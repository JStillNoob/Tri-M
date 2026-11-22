<?php

namespace App\Services;

use Brick\Math\BigDecimal;
use DateTimeImmutable;
use Illuminate\Validation\ValidationException;
use SimpleXMLElement;
use ZipArchive;

class SupplierWorkbook
{
    public const array HEADERS = [
        'name' => ['product', 'product name', 'item', 'item name', 'product description', 'item description', 'description', 'name'],
        'category' => ['category', 'product category'],
        'quantity' => ['quantity', 'qty', 'stock in', 'received', 'quantity received', 'qty received', 'units'],
        'unit_cost' => ['item cost', 'cost per item', 'unit cost', 'purchase cost', 'purchase price', 'cost'],
        'selling_price' => ['sell price', 'selling price', 'retail price', 'srp', 'price'],
        'supplier' => ['supplier', 'supplier name', 'vendor', 'vendor name'],
        'date' => ['date', 'purchase date', 'invoice date', 'stock in date', 'date received'],
    ];

    public function __construct(private InventorySpreadsheet $spreadsheet) {}

    public function inspect(string $path): array
    {
        return $this->read($path, function (ZipArchive $archive, array $sheets): array {
            $first = collect($sheets)->firstWhere('visible', true);
            if ($first === null) {
                $this->invalid('The workbook must contain a visible worksheet.');
            }

            return ['sheets' => $sheets, 'sheet' => $this->sample($first, $this->cells($archive, $first['path']))];
        });
    }

    public function worksheet(string $path, int $index): array
    {
        return $this->read($path, function (ZipArchive $archive, array $sheets) use ($index): array {
            $sheet = $this->select($sheets, $index);

            return $this->sample($sheet, $this->cells($archive, $sheet['path']));
        });
    }

    public function extract(string $path, int $index, int $header, array $mapping): array
    {
        return $this->read($path, function (ZipArchive $archive, array $sheets) use ($index, $header, $mapping): array {
            $sheet = $this->select($sheets, $index);
            $cells = $this->cells($archive, $sheet['path']);
            $columns = $this->columns($cells);
            if (! isset($cells[$header]) || array_diff($mapping, $columns) !== []) {
                $this->invalid('Choose a header row and columns present in the worksheet.', 'mapping');
            }

            return ['sheet' => $sheet['name'], 'rows' => $this->stockRows($cells, $header, $mapping, $sheet['date_1904'])];
        });
    }

    public function itemCosts(string $path, int $index, int $header): array
    {
        return $this->read($path, function (ZipArchive $archive, array $sheets) use ($index, $header): array {
            $sheet = $this->select($sheets, $index);
            $rows = $this->cells($archive, $sheet['path']);
            $column = $this->headerColumn($rows[$header] ?? [], self::HEADERS['unit_cost']);
            $costs = [];
            if ($column === null) {
                return $costs;
            }

            foreach ($rows as $number => $row) {
                if ($number > $header && isset($row[$column])) {
                    $costs[$number] = [
                        'amount' => $this->itemCost($row[$column]),
                        'value' => $row[$column]['value'] ?? null,
                    ];
                }
            }

            return $costs;
        });
    }

