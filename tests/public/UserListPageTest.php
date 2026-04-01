<?php

declare(strict_types=1);

use Noblestock\Logic\MessageConst;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use Tests\Support\Response;
use Tests\Support\WebClient;
use Tests\Support\WebTestCase;

final class UserListPageTest extends WebTestCase
{
    // ログインセッションをクリア後、この画面にアクセスすると、indexに遷移する
    public function testUserListRedirectsToIndex(): void
    {
        $this->getClient()->get('index.php');

        $response = $this->getClient()->get('user_list.php');
        $this->assertSame(200, $response->status, "Expected final 200 from {$response->url}");
        $this->assertStringContainsString('Location: index.php', $response->headers);
    }

    // ログインセッションをクリア後、この画面にアクセスすると、認証エラーメッセージ付きでindexに遷移する
    public function testUserListRedirectsToIndexWithSessionExpiredMessage(): void
    {
        $this->getClient()->get('index.php');

        $response = $this->getClient()->get('user_list.php');
        $this->assertSame(200, $response->status, "Expected final 200 from {$response->url}");
        $this->assertStringContainsString('Location: index.php', $response->headers);
        $this->assertStringContainsString(MessageConst::MSG_INF_AUTH_002, $response->body);
    }

    public function testUserListDisplays(): void
    {
        $this->loginAsAdmin();
        $response = $this->getClient()->get('user_list.php');
        $this->assertOk($response);
    }

    // この画面に権限があるユーザー[perm18]で200で表示される
    public function testUserListDisplaysForPerm18(): void
    {
        $this->loginAs('perm18', 'perm1800');

        $response = $this->getClient()->get('user_list.php');
        $this->assertOk($response);
    }

    // この画面に権限がないユーザーnoauthで403エラーとなる
    public function testUserListReturns403ForNoAuth(): void
    {
        $this->loginAs('noauth', 'noauth00');

        $response = $this->getClient()->get('user_list.php');
        $this->assertSame(403, $response->status, "Expected 403 from {$response->url}");
    }

    // 初期表示では案内文と現在のユーザー一覧が表示され、更新・登録・Excel出力の導線も見える
    public function testUserListShowsGuideAndCurrentUsers(): void
    {
        $response = $this->openUserListAsAdmin();

        $this->assertGuideMessage($response, MessageConst::MSG_INF_FILE_002);
        $this->assertCurrentUserRows($response, [
            ['sort_order' => '1', 'login_id' => 'admin', 'user_name' => '管理者がう'],
            ['sort_order' => '3', 'login_id' => 'user1', 'user_name' => 'ユーザ1'],
            ['sort_order' => '4', 'login_id' => 'user2', 'user_name' => 'ユーザ2'],
        ]);
        $this->assertElementExists($response, "//form[@action='user_create.php']//button[normalize-space()='ユーザー登録']");
        $this->assertElementExists($response, "//form[@action='user_export.php']//button[normalize-space()='Excel出力']");
        $this->assertElementExists($response, "//form[@action='user_edit.php']//input[@name='id' and @value='1']");
    }

    // ファイル未選択のまま読込するとエラーになり、結果一覧は出ない
    public function testUserListUploadWithoutFileShowsValidationError(): void
    {
        $this->prepareUserListAsAdmin();

        $response = $this->getClient()->post('user_list.php', [
            'mode' => 'upload',
        ]);
        $this->assertOk($response);

        $this->assertFlushErrorMessage($response, MessageConst::MSG_VAL_FILE_001);
        $this->assertUploadResultRows($response, []);
    }

    // Excel 以外の拡張子を読ませたときは拡張子エラーになる
    public function testUserListUploadWithInvalidExtensionShowsValidationError(): void
    {
        $this->prepareUserListAsAdmin();
        $tempFile = $this->createTempFile('not excel');

        try {
            $response = $this->getClient()->post('user_list.php', [
                'mode' => 'upload',
                'upfile' => curl_file_create($tempFile, 'text/plain', 'users.txt'),
            ]);
            $this->assertOk($response);

            $this->assertFlushErrorMessage($response, MessageConst::MSG_VAL_FILE_005);
            $this->assertUploadResultRows($response, []);
        } finally {
            @unlink($tempFile);
        }
    }

