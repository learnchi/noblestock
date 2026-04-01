<?php

declare(strict_types=1);

use Noblestock\Logic\MessageConst;
use Tests\Support\Response;
use Tests\Support\WebClient;
use Tests\Support\WebTestCase;

final class SalesListPageTest extends WebTestCase
{
    // ログインしていない状態ではこの画面に直接アクセスできず、index.php に戻される
    public function testSalesListRedirectsToIndex(): void
    {
        $this->getClient()->get('index.php');

        $response = $this->getClient()->get('sales_list.php');
        $this->assertSame(200, $response->status, "Expected final 200 from {$response->url}");
        $this->assertStringContainsString('Location: index.php', $response->headers);
    }

    // セッション切れ時も index.php に戻り、期限切れメッセージが表示される
    public function testSalesListRedirectsToIndexWithSessionExpiredMessage(): void
    {
        $this->getClient()->get('index.php');

        $response = $this->getClient()->get('sales_list.php');
        $this->assertSame(200, $response->status, "Expected final 200 from {$response->url}");
        $this->assertStringContainsString('Location: index.php', $response->headers);
        $this->assertStringContainsString(MessageConst::MSG_INF_AUTH_002, $response->body);
    }

    public function testSalesListDisplays(): void
    {
        $this->loginAsAdmin();

        $response = $this->getClient()->get('sales_list.php');
        $this->assertOk($response);
    }

    // 実績一覧権限を持つ perm09 でも表示できる
    public function testSalesListDisplaysForPerm09(): void
    {
        $this->loginAs('perm09', 'perm0900');

        $response = $this->getClient()->get('sales_list.php');
        $this->assertOk($response);
    }

    // 権限のない noauth では 403 になる
    public function testSalesListReturns403ForNoAuth(): void
    {
        $this->loginAs('noauth', 'noauth00');

        $response = $this->getClient()->get('sales_list.php');
        $this->assertSame(403, $response->status, "Expected 403 from {$response->url}");
    }

    // 初期表示では期間が自動設定され、処理内容は「全て」が選ばれた状態になる
    public function testSalesListInitialDisplayShowsDefaultDatesAndSearchKbnWithoutWarnings(): void
    {
        $response = $this->openSalesListAsAdmin();

        $this->assertSearchTextInputValue($response, 'df', date('Y-m-d', strtotime('-1 month')));
        $this->assertSearchTextInputValue($response, 'dt', date('Y-m-d'));
        $this->assertSelectedSearchKbn($response, '0');
        $this->assertStringNotContainsString('Warning', $response->body);
    }

    // 管理番号検索では入力値がフォームに残り、対象行だけに絞り込まれる
    public function testSalesListSearchByManagementNoShowsExpectedRow(): void
    {
        $response = $this->openSalesListAsAdmin($this->buildSearchQuery([
            'mn' => 'ABC001',
        ]));

        $this->assertSearchTextInputValue($response, 'mn', 'ABC001');
        $this->assertTableRowCount($response, 1);
        $this->assertSearchResultCount($response, 1);
        $this->assertTableManagementNos($response, ['ABC001']);
    }

    // 商品名検索は部分一致なので、ABC003 だけを狙って1件にできる
    public function testSalesListSearchByProductNameShowsExpectedRow(): void
    {
        $response = $this->openSalesListAsAdmin($this->buildSearchQuery([
            'prn' => 'ABC003',
        ]));

        $this->assertSearchTextInputValue($response, 'prn', 'ABC003');
        $this->assertTableRowCount($response, 1);
        $this->assertSearchResultCount($response, 1);
        $this->assertTableManagementNos($response, ['ABC003']);
    }

    // 店舗検索は履歴の店舗名で絞り込まれ、店舗10には ABC001 だけが残る
    public function testSalesListSearchByLocationShowsExpectedRow(): void
    {
        $response = $this->openSalesListAsAdmin($this->buildSearchQuery([
            'ln' => ['店舗10'],
        ]));

        $this->assertSearchSelectValues($response, 'ln[]', ['店舗10']);
        $this->assertTableRowCount($response, 1);
        $this->assertSearchResultCount($response, 1);
        $this->assertTableManagementNos($response, ['ABC001']);
    }

