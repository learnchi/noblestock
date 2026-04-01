<?php

declare(strict_types=1);

use Tests\Support\Response;
use Tests\Support\WebTestCase;

final class IncHeadRenderTest extends WebTestCase
{
    // index.php 経由で、共通 head のメタ情報とアセット読込が入っていることを確認する
    public function testIndexPageIncludesCommonHeadMetaAndAssets(): void
    {
        $response = $this->getClient()->get('index.php');
        $this->assertOk($response);

        $this->assertStringContainsString('<meta charset="UTF-8">', $response->body);
        $this->assertStringContainsString('<meta name="author" content="Studio GAU">', $response->body);
        $this->assertStringContainsString('<meta name="viewport" content="width=device-width,initial-scale=1">', $response->body);
        $this->assertStringContainsString('<title>ログイン | NobleStock</title>', $response->body);
        $this->assertStringContainsString('bootstrap@5.3.0/dist/css/bootstrap.min.css', $response->body);
        $this->assertStringContainsString('bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js', $response->body);
        $this->assertStringContainsString('bootstrap-icons@1.11.3/font/bootstrap-icons.css', $response->body);
        $this->assertStringContainsString('href="css/noblestock.css"', $response->body);
        $this->assertStringContainsString('src="js/noblestock.js"', $response->body);
    }

    // include 側の $title が親画面ごとに反映されることを、ログイン後の画面でも確認する
    public function testMenuMasterPageIncludesTitleFromParentScreen(): void
    {
        $response = $this->openMenuMasterAsAdmin();

        $this->assertStringContainsString('<title>マスタメンテナンス | NobleStock</title>', $response->body);
        $this->assertStringContainsString('href="css/noblestock.css"', $response->body);
    }

    private function openMenuMasterAsAdmin(): Response
    {
        $this->loginAsAdmin();

        $response = $this->getClient()->get('menu_master.php');
        $this->assertOk($response);

        return $response;
    }
}
