<?php

declare(strict_types=1);

use Tests\Support\WebTestCase;

final class MdlImageviewerTest extends WebTestCase
{
    public function testDirectAccessReturns404(): void
    {
        $response = $this->getClient()->get('mdl_imageviewer.php');
        $this->assertSame(404, $response->status);
    }
}