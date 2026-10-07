-- データベース作成

create database if not EXISTS noblestock default character set utf8mb4 collate utf8mb4_unicode_ci;
use noblestock;

-- ユーザー
drop table if exists users;
create table users (
    id int unsigned auto_increment not null,
    login_id varchar(16) not null,
    password_hash varchar(255) not null,
    sort_order int unsigned not null default 1,
    user_name varchar(40) not null,
    furigana varchar(40),
    email varchar(80),
    authority varchar(50),
    created_at datetime not null,
    created_by varchar(16) not null,
    updated_at datetime,
    updated_by varchar(16),
    primary key(id),
    unique key(login_id)
) engine=InnoDB default charset=utf8mb4 collate=utf8mb4_unicode_ci
comment='ユーザー';

-- 商品
drop table if exists products;
create table products (
    management_no varchar(16) not null,
    category_id tinyint unsigned,
    maker_id tinyint unsigned,
    product_name varchar(100),
    wholesale_amount int unsigned,
    retail_amount int unsigned,
    sell_amount int unsigned,
    quantity int unsigned not null default 0,
    unit_id tinyint unsigned,
    storage_place varchar(100),
    image_file varchar(100),
    remarks text,
    remarks2 text,
    created_at datetime not null,
    created_by varchar(16) not null,
    updated_at datetime,
    updated_by varchar(16),
    primary key(management_no)
) engine=InnoDB default charset=utf8mb4 collate=utf8mb4_unicode_ci
comment='商品';
alter table products add index (category_id);
alter table products add index (maker_id);
alter table products add index (unit_id);

-- メーカー
drop table if exists makers;
create table makers (
    id tinyint unsigned not null,
    maker_name varchar(100) not null,
    remarks text,
    created_at datetime not null,
    created_by varchar(16) not null,
    updated_at datetime,
    updated_by varchar(16),
    primary key(id)
) engine=InnoDB default charset=utf8mb4 collate=utf8mb4_unicode_ci
comment='メーカー';

-- カテゴリー
drop table if exists categories;
create table categories (
    id tinyint unsigned not null,
    category_name varchar(100) not null,
    remarks text,
    created_at datetime not null,
    created_by varchar(16) not null,
    updated_at datetime,
    updated_by varchar(16),
    primary key(id)
) engine=InnoDB default charset=utf8mb4 collate=utf8mb4_unicode_ci
comment='カテゴリー';

-- 単位
drop table if exists units;
create table units (
    id tinyint unsigned not null,
    unit_name varchar(100) not null,
    remarks text,
    created_at datetime not null,
    created_by varchar(16) not null,
    updated_at datetime,
    updated_by varchar(16),
    primary key(id)
) engine=InnoDB default charset=utf8mb4 collate=utf8mb4_unicode_ci
comment='単位';

-- 履歴
drop table if exists histories;
create table histories (
    id int unsigned auto_increment not null,
    history_yy smallint not null,
    history_mm tinyint not null,
    history_dd tinyint not null,
    history_kbn tinyint not null,  -- 0：入庫 1：出庫 2：商品登録 3：商品更新 4：商品削除 5：店舗移動From 6：店舗移動To 7：在庫数変更 9：履歴削除
    management_no varchar(16) not null,
    branch_no int not null default 1,
    category_name varchar(100),
    maker_name varchar(100),
    product_name varchar(100),
    location_name varchar(100),  -- ★店舗名 追加
    quantity int unsigned,  -- 出庫数
    stock_in int unsigned,  -- 入庫数
    move_stock int unsigned,  -- 移動数 追加
    location_stock int unsigned,  -- 店舗在庫数 追加
    stock int unsigned,  -- 在庫数（全店舗合計）
    del_flg tinyint not null default 0,
    created_at datetime not null,
    created_by varchar(16) not null,
    updated_at datetime,
    updated_by varchar(16),
    primary key(id)
) engine=InnoDB default charset=utf8mb4 collate=utf8mb4_unicode_ci
comment='履歴';
alter table histories add index (management_no, branch_no);

-- 設定
drop table if exists configs;
create table configs (
    config_key varchar(30) not null,
    value_int tinyint unsigned,
    value_str varchar(100),
    config_description text,
    created_at datetime not null,
    created_by varchar(16) not null,
    updated_at datetime,
    updated_by varchar(16),
    primary key(config_key)
) engine=InnoDB default charset=utf8mb4 collate=utf8mb4_unicode_ci
comment='設定';

-- 店舗
drop table if exists locations;
create table locations (
    id int unsigned not null,
    location_name varchar(100) not null,
    remarks text,
    created_at datetime not null,
    created_by varchar(16) not null,
    updated_at datetime,
    updated_by varchar(16),
    primary key(id)
) engine=InnoDB default charset=utf8mb4 collate=utf8mb4_unicode_ci
comment='店舗';

-- 在庫
drop table if exists stocks;
create table stocks (
    management_no varchar(16) not null,
    location_id tinyint unsigned not null,
    quantity int unsigned not null default 0,
    remarks text,
    created_at datetime not null,
    created_by varchar(16) not null,
    updated_at datetime,
    updated_by varchar(16),
    primary key(management_no, location_id)
) engine=InnoDB default charset=utf8mb4 collate=utf8mb4_unicode_ci
comment='在庫';
alter table stocks add index (location_id);

-- 在庫チェック
drop table if exists checks;
create table checks (
    login_id varchar(16) not null comment 'ログインユーザーID',
    location_id tinyint unsigned not null comment '店舗NO',
    management_no varchar(16) not null comment '管理番号',
    check_count int unsigned not null comment 'チェック数',
    result_flg tinyint not null default 0 comment '結果フラグ',  -- 0：不一致 1：一致
    created_at datetime not null,
    created_by varchar(16) not null,
    updated_at datetime,
    updated_by varchar(16),
    primary key(login_id, location_id, management_no)
) engine=InnoDB default charset=utf8mb4 collate=utf8mb4_unicode_ci
comment='在庫チェック';
-- ログイン試行履歴
drop table if exists login_attempts;
create table login_attempts (
    id bigint unsigned auto_increment not null,
    login_id varchar(16) not null default '' comment '入力されたログインID',
    client_ip varchar(45) not null default '' comment '送信元IPアドレス',
    result_flg tinyint not null default 0 comment '結果フラグ',
    failure_reason varchar(50) comment '失敗理由',
    attempted_at datetime not null comment '試行日時',
    created_at datetime not null,
    created_by varchar(16) not null,
    updated_at datetime,
    updated_by varchar(16),
    primary key(id)
) engine=InnoDB default charset=utf8mb4 collate=utf8mb4_unicode_ci
comment='ログイン試行履歴';
alter table login_attempts add index (login_id, attempted_at);
alter table login_attempts add index (client_ip, attempted_at);
alter table login_attempts add index (result_flg, attempted_at);

-- ログインロック状態
drop table if exists login_locks;
create table login_locks (
    id bigint unsigned auto_increment not null,
    lock_type varchar(20) not null comment 'ロック種別',
    lock_key varchar(191) not null comment 'ロック対象キー',
    failed_count int unsigned not null default 0 comment 'ロック時失敗回数',
    last_failed_at datetime not null comment '最終失敗日時',
    locked_until datetime not null comment 'ロック解除日時',
    created_at datetime not null,
    created_by varchar(16) not null,
    updated_at datetime,
    updated_by varchar(16),
    primary key(id),
    unique key login_locks_type_key_unique(lock_type, lock_key)
) engine=InnoDB default charset=utf8mb4 collate=utf8mb4_unicode_ci
comment='ログインロック状態';
alter table login_locks add index (locked_until);
