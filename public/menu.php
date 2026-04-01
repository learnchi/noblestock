<?php
@session_start();
date_default_timezone_set('Asia/Tokyo');

// composerを使用
require_once(__DIR__ . '/../vendor/autoload.php');
use Studiogau\Chandra\Logging\Logger;
use Studiogau\Chandra\Auth\LoginAttempt;
use Studiogau\Chandra\Auth\LoginCredentials;
use Studiogau\Chandra\Support\SessionHelper;
use Studiogau\Chandra\Support\Utility;
use Studiogau\Chandra\Auth\AuthException;
use Noblestock\Logic\AuthServiceFactory;
use Noblestock\Logic\MenuRouter;
use Noblestock\DbLogic\Config;
use Noblestock\DbLogic\Category;
use Noblestock\DbLogic\Maker;
use Noblestock\DbLogic\Unit;
use Noblestock\DbLogic\Location;
use Noblestock\Logic\MessageConst;

// ロガー
$logger = Logger::createDefault(dirname(__DIR__, 1));

// 認証チェック
$auth = AuthServiceFactory::create($logger);

if (!$auth->checkUserSession()) {
	if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
		if (session_status() !== PHP_SESSION_ACTIVE) {
			@session_start();
		}
		SessionHelper::flushError(MessageConst::MSG_INF_AUTH_002);
		header("Location: index.php");
		exit;
	}

	$csrfScope = (string)($_POST[Utility::getCsrfScopeFieldName()] ?? '');
	$csrfToken = (string)($_POST[Utility::getCsrfFieldName()] ?? '');
	if (!Utility::validatePostedCsrfToken($csrfScope, $csrfToken)) {
		$logger->error(basename(__FILE__).' op=login msg="Invalid csrf token" page=menu.php');
		SessionHelper::flushError(MessageConst::MSG_SYS_COMMON_900);
		header("Location: index.php");
		exit;
	}

	try {
		$credentials = new LoginCredentials((string) ($_POST['user'] ?? ''), (string) ($_POST['pass'] ?? ''));
		$attempt = LoginAttempt::fromServer($credentials->getUserId(), $_SERVER);
		$auth->login($credentials, $attempt);

	} catch (AuthException $e) { 
		$logger->error(basename(__FILE__)." login returned Error:".$e->getMessage());
		SessionHelper::flushError(MessageConst::MSG_VAL_AUTH_001);
		// チェック結果がエラーの場合ログイン画面に遷移
		header("Location: index.php");
		exit;
	} catch (\Throwable $e) { 
		$logger->error(basename(__FILE__)." login returned Error:".$e->getMessage());
		SessionHelper::flushError(MessageConst::MSG_SYS_COMMON_900);    // システムエラー
		// チェック結果がエラーの場合ログイン画面に遷移
		header("Location: index.php");
		exit;
	}
}
// screenごとの権限チェック不要

// 設定情報をSessionに格納
(function () {

	// 設定値取得
	$config = new Config();
	$rtnset = $config->list();
	foreach ($rtnset ?? [] as $i => $wk) {
		if (!is_null($wk["value_str"]) && !empty($wk["value_str"])) {
			SessionHelper::setPref($wk["config_key"], $wk["value_str"]);
		} else {
			SessionHelper::setPref($wk["config_key"], $wk["value_int"]);
		}
	}
})();

(function () {
	$masterMap = SessionHelper::getMaster() ?? [];
	$requiredKeys = ['makerList', 'categoryList', 'unitList', 'locationList'];
	$missingKeys = array_filter($requiredKeys, fn($key) => !array_key_exists($key, $masterMap) || $masterMap[$key] === null);

	if (!empty($missingKeys)) {

		if (in_array('makerList', $missingKeys, true)) {
			$maker = new Maker();
			$masterMap['makerList'] = $maker->list();
		}
		if (in_array('categoryList', $missingKeys, true)) {
			$category = new Category();
			$masterMap['categoryList'] = $category->list();
		}
		if (in_array('unitList', $missingKeys, true)) {
			$unit = new Unit();
			$masterMap['unitList'] = $unit->list();
		}
		if (in_array('locationList', $missingKeys, true)) {
			$location = new Location();
			$masterMap['locationList'] = $location->list();
		}

		sessionHelper::setMaster($masterMap);
	}
})();

