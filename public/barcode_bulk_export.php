<?php
/**
 * バーコードExcel一括出力
 */
session_cache_limiter("none");
session_start();
date_default_timezone_set('Asia/Tokyo');
// composerを使用
require_once(__DIR__ . '/../vendor/autoload.php');
use Studiogau\Chandra\Logging\Logger;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Cell\DataType;
use PhpOffice\PhpSpreadsheet\Worksheet\Drawing;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;
use Studiogau\Chandra\Support\Utility;
use Studiogau\Chandra\Support\SessionHelper;
use Studiogau\Chandra\Auth\AuthService;
use Noblestock\Logic\LogicConst;
use Noblestock\Logic\BarcodeGenerator;
use Noblestock\DbLogic\UserRepository;
use Noblestock\DbLogic\User;
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
	$logger->error(basename(__FILE__)." checkUserSession failed for user id id=".$auth->getCurrentUser()?->getLoginId());
	if (session_status() !== PHP_SESSION_ACTIVE) {
		session_start();
	}
	SessionHelper::FlushError(MessageConst::MSG_INF_AUTH_002);
	// チェック結果がエラーの場合ログイン画面に遷移
	header("Location: index.php", true, 303);
	exit;
}
// screenごとの権限チェック不要

// 実行時間
set_time_limit(LogicConst::RUN_TIME_LIMIT);

// メモリアップ
ini_set("memory_limit",LogicConst::MEMORY_LIMIT);

// セッション管理ID バーコード出力
$funcId = "com901";

$csrfScope = (string)($_POST[Utility::getCsrfScopeFieldName()] ?? '');
$csrfToken = (string)($_POST[Utility::getCsrfFieldName()] ?? '');
if (!Utility::validatePostedCsrfToken($csrfScope, $csrfToken)) {
	$logger->error(basename(__FILE__).' op=csrf.validate msg="Invalid csrf token" user_id='.$auth->getCurrentUser()?->getLoginId());
	SessionHelper::FlushError(MessageConst::MSG_SYS_COMMON_900);
	$backTo = SessionHelper::getData("com901", "backTo");
	if ($backTo === null || $backTo === '') {
		$barFile = SessionHelper::getData("com901", "barFile");
		if ($barFile === 'product') {
			$backTo = 'product_barcode_list';
		} else if ($barFile === 'menu' || $barFile === 'shop') {
			$backTo = 'master_bulk_edit';
		} else {
			$backTo = 'barcode_create';
		}
	}
	header("Location: ".$backTo.".php", true, 303);
	exit;
}

$settingExcelVar = intval(SessionHelper::getPref("EXCEL_VAR"));

// ラベルサイズ
// $settingBarSize = intval(SessionHelper::getPref("BAR_PRT_SIZE"));

$dirImg = __DIR__."/".LogicConst::DIR_IMAGES."/";
$dirTmp = __DIR__."/../".LogicConst::DIR_TMP."/";
$dirFile = __DIR__."/../".LogicConst::DIR_FILES."/";

// 設定取得
$settingBarSize = SessionHelper::getData($funcId, "barSize");
$fileName =  SessionHelper::getData($funcId, "barFile");
$fileName .= "_barcode_".date("Ymd");

// ユーザー情報取得
$user = new User();
$currentLoginId = $auth->getCurrentUser()?->getLoginId();
$currentUserId = $currentLoginId === null ? null : $user->getIdByUserId($currentLoginId);
if ($currentUserId === null) {
	$logger->error(
		basename(__FILE__)
		. ' op=user.lookup msg="current user not found"'
		. ' login_id=' . ($currentLoginId ?? 'null')
	);
	$auth->logout();
	if (session_status() !== PHP_SESSION_ACTIVE) {
		session_start();
	}
	SessionHelper::FlushError(MessageConst::MSG_INF_AUTH_002);
	header("Location: index.php", true, 303);
	exit;
}
$userSeq = "u".$currentUserId."_";

// バーコード一覧取得
$barlist = SessionHelper::getData($funcId, "barlist");
$barcnt = count($barlist ?? []);

