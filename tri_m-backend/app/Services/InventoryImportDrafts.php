<?php

namespace App\Services;

use App\Models\InventoryImport;
use App\Models\InventoryImportRow;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class InventoryImportDrafts
{
    public function __construct(private Filesystem $files) {}

    public function create(array $data): InventoryImport
    {
        $batch = new InventoryImport($data);
        $batch->forceFill(['id' => (string) Str::orderedUuid(), 'created_at' => now()]);
        $batch->setRelation('rows', new Collection);
        $this->save($batch);

        return $batch;
    }

    public function save(InventoryImport $batch): void
    {
        $batch->updated_at = now();
        $disk = Storage::disk('local');
        $disk->makeDirectory('inventory-drafts');
        $this->files->replace($disk->path("inventory-drafts/{$batch->id}.json"), json_encode([
            'batch' => $batch->getAttributes(),
            'rows' => $batch->getRelation('rows')->map(fn (InventoryImportRow $row) => $row->getAttributes())->all(),
        ], JSON_THROW_ON_ERROR), 0600);
    }

    public function find(string $id): ?InventoryImport
    {
        $json = Storage::disk('local')->get("inventory-drafts/$id.json");
        if ($json === null) {
            return null;
        }
        $data = json_decode($json, true, flags: JSON_THROW_ON_ERROR);
        $batch = (new InventoryImport)->newFromBuilder($data['batch']);
        $row = new InventoryImportRow;

        return $batch->setRelation('rows', new Collection(array_map($row->newFromBuilder(...), $data['rows'])));
    }

    public function all(): iterable
    {
        foreach (Storage::disk('local')->files('inventory-drafts') as $path) {
            $id = pathinfo($path, PATHINFO_FILENAME);
            if (Str::isUuid($id) && ($batch = $this->find($id)) !== null) {
                yield $batch;
            }
        }
    }

    public function forget(string $id): void
    {
        Storage::disk('local')->delete(["inventory-drafts/$id.json", "inventory-drafts/confirmations/$id"]);
    }

    public function markConfirmationStarted(string $id): void
    {
        abort_unless(Storage::disk('local')->put("inventory-drafts/confirmations/$id", '1'), 503, 'The confirmation could not be started. Try again.');
    }

    public function confirmationStarted(string $id): bool
    {
        return Storage::disk('local')->exists("inventory-drafts/confirmations/$id");
    }

    public function lock(string $id, callable $action): mixed
    {
        $disk = Storage::disk('local');
        $disk->makeDirectory('inventory-drafts/locks');
        $bucket = substr(hash('sha256', $id), 0, 2);
        $handle = fopen($disk->path("inventory-drafts/locks/$bucket"), 'c');
        abort_if($handle === false, 503, 'The import draft could not be opened. Try again.');
        try {
            abort_unless(flock($handle, LOCK_EX), 503, 'The import draft is busy. Try again.');

            return $action();
        } finally {
            fclose($handle);
        }
    }
}
