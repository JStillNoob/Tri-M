<?php

namespace App\Services;

use App\Models\Product;
use App\Models\User;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use LogicException;

class Inventory
{
    public const int MAX_STOCK = 2147483647;

    private array $typeIds = [];

    public function value(Product $product): BigDecimal
    {
        return $product->inventory_value === null
            ? Money::lineTotal($product->cost, $product->stock)
            : BigDecimal::of($product->inventory_value);
    }

    public function saleCost(Product $product, int $quantity): BigDecimal
    {
        return $this->value($product)->multipliedBy($quantity)
            ->dividedBy($product->stock, 2, RoundingMode::HalfUp);
    }

    public function move(Product $product, int $quantity, string $type, User $actor, ?int $saleItem = null, ?string $remarks = null, ?string $importId = null, ?int $importRow = null, ?string $purchaseUnitCost = null, ?BigDecimal $soldCost = null): int
    {
        $movement = $this->prepareMovement($product, $quantity, $type, $actor, $saleItem, $remarks, $importId, $importRow, $purchaseUnitCost, $soldCost);
        $product->save();

        return (int) DB::table('stock_movements')->insertGetId($movement);
    }

    public function prepareMovement(Product $product, int $quantity, string $type, User $actor, ?int $saleItem = null, ?string $remarks = null, ?string $importId = null, ?int $importRow = null, ?string $purchaseUnitCost = null, ?BigDecimal $soldCost = null): array
    {
        $previous = $product->stock;
        $balance = $previous + $quantity;

        if ($balance < 0 || $balance > self::MAX_STOCK) {
            throw ValidationException::withMessages(['stock' => "The stock change for {$product->name} is outside the allowed range."]);
        }

        $typeId = $this->typeIds[$type] ??= DB::table('stock_movement_types')->where('name', $type)->value('id');

        if ($typeId === null) {
            throw new LogicException('The required stock movement type is missing.');
        }

        $value = $this->value($product);
        $value = $quantity > 0
            ? $value->plus(Money::lineTotal($purchaseUnitCost ?? $product->cost, $quantity))
            : $value->minus($soldCost ?? $this->saleCost($product, -$quantity));

        $product->forceFill([
            'stock' => $balance,
            'inventory_value' => (string) $value,
            'cost' => $balance === 0 ? $product->cost : (string) $value->dividedBy($balance, 2, RoundingMode::HalfUp),
        ]);

        return [
            'product_id' => $product->id,
            'movement_type_id' => $typeId,
            'user_id' => $actor->id,
            'sale_item_id' => $saleItem,
            'inventory_import_id' => $importId,
            'inventory_import_row_id' => $importRow,
            'previous_stock' => $previous,
            'resulting_stock' => $balance,
            'quantity' => $quantity,
            'remarks' => $remarks,
        ];
    }
}
