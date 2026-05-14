<?php
defined('BASEPATH') OR exit('No direct script access allowed');

/*
|--------------------------------------------------------------------------
| Generic Cron Configuration
|--------------------------------------------------------------------------
| Token untuk endpoint HTTP cron umum. Jalur CLI tidak membutuhkan token.
*/

$config['cron_token'] = trim((string) getenv('CRON_TOKEN'));