    private function read(string $path, callable $callback): array
    {
        $this->spreadsheet->validateArchive($path);
        $archive = new ZipArchive;
        if ($archive->open($path, ZipArchive::RDONLY) !== true) {
            $this->invalid('Upload a readable XLSX workbook.');
        }
        try {
            $book = $this->xml($archive, 'xl/workbook.xml');
            $rels = $this->xml($archive, 'xl/_rels/workbook.xml.rels');
            $targets = [];
            foreach ($rels->Relationship as $relationship) {
                if (! str_ends_with((string) $relationship['Type'], '/worksheet') || isset($relationship['TargetMode'])) {
                    continue;
                }
                $target = (string) $relationship['Target'];
                $target = str_starts_with($target, '/') ? substr($target, 1) : 'xl/'.$target;
                if (preg_match('~^xl/(?:[\w-]+/)*[\w.-]+\.xml$~D', $target)) {
                    $targets[(string) $relationship['Id']] = $target;
                }
            }
            $sheets = [];
            foreach ($book->sheets->sheet as $sheet) {
                $relation = $sheet->attributes('http://schemas.openxmlformats.org/officeDocument/2006/relationships');
                $path = $targets[(string) $relation['id']] ?? null;
                if ($path !== null) {
                    $sheets[] = [
                        'index' => count($sheets),
                        'name' => (string) $sheet['name'],
                        'visible' => in_array((string) $sheet['state'], ['', 'visible'], true),
                        'path' => $path,
                        'date_1904' => in_array((string) ($book->workbookPr['date1904'] ?? ''), ['1', 'true'], true),
                    ];
                }
            }
            $result = $callback($archive, $sheets);
            if (isset($result['sheets'])) {
                $result['sheets'] = array_map(fn (array $sheet) => array_diff_key($sheet, ['path' => true, 'date_1904' => true]), $result['sheets']);
            }

            return $result;
        } finally {
            $archive->close();
        }
    }

    private function xml(ZipArchive $archive, string $path): SimpleXMLElement
    {
        $xml = $archive->getFromName($path);
        if ($xml === false || stripos(str_replace("\0", '', $xml), '<!DOCTYPE') !== false || ! ($value = @simplexml_load_string($xml, SimpleXMLElement::class, LIBXML_NONET | LIBXML_COMPACT))) {
            $this->invalid('We could not read a worksheet. Open the workbook in Excel, save a new copy, and try again.');
        }

        return $value;
    }

    private function select(array $sheets, int $index): array
    {
        if (! ($sheets[$index]['visible'] ?? false)) {
            $this->invalid('Select an available visible worksheet.', 'sheet');
        }

        return $sheets[$index];
    }

    private function cells(ZipArchive $archive, string $path): array
    {
        $strings = [];
        if ($archive->locateName('xl/sharedStrings.xml') !== false) {
            foreach ($this->xml($archive, 'xl/sharedStrings.xml')->si as $item) {
                $strings[] = $this->richText($item);
            }
        }
        $rows = [];
        foreach ($this->xml($archive, $path)->sheetData->row as $row) {
            $number = (int) $row['r'];
            foreach ($row->c as $cell) {
                if (! isset($cell->v) && ! isset($cell->f) && ! isset($cell->is)) {
                    continue;
                }
                $ref = (string) $cell['r'];
                if (! preg_match('/^([A-Z]{1,2})([1-9][0-9]*)$/D', $ref, $address) || (int) $address[2] !== $number
                    || $number > 1001 || (strlen($address[1]) === 2 && strcmp($address[1], 'BL') > 0)) {
                    $this->invalid('Use at most 1,001 worksheet rows and columns A through BL.');
                }
                $type = (string) $cell['t'];
                $saved = isset($cell->v) ? (string) $cell->v : null;
                $value = match ($type) {
                    's' => $strings[(int) $saved] ?? null,
                    'inlineStr' => $this->richText($cell->is),
                    default => $saved,
                };
                if (mb_strlen((string) $value) > 2000) {
                    $this->invalid('A cell exceeds the 2,000 character limit.');
                }
                $rows[$number][$address[1]] = array_filter([
                    'cell' => $ref,
                    'value' => $value,
                    'type' => $type === '' ? 'n' : $type,
                ], fn ($value) => $value !== null);
            }
        }
        ksort($rows);

        return $rows;
    }

    private function richText(SimpleXMLElement $item): string
    {
        $value = (string) $item->t;
        foreach ($item->r as $run) {
            $value .= (string) $run->t;
        }

        return $value;
    }

