<?php

declare(strict_types=1);

use Noblestock\Logic\LogicConst;
use Noblestock\Logic\MessageConst;
use Studiogau\Chandra\Support\Utility;
use Tests\Support\ImageWebTestCase;
use Tests\Support\Response;

final class ImageBulkCreatePageTest extends ImageWebTestCase
{
    // ログインセッションをクリア後、この画面にアクセスすると、indexに遷移する
    public function testImageBulkCreateRedirectsToIndex(): void
    {
        $this->getClient()->get('index.php');

        $response = $this->getClient()->get('image_bulk_create.php');
        $this->assertSame(200, $response->status, "Expected final 200 from {$response->url}");
        $this->assertStringContainsString('Location: index.php', $response->headers);
    }

    // ログインセッションをクリア後、この画面にアクセスすると、認証エラーメッセージ付きでindexに遷移する
    public function testImageBulkCreateRedirectsToIndexWithSessionExpiredMessage(): void
    {
        $this->getClient()->get('index.php');

        $response = $this->getClient()->get('image_bulk_create.php');
        $this->assertSame(200, $response->status, "Expected final 200 from {$response->url}");
        $this->assertStringContainsString('Location: index.php', $response->headers);
        $this->assertStringContainsString(MessageConst::MSG_INF_AUTH_002, $response->body);
    }

    // 初期表示では案内メッセージと複数画像アップロード欄、現在の画像一覧が表示される
    public function testImageBulkCreateDisplaysGuideAndUploadedImages(): void
    {
        $this->loginAsAdmin();

        $response = $this->getClient()->get('image_bulk_create.php');

        $this->assertOk($response);
        $this->assertStringContainsString(MessageConst::MSG_INF_IMAGE_001, $response->body);
        $this->assertElementExists(
            $response,
            "//form[@action='image_bulk_create.php']//input[@type='file' and @name='upfile[]' and @multiple]"
        );
        $this->assertElementExists($response, "//a[@href='menu_master.php' and normalize-space()='戻る']");

        $expectedImageNames = $this->expectedUploadImageNames();
        $this->assertNotEmpty($expectedImageNames, 'Expected at least one displayable image in public/uploads.');
        $this->assertXPathCount(
            $response,
            "//img[starts-with(@src, 'uploads/s_') and @data-bs-target='#imageViewer']",
            count($expectedImageNames)
        );
        $this->assertElementExists(
            $response,
            "//div[contains(@class, 'card-body')][h5[normalize-space()='{$expectedImageNames[0]}']]"
        );
    }

    // 複数画像をまとめて送信したとき、成功行と失敗行が結果一覧に並び、成功した画像だけが保存される
    public function testImageBulkCreateDisplaysMixedUploadResultsAndStoresSuccessfulImage(): void
    {
        $validUploadName = $this->existingUploadImageName();
        $invalidUploadName = 'invalid name.jpg';

        $response = $this->uploadImages([
                [
                    'name' => $validUploadName,
                    'path' => $this->existingUploadSourcePath(),
                    'mime' => 'image/jpeg',
                ],
                [
                    'name' => $invalidUploadName,
                    'path' => $this->existingUploadSourcePath(),
                    'mime' => 'image/jpeg',
                ],
            ]);

            $this->assertStringContainsString(MessageConst::MSG_OK_IMAGE_003, $response->body);
            $this->assertXPathCount($response, "//div[@id='table-wrapper']//tbody/tr", 2);
            $this->assertElementExists(
                $response,
                "//div[@id='table-wrapper']//tr[td[1][normalize-space()='成功'] and td[2][normalize-space()='{$validUploadName}']]"
            );
            $this->assertElementExists(
                $response,
                "//div[@id='table-wrapper']//tr[td[1][normalize-space()='失敗'] and td[2][normalize-space()='{$invalidUploadName}'] and td[4][contains(normalize-space(), '" . MessageConst::MSG_VAL_IMAGE_007 . "')]]"
            );
            $this->assertElementExists(
                $response,
                "//img[@src='uploads/s_{$validUploadName}' and @data-bs-target='#imageViewer' and @data-bs-image='uploads/{$validUploadName}']"
            );

            $this->assertFileExists($this->uploadsPath($validUploadName));
            $this->assertFileExists($this->uploadsPath('s_' . $validUploadName));
        $this->assertFileDoesNotExist($this->uploadsPath($invalidUploadName));
        $this->assertFileDoesNotExist($this->uploadsPath('s_' . $invalidUploadName));
    }

