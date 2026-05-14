<?php
defined('BASEPATH') OR exit('No direct script access allowed');

/*
| -------------------------------------------------------------------
| DATABASE CONNECTIVITY SETTINGS
| -------------------------------------------------------------------
| This file will contain the settings needed to access your database.
|
| For complete instructions please consult the 'Database Connection'
| page of the User Guide.
|
| -------------------------------------------------------------------
| EXPLANATION OF VARIABLES
| -------------------------------------------------------------------
|
|	['dsn']      The full DSN string describe a connection to the database.
|	['hostname'] The hostname of your database server.
|	['username'] The username used to connect to the database
|	['password'] The password used to connect to the database
|	['database'] The name of the database you want to connect to
|	['dbdriver'] The database driver. e.g.: mysqli.
|			Currently supported:
|				 cubrid, ibase, mssql, mysql, mysqli, oci8,
|				 odbc, pdo, postgre, sqlite, sqlite3, sqlsrv
|	['dbprefix'] You can add an optional prefix, which will be added
|				 to the table name when using the  Query Builder class
|	['pconnect'] TRUE/FALSE - Whether to use a persistent connection
|	['db_debug'] TRUE/FALSE - Whether database errors should be displayed.
|	['cache_on'] TRUE/FALSE - Enables/disables query caching
|	['cachedir'] The path to the folder where cache files should be stored
|	['char_set'] The character set used in communicating with the database
|	['dbcollat'] The character collation used in communicating with the database
|				 NOTE: For MySQL and MySQLi databases, this setting is only used
| 				 as a backup if your server is running PHP < 5.2.3 or MySQL < 5.0.7
|				 (and in table creation queries made with DB Forge).
| 				 There is an incompatibility in PHP with mysql_real_escape_string() which
| 				 can make your site vulnerable to SQL injection if you are using a
| 				 multi-byte character set and are running versions lower than these.
| 				 Sites using Latin-1 or UTF-8 database character set and collation are unaffected.
|	['swap_pre'] A default table prefix that should be swapped with the dbprefix
|	['encrypt']  Whether or not to use an encrypted connection.
|
|			'mysql' (deprecated), 'sqlsrv' and 'pdo/sqlsrv' drivers accept TRUE/FALSE
|			'mysqli' and 'pdo/mysql' drivers accept an array with the following options:
|
|				'ssl_key'    - Path to the private key file
|				'ssl_cert'   - Path to the public key certificate file
|				'ssl_ca'     - Path to the certificate authority file
|				'ssl_capath' - Path to a directory containing trusted CA certificates in PEM format
|				'ssl_cipher' - List of *allowed* ciphers to be used for the encryption, separated by colons (':')
|				'ssl_verify' - TRUE/FALSE; Whether verify the server certificate or not
|
|	['compress'] Whether or not to use client compression (MySQL only)
|	['stricton'] TRUE/FALSE - forces 'Strict Mode' connections
|							- good for ensuring strict SQL while developing
|	['ssl_options']	Used to set various SSL options that can be used when making SSL connections.
|	['failover'] array - A array with 0 or more data for connections if the main should fail.
|	['save_queries'] TRUE/FALSE - Whether to "save" all executed queries.
| 				NOTE: Disabling this will also effectively disable both
| 				$this->db->last_query() and profiling of DB queries.
| 				When you run a query, with this setting set to TRUE (default),
| 				CodeIgniter will store the SQL statement for debugging purposes.
| 				However, this may cause high memory usage, especially if you run
| 				a lot of SQL queries ... disable this to avoid that problem.
|
| The $active_group variable lets you choose which connection group to
| make active.  By default there is only one group (the 'default' group).
|
| The $query_builder variables lets you determine whether or not to load
| the query builder class.
*/
$active_group = 'default';
$query_builder = TRUE;

