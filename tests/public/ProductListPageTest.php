<?php

declare(strict_types=1);

use Noblestock\Logic\MessageConst;
use Tests\Support\BarcodeOutputSelectionHelper;
use Tests\Support\Response;
use Tests\Support\WebTestCase;

final class ProductListPageTest extends WebTestCase
{
    use BarcodeOutputSelectionHelper;

    public function testProductListRedirectsToIndex(): void
    {
        $this->getClient()->get('index.php');

        $response = $this->getClient()->get('product_list.php');
        $this->assertSame(200, $response->status, "Expected final 200 from {$response->url}");
        $this->assertStringContainsString('Location: index.php', $response->headers);
    }

    public function testProductListRedirectsToIndexWithSessionExpiredMessage(): void
    {
        $this->getClient()->get('index.php');

        $response = $this->getClient()->get('product_list.php');
        $this->assertSame(200, $response->status, "Expected final 200 from {$response->url}");
        $this->assertStringContainsString('Location: index.php', $response->headers);
        $this->assertStringContainsString(MessageConst::MSG_INF_AUTH_002, $response->body);
    }

    public function testProductListDisplays(): void
    {
        $this->loginAsAdmin();
        $response = $this->getClient()->get('product_list.php');
        $this->assertOk($response);
    }

    public function testProductListDisplaysForPerm01(): void
    {
        $this->loginAs('perm01', 'perm0100');

        $response = $this->getClient()->get('product_list.php');
        $this->assertOk($response);
    }

    public function testProductListReturns403ForNoAuth(): void
    {
        $this->loginAs('noauth', 'noauth00');

        $response = $this->getClient()->get('product_list.php');
        $this->assertSame(403, $response->status, "Expected 403 from {$response->url}");
    }

    // ここから検索フォームと一覧件数の確認。
    public function testNormalDisplayNoPostSearchFormIsEmpty(): void
    {
        $response = $this->openProductListAsAdmin();
        $this->assertSearchFormHasNoInputValues($response);
    }

    public function testNormalDisplayNoPostTableShows20Rows(): void
    {
        $response = $this->openProductListAsAdmin();
        $this->assertTableRowCount($response, 20);
    }

    public function testNormalDisplayNoPostShowsSearchResult20(): void
    {
        $response = $this->openProductListAsAdmin();
        $this->assertSearchResultCount($response, 20);
    }

    public function testEmptySearchSubmitSearchFormIsEmpty(): void
    {
        $response = $this->openProductListAsAdmin($this->emptySearchSubmitQuery());
        $this->assertSearchFormHasNoInputValues($response);
    }

    public function testEmptySearchSubmitTableShows20Rows(): void
    {
        $response = $this->openProductListAsAdmin($this->emptySearchSubmitQuery());
        $this->assertTableRowCount($response, 20);
    }

    public function testEmptySearchSubmitShowsSearchResult20(): void
    {
        $response = $this->openProductListAsAdmin($this->emptySearchSubmitQuery());
        $this->assertSearchResultCount($response, 20);
    }

    public function testSearchByManagementNoAbc001ShowsInputValueInForm(): void
    {
        $response = $this->openProductListAsAdmin(['mn' => 'ABC001']);
        $this->assertSearchTextInputValue($response, 'mn', 'ABC001');
    }

    public function testSearchByManagementNoAbc001Shows1RowInTable(): void
    {
        $response = $this->openProductListAsAdmin(['mn' => 'ABC001']);
        $this->assertTableRowCount($response, 1);
    }

    public function testSearchByManagementNoAbc001ShowsSearchResult1(): void
    {
        $response = $this->openProductListAsAdmin(['mn' => 'ABC001']);
        $this->assertSearchResultCount($response, 1);
    }

    public function testSearchByManagementNoAbc000ShowsInputValueInForm(): void
    {
        $response = $this->openProductListAsAdmin(['mn' => 'ABC000']);
        $this->assertSearchTextInputValue($response, 'mn', 'ABC000');
    }

    public function testSearchByManagementNoAbc000ShowsNoDataMessage(): void
    {
        $response = $this->openProductListAsAdmin(['mn' => 'ABC000']);
        $this->assertStringContainsString(MessageConst::MSG_VAL_LIST_001, $response->body);
    }

