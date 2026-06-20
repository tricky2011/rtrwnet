<?php
defined('BASEPATH') OR exit('No direct script access allowed');

class Genieacs
{
    /** @var CI_Controller */
    protected $CI;

    /** @var string */
    protected $baseUrl = '';

    /** @var int */
    protected $routerId = 0;

    /** @var string */
    protected $routerName = '';

    /** @var string */
    protected $acsUsername = '';

    /** @var string */
    protected $acsPassword = '';

    /** @var int */
    protected $timeout = 20;

    /** @var bool */
    protected $verifySsl = false;

    /** @var bool */
    protected $taskConnectionRequest = false;

    /** @var object|null */
    protected $settingsModel = null;

    public function __construct($params = array())
    {
        $this->CI =& get_instance();
        $this->CI->load->database();
        $this->CI->load->config('genieacs', true);
        $this->CI->load->library('encryption');
        $this->CI->load->model('settings_model');

        if (isset($this->CI->settings_model)) {
            $this->settingsModel = $this->CI->settings_model;
        }

        $cfg = (array) $this->CI->config->item('genieacs');
        $this->timeout = max(5, (int) ($cfg['genieacs_timeout'] ?? 20));
        $this->verifySsl = !empty($cfg['genieacs_verify_ssl']);
        $this->taskConnectionRequest = !empty($cfg['genieacs_task_connection_request']);

        $params = is_array($params) ? $params : array();
        $router_id = (int) ($params['router_id'] ?? 0);
        if ($router_id <= 0) {
            show_error('Router ID required for GenieACS connection.', 500);
            return;
        }
        $this->routerId = $router_id;
        $this->bootstrapRouterConfig($router_id);
    }

    public function getDevices($limit = 500)
    {
        $limit = max(1, (int) $limit);
        $query = json_encode(array(), JSON_UNESCAPED_SLASHES);
        return $this->request('GET', '/devices?query=' . rawurlencode($query) . '&limit=' . $limit);
    }

    public function getDevice($serial)
    {
        $serial = trim((string) $serial);
        if ($serial === '') {
            return array('success' => false, 'message' => 'Serial kosong.', 'data' => null);
        }

        $query = json_encode(array(
            '$or' => array(
                array('_deviceId._SerialNumber' => $serial),
                array('DeviceID.SerialNumber' => $serial),
                array('InternetGatewayDevice.DeviceInfo.SerialNumber._value' => $serial),
                array('Device.DeviceInfo.SerialNumber._value' => $serial),
            ),
        ), JSON_UNESCAPED_SLASHES);

        $resp = $this->request('GET', '/devices?query=' . rawurlencode($query) . '&limit=1');
        if (empty($resp['success'])) {
            return array('success' => false, 'message' => (string) ($resp['message'] ?? 'Gagal ambil device.'), 'data' => null);
        }

        $rows = is_array($resp['data']) ? $resp['data'] : array();
        if (!empty($rows[0])) {
            return array('success' => true, 'message' => 'OK', 'data' => (array) $rows[0]);
        }

        // Fallback: scan list jika query path berbeda antar vendor.
        $all = $this->getDevices(2000);
        if (empty($all['success']) || !is_array($all['data'])) {
            return array('success' => false, 'message' => 'Device tidak ditemukan.', 'data' => null);
        }
        foreach ($all['data'] as $item) {
            $item = is_array($item) ? $item : array();
            if ($this->extractSerial($item) === $serial) {
                return array('success' => true, 'message' => 'OK', 'data' => $item);
            }
        }

        return array('success' => false, 'message' => 'Device tidak ditemukan.', 'data' => null);
    }

    public function getDeviceById($deviceId, array $projections = array())
    {
        $deviceId = trim((string) $deviceId);
        if ($deviceId === '') {
            return array('success' => false, 'message' => 'Device ID kosong.', 'data' => null);
        }

        $query = json_encode(array('_id' => $deviceId), JSON_UNESCAPED_SLASHES);
        $path = '/devices?query=' . rawurlencode($query) . '&limit=1';
        $projectionQuery = $this->buildProjectionQuery($projections);
        if ($projectionQuery !== '') {
            $path .= '&projection=' . $projectionQuery;
        }

        $resp = $this->request('GET', $path);
        if (empty($resp['success'])) {
            return array(
                'success' => false,
                'message' => (string) ($resp['message'] ?? 'Gagal ambil device by ID.'),
                'data' => null,
            );
        }

        $rows = is_array($resp['data']) ? $resp['data'] : array();
        if (empty($rows[0]) || !is_array($rows[0])) {
            return array('success' => false, 'message' => 'Device tidak ditemukan.', 'data' => null);
        }

        return array('success' => true, 'message' => 'OK', 'data' => $rows[0]);
    }

