<?php
// application/config/app_constants.php
defined('BASEPATH') OR exit('No direct script access allowed');

// Status Pelanggan
define('STATUS_ACTIVE',     'active');
define('STATUS_ISOLATED',   'isolated');
define('STATUS_SUSPENDED',  'suspended');
define('STATUS_TERMINATED', 'terminated');

// Status Invoice
define('INV_UNPAID',    'unpaid');
define('INV_PAID',      'paid');
define('INV_OVERDUE',   'overdue');
define('INV_CANCELLED', 'cancelled');

// Status WO
define('WO_OPEN',        'open');
define('WO_PROCESS',     'process');
define('WO_DONE',        'done');
define('WO_ACTIVATED',   'activated');
define('WO_CANCELLED',   'cancel');

// Backward compatibility alias
define('WO_IN_PROGRESS', WO_PROCESS);
define('WO_COMPLETED',   WO_DONE);

// Billing
define('MAX_BILLING_DATE', 28);
define('DEFAULT_GRACE_DAYS', 7);
