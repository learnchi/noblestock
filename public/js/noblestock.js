/**
 * JavaScript ユーティリティ
 *
 * @author Studio GAU
 * @version 1.0
 */

document.addEventListener('DOMContentLoaded', () => {

    /** submit時に確認メッセージを表示し、OKの場合のみsubmit
     * 使い方
     * <form method="post" action="XXX.php"
        class="js-confirm"
        data-confirm="この画像を削除しても元に戻せません。よろしいですか？">
     */
    document.querySelectorAll('form.js-confirm').forEach(form => {
        form.addEventListener('submit', e => {
            const msg = form.dataset.confirm || '実行しますか？';
            if (!confirm(msg)) e.preventDefault();
        });
    });

});
