<?php
/**
 * バーコード一括出力
 */
session_cache_limiter("none");
session_start();
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
$funcId = "biz402";

// 自機能以外のSessionデータをクリア
SessionHelper::clearDataExceptFunc($funcId);

$rtnlist = array("status" => "99999", "errMsg" => "");
$nofrom = "";
$noto = "";

// ロジック処理
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
	$csrfScope = (string)($_POST[Utility::getCsrfScopeFieldName()] ?? '');
	$csrfToken = (string)($_POST[Utility::getCsrfFieldName()] ?? '');
	if (!Utility::validatePostedCsrfToken($csrfScope, $csrfToken)) {
		$logger->error(basename(__FILE__).' op=barcode.bulk.list msg="Invalid csrf token" page=barcode_bulk_list.php user_id='.$auth->getCurrentUser()?->getLoginId());
		SessionHelper::FlushError(MessageConst::MSG_SYS_COMMON_900);
		header('Location: barcode_bulk_list.php', true, 303);
		exit;
	}


	if ($_POST['mode'] === 'formexcel') {    // ファイルアップロード

		SessionHelper::delData($funcId, "barcodeList");
		SessionHelper::delData($funcId, "nofrom");
		SessionHelper::delData($funcId, "noto");
		SessionHelper::delData($funcId, "strl");

		if (is_uploaded_file($_FILES["upfile"]["tmp_name"])) {
			$strl = strtolower($_FILES["upfile"]["name"]);
			$pos = mb_strrpos($strl, '.');
			$len = mb_strlen($strl);
			// ドットが見つかり、ファイル名と拡張子の両方が存在する
			if ($pos !== false && $pos > 0 && $pos < $len - 1) {
				$ext = mb_substr($strl, $pos, $len);
				if ($ext === ".xls" || $ext === ".xlsx") {
					$filenm = "bar".date("YmdHis").$ext;
					if (move_uploaded_file($_FILES["upfile"]["tmp_name"], "../".LogicConst::DIR_TMP."/".$filenm)) {
						// アップロード成功
						$rtnlist = UtilExcel::getImportBarcode($filenm, $_POST["nofrom"], $_POST["noto"]);
						
						
						if ($rtnlist["status"] === "00000") {
							SessionHelper::setData($funcId, "barlist", $rtnlist["lists"]);
							SessionHelper::setData($funcId, "barcodeList", $rtnlist);
							$nofrom = $_POST["nofrom"];
							$noto = $_POST["noto"];
							if ($nofrom === '' && $noto === '') {
								$nofrom = $rtnlist["lists"][0]['SEQ'];
								$noto  = $rtnlist["lists"][count($rtnlist["lists"]) - 1]['SEQ'];
							}
							SessionHelper::setData($funcId, "nofrom", $nofrom);
							SessionHelper::setData($funcId, "noto", $noto);
							SessionHelper::setData($funcId, "strl", $strl);
						} else {
							SessionHelper::flushError($excelData["errMsg"]);
						}

					} else {
						// ファイルアップロードエラー
						SessionHelper::flushError(MessageConst::MSG_SYS_FILE_004);
						$logger->error(basename(__FILE__).' msg="file upload failed" page='.$filename.' user_id='.$auth->getCurrentUser()?->getLoginId());
						
					}
				} else {
					// Excel以外
					SessionHelper::FlushError(MessageConst::MSG_VAL_FILE_005);
				}
			} else {
				// ファイル名想定外
				SessionHelper::FlushError(MessageConst::MSG_VAL_FILE_006);
			}
		} else {
			// ファイルが選択されていない
			SessionHelper::FlushError(MessageConst::MSG_VAL_FILE_001);
		}
		
	} else if ($_POST['mode'] === 'filter') {    // 絞り込みボタン押下
		
		$rtnlist = SessionHelper::getData($funcId, "barcodeList");
		if ($rtnlist["status"] === "00000") {
			
			$nofrom = $_POST["nofrom"];
			$noto = $_POST["noto"];

			SessionHelper::delData($funcId, "nofrom");
			SessionHelper::delData($funcId, "noto");

			// from、toによる絞込み
			$list = array();
			$st = false;
			foreach ($rtnlist["lists"] ?? [] as $wk) {
				if (!empty($wk["SEQ"]) && !empty($wk["management_no"])) {
					if ($wk["SEQ"] == $nofrom) {
						$st = true;
					}
					if ($st) {
						$list[] = $wk;
					}
					if ($wk["SEQ"] == $noto) {
						break;
					}
				}
			}
			$rtnlist["lists"] = $list;


			SessionHelper::setData($funcId, "barcodeList", $rtnlist);
			SessionHelper::setData($funcId, "nofrom", $nofrom);
			SessionHelper::setData($funcId, "noto", $noto);
		} else {
			SessionHelper::FlushError(MessageConst::MSG_VAL_FILE_001);
		}

	} else if ($_POST['mode'] === 'excel') {    // バーコード出力ボタン押下

		$rtnlist = SessionHelper::getData($funcId, "barcodeList");
		if ($rtnlist["status"] === "00000") {
			$product = new Product();
			$barproduct_list = $product->selectMngNoSeqs($rtnlist['lists']);
			$barlist = array();
			foreach ($barproduct_list ?? [] as $wk) {

				$barLayout = UtilExcel::getBarcodeLayout($wk, $wk["SEQ"]);
				$barmng = $barLayout["barmng"];
				$barimg = $barLayout['barimg'];
				$bartop = $barLayout['bartop'];
				$barbtm = $barLayout['barbtm'];

				$barlist[] = array("bar_no" => $barmng, "img_file" => $barimg, "bar_top" => $bartop, "bar_btm" => $barbtm);
			}

			SessionHelper::setData("com901", "barFile", "product");
			SessionHelper::setData("com901", "barlist", $barlist);
			SessionHelper::setData("com901", "backTo", $filename);
			unset($barlist);

			if (count($rtnlist["lists"] ?? [])  <= LogicConst::BARCODE_ITEM_EXCEL) {
				// バーコードExcel一括出力
				$scope = 'barcode_bulk_export.direct';
				$_POST[Utility::getCsrfScopeFieldName()] = $scope;
				$_POST[Utility::getCsrfFieldName()] = Utility::issueCsrfToken($scope);
				$_POST['mode'] = 'export';
				require(__DIR__ . "/barcode_bulk_export.php");
				exit;
			} else {
				// バーコードExcel分割出力画面
				header("Location: barcode_list_export_split.php", true, 303);
			}
			exit;
		} else {
			SessionHelper::FlushError(MessageConst::MSG_VAL_FILE_001);
		}
	}

	// 画面表示はGETでredirect
	header('Location: barcode_bulk_list.php', true, 303);
	exit;
}