    public function testSearchByManagementNoAbc000Shows0RowsInTable(): void
    {
        $response = $this->openProductListAsAdmin(['mn' => 'ABC000']);
        $this->assertTableRowCount($response, 0);
    }

    public function testSearchByManagementNoAbc000ShowsSearchResult0(): void
    {
        $response = $this->openProductListAsAdmin(['mn' => 'ABC000']);
        $this->assertSearchResultCount($response, 0);
    }

    public function testSearchByProductNameExactShowsInputValueInForm(): void
    {
        $response = $this->openProductListAsAdmin(['prn' => 'ABC002']);
        $this->assertSearchTextInputValue($response, 'prn', 'ABC002');
    }

    public function testSearchByProductNameExactShows1RowInTable(): void
    {
        $response = $this->openProductListAsAdmin(['prn' => 'ABC002']);
        $this->assertTableRowCount($response, 1);
    }

    public function testSearchByProductNameExactShowsSearchResult1(): void
    {
        $response = $this->openProductListAsAdmin(['prn' => 'ABC002']);
        $this->assertSearchResultCount($response, 1);
    }

    public function testSearchByProductNameLikeShowsInputValueInForm(): void
    {
        $response = $this->openProductListAsAdmin(['prn' => 'ABC']);
        $this->assertSearchTextInputValue($response, 'prn', 'ABC');
    }

    public function testSearchByProductNameLikeShows20RowsInTable(): void
    {
        $response = $this->openProductListAsAdmin(['prn' => 'ABC']);
        $this->assertTableRowCount($response, 20);
    }

    public function testSearchByProductNameLikeShowsSearchResult20(): void
    {
        $response = $this->openProductListAsAdmin(['prn' => 'ABC']);
        $this->assertSearchResultCount($response, 20);
    }

    public function testSearchByStoragePlaceExactShowsInputValueInForm(): void
    {
        $response = $this->openProductListAsAdmin(['sh' => '03']);
        $this->assertSearchTextInputValue($response, 'sh', '03');
    }

    public function testSearchByStoragePlaceExactShows2RowsInTable(): void
    {
        $response = $this->openProductListAsAdmin(['sh' => '03']);
        $this->assertTableRowCount($response, 2);
    }

    public function testSearchByStoragePlaceExactShowsSearchResult2(): void
    {
        $response = $this->openProductListAsAdmin(['sh' => '03']);
        $this->assertSearchResultCount($response, 2);
    }

    public function testSearchByStoragePlaceLikeShowsInputValueInForm(): void
    {
        $keyword = "\xE4\xBF\x9D\xE7\xAE\xA1\xE5\xA0\xB4\xE6\x89\x80";
        $response = $this->openProductListAsAdmin(['sh' => $keyword]);
        $this->assertSearchTextInputValue($response, 'sh', $keyword);
    }

    public function testSearchByStoragePlaceLikeShows20RowsInTable(): void
    {
        $keyword = "\xE4\xBF\x9D\xE7\xAE\xA1\xE5\xA0\xB4\xE6\x89\x80";
        $response = $this->openProductListAsAdmin(['sh' => $keyword]);
        $this->assertTableRowCount($response, 20);
    }

    public function testSearchByStoragePlaceLikeShowsSearchResult20(): void
    {
        $keyword = "\xE4\xBF\x9D\xE7\xAE\xA1\xE5\xA0\xB4\xE6\x89\x80";
        $response = $this->openProductListAsAdmin(['sh' => $keyword]);
        $this->assertSearchResultCount($response, 20);
    }

    public function testSearchByRemarks01ShowsInputValueInForm(): void
    {
        $response = $this->openProductListAsAdmin(['rm' => '01']);
        $this->assertSearchTextInputValue($response, 'rm', '01');
    }

    public function testSearchByRemarks01Shows1RowInTable(): void
    {
        $response = $this->openProductListAsAdmin(['rm' => '01']);
        $this->assertTableRowCount($response, 1);
    }

