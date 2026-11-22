<?php

namespace App\Services;

use App\Models\Category;
use App\Models\Product;
use App\Models\StockPurchase;
use App\Models\Supplier;
use App\Models\User;
use Brick\Math\BigDecimal;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class StockPurchases
{
    public function __construct(private Inventory $inventory) {}

    public function findProduct(string $name, bool $lock = false): ?Product
    {
        $key = Product::nameKey($name);
        $matches = Product::withTrashed()->get(['id', 'name', 'deleted_at'])
            ->filter(fn (Product $product) => Product::nameKey($product->name) === $key);
        $active = $matches->reject(fn (Product $product) => $product->trashed());

        if ($active->count() > 1) {
            throw ValidationException::withMessages(['product_name' => 'Multiple catalog products have this name. Give each product a distinct name in Products.']);
        }
        if ($active->isEmpty() && $matches->isNotEmpty()) {
            throw ValidationException::withMessages(['product_name' => 'This product is archived. Restore it in Products before purchasing stock.']);
        }

        return $active->isEmpty() ? null : Product::when($lock, fn ($query) => $query->lockForUpdate())->findOrFail($active->first()->id);
    }

    public function record(User $owner, array $data): array
    {
        $name = Str::squish($data['product_name']);
        $key = strtolower($data['request_key']);
        $categoryId = isset($data['category_id']) ? (int) $data['category_id'] : null;
        $unitCost = BigDecimal::of($data['unit_cost'])->toScale(2);
        $sellingPrice = (string) BigDecimal::of($data['selling_price'])->toScale(2);
        $markup = (string) BigDecimal::of($data['markup_percentage'])->toScale(4);
        $updateCatalog = (bool) ($data['update_catalog'] ?? false);
        $quantity = (int) $data['quantity'];
        $hash = hash('sha256', json_encode([
            $data['purchase_date'], Product::nameKey($name), (int) $data['supplier_id'], $categoryId, $quantity, (string) $unitCost, $markup, $sellingPrice,
            ...($updateCatalog ? [true] : []),
        ], JSON_THROW_ON_ERROR));

        return DB::transaction(function () use ($owner, $data, $name, $key, $categoryId, $unitCost, $sellingPrice, $markup, $quantity, $hash, $updateCatalog): array {
            Product::lockCatalogWrites();
            $previous = StockPurchase::where('request_key', $key)->first();
            if ($previous !== null) {
                abort_unless($previous->user_id === $owner->id && hash_equals($previous->request_hash, $hash), 409, 'We could not save this purchase. Check Previous purchases before you try again.');

                return [$previous->load('supplier'), false];
            }

            $requestedCategory = $categoryId === null ? null : Category::lockForUpdate()->find($categoryId);
            $supplier = Supplier::findOrFail($data['supplier_id']);
            $product = $this->findProduct($name, lock: true);
            $new = $product === null;
            $category = $new ? $requestedCategory : $product->category;
            if (($new && $category === null) || ($categoryId !== null && $category?->id !== $categoryId)) {
                throw ValidationException::withMessages(['category_id' => 'Select an existing category for a new product, or use the catalog category for an existing product.']);
            }

            if ($new) {
                $product = Product::create([
                    'name' => $name, 'sku' => Product::internalSku(), 'category_id' => $category->id,
                    'cost' => (string) $unitCost, 'price' => $sellingPrice, 'markup_percentage' => $markup,
                ])->refresh();
            } elseif ($updateCatalog) {
                $product->update(['price' => $sellingPrice, 'markup_percentage' => $markup]);
            }

            if (Money::centavos($product->price) === 0) {
                throw ValidationException::withMessages(['selling_price' => 'Approve a positive selling price for this product.']);
            }

            $movement = $this->inventory->move($product, $quantity, $new ? 'opening' : 'restock', $owner,
                remarks: 'Stock purchase', purchaseUnitCost: (string) $unitCost);
            $purchase = StockPurchase::create([
                'product_id' => $product->id, 'supplier_id' => $supplier->id, 'user_id' => $owner->id,
                'stock_movement_id' => $movement, 'request_key' => $key, 'request_hash' => $hash, 'purchase_date' => $data['purchase_date'],
                'product_name' => $product->name, 'category_name' => $category?->name ?? 'Uncategorized', 'quantity' => $quantity,
                'unit_cost' => (string) $unitCost, 'total_purchase' => (string) $unitCost->multipliedBy($quantity),
                'markup_percentage' => $markup, 'selling_price' => $product->price,
            ])->setRelation('supplier', $supplier);

            return [$purchase, true];
        }, 5);
    }
}
