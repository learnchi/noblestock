/**
 * JavaScript ユーティリティ
 * scrollPosition
 * 
 * あらかじめ渡されたscrollPosがある場合、画面表示時にその箇所までスクロールする
 * 使い方：このjsファイルを読み込む前に、scrollPos変数を宣言する
 * 画面側HTML記述例：
 * <script>const scrollPos= <?= (int)($scrollPos ?? 0) ?>;</script>
 * <script src="js/scrollPosition.js"></script>
 * @author Studio GAU
 * @version 1.0
 */


document.addEventListener('DOMContentLoaded', () => {

    const tableWrapper = document.getElementById('table-wrapper');
    if (tableWrapper) {
        tableWrapper.scrollTo({
            top: scrollPos,
            behavior: 'smooth' // なめらかスクロール
        });

        // フォームには、hidden inputのscrollPosフィールドを用意する
        // POST前に、submit時点でのスクロール位置を代入する。
        document.addEventListener('submit', (e) => {
            const form = e.target;
            const scrollPosInput = form.querySelector('input[name="pos"]');
            if (scrollPosInput) {
                if (scrollPosInput.value === "") {
                    scrollPosInput.value = Math.round(tableWrapper.scrollTop);
                }
            }
        });
    }


});