    // 2MB を超える画像は結果一覧で失敗になり、ファイルも保存されない
    public function testImageBulkCreateDisplaysErrorWhenUploadedFileExceedsSizeLimit(): void
    {
        $uploadName = $this->existingUploadImageName();
        $tempFile = $this->createTempFile(str_repeat('A', 2_000_001));

        try {
            $response = $this->uploadImages([
                [
                    'name' => $uploadName,
                    'path' => $tempFile,
                    'mime' => 'image/jpeg',
                ],
            ]);

            $this->assertElementExists(
                $response,
                "//div[@id='table-wrapper']//tr[td[1][normalize-space()='失敗'] and td[2][normalize-space()='{$uploadName}'] and td[4][contains(normalize-space(), '" . Utility::replaceStr(MessageConst::MSG_VAL_IMAGE_006, '2MB') . "')]]"
            );
        } finally {
            @unlink($tempFile);
        }
    }

    // この画面に権限があるユーザー[perm14]で200で表示される
    public function testImageBulkCreateDisplaysForPerm14(): void
    {
        $this->loginAs('perm14', 'perm1400');

        $response = $this->getClient()->get('image_bulk_create.php');
        $this->assertOk($response);
    }

    // この画面に権限がないユーザーnoauthで403エラーとなる
    public function testImageBulkCreateReturns403ForNoAuth(): void
    {
        $this->loginAs('noauth', 'noauth00');

        $response = $this->getClient()->get('image_bulk_create.php');
        $this->assertSame(403, $response->status, "Expected 403 from {$response->url}");
    }

    private function expectedUploadImageNames(): array
    {
        $uploadsDir = dirname(__DIR__, 2) . '/public/' . LogicConst::DIR_IMAGES;
        $entries = @scandir($uploadsDir);

        if ($entries === false) {
            $this->fail("Failed to read uploads directory: {$uploadsDir}");
        }

        $imageNames = [];
        foreach ($entries as $entry) {
            if (str_starts_with($entry, 's_')) {
                continue;
            }

            if (Utility::checkImageName($entry)) {
                $imageNames[] = $entry;
            }
        }

        sort($imageNames);

        return $imageNames;
    }

    // 実際の multipart/form-data で複数画像アップロードを送信し、結果画面を受け取る
    private function uploadImages(array $files): Response
    {
        $this->loginAsAdmin();

        $postFiles = [];
        foreach ($files as $file) {
            $postFiles[] = curl_file_create($file['path'], $file['mime'], $file['name']);
        }

        $response = $this->getClient()->post('image_bulk_create.php', [
            'mode' => 'upload',
            'upfile' => $postFiles,
        ]);

        $this->assertOk($response);

        return $response;
    }

    // public/uploads に元からある画像を 1 つ選び、アップロード用の元データとして使う
    private function existingUploadSourcePath(): string
    {
        $imageNames = $this->expectedUploadImageNames();
        $this->assertNotEmpty($imageNames, 'Expected at least one source image in public/uploads.');

        return $this->uploadsPath($imageNames[0]);
    }

    private function existingUploadImageName(): string
    {
        $imageNames = $this->expectedUploadImageNames();
        $this->assertNotEmpty($imageNames, 'Expected at least one source image in public/uploads.');

        return $imageNames[0];
    }

    private function uploadsPath(string $fileName): string
    {
        return dirname(__DIR__, 2) . '/public/' . LogicConst::DIR_IMAGES . '/' . $fileName;
    }

    private function createTempFile(string $contents): string
    {
        $path = tempnam(sys_get_temp_dir(), 'image-bulk-create-test-');
        $this->assertNotFalse($path, 'Failed to create temporary upload file.');
        file_put_contents($path, $contents);

        return $path;
    }

    private function createXPath(Response $response): \DOMXPath
    {
        return new \DOMXPath($response->dom());
    }

    private function assertXPathCount(Response $response, string $xpath, int $expectedCount): void
    {
        $nodes = $this->createXPath($response)->query($xpath);
        $this->assertNotFalse($nodes, "Invalid XPath: {$xpath}");
        $this->assertSame($expectedCount, $nodes->length, "Unexpected node count for XPath: {$xpath}");
    }

    private function assertElementExists(Response $response, string $xpath): void
    {
        $this->assertXPathCount($response, $xpath, 1);
    }
    // セッション期限切れ時に認証情報と画面用セッションが破棄されることを確認する
    public function testImageBulkCreateClearsSessionDataWhenTimedOut(): void
    {
        $this->assertTimedOutProtectedPageClearsSessionDataAndShowsExpiredMessage('image_bulk_create.php');
    }
}
