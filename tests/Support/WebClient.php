<?php

declare(strict_types=1);

namespace Tests\Support;

use Studiogau\Chandra\Support\Utility;

final class WebClient
{
    private const AUTO_CSRF_SKIP_FIELD = '_skip_auto_csrf';

    private string $baseUrl;
    private string $cookieFile;

    public function __construct(string $baseUrl)
    {
        $this->baseUrl = rtrim($baseUrl, '/') . '/';
        $this->cookieFile = tempnam(sys_get_temp_dir(), 'noble-cookie-');
    }

    public function __destruct()
    {
        if (is_file($this->cookieFile)) {
            @unlink($this->cookieFile);
        }
    }

    public function login(string $user, string $pass): Response
    {
        $loginPage = $this->get('index.php');
        $csrfFields = $this->extractCsrfFields($loginPage->body);

        return $this->post('menu.php', [
            'user' => $user,
            'pass' => $pass,
        ] + $csrfFields);
    }

    /**
     * @return array<string, string>
     */
    private function extractCsrfFields(string $html): array
    {
        $dom = new \DOMDocument();
        @$dom->loadHTML($html);
        $xpath = new \DOMXPath($dom);

        $token = (string) ($xpath->query("//input[@name='" . Utility::getCsrfFieldName() . "']")?->item(0)?->attributes?->getNamedItem('value')?->nodeValue ?? '');
        $scope = (string) ($xpath->query("//input[@name='" . Utility::getCsrfScopeFieldName() . "']")?->item(0)?->attributes?->getNamedItem('value')?->nodeValue ?? '');

        if ($token === '' || $scope === '') {
            return [];
        }

        return [
            Utility::getCsrfScopeFieldName() => $scope,
            Utility::getCsrfFieldName() => $token,
        ];
    }

    public function get(string $path): Response
    {
        return $this->request('GET', $path);
    }

    public function post(string $path, array $fields): Response
    {
        return $this->request('POST', $path, $this->ensureCsrfFields($path, $fields));
    }

    private function request(string $method, string $path, array $fields = []): Response
    {
        $url = str_starts_with($path, 'http') ? $path : $this->baseUrl . ltrim($path, '/');

        $ch = curl_init();
        curl_setopt_array($ch, [
            CURLOPT_URL => $url,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_COOKIEJAR => $this->cookieFile,
            CURLOPT_COOKIEFILE => $this->cookieFile,
            CURLOPT_HEADER => true,
            CURLOPT_TIMEOUT => 30,
            CURLOPT_SSL_VERIFYPEER => false,
            CURLOPT_SSL_VERIFYHOST => false,
        ]);

        if ($method === 'POST') {
            curl_setopt($ch, CURLOPT_POST, true);
            curl_setopt($ch, CURLOPT_POSTFIELDS, $this->normalizePostFields($fields));
        }

        $raw = curl_exec($ch);
        if ($raw === false) {
            $err = curl_error($ch);
            curl_close($ch);
            throw new \RuntimeException("cURL error: {$err}");
        }

        $status = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $headerSize = curl_getinfo($ch, CURLINFO_HEADER_SIZE);
        curl_close($ch);

        $headersRaw = substr($raw, 0, $headerSize);
        $body = substr($raw, $headerSize);

        return new Response($status, $headersRaw, $body, $url);
    }

    /**
     * @param array<string, mixed> $fields
     * @return array<string, mixed>
     */
    private function ensureCsrfFields(string $path, array $fields): array
    {
        if (($fields[self::AUTO_CSRF_SKIP_FIELD] ?? false) === true) {
            unset($fields[self::AUTO_CSRF_SKIP_FIELD]);
            return $fields;
        }

        if (array_key_exists(Utility::getCsrfFieldName(), $fields) || array_key_exists(Utility::getCsrfScopeFieldName(), $fields)) {
            return $fields;
        }

        $csrfFields = $this->fetchCsrfFieldsForPath($path, $fields);
        if ($csrfFields !== []) {
            return $fields + $csrfFields;
        }

        $scope = '__auto__:' . ltrim((string) parse_url($path, PHP_URL_PATH), '/');
        $token = $this->issueCsrfTokenForCurrentSession($scope, $this->normalizeScriptName($path));

        return $fields + [
            Utility::getCsrfScopeFieldName() => $scope,
            Utility::getCsrfFieldName() => $token,
        ];
    }

    /**
     * @return array<string, string>
     */
    private function fetchCsrfFieldsForPath(string $path, array $fields = []): array
    {
        $sourcePath = $this->csrfFormSourcePath($path, $fields);
        $response = $this->get($sourcePath);

        return $this->extractCsrfFields($response->body);
    }

