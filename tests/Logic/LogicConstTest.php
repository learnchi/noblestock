<?php

declare(strict_types=1);

use Noblestock\Logic\LogicConst;
use PHPUnit\Framework\TestCase;

final class LogicConstTest extends TestCase
{
    /**
     * 主要なバーコードコマンド定数を参照し、期待している固定値と一致することを確認する。
     */
    public function testCommandConstantsHaveExpectedValues(): void
    {
        $this->assertSame('PCPM', LogicConst::CMD_MENU);
        $this->assertSame('PCPV', LogicConst::CMD_VIEW);
        $this->assertSame('PCPP', LogicConst::CMD_COMPLETE);
        $this->assertSame('PLP', LogicConst::CMD_LOC);
        $this->assertSame('/config/mailconfig.ini', LogicConst::MAIL_CONFIG_PATH);
    }

    /**
     * 権限定義の件数を確認し、AUTH_BITSで定義されたビット数と一致することを確認する。
     */
    public function testPermissionDefsCountMatchesAuthBits(): void
    {
        $this->assertCount(LogicConst::AUTH_BITS, LogicConst::PERMISSION_DEFS);
    }

    /**
     * マスター定義のロケーション設定を参照し、ロケーション用コマンドが設定されていることを確認する。
     */
    public function testMasterBulkDefsLocationUsesLocationCommand(): void
    {
        $locationDef = LogicConst::MASTER_BULK_DEFS[4];

        $this->assertSame('Location', $locationDef['name']);
        $this->assertSame(LogicConst::CMD_LOC, $locationDef['barCmd']);
    }
}
