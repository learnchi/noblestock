<?php
session_cache_limiter("none");
session_start();
date_default_timezone_set('Asia/Tokyo');

require_once __DIR__ . '/../vendor/autoload.php';

use Studiogau\Chandra\Support\SessionHelper;

$payload = json_decode(file_get_contents('php://input'), true);
$_SERVER['SCRIPT_NAME'] = '/noblestock/public/product_list.php';

SessionHelper::delData('biz002', null);
if (is_array($payload) && array_key_exists('selectedMngNos', $payload) && is_array($payload['selectedMngNos'])) {
    SessionHelper::setData('biz002', 'selectedMngNos', array_values($payload['selectedMngNos']));
}

header('Content-Type: application/json');
echo json_encode(['ok' => true], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);