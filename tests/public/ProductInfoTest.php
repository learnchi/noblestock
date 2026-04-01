<?php

declare(strict_types=1);

use Tests\Support\WebTestCase;

final class ProductInfoTest extends WebTestCase
{
    public function testDirectAccessReturns404(): void
    {
        $response = $this->getClient()->get('product_info.php');
        $this->assertSame(404, $response->status);
    }
}