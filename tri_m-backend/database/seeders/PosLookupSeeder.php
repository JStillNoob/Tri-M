<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class PosLookupSeeder extends Seeder
{
    public function run(): void
    {
        $lookups = [
            'roles' => ['owner', 'cashier'],
            'payment_methods' => ['cash', 'ewallet'],
            'wallet_providers' => ['gcash', 'maya'],
            'stock_movement_types' => ['opening', 'restock', 'sale', 'adjustment'],
        ];

        DB::transaction(function () use ($lookups): void {
            $timestamp = now();

            foreach ($lookups as $table => $names) {
                DB::table($table)->insertOrIgnore(array_map(
                    fn (string $name): array => [
                        'name' => $name,
                        'created_at' => $timestamp,
                        'updated_at' => $timestamp,
                    ],
                    $names,
                ));
            }
        });
    }
}
