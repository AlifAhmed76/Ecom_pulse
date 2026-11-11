<?php

namespace App\Services\Import;

use DateTimeInterface;
use DOMDocument;
use DOMElement;
use Generator;
use InvalidArgumentException;
use OpenSpout\Reader\XLSX\Options as XlsxOptions;
use OpenSpout\Reader\XLSX\Reader as XlsxReader;
use RuntimeException;

class RowReader
{
    public const ALLOWED_FORMATS = ['csv', 'xlsx', 'xml'];

    /**
     * @return array{header: list<string>, sample: list<array<string, string>>}
     */
    public function preview(string $path, string $format, int $sampleLimit = 5): array
    {
        $format = strtolower($format);
        $this->assertFormat($format);

        if ($format === 'xml') {
            $rows = iterator_to_array($this->iterateXmlRaw($path));
            $header = [];
            foreach ($rows as $row) {
                foreach (array_keys($row) as $key) {
                    if (! in_array($key, $header, true)) {
                        $header[] = $key;
                    }
                }
            }

            $sample = [];
            foreach (array_slice($rows, 0, $sampleLimit) as $row) {
                $padded = [];
                foreach ($header as $col) {
                    $padded[$col] = $row[$col] ?? '';
                }
                $sample[] = $padded;
            }

            return [
                'header' => $header,
                'sample' => $sample,
            ];
        }

        $header = [];
        $sample = [];
        $count = 0;

        foreach ($this->iterateWithHeader($path, $format, $header) as $row) {
            if ($count < $sampleLimit) {
                $sample[] = $row;
                $count++;
            } else {
                break;
            }
        }

        return [
            'header' => $header,
            'sample' => $sample,
        ];
    }

    /**
     * @return Generator<int, array<string, string>>
     */
    public function iterate(string $path, string $format): Generator
    {
        $format = strtolower($format);
        $this->assertFormat($format);

        if ($format === 'xml') {
            yield from $this->iterateXmlRaw($path);

            return;
        }

        $header = [];
        yield from $this->iterateWithHeader($path, $format, $header);
    }

    /**
     * @param  list<string>  $headerOut
     * @return Generator<int, array<string, string>>
     */
    private function iterateWithHeader(string $path, string $format, array &$headerOut): Generator
    {
        if ($format === 'csv') {
            yield from $this->iterateCsv($path, $headerOut);

            return;
        }

        yield from $this->iterateXlsx($path, $headerOut);
    }

    /**
     * @param  list<string>  $headerOut
     * @return Generator<int, array<string, string>>
     */
    private function iterateCsv(string $path, array &$headerOut): Generator
    {
        $delimiter = $this->detectCsvDelimiter($path);
        $handle = @fopen($path, 'rb');
        if ($handle === false) {
            throw new RuntimeException('Unable to open CSV file.');
        }

        try {
            $headerFound = false;
            while (($raw = fgetcsv($handle, 0, $delimiter)) !== false) {
                if ($raw === [null]) {
                    continue;
                }
                $cells = array_map(fn ($v): string => trim((string) ($v ?? '')), $raw);
                if (! $headerFound) {
                    if ($cells !== []) {
                        $cells[0] = $this->stripBom($cells[0]);
                    }
                    if ($this->isEmptyRow($cells)) {
                        continue;
                    }
                    $headerOut = $this->normalizeHeaderRow($cells);
                    $headerFound = true;
                    continue;
                }

                if ($this->isEmptyRow($cells)) {
                    continue;
                }

                yield $this->combineRow($headerOut, $cells);
            }
        } finally {
            fclose($handle);
        }
    }

    /**
     * @param  list<string>  $headerOut
     * @return Generator<int, array<string, string>>
     */
    private function iterateXlsx(string $path, array &$headerOut): Generator
    {
        if (! class_exists(XlsxReader::class)) {
            throw new RuntimeException('OpenSpout XLSX reader is not installed.');
        }
        if (! class_exists(\ZipArchive::class)) {
            throw new RuntimeException('PHP zip extension is required to read .xlsx files. Enable extension=zip in php.ini or upload as .csv/.xml.');
        }

        $reader = new XlsxReader(new XlsxOptions());
        $reader->open($path);

        try {
            foreach ($reader->getSheetIterator() as $sheet) {
                $headerFound = false;
                foreach ($sheet->getRowIterator() as $row) {
                    $cells = [];
                    foreach ($row->getCells() as $cell) {
                        $val = $cell->getValue();
                        if ($val instanceof DateTimeInterface) {
                            $cells[] = $val->format('Y-m-d H:i:s');
                        } elseif (is_bool($val)) {
                            $cells[] = $val ? '1' : '0';
                        } else {
                            $cells[] = trim((string) ($val ?? ''));
                        }
                    }

                    if (! $headerFound) {
                        if ($this->isEmptyRow($cells)) {
                            continue;
                        }
                        $headerOut = $this->normalizeHeaderRow($cells);
                        $headerFound = true;
                        continue;
                    }

                    if ($this->isEmptyRow($cells)) {
                        continue;
                    }

                    yield $this->combineRow($headerOut, $cells);
                }

                break; // First sheet only
            }
        } finally {
            $reader->close();
        }
    }