    public function deleteDevice($identifier)
    {
        $identifier = trim((string) $identifier);
        if ($identifier === '') {
            return array('success' => false, 'message' => 'Device ID/serial kosong.');
        }

        $deviceId = '';
        $byId = $this->getDeviceById($identifier, array('_id'));
        if (!empty($byId['success']) && !empty($byId['data']['_id'])) {
            $deviceId = (string) $byId['data']['_id'];
        }

        if ($deviceId === '') {
            $bySerial = $this->getDevice($identifier);
            if (!empty($bySerial['success']) && !empty($bySerial['data']['_id'])) {
                $deviceId = (string) $bySerial['data']['_id'];
            }
        }

        if ($deviceId === '') {
            return array('success' => false, 'message' => 'Device tidak ditemukan di GenieACS.');
        }

        return $this->deleteDeviceById($deviceId);
    }

    public function deleteDeviceById($deviceId)
    {
        $deviceId = trim((string) $deviceId);
        if ($deviceId === '') {
            return array('success' => false, 'message' => 'Device ID kosong.');
        }

        $resp = $this->request('DELETE', '/devices/' . rawurlencode($deviceId));
        if (!empty($resp['success'])) {
            return array(
                'success' => true,
                'message' => 'Device ' . $deviceId . ' berhasil dihapus dari GenieACS.',
                'device_id' => $deviceId,
            );
        }

        if ((int) ($resp['code'] ?? 0) === 404) {
            return array(
                'success' => true,
                'message' => 'Device ' . $deviceId . ' sudah tidak ada di GenieACS.',
                'device_id' => $deviceId,
            );
        }

        return array(
            'success' => false,
            'message' => (string) ($resp['message'] ?? 'Hapus device GenieACS gagal.'),
            'device_id' => $deviceId,
            'code' => (int) ($resp['code'] ?? 0),
        );
    }

    public function rebootDevice($serial)
    {
        $device = $this->getDevice($serial);
        if (empty($device['success']) || empty($device['data']['_id'])) {
            return array('success' => false, 'message' => (string) ($device['message'] ?? 'Device tidak ditemukan.'));
        }

        $deviceId = (string) $device['data']['_id'];
        $payload = array('name' => 'reboot');
        $resp = $this->request('POST', $this->taskPath($deviceId), $payload);
        if (empty($resp['success'])) {
            return array('success' => false, 'message' => (string) ($resp['message'] ?? 'Task reboot gagal.'));
        }
        return array('success' => true, 'message' => 'Task reboot berhasil dikirim ke antrean GenieACS.');
    }

    public function setWifi($serial, $ssid, $password)
    {
        $serial = trim((string) $serial);
        $ssid = trim((string) $ssid);
        $password = trim((string) $password);

        if ($serial === '' || $ssid === '' || strlen($password) < 8) {
            return array('success' => false, 'message' => 'Input set WiFi tidak valid.');
        }

        $device = $this->getDevice($serial);
        if (empty($device['success']) || !is_array($device['data'])) {
            return array('success' => false, 'message' => (string) ($device['message'] ?? 'Device tidak ditemukan.'));
        }

        $row = $device['data'];
        $deviceId = (string) ($row['_id'] ?? '');
        if ($deviceId === '') {
            return array('success' => false, 'message' => 'Device ID kosong.');
        }

        $isTr181 = $this->isTr181($row);
        if ($isTr181) {
            $params = array(
                array('Device.WiFi.SSID.1.SSID', $ssid, 'xsd:string'),
                array('Device.WiFi.AccessPoint.1.Security.KeyPassphrase', $password, 'xsd:string'),
            );
        } else {
            $params = array(
                array('InternetGatewayDevice.LANDevice.1.WLANConfiguration.1.SSID', $ssid, 'xsd:string'),
                array('InternetGatewayDevice.LANDevice.1.WLANConfiguration.1.PreSharedKey.1.KeyPassphrase', $password, 'xsd:string'),
            );
        }

        $taskPayload = array(
            'name' => 'setParameterValues',
            'parameterValues' => $params,
        );
        $setResp = $this->request('POST', $this->taskPath($deviceId), $taskPayload);
        if (empty($setResp['success'])) {
            return array('success' => false, 'message' => (string) ($setResp['message'] ?? 'Task set WiFi gagal.'));
        }

        $rebootResp = $this->request('POST', $this->taskPath($deviceId), array('name' => 'reboot'));
        if (empty($rebootResp['success'])) {
            return array('success' => false, 'message' => 'WiFi terset, tapi reboot gagal dikirim.');
        }

        return array('success' => true, 'message' => 'WiFi berhasil diupdate dan reboot task terkirim.');
    }

