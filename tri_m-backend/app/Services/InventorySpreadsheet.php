<?php

namespace App\Services;

use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;
use OpenSpout\Common\Entity\Cell\FormulaCell;
use OpenSpout\Reader\XLSX\Options;
use OpenSpout\Reader\XLSX\Reader;
use Throwable;
use ZipArchive;

class InventorySpreadsheet
{
    private const array COLUMNS = ['sku', 'name', 'category', 'cost', 'price', 'qty'];

    private const int MAX_ROWS = 1000;

    public function parse(string $path): array
    {
        $this->validateArchive($path);
        $options = new Options;
        $options->SHOULD_PRESERVE_EMPTY_ROWS = true;
        $reader = new Reader($options);
        $rows = [];
        $errors = [];
        $total = 0;
        $columns = null;
        $open = false;

        try {
            $reader->open($path);
            $open = true;

            foreach ($reader->getSheetIterator() as $sheet) {
                foreach ($sheet->getRowIterator() as $number => $row) {
                    if ($number > self::MAX_ROWS + 1) {
                        $this->invalid('Use at most '.self::MAX_ROWS.' spreadsheet rows below the header.');
                    }

                    $values = array_map(fn ($value) => is_string($value) ? trim($value) : $value, $row->toArray());

                    if ($number === 1) {
                        $columns = $this->mapColumns($values);

                        continue;
                    }

                    if (array_filter($values, fn ($value) => $value !== '' && $value !== null) === []) {
                        continue;
                    }

                    $total++;
                    $data = [];

                    foreach ($columns as $field => $index) {
                        $data[$field] = ($row->getCells()[$index] ?? null) instanceof FormulaCell
                            ? null
                            : ($values[$index] ?? null);
                    }

                    $validator = Validator::make($data, [
                        'sku' => ['required', 'string', 'max:100'],
                        'name' => ['required', 'string', 'max:255'],
                        'category' => ['required', 'string', 'max:100'],
                        'cost' => ['required', ...Money::RULES],
                        'price' => ['required', ...Money::RULES],
                        'qty' => ['required', 'integer', 'min:1', 'max:'.Inventory::MAX_STOCK],
                    ]);

                    if ($validator->fails()) {
                        $errors[] = ['row' => $number, 'messages' => $validator->errors()->all()];

                        continue;
                    }

                    $rows[] = [
                        ...$data,
                        'cost' => Money::decimal(Money::centavos($data['cost'])),
                        'price' => Money::decimal(Money::centavos($data['price'])),
                        'qty' => (int) $data['qty'],
                        'row' => $number,
                    ];
                }

                break;
            }
        } catch (ValidationException $exception) {
            throw $exception;
        } catch (Throwable) {
            $this->invalid('We could not open this Excel workbook. Check that it opens in Excel, then try uploading it again.');
        } finally {
            if ($open) {
                $reader->close();
            }
        }

        if ($total === 0) {
            $this->invalid('Add at least one data row under the headers on the first sheet.');
        }

        return ['total_rows' => $total, 'rows' => $rows, 'errors' => $errors];
    }

    private function mapColumns(array $values): array
    {
        $columns = [];

        foreach ($values as $index => $value) {
            $field = is_string($value) ? strtolower(ltrim($value, "\xEF\xBB\xBF")) : '';

            if (in_array($field, self::COLUMNS, true)) {
                if (isset($columns[$field])) {
                    $this->invalid("The header {$field} appears more than once.");
                }

                $columns[$field] = $index;
            }
        }

        if (count($columns) !== count(self::COLUMNS)) {
            $this->invalid('The first sheet must have these columns: sku, name, category, cost, price, qty.');
        }

        return $columns;
    }

    public function validateArchive(string $path): void
    {
        $archive = new ZipArchive;

        if ($archive->open($path, ZipArchive::RDONLY) !== true) {
            $this->invalid('Upload a valid XLSX workbook.');
        }

        try {
            if ($archive->numFiles > 150 || $archive->locateName('xl/workbook.xml') === false || $archive->locateName('[Content_Types].xml') === false) {
                $this->invalid('We could not open this workbook. Save the sheet you need as a new .xlsx file and try again.');
            }

            $expandedSize = 0;

            for ($index = 0; $index < $archive->numFiles; $index++) {
                $entry = $archive->statIndex($index);

                if ($entry === false || $entry['encryption_method'] !== 0) {
                    $this->invalid('We could not read part of this workbook. Open it in Excel and save an unprotected copy.');
                }

                $expandedSize += $entry['size'];

                if ($expandedSize > 20 * 1024 * 1024) {
                    $this->invalid('This workbook is too large to open. Split the sheets into smaller Excel files and try again.');
                }
            }
        } finally {
            $archive->close();
        }
    }

    private function invalid(string $message): never
    {
        throw ValidationException::withMessages(['file' => $message]);
    }
}
