<?php
ignore_user_abort(true);
if (!headers_sent()) {
    header('Expires: Wed, 11 Jan 1984 05:00:00 GMT');
    header('Cache-Control: no-cache, must-revalidate, max-age=0');
}

$finish_success = false;
if (function_exists('fastcgi_finish_request')) {
    $finish_success = fastcgi_finish_request();
} elseif (function_exists('litespeed_finish_request')) {
    $finish_success = litespeed_finish_request();
}
if ($finish_success) {
    error_log('Mnml SMTP: Connection closed successfully');
} else {
    error_log('Mnml SMTP: Failed to close connection early');
}

define('DOING_CRON', true);

$abspath = dirname(__DIR__, 3);
if (!file_exists($abspath . '/wp-load.php')) {
    $abspath = __DIR__;
    do {
        if (dirname($abspath) === $abspath) {
            error_log('Mnml SMTP: Could not find WordPress.');
            die();
        }
        $abspath = dirname($abspath);
    } while (!file_exists($abspath . '/wp-load.php') && !file_exists($abspath . '/wp-admin/'));
}
require_once $abspath . '/wp-load.php';

wp_raise_memory_limit('cron');
set_time_limit(360);

if (empty($_POST['secret']) || $_POST['secret'] !== wp_hash('mnml_smtp_secret')) {
    error_log('Mnml SMTP: Invalid async.php access');
    die();
}

$email_id = isset($_POST['id']) ? intval($_POST['id']) : 0;
$atts = $email_id ? ['id' => $email_id] : (isset($_POST['queue']) ? ['queue' => true] : []);
if ($atts) {
    error_log('Mnml SMTP: async.php processing with atts: ' . print_r($atts, true));
    MnmlSMTP::send_emails($atts);
}

die();