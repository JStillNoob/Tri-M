<?php

namespace App\Services;

use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class DashboardAnalytics
{
    public function __construct(private Dashboard $dashboard) {}

    public function overview(DashboardPeriod $period, ?int $categoryId): array
    {
        $intervals = $this->intervals($period);
        $buckets = $this->buckets($this->receipts($period->current, $categoryId), $period, $intervals);
        $previous = $this->totals($this->receipts($period->previous, $categoryId));
        $orderCount = (int) $buckets->sum('orders');
        $revenue = $this->sum($buckets);
        $previousRevenue = $this->decimal($previous->total);
        $units = $this->dashboard->completedItems($period->current, $categoryId)
            ->selectRaw('SUM(sale_items.quantity) AS units, COUNT(DISTINCT sale_items.product_id) AS products')
            ->selectRaw('SUM(sale_items.unit_price * sale_items.quantity - COALESCE(sale_items.cost_of_goods_sold, sale_items.unit_cost * sale_items.quantity)) AS profit')
            ->first();
        $methods = $buckets->groupBy('method');
        $difference = $revenue->minus($previousRevenue);

        return [
            'period' => $period->key,
            'from' => $period->calendar->start->toDateString(),
            'to' => $period->calendar->end->subDay()->toDateString(),
            'calendar_days' => $period->calendarDays(),
            'category_id' => $categoryId,
            'currency' => 'PHP',
            'sales_total' => (string) $revenue,
            'previous_sales_total' => (int) $previous->orders === 0 ? null : (string) $previousRevenue,
            'sales_difference' => (int) $previous->orders === 0 ? null : (string) $difference,
            'sales_percentage_change' => $previousRevenue->isZero() ? null : (string) $difference->multipliedBy(100)
                ->dividedBy($previousRevenue, 2, RoundingMode::HalfUp),
            'average_sale_value' => $this->average($revenue, $orderCount),
            'average_items_per_sale' => $this->average($this->decimal($units->units), $orderCount),
            'average_daily_sales' => $this->average($revenue, $period->calendarDays()),
            'total_orders' => $orderCount,
            'trend' => $this->trend($buckets, $intervals, $period->key === 'today'),
            'retail_metrics' => [
                'units_sold' => (int) $units->units,
                'unique_products' => (int) $units->products,
                'gross_profit' => (string) $this->decimal($units->profit),
                'best_seller' => (int) $units->products === 0 ? null : ($this->dashboard->topProducts($period->current, 1, $categoryId)[0] ?? null),
                'payments' => array_map(fn (string $method): array => [
                    'method' => $method,
                    'total' => (string) $this->sum($methods->get($method, collect())),
                    'orders' => (int) ($methods->get($method)?->sum('orders') ?? 0),
                ], ['cash', 'ewallet']),
            ],
        ];
    }

    public function monthlyRevenue(DashboardPeriod $period): BigDecimal
    {
        return $this->decimal($this->totals($this->receipts($period->current, null))->total);
    }

    private function receipts(StoreDateRange $range, ?int $categoryId): Builder
    {
        $columns = ['sales.id', 'sales.created_at', 'payments.payment_method_id'];
        $sales = $categoryId === null
            ? $this->dashboard->completedSales($range)->select([...$columns, 'sales.total'])
            : $this->dashboard->completedItems($range, $categoryId)->select($columns)
                ->selectRaw('SUM(sale_items.unit_price * sale_items.quantity) AS total')
                ->groupBy($columns);

        return DB::query()->fromSub($sales, 'receipts');
    }

    private function totals(Builder $receipts): object
    {
        return $receipts->selectRaw('SUM(receipts.total) AS total, COUNT(*) AS orders')->first();
    }

    private function intervals(DashboardPeriod $period): array
    {
        $intervals = [];

        for ($start = $period->current->start; $start->lt($period->current->end); $start = $end) {
            $end = $period->key === 'today' ? $start->addHour() : $start->addDay();
            $intervals[] = StoreDateRange::between($start, $end->min($period->current->end));
        }

        return $intervals;
    }

    private function buckets(Builder $receipts, DashboardPeriod $period, array $intervals): Collection
    {
        $localized = $receipts
            ->join('payment_methods', 'payment_methods.id', '=', 'receipts.payment_method_id')
            ->select('receipts.total', 'payment_methods.name as method');

        if (DB::getDriverName() === 'pgsql') {
            $group = $period->key === 'today' ? 'hour' : 'day';
            $localized->selectRaw("date_trunc(?, timezone(?, receipts.created_at AT TIME ZONE 'UTC')) AS bucket", [$group, config('pos.timezone')]);
        } else {
            $bindings = [];
            foreach ($intervals as $interval) {
                $bindings[] = $interval->end->utc()->format('Y-m-d H:i:s');
                $bindings[] = $interval->start->format('Y-m-d H:i:s');
            }
            $cases = str_repeat(' WHEN receipts.created_at < ? THEN ?', count($intervals));
            $localized->selectRaw('CASE'.$cases.' END AS bucket', $bindings);
        }

        return DB::query()->fromSub($localized, 'interval_sales')
            ->selectRaw('bucket, method, SUM(total) AS total, COUNT(*) AS orders')
            ->groupBy('bucket', 'method')->get();
    }

    private function trend(Collection $buckets, array $intervals, bool $hourly): array
    {
        $points = [];
        $runningTotal = $this->decimal(0);
        $rows = $buckets->groupBy('bucket');

        foreach ($intervals as $interval) {
            $start = $interval->start;
            $values = $rows->get($start->format('Y-m-d H:i:s'), collect());
            $total = $this->sum($values);
            $runningTotal = $runningTotal->plus($total);
            $points[] = [
                'start' => $start->toIso8601String(),
                'label' => $start->format($hourly ? 'g A' : 'j M'),
                'sales' => (string) $total,
                'cumulative_sales' => (string) $runningTotal,
                'orders' => (int) $values->sum('orders'),
            ];
        }

        return $points;
    }

    private function sum(Collection $rows): BigDecimal
    {
        return $rows->reduce(fn (BigDecimal $total, object $row): BigDecimal => $total->plus($this->decimal($row->total)), $this->decimal(0));
    }

    private function decimal(mixed $value): BigDecimal
    {
        return BigDecimal::of((string) ($value ?? 0))->toScale(2, RoundingMode::HalfUp);
    }

    private function average(BigDecimal $numerator, int $denominator): string
    {
        return $denominator === 0 ? '0.00' : (string) $numerator->dividedBy($denominator, 2, RoundingMode::HalfUp);
    }
}
