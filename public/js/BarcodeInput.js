/**
 * JavaScript ユーティリティ バーコード入力
 * BarcodeInput
 * 
 * 使い方
 * 
 * <?php
 * $bcin = trim($_POST['barcode'] ?? '');
 * if ($bcin !== '') {
 * 	// バーコードメニュー指定時にリダイレクトする
 *     $router = new MenuRouter($auth);
 *     $target = $router->resolve($bcin);
 * 
 *     if ($target !== null) {
 *         header("Location: {$target}", true, 303);
 *         exit;
 *     }
 * 
 * }
 * 
 * ?>
 * 
 * 	<!-- バーコード入力 -->
 *	<div id="barcode" class="py-2">
 * 		<form method="POST" action="<?= $filename ?>.php" class="d-flex justify-content-center">
 * 			<button class="btn btn-outline-dark"><i class="bi bi-upc-scan"></i></button>
 * 			<input type="text" class="form-control" name="barcode" maxlength="50" autocomplete="off" style="width: unset;"/>
 * 		</form>
 * 	</div>
 * 
 * @author Studio GAU
 * @version 1.0
 */
document.addEventListener('DOMContentLoaded', () => {

	// 画面表示時、バーコード入力にフォーカス
	const barcodeInputElement = document.querySelector('#barcode input[name="barcode"]');
	if (barcodeInputElement) barcodeInputElement.focus();
	// FreezeScreenOff();

	const barcodeButtonElement = document.querySelector('#barcode button');
	if (!barcodeInputElement || !barcodeButtonElement) return;
	barcodeButtonElement.addEventListener('click', (e) => {
		// e.preventDefault(); // もしボタンでPOST検索しない場合はコメントを外す
		barcodeInputElement.focus();
	});
	
	// focus
	barcodeInputElement.addEventListener('focus', () => {
		barcodeInputElement.style.background = 'var(--bs-body-bg)';
		barcodeButtonElement.innerHTML = '<i class="bi bi-upc-scan"></i>';
	});

	// blur
	barcodeInputElement.addEventListener('blur', (e) => {
		if (e.relatedTarget === barcodeButtonElement) {
			return;
		}
		barcodeInputElement.style.background = 'var(--bs-secondary-bg)';
		barcodeButtonElement.innerHTML = '<i class="bi bi-upc"></i>';
	});

	// keydown (Enter)
	barcodeInputElement.addEventListener('keydown', (e) => {
		if (e.key === 'Enter') {
			e.preventDefault(); // return false 相当
			barcodeInputElement.form.submit();
		}
	});
});
