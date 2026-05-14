<?php defined('BASEPATH') OR exit('No direct script access allowed'); ?>

Unhandled Exception

<?php if (isset($exception)): ?>
Message: <?php echo $exception->getMessage(); ?>
File: <?php echo $exception->getFile(); ?>
Line: <?php echo $exception->getLine(); ?>

<?php echo $exception->getTraceAsString(); ?>
<?php endif; ?>

