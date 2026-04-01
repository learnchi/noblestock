<?php
/**
 * バーコード選択出力 画面
 */
session_cache_limiter("none");
@session_start();
date_default_timezone_set('Asia/Tokyo');


// composerを使用
require_once(__DIR__ . '/../vendor/autoload.php');
use Studiogau\Chandra\Logging\Logger;
use Studiogau\Chandra\Support\Utility;
use Studiogau\Chandra\Support\SessionHelper;
use Studiogau\Chandra\Auth\AuthService;
use Noblestock\Logic\LogicConst;
use Noblestock\Logic\MessageConst;
use Noblestock\DbLogic\UserRepository;
use Noblestock\DbLogic\Product;
use Noblestock\Util\UtilExcel;

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

// セッション管理ID biz008: バーコード選択出力
$funcId = "biz008";

// バーコード出力リストをセッションから取得
$selectedMngNos = SessionHelper::getData("biz002", "selectedMngNos");
$barproduct_list = SessionHelper::getData($funcId, "barproduct_list");

// ロジック処理
$product = new Product();
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
	$csrfScope = (string)($_POST[Utility::getCsrfScopeFieldName()] ?? '');
	$csrfToken = (string)($_POST[Utility::getCsrfFieldName()] ?? '');
	if (!Utility::validatePostedCsrfToken($csrfScope, $csrfToken)) {
		$logger->error(basename(__FILE__).' op=product.barcode.list msg="Invalid csrf token" page=product_barcode_list.php user_id='.$auth->getCurrentUser()?->getUserId());
		SessionHelper::FlushError(MessageConst::MSG_SYS_COMMON_900);
		header("Location: " . $_SERVER['PHP_SELF']);
		exit;
	}


	$mode = $_POST["mode"] ?? '';
	// if ($mode === "cancel") {
	// 	// キャンセル
	// 	SessionHelper::delData("biz002", "selectedMngNos");
	// 	SessionHelper::delData($funcId, "barproduct_list");
	// } else 
	if ($mode === "regist") {
		// バーコード出力
		$barlist = array();
		$wkBarproduct_list = array();
		$label_upper = intval(SessionHelper::getPref("LABEL_UPPER"));
		$label_lower = intval(SessionHelper::getPref("LABEL_LOWER"));
		$seqNo = 0;
		foreach ($barproduct_list ?? [] as $i => $wk) {
			$out_num = $_POST["OUT_NUM_".$i];
			if (!is_numeric($out_num)) $out_num = 1;
			if ($out_num < 1) $out_num = 0;
			$wk['OUT_NUM'] = $out_num;
			$wkBarproduct_list[] = $wk;

			$barLayout = UtilExcel::getBarcodeLayout($wk);
			$barmng = $barLayout["barmng"];
			$barimg = $barLayout['barimg'];
			$bartop = $barLayout['bartop'];
			$barbtm = $barLayout['barbtm'];
			for ($j = 0; $j < $out_num; $j++) {
				$seqNo++;
				$topStr = $bartop;
				if ($label_upper === 9) $topStr = $seqNo;
				$btmStr = $barbtm;
				if ($label_lower === 9) $btmStr = $seqNo;
				$barlist[] =array("bar_no" => $barmng, "img_file" => $barimg, "bar_top" => $topStr, "bar_btm" => $btmStr);
			}
		}
		$barproduct_list = $wkBarproduct_list;
		SessionHelper::setData($funcId, "barproduct_list", $barproduct_list);
		unset($wkBarproduct_list);

		SessionHelper::setData("com901", "barlist", $barlist);    // バーコード出力セッションに保存
		SessionHelper::setData("com901", "barFile", "product");
		if (count($barlist) > 0) {
			if (count($barlist) <= LogicConst::BARCODE_ITEM_EXCEL) {
				// バーコードExcel一括出力
				$_POST['mode'] = 'export';
				require(__DIR__ . "/barcode_bulk_export.php");
				exit;
			} else {
				// バーコードExcel分割出力画面
				SessionHelper::setData("com901", "backTo", $filename);

				// スクロール位置を保存
				// この時点でPOSTにscrollPosがある場合はproduct_listではなくこの画面のposである
				if (isset($_POST["pos"])) {
					$scrollPos = $_POST["pos"];
					SessionHelper::setData($funcId, "scrollPos", $scrollPos);
				}
				header("Location: barcode_list_export_split.php");
			}
			exit;
		} else {
			// バーコードデータがない場合
			SessionHelper::flushError(MessageConst::MSG_SYS_FILE_013);
		}
	} else if ($mode === "show") {
		// スクロール位置をセッションに保持
		if (isset($_POST["pos"])) {    // 0もある
			SessionHelper::setData("biz002", "scrollPos", $_POST["pos"]);
		}
	}

	// 画面表示はGETでredirect
	header("Location: " . $_SERVER['PHP_SELF'], true, 303);   // 302になることがあるため303を明示
	exit;
}

