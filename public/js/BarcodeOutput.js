/**
 * JavaScript ユーティリティ
 * バーコード商品選択出力
 * BarcodeOutput.js
 * @author Studio GAU
 * @version 1.0
 */


document.addEventListener('DOMContentLoaded', () => {

    const csrfJsonClient = window.ChandraCsrfFetch?.createJsonClient({
        csrfContainerId: 'barcode-output-csrf',
    });

    async function postBarcodeOutput(payload) {
        if (!csrfJsonClient) {
            throw new Error('ChandraCsrfFetch is not available.');
        }

        return csrfJsonClient.postJson('./api/BarcodeOutput.php', payload);
    }

    /**
     * バーコード出力対象商品の選択状態
     * 「全解除」「全選択」ボタンの押下イベント
     */
    document.addEventListener('click', async (e) => {
        /* -----------------------------
        * 全解除
        * ----------------------------- */
        const clearBtn = e.target.closest('#btnClearSelect');
        if (clearBtn) {
            try {
                const json = await postBarcodeOutput({ mode: 'clearSelect' });

                if (json.ok && typeof json.count !== 'undefined') {
                    // 「ﾊﾞｰｺｰﾄﾞ出力」ボタンが押せるかどうかの判定
                    updateBarcodeButtonState(json.count);
                }
                if (!json.ok) {
                    console.error('clearSelect failed', json);
                    return;
                }

                // UI反映：全チェック解除
                document.querySelectorAll('.js-product-check').forEach(cb => {
                    cb.checked = false;
                });

            } catch (err) {
                console.error('clearSelect ajax error', err);
            }
            return;
        }
        /* -----------------------------
         * 表示中だけ解除
         * ----------------------------- */
        const clearVisibleBtn = e.target.closest('#btnClearVisible');
        if (clearVisibleBtn) {
            const boxes = Array.from(document.querySelectorAll('.js-product-check'));
            const mngNos = boxes.map(cb => cb.dataset.mng || '').filter(v => v !== '');

            try {
                const json = await postBarcodeOutput({ mode: 'clearVisible', mngNos });

                if (json.ok && typeof json.count !== 'undefined') {
                    // 「ﾊﾞｰｺｰﾄﾞ出力」ボタンが押せるかどうかの判定
                    updateBarcodeButtonState(json.count);
                }
                if (!json.ok) {
                    console.error('clearVisible failed', json);
                    return;
                }

                // UI反映：今表示されているチェックだけ解除
                boxes.forEach(cb => { cb.checked = false; });

            } catch (err) {
                console.error('clearVisible ajax error', err);
            }
            return;
        }
        /* -----------------------------
        * 全選択
        * ----------------------------- */
        const selectBtn = e.target.closest('#btnSelectAll');
        if (selectBtn) {
            const boxes = Array.from(document.querySelectorAll('.js-product-check'));
            const mngNos = boxes.map(cb => cb.dataset.mng || '').filter(v => v !== '');

            try {
                const json = await postBarcodeOutput({ mode: 'selectAll', mngNos });


                if (json.ok && typeof json.count !== 'undefined') {
                    // 「ﾊﾞｰｺｰﾄﾞ出力」ボタンが押せるかどうかの判定
                    updateBarcodeButtonState(json.count);
                }
                if (!json.ok) {
                    console.error('selectAll failed', json);
                    return;
                }

                // UI反映：全チェックON
                boxes.forEach(cb => {
                    cb.checked = true;
                });

            } catch (err) {
                console.error('selectAll ajax error', err);
            }
            return;
        }
    });

    /**
     * バーコード出力対象商品の選択状態
     * 選択チェックボックスの変更イベント
     * 都度もとのphp画面を呼び出し、選択状態のセッション保持内容を変更する
     */
    document.addEventListener('change', async (e) => {
        const el = e.target;
        if (!(el instanceof HTMLInputElement)) return;
        if (!el.classList.contains('js-product-check')) return;

        // data-mng属性に入れたmanagemen_noを取得
        const mngNo = el.dataset.mng || '';
        const checked = el.checked;

        try {
            const json = await postBarcodeOutput({ mode: 'toggleSelect', mngNo, checked });

            if (json.ok && typeof json.count !== 'undefined') {
                // 「ﾊﾞｰｺｰﾄﾞ出力」ボタンが押せるかどうかの判定
                    updateBarcodeButtonState(json.count);
            }
            if (!json.ok) {
            console.error('save failed', json);
            }
        } catch (err) {
            console.error('ajax error', err);
        }

    });
    /**
     * 「ﾊﾞｰｺｰﾄﾞ出力」ボタンが押せるかどうかの判定
     */
    function updateBarcodeButtonState(count) {

        const btn = document.getElementById('btnBarcodeOutput');
        if (!btn) return;
        if (count <= 0) {
            btn.disabled = true;
        } else {
            btn.disabled = false;
        }
    }
});
