<?php

declare(strict_types=1);

use Noblestock\Logic\LogicConst;
use Noblestock\Logic\MessageConst;
use Tests\Support\Response;
use Tests\Support\WebTestCase;

final class StockOutPageTest extends WebTestCase
{
    // ログインセッションをクリア後、この画面にアクセスすると、indexに遷移する
    public function testStockOutRedirectsToIndex(): void
    {
        $this->getClient()->get('index.php');

        $response = $this->getClient()->get('stock_out.php');
        $this->assertSame(200, $response->status, "Expected final 200 from {$response->url}");
        $this->assertStringContainsString('Location: index.php', $response->headers);
    }
    // ログインセッションをクリア後、この画面にアクセスすると、認証エラーメッセージ付きでindexに遷移する
    public function testStockOutRedirectsToIndexWithSessionExpiredMessage(): void
    {
        $this->getClient()->get('index.php');

        $response = $this->getClient()->get('stock_out.php');
        $this->assertSame(200, $response->status, "Expected final 200 from {$response->url}");
        $this->assertStringContainsString('Location: index.php', $response->headers);
        $this->assertStringContainsString(MessageConst::MSG_INF_AUTH_002, $response->body);
    }
    public function testStockOutDisplays(): void
    {
        $this->loginAsAdmin();
        $response = $this->getClient()->get('stock_out.php');
        $this->assertOk($response);
    }

    // この画面に権限があるユーザー[perm04]で200で表示される
    public function testStockOutDisplaysForPerm04(): void
    {
        $this->loginAs('perm04', 'perm0400');

        $response = $this->getClient()->get('stock_out.php');
        $this->assertOk($response);
    }

    // この画面に権限がないユーザーnoauthで403エラーとなる
    public function testStockOutReturns403ForNoAuth(): void
    {
        $this->loginAs('noauth', 'noauth00');

        $response = $this->getClient()->get('stock_out.php');
        $this->assertSame(403, $response->status, "Expected 403 from {$response->url}");
    }

    // ガイドメッセージは、店舗未選択なら「店舗バーコード入力」を案内する。
    public function testGuideMessageWithoutLocationPromptsLocationBarcode(): void
    {
        $this->prepareStockOutAsAdmin();

        $response = $this->getClient()->get('stock_out.php');
        $this->assertOk($response);

        $this->assertGuideMessage($response, MessageConst::MSG_INF_BARCODE_004);
    }

    // 店舗だけ決まっていて商品がまだ無い状態では、「商品バーコード入力」を案内する。
    public function testGuideMessageWithLocationOnlyPromptsProductBarcode(): void
    {
        $this->prepareStockOutAsAdmin();

        $response = $this->postBarcode('stock_out.php', LogicConst::CMD_LOC . '20');

        $this->assertGuideMessage($response, MessageConst::MSG_INF_BARCODE_003);
    }

    // 商品は選択済みだが数量が未確定なら、「数量バーコード入力」を案内する。
    public function testGuideMessageWithProductAndNoQuantityPromptsQuantityBarcode(): void
    {
        $this->prepareStockOutAsAdmin();
        $this->postBarcode('stock_out.php', LogicConst::CMD_LOC . '20');

        $response = $this->postBarcode('stock_out.php', 'ABC002');

        $this->assertGuideMessage($response, MessageConst::MSG_INF_BARCODE_005);
    }

    // 数量バーコードを読み始めた後は、次の商品か完了バーコードへ進む案内に変わる。
    public function testGuideMessageWithTypedQuantityPromptsProductOrCompleteBarcode(): void
    {
        $this->prepareStockOutAsAdmin();
        $this->postBarcode('stock_out.php', LogicConst::CMD_LOC . '20');
        $this->postBarcode('stock_out.php', 'ABC002');
        $this->postBarcode('stock_out.php', LogicConst::CMD_VAL . '1');

        $response = $this->postBarcode('stock_out.php', LogicConst::CMD_VAL . '2');

        $this->assertGuideMessage($response, MessageConst::MSG_INF_BARCODE_006);
    }

    // 店舗 -> 商品 -> 数量 -> 完了 の順に POST し、2桁数量の出庫が成立することを確認する。
    // 画面表示だけでなく、stocks と histories の更新まで見ておく。
    public function testStockOutCompleteSubtractsTwoDigitQuantity(): void
    {
        $this->prepareStockOutAsAdmin();

        $locationName = $this->readLocationName(20);
        $this->assertSame(300, $this->readStockQuantity('ABC002', 20));
        $beforeHistoryCount = $this->countStockOutHistory('ABC002', $locationName, 12);

        $this->postBarcode('stock_out.php', LogicConst::CMD_LOC . '20');
        $this->postBarcode('stock_out.php', 'ABC002');
        $this->postBarcode('stock_out.php', LogicConst::CMD_VAL . '1');

        $preview = $this->postBarcode('stock_out.php', LogicConst::CMD_VAL . '2');
        $this->assertStringContainsString('ABC002', $preview->body);
        $this->assertStringContainsString('12', $preview->body);

        $response = $this->postBarcode('stock_out.php', LogicConst::CMD_COMPLETE);

        $this->assertSame(288, $this->readStockQuantity('ABC002', 20));
        $this->assertSame($beforeHistoryCount + 1, $this->countStockOutHistory('ABC002', $locationName, 12));
        $this->assertStringContainsString('ABC002', $response->body);
        $this->assertStringContainsString('12', $response->body);
    }

