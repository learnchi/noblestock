<?php
/**
 * バーコード生成
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
use Studiogau\Chandra\Database\RecordNotFoundException;
use Studiogau\Chandra\Database\MultipleRecordsFoundException;
use Noblestock\Logic\LogicConst;
use Noblestock\Logic\BarcodeGenerator;
use Noblestock\Util\UtilExcel;
use Noblestock\DbLogic\UserRepository;
use Noblestock\DbLogic\Product;
use Noblestock\DbLogic\User;
use Noblestock\DbLogic\Stock;
use Noblestock\Logic\MenuRouter;
use Noblestock\Logic\MessageConst;

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

// 実行時間
set_time_limit(LogicConst::RUN_TIME_LIMIT);

// メモリアップ
ini_set("memory_limit",LogicConst::MEMORY_LIMIT);

// セッション管理ID
$funcId = "biz401";

// 自機能以外のSessionデータをクリア
SessionHelper::clearDataExceptFunc($funcId);

// 商品ロジック
$product = new Product();

// ラベルサイズ
$barPrtSize = intval(SessionHelper::getPref("BAR_PRT_SIZE"));

// ユーザー情報取得
$user = new User();
$currentLoginId = $auth->getCurrentUser()?->getLoginId();
$currentUserId = $currentLoginId === null ? null : $user->getIdByUserId($currentLoginId);
if ($currentUserId === null) {
	$logger->error(
		basename(__FILE__)
		. ' op=user.lookup msg="current user not found"'
		. ' login_id=' . ($currentLoginId ?? 'null')
	);
	$auth->logout();
	if (session_status() !== PHP_SESSION_ACTIVE) {
		session_start();
	}
	SessionHelper::FlushError(MessageConst::MSG_INF_AUTH_002);
	header("Location: index.php", true, 302);
	exit;
}
$userSeq = "u".$currentUserId."_";

// バーコード生成
$retbar = -1;
$productData = null;
$barset = null;

$bcin = trim($_POST['barcode'] ?? '');
if ($bcin !== '') {
	$csrfScope = (string)($_POST[Utility::getCsrfScopeFieldName()] ?? '');
	$csrfToken = (string)($_POST[Utility::getCsrfFieldName()] ?? '');
	if (!Utility::validatePostedCsrfToken($csrfScope, $csrfToken)) {
		$logger->error(basename(__FILE__).' op=barcode.create msg="Invalid csrf token" page=barcode_create.php user_id='.$auth->getCurrentUser()?->getLoginId());
		SessionHelper::FlushError(MessageConst::MSG_SYS_COMMON_900);
		header("Location: barcode_create.php", true, 303);
		exit;
	}


	// バーコードメニュー指定時にリダイレクトする
    $router = new MenuRouter($auth);
    $target = $router->resolve($bcin);

    if ($target !== null) {
        header("Location: {$target}", true, 303);
        exit;
    }

	// バーコードExcel出力用リスト作成
	$barcode = new BarcodeGenerator();
	$retbar = $barcode->createBarcode($bcin, __DIR__.'/../'.LogicConst::DIR_TMP.'/'.$userSeq.'barcode.png');
	
	if ($retbar === 0) {
		$barmng = $bcin;
		$barimg = "";
		$bartop = "";
		$barbtm = "";

		try{
			$productData = $product->select($barmng);
		} catch (RecordNotFoundException | MultipleRecordsFoundException $e) {
			SessionHelper::flushError(MessageConst::MSG_VAL_BARCODE_002);
			$productData['management_no'] = $barmng;
		}

		$barLayout = UtilExcel::getBarcodeLayout($productData);
		$barmng = $barLayout["barmng"];
		$barimg = $barLayout['barimg'];
		$bartop = $barLayout['bartop'];
		$barbtm = $barLayout['barbtm'];
			
		$barCnt = LogicConst::BAR_PRT_SIZE_CNT[$barPrtSize] ?? LogicConst::BAR_PRT_SIZE_CNT[1];

		$label_upper = intval(SessionHelper::getPref("LABEL_UPPER"));
		$label_lower = intval(SessionHelper::getPref("LABEL_LOWER"));
		$barlist = array();
		for ($i = 0; $i < $barCnt; $i++) {
			$topStr = $bartop;
			if ($label_upper === 9) $topStr = $i + 1;
			$btmStr = $barbtm;
			if ($label_lower === 9) $btmStr = $i + 1;
			$barlist[] = array("bar_no" => $barmng, "img_file" => $barimg, "bar_top" => $topStr, "bar_btm" => $btmStr);
		}
		SessionHelper::setData("com901", "barlist", $barlist); // セッション管理ID com901=バーコード出力
		// 画面表示用
		SessionHelper::setData("com901", "barFile", "mng_product");
		$bartype = "Code39";
		$chkbar = BarcodeGenerator::check($_POST["barcode"]);
		if ($chkbar === 128) $bartype = "Code128";
		if ($chkbar === 12) $bartype = "UPC-A";
		if ($chkbar === 13) $bartype = "JAN";

		$barset = LogicConst::BAR_PRT_SIZE_SET[$barPrtSize] ?? LogicConst::BAR_PRT_SIZE_SET[1];

	} else {
		SessionHelper::flushError(MessageConst::MSG_SYS_BARCODE_018);
	}
	// バーコードExcel出力用リスト作成 ここまで
}


// バーコード規格の説明として表示する
$barmsg = LogicConst::BAR_PRT_SIZE_DEFS[$barPrtSize] ?? LogicConst::BAR_PRT_SIZE_DEFS[1];

$guideMsg = MessageConst::MSG_INF_BARCODE_001.$barmsg;

// 店舗在庫数取得
if ($productData != null && $productData != -1 && $productData != -2) {
	$stock = new Stock();
	$locationStock = "";
	$locationStock = $stock->getStockPerLocation($productData['management_no']);
	$productData['SHOP_QUANTITY'] = $locationStock;
}
?>


<!DOCTYPE html>
<html lang="ja">
	<head>
		<?php $title = "バーコード生成"; require_once(__DIR__."/inc_head.php"); ?>
		<script src="js/BarcodeInput.js"></script>
</head>

<body class="barcode">
	<?php require_once(__DIR__."/mdl_imageviewer.php"); ?>
	<?php $userName = $auth->getCurrentUser()?->getUserName(); require_once(__DIR__."/inc_nav.php"); ?>
	<main class="container py-2">

		<?php if (SessionHelper::hasFlushError()) { ?>
		<div class="alert alert-danger alert-dismissible fade show" role="alert">
			<div><?= Utility::h(SessionHelper::getFlushError()) ?></div>
			<button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
		</div>
		<?php } ?>

		<!-- 案内 -->
		<div class="alert alert-info alert-dismissible fade show" role="alert">
			<div><?=$guideMsg ?></div>
			<button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
		</div>

		<!-- バーコード入力 -->
		<div id="barcode" class="py-2">
			<form method="POST" action="<?= $filename ?>.php" class="d-flex justify-content-center">
				<?= Utility::renderCsrfHiddenInput('barcode_create.form') ?>
				<button class="btn btn-outline-dark"><i class="bi bi-upc-scan"></i></button>
				<input type="text" class="form-control" name="barcode" maxlength="50" autocomplete="off" style="width: unset;"/>
			</form>
		</div>

		<?php
		if ($retbar === 0) {
		?>
		<div class="p-5 d-flex flex-column flex-lg-row align-items-center justify-content-evenly">
			<h3>バーコードイメージ <?=$bartype ?></h3>
			<div>
				<img src="<?= Utility::h('../'.LogicConst::DIR_TMP.'/'.$userSeq.'barcode.png') ?>" hspace="2" vspace="10" title="バーコード" />
			</div>
		</div>
		<?php
		}
		?>

		<?php
		if ($productData) {
			// 商品詳細
			include(__DIR__."/product_info.php");
		}
		?>

		<!-- ボタン -->
		<div class="d-flex justify-content-evenly">
			<a href="menu.php" type="button" class="btn btn-primary">戻る</a>
			<div>
				<form method="POST" action="barcode_bulk_export.php">
					<?= Utility::renderCsrfHiddenInput('barcode_create.form') ?>
					<input type="hidden" name="mode" value="export">
					<button type="submit" class="btn btn-primary <?php if ($retbar !== 0) { ?>disabled<?php } ?>" <?php if ($retbar !== 0) { ?>disabled aria-disabled="true"<?php } ?>>バーコード出力</button>
				</form>
				<?php if($barset){?><?=$barset ?> で出力します<?php } ?>
			</div>
		</div>
	</main>
	<?php require_once(__DIR__."/inc_footer.php"); ?>
	</body>
</html>
