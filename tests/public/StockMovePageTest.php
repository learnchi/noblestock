<?php

declare(strict_types=1);

use Noblestock\Logic\LogicConst;
use Noblestock\Logic\MessageConst;
use Tests\Support\Response;
use Tests\Support\WebTestCase;

final class StockMovePageTest extends WebTestCase
{
    // ログインセッションをクリア後、この画面にアクセスすると、indexに遷移する
    public function testStockMoveRedirectsToIndex(): void
    {
        $this->getClient()->get('index.php');

        $response = $this->getClient()->get('stock_move.php');
        $this->assertSame(200, $response->status, "Expected final 200 from {$response->url}");
        $this->assertStringContainsString('Location: index.php', $response->headers);
    }
    // ログインセッションをクリア後、この画面にアクセスすると、認証エラーメッセージ付きでindexに遷移する
    public function testStockMoveRedirectsToIndexWithSessionExpiredMessage(): void
    {
        $this->getClient()->get('index.php');

        $response = $this->getClient()->get('stock_move.php');
        $this->assertSame(200, $response->status, "Expected final 200 from {$response->url}");
        $this->assertStringContainsString('Location: index.php', $response->headers);
        $this->assertStringContainsString(MessageConst::MSG_INF_AUTH_002, $response->body);
    }
    public function testStockMoveDisplays(): void
    {
        $this->loginAsAdmin();
        $response = $this->getClient()->get('stock_move.php');
        $this->assertOk($response);
    }

    // この画面に権限があるユーザー[perm05]で200で表示される
    public function testStockMoveDisplaysForPerm05(): void
    {
        $this->loginAs('perm05', 'perm0500');

        $response = $this->getClient()->get('stock_move.php');
        $this->assertOk($response);
    }

    // この画面に権限がないユーザーnoauthで403エラーとなる
    public function testStockMoveReturns403ForNoAuth(): void
    {
        $this->loginAs('noauth', 'noauth00');

        $response = $this->getClient()->get('stock_move.php');
        $this->assertSame(403, $response->status, "Expected 403 from {$response->url}");
    }

    // 移動画面は移動元と移動先の 2 店舗が揃うまで、店舗バーコード入力を案内する。
    public function testGuideMessageWithoutLocationPromptsLocationBarcode(): void
    {
        $this->prepareStockMoveAsAdmin();

        $response = $this->getClient()->get('stock_move.php');
        $this->assertOk($response);

        $this->assertGuideMessage($response, MessageConst::MSG_INF_BARCODE_004);
    }

    public function testGuideMessageWithOneLocationStillPromptsLocationBarcode(): void
    {
        $this->prepareStockMoveAsAdmin();

        $response = $this->postBarcode('stock_move.php', LogicConst::CMD_LOC . '10');

        $this->assertGuideMessage($response, MessageConst::MSG_INF_BARCODE_004);
    }

    // 移動元と移動先が揃った後は、商品バーコード入力へ進む。
    public function testGuideMessageWithTwoLocationsPromptsProductBarcode(): void
    {
        $this->prepareStockMoveAsAdmin();
        $this->postBarcode('stock_move.php', LogicConst::CMD_LOC . '10');

        $response = $this->postBarcode('stock_move.php', LogicConst::CMD_LOC . '20');

        $this->assertGuideMessage($response, MessageConst::MSG_INF_BARCODE_003);
    }

    // 商品は選択済みだが数量が未確定なら、数量バーコード入力を案内する。
    public function testGuideMessageWithProductAndNoQuantityPromptsQuantityBarcode(): void
    {
        $this->prepareStockMoveAsAdmin();
        $this->postBarcode('stock_move.php', LogicConst::CMD_LOC . '10');
        $this->postBarcode('stock_move.php', LogicConst::CMD_LOC . '20');

        $response = $this->postBarcode('stock_move.php', 'ABC001');

        $this->assertGuideMessage($response, MessageConst::MSG_INF_BARCODE_005);
    }

