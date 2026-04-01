<?php

declare(strict_types=1);

use Noblestock\Logic\LogicConst;
use Noblestock\Logic\MessageConst;
use Studiogau\Chandra\Support\Utility;
use Tests\Support\ImageWebTestCase;
use Tests\Support\Response;

final class ImageCreatePageTest extends ImageWebTestCase
{
    // ログインセッションをクリア後、この画面にアクセスすると、indexに遷移する
    public function testImageCreateRedirectsToIndex(): void
    {
        $this->getClient()->get('index.php');

        $response = $this->getClient()->get('image_create.php');
        $this->assertSame(200, $response->status, "Expected final 200 from {$response->url}");
        $this->assertStringContainsString('Location: index.php', $response->headers);
    }

    public function testImageCreateRedirectsToIndexWithSessionExpiredMessage(): void
    {
        $this->getClient()->get('index.php');

        $response = $this->getClient()->get('image_create.php');
        $this->assertSame(200, $response->status, "Expected final 200 from {$response->url}");
        $this->assertStringContainsString('Location: index.php', $response->headers);
        $this->assertStringContainsString(MessageConst::MSG_INF_AUTH_002, $response->body);
    }

    public function testImageCreateDisplays(): void
    {
        $this->arrangeImageCreateSession();
        $response = $this->getClient()->get('image_create.php');
        $this->assertOk($response);

    }

    public function testImageCreateDisplaysAsGet(): void
    {

        $this->arrangeImageCreateSession();
        $this->getClient()->post('image_create.php', [
            'filename' => 'product_edit',
        ]); // ここで内部の backTo がセットされる

        $response = $this->getClient()->get('image_create.php');
        $this->assertOk($response);
        $this->assertStringContainsString('href="product_edit.php"', $response->body);

    }

    public function testImageCreateDisplaysAllUploadedImages(): void
    {
        $this->arrangeImageCreateSession();

        $response = $this->getClient()->get('image_create.php');

        $this->assertOk($response);

        $expectedImageNames = $this->expectedUploadImageNames();
        $this->assertNotEmpty($expectedImageNames, 'Expected at least one displayable image in public/uploads.');
        $this->assertXPathCount(
            $response,
            "//img[starts-with(@src, 'uploads/s_') and @data-bs-target='#imageViewer']",
            count($expectedImageNames)
        );

        foreach ($expectedImageNames as $imageName) {
            $thumbnailPath = 'uploads/s_' . $imageName;
            $imagePath = 'uploads/' . $imageName;

            $this->assertElementExists(
                $response,
                "//img[@src='{$thumbnailPath}' and @data-bs-target='#imageViewer' and @data-bs-image='{$imagePath}']"
            );
        }
    }
    // 一覧内の任意画像で「この画像を使用」を実行した場合に、選択画像が productData.image_file へ上書きされることを確認する
    public function testImageCreateSetsImageFileWhenImageIsSelected(): void
    {
        $imageNames = $this->expectedUploadImageNames();
        $this->assertGreaterThanOrEqual(2, count($imageNames), 'Expected at least two displayable images in public/uploads.');

        $initialImageName = $imageNames[0];
        $selectedImageName = $imageNames[1];

        $this->arrangeImageCreateSession([
            'image_file' => $initialImageName,
        ]);

        $response = $this->getClient()->post('image_create.php', [
            'imgName' => $selectedImageName,
        ]);

        $this->assertOk($response);
        $this->assertStringContainsString($selectedImageName, $response->body);
        $this->assertElementExists(
            $response,
            "//a[@href='uploads/{$selectedImageName}' and @data-bs-target='#imageViewer']/img[@src='uploads/s_{$selectedImageName}']"
        );
        $this->assertElementExists(
            $response,
            "//div[contains(@class, 'card-body')][h5[text()='{$selectedImageName}'] and p[contains(@class, 'card-text')]]"
        );
        $this->assertElementExists(
            $response,
            "//div[contains(@class, 'card-body')][h5[text()='{$initialImageName}'] and form//input[@name='imgName' and @value='{$initialImageName}']]"
        );
    }

    // delImage を送信した場合に対象画像とサムネイルが削除され、productData.image_file も空になることを確認する
    public function testImageCreateDeletesSelectedImageAndClearsImageFile(): void
    {
        $deleteImageName = $this->existingUploadImageName();

        $this->arrangeImageCreateSession([
            'image_file' => $deleteImageName,
        ]);

        $response = $this->getClient()->post('image_create.php', [
            'mode' => 'delImage',
        ]);

        $this->assertOk($response);
        $this->assertFileDoesNotExist($this->uploadsPath($deleteImageName));
        $this->assertFileDoesNotExist($this->uploadsPath('s_' . $deleteImageName));
        $this->assertXPathCount(
            $response,
            "//button[@name='mode' and @value='delImage']",
            0
        );
    }
    // 画像アップロードが成功した場合に成功メッセージが表示され、画像本体とサムネイルが保存されることを確認する
    public function testImageCreateDisplaysSuccessMessageWhenUploadSucceeds(): void
    {
        $sourceImagePath = $this->existingUploadSourcePath();
        $uploadName = $this->existingUploadImageName();

        $response = $this->uploadImage($uploadName, $sourceImagePath);

        $this->assertStringContainsString(MessageConst::MSG_OK_IMAGE_003, $response->body);
        $this->assertFileExists($this->uploadsPath($uploadName));
        $this->assertFileExists($this->uploadsPath('s_' . $uploadName));
    }

