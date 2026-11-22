<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('products', function (Blueprint $table): void {
            $table->decimal('markup_percentage', 9, 4)->nullable();
            $table->decimal('inventory_value', 22, 2)->nullable();
        });

        Schema::table('sale_items', function (Blueprint $table): void {
            $table->decimal('cost_of_goods_sold', 22, 2)->nullable();
        });

        Schema::table('inventory_import_rows', function (Blueprint $table): void {
            $table->decimal('markup_percentage', 9, 4)->nullable();
            $table->decimal('purchase_total_cost', 22, 2)->nullable();
            $table->decimal('purchase_selling_price', 12, 2)->nullable();
        });

        if (DB::getDriverName() === 'pgsql') {
            DB::statement('ALTER TABLE products ADD CONSTRAINT products_valuation_check CHECK (inventory_value >= 0 AND markup_percentage >= 0)');
            DB::statement('ALTER TABLE sale_items ADD CONSTRAINT sale_items_cogs_check CHECK (cost_of_goods_sold >= 0)');
            DB::statement('ALTER TABLE inventory_import_rows ADD CONSTRAINT inventory_import_rows_purchase_check CHECK (
                markup_percentage >= 0 AND purchase_total_cost >= 0 AND purchase_selling_price >= 0
            )');
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        if (DB::getDriverName() === 'pgsql') {
            DB::statement('ALTER TABLE inventory_import_rows DROP CONSTRAINT inventory_import_rows_purchase_check');
            DB::statement('ALTER TABLE sale_items DROP CONSTRAINT sale_items_cogs_check');
            DB::statement('ALTER TABLE products DROP CONSTRAINT products_valuation_check');
        }

        Schema::table('inventory_import_rows', fn (Blueprint $table) => $table->dropColumn(['markup_percentage', 'purchase_total_cost', 'purchase_selling_price']));
        Schema::table('sale_items', fn (Blueprint $table) => $table->dropColumn('cost_of_goods_sold'));
        Schema::table('products', fn (Blueprint $table) => $table->dropColumn(['markup_percentage', 'inventory_value']));
    }
};
