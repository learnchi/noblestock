<?php

declare(strict_types=1);

use Noblestock\Logic\MessageConst;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use Tests\Support\Response;
use Tests\Support\WebClient;
use Tests\Support\WebTestCase;

final class MasterBulkEditPageTest extends WebTestCase
{
    // ログインセッションをクリア後、この画面にアクセスすると、indexに遷移する
    public function testMasterBulkEditRedirectsToIndex(): void
    {
        $this->getClient()->get('index.php');

        $response = $this->getClient()->get('master_bulk_edit.php');
        $this->assertSame(200, $response->status, "Expected final 200 from {$response->url}");
        $this->assertStringContainsString('Location: index.php', $response->headers);
    }

    // ログインセッションをクリア後、この画面にアクセスすると、認証エラーメッセージ付きでindexに遷移する
    public function testMasterBulkEditRedirectsToIndexWithSessionExpiredMessage(): void
    {
        $this->getClient()->get('index.php');

        $response = $this->getClient()->get('master_bulk_edit.php');
        $this->assertSame(200, $response->status, "Expected final 200 from {$response->url}");
        $this->assertStringContainsString('Location: index.php', $response->headers);
        $this->assertStringContainsString(MessageConst::MSG_INF_AUTH_002, $response->body);
    }

    public function testMasterBulkEditDisplays(): void
    {
        $this->loginAsAdmin();
        $response = $this->getClient()->get('master_bulk_edit.php');
        $this->assertOk($response);
    }

    // この画面に権限があるユーザー[perm15]で200で表示される
    public function testMasterBulkEditDisplaysForPerm15(): void
    {
        $this->loginAs('perm15', 'perm1500');

        $response = $this->getClient()->get('master_bulk_edit.php');
        $this->assertOk($response);
    }

    // この画面に権限がないユーザーnoauthで403エラーとなる
    public function testMasterBulkEditReturns403ForNoAuth(): void
    {
        $this->loginAs('noauth', 'noauth00');

        $response = $this->getClient()->get('master_bulk_edit.php');
        $this->assertSame(403, $response->status, "Expected 403 from {$response->url}");
    }

    // 初期表示はメニュー選択状態で、バーコード出力案内とバーコード出力ボタンが見える
    public function testMasterBulkEditShowsMenuGuideOnInitialDisplay(): void
    {
        $response = $this->openMasterBulkEditAsAdmin();

        $this->assertGuideMessage($response, 'メニュー' . MessageConst::MSG_INF_MASTER_006);
        $this->assertSelectedMaster($response, '0');
        $this->assertElementExists($response, "//button[@formaction='barcode_bulk_export.php' and normalize-space()='バーコード出力']");
        $this->assertXPathCount($response, "//input[@type='file' and @name='upfile']", 0);
    }

    // メーカーを選ぶと現在のマスタ一覧とExcel読込欄が表示される
    public function testMasterBulkEditSelectMasterDisplaysCurrentMasterList(): void
    {
        $this->prepareMasterBulkEditAsAdmin();

        $response = $this->selectMaster('2');

        $this->assertGuideMessage($response, MessageConst::MSG_INF_MASTER_005);
        $this->assertSelectedMaster($response, '2');
        $this->assertElementExists($response, "//input[@type='file' and @name='upfile']");
        $this->assertElementExists($response, "//button[@formaction='master_bulk_export.php' and normalize-space()='Excel出力']");
        $this->assertXPathCount($response, "//button[@formaction='barcode_bulk_export.php' and normalize-space()='バーコード出力']", 0);
        $this->assertMasterListRows($response, [
            ['id' => '10', 'name' => 'メーカー10', 'remarks' => '備考10'],
            ['id' => '20', 'name' => 'メーカー20', 'remarks' => '備考20'],
            ['id' => '30', 'name' => 'メーカー30', 'remarks' => '備考30'],
        ]);
    }

    // マスタ選択後にファイル未選択で登録するとエラーになる
    public function testMasterBulkEditUploadWithoutFileShowsValidationError(): void
    {
        $this->prepareMasterBulkEditAsAdmin();
        $this->selectMaster('2');

        $response = $this->getClient()->post('master_bulk_edit.php', [
            'mode' => 'excel',
        ]);
        $this->assertOk($response);

        $this->assertFlushErrorMessage($response, MessageConst::MSG_VAL_FILE_001);
        $this->assertXPathCount($response, "//table//th[normalize-space()='チェック']", 0);
    }

