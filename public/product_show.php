<?php
/**
 * 商品表示 画面
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
use Noblestock\Logic\BarcodeProcessor;
use Noblestock\DbLogic\UserRepository;

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
$funcId = "biz001";

// 自機能以外のSessionデータをクリア
SessionHelper::clearDataExceptFunc($funcId);

$productData = null;

$bcin = trim($_POST['barcode'] ?? '');
if ($bcin !== '') {
	$csrfScope = (string)($_POST[Utility::getCsrfScopeFieldName()] ?? '');
	$csrfToken = (string)($_POST[Utility::getCsrfFieldName()] ?? '');
	if (!Utility::validatePostedCsrfToken($csrfScope, $csrfToken)) {
		$logger->error(basename(__FILE__).' op=barcode.select msg="Invalid csrf token" page=product_show.php user_id='.$auth->getCurrentUser()?->getUserId());
		SessionHelper::FlushError(MessageConst::MSG_SYS_COMMON_900);
		header("Location: product_show.php");
		exit;
	}

	// コードから処理を実施する
	$processor = new BarcodeProcessor(
		auth: $auth,
		funcId: $funcId,
		mode: BarcodeProcessor::MODE_SELECT,
		logger: $logger,
	);
	$cmdResult = $processor->handle($bcin);

	// バーコードメニュー指定時にリダイレクトする
	if ($cmdResult->isRedirect()) {
		header("Location: {$cmdResult->getRedirectUrl()}");
		exit;
	}

	// 結果配列がある
	if ($cmdResult->hasResult()) {
		$productData = $cmdResult->getResultArray();
	}
}
?>

<!DOCTYPE html>
<html lang="ja">
<head>
	<?php $title = "商品表示"; require_once(__DIR__."/inc_head.php"); ?>
	<script src="js/BarcodeInput.js"></script>
</head>

<body class="product">
	<?php require_once(__DIR__."/mdl_imageviewer.php"); ?>
	<?php $userName = $auth->getCurrentUser()?->getUserName(); require_once(__DIR__."/inc_nav.php"); ?>
	<main class="container py-2" style="min-height: calc(100vh - 40px - 60px);">

		<?php if (SessionHelper::hasFlushError()) { ?>
		<div class="alert alert-danger alert-dismissible fade show" role="alert">
			<div><?= Utility::h(SessionHelper::getFlushError()) ?></div>
			<button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
		</div>
		<?php } ?>

		<!-- 案内 -->
		<div class="alert alert-info alert-dismissible fade show" role="alert">
			<div><?=MessageConst::MSG_INF_BARCODE_001?></div>
			<button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
		</div>

		<!-- バーコード入力 -->
		<div id="barcode" class="py-2">
			<form method="POST" action="<?= $filename ?>.php" class="d-flex justify-content-center">
				<?= Utility::renderCsrfHiddenInput('product_show.form') ?>
				<button class="btn btn-outline-dark"><i class="bi bi-upc-scan"></i></button>
				<input type="text" class="form-control" name="barcode" maxlength="50" autocomplete="off" style="width: unset;"/>
			</form>
		</div>

		<div class="h-100 overflow-y-auto">
				<?php
				if (!is_null($productData)) {
					// 商品詳細
					include(__DIR__."/product_info.php");
				}
				?>
		</div>
		<a href="menu.php" type="button" class="btn btn-primary">戻る</a>
	</main>
	<?php require_once(__DIR__."/inc_footer.php"); ?>
</body>
</html>

<?php
?>
