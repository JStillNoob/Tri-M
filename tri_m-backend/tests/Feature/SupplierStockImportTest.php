<?php

namespace Tests\Feature;

use App\Models\InventoryImport;
use App\Models\Role;
use App\Models\User;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use OpenSpout\Common\Entity\Row;
use OpenSpout\Writer\XLSX\Writer;
use Tests\TestCase;

class SupplierStockImportTest extends TestCase
{
    use LazilyRefreshDatabase;

    public function test_browser_confirmation_saves_only_reviewed_rows_and_can_be_retried(): void
    {
        Storage::fake('local');
        $owner = $this->account('owner');
        $cashier = $this->account('cashier');
        $this->actingAs($owner, 'web');
        $category = $this->postJson('/api/categories', ['name' => 'Drinks'])->assertCreated()->json('data.id');
        $water = $this->product('WATER', 'Mineral Water', $category, 5);
        $tea = $this->product('TEA', 'Green Tea', $category, 1);
        $id = (string) Str::uuid();
        $file = $this->workbook([
            ['Product', 'Quantity', 'Item Cost', 'Category'],
            ['Mineral Water', 3, '2.123456789012345', 'Drinks'],
            ['Green Tea', 2, 5, 'Drinks'],
            ['Orange Juice', 1, 8, 'Drinks'],
        ], withCover: false);
        $rows = [
            ['row_number' => 2, 'decision' => 'existing', 'product_id' => $water, 'price_decision' => 'keep', 'reviewed_catalog_price' => '10.00'],
            ['row_number' => 3, 'decision' => 'existing', 'product_id' => $tea, 'price_decision' => 'change', 'reviewed_catalog_price' => '10.00', 'approved_selling_price' => '11.77'],
            ['row_number' => 4, 'decision' => 'new', 'new_name' => 'Orange Juice', 'category_id' => $category, 'approved_selling_price' => '12.80'],
        ];
        $confirm = fn (array $decisions, string $reference = '') => $this->postJson('/api/imports/confirm-workbook', [
            'file' => $file,
            'review' => json_encode([
                'draft_id' => $reference ?: $id, 'sheet' => 0, 'header' => 1,
                'mapping' => ['name' => 'A', 'quantity' => 'B', 'unit_cost' => 'C', 'category' => 'D'],
                'rows' => $decisions,
            ]),
        ]);

        $this->getJson('/api/imports/review-catalog')->assertOk()
            ->assertJsonPath('data.products.0.stock', 5)->assertJsonMissingPath('data.products.0.cost');
        $this->getJson("/api/imports/$id")->assertNotFound();
        $this->assertDatabaseCount('inventory_imports', 0);
        $this->actingAs($cashier, 'web')->getJson('/api/imports/review-catalog')->assertForbidden();
        $confirm($rows)->assertForbidden();
        $this->actingAs($owner, 'web');

        $confirm([$rows[0], $rows[1]])->assertUnprocessable();
        $confirm([[...$rows[0], 'price_decision' => null], $rows[1], $rows[2]])->assertUnprocessable();
        $confirm([[...$rows[0], 'reviewed_catalog_price' => '8.75'], $rows[1], $rows[2]])->assertUnprocessable()
            ->assertJsonPath('errors.rows.0', 'Row 2: The current selling price changed. Review the price decision again.');
        $this->assertDatabaseCount('inventory_imports', 0);
        $this->assertDatabaseCount('inventory_import_rows', 0);
        $this->assertDatabaseHas('products', ['id' => $water, 'stock' => 5, 'price' => '10.00']);
        $this->assertSame([], Storage::disk('local')->allFiles());

        $confirm($rows)->assertOk()->assertJsonPath('data.reference', $id)
            ->assertJsonPath('data.units', 6)->assertJsonPath('data.prices_adjusted', 1);
        $confirm($rows)->assertOk()->assertJsonPath('data.reference', $id);
        $confirm($rows, (string) Str::uuid())->assertConflict();
        $this->assertDatabaseCount('inventory_imports', 1);
        $this->assertDatabaseCount('inventory_import_rows', 3);
        $this->assertDatabaseHas('products', ['id' => $water, 'stock' => 8, 'price' => '10.00']);
        $this->assertDatabaseHas('products', ['id' => $tea, 'stock' => 3, 'price' => '11.77']);
        $this->assertDatabaseHas('products', ['name' => 'Orange Juice', 'stock' => 1, 'price' => '12.80']);
        $this->assertSame([], Storage::disk('local')->allFiles());
    }

