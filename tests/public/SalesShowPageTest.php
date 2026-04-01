<?php

declare(strict_types=1);

use Noblestock\Logic\MessageConst;
use Tests\Support\Response;
use Tests\Support\WebClient;
use Tests\Support\WebTestCase;

final class SalesShowPageTest extends WebTestCase
{
    // ログインしていない状態では sales_show.php に直接入れず、index.php に戻される
    public function testSalesShowRedirectsToIndex(): void
    {
        $this->getClient()->get('index.php');

        $response = $this->getClient()->get('sales_show.php');
        $this->assertSame(200, $response->status, "Expected final 200 from {$response->url}");
        $this->assertStringContainsString('Location: index.php', $response->headers);
    }

    // セッション切れ時も index.php に戻り、期限切れメッセージが表示される
    public function testSalesShowRedirectsToIndexWithSessionExpiredMessage(): void
    {
        $this->getClient()->get('index.php');

        $response = $this->getClient()->get('sales_show.php');
        $this->assertSame(200, $response->status, "Expected final 200 from {$response->url}");
        $this->assertStringContainsString('Location: index.php', $response->headers);
        $this->assertStringContainsString(MessageConst::MSG_INF_AUTH_002, $response->body);
    }

    public function testSalesShowDisplays(): void
    {
        $response = $this->openSalesShowAsAdmin($this->defaultSeedQuery());

        $this->assertOk($response);
    }

    // 実績詳細権限を持つ perm09 でも表示できる
    public function testSalesShowDisplaysForPerm09(): void
    {
        self::$client = new WebClient('http://localhost/noblestock/public');
        $this->loginAs('perm09', 'perm0900');
        $this->getClient()->get('menu.php');

        $response = $this->getSalesShow($this->defaultSeedQuery());
        $this->assertOk($response);
    }

    // 権限のない noauth では 403 になる
    public function testSalesShowReturns403ForNoAuth(): void
    {
        self::$client = new WebClient('http://localhost/noblestock/public');
        $this->loginAs('noauth', 'noauth00');
        $this->getClient()->get('menu.php');

        $response = $this->getClient()->get('sales_show.php?' . http_build_query($this->defaultSeedQuery()));
        $this->assertSame(403, $response->status, "Expected 403 from {$response->url}");
    }

    // 直接開くには管理番号と枝番が必要なので、条件なし GET は 400 にする
    public function testNonDataGetAccessReturns400(): void
    {
        $this->prepareSalesShowAsAdmin();

        $response = $this->getClient()->get('sales_show.php');
        $this->assertSame(400, $response->status);
        $this->assertStringContainsString(MessageConst::MSG_VAL_FILE_018, $response->body);
    }

    // 初期表示では対象商品の案内メッセージ、検索フォームの hidden 条件、明細3行が表示される
    public function testSalesShowDisplaysGuideMessageAndExpectedRowsWithoutWarnings(): void
    {
        $response = $this->openSalesShowAsAdmin($this->defaultSeedQuery());

        $this->assertGuideMessage($response, 'ABC001', '2');
        $this->assertTableRowCount($response, 3);
        $this->assertTableHistoryKbnSequence($response, ['商品登録', '入庫', '出庫']);
        $this->assertStringContainsString('name="mn" value="ABC001"', $response->body);
        $this->assertStringContainsString('name="bn" value="2"', $response->body);
        $this->assertStringNotContainsString('Warning', $response->body);
    }

    // 最初に開いたあとなら、GET だけの再表示でもセッション条件を使って同じ詳細を表示できる
    public function testSalesShowCanRedisplayFromSessionWithoutRepeatingQuery(): void
    {
        $this->prepareSalesShowAsAdmin();
        $this->getSalesShow($this->defaultSeedQuery());

        $response = $this->getClient()->get('sales_show.php');
        $this->assertOk($response);

        $this->assertGuideMessage($response, 'ABC001', '2');
        $this->assertTableRowCount($response, 3);
    }

