<?php

namespace ESolution\DataSources\Services\Import;

use Illuminate\Http\UploadedFile;
use RuntimeException;
use SimpleXMLElement;
use ZipArchive;

class ImportTemplateReader
{
    public function analyze(UploadedFile|string $file, ?string $selectedSheet = null): array
    {
        $path = is_string($file) ? $file : $file->getRealPath();
        $originalName = is_string($file) ? basename($file) : $file->getClientOriginalName();
        $extension = strtolower(pathinfo($originalName, PATHINFO_EXTENSION));

        if ($extension === 'csv') {
            return $this->analyzeCsv($path, $originalName);
        }

        if ($extension === 'xlsx') {
            return $this->analyzeXlsx($path, $originalName, $selectedSheet);
        }

        throw new RuntimeException('Unsupported template file type.');
    }

    public function readRows(UploadedFile|string $file, ?string $selectedSheet = null): array
    {
        $analysis = $this->analyze($file, $selectedSheet);

        return [
            'metadata' => $analysis['metadata'],
            'rows' => $analysis['rows'],
        ];
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

    protected function analyzeXlsx(string $path, string $originalName, ?string $selectedSheet = null): array
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
            $sheetName = $selectedSheet !== null && in_array($selectedSheet, $sheetNames, true)
                ? $selectedSheet
                : ($sheetNames[0] ?? 'Sheet1');
            $sheetPath = $sheetTargets[$sheetName] ?? array_values($sheetTargets)[0] ?? null;

            if (! is_string($sheetPath) || $sheetPath === '') {
                throw new RuntimeException('No worksheet found in XLSX file.');
            }

            $rows = $this->readWorksheetRows($zip, $sheetPath, $sharedStrings);

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
                $relationships[$id] = 'xl/' . ltrim($target, '/');
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

    protected function readWorksheetRows(ZipArchive $zip, string $sheetPath, array $sharedStrings): array
    {
        $sheetXml = $zip->getFromName($sheetPath);
        if ($sheetXml === false) {
            throw new RuntimeException('Unable to read worksheet.');
        }

        $worksheet = new SimpleXMLElement($sheetXml);
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
