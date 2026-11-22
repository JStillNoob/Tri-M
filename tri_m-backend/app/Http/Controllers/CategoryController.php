<?php

namespace App\Http\Controllers;

use App\Http\Resources\CategoryResource;
use App\Models\Category;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class CategoryController extends PosController
{
    public function index(Request $request): JsonResponse
    {
        $request->validate(['include_counts' => ['sometimes', 'boolean']]);
        $query = Category::query()->select(['id', 'name'])->orderBy('name');

        if ($request->user()->hasRole('owner') && $request->boolean('include_counts', true)) {
            $query->withCount('products');
        }

        return $this->result(CategoryResource::collection($query->get()), 'Categories retrieved.');
    }

    public function store(Request $request): JsonResponse
    {
        $category = $this->save($request, new Category);

        return $this->result(new CategoryResource($category), 'Category created.', 201);
    }

    public function update(Request $request, Category $category): JsonResponse
    {
        return $this->result(new CategoryResource($this->save($request, $category)), 'Category updated.');
    }

    public function destroy(Category $category): JsonResponse
    {
        DB::transaction(function () use ($category): void {
            $current = Category::query()->lockForUpdate()->findOrFail($category->id);
            abort_if($current->products()->exists(), 409, 'Move products to another category before removing this category.');
            $current->delete();
        }, 5);

        return $this->result(null, 'Category removed.');
    }

    private function save(Request $request, Category $category): Category
    {
        $data = $request->validate(['name' => ['required', 'string', 'max:100']]);

        if (mb_strtolower($data['name']) === 'all') {
            throw ValidationException::withMessages(['name' => 'Choose a category name other than All.']);
        }

        try {
            $category->fill($data)->save();
        } catch (UniqueConstraintViolationException) {
            throw ValidationException::withMessages(['name' => 'This category already exists. Choose a different name.']);
        }

        return $category->loadCount('products');
    }
}