    // カテゴリ検索は履歴側のカテゴリ名で行うので、専用データを足して確認する
    public function testSalesListSearchByCategoryShowsExpectedRow(): void
    {
        $this->prepareSalesListAsAdmin();
        try {
            $this->insertHistorySummaryRow([
                'management_no' => 'TSC301',
                'branch_no' => 1,
                'category_name' => 'カテゴリ30',
                'maker_name' => 'メーカー10',
                'product_name' => 'カテゴリ検索用商品',
                'location_name' => '店舗40',
                'stock_in' => 15,
                'quantity' => 0,
                'move_stock' => 0,
                'stock' => 15,
            ]);

            $response = $this->getSalesList($this->buildSearchQuery([
                'cn' => ['カテゴリ30'],
            ]));

            $this->assertSearchSelectValues($response, 'cn[]', ['カテゴリ30']);
            $this->assertTableRowCount($response, 1);
            $this->assertSearchResultCount($response, 1);
            $this->assertTableManagementNos($response, ['TSC301']);
        } finally {
            $this->deleteHistoriesByPrefix('TSC');
        }
    }

    // メーカー検索も専用データを足し、対象メーカーだけに絞られることを確認する
    public function testSalesListSearchByMakerShowsExpectedRow(): void
    {
        $this->prepareSalesListAsAdmin();
        try {
            $this->insertHistorySummaryRow([
                'management_no' => 'TSM301',
                'branch_no' => 1,
                'category_name' => 'カテゴリ10',
                'maker_name' => 'メーカー30',
                'product_name' => 'メーカー検索用商品',
                'location_name' => '店舗50',
                'stock_in' => 22,
                'quantity' => 0,
                'move_stock' => 0,
                'stock' => 22,
            ]);

            $response = $this->getSalesList($this->buildSearchQuery([
                'mk' => ['メーカー30'],
            ]));

            $this->assertSearchSelectValues($response, 'mk[]', ['メーカー30']);
            $this->assertTableRowCount($response, 1);
            $this->assertSearchResultCount($response, 1);
            $this->assertTableManagementNos($response, ['TSM301']);
        } finally {
            $this->deleteHistoriesByPrefix('TSM');
        }
    }

    // 日付範囲が合わないと対象データは0件になる
    public function testSalesListSearchByDateRangeExcludesSeededRows(): void
    {
        $response = $this->openSalesListAsAdmin([
            'df' => '2026-01-28',
            'dt' => '2026-01-31',
        ]);

        $this->assertSearchTextInputValue($response, 'df', '2026-01-28');
        $this->assertSearchTextInputValue($response, 'dt', '2026-01-31');
        $this->assertTableRowCount($response, 0);
        $this->assertSearchResultCount($response, 0);
    }

    // 処理内容「入庫あり」では入庫数がある商品だけに絞り込まれる
    public function testSalesListSearchBySearchKbnInOnlyShowsRowsWithStockIn(): void
    {
        $response = $this->openSalesListAsAdmin($this->buildSearchQuery([
            'sk' => '1',
        ]));

        $this->assertSelectedSearchKbn($response, '1');
        $this->assertSearchResultCount($response, 3);
        $this->assertTableManagementNos($response, ['ABC001', 'ABC002', 'ABC003']);
    }

    // 処理内容「移動あり」では移動数がある ABC003 だけが残る
    public function testSalesListSearchBySearchKbnMoveOnlyShowsExpectedRow(): void
    {
        $response = $this->openSalesListAsAdmin($this->buildSearchQuery([
            'sk' => '3',
        ]));

        $this->assertSelectedSearchKbn($response, '3');
        $this->assertTableRowCount($response, 1);
        $this->assertSearchResultCount($response, 1);
        $this->assertTableManagementNos($response, ['ABC003']);
    }

