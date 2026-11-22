<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('suppliers', function (Blueprint $table): void {
            $table->id();
            $table->string('name', 150);
            $table->string('name_key', 150)->unique();
            $table->timestamps();
        });

        Schema::create('stock_purchases', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('product_id')->constrained()->restrictOnDelete();
            $table->foreignId('supplier_id')->constrained()->restrictOnDelete();
            $table->foreignId('user_id')->constrained()->restrictOnDelete();
            $table->foreignId('stock_movement_id')->unique()->constrained()->restrictOnDelete();
            $table->uuid('request_key')->unique();
            $table->char('request_hash', 64);
            $table->date('purchase_date');
            $table->string('product_name');
            $table->string('category_name', 100);
            $table->unsignedInteger('quantity');
            $table->decimal('unit_cost', 12, 2);
            $table->decimal('total_purchase', 22, 2);
            $table->decimal('markup_percentage', 9, 4);
            $table->decimal('selling_price', 12, 2);
            $table->timestamps();
            $table->index(['purchase_date', 'id']);
        });

        if (DB::getDriverName() === 'pgsql') {
            DB::statement('ALTER TABLE suppliers ENABLE ROW LEVEL SECURITY');
            DB::statement('ALTER TABLE stock_purchases ENABLE ROW LEVEL SECURITY');
            DB::statement('ALTER TABLE stock_purchases ADD CONSTRAINT stock_purchases_values_check CHECK (
                quantity > 0 AND unit_cost >= 0 AND total_purchase = unit_cost * quantity AND
                markup_percentage >= 0 AND selling_price > 0
            )');
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('stock_purchases');
        Schema::dropIfExists('suppliers');
    }
};
