<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class StockPurchase extends Model
{
    protected $fillable = [
        'product_id', 'supplier_id', 'user_id', 'stock_movement_id', 'request_key', 'request_hash',
        'purchase_date', 'product_name', 'category_name', 'quantity', 'unit_cost', 'total_purchase',
        'markup_percentage', 'selling_price',
    ];

    public function supplier(): BelongsTo
    {
        return $this->belongsTo(Supplier::class);
    }

    protected function casts(): array
    {
        return [
            'purchase_date' => 'immutable_date',
            'quantity' => 'integer',
            'unit_cost' => 'decimal:2',
            'total_purchase' => 'decimal:2',
            'markup_percentage' => 'decimal:4',
            'selling_price' => 'decimal:2',
        ];
    }
}