    // 正しい Excel を読ませると結果一覧が表示され、users テーブルもExcelの内容で入れ替わる
    public function testUserListUploadValidExcelDisplaysResultRowsAndUpdatesDatabase(): void
    {
        $this->prepareUserListAsAdmin();
        $fixturePath = $this->createUserBulkExcelFixture([
            [
                'login_id' => 'admin',
                'password' => 'admin000',
                'user_name' => '管理者テスト',
                'sort_order' => '1',
                'furigana' => 'カンリシャテスト',
                'email' => 'admin_test@example.com',
                'authority' => '11111111111111111111',
            ],
            [
                'login_id' => 'limit01',
                'password' => 'pass001A',
                'user_name' => '制限ユーザー01',
                'sort_order' => '2',
                'furigana' => 'セイゲンユーザー',
                'email' => 'limit01@example.com',
                'authority' => '1',
            ],
            [
                'login_id' => 'open001',
                'password' => 'pass002A',
                'user_name' => '通常ユーザー01',
                'sort_order' => '3',
                'furigana' => 'ツウジョウユーザー',
                'email' => 'open001@example.com',
                'authority' => '0',
            ],
        ]);

        try {
            $response = $this->uploadUserExcelFixture($fixturePath, 'users.xlsx');

            $this->assertStringContainsString('users.xlsx', $response->body);
            $this->assertUploadResultRows($response, [
                ['status' => '登録', 'sort_order' => '1', 'login_id' => 'admin', 'user_name' => '管理者テスト', 'authority_label' => '制限なし'],
                ['status' => '登録', 'sort_order' => '2', 'login_id' => 'limit01', 'user_name' => '制限ユーザー01', 'authority_label' => '制限ユーザ'],
                ['status' => '登録', 'sort_order' => '3', 'login_id' => 'open001', 'user_name' => '通常ユーザー01', 'authority_label' => '制限なし'],
            ]);

            $users = $this->selectAllUsers();
            $this->assertCount(3, $users);
            $this->assertSame('admin', (string) ($users[0]['login_id'] ?? ''));
            $this->assertSame('管理者テスト', (string) ($users[0]['user_name'] ?? ''));
            $this->assertSame('1', (string) ($users[0]['sort_order'] ?? ''));
            $this->assertSame('11111111111111111111', (string) ($users[0]['authority'] ?? ''));
            $this->assertSame('limit01', (string) ($users[1]['login_id'] ?? ''));
            $this->assertSame('1', (string) ($users[1]['authority'] ?? ''));
            $this->assertSame('open001', (string) ($users[2]['login_id'] ?? ''));
            $this->assertSame('0', (string) ($users[2]['authority'] ?? ''));

            $this->assertTrue(password_verify('admin000', (string) ($users[0]['password_hash'] ?? '')));
            $this->assertTrue(password_verify('pass001A', (string) ($users[1]['password_hash'] ?? '')));
            $this->assertTrue(password_verify('pass002A', (string) ($users[2]['password_hash'] ?? '')));
        } finally {
            @unlink($fixturePath);
        }
    }

    private function openUserListAsAdmin(): Response
    {
        $this->prepareUserListAsAdmin();

        $response = $this->getClient()->get('user_list.php');
        $this->assertOk($response);

        return $response;
    }

    private function prepareUserListAsAdmin(): void
    {
        \Tests\Support\TestDatabase::seed();
        self::$client = new WebClient('http://localhost/noblestock/public');
        $this->loginAsAdmin();
        $this->getClient()->get('menu.php');
    }

    // 実際の画面と同じ multipart/form-data で Excel を投げる
    private function uploadUserExcelFixture(string $fixturePath, string $uploadName): Response
    {
        $response = $this->getClient()->post('user_list.php', [
            'mode' => 'upload',
            'upfile' => curl_file_create(
                $fixturePath,
                'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
                $uploadName
            ),
        ]);
        $this->assertOk($response);

        return $response;
    }