    // 管理番号降順ソートでは末尾の番号から並ぶ
    public function testSalesListSortByManagementNoDescendingShowsExpectedOrder(): void
    {
        $response = $this->openSalesListAsAdmin($this->buildSearchQuery([
            's' => '2',
        ]));

        $this->assertRowsSortedBy($response, 'management_no', 'desc');
        $this->assertTableStartsWith($response, ['ABC020', 'ABC019', 'ABC018']);
    }

    // 現在在庫数降順ソートでは在庫の多い商品が先頭に来る
    public function testSalesListSortByStockDescendingShowsExpectedOrder(): void
    {
        $response = $this->openSalesListAsAdmin($this->buildSearchQuery([
            's' => '18',
        ]));

        $this->assertRowsSortedBy($response, 'stock', 'desc');
        $this->assertTableStartsWith($response, ['ABC002', 'ABC001', 'ABC003']);
    }

    // 一覧の管理番号ボタンから sales_show.php に遷移でき、検索条件も引き継がれる
    public function testSalesListManagementLinkNavigatesToSalesShowWithCurrentConditions(): void
    {
        $this->prepareSalesListAsAdmin();
        $query = $this->buildSearchQuery([
            'ln' => ['店舗10'],
        ]);
        $response = $this->getSalesList($query);

        $this->assertStringContainsString('action="sales_show.php"', $response->body);
        $this->assertStringContainsString('name="mn" value="ABC001"', $response->body);
        $this->assertStringContainsString('name="bn" value="2"', $response->body);
        $this->assertStringContainsString('name="df" value="2026-01-01"', $response->body);
        $this->assertStringContainsString('name="dt" value="2026-01-31"', $response->body);
        $this->assertStringContainsString('name="ln[]" value="店舗10"', $response->body);

        $detail = $this->getClient()->get('sales_show.php?' . http_build_query([
            'mn' => 'ABC001',
            'bn' => '2',
            'df' => '2026-01-01',
            'dt' => '2026-01-31',
            'ln' => ['店舗10'],
        ]));

        $this->assertOk($detail);
        $this->assertStringContainsString('ABC001', $detail->body);
    }

    // 21件以上あれば2ページ目が現れ、GET の p パラメータで次ページへ移動できる
    public function testSalesListPaginationDisplaysSecondPageRows(): void
    {
        $this->prepareSalesPaginationData();

        try {
            $response = $this->getSalesList([
                'df' => '2026-01-01',
                'dt' => '2026-01-31',
                'p' => '2',
                's' => '1',
                'pos' => '0',
            ]);

            $rows = $this->extractListRows($response);
            $this->assertCount(20, $rows);
            $this->assertSame('SLS021', $rows[0]['management_no']);
            $this->assertSame('SLS040', $rows[19]['management_no']);
            $this->assertStringContainsString('aria-current="page"', $response->body);
            $this->assertStringContainsString('>2<', $response->body);
        } finally {
            $this->deleteHistoriesByPrefix('SLS');
        }
    }

    // ページ移動後に次ページの管理番号群が表示され、前ページ移動ボタンも有効になる
    public function testSalesListPaginationShowsLastPageRowsAndPreviousButton(): void
    {
        $this->prepareSalesPaginationData();

        try {
            $response = $this->getSalesList([
                'df' => '2026-01-01',
                'dt' => '2026-01-31',
                'p' => '3',
                's' => '1',
                'pos' => '0',
            ]);

            $rows = $this->extractListRows($response);
            $this->assertCount(5, $rows);
            $this->assertSame('SLS041', $rows[0]['management_no']);
            $this->assertSame('SLS045', $rows[4]['management_no']);
            $this->assertStringContainsString('aria-label="Previous"', $response->body);
        } finally {
            $this->deleteHistoriesByPrefix('SLS');
        }
    }

    /**
     * 普段使う検索は、種データの 2026-01-27 を必ず含む期間をベースにする。
     *
     * @param array<string, mixed> $overrides
     * @return array<string, mixed>
     */
    private function buildSearchQuery(array $overrides = []): array
    {
        return array_merge([
            'df' => '2026-01-01',
            'dt' => '2026-01-31',
        ], $overrides);
    }

