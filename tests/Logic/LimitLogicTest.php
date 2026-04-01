<?php

declare(strict_types=1);

use Noblestock\Logic\LimitLogic;
use PHPUnit\Framework\TestCase;
use Studiogau\Chandra\Support\SessionHelper;

final class LimitLogicTest extends TestCase
{
    private array $backupSession = [];
    private array $backupServer = [];

    protected function setUp(): void
    {
        parent::setUp();

        if (session_status() === PHP_SESSION_NONE) {
            session_start();
        }

        $this->backupSession = $_SESSION ?? [];
        $this->backupServer = $_SERVER;

        $_SESSION = [];
        $_SERVER['SCRIPT_NAME'] = '/noblestock/index.php';
    }

    protected function tearDown(): void
    {
        $_SESSION = $this->backupSession;
        $_SERVER = $this->backupServer;
        parent::tearDown();
    }

    /**
     * テスト用に通知バッチ呼び出しを記録する.
     */
    private function createSpyLogic(): LimitLogic
    {
        return new class extends LimitLogic {
            public array $calledManagementNos = [];

            protected function runBatch(string $managementNo): void
            {
                $this->calledManagementNos[] = $managementNo;
            }
        };
    }

    /**
     * 閾値が0以下なら通知判定自体を行わず false を返すことを確認する.
     */
    public function testNoticeStockReturnsFalseWhenLowLimitIsDisabled(): void
    {
        SessionHelper::setPref('STOCK_LOW_LIMIT', 0);
        SessionHelper::setPref('STOCK_LOW_INTERVAL', 5);

        $logic = $this->createSpyLogic();
        $result = $logic->noticeStock([
            'management_no' => '4901234567894',
            'quantity' => 1,
            'STOCK_COUNT' => 0,
        ]);

        $this->assertFalse($result);
        $this->assertSame([], $logic->calledManagementNos);
    }

    /**
     * 現在在庫が閾値以上なら通知せず false を返すことを確認する.
     */
    public function testNoticeStockReturnsFalseWhenCurrentStockIsAboveOrEqualToLimit(): void
    {
        SessionHelper::setPref('STOCK_LOW_LIMIT', 10);
        SessionHelper::setPref('STOCK_LOW_INTERVAL', 5);

        $logic = $this->createSpyLogic();
        $result = $logic->noticeStock([
            'management_no' => '4901234567894',
            'quantity' => 10,
            'STOCK_COUNT' => 0,
        ]);

        $this->assertFalse($result);
        $this->assertSame([], $logic->calledManagementNos);
    }

    /**
     * 在庫0は無条件で通知対象となり、バッチ実行まで進むことを確認する.
     */
    public function testNoticeStockReturnsTrueAndRunsBatchWhenCurrentStockIsZero(): void
    {
        SessionHelper::setPref('STOCK_LOW_LIMIT', 10);
        SessionHelper::setPref('STOCK_LOW_INTERVAL', 5);

        $logic = $this->createSpyLogic();
        $result = $logic->noticeStock([
            'management_no' => '4901234567894',
            'quantity' => 0,
            'STOCK_COUNT' => 0,
        ]);

        $this->assertTrue($result);
        $this->assertSame(['4901234567894'], $logic->calledManagementNos);
    }

    /**
     * 1段階通知モードでは、直前在庫が閾値以上から閾値未満へ下がったとき通知する.
     */
    public function testNoticeStockReturnsTrueWhenSingleThresholdIsCrossed(): void
    {
        SessionHelper::setPref('STOCK_LOW_LIMIT', 10);
        SessionHelper::setPref('STOCK_LOW_INTERVAL', 0);

        $logic = $this->createSpyLogic();
        $result = $logic->noticeStock([
            'management_no' => '4901234567894',
            'quantity' => 9,
            'STOCK_COUNT' => 1,
        ]);

        $this->assertTrue($result);
        $this->assertSame(['4901234567894'], $logic->calledManagementNos);
    }

    /**
     * しきい値をまたいでいなければ、1段階通知モードでは通知しないことを確認する.
     */
    public function testNoticeStockReturnsFalseWhenSingleThresholdIsNotCrossed(): void
    {
        SessionHelper::setPref('STOCK_LOW_LIMIT', 10);
        SessionHelper::setPref('STOCK_LOW_INTERVAL', 0);

        $logic = $this->createSpyLogic();
        $result = $logic->noticeStock([
            'management_no' => '4901234567894',
            'quantity' => 9,
            'STOCK_COUNT' => 0,
        ]);

        $this->assertFalse($result);
        $this->assertSame([], $logic->calledManagementNos);
    }

    /**
     * 段階通知モードでは、いずれかの段階しきい値をまたいだとき通知する.
     */
    public function testNoticeStockReturnsTrueWhenSteppedThresholdIsCrossed(): void
    {
        SessionHelper::setPref('STOCK_LOW_LIMIT', 10);
        SessionHelper::setPref('STOCK_LOW_INTERVAL', 5);

        $logic = $this->createSpyLogic();
        $result = $logic->noticeStock([
            'management_no' => '4901234567894',
            'quantity' => 4,
            'STOCK_COUNT' => 2,
        ]);

        $this->assertTrue($result);
        $this->assertSame(['4901234567894'], $logic->calledManagementNos);
    }

    /**
     * しきい値をまたいでいなければ、段階通知モードでは通知しないことを確認する.
     */
    public function testNoticeStockReturnsFalseWhenSteppedThresholdIsNotCrossed(): void
    {
        SessionHelper::setPref('STOCK_LOW_LIMIT', 10);
        SessionHelper::setPref('STOCK_LOW_INTERVAL', 5);

        $logic = $this->createSpyLogic();
        $result = $logic->noticeStock([
            'management_no' => '4901234567894',
            'quantity' => 9,
            'STOCK_COUNT' => 0,
        ]);

        $this->assertFalse($result);
        $this->assertSame([], $logic->calledManagementNos);
    }
}
