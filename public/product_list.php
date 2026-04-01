<?php
/**
 * 商品一覧 画面
 */
session_cache_limiter("none");
@session_start();
date_default_timezone_set('Asia/Tokyo');

// composerを使用
require_once(__DIR__ . '/../vendor/autoload.php');
use Studiogau\Chandra\Logging\Logger;
use Studiogau\Chandra\Support\SessionHelper;
use Studiogau\Chandra\Auth\AuthService;
use Studiogau\Chandra\Support\Utility;
use Noblestock\Logic\LogicConst;
use Noblestock\Logic\MessageConst;
use Noblestock\DbLogic\Product;
use Noblestock\DbLogic\Stock;
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

// セッション管理ID biz002: 商品一覧
$funcId = "biz002";

// 自機能以外のSessionデータをクリア
SessionHelper::clearDataExceptFunc($funcId);

// マスタデータ取得
$makerList = SessionHelper::getMasterList("makerList");
$categoryList = SessionHelper::getMasterList("categoryList");

// ----- クエリパラメータを取得→無い場合セッションから取得

// ページ
$pgcnt = filter_input(INPUT_GET, 'p', FILTER_VALIDATE_INT);
if (empty($pgcnt)) {    // 0も不可
	$pgcnt = SessionHelper::getData($funcId, "pgcnt") ?? 1;
}
SessionHelper::setData($funcId, "pgcnt", $pgcnt);

// ソート
$pgsort = filter_input(INPUT_GET, 's', FILTER_VALIDATE_INT);
if (empty($pgsort)) {    // 0も不可
	$pgsort = SessionHelper::getData($funcId, "pgsort") ?? 5;
}
SessionHelper::setData($funcId, "pgsort", $pgsort);

// スクロール位置
$scrollPos = filter_input(INPUT_GET, 'pos', FILTER_VALIDATE_INT);
if (is_null($scrollPos)) {    // 0がありえる
	$scrollPos = SessionHelper::getData($funcId, "scrollPos") ?? 0;
}
SessionHelper::setData($funcId, "scrollPos", $scrollPos);

// 検索条件
$keys = ['mn','cn','mk','prn','sh','rm','sf'];    // 検索条件のクエリキー
$searched = !empty(array_intersect_key($_GET, array_flip($keys)));    //$keysがGETクエリパラメータにある場合、検索されたとみなす
if ($searched) {
	$searchCondition = [];
	if (filter_input(INPUT_GET, 'mn')) $searchCondition['management_no'] = filter_input(INPUT_GET, 'mn');
	if (filter_input(INPUT_GET, 'cn', FILTER_DEFAULT, FILTER_REQUIRE_ARRAY)) $searchCondition['category_id'  ] = filter_input(INPUT_GET, 'cn', FILTER_DEFAULT, FILTER_REQUIRE_ARRAY);
	if (filter_input(INPUT_GET, 'mk', FILTER_DEFAULT, FILTER_REQUIRE_ARRAY))$searchCondition['maker_id'     ] = filter_input(INPUT_GET, 'mk', FILTER_DEFAULT, FILTER_REQUIRE_ARRAY);
	if (filter_input(INPUT_GET, 'prn'))	$searchCondition['product_name' ] = filter_input(INPUT_GET, 'prn');
	if (filter_input(INPUT_GET, 'sh') )	$searchCondition['storage_place'] = filter_input(INPUT_GET, 'sh') ;
	if (filter_input(INPUT_GET, 'rm') )	$searchCondition['remarks'      ] = filter_input(INPUT_GET, 'rm') ;
	if (filter_input(INPUT_GET, 'sf') )	$searchCondition['STOCK_FLG'    ] = filter_input(INPUT_GET, 'sf') ;

	SessionHelper::setData($funcId, "searchCondition", $searchCondition);
} else {
	$searchCondition = SessionHelper::getData($funcId, "searchCondition");
	if (empty($searchCondition)) {
		// セッションにも条件が無い場合
		$searchCondition = [
			'STOCK_FLG' => '9',
		];
		SessionHelper::setData($funcId, "searchCondition", $searchCondition);
	}
}
// ----- クエリパラメータを取得 ここまで

