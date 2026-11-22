<?php

namespace Database\Seeders;

use App\Models\Role;
use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use RuntimeException;

class PosAccountSeeder extends Seeder
{
    public function run(): void
    {
        $command = $this->command;

        if ($command === null || ($command->hasOption('no-interaction') && $command->option('no-interaction'))) {
            throw new RuntimeException('Run this seeder interactively with php artisan db:seed --class=PosAccountSeeder.');
        }

        $accounts = [
            'owner' => $this->readAccount($command, 'Owner'),
            'cashier' => $this->readAccount($command, 'Cashier'),
        ];

        if ($accounts['owner']['email'] === $accounts['cashier']['email']) {
            throw new RuntimeException('Enter different email addresses for the Owner and Cashier.');
        }

        DB::transaction(function () use ($accounts): void {
            $this->callSilent(PosLookupSeeder::class);

            foreach ($accounts as $roleName => $account) {
                $role = Role::query()->where('name', $roleName)->sole();
                $user = User::query()->firstOrCreate(
                    ['email' => $account['email']],
                    [...$account, 'role_id' => $role->id],
                );

                if ($user->role_id !== $role->id) {
                    throw new RuntimeException("The email entered for {$roleName} already belongs to an account with a different role.");
                }
            }
        });

        $command->info('Owner and Cashier accounts are in place.');
    }

    /** @return array{email: string, first_name: string, last_name: string, password: string} */
    private function readAccount(Command $command, string $label): array
    {
        $account = Validator::make([
            'email' => mb_strtolower(trim((string) $command->ask("{$label} email"))),
            'first_name' => trim((string) $command->ask("{$label} first name")),
            'last_name' => trim((string) $command->ask("{$label} last name")),
        ], [
            'email' => ['required', 'email', 'max:255'],
            'first_name' => ['required', 'string', 'max:100'],
            'last_name' => ['required', 'string', 'max:100'],
        ])->validate();

        $password = $command->secret("{$label} password", false);

        if (! is_string($password) || mb_strlen($password) < 12 || strlen($password) > 72 || $password !== $command->secret("Confirm {$label} password", false)) {
            throw new RuntimeException("Use a matching {$label} password of at least 12 characters and at most 72 bytes.");
        }

        return [...$account, 'password' => $password];
    }
}
