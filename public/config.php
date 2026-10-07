<?php
/**
 * 機能設定 画面
 */
session_cache_limiter("none");
session_start();
date_default_timezone_set('Asia/Tokyo');


// composerを使用
require_once(__DIR__ . '/../vendor/autoload.php');
use Studiogau\Chandra\Logging\Logger;
use Studiogau\Chandra\Support\SessionHelper;
use Studiogau\Chandra\Support\Utility;
use Studiogau\Chandra\Auth\AuthService;
use Noblestock\Logic\MessageConst;
use Noblestock\DbLogic\UserRepository;
use Noblestock\DbLogic\Config;

// ロガー
$logger = Logger::createDefault(dirname(__DIR__, 1));

// 認証チェック
$auth = new AuthService(new UserRepository(), $logger);
if (!$auth->checkUserSession()) {
	$logger->error(basename(__FILE__)." checkUserSession failed for user id id=".$auth->getCurrentUser()?->getLoginId());
	if (session_status() !== PHP_SESSION_ACTIVE) {
		session_start();
	}
	SessionHelper::FlushError(MessageConst::MSG_INF_AUTH_002);
	// チェック結果がエラーの場合ログイン画面に遷移
	header("Location: index.php", true, 302);
	exit;
}
// screenごとの権限チェック
$filename = basename(__FILE__, '.php');
if ($auth->getCurrentUser()?->can($filename) === false) {
    $logger->error(basename(__FILE__).' op=auth msg="Permission denied" page='.$filename.' user_id='.$auth->getCurrentUser()?->getLoginId());
	http_response_code(403);
	header('Content-Type: text/plain; charset=UTF-8');
	echo MessageConst::MSG_INF_AUTH_003;
	exit;
}

// セッション管理ID 画像選択登録
$funcId = "mst901";

// 設定値ロジック
$config = new Config();
// ロジック処理
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
	$csrfScope = (string)($_POST[Utility::getCsrfScopeFieldName()] ?? '');
	$csrfToken = (string)($_POST[Utility::getCsrfFieldName()] ?? '');
	if (!Utility::validatePostedCsrfToken($csrfScope, $csrfToken)) {
		$logger->error(basename(__FILE__).' op=config.update msg="Invalid csrf token" page=config.php user_id='.$auth->getCurrentUser()?->getLoginId());
		SessionHelper::FlushError(MessageConst::MSG_SYS_COMMON_900);
		header('Location: config.php', true, 303);
		exit;
	}

    if (($_POST["mode"] ?? '') === "update") {

		// 設定更新
		$hasError = false;
		foreach ($_POST ?? [] as $key => $value) {
			if ($key !== "mode"
				&& $key !== Utility::getCsrfFieldName()
				&& $key !== Utility::getCsrfScopeFieldName()) {
				$wkup = array("config_key" => $key, "value_int" => $value, "value_str" => null);
				try {
					$config->update($wkup);
					SessionHelper::setPref($key, $value);
				} catch (\Throwable $e) { 
					$hasError = true;
				}
			}
		}
		if ($hasError) {
			SessionHelper::flushError(MessageConst::MSG_SYS_COMMON_900);
		} else {
			SessionHelper::flushSuccess(MessageConst::MSG_OK_CONF_002);
		}
	}
    // 画面表示はGETでredirect
    header('Location: config.php', true, 303);
    exit;
}

// 設定値取得
$retlist = $config->list();
?>

<!DOCTYPE html>
<html lang="ja">
	<head>
		<?php $title = "機能設定"; require_once(__DIR__."/inc_head.php"); ?>
		<script src="js/Validation.js"></script>
	</head>
<body class="maintenance">
	<?php $userName = $auth->getCurrentUser()?->getUserName(); require_once(__DIR__."/inc_nav.php"); ?>
	<main class="container py-2">

		<?php if (SessionHelper::hasFlushError()) { ?>
		<div class="alert alert-danger alert-dismissible fade show" role="alert">
			<div><?= Utility::h(SessionHelper::getFlushError()) ?></div>
			<button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
			</div>
		<?php } ?>

		<?php if (SessionHelper::hasFlushSuccess()) { ?>
		<div class="alert alert-success alert-dismissible fade show" role="alert">
			<div><?= Utility::h(SessionHelper::getFlushSuccess()) ?></div>
			<button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
			</div>
		<?php } ?>

		<!-- 案内 -->
		<div class="alert alert-info alert-dismissible fade show" role="alert">
			<div><?=MessageConst::MSG_INF_CONF_003 ?></div>
			<button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
		</div>
		
		<?php if (count($retlist ?? []) > 0): ?>
		<form method="post" action="config.php" class="needs-validation" novalidate>
		<?= Utility::renderCsrfHiddenInput('config.form') ?>
		<table class="table table-sm table-responsive">
			<thead>
				<tr>
					<th scope="col">項目</th>
					<th scope="col">設定値</th>
					<th scope="col">説明</th>

				</tr>
			</thead>
			<tbody>
				<?php foreach ($retlist as $i => $wk): ?>
				<tr>
					<td>
						<input type="text" class="form-control-plaintext form-control-sm" value="<?= Utility::h($wk['config_key']) ?>">
					</td>
					<td>
						<input type="number" class="form-control form-control-sm" min="0" max="255" name="<?= Utility::h($wk['config_key']) ?>" value="<?= Utility::h($wk['value_int']) ?>" required>
						<div class="invalid-feedback"><?= MessageConst::MSG_VAL_PRODUCT_004 ?></div>
					</td>
					<td><?= Utility::h($wk['config_description']) ?></td>
				</tr>
				<?php endforeach; ?>
			</tbody>
		</table>

		<!-- ボタン -->
		<div class="d-flex justify-content-evenly">
			<a href="menu_master.php" type="button" class="btn btn-primary">戻る</a>
			<button type="submit" class="btn btn-primary" name="mode" value="update">登録</button>
		</div>

		</form>
		<?php endif; ?>

	</main>
	<?php require_once(__DIR__."/inc_footer.php"); ?>
	</body>
</html>
