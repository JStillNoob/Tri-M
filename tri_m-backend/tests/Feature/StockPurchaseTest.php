<?php

namespace Tests\Feature;

use App\Models\Role;
use App\Models\User;
use App\Services\Inventory;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

class StockPurchaseTest extends TestCase
{
    use LazilyRefreshDatabase;

    public function test_owner_records_purchases_by_product_name_and_cashier_sees_only_sale_fields(): void
    {
        $this->actingAs($this->account('owner'), 'web');
        $category = $this->postJson('/api/categories', ['name' => 'Pantry'])->assertCreated()->json('data.id');
        $supplier = $this->postJson('/api/suppliers', ['name' => ' Great  Wall '])->assertCreated()->json('data.id');
        $this->postJson('/api/suppliers', ['name' => 'great wall'])->assertOk()->assertJsonPath('data.id', $supplier);
        $key = (string) Str::uuid();
        $purchase = [
            'purchase_date' => '2026-11-07', 'product_name' => 'Brown Rice', 'supplier_id' => $supplier, 'category_id' => $category,
            'quantity' => 3, 'unit_cost' => '10.25', 'markup_percentage' => '33.3333', 'selling_price' => '13.77',
            'total_purchase' => '1.00',
        ];
        $saved = $this->postJson('/api/stock-purchases', $purchase, ['Idempotency-Key' => $key])->assertCreated()
            ->assertJsonPath('data.total_purchase', '30.75')->assertJsonPath('data.selling_price', '13.77')
            ->assertJsonPath('data.markup_percentage', '33.3333')->assertJsonPath('data.supplier.name', 'Great Wall');
        $product = $saved->json('data.product_id');
        $this->postJson('/api/stock-purchases', $purchase, ['Idempotency-Key' => $key])->assertOk()->assertJsonPath('data.id', $saved->json('data.id'));
        $this->postJson('/api/stock-purchases', [...$purchase, 'quantity' => 99], ['Idempotency-Key' => $key])->assertConflict();
        $this->getJson('/api/stock-purchases/product?name=brown%20%20rice')->assertOk()->assertJsonPath('data.id', $product)->assertJsonPath('data.stock', 3);
        $restock = [...$purchase, 'purchase_date' => '2026-11-08', 'product_name' => ' BROWN  RICE ', 'category_id' => null, 'quantity' => 2,
            'unit_cost' => '11.16', 'markup_percentage' => '40', 'selling_price' => '15.63', 'update_catalog' => true];
        $this->postJson('/api/stock-purchases', $restock, ['Idempotency-Key' => (string) Str::uuid()])
            ->assertCreated()->assertJsonPath('data.product_id', $product)->assertJsonPath('data.total_purchase', '22.32');
        $this->getJson('/api/stock-purchases')->assertOk()->assertJsonCount(2, 'data')
            ->assertJsonPath('data.0.category_name', 'Pantry')->assertJsonPath('data.1.selling_price', '13.77')->assertJsonPath('data.1.unit_cost', '10.25');
        $this->assertDatabaseCount('products', 1);
        $this->assertDatabaseHas('products', ['id' => $product, 'stock' => 5, 'price' => '15.63', 'cost' => '10.61', 'inventory_value' => '53.07', 'markup_percentage' => '40.0000']);
        $this->assertDatabaseHas('stock_movements', ['product_id' => $product, 'previous_stock' => 3, 'resulting_stock' => 5]);

        $this->actingAs($this->account('cashier'), 'web');
        $this->getJson('/api/stock-purchases')->assertForbidden();
        $this->getJson('/api/stock-purchases/product?name=Rice')->assertForbidden();
        $this->postJson('/api/stock-purchases', $purchase)->assertForbidden();
        $this->getJson('/api/suppliers')->assertForbidden();
        $this->postJson('/api/suppliers', ['name' => 'Another'])->assertForbidden();
        $this->getJson("/api/products/$product")->assertOk()->assertJsonPath('data.price', '15.63')->assertJsonPath('data.stock', 5)
            ->assertJsonMissingPath('data.cost')->assertJsonMissingPath('data.sku')->assertJsonMissingPath('data.supplier')->assertJsonMissingPath('data.markup_percentage');
        $this->postJson('/api/sales', [
            'items' => [['product_id' => $product, 'quantity' => 1]],
            'payment' => ['method' => 'cash', 'amount_tendered' => '20.00'],
        ], ['Idempotency-Key' => (string) Str::uuid()])->assertCreated()
            ->assertJsonPath('data.total', '15.63')->assertJsonMissingPath('data.items.0.unit_cost')
            ->assertJsonMissingPath('data.items.0.cost_of_goods_sold');
        $this->assertDatabaseHas('products', ['id' => $product, 'stock' => 4]);
    }

