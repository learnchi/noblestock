<?php
/**
 * 商品一覧 画面のajax処理
 * 商品一覧 画面から、バーコード商品選択出力の選択状態を変更した場合
 */
session_cache_limiter("none");
@session_start();
date_default_timezone_set('Asia/Tokyo');


// composerを使用
require_once(__DIR__ . '/../../vendor/autoload.php');

use Studiogau\Chandra\Logging\Logger;
use Studiogau\Chandra\Support\SessionHelper;
use Studiogau\Chandra\Support\Utility;
use Studiogau\Chandra\Auth\AuthService;
use Noblestock\DbLogic\UserRepository;

// JSONレスポンス固定
header('Content-Type: application/json; charset=UTF-8');

// ロガー
$logger = Logger::createDefault(dirname(__DIR__, 1));

// 認証チェック
$auth = new AuthService(new UserRepository(), $logger);
if (!$auth->checkUserSession()) {
	$logger->error(basename(__FILE__) . " checkUserSession failed for user id id=" . $auth->getCurrentUser()?->getUserId());
	http_response_code(401);
	echo json_encode(['ok' => false, 'error' => 'unauthorized']);
	exit;
}

// screenごとの権限チェック product_list.phpの権限
$filename = "product_list";
$filename = "product_list";
if ($auth->getCurrentUser()?->can($filename) === false) {
    $logger->error(basename(__FILE__).' op=auth msg="Permission denied" page='.$filename.' user_id='.$auth->getCurrentUser()?->getUserId());
	http_response_code(404);
	echo json_encode(['ok' => false, 'error' => 'not_found']);
	exit;
}

// AJAX窓口は POST のみ
if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
	$logger->error(basename(__FILE__) . " AJAX窓口はPOSTのみ REQUEST_METHOD=" . ($_SERVER['REQUEST_METHOD'] ?? '').' user_id='.$auth->getCurrentUser()?->getUserId());
	http_response_code(405);
	echo json_encode(['ok' => false, 'error' => 'method_not_allowed']);
	exit;
}

// セッション管理ID biz002: 商品一覧
$funcId = "biz002";

// JSON body を読む
$raw = file_get_contents('php://input') ?: '';
$data = json_decode($raw, true);
if (!is_array($data)) {
	http_response_code(400);
	echo json_encode(['ok' => false, 'error' => 'invalid_json']);
	exit;
}

$mode = (string)($data['mode'] ?? '');
$csrfScope = (string)($data[Utility::getCsrfScopeFieldName()] ?? '');
$csrfToken = (string)($data[Utility::getCsrfFieldName()] ?? '');
$nextCsrf = static function (): array {
	return Utility::issueCsrfPostFields('product_list.barcode_ajax');
};

