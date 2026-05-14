<?php defined('BASEPATH') OR exit('No direct script access allowed'); ?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <title>Unhandled Exception</title>
    <style>
        body{background:#f5f7fb;color:#1f2937;font:14px/1.5 Menlo,Consolas,monospace;margin:0;padding:24px}
        .card{max-width:980px;margin:0 auto;background:#fff;border:1px solid #dbe3f0;border-radius:8px;overflow:hidden}
        .head{background:#7f1d1d;color:#fff;padding:12px 16px;font-weight:700}
        .body{padding:14px 16px}
        pre{white-space:pre-wrap;word-break:break-word;background:#f8fafc;border:1px solid #e2e8f0;padding:10px;border-radius:6px}
    </style>
</head>
<body>
<div class="card">
    <div class="head">Unhandled Exception</div>
    <div class="body">
        <?php if (isset($exception)): ?>
        <p><strong>Message:</strong> <?php echo htmlspecialchars($exception->getMessage(), ENT_QUOTES, 'UTF-8'); ?></p>
        <p><strong>File:</strong> <?php echo htmlspecialchars($exception->getFile(), ENT_QUOTES, 'UTF-8'); ?> : <?php echo (int) $exception->getLine(); ?></p>
        <pre><?php echo htmlspecialchars($exception->getTraceAsString(), ENT_QUOTES, 'UTF-8'); ?></pre>
        <?php else: ?>
        <p>No exception data available.</p>
        <?php endif; ?>
    </div>
</div>
</body>
</html>
