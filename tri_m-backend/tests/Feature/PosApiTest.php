<?php

namespace Tests\Feature;

use App\Models\Role;
use App\Models\User;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use OpenSpout\Common\Entity\Row;
use OpenSpout\Writer\XLSX\Writer;
use Tests\TestCase;

class PosApiTest extends TestCase
{
    use LazilyRefreshDatabase;

    public function test_session_login_current_user_logout_and_inactive_rejection(): void
    {
        $user = $this->account('cashier');
        config(['sanctum.stateful' => ['localhost:8000']]);
        $this->withHeader('Origin', 'http://localhost:8000');

        $this->getJson('/api/user')->assertUnauthorized()
            ->assertJsonPath('message', 'Your session has expired. Log in again to continue.');
        $this->postJson('/api/login', ['email' => 'bad-email'])->assertUnprocessable()
            ->assertJsonPath('errors.email.0', 'Enter a valid email address.')
            ->assertJsonPath('errors.password.0', 'Enter your password.');
        $this->postJson('/api/login', ['email' => $user->email, 'password' => 'wrong'])->assertUnauthorized()
            ->assertJsonPath('message', 'Check your email and password and try again. If you still cannot sign in, ask the Owner to check your account.');
        $this->postJson('/api/login', ['email' => $user->email, 'password' => 'password'])
            ->assertOk()->assertJsonPath('data.role', 'cashier')->assertJsonMissingPath('data.password');
        $this->getJson('/api/user')->assertOk()->assertJsonPath('data.id', $user->id);
        $this->postJson('/api/logout')->assertOk();
        Auth::forgetGuards();
        $this->getJson('/api/user')->assertUnauthorized();

        $user->forceFill(['is_active' => false])->save();
        $this->postJson('/api/login', ['email' => $user->email, 'password' => 'password'])->assertUnauthorized();
        $this->actingAs($user, 'web')->getJson('/api/products')->assertForbidden();
    }

    public function test_catalog_enforces_owner_mutations_and_keeps_cost_out_of_cashier_responses(): void
    {
        $owner = $this->account('owner');
        $cashier = $this->account('cashier');
        $category = $this->createCategory($owner);
        $product = $this->createProduct($owner, $category);

        $this->assertDatabaseHas('stock_movements', ['product_id' => $product, 'quantity' => 5, 'user_id' => $owner->id]);
        $this->deleteJson("/api/categories/$category")->assertConflict();
        $this->putJson("/api/products/$product", ['sku' => 'WATER', 'name' => 'Water', 'cost' => '10.00', 'price' => '18.00', 'stock' => 50])
            ->assertUnprocessable()->assertJsonValidationErrors('stock');
        $this->actingAs($cashier, 'web')->getJson("/api/products?category_id=$category&search=WATE&availability=in_stock")
            ->assertOk()->assertJsonPath('data.0.id', $product)->assertJsonMissingPath('data.0.cost')->assertJsonPath('meta.total', 1);
        $this->getJson("/api/products/$product")->assertOk()->assertJsonMissingPath('data.cost');
        $this->getJson('/api/products?status=all')->assertForbidden();
        $this->postJson('/api/categories', ['name' => 'Other'])->assertForbidden()
            ->assertJsonPath('message', 'You do not have permission to do this. Ask the Owner for help.');
        $this->getJson('/api/dashboard/summary')->assertForbidden();

        $this->actingAs($owner, 'web')->deleteJson("/api/products/$product")->assertOk();
        $this->actingAs($cashier, 'web')->getJson("/api/products/$product")->assertNotFound();
        $this->actingAs($owner, 'web')->getJson("/api/products/$product")
            ->assertOk()->assertJsonPath('data.archived', true)->assertJsonPath('data.cost', '10.00');
        $this->deleteJson("/api/categories/$category")->assertConflict();
        $this->postJson("/api/products/$product/restore")->assertOk()->assertJsonPath('data.archived', false);
    }