if (!Utility::validatePostedCsrfToken($csrfScope, $csrfToken)) {
	$logger->error(basename(__FILE__).' op=csrf.validate msg="Invalid csrf token" page='.$filename.' user_id='.$auth->getCurrentUser()?->getUserId());
	http_response_code(403);
	echo json_encode(['ok' => false, 'error' => 'invalid_csrf'] + $nextCsrf(), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
	exit;
}

try {
	switch ($mode) {

		// ----------------------------
		// チェックON/OFFをセッションへ保存
		// mode: toggleSelect
		//   mngNo: string
		//   checked: bool
		// ----------------------------
		case 'toggleSelect': {
			$mngNo   = trim((string)($data['mngNo'] ?? ''));
			$checked = (bool)($data['checked'] ?? false);

			// MANAGEMENT_NOの形式に合わせて制限（必要なら調整）
			if ($mngNo === '' || !preg_match('/^[A-Za-z0-9_-]+$/', $mngNo)) {
				$logger->error(basename(__FILE__) . " toggleSelect データ不正 mngNo=" . $mngNo.' user_id='.$auth->getCurrentUser()?->getUserId());
				http_response_code(400);
				echo json_encode(['ok' => false, 'error' => 'invalid_mngNo']);
				exit;
			}

			$selected = SessionHelper::getData($funcId, 'selectedMngNos') ?? [];
			if (!is_array($selected)) $selected = [];

			if ($checked) {
				if (!in_array($mngNo, $selected, true)) {
					$selected[] = $mngNo;
				}
			} else {
				$selected = array_values(array_filter(
					$selected,
					fn($x) => $x !== $mngNo
				));
			}

			SessionHelper::setData($funcId, 'selectedMngNos', $selected);
			echo json_encode([
				'ok'    => true,
				'mode'  => 'toggleSelect',
				'count' => count($selected),
			] + $nextCsrf(), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
			exit;
		}

		// ----------------------------
		// 全選択（追加：既存の選択にマージする）
		// mode: selectAll
		// ----------------------------
		case 'selectAll': {
			$mngNos = $data['mngNos'] ?? [];
			if (!is_array($mngNos)) {
				$logger->error(basename(__FILE__) . " selectAll データ不正 mngNos=" . print_r($mngNos, true).' user_id='.$auth->getCurrentUser()?->getUserId());
				http_response_code(400);
				echo json_encode(['ok' => false, 'error' => 'invalid_mngNos']);
				exit;
			}

			// 追加分を正規化（形式チェックしつつ）
			$add = [];
			foreach ($mngNos as $mngNo) {
				$mngNo = trim((string)$mngNo);
				if ($mngNo !== '' && preg_match('/^[A-Za-z0-9_-]+$/', $mngNo)) {
					$add[] = $mngNo;
				}
			}
			$add = array_values(array_unique($add));

			// 現在の選択状態を取得して、追加分をマージ
			$selected = SessionHelper::getData($funcId, 'selectedMngNos') ?? [];
			if (!is_array($selected)) $selected = [];

			$merged = array_values(array_unique(array_merge($selected, $add)));

			SessionHelper::setData($funcId, 'selectedMngNos', $merged);
			echo json_encode([
				'ok'    => true,
				'mode'  => 'selectAll',
				'count' => count($merged),
				'added' => count($add),
			] + $nextCsrf(), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
			exit;
		}

		// ----------------------------
		// 全解除
		// mode: clearSelect
		// ----------------------------
		case 'clearSelect': {
			SessionHelper::setData($funcId, 'selectedMngNos', []);
			echo json_encode(['ok' => true, 'mode' => 'clearSelect', 'count' => 0] + $nextCsrf(), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
			exit;
		}

		// ----------------------------
		// 画面に表示されている商品を解除
		// mode: clearVisible
		// ----------------------------
		case 'clearVisible': {
			$mngNos = $data['mngNos'] ?? [];
			if (!is_array($mngNos)) {
				$logger->error(basename(__FILE__) . " clearVisible データ不正 mngNos=" . print_r($mngNos, true).' user_id='.$auth->getCurrentUser()?->getUserId());
				http_response_code(400);
				echo json_encode(['ok' => false, 'error' => 'invalid_mngNos']);
				exit;
			}

			// 入力を正規化（形式チェック＋重複排除）
			$targets = [];
			foreach ($mngNos as $mngNo) {
				$mngNo = trim((string)$mngNo);
				if ($mngNo !== '' && preg_match('/^[A-Za-z0-9_-]+$/', $mngNo)) {
					$targets[] = $mngNo;
				}
			}
			$targets = array_values(array_unique($targets));

			// 現在の選択状態
			$selected = SessionHelper::getData($funcId, 'selectedMngNos') ?? [];
			if (!is_array($selected)) $selected = [];

			// 差し引く（targets に含まれるものだけ除去）
			if (!empty($targets) && !empty($selected)) {
				$targetSet = array_flip($targets); // O(1) lookups
				$selected = array_values(array_filter(
					$selected,
					fn($x) => !isset($targetSet[$x])
				));
			}

			SessionHelper::setData($funcId, 'selectedMngNos', $selected);

			echo json_encode([
				'ok'      => true,
				'mode'    => 'clearVisible',
				'count'   => count($selected),
				'removed' => count($targets),
			] + $nextCsrf(), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
			exit;
		}

		default:
			http_response_code(400);
			echo json_encode(['ok' => false, 'error' => 'invalid_mode']);
			exit;
	}

} catch (Throwable $e) {
	$logger->error(basename(__FILE__).' op=ajax.update msg="Error occurred during ajax execute" page='.$filename.' user_id='.$auth->getCurrentUser()?->getUserId()).' detail='.$e->getMessage();
	http_response_code(500);
	echo json_encode(['ok' => false, 'error' => 'server_error']);
	exit;
}
