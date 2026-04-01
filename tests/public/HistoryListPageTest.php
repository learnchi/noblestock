<?php

declare(strict_types=1);

use Noblestock\Logic\MessageConst;
use Tests\Support\Response;
use Tests\Support\TestDatabase;
use Tests\Support\WebClient;
use Tests\Support\WebTestCase;

final class HistoryListPageTest extends WebTestCase
{
    // ログインしていない状態ではこの画面に直接入れず、index.php に戻される
    public function testHistoryListRedirectsToIndex(): void
    {
        $this->getClient()->get('index.php');

        $response = $this->getClient()->get('history_list.php');
        $this->assertSame(200, $response->status, "Expected final 200 from {$response->url}");
        $this->assertStringContainsString('Location: index.php', $response->headers);
    }

    // セッション切れでも index.php に戻り、案内メッセージが出る
    public function testHistoryListRedirectsToIndexWithSessionExpiredMessage(): void
    {
        $this->getClient()->get('index.php');

        $response = $this->getClient()->get('history_list.php');
        $this->assertSame(200, $response->status, "Expected final 200 from {$response->url}");
        $this->assertStringContainsString('Location: index.php', $response->headers);
        $this->assertStringContainsString(MessageConst::MSG_INF_AUTH_002, $response->body);
    }

    public function testHistoryListDisplays(): void
    {
        $response = $this->openHistoryListAsAdmin();
        $this->assertOk($response);
    }

    // この画面に権限があるユーザー[perm10]では 200 で表示できる
    public function testHistoryListDisplaysForPerm10(): void
    {
        $this->prepareHistoryListAs('perm10', 'perm1000');

        $response = $this->getHistoryList();
        $this->assertOk($response);
    }

    // 権限のない noauth では 403 になる
    public function testHistoryListReturns403ForNoAuth(): void
    {
        $this->prepareHistoryListAs('noauth', 'noauth00');

        $response = $this->getClient()->get('history_list.php');
        $this->assertSame(403, $response->status, "Expected 403 from {$response->url}");
    }

    // 初期表示では既定の日付が入っており、Warning を出さずに描画できる
    public function testHistoryListInitialDisplayShowsDefaultDatesWithoutWarnings(): void
    {
        $response = $this->openHistoryListAsAdmin();

        $this->assertSearchTextInputValue($response, 'df', date('Y-m-d', strtotime('-1 month')));
        $this->assertSearchTextInputValue($response, 'dt', date('Y-m-d'));
        $this->assertStringNotContainsString('Warning', $response->body);
    }

    // 管理番号検索では ABC001 の履歴 3 件だけに絞られる
    public function testHistoryListSearchByManagementNoShowsExpectedRows(): void
    {
        $response = $this->openHistoryListAsAdmin($this->buildSearchQuery([
            'mn' => 'ABC001',
        ]));

        $this->assertSearchTextInputValue($response, 'mn', 'ABC001');
        $this->assertTableRowCount($response, 3);
        $this->assertSearchResultCount($response, 3);
        $this->assertSame(['ABC001', 'ABC001', 'ABC001'], array_column($this->extractListRows($response), 'management_no'));
    }

    // 商品名は部分一致なので、ABC003 を入れるとその商品の履歴 4 件だけが残る
    public function testHistoryListSearchByProductNameShowsExpectedRows(): void
    {
        $response = $this->openHistoryListAsAdmin($this->buildSearchQuery([
            'prn' => 'ABC003',
        ]));

        $this->assertSearchTextInputValue($response, 'prn', 'ABC003');
        $this->assertTableRowCount($response, 4);
        $this->assertSearchResultCount($response, 4);
        $this->assertSame(['ABC003', 'ABC003', 'ABC003', 'ABC003'], array_column($this->extractListRows($response), 'management_no'));
    }

    // 店舗検索では履歴側の店舗名で絞られ、店舗10 には ABC001 の入出庫だけが残る
    public function testHistoryListSearchByLocationShowsExpectedRows(): void
    {
        $response = $this->openHistoryListAsAdmin($this->buildSearchQuery([
            'ln' => ['店舗10'],
        ]));

        $this->assertSearchSelectValues($response, 'ln[]', ['店舗10']);
        $this->assertTableRowCount($response, 2);
        $this->assertSearchResultCount($response, 2);
        $this->assertSame(['ABC001', 'ABC001'], array_column($this->extractListRows($response), 'management_no'));
    }

    // カテゴリ検索は seed だけだと履歴に値が薄いので、専用データを足して 1 件に絞れることを確認する
    public function testHistoryListSearchByCategoryShowsExpectedRow(): void
    {
        $this->prepareHistoryListAsAdmin();
        $this->insertHistoryListSearchRows();

        $response = $this->getHistoryList([
            'df' => '2026-01-26',
            'dt' => '2026-01-26',
            'cn' => ['カテゴリ30'],
        ]);

        $this->assertSearchSelectValues($response, 'cn[]', ['カテゴリ30']);
        $this->assertTableRowCount($response, 1);
        $this->assertSearchResultCount($response, 1);
        $this->assertSame('ABC011', $this->extractListRows($response)[0]['management_no']);
    }