// ----- いま確定している状態から「正規URL」を作る
$getParams = [];

// ページ
$pgcntStr = (string)$pgcnt;
if ($pgcntStr !== '' && $pgcntStr !== '1') {    // null、空文字、既定値ではない場合のみパラメータとする
    $getParams['p'] = $pgcntStr;
}

// ソート
$sortStr = (string)$pgsort;
if ($sortStr !== '' && $sortStr !== '5') {
    $getParams['s'] = $sortStr;
}

// スクロール位置（0は省略）
$posStr = (string)$scrollPos;
if ($posStr !== '' && $posStr !== '0') {
    $getParams['pos'] = $posStr;
}

// 検索条件
if (!empty($searchCondition['management_no'])) {
    $getParams['mn'] = $searchCondition['management_no'];
}
if (!empty($searchCondition['category_id'])) {
    $getParams['cn'] = $searchCondition['category_id'];
}
if (!empty($searchCondition['maker_id'])) {
    $getParams['mk'] = $searchCondition['maker_id'];
}
if (!empty($searchCondition['product_name'])) {
    $getParams['prn'] = $searchCondition['product_name'];
}
if (!empty($searchCondition['storage_place'])) {
    $getParams['sh'] = $searchCondition['storage_place'];
}
if (!empty($searchCondition['remarks'])) {
    $getParams['rm'] = $searchCondition['remarks'];
}
// 在庫フラグ：デフォルト'9'はURLに出さない
if (!empty($searchCondition['STOCK_FLG']) && $searchCondition['STOCK_FLG'] !== '9') {
    $getParams['sf'] = $searchCondition['STOCK_FLG'];
}

$current = $_GET ?? [];

// いまのURL($_GET)と正規パラメータがズレていたら302
if ($current !== $getParams) {
    $url = basename($_SERVER['PHP_SELF']) . '?' . http_build_query($getParams);
    header('Location: ' . $url, true, 302);
    exit;
}

// 数値文字列を数値に変換し、select部品に対応する
$searchCondition['category_id'] = array_map('intval', $searchCondition['category_id'] ?? []);
$searchCondition['maker_id']    = array_map('intval', $searchCondition['maker_id'] ?? []);

// ----- いま確定している状態から「正規URL」を作る ここまで

// ロジック
$stock = new Stock();
$product = new Product();

// 画面表示データの取得
$productCnt = $product->countAll();
$listCnt = $product->count($searchCondition);
SessionHelper::setData($funcId, "listCnt", $listCnt);

$pageList = null;
if ($listCnt > 0) {
	$pageList = $product->list($searchCondition, $pgsort, LogicConst::PAGE_ITEM, $pgcnt, $listCnt);
}
if ($listCnt <= 0 || $pageList === null) {
	SessionHelper::flushError(MessageConst::MSG_VAL_LIST_001);
}

// バーコード商品選択出力の選択状態 編集はproduct_list_ajax.phpで行う
$selectedMngNos = SessionHelper::getData($funcId, 'selectedMngNos') ?? [];
?>

<!DOCTYPE html>
<html lang="ja">
<head>
	<?php $title = "商品一覧"; require_once(__DIR__."/inc_head.php"); ?>
	<link rel="stylesheet" href="css/imgpreview.css">
	<script src="js/imgpreview.js"></script>

	<script>const scrollPos= <?= (int)($scrollPos ?? 0) ?>;</script><!-- スクロール位置 -->
	<script src="js/scrollPosition.js"></script>

	<script src="js/multiselect.js"></script>

	<?php if (intval(SessionHelper::getPref("BAR_PRO_FLG")) === 1) :    // バーコード商品選択出力 1：選択出力可能 ?>
	<script src="../vendor/studiogau/chandra/assets/js/csrf.js"></script>
	<script src="js/BarcodeOutput.js"></script>
	<?php endif;    // BAR_PRO_FLG ?>
