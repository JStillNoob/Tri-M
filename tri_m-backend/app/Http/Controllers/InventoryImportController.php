<?php

namespace App\Http\Controllers;

use App\Http\Resources\InventoryImportResource;
use App\Models\InventoryImport;
use App\Services\InventoryImportDrafts;
use App\Services\InventoryImports;
use App\Services\InventorySpreadsheet;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Pagination\LengthAwarePaginator;

class InventoryImportController extends PosController
{
    public function preview(Request $request, InventorySpreadsheet $spreadsheet, InventoryImports $imports, InventoryImportDrafts $drafts): JsonResponse
    {
        $request->validate([
            'file' => ['required', 'file', 'extensions:xlsx', 'mimetypes:application/vnd.openxmlformats-officedocument.spreadsheetml.sheet,application/zip', 'max:2048'],
        ]);
        $file = $request->file('file');
        $source = $spreadsheet->parse($file->getPathname());
        $preview = $imports->inspect($source);
        $import = $drafts->create([
            'user_id' => $request->user()->id,
            'file_name' => mb_substr($file->getClientOriginalName(), 0, 255),
            'preview' => $source,
            'expires_at' => now()->addMinutes(30),
        ]);

        return $this->result([
            'id' => $import->id,
            'expires_at' => $import->expires_at->toISOString(),
            ...$preview,
        ], 'Inventory preview ready.', 201);
    }

    public function confirm(Request $request, InventoryImports $imports): JsonResponse
    {
        $data = $request->validate(['preview_id' => ['required', 'uuid']]);
        $result = $imports->confirm($request->user(), $data['preview_id']);

        return $this->result($result, 'Inventory import completed.');
    }

    public function index(Request $request, InventoryImportDrafts $drafts): JsonResponse
    {
        $filters = $request->validate([
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
            'page' => ['nullable', 'integer', 'min:1', 'max:100000'],
            'include_drafts' => ['sometimes', 'boolean'],
        ]);

        $history = InventoryImport::query()->whereNotNull('confirmed_at')
            ->orderByRaw('COALESCE(confirmed_at, updated_at) DESC')->orderBy('id');
        $perPage = (int) ($filters['per_page'] ?? 25);
        if (! ($filters['include_drafts'] ?? false)) {
            return $this->page($history->paginate($perPage), InventoryImportResource::class, 'Inventory imports retrieved.');
        }

        $owner = $request->user()->id;
        $local = collect($drafts->all())->filter(fn (InventoryImport $draft) => $draft->user_id === $owner && $draft->status !== null)
            ->sortByDesc('updated_at')->values();
        if ($local->isNotEmpty()) {
            $confirmed = InventoryImport::whereKey($local->pluck('id')->all())->whereNotNull('confirmed_at')->pluck('id')->all();
            $local = $local->whereNotIn('id', $confirmed)->values();
        }
        $history->orWhere(fn ($query) => $query->where('user_id', $owner)->whereNotNull('status')->whereNotIn('id', $local->pluck('id')));
        $page = (int) ($filters['page'] ?? 1);
        $offset = ($page - 1) * $perPage;
        $items = $local->slice($offset, $perPage)->values();
        $remaining = $perPage - $items->count();
        $total = $local->count() + $history->count();
        if ($remaining > 0 && $offset < $total) {
            $items = $items->concat($history->offset(max(0, $offset - $local->count()))->limit($remaining)->get());
        }

        return $this->page(new LengthAwarePaginator($items, $total, $perPage, $page), InventoryImportResource::class, 'Inventory imports retrieved.');
    }
}
