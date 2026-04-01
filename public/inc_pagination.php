<?php
if (realpath(__FILE__) === realpath($_SERVER['SCRIPT_FILENAME'])) {
	// 直接指定での実行はできない
    http_response_code(404);
    exit;
}
?>

<?php 
// $pagination_action ページ遷移時のアクション
// $pgcnt 現在のページ番号
// $pgsort 現在のソート順
// $listCnt 総件数

use Studiogau\Chandra\Support\Utility;
use Noblestock\Logic\LogicConst;
use Noblestock\Logic\MessageConst;

?>
<!-- ページネーション -->
<nav aria-label="Page navigation" class="d-flex align-items-center my-2">
    <ul class="pagination m-1">
        <?php
        if ($listCnt > 0) {
            $pglast = ceil($listCnt / LogicConst::PAGE_ITEM);
            if ($pglast > 1) {
                if ($pgcnt > 1) {
        ?>
        <li class="page-item disabled">
            <form method="GET" action="<?= Utility::h($pagination_action) ?>" >
                <input type="hidden" name="s" value="<?= Utility::h($pgsort) ?>">
                <input type="hidden" name="p" value="<?= Utility::h($pgcnt-1) ?>">
                <input type="hidden" name="pos" value="0">
                <button class="page-link btn" type="submit" aria-label="Previous">
                    <span aria-hidden="true">&laquo;</span>
                </button>
            </form>
        </li>
        <?php
                } else {
        ?>
        <li class="page-item disabled">
            <a class="page-link">&laquo;</a>
        </li>
        <?php
                }
                $pgst = 1;
                $pgen = 10;
                if ($pglast <= $pgen) {
                    $pgen = $pglast;
                } else {
                    if ($pgcnt > 5) {
                        $pgst = $pgcnt - 4;
                        if ($pgst > ($pglast - 9)) $pgst = $pglast - 9;
                        $pgen = $pgcnt + 5;
                        if ($pgen > $pglast) $pgen = $pglast;
                    }
                }
                for($i = $pgst; $i <= $pgen; $i++) {
                    if ($pgcnt == $i) {
        ?>
        <li class="page-item active" aria-current="page">
            <a class="page-link" href="#"><?=Utility::h($i)?></a>
        </li>

        <?php
                    } else {
        ?>
        <li class="page-item">
            <form method="GET" action="<?= Utility::h($pagination_action) ?>">
                <input type="hidden" name="s" value="<?= Utility::h($pgsort) ?>">
                <input type="hidden" name="p" value="<?= Utility::h($i) ?>">
                <input type="hidden" name="pos" value="0">   
                <button class="page-link btn" type="submit" ><?=Utility::h($i)?></button>
            </form>
        </li>
        <?php
                    }
                }

                if ($pgcnt < $pglast) {
        ?>
        <li class="page-item ">
            <form method="GET" action="<?= Utility::h($pagination_action) ?>">
                <input type="hidden" name="s" value="<?= Utility::h($pgsort) ?>">
                <input type="hidden" name="p" value="<?= Utility::h($pgcnt+1) ?>">
                <input type="hidden" name="pos" value="0"> 
                <button class="page-link" type="submit" aria-label="Next">
                    <span aria-hidden="true">&raquo;</span>
                </button>
            </form>
        </li>
        <?php
                } else {
        ?>
        <li class="page-item disabled">
            <a class="page-link" href="#" aria-label="Next">
                <span aria-hidden="true">&raquo;</span>
            </a>
        </li>
        <?php
                }
            }
        }
        ?>
    </ul>
    <p class="m-1"><?= Utility::h(Utility::replaceStr(MessageConst::MSG_OK_LIST_002, $listCnt)) ?></p>
</nav>
<!-- ページネーション ここまで -->