    // アップロードした画像のサイズが2,000,000バイトを超える場合にサイズ超過エラーメッセージが表示されることを確認する
    public function testImageCreateDisplaysErrorMessageWhenUploadedFileExceedsSizeLimit(): void
    {
        $tempFile = $this->createTempFile(str_repeat('A', 2_000_001));
        $uploadName = $this->existingUploadImageName();

        try {
            $response = $this->uploadImage($uploadName, $tempFile, 'image/jpeg');

            $this->assertStringContainsString(
                Utility::replaceStr(MessageConst::MSG_VAL_IMAGE_006, '2MB'),
                $response->body
            );
        } finally {
            @unlink($tempFile);
        }
    }

    // 保存先に同名ディレクトリがある場合に画像登録失敗メッセージが表示されることを確認する
    public function testImageCreateDisplaysErrorMessageWhenUploadDestinationIsDirectory(): void
    {
        $sourceImagePath = $this->existingUploadSourcePath();
        $uploadName = basename($sourceImagePath);
        $blockedPath = $this->uploadsPath($uploadName);
        $tempFile = $this->copyToTempFile($sourceImagePath);
        $this->cleanupPath($blockedPath);

        try {
            $this->assertTrue(@mkdir($blockedPath), "Failed to create blocking directory: {$blockedPath}");

            $response = $this->uploadImage($uploadName, $tempFile);

            $this->assertStringContainsString(MessageConst::MSG_SYS_IMAGE_009, $response->body);
            $this->assertDirectoryExists($blockedPath);
        } finally {
            @unlink($tempFile);
            $this->cleanupPath($blockedPath);
        }
    }

    // ファイル名チェックで不正と判定される名前を送信した場合にファイル名エラーメッセージが表示されることを確認する
    public function testImageCreateDisplaysErrorMessageWhenImageNameIsInvalid(): void
    {
        $response = $this->uploadImage('invalid name.jpg', $this->existingUploadSourcePath());

        $this->assertStringContainsString(MessageConst::MSG_VAL_IMAGE_007, $response->body);
    }

    // アップロードファイルを選択せずに imgup を送信した場合に未選択エラーメッセージが表示されることを確認する
    public function testImageCreateDisplaysErrorMessageWhenNoFileIsSelected(): void
    {
        $this->arrangeImageCreateSession();

        $response = $this->getClient()->post('image_create.php', [
            'mode' => 'imgup',
        ]);

        $this->assertOk($response);
        $this->assertStringContainsString(MessageConst::MSG_VAL_FILE_001, $response->body);
    }

   // この画面に必須の情報が無い場合400エラー
    public function testNonDataPostAccessReturns400(): void
    {
        $this->loginAsAdmin();
        $response = $this->getClient()->post('image_create.php', [
            'mode' => 'invalid',
        ]);
        $this->assertSame(400, $response->status);
    }

    // 許可されていない遷移元画面名が送られた場合は 400 を返す
    public function testImageCreateReturns400ForInvalidFilename(): void
    {
        $this->arrangeImageCreateSession();
        $productEditResponse = $this->getClient()->get('product_edit.php?mn=ABC001');
        $this->assertOk($productEditResponse);

        $response = $this->getClient()->post('image_create.php', [
            'filename' => 'evil',
        ] + $this->buildImageCreateProductData() + $this->extractCsrfPostData($productEditResponse));

        $this->assertSame(400, $response->status);
        $this->assertStringContainsString(MessageConst::MSG_VAL_FILE_018, $response->body);
    }
    // この画面に必須の情報が無い場合400エラー
    public function testNonDataGetAccessReturns400(): void
    {
        $this->loginAsAdmin();
        $response = $this->getClient()->get('image_create.php');
        $this->assertSame(400, $response->status);
    }

    // この画面に権限があるユーザー[perm01]で200で表示される
    public function testImageCreateDisplaysForPerm01(): void
    {
        $this->loginAs('perm01', 'perm0100');
        $response = $this->getClient()->post('image_create.php', [
            'filename' => 'product_create',
        ]);
        $this->assertOk($response);
    }