    private function sample(array $sheet, array $rows): array
    {
        $best = [];
        $header = array_key_first($rows) ?? 1;
        foreach (array_slice($rows, 0, 25, true) as $number => $row) {
            $mapping = [];
            foreach (self::HEADERS as $field => $aliases) {
                $column = $this->headerColumn($row, $aliases);
                if ($column !== null) {
                    $mapping[$field] = $column;
                }
            }
            if ([isset($mapping['name'], $mapping['quantity']), count($mapping)] > [isset($best['name'], $best['quantity']), count($best)]) {
                $header = $number;
                $best = $mapping;
            }
        }

        return [
            'index' => $sheet['index'], 'name' => $sheet['name'], 'header' => $header,
            'mapping' => (object) $best, 'columns' => $this->columns($rows),
            'sample' => array_map(fn ($number, $row) => ['row' => $number, 'cells' => array_map(fn ($cell) => $cell['value'] ?? null, $row)], array_keys(array_slice($rows, 0, 12, true)), array_slice($rows, 0, 12)),
        ];
    }

    private function columns(array $rows): array
    {
        $columns = array_unique(array_merge([], ...array_map('array_keys', array_values($rows))));
        usort($columns, fn ($first, $second) => strlen($first) <=> strlen($second) ?: strcmp($first, $second));

        return $columns;
    }

    private function headerColumn(array $row, array $aliases): ?string
    {
        $matches = array_filter($row, function (array $cell) use ($aliases): bool {
            $heading = preg_replace('/\s*\(.*\)\s*$/u', '', $cell['value'] ?? '');
            $heading = self::identity(str_replace(['-', '_'], ' ', $heading));

            return in_array($heading, $aliases, true);
        });

        return count($matches) === 1 ? array_key_first($matches) : null;
    }

    private function stockRows(array $rows, int $header, array $mapping, bool $date1904): array
    {
        $records = $seen = [];
        foreach ($rows as $number => $row) {
            if ($number <= $header) {
                continue;
            }

            $cells = array_map(fn ($column) => $row[$column] ?? null, $mapping);
            $value = fn (string $field) => trim((string) ($cells[$field]['value'] ?? ''));
            $labels = array_map(fn (array $cell) => self::identity($cell['value'] ?? ''), $row);
            if ($value('quantity') === '' && collect($labels)->contains(fn ($label) => str_starts_with($label, 'month of ') || in_array($label, ['subtotal', 'sub total', 'total purchase', 'purchase total', 'grand total', 'total'], true))) {
                continue;
            }
            if (self::identity($value('name')) === self::identity((string) ($rows[$header][$mapping['name']]['value'] ?? ''))
                && self::identity($value('quantity')) === self::identity((string) ($rows[$header][$mapping['quantity']]['value'] ?? ''))) {
                continue;
            }
            if (! collect($cells)->contains(fn ($cell) => trim((string) ($cell['value'] ?? '')) !== '')) {
                continue;
            }

            $name = $value('name');
            $date = self::dateValue($cells['date'] ?? null, $date1904);
            $count = $this->number($cells['quantity'] ?? null);
            $price = $this->number($cells['selling_price'] ?? null, currency: true);
            $itemCost = $this->itemCost($cells['unit_cost'] ?? null);
            $quantity = $count !== null && $count->getScale() === 0 && $count->isGreaterThan(0)
                && $count->isLessThanOrEqualTo(Inventory::MAX_STOCK) ? $count->toInt() : null;
            $sellingPrice = $price !== null && $price->getScale() <= 2 && $price->isGreaterThan(0)
                && $price->isLessThanOrEqualTo('9999999999.99') ? (string) $price : null;
            $errors = [];
            if ($name === '' || mb_strlen($name) > 255) {
                $errors[] = 'Enter a product name of no more than 255 characters.';
            }
            if ($quantity === null) {
                $errors[] = 'Enter a positive whole quantity.';
            }
            if ($date === null && $value('date') !== '') {
                $errors[] = 'Check the date in this row.';
            }
            if (mb_strlen($value('supplier')) > 150) {
                $errors[] = 'Enter a supplier name of no more than 150 characters.';
            }
            if ($sellingPrice === null && $value('selling_price') !== '') {
                $errors[] = 'Enter a positive price in pesos and centavos, or clear the price to keep the existing price.';
            }
            if ($itemCost === null && $value('unit_cost') !== '') {
                $errors[] = 'Check the item cost in this row.';
            }
            if (self::isReceiptPlaceholder($name)) {
                $errors[] = 'This is a receipt placeholder. Skip this row.';
            }
            $fingerprint = serialize(array_map(fn ($cell) => self::identity($cell['value'] ?? ''), array_diff_key($cells, ['category' => true])));
            $seen[$fingerprint][] = $number;
            $records[$number] = [
                'row_number' => $number,
                'uploaded_name' => $name === '' ? null : mb_substr($name, 0, 255),
                'quantity' => $quantity,
                'original_unit_cost' => $itemCost,
                'original_selling_price' => $sellingPrice,
                'source' => ['cells' => $cells, 'date' => $date, 'financial' => [
                    'original_unit_cost' => $itemCost,
                    'original_selling_price' => $sellingPrice,
                ]],
                'errors' => $errors,
                'warnings' => [],
            ];
        }

        foreach ($seen as $duplicates) {
            if (count($duplicates) > 1) {
                foreach ($duplicates as $number) {
                    $records[$number]['warnings'][] = 'This row repeats the values on row(s) '.implode(', ', array_diff($duplicates, [$number])).'. Check the workbook before including it.';
                    $records[$number]['source']['duplicate'] = true;
                }
            }
        }

        if ($records === []) {
            $this->invalid('The worksheet has no stock-in rows below the headers.', 'mapping');
        }

        return array_values($records);
    }

