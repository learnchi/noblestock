<?php
if (realpath(__FILE__) === realpath($_SERVER['SCRIPT_FILENAME'])) {
	// 直接指定での実行はできない
    http_response_code(404);
    exit;
}
?>
<footer class="bg-light py-2">
    <div class="container text-end">
        NobleStock ver 0.1 &copy; Studio GAU
    </div>
</footer>