// 商品の選択が無い場合はエラー
if (empty($selectedMngNos)) {
	http_response_code(400);
	header('Content-Type: text/plain; charset=UTF-8');
	echo MessageConst::MSG_VAL_FILE_018;
	exit;
}

if (count($barproduct_list ?? []) < 1) {
	$product = new Product();
	$barproduct_list = $product->selectMngNos($selectedMngNos);
	SessionHelper::setData($funcId, "barproduct_list", $barproduct_list);
}

// スクロール位置
$scrollPos = SessionHelper::getData($funcId, "scrollPos");
if ($scrollPos == null) $scrollPos = 0;

?>

<!DOCTYPE html>
<html lang="ja">
	<head>
		<?php $title = "バーコード選択出力"; require_once(__DIR__."/inc_head.php"); ?>

		<script>const scrollPos= <?= (int)($scrollPos ?? 0) ?>;</script><!-- スクロール位置 -->
		<script src="js/scrollPosition.js"></script>

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

		<?php if (SessionHelper::hasFlushSuccess()) { ?>
		<div class="alert alert-success alert-dismissible fade show" role="alert">
			<div><?= Utility::h(SessionHelper::getFlushSuccess()) ?></div>
			<button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
		</div>
		<?php } ?>

		<!-- 案内 -->
		<div class="alert alert-info alert-dismissible fade show" role="alert">
			<div><?=MessageConst::MSG_INF_FILE_012 ?></div>
			<button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
		</div>


		<?php
		if (count($barproduct_list ?? []) > 0) {
		?>
		<div id="table-wrapper" class="h-100 overflow-y-auto">
		<form method="POST" action="product_barcode_list.php" id="registForm">
			<?= Utility::renderCsrfHiddenInput('product_barcode_list.form') ?>
			<input type="hidden" name="filename" value="<?= $filename ?>">
			<input type="hidden" name="pos" value=""><!-- submit時に代入 -->
			<table class="table table-sm table-responsive">
				<thead>
				<tr>
					<th scope="col">管理番号</th>
					<th scope="col">商品名</th>
					<th scope="col">個数</th>
				</thead>
				<tbody>
				<?php
				foreach ($barproduct_list ?? [] as $i => $wk) {
				?>
					<tr>
						<td><?=Utility::h($wk['management_no']) ?></td>
						<td><?=Utility::h($wk['product_name']) ?></td>
						<td><input type="number" min="0" max="4294967295" name="OUT_NUM_<?=$i?>" class="text-end" value="<?=Utility::h($wk['OUT_NUM']) ?>" /></td>
					</tr>
				<?php
				}
				?>
				</tbody>
			</table>
		</form>
		</div>
		<?php
		}
		?>

		<!-- ボタン -->
		<div class="d-flex justify-content-evenly">

			<a href="product_list.php" type="button" class="btn btn-primary">戻る</a>
			<!-- <form method="POST" action="product_barcode_list.php">
				<input type="hidden" name="mode" value="cancel">
				<button type="submit" class="btn btn-primary" name="mode" value="cancel">キャンセル</button>
			</form> -->
			<button type="submit" class="btn btn-primary" form="registForm" name="mode" value="regist">バーコード出力</button>
			<button type="submit" class="btn btn-primary" form="registForm" formaction="barcode_export.php">リスト出力</button>

		</div>
		
	</main>
	<?php require_once(__DIR__."/inc_footer.php"); ?>
</body>
</html>
