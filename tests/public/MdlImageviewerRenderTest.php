<?php

declare(strict_types=1);

use Tests\Support\ImageWebTestCase;
use Tests\Support\Response;
use Tests\Support\TestDatabase;
use Tests\Support\WebClient;

final class MdlImageviewerRenderTest extends ImageWebTestCase
{
    // user_create に共通モーダル本体と画像差し替え用スクリプトが含まれていることを確認する
    public function testUserCreateIncludesImageViewerModalMarkupAndScript(): void
    {
        $this->prepareUserCreateAsAdmin();

        $response = $this->getClient()->get('user_create.php');
        $this->assertOk($response);

        $this->assertXPathCount($response, "//div[@id='imageViewer' and contains(@class,'modal') and contains(@class,'fade')]", 1);
        $this->assertXPathCount($response, "//div[@id='imageViewer']//div[contains(@class,'modal-body')]/img[contains(@class,'img-fluid')]", 1);
        $this->assertStringContainsString("document.getElementById('imageViewer')", $response->body);
        $this->assertStringContainsString('show.bs.modal', $response->body);
        $this->assertStringContainsString("getAttribute('data-bs-image')", $response->body);
        $this->assertStringContainsString('modalBodyInput.src = imageName;', $response->body);
    }

    // product_show で画像リンクを押す導線が、同じ imageViewer モーダルを参照していることを確認する
    public function testProductShowUsesImageViewerModalAsThumbnailTarget(): void
    {
        $this->prepareProductShowAsAdmin();
        $this->updateProductImage('ABC001', 'sample001.jpg');

        $response = $this->getClient()->post('product_show.php', [
            'barcode' => 'ABC001',
        ]);
        $this->assertOk($response);

        $this->assertXPathCount(
            $response,
            "//a[@data-bs-toggle='modal' and @data-bs-target='#imageViewer' and @data-bs-image='uploads/sample001.jpg']" .
            "/img[@src='uploads/s_sample001.jpg']",
            1
        );
        $this->assertXPathCount($response, "//div[@id='imageViewer']", 1);
    }

    private function prepareUserCreateAsAdmin(): void
    {
        TestDatabase::seed();
        self::$client = new WebClient('http://localhost/noblestock/public');
        $this->loginAsAdmin();
        $this->getClient()->get('menu.php');
    }

    private function prepareProductShowAsAdmin(): void
    {
        TestDatabase::seed();
        self::$client = new WebClient('http://localhost/noblestock/public');
        $this->loginAsAdmin();
        $this->getClient()->get('menu.php');
    }

    private function updateProductImage(string $managementNo, string $imageFile): void
    {
        $pdo = $this->createTestPdo();
        $stmt = $pdo->prepare('UPDATE products SET image_file = :image_file WHERE management_no = :management_no');
        $stmt->execute([
            'image_file' => $imageFile,
            'management_no' => $managementNo,
        ]);
    }

    private function assertXPathCount(Response $response, string $xpathExpression, int $expectedCount): void
    {
        $xpath = new DOMXPath($response->dom());
        $nodes = $xpath->query($xpathExpression);
        $this->assertNotFalse($nodes, "Invalid XPath: {$xpathExpression}");
        $this->assertSame($expectedCount, $nodes->length, "Unexpected node count for XPath: {$xpathExpression}");
    }

    private function createTestPdo(): \PDO
    {
        $configPath = dirname(__DIR__, 2) . '/config/dbconfig.ini';
        $config = parse_ini_file($configPath);

        $this->assertIsArray($config, 'Failed to read dbconfig.ini.');

        return new \PDO(
            sprintf(
                'mysql:host=%s;dbname=%s;charset=utf8mb4',
                (string) $config['dbhost'],
                (string) $config['dbname']
            ),
            (string) $config['dbuser'],
            (string) $config['dbpass'],
            [\PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION]
        );
    }
}
