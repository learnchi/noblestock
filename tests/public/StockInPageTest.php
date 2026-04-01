<?php

declare(strict_types=1);

use Noblestock\Logic\LogicConst;
use Noblestock\Logic\MessageConst;
use Tests\Support\Response;
use Tests\Support\WebTestCase;

final class StockInPageTest extends WebTestCase
{
    // ログインセッションをクリア後、この画面にアクセスすると、indexに遷移する
    public function testStockInRedirectsToIndex(): void
    {
        $this->getClient()->get('index.php');

        $response = $this->getClient()->get('stock_in.php');
        $this->assertSame(200, $response->status, "Expected final 200 from {$response->url}");
        $this->assertStringContainsString('Location: index.php', $response->headers);
    }
    // ログインセッションをクリア後、この画面にアクセスすると、認証エラーメッセージ付きでindexに遷移する
    public function testStockInRedirectsToIndexWithSessionExpiredMessage(): void
    {
        $this->getClient()->get('index.php');

        $response = $this->getClient()->get('stock_in.php');
        $this->assertSame(200, $response->status, "Expected final 200 from {$response->url}");
        $this->assertStringContainsString('Location: index.php', $response->headers);
        $this->assertStringContainsString(MessageConst::MSG_INF_AUTH_002, $response->body);
    }
    public function testStockInDisplays(): void
    {
        $this->loginAsAdmin();
        $response = $this->getClient()->get('stock_in.php');
        $this->assertOk($response);
    }

    // この画面に権限があるユーザー[perm03]で200で表示される
    public function testStockInDisplaysForPerm03(): void
    {
        $this->loginAs('perm03', 'perm0300');

        $response = $this->getClient()->get('stock_in.php');
        $this->assertOk($response);
    }

    // この画面に権限がないユーザーnoauthで403エラーとなる
    public function testStockInReturns403ForNoAuth(): void
    {
        $this->loginAs('noauth', 'noauth00');

        $response = $this->getClient()->get('stock_in.php');
        $this->assertSame(403, $response->status, "Expected 403 from {$response->url}");
    }

    // ガイドメッセージは、店舗未選択なら「店舗バーコード入力」を案内する。
    public function testGuideMessageWithoutLocationPromptsLocationBarcode(): void
    {
        $this->prepareStockInAsAdmin();

        $response = $this->getClient()->get('stock_in.php');
        $this->assertOk($response);

        $this->assertGuideMessage($response, MessageConst::MSG_INF_BARCODE_004);
    }

    // 店舗だけ決まっていて商品がまだ無い状態では、「商品バーコード入力」を案内する。
    public function testGuideMessageWithLocationOnlyPromptsProductBarcode(): void
    {
        $this->prepareStockInAsAdmin();

        $response = $this->postBarcode('stock_in.php', LogicConst::CMD_LOC . '10');

        $this->assertGuideMessage($response, MessageConst::MSG_INF_BARCODE_003);
    }

    // 商品は選択済みだが数量が未確定なら、「数量バーコード入力」を案内する。
    public function testGuideMessageWithProductAndNoQuantityPromptsQuantityBarcode(): void
    {
        $this->prepareStockInAsAdmin();
        $this->postBarcode('stock_in.php', LogicConst::CMD_LOC . '10');

        $response = $this->postBarcode('stock_in.php', 'ABC001');

        $this->assertGuideMessage($response, MessageConst::MSG_INF_BARCODE_005);
    }

    // 数量バーコードを読み始めた後は、次の商品か完了バーコードへ進む案内に変わる。
    public function testGuideMessageWithTypedQuantityPromptsProductOrCompleteBarcode(): void
    {
        $this->prepareStockInAsAdmin();
        $this->postBarcode('stock_in.php', LogicConst::CMD_LOC . '10');
        $this->postBarcode('stock_in.php', 'ABC001');
        $this->postBarcode('stock_in.php', LogicConst::CMD_VAL . '1');

        $response = $this->postBarcode('stock_in.php', LogicConst::CMD_VAL . '2');

        $this->assertGuideMessage($response, MessageConst::MSG_INF_BARCODE_006);
    }