    public function testSearchByRemarks01ShowsSearchResult1(): void
    {
        $response = $this->openProductListAsAdmin(['rm' => '01']);
        $this->assertSearchResultCount($response, 1);
    }

    public function testSearchByRemarks02ShowsInputValueInForm(): void
    {
        $response = $this->openProductListAsAdmin(['rm' => '02']);
        $this->assertSearchTextInputValue($response, 'rm', '02');
    }

    public function testSearchByRemarks02Shows1RowInTable(): void
    {
        $response = $this->openProductListAsAdmin(['rm' => '02']);
        $this->assertTableRowCount($response, 1);
    }

    public function testSearchByRemarks02ShowsSearchResult1(): void
    {
        $response = $this->openProductListAsAdmin(['rm' => '02']);
        $this->assertSearchResultCount($response, 1);
    }

    public function testSearchByRemarksLikeShowsInputValueInForm(): void
    {
        $keyword = "\xE5\x82\x99\xE8\x80\x83";
        $response = $this->openProductListAsAdmin(['rm' => $keyword]);
        $this->assertSearchTextInputValue($response, 'rm', $keyword);
    }

    public function testSearchByRemarksLikeShows2RowsInTable(): void
    {
        $keyword = "\xE5\x82\x99\xE8\x80\x83";
        $response = $this->openProductListAsAdmin(['rm' => $keyword]);
        $this->assertTableRowCount($response, 2);
    }

    public function testSearchByRemarksLikeShowsSearchResult2(): void
    {
        $keyword = "\xE5\x82\x99\xE8\x80\x83";
        $response = $this->openProductListAsAdmin(['rm' => $keyword]);
        $this->assertSearchResultCount($response, 2);
    }

    public function testSearchByCategory10ShowsSelectedValueInForm(): void
    {
        $response = $this->openProductListAsAdmin(['cn' => ['10']]);
        $this->assertSearchSelectValues($response, 'cn[]', ['10']);
    }

    public function testSearchByCategory10Shows2RowsInTable(): void
    {
        $response = $this->openProductListAsAdmin(['cn' => ['10']]);
        $this->assertTableRowCount($response, 2);
    }

    public function testSearchByCategory10ShowsSearchResult2(): void
    {
        $response = $this->openProductListAsAdmin(['cn' => ['10']]);
        $this->assertSearchResultCount($response, 2);
    }

    public function testSearchByCategory10And20ShowsSelectedValuesInForm(): void
    {
        $response = $this->openProductListAsAdmin(['cn' => ['10', '20']]);
        $this->assertSearchSelectValues($response, 'cn[]', ['10', '20']);
    }

    public function testSearchByCategory10And20Shows4RowsInTable(): void
    {
        $response = $this->openProductListAsAdmin(['cn' => ['10', '20']]);
        $this->assertTableRowCount($response, 4);
    }

    public function testSearchByCategory10And20ShowsSearchResult4(): void
    {
        $response = $this->openProductListAsAdmin(['cn' => ['10', '20']]);
        $this->assertSearchResultCount($response, 4);
    }

    public function testSearchByMaker30ShowsSelectedValueInForm(): void
    {
        $response = $this->openProductListAsAdmin(['mk' => ['30']]);
        $this->assertSearchSelectValues($response, 'mk[]', ['30']);
    }

    public function testSearchByMaker30Shows2RowsInTable(): void
    {
        $response = $this->openProductListAsAdmin(['mk' => ['30']]);
        $this->assertTableRowCount($response, 2);
    }

    public function testSearchByMaker30ShowsSearchResult2(): void
    {
        $response = $this->openProductListAsAdmin(['mk' => ['30']]);
        $this->assertSearchResultCount($response, 2);
    }

    public function testSearchByMaker30And40ShowsSelectedValuesInForm(): void
    {
        $response = $this->openProductListAsAdmin(['mk' => ['30', '40']]);
        $this->assertSearchSelectValues($response, 'mk[]', ['30', '40']);
    }

    public function testSearchByMaker30And40Shows4RowsInTable(): void
    {
        $response = $this->openProductListAsAdmin(['mk' => ['30', '40']]);
        $this->assertTableRowCount($response, 4);
    }

