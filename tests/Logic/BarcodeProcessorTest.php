<?php

declare(strict_types=1);

use Noblestock\Logic\BarcodeProcessor;
use Noblestock\Logic\LogicConst;
use PHPUnit\Framework\TestCase;
use Studiogau\Chandra\Auth\AuthService;
use Studiogau\Chandra\Auth\LoginUser;
use Studiogau\Chandra\Auth\UserRepositoryInterface;
use Studiogau\Chandra\Support\SessionHelper;

require_once dirname(__DIR__, 2) . '/Logic/BarcodeProcessor.php';

final class BarcodeProcessorTest extends TestCase
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
        SessionHelper::setMaster([]);
    }

    protected function tearDown(): void
    {
        $_SESSION = $this->backupSession;
        $_SERVER = $this->backupServer;
        parent::tearDown();
    }

    /**
     * 空文字入力を処理し、何も実行されず通常完了の結果が返ることを確認する。
     */
    public function testHandleReturnsDoneWhenInputIsBlank(): void
    {
        $processor = new BarcodeProcessor($this->createAuthService([]), 'FUNC001');
        $result = $processor->handle('   ');

        $this->assertFalse($result->isRedirect());
        $this->assertFalse($result->hasResult());
    }

    /**
     * メニューコマンドを処理し、menu.phpへのリダイレクト結果が返ることを確認する。
     */
    public function testHandleReturnsRedirectForMenuCommand(): void
    {
        $processor = new BarcodeProcessor($this->createAuthService([]), 'FUNC001');
        $result = $processor->handle(LogicConst::CMD_MENU);

        $this->assertTrue($result->isRedirect());
        $this->assertSame('menu.php', $result->getRedirectUrl());
    }

    /**
     * UNDOコマンドを処理し、直近商品のSTOCK_COUNTが空文字に戻されることを確認する。
     */
    public function testHandleUndoInStockModeClearsLastStockCount(): void
    {
        SessionHelper::setData('FUNC001', 'proData', [
            ['management_no' => '4901234567894', 'STOCK_COUNT' => '2'],
        ]);
        SessionHelper::delData('FUNC001', 'valData');

        $processor = new BarcodeProcessor($this->createAuthService([]), 'FUNC001', BarcodeProcessor::MODE_STOCK_OUT);
        $processor->handle(LogicConst::CMD_UNDO);

        $proData = SessionHelper::getData('FUNC001', 'proData');
        $this->assertSame('', $proData[0]['STOCK_COUNT']);
    }

    /**
     * CANCELコマンドを処理し、作業中のセッションデータがすべて削除されることを確認する。
     */
    public function testHandleCancelInStockModeClearsSessionData(): void
    {
        SessionHelper::setData('FUNC001', 'proData', [['management_no' => '4901234567894']]);
        SessionHelper::setData('FUNC001', 'valData', '3');
        SessionHelper::setData('FUNC001', 'locData', ['location_id' => 1]);
        SessionHelper::setData('FUNC001', 'locCount', 2);

        $processor = new BarcodeProcessor($this->createAuthService([]), 'FUNC001', BarcodeProcessor::MODE_STOCK_OUT);
        $processor->handle(LogicConst::CMD_CANCEL);

        $this->assertNull(SessionHelper::getData('FUNC001', 'proData'));
        $this->assertNull(SessionHelper::getData('FUNC001', 'valData'));
        $this->assertNull(SessionHelper::getData('FUNC001', 'locData'));
        $this->assertNull(SessionHelper::getData('FUNC001', 'locCount'));
    }

    /**
     * CHECKモードでCOMPLETEコマンドを処理し、通常完了の結果が返ることを確認する。
     */
    public function testHandleCompleteInCheckModeReturnsDone(): void
    {
        $processor = new BarcodeProcessor($this->createAuthService([]), 'FUNC001', BarcodeProcessor::MODE_CHECK);
        $result = $processor->handle(LogicConst::CMD_COMPLETE);

        $this->assertFalse($result->isRedirect());
        $this->assertFalse($result->hasResult());
    }

    /**
     * 数値入力コマンドを処理し、入力した1桁の値がvalDataとしてセッションに保存されることを確認する。
     */
    public function testHandleValueInputStoresNumericValue(): void
    {
        SessionHelper::setData('FUNC001', 'proData', [
            ['management_no' => '4901234567894', 'STOCK_COUNT' => ''],
        ]);
        SessionHelper::delData('FUNC001', 'valData');

        $processor = new BarcodeProcessor($this->createAuthService([]), 'FUNC001', BarcodeProcessor::MODE_STOCK_OUT);
        $processor->handle(LogicConst::CMD_VAL . '3');

        $this->assertSame('3', SessionHelper::getData('FUNC001', 'valData'));
    }

    /**
     * CHECKモードでロケーション入力コマンドを処理し、対象ロケーション情報がセッションに保存されることを確認する。
     */
    public function testHandleLocationInputInCheckModeStoresLocation(): void
    {
        SessionHelper::setMaster([
            'locationList' => [
                ['id' => 1, 'location_name' => '本店'],
            ],
        ]);

        $processor = new BarcodeProcessor($this->createAuthService([]), 'FUNC001', BarcodeProcessor::MODE_CHECK);
        $processor->handle(LogicConst::CMD_LOC . '1');

        $locData = SessionHelper::getData('FUNC001', 'locData');
        $this->assertSame(1, $locData['location_id']);
        $this->assertSame('本店', $locData['location_name']);
    }

    private function createAuthService(array $permissions): AuthService
    {
        $repo = new class implements UserRepositoryInterface {
            public function findByCredentials(string $userId, string $password): ?array
            {
                return null;
            }
        };

        $auth = new AuthService($repo);
        $auth->setCurrentUser(new LoginUser('tester', 'Tester', $permissions));

        return $auth;
    }
}

