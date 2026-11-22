<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class InventoryImportRow extends Model
{
    protected $fillable = [
        'row_number', 'uploaded_sku', 'uploaded_name', 'quantity', 'original_unit_cost',
        'original_total_purchase', 'original_markup', 'original_selling_price', 'approved_selling_price',
        'approved_cost', 'product_id', 'category_id', 'new_name', 'new_sku', 'decision', 'matching_method',
        'source', 'errors', 'warnings', 'candidates', 'processed_at', 'markup_percentage',
        'purchase_total_cost', 'purchase_selling_price',
    ];

    protected function casts(): array
    {
        return [
            'source' => 'array',
            'errors' => 'array',
            'warnings' => 'array',
            'candidates' => 'array',
            'quantity' => 'integer',
            'approved_selling_price' => 'decimal:2',
            'approved_cost' => 'decimal:2',
            'markup_percentage' => 'decimal:4',
            'purchase_total_cost' => 'decimal:2',
            'purchase_selling_price' => 'decimal:2',
            'processed_at' => 'datetime',
        ];
    }
}
