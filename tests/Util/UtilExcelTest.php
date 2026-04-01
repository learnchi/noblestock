<?php

declare(strict_types=1);

use Noblestock\Util\UtilExcel;
use PhpOffice\PhpSpreadsheet\Cell\DataType;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PHPUnit\Framework\TestCase;

final class UtilExcelTest extends TestCase
{
    /**
     * 数式解釈されうる文字列は明示的に文字列セルへ書き込み、
     * 通常文字列や数値は既存どおりの値で保持されることを確認する。
     */
    public function testSetCellValueWritesFormulaLikeStringAsString(): void
    {
        $spreadsheet = new Spreadsheet();
        $sheet = $spreadsheet->getActiveSheet();

        // 先頭記号付きの値は Excel の数式として評価させない。
        UtilExcel::setCellValue($sheet, 'A1', '=SUM(1,1)');
        UtilExcel::setCellValue($sheet, 'A2', '+cmd');
        UtilExcel::setCellValue($sheet, 'A3', '@user');

        // 通常の文字列と数値はそのまま扱う。
        UtilExcel::setCellValue($sheet, 'A4', 'normal text');
        UtilExcel::setCellValue($sheet, 'A5', 123);

        $this->assertSame(DataType::TYPE_STRING, $sheet->getCell('A1')->getDataType());
        $this->assertSame(DataType::TYPE_STRING, $sheet->getCell('A2')->getDataType());
        $this->assertSame(DataType::TYPE_STRING, $sheet->getCell('A3')->getDataType());
        $this->assertSame('=SUM(1,1)', (string) $sheet->getCell('A1')->getValue());
        $this->assertSame('normal text', (string) $sheet->getCell('A4')->getValue());
        $this->assertSame('123', (string) $sheet->getCell('A5')->getValue());
    }
}