    public function refreshVirtualParameters($deviceId, array $parameterNames = array())
    {
        $deviceId = trim((string) $deviceId);
        if ($deviceId === '') {
            return array('success' => false, 'message' => 'Device ID kosong.');
        }

        if (empty($parameterNames)) {
            $parameterNames = array(
                'VirtualParameters.RXPower',
                'VirtualParameters.WlanPassword',
                'VirtualParameters.pppoeUsername',
                'VirtualParameters.pppoeIP',
            );
        }

        $normalized = array();
        foreach ($parameterNames as $name) {
            $name = trim((string) $name);
            if ($name === '') {
                continue;
            }
            $normalized[$name] = $name;
        }

        if (empty($normalized)) {
            return array('success' => false, 'message' => 'Daftar virtual parameter kosong.');
        }

        $payload = array(
            'name' => 'getParameterValues',
            'parameterNames' => array_values($normalized),
        );

        $resp = $this->request(
            'POST',
            $this->taskPath($deviceId),
            $payload
        );
        if (empty($resp['success'])) {
            return array(
                'success' => false,
                'message' => (string) ($resp['message'] ?? 'Gagal refresh virtual parameter.'),
            );
        }

        return array('success' => true, 'message' => 'Task refresh virtual parameter dikirim.');
    }

    public function refreshObject($deviceId, $objectName)
    {
        $deviceId = trim((string) $deviceId);
        $objectName = trim((string) $objectName);
        if ($deviceId === '' || $objectName === '') {
            return array('success' => false, 'message' => 'Device ID/objectName kosong.');
        }

        $resp = $this->request(
            'POST',
            $this->taskPath($deviceId),
            array('name' => 'refreshObject', 'objectName' => $objectName)
        );
        if (empty($resp['success'])) {
            return array(
                'success' => false,
                'message' => (string) ($resp['message'] ?? 'Gagal refresh object.'),
            );
        }

        return array('success' => true, 'message' => 'Task refresh object dikirim.');
    }

    public function extractSerial(array $row)
    {
        $candidates = array(
            $row['_deviceId']['_SerialNumber'] ?? null,
            $row['DeviceID']['SerialNumber'] ?? null,
            $row['InternetGatewayDevice.DeviceInfo.SerialNumber']['_value'] ?? null,
            $row['Device.DeviceInfo.SerialNumber']['_value'] ?? null,
            $row['SerialNumber']['_value'] ?? null,
        );
        foreach ($candidates as $candidate) {
            $candidate = trim((string) $candidate);
            if ($candidate !== '') {
                return $candidate;
            }
        }

        $id = trim((string) ($row['_id'] ?? ''));
        if ($id !== '' && strpos($id, '-') !== false) {
            return substr($id, strrpos($id, '-') + 1);
        }
        return $id;
    }

    public function extractManufacturer(array $row)
    {
        return $this->pickByPaths($row, array(
            '_deviceId._Manufacturer',
            'DeviceID.Manufacturer',
            'InternetGatewayDevice.DeviceInfo.Manufacturer._value',
            'Device.DeviceInfo.Manufacturer._value',
            'InternetGatewayDevice.DeviceInfo.ManufacturerOUI._value',
        ));
    }

    public function extractProductClass(array $row)
    {
        return $this->pickByPaths($row, array(
            '_deviceId._ProductClass',
            'DeviceID.ProductClass',
            'InternetGatewayDevice.DeviceInfo.ProductClass._value',
            'Device.DeviceInfo.ProductClass._value',
        ));
    }