    private function itemCost(?array $cell): ?string
    {
        $cost = $this->number($cell, currency: true);

        return $cost !== null && $cost->getScale() <= 16 && $cost->isLessThanOrEqualTo('9999999999.99') ? (string) $cost : null;
    }

    private function number(?array $cell, bool $currency = false): ?BigDecimal
    {
        if ($cell === null || in_array($cell['type'], ['b', 'e', 'd'], true)) {
            return null;
        }
        $value = trim($cell['value'] ?? '');
        if ($currency) {
            $value = trim(preg_replace('/^(?:PHP|₱)\s*/iu', '', $value));
        }
        if (! preg_match('/^\+?(?:\d{1,20}|\d{1,3}(?:,\d{3}){1,5})(?:\.\d{1,20})?(?:[Ee][+-]?\d{1,2})?$/D', $value)) {
            return null;
        }

        return BigDecimal::of(str_replace(',', '', $value))->strippedOfTrailingZeros();
    }

    public static function dateValue(?array $cell, bool $date1904 = false): ?string
    {
        $value = trim($cell['value'] ?? '');
        if (($cell['type'] ?? null) === 'n' && preg_match('/^\d{1,7}(?:\.\d+)?$/D', $value)) {
            $days = (int) $value;
            if ($days <= ($date1904 ? 2957003 : 2958465) && ($date1904 || ($days > 0 && $days !== 60))) {
                $base = $date1904 ? '1904-01-01' : ($days < 60 ? '1899-12-31' : '1899-12-30');

                return (new DateTimeImmutable($base))->modify("+{$days} days")->format('Y-m-d');
            }
        }
        foreach (['Y-m-d', 'j-M-y', 'j-M-Y', 'Y/m/d'] as $format) {
            $date = DateTimeImmutable::createFromFormat('!'.$format, $value);
            if ($date !== false && DateTimeImmutable::getLastErrors() === false) {
                return $date->format('Y-m-d');
            }
        }

        return null;
    }

    public static function isReceiptPlaceholder(string $name): bool
    {
        return preg_match('/^\[.*(?:missing|cut.off|unknown|unreadable).*\]$/iu', $name) === 1;
    }

    public static function identity(string $text): string
    {
        return mb_strtolower(trim(preg_replace('/\s+/u', ' ', $text)));
    }

    private function invalid(string $message, string $field = 'file'): never
    {
        throw ValidationException::withMessages([$field => $message]);
    }
}
