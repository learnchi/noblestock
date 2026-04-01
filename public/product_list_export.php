<?php
/**
 * 商品一覧→Excel出力
 */
session_cache_limiter("none");
@session_start();
date_default_timezone_set('Asia/Tokyo');


// composerを使用
require_once(__DIR__ . '/../vendor/autoload.php');
use Studiogau\Chandra\Logging\Logger;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Cell\DataType;
use Studiogau\Chandra\Support\SessionHelper;
use Studiogau\Chandra\Support\Utility;
use Studiogau\Chandra\Auth\AuthService;
use Noblestock\Logic\LogicConst;
use Noblestock\DbLogic\UserRepository;
use Noblestock\DbLogic\Product;
use Noblestock\DbLogic\Stock;
use Noblestock\Util\UtilExcel;
use Noblestock\Logic\MessageConst;

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
	http_response_code(405);
	header('Allow: POST');
	header('Content-Type: text/plain; charset=UTF-8');
	echo MessageConst::MSG_VAL_FILE_017;
	exit;
}
if (($_POST['mode'] ?? '') !== 'export') {
	http_response_code(400);
	header('Content-Type: text/plain; charset=UTF-8');
	echo MessageConst::MSG_VAL_FILE_018;
	exit;
}

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

// 実行時間
set_time_limit(LogicConst::RUN_TIME_LIMIT);

// メモリアップ
ini_set("memory_limit",LogicConst::MEMORY_LIMIT);

// セッション管理ID
$csrfScope = (string)($_POST[Utility::getCsrfScopeFieldName()] ?? '');
$csrfToken = (string)($_POST[Utility::getCsrfFieldName()] ?? '');
if (!Utility::validatePostedCsrfToken($csrfScope, $csrfToken)) {
	$logger->error(basename(__FILE__).' op=csrf.validate msg="Invalid csrf token" page='.$filename.' user_id='.$auth->getCurrentUser()?->getUserId());
	SessionHelper::FlushError(MessageConst::MSG_SYS_COMMON_900);
	header("Location: product_list.php");
	exit;
}

$funcId = "biz002";

$itemlimit = 0;
$listPage = 0;
$listCnt = 0;
$searchCondition = SessionHelper::getData($funcId, 'searchCondition');
$pgsort = SessionHelper::getData($funcId, "pgsort");
$excelName = "product_list_".date("Ymd");

if (isset($_POST["listPage"])) {
	$itemlimit = LogicConst::PAGE_ITEM_EXCEL;
	$listPage = $_POST["listPage"];
	$listCnt = SessionHelper::getData($funcId, "listCnt");
	$excelName .= "-".$listPage;
}

// 商品一覧
$product = new Product();
$prret = $product->list($searchCondition, $pgsort, $itemlimit, $listPage, $listCnt);
$cnt = count($prret ?? []);

// 在庫数ロジック
$stock = new Stock();

// 0件の場合にサンプルを出力
if ($cnt < 1) {
	$prret = array();
	$prret[] = array("management_no" => "A12345", "category_id" => "1", "category_name" => "", "maker_id" => "1", "maker_name" => "", "product_name" => "商品サンプル01", "wholesale_amount" => "12500", "retail_amount" => "15000", "sell_amount" => "10000", "quantity" => "", "unit_id" => "1", "storage_place" => "保管場所01", "image_file" => "sample01.jpg", "remarks" => "本データはサンプルです。\n行を削除するか編集してご使用ください。", "remarks2" => "備考02");
	$cnt = count($prret);
}

// Excelバージョン
$excelVar = "Xls";
$excelExt = ".xls";
$excelCon = 'Content-Type: application/vnd.ms-excel';
if (intval(SessionHelper::getPref("EXCEL_VAR")) === 1) {
	$excelVar = "Xlsx";
	$excelExt = ".xlsx";
	$excelCon = 'Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet';
}

// テンプレート
$tmpfile = __DIR__.'/../'.LogicConst::DIR_FILES.'/productlist'.$excelExt;

// PhpSpreadsheetオブジェクト生成
$phpSpreadsheet = new Spreadsheet();

// テンプレート読込
$objReader = IOFactory::createReader($excelVar);
$phpSpreadsheet = $objReader->load($tmpfile);
$phpSpreadsheet->setActiveSheetIndex(0);
$sheet = $phpSpreadsheet->getActiveSheet();

// スタイルコピー
for ($i = 1; $i < $cnt; $i++) {
	$no = $i + 1;
	$no2 = $i + 2;

	for ($char = ord('A'); $char <= ord('Q'); $char++) {
		$style = $sheet->getStyle(chr($char).$no);
		$sheet->duplicateStyle($style, chr($char).$no2);
	}
}

// 一覧セット
for ($i = 0; $i < $cnt; $i++) {
	$wk = $prret[$i];
	$no = $i + 2;

	// 店舗在庫数取得
	$locationStock = "";
	$locationStock = $stock->getStockPerLocation($wk['management_no']);

	$sheet->setCellValueExplicit("A".$no, $wk['management_no'], DataType::TYPE_STRING);  // セルを文字列に
	$sheet->setCellValue("B".$no, $wk['category_id']);
	UtilExcel::setCellValue($sheet, "C".$no, $wk['category_name']);
	$sheet->setCellValue("D".$no, $wk['maker_id']);
	UtilExcel::setCellValue($sheet, "E".$no, $wk['maker_name']);
	UtilExcel::setCellValue($sheet, "F".$no, $wk['product_name']);
	$sheet->setCellValue("G".$no, $wk['wholesale_amount']);
	$sheet->setCellValue("H".$no, $wk['retail_amount']);
	$sheet->setCellValue("I".$no, $wk['sell_amount']);
	$sheet->setCellValue("J".$no, $wk['quantity']);
	$sheet->setCellValue("K".$no, $wk['unit_id']);
	UtilExcel::setCellValue($sheet, "L".$no, $wk['unit_name']);
	UtilExcel::setCellValue($sheet, "M".$no, $wk['storage_place']);
	UtilExcel::setCellValue($sheet, "N".$no, $locationStock);
	UtilExcel::setCellValue($sheet, "O".$no, $wk['image_file']);
	UtilExcel::setCellValue($sheet, "P".$no, $wk['remarks']);
	UtilExcel::setCellValue($sheet, "Q".$no, $wk['remarks2']);
	// $sheet->getRowDimension($no)->setRowHeight();  // 行高さ自動調整
}

// 出力
$writerType = 'Xls';
$contentType = 'application/vnd.ms-excel';
$ext = '.xls';
if (intval(SessionHelper::getPref('EXCEL_VAR')) === 1) {
    $writerType = 'Xlsx';
    $contentType = 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet';
    $ext = '.xlsx';
}

// 余計な出力が混ざらないようにバッファをクリア
if (ob_get_length()) {
    ob_end_clean();
}
header('Content-Type: ' . $contentType);
header('Content-Disposition: attachment;filename="' . $excelName . $ext . '"');
header('Cache-Control: max-age=0');
$writer = IOFactory::createWriter($phpSpreadsheet, $writerType);
$writer->save('php://output');
exit;