    // キャンセルは入力途中の状態だけを破棄し、在庫自体は変更しないことを確認する。
    public function testStockOutCancelClearsSessionWithoutChangingStock(): void
    {
        $this->prepareStockOutAsAdmin();

        $this->assertSame(300, $this->readStockQuantity('ABC002', 20));

        $this->postBarcode('stock_out.php', LogicConst::CMD_LOC . '20');
        $this->postBarcode('stock_out.php', 'ABC002');
        $this->postBarcode('stock_out.php', LogicConst::CMD_VAL . '1');
        $this->postBarcode('stock_out.php', LogicConst::CMD_VAL . '2');

        $response = $this->postBarcode('stock_out.php', LogicConst::CMD_CANCEL);

        $this->assertSame(300, $this->readStockQuantity('ABC002', 20));
        $this->assertStringNotContainsString('ABC002', $response->body);
    }

    // 取り消しは最後に読み込んだ商品を 1 件戻す操作なので、直前の商品だけ消えることを確認する。
    public function testStockOutUndoRemovesLastSelectedProduct(): void
    {
        $this->prepareStockOutAsAdmin();

        $this->postBarcode('stock_out.php', LogicConst::CMD_LOC . '20');
        $this->postBarcode('stock_out.php', 'ABC001');
        $this->postBarcode('stock_out.php', 'ABC002');

        $response = $this->postBarcode('stock_out.php', LogicConst::CMD_UNDO);
        $this->assertStringContainsString('ABC001', $response->body);
        $this->assertStringNotContainsString('ABC002', $response->body);
    }

    // 店舗在庫が 0 の商品を選ぶと、出庫前の段階で在庫なしエラーが出ることを確認する。
    public function testStockOutShowsErrorWhenLocationStockIsZero(): void
    {
        $this->prepareStockOutAsAdmin();
        $this->postBarcode('stock_out.php', LogicConst::CMD_LOC . '20');

        $response = $this->postBarcode('stock_out.php', 'ABC003');

        $this->assertFlushErrorMessage($response, MessageConst::MSG_VAL_BARCODE_009);
    }

    // 店舗在庫より多い数量を指定すると、在庫不足エラーが出て DB は変わらないことを確認する。
    public function testStockOutShowsErrorWhenQuantityExceedsLocationStock(): void
    {
        $this->prepareStockOutAsAdmin();
        $this->assertSame(70, $this->readStockQuantity('ABC001', 10));

        $this->postBarcode('stock_out.php', LogicConst::CMD_LOC . '10');
        $this->postBarcode('stock_out.php', 'ABC001');
        $this->postBarcode('stock_out.php', LogicConst::CMD_VAL . '9');
        $this->postBarcode('stock_out.php', LogicConst::CMD_VAL . '9');

        $response = $this->postBarcode('stock_out.php', LogicConst::CMD_COMPLETE);

        $this->assertFlushErrorMessage($response, MessageConst::MSG_VAL_BARCODE_010);
        $this->assertSame(70, $this->readStockQuantity('ABC001', 10));
    }

    // 出庫画面は locationList マスタをセッションから読む前提なので、menu.php を一度通してから開始する。
    // あわせて biz201 の作業セッションを消し、各テストを独立させる。
    private function prepareStockOutAsAdmin(): void
    {
        \Tests\Support\TestDatabase::seed();
        $this->loginAsAdmin();
        $menu = $this->getClient()->get('menu.php');
        $this->assertOk($menu);
        $this->delSessionStructData('biz201', null, 'stock_out.php');
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

    // 出庫画面では入力エラーが danger アラートに出るので、その文言を直接確認する。
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

    // 出庫完了時には histories に出庫履歴も 1 件追加されるので、その件数差分を確認する。
    private function countStockOutHistory(string $managementNo, string $locationName, int $quantity): int
    {
        $stmt = $this->createPdo()->prepare(
            'SELECT COUNT(*) FROM histories
             WHERE history_kbn = 1
               AND management_no = :management_no
               AND location_name = :location_name
               AND quantity = :quantity'
        );
        $stmt->execute([
            'management_no' => $managementNo,
            'location_name' => $locationName,
            'quantity' => $quantity,
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
        $this->assertIsArray($config, 'Failed to read dbconfig.ini for StockOutPageTest.');
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
    public function testStockOutClearsSessionDataWhenTimedOut(): void
    {
        $this->assertTimedOutProtectedPageClearsSessionDataAndShowsExpiredMessage('stock_out.php');
    }
}