    // メーカー検索も専用データを使い、メーカー30 だけで 1 件に絞れることを見る
    public function testHistoryListSearchByMakerShowsExpectedRow(): void
    {
        $this->prepareHistoryListAsAdmin();
        $this->insertHistoryListSearchRows();

        $response = $this->getHistoryList([
            'df' => '2026-01-26',
            'dt' => '2026-01-26',
            'mk' => ['メーカー30'],
        ]);

        $this->assertSearchSelectValues($response, 'mk[]', ['メーカー30']);
        $this->assertTableRowCount($response, 1);
        $this->assertSearchResultCount($response, 1);
        $this->assertSame('ABC012', $this->extractListRows($response)[0]['management_no']);
    }

    // 作成者検索は created_by のユーザー名で絞られるので、Perm 10 の履歴だけが残ることを確認する
    public function testHistoryListSearchByUserShowsExpectedRow(): void
    {
        $this->prepareHistoryListAsAdmin();
        $this->insertHistoryListSearchRows();

        $response = $this->getHistoryList([
            'df' => '2026-01-26',
            'dt' => '2026-01-26',
            'un' => ['Perm 10'],
        ]);

        $this->assertSearchSelectValues($response, 'un[]', ['Perm 10']);
        $this->assertTableRowCount($response, 1);
        $this->assertSearchResultCount($response, 1);
        $this->assertSame('ABC012', $this->extractListRows($response)[0]['management_no']);
    }

    // 履歴区分検索では在庫数変更だけを残し、選択状態も戻ってくる
    public function testHistoryListSearchByHistoryKbnShowsExpectedRow(): void
    {
        $this->prepareHistoryListAsAdmin();
        $this->insertHistoryListSearchRows();

        $response = $this->getHistoryList([
            'df' => '2026-01-26',
            'dt' => '2026-01-26',
            'hk' => ['7'],
        ]);

        $this->assertSearchSelectValues($response, 'hk[]', ['7']);
        $this->assertTableRowCount($response, 1);
        $this->assertSearchResultCount($response, 1);
        $this->assertSame('在庫変更', $this->extractListRows($response)[0]['history_kbn']);
    }

    // 日付範囲が合わなければ 0 件になり、件数表示も 0 件になる
    public function testHistoryListSearchByDateRangeExcludesSeededRows(): void
    {
        $response = $this->openHistoryListAsAdmin([
            'df' => '2026-01-28',
            'dt' => '2026-01-31',
        ]);

        $this->assertSearchTextInputValue($response, 'df', '2026-01-28');
        $this->assertSearchTextInputValue($response, 'dt', '2026-01-31');
        $this->assertTableRowCount($response, 0);
        $this->assertSearchResultCount($response, 0);
    }

    // 管理番号降順ソートでは末尾側の番号から並ぶ
    public function testHistoryListSortByManagementNoDescendingShowsExpectedOrder(): void
    {
        $response = $this->openHistoryListAsAdmin($this->buildSearchQuery([
            's' => '22',
        ]));

        $this->assertRowsSortedBy($response, 'management_no', 'desc');
        $this->assertSame(['ABC020', 'ABC019', 'ABC018'], array_slice(array_column($this->extractListRows($response), 'management_no'), 0, 3));
    }

    // 専用データで在庫数降順ソートを掛けると、90 -> 50 -> 10 の順で並ぶ
    public function testHistoryListSortByLocationStockDescendingShowsExpectedOrder(): void
    {
        $this->prepareHistoryListAsAdmin();
        $this->insertHistoryListSearchRows();

        $response = $this->getHistoryList([
            'df' => '2026-01-26',
            'dt' => '2026-01-26',
            's' => '14',
        ]);

        $this->assertSame([90, 50, 10], array_column($this->extractListRows($response), 'location_stock'));
    }

    // 一覧行には商品更新と履歴更新の POST 導線があり、履歴IDも hidden で引き継いでいる
    public function testHistoryListRowsContainProductEditAndHistoryEditForms(): void
    {
        $response = $this->openHistoryListAsAdmin($this->buildSearchQuery([
            'mn' => 'ABC001',
            'hk' => ['0'],
        ]));

        $rows = $this->extractListRows($response);
        $this->assertSame('product_edit.php', $rows[0]['product_edit_action']);
        $this->assertSame('history_edit.php', $rows[0]['history_edit_action']);
        $this->assertSame('21', $rows[0]['history_no']);
    }

