<?php

namespace App\Services;

use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use Illuminate\Support\Facades\DB;

class StockFinancials
{
    public function overview(): array
    {
        $manual = DB::table('stock_purchases')->selectRaw('quantity, total_purchase AS total_cost, selling_price');
        $cost = $this->appliedDecimal('item_cost');
        $price = $this->appliedDecimal('selling_price');
        $workbook = DB::table('inventory_import_rows')
            ->whereNotNull('processed_at')->whereIn('decision', ['existing', 'new'])
            ->selectRaw("quantity, COALESCE(purchase_total_cost, ROUND($cost * quantity, 2)) AS total_cost,
                         COALESCE(purchase_selling_price, $price) AS selling_price");
        $purchases = DB::query()->fromSub($manual->unionAll($workbook), 'purchases')
            ->selectRaw('SUM(total_cost) AS stock_investment,
                SUM(selling_price * quantity) AS expected_sales,
                SUM(selling_price * quantity - total_cost) AS expected_gross_profit,
                COUNT(*) - COUNT(CASE WHEN total_cost IS NOT NULL AND selling_price IS NOT NULL THEN 1 END) AS incomplete_purchases');
        $sales = DB::table('sale_items')->join('payments', 'payments.sale_id', '=', 'sale_items.sale_id')
            ->selectRaw('SUM(unit_price * quantity) AS actual_sales,
                SUM(unit_price * quantity - COALESCE(cost_of_goods_sold, unit_cost * quantity)) AS actual_gross_profit');
        $inventory = DB::table('products')
            ->selectRaw('SUM(COALESCE(inventory_value, cost * stock)) AS remaining_stock_value');

        $totals = (array) DB::query()->fromSub($purchases, 'purchases')
            ->crossJoinSub($sales, 'sales')->crossJoinSub($inventory, 'inventory')->first();
        $incomplete = (int) $totals['incomplete_purchases'];
        unset($totals['incomplete_purchases']);

        return [
            ...array_map(fn ($amount) => (string) BigDecimal::of((string) ($amount ?? 0))->toScale(2, RoundingMode::HalfUp), $totals),
            'incomplete_purchases' => $incomplete,
        ];
    }

    private function appliedDecimal(string $field): string
    {
        return DB::getDriverName() === 'pgsql'
            ? "CAST(source->'applied'->>'$field' AS numeric)"
            : "CAST(json_extract(source, '$.applied.$field') AS numeric)";
    }
}
