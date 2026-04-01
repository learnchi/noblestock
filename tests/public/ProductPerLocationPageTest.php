<?php

declare(strict_types=1);

use Noblestock\Logic\MessageConst;
use Tests\Support\Response;
use Tests\Support\WebTestCase;

final class ProductPerLocationPageTest extends WebTestCase
{
    // ログインセッションをクリア後、この画面にアクセスすると、indexに遷移する
    public function testProductPerLocationRedirectsToIndex(): void
    {
        $this->getClient()->get('index.php');

        $response = $this->getClient()->get('product_per_location.php');
        $this->assertSame(200, $response->status, "Expected final 200 from {$response->url}");
        $this->assertStringContainsString('Location: index.php', $response->headers);
    }
    // ログインセッションをクリア後、この画面にアクセスすると、認証エラーメッセージ付きでindexに遷移する
    public function testProductPerLocationRedirectsToIndexWithSessionExpiredMessage(): void
    {
        $this->getClient()->get('index.php');

        $response = $this->getClient()->get('product_per_location.php');
        $this->assertSame(200, $response->status, "Expected final 200 from {$response->url}");
        $this->assertStringContainsString('Location: index.php', $response->headers);
        $this->assertStringContainsString(MessageConst::MSG_INF_AUTH_002, $response->body);
    }
    public function testProductPerLocationDisplays(): void
    {
        $this->loginAsAdmin();
        $response = $this->getClient()->get('product_per_location.php');
        $this->assertOk($response);
    }

    // この画面に権限があるユーザー[perm02]で200で表示される
    public function testProductPerLocationDisplaysForPerm02(): void
    {
        $this->loginAs('perm02', 'perm0200');

        $response = $this->getClient()->get('product_per_location.php');
        $this->assertOk($response);
    }

    // この画面に権限がないユーザーnoauthで403エラーとなる
    public function testProductPerLocationReturns403ForNoAuth(): void
    {
        $this->loginAs('noauth', 'noauth00');

        $response = $this->getClient()->get('product_per_location.php');
        $this->assertSame(403, $response->status, "Expected 403 from {$response->url}");
    }

    // 初期表示では検索条件が空のまま 4 件の在庫行が表示される。
    public function testNormalDisplayNoPostSearchFormIsEmpty(): void
    {
        $response = $this->openProductPerLocationAsAdmin();
        $this->assertSearchFormHasNoInputValues($response);
    }

    public function testNormalDisplayNoPostTableShows4Rows(): void
    {
        $response = $this->openProductPerLocationAsAdmin();
        $this->assertTableRowCount($response, 4);
    }

    public function testNormalDisplayNoPostShowsSearchResult4(): void
    {
        $response = $this->openProductPerLocationAsAdmin();
        $this->assertSearchResultCount($response, 4);
    }

    // 検索ボタンだけ押したケースでも、初期表示と同じ状態が維持される。
    public function testEmptySearchSubmitSearchFormIsEmpty(): void
    {
        $response = $this->openProductPerLocationAsAdmin($this->emptySearchSubmitQuery());
        $this->assertSearchFormHasNoInputValues($response);
    }

    public function testEmptySearchSubmitTableShows4Rows(): void
    {
        $response = $this->openProductPerLocationAsAdmin($this->emptySearchSubmitQuery());
        $this->assertTableRowCount($response, 4);
    }

    public function testEmptySearchSubmitShowsSearchResult4(): void
    {
        $response = $this->openProductPerLocationAsAdmin($this->emptySearchSubmitQuery());
        $this->assertSearchResultCount($response, 4);
    }

    public function testSearchByManagementNoAbc001ShowsInputValueInForm(): void
    {
        $response = $this->openProductPerLocationAsAdmin($this->searchQuery(['mn' => 'ABC001']));
        $this->assertSearchTextInputValue($response, 'mn', 'ABC001');
    }

    public function testSearchByManagementNoAbc001Shows1RowInTable(): void
    {
        $response = $this->openProductPerLocationAsAdmin($this->searchQuery(['mn' => 'ABC001']));
        $this->assertTableRowCount($response, 1);
    }

    public function testSearchByManagementNoAbc001ShowsSearchResult1(): void
    {
        $response = $this->openProductPerLocationAsAdmin($this->searchQuery(['mn' => 'ABC001']));
        $this->assertSearchResultCount($response, 1);
    }

