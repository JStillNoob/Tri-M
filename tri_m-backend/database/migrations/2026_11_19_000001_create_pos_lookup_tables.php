<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        $this->createLookupTable('roles', 50);
        $this->createLookupTable('categories', 100);
        $this->createLookupTable('payment_methods', 50);
        $this->createLookupTable('wallet_providers', 50);
        $this->createLookupTable('stock_movement_types', 50);
    }

    public function down(): void
    {
        Schema::dropIfExists('stock_movement_types');
        Schema::dropIfExists('wallet_providers');
        Schema::dropIfExists('payment_methods');
        Schema::dropIfExists('categories');
        Schema::dropIfExists('roles');
    }

    private function createLookupTable(string $name, int $nameLength): void
    {
        Schema::create($name, function (Blueprint $table) use ($nameLength): void {
            $table->id();
            $table->string('name', $nameLength)->unique();
            $table->timestamps();
        });
    }
};
