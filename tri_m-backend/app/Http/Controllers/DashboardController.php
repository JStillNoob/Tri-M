<?php

namespace App\Http\Controllers;

use App\Http\Resources\LowStockProductResource;
use App\Models\Product;
use App\Models\Sale;
use App\Services\Dashboard;
use App\Services\DashboardAnalytics;
use App\Services\DashboardPeriod;
use App\Services\Money;
use App\Services\PosDashboardCache;
use App\Services\StockFinancials;
use App\Services\StoreDateRange;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

class DashboardController extends PosController
{
    public function stockFinancials(StockFinancials $financials): JsonResponse
    {
        return $this->result($financials->overview(), 'Stock and profit totals retrieved.');
    }

    public function overview(Request $request, DashboardAnalytics $analytics, PosDashboardCache $cache): JsonResponse
    {
        $filters = $request->validate([
            'period' => ['sometimes', 'required', 'in:today,week,month'],
            'category_id' => ['nullable', 'integer', 'min:1', 'exists:categories,id'],
            'refresh' => ['sometimes', 'boolean'],
        ]);
        $period = DashboardPeriod::current($filters['period'] ?? 'today');
        $category = isset($filters['category_id']) ? (int) $filters['category_id'] : null;

        return $this->result($cache->overview($analytics, $period, $category, $request->boolean('refresh')), 'Dashboard overview retrieved.');
    }

    public function summary(Request $request, Dashboard $dashboard): JsonResponse
    {
        return $this->result($dashboard->summary(StoreDateRange::fromRequest($request)), 'Sales summary retrieved.');
    }

    public function sales(Request $request, Dashboard $dashboard): JsonResponse
    {
        return $this->result($dashboard->daily(StoreDateRange::fromRequest($request)), 'Daily sales retrieved.');
    }

    public function payments(Request $request, Dashboard $dashboard): JsonResponse
    {
        return $this->result($dashboard->payments(StoreDateRange::fromRequest($request)), 'Payment totals retrieved.');
    }

    public function topProducts(Request $request, Dashboard $dashboard): JsonResponse
    {
        $filters = $request->validate(['limit' => ['nullable', 'integer', 'min:1', 'max:100']]);

        return $this->result($dashboard->topProducts(StoreDateRange::fromRequest($request), $filters['limit'] ?? 10), 'Top products retrieved.');
    }

    public function lowStock(Request $request): JsonResponse
    {
        $filters = $request->validate([
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
            'page' => ['nullable', 'integer', 'min:1', 'max:100000'],
        ]);
        $threshold = max(0, config('pos.low_stock_threshold'));
        $products = Product::query()->with('category')->where('stock', '<=', $threshold)
            ->orderBy('stock')->orderBy('name')->orderBy('id')->paginate($filters['per_page'] ?? 25);

        $response = $this->page($products, LowStockProductResource::class, 'Low-stock products retrieved.');
        $data = $response->getData(true);
        $data['meta']['threshold'] = $threshold;

        return $response->setData($data);
    }

    public function recentOrders(Request $request): JsonResponse
    {
        $filters = $request->validate(['limit' => ['sometimes', 'required', 'integer', 'min:1', 'max:20']]);
        $orders = Sale::query()->has('payment')->where('created_at', '<=', now()->utc())
            ->latest('created_at')->orderByDesc('id')->limit($filters['limit'] ?? 5)
            ->get(['id', 'receipt_no', 'total', 'created_at']);

        return $this->result($orders->map(fn (Sale $sale): array => [
            'id' => $sale->id,
            'receipt_no' => $sale->receipt_no,
            'total' => $sale->total,
            'created_at' => $sale->created_at->toISOString(),
            'local_time' => $sale->created_at->setTimezone(config('pos.timezone'))->format('M j, Y, g:i A'),
            'status' => 'completed',
        ]), 'Recent orders retrieved.');
    }

    public function target(DashboardAnalytics $analytics): JsonResponse
    {
        return $this->result($this->monthlyTarget(DashboardPeriod::current('month'), $analytics), 'Sales target retrieved.');
    }

    public function updateTarget(Request $request, DashboardAnalytics $analytics): JsonResponse
    {
        $period = DashboardPeriod::current('month');
        $month = $period->calendar->start->format('Y-m');
        $data = $request->validate([
            'month' => ['required', 'date_format:Y-m', Rule::in([$month])],
            'amount' => ['required', ...Money::RULES, 'gt:0'],
        ]);
        $now = now();

        DB::table('sales_targets')->upsert([
            'month' => $period->calendar->start->toDateString(),
            'amount' => $data['amount'],
            'created_at' => $now,
            'updated_at' => $now,
        ], ['month'], ['amount', 'updated_at']);

        return $this->result($this->monthlyTarget($period, $analytics), 'Sales target saved.');
    }

    private function monthlyTarget(DashboardPeriod $period, DashboardAnalytics $analytics): array
    {
        $start = $period->calendar->start;
        $value = DB::table('sales_targets')->where('month', $start->toDateString())->value('amount');
        $amount = $value === null ? null : BigDecimal::of((string) $value)->toScale(2);
        $actual = $analytics->monthlyRevenue($period);

        return [
            'month' => $start->format('Y-m'),
            'label' => $start->format('F Y'),
            'amount' => $amount === null ? null : (string) $amount,
            'actual_sales' => (string) $actual,
            'achievement_percentage' => $amount === null ? null : (string) $actual->multipliedBy(100)
                ->dividedBy($amount, 2, RoundingMode::HalfUp),
        ];
    }
}
