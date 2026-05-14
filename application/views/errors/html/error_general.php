<?php defined('BASEPATH') OR exit('No direct script access allowed'); ?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <title><?php echo isset($heading) ? htmlspecialchars((string) $heading, ENT_QUOTES, 'UTF-8') : 'Error'; ?></title>
    <style>
        body{background:#f5f7fb;color:#1f2937;font:15px/1.5 -apple-system,BlinkMacSystemFont,"Segoe UI",Arial,sans-serif;margin:0;padding:24px}
        .card{max-width:920px;margin:0 auto;background:#fff;border:1px solid #dbe3f0;border-radius:10px;box-shadow:0 8px 20px rgba(17,24,39,.08);overflow:hidden}
        .head{background:#0f172a;color:#fff;padding:14px 18px;font-weight:700}
        .body{padding:16px 18px}
    </style>
</head>
<body>
<div class="card">
    <div class="head"><?php echo isset($heading) ? htmlspecialchars((string) $heading, ENT_QUOTES, 'UTF-8') : 'An Error Was Encountered'; ?></div>
    <div class="body"><?php echo isset($message) ? $message : ''; ?></div>
</div>
</body>
</html>