    public function testSearchByManagementNoAbc000ShowsInputValueInForm(): void
    {
        $response = $this->openProductPerLocationAsAdmin($this->searchQuery(['mn' => 'ABC000']));
        $this->assertSearchTextInputValue($response, 'mn', 'ABC000');
    }

    public function testSearchByManagementNoAbc000ShowsNoDataMessage(): void
    {
        $response = $this->openProductPerLocationAsAdmin($this->searchQuery(['mn' => 'ABC000']));
        $this->assertStringContainsString(MessageConst::MSG_VAL_LIST_001, $response->body);
    }

    public function testSearchByManagementNoAbc000Shows0RowsInTable(): void
    {
        $response = $this->openProductPerLocationAsAdmin($this->searchQuery(['mn' => 'ABC000']));
        $this->assertTableRowCount($response, 0);
    }

    public function testSearchByManagementNoAbc000ShowsSearchResult0(): void
    {
        $response = $this->openProductPerLocationAsAdmin($this->searchQuery(['mn' => 'ABC000']));
        $this->assertSearchResultCount($response, 0);
    }

    public function testSearchByLocation10ShowsSelectedValueInForm(): void
    {
        $response = $this->openProductPerLocationAsAdmin($this->searchQuery(['ln' => ['10']]));
        $this->assertSearchSelectValues($response, 'ln[]', ['10']);
    }

    public function testSearchByLocation10Shows1RowInTable(): void
    {
        $response = $this->openProductPerLocationAsAdmin($this->searchQuery(['ln' => ['10']]));
        $this->assertTableRowCount($response, 1);
    }

    public function testSearchByLocation10ShowsSearchResult1(): void
    {
        $response = $this->openProductPerLocationAsAdmin($this->searchQuery(['ln' => ['10']]));
        $this->assertSearchResultCount($response, 1);
    }

    public function testSearchByLocation20And30ShowsSelectedValuesInForm(): void
    {
        $response = $this->openProductPerLocationAsAdmin($this->searchQuery(['ln' => ['20', '30']]));
        $this->assertSearchSelectValues($response, 'ln[]', ['20', '30']);
    }

    public function testSearchByLocation20And30Shows3RowsInTable(): void
    {
        $response = $this->openProductPerLocationAsAdmin($this->searchQuery(['ln' => ['20', '30']]));
        $this->assertTableRowCount($response, 3);
    }

    public function testSearchByLocation20And30ShowsSearchResult3(): void
    {
        $response = $this->openProductPerLocationAsAdmin($this->searchQuery(['ln' => ['20', '30']]));
        $this->assertSearchResultCount($response, 3);
    }

    public function testSearchByCategory30ShowsSelectedValueInForm(): void
    {
        $response = $this->openProductPerLocationAsAdmin($this->searchQuery(['cn' => ['30']]));
        $this->assertSearchSelectValues($response, 'cn[]', ['30']);
    }

    public function testSearchByCategory30Shows2RowsInTable(): void
    {
        $response = $this->openProductPerLocationAsAdmin($this->searchQuery(['cn' => ['30']]));
        $this->assertTableRowCount($response, 2);
    }

    public function testSearchByCategory30ShowsSearchResult2(): void
    {
        $response = $this->openProductPerLocationAsAdmin($this->searchQuery(['cn' => ['30']]));
        $this->assertSearchResultCount($response, 2);
    }

    public function testSearchByMaker30ShowsSelectedValueInForm(): void
    {
        $response = $this->openProductPerLocationAsAdmin($this->searchQuery(['mk' => ['30']]));
        $this->assertSearchSelectValues($response, 'mk[]', ['30']);
    }

    public function testSearchByMaker30Shows2RowsInTable(): void
    {
        $response = $this->openProductPerLocationAsAdmin($this->searchQuery(['mk' => ['30']]));
        $this->assertTableRowCount($response, 2);
    }

    public function testSearchByMaker30ShowsSearchResult2(): void
    {
        $response = $this->openProductPerLocationAsAdmin($this->searchQuery(['mk' => ['30']]));
        $this->assertSearchResultCount($response, 2);
    }

