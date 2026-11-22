<?php

namespace App\Services;

use App\Models\Category;
use App\Models\InventoryImport;
use App\Models\InventoryImportRow;
use App\Models\Product;
use App\Models\User;
use Brick\Math\BigDecimal;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\QueryException;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Throwable;

class SupplierStockImports
{
    public function __construct(
        private SupplierWorkbook $workbook,
        private Inventory $inventory,
        private InventoryImportDrafts $drafts,
        private InventoryImportStorage $storage,
    ) {}

    public function upload(User $owner, UploadedFile $file, bool $autoReview = false): array
    {
        $workbook = $this->workbook->inspect($file->getPathname());
        $id = (string) Str::orderedUuid();
        $path = InventoryImportStorage::DRAFT_DIRECTORY."/{$id}.xlsx";
        $batch = $this->drafts->lock($id, function () use ($owner, $file, $workbook, $id, $path): InventoryImport {
            $this->storage->saveWorkbook($file, $path);

            return $this->drafts->create([
                'user_id' => $owner->id,
                'file_name' => mb_substr($file->getClientOriginalName(), 0, 255),
                'file_path' => $path,
                'file_hash' => hash_file('sha256', $file->getPathname()),
                'status' => 'uploaded',
                'preview' => ['sheets' => $workbook['sheets'], 'sheet' => $workbook['sheet'], 'revision' => 0],
                'expires_at' => now()->addDay(),
            ], $id);
        });

        $sheet = $workbook['sheet'];
        $mapping = (array) $sheet['mapping'];
        if ($autoReview && isset($mapping['name'], $mapping['quantity']) && collect($workbook['sheets'])->where('visible', true)->count() === 1) {
            try {
                $batch = $this->map($owner, $batch->id, $sheet['index'], $sheet['header'], $mapping);
            } catch (ValidationException $exception) {
                return [...$this->describe($batch, 1, 'all'), 'setup_error' => $exception->validator->errors()->first()];
            }
        }

        return $this->describe($batch, 1, 'all');
    }

    public function worksheet(User $owner, string $id, int $sheet): array
    {
        return $this->drafts->lock($id, function () use ($owner, $id, $sheet): array {
            $batch = $this->draft($owner, $id);

            return $this->storage->readWorkbook($batch->file_path, fn (string $path) => $this->workbook->worksheet($path, $sheet));
        });
    }

    public function map(User $owner, string $id, int $sheet, int $header, array $mapping): InventoryImport
    {
        return $this->drafts->lock($id, function () use ($owner, $id, $sheet, $header, $mapping): InventoryImport {
            $batch = $this->draft($owner, $id);
            $data = $this->storage->readWorkbook($batch->file_path, fn (string $path) => $this->workbook->extract($path, $sheet, $header, $mapping));
            $rows = $this->mappedRows($batch, $data['rows'], $this->catalog());
            $batch->setRelation('rows', $rows)->fill([
                'status' => 'review',
                'worksheet_name' => $data['sheet'],
                'preview' => [
                    'sheets' => $batch->preview['sheets'], 'sheet_index' => $sheet, 'header' => $header, 'mapping' => $mapping,
                    'item_cost_checked' => true,
                    'warnings' => [],
                    'revision' => $batch->preview['revision'] + 1,
                ],
            ]);
            $this->drafts->save($batch);

            return $batch;
        });
    }

    private function mappedRows(InventoryImport $batch, array $details, Collection $products): Collection
    {
        $names = $products->groupBy(fn (Product $product) => Product::nameKey($product->name));
        $rows = new Collection;
        foreach ($details as $line) {
            $name = Product::nameKey($line['uploaded_name'] ?? '');
            $hits = $name === '' ? collect() : ($names[$name] ?? collect());
            $product = $hits->count() === 1 ? $hits->first() : null;
            $warnings = $line['warnings'];
            if ($hits->count() > 1) {
                $warnings[] = 'Multiple catalog products match. Select the correct product.';
            } elseif ($product?->trashed()) {
                $product = null;
                $warnings[] = 'The matching product is archived. Restore it before importing stock.';
            }
            $price = $line['original_selling_price'];
            $priceChanged = $product && $price !== null && Money::centavos($price) !== Money::centavos($product->price);
            if ($priceChanged) {
                $warnings[] = 'The workbook price differs from the catalog price. Review the price.';
            }
            $candidates = $hits->take(20)->map(fn (Product $candidate) => $this->snapshot($candidate))->values()->all();
            $ready = $product && $line['original_unit_cost'] !== null && $line['errors'] === [] && $warnings === [];
            $row = new InventoryImportRow([
                ...$line,
                'warnings' => $warnings,
                'candidates' => $candidates,
                'decision' => $ready ? 'existing' : 'pending',
                'matching_method' => $product === null ? null : 'name',
                'product_id' => $product?->id,
            ]);
            $rows->push($row->forceFill(['id' => $row->row_number, 'inventory_import_id' => $batch->id]));
        }

        return $rows;
    }

    public function review(User $owner, string $id, int $rowId, array $decision): void
    {
        $this->drafts->lock($id, function () use ($owner, $id, $rowId, $decision): void {
            $batch = $this->draft($owner, $id, 'review');
            $row = $batch->getRelation('rows')->find($rowId);
            abort_if($row === null, 404);
            $product = $decision['decision'] === 'existing' ? Product::find($decision['product_id']) : null;
            $this->applyReview($row, $decision, $product, $this->savedWorkbookCosts($batch));
            $batch->preview = [...$batch->preview, 'revision' => $batch->preview['revision'] + 1];
            $this->drafts->save($batch);
        });
    }