    public function test_checkout_uses_server_prices_and_replays_only_identical_requests(): void
    {
        $owner = $this->account('owner');
        $cashier = $this->account('cashier');
        $product = $this->createProduct($owner);
        $key = (string) Str::uuid();
        $body = [
            'items' => [['product_id' => $product, 'quantity' => 2]],
            'payment' => ['method' => 'cash', 'amount_tendered' => '50.00'],
            'total' => '0.01',
        ];

        $response = $this->actingAs($cashier, 'web')->postJson('/api/sales', $body, ['Idempotency-Key' => $key])
            ->assertCreated()->assertJsonPath('data.total', '36.00')
            ->assertJsonPath('data.payment.change_due', '14.00')->assertJsonMissingPath('data.items.0.unit_cost');
        $sale = $response->json('data.id');
        $receipt = $response->json('data.receipt_no');

        $this->postJson('/api/sales', $body, ['Idempotency-Key' => $key])->assertOk()->assertJsonPath('data.receipt_no', $receipt);
        $this->postJson('/api/sales', [...$body, 'items' => [['product_id' => $product, 'quantity' => 1]]], ['Idempotency-Key' => $key])
            ->assertConflict();
        $this->assertDatabaseCount('sales', 1);
        $this->assertDatabaseCount('payments', 1);
        $this->assertDatabaseHas('products', ['id' => $product, 'stock' => 3]);
        $this->assertDatabaseHas('stock_movements', ['product_id' => $product, 'quantity' => -2, 'user_id' => $cashier->id]);

        $other = $this->account('cashier');
        $this->actingAs($other, 'web')->postJson('/api/sales', $body, ['Idempotency-Key' => $key])->assertConflict();
        $this->getJson("/api/sales/$sale")->assertNotFound();
        $this->getJson('/api/sales')->assertOk()->assertJsonCount(0, 'data');

        $this->actingAs($owner, 'web')->putJson("/api/products/$product", ['sku' => 'WATER', 'name' => 'New name', 'cost' => '15.00', 'price' => '22.00'])
            ->assertOk();
        $this->getJson("/api/sales/$sale")->assertOk()->assertJsonPath('data.items.0.name', 'Water')
            ->assertJsonPath('data.items.0.unit_price', '18.00')->assertJsonPath('data.items.0.unit_cost', '10.00');
        $this->getJson('/api/dashboard/summary')->assertOk()->assertJsonPath('data.gross_profit', '16.00');
    }

    public function test_failed_checkout_rolls_back_and_wallet_details_are_recorded(): void
    {
        $owner = $this->account('owner');
        $cashier = $this->account('cashier');
        $product = $this->createProduct($owner);
        $wallet = ['method' => 'ewallet', 'wallet_provider' => 'maya', 'reference_no' => 'PAY-001'];
        $key = (string) Str::uuid();
        $request = ['idempotency_key' => $key, 'items' => [['product_id' => $product, 'quantity' => 6]], 'payment' => $wallet];

        $this->actingAs($cashier, 'web')->postJson('/api/sales', $request)
            ->assertUnprocessable()->assertJsonValidationErrors('items');
        $this->assertDatabaseCount('sales', 0);
        $this->assertDatabaseCount('stock_movements', 1);
        $this->assertDatabaseHas('products', ['id' => $product, 'stock' => 5]);

        $request['items'][0]['quantity'] = 1;
        $this->postJson('/api/sales', [...$request, 'payment' => ['method' => 'cash', 'amount_tendered' => '1.00']])
            ->assertUnprocessable()->assertJsonValidationErrors('payment.amount_tendered');
        $this->postJson('/api/sales', [...$request, 'payment' => [...$wallet, 'reference_no' => '   ']])
            ->assertUnprocessable()->assertJsonValidationErrors('payment.reference_no');
        $this->postJson('/api/sales', $request)->assertCreated()
            ->assertJsonPath('data.payment.reference_no', 'PAY-001')->assertJsonPath('data.payment.wallet_provider', 'maya');
        $this->getJson('/api/sales?payment_method=ewallet')->assertOk()->assertJsonCount(1, 'data');
    }

    public function test_failure_while_recording_payment_rolls_back_stock_and_sale_records(): void
    {
        $owner = $this->account('owner');
        $cashier = $this->account('cashier');
        $product = $this->createProduct($owner);
        DB::table('wallet_providers')->where('name', 'maya')->delete();

        $this->actingAs($cashier, 'web')->postJson('/api/sales', [
            'idempotency_key' => (string) Str::uuid(),
            'items' => [['product_id' => $product, 'quantity' => 1]],
            'payment' => ['method' => 'ewallet', 'wallet_provider' => 'maya', 'reference_no' => 'PAY-001'],
        ])->assertServerError()->assertExactJson(['pos_error' => true, 'message' => 'Something went wrong. Please try again.']);

        $this->assertDatabaseCount('sales', 0);
        $this->assertDatabaseCount('sale_items', 0);
        $this->assertDatabaseCount('payments', 0);
        $this->assertDatabaseCount('stock_movements', 1);
        $this->assertDatabaseHas('products', ['id' => $product, 'stock' => 5]);
    }

