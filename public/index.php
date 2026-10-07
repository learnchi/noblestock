<?php
/**
 * ログイン画面
 */
session_start();

require_once(__DIR__ . '/../vendor/autoload.php');

use Studiogau\Chandra\Support\SessionHelper;
use Studiogau\Chandra\Support\Utility;

// ログイン画面に戻ったら古いログイン情報をクリア
$flashError = SessionHelper::getFlushError();
$flashSuccess = SessionHelper::getFlushSuccess();
// 既存セッションを無効化し、新しいセッションを開始する
SessionHelper::invalidateSession(true);
?>

<!DOCTYPE html>
<html lang="ja" class="h-100">
	<head>
		<?php $title = "ログイン"; require_once(__DIR__."/inc_head.php"); ?>
	</head>
<body class="container d-flex flex-column h-100 login" >
	<?php if ($flashError !== null) { ?>
	<div class="alert alert-danger alert-dismissible fade show" role="alert">
		<div><?= Utility::h($flashError) ?></div>
		<button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
	</div>
	<?php } ?>

	<?php if ($flashSuccess !== null) { ?>
	<div class="alert alert-success alert-dismissible fade show" role="alert">
		<div><?= Utility::h($flashSuccess) ?></div>
		<button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
		</div>
	<?php } ?>

	<main class="w-100 m-auto">

		<form method="post" name="login" class="login-container" action="menu.php">
			<?= Utility::renderCsrfHiddenInput('index.login') ?>
			<h1><img class="m-4" width="72" height="72" src="img/logos.png">ログイン</h1>
			<input type="text" name="user" class="form-control" value="" maxlength="16" placeholder="ログインID">
			<input type="password" name="pass" class="form-control" value="" maxlength="128" placeholder="パスワード">
			<button type="submit" name="admin" class="btn btn-primary w-100">ログイン</button>
		</form>
	</main>
</body>
</html>
