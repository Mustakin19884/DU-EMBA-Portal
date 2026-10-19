<?php
declare(strict_types=1);

define('APP_NAME', 'DU EMBA MIS Portal');
define('BASE_URL', '/DU-EMBA-Portal');

date_default_timezone_set('Asia/Dhaka');

ini_set('session.use_strict_mode', '1');
ini_set('session.use_only_cookies', '1');

if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}