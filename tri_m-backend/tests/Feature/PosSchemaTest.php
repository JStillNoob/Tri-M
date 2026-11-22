<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class PosSchemaTest extends TestCase
{
    use LazilyRefreshDatabase;

    public function test_seeding_twice_keeps_one_copy_of_each_lookup(): void
    {
        $this->seed()->seed();

        $this->assertSame(['cashier', 'owner'], DB::table('roles')->orderBy('name')->pluck('name')->all());
        $this->assertSame(['cash', 'ewallet'], DB::table('payment_methods')->orderBy('name')->pluck('name')->all());
        $this->assertSame(['gcash', 'maya'], DB::table('wallet_providers')->orderBy('name')->pluck('name')->all());
        $this->assertSame(['adjustment', 'opening', 'restock', 'sale'], DB::table('stock_movement_types')->orderBy('name')->pluck('name')->all());
        $this->assertDatabaseCount('users', 0);
    }

    public function test_user_factory_creates_a_cashier_with_separate_name_fields(): void
    {
        $user = User::factory()->create();

        $this->assertNotEmpty($user->first_name);
        $this->assertNotEmpty($user->last_name);
        $this->assertSame('cashier', $user->role->name);
    }

    public function test_sale_line_retains_snapshot_when_product_changes(): void
    {
        $cashier = User::factory()->create();
        $productId = $this->createProduct();
        $saleId = $this->createSale($cashier);

        DB::table('sale_items')->insert([
            'sale_id' => $saleId,
            'product_id' => $productId,
            'product_name' => 'Bottled Water',
            'unit_price' => '18.00',
            'unit_cost' => '10.00',
            'quantity' => 1,
        ]);
        DB::table('products')->where('id', $productId)->update([
            'name' => 'Mineral Water',
            'price' => '20.00',
            'deleted_at' => now(),
        ]);

        $this->assertDatabaseHas('sale_items', [
            'sale_id' => $saleId,
            'product_id' => $productId,
            'product_name' => 'Bottled Water',
            'unit_price' => '18.00',
            'unit_cost' => '10.00',
        ]);
    }

    public function test_sale_accepts_only_one_payment(): void
    {
        $this->seed();
        $cashier = User::factory()->create();
        $payment = [
            'sale_id' => $this->createSale($cashier),
            'payment_method_id' => DB::table('payment_methods')->where('name', 'cash')->value('id'),
            'amount_paid' => '18.00',
            'amount_tendered' => '20.00',
            'change_due' => '2.00',
        ];
        DB::table('payments')->insert($payment);

        $this->expectException(QueryException::class);
        DB::table('payments')->insert($payment);
    }

    public function test_postgresql_rejects_negative_stock(): void
    {
        $this->requirePostgres();
        $productId = $this->createProduct();

        $this->expectException(QueryException::class);
        $this->expectExceptionMessage('products_values_check');
        DB::table('products')->where('id', $productId)->update(['stock' => -1]);
    }

    public function test_postgresql_rejects_ewallet_payment_with_a_blank_reference(): void
    {
        $this->requirePostgres();
        $this->seed();
        $cashier = User::factory()->create();

        $this->expectException(QueryException::class);
        $this->expectExceptionMessage('payments_details_check');
        DB::table('payments')->insert([
            'sale_id' => $this->createSale($cashier),
            'payment_method_id' => DB::table('payment_methods')->where('name', 'ewallet')->value('id'),
            'wallet_provider_id' => DB::table('wallet_providers')->where('name', 'gcash')->value('id'),
            'reference_no' => " \t",
            'amount_paid' => '18.00',
        ]);
    }

    public function test_postgresql_rejects_changes_to_a_recorded_stock_movement(): void
    {
        $this->requirePostgres();
        $this->seed();
        $cashier = User::factory()->create();
        $movementId = DB::table('stock_movements')->insertGetId([
            'product_id' => $this->createProduct(),
            'movement_type_id' => DB::table('stock_movement_types')->where('name', 'opening')->value('id'),
            'user_id' => $cashier->id,
            'quantity' => 5,
        ]);

        $this->expectException(QueryException::class);
        $this->expectExceptionMessage('Stock movements are append-only');
        DB::table('stock_movements')->where('id', $movementId)->update(['quantity' => 6]);
    }

    private function createProduct(): int
    {
        return DB::table('products')->insertGetId([
            'sku' => 'WATER-500',
            'name' => 'Bottled Water',
            'cost' => '10.00',
            'price' => '18.00',
        ]);
    }

    private function createSale(User $cashier): int
    {
        return DB::table('sales')->insertGetId([
            'cashier_id' => $cashier->id,
            'receipt_no' => 'R-0001',
            'total' => '18.00',
        ]);
    }

    private function requirePostgres(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            $this->markTestSkipped('This constraint requires PostgreSQL.');
        }
    }
}
