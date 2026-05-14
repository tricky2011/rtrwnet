<?php defined('BASEPATH') OR exit('No direct script access allowed'); ?>

<?php echo isset($heading) ? strip_tags((string) $heading) : '404 Page Not Found'; ?>
<?php echo isset($message) ? strip_tags((string) $message) : ''; ?>

