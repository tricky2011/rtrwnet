<?php defined('BASEPATH') OR exit('No direct script access allowed'); ?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <title><?php echo isset($heading) ? htmlspecialchars((string) $heading, ENT_QUOTES, 'UTF-8') : '404 Page Not Found'; ?></title>
    <style>
        body{background:#f8fafc;color:#0f172a;font:15px/1.5 -apple-system,BlinkMacSystemFont,"Segoe UI",Arial,sans-serif;margin:0;padding:24px}
        .wrap{max-width:760px;margin:80px auto;text-align:center}
        h1{font-size:28px;margin-bottom:12px}
        p{color:#475569}
    </style>
</head>
<body>
<div class="wrap">
    <h1><?php echo isset($heading) ? htmlspecialchars((string) $heading, ENT_QUOTES, 'UTF-8') : '404'; ?></h1>
    <p><?php echo isset($message) ? strip_tags((string) $message) : 'The page you requested was not found.'; ?></p>
</div>
</body>
</html>
