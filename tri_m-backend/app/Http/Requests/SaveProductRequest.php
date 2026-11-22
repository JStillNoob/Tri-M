<?php

namespace App\Http\Requests;

use App\Services\Inventory;
use App\Services\Money;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class SaveProductRequest extends FormRequest
{
    public function rules(): array
    {
        $product = $this->route('product');
        $uniqueSku = Rule::unique('products', 'sku');

        if ($product !== null) {
            $uniqueSku->ignore($product);
        }

        return [
            'sku' => ['sometimes', 'required', 'string', 'max:100', $uniqueSku],
            'name' => ['required', 'string', 'max:255'],
            'category_id' => ['nullable', 'integer', 'exists:categories,id'],
            'cost' => $product === null ? ['required', ...Money::RULES] : ['sometimes', 'required', ...Money::RULES],
            'price' => ['required', ...Money::RULES],
            'markup_percentage' => ['sometimes', 'required', ...Money::MARKUP_RULES],
            'stock' => $product === null
                ? ['sometimes', 'required', 'integer', 'min:0', 'max:'.Inventory::MAX_STOCK]
                : ['prohibited'],
        ];
    }
}
