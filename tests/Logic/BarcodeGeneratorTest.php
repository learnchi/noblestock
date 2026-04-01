<?php

declare(strict_types=1);

use Noblestock\Logic\BarcodeGenerator;
use PHPUnit\Framework\TestCase;

final class BarcodeGeneratorTest extends TestCase
{
    /**
     * 有効なJANコードでバーコード画像を生成し、戻り値が成功コードかつPNGファイルが作成されることを確認する。
     */
    public function testCreateBarcodeCreatesPngFile(): void
    {
        $filePath = __DIR__ . '/../tmp/barcode-generator-test.png';
        @unlink($filePath);

        $generator = new BarcodeGenerator();
        $result = $generator->createBarcode('4901234567894', $filePath, 2);

        $this->assertSame(0, $result);
        $this->assertFileExists($filePath);
        $this->assertGreaterThan(0, (int) @filesize($filePath));

        @unlink($filePath);
    }

    /**
     * 有効なUPC-Aコードを判定し、UPC-Aの判定値(12)が返ることを確認する。
     */
    public function testCheckReturns12ForValidUpcA(): void
    {
        $this->assertSame(12, BarcodeGenerator::check('042100005264', 2));
    }

    /**
     * checkBarCodeで有効なJANコードを判定し、JANの判定値(13)が返ることを確認する。
     */
    public function testCheckBarCodeReturns13ForValidJan(): void
    {
        $this->assertSame(13, BarcodeGenerator::checkBarCode('4901234567894', 2));
    }

    /**
     * checkJANで有効なJANコードを判定し、チェックデジット一致の正常値(10)が返ることを確認する。
     */
    public function testCheckJanReturns10ForValidJan(): void
    {
        $this->assertSame(10, BarcodeGenerator::checkJAN('4901234567894'));
    }
}

