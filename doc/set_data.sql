-- 初期データ
USE noblestock;

-- 管理ユーザ追加
INSERT INTO users (login_id, password_hash, sort_order, user_name, furigana, email, authority, created_at, created_by, updated_at, updated_by) VALUES
('admin', '$2y$10$VKCxCYz9C9iih4NDEmONV.B4vS1yxoncrm5YuW1k2e4.rvmSGugfa', 1, '管理者がう', 'カンリシャガウ', 'admingau@example.com', '11111111111111111111', '2021-05-20 22:24:35', 'admin', '2021-05-20 22:24:35', 'admin');

-- 設定情報追加
INSERT INTO configs (config_key, value_int, value_str, config_description, created_at, created_by, updated_at, updated_by) VALUES ('BAR_PRT_SIZE', 2, null, 'バーコード印刷サイズ<br/>　1：Code39,JAN,UPC-A（3×8）<br/>　2：Code128,JAN,UPC-A（3×8）<br/>　3：Code128,JAN,UPC-A（3×8）画像付<br/>　4：Code128,JAN,UPC-A（4×11）<br/>　5：Code128,JAN,UPC-A（5×13）', '2013-03-27 21:20:30', 'admin', '2017-01-11 21:30:00', 'admin');
INSERT INTO configs (config_key, value_int, value_str, config_description, created_at, created_by, updated_at, updated_by) VALUES ('EXCEL_VAR', 1, null, 'Excelバージョン<br/>　0：Excel97-2003<br/>　1：Excel2007-2016', '2013-03-27 21:20:30', 'admin', '2013-03-27 21:20:30', 'admin');
INSERT INTO configs (config_key, value_int, value_str, config_description, created_at, created_by, updated_at, updated_by) VALUES ('SHIP_FLG', 3, null, '履歴<br/>　0：非表示<br/>　1：履歴表示<br/>　2：入出庫実績表示<br/>　3：全表示', '2013-03-27 21:20:30', 'admin', '2013-03-27 21:20:30', 'admin');
INSERT INTO configs (config_key, value_int, value_str, config_description, created_at, created_by, updated_at, updated_by) VALUES ('STOCK_LOW_LIMIT', 0, null, '在庫数低下通知 下限値<br/>　0：設定なし', '2014-04-23 20:11:30', 'admin', '2014-04-23 20:11:30', 'admin');
INSERT INTO configs (config_key, value_int, value_str, config_description, created_at, created_by, updated_at, updated_by) VALUES ('STOCK_LOW_INTERVAL', 0, null, '在庫数低下通知 間隔<br/>　0：設定なし', '2014-04-25 14:45:30', 'admin', '2014-04-25 14:45:30', 'admin');
INSERT INTO configs (config_key, value_int, value_str, config_description, created_at, created_by, updated_at, updated_by) VALUES ('BAR_PRO_FLG', 1, null, 'バーコード商品選択出力<br/>　0：選択出力不可<br/>　1：選択出力可能', '2014-09-10 19:24:30', 'admin', '2014-09-10 19:24:30', 'admin');
INSERT INTO configs (config_key, value_int, value_str, config_description, created_at, created_by, updated_at, updated_by) VALUES ('LABEL_UPPER', 2, null, 'ラベル表示（上段）', '2017-01-11 21:30:00', 'admin', '2017-01-11 21:30:00', 'admin');
INSERT INTO configs (config_key, value_int, value_str, config_description, created_at, created_by, updated_at, updated_by) VALUES ('LABEL_LOWER', 4, null, 'ラベル表示（下段）', '2017-01-11 21:30:00', 'admin', '2017-01-11 21:30:00', 'admin');