    public function test_workbook_review_applies_only_explicit_prices_and_records_stock_for_cashier(): void
    {
        Storage::fake('local');
        $owner = $this->account('owner');
        $cashier = $this->account('cashier');
        $this->actingAs($owner, 'web');
        $category = $this->postJson('/api/categories', ['name' => 'Drinks'])->assertCreated()->json('data.id');
        $water = $this->product('WATER', 'Mineral Water', $category, 5);
        $tea = $this->product('TEA', 'Green Tea', $category, 2);
        $file = $this->workbook([
            ['MONTH OF NOVEMBER'],
            ['Quantity', 'Product', 'Cost per Item', 'Total Purchase', 'Sell Price', 'SKU', 'Supplier'],
            [3, ' MINERAL   WATER ', '2.123456789012345', '6.370370367037035', '13.725', '', 'Supplier'],
            [4, 'Green Tea', 5, 20, 25.99, 'tea', 'Supplier'],
            [2, 'Orange Juice', 6.12, 12.24, '', '', 'Supplier'],
            [1, '[MISSING ITEMS FROM RECEIPT CUT-OFF]', 3, 3],
            [null, 'TOTAL PURCHASE', null, '41.61'],
        ]);
        $review = $this->draft($file, 1, 2, ['quantity' => 'A', 'name' => 'B', 'unit_cost' => 'C', 'selling_price' => 'E', 'supplier' => 'G']);
        $id = $review['id'];
        [$waterRow, $teaRow, $newRow, $placeholder] = array_column($review['rows'], 'id');
        $this->assertSame('name', $review['rows'][0]['matching_method']);
        $this->assertSame('name', $review['rows'][1]['matching_method']);
        $this->assertSame('13.725', $review['rows'][0]['stock_in']['source_price']);
        $this->assertSame('2.123456789012345', $review['rows'][0]['stock_in']['original_item_cost']);
        $this->assertSame('6.37', $review['rows'][0]['purchase']['total_cost']);
        $this->assertSame(2, $review['summary']['invalid_rows']);
        $this->assertDatabaseHas('products', ['id' => $water, 'stock' => 5]);
        $this->assertDatabaseCount('stock_movements', 2);

        $this->postJson("/api/imports/$id/approve", ['revision' => $review['setup']['revision'], 'acknowledged' => true])->assertUnprocessable();
        $this->actingAs($this->account('owner'), 'web')->getJson("/api/imports/$id")->assertNotFound();
        $this->actingAs($owner, 'web')->postJson('/api/imports/confirm', ['preview_id' => $id])->assertNotFound();
        $this->putJson("/api/imports/$id/rows/$placeholder", ['decision' => 'existing', 'product_id' => $water])->assertUnprocessable();
        $this->putJson("/api/imports/$id/rows/$waterRow", ['decision' => 'existing', 'product_id' => $water, 'price_decision' => 'keep'])->assertOk();
        $this->putJson("/api/imports/$id/rows/$teaRow", ['decision' => 'existing', 'product_id' => $tea, 'price_decision' => 'change', 'approved_selling_price' => '19.37'])->assertOk();
        $details = ['decision' => 'new', 'new_name' => 'Orange Juice', 'category_id' => $category, 'item_cost' => '6.12'];
        $this->putJson("/api/imports/$id/rows/$newRow", [...$details, 'approved_selling_price' => '0'])->assertUnprocessable();
        $this->putJson("/api/imports/$id/rows/$newRow", [...$details, 'approved_selling_price' => '11.98'])->assertOk();
        $revision = $this->putJson("/api/imports/$id/rows/$placeholder", ['decision' => 'skip'])->assertOk()->json('data.setup.revision');
        $this->postJson("/api/imports/$id/approve", ['revision' => $revision - 1, 'acknowledged' => true])->assertConflict();
        $this->postJson("/api/imports/$id/approve", ['revision' => $revision, 'acknowledged' => true])
            ->assertOk()->assertJsonPath('data.units', 9)->assertJsonPath('data.prices_adjusted', 1)->assertJsonPath('data.new_products', 1);
        $this->postJson("/api/imports/$id/approve", ['revision' => $revision, 'acknowledged' => true])->assertConflict();
        $this->getJson('/api/imports')->assertOk()->assertJsonPath('data.0.id', $id);
        $this->getJson("/api/imports/$id")->assertOk()->assertJsonPath('data.rows.0.applied.resulting_stock', 8);
        $this->getJson("/api/imports/$id?search=Row%20%234&page=2")->assertOk()
            ->assertJsonCount(1, 'data.rows')->assertJsonPath('data.rows.0.row_number', 4)
            ->assertJsonPath('data.pagination.current_page', 1)->assertJsonPath('data.summary.units', 9);
        $this->getJson("/api/imports/$id?search=ORANGE%20%20JUICE")->assertOk()
            ->assertJsonCount(1, 'data.rows')->assertJsonPath('data.rows.0.row_number', 5);
        $this->getJson("/api/imports/$id?search=4&filter=skipped")->assertOk()
            ->assertJsonCount(0, 'data.rows');
        $this->getJson("/api/stock-movements?import_id=$id")->assertOk()->assertJsonPath('data.pagination.total', 3);
        $this->assertDatabaseHas('products', ['id' => $water, 'name' => 'Mineral Water', 'cost' => '3.61', 'price' => '10.00', 'stock' => 8, 'category_id' => $category]);
        $this->assertDatabaseHas('products', ['name' => 'Orange Juice', 'price' => '11.98', 'stock' => 2]);
        $storedWaterRow = InventoryImport::findOrFail($id)->rows()->where('row_number', 3)->value('id');
        $this->assertDatabaseHas('stock_movements', ['inventory_import_id' => $id, 'inventory_import_row_id' => $storedWaterRow, 'previous_stock' => 5, 'resulting_stock' => 8]);
        $this->assertNull(InventoryImport::findOrFail($id)->file_path);

        $this->actingAs($cashier, 'web');
        foreach ([
            ['GET', '/api/imports'], ['POST', '/api/imports/upload'], ['GET', "/api/imports/$id"],
            ['GET', "/api/imports/$id/worksheet?sheet=1"], ['PUT', "/api/imports/$id/mapping"],
            ['PUT', "/api/imports/$id/rows/$waterRow"], ['POST', "/api/imports/$id/approve"], ['POST', "/api/imports/$id/cancel"],
            ['GET', '/api/stock-movements'],
        ] as [$method, $route]) {
            $this->json($method, $route)->assertForbidden();
        }
        $this->getJson("/api/products/$tea")->assertOk()->assertJsonPath('data.price', '19.37')->assertJsonPath('data.stock', 6)->assertJsonMissingPath('data.cost');
        $this->postJson('/api/sales', [
            'items' => [['product_id' => $tea, 'quantity' => 1, 'unit_price' => '0.01']],
            'payment' => ['method' => 'cash', 'amount_tendered' => '20.00'],
        ], ['Idempotency-Key' => (string) Str::uuid()])->assertUnprocessable();
        $this->postJson('/api/sales', [
            'items' => [['product_id' => $tea, 'quantity' => 1]],
            'payment' => ['method' => 'cash', 'amount_tendered' => '20.00'],
        ], ['Idempotency-Key' => (string) Str::uuid()])
            ->assertCreated()->assertJsonPath('data.total', '19.37')->assertJsonMissingPath('data.items.0.unit_cost');
    }

