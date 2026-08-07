<?php

namespace ESolution\DataSources\Services\Import;

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;
use RuntimeException;
use SimpleXMLElement;
use ZipArchive;

class ImportTemplateReader
{
    public function analyze(UploadedFile|string $file, string|int|null $selectedSheet = null): array
    {
        $path = is_string($file) ? $file : $file->getRealPath();
        $originalName = is_string($file) ? basename($file) : $file->getClientOriginalName();
        $extension = strtolower(pathinfo($originalName, PATHINFO_EXTENSION));

        if ($extension === 'csv') {
            return $this->analyzeCsv($path, $originalName);
        }

        if ($extension === 'xlsx') {
            $workbook = $this->readAllRows($file);
            $sheet = $this->resolveRequestedSheetName(
                $selectedSheet,
                (array) ($workbook['metadata']['sheet_names'] ?? []),
                $originalName
            );
            $analysis = $workbook['worksheets'][$sheet] ?? null;
            if ($analysis === null) {
                throw ValidationException::withMessages([
                    'worksheet' => [$this->worksheetNotFoundMessage($sheet, $workbook['metadata']['sheet_names'] ?? [], $originalName)],
                ]);
            }
            $analysis['metadata']['worksheets'] = $workbook['metadata']['worksheets'] ?? [];
            return $analysis;
        }

        throw new RuntimeException('Unsupported template file type.');
    }

    public function readRows(UploadedFile|string $file, string|int|null $selectedSheet = null): array
    {
        $analysis = $this->analyze($file, $selectedSheet);

        return [
            'metadata' => $analysis['metadata'],
            'rows' => $analysis['rows'],
        ];
    }

    /**
     * Read every worksheet so imports can resolve parent and child records from
     * different sheets in the same workbook. CSV continues to expose Sheet1.
     */
    public function readAllRows(UploadedFile|string $file): array
    {
        $path = is_string($file) ? $file : $file->getRealPath();
        $originalName = is_string($file) ? basename($file) : $file->getClientOriginalName();
        $extension = strtolower(pathinfo($originalName, PATHINFO_EXTENSION));

        if ($extension === 'csv') {
            $analysis = $this->analyzeCsv($path, $originalName);
            return [
                'metadata' => $analysis['metadata'],
                'worksheets' => ['Sheet1' => $analysis],
            ];
        }

        if ($extension !== 'xlsx') {
            throw new RuntimeException('Unsupported template file type.');
        }

        if (! class_exists(ZipArchive::class)) {
            throw new RuntimeException('ZipArchive extension is required for XLSX import.');
        }

        $zip = new ZipArchive();
        if ($zip->open($path) !== true) {
            throw new RuntimeException('Unable to open XLSX file.');
        }

        try {
            [$sheetNames, $sheetTargets, $sharedStrings] = $this->readWorkbookMetadata($zip);
            $worksheets = [];
            Log::debug('Workbook loaded', [
                'file' => $path,
                'sheet_count' => count($sheetNames),
                'available_sheets' => $sheetNames,
                'requested_sheet' => null,
            ]);

            foreach ($sheetNames as $sheetIndex => $sheetName) {
                $sheetPath = $sheetTargets[$sheetName] ?? null;
                if (! is_string($sheetPath) || $sheetPath === '') {
                    throw ValidationException::withMessages([
                        'worksheet' => [$this->worksheetNotFoundMessage($sheetName, $sheetNames, $originalName, $sheetIndex)],
                    ]);
                }
                $worksheets[$sheetName] = $this->normalizeTabularRows(
                    $this->readWorksheetRows($zip, $sheetPath, $sharedStrings, $sheetName, $sheetIndex, $sheetNames, $path, $originalName),
                    $sheetNames,
                    $sheetName,
                    $originalName
                );
            }

            $selectedSheet = $sheetNames[0] ?? 'Sheet1';
            $metadata = $worksheets[$selectedSheet]['metadata'] ?? [
                'sheet_names' => $sheetNames,
                'selected_sheet' => $selectedSheet,
                'column_headers' => [],
                'original_name' => $originalName,
            ];
            $metadata['worksheets'] = array_map(
                static fn (array $analysis): array => $analysis['metadata'],
                $worksheets
            );

            return compact('metadata', 'worksheets');
        } finally {
            $zip->close();
        }
    }

