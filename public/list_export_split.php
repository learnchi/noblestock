<?php
/**
 * 分割ファイル出力をするための選択画面 (汎用)
 */
@session_start();
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
    $logger->error(basename(__FILE__).' checkUserSession failed for user id id='.$auth->getCurrentUser()?->getUserId());
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
// $funcId = "bizxxx";

// 遷移元画面名と対応するfuncIdのマップ
$splitFuncIds = [
    'history_list' => 'biz305',
    'product_check' => 'biz601',
    'product_list' => 'biz002',
    'product_per_location' => 'biz009',
    'sales_list' => 'biz301',
    'sales_show' => 'biz302',
];

$backTo = $_GET['fn'] ?? '';
if ($backTo === '' || !isset($splitFuncIds[$backTo])) {
    http_response_code(400);
    header('Content-Type: text/plain; charset=UTF-8');
    echo MessageConst::MSG_VAL_FILE_018;
    exit;
}

$funcId = $splitFuncIds[$backTo];
$excelName = $backTo . "_" . date("Ymd") . "-";
$fid = $backTo."_export";    // 対応するExcel出力処理名

// 最大ページ数取得
$listCnt = (int) (SessionHelper::getData($funcId, "listCnt") ?? 0);
$pageMax = 1;
if ($listCnt > LogicConst::PAGE_ITEM_EXCEL) {
    $pageMax = ceil($listCnt / LogicConst::PAGE_ITEM_EXCEL);
}

$excelExt = ".xls";
if (intval(SessionHelper::getPref("EXCEL_VAR")) === 1) {
    $excelExt = ".xlsx";
}
?>

<!DOCTYPE html>
<html lang="ja">
<head>
    <?php $title = "Excel出力"; require_once(__DIR__."/inc_head.php"); ?>
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
            <div><?=MessageConst::MSG_INF_FILE_014?></div>
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
                <?php
                for ($i = 1; $i <= $pageMax; $i++) {
                    $fileName = $excelName.$i.$excelExt;
                    $fromNo = LogicConst::PAGE_ITEM_EXCEL * ($i - 1) + 1;
                    $toNo = LogicConst::PAGE_ITEM_EXCEL * $i;
                    if ($i == $pageMax) {
                        $toNo = $listCnt;
                    }
                ?>
                    <tr>
                        <td><?=$fileName ?></td>
                        <td><?=$fromNo." ～ ".$toNo ?></td>
                        <td class="text-center">
                        <form method="POST" action="<?=Utility::h($fid) ?>.php">
                            <?= Utility::renderCsrfHiddenInput('list_export_split.export') ?>
                            <input type="hidden" name="listPage" value="<?=$i ?>">
                            <button type="submit" class="btn btn-primary" name="mode" value="export">Excel出力</button>
                        </form>
                        </td>
                    </tr>
                <?php
                }
                ?>
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
