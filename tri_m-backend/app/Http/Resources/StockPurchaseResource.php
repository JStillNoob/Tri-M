<?php

namespace App\Http\Resources;

use App\Services\Money;
use Brick\Math\BigDecimal;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class StockPurchaseResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $expectedSales = Money::lineTotal($this->selling_price, $this->quantity);

        return [
            'id' => $this->id,
            'purchase_date' => $this->purchase_date->toDateString(),
            'product_id' => $this->product_id,
            'product_name' => $this->product_name,
            'supplier' => $this->supplier->only(['id', 'name']),
            'quantity' => $this->quantity,
            'unit_cost' => $this->unit_cost,
            'total_purchase' => $this->total_purchase,
            'markup_percentage' => $this->markup_percentage,
            'selling_price' => $this->selling_price,
            'expected_sales' => (string) $expectedSales,
            'expected_gross_profit' => (string) $expectedSales->minus(BigDecimal::of($this->total_purchase)),
            'category_name' => $this->category_name,
        ];
    }
}
