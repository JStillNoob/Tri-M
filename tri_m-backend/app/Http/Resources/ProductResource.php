<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class ProductResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'sku' => $this->when($request->user()->hasRole('owner'), fn () => $this->sku),
            'name' => $this->name,
            'category_id' => $this->category_id,
            'category' => $this->category?->name,
            'supplier' => $this->when(
                $request->user()->hasRole('owner') && array_key_exists('latest_supplier', $this->resource->getAttributes()),
                fn () => $this->latest_supplier,
            ),
            'cost' => $this->when($request->user()->hasRole('owner'), fn () => $this->cost),
            'markup_percentage' => $this->when($request->user()->hasRole('owner'), fn () => $this->markup_percentage),
            'price' => $this->price,
            'stock' => $this->stock,
            'archived' => $this->when($request->user()->hasRole('owner'), fn () => $this->trashed()),
        ];
    }
}