    /**
     * 初期表示を確認したいときだけ、画面自身のデフォルト条件で開く。
     *
     * @param array<string, mixed> $query
     */
    private function openSalesListAsAdmin(array $query = []): Response
    {
        $this->prepareSalesListAsAdmin();

        return $this->getSalesList($query);
    }

    private function prepareSalesListAsAdmin(): void
    {
        self::$client = new WebClient('http://localhost/noblestock/public');
        $this->loginAsAdmin();
        $this->getClient()->get('menu.php');
    }

    /**
     * @param array<string, mixed> $query
     */
    private function getSalesList(array $query = []): Response
    {
        $path = 'sales_list.php';
        if ($query !== []) {
            $path .= '?' . http_build_query($query);
        }

        $response = $this->getClient()->get($path);
        $this->assertOk($response);

        return $response;
    }

    // テーブル行を配列化しておくと、並び順や件数の確認を短く書ける
    private function extractListRows(Response $response): array
    {
        $xpath = new DOMXPath($response->dom());
        $rowNodes = $xpath->query("(//table[contains(@class,'table')])[last()]//tbody/tr");
        $this->assertNotFalse($rowNodes);

        $rows = [];
        foreach ($rowNodes as $rowNode) {
            $cellNodes = $xpath->query('./td', $rowNode);
            if ($cellNodes === false || $cellNodes->length < 8) {
                continue;
            }

            $buttonText = $this->normalizeText($cellNodes->item(0)?->textContent ?? '');
            preg_match('/^(.+?)\s*\[(\d+)\]$/u', $buttonText, $matches);

            $rows[] = [
                'management_no' => $matches[1] ?? $buttonText,
                'branch_no' => isset($matches[2]) ? (int) $matches[2] : 0,
                'category_name' => $this->normalizeText($cellNodes->item(1)?->textContent ?? ''),
                'maker_name' => $this->normalizeText($cellNodes->item(2)?->textContent ?? ''),
                'product_name' => $this->normalizeText($cellNodes->item(3)?->textContent ?? ''),
                'stock_in' => $this->extractInt($cellNodes->item(4)?->textContent ?? ''),
                'quantity' => $this->extractInt($cellNodes->item(5)?->textContent ?? ''),
                'move_stock' => $this->extractInt($cellNodes->item(6)?->textContent ?? ''),
                'stock' => $this->extractInt($cellNodes->item(7)?->textContent ?? ''),
            ];
        }

        return $rows;
    }

