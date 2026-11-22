<?php

namespace App\Http\Controllers;

use App\Http\Resources\StockPurchaseResource;
use App\Models\StockPurchase;
use App\Models\Supplier;
use App\Services\Inventory;
use App\Services\Money;
use App\Services\StockPurchases;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

class StockPurchaseController extends PosController
{
    public function index(Request $request): JsonResponse
    {
        $request->validate(['page' => ['sometimes', 'integer', 'min:1', 'max:100000']]);

        return $this->page(StockPurchase::with('supplier')->orderByDesc('purchase_date')->orderByDesc('id')->paginate(15),
            StockPurchaseResource::class, 'Stock purchases retrieved.');
    }

    public function product(Request $request, StockPurchases $purchases): JsonResponse
    {
        $data = $request->validate(['name' => ['required', 'string', 'max:255']]);
        $product = $purchases->findProduct($data['name']);

        return $this->result($product === null ? null : [
            ...$product->only(['id', 'name', 'category_id', 'price', 'stock', 'markup_percentage']),
            'category' => $product->category?->name,
        ], 'Product lookup completed.');
    }

    public function store(Request $request, StockPurchases $purchases): JsonResponse
    {
        $request->merge(['request_key' => $request->header('Idempotency-Key')]);
        $data = $request->validate([
            'request_key' => ['required', 'uuid'],
            'purchase_date' => ['required', 'date_format:Y-m-d'],
            'product_name' => ['required', 'string', 'max:255'],
            'supplier_id' => ['required', 'integer', 'exists:suppliers,id'],
            'category_id' => ['nullable', 'integer', 'exists:categories,id'],
            'quantity' => ['required', 'integer', 'min:1', 'max:'.Inventory::MAX_STOCK],
            'unit_cost' => ['required', ...Money::RULES],
            'markup_percentage' => ['required', ...Money::MARKUP_RULES],
            'selling_price' => ['required', ...Money::RULES, 'gt:0'],
            'update_catalog' => ['sometimes', 'boolean'],
        ]);
        [$purchase, $created] = $purchases->record($request->user(), $data);

        return $this->result(new StockPurchaseResource($purchase), 'Stock purchase saved.', $created ? 201 : 200);
    }

    public function suppliers(): JsonResponse
    {
        return $this->result(Supplier::orderBy('name')->get(['id', 'name']), 'Suppliers retrieved.');
    }

    public function storeSupplier(Request $request): JsonResponse
    {
        $request->merge(['name' => is_string($request->input('name')) ? Str::squish($request->input('name')) : $request->input('name')]);
        $data = $request->validate(['name' => ['required', 'string', 'max:150']]);
        $supplier = Supplier::firstOrCreate(['name_key' => mb_strtolower($data['name'])], ['name' => $data['name']]);

        return $this->result($supplier, 'Supplier saved.', $supplier->wasRecentlyCreated ? 201 : 200);
    }
}
