<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

return new class extends Migration
{
    public function up(): void
    {
        DB::transaction(function (): void {
            Schema::table('users', function (Blueprint $table): void {
                $table->foreignId('role_id')->nullable()->constrained()->restrictOnDelete();
                $table->index('role_id');
                $table->string('first_name', 100)->nullable();
                $table->string('middle_name', 100)->nullable();
                $table->string('last_name', 100)->nullable();
            });

            $this->backfillExistingUsers();

            Schema::table('users', function (Blueprint $table): void {
                $table->foreignId('role_id')->nullable(false)->change();
                $table->string('first_name', 100)->nullable(false)->change();
                $table->string('last_name', 100)->nullable(false)->change();
                $table->dropColumn('name');
            });
        });
    }

    public function down(): void
    {
        DB::transaction(function (): void {
            Schema::table('users', function (Blueprint $table): void {
                $table->string('name')->nullable();
            });

            foreach (DB::table('users')->lazyById() as $user) {
                $name = Str::squish(implode(' ', [$user->first_name, $user->middle_name, $user->last_name]));

                if (mb_strlen($name) > 255) {
                    throw new RuntimeException("User {$user->id} has a full name exceeding the legacy 255-character limit.");
                }

                DB::table('users')->where('id', $user->id)->update(['name' => $name]);
            }

            Schema::table('users', function (Blueprint $table): void {
                $table->string('name')->nullable(false)->change();
                $table->dropIndex(['role_id']);
                $table->dropConstrainedForeignId('role_id');
                $table->dropColumn(['first_name', 'middle_name', 'last_name']);
            });
        });
    }

    private function backfillExistingUsers(): void
    {
        $users = DB::table('users');

        if (! $users->exists()) {
            return;
        }

        $cashierId = DB::table('roles')->where('name', 'cashier')->value('id')
            ?? DB::table('roles')->insertGetId([
                'name' => 'cashier',
                'created_at' => now(),
                'updated_at' => now(),
            ]);

        foreach ($users->lazyById() as $user) {
            $parts = explode(' ', Str::squish($user->name));
            $firstName = array_shift($parts);
            $lastName = array_pop($parts) ?? '';
            $middleName = implode(' ', $parts);

            if (max(mb_strlen($firstName), mb_strlen($lastName), mb_strlen($middleName)) > 100) {
                throw new RuntimeException("User {$user->id} has a name component exceeding 100 characters.");
            }

            DB::table('users')->where('id', $user->id)->update([
                'role_id' => $cashierId,
                'first_name' => $firstName,
                'middle_name' => $middleName === '' ? null : $middleName,
                'last_name' => $lastName,
            ]);
        }
    }
};
