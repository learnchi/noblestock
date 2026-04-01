<?php

declare(strict_types=1);

namespace Tests\Support;

trait BarcodeOutputSelectionHelper
{
    // 現在の WebClient と同じ cookie jar を使って selectedMngNos を投入する
    protected function seedSelectedMngNos(array $mngNos): void
    {
        $this->clearSelectedMngNos();

        $response = $this->postJsonWithCurrentClient('api/BarcodeOutput.php', [
            'mode' => 'selectAll',
            'mngNos' => array_values($mngNos),
        ]);

        $this->assertSame(200, $response->status);
        $this->assertStringContainsString('"ok":true', $response->body);
    }

    // 選択中の管理番号一覧を空にする
    protected function clearSelectedMngNos(): void
    {
        $response = $this->postJsonWithCurrentClient('api/BarcodeOutput.php', [
            'mode' => 'clearSelect',
        ]);

        $this->assertSame(200, $response->status);
        $this->assertStringContainsString('"ok":true', $response->body);
    }

    // 現在の WebClient と同じ cookie jar を使って JSON POST を行う
    protected function postJsonWithCurrentClient(string $path, array $payload): Response
    {
        $payload += $this->extractCsrfPostData($this->getClient()->get('menu.php'));

        $clientRef = new \ReflectionClass($this->getClient());

        $baseUrlProperty = $clientRef->getProperty('baseUrl');
        $baseUrlProperty->setAccessible(true);
        $baseUrl = rtrim((string) $baseUrlProperty->getValue($this->getClient()), '/') . '/';

        $cookieFileProperty = $clientRef->getProperty('cookieFile');
        $cookieFileProperty->setAccessible(true);
        $cookieFile = (string) $cookieFileProperty->getValue($this->getClient());

        $url = str_starts_with($path, 'http') ? $path : $baseUrl . ltrim($path, '/');

        $ch = curl_init();
        curl_setopt_array($ch, [
            CURLOPT_URL => $url,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_COOKIEJAR => $cookieFile,
            CURLOPT_COOKIEFILE => $cookieFile,
            CURLOPT_HEADER => true,
            CURLOPT_TIMEOUT => 30,
            CURLOPT_SSL_VERIFYPEER => false,
            CURLOPT_SSL_VERIFYHOST => false,
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            CURLOPT_HTTPHEADER => ['Content-Type: application/json'],
        ]);

        $raw = curl_exec($ch);
        if ($raw === false) {
            $error = curl_error($ch);
            curl_close($ch);
            $this->fail("cURL error: {$error}");
        }

        $status = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $headerSize = curl_getinfo($ch, CURLINFO_HEADER_SIZE);
        curl_close($ch);

        return new Response(
            $status,
            substr((string) $raw, 0, $headerSize),
            substr((string) $raw, $headerSize),
            $url
        );
    }
}