// 分割出力
if (isset($_POST["listPage"])) {
	$listPage = $_POST["listPage"];
	$sliceSt = LogicConst::BARCODE_ITEM_EXCEL * ($listPage - 1);
	$barlist = array_slice(($barlist ?? []), $sliceSt, LogicConst::BARCODE_ITEM_EXCEL);
	$barcnt = count($barlist);
	$fileName .= "-".$listPage;
}

// バーコード生成
$barcode = new BarcodeGenerator();
for ($i = 0; $i < $barcnt; $i++) {
	$wk = $barlist[$i];

	// バーコード画像生成
	if ($wk["bar_no"] != "") {
		$barcode->createBarcode($wk["bar_no"], $dirTmp.$userSeq.'bar'.$i.'.png', $settingBarSize);
	}

	// 商品縮小画像生成
	if ($settingBarSize === 3) {
		if ($wk['img_file'] != null && $wk['img_file'] != "") {
			$targetFile = $dirImg.$wk['img_file'];
			$createFile = $dirTmp.$userSeq."s_product".$i.".png";
			$imgSize = 40;
			if (!Utility::creatImageSize34($targetFile, $createFile, $imgSize)) {
				if (file_exists($createFile)) {
					unlink($createFile);
				}
			}
		}
	}
}

// Excelバージョン
$excelVar = "Xls";
$excelExt = ".xls";
$excelCon = 'Content-Type: application/vnd.ms-excel';
if ($settingExcelVar === 1) {
	$excelVar = "Xlsx";
	$excelExt = ".xlsx";
	$excelCon = 'Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet';
}

// 70.0mm×33.9mm 24面 Code39
$tmpfile = $dirFile.'bar'.$excelExt;  // テンプレートファイル
$excelX = 'C';  // 最終セル
$excelXnum = 3;  // 列数
$excelY = 8;  // 行数
$selW = 264;  // セル幅 277px 画像位置X補正用
$offXA = 0;  // セルAバーコード画像位置X補正
$offXB = 4;  // セルBバーコード画像位置X補正
$offXC = 8;  // セルCバーコード画像位置X補正
$offY = 16;  // バーコード画像位置Y
$selwA = 34.62;  // セルA幅 34.00 + 0.62
$selwB = 34.62;  // セルB幅 34.00 + 0.62
$selwC = 34.62;  // セルC幅 34.00 + 0.62
$selh1 = 21.75;  // セル高さ（上段）商品項目
$selh2 = 62.25;  // セル高さ（中段）バーコード画像
$selh3 = 21.75;  // セル高さ（下段）商品項目
if ($settingExcelVar === 1) {
	// Xlsx以降
	$selW = 274;  // セル幅 277px 画像位置X補正用
	$offXA = 0;  // セルAバーコード画像位置X補正
	$offXB = 0;  // セルBバーコード画像位置X補正
	$offXC = 0;  // セルCバーコード画像位置X補正
}