    public function test_large_workbook_confirmation_batches_stock_writes(): void
    {
        $this->actingAs($this->account('owner'), 'web');
        $category = $this->postJson('/api/categories', ['name' => 'Drinks'])->assertCreated()->json('data.id');
        $water = $this->product('WATER', 'Mineral Water', $category, 5);
        $id = (string) Str::uuid();
        $lines = [['Product', 'Quantity', 'Item Cost']];
        $decisions = [];
        foreach (range(1, 120) as $number) {
            $name = 'New drink '.$number;
            $lines[] = [$name, 2, '3.335'];
            $decisions[] = ['row_number' => count($lines), 'decision' => 'new', 'new_name' => $name,
                'category_id' => $category, 'approved_selling_price' => '8.00'];
        }
        foreach (range(1, 100) as $number) {
            $lines[] = ['Mineral Water', 1, '5.50'];
            $decisions[] = ['row_number' => count($lines), 'decision' => 'existing', 'product_id' => $water,
                'price_decision' => 'change', 'reviewed_catalog_price' => '10.00', 'approved_selling_price' => '11.23'];
        }
        $lines[] = ['New drink 1', 2, '3.335'];
        $decisions[] = ['row_number' => count($lines), 'decision' => 'skip'];
        $file = $this->workbook($lines, withCover: false);

        DB::flushQueryLog();
        DB::enableQueryLog();
        try {
            $response = $this->postJson('/api/imports/confirm-workbook', [
                'file' => $file,
                'review' => json_encode([
                    'draft_id' => $id, 'sheet' => 0, 'header' => 1,
                    'mapping' => ['name' => 'A', 'quantity' => 'B', 'unit_cost' => 'C'],
                    'rows' => $decisions,
                ]),
            ]);
            $queries = count(DB::getQueryLog());
        } finally {
            DB::disableQueryLog();
            DB::flushQueryLog();
        }

        $response->assertOk()->assertJsonPath('data.units', 340)->assertJsonPath('data.skipped_rows', 1)
            ->assertJsonPath('data.prices_adjusted', 1);
        $this->assertLessThan(60, $queries, "Confirmation used {$queries} database queries for 221 rows.");
        $this->assertDatabaseHas('products', ['id' => $water, 'stock' => 105, 'inventory_value' => '572.50', 'cost' => '5.45', 'price' => '11.23']);
        $this->assertDatabaseHas('products', ['name' => 'New drink 1', 'stock' => 2, 'inventory_value' => '6.67']);
        $this->assertDatabaseHas('inventory_import_rows', ['inventory_import_id' => $id, 'row_number' => 222, 'decision' => 'skip', 'processed_at' => null]);
        $this->assertSame(220, DB::table('stock_movements')->where('inventory_import_id', $id)->count());
        $this->assertDatabaseHas('stock_movements', ['inventory_import_id' => $id, 'product_id' => $water, 'previous_stock' => 104, 'resulting_stock' => 105]);
    }