    public function testSearchByProductNameExactShowsInputValueInForm(): void
    {
        $response = $this->openProductPerLocationAsAdmin($this->searchQuery(['prn' => 'ABC002']));
        $this->assertSearchTextInputValue($response, 'prn', 'ABC002');
    }

    public function testSearchByProductNameExactShows1RowInTable(): void
    {
        $response = $this->openProductPerLocationAsAdmin($this->searchQuery(['prn' => 'ABC002']));
        $this->assertTableRowCount($response, 1);
    }

    public function testSearchByProductNameExactShowsSearchResult1(): void
    {
        $response = $this->openProductPerLocationAsAdmin($this->searchQuery(['prn' => 'ABC002']));
        $this->assertSearchResultCount($response, 1);
    }

    public function testSearchByProductNameLikeShowsInputValueInForm(): void
    {
        $response = $this->openProductPerLocationAsAdmin($this->searchQuery(['prn' => 'ABC']));
        $this->assertSearchTextInputValue($response, 'prn', 'ABC');
    }

    public function testSearchByProductNameLikeShows4RowsInTable(): void
    {
        $response = $this->openProductPerLocationAsAdmin($this->searchQuery(['prn' => 'ABC']));
        $this->assertTableRowCount($response, 4);
    }

    public function testSearchByProductNameLikeShowsSearchResult4(): void
    {
        $response = $this->openProductPerLocationAsAdmin($this->searchQuery(['prn' => 'ABC']));
        $this->assertSearchResultCount($response, 4);
    }

    public function testSearchByStoragePlaceExactShowsInputValueInForm(): void
    {
        $response = $this->openProductPerLocationAsAdmin($this->searchQuery(['sh' => '03']));
        $this->assertSearchTextInputValue($response, 'sh', '03');
    }

    public function testSearchByStoragePlaceExactShows2RowsInTable(): void
    {
        $response = $this->openProductPerLocationAsAdmin($this->searchQuery(['sh' => '03']));
        $this->assertTableRowCount($response, 2);
    }

    public function testSearchByStoragePlaceExactShowsSearchResult2(): void
    {
        $response = $this->openProductPerLocationAsAdmin($this->searchQuery(['sh' => '03']));
        $this->assertSearchResultCount($response, 2);
    }

    public function testSearchByStoragePlaceLikeShowsInputValueInForm(): void
    {
        $keyword = '保管場所';
        $response = $this->openProductPerLocationAsAdmin($this->searchQuery(['sh' => $keyword]));
        $this->assertSearchTextInputValue($response, 'sh', $keyword);
    }

    public function testSearchByStoragePlaceLikeShows4RowsInTable(): void
    {
        $keyword = '保管場所';
        $response = $this->openProductPerLocationAsAdmin($this->searchQuery(['sh' => $keyword]));
        $this->assertTableRowCount($response, 4);
    }

    public function testSearchByStoragePlaceLikeShowsSearchResult4(): void
    {
        $keyword = '保管場所';
        $response = $this->openProductPerLocationAsAdmin($this->searchQuery(['sh' => $keyword]));
        $this->assertSearchResultCount($response, 4);
    }

    public function testSearchByRemarks01ShowsInputValueInForm(): void
    {
        $response = $this->openProductPerLocationAsAdmin($this->searchQuery(['rm' => '01']));
        $this->assertSearchTextInputValue($response, 'rm', '01');
    }

    public function testSearchByRemarks01ShowsNoDataMessage(): void
    {
        $response = $this->openProductPerLocationAsAdmin($this->searchQuery(['rm' => '01']));
        $this->assertStringContainsString(MessageConst::MSG_VAL_LIST_001, $response->body);
    }

    public function testSearchByRemarks01Shows0RowsInTable(): void
    {
        $response = $this->openProductPerLocationAsAdmin($this->searchQuery(['rm' => '01']));
        $this->assertTableRowCount($response, 0);
    }

    public function testSearchByRemarks01ShowsSearchResult0(): void
    {
        $response = $this->openProductPerLocationAsAdmin($this->searchQuery(['rm' => '01']));
        $this->assertSearchResultCount($response, 0);
    }

