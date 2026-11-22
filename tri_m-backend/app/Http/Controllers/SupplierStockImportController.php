<?php

namespace App\Http\Controllers;

use App\Services\Inventory;
use App\Services\Money;
use App\Services\SupplierStockImports;
use App\Services\SupplierWorkbook;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;

class SupplierStockImportController extends PosController
{
    public function __construct(private SupplierStockImports $imports) {}

    public function reviewCatalog(): JsonResponse
    {
        return $this->result($this->imports->catalogForReview(), 'Import catalog retrieved.');
    }

    public function confirmWorkbook(Request $request): JsonResponse
    {
        $data = $request->validate([
            'file' => ['required', 'file', 'extensions:xlsx', 'mimetypes:application/vnd.openxmlformats-officedocument.spreadsheetml.sheet,application/zip', 'max:2048'],
            'review' => ['required', 'string', 'max:2000000', 'json'],
        ]);
        $review = Validator::make(
            ['review' => json_decode($data['review'], true)],
            [
                'review' => ['required', 'array:draft_id,sheet,header,mapping,rows'],
                'review.draft_id' => ['required', 'uuid'],
                'review.sheet' => ['required', 'integer', 'min:0', 'max:149'],
                'review.header' => ['required', 'integer', 'min:1', 'max:1000'],
                'review.mapping' => ['required', 'array:'.implode(',', array_keys(SupplierWorkbook::HEADERS))],
                'review.mapping.quantity' => ['required'],
                'review.mapping.name' => ['required'],
                'review.mapping.*' => ['nullable', 'string', 'regex:/^[A-Z]{1,2}$/D', 'distinct'],
                'review.rows' => ['required', 'array', 'list', 'min:1', 'max:1000'],
                'review.rows.*' => ['required', 'array:row_number,decision,quantity,product_id,category_id,category_name,use_workbook_category,new_name,price_decision,reviewed_catalog_price,approved_selling_price,markup_percentage,item_cost,date,supplier'],
                'review.rows.*.row_number' => ['required', 'integer', 'min:2', 'max:1001', 'distinct'],
                'review.rows.*.decision' => ['required', 'in:existing,new,skip'],
                'review.rows.*.quantity' => ['sometimes', 'required', 'integer', 'min:1', 'max:'.Inventory::MAX_STOCK],
                'review.rows.*.product_id' => ['required_if:review.rows.*.decision,existing', 'nullable', 'integer', 'min:1'],
                'review.rows.*.category_id' => ['nullable', 'integer', 'min:1'],
                'review.rows.*.category_name' => ['nullable', 'string', 'max:100'],
                'review.rows.*.use_workbook_category' => ['sometimes', 'boolean'],
                'review.rows.*.new_name' => ['required_if:review.rows.*.decision,new', 'nullable', 'string', 'max:255'],
                'review.rows.*.price_decision' => ['required_if:review.rows.*.decision,existing', 'nullable', 'in:keep,change'],
                'review.rows.*.reviewed_catalog_price' => ['required_if:review.rows.*.decision,existing', 'nullable', ...Money::RULES],
                'review.rows.*.approved_selling_price' => ['required_if:review.rows.*.decision,new', 'nullable', ...Money::RULES, 'gt:0'],
                'review.rows.*.markup_percentage' => ['nullable', ...Money::MARKUP_RULES],
                'review.rows.*.item_cost' => ['nullable', ...Money::RULES],
                'review.rows.*.date' => ['nullable', 'date_format:Y-m-d'],
                'review.rows.*.supplier' => ['nullable', 'string', 'max:150'],
            ],
        )->validate()['review'];

        return $this->result($this->imports->confirmWorkbook($request->user(), $request->file('file'), $review), 'Inventory import completed.');
    }

    public function upload(Request $request): JsonResponse
    {
        $request->validate([
            'file' => ['required', 'file', 'extensions:xlsx', 'mimetypes:application/vnd.openxmlformats-officedocument.spreadsheetml.sheet,application/zip', 'max:2048'],
            'auto_review' => ['sometimes', 'boolean'],
        ]);

        return $this->result($this->imports->upload($request->user(), $request->file('file'), $request->boolean('auto_review')), 'Workbook uploaded.', 201);
    }

    public function worksheet(Request $request, string $import): JsonResponse
    {
        $data = $request->validate(['sheet' => ['required', 'integer', 'min:0', 'max:149']]);

        return $this->result($this->imports->worksheet($request->user(), $import, (int) $data['sheet']), 'Worksheet loaded.');
    }

    public function map(Request $request, string $import): JsonResponse
    {
        $data = $request->validate([
            'sheet' => ['required', 'integer', 'min:0', 'max:149'],
            'header' => ['required', 'integer', 'min:1', 'max:1000'],
            'mapping' => ['required', 'array:'.implode(',', array_keys(SupplierWorkbook::HEADERS))],
            'mapping.quantity' => ['required'],
            'mapping.name' => ['required'],
            'mapping.*' => ['nullable', 'string', 'regex:/^[A-Z]{1,2}$/D', 'distinct'],
        ]);
        $mapping = array_filter($data['mapping'], fn ($column) => $column !== null);
        $this->imports->map($request->user(), $import, (int) $data['sheet'], (int) $data['header'], $mapping);

        return $this->show($request, $import);
    }