$bcin = trim($_POST['barcode'] ?? '');
if ($bcin !== '') {
	$csrfScope = (string)($_POST[Utility::getCsrfScopeFieldName()] ?? '');
	$csrfToken = (string)($_POST[Utility::getCsrfFieldName()] ?? '');
	if (!Utility::validatePostedCsrfToken($csrfScope, $csrfToken)) {
		$logger->error(basename(__FILE__).' op=barcode msg="Invalid csrf token" page=menu.php user_id='.$auth->getCurrentUser()?->getUserId());
		SessionHelper::flushError(MessageConst::MSG_SYS_COMMON_900);
		header("Location: menu.php");
		exit;
	}

	// バーコードメニュー指定時にリダイレクトする
    $router = new MenuRouter($auth);
    $target = $router->resolve($bcin);

    if ($target !== null) {
        header("Location: {$target}");
        exit;
    }

	SessionHelper::flushError(MessageConst::MSG_SYS_MENU_002);
}

// 自画面のセッションを削除
SessionHelper::clearData();

?>

<!DOCTYPE html>
<html lang="ja">
<head>
	<?php $title = "トップメニュー"; require_once(__DIR__."/inc_head.php"); ?>
	<script src="js/BarcodeInput.js"></script>
	<link rel="stylesheet" href="css/menu.css">