    // 数量バーコードを読み始めた後は、次の商品か完了バーコードへ進む案内に変わる。
    public function testGuideMessageWithTypedQuantityPromptsProductOrCompleteBarcode(): void
    {
        $this->prepareStockMoveAsAdmin();
        $this->postBarcode('stock_move.php', LogicConst::CMD_LOC . '10');
        $this->postBarcode('stock_move.php', LogicConst::CMD_LOC . '20');
        $this->postBarcode('stock_move.php', 'ABC001');
        $this->postBarcode('stock_move.php', LogicConst::CMD_VAL . '1');

        $response = $this->postBarcode('stock_move.php', LogicConst::CMD_VAL . '2');

        $this->assertGuideMessage($response, MessageConst::MSG_INF_BARCODE_006);
    }

    // 移動元 -> 移動先 -> 商品 -> 数量 -> 完了 の順に POST し、2桁数量の移動が成立することを確認する。
    // 画面表示だけでなく、移動元/移動先の stocks と histories 2 件の更新まで見ておく。
    public function testStockMoveCompleteTransfersTwoDigitQuantity(): void
    {
        $this->prepareStockMoveAsAdmin();

        $fromName = $this->readLocationName(10);
        $toName = $this->readLocationName(20);
        $this->assertSame(70, $this->readStockQuantity('ABC001', 10));
        $this->assertNull($this->readStockQuantity('ABC001', 20));
        $beforeFromHistoryCount = $this->countTransferHistory('ABC001', $fromName, 5, 12);
        $beforeToHistoryCount = $this->countTransferHistory('ABC001', $toName, 6, 12);

        $this->postBarcode('stock_move.php', LogicConst::CMD_LOC . '10');
        $this->postBarcode('stock_move.php', LogicConst::CMD_LOC . '20');
        $this->postBarcode('stock_move.php', 'ABC001');
        $this->postBarcode('stock_move.php', LogicConst::CMD_VAL . '1');

        $preview = $this->postBarcode('stock_move.php', LogicConst::CMD_VAL . '2');
        $this->assertStringContainsString('ABC001', $preview->body);
        $this->assertStringContainsString('12', $preview->body);

        $response = $this->postBarcode('stock_move.php', LogicConst::CMD_COMPLETE);

        $this->assertSame(58, $this->readStockQuantity('ABC001', 10));
        $this->assertSame(12, $this->readStockQuantity('ABC001', 20));
        $this->assertSame($beforeFromHistoryCount + 1, $this->countTransferHistory('ABC001', $fromName, 5, 12));
        $this->assertSame($beforeToHistoryCount + 1, $this->countTransferHistory('ABC001', $toName, 6, 12));
        $this->assertStringContainsString('ABC001', $response->body);
        $this->assertStringContainsString('12', $response->body);
        $this->assertStringContainsString($fromName, $response->body);
        $this->assertStringContainsString($toName, $response->body);
    }

    // キャンセルは入力途中の状態だけを破棄し、移動元/移動先の在庫は変更しないことを確認する。
    public function testStockMoveCancelClearsSessionWithoutChangingStock(): void
    {
        $this->prepareStockMoveAsAdmin();

        $this->assertSame(70, $this->readStockQuantity('ABC001', 10));
        $this->assertNull($this->readStockQuantity('ABC001', 20));

        $this->postBarcode('stock_move.php', LogicConst::CMD_LOC . '10');
        $this->postBarcode('stock_move.php', LogicConst::CMD_LOC . '20');
        $this->postBarcode('stock_move.php', 'ABC001');
        $this->postBarcode('stock_move.php', LogicConst::CMD_VAL . '1');
        $this->postBarcode('stock_move.php', LogicConst::CMD_VAL . '2');

        $response = $this->postBarcode('stock_move.php', LogicConst::CMD_CANCEL);

        $this->assertSame(70, $this->readStockQuantity('ABC001', 10));
        $this->assertNull($this->readStockQuantity('ABC001', 20));
        $this->assertStringNotContainsString('ABC001', $response->body);
    }