    protected function analyzeCsv(string $path, string $originalName): array
    {
        $handle = fopen($path, 'rb');

        if ($handle === false) {
            throw new RuntimeException('Unable to read CSV file.');
        }

        $rows = [];
        while (($row = fgetcsv($handle)) !== false) {
            $rows[] = $row;
        }
        fclose($handle);

        return $this->normalizeTabularRows($rows, ['Sheet1'], 'Sheet1', $originalName);
    }

    protected function analyzeXlsx(string $path, string $originalName, string|int|null $selectedSheet = null): array
    {
        if (! class_exists(ZipArchive::class)) {
            throw new RuntimeException('ZipArchive extension is required for XLSX import.');
        }

        $zip = new ZipArchive();
        if ($zip->open($path) !== true) {
            throw new RuntimeException('Unable to open XLSX file.');
        }

        try {
            [$sheetNames, $sheetTargets, $sharedStrings] = $this->readWorkbookMetadata($zip);
            $sheetName = $this->resolveRequestedSheetName($selectedSheet, $sheetNames, $originalName);
            $sheetIndex = array_search($sheetName, $sheetNames, true);
            $sheetPath = $sheetTargets[$sheetName] ?? null;

            if (! is_string($sheetPath) || $sheetPath === '') {
                throw ValidationException::withMessages([
                    'worksheet' => [$this->worksheetNotFoundMessage($selectedSheet ?? $sheetName, $sheetNames, $originalName, is_int($sheetIndex) ? $sheetIndex : null)],
                ]);
            }

            $rows = $this->readWorksheetRows($zip, $sheetPath, $sharedStrings, $sheetName, is_int($sheetIndex) ? $sheetIndex : null, $sheetNames, $path, $originalName);

            return $this->normalizeTabularRows($rows, $sheetNames, $sheetName, $originalName);
        } finally {
            $zip->close();
        }
    }

    protected function readWorkbookMetadata(ZipArchive $zip): array
    {
        $workbookXml = $zip->getFromName('xl/workbook.xml');
        $relsXml = $zip->getFromName('xl/_rels/workbook.xml.rels');

        if ($workbookXml === false || $relsXml === false) {
            throw new RuntimeException('Invalid XLSX workbook structure.');
        }

        $workbook = new SimpleXMLElement($workbookXml);
        $rels = new SimpleXMLElement($relsXml);

        $relationships = [];
        foreach ($rels->Relationship as $relationship) {
            $id = (string) ($relationship['Id'] ?? '');
            $target = (string) ($relationship['Target'] ?? '');
            if ($id !== '' && $target !== '') {
                $relationships[$id] = $this->resolveRelationshipTarget($target);
            }
        }

        $sheetNames = [];
        $sheetTargets = [];

        foreach ($workbook->sheets->sheet as $sheet) {
            $sheetName = (string) ($sheet['name'] ?? 'Sheet1');
            $sheetAttributes = $sheet->attributes('r', true);
            $relId = (string) ($sheetAttributes['id'] ?? '');
            $sheetNames[] = $sheetName;
            if ($relId !== '' && isset($relationships[$relId])) {
                $sheetTargets[$sheetName] = $relationships[$relId];
            }
        }

        $sharedStrings = [];
        $sharedXml = $zip->getFromName('xl/sharedStrings.xml');
        if ($sharedXml !== false) {
            $shared = new SimpleXMLElement($sharedXml);
            foreach ($shared->si as $item) {
                $sharedStrings[] = trim((string) $item->t);
            }
        }

        return [$sheetNames, $sheetTargets, $sharedStrings];
    }

