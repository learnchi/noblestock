<?php

declare(strict_types=1);

use Noblestock\Logic\MessageConst;
use PHPUnit\Framework\TestCase;

final class MessageConstTest extends TestCase
{
    /**
     * 代表的なメッセージ定数を参照し、空文字ではなく定義されていることを確認する。
     */
    public function testRepresentativeMessageConstantsAreDefined(): void
    {
        $this->assertNotSame('', MessageConst::MSG_SYS_COMMON_900);
        $this->assertNotSame('', MessageConst::MSG_INF_MENU_001);
        $this->assertNotSame('', MessageConst::MSG_VAL_BARCODE_002);
    }

    /**
     * MessageConstの全定数を走査し、すべて文字列かつ空文字でないことを確認する。
     */
    public function testAllMessageConstantsAreNonEmptyStrings(): void
    {
        $reflection = new ReflectionClass(MessageConst::class);
        $constants = $reflection->getConstants();

        foreach ($constants as $name => $value) {
            $this->assertIsString($value, $name);
            $this->assertNotSame('', $value, $name);
        }
    }
}

