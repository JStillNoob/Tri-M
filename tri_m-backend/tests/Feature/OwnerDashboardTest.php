<?php

namespace Tests\Feature;

use App\Models\Category;
use App\Models\Role;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

class OwnerDashboardTest extends TestCase
{
    use LazilyRefreshDatabase;

    public function test_overview_reuses_aggregates_and_checkout_invalidates_the_cached_report(): void
    {
        [$owner, $cashier] = $this->accounts();
        $product = $this->product();
        $this->sale($cashier, '2026-04-15 10:00:00', 'FIRST', [[$product, 1, 15]]);

        DB::enableQueryLog();
        $this->actingAs($owner, 'sanctum')->getJson('/api/dashboard/overview')->assertOk()
            ->assertJsonPath('data.sales_total', '15.00');
        $this->assertNotEmpty(array_filter(DB::getQueryLog(), fn ($query) => str_contains($query['query'], 'interval_sales')));
        DB::flushQueryLog();
        $this->getJson('/api/dashboard/overview')->assertOk()->assertJsonPath('data.sales_total', '15.00');
        $this->assertEmpty(array_filter(DB::getQueryLog(), fn ($query) => str_contains($query['query'], 'interval_sales')));
        DB::disableQueryLog();

        $checkout = [
            'idempotency_key' => (string) Str::uuid(),
            'items' => [['product_id' => $product, 'quantity' => 1]],
            'payment' => ['method' => 'cash', 'amount_tendered' => '20.00'],
        ];
        $this->actingAs($cashier, 'sanctum')->postJson('/api/sales', $checkout)->assertCreated();
        $this->actingAs($owner, 'sanctum')->getJson('/api/dashboard/overview')->assertOk()
            ->assertJsonPath('data.sales_total', '22.00');
    }

    public function test_catalog_can_fetch_only_selected_active_products_and_filter_price(): void
    {
        [$owner, $cashier] = $this->accounts();
        $first = $this->product('FIRST');
        $second = $this->product('SECOND');
        $excluded = $this->product('UNSELECTED');
        DB::table('products')->where('id', $second)->update(['price' => '12']);
        DB::table('products')->where('id', $excluded)->update(['price' => '9']);

        $this->actingAs($cashier, 'sanctum')->getJson("/api/products?ids[]=$first&ids[]=$second&max_price=10")
            ->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.id', $first)->assertJsonMissingPath('data.0.cost');
        $this->getJson('/api/products?sort=price-high&per_page=1')->assertOk()
            ->assertJsonPath('data.0.id', $second)->assertJsonPath('meta.total', 3);
        $this->actingAs($owner, 'sanctum')->deleteJson("/api/products/$second")->assertOk();
        $this->actingAs($cashier, 'sanctum')->getJson("/api/products?ids[]=$second")->assertOk()->assertJsonCount(0, 'data');
    }

    public function test_today_overview_uses_paid_receipts_local_hours_and_category_line_totals(): void
    {
        [$owner, $cashier] = $this->accounts();
        $drinks = Category::create(['name' => 'Drinks']);
        $snacks = Category::create(['name' => 'Snacks']);
        $empty = Category::create(['name' => 'No sales']);
        $drink = $this->product('DRINK', $drinks->id);
        $snack = $this->product('SNACK', $snacks->id);
        $this->sale($cashier, '2026-04-14 11:30:00', 'PREVIOUS', [[$snack, 2, 5]]);
        $this->sale($cashier, '2026-04-14 13:00:00', 'AFTER-CUTOFF', [[$snack, 1, 50]]);
        $this->sale($cashier, '2026-04-15 00:00:00', 'MIXED', [[$snack, 2, 10], [$drink, 1, 30]]);
        $this->sale($cashier, '2026-04-15 09:10:00', 'WALLET', [[$drink, 2, 10]], 'ewallet');
        $this->sale($cashier, '2026-04-15 11:59:00', 'UNPAID', [[$snack, 1, 99]], null);
        $this->sale($cashier, '2026-04-15 12:31:00', 'FUTURE', [[$snack, 1, 99]]);
        DB::table('products')->where('id', $snack)->update(['price' => 999, 'deleted_at' => now()]);

        $this->actingAs($owner, 'sanctum')->getJson('/api/dashboard/overview?period=today')->assertOk()
            ->assertJsonPath('data.sales_total', '70.00')
            ->assertJsonPath('data.total_orders', 2)
            ->assertJsonPath('data.average_sale_value', '35.00')
            ->assertJsonPath('data.average_items_per_sale', '2.50')
            ->assertJsonPath('data.previous_sales_total', '10.00')
            ->assertJsonPath('data.sales_difference', '60.00')
            ->assertJsonPath('data.sales_percentage_change', '600.00')
            ->assertJsonCount(13, 'data.trend')
            ->assertJsonPath('data.trend.0.start', '2026-04-15T00:00:00+08:00')
            ->assertJsonPath('data.trend.0.sales', '50.00')
            ->assertJsonPath('data.trend.1.sales', '0.00')
            ->assertJsonPath('data.trend.9.sales', '20.00')
            ->assertJsonPath('data.trend.12.cumulative_sales', '70.00');

        $this->getJson("/api/dashboard/overview?category_id={$snacks->id}")->assertOk()
            ->assertJsonPath('data.sales_total', '20.00')
            ->assertJsonPath('data.total_orders', 1)
            ->assertJsonPath('data.average_items_per_sale', '2.00')
            ->assertJsonPath('data.retail_metrics.gross_profit', '16.00')
            ->assertJsonPath('data.retail_metrics.unique_products', 1)
            ->assertJsonPath('data.retail_metrics.best_seller.product_id', $snack)
            ->assertJsonPath('data.retail_metrics.payments.0.total', '20.00')
            ->assertJsonPath('data.retail_metrics.payments.1.total', '0.00');

        $this->getJson("/api/dashboard/overview?category_id={$empty->id}")->assertOk()
            ->assertJsonPath('data.sales_total', '0.00')
            ->assertJsonPath('data.previous_sales_total', null)
            ->assertJsonPath('data.sales_percentage_change', null)
            ->assertJsonPath('data.retail_metrics.best_seller', null);

        $this->getJson('/api/dashboard/recent-orders?limit=2')->assertOk()->assertJsonCount(2, 'data')
            ->assertJsonPath('data.0.receipt_no', 'WALLET')
            ->assertJsonPath('data.1.receipt_no', 'MIXED')
            ->assertJsonPath('data.1.total', '50.00');
    }