if ( ! function_exists('rtrwnet_database_env'))
{
	/**
	 * Resolve database env values from canonical keys first, then legacy aliases.
	 * Empty strings are treated as "not set" so fallback aliases can still work.
	 *
	 * @param	mixed	$keys
	 * @param	mixed	$default
	 * @param	bool	$allow_empty
	 * @return	mixed
	 */
	function rtrwnet_database_env($keys, $default = NULL, $allow_empty = FALSE)
	{
		$empty_found = FALSE;

		foreach ((array) $keys as $key)
		{
			$sources = array(
				getenv($key),
				isset($_ENV[$key]) ? $_ENV[$key] : NULL,
				isset($_SERVER[$key]) ? $_SERVER[$key] : NULL,
			);

			foreach ($sources as $value)
			{
				if ($value === FALSE OR $value === NULL)
				{
					continue;
				}

				if (is_string($value))
				{
					$value = trim($value);
				}

				if ($value === '')
				{
					$empty_found = TRUE;
					continue;
				}

				return $value;
			}
		}

		if ($allow_empty === TRUE && $empty_found === TRUE)
		{
			return '';
		}

		return $default;
	}
}

if ( ! function_exists('rtrwnet_database_env_probe'))
{
	/**
	 * Probe whether any database env key is present and capture the first non-empty value.
	 *
	 * @param	mixed	$keys
	 * @return	array
	 */
	function rtrwnet_database_env_probe($keys)
	{
		$result = array(
			'present' => FALSE,
			'empty' => FALSE,
			'value' => NULL,
		);

		foreach ((array) $keys as $key)
		{
			$sources = array(
				getenv($key),
				isset($_ENV[$key]) ? $_ENV[$key] : NULL,
				isset($_SERVER[$key]) ? $_SERVER[$key] : NULL,
			);

			foreach ($sources as $value)
			{
				if ($value === FALSE OR $value === NULL)
				{
					continue;
				}

				$result['present'] = TRUE;

				if (is_string($value))
				{
					$value = trim($value);
				}

				if ($value === '')
				{
					$result['empty'] = TRUE;
					continue;
				}

				$result['value'] = $value;
				return $result;
			}
		}

		return $result;
	}
}

if ( ! function_exists('rtrwnet_database_env_int'))
{
	/**
	 * Resolve integer env values with a sane numeric fallback.
	 *
	 * @param	mixed	$keys
	 * @param	int	$default
	 * @return	int
	 */
	function rtrwnet_database_env_int($keys, $default)
	{
		$value = rtrwnet_database_env($keys, NULL);
		if ($value === NULL)
		{
			return (int) $default;
		}

		$value = (int) $value;
		return $value > 0 ? $value : (int) $default;
	}
}

if ( ! function_exists('rtrwnet_database_env_bool'))
{
	/**
	 * Resolve boolean env values with a safe explicit default.
	 *
	 * @param	mixed	$keys
	 * @param	bool	$default
	 * @return	bool
	 */
	function rtrwnet_database_env_bool($keys, $default = FALSE)
	{
		$value = rtrwnet_database_env($keys, NULL);
		if ($value === NULL)
		{
			return (bool) $default;
		}

		$parsed = filter_var($value, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE);
		return $parsed === NULL ? (bool) $default : (bool) $parsed;
	}
}

if ( ! function_exists('rtrwnet_database_bootstrap_fail'))
{
	/**
	 * Abort bootstrap with a clear database configuration error.
	 *
	 * @param	string	$message
	 * @return	void
	 */
	function rtrwnet_database_bootstrap_fail($message)
	{
		$message = trim((string) $message);
		log_message('error', '[DATABASE_BOOTSTRAP] '.$message);

		if (function_exists('show_error'))
		{
			show_error($message, 500, 'Database Bootstrap Error');
		}

		exit($message);
	}
}