    /**
     * @return Generator<int, array<string, string>>
     */
    private function iterateXmlRaw(string $path): Generator
    {
        $this->assertSafeXmlFile($path);

        $prev = libxml_use_internal_errors(true);
        libxml_clear_errors();

        try {
            $dom = new DOMDocument('1.0', 'UTF-8');
            $loaded = $dom->load($path, LIBXML_NONET | LIBXML_NOBLANKS | LIBXML_NOCDATA);
            if (! $loaded || $dom->documentElement === null) {
                $errors = libxml_get_errors();
                $msg = $errors !== [] ? trim($errors[0]->message) : 'Malformed XML file.';
                throw new InvalidArgumentException('Invalid XML: '.$msg);
            }
            if ($dom->doctype !== null) {
                throw new InvalidArgumentException('XML DOCTYPE declarations are rejected for security.');
            }

            $root = $dom->documentElement;
            foreach ($root->childNodes as $child) {
                if (! $child instanceof DOMElement) {
                    continue;
                }
                foreach ($this->flattenXmlRecord($child) as $flatRow) {
                    yield $flatRow;
                }
            }
        } finally {
            libxml_clear_errors();
            libxml_use_internal_errors($prev);
        }
    }

    private function assertSafeXmlFile(string $path): void
    {
        $handle = @fopen($path, 'rb');
        if ($handle === false) {
            throw new RuntimeException('Unable to read XML file.');
        }

        $tail = '';
        try {
            while (! feof($handle)) {
                $chunk = fread($handle, 65536);
                if ($chunk === false) {
                    break;
                }
                $window = $tail.$chunk;
                if (preg_match('/<!\s*(DOCTYPE|ENTITY)\b/i', $window)) {
                    throw new InvalidArgumentException('XML DOCTYPE declarations are rejected for security.');
                }
                $tail = substr($window, -64);
            }
        } finally {
            fclose($handle);
        }
    }

    /**
     * @return list<array<string, string>>
     */
    private function flattenXmlRecord(DOMElement $record): array
    {
        $base = [];
        foreach ($record->attributes as $attr) {
            $base[$attr->nodeName] = trim($attr->value);
        }

        $childElements = [];
        foreach ($record->childNodes as $node) {
            if ($node instanceof DOMElement) {
                $childElements[] = $node;
            }
        }

        $tagCounts = [];
        foreach ($childElements as $el) {
            $tagCounts[$el->nodeName] = ($tagCounts[$el->nodeName] ?? 0) + 1;
        }

        $expandedGroups = [];

        foreach ($childElements as $el) {
            $subElements = $this->directChildElements($el);

            // Repeated sibling element directly under <order>
            if (($tagCounts[$el->nodeName] ?? 0) > 1) {
                $expandedGroups[] = $this->flattenItemElement($el);
                continue;
            }

            if ($subElements === []) {
                $base[$el->nodeName] = trim($el->textContent);
                foreach ($el->attributes as $attr) {
                    $base[$el->nodeName.'_'.$attr->nodeName] = trim($attr->value);
                }
                continue;
            }

            if ($this->isRepeatingContainer($el, $subElements)) {
                foreach ($subElements as $sub) {
                    $expandedGroups[] = $this->flattenItemElement($sub);
                }
                continue;
            }

            // Simple nested object e.g. <customer><name>...</name><phone>...</phone></customer>
            foreach ($el->attributes as $attr) {
                $base[$el->nodeName.'_'.$attr->nodeName] = trim($attr->value);
            }
            foreach ($subElements as $sub) {
                $base[$el->nodeName.'_'.$sub->nodeName] = trim($sub->textContent);
            }
        }

        if ($expandedGroups === []) {
            return $base === [] ? [] : [$base];
        }

        $rows = [];
        foreach ($expandedGroups as $group) {
            $merged = $base;
            foreach ($group as $k => $v) {
                if ($k === 'name' && (isset($merged['name']) || isset($merged['customer_name']))) {
                    $merged['item_name'] = $v;
                } elseif (isset($merged[$k])) {
                    $merged['item_'.$k] = $v;
                } else {
                    $merged[$k] = $v;
                }
            }
            $rows[] = $merged;
        }

        return $rows;
    }

