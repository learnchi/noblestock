<?php
/**
 * LogicConst.php
 * 定数クラス
 * @author Studio GAU
 */
namespace Noblestock\Logic;

class LogicConst {

	// ディレクトリ設定
	const DIR_TMP = "tmp";    // tmp/
	const DIR_IMAGES = "uploads";    // public/uploads/
    const DIR_FILES = "file";
    const DB_CONFIG_PATH = "/config/dbconfig.ini";    // DB接続情報
    const MAIL_CONFIG_PATH = "/config/mailconfig.ini";    // SMTP接続情報

	// クッキー有効期限 CookieHelperで使用
	const COOKIE_TIME = 2592000;

	// 画面1ページ行数
	// const PAGE_ITEM = 100;
	const PAGE_ITEM = 20;

	// 一覧Excel出力行数
	const PAGE_ITEM_EXCEL = 2000;
	//const PAGE_ITEM_EXCEL = 20;

	// バーコードExcel出力個数
	const BARCODE_ITEM_EXCEL = 1000;
	//const BARCODE_ITEM_EXCEL = 10;

	// 実行時間 set_time_limitで使用
	const RUN_TIME_LIMIT = 900;

	// 処理メモリ ini_setで使用
	const MEMORY_LIMIT = "512M";

	// メニューコマンド
	const CMD_MENU = 'PCPM'; // メニュー
	const CMD_VIEW = 'PCPV'; // 表示
	const CMD_REGIST = 'PCPR'; // 登録
	const CMD_STOCK = 'PCPU'; // 入庫
	const CMD_SHIPPING = 'PCPD'; // 出庫
	const CMD_CREATE = 'PCPC'; // バーコード生成
	const CMD_VAL = 'PVP'; // 数字
	const CMD_COMPLETE = 'PCPP'; // 完了
	const CMD_CANCEL = 'PCPN'; // キャンセル
	const CMD_UNDO = 'PCPO'; // 取消
	const CMD_LOC = 'PLP'; // 店舗
	const CMD_LOCCHG = 'PCPL'; // 移動

	// マスタ一括登録/出力 共通定義
	public const MASTER_BULK_DEFS = [
		0 => [
			'name' => '',
			'nameStr' => 'メニュー',
			'listKey' => null,
			'excelName' => '',
			'excelHeader' => null,
			'itemName' => null,
			'barCmd' => null,
		],
		1 => [
			'name' => 'Category',
			'nameStr' => 'カテゴリ',
			'listKey' => 'categoryList',
			'excelName' => 'category',
			'excelHeader' => ['カテゴリid', 'カテゴリ名', '備考'],
			'itemName' => ['id', 'category_name', 'remarks'],
			'barCmd' => null,
		],
		2 => [
			'name' => 'Maker',
			'nameStr' => 'メーカー',
			'listKey' => 'makerList',
			'excelName' => 'maker',
			'excelHeader' => ['メーカーid', 'メーカー名', '備考'],
			'itemName' => ['id', 'maker_name', 'remarks'],
			'barCmd' => null,
		],
		3 => [
			'name' => 'Unit',
			'nameStr' => '単位',
			'listKey' => 'unitList',
			'excelName' => 'unit',
			'excelHeader' => ['単位id', '単位名', '備考'],
			'itemName' => ['id', 'unit_name', 'remarks'],
			'barCmd' => null,
		],
		4 => [
			'name' => 'Location',
			'nameStr' => '店舗',
			'listKey' => 'locationList',
			'excelName' => 'shop',
			'excelHeader' => ['店舗id', '店舗名', '備考'],
			'itemName' => ['id', 'location_name', 'remarks'],
			'barCmd' => self::CMD_LOC,
		],
	];

	// ----------------------- 権限系
	    // 権限ビット数（authority の期待長）
    public const AUTH_BITS = 20;

