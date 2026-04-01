<?php
/**
 * MessageConst.php
 * 画面/ログ表示用メッセージ 定数クラス
 * MSG_{TYPE}_{DOMAIN}_{NNN} 形式で、DOMAINごとに連番
 * @author Studio GAU
 */
namespace Noblestock\Logic;

class MessageConst {

	/**
	 * 認証関連
	 */
	// const MSG_OK_AUTH_001  = "ログインしました ログインID：";
	const MSG_VAL_AUTH_001 = "ログインID、または、パスワードを正しく入力してください";
	// const MSG_INF_AUTH_001 = "ログインID、パスワードを入力してログインボタンをクリックしてください";
	const MSG_INF_AUTH_002 = "セッションの有効期限が切れました 再ログインしてください";
	const MSG_INF_AUTH_003 = "この画面を表示する権限がありません";
	// const MSG_INF_AUTH_002 = "ユーザー名：{0}";
	const MSG_INF_AUTH_004 = "パスワードを入力して変更ボタンを押してください（8～128文字）";
	const MSG_OK_AUTH_005  = "パスワードを変更しました 新しいパスワードでログインし直してください";
	const MSG_SYS_AUTH_006 = "パスワードを変更できませんでした";
	const MSG_CNF_AUTH_007 = "パスワードを変更してよろしいですか？";
	const MSG_INF_AUTH_008 = "現在のパスワードを入力してください";
	const MSG_INF_AUTH_009 = "新しいパスワードを入力してください";

	/**
	 * 共通
	 */

	const MSG_SYS_COMMON_900 = "システムエラーが発生しました";
	// const MSG_COM_002 = "入力エラー";　→　InvalidArgumentExceptionの場合はシステムエラーとする



	/**
	 * メニュー画面
	 */
	const MSG_INF_MENU_001 = "メニューを選択してください";
	const MSG_SYS_MENU_002 = "指定されたメニューが見つかりません";

	/**
	 * バーコード入力 共通
	 */
	
	const MSG_INF_BARCODE_001 = "バーコードを入力してください";
	const MSG_VAL_BARCODE_002 = "対象商品が存在しません";
	const MSG_INF_BARCODE_003 = "商品バーコードを入力してください";
	const MSG_INF_BARCODE_004 = "店舗バーコードを入力してください";
	const MSG_INF_BARCODE_005 = "数量バーコードを入力してください";
	const MSG_INF_BARCODE_006 = "商品バーコードか、完了バーコードを入力してください";
	const MSG_SYS_BARCODE_018 = "入力されたバーコードが正しくありません";
	
	/**
	 * バーコード入力 取消処理 在庫チェック
	 */

	const MSG_OK_BARCODE_007 = "{0}のチェック数を {1} に戻しました";
	const MSG_SYS_BARCODE_008 = "取消（元に戻す）処理が失敗しました";

	/**
	 * バーコード入力 入庫出庫移動 完了処理
	 */

	const MSG_VAL_BARCODE_009 = "在庫のない商品があります";	
	const MSG_VAL_BARCODE_010 = "在庫数が足りない商品があります";

	/**
	 * バーコード入力 店舗入力処理
	 */
	const MSG_VAL_BARCODE_011 = "店舗在庫がありません";
	const MSG_VAL_BARCODE_012 = "店舗が存在しません";

    /**
     * バーコード入力 商品コード入力処理 在庫チェック
     */
	const MSG_SYS_BARCODE_013 = "在庫チェック数加算処理が失敗しました";
	const MSG_INF_BARCODE_014 = "{0}（{1}）のチェック数が {2} になりました";
	const MSG_OK_BARCODE_015 = "{0}（{1}）在庫数({2})とチェック数({3})が一致しました";
	const MSG_SYS_BARCODE_016 = "{0}（{1}）チェック数({2})が在庫数({3})を超えました";
	const MSG_SYS_BARCODE_017 = "在庫チェック数加算後のチェックが失敗しました";

	/**
	 * 一覧表示処理
	 */
	const MSG_VAL_LIST_001 = "検索条件に該当するデータはありません";
	const MSG_OK_LIST_002 = "検索結果: {0}件";

