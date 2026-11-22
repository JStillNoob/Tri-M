<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('sales_targets', function (Blueprint $table): void {
            $table->date('month')->primary();
            $table->decimal('amount', 12, 2);
            $table->timestamps();
        });

        if (DB::getDriverName() === 'pgsql') {
            DB::statement('ALTER TABLE sales_targets ADD CONSTRAINT sales_targets_amount_check CHECK (amount > 0 AND amount <= 9999999999.99)');
            DB::statement('ALTER TABLE sales_targets ADD CONSTRAINT sales_targets_month_check CHECK (EXTRACT(DAY FROM month) = 1)');
            DB::statement('ALTER TABLE sales_targets ENABLE ROW LEVEL SECURITY');
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('sales_targets');
    }
};