    public function test_calendar_week_and_month_compare_at_the_same_local_time(): void
    {
        [$owner, $cashier] = $this->accounts();
        $product = $this->product();
        foreach ([
            'MAR-START' => ['2026-03-01 00:00:00', 30],
            'MAR-BEFORE' => ['2026-03-15 11:00:00', 20],
            'MAR-AFTER' => ['2026-03-15 13:00:00', 5],
            'APR-START' => ['2026-04-01 00:00:00', 60],
            'WEEK-PRIOR' => ['2026-04-06 09:00:00', 15],
            'WEEK-LATE' => ['2026-04-08 13:00:00', 200],
            'SUNDAY' => ['2026-04-12 23:59:59', 35],
            'MONDAY' => ['2026-04-13 00:00:00', 70],
        ] as $receipt => [$created, $amount]) {
            $this->sale($cashier, $created, $receipt, [[$product, 1, $amount]]);
        }

        $this->actingAs($owner, 'sanctum')->getJson('/api/dashboard/overview?period=week')->assertOk()
            ->assertJsonPath('data.from', '2026-04-13')->assertJsonPath('data.to', '2026-04-19')
            ->assertJsonPath('data.calendar_days', 7)->assertJsonPath('data.sales_total', '70.00')
            ->assertJsonPath('data.previous_sales_total', '15.00')
            ->assertJsonPath('data.average_daily_sales', '10.00')
            ->assertJsonPath('data.trend.0.sales', '70.00')
            ->assertJsonPath('data.trend.1.sales', '0.00');

        $this->getJson('/api/dashboard/overview?period=month')->assertOk()
            ->assertJsonPath('data.from', '2026-04-01')->assertJsonPath('data.to', '2026-04-30')
            ->assertJsonPath('data.calendar_days', 30)->assertJsonPath('data.sales_total', '380.00')
            ->assertJsonPath('data.previous_sales_total', '50.00')
            ->assertJsonPath('data.average_daily_sales', '12.67')
            ->assertJsonPath('data.trend.0.sales', '60.00');
    }

