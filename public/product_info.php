<?php
/**
 * 商品詳細情報を表示するtable
 * $productDataが必要です。
 * <?php require_once(__DIR__."/mdl_imageviewer.php"); ?> が必要です。
 */

if (realpath(__FILE__) === realpath($_SERVER['SCRIPT_FILENAME'])) {
	// 直接指定での実行はできない
    http_response_code(404);
    exit;
}

use Studiogau\Chandra\Support\Utility;
use Noblestock\Logic\LogicConst;
?>

<div class="d-flex flex-lg-row flex-column-reverse w-100"><!-- lg幅で横並び、それ以下では画像が商品情報の上に表示される -->
	<div class="flex-grow-1"><!-- 商品情報 -->
		<table class="table table-sm table-responsive">
			<tbody>
			<?php if (!empty($productData['shop_name'])) : ?>
				<tr>
					<th scope="row">店舗名</th>
					<td><?=Utility::h($productData['shop_name']) ?></td>
				</tr>
			<?php endif; ?>
			<?php if (!empty($productData['management_no'])) : ?>
				<tr>
					<th scope="row">管理番号</th>
						<td><?=Utility::h($productData['management_no']) ?></td>
				</tr>
			<?php endif; ?>
			<?php if (!empty($productData['category_name'])) : ?>
				<tr>
					<th scope="row">カテゴリ</th>
					<td><?=Utility::h($productData['category_name']) ?></td>
				</tr>
			<?php endif; ?>
			<?php if (!empty($productData['maker_name'])) : ?>
				<tr>
					<th scope="row">メーカー</th>
					<td><?=Utility::h($productData['maker_name']) ?></td>
				</tr>
			<?php endif; ?>
			<?php if (!empty($productData['product_name'])) : ?>
				<tr>
					<th scope="row">商品名</th>
					<td><?=Utility::h($productData['product_name']) ?></td>
				</tr>
			<?php endif; ?>
			<?php if (array_key_exists('wholesale_amount', $productData) && $productData['wholesale_amount'] !== null && $productData['wholesale_amount'] !== "") : ?>
				<tr>
					<th scope="row">卸価格</th>
					<td><?=number_format($productData['wholesale_amount']) ?></td>
				</tr>
			<?php endif; ?>
			<?php if (array_key_exists('retail_amount', $productData) && $productData['retail_amount'] !== null && $productData['retail_amount'] !== "") : ?>
				<tr>
					<th scope="row">小売価格</th>
					<td><?=number_format($productData['retail_amount']) ?></td>
				</tr>
			<?php endif; ?>
			<?php if (array_key_exists('sell_amount', $productData) && $productData['sell_amount'] !== null && $productData['sell_amount'] !== "") : ?>
				<tr>
					<th scope="row">仕入原価</th>
					<td><?=number_format($productData['sell_amount']) ?></td>
				</tr>
			<?php endif; ?>
			<?php if (array_key_exists('quantity', $productData) && $productData['quantity'] !== null && $productData['quantity'] !== "") : ?>
				<tr>
					<th scope="row">在庫数</th>
					<td><?=number_format($productData['quantity']) ?><?=Utility::h($productData['unit_name']) ?></td>
				</tr>
			<?php endif; ?>
			<?php if (!empty($productData['storage_place'])): ?>
				<tr>
					<th scope="row">保管場所</th>
					<td><?=Utility::h($productData['storage_place']) ?></td>
				</tr>
			<?php endif; ?>
			<?php if (!empty($productData['SHOP_QUANTITY'])): ?>
				<tr>
					<th scope="row">店舗在庫数</th>
					<td><?=Utility::h($productData['SHOP_QUANTITY']) ?></td>
				</tr>
			<?php endif; ?>
			<?php if (!empty($productData['remarks'])): ?>
				<tr>
					<th scope="row">備考</th>
					<td><?=nl2br(Utility::h($productData['remarks'])) ?></td>
				</tr>
			<?php endif; ?>
			<?php if (!empty($productData['remarks2'])): ?>
				<tr>
					<th scope="row">備考２</th>
					<td><?=nl2br(Utility::h($productData['remarks2'])) ?></td>
				</tr>
			<?php endif; ?>
			</tbody>
		</table>
	</div><!-- 商品情報ここまで -->

	<div class=""><!-- 画像 -->
		<?php
			$imgFile = $productData['image_file']?? '';
			if (Utility::checkImageCreate(__DIR__."/".LogicConst::DIR_IMAGES."/", $imgFile, 240)):
		?>
			<a href="<?=Utility::h(LogicConst::DIR_IMAGES.'/'.$imgFile) ?>" data-bs-toggle="modal" data-bs-target="#imageViewer" data-bs-image="<?=Utility::h(LogicConst::DIR_IMAGES.'/'.$imgFile) ?>">
				<img src="<?=Utility::h(LogicConst::DIR_IMAGES.'/s_'.$imgFile) ?>" class="img-thumbnail" />
			</a>
		<?php endif; ?>
	</div><!-- 画像ここまで -->
</div>
