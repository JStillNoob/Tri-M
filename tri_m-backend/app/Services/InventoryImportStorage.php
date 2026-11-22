<?php

namespace App\Services;

use Aws\CommandInterface;
use Illuminate\Filesystem\FilesystemAdapter;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use RuntimeException;
use Throwable;

class InventoryImportStorage
{
    public const CONFIRMED_DIRECTORY = 'inventory-workbooks/confirmed';

    public const DRAFT_DIRECTORY = 'inventory-workbooks/drafts';

    public function usesSupabase(): bool
    {
        return config('filesystems.default') === 'supabase';
    }

    public function disk(): FilesystemAdapter
    {
        return Storage::disk($this->diskName());
    }

    public function diskName(): string
    {
        return $this->usesSupabase() ? 'supabase' : 'local';
    }

    public function writeOptions(): array
    {
        if (! $this->usesSupabase()) {
            return [];
        }

        // Supabase uses bucket privacy and does not support per-object S3 ACLs.
        return ['before_upload' => static function (CommandInterface $command): void {
            unset($command['ACL'], $command['AddContentMD5']);
        }];
    }

    public function saveWorkbook(UploadedFile $file, string $path): void
    {
        $disk = $this->disk();
        $options = $this->writeOptions();
        abort_if($disk->putFileAs(dirname($path), $file, basename($path), $options) === false,
            503, 'The workbook could not be stored. Try again.');
    }

    public function oldWorkbookPaths(int $cutoff): iterable
    {
        $driver = $this->disk()->getDriver();
        foreach ([self::DRAFT_DIRECTORY, self::CONFIRMED_DIRECTORY] as $directory) {
            foreach ($driver->listContents($directory, false) as $entry) {
                if ($entry->isFile() && $entry->lastModified() !== null && $entry->lastModified() <= $cutoff) {
                    yield $entry->path();
                }
            }
        }
    }

    public function tryDelete(string $path): bool
    {
        try {
            if (! $this->disk()->delete($path)) {
                throw new RuntimeException("Could not remove inventory import file: {$path}");
            }

            return true;
        } catch (Throwable $exception) {
            report($exception);

            return false;
        }
    }

    public function readWorkbook(string $path, callable $read): mixed
    {
        $disk = $this->disk();

        if (! $this->usesSupabase()) {
            return $read($disk->path($path));
        }

        $temporary = tmpfile();
        abort_if($temporary === false, 503, 'The workbook could not be opened. Try again.');

        try {
            $source = $disk->readStream($path);
            abort_unless(is_resource($source), 503, 'The workbook could not be read. Try again.');

            try {
                abort_if(stream_copy_to_stream($source, $temporary) === false, 503, 'The workbook could not be read. Try again.');
            } finally {
                fclose($source);
            }

            return $read(stream_get_meta_data($temporary)['uri']);
        } finally {
            fclose($temporary);
        }
    }
}