    public function show(Request $request, string $import): JsonResponse
    {
        $data = $request->validate([
            'page' => ['sometimes', 'integer', 'min:1', 'max:1000'],
            'filter' => ['sometimes', 'in:all,pending,warnings,skipped'],
            'search' => ['sometimes', 'nullable', 'string', 'max:255'],
        ]);

        return $this->result($this->imports->details($request->user(), $import, $data['page'] ?? 1, $data['filter'] ?? 'all', $data['search'] ?? ''), 'Import retrieved.');
    }

    public function markup(Request $request, string $import): JsonResponse
    {
        $data = $request->validate([
            'revision' => ['required', 'integer', 'min:1'],
            'rows' => ['required', 'array', 'min:1', 'max:1000'],
            'rows.*' => ['required', 'integer', 'min:1', 'distinct'],
            'markup_percentage' => ['required', ...Money::MARKUP_RULES],
        ]);
        $this->imports->bulkMarkup($request->user(), $import, (int) $data['revision'], $data['rows'], (string) $data['markup_percentage']);

        return $this->show($request, $import);
    }

    public function review(Request $request, string $import, int $row): JsonResponse
    {
        $data = $request->validate([
            'decision' => ['required', 'in:existing,new,skip'],
            'product_id' => ['nullable', 'required_if:decision,existing', 'integer', 'min:1'],
            'approved_selling_price' => ['nullable', 'required_if:decision,new', ...Money::RULES, 'gt:0'],
            'price_decision' => ['required_if:decision,existing', 'nullable', 'in:keep,change'],
            'markup_percentage' => ['nullable', ...Money::MARKUP_RULES],
            'item_cost' => ['nullable', ...Money::RULES],
            'quantity' => ['sometimes', 'required', 'integer', 'min:1', 'max:'.Inventory::MAX_STOCK],
            'date' => ['nullable', 'date_format:Y-m-d'],
            'supplier' => ['nullable', 'string', 'max:150'],
            'category_id' => ['nullable', 'integer', 'exists:categories,id'],
            'category_name' => ['nullable', 'string', 'max:100'],
            'use_workbook_category' => ['sometimes', 'boolean'],
            'new_name' => ['nullable', 'required_if:decision,new', 'string', 'max:255'],
        ]);
        $this->imports->review($request->user(), $import, $row, $data);

        return $this->show($request, $import);
    }

    public function approve(Request $request, string $import): JsonResponse
    {
        $data = $request->validate(['revision' => ['required', 'integer', 'min:1'], 'acknowledged' => ['sometimes', 'boolean']]);

        return $this->result($this->imports->approve($request->user(), $import, (int) $data['revision'], (bool) ($data['acknowledged'] ?? false)), 'Inventory import completed.');
    }

    public function cancel(Request $request, string $import): JsonResponse
    {
        $this->imports->cancel($request->user(), $import);

        return $this->show($request, $import);
    }

    public function movements(Request $request): JsonResponse
    {
        $data = $request->validate([
            'product_id' => ['sometimes', 'integer', 'min:1'],
            'import_id' => ['sometimes', 'uuid'],
            'page' => ['sometimes', 'integer', 'min:1', 'max:100000'],
        ]);
        $page = DB::table('stock_movements as movement')
            ->join('products as product', 'product.id', '=', 'movement.product_id')
            ->join('stock_movement_types as type', 'type.id', '=', 'movement.movement_type_id')
            ->leftJoin('users as actor', 'actor.id', '=', 'movement.user_id')
            ->leftJoin('inventory_import_rows as source', 'source.id', '=', 'movement.inventory_import_row_id')
            ->when(isset($data['product_id']), fn ($query) => $query->where('movement.product_id', $data['product_id']))
            ->when(isset($data['import_id']), fn ($query) => $query->where('movement.inventory_import_id', $data['import_id']))
            ->orderByDesc('movement.created_at')->orderByDesc('movement.id')
            ->paginate(20, ['movement.id', 'movement.product_id', 'product.name', 'product.sku', 'type.name as type',
                DB::raw("TRIM(COALESCE(actor.first_name, '') || ' ' || COALESCE(actor.last_name, '')) as actor"),
                'movement.quantity', 'movement.previous_stock', 'movement.resulting_stock', 'movement.inventory_import_id',
                'source.row_number', 'movement.created_at']);

        return $this->result([
            'rows' => $page->getCollection()->map(function (object $movement): object {
                $movement->created_at = Carbon::parse($movement->created_at, 'UTC')->toISOString();

                return $movement;
            }),
            'pagination' => ['current_page' => $page->currentPage(), 'last_page' => $page->lastPage(), 'total' => $page->total()],
        ], 'Stock movements retrieved.');
    }
}