    private function assertSearchTextInputValue(Response $response, string $name, string $expectedValue): void
    {
        $xpath = new DOMXPath($response->dom());
        $nodes = $xpath->query("//form[@name='search']//input[@name='{$name}']");
        $this->assertNotFalse($nodes);
        $this->assertGreaterThan(0, $nodes->length, "Search input '{$name}' was not found.");

        $actual = $nodes->item(0)?->attributes?->getNamedItem('value')?->nodeValue ?? '';
        $this->assertSame($expectedValue, $actual, "Unexpected value in search input '{$name}'.");
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

    private function assertSelectedSearchKbn(Response $response, string $expectedValue): void
    {
        $xpath = new DOMXPath($response->dom());
        $nodes = $xpath->query("//form[@name='search']//select[@name='sk']/option[@selected]");
        $this->assertNotFalse($nodes);
        $this->assertGreaterThan(0, $nodes->length, 'Selected search kbn option was not found.');

        $actual = (string) ($nodes->item(0)?->attributes?->getNamedItem('value')?->nodeValue ?? '');
        $this->assertSame($expectedValue, $actual);
    }

    private function assertTableRowCount(Response $response, int $expectedCount): void
    {
        $this->assertCount($expectedCount, $this->extractListRows($response));
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
     * @param array<int, string> $expectedManagementNos
     */
    private function assertTableManagementNos(Response $response, array $expectedManagementNos): void
    {
        $actual = array_column($this->extractListRows($response), 'management_no');
        $this->assertSame($expectedManagementNos, $actual);
    }

    /**
     * @param array<int, string> $expectedPrefix
     */
    private function assertTableStartsWith(Response $response, array $expectedPrefix): void
    {
        $actual = array_column($this->extractListRows($response), 'management_no');
        $this->assertSame($expectedPrefix, array_slice($actual, 0, count($expectedPrefix)));
    }

    private function assertRowsSortedBy(Response $response, string $columnKey, string $direction): void
    {
        $rows = $this->extractListRows($response);
        $this->assertNotEmpty($rows, 'No table rows were found for sort assertion.');

        $values = array_column($rows, $columnKey);
        $expected = $values;

        if (in_array($columnKey, ['branch_no', 'stock_in', 'quantity', 'move_stock', 'stock'], true)) {
            sort($expected, SORT_NUMERIC);
        } else {
            sort($expected, SORT_STRING);
        }

        if ($direction === 'desc') {
            $expected = array_reverse($expected);
        }

        $this->assertSame($expected, $values, "Rows are not sorted by {$columnKey} ({$direction}).");
    }

    /**
     * ページネーション確認用に 45 件ぶんの実績を用意する。
     * 既存 20 件の後ろに続く管理番号へしておくと、2ページ目以降の確認が単純になる。
     */
    private function prepareSalesPaginationData(): void
    {
        $this->prepareSalesListAsAdmin();

        for ($i = 21; $i <= 45; $i++) {
            $managementNo = sprintf('SLS%03d', $i);
            $this->insertHistorySummaryRow([
                'management_no' => $managementNo,
                'branch_no' => 1,
                'category_name' => 'カテゴリ10',
                'maker_name' => 'メーカー10',
                'product_name' => 'ページング商品' . sprintf('%03d', $i),
                'location_name' => '店舗10',
                'stock_in' => $i,
                'quantity' => 0,
                'move_stock' => 0,
                'stock' => $i,
            ]);
        }
    }

    /**
     * sales_list.php は histories の集計結果を表示するので、1行追加するだけで検索・並び替えの確認に使える。
     *
     * @param array<string, int|string> $row
     */
    private function insertHistorySummaryRow(array $row): void
    {
        $pdo = $this->createTestPdo();
        $stmt = $pdo->prepare(
            'INSERT INTO histories (
                history_yy, history_mm, history_dd, history_kbn, management_no, branch_no,
                category_name, maker_name, product_name, location_name,
                quantity, stock_in, move_stock, location_stock, stock, del_flg,
                created_at, created_by, updated_at, updated_by
            ) VALUES (
                2026, 1, 27, 0, :management_no, :branch_no,
                :category_name, :maker_name, :product_name, :location_name,
                :quantity, :stock_in, :move_stock, :location_stock, :stock, 0,
                :created_at, :created_by, :updated_at, :updated_by
            )'
        );
        $stmt->execute([
            'management_no' => (string) $row['management_no'],
            'branch_no' => (int) $row['branch_no'],
            'category_name' => (string) $row['category_name'],
            'maker_name' => (string) $row['maker_name'],
            'product_name' => (string) $row['product_name'],
            'location_name' => (string) $row['location_name'],
            'quantity' => (int) $row['quantity'],
            'stock_in' => (int) $row['stock_in'],
            'move_stock' => (int) $row['move_stock'],
            'location_stock' => (int) $row['stock'],
            'stock' => (int) $row['stock'],
            'created_at' => '2026-01-27 10:30:00',
            'created_by' => 'admin',
            'updated_at' => '2026-01-27 10:30:00',
            'updated_by' => 'admin',
        ]);
    }

    private function deleteHistoriesByPrefix(string $prefix): void
    {
        $pdo = $this->createTestPdo();
        $stmt = $pdo->prepare('DELETE FROM histories WHERE management_no LIKE :prefix');
        $stmt->execute([
            'prefix' => $prefix . '%',
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
    public function testSalesListClearsSessionDataWhenTimedOut(): void
    {
        $this->assertTimedOutProtectedPageClearsSessionDataAndShowsExpiredMessage('sales_list.php');
    }
}
