<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('payments', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('sale_id')->unique()->constrained()->restrictOnDelete();
            $table->foreignId('payment_method_id')->constrained()->restrictOnDelete();
            $table->foreignId('wallet_provider_id')->nullable()->constrained()->restrictOnDelete();
            $table->index('payment_method_id');
            $table->index('wallet_provider_id');
            $table->string('reference_no', 100)->nullable();
            $table->decimal('amount_paid', 12, 2);
            $table->decimal('amount_tendered', 12, 2)->nullable();
            $table->decimal('change_due', 12, 2)->nullable();
            $table->timestamps();
        });

        Schema::create('stock_movements', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('product_id')->constrained()->restrictOnDelete();
            $table->foreignId('movement_type_id')->constrained('stock_movement_types')->restrictOnDelete();
            $table->foreignId('user_id')->constrained()->restrictOnDelete();
            $table->foreignId('sale_item_id')->nullable()->unique();
            $table->foreign(['sale_item_id', 'product_id'])->references(['id', 'product_id'])->on('sale_items')->restrictOnDelete();
            $table->integer('quantity');
            $table->text('remarks')->nullable();
            $table->timestamp('created_at')->useCurrent();
            $table->index(['product_id', 'created_at']);
            $table->index('movement_type_id');
            $table->index('user_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('stock_movements');
        Schema::dropIfExists('payments');
    }
};