    private function applyReview(InventoryImportRow $row, array $decision, ?Product $product, array $workbookCosts = []): void
    {
        $action = $decision['decision'];
        $quantity = $decision['quantity'] ?? $row->quantity;
        if ($action !== 'skip' && SupplierWorkbook::isReceiptPlaceholder($row->uploaded_name ?? '')) {
            $this->invalid($row, 'This is a receipt placeholder. Skip this row.');
        }
        if ($action !== 'skip' && ! $quantity) {
            throw ValidationException::withMessages(['quantity' => 'Enter a positive whole quantity.']);
        }
        if ($action === 'existing' && $product === null) {
            $this->invalid($row, 'Select an active catalog product.');
        }
        $useWorkbookCategory = $action === 'new' && ($decision['use_workbook_category'] ?? false);
        $categoryId = $action === 'skip' ? null : ($decision['category_id'] ?? null);
        $stockIn = $this->stockInFields($row);
        $source = $row->source;
        if ($action !== 'skip') {
            $source['reviewed'] = [
                ...($source['reviewed'] ?? []),
                'date' => array_key_exists('date', $decision) ? $decision['date'] : $stockIn['date'],
                'supplier' => array_key_exists('supplier', $decision) ? $decision['supplier'] : $stockIn['supplier'],
            ];
            if (array_key_exists('category_name', $decision)) {
                $source['reviewed']['category_name'] = $decision['category_name'];
            }
        }
        $row->source = [...$source, 'use_workbook_category' => $useWorkbookCategory];
        if ($action === 'new' && ($useWorkbookCategory ? $categoryId !== null || $this->workbookCategoryName($row) === null : $categoryId === null)) {
            throw ValidationException::withMessages(['category_id' => 'Choose a catalog category or use a valid workbook category.']);
        }
        if ($action !== 'skip') {
            if (array_key_exists('item_cost', $decision)) {
                $row->approved_cost = $decision['item_cost'];
            }
            $this->purchaseCost($row, $this->costFields($row, $workbookCosts));
        }
        $price = $action === 'skip' ? null : ($decision['approved_selling_price'] ?? null);
        if ($action === 'existing') {
            $choice = $decision['price_decision'] ?? null;
            if ($choice === null || ($choice === 'change' && $price === null)) {
                throw ValidationException::withMessages(['price_decision' => 'Choose to keep the current price or enter a new price.']);
            }
            $price = $choice === 'keep' ? null : $price;
            $markup = $decision['markup_percentage'] ?? $row->markup_percentage;
            $row->source = [
                ...$row->source,
                'price_decision' => $choice,
                'reviewed_catalog_price' => $product->price,
                'catalog_markup' => $choice === 'change' && $markup !== null ? (string) BigDecimal::of($markup)->toScale(4) : null,
            ];
        } else {
            $row->source = array_diff_key($row->source, array_flip(['price_decision', 'reviewed_catalog_price', 'catalog_markup']));
        }
        $row->fill([
            'decision' => $action,
            'matching_method' => $action === 'existing' ? 'manual' : null,
            'product_id' => $product?->id,
            'quantity' => $quantity,
            'errors' => $action === 'skip' ? $row->errors : [],
            'new_name' => $action === 'new' ? $decision['new_name'] : null,
            'category_id' => $categoryId,
            'warnings' => $action === 'skip' ? $row->warnings : array_values(array_filter($row->warnings, fn ($warning) => $warning !== 'The workbook price differs from the catalog price. Review the price.')),
            'approved_selling_price' => $price,
            'markup_percentage' => $action === 'skip' ? null : (
                array_key_exists('markup_percentage', $decision) ? $decision['markup_percentage'] : $row->markup_percentage
            ),
        ]);
    }

    public function bulkMarkup(User $owner, string $id, int $revision, array $rowIds, string $markup): void
    {
        $this->drafts->lock($id, function () use ($owner, $id, $revision, $rowIds, $markup): void {
            $batch = $this->draft($owner, $id, 'review');
            abort_unless($revision === $batch->preview['revision'], 409, 'The review changed. Reload the import before applying markup.');
            $rows = $batch->getRelation('rows')->whereIn('id', $rowIds);
            if ($rows->count() !== count($rowIds)) {
                throw ValidationException::withMessages(['rows' => 'Select rows from this workbook.']);
            }

            $workbookCosts = $this->savedWorkbookCosts($batch);
            foreach ($rows as $row) {
                if ($row->decision === 'skip' || SupplierWorkbook::isReceiptPlaceholder($row->uploaded_name ?? '')) {
                    $this->invalid($row, 'Select an included purchase row.');
                }
                if ($row->markup_percentage !== null) {
                    $this->invalid($row, 'Use the row editor to change existing markup.');
                }
                $this->purchaseCost($row, $this->costFields($row, $workbookCosts));
                $row->markup_percentage = $markup;
            }

            $batch->preview = [...$batch->preview, 'revision' => $revision + 1];
            $this->drafts->save($batch);
        });
    }

    public function details(User $owner, string $id, int $page, string $filter = 'all', string $search = ''): array
    {
        $batch = $this->findBatch($id);
        abort_unless($batch->confirmed_at || $batch->user_id === $owner->id, 404);

        return $this->describe($batch, $page, $filter, $search);
    }

