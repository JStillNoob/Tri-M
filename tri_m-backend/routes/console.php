<?php

use App\Models\Role;
use App\Models\User;
use App\Services\InventoryImportStorage;
use App\Services\SupplierStockImports;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;
use Illuminate\Support\Facades\Validator;
use Symfony\Component\Console\Formatter\OutputFormatter;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

Artisan::command('pos:user {email} {role} {first_name} {last_name} {--middle-name=}', function (): int {
    $account = [
        'email' => mb_strtolower(trim($this->argument('email'))),
        'role' => $this->argument('role'),
        'first_name' => trim($this->argument('first_name')),
        'middle_name' => $this->option('middle-name'),
        'last_name' => trim($this->argument('last_name')),
    ];
    $validator = Validator::make($account, [
        'email' => ['required', 'email', 'max:255', 'unique:users,email'],
        'role' => ['required', 'in:owner,cashier', 'exists:roles,name'],
        'first_name' => ['required', 'string', 'max:100'],
        'middle_name' => ['nullable', 'string', 'max:100'],
        'last_name' => ['required', 'string', 'max:100'],
    ]);

    if ($validator->fails()) {
        foreach ($validator->errors()->all() as $error) {
            $this->error($error);
        }

        return 1;
    }

    $password = $this->secret('Password');

    if (! is_string($password) || mb_strlen($password) < 12 || strlen($password) > 72 || $password !== $this->secret('Confirm password')) {
        $this->error('Use a matching password of at least 12 characters and at most 72 bytes.');

        return 1;
    }

    $role = Role::query()->where('name', $account['role'])->sole();
    $user = new User([...$account, 'password' => $password]);
    $user->role()->associate($role);

    try {
        $user->save();
    } catch (UniqueConstraintViolationException) {
        $this->error('An account already uses that email.');

        return 1;
    }

    $this->info('POS account created.');

    return 0;
})->purpose('Create an owner or cashier account');

Artisan::command('pos:prune-imports {--dry-run : List files eligible for deletion}', function (SupplierStockImports $imports, InventoryImportStorage $storage): int {
    $disk = $storage->diskName();
    $bucket = $disk === 'local' ? 'None (local filesystem)' : (config("filesystems.disks.$disk.bucket") ?: 'Not configured');
    $this->line("Storage disk: $disk");
    $this->line('Storage bucket: '.OutputFormatter::escape($bucket));

    if ($this->option('dry-run')) {
        $preview = $imports->previewPrune();
        foreach ($preview['files'] as $path => $reason) {
            $this->line(OutputFormatter::escape($path).'  '.$reason);
        }
        $this->info('File deletion candidates: '.count($preview['files']));
        $this->line('Expired database preview records: '.$preview['expired_previews']);
        $this->info('Dry run complete.');

        return 0;
    }

    $failed = $imports->prune();
    foreach ($failed as $path) {
        $this->error('Deletion failed: '.OutputFormatter::escape($path));
    }
    if ($failed !== []) {
        $this->error('Files with failed deletion attempts: '.count($failed));

        return 1;
    }

    $this->info('Import cleanup complete.');

    return 0;
})->purpose('Remove expired import drafts and unreferenced inventory workbooks');

Schedule::command('pos:prune-imports')->daily();