    // 履歴区分で「入庫」だけに絞ると、該当する1行だけが残る
    public function testSalesShowSearchByHistoryKbnShowsExpectedRow(): void
    {
        $response = $this->openSalesShowAsAdmin([
            'mn' => 'ABC001',
            'bn' => '2',
            'df' => '2026-01-01',
            'dt' => '2026-01-31',
            'hk' => ['0'],
        ]);

        $this->assertSearchSelectValues($response, 'hk[]', ['0']);
        $this->assertTableRowCount($response, 1);
        $this->assertTableHistoryKbnSequence($response, ['入庫']);
    }

    // 店舗で絞ると、その店舗で動いた履歴だけに減る
    public function testSalesShowSearchByLocationShowsExpectedRows(): void
    {
        $response = $this->openSalesShowAsAdmin([
            'mn' => 'ABC001',
            'bn' => '2',
            'df' => '2026-01-01',
            'dt' => '2026-01-31',
            'ln' => ['店舗10'],
        ]);

        $this->assertSearchSelectValues($response, 'ln[]', ['店舗10']);
        $this->assertTableRowCount($response, 2);
        $this->assertTableHistoryKbnSequence($response, ['入庫', '出庫']);
    }

    // ユーザー絞り込みでは selected 状態も含めて確認する
    public function testSalesShowSearchByUserShowsSelectedValue(): void
    {
        $response = $this->openSalesShowAsAdmin([
            'mn' => 'ABC001',
            'bn' => '2',
            'df' => '2026-01-01',
            'dt' => '2026-01-31',
            'un' => ['管理者がう'],
        ]);

        $this->assertSearchSelectValues($response, 'un[]', ['管理者がう']);
        $this->assertTableRowCount($response, 3);
    }

    // 期間外を指定すると0件になり、一覧なしメッセージが表示される
    public function testSalesShowSearchByDateRangeExcludesRows(): void
    {
        $response = $this->openSalesShowAsAdmin([
            'mn' => 'ABC001',
            'bn' => '2',
            'df' => '2026-01-28',
            'dt' => '2026-01-31',
        ]);

        $this->assertTableRowCount($response, 0);
        $this->assertSearchResultCount($response, 0);
        $this->assertStringContainsString(MessageConst::MSG_VAL_LIST_001, $response->body);
    }

    // 在庫数降順ソートでは 120, 70, 0 の順に並ぶ
    public function testSalesShowSortByLocationStockDescendingShowsExpectedOrder(): void
    {
        $response = $this->openSalesShowAsAdmin([
            'mn' => 'ABC001',
            'bn' => '2',
            'df' => '2026-01-01',
            'dt' => '2026-01-31',
            's' => '14',
        ]);

        $this->assertColumnValues($response, 'location_stock', [120, 70, 0]);
    }

    // 処理内容順ソートでは 入庫 -> 出庫 -> 商品登録 の順に並ぶ
    public function testSalesShowSortByHistoryKbnAscendingShowsExpectedOrder(): void
    {
        $response = $this->openSalesShowAsAdmin([
            'mn' => 'ABC001',
            'bn' => '2',
            'df' => '2026-01-01',
            'dt' => '2026-01-31',
            's' => '3',
        ]);

        $this->assertTableHistoryKbnSequence($response, ['入庫', '出庫', '商品登録']);
    }

    // 商品編集と実績更新の POST 導線があり、履歴IDも正しく埋まっている
    public function testSalesShowRowsContainProductEditAndHistoryEditForms(): void
    {
        $response = $this->openSalesShowAsAdmin($this->defaultSeedQuery());
        $rows = $this->extractListRows($response);

        $this->assertSame('product_edit.php', $rows[0]['product_edit_action']);
        $this->assertSame('history_edit.php', $rows[0]['history_edit_action']);
        $this->assertSame('1', $rows[0]['history_no']);
    }