	/**
	 * 共通登録・更新・削除
	 */
	const MSG_VAL_PRODUCT_003 = "必須入力です";
	const MSG_VAL_PRODUCT_004 = "数値を入力してください";
	const MSG_INF_PRODUCT_006 = "表示の内容で登録します 登録ボタンを押下してください";
	const MSG_INF_PRODUCT_007 = "表示の内容で更新します 更新ボタンを押下してください";
	const MSG_CNF_COMMON_014 = "登録してよろしいですか？";
	const MSG_CNF_COMMON_015 = "更新してよろしいですか？";
	const MSG_CNF_COMMON_016 = "削除してよろしいですか？";

	/**
	 * 商品登録・更新・削除
	 */
	const MSG_INF_PRODUCT_001 = "商品情報を入力して登録ボタンを押してください";
	const MSG_INF_PRODUCT_008 = "商品情報を編集して更新ボタンを押してください";
	const MSG_INF_PRODUCT_002 = "※管理番号はバーコード規格{0}に従って入力してください";
	const MSG_VAL_PRODUCT_005 = "入力された管理番号の商品が既に存在します";
	const MSG_SYS_PRODUCT_009 = "商品を取得できませんでした 管理番号：{0}";
	const MSG_OK_PRODUCT_010 = "商品を登録しました 管理番号：{0}";
	const MSG_OK_PRODUCT_011 = "商品を更新しました 管理番号：{0}";
	const MSG_OK_PRODUCT_012 = "商品を削除しました 管理番号：{0}";
	const MSG_SYS_PRODUCT_013 = "商品を削除できませんでした 管理番号：{0}";

	/**
	 * 在庫チェック
	 */
	const MSG_OK_CHECK_001 = "全商品の在庫チェック数をクリアしました";
	const MSG_SYS_CHECK_002 = "在庫チェック数のクリアが失敗しました";
	const MSG_OK_CHECK_003 = "在庫チェック数を更新しました 管理番号：{0} チェック数：{1}";
	const MSG_SYS_CHECK_004 = "在庫チェック数更新処理が失敗しました";
	const MSG_CNF_CHECK_005 = "全商品の在庫チェック数をクリアしてよろしいですか？";

	/**
	 * 実績
	 */
	const MSG_INF_SALES_001 = "管理番号：{0} 枝番：{1} の詳細情報を表示しています";

	/**
	 * 履歴
	 */
	const MSG_OK_HISTORY_001 = "履歴を更新しました 管理番号：{0} 枝番：{1}";
	const MSG_OK_HISTORY_002 = "履歴を削除しました 管理番号：{0} 枝番：{1} 日付：{2}";
	const MSG_INF_HISTORY_003 = "商品情報と履歴情報で在庫数が一致していません 商品情報：{0} 履歴情報：{1}";
	const MSG_SYS_HISTORY_004 = "履歴を更新できませんでした 管理番号：{0} 枝番：{1}";
	const MSG_SYS_HISTORY_005 = "履歴を削除できませんでした 管理番号：{0} 枝番：{1} 日付：{2}";

	/**
	 * マスター系
	 */

	const MSG_SYS_MASTER_001 = "対象のマスターデータを削除できませんでした";
	const MSG_SYS_MASTER_002 = "処理が失敗しました";
	const MSG_SYS_MASTER_003 = "店舗マスターデータが登録されていません";
	const MSG_OK_MASTER_004 = "マスターデータファイルをアップロードしました";
	const MSG_OK_MASTER_009 = "マスターデータファイルを登録しました";
	const MSG_INF_MASTER_005 = "操作するマスタを選択してください マスタを更新する場合はマスターデータファイルを選択して登録ボタンを押してください";
	const MSG_INF_MASTER_006 = "バーコードを出力できます";
	const MSG_INF_MASTER_007 = "マスタは {0} 件登録されています";
	const MSG_INF_MASTER_008 = "マスタは登録されていません";

	/**
	 * ファイル系共通
	 */
	const MSG_VAL_FILE_001 = "ファイルが選択されていません";