$rtnlist = SessionHelper::getData($funcId, "barcodeList");
$nofrom	 = SessionHelper::getData($funcId, "nofrom");
$noto =	SessionHelper::getData($funcId, "noto");
$strl = SessionHelper::getData($funcId, "strl");

$guideMsg = MessageConst::MSG_INF_FILE_002;
if (($rtnlist['status'] ?? null) === '00000') {
	$guideMsg = MessageConst::MSG_INF_FILE_003;
}
?>

<!DOCTYPE html>
<html lang="ja">
	<head>
		<?php $title = "バーコード一括出力"; require_once(__DIR__."/inc_head.php"); ?>
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

		<form method="post"  enctype="multipart/form-data" action="barcode_bulk_list.php" class="my-2">
			<?= Utility::renderCsrfHiddenInput('barcode_bulk_list.form') ?>
			<div class="d-flex flex-column flex-md-row align-items-center justify-content-start">
				<input type="file" name="upfile" class="form-control form-control-sm" maxlength="50" />
				<button type="submit" class="btn btn-primary mx-3" name="mode" value="formexcel">読込</button>
				<input type="number" min="0" max="4294967295" class="form-control form-control-sm" name="nofrom" value="<?=Utility::h($nofrom)?>" placeholder="SEQ From" />&nbsp;～&nbsp;
				<input type="number" min="0" max="4294967295" class="form-control form-control-sm" name="noto"   value="<?=Utility::h($noto)?>"   placeholder="SEQ To" />
				<?php if ($rtnlist && $rtnlist["status"] === "00000"): ?>
					<button type="submit" class="btn btn-primary mx-3" name="mode" value="filter">SEQ絞り込み</button>
				<?php endif; ?>
			</div>
			<div>
				<?php if ($strl): ?>
					<input type="text" readonly class="form-control-sm form-control-plaintext" value="現在の読み込み中ファイル：<?= Utility::h($strl) ?>">
				<?php endif; ?>
			</div>
		</form>

		<?php if (($rtnlist['status'] ?? null) === '00000'): ?>
		<table class="table table-sm table-responsive">
			<thead>
			<tr>
				<th scope="col">SEQ</th>
				<th scope="col">管理番号</th>
			</tr>
			</thead>
			<tbody>
			<?php foreach ($rtnlist["lists"] ?? [] as $wk): ?>
			<tr>
				<td><?=Utility::h($wk["SEQ"]) ?></td>
				<td><?=Utility::h($wk["management_no"]) ?></td>
			</tr>
			<?php endforeach; ?>
			</tbody>
		</table>
		<?php endif; ?>


		<!-- ボタン -->
		<div class="d-flex justify-content-evenly">

			<a href="menu.php" type="button" class="btn btn-primary">戻る</a>

			<?php
			if ($rtnlist && $rtnlist["status"] === "00000") {
			?>
				<form method="POST" action="barcode_bulk_list.php">
					<?= Utility::renderCsrfHiddenInput('barcode_bulk_list.form') ?>
					<input type="hidden" name="mode" value="excel">
					<button type="submit" class="btn btn-primary" >ﾊﾞｰｺｰﾄﾞ出力</button>
				</form>
			<?php
			} else {
			?>
			<button type="submit" class="btn btn-primary" disabled>ﾊﾞｰｺｰﾄﾞ出力</button>
			<?php
			} 
			?>
		</div>

	</main>
	<?php require_once(__DIR__."/inc_footer.php"); ?>
</body>
</html>