    private function describe(InventoryImport $batch, int $page, string $filter, string $search = ''): array
    {
        $rows = in_array($batch->status, [null, 'uploaded'], true)
            ? new Collection : ($batch->relationLoaded('rows') ? $batch->getRelation('rows') : $batch->rows()->orderBy('row_number')->get());
        $productIds = $rows->pluck('product_id')->filter()->unique()->values()->all();
        $products = $productIds === [] ? new Collection : Product::withTrashed()->whereKey($productIds)->get()->keyBy('id');
        $categoryReviews = $this->categoryReviews($rows, $products, $batch->confirmed_at !== null);
        $workbookCosts = $this->savedWorkbookCosts($batch);
        $totals = $this->summarize($rows, $categoryReviews);
        $priceReviews = $batch->confirmed_at ? new Collection : $rows->filter(fn (InventoryImportRow $row) => $row->decision === 'existing' && $this->needsPriceReview($row, $products->get($row->product_id)))->keyBy('id');
        $totals['price_review_rows'] = $priceReviews->count();
        $selected = $rows->filter(fn (InventoryImportRow $row) => match ($filter) {
            'pending' => $row->decision === 'pending' || $priceReviews->has($row->id),
            'warnings' => $priceReviews->has($row->id) || $this->hasWarnings($row, $categoryReviews[$row->row_number]),
            'skipped' => $row->decision === 'skip',
            default => true,
        });
        $query = SupplierWorkbook::identity($search);
        if ($query !== '') {
            $rowNumber = preg_match('/^(?:row\s*#?\s*|#\s*)?([0-9]+)$/D', $query, $matches) ? (int) $matches[1] : null;
            $selected = $selected->filter(fn (InventoryImportRow $row) => $rowNumber !== null
                ? (int) $row->row_number === $rowNumber
                : collect([$row->source['applied']['name'] ?? null, $row->new_name, $products->get($row->product_id)?->name, $row->uploaded_name])
                    ->contains(fn (?string $name) => str_contains(SupplierWorkbook::identity($name ?? ''), $query)));
        }
        $pageSize = 20;
        $lastPage = max(1, (int) ceil($selected->count() / $pageSize));
        $page = min($page, $lastPage);
        $expired = in_array($batch->status, ['uploaded', 'review'], true) && $batch->expires_at->isPast();

        return [
            'id' => $batch->id, 'file_name' => $batch->file_name, 'worksheet_name' => $batch->worksheet_name,
            'status' => $expired ? 'expired' : ($batch->status ?? ($batch->confirmed_at ? 'approved' : 'uploaded')),
            'confirmed_at' => $batch->confirmed_at?->toISOString(), 'expires_at' => $batch->expires_at->toISOString(),
            'setup' => $batch->status === null ? [] : $batch->preview,
            'summary' => $batch->result ?? array_diff_key($totals, ['product_quantities' => true, 'new_quantities' => true]),
            'rows' => $selected->forPage($page, $pageSize)->values()->map(function (InventoryImportRow $row) use ($products, $totals, $batch, $categoryReviews, $workbookCosts, $priceReviews): array {
                $product = $products[$row->product_id] ?? null;
                $entry = $batch->confirmed_at ? ($row->source['applied'] ?? null) : null;
                $category = $categoryReviews[$row->row_number];
                $cost = $this->costFields($row, $workbookCosts);
                $price = $entry['selling_price'] ?? $row->approved_selling_price ?? $product?->price;

                return [
                    ...$row->only(['id', 'row_number', 'uploaded_name', 'quantity', 'decision', 'matching_method', 'product_id', 'category_id',
                        'new_name', 'approved_selling_price', 'errors', 'candidates', 'processed_at']),
                    'stock_in' => [...$this->stockInFields($row), ...$cost],
                    'markup_percentage' => $entry['markup_percentage'] ?? $row->markup_percentage,
                    'suggested_selling_price' => Money::suggestedPrice($cost['item_cost'], $row->markup_percentage),
                    'purchase' => $this->purchaseAmounts($row->quantity, $cost['item_cost'], $price),
                    'can_include' => ! SupplierWorkbook::isReceiptPlaceholder($row->uploaded_name ?? ''),
                    'applied' => $entry,
                    'category_review' => $category,
                    'warnings' => $category['warning'] === null ? $row->warnings : [...$row->warnings, $category['warning']],
                    'product' => $product === null ? null : $this->snapshot($product),
                    'price_decision' => $priceReviews->has($row->id) ? null : ($row->source['price_decision'] ?? null),
                    'price_review_required' => $priceReviews->has($row->id),
                    'expected_stock' => $entry['resulting_stock'] ?? ($product && ! $batch->confirmed_at && $row->decision === 'existing'
                        ? $product->stock + ($totals['product_quantities'][$product->id] ?? 0)
                        : ($row->decision === 'new' && ! $batch->confirmed_at ? ($totals['new_quantities'][$this->newKey($row)] ?? 0) : null)),
                ];
            }),
            'pagination' => ['current_page' => $page, 'last_page' => $lastPage, 'total' => $selected->count()],
        ];
    }

    public function approve(User $owner, string $id, int $revision, bool $acknowledged): array
    {
        return $this->drafts->lock($id, fn () => $this->approveLocked($owner, $id, $revision, $acknowledged));
    }

    private function approveLocked(User $owner, string $id, int $revision, bool $acknowledged): array
    {
        $draft = $this->draft($owner, $id, 'review');
        abort_unless($revision === $draft->preview['revision'], 409, 'The review changed. Reload the import and verify the latest preview.');
        $workbookCosts = $this->savedWorkbookCosts($draft);
        $batch = $this->commitTransaction(function () use ($owner, $id, $draft, $acknowledged, $workbookCosts): InventoryImport {
            $this->protectStagedWorkbook($id, $draft->file_path);
            Product::lockCatalogWrites();
            $source = hash('sha256', $draft->file_hash.'|'.$draft->preview['sheet_index']);
            $this->requireUnusedSource($source);

            $batch = clone $draft;
            $batch->setRelation('rows', $draft->getRelation('rows')->map(fn (InventoryImportRow $row) => clone $row));

            return $this->commitReviewed($batch, $owner, $source, $acknowledged, $workbookCosts);
        });
        $this->drafts->forget($id);

        return $batch->result;
    }

    public function catalogForReview(): array
    {
        return [
            'products' => $this->catalog()->map(fn (Product $product) => [
                ...$this->snapshot($product), 'category_id' => $product->category_id,
            ])->all(),
            'categories' => Category::orderBy('name')->get(['id', 'name'])->toArray(),
        ];
    }

    public function confirmWorkbook(User $owner, UploadedFile $file, array $data): array
    {
        $path = $file->getPathname();
        $fileHash = hash_file('sha256', $path);
        $mapping = array_filter($data['mapping'], fn ($column) => $column !== null);
        $sheet = (int) $data['sheet'];
        $header = (int) $data['header'];
        $workbook = $this->workbook->extract($path, $sheet, $header, $mapping);
        $source = hash('sha256', $fileHash.'|'.$sheet);
        $id = strtolower($data['draft_id']);
        $archive = $this->storage->usesSupabase() ? InventoryImportStorage::CONFIRMED_DIRECTORY."/{$id}-{$fileHash}.xlsx" : null;

        $confirm = function () use ($owner, $file, $data, $workbook, $fileHash, $mapping, $sheet, $header, $source, $id, $archive): array {
            if ($archive !== null) {
                $previous = $this->confirmedWorkbook($owner, $id, $source);
                if ($previous !== null) {
                    return $previous->result;
                }
                $this->requireUnusedSource($source);
                $this->storage->saveWorkbook($file, $archive);
            }

            return $this->commitTransaction(function () use ($owner, $file, $data, $workbook, $fileHash, $mapping, $sheet, $header, $source, $id, $archive): InventoryImport {
                $this->protectStagedWorkbook($id, $archive);
                Product::lockCatalogWrites();
                $previous = $this->confirmedWorkbook($owner, $id, $source);
                if ($previous !== null) {
                    return $previous;
                }
                $this->requireUnusedSource($source);

                $batch = (new InventoryImport([
                    'user_id' => $owner->id,
                    'file_name' => mb_substr($file->getClientOriginalName(), 0, 255),
                    'file_path' => $archive,
                    'file_hash' => $fileHash,
                    'status' => 'review',
                    'worksheet_name' => $workbook['sheet'],
                    'preview' => ['sheet_index' => $sheet, 'header' => $header, 'mapping' => $mapping, 'item_cost_checked' => true],
                    'expires_at' => now()->addDay(),
                ]))->forceFill(['id' => $id]);
                $this->reviewWorkbookRows($batch, $workbook['rows'], $data['rows']);

                return $this->commitReviewed($batch, $owner, $source, true, []);
            })->result;
        };

        return $archive === null ? $confirm() : $this->drafts->lock($id, $confirm);
    }

