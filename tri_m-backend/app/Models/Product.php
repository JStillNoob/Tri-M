<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class Product extends Model
{
    use SoftDeletes;

    protected $fillable = ['category_id', 'sku', 'name', 'cost', 'price', 'markup_percentage'];

    protected function casts(): array
    {
        return [
            'cost' => 'decimal:2',
            'price' => 'decimal:2',
            'stock' => 'integer',
            'inventory_value' => 'decimal:2',
            'markup_percentage' => 'decimal:4',
        ];
    }

    public function category(): BelongsTo
    {
        return $this->belongsTo(Category::class);
    }

    public static function nameKey(string $name): string
    {
        return mb_strtolower(Str::squish($name));
    }

    public static function internalSku(): string
    {
        return 'POS-'.Str::ulid();
    }

    public static function lockCatalogWrites(): void
    {
        if (DB::getDriverName() === 'pgsql') {
            DB::selectOne('SELECT pg_advisory_xact_lock(384920, 1)');
        }
    }
}
