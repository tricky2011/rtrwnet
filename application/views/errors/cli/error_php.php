<?php defined('BASEPATH') OR exit('No direct script access allowed'); ?>

ERROR: <?php echo isset($severity) ? $severity : 'N/A'; ?>
MESSAGE: <?php echo isset($message) ? $message : ''; ?>
FILE: <?php echo isset($filepath) ? $filepath : ''; ?>
LINE: <?php echo isset($line) ? $line : 0; ?>