</head>
<body class="maintenance">
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
			<div><?=MessageConst::MSG_INF_MENU_001 ?></div>
			<button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
		</div>

		<!-- バーコード入力 -->
		<div id="barcode" class="py-2">
			<form method="POST" action="menu.php" class="d-flex justify-content-center">
				<?= Utility::renderCsrfHiddenInput('menu.barcode') ?>
				<button class="btn btn-outline"><i class="bi bi-upc-scan"></i></button>
				<input type="text" class="form-control" name="barcode" maxlength="50" autocomplete="off" style="width: unset;"/>
			</form>
		</div>

		<!-- メニュー -->
		<div id="menu-container" class="d-flex justify-content-center align-items-stretch flex-wrap py-2">


		<?php if ($auth->getCurrentUser()?->can("product_show")) { ?>

    <a class="btn btn-product m-2 d-flex justify-content-between align-items-center" href="product_show.php" role="button">
      <div>
        <svg xmlns="http://www.w3.org/2000/svg" width="72" height="72" fill="#578C78" class="bi bi-box-seam" viewBox="0 0 16 16">
          <path d="M8.186 1.113a.5.5 0 0 0-.372 0L1.846 3.5l2.404.961L10.404 2zm3.564 1.426L5.596 5 8 5.961 14.154 3.5zm3.25 1.7-6.5 2.6v7.922l6.5-2.6V4.24zM7.5 14.762V6.838L1 4.239v7.923zM7.443.184a1.5 1.5 0 0 1 1.114 0l7.129 2.852A.5.5 0 0 1 16 3.5v8.662a1 1 0 0 1-.629.928l-7.185 2.874a.5.5 0 0 1-.372 0L.63 13.09a1 1 0 0 1-.63-.928V3.5a.5.5 0 0 1 .314-.464z"/>
        </svg>
      </div>
      <div class="flex-grow-1">
        <h4 class="card-title">商品表示</h4>
        <p class="card-text">商品の詳細を表示します。</p>
      </div>
    </a>
		<?php } ?>
		<?php if ($auth->getCurrentUser()?->can("product_list")) { ?>
			<a class="btn btn-product m-2 d-flex justify-content-between align-items-center" href="product_list.php" role="button">
      <div>
<svg xmlns="http://www.w3.org/2000/svg" width="72" height="72" fill="#578C78" class="bi bi-card-checklist" viewBox="0 0 16 16">
  <path d="M14.5 3a.5.5 0 0 1 .5.5v9a.5.5 0 0 1-.5.5h-13a.5.5 0 0 1-.5-.5v-9a.5.5 0 0 1 .5-.5zm-13-1A1.5 1.5 0 0 0 0 3.5v9A1.5 1.5 0 0 0 1.5 14h13a1.5 1.5 0 0 0 1.5-1.5v-9A1.5 1.5 0 0 0 14.5 2z"/>
  <path d="M7 5.5a.5.5 0 0 1 .5-.5h5a.5.5 0 0 1 0 1h-5a.5.5 0 0 1-.5-.5m-1.496-.854a.5.5 0 0 1 0 .708l-1.5 1.5a.5.5 0 0 1-.708 0l-.5-.5a.5.5 0 1 1 .708-.708l.146.147 1.146-1.147a.5.5 0 0 1 .708 0M7 9.5a.5.5 0 0 1 .5-.5h5a.5.5 0 0 1 0 1h-5a.5.5 0 0 1-.5-.5m-1.496-.854a.5.5 0 0 1 0 .708l-1.5 1.5a.5.5 0 0 1-.708 0l-.5-.5a.5.5 0 0 1 .708-.708l.146.147 1.146-1.147a.5.5 0 0 1 .708 0"/>
</svg>
      </div>
      <div class="flex-grow-1">
        <h4 class="card-title">商品一覧</h4>
        <p class="card-text">商品の一覧を表示します。</p>
      </div>
			</a>
		<?php } ?>
		<?php if ($auth->getCurrentUser()?->can("product_per_location")) { ?>
			<a class="btn btn-product m-2 d-flex justify-content-between align-items-center" href="product_per_location.php" role="button">
      <div>
        <svg xmlns="http://www.w3.org/2000/svg" width="72" height="72" fill="#578C78" class="bi bi-shop" viewBox="0 0 16 16">
         <path d="M2.97 1.35A1 1 0 0 1 3.73 1h8.54a1 1 0 0 1 .76.35l2.609 3.044A1.5 1.5 0 0 1 16 5.37v.255a2.375 2.375 0 0 1-4.25 1.458A2.37 2.37 0 0 1 9.875 8 2.37 2.37 0 0 1 8 7.083 2.37 2.37 0 0 1 6.125 8a2.37 2.37 0 0 1-1.875-.917A2.375 2.375 0 0 1 0 5.625V5.37a1.5 1.5 0 0 1 .361-.976zm1.78 4.275a1.375 1.375 0 0 0 2.75 0 .5.5 0 0 1 1 0 1.375 1.375 0 0 0 2.75 0 .5.5 0 0 1 1 0 1.375 1.375 0 1 0 2.75 0V5.37a.5.5 0 0 0-.12-.325L12.27 2H3.73L1.12 5.045A.5.5 0 0 0 1 5.37v.255a1.375 1.375 0 0 0 2.75 0 .5.5 0 0 1 1 0M1.5 8.5A.5.5 0 0 1 2 9v6h1v-5a1 1 0 0 1 1-1h3a1 1 0 0 1 1 1v5h6V9a.5.5 0 0 1 1 0v6h.5a.5.5 0 0 1 0 1H.5a.5.5 0 0 1 0-1H1V9a.5.5 0 0 1 .5-.5M4 15h3v-5H4zm5-5a1 1 0 0 1 1-1h2a1 1 0 0 1 1 1v3a1 1 0 0 1-1 1h-2a1 1 0 0 1-1-1zm3 0h-2v3h2z"/>
        </svg>
      </div>
      <div class="flex-grow-1">
        <h4 class="card-title">店舗別商品一覧</h4>
        <p class="card-text">店舗別の商品一覧を表示します。</p>
      </div>
			</a>
		<?php } ?>
		<?php if ($auth->getCurrentUser()?->can("stock_in")) { ?>
			<a class="btn btn-stock m-2 d-flex justify-content-between align-items-center" href="stock_in.php" role="button">
      <div>
<svg xmlns="http://www.w3.org/2000/svg" width="72" height="72" viewBox="0 0 16 16" fill="#6E8C69">
  <g transform="translate(3 0) scale(0.6)" >
    <path d="M2.95.4a1 1 0 0 1 .8-.4h8.5a1 1 0 0 1 .8.4l2.85 3.8a.5.5 0 0 1 .1.3V15a1 1 0 0 1-1 1H1a1 1 0 0 1-1-1V4.5a.5.5 0 0 1 .1-.3zM7.5 1H3.75L1.5 4h6zm1 0v3h6l-2.25-3zM15 5H1v10h14z"/>
  </g>

  <g transform="translate(0 2.2)">
    <path fill-rule="evenodd" d="M8 4a.5.5 0 0 1 .5.5v5.793l2.146-2.147a.5.5 0 0 1 .708.708l-3 3a.5.5 0 0 1-.708 0l-3-3a.5.5 0 1 1 .708-.708L7.5 10.293V4.5A.5.5 0 0 1 8 4"/>
  </g>
</svg>



      </div>
      <div class="flex-grow-1">
        <h4 class="card-title">商品入庫</h4>
        <p class="card-text">店舗を指定して商品の入庫処理を行います。</p>
      </div>
			</a>
		<?php } ?>
		<?php if ($auth->getCurrentUser()?->can("stock_out")) { ?>
			<a class="btn btn-stock m-2 d-flex justify-content-between align-items-center" href="stock_out.php" role="button">
      <div>
<svg xmlns="http://www.w3.org/2000/svg" width="72" height="72" viewBox="0 0 16 16" fill="#6E8C69">
  <g transform="translate(3 6) scale(0.6)" >
    <path d="M2.95.4a1 1 0 0 1 .8-.4h8.5a1 1 0 0 1 .8.4l2.85 3.8a.5.5 0 0 1 .1.3V15a1 1 0 0 1-1 1H1a1 1 0 0 1-1-1V4.5a.5.5 0 0 1 .1-.3zM7.5 1H3.75L1.5 4h6zm1 0v3h6l-2.25-3zM15 5H1v10h14z"/>
  </g>

  <g transform="translate(0 -3)">
  <path fill-rule="evenodd" d="M8 12a.5.5 0 0 0 .5-.5V5.707l2.146 2.147a.5.5 0 0 0 .708-.708l-3-3a.5.5 0 0 0-.708 0l-3 3a.5.5 0 1 0 .708.708L7.5 5.707V11.5a.5.5 0 0 0 .5.5"/>

  </g>
</svg>

      </div>
      <div class="flex-grow-1">
        <h4 class="card-title">商品出庫</h4>
        <p class="card-text">店舗を指定して商品の出庫処理を行います。</p>
      </div>
			</a>
		<?php } ?>
		<?php if ($auth->getCurrentUser()?->can("stock_move")) { ?>
			<a class="btn btn-stock m-2 d-flex justify-content-between align-items-center" href="stock_move.php" role="button">
      <div>
<svg xmlns="http://www.w3.org/2000/svg" width="72" height="72" viewBox="0 0 16 16" fill="#6E8C69">
  <g transform="translate(3 3) scale(0.6)" >
    <path d="M2.95.4a1 1 0 0 1 .8-.4h8.5a1 1 0 0 1 .8.4l2.85 3.8a.5.5 0 0 1 .1.3V15a1 1 0 0 1-1 1H1a1 1 0 0 1-1-1V4.5a.5.5 0 0 1 .1-.3zM7.5 1H3.75L1.5 4h6zm1 0v3h6l-2.25-3zM15 5H1v10h14z"/>
  </g>

  <g transform="translate(-4.5 -2) scale(1.2)">
<path fill-rule="evenodd" d="M12 8a.5.5 0 0 1-.5.5H5.707l2.147 2.146a.5.5 0 0 1-.708.708l-3-3a.5.5 0 0 1 0-.708l3-3a.5.5 0 1 1 .708.708L5.707 7.5H11.5a.5.5 0 0 1 .5.5"/>
  </g>

  <g transform="translate(1.5 1) scale(1.2)">
<path fill-rule="evenodd" d="M4 8a.5.5 0 0 1 .5-.5h5.793L8.146 5.354a.5.5 0 1 1 .708-.708l3 3a.5.5 0 0 1 0 .708l-3 3a.5.5 0 0 1-.708-.708L10.293 8.5H4.5A.5.5 0 0 1 4 8"/>
  </g>


</svg>

      </div>
      <div class="flex-grow-1">
        <h4 class="card-title">商品移動</h4>
        <p class="card-text">店舗間で商品の移動を行います。</p>
      </div>
			</a>
		<?php } ?>
		<?php if ($auth->getCurrentUser()?->can("barcode_create")) { ?>
			<a class="btn btn-barcode m-2 d-flex justify-content-between align-items-center" href="barcode_create.php" role="button">
      <div>
<svg xmlns="http://www.w3.org/2000/svg" width="72" height="72" viewBox="0 0 16 16" fill="#BEA05A" aria-label="barcode icon" role="img">
  <g>
    <rect x="1.2"  y="2.0" width="0.6" height="12.6" rx="0.1"/>
    <rect x="2.2"  y="2.0" width="0.2" height="12.6"/>
    <rect x="2.8"  y="2.0" width="0.8" height="10.6" rx="0.1"/>
    <rect x="3.9"  y="2.0" width="0.3" height="10.6"/>
    <rect x="4.5"  y="2.0" width="0.3" height="10.6"/>
    <rect x="5.2"  y="2.0" width="1.1" height="10.6" rx="0.1"/>
    <rect x="6.6"  y="2.0" width="0.2" height="10.6"/>
    <rect x="7.1"  y="2.0" width="0.7" height="10.6" rx="0.1"/>
    <rect x="8.1"  y="2.0" width="0.3" height="10.6"/>
    <rect x="8.7"  y="2.0" width="1.0" height="10.6" rx="0.1"/>
    <rect x="10.0" y="2.0" width="0.2" height="10.6"/>
    <rect x="10.5" y="2.0" width="0.8" height="10.6" rx="0.1"/>
    <rect x="11.6" y="2.0" width="0.3" height="12.6"/>
    <rect x="12.2" y="2.0" width="1.2" height="12.6" rx="0.1"/>
    <rect x="13.7" y="2.0" width="0.2" height="12.6"/>

<text x="7" y="14.5" width="14.0" text-anchor="middle" font-size="2">
  barcode
</text>

  </g>
</svg>

      </div>
      <div class="flex-grow-1">
        <h4 class="card-title">バーコード生成</h4>
        <p class="card-text">バーコードを生成します。</p>
      </div>
			</a>
		<?php } ?>
		<?php if ($auth->getCurrentUser()?->can("barcode_bulk_list")) { ?>
			<a class="btn btn-barcode m-2 d-flex justify-content-between align-items-center" href="barcode_bulk_list.php" role="button">
      <div>
<svg xmlns="http://www.w3.org/2000/svg" width="72" height="72" fill="#BEA05A" viewBox="0 0 16 16" aria-label="files with barcode" role="img">
  <!-- 外枠（ファイル） -->
  <path d="M13 0H6a2 2 0 0 0-2 2 2 2 0 0 0-2 2v10a2 2 0 0 0 2 2h7a2 2 0 0 0 2-2 2 2 0 0 0 2-2V2a2 2 0 0 0-2-2m0 13V4a2 2 0 0 0-2-2H5a1 1 0 0 1 1-1h7a1 1 0 0 1 1 1v10a1 1 0 0 1-1 1M3 4a1 1 0 0 1 1-1h7a1 1 0 0 1 1 1v10a1 1 0 0 1-1 1H4a1 1 0 0 1-1-1z"/>

  <!-- 中身（バーコード）: 前面の紙の中に入るように配置 -->
  <g transform="translate(4.15 5.1) scale(0.50)">
    <!-- バー本体 -->
    <rect x="0.0"  y="0.0" width="0.7" height="11.0" rx="0.12"/>
    <rect x="1.1"  y="0.0" width="0.25" height="11.0"/>
    <rect x="1.7"  y="0.0" width="0.9" height="11.0" rx="0.12"/>
    <rect x="2.9"  y="0.0" width="0.35" height="11.0"/>
    <rect x="3.6"  y="0.0" width="0.35" height="11.0"/>
    <rect x="4.3"  y="0.0" width="1.1" height="11.0" rx="0.12"/>
    <rect x="5.7"  y="0.0" width="0.25" height="11.0"/>
    <rect x="6.2"  y="0.0" width="0.75" height="11.0" rx="0.12"/>
    <rect x="7.2"  y="0.0" width="0.35" height="11.0"/>
    <rect x="7.8"  y="0.0" width="1.05" height="11.0" rx="0.12"/>
    <rect x="9.2"  y="0.0" width="0.25" height="11.0"/>
    <rect x="9.7"  y="0.0" width="0.9" height="11.0" rx="0.12"/>
    <rect x="10.9" y="0.0" width="0.35" height="11.0"/>
    <rect x="11.5" y="0.0" width="1.25" height="11.0" rx="0.12"/>
    <rect x="13.1" y="0.0" width="0.25" height="11.0"/>

    <!-- 下の文字 -->
    <text x="6.7" y="13.1" text-anchor="middle"
          font-size="2.2">barcode</text>
  </g>
</svg>

      </div>
      <div class="flex-grow-1">
        <h4 class="card-title">バーコード一括出力</h4>
        <p class="card-text">Excelファイルを読込み、バーコードの一括出力を行います。</p>
      </div>
			</a>
		<?php } ?>
		<?php if ($auth->getCurrentUser()?->can("product_check")) { ?>
			<a class="btn btn-product m-2 d-flex justify-content-between align-items-center" href="product_check.php" role="button">
      <div>
<svg xmlns="http://www.w3.org/2000/svg" width="72" height="72" fill="#578C78" class="bi bi-clipboard-check" viewBox="0 0 16 16">
  <path fill-rule="evenodd" d="M10.854 7.146a.5.5 0 0 1 0 .708l-3 3a.5.5 0 0 1-.708 0l-1.5-1.5a.5.5 0 1 1 .708-.708L7.5 9.793l2.646-2.647a.5.5 0 0 1 .708 0"/>
  <path d="M4 1.5H3a2 2 0 0 0-2 2V14a2 2 0 0 0 2 2h10a2 2 0 0 0 2-2V3.5a2 2 0 0 0-2-2h-1v1h1a1 1 0 0 1 1 1V14a1 1 0 0 1-1 1H3a1 1 0 0 1-1-1V3.5a1 1 0 0 1 1-1h1z"/>
  <path d="M9.5 1a.5.5 0 0 1 .5.5v1a.5.5 0 0 1-.5.5h-3a.5.5 0 0 1-.5-.5v-1a.5.5 0 0 1 .5-.5zm-3-1A1.5 1.5 0 0 0 5 1.5v1A1.5 1.5 0 0 0 6.5 4h3A1.5 1.5 0 0 0 11 2.5v-1A1.5 1.5 0 0 0 9.5 0z"/>
</svg>
      </div>
      <div class="flex-grow-1">
        <h4 class="card-title">在庫チェック</h4>
        <p class="card-text">システムで管理している在庫数と実際の在庫数を比較チェックします。</p>
      </div>
			</a>
		<?php } ?>
		<?php if ($auth->getCurrentUser()?->can("sales_list")) { ?>
			<a class="btn btn-history m-2 d-flex justify-content-between align-items-center" href="sales_list.php" role="button">
      <div>
<svg xmlns="http://www.w3.org/2000/svg" width="72" height="72" fill="#7891A0" class="bi bi-clock-history" viewBox="0 0 16 16">
  <path d="M8.515 1.019A7 7 0 0 0 8 1V0a8 8 0 0 1 .589.022zm2.004.45a7 7 0 0 0-.985-.299l.219-.976q.576.129 1.126.342zm1.37.71a7 7 0 0 0-.439-.27l.493-.87a8 8 0 0 1 .979.654l-.615.789a7 7 0 0 0-.418-.302zm1.834 1.79a7 7 0 0 0-.653-.796l.724-.69q.406.429.747.91zm.744 1.352a7 7 0 0 0-.214-.468l.893-.45a8 8 0 0 1 .45 1.088l-.95.313a7 7 0 0 0-.179-.483m.53 2.507a7 7 0 0 0-.1-1.025l.985-.17q.1.58.116 1.17zm-.131 1.538q.05-.254.081-.51l.993.123a8 8 0 0 1-.23 1.155l-.964-.267q.069-.247.12-.501m-.952 2.379q.276-.436.486-.908l.914.405q-.24.54-.555 1.038zm-.964 1.205q.183-.183.35-.378l.758.653a8 8 0 0 1-.401.432z"/>
  <path d="M8 1a7 7 0 1 0 4.95 11.95l.707.707A8.001 8.001 0 1 1 8 0z"/>
  <path d="M7.5 3a.5.5 0 0 1 .5.5v5.21l3.248 1.856a.5.5 0 0 1-.496.868l-3.5-2A.5.5 0 0 1 7 9V3.5a.5.5 0 0 1 .5-.5"/>
</svg>
      </div>
      <div class="flex-grow-1">
        <h4 class="card-title">実績一覧</h4>
        <p class="card-text">入出庫の実績を一覧表示します。</p>
      </div>
			</a>
		<?php } ?>
		<?php if ($auth->getCurrentUser()?->can("history_list")) { ?>
			<a class="btn btn-history m-2 d-flex justify-content-between align-items-center" href="history_list.php" role="button">
      <div>
<svg xmlns="http://www.w3.org/2000/svg" width="72" height="72" fill="#7891A0" class="bi bi-clock-history" viewBox="0 0 16 16">
  <path d="M8.515 1.019A7 7 0 0 0 8 1V0a8 8 0 0 1 .589.022zm2.004.45a7 7 0 0 0-.985-.299l.219-.976q.576.129 1.126.342zm1.37.71a7 7 0 0 0-.439-.27l.493-.87a8 8 0 0 1 .979.654l-.615.789a7 7 0 0 0-.418-.302zm1.834 1.79a7 7 0 0 0-.653-.796l.724-.69q.406.429.747.91zm.744 1.352a7 7 0 0 0-.214-.468l.893-.45a8 8 0 0 1 .45 1.088l-.95.313a7 7 0 0 0-.179-.483m.53 2.507a7 7 0 0 0-.1-1.025l.985-.17q.1.58.116 1.17zm-.131 1.538q.05-.254.081-.51l.993.123a8 8 0 0 1-.23 1.155l-.964-.267q.069-.247.12-.501m-.952 2.379q.276-.436.486-.908l.914.405q-.24.54-.555 1.038zm-.964 1.205q.183-.183.35-.378l.758.653a8 8 0 0 1-.401.432z"/>
  <path d="M8 1a7 7 0 1 0 4.95 11.95l.707.707A8.001 8.001 0 1 1 8 0z"/>
  <path d="M7.5 3a.5.5 0 0 1 .5.5v5.21l3.248 1.856a.5.5 0 0 1-.496.868l-3.5-2A.5.5 0 0 1 7 9V3.5a.5.5 0 0 1 .5-.5"/>
</svg>
      </div>
      <div class="flex-grow-1">
        <h4 class="card-title">履歴一覧</h4>
        <p class="card-text">入出庫履歴および商品情報の更新履歴を一覧表示します。</p>
      </div>
			</a>
		<?php } ?>
		<?php if ($auth->getCurrentUser()?->can("menu_master")) { ?>
			<a class="btn btn-maintenance m-2 d-flex justify-content-between align-items-center" href="menu_master.php" role="button">
      <div>
<svg xmlns="http://www.w3.org/2000/svg" width="72" height="72" fill="#3C4B69" class="bi bi-gear" viewBox="0 0 16 16">
  <path d="M8 4.754a3.246 3.246 0 1 0 0 6.492 3.246 3.246 0 0 0 0-6.492M5.754 8a2.246 2.246 0 1 1 4.492 0 2.246 2.246 0 0 1-4.492 0"/>
  <path d="M9.796 1.343c-.527-1.79-3.065-1.79-3.592 0l-.094.319a.873.873 0 0 1-1.255.52l-.292-.16c-1.64-.892-3.433.902-2.54 2.541l.159.292a.873.873 0 0 1-.52 1.255l-.319.094c-1.79.527-1.79 3.065 0 3.592l.319.094a.873.873 0 0 1 .52 1.255l-.16.292c-.892 1.64.901 3.434 2.541 2.54l.292-.159a.873.873 0 0 1 1.255.52l.094.319c.527 1.79 3.065 1.79 3.592 0l.094-.319a.873.873 0 0 1 1.255-.52l.292.16c1.64.893 3.434-.902 2.54-2.541l-.159-.292a.873.873 0 0 1 .52-1.255l.319-.094c1.79-.527 1.79-3.065 0-3.592l-.319-.094a.873.873 0 0 1-.52-1.255l.16-.292c.893-1.64-.902-3.433-2.541-2.54l-.292.159a.873.873 0 0 1-1.255-.52zm-2.633.283c.246-.835 1.428-.835 1.674 0l.094.319a1.873 1.873 0 0 0 2.693 1.115l.291-.16c.764-.415 1.6.42 1.184 1.185l-.159.292a1.873 1.873 0 0 0 1.116 2.692l.318.094c.835.246.835 1.428 0 1.674l-.319.094a1.873 1.873 0 0 0-1.115 2.693l.16.291c.415.764-.42 1.6-1.185 1.184l-.291-.159a1.873 1.873 0 0 0-2.693 1.116l-.094.318c-.246.835-1.428.835-1.674 0l-.094-.319a1.873 1.873 0 0 0-2.692-1.115l-.292.16c-.764.415-1.6-.42-1.184-1.185l.159-.291A1.873 1.873 0 0 0 1.945 8.93l-.319-.094c-.835-.246-.835-1.428 0-1.674l.319-.094A1.873 1.873 0 0 0 3.06 4.377l-.16-.292c-.415-.764.42-1.6 1.185-1.184l.292.159a1.873 1.873 0 0 0 2.692-1.115z"/>
</svg>
      </div>
      <div class="flex-grow-1">
        <h4 class="card-title">マスタメンテナンス</h4>
        <p class="card-text">各マスタの登録及びバーコード生成を行います。</p>
      </div>
			</a>
		<?php } ?>
		</div>

	</main>
	<?php require_once(__DIR__."/inc_footer.php"); ?>
</body>
</html>