    // 専用データを25件入れると2ページ目ができ、21件目以降が表示される
    public function testSalesShowPaginationDisplaysSecondPageRows(): void
    {
        $this->insertPaginationHistories();

        try {
            $response = $this->getSalesShow([
                'mn' => 'SHW901',
                'bn' => '1',
                'df' => '2026-01-01',
                'dt' => '2026-01-31',
                's' => '1',
                'p' => '2',
                'pos' => '0',
            ]);

            $rows = $this->extractListRows($response);
            $this->assertCount(5, $rows);
            $this->assertSame('2026/1/21', $rows[0]['date']);
            $this->assertSame('2026/1/25', $rows[4]['date']);
            $this->assertActivePageNumber($response, 2);
            $this->assertPreviousPageLinkEnabled($response);
            $this->assertNextPageLinkDisabled($response);
        } finally {
            $this->deleteHistoriesByManagementNo('SHW901');
        }
    }

    /**
     * 種データで sales_show を開くときの基本条件。
     *
     * @return array<string, mixed>
     */
    private function defaultSeedQuery(): array
    {
        return [
            'mn' => 'ABC001',
            'bn' => '2',
            'df' => '2026-01-01',
            'dt' => '2026-01-31',
        ];
    }

    private function prepareSalesShowAsAdmin(): void
    {
        self::$client = new WebClient('http://localhost/noblestock/public');
        $this->loginAsAdmin();
        $this->getClient()->get('menu.php');
    }

    /**
     * @param array<string, mixed> $query
     */
    private function openSalesShowAsAdmin(array $query): Response
    {
        $this->prepareSalesShowAsAdmin();

        return $this->getSalesShow($query);
    }

    /**
     * @param array<string, mixed> $query
     */
    private function getSalesShow(array $query): Response
    {
        $response = $this->getClient()->get('sales_show.php?' . http_build_query($query));
        $this->assertOk($response);

        return $response;
    }

    // テーブルを配列化しておくと、絞り込みやソートの確認を短く書ける
    private function extractListRows(Response $response): array
    {
        $xpath = new DOMXPath($response->dom());
        $rowNodes = $xpath->query('(//table[contains(@class,"table")])[last()]//tbody/tr');
        $this->assertNotFalse($rowNodes);

        $rows = [];
        foreach ($rowNodes as $rowNode) {
            $cellNodes = $xpath->query('./td', $rowNode);
            if ($cellNodes === false || $cellNodes->length < 13) {
                continue;
            }

            $managementText = $this->normalizeText($cellNodes->item(1)?->textContent ?? '');
            preg_match('/^(.+?)\s*\[(\d+)\]$/u', $managementText, $matches);

            $productEditForms = $xpath->query('.//form[@action="product_edit.php"]', $cellNodes->item(1));
            $historyEditForms = $xpath->query('.//form[@action="history_edit.php"]', $cellNodes->item(12));
            $historyNoNodes = $xpath->query('.//input[@name="HISTORY_NO"]', $cellNodes->item(12));

            $rows[] = [
                'date' => $this->normalizeText($cellNodes->item(0)?->textContent ?? ''),
                'management_no' => $matches[1] ?? $managementText,
                'branch_no' => $matches[2] ?? '',
                'history_kbn' => $this->normalizeText($cellNodes->item(2)?->textContent ?? ''),
                'location_name' => $this->normalizeText($cellNodes->item(3)?->textContent ?? ''),
                'category_name' => $this->normalizeText($cellNodes->item(4)?->textContent ?? ''),
                'maker_name' => $this->normalizeText($cellNodes->item(5)?->textContent ?? ''),
                'product_name' => $this->normalizeText($cellNodes->item(6)?->textContent ?? ''),
                'stock_in' => $this->extractInt($cellNodes->item(7)?->textContent ?? ''),
                'quantity' => $this->extractInt($cellNodes->item(8)?->textContent ?? ''),
                'move_stock' => $this->extractInt($cellNodes->item(9)?->textContent ?? ''),
                'location_stock' => $this->extractInt($cellNodes->item(10)?->textContent ?? ''),
                'user_name' => $this->normalizeText($cellNodes->item(11)?->textContent ?? ''),
                'product_edit_action' => ($productEditForms !== false && $productEditForms->length > 0) ? 'product_edit.php' : '',
                'history_edit_action' => ($historyEditForms !== false && $historyEditForms->length > 0) ? 'history_edit.php' : '',
                'history_no' => ($historyNoNodes !== false && $historyNoNodes->length > 0)
                    ? (string) ($historyNoNodes->item(0)?->attributes?->getNamedItem('value')?->nodeValue ?? '')
                    : '',
            ];
        }

        return $rows;
    }

