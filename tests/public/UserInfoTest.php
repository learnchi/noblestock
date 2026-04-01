<?php

declare(strict_types=1);

use Tests\Support\WebTestCase;

final class UserInfoTest extends WebTestCase
{
    public function testDirectAccessReturns404(): void
    {
        $response = $this->getClient()->get('user_info.php');
        $this->assertSame(404, $response->status);
    }
}