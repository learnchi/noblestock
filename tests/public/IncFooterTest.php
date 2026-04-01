<?php

declare(strict_types=1);

use Tests\Support\WebTestCase;

final class IncFooterTest extends WebTestCase
{
    public function testDirectAccessReturns404(): void
    {
        $response = $this->getClient()->get('inc_footer.php');
        $this->assertSame(404, $response->status);
    }
}