    private function confirmedWorkbook(User $owner, string $id, string $source): ?InventoryImport
    {
        $previous = InventoryImport::find($id);
        if ($previous !== null) {
            abort_unless($previous->user_id === $owner->id && $previous->confirmed_at !== null
                && $previous->approved_source === $source, 409, 'This import reference is already in use.');
        }

        return $previous;
    }

    private function reviewWorkbookRows(InventoryImport $batch, array $sourceRows, array $decisions): void
    {
        $products = $this->catalog()->keyBy('id');
        $categories = Category::pluck('id')->flip();
        $rows = $this->mappedRows($batch, $sourceRows, $products);
        $reviews = collect($decisions)->keyBy('row_number');

        if ($reviews->count() !== $rows->count() || $rows->contains(fn (InventoryImportRow $row) => ! $reviews->has($row->row_number))) {
            throw ValidationException::withMessages(['rows' => 'The reviewed rows differ from the workbook. Check the worksheet and column settings.']);
        }
        foreach ($rows as $row) {
            $decision = $reviews[$row->row_number];
            $product = $decision['decision'] === 'existing' ? $products->get($decision['product_id']) : null;
            if ($decision['decision'] === 'existing') {
                if ($product === null || $product->trashed()) {
                    $this->invalid($row, 'The matched product is no longer active. Review this match.');
                }
                if (Money::centavos($decision['reviewed_catalog_price']) !== Money::centavos($product->price)) {
                    $this->invalid($row, 'The current selling price changed. Review the price decision again.');
                }
            }
            if (isset($decision['category_id']) && ! $categories->has($decision['category_id'])) {
                $this->invalid($row, 'The selected category is no longer available.');
            }
            $this->applyReview($row, $decision, $product);
        }
        $batch->setRelation('rows', $rows);
    }

    private function protectStagedWorkbook(string $id, ?string $path): void
    {
        if ($path !== null && $this->storage->usesSupabase()) {
            $this->lockWorkbook($id);
            abort_unless($this->storage->disk()->fileExists($path), 503, 'The workbook could not be found in storage. Upload it again.');
        }
    }

    private function lockWorkbook(string $id): void
    {
        if (DB::getDriverName() === 'pgsql') {
            // Keep the final existence check and commit exclusive with orphan deletion for this file.
            DB::selectOne('select pg_advisory_xact_lock(hashtextextended(?, 0))', ['pos-import-workbook:'.strtolower($id)]);
        }
    }

    private function commitTransaction(callable $callback): InventoryImport
    {
        try {
            return DB::transaction($callback, 5);
        } catch (UniqueConstraintViolationException $exception) {
            if (str_contains($exception->getMessage(), 'approved_source')) {
                abort(409, 'This exact workbook and worksheet were already approved.');
            }
            throw ValidationException::withMessages(['rows' => 'The catalog changed during stock-in. Review the product matches and try again.']);
        } catch (QueryException $exception) {
            if ((string) $exception->getCode() === '42703') {
                report($exception);
                abort(503, 'The inventory valuation migration must be applied before confirming stock-in. Your draft is still saved.');
            }
            throw $exception;
        }
    }

    private function requireUnusedSource(string $source): void
    {
        abort_if(InventoryImport::where('approved_source', $source)->exists(), 409, 'This exact workbook and worksheet were already approved.');
    }

    private function commitReviewed(InventoryImport $batch, User $owner, string $source, bool $acknowledged, array $workbookCosts): InventoryImport
    {
        $rows = $batch->getRelation('rows');
        $summary = $this->summarize($rows);
        if ($summary['pending_rows'] || ! $summary['units']) {
            throw ValidationException::withMessages(['rows' => 'Resolve every pending row and include at least one valid purchase line.']);
        }
        $createdCategories = $this->resolveWorkbookCategories($rows);
        $categories = Category::whereKey($rows->where('decision', 'new')->pluck('category_id')->filter()->unique()->values()->all())
            ->orderBy('id')->lockForUpdate()->pluck('id')->all();
        $products = $this->catalog();
        $locked = Product::withTrashed()->whereKey($rows->where('decision', 'existing')->pluck('product_id')->filter()->unique()->values()->all())
            ->orderBy('id')->lockForUpdate()->get()->keyBy('id');
        $categoryReviews = $this->categoryReviews($rows, $locked);
        $summary['warning_rows'] = $rows->filter(fn (InventoryImportRow $row) => $this->hasWarnings($row, $categoryReviews[$row->row_number]))->count();
        if ($summary['warning_rows'] && ! $acknowledged) {
            throw ValidationException::withMessages(['warnings' => 'Review and acknowledge the workbook warnings before approving.']);
        }
        $plans = $this->validateApproval($rows->where('decision', '!=', 'skip'), $products, $locked, $categories, $workbookCosts);
        $batch = $this->persistDraft($batch, $source);
        $entries = $this->apply($batch, $owner, $rows->sortBy('row_number'), $locked, $plans, $categoryReviews, $workbookCosts);
        $changedPrices = collect($entries)->filter(fn (array $entry) => $entry['previous_price'] !== null
            && $entry['previous_price'] !== $entry['selling_price'])->pluck('product_id')->unique()->count();
        $batch->update([
            'status' => 'approved', 'confirmed_at' => now(),
            'result' => [
                ...array_diff_key($summary, ['product_quantities' => true, 'new_quantities' => true]),
                'prices_adjusted' => $changedPrices, 'processed_rows' => count($entries), 'failed_rows' => 0,
                'new_categories' => $createdCategories,
                'warnings_reviewed' => $summary['warning_rows'],
                'reference' => $batch->id, 'entries' => $entries,
            ],
        ]);

        return $batch;
    }

    private function persistDraft(InventoryImport $draft, string $source): InventoryImport
    {
        $batch = InventoryImport::lockForUpdate()->find($draft->id) ?? new InventoryImport;
        abort_if($batch->confirmed_at !== null, 409, 'This workbook was already confirmed.');
        if ($batch->exists) {
            $batch->rows()->delete();
        }
        $batch->forceFill([...$draft->getAttributes(), 'approved_source' => $source])->save();

        return $batch;
    }