    // Excel 以外の拡張子ではファイルエラーになる
    public function testMasterBulkEditUploadWithInvalidExtensionShowsValidationError(): void
    {
        $this->prepareMasterBulkEditAsAdmin();
        $this->selectMaster('2');
        $tempFile = $this->createTempFile('not excel');

        try {
            $response = $this->getClient()->post('master_bulk_edit.php', [
                'mode' => 'excel',
                'upfile' => curl_file_create($tempFile, 'text/plain', 'makers.txt'),
            ]);
            $this->assertOk($response);

            $this->assertFlushErrorMessage($response, MessageConst::MSG_VAL_FILE_005);
            $this->assertXPathCount($response, "//table//th[normalize-space()='チェック']", 0);
        } finally {
            @unlink($tempFile);
        }
    }

    // 正しい Excel を読ませると、まだDBは更新せず、チェック結果一覧だけを表示する
    public function testMasterBulkEditUploadValidExcelShowsCheckedRowsWithoutUpdatingDatabase(): void
    {
        $this->prepareMasterBulkEditAsAdmin();
        $this->selectMaster('2');
        $originalMakers = $this->selectAllMakers();
        $fixturePath = $this->createMasterBulkExcelFixture([
            ['id' => '210', 'name' => 'テストメーカー210', 'remarks' => '備考210'],
            ['id' => '220', 'name' => 'テストメーカー220', 'remarks' => '備考220'],
        ]);

        try {
            $response = $this->uploadMakerExcelFixture($fixturePath, 'makers.xlsx');

            $this->assertStringContainsString(MessageConst::MSG_OK_MASTER_004, $response->body);
            $this->assertStringContainsString('makers.xlsx', $response->body);
            $this->assertCheckedRows($response, [
                ['result' => 'チェックOK', 'id' => '210', 'name' => 'テストメーカー210', 'remarks' => '備考210'],
                ['result' => 'チェックOK', 'id' => '220', 'name' => 'テストメーカー220', 'remarks' => '備考220'],
            ]);
            $this->assertElementExists($response, "//button[@name='mode' and @value='insert' and normalize-space()='マスターに登録']");
            $this->assertElementExists($response, "//button[@name='mode' and @value='cancel' and normalize-space()='キャンセル']");

            $currentMaker = $this->selectMakerRowNullable(210);
            $this->assertNull($currentMaker, 'Maker should not be inserted before mode=insert.');
            $this->assertCount(count($originalMakers), $this->selectAllMakers());
        } finally {
            @unlink($fixturePath);
        }
    }

    // 読み込み後に登録すると、makers テーブルがExcelの内容で入れ替わる
    public function testMasterBulkEditInsertAppliesUploadedMasterData(): void
    {
        $this->prepareMasterBulkEditAsAdmin();
        $this->selectMaster('2');
        $fixturePath = $this->createMasterBulkExcelFixture([
            ['id' => '210', 'name' => '登録メーカー210', 'remarks' => '登録備考210'],
            ['id' => '220', 'name' => '登録メーカー220', 'remarks' => '登録備考220'],
        ]);

        try {
            $this->uploadMakerExcelFixture($fixturePath, 'makers.xlsx');

            $response = $this->getClient()->post('master_bulk_edit.php', [
                'mode' => 'insert',
            ]);
            $this->assertOk($response);

            $this->assertStringContainsString(MessageConst::MSG_OK_MASTER_009, $response->body);
            $this->assertStringContainsString('メーカーマスタは 2 件登録されています', $response->body);
            $this->assertXPathCount($response, "//table//th[normalize-space()='チェック']", 0);
            $this->assertMasterListRows($response, [
                ['id' => '210', 'name' => '登録メーカー210', 'remarks' => '登録備考210'],
                ['id' => '220', 'name' => '登録メーカー220', 'remarks' => '登録備考220'],
            ]);

            $makers = $this->selectAllMakers();
            $this->assertCount(2, $makers);
            $this->assertSame('210', (string) ($makers[0]['id'] ?? ''));
            $this->assertSame('登録メーカー210', (string) ($makers[0]['maker_name'] ?? ''));
            $this->assertSame('220', (string) ($makers[1]['id'] ?? ''));
            $this->assertSame('登録メーカー220', (string) ($makers[1]['maker_name'] ?? ''));
        } finally {
            @unlink($fixturePath);
        }
    }