    protected function readWorksheetRows(
        ZipArchive $zip,
        string $sheetPath,
        array $sharedStrings,
        string $sheetName,
        ?int $sheetIndex,
        array $sheetNames,
        string $filePath,
        string $originalName
    ): array
    {
        Log::debug('Loading workbook worksheet', [
            'file' => $filePath,
            'sheet_count' => count($sheetNames),
            'available_sheets' => $sheetNames,
            'requested_sheet' => $sheetIndex === null ? $sheetName : ['name' => $sheetName, 'index' => $sheetIndex],
        ]);

        if ($sheetIndex !== null && ($sheetIndex < 0 || $sheetIndex >= count($sheetNames))) {
            throw ValidationException::withMessages([
                'worksheet' => [$this->worksheetNotFoundMessage($sheetIndex, $sheetNames, $originalName)],
            ]);
        }

        if (! in_array($sheetName, $sheetNames, true)) {
            throw ValidationException::withMessages([
                'worksheet' => [$this->worksheetNotFoundMessage($sheetName, $sheetNames, $originalName)],
            ]);
        }

        try {
            $sheetXml = $zip->getFromName($sheetPath);
            if ($sheetXml === false) {
                $zipStatus = method_exists($zip, 'getStatusString') ? $zip->getStatusString() : 'unknown ZIP error';
                throw new RuntimeException('ZIP entry "' . $sheetPath . '" was not found (' . $zipStatus . ').');
            }

            $worksheet = new SimpleXMLElement($sheetXml);
        } catch (Throwable $exception) {
            throw new RuntimeException(
                'Failed to load worksheet ' . $this->formatWorksheetRequest($sheetName, $sheetIndex)
                . ' from "' . $originalName . '". Available worksheets: '
                . implode(', ', $sheetNames) . '. Original error: ' . $exception->getMessage(),
                0,
                $exception
            );
        }
        $rows = [];

        foreach ($worksheet->sheetData->row as $row) {
            $rowNumber = (int) ($row['r'] ?? (count($rows) + 1));
            $current = [];
            $maxIndex = -1;

            foreach ($row->c as $cell) {
                $ref = (string) ($cell['r'] ?? '');
                $index = $this->columnLettersToIndex(preg_replace('/\d+$/', '', $ref) ?: '');
                $value = $this->resolveCellValue($cell, $sharedStrings);
                $current[$index] = $value;
                $maxIndex = max($maxIndex, $index);
            }

            if ($maxIndex >= 0) {
                ksort($current);
                $rows[] = [
                    'row' => $rowNumber > 0 ? $rowNumber : count($rows) + 1,
                    'values' => array_values($current),
                ];
            }
        }

        return $rows;
    }

    protected function resolveRequestedSheetName(string|int|null $requestedSheet, array $sheetNames, ?string $originalName = null): string
    {
        if ($sheetNames === []) {
            throw ValidationException::withMessages([
                'worksheet' => ['The workbook contains no worksheets.'],
            ]);
        }

        if ($requestedSheet === null || $requestedSheet === '') {
            return (string) $sheetNames[0];
        }

        if (is_int($requestedSheet) || ctype_digit((string) $requestedSheet)) {
            $index = (int) $requestedSheet;
            if ($index < 0 || $index >= count($sheetNames)) {
                throw ValidationException::withMessages([
                    'worksheet' => [$this->worksheetNotFoundMessage($requestedSheet, $sheetNames, $originalName)],
                ]);
            }
            return (string) $sheetNames[$index];
        }

        if (! in_array((string) $requestedSheet, $sheetNames, true)) {
            throw ValidationException::withMessages([
                'worksheet' => [$this->worksheetNotFoundMessage((string) $requestedSheet, $sheetNames, $originalName)],
            ]);
        }

        return (string) $requestedSheet;
    }

    protected function worksheetNotFoundMessage(string|int $requestedSheet, array $sheetNames, ?string $originalName = null, ?int $sheetIndex = null): string
    {
        $request = $this->formatWorksheetRequest($requestedSheet, $sheetIndex);
        $message = 'Worksheet ' . $request . ' was not found.';
        if ($originalName !== null && $originalName !== '') {
            $message .= ' File: "' . $originalName . '".';
        }
        $message .= ' Available worksheets:\n- ' . implode("\n- ", $sheetNames);
        return $message;
    }

    protected function formatWorksheetRequest(string|int $requestedSheet, ?int $sheetIndex = null): string
    {
        if ($sheetIndex !== null) {
            return '"' . $requestedSheet . '" (index ' . $sheetIndex . ')';
        }

        return is_int($requestedSheet) || ctype_digit((string) $requestedSheet)
            ? 'index ' . $requestedSheet
            : '"' . $requestedSheet . '"';
    }

    protected function normalizeZipPath(string $path): string
    {
        $parts = [];
        foreach (explode('/', str_replace('\\', '/', $path)) as $part) {
            if ($part === '' || $part === '.') {
                continue;
            }
            if ($part === '..') {
                array_pop($parts);
                continue;
            }
            $parts[] = $part;
        }

        return implode('/', $parts);
    }

