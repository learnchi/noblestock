<?php
/**
 * 商品一括登録 画面
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
use Noblestock\DbLogic\UserRepository;
use Noblestock\DbLogic\Product;
use Noblestock\Util\UtilExcel;
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

// セッション管理ID 画像選択登録
$funcId = "mst004";

// rtnlistを取り出す
$rtnlist = SessionHelper::getData($funcId, "rtnlist");
$strl = SessionHelper::getData($funcId, "strl");

// ロジック処理
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
	$csrfScope = (string)($_POST[Utility::getCsrfScopeFieldName()] ?? '');
	$csrfToken = (string)($_POST[Utility::getCsrfFieldName()] ?? '');
	if (!Utility::validatePostedCsrfToken($csrfScope, $csrfToken)) {
		$logger->error(basename(__FILE__).' op=product.bulk.create msg="Invalid csrf token" page=product_bulk_create.php user_id='.$auth->getCurrentUser()?->getLoginId());
		SessionHelper::FlushError(MessageConst::MSG_SYS_COMMON_900);
		header('Location: product_bulk_create.php', true, 303);
		exit;
	}

    if (($_POST["mode"] ?? '') === "upload") {
		// rtnlistをクリア
		$rtnlist = null;
		SessionHelper::delData($funcId, "rtnlist");
		$strl = "";
		SessionHelper::delData($funcId, "strl");
		if (is_uploaded_file($_FILES["upfile"]["tmp_name"])) {
			$strl = strtolower($_FILES["upfile"]["name"]);
			$pos = mb_strrpos($strl, '.');
			$len = mb_strlen($strl);
			// ドットが見つかり、ファイル名と拡張子の両方が存在する
			if ($pos !== false && $pos > 0 && $pos < $len - 1) {
				$ext = mb_substr($strl, $pos, $len);
				if ($ext === ".xls" || $ext === ".xlsx") {
					$filenm = "product".date("YmdHis").$ext;
					if (move_uploaded_file($_FILES["upfile"]["tmp_name"], "../".LogicConst::DIR_TMP."/".$filenm)) {
						// アップロード成功
						$excelData = utilExcel::getProductExcelData($filenm);
						if ($excelData["status"] === "00000") {
							// 商品登録／更新／削除
							$product = new Product();
							$rtnlist = $product->bulkChange($excelData["lists"]);

						} else {
							$rtnlist = $excelData;
						}
						SessionHelper::setData($funcId, "rtnlist", $rtnlist);
						SessionHelper::setData($funcId, "strl", $strl);
					} else {
						// ファイルアップロードエラー
						sessionHelper::flushError(MessageConst::MSG_SYS_FILE_004);
						$logger->error(basename(__FILE__).' op=product msg="update failed" page='.$filename.' user_id='.$auth->getCurrentUser()?->getLoginId()).' detail='.$e->getMessage();
					}
				} else {
					// Excel以外
					sessionHelper::flushError(MessageConst::MSG_VAL_FILE_005);
				}
			} else {
				// ファイル名想定外
				sessionHelper::flushError(MessageConst::MSG_VAL_FILE_006);
			}
		} else {
			// ファイルが選択されていない
			sessionHelper::flushError(MessageConst::MSG_VAL_FILE_001);
		}
	}
    // 画面表示はGETでredirect
    header('Location: product_bulk_create.php', true, 303);
    exit;
}
?>

<!DOCTYPE html>
<html lang="ja">
<head>
	<?php $title = "商品一括登録"; require_once(__DIR__."/inc_head.php"); ?>
	<link rel="stylesheet" href="css/imgpreview.css">
	<script src="js/imgpreview.js"></script>

	<script>const scrollPos= <?= (int)($scrollPos ?? 0) ?>;</script><!-- スクロール位置 -->
	<script src="js/scrollPosition.js"></script>

	<script src="js/multiselect.js"></script>
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
			<div><?=MessageConst::MSG_INF_FILE_002?></div>
			<button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
		</div>

		<form method="post"  enctype="multipart/form-data" action="product_bulk_create.php" class="js-confirm" data-confirm="<?= MessageConst::MSG_CNF_COMMON_014 ?>">
			<?= Utility::renderCsrfHiddenInput('product_bulk_create.form') ?>
			<div class="d-flex flex-column flex-md-row align-items-center justify-content-start">
				<input type="file" name="upfile" class="form-input" maxlength="50" />
				<button type="submit" class="btn btn-primary" name="mode" value="upload">読込</button>
			</div>
			<div>
				<?php if ($strl): ?>
					<input type="text" readonly class="form-control-sm form-control-plaintext" value="現在の読み込み中ファイル：<?= Utility::h($strl) ?>">
				<?php endif; ?>
			</div>
		</form>

		<?php if (($rtnlist['status'] ?? null) === '00000'): ?>
			<div id="table-wrapper" class="h-100 overflow-y-auto narrow">
				<table class="table table-sm table-responsive">
					<thead>
					<tr>
						<th scope="col">結果</th>
						<th scope="col">管理番号</th>
						<th scope="col">商品名</th>
					</tr>
					</thead>
					<tbody>
					<?php foreach ($rtnlist["lists"] ?? [] as $wk): ?>
					<?php 
						$cls = "text-primary-emphasis";
						if ($wk["status"] === "失敗") {
							$cls = "text-danger";
						} else if ($wk["status"] === "更新") {
							$cls = "text-success";
						}	
					?>
						<tr>
							<td class="text-center <?=$cls?>"><?=$wk['status'] ?></td>
							<td><?=Utility::h($wk['management_no']) ?></td>
							<td><?=Utility::h($wk['product_name']) ?></td>
						</tr>
					<?php endforeach; ?>
					</tbody>
				</table>
			</div><!-- table-wrapper -->
		<?php endif; ?>

		<!-- ボタン -->
		<div class="d-flex justify-content-evenly">
			<a href="menu_master.php" type="button" class="btn btn-primary">戻る</a>
		</div>
		
	</main>
	<?php require_once(__DIR__."/inc_footer.php"); ?>
</body>
</html>