    private function csrfFormSourcePath(string $path, array $fields = []): string
    {
        $normalizedPath = ltrim((string) parse_url($path, PHP_URL_PATH), '/');
        $hasListPage = array_key_exists('listPage', $fields);

        return match ($normalizedPath) {
            'product_confirm.php' => 'product_create.php',
            'product_edit.php' => 'product_list.php',
            'product_edit_confirm.php' => 'product_edit.php',
            'user_confirm.php' => 'user_create.php',
            'user_edit.php' => 'user_list.php',
            'user_edit_confirm.php' => 'user_edit.php',
            'history_edit.php' => 'history_list.php',
            'history_confirm.php' => 'history_edit.php',
            'product_list_export.php' => $hasListPage ? 'list_export_split.php?fn=product_list' : 'product_list.php',
            'product_per_location_export.php' => $hasListPage ? 'list_export_split.php?fn=product_per_location' : 'product_per_location.php',
            'history_list_export.php' => $hasListPage ? 'list_export_split.php?fn=history_list' : 'history_list.php',
            'sales_list_export.php' => $hasListPage ? 'list_export_split.php?fn=sales_list' : 'sales_list.php',
            'sales_show_export.php' => $hasListPage ? 'list_export_split.php?fn=sales_show' : 'sales_show.php',
            'user_export.php' => 'user_list.php',
            'master_bulk_export.php' => 'master_bulk_edit.php',
            'product_check_export.php' => $hasListPage ? 'list_export_split.php?fn=product_check' : 'product_check.php',
            default => $path,
        };
    }

    private function issueCsrfTokenForCurrentSession(string $scope, string $scriptName): string
    {
        $sessionId = $this->readPhpSessionIdFromCookieJar();
        $wasSessionActive = session_status() === PHP_SESSION_ACTIVE;
        $previousSessionId = $wasSessionActive ? session_id() : null;
        $previousSession = $wasSessionActive ? ($_SESSION ?? []) : null;
        $previousScriptName = $_SERVER['SCRIPT_NAME'] ?? null;

        if ($wasSessionActive) {
            session_write_close();
        }

        session_id($sessionId);
        session_start();
        $_SERVER['SCRIPT_NAME'] = $scriptName;

        try {
            return Utility::issueCsrfToken($scope);
        } finally {
            if (session_status() === PHP_SESSION_ACTIVE) {
                session_write_close();
            }

            if ($wasSessionActive && $previousSessionId !== null) {
                session_id($previousSessionId);
                session_start();
                $_SESSION = $previousSession ?? [];
            }

            if ($previousScriptName === null) {
                unset($_SERVER['SCRIPT_NAME']);
            } else {
                $_SERVER['SCRIPT_NAME'] = $previousScriptName;
            }
        }
    }

    private function normalizeScriptName(string $path): string
    {
        $parsedPath = (string) parse_url($path, PHP_URL_PATH);
        if ($parsedPath === '') {
            return '/noblestock/public/index.php';
        }

        if (str_starts_with($parsedPath, '/')) {
            return $parsedPath;
        }

        return '/noblestock/public/' . ltrim($parsedPath, '/');
    }

    private function readPhpSessionIdFromCookieJar(): string
    {
        $cookieLines = @file($this->cookieFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [];

        foreach (array_reverse($cookieLines) as $line) {
            if (str_starts_with($line, '#')) {
                continue;
            }

            $parts = explode("\t", $line);
            if (count($parts) >= 7 && $parts[5] === 'PHPSESSID') {
                return trim($parts[6]);
            }
        }

        $this->get('index.php');
        $cookieLines = @file($this->cookieFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [];
        foreach (array_reverse($cookieLines) as $line) {
            if (str_starts_with($line, '#')) {
                continue;
            }

            $parts = explode("\t", $line);
            if (count($parts) >= 7 && $parts[5] === 'PHPSESSID') {
                return trim($parts[6]);
            }
        }

        throw new \RuntimeException('PHPSESSID cookie was not found in WebClient cookie jar.');
    }

    // CURLFile を含む場合は multipart/form-data のまま送り、それ以外は従来どおり URL エンコードする
    private function normalizePostFields(array $fields): array|string
    {
        if ($this->containsCurlFile($fields)) {
            return $this->flattenMultipartFields($fields);
        }

        return http_build_query($fields);
    }

    // POST データのどこかに CURLFile が含まれているかを再帰的に判定する
    private function containsCurlFile(array $fields): bool
    {
        foreach ($fields as $value) {
            if ($value instanceof \CURLFile) {
                return true;
            }

            if (is_array($value) && $this->containsCurlFile($value)) {
                return true;
            }
        }

        return false;
    }

    // CURLFile を含む多次元配列を multipart/form-data で送れる形に平坦化する
    private function flattenMultipartFields(array $fields, string $prefix = ''): array
    {
        $flattened = [];

        foreach ($fields as $key => $value) {
            $fieldName = $prefix === '' ? (string) $key : $prefix . '[' . $key . ']';

            if (is_array($value)) {
                $flattened += $this->flattenMultipartFields($value, $fieldName);
                continue;
            }

            $flattened[$fieldName] = $value;
        }

        return $flattened;
    }
}

final class Response
{
    public function __construct(
        public int $status,
        public string $headers,
        public string $body,
        public string $url
    ) {
    }

    public function dom(): \DOMDocument
    {
        $dom = new \DOMDocument();
        @$dom->loadHTML($this->body);
        return $dom;
    }
}
