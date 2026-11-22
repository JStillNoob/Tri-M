<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            return;
        }

        DB::statement(<<<'SQL'
            ALTER TABLE products ADD CONSTRAINT products_values_check CHECK (
                cost BETWEEN 0 AND 9999999999.99
                AND price BETWEEN 0 AND 9999999999.99
                AND stock >= 0
            )
            SQL);
        DB::statement(<<<'SQL'
            ALTER TABLE sales ADD CONSTRAINT sales_total_check CHECK (total BETWEEN 0 AND 9999999999.99)
            SQL);
        DB::statement(<<<'SQL'
            ALTER TABLE sale_items ADD CONSTRAINT sale_items_values_check CHECK (
                unit_price BETWEEN 0 AND 9999999999.99
                AND unit_cost BETWEEN 0 AND 9999999999.99
                AND quantity > 0
            )
            SQL);
        DB::statement(<<<'SQL'
            ALTER TABLE payments ADD CONSTRAINT payments_details_check CHECK (
                amount_paid BETWEEN 0 AND 9999999999.99
                AND (
                    (
                        wallet_provider_id IS NULL
                        AND reference_no IS NULL
                        AND amount_tendered IS NOT NULL
                        AND amount_tendered BETWEEN amount_paid AND 9999999999.99
                        AND change_due IS NOT NULL
                        AND change_due = amount_tendered - amount_paid
                    )
                    OR (
                        wallet_provider_id IS NOT NULL
                        AND reference_no IS NOT NULL
                        AND reference_no ~ '[^[:space:]]'
                        AND amount_tendered IS NULL
                        AND change_due IS NULL
                    )
                )
            )
            SQL);
        DB::statement(<<<'SQL'
            ALTER TABLE stock_movements ADD CONSTRAINT stock_movements_quantity_check CHECK (
                quantity <> 0 AND (sale_item_id IS NULL OR quantity < 0)
            )
            SQL);
        DB::unprepared(<<<'SQL'
            CREATE OR REPLACE FUNCTION reject_stock_movement_mutation() RETURNS trigger
            LANGUAGE plpgsql AS $$
            BEGIN
                RAISE EXCEPTION 'Stock movements are append-only' USING ERRCODE = '23514';
            END;
            $$
            SQL);
        DB::unprepared(<<<'SQL'
            CREATE TRIGGER stock_movements_append_only
            BEFORE UPDATE OR DELETE ON stock_movements
            FOR EACH ROW EXECUTE FUNCTION reject_stock_movement_mutation()
            SQL);
    }

    public function down(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            return;
        }

        DB::unprepared('DROP TRIGGER stock_movements_append_only ON stock_movements');
        DB::unprepared('DROP FUNCTION reject_stock_movement_mutation()');
        DB::statement('ALTER TABLE stock_movements DROP CONSTRAINT stock_movements_quantity_check');
        DB::statement('ALTER TABLE payments DROP CONSTRAINT payments_details_check');
        DB::statement('ALTER TABLE sale_items DROP CONSTRAINT sale_items_values_check');
        DB::statement('ALTER TABLE sales DROP CONSTRAINT sales_total_check');
        DB::statement('ALTER TABLE products DROP CONSTRAINT products_values_check');
    }
};
