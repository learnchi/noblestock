<?php
/**
 * 商品出庫
 */
session_cache_limiter("none");
@session_start();
date_default_timezone_set('Asia/Tokyo');

require_once(__DIR__."/../Logic/Limitlogic.php");

// composerを使用
require_once(__DIR__ . '/../vendor/autoload.php');
use Studiogau\Chandra\Logging\Logger;
use Studiogau\Chandra\Support\SessionHelper;
use Studiogau\Chandra\Support\Utility;
use Studiogau\Chandra\Auth\AuthService;
use Noblestock\Logic\LogicConst;
use Noblestock\Logic\BarcodeProcessor;
use Noblestock\DbLogic\UserRepository;
use Noblestock\Logic\MessageConst;

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
$funcId = "biz201";

// 自機能以外のSessionデータをクリア
SessionHelper::clearDataExceptFunc($funcId);

// 在庫数低下通知ロジック
$limitLogic = new \Noblestock\Logic\LimitLogic();

$result = array("status" => "99999", "errMsg" => "未処理");

// 入力されたコード
$bcin = trim($_POST['barcode'] ?? '');
if ($bcin !== '') {
	$csrfScope = (string)($_POST[Utility::getCsrfScopeFieldName()] ?? '');
	$csrfToken = (string)($_POST[Utility::getCsrfFieldName()] ?? '');
	if (!Utility::validatePostedCsrfToken($csrfScope, $csrfToken)) {
		$logger->error(basename(__FILE__).' op=stock.out msg="Invalid csrf token" page=stock_out.php user_id='.$auth->getCurrentUser()?->getUserId());
		SessionHelper::FlushError(MessageConst::MSG_SYS_COMMON_900);
		header("Location: stock_out.php");
		exit;
	}

	// コードから処理を実施する
	$processor = new BarcodeProcessor(
		auth: $auth,
		funcId: $funcId,
		mode: BarcodeProcessor::MODE_STOCK_OUT,
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
		$result = $cmdResult->getResultArray();
	}
}

$proData = SessionHelper::getData($funcId, "proData");
$valData = SessionHelper::getData($funcId, "valData");
$locData = SessionHelper::getData($funcId, "locData");

// 在庫数不足チェック
if ((SessionHelper::hasFlushError() == false) && $locData != null && $proData != null) {
	foreach ($proData ?? [] as $i => $wk) {
		if ($wk['location_stock'] == 0) {
			// 店舗在庫数が0
			SessionHelper::flushError(MessageConst::MSG_VAL_BARCODE_009);
			break;
		} else {
			// 店舗在庫数が足らない場合
			if ($wk['STOCK_COUNT'] != null && $wk['STOCK_COUNT'] != "") {
				if ($wk['location_stock'] < $wk['STOCK_COUNT']) {
					SessionHelper::flushError(MessageConst::MSG_VAL_BARCODE_010);
					break;
				}
			}
		}
	}
}

$guideMsg = MessageConst::MSG_INF_BARCODE_001;
if (empty($locData)) {
	$guideMsg = MessageConst::MSG_INF_BARCODE_004;
} else {
	if (empty($proData)) {
		$guideMsg = MessageConst::MSG_INF_BARCODE_003;
	} else {
		$proDt = $proData[count($proData ?? []) - 1];
		if (empty($proDt["STOCK_COUNT"])) {
			if (empty($valData)) {
				$guideMsg = MessageConst::MSG_INF_BARCODE_005;
			} else {
				$guideMsg = MessageConst::MSG_INF_BARCODE_006;
			}
		} else {
				$guideMsg = MessageConst::MSG_INF_BARCODE_006;
		}
	}
}
?>

<!DOCTYPE html>
<html lang="ja">
	<head>
		<?php $title = "商品出庫"; require_once(__DIR__."/inc_head.php"); ?>
		<script src="js/BarcodeInput.js"></script>
</head>

<body class="stock">
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
				<?= Utility::renderCsrfHiddenInput('stock_out.form') ?>
				<button class="btn btn-outline-dark"><i class="bi bi-upc-scan"></i></button>
				<input type="text" class="form-control" name="barcode" maxlength="50" autocomplete="off" style="width: unset;"/>
			</form>
		</div>

		<?php if ($locData != null): ?>
		<table class="table table-sm table-responsive">
			<tr>
				<td scope="row">店舗</td>
				<td><?=Utility::h($locData["location_name"]) ?></td>
			</tr>
		</table>
		<?php endif; ?>

		<?php if (count($proData ?? []) > 0): ?>
		<table class="table table-sm table-responsive">
			<thead>
			<tr>
				<th scope="col">管理番号</th>
				<th scope="col">商品名</th>
				<th scope="col">全在庫数</th>
				<th scope="col">店舗</th>
				<th scope="col">店舗在庫数</th>
				<th scope="col">出庫数</th>
			</tr>
			</thead>
			<tbody>
			<?php
			foreach ($proData ?? [] as $i => $wk):
				$stCnt = $wk['STOCK_COUNT'];
				$cls = "text-body";
				if ($stCnt == "") {
					if ($valData != null) {
						$stCnt = $valData;
						$cls = "text-primary-emphasis";
					}
				}
				if ($wk['location_stock'] < $stCnt) {
					// 店舗在庫数が足らない
					if ($cls == "text-body") {
						$cls = "text-danger";
					}
				}
			?>
			<tr>
				<td><?=Utility::h($wk['management_no']) ?></td>
				<td><?=Utility::h($wk['product_name']) ?></td>
				<td class="text-end"><?=Utility::h($wk['quantity']) ?></td>
				<td><?=Utility::h($locData["location_name"] ?? "") ?></td>
				<td class="text-end"><?=Utility::h($wk['location_stock']) ?></td>
				<td class="text-end <?=$cls?>"><?=Utility::h($stCnt) ?></td>
			</tr>
			<?php endforeach;?>
			</tbody>
		</table>
		<?php endif; ?>

		<?php if ($result['status'] == "00000" || $result['status'] == "90001"):	// 処理が完了または一部失敗の場合 ?>
		<table class="table table-sm table-responsive">
			<thead>
			<tr>
				<th scope="col">結果</th>
				<th scope="col">管理番号</th>
				<th scope="col">商品名</th>
				<th scope="col">全在庫数</th>
				<th scope="col">店舗</th>
				<th scope="col">店舗在庫数</th>
				<th scope="col">出庫数</th>
			</tr>
			</thead>
			<tbody>
			<?php
			foreach ($result['lists'] ?? [] as $i => $wkArray):
				$wk = $wkArray["lists"];
				$cls = "text-success";
				if ($wkArray["status"] === "失敗") {
					$cls = "text-danger";
				} else {
					// 在庫数低下通知
					$limitLogic->noticeStock($wk);
				}
			?>
				<tr>
					<td class="text-center <?=$cls ?>"><?=$wkArray['status'] ?></td>
					<td><?=Utility::h($wk['management_no']) ?></td>
					<td><?=Utility::h($wk['product_name']) ?></td>
					<td class="text-end"><?=Utility::h($wk['quantity']) ?></td>
					<td><?=Utility::h($wk['location_name'] ?? '') ?></td>
					<td class="text-end"><?=Utility::h($wk['location_stock']) ?></td>
					<td class="text-end <?=$cls ?>"><?=Utility::h($wk['STOCK_COUNT']) ?></td>
				</tr>
			<?php endforeach;?>
			</tbody>
		</table>
		
		<?php endif; ?>

		<a href="menu.php" type="button" class="btn btn-primary">戻る</a>
	</main>
	<?php require_once(__DIR__."/inc_footer.php"); ?>
	</body>
</html>