if ($settingBarSize === 2) {
	// 70.0mm×33.9mm 24面 Code128
	$tmpfile = $dirFile.'bar'.$excelExt;
	$excelX = 'C';
	$excelXnum = 3;
	$excelY = 8;
	$selW = 264;  // セル幅 277px 画像位置X補正用
	$offXA = 0;  // セルAバーコード画像位置X補正
	$offXB = 4;  // セルBバーコード画像位置X補正
	$offXC = 8;  // セルCバーコード画像位置X補正
	$offY = 16;
	$selwA = 34.62;  // セルA幅 34.00 + 0.62
	$selwB = 34.62;  // セルB幅 34.00 + 0.62
	$selwC = 34.62;  // セルC幅 34.00 + 0.62
	$selh1 = 21.75;
	$selh2 = 62.25;
	$selh3 = 21.75;
	if ($settingExcelVar === 1) {
		$selW = 274;  // セル幅 277px 画像位置X補正用
		$offXA = 0;  // セルAバーコード画像位置X補正
		$offXB = 0;  // セルBバーコード画像位置X補正
		$offXC = 0;  // セルCバーコード画像位置X補正
	}
}
if ($settingBarSize === 3) {
	// 70.0mm×33.9mm 24面 Code128 画像
	$tmpfile = $dirFile.'bar'.$excelExt;
	$excelX = 'C';
	$excelXnum = 3;
	$excelY = 8;
	$selW = 264;  // セル幅 277px 画像位置X補正用
	$offXA = 0;  // セルAバーコード画像位置X補正
	$offXB = 4;  // セルBバーコード画像位置X補正
	$offXC = 8;  // セルCバーコード画像位置X補正
	$offY = 16;
	$selwA = 34.62;  // セルA幅 34.00 + 0.62
	$selwB = 34.62;  // セルB幅 34.00 + 0.62
	$selwC = 34.62;  // セルC幅 34.00 + 0.62
	$selh1 = 21.75;
	$selh2 = 62.25;
	$selh3 = 21.75;
	if ($settingExcelVar === 1) {
		$selW = 274;  // セル幅 277px 画像位置X補正用
		$offXA = 0;  // セルAバーコード画像位置X補正
		$offXB = 0;  // セルBバーコード画像位置X補正
		$offXC = 0;  // セルCバーコード画像位置X補正
	}
}
if ($settingBarSize === 4) {
	// 48.3mm×25.4mm 44面 Code128
	$tmpfile = $dirFile.'bar8'.$excelExt;
	$excelX = 'D';
	$excelXnum = 4;
	$excelY = 11;
	$selW = 199;  // セル幅 199px 画像位置X補正用
	$offXA = 0;  // セルAバーコード画像位置X補正
	$offXB = 0;  // セルBバーコード画像位置X補正
	$offXC = 0;  // セルCバーコード画像位置X補正
	$offXD = 0;  // セルDバーコード画像位置X補正
	$offY = 9;
	$selwA = 24.87;  // セルA幅 24.25 + 0.62
	$selwB = 24.87;  // セルB幅 24.25 + 0.62
	$selwC = 24.87;  // セルC幅 24.25 + 0.62
	$selwD = 24.87;  // セルD幅 24.25 + 0.62
	$selh1 = 17.25;
	$selh2 = 45.75;
	$selh3 = 16.5;
	if ($settingExcelVar === 1) {
		$selW = 199;  // セル幅 199px 画像位置X補正用
		$offXA = 0;  // セルAバーコード画像位置X補正
		$offXB = 0;  // セルBバーコード画像位置X補正
		$offXC = 0;  // セルCバーコード画像位置X補正
		$offXD = 0;  // セルDバーコード画像位置X補正
	}
}
if ($settingBarSize === 5) {
	// 38.1mm×21.2mm 65面 Code128
	$tmpfile = $dirFile.'bar2'.$excelExt;
	$excelX = 'E';
	$excelXnum = 5;
	$excelY = 13;
	$selW = 162;  // セル幅 164px 167px 167px 167px 164px 画像位置X補正用
	$offXA = 0;  // セルAバーコード画像位置X補正
	$offXB = 0;  // セルBバーコード画像位置X補正
	$offXC = 0;  // セルCバーコード画像位置X補正
	$offXD = 0;  // セルDバーコード画像位置X補正
	$offXE = 0;  // セルEバーコード画像位置X補正
	$offY = 4;
	$selwA = 20.51;  // セルA幅 19.88 + 0.63
	$selwB = 20.87;  // セルB幅 20.25 + 0.62
	$selwC = 20.87;  // セルC幅 20.25 + 0.62
	$selwD = 20.87;  // セルD幅 20.25 + 0.62
	$selwE = 20.51;  // セルE幅 19.88 + 0.63
	$selh1 = 14.25;
	$selh2 = 38.25;
	$selh3 = 14.25;
	if ($settingExcelVar === 1) {
		$selW = 168;  // セル幅 164px 167px 167px 167px 164px 画像位置X補正用
		$offXA = 0;  // セルAバーコード画像位置X補正
		$offXB = 0;  // セルBバーコード画像位置X補正
		$offXC = 0;  // セルCバーコード画像位置X補正
		$offXD = 0;  // セルDバーコード画像位置X補正
		$offXE = 0;  // セルEバーコード画像位置X補正
	}
}