    public function test_duplicates_ambiguous_names_and_invalid_quantities_require_review_and_prevent_replay(): void
    {
        Storage::fake('local');
        $this->getJson('/api/stock-movements')->assertUnauthorized();
        $this->actingAs($this->account('owner'), 'web');
        $category = $this->postJson('/api/categories', ['name' => 'Drinks'])->assertCreated()->json('data.id');
        $first = $this->product('A', 'Same Drink', $category, 2);
        $this->product('B', ' same  drink ', $category, 3);
        $this->postJson('/api/imports/upload', ['file' => UploadedFile::fake()->create('fake.xlsx', 20, 'application/zip')])->assertUnprocessable();
        $file = $this->workbook([
            ['Item', 'Units', 'Barcode'],
            ['Same Drink', 2, ''],
            ['Same Drink', 2, ''],
            ['Same Drink', 4, 'UNKNOWN'],
            ['Bad quantity', 0.5, ''],
        ]);
        $mapping = ['name' => 'A', 'quantity' => 'B'];
        $firstDraft = $this->draft($file, 1, 1, $mapping);
        $this->assertSame(2, $firstDraft['summary']['duplicate_rows']);
        $this->assertSame(4, $firstDraft['summary']['pending_rows']);
        $this->assertCount(2, $firstDraft['rows'][0]['candidates']);
        $this->assertStringContainsString('Multiple catalog products', $firstDraft['rows'][2]['warnings'][0]);
        $secondDraft = $this->draft($file, 1, 1, $mapping);
        foreach ([$firstDraft, $secondDraft] as $index => $draft) {
            $id = $draft['id'];
            foreach ($draft['rows'] as $line => $row) {
                $response = $this->putJson("/api/imports/$id/rows/{$row['id']}", $line === 0
                    ? ['decision' => 'existing', 'product_id' => $first, 'item_cost' => '4.50', 'price_decision' => 'keep']
                    : ['decision' => 'skip'])->assertOk();
            }
            $this->postJson("/api/imports/$id/approve", ['revision' => $response->json('data.setup.revision'), 'acknowledged' => true])
                ->assertStatus($index === 0 ? 200 : 409);
        }
        $this->assertDatabaseHas('products', ['id' => $first, 'stock' => 4]);
        $cancel = $this->draft($file, 1, 1, $mapping)['id'];
        $this->postJson("/api/imports/$cancel/cancel")->assertOk()->assertJsonPath('data.status', 'cancelled');
        $this->assertDatabaseMissing('inventory_imports', ['id' => $cancel]);
    }

    public function test_confirmation_rolls_back_if_the_stock_ledger_fails(): void
    {
        Storage::fake('local');
        $this->actingAs($this->account('owner'), 'web');
        $category = $this->postJson('/api/categories', ['name' => 'Drinks'])->assertCreated()->json('data.id');
        $product = $this->product('W', 'Water', $category, 5);
        $review = $this->draft($this->workbook([['Product', 'Quantity', 'Category', 'Item cost'], ['Water', 2, '', 4.50], ['Biscuits', 3, 'Snacks']]), 1, 1,
            ['name' => 'A', 'quantity' => 'B', 'category' => 'C', 'unit_cost' => 'D']);
        $id = $review['id'];
        $firstRow = $review['rows'][0]['id'];
        $secondRow = $review['rows'][1]['id'];
        $this->putJson("/api/imports/$id/rows/$firstRow", [
            'decision' => 'existing', 'product_id' => $product, 'price_decision' => 'keep',
        ])->assertOk();
        $revision = $this->putJson("/api/imports/$id/rows/$secondRow", [
            'decision' => 'new', 'new_name' => 'Biscuits', 'item_cost' => '5.00', 'approved_selling_price' => '10.00', 'use_workbook_category' => true,
        ])->assertOk()->json('data.setup.revision');
        $driver = DB::getDriverName();
        if ($driver === 'sqlite') {
            DB::unprepared("CREATE TRIGGER reject_import_move BEFORE INSERT ON stock_movements WHEN NEW.inventory_import_row_id IS NOT NULL AND NEW.quantity = 3 BEGIN SELECT RAISE(ABORT, 'fixture failure'); END");
        } else {
            DB::unprepared('ALTER TABLE stock_movements ADD CONSTRAINT reject_import_move CHECK (inventory_import_row_id IS NULL OR quantity <> 3)');
        }
        try {
            $this->postJson("/api/imports/$id/approve", ['revision' => $revision])->assertServerError();
            $this->assertDatabaseHas('products', ['id' => $product, 'stock' => 5]);
            $this->assertDatabaseMissing('categories', ['name' => 'Snacks']);
            $this->assertDatabaseCount('stock_movements', 1);
            $this->assertDatabaseMissing('inventory_imports', ['id' => $id]);
            $this->assertDatabaseMissing('inventory_import_rows', ['inventory_import_id' => $id]);
            $this->getJson("/api/imports/$id")->assertOk()->assertJsonPath('data.status', 'review');
        } finally {
            DB::unprepared($driver === 'sqlite' ? 'DROP TRIGGER reject_import_move' : 'ALTER TABLE stock_movements DROP CONSTRAINT reject_import_move');
        }
        $this->postJson("/api/imports/$id/approve", ['revision' => $revision])->assertOk()->assertJsonPath('data.new_categories', 1);
    }