    // 店舗別一覧は管理番号昇順が既定なので、初期表示でその並びを確認する。
    public function testSortDefaultShowsManagementAscWithHeaderButtons(): void
    {
        $response = $this->openProductPerLocationAsAdmin();
        $this->assertSortHeaderIndicator($response);
        $this->assertRowsSortedBy($response, 'management_no', 'asc');
    }

    public function testSortLocationAscShowsHeaderButtonsAndAscending(): void
    {
        $response = $this->openProductPerLocationAsAdmin(['s' => '19']);
        $this->assertSortHeaderIndicator($response);
        $this->assertRowsSortedBy($response, 'shop_name', 'asc');
    }

    public function testSortLocationDescShowsHeaderButtonsAndDescending(): void
    {
        $response = $this->openProductPerLocationAsAdmin(['s' => '20']);
        $this->assertSortHeaderIndicator($response);
        $this->assertRowsSortedBy($response, 'shop_name', 'desc');
    }

    public function testSortManagementDescShowsHeaderButtonsAndDescending(): void
    {
        $response = $this->openProductPerLocationAsAdmin(['s' => '6']);
        $this->assertSortHeaderIndicator($response);
        $this->assertRowsSortedBy($response, 'management_no', 'desc');
    }

    public function testSortCategoryAscShowsHeaderButtonsAndAscending(): void
    {
        $response = $this->openProductPerLocationAsAdmin(['s' => '7']);
        $this->assertSortHeaderIndicator($response);
        $this->assertRowsSortedBy($response, 'category_name', 'asc');
    }

    // カテゴリ昇順ではカテゴリ名が同じ行も管理番号昇順で安定して並ぶことを確認する
    public function testSortCategoryAscOrdersTiedRowsByManagementNo(): void
    {
        $this->preparePaginationTestProductsPerLocation();
        try {
            $response = $this->openProductPerLocationAsAdmin(['s' => '7']);
            $this->assertManagementNosStartWith($response, [
                'ABC001',
                'ABC011',
                'ABC021',
                'ABC031',
                'ABC041',
            ]);
        } finally {
            \Tests\Support\TestDatabase::seed();
        }
    }

    public function testSortCategoryDescShowsHeaderButtonsAndDescending(): void
    {
        $response = $this->openProductPerLocationAsAdmin(['s' => '8']);
        $this->assertSortHeaderIndicator($response);
        $this->assertRowsSortedBy($response, 'category_name', 'desc');
    }

    public function testSortMakerAscShowsHeaderButtonsAndAscending(): void
    {
        $response = $this->openProductPerLocationAsAdmin(['s' => '9']);
        $this->assertSortHeaderIndicator($response);
        $this->assertRowsSortedBy($response, 'maker_name', 'asc');
    }

    public function testSortMakerDescShowsHeaderButtonsAndDescending(): void
    {
        $response = $this->openProductPerLocationAsAdmin(['s' => '10']);
        $this->assertSortHeaderIndicator($response);
        $this->assertRowsSortedBy($response, 'maker_name', 'desc');
    }

    public function testSortProductNameAscShowsHeaderButtonsAndAscending(): void
    {
        $response = $this->openProductPerLocationAsAdmin(['s' => '3']);
        $this->assertSortHeaderIndicator($response);
        $this->assertRowsSortedBy($response, 'product_name', 'asc');
    }

    public function testSortProductNameDescShowsHeaderButtonsAndDescending(): void
    {
        $response = $this->openProductPerLocationAsAdmin(['s' => '4']);
        $this->assertSortHeaderIndicator($response);
        $this->assertRowsSortedBy($response, 'product_name', 'desc');
    }

    public function testSortWholesaleAscShowsHeaderButtonsAndAscending(): void
    {
        $response = $this->openProductPerLocationAsAdmin(['s' => '15']);
        $this->assertSortHeaderIndicator($response);
        $this->assertRowsSortedBy($response, 'wholesale_amount', 'asc');
    }

    public function testSortWholesaleDescShowsHeaderButtonsAndDescending(): void
    {
        $response = $this->openProductPerLocationAsAdmin(['s' => '16']);
        $this->assertSortHeaderIndicator($response);
        $this->assertRowsSortedBy($response, 'wholesale_amount', 'desc');
    }

