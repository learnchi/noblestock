<?php

declare(strict_types=1);

use Tests\Support\ImageWebTestCase;
use Tests\Support\Response;
use Tests\Support\TestDatabase;
use Tests\Support\WebClient;

final class ProductInfoRenderTest extends ImageWebTestCase
{
    // product_show 経由で product_info の可変項目を表示し、画像リンクと改行備考が崩れないことを確認する
    public function testProductInfoDisplaysThumbnailAndMultilineRemarksWhenDataExists(): void
    {
        $this->prepareProductShowAsAdmin();
        $this->updateProductFields('ABC001', [
            'image_file' => 'sample001.jpg',
            'storage_place' => '棚A-01',
            'remarks' => "1行目\n2行目",
            'remarks2' => "補足1\r\n補足2",
        ]);

        $response = $this->getClient()->post('product_show.php', [
            'barcode' => 'ABC001',
        ]);
        $this->assertOk($response);
        $response->body = str_replace("\r\n", "\n", $response->body);

        $this->assertInfoCellValue($response, '管理番号', 'ABC001');
        $this->assertInfoCellValue($response, '保管場所', '棚A-01');
        $this->assertStringContainsString('1行目<br />' . "\n" . '2行目', $response->body);
        $this->assertStringContainsString('補足1<br />' . "\n" . '補足2', $response->body);
        $this->assertXPathCount(
            $response,
            "//a[@href='uploads/sample001.jpg' and @data-bs-target='#imageViewer' and @data-bs-image='uploads/sample001.jpg']" .
            "/img[@src='uploads/s_sample001.jpg' and contains(@class,'img-thumbnail')]",
            1
        );
    }

    // 画像ファイル名や備考が空なら、product_info 側で対応する行やサムネイルが出ないことを確認する
    public function testProductInfoOmitsOptionalRowsWhenDataIsEmpty(): void
    {
        $this->prepareProductShowAsAdmin();
        $this->updateProductFields('ABC001', [
            'image_file' => '',
            'remarks' => '',
            'remarks2' => '',
        ]);

        $response = $this->getClient()->post('product_show.php', [
            'barcode' => 'ABC001',
        ]);
        $this->assertOk($response);

        $this->assertXPathCount($response, "//th[normalize-space()='備考']", 0);
        $this->assertXPathCount($response, "//th[normalize-space()='備考２']", 0);
        $this->assertXPathCount($response, "//a[@data-bs-target='#imageViewer' and contains(@href, 'uploads/')]", 0);
    }

    private function prepareProductShowAsAdmin(): void
    {
        TestDatabase::seed();
        self::$client = new WebClient('http://localhost/noblestock/public');
        $this->loginAsAdmin();
        $this->getClient()->get('menu.php');
    }

    private function updateProductFields(string $managementNo, array $fields): void
    {
        $pdo = $this->createTestPdo();
        $stmt = $pdo->prepare(
            'UPDATE products
             SET image_file = :image_file,
                 storage_place = :storage_place,
                 remarks = :remarks,
                 remarks2 = :remarks2
             WHERE management_no = :management_no'
        );
        $stmt->execute([
            'image_file' => (string) ($fields['image_file'] ?? ''),
            'storage_place' => (string) ($fields['storage_place'] ?? ''),
            'remarks' => (string) ($fields['remarks'] ?? ''),
            'remarks2' => (string) ($fields['remarks2'] ?? ''),
            'management_no' => $managementNo,
        ]);
    }

    private function assertInfoCellValue(Response $response, string $label, string $expectedValue): void
    {
        $xpath = new DOMXPath($response->dom());
        $nodes = $xpath->query("//table//tr[th[normalize-space()='{$label}']]/td");
        $this->assertNotFalse($nodes);
        $this->assertSame(1, $nodes->length, "Info cell for {$label} was not found.");
        $this->assertSame($expectedValue, $this->normalizeText($nodes->item(0)?->textContent ?? ''));
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

    private function normalizeText(string $text): string
    {
        return trim((string) preg_replace('/\s+/u', ' ', $text));
    }
}
