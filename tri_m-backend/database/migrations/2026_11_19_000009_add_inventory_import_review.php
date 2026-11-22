<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('inventory_imports', function (Blueprint $table): void {
            $table->string('status', 20)->nullable();
            $table->string('worksheet_name')->nullable();
            $table->string('file_path')->nullable();
            $table->char('file_hash', 64)->nullable();
            $table->char('approved_source', 64)->nullable()->unique();
            $table->index(['status', 'expires_at']);
        });

        Schema::create('inventory_import_rows', function (Blueprint $table): void {
            $table->id();
            $table->foreignUuid('inventory_import_id')->constrained()->restrictOnDelete();
            $table->unsignedInteger('row_number');
            $table->string('uploaded_sku', 100)->nullable();
            $table->string('uploaded_name')->nullable();
            $table->unsignedInteger('quantity')->nullable();
            $table->decimal('original_unit_cost', 30, 16)->nullable();
            $table->decimal('original_total_purchase', 30, 16)->nullable();
            $table->decimal('original_markup', 30, 16)->nullable();
            $table->decimal('original_selling_price', 30, 16)->nullable();
            $table->decimal('approved_selling_price', 12, 2)->nullable();
            $table->decimal('approved_cost', 12, 2)->nullable();
            $table->foreignId('product_id')->nullable()->index()->constrained()->restrictOnDelete();
            $table->foreignId('category_id')->nullable()->constrained()->nullOnDelete();
            $table->string('new_name')->nullable();
            $table->string('new_sku', 100)->nullable();
            $table->string('decision', 20);
            $table->string('matching_method', 20)->nullable();
            $table->json('source');
            $table->json('errors');
            $table->json('warnings');
            $table->json('candidates');
            $table->timestamp('processed_at')->nullable();
            $table->timestamps();
            $table->unique(['inventory_import_id', 'row_number']);
        });

        Schema::table('stock_movements', function (Blueprint $table): void {
            $table->foreignUuid('inventory_import_id')->nullable()->index()->constrained()->restrictOnDelete();
            $table->foreignId('inventory_import_row_id')->nullable()->unique()->constrained()->restrictOnDelete();
            $table->unsignedInteger('previous_stock')->nullable();
            $table->unsignedInteger('resulting_stock')->nullable();
        });

        if (DB::getDriverName() === 'pgsql') {
            DB::statement('ALTER TABLE inventory_import_rows ENABLE ROW LEVEL SECURITY');
            DB::statement('ALTER TABLE inventory_import_rows ADD CONSTRAINT inventory_import_rows_values_check CHECK (
                (quantity IS NULL OR quantity > 0) AND
                (approved_selling_price IS NULL OR (approved_selling_price > 0 AND approved_selling_price <= 9999999999.99)) AND
                (approved_cost IS NULL OR (approved_cost >= 0 AND approved_cost <= 9999999999.99))
            )');
            DB::statement('ALTER TABLE stock_movements ADD CONSTRAINT stock_movements_balance_check CHECK (
                (inventory_import_row_id IS NULL OR inventory_import_id IS NOT NULL) AND
                ((previous_stock IS NULL AND resulting_stock IS NULL) OR
                 (previous_stock IS NOT NULL AND resulting_stock IS NOT NULL AND previous_stock >= 0 AND resulting_stock >= 0
                  AND previous_stock::bigint + quantity = resulting_stock))
            )');
        }
    }

    public function down(): void
    {
        if (DB::getDriverName() === 'pgsql') {
            DB::statement('ALTER TABLE stock_movements DROP CONSTRAINT stock_movements_balance_check');
        }
        Schema::table('stock_movements', function (Blueprint $table): void {
            $table->dropUnique(['inventory_import_row_id']);
            $table->dropIndex(['inventory_import_id']);
            $table->dropConstrainedForeignId('inventory_import_row_id');
            $table->dropConstrainedForeignId('inventory_import_id');
            $table->dropColumn(['previous_stock', 'resulting_stock']);
        });

        Schema::dropIfExists('inventory_import_rows');

        Schema::table('inventory_imports', function (Blueprint $table): void {
            $table->dropIndex(['status', 'expires_at']);
            $table->dropUnique(['approved_source']);
            $table->dropColumn(['status', 'worksheet_name', 'file_path', 'file_hash', 'approved_source']);
        });
    }
};