    public function test_workbook_categories_suggest_exact_matches_and_keep_restock_categories(): void
    {
        Storage::fake('local');
        $this->actingAs($this->account('owner'), 'web');
        $drinks = $this->postJson('/api/categories', ['name' => 'Cold Drinks'])->assertCreated()->json('data.id');
        $snacks = $this->postJson('/api/categories', ['name' => 'Snacks'])->assertCreated()->json('data.id');
        $this->postJson('/api/categories', ['name' => 'Frozen Goods'])->assertCreated();
        $this->postJson('/api/categories', ['name' => 'Frozen   Goods'])->assertCreated();
        $water = $this->product('WATER', 'Water', $drinks, 5);
        $file = $this->workbook([
            ['Product', 'Quantity', 'Cost per Item', 'Category'],
            ['Water', 3, 5, 'Snacks'],
            ['Orange Juice', 2, 6, ' cOLD  drinks '],
            ['Pretzels', 2, 2, 'Snakcs'],
            ['Chips', 1, 3, ''],
            ['Ice Cream', 2, 7, 'Frozen Goods'],
        ]);
        $review = $this->draft($file, 1, 1, ['name' => 'A', 'quantity' => 'B', 'unit_cost' => 'C', 'category' => 'D']);
        $id = $review['id'];
        [$restock, $matched, $unknown, $blank, $ambiguous] = $review['rows'];
        $this->assertSame('Snacks', $restock['category_review']['source']);
        $this->assertSame($drinks, $restock['category_review']['selected']['id']);
        $this->assertStringContainsString('Cold Drinks', $restock['warnings'][0]);
        $this->assertSame($drinks, $matched['category_review']['suggestion']['id']);
        $this->assertNull($matched['category_id']);
        $this->assertNull($unknown['category_review']['suggestion']);
        $this->assertNull($blank['category_review']['suggestion']);
        $this->assertNull($ambiguous['category_review']['suggestion']);
        $this->getJson("/api/imports/$id?filter=warnings")->assertOk()
            ->assertJsonPath('data.rows.0.id', $restock['id'])->assertJsonPath('data.pagination.total', 1);
        $this->putJson("/api/imports/$id/rows/{$restock['id']}", [
            'decision' => 'existing', 'product_id' => $water, 'price_decision' => 'keep',
        ])->assertOk();

        $decision = ['decision' => 'new', 'new_name' => 'Orange Juice', 'item_cost' => '6.00', 'approved_selling_price' => '10.57'];
        $this->putJson("/api/imports/$id/rows/{$matched['id']}", $decision)->assertUnprocessable()->assertJsonValidationErrors('category_id');
        $this->putJson("/api/imports/$id/rows/{$matched['id']}", [...$decision, 'category_id' => $snacks])->assertOk()
            ->assertJsonPath('data.rows.1.category_review.selected.name', 'Snacks');
        foreach ([$unknown, $blank, $ambiguous] as $row) {
            $updated = $this->putJson("/api/imports/$id/rows/{$row['id']}", ['decision' => 'skip'])->assertOk();
        }
        $revision = $updated->json('data.setup.revision');
        $this->postJson("/api/imports/$id/approve", ['revision' => $revision])->assertUnprocessable()->assertJsonValidationErrors('warnings');
        $this->postJson("/api/imports/$id/approve", ['revision' => $revision, 'acknowledged' => true])->assertOk()
            ->assertJsonPath('data.warnings_reviewed', 1);
        $this->getJson("/api/imports/$id")->assertOk()->assertJsonPath('data.rows.0.category_review.selected.name', 'Cold Drinks')
            ->assertJsonPath('data.rows.1.category_review.selected.name', 'Snacks');
        $this->assertDatabaseHas('products', ['id' => $water, 'category_id' => $drinks, 'stock' => 8]);
        $this->actingAs($this->account('cashier'), 'web')->getJson("/api/products?category_id=$snacks")
            ->assertOk()->assertJsonPath('data.0.name', 'Orange Juice')->assertJsonPath('data.0.category', 'Snacks')
            ->assertJsonMissingPath('data.0.cost');
    }