    public function test_import_previews_rows_and_confirmation_rechecks_catalog_and_applies_once(): void
    {
        $owner = $this->account('owner');
        $cashier = $this->account('cashier');
        $category = $this->createCategory($owner);
        $product = $this->createProduct($owner, $category);
        $file = $this->workbook([
            ['sku', 'name', 'category', 'cost', 'price', 'qty'],
            [' WATER ', 'Mineral water', 'Drinks', 11, 19, 2],
            ['WATER', 'Mineral water', 'Drinks', 11, 19, 3],
            ['JUICE', 'Juice', 'Drinks', 35, 49.75, 4],
            ['MISSING', 'Unknown', 'Missing category', 1, 2, 4],
            ['BAD', 'Negative', 'Drinks', 1, 2, -1],
            ['FIGHT', 'First', 'Drinks', 1, 2, 4],
            ['FIGHT', 'Second', 'Drinks', 1, 2, 4],
        ]);

        try {
            $this->actingAs($cashier, 'web')->postJson('/api/imports/preview', ['file' => $file])->assertForbidden();
            $preview = $this->actingAs($owner, 'web')->postJson('/api/imports/preview', ['file' => $file])
                ->assertCreated()->assertJsonPath('data.valid_rows', 3)->assertJsonPath('data.skipped_rows', 4)
                ->assertJsonPath('data.units', 9)->assertJsonPath('data.entries.1.mismatches', ['name', 'cost', 'price']);
        } finally {
            unlink($file->getPathname());
        }

        $id = $preview->json('data.id');
        $this->assertDatabaseCount('products', 1);
        $this->assertDatabaseCount('stock_movements', 1);
        $this->actingAs($this->account('owner'), 'web')->postJson('/api/imports/confirm', ['preview_id' => $id])->assertNotFound();

        $this->actingAs($owner, 'web')->postJson('/api/imports/confirm', ['preview_id' => $id])
            ->assertOk()->assertJsonPath('data.new_products', 1)->assertJsonPath('data.existing_products', 1);
        $this->postJson('/api/imports/confirm', ['preview_id' => $id])->assertOk()->assertJsonPath('data.units', 9);
        $this->getJson('/api/imports')->assertOk()->assertJsonPath('data.0.id', $id);
        $this->assertDatabaseHas('products', ['id' => $product, 'name' => 'Water', 'price' => '18.00', 'stock' => 10]);
        $this->assertDatabaseHas('products', ['sku' => 'JUICE', 'stock' => 4]);
        $this->assertDatabaseCount('stock_movements', 3);
    }

    public function test_owner_reports_use_manila_day_boundaries_and_completed_sales(): void
    {
        $owner = $this->account('owner');
        $cashier = $this->account('cashier');
        $product = $this->createProduct($owner);
        $request = [
            'idempotency_key' => (string) Str::uuid(),
            'items' => [['product_id' => $product, 'quantity' => 1]],
            'payment' => ['method' => 'cash', 'amount_tendered' => '20.00'],
        ];

        $this->travelTo(now()->setDate(2026, 11, 19)->setTime(16, 15));
        $this->actingAs($cashier, 'web')->postJson('/api/sales', $request)->assertCreated();
        DB::table('sales')->insert(['cashier_id' => $cashier->id, 'receipt_no' => 'INCOMPLETE', 'total' => '99.00', 'created_at' => now()]);

        $period = 'period=custom&from=2026-11-19&to=2026-11-20';
        $this->actingAs($owner, 'web')->getJson("/api/dashboard/sales?$period")->assertOk()
            ->assertJsonPath('data.0.total_sales', '0.00')->assertJsonPath('data.1.total_sales', '18.00');
        $this->getJson("/api/dashboard/payments?$period")->assertOk()->assertJsonPath('data.0.total_sales', '18.00');
        $this->getJson("/api/dashboard/top-products?$period")->assertOk()->assertJsonPath('data.0.quantity', 1);
        $this->getJson('/api/dashboard/low-stock')->assertOk()->assertJsonPath('data.0.stock', 4);
        $this->getJson('/api/dashboard/summary?period=custom')->assertUnprocessable();
        $this->getJson('/api/sales')->assertOk()->assertJsonCount(1, 'data');
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

    private function createCategory(User $owner): int
    {
        return $this->actingAs($owner, 'web')->postJson('/api/categories', ['name' => 'Drinks'])->assertCreated()->json('data.id');
    }

    private function createProduct(User $owner, ?int $category = null): int
    {
        return $this->actingAs($owner, 'web')->postJson('/api/products', [
            'sku' => 'WATER',
            'name' => 'Water',
            'category_id' => $category,
            'cost' => '10.00',
            'price' => '18.00',
            'stock' => 5,
        ])->assertCreated()->json('data.id');
    }

    private function workbook(array $rows): UploadedFile
    {
        $path = tempnam(sys_get_temp_dir(), 'tri-m-xlsx-');
        $writer = new Writer;
        $writer->openToFile($path);
        foreach ($rows as $row) {
            $writer->addRow(Row::fromValues($row));
        }
        $writer->close();

        return new UploadedFile($path, 'inventory.xlsx', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', test: true);
    }
}
