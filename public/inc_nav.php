<?php
use Studiogau\Chandra\Support\Utility;
if (realpath(__FILE__) === realpath($_SERVER['SCRIPT_FILENAME'])) {
	// 直接指定での実行はできない
    http_response_code(404);
    exit;
}
?>
<nav class="navbar bg-light py-1">
    <div class="container">
        <div class="navbar-brand">
            <a href="#">
            <img src="img/logos.png" alt="Noble Stock">
            </a>
            <?=Utility::h($title ?? '') ?>
        </div>
        <ul class="navbar-nav">
            <li class="nav-item">
                <a class="btn py-0" href="password_edit.php">
                    <i class="bi bi-person-circle"></i> <?=Utility::h($userName ?? "") ?>
                </a>
            </li>
            <li class="nav-item">
                <a class="btn py-0" href="index.php">
                    <i class="bi bi-door-open"></i> ログアウト
                </a>
            </li>
        </ul>

    </div>

</nav>
