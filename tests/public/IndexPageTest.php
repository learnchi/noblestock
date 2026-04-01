<?php

declare(strict_types=1);

use Tests\Support\WebTestCase;

final class IndexPageTest extends WebTestCase
{
    public function testLoginPageShows(): void
    {
        $response = $this->getClient()->get('index.php');
        $this->assertOk($response);
    }

    // セッション期限切れ時に認証情報と画面用セッションが破棄されることを確認する
    public function testIndexClearsAuthenticatedSessionDataAndUser(): void
    {
        $this->assertLogoutClearsAuthenticatedSessionDataAndUser('index.php');
    }
}
