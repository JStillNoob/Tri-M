<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Arr;

class InventoryImportResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'file_name' => $this->file_name,
            'user_id' => $this->user_id,
            'confirmed_at' => $this->confirmed_at?->toISOString(),
            'updated_at' => $this->updated_at->toISOString(),
            'worksheet_name' => $this->worksheet_name,
            'status' => $this->expires_at->isPast() && in_array($this->status, ['uploaded', 'review'], true)
                ? 'expired' : ($this->status ?? 'approved'),
            'summary' => Arr::except($this->result ?? [], ['entries', 'errors', 'duplicates']),
        ];
    }
}