</head>
<body class="product">


<!-- modal dialog -->
<!-- 使い方: data-bs-toggle="modal" data-bs-target="#searchModal" -->
<div class="modal fade" id="searchModal" tabindex="-1">
  <div class="modal-dialog modal-dialog-centered">
    <div class="modal-content">
      <div class="modal-header">
        <h5 class="modal-title">検索ダイアログ</h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
      </div>

		<form method="GET" name="search" action="product_list.php">
			<input type="hidden" name="pos" value="0"><!-- scrollPosは0にリセット -->
			<input type="hidden" name="s" value="<?=Utility::h($pgsort) ?>">
			<input type="hidden" name="p" value="1">
      <div class="modal-body d-flex align-items-center flex-wrap">
		<input type="text" id="management_no" name="mn" class="form-control form-control-sm" maxlength="18" value="<?=Utility::h($searchCondition['management_no'] ?? '')?>" placeholder="管理番号" />
		<input type="text" id="product_name" name="prn" class="form-control form-control-sm" maxlength="100" value="<?=Utility::h($searchCondition['product_name'] ?? '')?>" placeholder="商品名" />
		<input type="text" id="storage_place" name="sh" class="form-control form-control-sm" maxlength="100" value="<?=Utility::h($searchCondition['storage_place'] ?? '')?>" placeholder="保管場所" />
		<input type="text" id="remarks" name="rm" class="form-control form-control-sm" maxlength="100" value="<?=Utility::h($searchCondition['remarks'] ?? '')?>" placeholder="備考" />
		<select id="category_id" name="cn[]" class="multiselect form-select form-select-sm" multiple data-textcontent="カテゴリ">
			<?php
			foreach ($categoryList ?? [] as $i => $wk) {
				$selected = "";
				if (in_array($wk['id'], $searchCondition['category_id'] ?? [], true)) {
					$selected = 'selected';
				}
			?>
			<option value="<?=Utility::h($wk['id'])?>" <?=$selected ?>><?=Utility::h($wk['category_name']) ?></option>
			<?php
			}
			?>
		</select>
		<select id="maker_id" name="mk[]" class="multiselect form-select form-select-sm" multiple data-textcontent="メーカー">
			<?php
			foreach ($makerList ?? [] as $i => $wk) {
				$selected = "";
				if (in_array($wk['id'], $searchCondition['maker_id'] ?? [], true)) {
					$selected = 'selected';
				}
			?>
			<option value="<?=Utility::h($wk['id'])?>" <?=$selected ?>><?=Utility::h($wk['maker_name']) ?></option>
			<?php
			}
			?>
		</select>
		<select id="STOCK_FLG" name="sf" class="form-select form-select-sm">
			<?php $selected = ''; if (($searchCondition['STOCK_FLG'] ?? '') === '9') $selected = 'selected'; ?>
			<option value="9" <?=$selected?>>在庫：全て</option>
			<?php $selected = ''; if (($searchCondition['STOCK_FLG'] ?? '') === '1') $selected = 'selected'; ?>
			<option value="1" <?=$selected?>>有り</option>
			<?php $selected = ''; if (($searchCondition['STOCK_FLG'] ?? '') === '2') $selected = 'selected'; ?>
			<option value="2" <?=$selected?>>無し</option>
		</select>
		<select id="SORT_NO" name="s" class="form-select form-select-sm">
			<?php $selected = ''; if ($pgsort == 5) $selected = 'selected'; ?>
			<option value="5" <?=$selected?>>管理番号 ▲</option>
			<?php $selected = ''; if ($pgsort == 6) $selected = 'selected'; ?>
			<option value="6" <?=$selected?>>管理番号 ▼</option>
			<?php $selected = ''; if ($pgsort == 7) $selected = 'selected'; ?>
			<option value="7" <?=$selected?>>カテゴリ ▲</option>
			<?php $selected = ''; if ($pgsort == 8) $selected = 'selected'; ?>
			<option value="8" <?=$selected?>>カテゴリ ▼</option>
			<?php $selected = ''; if ($pgsort == 9) $selected = 'selected'; ?>
			<option value="9" <?=$selected?>>メーカー ▲</option>
			<?php $selected = ''; if ($pgsort == 10) $selected = 'selected'; ?>
			<option value="10" <?=$selected?>>メーカー ▼</option>
			<?php $selected = ''; if ($pgsort == 3) $selected = 'selected'; ?>
			<option value="3" <?=$selected?>>商品名 ▲</option>
			<?php $selected = ''; if ($pgsort == 4) $selected = 'selected'; ?>
			<option value="4" <?=$selected?>>商品名 ▼</option>
			<?php $selected = ''; if ($pgsort == 15) $selected = 'selected'; ?>
			<option value="15" <?=$selected?>>卸価格 ▲</option>
			<?php $selected = ''; if ($pgsort == 16) $selected = 'selected'; ?>
			<option value="16" <?=$selected?>>卸価格 ▼</option>
			<?php $selected = ''; if ($pgsort == 17) $selected = 'selected'; ?>
			<option value="17" <?=$selected?>>小売価格 ▲</option>
			<?php $selected = ''; if ($pgsort == 18) $selected = 'selected'; ?>
			<option value="18" <?=$selected?>>小売価格 ▼</option>
			<?php $selected = ''; if ($pgsort == 11) $selected = 'selected'; ?>
			<option value="11" <?=$selected?>>在庫数 ▲</option>
			<?php $selected = ''; if ($pgsort == 12) $selected = 'selected'; ?>
			<option value="12" <?=$selected?>>在庫数 ▼</option>
		</select>
      </div><!-- modal-body -->
      <div class="modal-footer">
        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">キャンセル</button>
        <button type="submit" class="btn btn-primary" data-bs-dismiss="modal">検索</button>
      </div><!-- modal-footer -->
	  </form>
    </div><!-- modal-content -->
  </div>
