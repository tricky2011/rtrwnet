<?php defined('BASEPATH') OR exit('No direct script access allowed'); ?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <title><?php echo isset($heading) ? html_escape($heading) : 'Database Error'; ?></title>
    <style>
        body { font-family: Arial, sans-serif; background: #f7f7f7; margin: 0; padding: 24px; }
        .box { max-width: 760px; margin: 0 auto; background: #fff; border: 1px solid #ddd; border-radius: 8px; padding: 18px; }
        h1 { margin: 0 0 12px; font-size: 20px; color: #b42318; }
        p { margin: 0; color: #333; line-height: 1.5; white-space: pre-wrap; }
    </style>
</head>
<body>
    <div class="box">
        <h1><?php echo isset($heading) ? html_escape($heading) : 'Database Error'; ?></h1>
        <p><?php echo isset($message) ? html_escape($message) : 'Unable to connect to database.'; ?></p>
    </div>
</body>
</html>