    // この画面に権限がないユーザーnoauthで403エラーとなる
    public function testImageCreateReturns403ForNoAuth(): void
    {
        $this->loginAs('noauth', 'noauth00');
        $response = $this->getClient()->post('image_create.php', [
            'filename' => 'product_create',
        ]);
        $this->assertSame(403, $response->status, "Expected 403 from {$response->url}");
    }
    // 通常画面表示に必要なテストデータをPOSTする
    private function arrangeImageCreateSession(array $productDataOverrides = []): void
    {
        $this->loginAsAdmin();
        $productListResponse = $this->getClient()->get('product_list.php');
        $this->assertOk($productListResponse);

        $productEditResponse = $this->getClient()->post('product_edit.php', [
            'mngNo' => 'ABC001',
            'filename' => 'product_list',
        ] + $this->extractCsrfPostData($productListResponse));
        $this->assertOk($productEditResponse);

        $response = $this->getClient()->post('image_create.php', array_merge(
            ['filename' => 'product_edit'],
            $this->buildImageCreateProductData($productDataOverrides)
        ) + $this->extractCsrfPostData($productEditResponse));
        $this->assertOk($response);
    }

    private function buildImageCreateProductData(array $overrides = []): array
    {
        return array_merge([
            'management_no' => 'ABC001',
            'category_id' => 10,
            'maker_id' => 10,
            'product_name' => '商品サンプルABC001',
            'wholesale_amount' => 1,
            'retail_amount' => 1,
            'sell_amount' => 1,
            'quantity' => 0,
            'unit_id' => 10,
            'storage_place' => '保管場所01',
            'image_file' => '',
            'remarks' => '',
            'remarks2' => '',
        ], $overrides);
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

    // 画像アップロード画面の初期状態を作り、multipart/form-data で画像アップロード POST を送信する
    private function uploadImage(string $uploadName, string $localFilePath, string $mimeType = 'image/jpeg'): Response
    {
        $this->arrangeImageCreateSession();

        $response = $this->getClient()->post('image_create.php', [
            'mode' => 'imgup',
            'upfile' => curl_file_create($localFilePath, $mimeType, $uploadName),
        ]);

        $this->assertOk($response);

        return $response;
    }

    // public/uploads 内の既存画像を1件選び、アップロード元ファイルとして使う
    private function existingUploadSourcePath(): string
    {
        foreach ($this->expectedUploadImageNames() as $imageName) {
            $imagePath = $this->uploadsPath($imageName);
            $thumbnailPath = $this->thumbnailPathFromImagePath($imagePath);
            if (file_exists($thumbnailPath)) {
                return $imagePath;
            }
        }

        $this->fail('Expected at least one source image with thumbnail in public/uploads.');
    }

    private function existingUploadImageName(): string
    {
        return basename($this->existingUploadSourcePath());
    }

    // public/uploads 配下の対象ファイル絶対パスを返す
    private function uploadsPath(string $fileName): string
    {
        return dirname(__DIR__, 2) . '/public/' . LogicConst::DIR_IMAGES . '/' . $fileName;
    }

    // 指定内容を書き込んだ一時ファイルを作成し、アップロード用のローカルファイルとして返す
    private function createTempFile(string $contents): string
    {
        $path = tempnam(sys_get_temp_dir(), 'image-create-test-');
        $this->assertNotFalse($path, 'Failed to create temporary upload file.');
        file_put_contents($path, $contents);

        return $path;
    }

    // 既存の画像とサムネイルを複製し、削除テスト専用の uploads ファイル一式を作成する
    private function copyToTempFile(string $sourcePath): string
    {
        $tempFile = tempnam(sys_get_temp_dir(), 'image-create-copy-');
        $this->assertNotFalse($tempFile, 'Failed to create temporary copy file.');
        $this->assertTrue(copy($sourcePath, $tempFile), "Failed to copy source file: {$sourcePath}");

        return $tempFile;
    }
    // ファイルまたはディレクトリが残っている場合に、短いリトライ付きで削除する
    private function cleanupPath(string $path): void
    {
        for ($attempt = 0; $attempt < 5; $attempt++) {
            clearstatcache(true, $path);

            if (!file_exists($path)) {
                return;
            }

            if (is_dir($path)) {
                if (@rmdir($path)) {
                    return;
                }
            } else {
                if (@unlink($path)) {
                    return;
                }
            }

            usleep(200000);
        }
    }

    // 画像本体の絶対パスから対応するサムネイル画像の絶対パスを組み立てる
    private function thumbnailPathFromImagePath(string $imagePath): string
    {
        return dirname($imagePath) . '/s_' . basename($imagePath);
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
    public function testImageCreateClearsSessionDataWhenTimedOut(): void
    {
        $this->assertTimedOutProtectedPageClearsSessionDataAndShowsExpiredMessage('image_create.php');
    }
}
