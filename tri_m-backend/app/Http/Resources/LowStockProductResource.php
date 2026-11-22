<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;

class LowStockProductResource extends ProductResource
{
    public function toArray(Request $request): array
    {
        return [
            ...parent::toArray($request),
            'low_stock_threshold' => max(0, config('pos.low_stock_threshold')),
            'stock_status' => $this->stock === 0 ? 'out_of_stock' : 'low_stock',
        ];
    }
}
