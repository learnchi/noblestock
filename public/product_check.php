<?php
/**
 * 在庫チェック 画面
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
use Noblestock\Logic\LogicConst;
use Noblestock\Logic\BarcodeProcessor;
use Noblestock\DbLogic\Check;
use Noblestock\DbLogic\Stock;
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
$funcId = "biz601";

// 自機能以外のSessionデータをクリア
SessionHelper::clearDataExceptFunc($funcId);

// マスタデータ取得
$locationList = SessionHelper::getMasterList("locationList");
if (empty($locationList)) {
	// マスターデータがない→マスターメンテナンスメニュー画面へ
	$logger->error(basename(__FILE__).' op=master msg="locationList is empty" page='.$filename.' user_id='.$auth->getCurrentUser()?->getUserId());
	SessionHelper::flushError(MessageConst::MSG_SYS_MASTER_003);
	header("Location: menu_master.php");
	exit;
}

// セッション値の取得
$locData = SessionHelper::getData($funcId, "locData");  // 店舗情報
if (empty($locData)) {
	$locData = array("location_id" => $locationList[0]['id'], "location_name" => $locationList[0]['location_name']);
	SessionHelper::setData($funcId, "locData", $locData);
}

// 一覧ページ
$pgcnt = SessionHelper::getData($funcId, "pgcnt");
if (empty($pgcnt)) {
	// ページ数初期化
	$pgcnt = 1;
	SessionHelper::setData($funcId, "pgcnt", $pgcnt);

	// スクロール位置初期化
	SessionHelper::delData($funcId, "scrollPos");
}

// スクロール位置
$scrollPos = SessionHelper::getData($funcId, "scrollPos");
if ($scrollPos == null) $scrollPos = 0;

// 一覧ソート
$pgsort = intval(SessionHelper::getData($funcId, "pgsort"));
if (empty($pgsort)) {
	$pgsort = 1;
	SessionHelper::setData($funcId, "pgsort", $pgsort);
}

$pgsort = intval(SessionHelper::getData($funcId, "pgsort"));
if (empty($pgsort)) {
	$pgsort = 1;
	SessionHelper::setData($funcId, "pgsort", $pgsort);
}

// ページネーション部品は GET で p/s/pos を渡すので、ここで現在値へ反映する
$getPage = filter_input(INPUT_GET, 'p', FILTER_VALIDATE_INT);
if ($getPage !== null && $getPage !== false && $getPage > 0) {
	$pgcnt = $getPage;
	SessionHelper::setData($funcId, "pgcnt", $pgcnt);
}

$getSort = filter_input(INPUT_GET, 's', FILTER_VALIDATE_INT);
if ($getSort !== null && $getSort !== false && $getSort > 0) {
	$pgsort = $getSort;
	SessionHelper::setData($funcId, "pgsort", $pgsort);
}

$getScrollPos = filter_input(INPUT_GET, 'pos', FILTER_VALIDATE_INT);
if ($getScrollPos !== null && $getScrollPos !== false) {
	$scrollPos = $getScrollPos;
	SessionHelper::setData($funcId, "scrollPos", $scrollPos);
}

$user_id = $auth->getCurrentUser()?->getUserId();
$checkData = array();  // チェック数加算結果

// ロジック
$check = new Check();
$stock = new Stock();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
	$csrfScope = (string)($_POST[Utility::getCsrfScopeFieldName()] ?? '');
	$csrfToken = (string)($_POST[Utility::getCsrfFieldName()] ?? '');
	if (!Utility::validatePostedCsrfToken($csrfScope, $csrfToken)) {
		$logger->error(basename(__FILE__).' op=product.check msg="Invalid csrf token" page=product_check.php user_id='.$auth->getCurrentUser()?->getUserId());
		SessionHelper::FlushError(MessageConst::MSG_SYS_COMMON_900);
		header("Location: " . $_SERVER['PHP_SELF']);
		exit;
	}


	// セッションの値を取り出す

	// スクロール位置をセッションに保持
	if (isset($_POST["pos"])) {
		SessionHelper::setData($funcId, "scrollPos", $_POST["pos"]);
		$scrollPos = $_POST["pos"];
	}

	// 一覧ページ
	if (isset($_POST["pgcnt"])) {
		$pgcnt = $_POST["pgcnt"];
		SessionHelper::setData($funcId, "pgcnt", $pgcnt);

		// スクロール位置初期化
		SessionHelper::delData($funcId, "scrollPos");
		$scrollPos = 0;
	} 

	// 一覧ソート
	if (isset($_POST["pgsort"])) {
		// 数値型に変換
		$pgsort = intval($_POST["pgsort"]);
		SessionHelper::setData($funcId, "pgsort", $pgsort);

		// ページ数初期化
		$pgcnt = 1;
		SessionHelper::setData($funcId, "pgcnt", $pgcnt);

		// スクロール位置初期化
		SessionHelper::delData($funcId, "scrollPos");
		$scrollPos = 0;
	}

	$mode = $_POST["mode"] ?? '';
	if (!empty($mode)) {
		if ($mode === "clear") {    // 全クリア
			try {
				$rtndel = $check->delete($user_id);
				SessionHelper::flushSuccess(MessageConst::MSG_OK_CHECK_001);
			} catch (\Exception $e) { 
				// エラー
				SessionHelper::flushError(MessageConst::MSG_SYS_CHECK_002);
				$logger->fatal(__FILE__." 在庫チェック数削除処理エラー user_id=".$user_id." ".$e->getMessage());
			}
		} else if ($mode === "change") {    // 在庫チェック数一覧変更

			$in_check_count = $_POST["in_check_count"];
			$old_check_count = $_POST["old_check_count"];
			$in_management_no = $_POST["in_management_no"];
			$in_location_no = $_POST["in_location_no"];
			if (is_numeric($in_check_count) && $in_check_count >= 0) {
				if ($in_check_count != $old_check_count) {
					// 在庫チェック数変更
					try {
						$rtnChange = $check->change($user_id, $in_location_no, $in_management_no, $in_check_count, false);
						SessionHelper::flushSuccess(Utility::replaceStr(MessageConst::MSG_OK_CHECK_003, $in_management_no, $in_check_count));
					} catch (\Exception $e) {
						// エラー
						SessionHelper::flushError(MessageConst::MSG_SYS_CHECK_004);
						$logger->fatal(__FILE__." 在庫チェック数変更処理エラー user_id=".$user_id." location_no=".$in_location_no." management_no=".$in_management_no." ".$e->getMessage());
					}
				}
			}
			
		} else if ($mode === 'search') {    // 検索条件格納

			if (isset($_POST["locationNo"])){
				foreach ($locationList ?? [] as $wk) {
					if ($wk['id'] == $_POST["locationNo"]) {
						$locData = array("location_id" => $wk['id'], "location_name" => $wk['location_name']);
						SessionHelper::setData($funcId, "locData", $locData);
						break;
					}
					// ページ数初期化
					$pgcnt = 1;
					SessionHelper::setData($funcId, "pgcnt", $pgcnt);
				}
			}
		}
		
		// ページ数初期化
		$pgcnt = 1;
		SessionHelper::setData($funcId, "pgcnt", $pgcnt);

		// スクロール位置初期化
		SessionHelper::delData($funcId, "scrollPos");
		$scrollPos = 0;
	}

	// バーコード入力欄
	$bcin = trim($_POST['barcode'] ?? '');
	if ($bcin !== '') {
		// コードから処理を実施する
		$processor = new BarcodeProcessor(
			auth: $auth,
			funcId: $funcId,
			mode: BarcodeProcessor::MODE_CHECK,    // 在庫チェック
			logger: $logger,
		);
		$cmdResult = $processor->handle($bcin);

		// バーコードメニュー指定時にリダイレクトする
		if ($cmdResult->isRedirect()) {
			header("Location: {$cmdResult->getRedirectUrl()}");
			exit;
		}

		// // 結果配列がある
		// if ($cmdResult->hasResult()) {
		// 	$result = $cmdResult->getResultArray();
		// }

		// ページ数初期化
		$pgcnt = 1;
		SessionHelper::setData($funcId, "pgcnt", $pgcnt);

		// スクロール位置初期化
		SessionHelper::delData($funcId, "scrollPos");
	}

	// 画面表示はGETでredirect
	header("Location: " . $_SERVER['PHP_SELF']);
	exit;
}

// 一覧検索キー
$searchKey = array(
	"user_id" => $user_id,
	"location_no" => $locData["location_id"]
);
SessionHelper::setData($funcId, "searchKey", $searchKey);

// 一覧データの取得
$listCnt = 0;
$pageCnt = 0;
$pageList = array();

$listCnt = $stock->countAll($searchKey['location_no']);
SessionHelper::setData($funcId, "listCnt", $listCnt);
if ($listCnt > 0) {
	$pageList = $stock->list($searchKey['user_id'], $searchKey['location_no'], $pgsort, LogicConst::PAGE_ITEM, $pgcnt, $listCnt);
	$pageCnt = count($pageList ?? []);
}
?>

<!DOCTYPE html>
<html lang="ja">
	<head>
		<?php $title = "在庫チェック"; require_once(__DIR__."/inc_head.php"); ?>
	<link rel="stylesheet" href="css/imgpreview.css">
	<script src="js/imgpreview.js"></script>

	<script>const scrollPos= <?= (int)($scrollPos ?? 0) ?>;</script><!-- スクロール位置 -->
	<script src="js/scrollPosition.js"></script>
	<script src="js/BarcodeInput.js"></script>

</head>

	<body class="product">

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
			<div><?=MessageConst::MSG_INF_BARCODE_001?></div>
			<button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
		</div>
		
		<div class="row">
			<!-- バーコード入力 -->
			<div id="barcode" class="col-12 col-sm-6">
				<form method="POST" action="<?= $filename ?>.php" class="d-flex justify-content-center">
					<?= Utility::renderCsrfHiddenInput('product_check.form') ?>
					<button class="btn btn-outline-dark"><i class="bi bi-upc-scan"></i></button>
					<input type="text" class="form-control" name="barcode" maxlength="50" autocomplete="off" style="width: unset;"/>
				</form>
			</div>

			<!-- 検索条件 -->
			<div class="col-12 col-sm-6 d-flex justify-content-center">
				<form method="post" action="product_check.php">
					<?= Utility::renderCsrfHiddenInput('product_check.form') ?>
					<div class="d-flex align-items-center flex-wrap">
						<input type="hidden" name="mode" value="search">
						<input type="hidden" name="pos" value=""><!-- submit時に代入 -->

						<select class="form-select  form-select-sm" name="locationNo">
						<?php
						foreach ($locationList ?? [] as $wk) {
							if($wk['id'] == $locData["location_id"]) {
								$selected = "selected";
							} else {
								$selected = "";
							}
							?>
							<option value="<?=Utility::h($wk['id'])?>" <?=$selected ?>><?=Utility::h($wk['location_name']) ?></option>
						<?php
						}
						?>
						</select>
						
						<button type="submit" class="btn btn-primary text-nowrap">検索</button>
					</div>
				</form>
			</div><!-- 検索条件 ここまで -->
		</div>

		<?php
		if (!empty($checkData)) {
		?>
		<table class="table table-sm table-responsive">
			<thead>
				<tr>
					<th scope="col">管理番号</th>
					<th scope="col">カテゴリ</th>
					<th scope="col">メーカー</th>
					<th scope="col">商品名</th>
					<th scope="col">在庫数</th>
					<th scope="col">チェック数</th>
					<th scope="col">チェック結果</th>
				</tr>
			</thead>
			<tbody>
			<?php
			$checkStr = "";
			if ($checkData["quantity"] == $checkData["check_count"]) {
				$checkStr = "○";
			} else {
				if ($checkData["quantity"] < $checkData["check_count"]) {
					$checkStr = "超過";
				}
			}
			?>
				<tr>
					<td><?=Utility::h($checkData['management_no']) ?></td>
					<td><?=Utility::h($checkData['category_name']) ?></td>
					<td><?=Utility::h($checkData['maker_name']) ?></td>
					<td><?=Utility::h($checkData['product_name']) ?></td>
					<td class="text-end"><?=Utility::h($checkData['quantity']) ?></td>
					<td class="text-end"><?=Utility::h($checkData['check_count']) ?></td>
					<td class="text-center"><?=$checkStr ?></td>
				</tr>
			</tbody>
		</table>
		<?php
		}
		?>

		<?php
		if ($pageCnt > 0) {
		?>
		<table class="table table-sm table-responsive">
			<thead>
				<tr>
				<?php
				$noSort = 1;
				$strSort = "";
				if ($pgsort === 1) {
					$noSort = 2;
					$strSort = " ▲";
				} else if ($pgsort === 2) {
					$noSort = 1;
					$strSort = " ▼";
				}
				?>
				<th scope="col">
					<form method="POST" action="product_check.php">
						<?= Utility::renderCsrfHiddenInput('product_check.form') ?>
						<input type="hidden" name="pgsort" value="<?=$noSort?>">
						<input type="hidden" name="pos" value=""><!-- submit時に代入 -->
						<button type="submit" class="btn w-100">管理番号<?=$strSort?></button>
					</form>
				</th>
				<?php
				$noSort = 3;
				$strSort = "";
				if ($pgsort === 3) {
					$noSort = 4;
					$strSort = " ▲";
				} else if ($pgsort === 4) {
					$noSort = 3;
					$strSort = " ▼";
				}
				?>
				<th scope="col">
					<form method="POST" action="product_check.php">
						<?= Utility::renderCsrfHiddenInput('product_check.form') ?>
						<input type="hidden" name="pgsort" value="<?=$noSort?>">
						<input type="hidden" name="pos" value=""><!-- submit時に代入 -->
						<button type="submit" class="btn w-100">カテゴリ<?=$strSort?></button>
					</form>
				</th>
				<?php
				$noSort = 5;
				$strSort = "";
				if ($pgsort === 5) {
					$noSort = 6;
					$strSort = " ▲";
				} else if ($pgsort === 6) {
					$noSort = 5;
					$strSort = " ▼";
				}
				?>
				<th scope="col">
					<form method="POST" action="product_check.php">
						<?= Utility::renderCsrfHiddenInput('product_check.form') ?>
						<input type="hidden" name="pgsort" value="<?=$noSort?>">
						<input type="hidden" name="pos" value=""><!-- submit時に代入 -->
						<button type="submit" class="btn w-100">メーカー<?=$strSort?></button>
					</form>
				</th>
				<?php
				$noSort = 7;
				$strSort = "";
				if ($pgsort === 7) {
					$noSort = 8;
					$strSort = " ▲";
				} else if ($pgsort === 8) {
					$noSort = 7;
					$strSort = " ▼";
				}
				?>
				<th scope="col">
					<form method="POST" action="product_check.php">
						<?= Utility::renderCsrfHiddenInput('product_check.form') ?>
						<input type="hidden" name="pgsort" value="<?=$noSort?>">
						<input type="hidden" name="pos" value=""><!-- submit時に代入 -->
						<button type="submit" class="btn w-100">商品名<?=$strSort?></button>
					</form>
				</th>
				<th scope="col">在庫数</th>
				<th scope="col">チェック数</th>
				<th scope="col">チェック結果</th>
			</tr>
			</thead>
			<tbody>
			<?php
			for($i = 0; $i < $pageCnt; $i++) {
				$wk = $pageList[$i];

				$checkStr = "";
				$checkColor = "";
				if ($wk["quantity"] == $wk["check_count"]) {
					$checkStr = "○";
					$checkColor = ' class="check_ok"';
				} else {
					if ($wk["quantity"] < $wk["check_count"]) {
						$checkStr = "超過";
						$checkColor = ' class="check_ng"';
					}
				}
			?>
			<tr<?=$checkColor?>>
				<td><?=Utility::h($wk['management_no']) ?></td>
				<td><?=Utility::h($wk['category_name']) ?></td>
				<td><?=Utility::h($wk['maker_name']) ?></td>
				<td><?=Utility::h($wk['product_name']) ?></td>
				<td class="text-end"><?=Utility::h($wk['quantity']) ?></td>
				<td class="text-end" >
				<!-- 在庫チェック数変更 -->
					<form method="POST" action="product_check.php" class="js-confirm" data-confirm=<?= MessageConst::MSG_CNF_COMMON_015 ?>>
						<?= Utility::renderCsrfHiddenInput('product_check.form') ?>
						<input type="hidden" name="pos" value=""><!-- submit時に代入 -->
						<input type="hidden" name="in_check_count" value="<?=Utility::h($wk["check_count"])?>">
						<input type="hidden" name="in_management_no" value="<?=Utility::h($wk["management_no"])?>">
						<input type="hidden" name="in_location_no" value="<?=Utility::h($searchKey["location_no"])?>">
						<input type="hidden" name="old_check_count" value="<?=Utility::h($wk["check_count"])?>">
						<button type="submit" class="btn" name="mode" value="change"><?=Utility::h($wk['check_count']) ?></button>
					</form>
				</td>
				<td class="text-center"><?=$checkStr ?></td>
			</tr>
			<?php
			}
			?>
			</tbody>
		</table>
		<?php
		}
		?>

		<?php $pagination_action = basename(__FILE__); require_once(__DIR__."/inc_pagination.php"); ?>


		<!-- ボタン -->
		<div class="d-flex justify-content-evenly">

			<a href="menu.php" type="button" class="btn btn-primary">戻る</a>

			<form method="POST" action="product_check.php" class="js-confirm" data-confirm=<?= MessageConst::MSG_CNF_CHECK_005 ?>>
				<?= Utility::renderCsrfHiddenInput('product_check.form') ?>
				<input type="hidden" name="pos" value=""><!-- submit時に代入 -->
				<button type="submit" class="btn btn-primary" name="mode" value="clear">全クリア</button>
			</form>
			<?php
			if ($listCnt > 0) {
				if ($listCnt <= LogicConst::PAGE_ITEM_EXCEL) {
			?>
						
			<form method="POST" action="product_check_export.php">
				<?= Utility::renderCsrfHiddenInput('product_check.form') ?>
				<input type="hidden" name="mode" value="export">
				<button type="submit" class="btn btn-primary">Excel出力</button>
			</form>
			<?php
				} else {
			?>
				<form method="GET" action="list_export_split.php">
					<input type="hidden" name="fn" value="<?= $filename ?>">
					<button type="submit" class="btn btn-primary" >Excel出力</button>
				</form>
			<?php
				}
			}
			?>
		</div>
	</main>
	<?php require_once(__DIR__."/inc_footer.php"); ?>
</body>
</html>
