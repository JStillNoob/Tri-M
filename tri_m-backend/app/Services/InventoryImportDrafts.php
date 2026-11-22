<?php

namespace App\Services;

use App\Models\InventoryImport;
use App\Models\InventoryImportRow;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;
use Throwable;

class InventoryImportDrafts
{
    public function __construct(private Filesystem $files, private InventoryImportStorage $storage) {}

    public function create(array $data, ?string $id = null): InventoryImport
    {
        $batch = new InventoryImport($data);
        $batch->forceFill(['id' => $id ?? (string) Str::orderedUuid(), 'created_at' => now()]);
        $batch->setRelation('rows', new Collection);
        $this->save($batch);

        return $batch;
    }

    public function save(InventoryImport $batch): void
    {
        $batch->updated_at = now();
        $disk = $this->storage->disk();
        $path = "inventory-drafts/{$batch->id}.json";
        $data = json_encode([
            'batch' => $batch->getAttributes(),
            'rows' => $batch->getRelation('rows')->map(fn (InventoryImportRow $row) => $row->getAttributes())->all(),
        ], JSON_THROW_ON_ERROR);

        if ($this->storage->usesSupabase()) {
            abort_unless($disk->put($path, $data, $this->storage->writeOptions()), 503, 'The import draft could not be saved. Try again.');

            return;
        }

        $disk->makeDirectory('inventory-drafts');
        $this->files->replace($disk->path($path), $data, 0600);
    }

    public function find(string $id): ?InventoryImport
    {
        $disk = $this->storage->disk();
        $path = "inventory-drafts/$id.json";
        if ($this->storage->usesSupabase() && ! $disk->fileExists($path)) {
            return null;
        }
        $json = $disk->get($path);
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
        foreach ($this->storage->disk()->files('inventory-drafts') as $path) {
            $id = pathinfo($path, PATHINFO_FILENAME);
            if (Str::isUuid($id) && ($batch = $this->find($id)) !== null) {
                yield $batch;
            }
        }
    }

    public function metadataPaths(string $id): array
    {
        return ["inventory-drafts/confirmations/$id", "inventory-drafts/$id.json"];
    }

    public function forget(string $id): ?string
    {
        foreach ($this->metadataPaths($id) as $path) {
            if (! $this->storage->tryDelete($path)) {
                return $path;
            }
        }

        return null;
    }

    public function lock(string $id, callable $action): mixed
    {
        if ($this->storage->usesSupabase()) {
            $lock = Cache::store('database')->lock('pos-import-draft:'.strtolower($id), 300);
            try {
                $lock->block(5);
            } catch (LockTimeoutException) {
                abort(503, 'The import draft is busy. Try again.');
            }

            try {
                return $action();
            } finally {
                try {
                    $lock->release();
                } catch (Throwable $exception) {
                    report($exception);
                }
            }
        }

        $bucket = substr(hash('sha256', $id), 0, 2);
        $disk = $this->storage->disk();
        $disk->makeDirectory('inventory-drafts/locks');
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