    private function insertImportRows(InventoryImport $batch, Collection $rows): void
    {
        $defaults = array_fill_keys((new InventoryImportRow)->getFillable(), null);
        $now = now();
        foreach ($rows->chunk(32) as $chunk) {
            InventoryImportRow::insert($chunk->map(fn (InventoryImportRow $row) => [
                ...array_replace($defaults, array_intersect_key($row->getAttributes(), $defaults)),
                'inventory_import_id' => $batch->id,
                'created_at' => $now,
                'updated_at' => $now,
            ])->all());
        }
    }

    public function cancel(User $owner, string $id): void
    {
        $this->drafts->lock($id, function () use ($owner, $id): void {
            $batch = $this->draft($owner, $id);
            $batch->status = 'cancelled';
            $this->drafts->save($batch);
            if ($batch->file_path !== null && $this->discardUncommittedWorkbook($id, $batch->file_path)) {
                $batch->file_path = null;
                try {
                    $this->drafts->save($batch);
                } catch (Throwable $exception) {
                    report($exception);
                }
            }
        });
    }

    public function previewPrune(): array
    {
        $files = [];
        $disk = $this->storage->disk();
        $addFile = function (string $path, string $reason) use ($disk, &$files): void {
            if (! isset($files[$path]) && $disk->fileExists($path)) {
                $files[$path] = $reason;
            }
        };

        foreach ($this->expiredDrafts() as $draft) {
            if ($draft->file_path !== null && ! $this->isCommittedWorkbook($draft->file_path)) {
                $addFile($draft->file_path, 'Workbook for an expired draft');
            }
            foreach ($this->drafts->metadataPaths($draft->id) as $path) {
                $addFile($path, 'Metadata for an expired draft');
            }
        }

        foreach ($this->expirableWorkbookRecords()->lazyById(50) as $batch) {
            if ($this->canRemoveStoredWorkbook($batch) && ! $this->isCommittedWorkbook($batch->file_path)) {
                $reason = $batch->status === 'cancelled' ? 'Workbook for a cancelled import' : 'Workbook for an expired import';
                $addFile($batch->file_path, $reason);
            }
        }

        $cutoff = now()->subDay()->getTimestamp();
        foreach ($this->orphanCandidates($cutoff, preview: true) as [$id, $path]) {
            if (! isset($files[$path]) && $this->isOrphanedWorkbook($id, $path, $cutoff, preview: true)) {
                $files[$path] = 'At least 24 hours old and unreferenced by any retained import or draft';
            }
        }

        ksort($files);

        return ['files' => $files, 'expired_previews' => $this->expiredPreviewRecords()->count()];
    }

    public function prune(): array
    {
        $failed = [];
        foreach ($this->expiredDrafts() as $draft) {
            $failure = $this->drafts->lock($draft->id, function () use ($draft): ?string {
                $batch = $this->drafts->find($draft->id);
                if (! $batch?->expires_at->isPast()) {
                    return null;
                }
                if ($batch->file_path !== null && ! $this->discardUncommittedWorkbook($batch->id, $batch->file_path)) {
                    return $batch->file_path;
                }

                return $this->drafts->forget($batch->id);
            });
            if ($failure !== null) {
                $failed[] = $failure;
            }
        }

        $this->expiredPreviewRecords()->delete();
        foreach ($this->expirableWorkbookRecords()->lazyById(50) as $item) {
            $batch = DB::transaction(function () use ($item): ?InventoryImport {
                $batch = InventoryImport::lockForUpdate()->find($item->id);
                if ($batch === null || ! $this->canRemoveStoredWorkbook($batch)) {
                    return null;
                }
                if (in_array($batch->status, ['uploaded', 'review'], true)) {
                    $batch->update(['status' => 'expired']);
                }

                return $batch;
            }, 5);
            if ($batch === null) {
                continue;
            }
            if ($this->discardUncommittedWorkbook($batch->id, $batch->file_path)) {
                InventoryImport::whereKey($batch->id)->whereNull('confirmed_at')
                    ->where('file_path', $batch->file_path)->update(['file_path' => null]);
            } else {
                $failed[] = $batch->file_path;
            }
        }

        $cutoff = now()->subDay()->getTimestamp();
        foreach ($this->orphanCandidates($cutoff) as [$id, $path]) {
            $removed = $this->drafts->lock($id, function () use ($id, $path, $cutoff): bool {
                return DB::transaction(function () use ($id, $path, $cutoff): bool {
                    $this->lockWorkbook($id);

                    return ! $this->isOrphanedWorkbook($id, $path, $cutoff) || $this->storage->tryDelete($path);
                });
            });
            if (! $removed) {
                $failed[] = $path;
            }
        }

        return array_values(array_unique($failed));
    }

    private function expiredDrafts(): iterable
    {
        foreach ($this->drafts->all() as $draft) {
            if ($draft->expires_at->isPast()) {
                yield $draft;
            }
        }
    }

    private function expiredPreviewRecords(): Builder
    {
        return InventoryImport::whereNull('status')->whereNull('confirmed_at')->where('expires_at', '<=', now());
    }

    private function expirableWorkbookRecords(): Builder
    {
        return InventoryImport::whereNull('confirmed_at')->whereNotNull('file_path')->where(function (Builder $query): void {
            $query->whereIn('status', ['cancelled', 'expired'])->orWhere('expires_at', '<=', now());
        });
    }

    private function canRemoveStoredWorkbook(InventoryImport $batch): bool
    {
        return $batch->confirmed_at === null && $batch->file_path !== null
            && (in_array($batch->status, ['cancelled', 'expired'], true)
                || (in_array($batch->status, ['uploaded', 'review'], true) && $batch->expires_at->isPast()));
    }

    private function isCommittedWorkbook(string $path): bool
    {
        return InventoryImport::whereNotNull('confirmed_at')->where('file_path', $path)->exists();
    }

    private function discardUncommittedWorkbook(string $id, string $path): bool
    {
        return DB::transaction(function () use ($id, $path): bool {
            $this->lockWorkbook($id);

            return $this->isCommittedWorkbook($path) || $this->storage->tryDelete($path);
        });
    }

