<?php
/**
 * バーコードExcel分割出力画面
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
use Noblestock\Logic\LogicConst;
use Noblestock\Logic\MessageConst;
use Noblestock\DbLogic\UserRepository;

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

// セッション管理ID バーコード出力
$funcId = "com901";
// backTo に許可する画面名
$allowedBackTo = ['barcode_bulk_list', 'product_barcode_list'];

// rtnScreenを取り出す
$backTo = SessionHelper::getData($funcId, "backTo");
if (!is_string($backTo) || !in_array($backTo, $allowedBackTo, true)) {
	http_response_code(400);
	header('Content-Type: text/plain; charset=UTF-8');
	echo MessageConst::MSG_VAL_FILE_018;
	exit;
}

// 設定取得
$barFile = SessionHelper::getData($funcId, "barFile");
$excelName = $barFile."_barcode_".date("Ymd")."-";
$barList = SessionHelper::getData($funcId, "barlist");
$listCnt = count($barList ?? []);

// 最大ページ数取得
$pageMax = 1;
if ($listCnt > LogicConst::BARCODE_ITEM_EXCEL) {
	$pageMax = ceil($listCnt / LogicConst::BARCODE_ITEM_EXCEL);
}

$excelExt = ".xls";
if (intval(SessionHelper::getPref("EXCEL_VAR")) === 1) {
	$excelExt = ".xlsx";
}
?>

<!DOCTYPE html>
<html lang="ja">
	<head>
		<?php $title = "バーコード出力"; require_once(__DIR__."/inc_head.php"); ?>

	</head>

<body class="barcode">
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
			<div><?=MessageConst::MSG_INF_FILE_003?></div>
			<button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
		</div>

		<div id="table-wrapper" class="h-100 overflow-y-auto">
		<table class="table table-sm table-responsive">
			<thead>
				<tr>
					<th scope="col">ファイル名</th>
					<th scope="col">行数</th>
					<th scope="col">出力</th>
				</tr>
			</thead>
			<tbody>
				<form method="POST" action="barcode_bulk_export.php">
					<?= Utility::renderCsrfHiddenInput('barcode_list_export_split.export') ?>
					<input type="hidden" name="mode" value="export">
				<?php
				for ($i = 1; $i <= $pageMax; $i++):
					$fileName = $excelName.$i.$excelExt;
					$fromNo = LogicConst::BARCODE_ITEM_EXCEL * ($i - 1) + 1;
					$toNo = LogicConst::BARCODE_ITEM_EXCEL * $i;
					if ($i == $pageMax) {
						$toNo = $listCnt;
					}
				?>
					<tr>
						<td><?=$fileName ?></td>
						<td><?=$fromNo." ～ ".$toNo ?></td>
						<td class="text-center">
							<button type="submit" class="btn btn-primary" name="listPage" value="<?=$i ?>">バーコード出力</button>
						</td>
					</tr>
				<?php
				endfor;
				?>
				</form>
			</tbody>
		</table>
		</div><!-- table-wrapper -->


		<!-- ボタン -->
		<div class="d-flex justify-content-evenly">
			<a href="<?=Utility::h($backTo) ?>.php" type="button" class="btn btn-primary">戻る</a>
		</div>

	</main>
	<?php require_once(__DIR__."/inc_footer.php"); ?>
</body>
</html>