    public function testSortRetailAscShowsHeaderButtonsAndAscending(): void
    {
        $response = $this->openProductPerLocationAsAdmin(['s' => '17']);
        $this->assertSortHeaderIndicator($response);
        $this->assertRowsSortedBy($response, 'retail_amount', 'asc');
    }

    public function testSortRetailDescShowsHeaderButtonsAndDescending(): void
    {
        $response = $this->openProductPerLocationAsAdmin(['s' => '18']);
        $this->assertSortHeaderIndicator($response);
        $this->assertRowsSortedBy($response, 'retail_amount', 'desc');
    }

    public function testSortQuantityAscShowsHeaderButtonsAndAscending(): void
    {
        $response = $this->openProductPerLocationAsAdmin(['s' => '11']);
        $this->assertSortHeaderIndicator($response);
        $this->assertRowsSortedBy($response, 'quantity', 'asc');
    }

    public function testSortQuantityDescShowsHeaderButtonsAndDescending(): void
    {
        $response = $this->openProductPerLocationAsAdmin(['s' => '12']);
        $this->assertSortHeaderIndicator($response);
        $this->assertRowsSortedBy($response, 'quantity', 'desc');
    }

    // ページング検証では 520 件の在庫データを投入して 26 ページ構成を再現する。
    public function testPaginationInitialDisplayShows520ResultsAndPage6Link(): void
    {
        $this->preparePaginationTestProductsPerLocation();
        try {
            $response = $this->openProductPerLocationAsAdmin();
            $this->assertSearchResultCount($response, 520);
            $this->assertTableRowCount($response, 20);
            $this->assertPaginationIsVisible($response);
            $this->assertPageNumberIsLink($response, 6);
        } finally {
            \Tests\Support\TestDatabase::seed();
        }
    }

    public function testPaginationInitialDisplayDisablesPreviousAndPage1(): void
    {
        $this->preparePaginationTestProductsPerLocation();
        try {
            $response = $this->openProductPerLocationAsAdmin();
            $this->assertActivePageNumber($response, 1);
            $this->assertPreviousPageLinkDisabled($response);
            $this->assertActivePageIsNotClickable($response, 1);
        } finally {
            \Tests\Support\TestDatabase::seed();
        }
    }

    public function testPaginationSearchByProductKeywordShowsPage1WithScrollTopAndKeepsCondition(): void
    {
        $this->preparePaginationTestProductsPerLocation();
        try {
            $keyword = '商品';
            $response = $this->openProductPerLocationAsAdmin([
                'pos' => '0',
                's' => '5',
                'p' => '1',
                'mn' => '',
                'prn' => $keyword,
                'sh' => '',
                'rm' => '',
            ]);
            $this->assertActivePageNumber($response, 1);
            $this->assertScrollPositionZero($response);
            $this->assertSearchTextInputValue($response, 'prn', $keyword);
            $this->assertSearchResultCount($response, 520);
        } finally {
            \Tests\Support\TestDatabase::seed();
        }
    }

    public function testPaginationClickLinkedPageNumberShowsTargetPageData(): void
    {
        $this->preparePaginationTestProductsPerLocation();
        try {
            $initial = $this->openProductPerLocationAsAdmin();
            $this->assertPageNumberIsLink($initial, 6);

            $response = $this->openProductPerLocationAsAdmin(['p' => '6']);
            $this->assertActivePageNumber($response, 6);
            $this->assertTableRowCount($response, 20);
            $this->assertTableManagementNoRange($response, 'ABC101', 'ABC120');
        } finally {
            \Tests\Support\TestDatabase::seed();
        }
    }

    public function testPaginationClickLinkedPageNumberResetsScrollTop(): void
    {
        $this->preparePaginationTestProductsPerLocation();
        try {
            $response = $this->openProductPerLocationAsAdmin(['p' => '6', 'pos' => '0']);
            $this->assertActivePageNumber($response, 6);
            $this->assertScrollPositionZero($response);
        } finally {
            \Tests\Support\TestDatabase::seed();
        }
    }

    public function testPaginationNonFirstPageDisablesCurrentPageAndEnablesPrevious(): void
    {
        $this->preparePaginationTestProductsPerLocation();
        try {
            $response = $this->openProductPerLocationAsAdmin(['p' => '2']);
            $this->assertActivePageNumber($response, 2);
            $this->assertActivePageIsNotClickable($response, 2);
            $this->assertPreviousPageLinkEnabled($response);
        } finally {
            \Tests\Support\TestDatabase::seed();
        }
    }