    public function extractWanIp(array $row)
    {
        return $this->pickByPaths($row, array(
            'VirtualParameters.pppoeIP._value',
            'VirtualParameters.PPPoEIP._value',
            'InternetGatewayDevice.WANDevice.*.WANConnectionDevice.*.WANPPPConnection.*.ExternalIPAddress._value',
            'InternetGatewayDevice.WANDevice.*.WANConnectionDevice.*.WANIPConnection.*.ExternalIPAddress._value',
            'Device.IP.Interface.*.IPv4Address.*.IPAddress._value',
            'Device.IP.Interface.*.IPv6Address.*.IPAddress._value',
        ));
    }

    public function extractSsid(array $row)
    {
        $paths = array(
            'InternetGatewayDevice.LANDevice.1.WLANConfiguration.1.SSID._value',
            'InternetGatewayDevice.LANDevice.1.WLANConfiguration.1.X_ZTE-COM_SSID._value',
            'Device.WiFi.SSID.1.SSID._value',
            'InternetGatewayDevice.LANDevice.*.WLANConfiguration.*.SSID._value',
            'InternetGatewayDevice.LANDevice.*.WLANConfiguration.*.X_ZTE-COM_SSID._value',
            'Device.WiFi.SSID.*.SSID._value',
        );

        foreach ($paths as $path) {
            $values = $this->valuesByPath($row, $path);
            foreach ($values as $value) {
                $candidate = $this->toScalarString($value);
                if ($this->isUsableSsid($candidate)) {
                    return $candidate;
                }
            }
        }

        return '';
    }

    public function extractWifiPassword(array $row)
    {
        $paths = array(
            'VirtualParameters.WlanPassword._value',
            'VirtualParameters.wlanPassword._value',
            'VirtualParameters.WLANPassword._value',
            'InternetGatewayDevice.LANDevice.*.WLANConfiguration.*.PreSharedKey.*.KeyPassphrase._value',
            'InternetGatewayDevice.LANDevice.*.WLANConfiguration.*.PreSharedKey.*.PreSharedKey._value',
            'InternetGatewayDevice.LANDevice.*.WLANConfiguration.*.KeyPassphrase._value',
            'InternetGatewayDevice.LANDevice.*.WLANConfiguration.*.WPAKey.*.WPAKey._value',
            'InternetGatewayDevice.LANDevice.*.WLANConfiguration.*.WPAKey._value',
            'Device.WiFi.AccessPoint.*.Security.KeyPassphrase._value',
            'Device.WiFi.AccessPoint.*.Security.PreSharedKey._value',
        );

        foreach ($paths as $path) {
            $values = $this->valuesByPath($row, $path);
            foreach ($values as $value) {
                $candidate = $this->toScalarString($value);
                if ($this->isValidWifiPassword($candidate)) {
                    return $candidate;
                }
            }
        }

        return '';
    }

    public function isValidWifiPassword($value)
    {
        $value = trim((string) $value);
        if ($value === '') {
            return false;
        }

        $lower = strtolower($value);
        if (in_array($lower, array('null', 'none', 'undefined', 'n/a'), true)) {
            return false;
        }

        $length = strlen($value);
        if ($length < 8 || $length > 64) {
            return false;
        }

        return true;
    }

    public function extractPppoeUsername(array $row)
    {
        $serial = $this->extractSerial($row);
        $paths = array(
            'InternetGatewayDevice.WANDevice.*.WANConnectionDevice.*.WANPPPConnection.*.Username._value',
            'InternetGatewayDevice.WANDevice.*.WANConnectionDevice.*.WANPPPConnection.*.UserName._value',
            'Device.PPP.Interface.*.Username._value',
            'VirtualParameters.pppoeUsername._value',
            'VirtualParameters.pppoeUsername2._value',
            'VirtualParameters.PPPoEUsername._value',
            'InternetGatewayDevice.X_CU_UserInfo.UserName._value',
            'InternetGatewayDevice.X_CU_UserInfo.UserId._value',
            'InternetGatewayDevice.X_CMCC_UserInfo.UserName._value',
            'InternetGatewayDevice.X_CMCC_UserInfo.UserId._value',
            'InternetGatewayDevice.X_CT-COM_UserInfo.UserName._value',
            'InternetGatewayDevice.X_CT-COM_UserInfo.UserId._value',
            'InternetGatewayDevice.X_ZTE-COM_UserInfo.UserName._value',
        );

        foreach ($paths as $path) {
            $values = $this->valuesByPath($row, $path);
            foreach ($values as $value) {
                $candidate = $this->toScalarString($value);
                if ($candidate === '' || $this->isLikelyDeviceIdentifier($candidate, $serial)) {
                    continue;
                }
                return $candidate;
            }
        }

        return '';
    }