    private function orphanCandidates(int $cutoff, bool $preview = false): iterable
    {
        foreach ($this->storage->oldWorkbookPaths($cutoff) as $path) {
            if (! preg_match('/^([a-f0-9-]{36})(?:-[a-f0-9]{64})?\.xlsx$/D', basename($path), $matches) || ! Str::isUuid($matches[1])) {
                continue;
            }
            $id = $matches[1];
            if (! $this->retainedWorkbookReferences($path, $preview)->whereKey($id)->exists()) {
                yield [$id, $path];
            }
        }
    }

    private function retainedWorkbookReferences(string $path, bool $preview): Builder
    {
        $references = InventoryImport::where('file_path', $path);
        if ($preview) {
            $references->whereNotIn('id', $this->expiredPreviewRecords()->select('id'));
        }

        return $references;
    }

    private function isOrphanedWorkbook(string $id, string $path, int $cutoff, bool $preview = false): bool
    {
        if ($this->retainedWorkbookReferences($path, $preview)->exists() || $this->drafts->find($id)?->file_path === $path) {
            return false;
        }
        $disk = $this->storage->disk();

        return $disk->fileExists($path) && $disk->lastModified($path) <= $cutoff;
    }

    private function validateApproval(Collection $rows, Collection $catalog, Collection $locked, array $categories, array $workbookCosts): array
    {
        $namesTaken = $catalog->groupBy(fn (Product $product) => Product::nameKey($product->name));
        $new = $catalogUpdates = $stock = $finalPrices = [];
        foreach ($rows as $row) {
            if ($row->errors !== [] || ! $row->quantity || $row->quantity < 1) {
                $this->invalid($row, 'The source quantity is invalid.');
            }
            if ($row->approved_selling_price !== null && Money::centavos($row->approved_selling_price) <= 0) {
                $this->invalid($row, 'Enter a positive selling price.');
            }
            $cost = $this->costFields($row, $workbookCosts);
            $purchaseCost = $this->purchaseCost($row, $cost);
            if ($row->decision === 'existing') {
                $product = $locked[$row->product_id] ?? null;
                if ($product === null || $product->trashed()) {
                    $this->invalid($row, 'The matched product is no longer active. Review this match.');
                }
                if ($this->needsPriceReview($row, $product)) {
                    $this->invalid($row, 'Choose to keep the current price or approve a new price before confirming.');
                }
                $name = Product::nameKey($row->uploaded_name ?? '');
                if ($row->matching_method !== 'manual'
                    && ($name === '' || ($namesTaken[$name] ?? collect())->count() !== 1 || Product::nameKey($product->name) !== $name)) {
                    $this->invalid($row, 'The automatic match changed. Select the correct product manually.');
                }
                $key = 'existing:'.$product->id;
                $price = $row->approved_selling_price ?? $product->price;
                if (isset($finalPrices[$key]) && $finalPrices[$key] !== $price) {
                    $this->invalid($row, 'Rows for the same product must agree on the final selling price.');
                }
                $finalPrices[$key] = $price;
                if ($row->approved_selling_price !== null) {
                    $update = ['price' => $row->approved_selling_price, 'markup_percentage' => $row->source['catalog_markup'] ?? $product->markup_percentage];
                    if (isset($catalogUpdates[$key]) && $catalogUpdates[$key] !== $update) {
                        $this->invalid($row, 'Rows for the same product specify conflicting catalog prices or markups.');
                    }
                    $catalogUpdates[$key] = $update;
                }
                $stock[$key] ??= $product->stock;
            } else {
                $name = Product::nameKey($row->new_name ?? '');
                if ($row->decision !== 'new' || $name === '' || $row->approved_selling_price === null
                    || ! in_array($row->category_id, $categories, true)) {
                    $this->invalid($row, 'Provide a product name, category and price.');
                }
                if (isset($namesTaken[$name])) {
                    $this->invalid($row, 'The product name already exists in the catalog. Select that product or correct the name.');
                }
                $key = $this->newKey($row);
                $markup = $row->markup_percentage ?? '0.0000';
                if (isset($new[$key]) && ($new[$key]['category_id'] !== $row->category_id ||
                    $new[$key]['price'] !== $row->approved_selling_price || $new[$key]['markup_percentage'] !== $markup)) {
                    $this->invalid($row, 'Rows for the same new product must agree on category, price and markup.');
                }
                $new[$key] ??= [
                    'name' => $row->new_name, 'sku' => Product::internalSku(), 'category_id' => $row->category_id,
                    'cost' => (string) Money::lineTotal($purchaseCost, 1), 'price' => $row->approved_selling_price,
                    'markup_percentage' => $markup,
                ];
                $stock[$key] ??= 0;
            }
            $stock[$key] += $row->quantity;
            if ($stock[$key] > Inventory::MAX_STOCK) {
                $this->invalid($row, 'The resulting stock exceeds the allowed range.');
            }
        }

        return ['new' => $new, 'catalog_updates' => $catalogUpdates];
    }