$db_host_keys = array('DB_HOST', 'DATABASE_HOST', 'DBHOST', 'MYSQL_HOST');
$db_port_keys = array('DB_PORT', 'DATABASE_PORT', 'MYSQL_PORT');
$db_name_keys = array('DB_NAME', 'DB_DATABASE', 'DATABASE_NAME', 'MYSQL_DATABASE');
$db_user_keys = array('DB_USER', 'DB_USERNAME', 'DATABASE_USER', 'DATABASE_USERNAME', 'MYSQL_USER', 'MYSQL_USERNAME');
$db_pass_keys = array('DB_PASS', 'DB_PASSWORD', 'DATABASE_PASS', 'DATABASE_PASSWORD', 'MYSQL_PASSWORD');

$db_env_present = FALSE;
foreach (array($db_host_keys, $db_port_keys, $db_name_keys, $db_user_keys, $db_pass_keys) as $db_keys)
{
	$db_probe = rtrwnet_database_env_probe($db_keys);
	if ($db_probe['present'])
	{
		$db_env_present = TRUE;
		break;
	}
}

if ($db_env_present)
{
	$db_host_probe = rtrwnet_database_env_probe($db_host_keys);
	$db_port_probe = rtrwnet_database_env_probe($db_port_keys);
	$db_name_probe = rtrwnet_database_env_probe($db_name_keys);
	$db_user_probe = rtrwnet_database_env_probe($db_user_keys);
	$db_pass_probe = rtrwnet_database_env_probe($db_pass_keys);

	$bootstrap_errors = array();

	$db_hostname = $db_host_probe['value'];
	if ($db_hostname === NULL OR $db_hostname === '')
	{
		$bootstrap_errors[] = 'DB_HOST wajib diisi ketika salah satu DB env/alias terdeteksi.';
	}

	$db_database = $db_name_probe['value'];
	if ($db_database === NULL OR $db_database === '')
	{
		$bootstrap_errors[] = 'DB_NAME wajib diisi ketika salah satu DB env/alias terdeteksi.';
	}

	$db_username = $db_user_probe['value'];
	if ($db_username === NULL OR $db_username === '')
	{
		$bootstrap_errors[] = 'DB_USER wajib diisi ketika salah satu DB env/alias terdeteksi.';
	}

	if ( ! $db_pass_probe['present'])
	{
		$bootstrap_errors[] = 'DB_PASS wajib di-set. Gunakan string kosong eksplisit jika password database memang kosong.';
		$db_password = NULL;
	}
	else
	{
		$db_password = ($db_pass_probe['value'] !== NULL) ? $db_pass_probe['value'] : '';
	}

	if ($db_port_probe['present'])
	{
		$raw_port = $db_port_probe['value'];
		if ($raw_port === NULL OR preg_match('/^[0-9]+$/', (string) $raw_port) !== 1 OR (int) $raw_port <= 0)
		{
			$bootstrap_errors[] = 'DB_PORT tidak valid. Gunakan angka bulat positif, misalnya 3306.';
			$db_port = NULL;
		}
		else
		{
			$db_port = (int) $raw_port;
		}
	}
	else
	{
		$db_port = 3306;
	}

	if ( ! empty($bootstrap_errors))
	{
		rtrwnet_database_bootstrap_fail(implode(' ', $bootstrap_errors));
	}
}
else
{
	rtrwnet_database_bootstrap_fail('DB bootstrap canonical env tidak ditemukan. Isi DB_HOST, DB_NAME, DB_USER, DB_PASS dan opsional DB_PORT. Hardcoded fallback credential sudah dinonaktifkan demi keamanan packaging/deployment.');
}

$db['default'] = array(
	'dsn'	=> '',
	'hostname' => $db_hostname,
	'port' => $db_port,
	'username' => $db_username,
	'password' => $db_password,
	'database' => $db_database,
	'dbdriver' => 'mysqli',
	'dbprefix' => '',
	'pconnect' => FALSE,
	'db_debug' => (ENVIRONMENT !== 'production'),
	'cache_on' => FALSE,
	'cachedir' => '',
	'char_set' => 'utf8',
	'dbcollat' => 'utf8_general_ci',
	'swap_pre' => '',
	'encrypt' => FALSE,
	'compress' => FALSE,
	'stricton' => FALSE,
	'failover' => array(),
	'save_queries' => TRUE
);