    public function testPaginationLastPageDisablesNext(): void
    {
        $this->preparePaginationTestProductsPerLocation();
        try {
            $response = $this->openProductPerLocationAsAdmin(['p' => '26']);
            $this->assertActivePageNumber($response, 26);
            $this->assertNextPageLinkDisabled($response);
            $this->assertTableManagementNoRange($response, 'ABC501', 'ABC520');
        } finally {
            \Tests\Support\TestDatabase::seed();
        }
    }

    public function testPaginationAfterPageTransitionSortReturnsToPage1AndScrollTop(): void
    {
        $this->preparePaginationTestProductsPerLocation();
        try {
            $page6 = $this->openProductPerLocationAsAdmin(['p' => '6']);
            $this->assertActivePageNumber($page6, 6);

            $sorted = $this->openProductPerLocationAsAdmin([
                's' => '6',
                'p' => '1',
                'pos' => '0',
            ]);
            $this->assertActivePageNumber($sorted, 1);
            $this->assertScrollPositionZero($sorted);
            $this->assertRowsSortedBy($sorted, 'management_no', 'desc');
        } finally {
            \Tests\Support\TestDatabase::seed();
        }
    }

    private function emptySearchSubmitQuery(): array
    {
        return [
            'pos' => '0',
            's' => '5',
            'p' => '1',
            'mn' => '',
            'prn' => '',
            'sh' => '',
            'rm' => '',
        ];
    }

    /**
     * 検索フォーム送信に近い形で、空のテキスト入力も含めたクエリを組み立てる。
     *
     * @param array<string, mixed> $overrides
     * @return array<string, mixed>
     */
    private function searchQuery(array $overrides): array
    {
        return array_merge($this->emptySearchSubmitQuery(), $overrides);
    }

    /**
     * @param array<string, mixed> $query
     */
    private function openProductPerLocationAsAdmin(array $query = []): Response
    {
        $this->loginAsAdmin();
        $this->delSessionStructData('biz009', null, 'product_per_location.php');

        $path = 'product_per_location.php';
        if ($query !== []) {
            $path .= '?' . http_build_query($query);
        }

        $response = $this->getClient()->get($path);
        $this->assertOk($response);

        return $response;
    }