    // 店舗 -> 商品 -> 数量 -> 完了 の順に POST し、2桁数量の入庫が成立することを確認する。
    // 画面表示だけでなく、stocks と histories の更新まで見ておく。
    public function testStockInCompleteAddsTwoDigitQuantity(): void
    {
        $this->prepareStockInAsAdmin();

        $locationName = $this->readLocationName(10);
        $this->assertNull($this->readStockQuantity('ABC003', 10));
        $beforeHistoryCount = $this->countStockInHistory('ABC003', $locationName, 12);

        $this->postBarcode('stock_in.php', LogicConst::CMD_LOC . '10');
        $this->postBarcode('stock_in.php', 'ABC003');
        $this->postBarcode('stock_in.php', LogicConst::CMD_VAL . '1');

        $preview = $this->postBarcode('stock_in.php', LogicConst::CMD_VAL . '2');
        $this->assertStringContainsString('ABC003', $preview->body);
        $this->assertStringContainsString('12', $preview->body);

        $response = $this->postBarcode('stock_in.php', LogicConst::CMD_COMPLETE);

        $this->assertSame(12, $this->readStockQuantity('ABC003', 10));
        $this->assertSame($beforeHistoryCount + 1, $this->countStockInHistory('ABC003', $locationName, 12));
        $this->assertStringContainsString('ABC003', $response->body);
        $this->assertStringContainsString('12', $response->body);
    }

    // キャンセルは入力途中の状態だけを破棄し、在庫自体は変更しないことを確認する。
    public function testStockInCancelClearsSessionWithoutChangingStock(): void
    {
        $this->prepareStockInAsAdmin();

        $this->assertNull($this->readStockQuantity('ABC003', 10));

        $this->postBarcode('stock_in.php', LogicConst::CMD_LOC . '10');
        $this->postBarcode('stock_in.php', 'ABC003');
        $this->postBarcode('stock_in.php', LogicConst::CMD_VAL . '1');
        $this->postBarcode('stock_in.php', LogicConst::CMD_VAL . '2');

        $response = $this->postBarcode('stock_in.php', LogicConst::CMD_CANCEL);

        $this->assertNull($this->readStockQuantity('ABC003', 10));
        $this->assertStringNotContainsString('ABC003', $response->body);
    }

    // 取り消しは最後に読み込んだ商品を 1 件戻す操作なので、直前の商品だけ消えることを確認する。
    public function testStockInUndoRemovesLastSelectedProduct(): void
    {
        $this->prepareStockInAsAdmin();

        $this->postBarcode('stock_in.php', LogicConst::CMD_LOC . '10');
        $this->postBarcode('stock_in.php', 'ABC001');
        $this->postBarcode('stock_in.php', 'ABC002');

        $response = $this->postBarcode('stock_in.php', LogicConst::CMD_UNDO);
        $this->assertStringContainsString('ABC001', $response->body);
        $this->assertStringNotContainsString('ABC002', $response->body);
    }

    // 入庫画面は locationList マスタをセッションから読む前提なので、menu.php を一度通してから開始する。
    // あわせて biz101 の作業セッションを消し、各テストを独立させる。
    private function prepareStockInAsAdmin(): void
    {
        \Tests\Support\TestDatabase::seed();
        $this->loginAsAdmin();
        $menu = $this->getClient()->get('menu.php');
        $this->assertOk($menu);
        $this->delSessionStructData('biz101', null, 'stock_in.php');
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

    // stocks テーブルを直接見て、対象店舗の在庫数が実際に変わったかを確認する。
    // レコードが存在しない場合は null を返して、未入庫の判定に使う。
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

    // 入庫完了時には histories に入庫履歴も 1 件追加されるので、その件数差分を確認する。
    private function countStockInHistory(string $managementNo, string $locationName, int $stockIn): int
    {
        $stmt = $this->createPdo()->prepare(
            'SELECT COUNT(*) FROM histories
             WHERE history_kbn = 0
               AND management_no = :management_no
               AND location_name = :location_name
               AND stock_in = :stock_in'
        );
        $stmt->execute([
            'management_no' => $managementNo,
            'location_name' => $locationName,
            'stock_in' => $stockIn,
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
        $this->assertIsArray($config, 'Failed to read dbconfig.ini for StockInPageTest.');
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
    public function testStockInClearsSessionDataWhenTimedOut(): void
    {
        $this->assertTimedOutProtectedPageClearsSessionDataAndShowsExpiredMessage('stock_in.php');
    }
}
