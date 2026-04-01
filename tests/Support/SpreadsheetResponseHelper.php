<?php

declare(strict_types=1);

namespace Tests\Support;

use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;

trait SpreadsheetResponseHelper
{
    // レスポンスの Excel バイナリをワークシートとして読み込む
    protected function loadWorksheetFromResponse(Response $response, string $extension): Worksheet
    {
        $spreadsheet = $this->loadSpreadsheetFromResponse($response, $extension);

        return $spreadsheet->getActiveSheet();
    }

    // レスポンスの Excel バイナリを PhpSpreadsheet で読み込む
    protected function loadSpreadsheetFromResponse(Response $response, string $extension): Spreadsheet
    {
        $tempBase = tempnam(sys_get_temp_dir(), 'noble-sheet-');
        if ($tempBase === false) {
            $this->fail('Failed to create temp file for spreadsheet.');
        }

        $tempFile = $tempBase . '.' . $extension;
        if (!@rename($tempBase, $tempFile)) {
            @unlink($tempBase);
            $this->fail('Failed to prepare temp file for spreadsheet.');
        }

        try {
            file_put_contents($tempFile, $response->body);
            return IOFactory::load($tempFile);
        } finally {
            @unlink($tempFile);
        }
    }

    // シート内で指定文字列が何回出てくるかを数える
    protected function countCellValueOccurrences(Worksheet $sheet, string $expectedValue): int
    {
        $count = 0;

        foreach ($sheet->toArray(null, true, true, true) as $row) {
            foreach ($row as $value) {
                if ((string) $value === $expectedValue) {
                    $count++;
                }
            }
        }

        return $count;
    }
}
