<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class InventoryImport extends Model
{
    use HasUuids;

    protected $fillable = [
        'user_id', 'file_name', 'preview', 'result', 'expires_at', 'confirmed_at',
        'status', 'worksheet_name', 'file_path', 'file_hash', 'approved_source',
    ];

    protected $hidden = ['file_path', 'file_hash', 'approved_source'];

    public function rows(): HasMany
    {
        return $this->hasMany(InventoryImportRow::class);
    }

    protected function casts(): array
    {
        return [
            'preview' => 'array',
            'result' => 'array',
            'expires_at' => 'datetime',
            'confirmed_at' => 'datetime',
        ];
    }
}