    protected function resolveRelationshipTarget(string $target): string
    {
        $target = str_replace('\\', '/', trim($target));

        // XLSX relationship targets may be absolute archive paths such as
        // /xl/worksheets/sheet1.xml or relative to xl/workbook.xml.
        if (str_starts_with($target, '/')) {
            return $this->normalizeZipPath(ltrim($target, '/'));
        }

        return $this->normalizeZipPath(
            str_starts_with($target, 'xl/') ? $target : 'xl/' . $target
        );
    }

    protected function normalizeTabularRows(array $rows, array $sheetNames, string $selectedSheet, ?string $originalName = null): array
    {
        $headerRowIndex = null;
        $headerValues = [];

        foreach ($rows as $index => $rowInfo) {
            $row = (array) ($rowInfo['values'] ?? []);
            $normalized = array_values(array_map(static fn ($value) => trim((string) $value), $row));
            $hasValue = array_filter($normalized, static fn ($value) => $value !== '');
            if ($hasValue !== []) {
                $headerRowIndex = $index;
                $headerValues = $normalized;
                break;
            }
        }

        $headerRow = $headerRowIndex !== null ? $headerRowIndex + 1 : 1;
        $headers = [];

        foreach ($headerValues as $columnIndex => $header) {
            $headers[] = $header !== '' ? $header : 'Column ' . $this->indexToColumnLetters($columnIndex);
        }

        $dataRows = [];
        foreach ($rows as $index => $rowInfo) {
            if ($headerRowIndex !== null && $index <= $headerRowIndex) {
                continue;
            }

            $row = (array) ($rowInfo['values'] ?? []);
            $assoc = [];
            foreach ($headers as $columnIndex => $header) {
                $assoc[$header] = $this->normalizeCellValue($row[$columnIndex] ?? null);
            }

            if (array_filter($assoc, static fn ($value) => $value !== null && $value !== '') === []) {
                continue;
            }

            $dataRows[] = [
                'row' => (int) ($rowInfo['row'] ?? ($headerRow + count($dataRows) + 1)),
                'data' => $assoc,
            ];
        }

        return [
            'metadata' => [
                'sheet_names' => $sheetNames,
                'selected_sheet' => $selectedSheet,
                'header_row' => $headerRow,
                'start_data_row' => $headerRow + 1,
                'column_headers' => $headers,
                'total_columns' => count($headers),
                'total_rows' => count($dataRows),
                'original_name' => $originalName,
            ],
            'rows' => $dataRows,
        ];
    }

    protected function resolveCellValue(SimpleXMLElement $cell, array $sharedStrings): mixed
    {
        $type = (string) ($cell['t'] ?? '');
        $value = isset($cell->v) ? (string) $cell->v : '';

        if ($type === 's') {
            return $sharedStrings[(int) $value] ?? '';
        }

        if ($type === 'inlineStr') {
            return trim((string) ($cell->is->t ?? ''));
        }

        return $value;
    }

    protected function normalizeCellValue(mixed $value): mixed
    {
        if (! is_string($value)) {
            return $value;
        }

        $trimmed = trim($value);
        if ($trimmed === '') {
            return null;
        }

        if (in_array(strtolower($trimmed), ['true', 'false'], true)) {
            return strtolower($trimmed) === 'true';
        }

        if ((str_starts_with($trimmed, '{') && str_ends_with($trimmed, '}'))
            || (str_starts_with($trimmed, '[') && str_ends_with($trimmed, ']'))) {
            try {
                return json_decode($trimmed, true, 512, JSON_THROW_ON_ERROR);
            } catch (\Throwable) {
                return $trimmed;
            }
        }

        return $trimmed;
    }

    protected function columnLettersToIndex(string $letters): int
    {
        $letters = strtoupper(trim($letters));
        $index = 0;
        $length = strlen($letters);

        for ($i = 0; $i < $length; $i++) {
            $index = ($index * 26) + (ord($letters[$i]) - 64);
        }

        return max(0, $index - 1);
    }

    protected function indexToColumnLetters(int $index): string
    {
        $index++;
        $letters = '';

        while ($index > 0) {
            $remainder = ($index - 1) % 26;
            $letters = chr(65 + $remainder) . $letters;
            $index = intdiv($index - 1, 26);
        }

        return $letters;
    }
}
