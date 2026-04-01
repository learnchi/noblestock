<?php
if (realpath(__FILE__) === realpath($_SERVER['SCRIPT_FILENAME'])) {
	// 直接指定での実行はできない
    http_response_code(404);
    exit;
}
?>
<?php
/**
 * バーコード選択出力→リスト出力
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

// 実行時間
set_time_limit(LogicConst::RUN_TIME_LIMIT);

// メモリアップ
ini_set("memory_limit",LogicConst::MEMORY_LIMIT);

$csrfScope = (string)($_POST[Utility::getCsrfScopeFieldName()] ?? '');
$csrfToken = (string)($_POST[Utility::getCsrfFieldName()] ?? '');
if (!Utility::validatePostedCsrfToken($csrfScope, $csrfToken)) {
    $logger->error(basename(__FILE__).' op=csrf.validate msg="Invalid csrf token" page='.$filename.' user_id='.$auth->getCurrentUser()?->getUserId());
    http_response_code(400);
    header('Content-Type: text/plain; charset=UTF-8');
    echo MessageConst::MSG_VAL_FILE_018;
    exit;
}

// セッション管理ID biz008: バーコード選択出力
$funcId = "biz008";

$barproduct_list = SessionHelper::getData($funcId, "barproduct_list");
$barlist = array();
$seqNo = 0;
foreach ($barproduct_list ?? [] as $i => $wk) {
	$out_num = $_POST["OUT_NUM_".$i];
	if (!is_numeric($out_num)) $out_num = 1;
	if ($out_num < 1) $out_num = 0;
	if ($out_num > 0) {
		for ($j = 0; $j < $out_num; $j++) {
			$seqNo++;
			$barlist[] = array("SEQ" => $seqNo, "management_no" => $wk["management_no"]);
		}
	}
}
unset($barproduct_list);
$cnt = count($barlist);

$excelName = "barcodeList_".date("Ymd");

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
$tmpfile = __DIR__.'/../'.LogicConst::DIR_FILES.'/barlist'.$excelExt;

// PhpSpreadsheetオブジェクト生成
$phpExcel = new Spreadsheet();

// テンプレート読込
$objReader = IOFactory::createReader($excelVar);
$phpExcel = $objReader->load($tmpfile);
$phpExcel->setActiveSheetIndex(0);
$sheet = $phpExcel->getActiveSheet();

// スタイルコピー
for ($i = 1; $i < $cnt; $i++) {
	$no = $i + 1;
	$no2 = $i + 2;

	for ($char = ord('A'); $char <= ord('B'); $char++) {
		$style = $sheet->getStyle(chr($char).$no);
		$sheet->duplicateStyle($style, chr($char).$no2);
	}
}

// 一覧セット
for ($i = 0; $i < $cnt; $i++) {
	$wk = $barlist[$i];
	$no = $i + 2;

	$sheet->setCellValue("A".$no, $wk['SEQ']);
	$sheet->setCellValueExplicit("B".$no, $wk['management_no'], DataType::TYPE_STRING);  // セルを文字列に

	// $sheet->getRowDimension($no)->setRowHeight(-1);  // 行高さ自動調整
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
$writer = IOFactory::createWriter($phpExcel, $writerType);
$writer->save('php://output');
exit;
?>
