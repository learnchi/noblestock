<?php
/**
 * ユーザー登録
 */
session_cache_limiter("none");
@session_start();
date_default_timezone_set('Asia/Tokyo');


// composerを使用
require_once(__DIR__ . '/../vendor/autoload.php');
use Studiogau\Chandra\Logging\Logger;
use Studiogau\Chandra\Support\SessionHelper;
use Studiogau\Chandra\Support\Utility;
use Studiogau\Chandra\Auth\AuthService;
use Noblestock\Logic\MessageConst;
use Noblestock\DbLogic\UserRepository;
use Noblestock\DbLogic\User;

// ロガー
$logger = Logger::createDefault(dirname(__DIR__, 1));

// 認証チェック
$auth = new AuthService(new UserRepository(), $logger);
if (!$auth->checkUserSession()) {
	$logger->error(basename(__FILE__)." checkUserSession failed for user id id=".$auth->getCurrentUser()?->getUserId());
	if (session_status() !== PHP_SESSION_ACTIVE) {
		@session_start();
	}
	SessionHelper::FlushError(MessageConst::MSG_INF_AUTH_002);
	// チェック結果がエラーの場合ログイン画面に遷移
	header("Location: index.php");
	exit;
}
// screenごとの権限チェック
$filename = basename(__FILE__, '.php');
if ($auth->getCurrentUser()?->can($filename) === false) {
    $logger->error(basename(__FILE__).' op=auth msg="Permission denied" page='.$filename.' user_id='.$auth->getCurrentUser()?->getUserId());
	http_response_code(403);
	header('Content-Type: text/plain; charset=UTF-8');
	echo MessageConst::MSG_INF_AUTH_003;
	exit;
}

// セッション管理ID
$funcId = "mst907";

// ユーザーデータをセッションから取得
$userData = SessionHelper::getData($funcId, "userData");
if (empty($userData)) {

	// sort_order最大値取得
	$user = new User();
	$maxSortOrder = $user->selectMaxSortOrder();
	$maxSortOrder += 1;

	$userData = array("id" => "",
					"login_id" => "", 
					"password_hash" => "", 
					"user_name" => "", 
					"furigana" => "", 
					"email" => "", 
					"sort_order" => $maxSortOrder, 
					"authority" => "11111111111111111100");
}

// エラー情報を取得
$errors = SessionHelper::getData($funcId, "validation-errors");

// ロジック処理
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
	$csrfScope = (string)($_POST[Utility::getCsrfScopeFieldName()] ?? '');
	$csrfToken = (string)($_POST[Utility::getCsrfFieldName()] ?? '');
	if (!Utility::validatePostedCsrfToken($csrfScope, $csrfToken)) {
		$logger->error(basename(__FILE__).' op=user.create msg="Invalid csrf token" page=user_create.php user_id='.$auth->getCurrentUser()?->getUserId());
		SessionHelper::FlushError(MessageConst::MSG_SYS_COMMON_900);
		header("Location: " . $_SERVER['PHP_SELF'], true, 303);
		exit;
	}

	// リセット押下の場合は画面内容をクリアする
	if ($_POST['mode'] === 'reset') {
		SessionHelper::delData($funcId, "userData");
		SessionHelper::delData($funcId, "validation-errors");
		$userData = null;
		$errors = null;    // 確認画面(product_confirm)で設定したエラー情報

	}
	// スクロール位置をセッションに保持
	if (isset($_POST["pos"])) {
		SessionHelper::setData("mst902", "scrollPos", $_POST["pos"]);
	}

	// 画面表示はGETでredirect
	header("Location: " . $_SERVER['PHP_SELF'], true, 303);   // 302になることがあるため303を明示
	exit;
}

?>

<!DOCTYPE html>
<html lang="ja">
	<head>
		<?php $title = "ユーザー登録"; require_once(__DIR__."/inc_head.php"); ?>
		<script src="js/Validation.js"></script>
	</head>
	<body class="maintenance">
	<?php require_once(__DIR__."/mdl_imageviewer.php"); ?>
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
			<div><?=MessageConst::MSG_INF_USER_001 ?></div>
			<button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
		</div>

		<form method="post" action="user_confirm.php" class="needs-validation" novalidate>
			<?= Utility::renderCsrfHiddenInput('user_create.form') ?>

			<?php 
				$pwRequired = true;    // パスワード入力必須
				// ユーザー情報
				include(__DIR__."/user_edit_info.php");
			?>

			<!-- ボタン -->
			<div class="d-flex justify-content-evenly">
				<a href="user_list.php" type="button" class="btn btn-primary">戻る</a>
				<button type="submit" class="btn btn-primary" formaction="user_create.php" name="mode" value="reset">リセット</button>
				<button type="submit" class="btn btn-primary" name="mode" value="confirm">登録</button>

			</div>
		</form>
			
	</main>
	<?php require_once(__DIR__."/inc_footer.php"); ?>
	</body>
</html>
