<?php

declare(strict_types=1);

use Noblestock\Util\UtilCommon;
use PHPUnit\Framework\TestCase;

final class UtilCommonTest extends TestCase
{
    /**
     * テスト用のマスタデータを返す
     *
     * @return array<int, array<string, mixed>>
     */
    private function createMasterRows(): array
    {
        return [
            [
                'id' => 10,
                'name' => '店舗10',
            ],
            [
                'id' => '20',
                'name' => '店舗20',
            ],
            [
                'id' => 30,
                'name' => '店舗30',
            ],
        ];
    }

    /**
     * getTargetArray は一致する行を返し、数字文字列と整数も同値として扱う
     */
    public function testGetTargetArrayReturnsMatchedRow(): void
    {
        $rows = $this->createMasterRows();

        $matchedByInt = UtilCommon::getTargetArray($rows, 'id', 20);
        $matchedByString = UtilCommon::getTargetArray($rows, 'id', '10');

        $this->assertSame(['id' => '20', 'name' => '店舗20'], $matchedByInt);
        $this->assertSame(['id' => 10, 'name' => '店舗10'], $matchedByString);
    }

    /**
     * getTargetArray はデータが空、または一致しない場合は null を返す
     */
    public function testGetTargetArrayReturnsNullWhenNotMatched(): void
    {
        $rows = $this->createMasterRows();

        $this->assertNull(UtilCommon::getTargetArray($rows, 'id', 999));
        $this->assertNull(UtilCommon::getTargetArray(null, 'id', 10));
        $this->assertNull(UtilCommon::getTargetArray([], 'id', 10));
    }

    /**
     * masterConsistencyCheck はキー項目がある場合、その値から対応する表示値を返す
     */
    public function testMasterConsistencyCheckReturnsMatchedDataFromKey(): void
    {
        $rows = $this->createMasterRows();

        $result = UtilCommon::masterConsistencyCheck($rows, 'id', 'name', '20', '');

        $this->assertSame([
            'id' => '20',
            'name' => '店舗20',
        ], $result);
    }

    /**
     * masterConsistencyCheck はキー項目が空なら表示値から対応するキーを返す
     */
    public function testMasterConsistencyCheckReturnsMatchedDataFromValue(): void
    {
        $rows = $this->createMasterRows();

        $result = UtilCommon::masterConsistencyCheck($rows, 'id', 'name', '', '店舗30');

        $this->assertSame([
            'id' => 30,
            'name' => '店舗30',
        ], $result);
    }

    /**
     * masterConsistencyCheck は一致しない場合、両方の値を null にする
     */
    public function testMasterConsistencyCheckReturnsNullValuesWhenNotMatched(): void
    {
        $rows = $this->createMasterRows();

        $result = UtilCommon::masterConsistencyCheck($rows, 'id', 'name', '999', '');

        $this->assertSame([
            'id' => null,
            'name' => null,
        ], $result);
    }

    /**
     * masterConsistencyCheck は入力が両方空なら null 値の配列を返す
     */
    public function testMasterConsistencyCheckReturnsNullValuesWhenBothInputsAreEmpty(): void
    {
        $rows = $this->createMasterRows();

        $result = UtilCommon::masterConsistencyCheck($rows, 'id', 'name', '', '');

        $this->assertSame([
            'id' => null,
            'name' => null,
        ], $result);
    }

    /**
     * 0〜4294967295 の範囲にある整数文字列は true になることを確認する
     */
    public function testIsUnsignedInt32StringReturnsTrueForValidValues(): void
    {
        $this->assertTrue(UtilCommon::isUnsignedInt32String('0'));
        $this->assertTrue(UtilCommon::isUnsignedInt32String('1'));
        $this->assertTrue(UtilCommon::isUnsignedInt32String('4294967295'));
    }

    /**
     * 範囲外や整数文字列でない値は false になることを確認する
     */
    public function testIsUnsignedInt32StringReturnsFalseForInvalidValues(): void
    {
        $this->assertFalse(UtilCommon::isUnsignedInt32String(''));
        $this->assertFalse(UtilCommon::isUnsignedInt32String('-1'));
        $this->assertFalse(UtilCommon::isUnsignedInt32String('1.5'));
        $this->assertFalse(UtilCommon::isUnsignedInt32String('1e3'));
        $this->assertFalse(UtilCommon::isUnsignedInt32String('4294967296'));
        $this->assertFalse(UtilCommon::isUnsignedInt32String(null));
    }
}
