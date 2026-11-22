<?php

namespace App\Services;

use App\Models\Product;
use App\Models\Sale;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use LogicException;

class Checkout
{
    public function __construct(private Inventory $inventory) {}

    /** @return array{sale: Sale, replayed: bool} */
    public function complete(User $cashier, array $data): array
    {
        $key = strtolower($data['idempotency_key']);
        $items = collect($data['items'])
            ->mapWithKeys(fn (array $item) => [(int) $item['product_id'] => (int) $item['quantity']])
            ->sortKeys();
        $payment = $data['payment'];
        $cash = $payment['method'] === 'cash';
        $tendered = $cash ? Money::centavos($payment['amount_tendered']) : null;
        $wallet = $cash ? null : $payment['wallet_provider'];
        $reference = $cash ? null : $payment['reference_no'];
        $hash = hash('sha256', json_encode([$items->all(), $payment['method'], $tendered, $wallet, $reference], JSON_THROW_ON_ERROR));

        return DB::transaction(function () use ($cashier, $key, $items, $payment, $tendered, $wallet, $reference, $hash): array {
            $timestamp = now();
            $inserted = DB::table('sales')->insertOrIgnore([
                'cashier_id' => $cashier->id,
                'receipt_no' => 'TRM-'.Str::ulid(),
                'idempotency_key' => $key,
                'request_hash' => $hash,
                'total' => '0.00',
                'created_at' => $timestamp,
                'updated_at' => $timestamp,
            ]);

            $sale = Sale::query()->where('idempotency_key', $key)->first();
            abort_if($sale === null, 503, 'A receipt number could not be reserved.');
            abort_if($sale->cashier_id !== $cashier->id || ! hash_equals((string) $sale->request_hash, $hash), 409, 'We could not save this sale. Check Orders before trying again.');

            if ($inserted === 0) {
                return ['sale' => $sale, 'replayed' => true];
            }

            $products = Product::query()->whereKey($items->keys()->all())->orderBy('id')->lockForUpdate()->get()->keyBy('id');
            $total = 0;

            foreach ($items as $productId => $quantity) {
                $product = $products->get($productId);

                if ($product === null || $product->stock < $quantity) {
                    throw ValidationException::withMessages(['items' => $product
                        ? "Only {$product->stock} units of {$product->name} are available. Go back and reduce the quantity."
                        : 'A product in the basket is no longer available. Go back and remove it.']);
                }

                $unitPrice = Money::centavos($product->price);
                if ($unitPrice > intdiv(Money::MAX_CENTAVOS - $total, $quantity)) {
                    throw ValidationException::withMessages(['items' => 'This order is over the allowed total. Split it into smaller sales.']);
                }
                $total += $unitPrice * $quantity;
            }

            if ($tendered !== null && $tendered < $total) {
                throw ValidationException::withMessages(['payment.amount_tendered' => 'Enter enough cash to cover the total.']);
            }

            $sale->forceFill(['total' => Money::decimal($total)])->save();

            foreach ($items as $productId => $quantity) {
                $product = $products->get($productId);
                $cost = $this->inventory->saleCost($product, $quantity);
                $itemId = DB::table('sale_items')->insertGetId([
                    'sale_id' => $sale->id,
                    'product_id' => $product->id,
                    'product_name' => $product->name,
                    'unit_price' => $product->price,
                    'unit_cost' => $product->cost,
                    'cost_of_goods_sold' => (string) $cost,
                    'quantity' => $quantity,
                    'created_at' => $timestamp,
                    'updated_at' => $timestamp,
                ]);

                $this->inventory->move($product, -$quantity, 'sale', $cashier, $itemId, soldCost: $cost);
            }

            $methodId = DB::table('payment_methods')->where('name', $payment['method'])->value('id');
            $walletId = $wallet === null ? null : DB::table('wallet_providers')->where('name', $wallet)->value('id');

            if ($methodId === null || ($wallet !== null && $walletId === null)) {
                throw new LogicException('The required payment lookup is missing.');
            }

            DB::table('payments')->insert([
                'sale_id' => $sale->id,
                'payment_method_id' => $methodId,
                'wallet_provider_id' => $walletId,
                'reference_no' => $reference,
                'amount_paid' => $sale->total,
                'amount_tendered' => $tendered === null ? null : Money::decimal($tendered),
                'change_due' => $tendered === null ? null : Money::decimal($tendered - $total),
                'created_at' => $timestamp,
                'updated_at' => $timestamp,
            ]);

            return ['sale' => $sale, 'replayed' => false];
        }, 5);
    }
}
