<?php

namespace App\Http\Resources;

use App\Services\Money;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class SaleResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $payment = $this->payment;

        return [
            'id' => $this->id,
            'receipt_no' => $this->receipt_no,
            'created_at' => $this->created_at->toISOString(),
            'cashier' => ['id' => $this->cashier_id, 'name' => $this->cashier->fullName()],
            'total' => $this->total,
            'payment' => [
                'method' => $payment->method->name,
                'wallet_provider' => $payment->walletProvider?->name,
                'reference_no' => $payment->reference_no,
                'amount_paid' => $payment->amount_paid,
                'amount_tendered' => $payment->amount_tendered,
                'change_due' => $payment->change_due,
            ],
            'items' => $this->whenLoaded('items', fn () => $this->items->map(function ($item) use ($request): array {
                $line = [
                    'id' => $item->id,
                    'product_id' => $item->product_id,
                    'name' => $item->product_name,
                    'quantity' => $item->quantity,
                    'unit_price' => $item->unit_price,
                    'total' => Money::decimal(Money::centavos($item->unit_price) * $item->quantity),
                ];

                if ($request->user()->hasRole('owner')) {
                    $line['unit_cost'] = $item->unit_cost;
                    $line['cost_of_goods_sold'] = $item->cost_of_goods_sold ?? (string) Money::lineTotal($item->unit_cost, $item->quantity);
                }

                return $line;
            })),
        ];
    }
}