    // 取り消しは最後に読み込んだ商品を 1 件戻す操作なので、直前の商品だけ消えることを確認する。
    public function testStockMoveUndoRemovesLastSelectedProduct(): void
    {
        $this->prepareStockMoveAsAdmin();

        $this->postBarcode('stock_move.php', LogicConst::CMD_LOC . '10');
        $this->postBarcode('stock_move.php', LogicConst::CMD_LOC . '20');
        $this->postBarcode('stock_move.php', 'ABC001');
        $this->postBarcode('stock_move.php', 'ABC002');

        $response = $this->postBarcode('stock_move.php', LogicConst::CMD_UNDO);
        $this->assertStringContainsString('ABC001', $response->body);
        $this->assertStringNotContainsString('ABC002', $response->body);
    }

    // 移動元在庫が 0 の商品を選ぶと、移動前の段階で在庫なしエラーが出ることを確認する。
    public function testStockMoveShowsErrorWhenFromLocationStockIsZero(): void
    {
        $this->prepareStockMoveAsAdmin();
        $this->postBarcode('stock_move.php', LogicConst::CMD_LOC . '20');
        $this->postBarcode('stock_move.php', LogicConst::CMD_LOC . '10');

        $response = $this->postBarcode('stock_move.php', 'ABC003');

        $this->assertFlushErrorMessage($response, MessageConst::MSG_VAL_BARCODE_009);
    }

    // 移動元在庫より多い数量を指定すると、在庫不足エラーが出て DB は変わらないことを確認する。
    public function testStockMoveShowsErrorWhenQuantityExceedsFromLocationStock(): void
    {
        $this->prepareStockMoveAsAdmin();
        $this->assertSame(70, $this->readStockQuantity('ABC001', 10));
        $this->assertNull($this->readStockQuantity('ABC001', 20));

        $this->postBarcode('stock_move.php', LogicConst::CMD_LOC . '10');
        $this->postBarcode('stock_move.php', LogicConst::CMD_LOC . '20');
        $this->postBarcode('stock_move.php', 'ABC001');
        $this->postBarcode('stock_move.php', LogicConst::CMD_VAL . '9');
        $this->postBarcode('stock_move.php', LogicConst::CMD_VAL . '9');

        $response = $this->postBarcode('stock_move.php', LogicConst::CMD_COMPLETE);

        $this->assertFlushErrorMessage($response, MessageConst::MSG_VAL_BARCODE_010);
        $this->assertSame(70, $this->readStockQuantity('ABC001', 10));
        $this->assertNull($this->readStockQuantity('ABC001', 20));
    }

    // 移動画面は locationList マスタをセッションから読む前提なので、menu.php を一度通してから開始する。
    // あわせて biz501 の作業セッションを消し、各テストを独立させる。
    private function prepareStockMoveAsAdmin(): void
    {
        \Tests\Support\TestDatabase::seed();
        $this->loginAsAdmin();
        $menu = $this->getClient()->get('menu.php');
        $this->assertOk($menu);
        $this->delSessionStructData('biz501', null, 'stock_move.php');
    }

    private function postBarcode(string $path, string $barcode): Response
    {
        $response = $this->getClient()->post($path, [
            'barcode' => $barcode,
        ]);
        $this->assertOk($response);

        return $response;
    }

    // 画面上の info アラートに、期待した案内文が出ていることを確認する。
    private function assertGuideMessage(Response $response, string $expectedMessage): void
    {
        $xpath = new DOMXPath($response->dom());
        $nodes = $xpath->query("//div[contains(@class,'alert-info')]/div");
        $this->assertNotFalse($nodes);
        $this->assertGreaterThan(0, $nodes->length, 'Guide message alert was not found.');

        $actual = trim((string) ($nodes->item(0)?->textContent ?? ''));
        $this->assertSame($expectedMessage, $actual);
    }