    public function extractOpticalRxDbm(array $row)
    {
        $known = $this->pickByPaths($row, array(
            'VirtualParameters.RXPower._value',
            'VirtualParameters.rxPower._value',
            'VirtualParameters.rxpower._value',
            'VirtualParameters.OpticalRxPower._value',
            'VirtualParameters.optical_rx_power._value',
            'InternetGatewayDevice.WANDevice.*.X_CT-COM_EponInterfaceConfig.RXPower._value',
            'InternetGatewayDevice.WANDevice.*.X_CT-COM_GponInterfaceConfig.RXPower._value',
            'InternetGatewayDevice.WANDevice.*.X_CMCC_EponInterfaceConfig.RXPower._value',
            'InternetGatewayDevice.WANDevice.*.X_CMCC_GponInterfaceConfig.RXPower._value',
            'InternetGatewayDevice.WANDevice.*.X_GponInterafceConfig.RXPower._value',
            'InternetGatewayDevice.WANDevice.*.X_FH_GponInterfaceConfig.RXPower._value',
            'InternetGatewayDevice.WANDevice.*.X_CT-COM_PONInterfaceConfig.RXPower._value',
            'InternetGatewayDevice.WANDevice.*.X_CT-COM_WANPONInterfaceConfig.RXPower._value',
            'InternetGatewayDevice.WANDevice.*.WANPONInterfaceConfig.RXPower._value',
            'InternetGatewayDevice.WANDevice.*.X_ZTE-COM_WANPONInterfaceConfig.RXPower._value',
            'InternetGatewayDevice.WANDevice.*.X_CU_WANEPONInterfaceConfig.OpticalTransceiver.RXPower._value',
            'InternetGatewayDevice.X_ALU_OntOpticalParam.RXPower._value',
            'Device.PON.Interface.*.OpticalSignalLevel._value',
            'Device.PON.Interface.*.RXPower._value',
            'Device.Optical.Interface.*.RxPower._value',
        ));
        if ($known !== '') {
            return $this->normalizeDbm($known);
        }

        return $this->scanFirstValueByKeyPattern($row, array(
            'rxpower',
            'rx_power',
            'opticalsignallevel',
            'optical_rx_power',
            'signallevel',
            'dbm',
        ));
    }

    public function extractLastInform(array $row)
    {
        return $this->firstString(array(
            $row['_lastInform'] ?? null,
            $row['Events.Inform']['_lastInform'] ?? null,
        ));
    }

    public function extractConnectionRequestUrl(array $row)
    {
        return $this->pickByPaths($row, array(
            'InternetGatewayDevice.ManagementServer.ConnectionRequestURL._value',
            'Device.ManagementServer.ConnectionRequestURL._value',
        ));
    }

    protected function isTr181(array $row)
    {
        return isset($row['Device.WiFi.SSID.1.SSID']) || isset($row['Device.DeviceInfo.SerialNumber']);
    }

    protected function firstString(array $values)
    {
        foreach ($values as $value) {
            $value = trim((string) $value);
            if ($value !== '') {
                return $value;
            }
        }
        return '';
    }

    protected function isUsableSsid($value)
    {
        $value = trim((string) $value);
        if ($value === '') {
            return false;
        }

        $upper = strtoupper($value);
        if (preg_match('/^SSID[0-9]*$/', $upper)) {
            return false;
        }

        if (in_array($upper, array('NULL', 'NONE', 'N/A'), true)) {
            return false;
        }

        return true;
    }

    protected function pickByPaths(array $row, array $paths)
    {
        foreach ($paths as $path) {
            $values = $this->valuesByPath($row, (string) $path);
            foreach ($values as $value) {
                $str = $this->toScalarString($value);
                if ($str !== '') {
                    return $str;
                }
            }
        }

        return '';
    }

    protected function valuesByPath($node, $path)
    {
        $segments = explode('.', (string) $path);
        return $this->valuesBySegments($node, $segments);
    }