    public function test_approval_creates_a_workbook_category_once_and_later_imports_reuse_it(): void
    {
        Storage::fake('local');
        $this->actingAs($this->account('owner'), 'web');
        $this->assertDatabaseCount('categories', 0);
        $mapping = ['name' => 'A', 'quantity' => 'B', 'category' => 'C'];
        $file = $this->workbook([
            ['Product', 'Quantity', 'Category'],
            ['Chips', 2, '  Snacks  & Biscuits  '],
            ['Pretzels', 3, 'SNACKS & BISCUITS'],
            ['Skip this', 1, 'Unused Category'],
            ['Reserved', 1, 'All'],
        ]);
        $preview = $this->draft($file, 1, 1, $mapping);
        $id = $preview['id'];
        [$chips, $pretzels, $skip, $reserved] = array_column($preview['rows'], 'id');
        $this->assertSame('Snacks & Biscuits', $preview['rows'][0]['category_review']['create_name']);
        $this->assertNull($preview['rows'][3]['category_review']['create_name']);
        $new = ['decision' => 'new', 'item_cost' => '3.00', 'approved_selling_price' => '5.00', 'use_workbook_category' => true];
        $this->putJson("/api/imports/$id/rows/$reserved", [...$new, 'new_name' => 'Reserved'])->assertUnprocessable()->assertJsonValidationErrors('category_id');
        $this->putJson("/api/imports/$id/rows/$chips", [...$new, 'new_name' => 'Chips'])->assertOk();
        $this->putJson("/api/imports/$id/rows/$pretzels", [...$new, 'new_name' => 'Pretzels'])->assertOk()->assertJsonPath('data.summary.new_categories', 1);
        $this->putJson("/api/imports/$id/rows/$skip", [...$new, 'new_name' => 'Skip this'])->assertOk()->assertJsonPath('data.summary.new_categories', 2);
        $this->putJson("/api/imports/$id/rows/$skip", ['decision' => 'skip'])->assertOk()->assertJsonPath('data.summary.new_categories', 1);
        $revision = $this->putJson("/api/imports/$id/rows/$reserved", ['decision' => 'skip'])->assertOk()->json('data.setup.revision');
        $this->assertDatabaseCount('categories', 0);
        $this->postJson("/api/imports/$id/approve", ['revision' => $revision])->assertOk()->assertJsonPath('data.new_categories', 1);
        $category = DB::table('categories')->where('name', 'Snacks & Biscuits')->value('id');
        $this->assertDatabaseCount('categories', 1);
        $this->getJson("/api/imports/$id")->assertOk()->assertJsonPath('data.rows.0.category_review.selected.id', $category);

        $later = $this->draft($this->workbook([
            ['Product', 'Quantity', 'Category', 'Item cost'],
            ['Chips', 4, '', 3],
            ['Crackers', 2, 'snacks & biscuits'],
        ]), 1, 1, [...$mapping, 'unit_cost' => 'D']);
        $laterId = $later['id'];
        $crackers = $later['rows'][1]['id'];
        $this->assertSame($category, $later['rows'][0]['category_review']['selected']['id']);
        $this->assertNull($later['rows'][0]['category_review']['warning']);
        $this->assertSame($category, $later['rows'][1]['category_review']['suggestion']['id']);
        $this->putJson("/api/imports/$laterId/rows/{$later['rows'][0]['id']}", [
            'decision' => 'existing', 'product_id' => $later['rows'][0]['product_id'], 'price_decision' => 'keep',
        ])->assertOk();
        $revision = $this->putJson("/api/imports/$laterId/rows/$crackers", [...$new, 'new_name' => 'Crackers'])
            ->assertOk()->assertJsonPath('data.summary.new_categories', 0)->json('data.setup.revision');
        $this->postJson("/api/imports/$laterId/approve", ['revision' => $revision])->assertOk()->assertJsonPath('data.new_categories', 0);
        $this->assertDatabaseCount('categories', 1);
        $this->assertDatabaseHas('products', ['name' => 'Chips', 'category_id' => $category, 'stock' => 6]);
        $this->assertDatabaseHas('products', ['name' => 'Crackers', 'category_id' => $category, 'stock' => 2]);
    }