</div>
<!-- modal dialog end -->

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

		<!-- 案内
		<div class="alert alert-info alert-dismissible fade show" role="alert">
			<div>XXX</div>
			<button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
		</div> -->

		<div class="d-none d-lg-block my-2"><!-- PC幅だと表示 -->
			<form method="GET" name="search" action="product_list.php">
				<div class="d-flex align-items-center flex-wrap">
					<input type="hidden" name="pos" value="0"><!-- scrollPosは0にリセット -->
					<input type="hidden" name="s" value="<?=Utility::h($pgsort) ?>">
					<input type="hidden" name="p" value="1">
					<input type="text" class="form-control form-control-sm" name="mn" maxlength="18" value="<?=Utility::h($searchCondition['management_no'] ?? '')?>" placeholder="管理番号" />
					<input type="text" class="form-control form-control-sm" name="prn" maxlength="100" value="<?=Utility::h($searchCondition['product_name'] ?? '')?>" placeholder="商品名" />
					<input type="text" class="form-control form-control-sm" name="sh" maxlength="100" value="<?=Utility::h($searchCondition['storage_place'] ?? '')?>" placeholder="保管場所" />
					<input type="text" class="form-control form-control-sm" name="rm" maxlength="100" value="<?=Utility::h($searchCondition['remarks'] ?? '')?>" placeholder="備考" />

					<select id="category_id" name="cn[]" class="multiselect form-select form-select-sm" multiple data-textcontent="カテゴリ">
						<?php
						foreach ($categoryList ?? [] as $i => $wk) {
							$selected = "";
							if (in_array($wk['id'], $searchCondition['category_id'] ?? [], true)) {
								$selected = 'selected';
							}
						?>
						<option value="<?=Utility::h($wk['id'])?>" <?=$selected ?>><?=Utility::h($wk['category_name']) ?></option>
						<?php
						}
						?>
					</select>

					<select id="maker_id" name="mk[]" class="multiselect form-select form-select-sm" multiple data-textcontent="メーカー">
						<?php
						foreach ($makerList ?? [] as $i => $wk) {
							$selected = "";
							if (in_array($wk['id'], $searchCondition['maker_id'] ?? [], true)) {
								$selected = 'selected';
							}
						?>
						<option value="<?=Utility::h($wk['id'])?>" <?=$selected ?>><?=Utility::h($wk['maker_name']) ?></option>
						<?php
						}
						?>
					</select>
				
					<select id="STOCK_FLG" name="sf" class="form-select  form-select-sm">
						<?php $selected = ''; if (($searchCondition['STOCK_FLG'] ?? '') === '9') $selected = 'selected'; ?>
						<option value="9" <?=$selected?>>在庫：全て</option>
						<?php $selected = ''; if (($searchCondition['STOCK_FLG'] ?? '') === '1') $selected = 'selected'; ?>
						<option value="1" <?=$selected?>>在庫：有り</option>
						<?php $selected = ''; if (($searchCondition['STOCK_FLG'] ?? '') === '2') $selected = 'selected'; ?>
						<option value="2" <?=$selected?>>在庫：無し</option>
					</select>
					<button type="submit" class="btn btn-primary text-nowrap">検索</button>
				</div>
			</form>
		</div><!-- PC幅だと表示 ここまで -->
		<div class="d-lg-none d-block my-2"><!-- スマホ幅だと表示 -->
			<button type="button" class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#searchModal">検索ダイアログ</button>
		</div><!-- スマホ幅だと表示 ここまで -->

		<?php
		if (count($pageList ?? []) > 0) :
		?>
		<div id="table-wrapper" class="h-100 overflow-y-auto narrow">
		<table class="table table-sm table-responsive">
			<thead>
			<tr>
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
					<form method="GET" action="product_list.php">
						<input type="hidden" name="pos" value=""><!-- submit時に代入 scrollPos -->
						<input type="hidden" name="s" value="<?= Utility::h($noSort) ?>">
						<input type="hidden" name="p" value="1">
						<button type="submit" class="btn w-100">管理番号<?=$strSort?></button>
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
					<form method="GET" action="product_list.php">
						<input type="hidden" name="pos" value=""><!-- submit時に代入 scrollPos -->
						<input type="hidden" name="s" value="<?= Utility::h($noSort) ?>">
						<input type="hidden" name="p" value="1">
						<button type="submit" class="btn w-100">カテゴリ<?=$strSort?></button>
					</form>
				</th>
				<?php
				$noSort = 9;
				$strSort = "";
				if ($pgsort === 9) {
					$noSort = 10;
					$strSort = " ▲";
				} else if ($pgsort === 10) {
					$noSort = 9;
					$strSort = " ▼";
				}
				?>

				<th scope="col">
					<form method="GET" action="product_list.php">
						<input type="hidden" name="pos" value=""><!-- submit時に代入 scrollPos -->
						<input type="hidden" name="s" value="<?= Utility::h($noSort) ?>">
						<input type="hidden" name="p" value="1">
						<button type="submit" class="btn w-100">メーカー<?=$strSort?></button>
					</form>
				</th>
				<?php
				// }
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
					<form method="GET" action="product_list.php">
						<input type="hidden" name="pos" value=""><!-- submit時に代入 scrollPos -->
						<input type="hidden" name="s" value="<?= Utility::h($noSort) ?>">
						<input type="hidden" name="p" value="1">
						<button type="submit" class="btn w-100">商品名<?=$strSort?></button>
					</form>
				</th>
				<?php
				// if ($winWidth > 800) {    // タブレット幅
				$noSort = 15;
				$strSort = "";
				if ($pgsort === 15) {
					$noSort = 16;
					$strSort = " ▲";
				} else if ($pgsort === 16) {
					$noSort = 15;
					$strSort = " ▼";
				}
				?>
				
				<th scope="col">
					<form method="GET" action="product_list.php">
						<input type="hidden" name="pos" value=""><!-- submit時に代入 scrollPos -->
						<input type="hidden" name="s" value="<?= Utility::h($noSort) ?>">
						<input type="hidden" name="p" value="1">
						<button type="submit" class="btn w-100">卸価格<?=$strSort?></button>
					</form>
				</th>
				<?php
				$noSort = 17;
				$strSort = "";
				if ($pgsort === 17) {
					$noSort = 18;
					$strSort = " ▲";
				} else if ($pgsort === 18) {
					$noSort = 17;
					$strSort = " ▼";
				}
				?>
				
				<th scope="col">
					<form method="GET" action="product_list.php">
						<input type="hidden" name="pos" value=""><!-- submit時に代入 scrollPos -->
						<input type="hidden" name="s" value="<?= Utility::h($noSort) ?>">
						<input type="hidden" name="p" value="1">
						<button type="submit" class="btn w-100">小売価格<?=$strSort?></button>
					</form>
				</th>
				<?php
				$noSort = 11;
				$strSort = "";
				if ($pgsort === 11) {
					$noSort = 12;
					$strSort = " ▲";
				} else if ($pgsort === 12) {
					$noSort = 11;
					$strSort = " ▼";
				}
				?>
				
				<th scope="col">
					<form method="GET" action="product_list.php">
						<input type="hidden" name="pos" value=""><!-- submit時に代入 scrollPos -->
						<input type="hidden" name="s" value="<?= Utility::h($noSort) ?>">
						<input type="hidden" name="p" value="1">
						<button type="submit" class="btn w-100">在庫数<?=$strSort?></button>
					</form>
				</th>
				
				<?php if (intval(SessionHelper::getPref("BAR_PRO_FLG")) === 1) :    // バーコード商品選択出力 1：選択出力可能 ?>
				<th scope="col">
					<button type="button" class="btn" id="btnSelectAll">全選択</button>
					<button type="button" class="btn" id="btnClearVisible">全解除</button>
				</th>
				<?php endif;    // BAR_PRO_FLG ?>
			</tr>
			</thead>
			<tbody>
			<?php
			foreach ($pageList as $i => $wk) :

				// 店舗在庫数取得
				$locationStock = "";
				$locationStock = $stock->getStockPerLocation($wk['management_no']);
				$locationStock = htmlspecialchars($locationStock ?? '', ENT_QUOTES, 'UTF-8');
			?>
			<tr>
				<?php
				if ($auth->getCurrentUser()?->can("biz002_update")) :
				?>
				<td>
					<form method="POST" action="product_edit.php">
						<?= \Studiogau\Chandra\Support\Utility::renderCsrfHiddenInput('product_list.actions') ?>
						<input type="hidden" name="mngNo" value="<?=Utility::h($wk['management_no']) ?>">
						<input type="hidden" name="filename" value="<?= $filename ?>">
						<input type="hidden" name="pos" value=""><!-- submit時に代入 scrollPos -->
						<button type="submit" class="btn w-100"><?=Utility::h($wk['management_no']) ?></button>
					</form>
				</td>
				<?php
				else:
				?>
				<td><?=Utility::h($wk['management_no']) ?></td>
				<?php
				endif;
				?>
				<td><?=Utility::h($wk['category_name']) ?></td>
				<td><?=Utility::h($wk['maker_name']) ?></td>
				<td>
					<?php if (!empty($wk['image_file'])) { ?>
					<a class="imgprev" style="text-decoration:none;" data-img="<?=Utility::h(LogicConst::DIR_IMAGES.'/s_'.$wk['image_file']) ?>"><?=Utility::h($wk['product_name']) ?></a>
					<?php } else { ?>
					<?=Utility::h($wk['product_name']) ?>
					<?php } ?>
				</td>
				<?php
				$lsTitle = '';
					$lsTitle = ' data-bs-title="店舗別在庫数"';
				?>
				<td class="text-end"><?=number_format($wk['wholesale_amount']) ?></td>
				<td class="text-end"><?=number_format($wk['retail_amount']) ?></td>
				<td class="text-end"<?=$lsTitle?> tabindex="0" title="<?=$locationStock?>">
					<?=number_format($wk['quantity']) ?>&nbsp;<?=Utility::h($wk['unit_name']) ?>
				</td>
				<?php if (intval(SessionHelper::getPref("BAR_PRO_FLG")) === 1) :    // バーコード商品選択出力 1：選択出力可能 ?>
				<td class="text-center">
					<?php // バーコード商品選択出力の選択状態を比較して再現
					$isChecked = in_array($wk['management_no'], $selectedMngNos, true); 
					?>
					<input class="form-check-input js-product-check" type="checkbox" data-mng="<?= htmlspecialchars($wk['management_no'], ENT_QUOTES, 'UTF-8') ?>" <?= $isChecked ? 'checked' : '' ?>>
				</td>
				<?php endif;    // BAR_PRO_FLG?>
			</tr>
			<?php
			endforeach;    // foreach $pageList
			?>
			</tbody>
		</table>
		</div><!-- table-wrapper -->
		<?php
		endif;    // count($pageList)
		?>

		<?php $pagination_action = basename(__FILE__); require_once(__DIR__."/inc_pagination.php"); ?>

		<!-- ボタン -->
		<div class="d-flex justify-content-evenly">

			<a href="menu.php" type="button" class="btn btn-primary">戻る</a>

			<?php if ($auth->getCurrentUser()?->can("biz002_update")) { ?>
			<form method="POST" action="product_create.php">
				<?= \Studiogau\Chandra\Support\Utility::renderCsrfHiddenInput('product_list.actions') ?>
				<input type="hidden" name="pos" value=""><!-- submit時に代入 scrollPos -->
				<button type="submit" class="btn btn-primary" name="mode" value="insert">登録</button>
			</form>
			<?php } ?>
			<?php
			if ($listCnt > 0) {
				if ($listCnt <= LogicConst::PAGE_ITEM_EXCEL) {
			?>
			
			<form method="POST" action="product_list_export.php">
				<?= \Studiogau\Chandra\Support\Utility::renderCsrfHiddenInput('product_list.actions') ?>
				<input type="hidden" name="mode" value="export">
				<button type="submit" class="btn btn-primary">Excel出力</button>
			</form>

			<?php } else { ?>
				<form method="GET" action="list_export_split.php">
					<input type="hidden" name="fn" value="<?= $filename ?>">
					<button type="submit" class="btn btn-primary" >Excel出力</button>
				</form>
			<?php
				}
			} else {
				if (true) {
			?>
			<form method="POST" action="product_list_export.php">
				<?= \Studiogau\Chandra\Support\Utility::renderCsrfHiddenInput('product_list.actions') ?>
				<input type="hidden" name="mode" value="export">
				<button type="submit" class="btn btn-primary">入力ファイルテンプレート</button>
			</form>
			<?php
				}
			}
			if (intval(SessionHelper::getPref("BAR_PRO_FLG")) === 1) :    // バーコード商品選択出力 1：選択出力可能
			?>
				<?= \Studiogau\Chandra\Support\Utility::renderCsrfContainer('product_list.barcode_ajax', 'barcode-output-csrf') ?>
				<form method="POST" action="product_barcode_list.php">
					<?= \Studiogau\Chandra\Support\Utility::renderCsrfHiddenInput('product_list.actions') ?>
					<input type="hidden" name="mode" value="show">
					<input type="hidden" name="pos" value=""><!-- submit時に代入 scrollPos -->
					<button type="submit" id="btnBarcodeOutput" class="btn btn-primary" <?php if(count($selectedMngNos ?? [])<=0){ ?>disabled<?php } ?>>ﾊﾞｰｺｰﾄﾞ出力</button>
				</form>
			<?php
			endif;
			?>
		</div>
		
	</main>
	<?php require_once(__DIR__."/inc_footer.php"); ?>
</body>
</html>