    protected function valuesBySegments($node, array $segments)
    {
        if (empty($segments)) {
            return array($node);
        }

        $seg = array_shift($segments);
        $result = array();

        if ($seg === '*') {
            if (!is_array($node)) {
                return array();
            }
            foreach ($node as $child) {
                $result = array_merge($result, $this->valuesBySegments($child, $segments));
            }
            return $result;
        }

        if (!is_array($node) || !array_key_exists($seg, $node)) {
            return array();
        }

        return $this->valuesBySegments($node[$seg], $segments);
    }

    protected function toScalarString($value)
    {
        if (is_array($value)) {
            if (array_key_exists('_value', $value)) {
                return trim((string) $value['_value']);
            }
            return '';
        }
        if (is_object($value)) {
            return '';
        }
        return trim((string) $value);
    }

    protected function scanFirstValueByKeyPattern(array $row, array $patterns)
    {
        $it = new RecursiveIteratorIterator(new RecursiveArrayIterator($row));
        foreach ($it as $key => $value) {
            $path = array();
            for ($depth = 0; $depth <= $it->getDepth(); $depth++) {
                $path[] = (string) $it->getSubIterator($depth)->key();
            }
            $flat = strtolower(implode('.', $path));
            foreach ($patterns as $pattern) {
                if (strpos($flat, strtolower((string) $pattern)) === false) {
                    continue;
                }
                $str = $this->toScalarString($value);
                if ($str !== '') {
                    return $this->normalizeDbm($str);
                }
            }
        }

        return '';
    }

    protected function normalizeDbm($value)
    {
        $value = trim((string) $value);
        if ($value === '') {
            return '';
        }

        if (preg_match('/-?\\d+(?:\\.\\d+)?/', $value, $m)) {
            $num = (float) $m[0];
            if ($num > 0) {
                $num = $this->convertRawOpticalRxToDbm($num);
            }
            return $this->formatDbm($num);
        }

        return $value;
    }

    protected function convertRawOpticalRxToDbm($raw)
    {
        $raw = (float) $raw;
        if ($raw <= 0) {
            return $raw;
        }

        // Beberapa ONT ZTE/CMCC/CT-COM mengirim RXPower sebagai raw optical power.
        // Rumus ini sama dengan VirtualParameters.RXPower yang dipakai GenieACS lokal.
        return 30 + (log10($raw * pow(10, -7)) * 10);
    }

    protected function formatDbm($value)
    {
        $rounded = round((float) $value, 2);
        $text = number_format($rounded, 2, '.', '');
        $text = rtrim(rtrim($text, '0'), '.');
        return $text . ' dBm';
    }

    protected function isLikelyDeviceIdentifier($value, $serial = '')
    {
        $value = strtoupper(trim((string) $value));
        $serial = strtoupper(trim((string) $serial));
        if ($value === '') {
            return false;
        }

        if ($serial !== '' && $value === $serial) {
            return true;
        }
        if ($serial !== '' && strlen($value) >= 6 && substr($serial, -strlen($value)) === $value) {
            return true;
        }

        if (in_array($value, array('ADMIN', 'USER', 'ROOT', 'GLOBAL', 'XCU', 'XCT', 'CMCC', 'CU', 'CT'), true)) {
            return true;
        }

        if (!preg_match('/^[A-Z0-9]+$/', $value)) {
            return false;
        }

        return (bool) preg_match('/^(ZICG|ZXIC|CIOT|FHTT|CMDC|RTEG|ALCL|HWTC)[A-Z0-9]{6,}$/', $value);
    }

    protected function taskPath($deviceId)
    {
        $path = '/devices/' . rawurlencode((string) $deviceId) . '/tasks';
        if ($this->taskConnectionRequest) {
            $path .= '?connection_request';
        }
        return $path;
    }

    protected function buildProjectionQuery(array $projections)
    {
        if (empty($projections)) {
            return '';
        }

        $clean = array();
        foreach ($projections as $path) {
            $path = trim((string) $path);
            if ($path === '') {
                continue;
            }
            $clean[$path] = $path;
        }
        if (empty($clean)) {
            return '';
        }

        return rawurlencode(implode(',', array_values($clean)));
    }

