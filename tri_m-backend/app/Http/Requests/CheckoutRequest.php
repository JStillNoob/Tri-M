<?php

namespace App\Http\Requests;

use App\Services\Money;
use Illuminate\Foundation\Http\FormRequest;

class CheckoutRequest extends FormRequest
{
    protected function prepareForValidation(): void
    {
        if ($this->header('Idempotency-Key') !== null) {
            $this->merge(['idempotency_key' => $this->header('Idempotency-Key')]);
        }
    }

    public function rules(): array
    {
        return [
            'idempotency_key' => ['required', 'uuid'],
            'items' => ['required', 'array', 'list', 'min:1', 'max:100'],
            'items.*' => ['required', 'array:product_id,quantity'],
            'items.*.product_id' => ['required', 'integer', 'min:1', 'distinct'],
            'items.*.quantity' => ['required', 'integer', 'min:1', 'max:100000'],
            'payment' => ['required', 'array:method,amount_tendered,wallet_provider,reference_no'],
            'payment.method' => ['required', 'in:cash,ewallet'],
            'payment.amount_tendered' => ['required_if:payment.method,cash', 'prohibited_unless:payment.method,cash', ...Money::RULES],
            'payment.wallet_provider' => ['required_if:payment.method,ewallet', 'prohibited_unless:payment.method,ewallet', 'in:gcash,maya'],
            'payment.reference_no' => ['required_if:payment.method,ewallet', 'prohibited_unless:payment.method,ewallet', 'string', 'max:100', 'regex:/\S/u'],
        ];
    }
}
