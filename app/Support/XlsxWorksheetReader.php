<?php

namespace App\Support;

use ZipArchive;
use RuntimeException;
use SimpleXMLElement;

class XlsxWorksheetReader
{
    private const SPREADSHEET_NAMESPACE = 'http://schemas.openxmlformats.org/spreadsheetml/2006/main';

    /**
     * @return array<int, array<int, string>>
     */
    public function rows(string $path, int $sheetIndex = 0): array
    {
        if (!is_file($path)) {
            throw new RuntimeException("Excel fayl topilmadi: {$path}");
        }

        $zip = new ZipArchive;

        if ($zip->open($path) !== true) {
            throw new RuntimeException('Excel faylni ochib bo‘lmadi.');
        }

        try {
            $sharedStrings = $this->sharedStrings($zip);
            $sheetPath     = $this->sheetPath($zip, $sheetIndex);
            $sheetXml      = $zip->getFromName($sheetPath);

            if ($sheetXml === false) {
                throw new RuntimeException('Excel varag‘i o‘qilmadi.');
            }

            $xml = simplexml_load_string($sheetXml);

            if (!$xml instanceof SimpleXMLElement) {
                throw new RuntimeException('Excel varag‘i XML formati noto‘g‘ri.');
            }

            return $this->parseRows($xml, $sharedStrings);
        } finally {
            $zip->close();
        }
    }

    /**
     * @return array<int, string>
     */
    private function sharedStrings(ZipArchive $zip): array
    {
        $xml = $zip->getFromName('xl/sharedStrings.xml');

        if ($xml === false) {
            return [];
        }

        $sharedStringsXml = simplexml_load_string($xml);

        if (!$sharedStringsXml instanceof SimpleXMLElement) {
            return [];
        }

        $strings = [];

        $sharedStringsXml->registerXPathNamespace('xlsx', self::SPREADSHEET_NAMESPACE);

        foreach ($sharedStringsXml->xpath('//xlsx:si') ?: [] as $stringItem) {
            $text = '';

            $stringItem->registerXPathNamespace('xlsx', self::SPREADSHEET_NAMESPACE);
            $plainText = $stringItem->xpath('xlsx:t');

            if ($plainText !== false && isset($plainText[0])) {
                $text = (string) $plainText[0];
            } else {
                foreach ($stringItem->xpath('xlsx:r/xlsx:t') ?: [] as $runText) {
                    $text .= (string) $runText;
                }
            }

            $strings[] = $text;
        }

        return $strings;
    }

    private function sheetPath(ZipArchive $zip, int $sheetIndex): string
    {
        $workbookXml = simplexml_load_string((string) $zip->getFromName('xl/workbook.xml'));
        $relsXml     = simplexml_load_string((string) $zip->getFromName('xl/_rels/workbook.xml.rels'));

        if (!$workbookXml instanceof SimpleXMLElement || !$relsXml instanceof SimpleXMLElement) {
            throw new RuntimeException('Excel workbook tuzilmasi o‘qilmadi.');
        }

        $workbookXml->registerXPathNamespace('xlsx', self::SPREADSHEET_NAMESPACE);
        $workbookXml->registerXPathNamespace('r', 'http://schemas.openxmlformats.org/officeDocument/2006/relationships');

        $sheets = $workbookXml->xpath('//xlsx:sheet') ?: [];
        $sheet  = $sheets[$sheetIndex] ?? null;

        if (!$sheet instanceof SimpleXMLElement) {
            throw new RuntimeException('Excel ichida import uchun varaq topilmadi.');
        }

        $attributes     = $sheet->attributes('http://schemas.openxmlformats.org/officeDocument/2006/relationships');
        $relationshipId = (string) $attributes['id'];

        foreach ($relsXml->children('http://schemas.openxmlformats.org/package/2006/relationships')->Relationship as $relationship) {
            $relationshipAttributes = $relationship->attributes();

            if ((string) $relationshipAttributes['Id'] !== $relationshipId) {
                continue;
            }

            $target = ltrim((string) $relationshipAttributes['Target'], '/');

            return str_starts_with($target, 'xl/') ? $target : 'xl/' . $target;
        }

        throw new RuntimeException('Excel varaq manzili topilmadi.');
    }

    /**
     * @param  array<int, string>  $sharedStrings
     * @return array<int, array<int, string>>
     */
    private function parseRows(SimpleXMLElement $xml, array $sharedStrings): array
    {
        $rows = [];

        $xml->registerXPathNamespace('xlsx', self::SPREADSHEET_NAMESPACE);

        foreach ($xml->xpath('//xlsx:sheetData/xlsx:row') ?: [] as $row) {
            $values = [];

            $row->registerXPathNamespace('xlsx', self::SPREADSHEET_NAMESPACE);

            foreach ($row->xpath('xlsx:c') ?: [] as $cell) {
                $reference = (string) $cell['r'];
                $column    = $this->columnIndex($reference);

                $values[$column] = $this->cellValue($cell, $sharedStrings);
            }

            if ($values === []) {
                continue;
            }

            ksort($values);

            $lastColumn = max(array_keys($values));
            $normalized = [];

            for ($i = 0; $i <= $lastColumn; $i++) {
                $normalized[] = trim($values[$i] ?? '');
            }

            if (count(array_filter($normalized, fn (string $value): bool => $value !== '')) === 0) {
                continue;
            }

            $rows[] = $normalized;
        }

        return $rows;
    }

    /**
     * @param  array<int, string>  $sharedStrings
     */
    private function cellValue(SimpleXMLElement $cell, array $sharedStrings): string
    {
        $type = (string) $cell['t'];

        if ($type === 's') {
            $index = (int) $cell->v;

            return $sharedStrings[$index] ?? '';
        }

        if ($type === 'inlineStr') {
            $cell->registerXPathNamespace('xlsx', self::SPREADSHEET_NAMESPACE);
            $inlineText = $cell->xpath('xlsx:is/xlsx:t');

            return $inlineText !== false && isset($inlineText[0]) ? (string) $inlineText[0] : '';
        }

        $cell->registerXPathNamespace('xlsx', self::SPREADSHEET_NAMESPACE);
        $value = $cell->xpath('xlsx:v');

        return $value !== false && isset($value[0]) ? (string) $value[0] : '';
    }

    private function columnIndex(string $reference): int
    {
        $letters = preg_replace('/[^A-Z]/', '', strtoupper($reference)) ?: 'A';
        $index   = 0;

        foreach (str_split($letters) as $letter) {
            $index = ($index * 26) + (ord($letter) - ord('A') + 1);
        }

        return $index - 1;
    }
}
