<?php
/**
 * application/config/mikrotik.php
 *
 * Konfigurasi koneksi MikroTik RouterOS API.
 * Untuk production, gunakan ENV/secret manager.
 */
defined('BASEPATH') OR exit('No direct script access allowed');

if ( ! function_exists('rtrwnet_config_env'))
{
	/**
	 * Resolve env values from canonical keys first, then temporary aliases.
	 *
	 * @param	mixed	$keys
	 * @param	mixed	$default
	 * @return	mixed
	 */
	function rtrwnet_config_env($keys, $default = NULL)
	{
		foreach ((array) $keys as $key)
		{
			$value = getenv($key);
			if ($value !== FALSE)
			{
				return $value;
			}

			if (isset($_ENV[$key]))
			{
				return $_ENV[$key];
			}

			if (isset($_SERVER[$key]))
			{
				return $_SERVER[$key];
			}
		}

		return $default;
	}
}

if ( ! function_exists('rtrwnet_config_env_bool'))
{
	/**
	 * Resolve boolean env with sensible fallback when value is invalid.
	 *
	 * @param	mixed	$keys
	 * @param	bool	$default
	 * @return	bool
	 */
	function rtrwnet_config_env_bool($keys, $default = FALSE)
	{
		$value = rtrwnet_config_env($keys, NULL);
		if ($value === NULL)
		{
			return (bool) $default;
		}

		$parsed = filter_var($value, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE);
		return $parsed === NULL ? (bool) $default : $parsed;
	}
}

$mikrotik_port = (int) rtrwnet_config_env(array('MIKROTIK_PORT', 'MIKROTIK_API_PORT'), 8728);
if ($mikrotik_port <= 0)
{
	$mikrotik_port = 8728;
}

$mikrotik_timeout = (int) rtrwnet_config_env(array('MIKROTIK_TIMEOUT', 'MIKROTIK_API_TIMEOUT'), 5);
if ($mikrotik_timeout <= 0)
{
	$mikrotik_timeout = 5;
}

$mikrotik_retry_max = (int) rtrwnet_config_env(array('MIKROTIK_RETRY_MAX'), 3);
if ($mikrotik_retry_max <= 0)
{
	$mikrotik_retry_max = 3;
}

$mikrotik_retry_delay = (int) rtrwnet_config_env(array('MIKROTIK_RETRY_DELAY'), 1);
if ($mikrotik_retry_delay < 0)
{
	$mikrotik_retry_delay = 1;
}

$config['mikrotik_host']          = trim((string) rtrwnet_config_env(array('MIKROTIK_HOST', 'MIKROTIK_IP'), ''));
$config['mikrotik_port']          = $mikrotik_port;
$config['mikrotik_user']          = trim((string) rtrwnet_config_env(array('MIKROTIK_USER', 'MIKROTIK_USERNAME'), ''));
$config['mikrotik_pass']          = (string) rtrwnet_config_env(array('MIKROTIK_PASS', 'MIKROTIK_PASSWORD'), '');
$config['mikrotik_ssl']           = rtrwnet_config_env_bool(array('MIKROTIK_SSL'), false);
$config['mikrotik_timeout']       = $mikrotik_timeout;        // detik
$config['mikrotik_retry_max']     = $mikrotik_retry_max;        // max retry
$config['mikrotik_retry_delay']   = $mikrotik_retry_delay;        // detik awal (exponential)
$config['mikrotik_debug']         = false;     // true = log semua command
$config['mikrotik_log_file']      = 'api_mikrotik.log';

// Address list name untuk isolir (harus sama dengan firewall rule)
$config['isolir_address_list']    = 'ISOLIR';

// PPPoE service
$config['pppoe_service']          = 'pppoe';
