<?php defined('BASEPATH') OR exit('No direct script access allowed'); ?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <title>PHP Error</title>
    <style>
        body {
            background: #f5f7fb;
            color: #1f2937;
            font: 15px/1.5 -apple-system, BlinkMacSystemFont, "Segoe UI", Arial, sans-serif;
            margin: 0;
            padding: 24px;
        }
        .card {
            max-width: 920px;
            margin: 0 auto;
            background: #fff;
            border: 1px solid #dbe3f0;
            border-radius: 10px;
            box-shadow: 0 8px 20px rgba(17, 24, 39, 0.08);
            overflow: hidden;
        }
        .head {
            background: #ef4444;
            color: #fff;
            padding: 14px 18px;
            font-weight: 700;
        }
        .body {
            padding: 16px 18px;
        }
        code {
            background: #eef2ff;
            padding: 2px 5px;
            border-radius: 4px;
        }
        .line {
            margin: 10px 0;
        }
    </style>
</head>
<body>
<div class="card">
    <div class="head">A PHP Error Was Encountered</div>
    <div class="body">
        <div class="line"><strong>Severity:</strong> <?php echo isset($severity) ? htmlspecialchars((string) $severity, ENT_QUOTES, 'UTF-8') : 'N/A'; ?></div>
        <div class="line"><strong>Message:</strong> <?php echo isset($message) ? htmlspecialchars((string) $message, ENT_QUOTES, 'UTF-8') : ''; ?></div>
        <div class="line"><strong>Filename:</strong> <code><?php echo isset($filepath) ? htmlspecialchars((string) $filepath, ENT_QUOTES, 'UTF-8') : ''; ?></code></div>
        <div class="line"><strong>Line Number:</strong> <?php echo isset($line) ? (int) $line : 0; ?></div>
    </div>
</div>
</body>
</html>