	/**
	 * 画像登録・更新・削除
	 */
	const MSG_INF_IMAGE_001 = "参照ボタンを押して画像を選択し、読込ボタンを押してください";
	const MSG_INF_IMAGE_002 = "アップロード済みの画像を使用する場合は、画像を選択してください";
	const MSG_OK_IMAGE_003 = "画像を登録しました";
	const MSG_OK_IMAGE_004 = "画像ファイルを削除しました ファイル名：{0}";
	const MSG_INF_IMAGE_005 = "画像ファイルが存在しません";
	const MSG_VAL_IMAGE_006 = "ファイルサイズが {0} を超えています";
	const MSG_VAL_IMAGE_007 = "ファイル名に無効な文字(半角英数字、ハイフン「-」、アンダースコア「_」以外)が含まれています";
	// const MSG_VAL_IMAGE_008
	const MSG_SYS_IMAGE_009 = "画像の登録に失敗しました";
	const MSG_SYS_IMAGE_010 = "画像ファイルの削除に失敗しました ファイル名：{0}";
	const MSG_SYS_IMAGE_011 = "サムネイル画像の削除に失敗しました ファイル名：{0}";

	/**
	 * ファイルアップロード
	 */
	
	const MSG_INF_FILE_002 = "ファイルを選択して読込ボタンを押してください";
	const MSG_INF_FILE_014 = "出力するファイルのExcel出力ボタンを押してください";
	const MSG_INF_FILE_003 = "バーコード出力ボタンを押してください";
	const MSG_SYS_FILE_004 = "ファイルの読込に失敗しました";
	const MSG_VAL_FILE_005 = "Excelファイル選択してください";
	const MSG_VAL_FILE_006 = "ファイルが不正です";
	const MSG_VAL_FILE_007 = "Excelファイル名が設定されていません";
	const MSG_VAL_FILE_008 = "該当するExcelファイルが存在しません";
	const MSG_VAL_FILE_009 = "ヘッダ配列と物理名配列の要素数が一致しません";
	const MSG_VAL_FILE_010 = "Excelファイルのフォーマットが正しくありません";
	const MSG_VAL_FILE_011 = "該当のExcelファイルにデータがありません";
	const MSG_VAL_FILE_015 = "該当のExcelファイルに取り込み可能なデータがありません";
	const MSG_INF_FILE_012 = "個数を入力してバーコード出力ボタン、または、リスト出力ボタンを押してください";
	const MSG_SYS_FILE_013 = "出力するバーコードがありません 各商品の個数を1以上に設定してください";
	const MSG_SYS_FILE_016 = "Excelファイルの削除に失敗しました ファイル名：{0}";
	const MSG_VAL_FILE_017 = "この機能はPOST送信専用です。画面から実行してください。";
	const MSG_VAL_FILE_018 = "不正なリクエストです。画面を再表示して、もう一度実行してください。";

	/**
	 * ユーザー登録・更新・削除
	 */


	const MSG_INF_USER_001 = "ユーザー情報を入力して登録ボタンを押してください";
	const MSG_INF_USER_002 = "ユーザー情報を編集して更新ボタンを押してください";
	const MSG_SYS_USER_003 = "入力されたログインIDが既に存在します";
	const MSG_SYS_USER_004 = "ユーザーを取得できませんでした ログインID：{0}";
	const MSG_SYS_USER_005 = "ログインIDは3～16文字の英数字で入力してください";
	const MSG_SYS_USER_006 = "パスワードは8～128文字で入力してください";
	const MSG_SYS_USER_007 = "メールアドレスを正しく入力してください";
	const MSG_OK_USER_008 = "ユーザーを登録しました ログインID：{0}";
	const MSG_OK_USER_009 = "ユーザーを更新しました ログインID：{0}";
	const MSG_OK_USER_010 = "ユーザーを削除しました ログインID：{0}";
	const MSG_SYS_USER_011 = "ユーザーを削除できませんでした ログインID：{0}";

	/**
	 * 設定系
	 */
	const MSG_INF_CONF_001 = "設定値を選択してください";
	const MSG_INF_CONF_003 = "設定値を編集して更新ボタンを押してください";
	const MSG_OK_CONF_002 = "設定値を更新しました";
}
?>
