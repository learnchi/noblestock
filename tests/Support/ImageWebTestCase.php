<?php

declare(strict_types=1);

namespace Tests\Support;

abstract class ImageWebTestCase extends WebTestCase
{
    public static function setUpBeforeClass(): void
    {
        parent::setUpBeforeClass();
    }

    protected function setUp(): void
    {
        TestImageFiles::seed();
    }

    protected function tearDown(): void
    {
        TestImageFiles::restoreOriginal();
    }
}