    // 読み込み後にキャンセルするとチェック一覧だけ消え、DBのメーカーは元のまま残る
    public function testMasterBulkEditCancelClearsCheckedRowsWithoutUpdatingDatabase(): void
    {
        $this->prepareMasterBulkEditAsAdmin();
        $this->selectMaster('2');
        $originalMakers = $this->selectAllMakers();
        $fixturePath = $this->createMasterBulkExcelFixture([
            ['id' => '210', 'name' => 'キャンセルメーカー210', 'remarks' => 'キャンセル備考210'],
        ]);

        try {
            $this->uploadMakerExcelFixture($fixturePath, 'makers.xlsx');

            $response = $this->getClient()->post('master_bulk_edit.php', [
                'mode' => 'cancel',
            ]);
            $this->assertOk($response);

            $this->assertXPathCount($response, "//table//th[normalize-space()='チェック']", 0);
            $this->assertMasterListRows($response, [
                ['id' => '10', 'name' => 'メーカー10', 'remarks' => '備考10'],
                ['id' => '20', 'name' => 'メーカー20', 'remarks' => '備考20'],
                ['id' => '30', 'name' => 'メーカー30', 'remarks' => '備考30'],
            ]);
            $this->assertNull($this->selectMakerRowNullable(210));
            $this->assertCount(count($originalMakers), $this->selectAllMakers());
        } finally {
            @unlink($fixturePath);
        }
    }

    private function openMasterBulkEditAsAdmin(): Response
    {
        $this->prepareMasterBulkEditAsAdmin();

        $response = $this->getClient()->get('master_bulk_edit.php');
        $this->assertOk($response);

        return $response;
    }

    private function prepareMasterBulkEditAsAdmin(): void
    {
        \Tests\Support\TestDatabase::seed();
        self::$client = new WebClient('http://localhost/noblestock/public');
        $this->loginAsAdmin();
        $this->getClient()->get('menu.php');
    }

    // 選択ボタンを押した時と同じ POST を送り、対象マスタへ切り替える
    private function selectMaster(string $masterNo): Response
    {
        $response = $this->getClient()->post('master_bulk_edit.php', [
            'mode' => 'selectMaster',
            'masterNo' => $masterNo,
        ]);
        $this->assertOk($response);

        return $response;
    }

    // 実際の画面と同じ multipart/form-data でメーカーExcelを読み込ませる
    private function uploadMakerExcelFixture(string $fixturePath, string $uploadName): Response
    {
        $response = $this->getClient()->post('master_bulk_edit.php', [
            'mode' => 'excel',
            'upfile' => curl_file_create(
                $fixturePath,
                'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
                $uploadName
            ),
        ]);
        $this->assertOk($response);

        return $response;
    }

    // メーカー一括編集が受ける3列のExcelを一時生成する
    private function createMasterBulkExcelFixture(array $rows): string
    {
        $spreadsheet = new Spreadsheet();
        $sheet = $spreadsheet->getActiveSheet();

        $headers = ['メーカーid', 'メーカー名', '備考'];
        foreach ($headers as $index => $header) {
            $sheet->setCellValue(chr(ord('A') + $index) . '1', $header);
        }

        $rowNo = 2;
        foreach ($rows as $row) {
            $values = [
                (string) ($row['id'] ?? ''),
                (string) ($row['name'] ?? ''),
                (string) ($row['remarks'] ?? ''),
            ];

            foreach ($values as $index => $value) {
                $sheet->setCellValue(chr(ord('A') + $index) . $rowNo, $value);
            }

            $rowNo++;
        }

        $tempBase = tempnam(sys_get_temp_dir(), 'master-bulk-edit-');
        $this->assertNotFalse($tempBase, 'Failed to create temporary Excel base path.');

        $tempFile = $tempBase . '.xlsx';
        if (!@rename($tempBase, $tempFile)) {
            @unlink($tempBase);
            $this->fail('Failed to prepare temporary Excel file path.');
        }

        try {
            IOFactory::createWriter($spreadsheet, 'Xlsx')->save($tempFile);
        } finally {
            $spreadsheet->disconnectWorksheets();
            unset($spreadsheet);
        }

        return $tempFile;
    }

    private function createTempFile(string $contents): string
    {
        $path = tempnam(sys_get_temp_dir(), 'master-bulk-edit-');
        $this->assertNotFalse($path, 'Failed to create temporary upload file.');
        file_put_contents($path, $contents);

        return $path;
    }

    // info アラートの案内文を確認する
    private function assertGuideMessage(Response $response, string $expectedMessage): void
    {
        $xpath = new DOMXPath($response->dom());
        $nodes = $xpath->query("//div[contains(@class,'alert-info')]/div");
        $this->assertNotFalse($nodes);
        $this->assertGreaterThan(0, $nodes->length, 'Guide message alert was not found.');
        $this->assertSame($expectedMessage, $this->normalizeText($nodes->item(0)?->textContent ?? ''));
    }