    protected function request($method, $path, array $payload = null)
    {
        $method = strtoupper(trim((string) $method));
        $path = '/' . ltrim((string) $path, '/');

        if ($this->baseUrl === '') {
            return array(
                'success' => false,
                'message' => 'Config ACS NBI URL kosong untuk router #' . $this->routerId . '.',
                'data' => null,
                'code' => 0,
            );
        }

        $url = $this->baseUrl . $path;
        $headers = array('Accept: application/json');
        $ch = curl_init($url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_CUSTOMREQUEST, $method);
        curl_setopt($ch, CURLOPT_TIMEOUT, $this->timeout);
        curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);

        if (stripos($url, 'https://') === 0 && !$this->verifySsl) {
            curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
            curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, 0);
        }

        if ($this->acsUsername !== '') {
            curl_setopt($ch, CURLOPT_HTTPAUTH, CURLAUTH_BASIC);
            curl_setopt($ch, CURLOPT_USERPWD, $this->acsUsername . ':' . $this->acsPassword);
        }

        if ($payload !== null) {
            $json = json_encode($payload, JSON_UNESCAPED_SLASHES);
            $headers[] = 'Content-Type: application/json';
            curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
            curl_setopt($ch, CURLOPT_POSTFIELDS, $json);
        }

        $raw = curl_exec($ch);
        $err = curl_error($ch);
        $httpCode = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($raw === false) {
            return array(
                'success' => false,
                'message' => 'Request GenieACS gagal: ' . ($err !== '' ? $err : 'unknown error'),
                'data' => null,
                'code' => 0,
            );
        }

        $decoded = json_decode($raw, true);
        if ($httpCode >= 200 && $httpCode < 300) {
            return array(
                'success' => true,
                'message' => 'OK',
                'data' => $decoded !== null ? $decoded : $raw,
                'code' => $httpCode,
            );
        }

        return array(
            'success' => false,
            'message' => 'HTTP ' . $httpCode . ' dari GenieACS router #' . $this->routerId . '.',
            'data' => $decoded !== null ? $decoded : $raw,
            'code' => $httpCode,
        );
    }

    protected function bootstrapRouterConfig($router_id)
    {
        if (!$this->CI->db->table_exists('routers')) {
            show_error('Tabel routers tidak tersedia.', 500);
            return;
        }

        $fields = $this->CI->db->list_fields('routers');
        $required = array('acs_nbi_url');
        foreach ($required as $col) {
            if (!in_array($col, $fields, true)) {
                show_error('Kolom routers.' . $col . ' belum tersedia. Jalankan migration multi ACS.', 500);
                return;
            }
        }

        $name_col = in_array('name', $fields, true)
            ? 'name'
            : (in_array('router_name', $fields, true) ? 'router_name' : 'id');

        $select_cols = array('id', $name_col . ' AS router_name', 'acs_nbi_url');
        if (in_array('acs_username', $fields, true)) {
            $select_cols[] = 'acs_username';
        }
        if (in_array('acs_password', $fields, true)) {
            $select_cols[] = 'acs_password';
        }

        $router = $this->CI->db
            ->select(implode(', ', $select_cols), false)
            ->from('routers')
            ->where('id', (int) $router_id)
            ->limit(1)
            ->get()
            ->row();

        if (!$router) {
            show_error('Router tidak ditemukan (ID: ' . (int) $router_id . ').', 404);
            return;
        }

        $this->routerName = trim((string) ($router->router_name ?? ('Router #' . (int) $router_id)));
        $this->baseUrl = rtrim((string) ($router->acs_nbi_url ?? ''), '/');
        $this->acsUsername = trim((string) ($router->acs_username ?? ''));
        $this->acsPassword = $this->decryptSecret((string) ($router->acs_password ?? ''));

        if ($this->baseUrl === '') {
            show_error('ACS NBI URL belum diset untuk router ' . $this->routerName . '.', 500);
            return;
        }
    }

    protected function decryptSecret($raw)
    {
        $raw = trim((string) $raw);
        if ($raw === '') {
            return '';
        }

        if ($this->settingsModel && method_exists($this->settingsModel, 'decrypt_secret')) {
            $decoded = (string) $this->settingsModel->decrypt_secret($raw);
            if ($decoded !== '') {
                return $decoded;
            }
        }

        $fallback = $this->CI->encryption->decrypt($raw);
        if (is_string($fallback) && trim($fallback) !== '') {
            return trim($fallback);
        }

        return $raw;
    }
}