    // 移動画面では入力エラーが danger アラートに出るので、その文言を直接確認する。
    private function assertFlushErrorMessage(Response $response, string $expectedMessage): void
    {
        $xpath = new DOMXPath($response->dom());
        $nodes = $xpath->query("//div[contains(@class,'alert-danger')]/div");
        $this->assertNotFalse($nodes);
        $this->assertGreaterThan(0, $nodes->length, 'Flush error alert was not found.');

        $actual = trim((string) ($nodes->item(0)?->textContent ?? ''));
        $this->assertSame($expectedMessage, $actual);
    }

    // stocks テーブルを直接見て、対象店舗の在庫数が実際に変わったかを確認する。
    // レコードが存在しない場合は null を返して、未登録の判定に使う。
    private function readStockQuantity(string $managementNo, int $locationId): ?int
    {
        $stmt = $this->createPdo()->prepare(
            'SELECT quantity FROM stocks WHERE management_no = :management_no AND location_id = :location_id'
        );
        $stmt->execute([
            'management_no' => $managementNo,
            'location_id' => $locationId,
        ]);

        $value = $stmt->fetchColumn();
        if ($value === false) {
            return null;
        }

        return (int) $value;
    }

    // 移動完了時には histories に移動元(5) と移動先(6) の履歴が 1 件ずつ追加されるので、その件数差分を見る。
    private function countTransferHistory(string $managementNo, string $locationName, int $historyKbn, int $moveStock): int
    {
        $stmt = $this->createPdo()->prepare(
            'SELECT COUNT(*) FROM histories
             WHERE history_kbn = :history_kbn
               AND management_no = :management_no
               AND location_name = :location_name
               AND move_stock = :move_stock'
        );
        $stmt->execute([
            'history_kbn' => $historyKbn,
            'management_no' => $managementNo,
            'location_name' => $locationName,
            'move_stock' => $moveStock,
        ]);

        return (int) $stmt->fetchColumn();
    }

    // テストデータ上の店舗名を DB から取得して、履歴照合の条件に使う。
    private function readLocationName(int $locationId): string
    {
        $stmt = $this->createPdo()->prepare(
            'SELECT location_name FROM locations WHERE id = :id'
        );
        $stmt->execute([
            'id' => $locationId,
        ]);

        $value = $stmt->fetchColumn();
        $this->assertNotFalse($value, "Location {$locationId} was not found.");

        return (string) $value;
    }

    // DB確認系ヘルパーで使い回す PDO を遅延生成する。
    private function createPdo(): PDO
    {
        static $pdo = null;
        if ($pdo instanceof PDO) {
            return $pdo;
        }

        $configPath = dirname(__DIR__, 2) . '/config/dbconfig.ini';
        $config = parse_ini_file($configPath);
        $this->assertIsArray($config, 'Failed to read dbconfig.ini for StockMovePageTest.');
        foreach (['dbhost', 'dbuser', 'dbpass', 'dbname'] as $requiredKey) {
            $this->assertArrayHasKey($requiredKey, $config, "Missing {$requiredKey} in dbconfig.ini.");
        }

        $dsn = sprintf(
            'mysql:host=%s;dbname=%s;charset=utf8mb4',
            (string) $config['dbhost'],
            (string) $config['dbname']
        );

        $pdo = new PDO(
            $dsn,
            (string) $config['dbuser'],
            (string) $config['dbpass'],
            [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
        );

        return $pdo;
    }
    // セッション期限切れ時に認証情報と画面用セッションが破棄されることを確認する
    public function testStockMoveClearsSessionDataWhenTimedOut(): void
    {
        $this->assertTimedOutProtectedPageClearsSessionDataAndShowsExpiredMessage('stock_move.php');
    }
}