    private function apply(InventoryImport $batch, User $owner, Collection $rows, Collection $products, array $plans, array $categoryReviews, array $workbookCosts): array
    {
        $now = now();
        $newProducts = new Collection;
        if ($plans['new'] !== []) {
            foreach (array_chunk($plans['new'], 75) as $chunk) {
                Product::insert(array_map(fn (array $product) => [...$product, 'created_at' => $now, 'updated_at' => $now], $chunk));
            }
            $newProducts = Product::whereIn('sku', array_column($plans['new'], 'sku'))->get()->keyBy('sku');
        }

        $entries = $movements = $updatedProducts = [];
        foreach ($rows->where('decision', '!=', 'skip') as $row) {
            $isNew = $row->decision === 'new';
            $key = $isNew ? $this->newKey($row) : 'existing:'.$row->product_id;
            $product = $isNew ? $newProducts[$plans['new'][$key]['sku']] : $products[$row->product_id];
            $opening = $isNew && ! isset($updatedProducts[$product->id]);
            $previous = $product->stock;
            $oldPrice = $product->price;
            if (isset($plans['catalog_updates'][$key])) {
                $product->fill($plans['catalog_updates'][$key]);
            }
            $stockIn = $this->stockInFields($row);
            $cost = $this->costFields($row, $workbookCosts);
            $purchaseCost = $this->purchaseCost($row, $cost);
            $amounts = $this->purchaseAmounts($row->quantity, $purchaseCost, $product->price);
            $movements[$row->row_number] = $this->inventory->prepareMovement($product, $row->quantity, $opening ? 'opening' : 'restock', $owner,
                remarks: 'Inventory import '.$batch->id, importId: $batch->id, purchaseUnitCost: $purchaseCost);
            $updatedProducts[$product->id] = $product;
            $entry = [
                'row' => $row->row_number, 'product_id' => $product->id, 'name' => $product->name,
                'quantity' => $row->quantity, 'previous_stock' => $previous, 'resulting_stock' => $product->stock,
                'previous_price' => $opening ? null : $oldPrice, 'selling_price' => $product->price,
                'date' => $stockIn['date'], 'supplier' => $stockIn['supplier'], 'category' => $categoryReviews[$row->row_number]['selected']['name'] ?? null,
                'item_cost' => $purchaseCost,
                'source_item_cost' => $cost['source_item_cost'],
                'markup_percentage' => $row->markup_percentage ?? $product->markup_percentage,
                ...$amounts,
            ];
            $entries[] = $entry;
            $row->fill([
                'product_id' => $product->id, 'processed_at' => $now,
                'original_unit_cost' => $cost['original_item_cost'],
                'purchase_total_cost' => $amounts['total_cost'],
                'purchase_selling_price' => $product->price,
                'source' => [...$row->source, 'applied' => [...$entry, 'category_review' => $categoryReviews[$row->row_number]]],
            ]);
        }

        foreach (array_chunk($updatedProducts, 75) as $chunk) {
            Product::upsert(
                array_map(fn (Product $product) => $product->only([
                    'id', 'sku', 'name', 'category_id', 'cost', 'price', 'stock', 'inventory_value', 'markup_percentage',
                ]), $chunk),
                ['id'], ['cost', 'price', 'stock', 'inventory_value', 'markup_percentage'],
            );
        }
        $this->insertImportRows($batch, $rows);
        $rowIds = $batch->rows()->pluck('id', 'row_number');
        foreach ($movements as $rowNumber => $movement) {
            $movements[$rowNumber] = [...$movement, 'inventory_import_row_id' => $rowIds[$rowNumber]];
        }
        foreach (array_chunk($movements, 75) as $chunk) {
            DB::table('stock_movements')->insert($chunk);
        }

        return $entries;
    }

    private function categoryReviews(Collection $rows, Collection $products, bool $confirmed = false): array
    {
        $categories = $confirmed || $rows->isEmpty() ? new Collection : Category::all(['id', 'name'])->keyBy('id');
        $byName = $categories->groupBy(fn (Category $category) => SupplierWorkbook::identity($category->name));

        return $rows->mapWithKeys(function (InventoryImportRow $row) use ($products, $categories, $byName, $confirmed): array {
            $source = trim($row->source['cells']['category']['value'] ?? '');
            $categoryName = $this->workbookCategoryName($row);
            $review = ['source' => $source === '' ? null : $source, 'suggestion' => null, 'selected' => null, 'create_name' => null,
                'category_name' => $categoryName, 'use_source' => $row->source['use_workbook_category'] ?? false, 'warning' => null];
            if ($confirmed) {
                return [$row->row_number => $row->source['applied']['category_review'] ?? $review];
            }

            $product = $products[$row->product_id] ?? null;
            $selected = match ($row->decision) {
                'existing' => $categories[$row->category_id ?? $product?->category_id] ?? null,
                'new' => $categories[$row->category_id] ?? null,
                default => null,
            };
            $review['selected'] = $selected?->only(['id', 'name']);
            $name = SupplierWorkbook::identity($source);
            if ($name !== '' && $row->decision === 'existing' && $product && $row->category_id === null
                && $name !== SupplierWorkbook::identity($selected?->name ?? '')) {
                $review['warning'] = 'The workbook category differs from the catalog. Restock keeps the current category ('.($selected?->name ?? 'Uncategorized').').';
            }
            if ($categoryName !== null && in_array($row->decision, ['pending', 'new', 'existing'], true)) {
                $matches = $byName[SupplierWorkbook::identity($categoryName)] ?? collect();
                if ($matches->count() === 1) {
                    $review['suggestion'] = $matches->first()->only(['id', 'name']);
                } elseif ($matches->isEmpty()) {
                    $review['create_name'] = $categoryName;
                }
            }

            return [$row->row_number => $review];
        })->all();
    }

    private function workbookCategoryName(InventoryImportRow $row): ?string
    {
        $reviewed = $row->source['reviewed'] ?? [];
        $name = Str::squish((array_key_exists('category_name', $reviewed) ? $reviewed['category_name'] : ($row->source['cells']['category']['value'] ?? null)) ?? '');

        return $name !== '' && mb_strlen($name) <= 100 && mb_strtolower($name) !== 'all' ? $name : null;
    }

    private function stockInFields(InventoryImportRow $row): array
    {
        $cells = $row->source['cells'] ?? [];
        $reviewed = $row->source['reviewed'] ?? [];

        return [
            'date' => array_key_exists('date', $reviewed) ? $reviewed['date'] : ($row->source['date'] ?? SupplierWorkbook::dateValue($cells['date'] ?? null)),
            'source_date' => array_key_exists('date', $reviewed) ? null : ($cells['date']['value'] ?? null),
            'supplier' => array_key_exists('supplier', $reviewed) ? $reviewed['supplier'] : ($cells['supplier']['value'] ?? null),
            'price' => $row->source['financial']['original_selling_price'] ?? null,
            'source_price' => $cells['selling_price']['value'] ?? null,
        ];
    }

    private function savedWorkbookCosts(InventoryImport $batch): array
    {
        $setup = $batch->preview ?? [];
        if ($batch->status !== 'review' || $batch->expires_at->isPast() || ($setup['item_cost_checked'] ?? false)
            || isset($setup['mapping']['unit_cost']) || ! isset($setup['sheet_index'], $setup['header'])
            || ! $batch->file_path || ! $this->storage->disk()->fileExists($batch->file_path)) {
            return [];
        }

        try {
            return $this->storage->readWorkbook($batch->file_path, fn (string $path) => $this->workbook->itemCosts($path, $setup['sheet_index'], $setup['header']));
        } catch (ValidationException) {
            return [];
        }
    }