    public function test_purchase_cost_uses_precise_item_cost_and_ignores_workbook_subtotal_formulas(): void
    {
        Storage::fake('local');
        $this->actingAs($this->account('owner'), 'web');
        $mapping = ['name' => 'A', 'quantity' => 'B', 'unit_cost' => 'C'];
        foreach (['D', 'C'] as $column) {
            $file = $this->workbook([
                ['Product', 'Quantity', 'Cost per Item', 'Total Purchase'],
                ['Crackers', 5, '10.199999999999999', '=B2*C2'],
                ['Water', 2, 2.25, '=$C3 * $B3'],
                ['TOTAL PURCHASE', ' ', ' ', "=SUM({$column}2:{$column}3)"],
            ]);
            $preview = $this->draft($file, 1, 1, $mapping);
            $this->assertSame(2, $preview['summary']['total_rows']);
            $this->assertSame('10.199999999999999', $preview['rows'][0]['stock_in']['item_cost']);
            $this->assertSame('51.00', $preview['rows'][0]['purchase']['total_cost']);
            $this->assertSame('4.50', $preview['rows'][1]['purchase']['total_cost']);
            $this->assertSame(2, $preview['summary']['unmatched_without_price']);
            $this->assertSame([], $preview['rows'][0]['warnings']);
        }
    }

    public function test_bulk_markup_is_limited_to_selected_draft_rows_and_individual_prices_remain_editable(): void
    {
        Storage::fake('local');
        $owner = $this->account('owner');
        $cashier = $this->account('cashier');
        $this->actingAs($owner, 'web');
        $category = $this->postJson('/api/categories', ['name' => 'Drinks'])->assertCreated()->json('data.id');
        $water = $this->product('WATER', 'Water', $category, 5);
        $preview = $this->draft($this->workbook([
            ['Product', 'Quantity', 'Cost per Item'],
            ['Water', 2, 5],
            ['Orange Juice', 3, 15],
            ['Cola', 1, 40],
        ]), 1, 1, ['name' => 'A', 'quantity' => 'B', 'unit_cost' => 'C']);
        $id = $preview['id'];
        [$existing, $new, $unselected] = array_column($preview['rows'], 'id');
        $bulk = ['revision' => $preview['setup']['revision'], 'rows' => [$existing, $new], 'markup_percentage' => '25'];
        $this->actingAs($cashier, 'web')->putJson("/api/imports/$id/markup", $bulk)->assertForbidden();
        $this->actingAs($owner, 'web')->putJson("/api/imports/$id/markup", [...$bulk, 'rows' => [$existing, 99999999]])->assertUnprocessable();
        $this->getJson("/api/imports/$id")->assertOk()->assertJsonPath('data.rows.0.markup_percentage', null);
        $revision = $this->putJson("/api/imports/$id/markup", $bulk)->assertOk()
            ->assertJsonPath('data.rows.0.suggested_selling_price', '7.00')
            ->assertJsonPath('data.rows.1.suggested_selling_price', '19.00')
            ->assertJsonPath('data.rows.2.markup_percentage', null)
            ->assertJsonPath('data.summary.price_review_rows', 1)->json('data.setup.revision');
        $this->putJson("/api/imports/$id/markup", [
            'revision' => $revision, 'rows' => [$unselected, $existing], 'markup_percentage' => '50',
        ])->assertUnprocessable()->assertJsonValidationErrors('rows');
        $this->getJson("/api/imports/$id")->assertOk()
            ->assertJsonPath('data.rows.0.suggested_selling_price', '7.00')
            ->assertJsonPath('data.rows.2.markup_percentage', null);
        $this->getJson('/api/imports?include_drafts=1')->assertOk()->assertJsonPath('data.0.id', $id);
        $this->assertDatabaseMissing('inventory_imports', ['id' => $id]);
        $this->assertDatabaseMissing('inventory_import_rows', ['inventory_import_id' => $id]);
        $this->putJson("/api/imports/$id/markup", $bulk)->assertConflict();
        $this->assertDatabaseHas('products', ['id' => $water, 'stock' => 5, 'price' => '10.00']);
        $this->assertDatabaseCount('stock_movements', 1);
        $this->putJson("/api/imports/$id/rows/$new", [
            'decision' => 'new', 'new_name' => 'Orange Juice', 'category_id' => $category,
            'markup_percentage' => '30', 'approved_selling_price' => '19.20',
        ])->assertOk()->assertJsonPath('data.rows.1.suggested_selling_price', '20.00')
            ->assertJsonPath('data.rows.1.purchase.expected_gross_profit', '12.60');
        $revision = $this->putJson("/api/imports/$id/rows/$unselected", ['decision' => 'skip'])->assertOk()->json('data.setup.revision');
        $this->postJson("/api/imports/$id/approve", ['revision' => $revision])->assertUnprocessable()->assertJsonValidationErrors('rows');
        $revision = $this->putJson("/api/imports/$id/rows/$existing", [
            'decision' => 'existing', 'product_id' => $water, 'price_decision' => 'keep',
        ])->assertOk()->assertJsonPath('data.summary.price_review_rows', 0)->json('data.setup.revision');
        $this->assertDatabaseMissing('inventory_imports', ['id' => $id]);
        $this->postJson("/api/imports/$id/approve", ['revision' => $revision])->assertOk()
            ->assertJsonPath('data.entries.0.selling_price', '10.00')
            ->assertJsonPath('data.entries.1.selling_price', '19.20');
        $this->assertDatabaseHas('products', ['id' => $water, 'stock' => 7, 'price' => '10.00', 'markup_percentage' => '0']);
        $this->assertDatabaseHas('products', ['name' => 'Orange Juice', 'stock' => 3, 'price' => '19.20', 'markup_percentage' => '30']);
        $this->getJson('/api/dashboard/stock-financials')->assertOk()->assertJsonPath('data.stock_investment', '55.00')
            ->assertJsonPath('data.expected_sales', '77.60')->assertJsonPath('data.expected_gross_profit', '22.60')
            ->assertJsonPath('data.remaining_stock_value', '77.50');
    }

