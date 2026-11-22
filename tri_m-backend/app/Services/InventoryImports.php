<?php

namespace App\Services;

use App\Models\Category;
use App\Models\InventoryImport;
use App\Models\Product;
use App\Models\User;
use Illuminate\Support\Arr;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class InventoryImports
{
    public function __construct(private Inventory $inventory, private InventoryImportDrafts $drafts) {}

    public function inspect(array $source): array
    {
        return $this->process($source);
    }

    public function confirm(User $owner, string $id): array
    {
        return $this->drafts->lock($id, function () use ($owner, $id): array {
            $result = DB::transaction(function () use ($owner, $id): array {
                $existing = InventoryImport::query()->whereNull('status')->where('user_id', $owner->id)->lockForUpdate()->find($id);
                if ($existing?->confirmed_at !== null) {
                    return $existing->result;
                }

                $draft = $this->drafts->find($id) ?? $existing;
                abort_unless($draft !== null && $draft->status === null && $draft->user_id === $owner->id, 404);
                abort_if($draft->expires_at->isPast(), 410, 'This preview expired. Upload the workbook again.');
                $import = $existing ?? new InventoryImport;
                $import->forceFill($draft->getAttributes())->save();
                $result = $this->process($draft->preview, $owner, $id);
                $import->update(['preview' => null, 'result' => $result, 'confirmed_at' => now()]);

                return $result;
            }, 5);
            $this->drafts->forget($id);

            return $result;
        });
    }

    private function process(array $source, ?User $owner = null, ?string $importId = null): array
    {
        $groups = collect($source['rows'])->groupBy('sku')->sortKeys(SORT_STRING);
        $categories = Category::query()->whereIn('name', $groups->flatten(1)->pluck('category')->unique()->all())->orderBy('id');

        if ($owner !== null) {
            $categories->lockForUpdate();
        }

        $categories = $categories->get()->keyBy('name');
        $products = Product::withTrashed()->with('category')->whereIn('sku', $groups->keys()->all())->orderBy('id');

        if ($owner !== null) {
            $products->lockForUpdate();
        }

        $products = $products->get()->keyBy('sku');
        $entries = [];
        $errors = $source['errors'];
        $duplicates = [];

        foreach ($groups as $sku => $group) {
            $first = $group->first();
            $quantity = $group->sum('qty');
            $rowNumbers = $group->pluck('row')->all();
            $details = Arr::only($first, ['name', 'category', 'cost', 'price']);
            $consistent = $group->every(fn ($row) => Arr::only($row, array_keys($details)) === $details);

            if ($group->count() > 1) {
                $duplicates[] = ['sku' => (string) $sku, 'rows' => $rowNumbers, 'conflicting' => ! $consistent];
            }

            if (! $consistent) {
                $this->skip($errors, $group, 'Duplicate SKU rows have conflicting product details.');

                continue;
            }

            $product = $products->get($sku);
            $category = $categories->get($first['category']);

            if ($product === null && $category === null) {
                $this->skip($errors, $group, 'Create the category before importing this new product.');

                continue;
            }

            if ($owner !== null && $product === null && $quantity <= Inventory::MAX_STOCK) {
                $product = $this->findOrCreate($first, $category);
            }

            if ($product?->trashed() || $quantity + ($product?->stock ?? 0) > Inventory::MAX_STOCK) {
                $this->skip($errors, $group, $product?->trashed()
                    ? 'Restore this archived product before importing its stock.'
                    : 'The resulting stock would exceed the allowed limit.');

                continue;
            }

            $existing = $product !== null && ! $product->wasRecentlyCreated;
            $productCategory = $existing ? $product->category?->name : $first['category'];
            $mismatches = $existing ? array_keys(array_diff_assoc($details, [
                'name' => $product->name,
                'category' => $productCategory,
                'cost' => $product->cost,
                'price' => $product->price,
            ])) : [];
            $entries[] = [
                'product_id' => $product?->id,
                'sku' => (string) $sku,
                'name' => $product?->name ?? $first['name'],
                'category' => $productCategory,
                'quantity' => $quantity,
                'existing' => $existing,
                'mismatches' => $mismatches,
                'rows' => $rowNumbers,
            ];

            if ($owner !== null) {
                $this->inventory->move($product, $quantity, $existing ? 'restock' : 'opening', $owner,
                    remarks: 'Inventory import '.$importId, importId: $importId, purchaseUnitCost: $first['cost']);
            }
        }

        usort($errors, fn (array $a, array $b) => $a['row'] <=> $b['row']);
        $existingCount = count(array_filter($entries, fn ($entry) => $entry['existing']));

        return [
            'total_rows' => $source['total_rows'],
            'valid_rows' => $source['total_rows'] - count($errors),
            'skipped_rows' => count($errors),
            'new_products' => count($entries) - $existingCount,
            'existing_products' => $existingCount,
            'units' => array_sum(array_column($entries, 'quantity')),
            'entries' => $entries,
            'duplicates' => $duplicates,
            'errors' => $errors,
        ];
    }

    private function findOrCreate(array $row, Category $category): Product
    {
        $now = now();
        $created = DB::table('products')->insertOrIgnore([
            ...Arr::only($row, ['sku', 'name', 'cost', 'price']),
            'category_id' => $category->id,
            'markup_percentage' => '0',
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        $product = Product::withTrashed()->where('sku', $row['sku'])->lockForUpdate()->sole();
        $product->wasRecentlyCreated = $created === 1;

        return $product;
    }

    private function skip(array &$errors, Collection $group, string $message): void
    {
        foreach ($group as $row) {
            $errors[] = ['row' => $row['row'], 'messages' => [$message]];
        }
    }
}