    // 45 件の専用データを入れると 2 ページ目は 21 件目から表示される
    public function testHistoryListPaginationDisplaysSecondPageRows(): void
    {
        $this->prepareHistoryListAsAdmin();
        $this->insertHistoryListPaginationRows();

        $response = $this->getHistoryList([
            'df' => '2026-01-25',
            'dt' => '2026-01-25',
            'p' => '2',
            's' => '21',
            'pos' => '0',
        ]);

        $rows = $this->extractListRows($response);
        $this->assertCount(20, $rows);
        $this->assertSame('HLP021', $rows[0]['management_no']);
        $this->assertSame('HLP040', $rows[19]['management_no']);
        $this->assertActivePageNumber($response, 2);
    }

    // 最終ページでは残り 5 件だけが出て、前ページ導線も表示される
    public function testHistoryListPaginationShowsLastPageRowsAndPreviousButton(): void
    {
        $this->prepareHistoryListAsAdmin();
        $this->insertHistoryListPaginationRows();

        $response = $this->getHistoryList([
            'df' => '2026-01-25',
            'dt' => '2026-01-25',
            'p' => '3',
            's' => '21',
            'pos' => '0',
        ]);

        $rows = $this->extractListRows($response);
        $this->assertCount(5, $rows);
        $this->assertSame('HLP041', $rows[0]['management_no']);
        $this->assertSame('HLP045', $rows[4]['management_no']);
        $this->assertPreviousPageLinkEnabled($response);
    }

    /**
     * 一覧検索用の基本日付を付けたクエリを返す。
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

    private function prepareHistoryListAsAdmin(): void
    {
        $this->prepareHistoryListAs('admin', 'admin000');
    }

    private function prepareHistoryListAs(string $loginId, string $password): void
    {
        TestDatabase::seed();
        self::$client = new WebClient('http://localhost/noblestock/public');
        $this->loginAs($loginId, $password);
        $this->getClient()->get('menu.php');
    }

    /**
     * @param array<string, mixed> $query
     */
    private function openHistoryListAsAdmin(array $query = []): Response
    {
        $this->prepareHistoryListAsAdmin();

        return $this->getHistoryList($query);
    }

    /**
     * @param array<string, mixed> $query
     */
    private function getHistoryList(array $query = []): Response
    {
        $path = 'history_list.php';
        if ($query !== []) {
            $path .= '?' . http_build_query($query);
        }

        $response = $this->getClient()->get($path);
        $this->assertOk($response);

        return $response;
    }

    // 表示表を配列化しておくと、件数や並び順の確認を項目単位で書ける
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

            $managementText = $this->normalizeText($cellNodes->item(2)?->textContent ?? '');
            preg_match('/^(.+?)\s*\[(\d+)\]$/u', $managementText, $matches);

            $productEditForms = $xpath->query('.//form[@action="product_edit.php"]', $cellNodes->item(2));
            $historyEditForms = $xpath->query('.//form[@action="history_edit.php"]', $cellNodes->item(12));
            $historyNoNodes = $xpath->query('.//input[@name="HISTORY_NO"]', $cellNodes->item(12));

