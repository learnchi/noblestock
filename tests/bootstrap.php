<?php
// PHPUnit bootstrap for Noblestock

declare(strict_types=1);

$root = dirname(__DIR__);

require_once $root . '/vendor/autoload.php';
date_default_timezone_set('Asia/Tokyo');

// Helper classes for tests
require_once __DIR__ . '/Support/TestDatabase.php';
require_once __DIR__ . '/Support/TestImageFiles.php';
require_once __DIR__ . '/Support/WebClient.php';
require_once __DIR__ . '/Support/WebTestCase.php';
require_once __DIR__ . '/Support/ImageWebTestCase.php';
require_once __DIR__ . '/Support/SpreadsheetResponseHelper.php';
require_once __DIR__ . '/Support/ExcelSettingsHelper.php';
require_once __DIR__ . '/Support/BarcodeOutputSelectionHelper.php';
