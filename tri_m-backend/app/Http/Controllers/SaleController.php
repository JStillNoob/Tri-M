<?php

namespace App\Http\Controllers;

use App\Http\Requests\CheckoutRequest;
use App\Http\Resources\SaleResource;
use App\Models\Sale;
use App\Services\Checkout;
use App\Services\PosDashboardCache;
use App\Services\StoreDateRange;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class SaleController extends PosController
{
    private const array RELATIONS = [
        'cashier:id,first_name,middle_name,last_name',
        'payment.method:id,name',
        'payment.walletProvider:id,name',
    ];

    public function index(Request $request): JsonResponse
    {
        $filters = $request->validate([
            'receipt_no' => ['nullable', 'string', 'max:50'],
            'cashier_id' => ['nullable', 'integer', 'min:1'],
            'payment_method' => ['nullable', 'in:cash,ewallet'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
            'page' => ['nullable', 'integer', 'min:1', 'max:100000'],
        ]);
        $owner = $request->user()->hasRole('owner');
        $range = $owner ? StoreDateRange::fromRequest($request) : StoreDateRange::today();
        $sales = Sale::query()->with(self::RELATIONS)->has('payment')->latest('created_at')->orderByDesc('id');
        $range->apply($sales, 'created_at');

        if (! $owner || isset($filters['cashier_id'])) {
            $sales->where('cashier_id', $owner ? $filters['cashier_id'] : $request->user()->id);
        }

        if (isset($filters['receipt_no'])) {
            $term = '%'.strtr(strtoupper($filters['receipt_no']), ['!' => '!!', '%' => '!%', '_' => '!_']).'%';
            $sales->whereRaw("receipt_no LIKE ? ESCAPE '!'", [$term]);
        }

        if (isset($filters['payment_method'])) {
            $sales->whereHas('payment.method', fn ($query) => $query->where('name', $filters['payment_method']));
        }

        return $this->page($sales->paginate($filters['per_page'] ?? 25), SaleResource::class, 'Sales retrieved.');
    }

    public function show(Request $request, int $sale): JsonResponse
    {
        $query = Sale::query()->with([...self::RELATIONS, 'items'])->has('payment');

        if ($request->user()->hasRole('cashier')) {
            $query->where('cashier_id', $request->user()->id);
        }

        return $this->result(new SaleResource($query->findOrFail($sale)), 'Sale retrieved.');
    }

    public function store(CheckoutRequest $request, Checkout $checkout): JsonResponse
    {
        $result = $checkout->complete($request->user(), $request->validated());
        if (! $result['replayed']) {
            PosDashboardCache::invalidate();
        }

        $sale = new SaleResource($result['sale']->load([...self::RELATIONS, 'items']));

        return $this->result($sale, $result['replayed'] ? 'Sale retrieved.' : 'Sale completed.', $result['replayed'] ? 200 : 201);
    }
}