$rowcnt = ceil(ceil($barcnt / $excelXnum) / $excelY) * $excelY;

// テンプレート読込
$objReader = IOFactory::createReader($excelVar);
$phpExcel = $objReader->load($tmpfile);
$phpExcel->setActiveSheetIndex(0);
$sheet = $phpExcel->getActiveSheet();
$sheet->getPageSetup()->setPrintArea('A1:'.$excelX.($rowcnt * 3));

// 印刷範囲追加設定
for ($i = $excelY; $i < $rowcnt; $i+=$excelY) {
	$sheet->setBreak('A'.($i * 3), Worksheet::BREAK_ROW);
}

// スタイルコピー
$k = 1;
for ($i = 1; $i < $rowcnt; $i++) {
	for ($j = 0; $j < 3; $j++) {
		for ($char = ord('A'); $char <= ord($excelX); $char++) {
			$style = $sheet->getStyle(chr($char).$k);
			$sheet->duplicateStyle($style, chr($char).($k + 3));
			// セル幅設定
			$selwid = "selw".chr($char);
			$sheet->getColumnDimension(chr($char))->setWidth($$selwid);
		}

		// セル高さ設定
		if ($j === 0) $rh = $selh1;
		if ($j === 1) $rh = $selh2;
		if ($j === 2) $rh = $selh3;
		$sheet->getRowDimension($k + 3)->setRowHeight($rh);

		$k++;
	}
}