    public function test_monthly_target_is_owner_only_validated_and_saved_for_the_store_month(): void
    {
        $this->getJson('/api/dashboard/targets')->assertUnauthorized();
        [$owner, $cashier] = $this->accounts();
        $this->actingAs($cashier, 'sanctum')->getJson('/api/dashboard/overview')->assertForbidden();
        $this->getJson('/api/dashboard/recent-orders')->assertForbidden();
        $this->getJson('/api/dashboard/targets')->assertForbidden();
        $this->putJson('/api/dashboard/targets', ['month' => '2026-04', 'amount' => '100'])->assertForbidden();
        $this->sale($cashier, '2026-04-03 09:00:00', 'PAID', [[$this->product(), 4, 25]]);

        $this->actingAs($owner, 'sanctum')->getJson('/api/dashboard/targets')->assertOk()
            ->assertJsonPath('data.month', '2026-04')->assertJsonPath('data.amount', null)
            ->assertJsonPath('data.actual_sales', '100.00')->assertJsonPath('data.achievement_percentage', null);
        $this->putJson('/api/dashboard/targets', ['month' => '2026-04', 'amount' => '-5'])->assertJsonValidationErrors('amount');
        $this->putJson('/api/dashboard/targets', ['month' => '2026-03', 'amount' => '100'])->assertJsonValidationErrors('month');
        $this->getJson('/api/dashboard/overview?period=7d&category_id=9999')->assertJsonValidationErrors(['period', 'category_id']);
        $this->putJson('/api/dashboard/targets', ['month' => '2026-04', 'amount' => '250.00'])->assertOk()
            ->assertJsonPath('data.achievement_percentage', '40.00');
        $this->putJson('/api/dashboard/targets', ['month' => '2026-04', 'amount' => '80.00'])->assertOk()
            ->assertJsonPath('data.achievement_percentage', '125.00');
        $this->assertDatabaseCount('sales_targets', 1);
        $this->getJson('/api/dashboard/targets')->assertOk()->assertJsonPath('data.amount', '80.00');
        $this->travelTo(CarbonImmutable::parse('2026-05-01 00:00:00', 'Asia/Manila'));
        $this->getJson('/api/dashboard/targets')->assertOk()->assertJsonPath('data.month', '2026-05')
            ->assertJsonPath('data.amount', null);
    }

    public function test_low_stock_paginates_urgent_active_products_using_the_configured_threshold(): void
    {
        [$owner] = $this->accounts();
        config(['pos.low_stock_threshold' => 4]);
        $this->product('ABOVE', stock: 5);
        $this->product('AT', stock: 4);
        $this->product('URGENT', stock: 2);
        $this->product('OUT', stock: 0);
        $archived = $this->product('ARCHIVED', stock: 0);
        DB::table('products')->where('id', $archived)->update(['deleted_at' => now()]);

        $this->actingAs($owner, 'sanctum')->getJson('/api/dashboard/low-stock?per_page=2')->assertOk()
            ->assertJsonCount(2, 'data')->assertJsonPath('meta.total', 3)->assertJsonPath('meta.threshold', 4)
            ->assertJsonPath('data.0.sku', 'OUT')->assertJsonPath('data.0.stock_status', 'out_of_stock')
            ->assertJsonPath('data.1.sku', 'URGENT')->assertJsonPath('data.1.low_stock_threshold', 4);
        $this->getJson('/api/dashboard/low-stock?per_page=2&page=2')->assertOk()
            ->assertJsonPath('data.0.sku', 'AT')->assertJsonPath('data.0.stock_status', 'low_stock');
    }

    private function accounts(): array
    {
        $this->travelTo(CarbonImmutable::parse('2026-04-15 12:30:00', 'Asia/Manila'));
        $this->seed();

        return array_map(fn (string $role) => User::factory()->create([
            'role_id' => Role::query()->where('name', $role)->value('id'),
        ]), ['owner', 'cashier']);
    }

    private function product(string $sku = 'TEST', ?int $category = null, int $stock = 10): int
    {
        return DB::table('products')->insertGetId([
            'sku' => $sku,
            'name' => $sku,
            'category_id' => $category,
            'cost' => 2,
            'price' => 7,
            'stock' => $stock,
        ]);
    }

    private function sale(User $cashier, string $date, string $receipt, array $items, ?string $method = 'cash'): void
    {
        $total = array_sum(array_map(fn ($item) => $item[1] * $item[2], $items));
        $saleId = DB::table('sales')->insertGetId([
            'cashier_id' => $cashier->id,
            'receipt_no' => $receipt,
            'created_at' => CarbonImmutable::parse($date, 'Asia/Manila')->utc()->toDateTimeString(),
            'total' => $total,
        ]);

        foreach ($items as [$id, $quantity, $price]) {
            DB::table('sale_items')->insert([
                'sale_id' => $saleId,
                'product_id' => $id,
                'product_name' => 'Snapshot '.$id,
                'quantity' => $quantity,
                'unit_price' => $price,
                'unit_cost' => 2,
            ]);
        }

        if ($method !== null) {
            DB::table('payments')->insert([
                'sale_id' => $saleId,
                'payment_method_id' => DB::table('payment_methods')->where('name', $method)->value('id'),
                'amount_paid' => $total,
                'amount_tendered' => $method === 'cash' ? $total : null,
                'change_due' => $method === 'cash' ? 0 : null,
                'wallet_provider_id' => $method === 'ewallet' ? DB::table('wallet_providers')->where('name', 'gcash')->value('id') : null,
                'reference_no' => $method === 'ewallet' ? 'TEST-PAYMENT' : null,
            ]);
        }
    }
}