    /**
     * 権限定義：ビット index => ['label' => 表示名, 'screens' => [画面ID...]]
     */
    public const PERMISSION_DEFS = [
        0 => [
            'label' => '商品表示',
            'screens' => ['product_show'],
        ],
        1 => [
            'label' => '商品一覧',
            'screens' => [
                'product_list',
                'product_create',
                'product_confirm',
                'product_edit',
                'product_edit_confirm',
                'image_create',
                'product_barcode_list',
                'barcode_list_export_split',
                'list_export_split',
                'product_list_export',
                'barcode_export',
            ],
        ],
        2 => [
            'label' => '店舗別商品一覧',
            'screens' => [
                'product_per_location', 
                'list_export_split', 
                'product_per_location_export'
            ],
        ],
        3 => [
            'label' => '商品入庫',
            'screens' => ['stock_in'],
        ],
        4 => [
            'label' => '商品出庫',
            'screens' => ['stock_out'],
        ],
        5 => [
            'label' => '商品移動',
            'screens' => ['stock_move'],
        ],
        6 => [
            'label' => 'バーコード生成',
            'screens' => ['barcode_create'],
        ],
        7 => [
            'label' => 'バーコード一括出力',
            'screens' => ['barcode_bulk_list', 'barcode_list_export_split'],
        ],
        8 => [
            'label' => '在庫チェック',
            'screens' => ['product_check', 'list_export_split', 'product_check_export'],
        ],
        9 => [
            'label' => '実績一覧',
            'screens' => [
                'sales_list',
                'list_export_split',
                'sales_list_export',
                'sales_show',
                'sales_show_export',
                'history_edit',
                'history_confirm',
            ],
        ],
        10 => [
            'label' => '履歴一覧',
            'screens' => [
                'history_list', 
                'list_export_split', 
                'history_list_export', 
                'history_edit', 
                'history_confirm'
            ],
        ],
        11 => [
            'label' => 'マスタメンテナンス',
            'screens' => ['menu_master'],
        ],
        12 => [
            'label' => '商品一括登録',
            'screens' => ['product_bulk_create'],
        ],
        13 => [
            'label' => '在庫一括登録',
            'screens' => ['stock_bulk_create'],
        ],
        14 => [
            'label' => '画像一括登録',
            'screens' => ['image_bulk_create'],
        ],
        15 => [
            'label' => 'マスタ登録',
            'screens' => ['master_bulk_edit', 'master_bulk_export'],
        ],
        16 => [
            'label' => '設定',
            'screens' => ['settings'],
        ],
        17 => [
            'label' => '商品一覧更新',
            'screens' => ['biz002_update'],
        ],
        18 => [
            'label' => 'ユーザー管理',
            'screens' => ['user_list', 'user_edit', 'user_edit_confirm', 'user_create', 'user_confirm', 'user_export'],
        ],
        19 => [
            'label' => '機能設定',
            'screens' => ['config'],
        ],
    ];
	// -----------------------

    // ----------------------- マスター系
    // バーコード印刷サイズ　
    const BAR_PRT_SIZE_CNT = [
        1 => 24,     // Code39,JAN,UPC-A（3×8）
        2 => 24,     // Code128,JAN,UPC-A（3×8）
        3 => 24,     // Code128,JAN,UPC-A（3×8）画像付
        4 => 44,     // Code128,JAN,UPC-A（4×11）
        5 => 65,     // Code128,JAN,UPC-A（5×13）
    ];
    // バーコード印刷サイズ　
    const BAR_PRT_SIZE_SET = [
        1 => "3×8",     // Code39,JAN,UPC-A（3×8）
        2 => "3×8",     // Code128,JAN,UPC-A（3×8）
        3 => "3×8",     // Code128,JAN,UPC-A（3×8）画像付
        4 => "4×11",     // Code128,JAN,UPC-A（4×11）
        5 => "5×13",     // Code128,JAN,UPC-A（5×13）
    ];
    // バーコード印刷サイズ　ラベル規格の説明として画面表示するのに使用
    const BAR_PRT_SIZE_DEFS = [
        1 => "（Code39：英数字大文字、記号（-＋$/） 3-12桁　JAN：数字13桁　UPC-A：数字12桁）",     // Code39,JAN,UPC-A（3×8）
        2 => "（Code128：英数字大文字小文字、記号 3-18桁　JAN：数字13桁　UPC-A：数字12桁）",     // Code128,JAN,UPC-A（3×8）
        3 => "（Code128：英数字大文字小文字、記号 3-12桁　JAN：数字13桁　UPC-A：数字12桁）",     // Code128,JAN,UPC-A（3×8）画像付
        4 => "（Code128：英数字大文字小文字、記号 3-10桁　JAN：数字13桁　UPC-A：数字12桁）",     // Code128,JAN,UPC-A（4×11）
        5 => "（Code128：英数字大文字小文字、記号 3-7桁　JAN：数字13桁　UPC-A：数字12桁）",     // Code128,JAN,UPC-A（5×13）
    ];
    // ----------------------- 

	// 在庫数低下バッチ設定
	const BATCH_SW = true;  //true：起動する  false：起動しない
	// const BATCH_PATH = "/usr/local/php5.3/bin/php ";    // linux
	const BATCH_PATH = "C:\\xampp_chandra\\php\\php.exe ";    // windows XAMPP
	// const BATCH_RTN = " > /dev/null &";    // linux
	const BATCH_RTN = " > NUL 2>&1";    // windows

	// メール設定
	const MAIL_SW = true;  //true：送信する  false：送信しない
	const MAIL_PRESEND = true;  // true:PHPMailのPreSend使用（実際に送らない）
	const MAIL_SUB = "【NobleStock】在庫数低下";
	const MAIL_MSG = "以下商品の在庫数が低下しています。\n\n【管理番号】\n{0}\n\n【カテゴリー】\n{1}\n\n【メーカー】\n{2}\n\n【商品名】\n{3}\n\n【在庫数】\n{4}\n\n本メールはシステムより自動送信しています。\n本メールアドレスには返信できません。\n\nお問い合わせは以下よりお願いします。\nadmin@example.com";
    const MAIL_FTR = "\n\n--------------------------------------\nStudio GAU\n--------------------------------------";


}
?>