    public function testSearchByMaker30And40ShowsSearchResult4(): void
    {
        $response = $this->openProductListAsAdmin(['mk' => ['30', '40']]);
        $this->assertSearchResultCount($response, 4);
    }

    public function testSearchByStockNoneShowsSelectedValueInForm(): void
    {
        $response = $this->openProductListAsAdmin(['sf' => '2']);
        $this->assertSearchStockFlag($response, '2');
    }

    public function testSearchByStockNoneShows17RowsInTable(): void
    {
        $response = $this->openProductListAsAdmin(['sf' => '2']);
        $this->assertTableRowCount($response, 17);
    }

    public function testSearchByStockNoneShowsSearchResult17(): void
    {
        $response = $this->openProductListAsAdmin(['sf' => '2']);
        $this->assertSearchResultCount($response, 17);
    }

    public function testSearchByStockAllShowsSelectedValueInForm(): void
    {
        $response = $this->openProductListAsAdmin(['sf' => '9']);
        $this->assertSearchStockFlag($response, '9');
    }

    public function testSearchByStockAllShows20RowsInTable(): void
    {
        $response = $this->openProductListAsAdmin(['sf' => '9']);
        $this->assertTableRowCount($response, 20);
    }

    public function testSearchByStockAllShowsSearchResult20(): void
    {
        $response = $this->openProductListAsAdmin(['sf' => '9']);
        $this->assertSearchResultCount($response, 20);
    }

    public function testSearchByStockHasValueShowsSelectedValueInForm(): void
    {
        $response = $this->openProductListAsAdmin(['sf' => '1']);
        $this->assertSearchStockFlag($response, '1');
    }

    public function testSearchByStockHasValueShows3RowsInTable(): void
    {
        $response = $this->openProductListAsAdmin(['sf' => '1']);
        $this->assertTableRowCount($response, 3);
    }

    public function testSearchByStockHasValueShowsSearchResult3(): void
    {
        $response = $this->openProductListAsAdmin(['sf' => '1']);
        $this->assertSearchResultCount($response, 3);
    }

    // ソート番号ごとに、見出しボタンの存在と表の並び順をセットで確認する。
    public function testSortDefaultShowsManagementAscWithUpArrow(): void
    {
        $response = $this->openProductListAsAdmin();
        $this->assertSortHeaderIndicator($response);
        $this->assertRowsSortedBy($response, 'management_no', 'asc');
    }

    public function testSortManagementDescShowsDownArrowAndDescending(): void
    {
        $response = $this->openProductListAsAdmin(['s' => '6']);
        $this->assertSortHeaderIndicator($response);
        $this->assertRowsSortedBy($response, 'management_no', 'desc');
    }

    public function testSortCategoryAscShowsUpArrowAndAscending(): void
    {
        $response = $this->openProductListAsAdmin(['s' => '7']);
        $this->assertSortHeaderIndicator($response);
        $this->assertRowsSortedBy($response, 'category_name', 'asc');
    }