    // danger アラートに出るファイル関連エラーを確認する
    private function assertFlushErrorMessage(Response $response, string $expectedMessage): void
    {
        $xpath = new DOMXPath($response->dom());
        $nodes = $xpath->query("//div[contains(@class,'alert-danger')]/div");
        $this->assertNotFalse($nodes);
        $this->assertGreaterThan(0, $nodes->length, 'Flush error alert was not found.');
        $this->assertSame($expectedMessage, $this->normalizeText($nodes->item(0)?->textContent ?? ''));
    }

    // 選択中のマスタが select 要素に反映されていることを確認する
    private function assertSelectedMaster(Response $response, string $expectedMasterNo): void
    {
        $xpath = new DOMXPath($response->dom());
        $nodes = $xpath->query("//select[@name='masterNo']/option[@selected='selected']");
        $this->assertNotFalse($nodes);
        $this->assertSame(1, $nodes->length);
        $this->assertSame($expectedMasterNo, (string) $nodes->item(0)?->attributes?->getNamedItem('value')?->nodeValue);
    }

    // チェック一覧の行を result / id / name / remarks 単位で確認する
    private function assertCheckedRows(Response $response, array $expectedRows): void
    {
        $xpath = new DOMXPath($response->dom());
        $rowNodes = $xpath->query("//table[thead/tr/th[normalize-space()='チェック']]//tbody/tr");
        $this->assertNotFalse($rowNodes);
        $this->assertSame(count($expectedRows), $rowNodes->length, 'Unexpected checked row count.');

        foreach ($expectedRows as $index => $expectedRow) {
            $cellNodes = $xpath->query('./td', $rowNodes->item($index));
            $this->assertNotFalse($cellNodes);
            $this->assertSame(4, $cellNodes->length);

            $this->assertSame((string) $expectedRow['result'], $this->normalizeText($cellNodes->item(0)?->textContent ?? ''));
            $this->assertSame((string) $expectedRow['id'], $this->normalizeText($cellNodes->item(1)?->textContent ?? ''));
            $this->assertSame((string) $expectedRow['name'], $this->normalizeText($cellNodes->item(2)?->textContent ?? ''));
            $this->assertSame((string) $expectedRow['remarks'], $this->normalizeText($cellNodes->item(3)?->textContent ?? ''));
        }
    }

    // 通常表示中のマスタ一覧の先頭行を確認し、選んだマスタが正しく表示されていることを見る
    private function assertMasterListRows(Response $response, array $expectedRows): void
    {
        $xpath = new DOMXPath($response->dom());
        $rowNodes = $xpath->query("//table[not(thead/tr/th[normalize-space()='チェック'])]//tbody/tr");
        $this->assertNotFalse($rowNodes);
        $this->assertGreaterThanOrEqual(count($expectedRows), $rowNodes->length, 'Current master rows were not found.');

        foreach ($expectedRows as $index => $expectedRow) {
            $cellNodes = $xpath->query('./td', $rowNodes->item($index));
            $this->assertNotFalse($cellNodes);
            $this->assertSame(3, $cellNodes->length);

            $this->assertSame((string) $expectedRow['id'], $this->normalizeText($cellNodes->item(0)?->textContent ?? ''));
            $this->assertSame((string) $expectedRow['name'], $this->normalizeText($cellNodes->item(1)?->textContent ?? ''));
            $this->assertSame((string) $expectedRow['remarks'], $this->normalizeText($cellNodes->item(2)?->textContent ?? ''));
        }
    }

    private function assertXPathCount(Response $response, string $xpathExpression, int $expectedCount): void
    {
        $xpath = new DOMXPath($response->dom());
        $nodes = $xpath->query($xpathExpression);
        $this->assertNotFalse($nodes, "Invalid XPath: {$xpathExpression}");
        $this->assertSame($expectedCount, $nodes->length, "Unexpected node count for XPath: {$xpathExpression}");
    }

    private function assertElementExists(Response $response, string $xpathExpression): void
    {
        $this->assertXPathCount($response, $xpathExpression, 1);
    }

    private function selectMakerRowNullable(int $id): ?array
    {
        $pdo = $this->createTestPdo();
        $stmt = $pdo->prepare('SELECT id, maker_name, remarks FROM makers WHERE id = :id');
        $stmt->execute(['id' => $id]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        return $row === false ? null : $row;
    }

    private function selectAllMakers(): array
    {
        $pdo = $this->createTestPdo();
        return $pdo->query('SELECT id, maker_name, remarks FROM makers ORDER BY id')->fetchAll(PDO::FETCH_ASSOC);
    }

    private function normalizeText(string $text): string
    {
        return trim((string) preg_replace('/\s+/u', ' ', $text));
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
    public function testMasterBulkEditClearsSessionDataWhenTimedOut(): void
    {
        $this->assertTimedOutProtectedPageClearsSessionDataAndShowsExpiredMessage('master_bulk_edit.php');
    }
}
