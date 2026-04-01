/**
 * JavaScript 画像プレビュー
 *
 * @author Studio GAU
 * @version 1.0
 */
document.addEventListener('DOMContentLoaded', () => {

    const xOffset = 30;
    const yOffset = -14;

    document.querySelectorAll('a.imgprev').forEach(link => {

        let imgEl = null;

        link.addEventListener('mouseenter', (e) => {
            const imgSrc = link.dataset.img; // ← data-img 取得
            if (!imgSrc) return;

            imgEl = document.createElement('img');
            imgEl.className = 'screenimg';
            imgEl.src = imgSrc;
            imgEl.style.position = 'absolute';
            imgEl.style.pointerEvents = 'none';
            imgEl.style.display = 'block';
            imgEl.style.opacity = '0'; // フェード用
            document.body.appendChild(imgEl);

            const h = imgEl.height;
            imgEl.style.top  = (e.pageY - yOffset - h) + 'px';
            imgEl.style.left = (e.pageX + xOffset) + 'px';

            // フェードイン（任意）
            requestAnimationFrame(() => {
                imgEl.style.transition = 'opacity 0.15s';
                imgEl.style.opacity = '1';
            });
        });

        link.addEventListener('mouseleave', () => {
            if (imgEl) {
                imgEl.remove();
                imgEl = null;
            }
        });

        link.addEventListener('mousemove', (e) => {
            if (imgEl) {
                const h = imgEl.height;
                imgEl.style.top  = (e.pageY - yOffset - h) + 'px';
                imgEl.style.left = (e.pageX + xOffset) + 'px';
            }
        });

    });
});