    // カテゴリ昇順ではカテゴリ名が同じ行も管理番号昇順で安定して並ぶことを確認する
    public function testSortCategoryAscOrdersTiedRowsByManagementNo(): void
    {
        $this->preparePaginationTestProducts();
        try {
            $response = $this->openProductListAsAdmin(['s' => '7']);
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

    public function testSortCategoryDescShowsDownArrowAndDescending(): void
    {
        $response = $this->openProductListAsAdmin(['s' => '8']);
        $this->assertSortHeaderIndicator($response);
        $this->assertRowsSortedBy($response, 'category_name', 'desc');
    }

    public function testSortMakerAscShowsUpArrowAndAscending(): void
    {
        $response = $this->openProductListAsAdmin(['s' => '9']);
        $this->assertSortHeaderIndicator($response);
        $this->assertRowsSortedBy($response, 'maker_name', 'asc');
    }

    public function testSortMakerDescShowsDownArrowAndDescending(): void
    {
        $response = $this->openProductListAsAdmin(['s' => '10']);
        $this->assertSortHeaderIndicator($response);
        $this->assertRowsSortedBy($response, 'maker_name', 'desc');
    }

    public function testSortProductNameAscShowsUpArrowAndAscending(): void
    {
        $response = $this->openProductListAsAdmin(['s' => '3']);
        $this->assertSortHeaderIndicator($response);
        $this->assertRowsSortedBy($response, 'product_name', 'asc');
    }

    public function testSortProductNameDescShowsDownArrowAndDescending(): void
    {
        $response = $this->openProductListAsAdmin(['s' => '4']);
        $this->assertSortHeaderIndicator($response);
        $this->assertRowsSortedBy($response, 'product_name', 'desc');
    }

    public function testSortWholesaleAscShowsUpArrowAndAscending(): void
    {
        $response = $this->openProductListAsAdmin(['s' => '15']);
        $this->assertSortHeaderIndicator($response);
        $this->assertRowsSortedBy($response, 'wholesale_amount', 'asc');
    }

    public function testSortWholesaleDescShowsDownArrowAndDescending(): void
    {
        $response = $this->openProductListAsAdmin(['s' => '16']);
        $this->assertSortHeaderIndicator($response);
        $this->assertRowsSortedBy($response, 'wholesale_amount', 'desc');
    }

    public function testSortRetailAscShowsUpArrowAndAscending(): void
    {
        $response = $this->openProductListAsAdmin(['s' => '17']);
        $this->assertSortHeaderIndicator($response);
        $this->assertRowsSortedBy($response, 'retail_amount', 'asc');
    }

    public function testSortRetailDescShowsDownArrowAndDescending(): void
    {
        $response = $this->openProductListAsAdmin(['s' => '18']);
        $this->assertSortHeaderIndicator($response);
        $this->assertRowsSortedBy($response, 'retail_amount', 'desc');
    }

    public function testSortQuantityAscShowsUpArrowAndAscending(): void
    {
        $response = $this->openProductListAsAdmin(['s' => '11']);
        $this->assertSortHeaderIndicator($response);
        $this->assertRowsSortedBy($response, 'quantity', 'asc');
    }

    public function testSortQuantityDescShowsDownArrowAndDescending(): void
    {
        $response = $this->openProductListAsAdmin(['s' => '12']);
        $this->assertSortHeaderIndicator($response);
        $this->assertRowsSortedBy($response, 'quantity', 'desc');
    }

    public function testSortManagementAscShowsUpArrowAndAscending(): void
    {
        $response = $this->openProductListAsAdmin(['s' => '5']);
        $this->assertSortHeaderIndicator($response);
        $this->assertRowsSortedBy($response, 'management_no', 'asc');
    }

    // バーコード出力は選択状態に依存するため、チェックボックスの初期値も合わせて見る。
    public function testInitialDisplayProductCheckboxesAreAllUnchecked(): void
    {
        $response = $this->openProductListAsAdmin();
        $this->assertProductCheckboxesUnchecked($response);
    }

    public function testInitialDisplayBarcodeOutputButtonIsDisabled(): void
    {
        $response = $this->openProductListAsAdmin();
        $this->assertBarcodeOutputButtonDisabled($response);
    }

    public function testCheckedProductCheckboxMakesBarcodeOutputButtonEnabled(): void
    {
        $response = $this->openProductListAsAdmin([], ['ABC001']);
        $this->assertBarcodeOutputButtonEnabled($response);
    }

    public function testSelectAllEquivalentStateChecksAllProductCheckboxes(): void
    {
        $response = $this->openProductListAsAdmin([], $this->allManagementNos());
        $this->assertProductCheckboxesChecked($response);
    }

    public function testClearAllEquivalentStateUnchecksAllProductCheckboxes(): void
    {
        // 「全解除」相当の状態を再現し、バーコード出力用チェックボックスが全て未チェックであることを確認する
        $response = $this->openProductListAsAdmin([], []);
        $this->assertProductCheckboxesUnchecked($response);
    }

    // 初期表示時: 520件の検索結果が表示され、6ページリンクまで表示される
    // ページネーションは専用データを作って、件数表示と遷移後の表示範囲まで確認する。
    public function testPaginationInitialDisplayShows520ResultsAndPage6Link(): void
    {
        $this->preparePaginationTestProducts();
        try {
            $response = $this->openProductListAsAdmin();
            $this->assertSearchResultCount($response, 520);
            $this->assertTableRowCount($response, 20);
            $this->assertPaginationIsVisible($response);
            $this->assertPageNumberIsLink($response, 6);
        } finally {
            \Tests\Support\TestDatabase::seed();
        }
    }

    // 初期表示時: 「«」とページ1はクリック不可の表示になる
    public function testPaginationInitialDisplayDisablesPreviousAndPage1(): void
    {
        $this->preparePaginationTestProducts();
        try {
            $response = $this->openProductListAsAdmin();
            $this->assertActivePageNumber($response, 1);
            $this->assertPreviousPageLinkDisabled($response);
            $this->assertActivePageIsNotClickable($response, 1);
        } finally {
            \Tests\Support\TestDatabase::seed();
        }
    }

    // 商品名に「商品」を入力して検索: 1ページ目・スクロール0・検索条件維持で表示される
    public function testPaginationSearchByProductKeywordShowsPage1WithScrollTopAndKeepsCondition(): void
    {
        $this->preparePaginationTestProducts();
        try {
            $keyword = "\xE5\x95\x86\xE5\x93\x81";
            $response = $this->openProductListAsAdmin([
                'pos' => '0',
                's' => '5',
                'p' => '1',
                'prn' => $keyword,
            ]);
            $this->assertActivePageNumber($response, 1);
            $this->assertScrollPositionZero($response);
            $this->assertSearchTextInputValue($response, 'prn', $keyword);
            $this->assertSearchResultCount($response, 520);
        } finally {
            \Tests\Support\TestDatabase::seed();
        }
    }

    // リンクがあるページ番号を押す: 対応ページのデータが表示される
    public function testPaginationClickLinkedPageNumberShowsTargetPageData(): void
    {
        $this->preparePaginationTestProducts();
        try {
            $initial = $this->openProductListAsAdmin();
            $this->assertPageNumberIsLink($initial, 6);

            $response = $this->openProductListAsAdmin(['p' => '6']);
            $this->assertActivePageNumber($response, 6);
            $this->assertTableRowCount($response, 20);
            $this->assertTableManagementNoRange($response, 'ABC101', 'ABC120');
        } finally {
            \Tests\Support\TestDatabase::seed();
        }
    }

    // リンクがあるページ番号を押す: スクロール位置は先頭(0)に戻る
    public function testPaginationClickLinkedPageNumberResetsScrollTop(): void
    {
        $this->preparePaginationTestProducts();
        try {
            $response = $this->openProductListAsAdmin(['p' => '6', 'pos' => '0']);
            $this->assertActivePageNumber($response, 6);
            $this->assertScrollPositionZero($response);
        } finally {
            \Tests\Support\TestDatabase::seed();
        }
    }

    // 1ページ目以外の表示時: 現在ページはクリック不可で「«」はクリック可能になる
    public function testPaginationNonFirstPageDisablesCurrentPageAndEnablesPrevious(): void
    {
        $this->preparePaginationTestProducts();
        try {
            $response = $this->openProductListAsAdmin(['p' => '2']);
            $this->assertActivePageNumber($response, 2);
            $this->assertActivePageIsNotClickable($response, 2);
            $this->assertPreviousPageLinkEnabled($response);
        } finally {
            \Tests\Support\TestDatabase::seed();
        }
    }

    // 最終ページ表示時: 「»」はクリック不可になる
    public function testPaginationLastPageDisablesNext(): void
    {
        $this->preparePaginationTestProducts();
        try {
            $response = $this->openProductListAsAdmin(['p' => '26']);
            $this->assertActivePageNumber($response, 26);
            $this->assertNextPageLinkDisabled($response);
            $this->assertTableManagementNoRange($response, 'ABC501', 'ABC520');
        } finally {
            \Tests\Support\TestDatabase::seed();
        }
    }

    // ページ遷移後にソート項目を押す: 1ページ目に戻りスクロール位置は0になる
    public function testPaginationAfterPageTransitionSortReturnsToPage1AndScrollTop(): void
    {
        $this->preparePaginationTestProducts();
        try {
            $page6 = $this->openProductListAsAdmin(['p' => '6']);
            $this->assertActivePageNumber($page6, 6);

            $sorted = $this->openProductListAsAdmin([
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
            'sf' => '9',
        ];
    }

    /**
     * @param array<string, mixed> $query
     * @param array<int, string>|null $selectedMngNos
     */
    private function openProductListAsAdmin(array $query = [], ?array $selectedMngNos = null): Response
    {
        // 一覧画面はセッションに検索条件と選択状態を保持するので、テスト開始前に明示的に初期化する。
        $this->loginAsAdmin();
        $this->resetProductListSession($selectedMngNos);

        $path = 'product_list.php';
        if ($query !== []) {
            $path .= '?' . http_build_query($query);
        }

        $response = $this->getClient()->get($path);
        $this->assertOk($response);

        return $response;
    }

    /**
     * @param array<int, string>|null $selectedMngNos
     */
    private function resetProductListSession(?array $selectedMngNos): void
    {
        $bridgeName = '__product_list_session_' . bin2hex(random_bytes(8)) . '.php';
        $bridgePath = dirname(__DIR__, 2) . '/public/' . $bridgeName;
        $bridgeCode = <<<'PHP'
<?php
session_cache_limiter("none");
session_start();
date_default_timezone_set('Asia/Tokyo');

require_once __DIR__ . '/../vendor/autoload.php';

use Studiogau\Chandra\Support\SessionHelper;

$payload = json_decode(file_get_contents('php://input'), true);
$_SERVER['SCRIPT_NAME'] = '/noblestock/public/product_list.php';

SessionHelper::delData('biz002', null);
if (is_array($payload) && array_key_exists('selectedMngNos', $payload) && is_array($payload['selectedMngNos'])) {
    SessionHelper::setData('biz002', 'selectedMngNos', array_values($payload['selectedMngNos']));
}

header('Content-Type: application/json');
echo json_encode(['ok' => true], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
PHP;

        file_put_contents($bridgePath, $bridgeCode);

        try {
            $response = $this->postJsonWithCurrentClient($bridgeName, [
                'selectedMngNos' => $selectedMngNos,
            ]);

            $this->assertSame(200, $response->status);
            $this->assertStringContainsString('"ok":true', $response->body);
        } finally {
            @unlink($bridgePath);
        }
    }

    private function assertSearchFormHasNoInputValues(Response $response): void
    {
        $this->assertSearchTextInputValue($response, 'mn', '');
        $this->assertSearchTextInputValue($response, 'prn', '');
        $this->assertSearchTextInputValue($response, 'sh', '');
        $this->assertSearchTextInputValue($response, 'rm', '');
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

    private function assertSearchStockFlag(Response $response, string $expectedValue): void
    {
        $xpath = new DOMXPath($response->dom());
        $selectedNode = $xpath->query("//form[@name='search']//select[@name='sf']/option[@selected]");
        $this->assertNotFalse($selectedNode);
        $this->assertGreaterThan(0, $selectedNode->length, 'Selected stock flag option was not found.');

        $actual = (string) $selectedNode->item(0)?->attributes?->getNamedItem('value')?->nodeValue;
        $this->assertSame($expectedValue, $actual);
    }

    private function assertTableRowCount(Response $response, int $expectedCount): void
    {
        $xpath = new DOMXPath($response->dom());
        $rows = $xpath->query("//table[contains(@class,'table')]//tbody/tr");
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
        $nodes = $xpath->query("//table[contains(@class,'table')]//thead//button");
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
        $rowNodes = $xpath->query("//table[contains(@class,'table')]//tbody/tr");
        $this->assertNotFalse($rowNodes);

        $rows = [];
        foreach ($rowNodes as $rowNode) {
            $cellNodes = $xpath->query('./td', $rowNode);
            $this->assertNotFalse($cellNodes);
            if ($cellNodes->length < 7) {
                continue;
            }

            $rows[] = [
                'management_no' => $this->normalizeText($cellNodes->item(0)?->textContent ?? ''),
                'category_name' => $this->normalizeText($cellNodes->item(1)?->textContent ?? ''),
                'maker_name' => $this->normalizeText($cellNodes->item(2)?->textContent ?? ''),
                'product_name' => $this->normalizeText($cellNodes->item(3)?->textContent ?? ''),
                'wholesale_amount' => $this->extractInt($cellNodes->item(4)?->textContent ?? ''),
                'retail_amount' => $this->extractInt($cellNodes->item(5)?->textContent ?? ''),
                'quantity' => $this->extractInt($cellNodes->item(6)?->textContent ?? ''),
            ];
        }

        return $rows;
    }

    private function assertProductCheckboxesUnchecked(Response $response): void
    {
        $xpath = new DOMXPath($response->dom());
        $nodes = $xpath->query("//input[contains(concat(' ', normalize-space(@class), ' '), ' js-product-check ')]");
        $this->assertNotFalse($nodes);
        $this->assertGreaterThan(0, $nodes->length, 'Product checkboxes were not found.');

        foreach ($nodes as $node) {
            $this->assertFalse($node->attributes?->getNamedItem('checked') !== null, 'Checkbox should be unchecked.');
        }
    }

    private function assertProductCheckboxesChecked(Response $response): void
    {
        $xpath = new DOMXPath($response->dom());
        $nodes = $xpath->query("//input[contains(concat(' ', normalize-space(@class), ' '), ' js-product-check ')]");
        $this->assertNotFalse($nodes);
        $this->assertGreaterThan(0, $nodes->length, 'Product checkboxes were not found.');

        foreach ($nodes as $node) {
            $this->assertTrue($node->attributes?->getNamedItem('checked') !== null, 'Checkbox should be checked.');
        }
    }

    private function assertBarcodeOutputButtonDisabled(Response $response): void
    {
        $xpath = new DOMXPath($response->dom());
        $nodes = $xpath->query("//button[@id='btnBarcodeOutput']");
        $this->assertNotFalse($nodes);
        $this->assertGreaterThan(0, $nodes->length, 'Barcode output button was not found.');
        $this->assertTrue($nodes->item(0)?->attributes?->getNamedItem('disabled') !== null);
    }

    private function assertBarcodeOutputButtonEnabled(Response $response): void
    {
        $xpath = new DOMXPath($response->dom());
        $nodes = $xpath->query("//button[@id='btnBarcodeOutput']");
        $this->assertNotFalse($nodes);
        $this->assertGreaterThan(0, $nodes->length, 'Barcode output button was not found.');
        $this->assertFalse($nodes->item(0)?->attributes?->getNamedItem('disabled') !== null);
    }

    /**
     * @return array<int, string>
     */
    private function allManagementNos(): array
    {
        $list = [];
        for ($i = 1; $i <= 20; $i++) {
            $list[] = sprintf('ABC%03d', $i);
        }

        return $list;
    }

    private function preparePaginationTestProducts(): void
    {
        // ページング専用に 520 件の連番商品を作り、各テスト後に seed で元データへ戻す。
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
            $pdo->exec('DELETE FROM products');
        } finally {
            $pdo->exec('SET FOREIGN_KEY_CHECKS=1');
        }

        $stmt = $pdo->prepare(
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

        $productPrefix = "\xE5\x95\x86\xE5\x93\x81";
        $createdAt = '2026-03-06 00:00:00';
        $createdBy = 'admin';

        $pdo->beginTransaction();
        try {
            for ($i = 1; $i <= 520; $i++) {
                $managementNo = sprintf('ABC%03d', $i);
                $categoryId = ((($i - 1) % 10) + 1) * 10;
                $makerId = ((($i + 3) % 10) + 1) * 10;
                $unitId = ((($i - 1) % 8) + 1) * 10;
                $wholesale = 1000 + $i;
                $retail = $wholesale + 200;

                $stmt->execute([
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

        $disabled = $xpath->query("//nav[@aria-label='Page navigation']//li[contains(@class,'disabled')]//a[contains(.,'«')]");
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
    public function testProductListClearsSessionDataWhenTimedOut(): void
    {
        $this->assertTimedOutProtectedPageClearsSessionDataAndShowsExpiredMessage('product_list.php');
    }
}
