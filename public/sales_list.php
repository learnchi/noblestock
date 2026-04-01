<?php
/**
 * 実績一覧 画面
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
use Noblestock\DbLogic\UserRepository;
use Noblestock\DbLogic\History;
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
$funcId = "biz301";

// 自機能以外のSessionデータをクリア
SessionHelper::clearDataExceptFunc($funcId);

// マスタデータ取得
$makerList = SessionHelper::getMasterList("makerList");
$categoryList = SessionHelper::getMasterList("categoryList");
$locationList = SessionHelper::getMasterList("locationList");

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
$keys = ['df','dt','ln','cn','mk','mn','prn','sk','un','hk'];    // 検索条件のクエリキー
$searched = !empty(array_intersect_key($_GET, array_flip($keys)));    //$keysがGETクエリパラメータにある場合、検索されたとみなす
if ($searched) {
	$searchCondition = [];
    // df/dt は「キーがある」なら、空でも拾う（ユーザーが消した意思を尊重）
    if (array_key_exists('df', $_GET)) {
        $searchCondition['DATE_FROM'] = trim((string)$_GET['df']); // '' も入る
    }
    if (array_key_exists('dt', $_GET)) {
        $searchCondition['DATE_TO'] = trim((string)$_GET['dt']);   // '' も入る
    }
	if (filter_input(INPUT_GET, 'ln', FILTER_DEFAULT, FILTER_REQUIRE_ARRAY)) $searchCondition['location_name'] = filter_input(INPUT_GET, 'ln', FILTER_DEFAULT, FILTER_REQUIRE_ARRAY);
	if (filter_input(INPUT_GET, 'cn', FILTER_DEFAULT, FILTER_REQUIRE_ARRAY)) $searchCondition['category_name'] = filter_input(INPUT_GET, 'cn', FILTER_DEFAULT, FILTER_REQUIRE_ARRAY);
	if (filter_input(INPUT_GET, 'mk', FILTER_DEFAULT, FILTER_REQUIRE_ARRAY))$searchCondition['maker_name'    ] = filter_input(INPUT_GET, 'mk', FILTER_DEFAULT, FILTER_REQUIRE_ARRAY);
	if (filter_input(INPUT_GET, 'mn')) $searchCondition['management_no'] = filter_input(INPUT_GET, 'mn');
	if (filter_input(INPUT_GET, 'prn'))	$searchCondition['product_name' ] = filter_input(INPUT_GET, 'prn');
	if (filter_input(INPUT_GET, 'sk') !== null) $searchCondition['SEARCH_KBN'] = filter_input(INPUT_GET, 'sk');
	if (filter_input(INPUT_GET, 'un', FILTER_DEFAULT, FILTER_REQUIRE_ARRAY)) $searchCondition['user_name'] = filter_input(INPUT_GET, 'un', FILTER_DEFAULT, FILTER_REQUIRE_ARRAY);
	if (filter_input(INPUT_GET, 'hk', FILTER_DEFAULT, FILTER_REQUIRE_ARRAY)) $searchCondition['history_kbn'] = filter_input(INPUT_GET, 'hk', FILTER_DEFAULT, FILTER_REQUIRE_ARRAY);
	if (!array_key_exists('SEARCH_KBN', $searchCondition)) {
		$searchCondition['SEARCH_KBN'] = '0';
	}

	SessionHelper::setData($funcId, "searchCondition", $searchCondition);
} else {
	$searchCondition = SessionHelper::getData($funcId, "searchCondition");
	if (empty($searchCondition)) {
		// セッションにも条件が無い場合に、日付を過去1か月とする
		$searchCondition = [
			'DATE_FROM'  => date('Y-m-d', strtotime('-1 month')),
			'DATE_TO'    => date('Y-m-d'),
			'SEARCH_KBN' => '0',
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
if (!empty($searchCondition['DATE_FROM'])) {
    $getParams['df'] = $searchCondition['DATE_FROM'];
}
if (!empty($searchCondition['DATE_TO'])) {
    $getParams['dt'] = $searchCondition['DATE_TO'];
}
if (!empty($searchCondition['location_name'])) {
    $getParams['ln'] = $searchCondition['location_name'];
}
if (!empty($searchCondition['category_name'])) {
    $getParams['cn'] = $searchCondition['category_name'];
}
if (!empty($searchCondition['maker_name'])) {
    $getParams['mk'] = $searchCondition['maker_name'];
}
if (!empty($searchCondition['management_no'])) {
    $getParams['mn'] = $searchCondition['management_no'];
}	
if (!empty($searchCondition['product_name'])) {
    $getParams['prn'] = $searchCondition['product_name'];
}
if (!empty($searchCondition['SEARCH_KBN']) && $searchCondition['SEARCH_KBN'] !== '0') {
    $getParams['sk'] = $searchCondition['SEARCH_KBN'];
}

$current = $_GET ?? [];

// いまのURL($_GET)と正規パラメータがズレていたら302
if ($current !== $getParams) {
    $url = basename($_SERVER['PHP_SELF']) . '?' . http_build_query($getParams);
    header('Location: ' . $url, true, 302);
    exit;
}
// ----- いま確定している状態から「正規URL」を作る ここまで


// 画面表示データの取得
$history = new History();
$listCnt = $history->count4Zaiko($searchCondition);

SessionHelper::setData($funcId, "listCnt", $listCnt);
$pageList = null;
if ($listCnt > 0) {

	// 検索条件に追加
	$pageList = $history->list4Zaiko($searchCondition, $pgsort, LogicConst::PAGE_ITEM, $pgcnt, $listCnt);
}


?>

<!DOCTYPE html>
<html lang="ja">
<head>
	<?php $title = "実績一覧"; require_once(__DIR__."/inc_head.php"); ?>
	<script>const scrollPos= <?= (int)($scrollPos ?? 0) ?>;</script><!-- スクロール位置 -->
	<script src="js/scrollPosition.js"></script>
	<script src="js/multiselect.js"></script>
		
</head>
<body class="history">

<!-- modal dialog -->
<!-- 使い方: data-bs-toggle="modal" data-bs-target="#searchModal" -->
<div class="modal fade" id="searchModal" tabindex="-1">
  <div class="modal-dialog modal-dialog-centered">
    <div class="modal-content">
      <div class="modal-header">
        <h5 class="modal-title">検索ダイアログ</h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
      </div>
	  <form method="GET" name="search" action="sales_list.php">
			<input type="hidden" name="pos" value="0"><!-- scrollPosは0にリセット -->
			<input type="hidden" name="s" value="<?=Utility::h($pgsort) ?>">
			<input type="hidden" name="p" value="1">
      <div class="modal-body d-flex align-items-center flex-wrap">

		<input type="date" id="date_from" name="df" class="form-control" value="<?=Utility::h($searchCondition['DATE_FROM'] ?? '')?>" />
		～
		<input type="date" id="date_to" name="dt" class="form-control" value="<?=Utility::h($searchCondition['DATE_TO'] ?? '')?>" />
		<input type="text" name="mn" class="form-control" maxlength="18" value="<?=Utility::h($searchCondition['management_no'] ?? '')?>" placeholder="管理番号" />
		<input type="text" name="prn" class="form-control" maxlength="100" value="<?=Utility::h($searchCondition['product_name'] ?? '')?>" placeholder="商品名" />
		<select id="SEARCH_KBN" name="sk" class="form-select form-select-sm">
			<?php $selected = ''; if ($searchCondition["SEARCH_KBN"] == 0) $selected = 'selected'; ?>
			<option value="0" <?=$selected?>>処理内容：全て</option>
			<?php $selected = ''; if ($searchCondition["SEARCH_KBN"] == 1) $selected = 'selected'; ?>
			<option value="1" <?=$selected?>>入庫あり</option>
			<?php $selected = ''; if ($searchCondition["SEARCH_KBN"] == 2) $selected = 'selected'; ?>
			<option value="2" <?=$selected?>>出庫あり</option>
			<?php $selected = ''; if ($searchCondition["SEARCH_KBN"] == 3) $selected = 'selected'; ?>
			<option value="3" <?=$selected?>>移動あり</option>
			<?php $selected = ''; if ($searchCondition["SEARCH_KBN"] == 4) $selected = 'selected'; ?>
			<option value="4" <?=$selected?>>入庫/出庫あり</option>
			<?php $selected = ''; if ($searchCondition["SEARCH_KBN"] == 5) $selected = 'selected'; ?>
			<option value="5" <?=$selected?>>入庫/出庫なし</option>
			<?php $selected = ''; if ($searchCondition["SEARCH_KBN"] == 6) $selected = 'selected'; ?>
			<option value="6" <?=$selected?>>入庫/出庫/移動あり</option>
			<?php $selected = ''; if ($searchCondition["SEARCH_KBN"] == 7) $selected = 'selected'; ?>
			<option value="7" <?=$selected?>>入庫/出庫/移動なし</option>
		</select>
		<select id="location_name" name="ln[]" class="multiselect form-select form-select-sm" multiple data-textcontent="店舗">
			<?php
			foreach ($locationList ?? [] as $i => $wk) {
				$selected = '';
				if (in_array($wk['location_name'], $searchCondition['location_name'] ?? [], true)) {
					$selected = 'selected';
				}
			?>
			<option value="<?=Utility::h($wk['location_name'])?>" <?=$selected ?>><?=Utility::h($wk['location_name']) ?></option>
			<?php
			}
			?>
		</select>
		<select id="category_name" name="cn[]" class="multiselect form-select form-select-sm" multiple data-textcontent="カテゴリ">
			<?php
			foreach ($categoryList ?? [] as $i => $wk) {
				$selected = '';
				if (in_array($wk['category_name'], $searchCondition['category_name'] ?? [], true)) {
					$selected = 'selected';
				}
			?>
			<option value="<?=Utility::h($wk['category_name'])?>" <?=$selected ?>><?=Utility::h($wk['category_name']) ?></option>
			<?php
			}
			?>
		</select>
		<select id="maker_name" name="mk[]" class="multiselect form-select form-select-sm" multiple data-textcontent="メーカー">
			<?php
			foreach ($makerList ?? [] as $i => $wk) {
				$selected = '';
				if (in_array($wk['maker_name'], $searchCondition['maker_name'] ?? [], true)) {
					$selected = 'selected';
				}
			?>
			<option value="<?=Utility::h($wk['maker_name'])?>" <?=$selected ?>><?=Utility::h($wk['maker_name']) ?></option>
			<?php
			}
			?>
		</select>

		<select name="s" class="form-select form-select-sm">
			<?php $selected = ''; if ($pgsort == 1) $selected = 'selected'; ?>
			<option value="1" <?=$selected?>>管理番号 ▲</option>
			<?php $selected = ''; if ($pgsort == 2) $selected = 'selected'; ?>
			<option value="2" <?=$selected?>>管理番号 ▼</option>
			<?php $selected = ''; if ($pgsort == 5) $selected = 'selected'; ?>
			<option value="5" <?=$selected?>>カテゴリ ▲</option>
			<?php $selected = ''; if ($pgsort == 6) $selected = 'selected'; ?>
			<option value="6" <?=$selected?>>カテゴリ ▼</option>
			<?php $selected = ''; if ($pgsort == 7) $selected = 'selected'; ?>
			<option value="7" <?=$selected?>>メーカー ▲</option>
			<?php $selected = ''; if ($pgsort == 8) $selected = 'selected'; ?>
			<option value="8" <?=$selected?>>メーカー ▼</option>
			<?php $selected = ''; if ($pgsort == 9) $selected = 'selected'; ?>
			<option value="9" <?=$selected?>>商品名 ▲</option>
			<?php $selected = ''; if ($pgsort == 10) $selected = 'selected'; ?>
			<option value="10" <?=$selected?>>商品名 ▼</option>
			<?php $selected = ''; if ($pgsort == 11) $selected = 'selected'; ?>
			<option value="11" <?=$selected?>>入庫数 ▲</option>
			<?php $selected = ''; if ($pgsort == 12) $selected = 'selected'; ?>
			<option value="12" <?=$selected?>>入庫数 ▼</option>
			<?php $selected = ''; if ($pgsort == 13) $selected = 'selected'; ?>
			<option value="13" <?=$selected?>>出庫数 ▲</option>
			<?php $selected = ''; if ($pgsort == 14) $selected = 'selected'; ?>
			<option value="14" <?=$selected?>>出庫数 ▼</option>
			<?php $selected = ''; if ($pgsort == 15) $selected = 'selected'; ?>
			<option value="15" <?=$selected?>>移動数 ▲</option>
			<?php $selected = ''; if ($pgsort == 16) $selected = 'selected'; ?>
			<option value="16" <?=$selected?>>移動数 ▼</option>
			<?php $selected = ''; if ($pgsort == 17) $selected = 'selected'; ?>
			<option value="17" <?=$selected?>>在庫数 ▲</option>
			<?php $selected = ''; if ($pgsort == 18) $selected = 'selected'; ?>
			<option value="18" <?=$selected?>>在庫数 ▼</option>
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
			<form method="GET" action="sales_list.php">
				<div class="d-flex align-items-center flex-wrap">
					<input type="hidden" name="pos" value="0"><!-- scrollPosは0にリセット -->
					<input type="hidden" name="s" value="<?=Utility::h($pgsort) ?>">
					<input type="hidden" name="p" value="1">
					<input type="date" id="date_from" name="df" class="form-control" value="<?=Utility::h($searchCondition['DATE_FROM'] ?? '')?>" />
					～
					<input type="date" id="date_to" name="dt" class="form-control" value="<?=Utility::h($searchCondition['DATE_TO'] ?? '')?>" />
					<input type="text" name="mn" class="form-control" maxlength="18" value="<?=Utility::h($searchCondition['management_no'] ?? '')?>" placeholder="管理番号" />
					<input type="text" name="prn" class="form-control" maxlength="100" value="<?=Utility::h($searchCondition['product_name'] ?? '')?>" placeholder="商品名" />

					<select id="SEARCH_KBN" name="sk" class="form-select form-select-sm">
						<?php $selected = ''; if ($searchCondition["SEARCH_KBN"] == 0) $selected = 'selected'; ?>
						<option value="0" <?=$selected?>>処理内容：全て</option>
						<?php $selected = ''; if ($searchCondition["SEARCH_KBN"] == 1) $selected = 'selected'; ?>
						<option value="1" <?=$selected?>>入庫あり</option>
						<?php $selected = ''; if ($searchCondition["SEARCH_KBN"] == 2) $selected = 'selected'; ?>
						<option value="2" <?=$selected?>>出庫あり</option>
						<?php $selected = ''; if ($searchCondition["SEARCH_KBN"] == 3) $selected = 'selected'; ?>
						<option value="3" <?=$selected?>>移動あり</option>
						<?php $selected = ''; if ($searchCondition["SEARCH_KBN"] == 4) $selected = 'selected'; ?>
						<option value="4" <?=$selected?>>入庫/出庫あり</option>
						<?php $selected = ''; if ($searchCondition["SEARCH_KBN"] == 5) $selected = 'selected'; ?>
						<option value="5" <?=$selected?>>入庫/出庫なし</option>
						<?php $selected = ''; if ($searchCondition["SEARCH_KBN"] == 6) $selected = 'selected'; ?>
						<option value="6" <?=$selected?>>入庫/出庫/移動あり</option>
						<?php $selected = ''; if ($searchCondition["SEARCH_KBN"] == 7) $selected = 'selected'; ?>
						<option value="7" <?=$selected?>>入庫/出庫/移動なし</option>
					</select>
					<select id="location_name" name="ln[]" class="multiselect form-select form-select-sm" multiple data-textcontent="店舗">
						<?php
						foreach ($locationList ?? [] as $i => $wk) {
							$selected = '';
							if (in_array($wk['location_name'], $searchCondition['location_name'] ?? [], true)) {
									$selected = 'selected';
							}
						?>
						<option value="<?=Utility::h($wk['location_name'])?>" <?=$selected ?>><?=Utility::h($wk['location_name']) ?></option>
						<?php
						}
						?>
					</select>
					<select id="category_name" name="cn[]" class="multiselect form-select form-select-sm" multiple data-textcontent="カテゴリ">
						<?php
						foreach ($categoryList ?? [] as $i => $wk) {
							$selected = '';
							if (in_array($wk['category_name'], $searchCondition['category_name'] ?? [], true)) {
									$selected = 'selected';
							}
						?>
						<option value="<?=Utility::h($wk['category_name'])?>" <?=$selected ?>><?=Utility::h($wk['category_name']) ?></option>
						<?php
						}
						?>
					</select>
					<select id="maker_name" name="mk[]" class="multiselect form-select form-select-sm" multiple data-textcontent="メーカー">
						<?php
						foreach ($makerList ?? [] as $i => $wk) {
							$selected = '';
							if (in_array($wk['maker_name'], $searchCondition['maker_name'] ?? [], true)) {
									$selected = 'selected';
							}
						?>
						<option value="<?=Utility::h($wk['maker_name'])?>" <?=$selected ?>><?=Utility::h($wk['maker_name']) ?></option>
						<?php
						}
						?>
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
				<th scope="col" class="text-nowrap">
					<form method="GET" action="sales_list.php">
						<input type="hidden" name="pos" value=""><!-- submit時に代入 scrollPos -->
						<input type="hidden" name="s" value="<?= Utility::h($noSort) ?>">
						<input type="hidden" name="p" value="1">
						<button type="submit" class="btn w-100">管理番号<?=$strSort?></button>
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
				<th scope="col" class="text-nowrap">
					<form method="GET" action="sales_list.php">
						<input type="hidden" name="pos" value=""><!-- submit時に代入 scrollPos -->
						<input type="hidden" name="s" value="<?= Utility::h($noSort) ?>">
						<input type="hidden" name="p" value="1">
						<button type="submit" class="btn w-100">カテゴリ<?=$strSort?></button>
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
				<th scope="col" class="text-nowrap">
					<form method="GET" action="sales_list.php">
						<input type="hidden" name="pos" value=""><!-- submit時に代入 scrollPos -->
						<input type="hidden" name="s" value="<?= Utility::h($noSort) ?>">
						<input type="hidden" name="p" value="1">
						<button type="submit" class="btn w-100">メーカー<?=$strSort?></button>
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
				<th scope="col" class="text-nowrap">
					<form method="GET" action="sales_list.php">
						<input type="hidden" name="pos" value=""><!-- submit時に代入 scrollPos -->
						<input type="hidden" name="s" value="<?= Utility::h($noSort) ?>">
						<input type="hidden" name="p" value="1">
						<button type="submit" class="btn w-100">商品名<?=$strSort?></button>
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
				<th scope="col" class="text-nowrap">
					<form method="GET" action="sales_list.php">
						<input type="hidden" name="pos" value=""><!-- submit時に代入 scrollPos -->
						<input type="hidden" name="s" value="<?= Utility::h($noSort) ?>">
						<input type="hidden" name="p" value="1">
						<button type="submit" class="btn w-100">入庫数<?=$strSort?></button>
					</form>
				</th>
				<?php
				$noSort = 13;
				$strSort = "";
				if ($pgsort === 13) {
					$noSort = 14;
					$strSort = " ▲";
				} else if ($pgsort === 14) {
					$noSort = 13;
					$strSort = " ▼";
				}
				?>
				<th scope="col" class="text-nowrap">
					<form method="GET" action="sales_list.php">
						<input type="hidden" name="pos" value=""><!-- submit時に代入 scrollPos -->
						<input type="hidden" name="s" value="<?= Utility::h($noSort) ?>">
						<input type="hidden" name="p" value="1">
						<button type="submit" class="btn w-100">出庫数<?=$strSort?></button>
					</form>
				</th>
				<?php
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
				<th scope="col" class="text-nowrap">
					<form method="GET" action="sales_list.php">
						<input type="hidden" name="pos" value=""><!-- submit時に代入 scrollPos -->
						<input type="hidden" name="s" value="<?= Utility::h($noSort) ?>">
						<input type="hidden" name="p" value="1">
						<button type="submit" class="btn w-100">移動数<?=$strSort?></button>
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
				<th scope="col" class="text-nowrap">
					<form method="GET" action="sales_list.php">
						<input type="hidden" name="pos" value=""><!-- submit時に代入 scrollPos -->
						<input type="hidden" name="s" value="<?= Utility::h($noSort) ?>">
						<input type="hidden" name="p" value="1">
						<button type="submit" class="btn w-100">在庫数<?=$strSort?></button>
					</form>
				</th>
			</tr>
			</thead>
			<tbody>
			<?php
			foreach ($pageList as $i => $wk) :
			?>
			<tr>
				<td>
					<form method="GET" action="sales_show.php">
						<input type="hidden" name="mn" value="<?=Utility::h($wk['management_no']) ?>">
						<input type="hidden" name="bn" value="<?=Utility::h($wk['branch_no']) ?>">
						<input type="hidden" name="df" value="<?= $searchCondition["DATE_FROM"]??'' ?>">
						<input type="hidden" name="dt" value="<?= $searchCondition["DATE_TO"]??'' ?>">
						<?php foreach (($searchCondition['location_name'] ?? []) as $ln): ?>
							<input type="hidden" name="ln[]" value="<?= htmlspecialchars($ln, ENT_QUOTES) ?>">
						<?php endforeach; ?>
						<input type="hidden" name="pos" value=""><!-- submit時に代入 scrollPos -->
						<button type="submit" class="btn w-100"><?=Utility::h($wk['management_no']) ?>&nbsp;[<?=Utility::h($wk['branch_no']) ?>]</button>
					</form>
				</td>
				<td><?=Utility::h($wk['category_name']) ?></td>
				<td><?=Utility::h($wk['maker_name']) ?></td>

				<td><?=Utility::h($wk['product_name']) ?></td>
				<td class="text-end"><?=number_format($wk['ZAIKO_IN']) ?></td>
				<td class="text-end"><?=number_format($wk['ZAIKO_OUT']) ?></td>
				<td class="text-end"><?=number_format($wk['ZAIKO_MOVE']) ?></td>
				<td class="text-end"><?=number_format($wk['stock']) ?></td>
			</tr>
			<?php
			endforeach;    // $pageList
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
			<?php
			if ($listCnt > 0) {
				if ($listCnt <= LogicConst::PAGE_ITEM_EXCEL) {
			?>			
			<form method="POST" action="sales_list_export.php">
				<?= Utility::renderCsrfHiddenInput('sales_list.actions') ?>
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
			}
			?>
		</div>

	</main>
	<?php require_once(__DIR__."/inc_footer.php"); ?>
</body>
</html>