// バーコードセット
$hcnt = 1;
$bcnt = 0;
for ($i = 1; $i <= $rowcnt; $i++) {
	for ($char = ord('A'); $char <= ord($excelX); $char++) {
		$seliti = chr($char);

		if ($bcnt < $barcnt) {
			$wk = $barlist[$bcnt];

			if ($wk["bar_no"] != "") {
				$bar_img = $dirTmp.$userSeq.'bar'.$bcnt.'.png';
				$bar_size = @getimagesize($bar_img);

				$delWidth = round(($bar_size[0] / 16 * 2), 2);
				$addHight = 0;
				// Xlsx以降の場合、補正しない
				if ($settingExcelVar === 1) {
					$delWidth = 0;
				}

				$product_img = $dirTmp.$userSeq."s_product".$bcnt.".png";
				$product_size = null;
				if (file_exists($product_img)) {
					$product_size = @getimagesize($product_img);
				}

				// テンプレートにバーコード画像張付
				$sheetdraw = new Drawing();
				$sheetdraw->setPath($bar_img);
				$sheetdraw->setResizeProportional(false);
				$sheetdraw->setWidth($bar_size[0] - $delWidth);
				$sheetdraw->setHeight($bar_size[1] + $addHight);
				$sheetdraw->setName('Barcode');
				$sheetdraw->setDescription('Barcode');
				$sheetdraw->setCoordinates($seliti.($hcnt + 1));
				$offX = round(($selW - $bar_size[0]) / 2);

				// セルバーコード画像位置X補正
				if (chr($char) === 'A') $offX += $offXA;
				if (chr($char) === 'B') $offX += $offXB;
				if (chr($char) === 'C') $offX += $offXC;
				if (chr($char) === 'D') $offX += $offXD;
				if (chr($char) === 'E') $offX += $offXE;
				if (($settingBarSize === 1 || $settingBarSize === 2 || $settingBarSize === 3) && $settingExcelVar === 1 && $bar_size[0] > 200) {
					if (chr($char) === 'A') $offX -= 11;
					if (chr($char) === 'B') $offX -= 6;
				}
				if ($settingBarSize === 3 && $wk["img_file"] != "" && $product_size != null) {
					// 商品縮小画像がある場合
					if (chr($char) === 'A') $offX += 15;
					if (chr($char) === 'B') $offX += 11;
					if (chr($char) === 'C') $offX += 10;
				}

				$sheetdraw->setOffsetX($offX);
				$sheetdraw->setOffsetY($offY);
				$sheetdraw->setWorksheet($phpExcel->getActiveSheet());

				// テンプレートに商品縮小画像張り付け
				if ($settingBarSize === 3 && $wk["img_file"] != "" && $product_size != null) {
					$addimgW = -5;
					$addimgH = 0;
					if ($settingExcelVar === 1) {
						$addimgW = 0;
						$addimgH = 0;
					}
					$sheetdraw = new Drawing();
					$sheetdraw->setPath($product_img);
					$sheetdraw->setResizeProportional(false);
					$sheetdraw->setWidth($product_size[0] + $addimgW);
					$sheetdraw->setHeight($product_size[1] + $addimgH);
					$sheetdraw->setName('Product');
					$sheetdraw->setDescription('Product');
					$sheetdraw->setCoordinates($seliti.($hcnt + 1));
					$sheetdraw->setOffsetX(10);
					$sheetdraw->setOffsetY($offY);
					$sheetdraw->setWorksheet($phpExcel->getActiveSheet());
				}
			} else {
				$sheet->setCellValueExplicit($seliti.($hcnt + 1), "", DataType::TYPE_STRING);
			}

			$bartop = $wk["bar_top"];
			$barbtm = $wk["bar_btm"];
			$sheet->setCellValueExplicit($seliti.$hcnt, $bartop, DataType::TYPE_STRING);
			$sheet->setCellValueExplicit($seliti.($hcnt + 2), $barbtm, DataType::TYPE_STRING);

			// 文字サイズ変更
			if ($settingBarSize === 1 || $settingBarSize === 2 || $settingBarSize === 3) {
				$len = strlen(mb_convert_encoding($bartop, 'SJIS', 'UTF-8'));
				if ($len > 30) {
					$fontsize = 10;
					if ($len > 40) $fontsize = 8;
					if ($len > 50) $fontsize = 6;
					$sheet->getStyle($seliti.$hcnt)->getFont()->setSize($fontsize);
				}
				$len = strlen(mb_convert_encoding($barbtm, 'SJIS', 'UTF-8'));
				if ($len > 30) {
					$fontsize = 10;
					if ($len > 40) $fontsize = 8;
					if ($len > 50) $fontsize = 6;
					$sheet->getStyle($seliti.($hcnt + 2))->getFont()->setSize($fontsize);
				}
			}
			if ($settingBarSize === 4) {
				$len = strlen(mb_convert_encoding($bartop, 'SJIS', 'UTF-8'));
				if ($len > 26) {
					$fontsize = 8;
					if ($len > 36) $fontsize = 6;
					$sheet->getStyle($seliti.$hcnt)->getFont()->setSize($fontsize);
				}
				$len = strlen(mb_convert_encoding($barbtm, 'SJIS', 'UTF-8'));
				if ($len > 25) {
					$fontsize = 8;
					if ($len > 35) $fontsize = 6;
					$sheet->getStyle($seliti.($hcnt + 2))->getFont()->setSize($fontsize);
				}
			}
			if ($settingBarSize === 5) {
				$len = strlen(mb_convert_encoding($bartop, 'SJIS', 'UTF-8'));
				if ($len > 20) {
					$fontsize = 8;
					if ($len > 30) $fontsize = 6;
					$sheet->getStyle($seliti.$hcnt)->getFont()->setSize($fontsize);
				}
				$len = strlen(mb_convert_encoding($barbtm, 'SJIS', 'UTF-8'));
				if ($len > 20) {
					$fontsize = 8;
					if ($len > 30) $fontsize = 6;
					$sheet->getStyle($seliti.($hcnt + 2))->getFont()->setSize($fontsize);
				}
			}
		}
		$bcnt++;
	}
	$hcnt+=3;
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
header('Content-Disposition: attachment;filename="' . $fileName . $ext . '"');
header('Cache-Control: max-age=0');
$writer = IOFactory::createWriter($phpExcel, $writerType);
$writer->save('php://output');
exit;
?>