    public function test_restock_preserves_approved_prices_and_keeps_prior_sale_costs(): void
    {
        $owner = $this->account('owner');
        $cashier = $this->account('cashier');
        $this->actingAs($owner, 'web');
        $category = $this->postJson('/api/categories', ['name' => 'Drinks'])->assertCreated()->json('data.id');
        $supplier = $this->postJson('/api/suppliers', ['name' => 'Supplier'])->assertCreated()->json('data.id');
        $purchase = [
            'purchase_date' => '2026-11-07', 'product_name' => 'Cola', 'supplier_id' => $supplier,
            'category_id' => $category, 'quantity' => 20, 'unit_cost' => '40.00', 'markup_percentage' => '25',
            'selling_price' => '50.00',
        ];
        $product = $this->postJson('/api/stock-purchases', $purchase, ['Idempotency-Key' => (string) Str::uuid()])
            ->assertCreated()->assertJsonPath('data.expected_sales', '1000.00')
            ->assertJsonPath('data.expected_gross_profit', '200.00')->json('data.product_id');

        $this->actingAs($cashier, 'web')->getJson('/api/dashboard/stock-financials')->assertForbidden();
        $sale = [
            'items' => [['product_id' => $product, 'quantity' => 4]],
            'payment' => ['method' => 'cash', 'amount_tendered' => '200.00'],
        ];
        $before = $this->postJson('/api/sales', $sale, ['Idempotency-Key' => (string) Str::uuid()])
            ->assertCreated()->assertJsonMissingPath('data.items.0.cost_of_goods_sold')->json('data.id');

        $this->actingAs($owner, 'web')->postJson('/api/stock-purchases', [
            ...$purchase, 'unit_cost' => '45.00', 'markup_percentage' => '35', 'selling_price' => '60.75',
        ], ['Idempotency-Key' => (string) Str::uuid()])
            ->assertCreated()->assertJsonPath('data.selling_price', '50.00');
        $this->getJson("/api/products/$product")->assertOk()
            ->assertJsonPath('data.markup_percentage', '25.0000')->assertJsonPath('data.price', '50.00')
            ->assertJsonPath('data.stock', 36)->assertJsonPath('data.cost', '42.78');
        $this->actingAs($cashier, 'web');
        $sale['items'][0]['quantity'] = 3;
        $after = $this->postJson('/api/sales', $sale, ['Idempotency-Key' => (string) Str::uuid()])
            ->assertCreated()->assertJsonPath('data.total', '150.00')->json('data.id');

        $this->actingAs($owner, 'web');
        $this->getJson("/api/sales/$before")->assertOk()->assertJsonPath('data.items.0.cost_of_goods_sold', '160.00');
        $this->getJson("/api/sales/$after")->assertOk()->assertJsonPath('data.items.0.cost_of_goods_sold', '128.33');
        $this->getJson('/api/dashboard/stock-financials')->assertOk()->assertJsonPath('data.stock_investment', '1700.00')
            ->assertJsonPath('data.expected_sales', '2000.00')->assertJsonPath('data.expected_gross_profit', '300.00')
            ->assertJsonPath('data.actual_sales', '350.00')->assertJsonPath('data.actual_gross_profit', '61.67')
            ->assertJsonPath('data.remaining_stock_value', '1411.67');
        $this->assertDatabaseCount('products', 1);
        $this->assertDatabaseCount('stock_purchases', 2);
    }

    public function test_validation_and_stock_failure_leave_the_catalog_and_purchase_history_consistent(): void
    {
        $this->getJson('/api/stock-purchases')->assertUnauthorized();
        $this->actingAs($this->account('owner'), 'web');
        $category = $this->postJson('/api/categories', ['name' => 'Drinks'])->assertCreated()->json('data.id');
        $supplier = $this->postJson('/api/suppliers', ['name' => 'Supplier'])->assertCreated()->json('data.id');
        $catalog = ['name' => 'Water', 'category_id' => $category, 'cost' => '7.00', 'price' => '12.00'];
        $product = $this->postJson('/api/products', [...$catalog, 'stock' => Inventory::MAX_STOCK])->assertCreated()->json('data.id');
        $this->postJson('/api/products', [...$catalog, 'name' => 'WATER'])->assertUnprocessable()->assertJsonValidationErrors('name');
        $headers = ['Idempotency-Key' => (string) Str::uuid()];
        $input = [
            'purchase_date' => '2026-11-07', 'product_name' => 'Water', 'supplier_id' => $supplier, 'category_id' => null,
            'quantity' => 1, 'unit_cost' => '10.00', 'markup_percentage' => '50', 'selling_price' => '16.00',
        ];
        $this->postJson('/api/stock-purchases', [...$input, 'selling_price' => '16.001'], $headers)
            ->assertUnprocessable()->assertJsonValidationErrors('selling_price');
        $this->postJson('/api/stock-purchases', [...$input, 'product_name' => 'Juice'], $headers)
            ->assertUnprocessable()->assertJsonValidationErrors('category_id');
        $this->postJson('/api/stock-purchases', $input, $headers)->assertUnprocessable()->assertJsonValidationErrors('stock');
        $this->assertDatabaseCount('stock_purchases', 0);
        $this->assertDatabaseHas('products', ['id' => $product, 'price' => '12.00', 'stock' => Inventory::MAX_STOCK]);
        $this->assertDatabaseCount('stock_movements', 1);
        $this->deleteJson("/api/products/$product")->assertOk();
        $this->postJson('/api/stock-purchases', [...$input, 'category_id' => $category], $headers)
            ->assertUnprocessable()->assertJsonValidationErrors('product_name');
        $this->assertSame(1, DB::table('products')->count());
    }

    private function account(string $role): User
    {
        $this->seed();

        return User::factory()->create(['role_id' => Role::where('name', $role)->sole()->id]);
    }

    public function actingAs(Authenticatable $user, $guard = null): static
    {
        Auth::forgetGuards();

        return parent::actingAs($user, $guard);
    }
}
