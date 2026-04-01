<?php

declare(strict_types=1);

use Tests\Support\WebTestCase;

final class BarcodeOutputApiTest extends WebTestCase
{
    // 認証チェックテスト：ログインなしで直接URL指定した場合は401エラー
    public function testUnauthorizedAccessReturns401(): void
    {
        $this->getClient()->get('index.php');
        $response = $this->getClient()->get('api/BarcodeOutput.php');
        $this->assertSame(401, $response->status);
    }

    // 認証チェックテスト：ログインなしで直接URL指定した場合はunauthorized JSONが返る
    public function testUnauthorizedAccessReturnsUnauthorizedJson(): void
    {
        $this->getClient()->get('index.php');
        $response = $this->getClient()->get('api/BarcodeOutput.php');

        $json = json_decode($response->body, true);
        $this->assertIsArray($json);
        $this->assertSame('unauthorized', $json['error'] ?? null);
    }

    // 権限チェックテスト：product_list権限なしユーザーで直接URL指定した場合は404エラー
    public function testForbiddenUserAccessReturns404(): void
    {
        $this->loginAs('noauth', 'noauth00');
        $response = $this->getClient()->get('api/BarcodeOutput.php');
        $this->assertSame(404, $response->status);
    }

    // 権限チェックテスト：product_list権限なしユーザーで直接URL指定した場合はnot_found JSONが返る
    public function testForbiddenUserAccessReturnsNotFoundJson(): void
    {
        $this->loginAs('noauth', 'noauth00');
        $response = $this->getClient()->get('api/BarcodeOutput.php');

        $json = json_decode($response->body, true);
        $this->assertIsArray($json);
        $this->assertSame('not_found', $json['error'] ?? null);
    }

    // POSTチェックテスト：GETで直接URL指定した場合は405エラー
    public function testGetRequestReturns405(): void
    {
        $this->loginAs('perm01', 'perm0100');
        $response = $this->getClient()->get('api/BarcodeOutput.php');
        $this->assertSame(405, $response->status);
    }

    // POSTチェックテスト：GETで直接URL指定した場合はmethod_not_allowed JSONが返る
    public function testGetRequestReturnsMethodNotAllowedJson(): void
    {
        $this->loginAs('perm01', 'perm0100');
        $response = $this->getClient()->get('api/BarcodeOutput.php');

        $json = json_decode($response->body, true);
        $this->assertIsArray($json);
        $this->assertSame('method_not_allowed', $json['error'] ?? null);
    }

    // POST内容テスト：POSTでJSON形式以外の内容を送った場合は400エラー
    public function testNonJsonPostReturns400(): void
    {
        $this->loginAs('perm01', 'perm0100');
        $response = $this->getClient()->post('api/BarcodeOutput.php', [
            'mode' => 'export',
        ]);
        $this->assertSame(400, $response->status);
    }

    // POST内容テスト：POSTでJSON形式以外の内容を送った場合はinvalid_json JSONが返る
    public function testNonJsonPostReturnsInvalidJsonError(): void
    {
        $this->loginAs('perm01', 'perm0100');

        $response = $this->getClient()->post('api/BarcodeOutput.php', [
            'mode' => 'export',
        ]);

        $json = json_decode($response->body, true);
        $this->assertIsArray($json);
        $this->assertSame('invalid_json', $json['error'] ?? null);
    }
}