    // user_list の一括登録が受ける7列Excelを一時生成する
    private function createUserBulkExcelFixture(array $rows): string
    {
        $spreadsheet = new Spreadsheet();
        $sheet = $spreadsheet->getActiveSheet();

        $headers = ['ログインID', 'パスワード', '名前', 'ソート順', 'フリガナ', 'メールアドレス', '権限'];
        foreach ($headers as $index => $header) {
            $sheet->setCellValue(chr(ord('A') + $index) . '1', $header);
        }

        $rowNo = 2;
        foreach ($rows as $row) {
            $values = [
                (string) ($row['login_id'] ?? ''),
                (string) ($row['password'] ?? ''),
                (string) ($row['user_name'] ?? ''),
                (string) ($row['sort_order'] ?? ''),
                (string) ($row['furigana'] ?? ''),
                (string) ($row['email'] ?? ''),
                (string) ($row['authority'] ?? ''),
            ];

            foreach ($values as $index => $value) {
                $sheet->setCellValue(chr(ord('A') + $index) . $rowNo, $value);
            }
            $rowNo++;
        }

        $tempBase = tempnam(sys_get_temp_dir(), 'user-list-');
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
        $path = tempnam(sys_get_temp_dir(), 'user-list-');
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

    // 初期表示のユーザー一覧を sort_order / login_id / user_name 単位で確認する
    private function assertCurrentUserRows(Response $response, array $expectedRows): void
    {
        $xpath = new DOMXPath($response->dom());
        $rowNodes = $xpath->query("//table[thead/tr/th[normalize-space()='編集']]//tbody/tr");
        $this->assertNotFalse($rowNodes);
        $this->assertGreaterThanOrEqual(count($expectedRows), $rowNodes->length, 'Current user rows were not found.');

        foreach ($expectedRows as $index => $expectedRow) {
            $cellNodes = $xpath->query('./td', $rowNodes->item($index));
            $this->assertNotFalse($cellNodes);
            $this->assertSame(7, $cellNodes->length);

            $this->assertSame((string) $expectedRow['sort_order'], $this->normalizeText($cellNodes->item(0)?->textContent ?? ''));
            $this->assertSame((string) $expectedRow['login_id'], $this->normalizeText($cellNodes->item(1)?->textContent ?? ''));
            $this->assertSame((string) $expectedRow['user_name'], $this->normalizeText($cellNodes->item(2)?->textContent ?? ''));
        }
    }

    // 読込結果一覧を status / sort_order / login_id / user_name / authority単位で確認する
    private function assertUploadResultRows(Response $response, array $expectedRows): void
    {
        $xpath = new DOMXPath($response->dom());
        $rowNodes = $xpath->query("//table[thead/tr/th[normalize-space()='結果']]//tbody/tr");
        $this->assertNotFalse($rowNodes);
        $this->assertSame(count($expectedRows), $rowNodes->length, 'Unexpected upload result row count.');

        foreach ($expectedRows as $index => $expectedRow) {
            $cellNodes = $xpath->query('./td', $rowNodes->item($index));
            $this->assertNotFalse($cellNodes);
            $this->assertSame(7, $cellNodes->length);

            $this->assertSame((string) $expectedRow['status'], $this->normalizeText($cellNodes->item(0)?->textContent ?? ''));
            $this->assertSame((string) $expectedRow['sort_order'], $this->normalizeText($cellNodes->item(1)?->textContent ?? ''));
            $this->assertSame((string) $expectedRow['login_id'], $this->normalizeText($cellNodes->item(2)?->textContent ?? ''));
            $this->assertSame((string) $expectedRow['user_name'], $this->normalizeText($cellNodes->item(3)?->textContent ?? ''));
            $this->assertSame((string) $expectedRow['authority_label'], $this->normalizeText($cellNodes->item(6)?->textContent ?? ''));
        }
    }

    private function assertElementExists(Response $response, string $xpathExpression): void
    {
        $xpath = new DOMXPath($response->dom());
        $nodes = $xpath->query($xpathExpression);
        $this->assertNotFalse($nodes, "Invalid XPath: {$xpathExpression}");
        $this->assertSame(1, $nodes->length, "Unexpected node count for XPath: {$xpathExpression}");
    }

    private function selectAllUsers(): array
    {
        $pdo = $this->createTestPdo();
        return $pdo->query(
            'SELECT login_id, password_hash, user_name, sort_order, furigana, email, authority
             FROM users ORDER BY sort_order'
        )->fetchAll(PDO::FETCH_ASSOC);
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
    public function testUserListClearsSessionDataWhenTimedOut(): void
    {
        $this->assertTimedOutProtectedPageClearsSessionDataAndShowsExpiredMessage('user_list.php');
    }
}
