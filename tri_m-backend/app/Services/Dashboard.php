<?php

namespace App\Services;

use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

class Dashboard
{
    public function summary(StoreDateRange $range): array
    {
        $sales = $this->completedSales($range);
        $totals = (clone $sales)->selectRaw('COUNT(*) AS transactions, COALESCE(SUM(sales.total), 0) AS total')->first();
        $profit = $sales->join('sale_items', 'sale_items.sale_id', '=', 'sales.id')
            ->selectRaw('COALESCE(SUM((sale_items.unit_price - sale_items.unit_cost) * sale_items.quantity), 0) AS gross_profit')->value('gross_profit');

        return [
            'from' => $range->start->toDateString(),
            'to' => $range->end->subDay()->toDateString(),
            'total_sales' => $this->amount($totals->total),
            'transactions' => (int) $totals->transactions,
            'gross_profit' => $this->amount($profit),
        ];
    }

    public function daily(StoreDateRange $range): array
    {
        $sales = $this->completedSales($range);
        $daily = [];

        if (DB::getDriverName() === 'pgsql') {
            $buckets = $sales->selectRaw("timezone(?, sales.created_at AT TIME ZONE 'UTC')::date AS day, sales.total", [config('pos.timezone')]);
            $daily = DB::query()->fromSub($buckets, 'daily_sales')
                ->selectRaw('day, SUM(total) AS total, COUNT(*) AS transactions')->groupBy('day')->get()->keyBy('day')->all();
        }

        $result = [];

        for ($day = $range->start; $day->lessThan($range->end); $day = $day->addDay()) {
            $key = $day->toDateString();
            $row = $daily[$key] ?? null;

            if (DB::getDriverName() !== 'pgsql') {
                $row = (clone $sales)->where('sales.created_at', '>=', $day->utc()->format('Y-m-d H:i:s'))
                    ->where('sales.created_at', '<', $day->addDay()->utc()->format('Y-m-d H:i:s'))
                    ->selectRaw('SUM(sales.total) AS total, COUNT(*) AS transactions')->first();
            }

            $result[] = [
                'date' => $key,
                'total_sales' => $this->amount($row->total ?? 0),
                'transactions' => (int) ($row->transactions ?? 0),
            ];
        }

        return $result;
    }

    public function payments(StoreDateRange $range): array
    {
        $totals = $this->completedSales($range)
            ->join('payment_methods', 'payment_methods.id', '=', 'payments.payment_method_id')
            ->selectRaw('payment_methods.name AS method, COUNT(*) AS transactions, SUM(payments.amount_paid) AS total')
            ->groupBy('payment_methods.name')->get()->keyBy('method');

        return array_map(fn ($method) => [
            'method' => $method,
            'total_sales' => $this->amount($totals[$method]->total ?? 0),
            'transactions' => (int) ($totals[$method]->transactions ?? 0),
        ], ['cash', 'ewallet']);
    }

    public function topProducts(StoreDateRange $range, int $limit, ?int $categoryId = null): array
    {
        $items = $this->completedItems($range, $categoryId);
        $totals = (clone $items)
            ->selectRaw('sale_items.product_id, SUM(sale_items.quantity) AS quantity, SUM(sale_items.unit_price * sale_items.quantity) AS total')
            ->groupBy('sale_items.product_id')->orderByDesc('quantity')->orderBy('sale_items.product_id')->limit($limit)->get();
        $latestNames = $items->select(['sale_items.product_id', 'sale_items.product_name'])
            ->whereIn('sale_items.product_id', $totals->pluck('product_id'))
            ->selectRaw('ROW_NUMBER() OVER (PARTITION BY sale_items.product_id ORDER BY sales.created_at DESC, sale_items.id DESC) AS position');
        $names = DB::query()->fromSub($latestNames, 'sold_items')->where('position', 1)->pluck('product_name', 'product_id');

        return $totals->map(fn ($row) => [
            'product_id' => $row->product_id,
            'name' => $names[$row->product_id],
            'quantity' => (int) $row->quantity,
            'total_sales' => $this->amount($row->total),
        ])->all();
    }

    public function completedItems(StoreDateRange $range, ?int $categoryId = null): Builder
    {
        $items = $this->completedSales($range)->join('sale_items', 'sale_items.sale_id', '=', 'sales.id');

        if ($categoryId !== null) {
            $items->join('products', 'products.id', '=', 'sale_items.product_id')->where('products.category_id', $categoryId);
        }

        return $items;
    }

    public function completedSales(StoreDateRange $range): Builder
    {
        return $range->apply(DB::table('sales')->join('payments', 'payments.sale_id', '=', 'sales.id'), 'sales.created_at');
    }

    private function amount(mixed $value): string
    {
        return (string) BigDecimal::of((string) $value)->toScale(2, RoundingMode::HalfUp);
    }
}
