<?php

namespace App\Http\Controllers;

use App\Http\Requests\SaveProductRequest;
use App\Http\Resources\ProductResource;
use App\Models\Category;
use App\Models\Product;
use App\Services\Inventory;
use App\Services\Money;
use App\Services\PosDashboardCache;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class ProductController extends PosController
{
    public function index(Request $request): JsonResponse
    {
        $filters = $request->validate([
            'search' => ['nullable', 'string', 'max:100'],
            'category_id' => ['nullable', 'integer', 'min:1'],
            'availability' => ['nullable', 'in:in_stock,out_of_stock'],
            'max_price' => ['nullable', ...Money::RULES],
            'sort' => ['nullable', 'in:catalog,price-low,price-high'],
            'ids' => ['sometimes', 'required', 'array', 'max:100'],
            'ids.*' => ['required', 'integer', 'min:1', 'distinct'],
            'status' => ['nullable', 'in:active,archived,all'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
            'include_supplier' => ['sometimes', 'boolean'],
            'page' => ['nullable', 'integer', 'min:1', 'max:100000'],
        ]);

        $owner = $request->user()->hasRole('owner');
        $query = $this->catalogQuery($owner);
        $sort = $filters['sort'] ?? 'catalog';
        if ($sort !== 'catalog') {
            $query->orderBy('price', $sort === 'price-low' ? 'asc' : 'desc');
        }
        $query->orderBy('name')->orderBy('id');
        $status = $filters['status'] ?? 'active';
        abort_if($status !== 'active' && ! $owner, 403);

        if ($status === 'archived') {
            $query->onlyTrashed();
        } elseif ($status === 'all') {
            $query->withTrashed();
        }

        if (filled($filters['search'] ?? null)) {
            $term = '%'.strtr(mb_strtolower($filters['search']), ['!' => '!!', '%' => '!%', '_' => '!_']).'%';
            $query->whereRaw("LOWER(name) LIKE ? ESCAPE '!'", [$term]);
        }

        if (isset($filters['category_id'])) {
            $query->where('category_id', $filters['category_id']);
        }

        if (isset($filters['availability'])) {
            $query->where('stock', $filters['availability'] === 'in_stock' ? '>' : '=', 0);
        }

        if (isset($filters['max_price'])) {
            $query->where('price', '<=', $filters['max_price']);
        }

        if (isset($filters['ids'])) {
            $query->whereKey($filters['ids']);
        }

        $page = $query->paginate($filters['per_page'] ?? 25);
        if ($owner && ($filters['include_supplier'] ?? false)) {
            $this->attachSuppliers($page->items());
        }

        return $this->page($page, ProductResource::class, 'Products retrieved.');
    }

    public function show(Request $request, int $product): JsonResponse
    {
        $owner = $request->user()->hasRole('owner');
        $query = $this->catalogQuery($owner);

        if ($owner) {
            $query->withTrashed();
        }

        return $this->result(new ProductResource($query->findOrFail($product)), 'Product retrieved.');
    }

    private function catalogQuery(bool $owner): Builder
    {
        $columns = ['id', 'category_id', 'name', 'price', 'stock', 'deleted_at'];
        if ($owner) {
            $columns = [...$columns, 'cost', 'sku', 'markup_percentage'];
        }

        return Product::query()->select($columns)->with('category:id,name');
    }

    /** @param list<Product> $products */
    private function attachSuppliers(array $products): void
    {
        if ($products === []) {
            return;
        }
        $ids = array_map(fn (Product $product) => $product->id, $products);
        $history = DB::table('stock_movements as movement')
            ->leftJoin('stock_purchases as purchase', 'purchase.stock_movement_id', '=', 'movement.id')
            ->leftJoin('suppliers as supplier', 'supplier.id', '=', 'purchase.supplier_id')
            ->leftJoin('inventory_import_rows as import_row', 'import_row.id', '=', 'movement.inventory_import_row_id')
            ->whereIn('movement.product_id', $ids)
            ->where(fn ($query) => $query->whereNotNull('purchase.id')->orWhereNotNull('import_row.id'))
            ->orderByDesc('movement.id')
            ->select(['movement.product_id', 'supplier.name', 'import_row.source'])
            ->cursor();
        $suppliers = [];
        foreach ($history as $record) {
            if (isset($suppliers[$record->product_id])) {
                continue;
            }
            $source = json_decode($record->source ?? '{}', true) ?? [];
            $supplier = $record->name;
            foreach ([$source['applied'] ?? [], $source['reviewed'] ?? [], ['supplier' => $source['cells']['supplier']['value'] ?? null]] as $values) {
                if ($supplier !== null || array_key_exists('supplier', $values)) {
                    $supplier ??= $values['supplier'] ?? null;
                    break;
                }
            }
            $supplier = trim($supplier ?? '');
            if ($supplier !== '') {
                $suppliers[$record->product_id] = $supplier;
                if (count($suppliers) === count($ids)) {
                    break;
                }
            }
        }
        foreach ($products as $product) {
            $product->setAttribute('latest_supplier', $suppliers[$product->id] ?? null);
        }
    }

    public function store(SaveProductRequest $request, Inventory $inventory): JsonResponse
    {
        $data = $request->validated();

        try {
            $product = DB::transaction(function () use ($data, $request, $inventory): Product {
                Product::lockCatalogWrites();
                $this->lockCategory($data['category_id'] ?? null);
                if (! isset($data['sku'])) {
                    $this->guardProductName($data['name']);
                    $data['sku'] = Product::internalSku();
                }
                $product = Product::create(Arr::except($data, 'stock') + ['markup_percentage' => '0'])->refresh();

                if ((int) ($data['stock'] ?? 0) > 0) {
                    $inventory->move($product, (int) $data['stock'], 'opening', $request->user());
                }

                return $product->load('category');
            }, 5);
        } catch (UniqueConstraintViolationException) {
            throw ValidationException::withMessages(['sku' => 'The SKU has already been taken.']);
        }

        return $this->result(new ProductResource($product), 'Product created.', 201);
    }

    public function update(SaveProductRequest $request, Product $product): JsonResponse
    {
        $data = $request->validated();

        try {
            $product = DB::transaction(function () use ($product, $data): Product {
                Product::lockCatalogWrites();
                $this->lockCategory($data['category_id'] ?? null);
                $current = Product::query()->lockForUpdate()->findOrFail($product->id);
                if (! isset($data['sku']) && Product::nameKey($data['name']) !== Product::nameKey($current->name)) {
                    $this->guardProductName($data['name']);
                }
                if (isset($data['cost']) && Money::centavos($data['cost']) !== Money::centavos($current->cost)) {
                    $current->forceFill(['inventory_value' => (string) Money::lineTotal((string) $data['cost'], $current->stock)]);
                }
                $current->update($data);

                return $current->load('category');
            }, 5);
        } catch (UniqueConstraintViolationException) {
            throw ValidationException::withMessages(['sku' => 'The SKU has already been taken.']);
        }

        if ($product->wasChanged('category_id')) {
            PosDashboardCache::invalidate();
        }

        return $this->result(new ProductResource($product), 'Product updated.');
    }

    public function destroy(Product $product): JsonResponse
    {
        $product->delete();

        return $this->result(null, 'Product archived.');
    }

    public function restore(int $product): JsonResponse
    {
        $record = Product::withTrashed()->findOrFail($product);
        $record->restore();

        return $this->result(new ProductResource($record->load('category')), 'Product restored.');
    }

    private function lockCategory(?int $categoryId): void
    {
        if ($categoryId !== null && Category::query()->lockForUpdate()->find($categoryId) === null) {
            throw ValidationException::withMessages(['category_id' => 'The selected category is unavailable.']);
        }
    }

    private function guardProductName(string $name): void
    {
        $key = Product::nameKey($name);
        if (Product::withTrashed()->pluck('name')->contains(fn (string $stored) => Product::nameKey($stored) === $key)) {
            throw ValidationException::withMessages(['name' => 'This product name is already in use. Edit the existing product or choose a different name.']);
        }
    }
}