            $rows[] = [
                'date' => $this->normalizeText($cellNodes->item(0)?->textContent ?? ''),
                'history_kbn' => $this->normalizeText($cellNodes->item(1)?->textContent ?? ''),
                'management_no' => $matches[1] ?? $managementText,
                'branch_no' => isset($matches[2]) ? (int) $matches[2] : 0,
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

    private function assertSearchTextInputValue(Response $response, string $name, string $expectedValue): void
    {
        $xpath = new DOMXPath($response->dom());
        $nodes = $xpath->query("//form[@name='search']//input[@name='{$name}']");
        $this->assertNotFalse($nodes);
        $this->assertGreaterThan(0, $nodes->length, "Search input '{$name}' was not found.");

        $actual = (string) ($nodes->item(0)?->attributes?->getNamedItem('value')?->nodeValue ?? '');
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
            $selectedValues[] = (string) ($node->attributes?->getNamedItem('value')?->nodeValue ?? '');
        }

        sort($selectedValues);
        $expected = $expectedValues;
        sort($expected);
        $this->assertSame($expected, $selectedValues, "Unexpected selected values in '{$selectName}'.");
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

    private function assertRowsSortedBy(Response $response, string $columnKey, string $direction): void
    {
        $rows = $this->extractListRows($response);
        $this->assertNotEmpty($rows, 'No table rows were found for sort assertion.');

        $values = array_column($rows, $columnKey);
        $expected = $values;

        if (in_array($columnKey, ['branch_no', 'stock_in', 'quantity', 'move_stock', 'location_stock'], true)) {
            sort($expected, SORT_NUMERIC);
        } else {
            sort($expected, SORT_STRING);
        }

        if ($direction === 'desc') {
            $expected = array_reverse($expected);
        }

        $this->assertSame($expected, $values, "Rows are not sorted by {$columnKey} ({$direction}).");
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

    // 検索・ソート確認用に、1日だけに閉じた専用履歴を 3 件追加する
    private function insertHistoryListSearchRows(): void
    {
        $pdo = $this->createTestPdo();
        $stmt = $pdo->prepare(
            'INSERT INTO histories (
                history_yy, history_mm, history_dd, history_kbn, management_no, branch_no,
                category_name, maker_name, product_name, location_name,
                quantity, stock_in, move_stock, location_stock, stock, del_flg,
                created_at, created_by, updated_at, updated_by
            ) VALUES (
                2026, 1, :history_dd, :history_kbn, :management_no, :branch_no,
                :category_name, :maker_name, :product_name, :location_name,
                :quantity, :stock_in, :move_stock, :location_stock, :stock, 0,
                :created_at, :created_by, :updated_at, :updated_by
            )'
        );

        $rows = [
            [
                'history_dd' => 26,
                'history_kbn' => 0,
                'management_no' => 'ABC011',
                'branch_no' => 1,
                'category_name' => 'カテゴリ30',
                'maker_name' => 'メーカー10',
                'product_name' => 'カテゴリ検索用商品',
                'location_name' => '店舗40',
                'quantity' => 0,
                'stock_in' => 15,
                'move_stock' => 0,
                'location_stock' => 90,
                'stock' => 90,
                'created_at' => '2026-01-26 09:00:00',
                'created_by' => 'admin',
                'updated_at' => '2026-01-26 09:00:00',
                'updated_by' => 'admin',
            ],
            [
                'history_dd' => 26,
                'history_kbn' => 1,
                'management_no' => 'ABC012',
                'branch_no' => 1,
                'category_name' => 'カテゴリ10',
                'maker_name' => 'メーカー30',
                'product_name' => 'メーカー検索用商品',
                'location_name' => '店舗50',
                'quantity' => 7,
                'stock_in' => 0,
                'move_stock' => 0,
                'location_stock' => 50,
                'stock' => 50,
                'created_at' => '2026-01-26 10:00:00',
                'created_by' => 'perm10',
                'updated_at' => '2026-01-26 10:00:00',
                'updated_by' => 'perm10',
            ],
            [
                'history_dd' => 26,
                'history_kbn' => 7,
                'management_no' => 'ABC013',
                'branch_no' => 1,
                'category_name' => 'カテゴリ10',
                'maker_name' => 'メーカー10',
                'product_name' => '在庫変更検索用商品',
                'location_name' => '店舗60',
                'quantity' => 0,
                'stock_in' => 0,
                'move_stock' => 0,
                'location_stock' => 10,
                'stock' => 10,
                'created_at' => '2026-01-26 11:00:00',
                'created_by' => 'user1',
                'updated_at' => '2026-01-26 11:00:00',
                'updated_by' => 'user1',
            ],
        ];

        foreach ($rows as $row) {
            $stmt->execute($row);
        }
    }

    // ページネーション確認用に 45 件積み、2ページ目・最終ページの境目を安定させる
    private function insertHistoryListPaginationRows(): void
    {
        $pdo = $this->createTestPdo();
        $stmt = $pdo->prepare(
            'INSERT INTO histories (
                history_yy, history_mm, history_dd, history_kbn, management_no, branch_no,
                category_name, maker_name, product_name, location_name,
                quantity, stock_in, move_stock, location_stock, stock, del_flg,
                created_at, created_by, updated_at, updated_by
            ) VALUES (
                2026, 1, 25, 0, :management_no, 1,
                :category_name, :maker_name, :product_name, :location_name,
                0, :stock_in, 0, :location_stock, :stock, 0,
                :created_at, :created_by, :updated_at, :updated_by
            )'
        );

        for ($i = 1; $i <= 45; $i++) {
            $managementNo = sprintf('HLP%03d', $i);
            $stmt->execute([
                'management_no' => $managementNo,
                'category_name' => 'カテゴリ10',
                'maker_name' => 'メーカー10',
                'product_name' => '履歴一覧ページング商品' . sprintf('%03d', $i),
                'location_name' => '店舗10',
                'stock_in' => $i,
                'location_stock' => $i,
                'stock' => $i,
                'created_at' => sprintf('2026-01-25 10:%02d:00', $i % 60),
                'created_by' => 'admin',
                'updated_at' => sprintf('2026-01-25 10:%02d:00', $i % 60),
                'updated_by' => 'admin',
            ]);
        }
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
    public function testHistoryListClearsSessionDataWhenTimedOut(): void
    {
        $this->assertTimedOutProtectedPageClearsSessionDataAndShowsExpiredMessage('history_list.php');
    }
}