    private function draft(UploadedFile $file, int $sheet, int $header, array $mapping): array
    {
        $upload = $this->postJson('/api/imports/upload', ['file' => $file])->assertCreated()
            ->assertJsonPath('data.setup.sheets.1.name', 'Purchases')->assertJsonMissingPath('data.file_path');
        $id = $upload->json('data.id');
        $this->getJson("/api/imports/$id/worksheet?sheet=$sheet")->assertOk()->assertJsonPath('data.header', $header);

        return $this->putJson("/api/imports/$id/mapping", compact('sheet', 'header', 'mapping'))->assertOk()->json('data');
    }

    public function test_recognized_upload_goes_directly_to_review_using_product_names(): void
    {
        Storage::fake('local');
        $this->actingAs($this->account('owner'), 'web');
        $category = $this->postJson('/api/categories', ['name' => 'Drinks'])->assertCreated()->json('data.id');
        $water = $this->product('WATER', 'Mineral Water', $category, 5);
        $recognized = [
            ['Product', 'Quantity', 'SKU'],
            [' MINERAL  WATER ', 3, 'IGNORED-CODE'],
        ];
        $this->postJson('/api/imports/upload', ['file' => $this->workbook($recognized, withCover: false), 'auto_review' => '1'])
            ->assertCreated()->assertJsonPath('data.status', 'review')->assertJsonPath('data.setup.mapping.name', 'A')
            ->assertJsonMissingPath('data.setup.mapping.sku')->assertJsonPath('data.rows.0.product_id', $water)
            ->assertJsonPath('data.rows.0.matching_method', 'name');
        $this->assertDatabaseHas('products', ['id' => $water, 'stock' => 5]);
        $this->assertDatabaseCount('stock_movements', 1);

        $this->postJson('/api/imports/upload', ['file' => $this->workbook($recognized), 'auto_review' => '1'])
            ->assertCreated()->assertJsonPath('data.status', 'uploaded')->assertJsonCount(2, 'data.setup.sheets');
        $unknown = $this->workbook([['Custom label', 'Custom count'], ['Mineral Water', 3]], withCover: false);
        $this->postJson('/api/imports/upload', ['file' => $unknown, 'auto_review' => '1'])
            ->assertCreated()->assertJsonPath('data.status', 'uploaded')->assertJsonMissingPath('data.setup.sheet.mapping.name');
    }

    private function account(string $role): User
    {
        $this->seed();

        return User::factory()->create(['role_id' => Role::query()->where('name', $role)->sole()->id]);
    }

    public function actingAs(Authenticatable $user, $guard = null): static
    {
        Auth::forgetGuards();

        return parent::actingAs($user, $guard);
    }

    private function product(string $sku, string $name, int $category, int $stock): int
    {
        return $this->postJson('/api/products', ['sku' => $sku, 'name' => $name, 'category_id' => $category, 'stock' => $stock, 'cost' => '4.50', 'price' => '10.00'])
            ->assertCreated()->json('data.id');
    }

    private function workbook(array $rows, bool $withCover = true): UploadedFile
    {
        $path = tempnam(sys_get_temp_dir(), 'tri-m-supplier-');
        $this->beforeApplicationDestroyed(fn () => unlink($path));
        $writer = new Writer;
        $writer->openToFile($path);
        if ($withCover) {
            $writer->getCurrentSheet()->setName('Cover');
            $writer->addRow(Row::fromValues(['Supplier purchase workbook']));
            $writer->addNewSheetAndMakeItCurrent();
        }
        $writer->getCurrentSheet()->setName('Purchases');
        foreach ($rows as $row) {
            $writer->addRow(Row::fromValues($row));
        }
        $writer->close();

        return new UploadedFile($path, 'supplier.xlsx', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', test: true);
    }
}