    private function assertGuideMessage(Response $response, string $managementNo, string $branchNo): void
    {
        $xpath = new DOMXPath($response->dom());
        $nodes = $xpath->query("//div[contains(@class,'alert-info')]/div");
        $this->assertNotFalse($nodes);
        $this->assertGreaterThan(0, $nodes->length, 'Guide message alert was not found.');

        $message = $this->normalizeText($nodes->item(0)?->textContent ?? '');
        $this->assertSame(
            str_replace(['{0}', '{1}'], [$managementNo, $branchNo], MessageConst::MSG_INF_SALES_001),
            $message
        );
    }

    private function assertTableRowCount(Response $response, int $expectedCount): void
    {
        $this->assertCount($expectedCount, $this->extractListRows($response));
    }

    /**
     * @param array<int, string> $expectedValues
     */
    private function assertTableHistoryKbnSequence(Response $response, array $expectedValues): void
    {
        $actual = array_column($this->extractListRows($response), 'history_kbn');
        $this->assertSame($expectedValues, $actual);
    }

    private function assertSearchResultCount(Response $response, int $expectedCount): void
    {
        $xpath = new DOMXPath($response->dom());
        $nodes = $xpath->query("//nav[@aria-label='Page navigation']//p[contains(@class,'m-1')]");
        $this->assertNotFalse($nodes);
        $this->assertGreaterThan(0, $nodes->length, 'Search result summary was not found.');

        $actual = $this->normalizeText($nodes->item(0)?->textContent ?? '');
        $this->assertMatchesRegularExpression('/\d+/', $actual);
        preg_match('/(\d+)/', $actual, $matches);
        $this->assertSame($expectedCount, (int) ($matches[1] ?? 0));
    }

    /**
     * @param array<int, string> $expectedValues
     */
    private function assertSearchSelectValues(Response $response, string $selectName, array $expectedValues): void
    {
        $xpath = new DOMXPath($response->dom());
        $selectNodes = $xpath->query("//form[@name='search']//select[@name='{$selectName}']");
        $this->assertNotFalse($selectNodes);
        $this->assertGreaterThan(0, $selectNodes->length, "Search select '{$selectName}' was not found.");

        $selectedValues = [];
        $selectedNodes = $xpath->query('.//option[@selected]', $selectNodes->item(0));
        $this->assertNotFalse($selectedNodes);
        foreach ($selectedNodes as $node) {
            $selectedValues[] = (string) $node->attributes?->getNamedItem('value')?->nodeValue;
        }

        sort($selectedValues);
        $expected = $expectedValues;
        sort($expected);
        $this->assertSame($expected, $selectedValues, "Unexpected selected values in '{$selectName}'.");
    }

    /**
     * @param array<int, int> $expectedValues
     */
    private function assertColumnValues(Response $response, string $columnKey, array $expectedValues): void
    {
        $actual = array_column($this->extractListRows($response), $columnKey);
        $this->assertSame($expectedValues, $actual);
    }

    private function assertActivePageNumber(Response $response, int $expectedPage): void
    {
        $xpath = new DOMXPath($response->dom());
        $nodes = $xpath->query("//nav[@aria-label='Page navigation']//li[contains(@class,'active')]/a");
        $this->assertNotFalse($nodes);
        $this->assertGreaterThan(0, $nodes->length, 'Active page indicator was not found.');

        $actual = $this->normalizeText((string) $nodes->item(0)?->textContent);
        $this->assertSame((string) $expectedPage, $actual, 'Unexpected active page number.');
    }