    /**
     * @return list<DOMElement>
     */
    private function directChildElements(DOMElement $parent): array
    {
        $out = [];
        foreach ($parent->childNodes as $node) {
            if ($node instanceof DOMElement) {
                $out[] = $node;
            }
        }

        return $out;
    }

    /**
     * @param  list<DOMElement>  $subElements
     */
    private function isRepeatingContainer(DOMElement $el, array $subElements): bool
    {
        $names = array_map(fn (DOMElement $c): string => $c->nodeName, $subElements);
        $uniqueNames = array_values(array_unique($names));

        if (count($subElements) > 1 && count($uniqueNames) === 1) {
            return true;
        }

        if (in_array(strtolower($el->nodeName), ['items', 'order_items', 'line_items', 'lines', 'products'], true)
            && count($uniqueNames) === 1) {
            return true;
        }

        return false;
    }

    /**
     * @return array<string, string>
     */
    private function flattenItemElement(DOMElement $el): array
    {
        $row = [];
        foreach ($el->attributes as $attr) {
            $row[$attr->nodeName] = trim($attr->value);
        }

        $children = $this->directChildElements($el);
        if ($children === []) {
            $row[$el->nodeName] = trim($el->textContent);

            return $row;
        }

        foreach ($children as $child) {
            if ($child->nodeName === 'name') {
                $row['item_name'] = trim($child->textContent);
            } else {
                $row[$child->nodeName] = trim($child->textContent);
            }
        }

        return $row;
    }

    private function detectCsvDelimiter(string $path): string
    {
        $handle = @fopen($path, 'rb');
        if ($handle === false) {
            return ',';
        }

        try {
            $firstLine = '';
            while (($line = fgets($handle)) !== false) {
                $stripped = trim($this->stripBom($line));
                if ($stripped !== '') {
                    $firstLine = $stripped;
                    break;
                }
            }
        } finally {
            fclose($handle);
        }

        if ($firstLine === '') {
            return ',';
        }

        $bestDelim = ',';
        $bestCount = 0;
        foreach ([',', ';', "\t", '|'] as $cand) {
            $cols = str_getcsv($firstLine, $cand);
            $count = count($cols);
            if ($count > $bestCount) {
                $bestCount = $count;
                $bestDelim = $cand;
            }
        }

        return $bestDelim;
    }

    /**
     * @param  list<string>  $cells
     * @return list<string>
     */
    private function normalizeHeaderRow(array $cells): array
    {
        // Trim trailing empty cells
        while ($cells !== [] && end($cells) === '') {
            array_pop($cells);
        }

        $headers = [];
        $seen = [];
        foreach ($cells as $idx => $raw) {
            $name = $raw !== '' ? $raw : 'column_'.($idx + 1);
            if (isset($seen[$name])) {
                $seen[$name]++;
                $name = $name.' ('.$seen[$name].')';
            } else {
                $seen[$name] = 1;
            }
            $headers[] = $name;
        }

        return $headers;
    }

    /**
     * @param  list<string>  $header
     * @param  list<string>  $cells
     * @return array<string, string>
     */
    private function combineRow(array $header, array $cells): array
    {
        $assoc = [];
        foreach ($header as $i => $col) {
            $assoc[$col] = isset($cells[$i]) ? trim($cells[$i]) : '';
        }

        return $assoc;
    }

    /**
     * @param  list<string>  $cells
     */
    private function isEmptyRow(array $cells): bool
    {
        foreach ($cells as $c) {
            if (trim($c) !== '') {
                return false;
            }
        }

        return true;
    }

    private function stripBom(string $s): string
    {
        return str_starts_with($s, "\xEF\xBB\xBF") ? substr($s, 3) : $s;
    }

    private function assertFormat(string $format): void
    {
        if (! in_array($format, self::ALLOWED_FORMATS, true)) {
            throw new InvalidArgumentException("Unsupported format [{$format}]. Save the file as .csv, .xlsx, or .xml.");
        }
    }
}