    private function costFields(InventoryImportRow $row, array $workbookCosts): array
    {
        $copied = $workbookCosts[$row->row_number] ?? [];
        $original = $row->source['financial']['original_unit_cost'] ?? $row->original_unit_cost ?? $copied['amount'] ?? null;
        if ($original !== null) {
            $original = (string) BigDecimal::of((string) $original)->strippedOfTrailingZeros();
        }

        return [
            'original_item_cost' => $original,
            'source_item_cost' => $row->source['cells']['unit_cost']['value']
                ?? $copied['value'] ?? $row->source['applied']['source_item_cost'] ?? null,
            'item_cost' => $row->source['applied']['item_cost'] ?? $row->approved_cost ?? $original,
        ];
    }

    private function purchaseCost(InventoryImportRow $row, array $cost): string
    {
        $itemCost = $cost['item_cost'];
        if ($itemCost === null || BigDecimal::of($itemCost)->isNegative() || BigDecimal::of($itemCost)->isGreaterThan('9999999999.99')) {
            $this->invalid($row, 'Enter a valid item cost before importing this row.');
        }

        return $itemCost;
    }

    private function purchaseAmounts(?int $quantity, ?string $cost, ?string $price): array
    {
        $totalCost = $quantity !== null && $cost !== null ? Money::lineTotal($cost, $quantity) : null;
        $sales = $quantity !== null && $price !== null ? Money::lineTotal($price, $quantity) : null;

        return [
            'total_cost' => $totalCost === null ? null : (string) $totalCost,
            'expected_sales' => $sales === null ? null : (string) $sales,
            'expected_gross_profit' => $sales === null || $totalCost === null ? null : (string) $sales->minus($totalCost),
        ];
    }

    private function resolveWorkbookCategories(Collection $rows): int
    {
        $requested = $rows->where('decision', 'new')->filter(fn (InventoryImportRow $row) => $row->source['use_workbook_category'] ?? false);
        if ($requested->isEmpty()) {
            return 0;
        }

        $byName = Category::all(['id', 'name'])->groupBy(fn (Category $category) => SupplierWorkbook::identity($category->name));
        $created = 0;
        foreach ($requested as $row) {
            $name = $this->workbookCategoryName($row);
            if ($name === null) {
                $this->invalid($row, 'The workbook category is invalid. Select a catalog category.');
            }
            $key = SupplierWorkbook::identity($name);
            $matches = $byName->get($key, collect());
            if ($matches->count() > 1) {
                $this->invalid($row, 'Several catalog categories match the workbook name. Select the category for this product.');
            }
            $category = $matches->first();
            if ($category === null) {
                $category = Category::firstOrCreate(['name' => $name]);
                $created += (int) $category->wasRecentlyCreated;
                $byName->put($key, collect([$category]));
            }
            $row->category_id = $category->id;
        }

        return $created;
    }

    private function hasWarnings(InventoryImportRow $row, array $category = []): bool
    {
        return $row->errors !== [] || $row->warnings !== [] || ($category['warning'] ?? null) !== null;
    }

    private function summarize(Collection $rows, array $categoryReviews = []): array
    {
        $existing = $rows->where('decision', 'existing');
        $new = $rows->where('decision', 'new');
        $productQuantities = $existing->groupBy('product_id')->map->sum('quantity')->all();
        $newQuantities = $new->groupBy(fn (InventoryImportRow $row) => $this->newKey($row))->map->sum('quantity')->all();
        $pending = $rows->where('decision', 'pending');

        return [
            'total_rows' => $rows->count(), 'existing_products' => count($productQuantities), 'new_products' => count($newQuantities),
            'new_categories' => collect($categoryReviews)->filter(fn (array $review) => ($review['use_source'] ?? false) && ($review['create_name'] ?? null) !== null)
                ->map(fn (array $review) => SupplierWorkbook::identity($review['create_name']))->unique()->count(),
            'pending_rows' => $pending->count(), 'skipped_rows' => $rows->where('decision', 'skip')->count(),
            'unmatched_without_price' => $pending->filter(fn (InventoryImportRow $row) => $row->errors === [] && $row->candidates === []
                && in_array($row->source['financial']['original_selling_price'] ?? null, [null, '0'], true))->count(),
            'invalid_rows' => $rows->filter(fn (InventoryImportRow $row) => $row->errors !== [])->count(),
            'duplicate_rows' => $rows->filter(fn (InventoryImportRow $row) => $row->source['duplicate'] ?? false)->count(),
            'warning_rows' => $rows->filter(fn (InventoryImportRow $row) => $this->hasWarnings($row, $categoryReviews[$row->row_number] ?? []))->count(),
            'prices_adjusted' => $existing->whereNotNull('approved_selling_price')->unique('product_id')->count(),
            'units' => array_sum($productQuantities) + array_sum($newQuantities), 'product_quantities' => $productQuantities, 'new_quantities' => $newQuantities,
        ];
    }

    private function newKey(InventoryImportRow $row): string
    {
        return 'new:'.Product::nameKey($row->new_name ?? '');
    }

    private function snapshot(Product $product): array
    {
        return [...$product->only(['id', 'name', 'stock', 'price']), 'archived' => $product->trashed()];
    }

    private function catalog(): Collection
    {
        return Product::withTrashed()->orderBy('id')->get(['id', 'name', 'stock', 'price', 'category_id', 'deleted_at']);
    }

    private function draft(User $owner, string $id, ?string $status = null): InventoryImport
    {
        $batch = $this->findBatch($id);
        abort_unless($batch->user_id === $owner->id && $batch->status !== null, 404);
        abort_if($batch->expires_at->isPast() && in_array($batch->status, ['uploaded', 'review'], true), 409, 'This import has expired. Upload the workbook again.');
        abort_unless($status === null ? in_array($batch->status, ['uploaded', 'review'], true) : $batch->status === $status,
            409, 'This import is no longer available for that action.');

        return $batch->loadMissing(['rows' => fn ($query) => $query->orderBy('row_number')]);
    }

    private function findBatch(string $id): InventoryImport
    {
        $stored = InventoryImport::find($id);
        if ($stored?->confirmed_at !== null) {
            return $stored;
        }

        return $this->drafts->find($id) ?? $stored ?? abort(404);
    }

    private function needsPriceReview(InventoryImportRow $row, ?Product $product): bool
    {
        return ! in_array($row->source['price_decision'] ?? null, ['keep', 'change'], true)
            || $product === null || $product->trashed()
            || ($row->source['reviewed_catalog_price'] ?? null) !== $product->price;
    }

    private function invalid(InventoryImportRow $row, string $message): never
    {
        throw ValidationException::withMessages(['rows' => "Row {$row->row_number}: {$message}"]);
    }
}