    private function assertPreviousPageLinkEnabled(Response $response): void
    {
        $xpath = new DOMXPath($response->dom());
        $nodes = $xpath->query("//nav[@aria-label='Page navigation']//button[@aria-label='Previous']");
        $this->assertNotFalse($nodes);
        $this->assertGreaterThan(0, $nodes->length, 'Previous page button should be enabled.');
    }

    private function assertNextPageLinkDisabled(Response $response): void
    {
        $xpath = new DOMXPath($response->dom());
        $enabled = $xpath->query("//nav[@aria-label='Page navigation']//button[@aria-label='Next']");
        $this->assertNotFalse($enabled);
        $this->assertSame(0, $enabled->length, 'Next page button should be disabled.');

        $disabled = $xpath->query("//nav[@aria-label='Page navigation']//li[contains(@class,'disabled')]//a[@aria-label='Next']");
        $this->assertNotFalse($disabled);
        $this->assertGreaterThan(0, $disabled->length, 'Disabled next page element was not found.');
    }

    // ページング確認用に同じ管理番号・枝番の履歴を 25 件追加する
    private function insertPaginationHistories(): void
    {
        $pdo = $this->createTestPdo();
        $stmt = $pdo->prepare(
            'INSERT INTO histories (
                history_yy, history_mm, history_dd, history_kbn, management_no, branch_no,
                category_name, maker_name, product_name, location_name,
                quantity, stock_in, move_stock, location_stock, stock, del_flg,
                created_at, created_by, updated_at, updated_by
            ) VALUES (
                2026, 1, :history_dd, 0, :management_no, 1,
                :category_name, :maker_name, :product_name, :location_name,
                0, :stock_in, 0, :location_stock, :stock, 0,
                :created_at, :created_by, :updated_at, :updated_by
            )'
        );

        for ($i = 1; $i <= 25; $i++) {
            $stmt->execute([
                'history_dd' => $i,
                'management_no' => 'SHW901',
                'category_name' => 'カテゴリ10',
                'maker_name' => 'メーカー10',
                'product_name' => '実績詳細ページング',
                'location_name' => '店舗10',
                'stock_in' => $i,
                'location_stock' => $i,
                'stock' => $i,
                'created_at' => sprintf('2026-01-%02d 10:00:00', $i),
                'created_by' => 'admin',
                'updated_at' => sprintf('2026-01-%02d 10:00:00', $i),
                'updated_by' => 'admin',
            ]);
        }
    }

    private function deleteHistoriesByManagementNo(string $managementNo): void
    {
        $pdo = $this->createTestPdo();
        $stmt = $pdo->prepare('DELETE FROM histories WHERE management_no = :management_no');
        $stmt->execute([
            'management_no' => $managementNo,
        ]);
    }

    private function normalizeText(string $text): string
    {
        return trim((string) preg_replace('/\s+/u', ' ', $text));
    }

    private function extractInt(string $text): int
    {
        $normalized = str_replace(',', '', $text);
        if (preg_match('/-?\d+/', $normalized, $matches) !== 1) {
            return 0;
        }

        return (int) $matches[0];
    }

    private function createTestPdo(): PDO
    {
        $configPath = dirname(__DIR__, 2) . '/config/dbconfig.ini';
        $config = parse_ini_file($configPath);

        $this->assertIsArray($config, 'Failed to read dbconfig.ini.');
        foreach (['dbhost', 'dbuser', 'dbpass', 'dbname'] as $requiredKey) {
            $this->assertArrayHasKey($requiredKey, $config, "Missing {$requiredKey} in dbconfig.ini.");
        }

        return new PDO(
            sprintf(
                'mysql:host=%s;dbname=%s;charset=utf8mb4',
                (string) $config['dbhost'],
                (string) $config['dbname']
            ),
            (string) $config['dbuser'],
            (string) $config['dbpass'],
            [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
        );
    }
    // セッション期限切れ時に認証情報と画面用セッションが破棄されることを確認する
    public function testSalesShowClearsSessionDataWhenTimedOut(): void
    {
        $this->assertTimedOutProtectedPageClearsSessionDataAndShowsExpiredMessage('sales_show.php');
    }
}
