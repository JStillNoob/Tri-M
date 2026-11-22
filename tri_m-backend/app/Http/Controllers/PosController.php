<?php

namespace App\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Pagination\LengthAwarePaginator;

abstract class PosController extends Controller
{
    protected function result(mixed $data, string $message, int $status = 200): JsonResponse
    {
        return response()->json(['message' => $message, 'data' => $data], $status, ['Cache-Control' => 'no-store, private']);
    }

    /** @param class-string<JsonResource> $resource */
    protected function page(LengthAwarePaginator $paginator, string $resource, string $message): JsonResponse
    {
        return response()->json([
            'message' => $message,
            'data' => $resource::collection($paginator->items())->resolve(),
            'meta' => [
                'current_page' => $paginator->currentPage(),
                'last_page' => $paginator->lastPage(),
                'per_page' => $paginator->perPage(),
                'total' => $paginator->total(),
            ],
        ], headers: ['Cache-Control' => 'no-store, private']);
    }
}
