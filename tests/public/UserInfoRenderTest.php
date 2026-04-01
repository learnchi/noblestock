<?php

declare(strict_types=1);

use Tests\Support\Response;
use Tests\Support\TestDatabase;
use Tests\Support\WebClient;
use Tests\Support\WebTestCase;

final class UserInfoRenderTest extends WebTestCase
{
    private const VALID_COMPLEX_PASSWORD = 'Passphrase-2026!OpenAI';

    // user_confirm に出る user_info の表で、項目の並びと値が部品どおり表示されることを確認する
    public function testUserInfoDisplaysExpectedRowsOnUserConfirm(): void
    {
        $this->prepareUserConfirmAsAdmin();

        $response = $this->postConfirm([
            'login_id' => 'render901',
            'password_hash' => self::VALID_COMPLEX_PASSWORD,
            'user_name' => '表示確認ユーザー',
            'furigana' => 'ヒョウジカクニン',
            'email' => 'render@example.com',
            'sort_order' => '42',
            'auth_screen' => ['0', '3', '18'],
        ]);

        $this->assertSame(
            ['ログインID', 'パスワード', 'ユーザー名', 'フリガナ', 'メール', 'ソート順', '権限'],
            $this->extractRowLabels($response)
        );
        $this->assertInfoCellValue($response, 'ログインID', 'render901');
        $this->assertInfoCellValue($response, 'パスワード', self::VALID_COMPLEX_PASSWORD);
        $this->assertInfoCellValue($response, 'ユーザー名', '表示確認ユーザー');
        $this->assertInfoCellValue($response, 'フリガナ', 'ヒョウジカクニン');
        $this->assertInfoCellValue($response, 'メール', 'render@example.com');
        $this->assertInfoCellValue($response, 'ソート順', '42');
    }

    // 権限欄は 20 桁の権限文字列ではなく、○×付きの日本語一覧として表示されることを確認する
    public function testUserInfoDisplaysAuthoritySummaryMarks(): void
    {
        $this->prepareUserConfirmAsAdmin();

        $response = $this->postConfirm([
            'auth_screen' => ['0', '3', '18'],
        ]);

        $authorityText = $this->readInfoCellText($response, '権限');
        $this->assertStringContainsString('○ 商品表示', $authorityText);
        $this->assertStringContainsString('× 商品一覧', $authorityText);
        $this->assertStringContainsString('○ 商品入庫', $authorityText);
        $this->assertStringContainsString('○ ユーザー管理', $authorityText);
        $this->assertStringContainsString('× 履歴一覧', $authorityText);
    }

    private function prepareUserConfirmAsAdmin(): void
    {
        TestDatabase::seed();
        self::$client = new WebClient('http://localhost/noblestock/public');
        $this->loginAsAdmin();
        $this->getClient()->get('menu.php');
    }

    private function postConfirm(array $overrides = []): Response
    {
        $response = $this->getClient()->post('user_confirm.php', array_merge([
            'mode' => 'confirm',
            'id' => '',
            'login_id' => 'confirm901',
            'password_hash' => self::VALID_COMPLEX_PASSWORD,
            'user_name' => '確認ユーザー',
            'furigana' => 'カクニンユーザー',
            'email' => 'confirm901@example.com',
            'sort_order' => '20',
            'auth_screen' => ['0', '1', '18'],
        ], $overrides));
        $this->assertOk($response);

        return $response;
    }

    private function extractRowLabels(Response $response): array
    {
        $xpath = new DOMXPath($response->dom());
        $nodes = $xpath->query("//table//tr/th");
        $this->assertNotFalse($nodes);

        $labels = [];
        foreach ($nodes as $node) {
            $labels[] = $this->normalizeText($node->textContent ?? '');
        }

        return $labels;
    }

    private function assertInfoCellValue(Response $response, string $label, string $expectedValue): void
    {
        $this->assertSame($expectedValue, $this->readInfoCellText($response, $label));
    }

    private function readInfoCellText(Response $response, string $label): string
    {
        $xpath = new DOMXPath($response->dom());
        $nodes = $xpath->query("//table//tr[th[normalize-space()='{$label}']]/td");
        $this->assertNotFalse($nodes);
        $this->assertSame(1, $nodes->length, "Info cell for {$label} was not found.");

        return $this->normalizeText($nodes->item(0)?->textContent ?? '');
    }

    private function normalizeText(string $text): string
    {
        return trim((string) preg_replace('/\s+/u', ' ', $text));
    }
}