    private function assertSearchFormHasNoInputValues(Response $response): void
    {
        $this->assertSearchTextInputValue($response, 'mn', '');
        $this->assertSearchTextInputValue($response, 'prn', '');
        $this->assertSearchTextInputValue($response, 'sh', '');
        $this->assertSearchTextInputValue($response, 'rm', '');
        $this->assertSearchSelectValues($response, 'ln[]', []);
        $this->assertSearchSelectValues($response, 'cn[]', []);
        $this->assertSearchSelectValues($response, 'mk[]', []);
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

    private function assertTableRowCount(Response $response, int $expectedCount): void
    {
        $xpath = new DOMXPath($response->dom());
        $rows = $xpath->query("//div[@id='table-wrapper']//table[contains(@class,'table')]//tbody/tr");
        $this->assertNotFalse($rows);
        $this->assertSame($expectedCount, $rows->length, 'Unexpected table row count.');
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

    private function assertSortHeaderIndicator(Response $response): void
    {
        $xpath = new DOMXPath($response->dom());
        $nodes = $xpath->query("//div[@id='table-wrapper']//table[contains(@class,'table')]//thead//button");
        $this->assertNotFalse($nodes);
        $this->assertGreaterThan(0, $nodes->length, 'Sort header button was not found.');
    }

    private function assertRowsSortedBy(Response $response, string $columnKey, string $direction): void
    {
        $rows = $this->extractTableRows($response);
        $this->assertNotEmpty($rows, 'No table rows were found for sort assertion.');

        $values = array_column($rows, $columnKey);
        $expected = $values;

        if (in_array($columnKey, ['wholesale_amount', 'retail_amount', 'quantity'], true)) {
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
     * @return array<int, array<string, int|string>>
     */
    private function extractTableRows(Response $response): array
    {
        $dom = $response->dom();
        $xpath = new DOMXPath($dom);
        $rowNodes = $xpath->query("//div[@id='table-wrapper']//table[contains(@class,'table')]//tbody/tr");
        $this->assertNotFalse($rowNodes);

        $rows = [];
        foreach ($rowNodes as $rowNode) {
            $cellNodes = $xpath->query('./td', $rowNode);
            $this->assertNotFalse($cellNodes);
            if ($cellNodes->length < 8) {
                continue;
            }

            $rows[] = [
                'shop_name' => $this->normalizeText($cellNodes->item(0)?->textContent ?? ''),
                'management_no' => $this->normalizeText($cellNodes->item(1)?->textContent ?? ''),
                'category_name' => $this->normalizeText($cellNodes->item(2)?->textContent ?? ''),
                'maker_name' => $this->normalizeText($cellNodes->item(3)?->textContent ?? ''),
                'product_name' => $this->normalizeText($cellNodes->item(4)?->textContent ?? ''),
                'wholesale_amount' => $this->extractInt($cellNodes->item(5)?->textContent ?? ''),
                'retail_amount' => $this->extractInt($cellNodes->item(6)?->textContent ?? ''),
                'quantity' => $this->extractInt($cellNodes->item(7)?->textContent ?? ''),
            ];
        }

        return $rows;
    }

    private function preparePaginationTestProductsPerLocation(): void
    {
        \Tests\Support\TestDatabase::seed();

        $configPath = dirname(__DIR__, 2) . '/config/dbconfig.ini';
        $config = parse_ini_file($configPath);
        $this->assertIsArray($config, 'Failed to read dbconfig.ini for pagination test data.');
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

        $pdo->exec('SET FOREIGN_KEY_CHECKS=0');
        try {
            $pdo->exec('DELETE FROM stocks');
            $pdo->exec('DELETE FROM products');
        } finally {
            $pdo->exec('SET FOREIGN_KEY_CHECKS=1');
        }

        $productStmt = $pdo->prepare(
            'INSERT INTO products (
                management_no, category_id, maker_id, product_name,
                wholesale_amount, retail_amount, sell_amount, quantity, unit_id,
                storage_place, image_file, remarks, remarks2,
                created_at, created_by, updated_at, updated_by
            ) VALUES (
                :management_no, :category_id, :maker_id, :product_name,
                :wholesale_amount, :retail_amount, :sell_amount, :quantity, :unit_id,
                :storage_place, :image_file, :remarks, :remarks2,
                :created_at, :created_by, :updated_at, :updated_by
            )'
        );
        $stockStmt = $pdo->prepare(
            'INSERT INTO stocks (
                management_no, location_id, quantity, remarks,
                created_at, created_by, updated_at, updated_by
            ) VALUES (
                :management_no, :location_id, :quantity, :remarks,
                :created_at, :created_by, :updated_at, :updated_by
            )'
        );

        $productPrefix = '商品';
        $createdAt = '2026-03-06 00:00:00';
        $createdBy = 'admin';
        $locationIds = [10, 20, 30, 40, 50, 60, 70, 80, 90, 100];

        $pdo->beginTransaction();
        try {
            for ($i = 1; $i <= 520; $i++) {
                $managementNo = sprintf('ABC%03d', $i);
                $categoryId = ((($i - 1) % 10) + 1) * 10;
                $makerId = ((($i + 3) % 10) + 1) * 10;
                $unitId = ((($i - 1) % 8) + 1) * 10;
                $wholesale = 1000 + $i;
                $retail = $wholesale + 200;

                $productStmt->execute([
                    'management_no' => $managementNo,
                    'category_id' => $categoryId,
                    'maker_id' => $makerId,
                    'product_name' => $productPrefix . sprintf('%03d', $i),
                    'wholesale_amount' => $wholesale,
                    'retail_amount' => $retail,
                    'sell_amount' => $retail,
                    'quantity' => 0,
                    'unit_id' => $unitId,
                    'storage_place' => 'STORAGE' . sprintf('%02d', ((($i - 1) % 20) + 1)),
                    'image_file' => '',
                    'remarks' => 'note' . sprintf('%03d', $i),
                    'remarks2' => 'memo' . sprintf('%03d', $i),
                    'created_at' => $createdAt,
                    'created_by' => $createdBy,
                    'updated_at' => $createdAt,
                    'updated_by' => $createdBy,
                ]);

                $stockStmt->execute([
                    'management_no' => $managementNo,
                    'location_id' => $locationIds[($i - 1) % count($locationIds)],
                    'quantity' => $i,
                    'remarks' => null,
                    'created_at' => $createdAt,
                    'created_by' => $createdBy,
                    'updated_at' => $createdAt,
                    'updated_by' => $createdBy,
                ]);
            }
            $pdo->commit();
        } catch (\Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $e;
        }
    }

    private function assertPaginationIsVisible(Response $response): void
    {
        $xpath = new DOMXPath($response->dom());
        $nodes = $xpath->query("//nav[@aria-label='Page navigation']//ul[contains(@class,'pagination')]");
        $this->assertNotFalse($nodes);
        $this->assertGreaterThan(0, $nodes->length, 'Pagination was not found.');
    }

    private function assertPageNumberIsLink(Response $response, int $page): void
    {
        $xpath = new DOMXPath($response->dom());
        $nodes = $xpath->query("//nav[@aria-label='Page navigation']//button[normalize-space(text())='{$page}']");
        $this->assertNotFalse($nodes);
        $this->assertGreaterThan(0, $nodes->length, "Page {$page} link button was not found.");
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

    private function assertActivePageIsNotClickable(Response $response, int $page): void
    {
        $xpath = new DOMXPath($response->dom());
        $activeNodes = $xpath->query(
            "//nav[@aria-label='Page navigation']//li[contains(@class,'active')]/a[normalize-space(text())='{$page}']"
        );
        $this->assertNotFalse($activeNodes);
        $this->assertGreaterThan(0, $activeNodes->length, "Active page {$page} was not found.");

        $buttonNodes = $xpath->query("//nav[@aria-label='Page navigation']//button[normalize-space(text())='{$page}']");
        $this->assertNotFalse($buttonNodes);
        $this->assertSame(0, $buttonNodes->length, "Active page {$page} should not be clickable.");
    }

    private function assertPreviousPageLinkDisabled(Response $response): void
    {
        $xpath = new DOMXPath($response->dom());
        $enabled = $xpath->query("//nav[@aria-label='Page navigation']//button[@aria-label='Previous']");
        $this->assertNotFalse($enabled);
        $this->assertSame(0, $enabled->length, 'Previous page button should be disabled.');

        $disabled = $xpath->query(
            "//nav[@aria-label='Page navigation']//li[contains(@class,'disabled')]/a[contains(@class,'page-link') and not(@aria-label='Next')]"
        );
        $this->assertNotFalse($disabled);
        $this->assertGreaterThan(0, $disabled->length, 'Disabled previous page element was not found.');
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

    private function assertScrollPositionZero(Response $response): void
    {
        $this->assertMatchesRegularExpression('/const\s+scrollPos\s*=\s*0\s*;/', $response->body);
    }

    private function assertTableManagementNoRange(Response $response, string $expectedFirst, string $expectedLast): void
    {
        $rows = $this->extractTableRows($response);
        $this->assertNotEmpty($rows, 'No table rows were found.');

        $first = (string) ($rows[0]['management_no'] ?? '');
        $last = (string) ($rows[array_key_last($rows)]['management_no'] ?? '');
        $this->assertSame($expectedFirst, $first, 'Unexpected first management number.');
        $this->assertSame($expectedLast, $last, 'Unexpected last management number.');
    }

    /**
     * @param array<int, string> $expectedManagementNos
     */
    // 一覧の先頭が期待した管理番号の並びになっていることを確認する
    private function assertManagementNosStartWith(Response $response, array $expectedManagementNos): void
    {
        $actual = array_column($this->extractTableRows($response), 'management_no');
        $this->assertSame($expectedManagementNos, array_slice($actual, 0, count($expectedManagementNos)));
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
    // セッション期限切れ時に認証情報と画面用セッションが破棄されることを確認する
    public function testProductPerLocationClearsSessionDataWhenTimedOut(): void
    {
        $this->assertTimedOutProtectedPageClearsSessionDataAndShowsExpiredMessage('product_per_location.php');
    }
}
