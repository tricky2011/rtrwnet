<?php
defined('BASEPATH') OR exit('No direct script access allowed');

class Tr069_acs_model extends CI_Model
{
    private $cfg = array();
    private $customer_fields = array();

    public function __construct()
    {
        parent::__construct();
        $this->load->config('tr069', true);
        $this->cfg = (array) $this->config->item('tr069');

        if ($this->db->table_exists('customers')) {
            $this->customer_fields = $this->db->list_fields('customers');
        }
    }

    public function get_customer_ont($customer_id)
    {
        $customer_id = (int) $customer_id;
        if ($customer_id <= 0) {
            return array('success' => false, 'message' => 'customer_id tidak valid.');
        }

        if (!$this->db->table_exists('customers')) {
            return array('success' => false, 'message' => 'Tabel customers tidak ditemukan.');
        }

        $required = array('ont_device_id', 'tr069_profile');
        foreach ($required as $field) {
            if (!$this->has_customer_field($field)) {
                return array(
                    'success' => false,
                    'message' => 'Kolom `' . $field . '` belum ada. Jalankan migration TR-069 terlebih dahulu.',
                );
            }
        }

        $row = $this->db->where('id', $customer_id)->get('customers')->row_array();
        if (empty($row)) {
            return array('success' => false, 'message' => 'Customer tidak ditemukan.');
        }

        $device_id = trim((string) ($row['ont_device_id'] ?? ''));
        if ($device_id === '') {
            return array('success' => false, 'message' => 'Customer belum memiliki ont_device_id.');
        }

        return array(
            'success' => true,
            'message' => 'OK',
            'data' => array(
                'id' => (int) ($row['id'] ?? 0),
                'customer_name' => (string) ($row['full_name'] ?? $row['nama'] ?? ''),
                'ont_serial' => (string) ($row['ont_serial'] ?? ''),
                'ont_device_id' => $device_id,
                'ont_model' => (string) ($row['ont_model'] ?? ''),
                'tr069_profile' => (string) ($row['tr069_profile'] ?? 'auto'),
            ),
        );
    }

    public function set_wifi($customer_id, $ssid, $password)
    {
        $ssid = trim((string) $ssid);
        $password = trim((string) $password);

        if ($ssid === '' || $password === '') {
            return array('success' => false, 'message' => 'SSID dan password wajib diisi.');
        }

        $customer = $this->get_customer_ont($customer_id);
        if (empty($customer['success'])) {
            return $customer;
        }

        $customer_data = $customer['data'];
        $device_id = (string) $customer_data['ont_device_id'];
        $profile_sequence = $this->resolve_profile_sequence((string) $customer_data['tr069_profile']);

        $errors = array();

        foreach ($profile_sequence as $profile_key) {
            $map = $this->get_parameter_map($profile_key);
            if (empty($map)) {
                $errors[] = 'Mapping parameter `' . $profile_key . '` tidak ditemukan.';
                continue;
            }

            $set_result = $this->set_parameter_values($device_id, array(
                $map['ssid'] => $ssid,
                $map['password'] => $password,
            ), $profile_key);

            if (!empty($set_result['success'])) {
                $reboot = $this->reboot_by_device_id($device_id);
                if (empty($reboot['success'])) {
                    return array(
                        'success' => false,
                        'message' => 'Parameter tersimpan namun reboot gagal: ' . (string) $reboot['message'],
                    );
                }

                return array(
                    'success' => true,
                    'message' => 'WiFi ONT berhasil diupdate dan ONT direboot.',
                    'data' => array(
                        'customer_id' => (int) $customer_data['id'],
                        'device_id' => $device_id,
                        'profile_used' => $profile_key,
                        'ssid' => $ssid,
                    ),
                );
            }

            $errors[] = (string) ($set_result['message'] ?? 'Unknown error');
        }

        return array(
            'success' => false,
            'message' => 'Set WiFi gagal pada semua fallback parameter.',
            'errors' => $errors,
        );
    }

    public function reboot_ont($customer_id)
    {
        $customer = $this->get_customer_ont($customer_id);
        if (empty($customer['success'])) {
            return $customer;
        }

        return $this->reboot_by_device_id((string) $customer['data']['ont_device_id']);
    }

    public function get_connected_devices($customer_id)
    {
        $customer = $this->get_customer_ont($customer_id);
        if (empty($customer['success'])) {
            return $customer;
        }

        $customer_data = $customer['data'];
        $device_id = (string) $customer_data['ont_device_id'];
        $profile_sequence = $this->resolve_profile_sequence((string) $customer_data['tr069_profile']);

        if ($this->mode() === 'rest') {
            return $this->rest_connected_devices($device_id);
        }

        $context = $this->soap_get_unit_context($device_id);
        if (empty($context['success'])) {
            return $context;
        }

        $parameters = isset($context['data']['parameters']) && is_array($context['data']['parameters'])
            ? $context['data']['parameters']
            : array();

        $hosts = array();
        foreach ($profile_sequence as $profile_key) {
            $map = $this->get_parameter_map($profile_key);
            if (empty($map) || empty($map['hosts_root'])) {
                continue;
            }
            $hosts = $this->extract_hosts_from_parameters($parameters, $map['hosts_root']);
            if (!empty($hosts)) {
                break;
            }
        }

        return array(
            'success' => true,
            'message' => 'OK',
            'data' => array(
                'customer_id' => (int) $customer_data['id'],
                'device_id' => $device_id,
                'hosts' => array_values($hosts),
            ),
        );
    }

    public function set_parameter_values($device_id, array $parameters, $profile_hint = 'auto')
    {
        $device_id = trim((string) $device_id);
        if ($device_id === '') {
            return array('success' => false, 'message' => 'device_id kosong.');
        }

        if (empty($parameters)) {
            return array('success' => false, 'message' => 'Parameter kosong.');
        }

        if ($this->mode() === 'rest') {
            return $this->rest_set_parameters($device_id, $parameters, $profile_hint);
        }

        $context = $this->soap_get_unit_context($device_id);
        if (empty($context['success'])) {
            return $context;
        }

        $ctx = $context['data'];
        if (empty($ctx['unittype']) || empty($ctx['profile'])) {
            return array(
                'success' => false,
                'message' => 'Context unit tidak lengkap (unittype/profile kosong).',
            );
        }

        return $this->soap_add_or_change_unit_parameters(
            $device_id,
            (string) $ctx['unittype'],
            (string) $ctx['profile'],
            $parameters,
            (string) $profile_hint
        );
    }

    private function rest_set_parameters($device_id, array $parameters, $profile_hint)
    {
        $path = '/api/devices/' . rawurlencode($device_id) . '/tasks/set-parameters';
        $payload = array(
            'parameters' => $parameters,
            'profile_hint' => (string) $profile_hint,
        );

        $resp = $this->rest_call('POST', $path, $payload);
        if (empty($resp['success'])) {
            return $resp;
        }

        return array(
            'success' => true,
            'message' => 'Set parameter berhasil dikirim ke ACS REST bridge.',
            'data' => $resp['data'],
        );
    }

    private function rest_connected_devices($device_id)
    {
        $path = '/api/devices/' . rawurlencode($device_id) . '/connected-devices';
        $resp = $this->rest_call('GET', $path, null);
        if (empty($resp['success'])) {
            return $resp;
        }

        $hosts = array();
        $rows = isset($resp['data']['hosts']) && is_array($resp['data']['hosts'])
            ? $resp['data']['hosts']
            : (is_array($resp['data']) ? $resp['data'] : array());

        foreach ($rows as $row) {
            if (!is_array($row)) {
                continue;
            }
            $hosts[] = array(
                'hostname' => trim((string) ($row['hostname'] ?? '')),
                'mac' => trim((string) ($row['mac'] ?? '')),
                'ip' => trim((string) ($row['ip'] ?? '')),
            );
        }

        return array(
            'success' => true,
            'message' => 'OK',
            'data' => array(
                'device_id' => $device_id,
                'hosts' => array_values($hosts),
            ),
        );
    }

    private function reboot_by_device_id($device_id)
    {
        $device_id = trim((string) $device_id);
        if ($device_id === '') {
            return array('success' => false, 'message' => 'device_id kosong.');
        }

        if ($this->mode() === 'rest') {
            $path = '/api/devices/' . rawurlencode($device_id) . '/tasks/reboot';
            $resp = $this->rest_call('POST', $path, array('reason' => 'RTRWNet API reboot task'));
            if (empty($resp['success'])) {
                return $resp;
            }

            return array(
                'success' => true,
                'message' => 'Task reboot berhasil dikirim ke ACS REST bridge.',
                'data' => $resp['data'],
            );
        }

        return $this->soap_kick_unit($device_id);
    }

    private function soap_get_unit_context($device_id)
    {
        $body =
            '<ws:GetUnitsRequest>'
            . $this->soap_login_xml()
            . '<ws:unit>'
            . '<ws:unitId>' . $this->xml_escape($device_id) . '</ws:unitId>'
            . '</ws:unit>'
            . '</ws:GetUnitsRequest>';

        $resp = $this->soap_call($body);
        if (empty($resp['success'])) {
            return $resp;
        }

        $parsed = $this->parse_soap_units((string) $resp['body']);
        if (empty($parsed['success'])) {
            return $parsed;
        }

        $units = isset($parsed['data']) && is_array($parsed['data']) ? $parsed['data'] : array();
        if (empty($units)) {
            return array('success' => false, 'message' => 'Unit tidak ditemukan di FreeACS.');
        }

        return array(
            'success' => true,
            'message' => 'OK',
            'data' => $units[0],
        );
    }

    private function soap_add_or_change_unit_parameters($device_id, $unittype, $profile, array $parameters, $profile_hint = 'auto')
    {
        $parameter_xml = '';
        foreach ($parameters as $name => $value) {
            $name = trim((string) $name);
            if ($name === '') {
                continue;
            }

            $parameter_xml .= '<ws:item>'
                . '<ws:name>' . $this->xml_escape($name) . '</ws:name>'
                . '<ws:value>' . $this->xml_escape((string) $value) . '</ws:value>'
                . '<ws:flags>AC</ws:flags>'
                . '</ws:item>';
        }

        if ($parameter_xml === '') {
            return array('success' => false, 'message' => 'Parameter WiFi kosong setelah normalisasi.');
        }

        $body =
            '<ws:AddOrChangeUnitRequest>'
            . $this->soap_login_xml()
            . '<ws:unit>'
            . '<ws:unitId>' . $this->xml_escape($device_id) . '</ws:unitId>'
            . '<ws:profile><ws:name>' . $this->xml_escape($profile) . '</ws:name></ws:profile>'
            . '<ws:unittype><ws:name>' . $this->xml_escape($unittype) . '</ws:name></ws:unittype>'
            . '<ws:parameters><ws:parameterArray>' . $parameter_xml . '</ws:parameterArray></ws:parameters>'
            . '</ws:unit>'
            . '</ws:AddOrChangeUnitRequest>';

        $resp = $this->soap_call($body);
        if (empty($resp['success'])) {
            return $resp;
        }

        return array(
            'success' => true,
            'message' => 'SetParameterValues via AddOrChangeUnit berhasil (' . $profile_hint . ').',
            'data' => array('device_id' => $device_id),
        );
    }

    private function soap_kick_unit($device_id)
    {
        $this->apply_task_throttle();

        $body =
            '<ws:KickUnitRequest>'
            . $this->soap_login_xml()
            . '<ws:unitId>' . $this->xml_escape($device_id) . '</ws:unitId>'
            . '</ws:KickUnitRequest>';

        $resp = $this->soap_call($body);
        if (empty($resp['success'])) {
            return $resp;
        }

        return array(
            'success' => true,
            'message' => 'Reboot task (KickUnit) berhasil dikirim.',
            'data' => array('device_id' => $device_id),
        );
    }

    private function soap_call($inner_body_xml)
    {
        $soap_url = trim((string) ($this->cfg['tr069_freeacs_soap_url'] ?? ''));
        $login_user = (string) ($this->cfg['tr069_freeacs_soap_login_user'] ?? '');

        if ($soap_url === '') {
            return array('success' => false, 'message' => 'Config tr069_freeacs_soap_url kosong.');
        }

        if ($login_user === '') {
            return array('success' => false, 'message' => 'Config tr069_freeacs_soap_login_user kosong.');
        }

        $xml =
            '<?xml version="1.0" encoding="UTF-8"?>'
            . '<soapenv:Envelope xmlns:soapenv="http://schemas.xmlsoap.org/soap/envelope/" xmlns:ws="http://xml.ws.freeacs.github.com/">'
            . '<soapenv:Header/>'
            . '<soapenv:Body>' . $inner_body_xml . '</soapenv:Body>'
            . '</soapenv:Envelope>';

        $ch = curl_init($soap_url);
        curl_setopt_array($ch, array(
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => $xml,
            CURLOPT_HTTPHEADER => array(
                'Content-Type: text/xml; charset=utf-8',
                'Accept: text/xml',
            ),
            CURLOPT_TIMEOUT => max(5, (int) ($this->cfg['tr069_freeacs_timeout'] ?? 20)),
        ));

        $http_user = trim((string) ($this->cfg['tr069_freeacs_http_username'] ?? ''));
        $http_pass = (string) ($this->cfg['tr069_freeacs_http_password'] ?? '');
        if ($http_user !== '') {
            curl_setopt($ch, CURLOPT_HTTPAUTH, CURLAUTH_BASIC);
            curl_setopt($ch, CURLOPT_USERPWD, $http_user . ':' . $http_pass);
        }

        if (empty($this->cfg['tr069_freeacs_verify_ssl'])) {
            curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
            curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, 0);
        }

        $response = curl_exec($ch);
        $curl_error = curl_error($ch);
        $http_code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($response === false) {
            log_message('error', '[TR069][SOAP] cURL error: ' . $curl_error);
            return array('success' => false, 'message' => 'Gagal koneksi SOAP: ' . $curl_error);
        }

        $fault = $this->extract_soap_fault((string) $response);
        if ($fault !== '') {
            log_message('error', '[TR069][SOAP] Fault: ' . $fault);
            return array('success' => false, 'message' => 'SOAP Fault: ' . $fault, 'raw' => $response);
        }

        if ($http_code >= 400) {
            log_message('error', '[TR069][SOAP] HTTP ' . $http_code . ' body: ' . substr((string) $response, 0, 1000));
            return array('success' => false, 'message' => 'HTTP error dari FreeACS: ' . $http_code, 'raw' => $response);
        }

        return array(
            'success' => true,
            'message' => 'OK',
            'body' => (string) $response,
            'http_code' => $http_code,
        );
    }

    private function rest_call($method, $path, $payload = null)
    {
        $base = rtrim((string) ($this->cfg['tr069_freeacs_rest_base_url'] ?? ''), '/');
        if ($base === '') {
            return array(
                'success' => false,
                'message' => 'Config tr069_freeacs_rest_base_url kosong. Gunakan mode SOAP atau isi URL REST bridge.',
            );
        }

        $url = $base . '/' . ltrim((string) $path, '/');
        $method = strtoupper((string) $method);

        $headers = array('Accept: application/json');
        $token = trim((string) ($this->cfg['tr069_freeacs_rest_token'] ?? ''));
        if ($token !== '') {
            $headers[] = 'Authorization: Bearer ' . $token;
        }

        $ch = curl_init($url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_TIMEOUT, max(5, (int) ($this->cfg['tr069_freeacs_timeout'] ?? 20)));

        if ($method === 'POST') {
            curl_setopt($ch, CURLOPT_POST, true);
        } elseif ($method !== 'GET') {
            curl_setopt($ch, CURLOPT_CUSTOMREQUEST, $method);
        }

        if ($payload !== null) {
            $json = json_encode($payload);
            $headers[] = 'Content-Type: application/json';
            curl_setopt($ch, CURLOPT_POSTFIELDS, $json);
        }

        $http_user = trim((string) ($this->cfg['tr069_freeacs_http_username'] ?? ''));
        $http_pass = (string) ($this->cfg['tr069_freeacs_http_password'] ?? '');
        if ($http_user !== '') {
            curl_setopt($ch, CURLOPT_HTTPAUTH, CURLAUTH_BASIC);
            curl_setopt($ch, CURLOPT_USERPWD, $http_user . ':' . $http_pass);
        }

        if (!empty($headers)) {
            curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
        }

        if (empty($this->cfg['tr069_freeacs_verify_ssl'])) {
            curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
            curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, 0);
        }

        $body = curl_exec($ch);
        $curl_error = curl_error($ch);
        $http_code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($body === false) {
            return array('success' => false, 'message' => 'REST bridge unreachable: ' . $curl_error);
        }

        $decoded = json_decode((string) $body, true);
        if ($http_code >= 400) {
            $message = is_array($decoded) && !empty($decoded['message'])
                ? (string) $decoded['message']
                : ('REST bridge HTTP ' . $http_code);
            return array('success' => false, 'message' => $message, 'raw' => $body);
        }

        return array(
            'success' => true,
            'message' => 'OK',
            'data' => is_array($decoded) ? $decoded : array('raw' => $body),
        );
    }

    private function parse_soap_units($xml_string)
    {
        $xml = $this->load_xml($xml_string);
        if (!$xml) {
            return array('success' => false, 'message' => 'Response SOAP tidak valid XML.');
        }

        $units = array();
        $unit_items = $xml->xpath("//*[local-name()='GetUnitsResponse']//*[local-name()='unitArray']/*[local-name()='item']");
        if (!is_array($unit_items)) {
            $unit_items = array();
        }

        foreach ($unit_items as $item) {
            $unit_id = $this->xpath_text($item, "./*[local-name()='unitId']");
            if ($unit_id === '') {
                continue;
            }

            $unittype = $this->xpath_text($item, "./*[local-name()='unittype']/*[local-name()='name']");
            $profile = $this->xpath_text($item, "./*[local-name()='profile']/*[local-name()='name']");

            $params = array();
            $param_items = $item->xpath(".//*[local-name()='parameterArray']/*[local-name()='item']");
            if (is_array($param_items)) {
                foreach ($param_items as $pi) {
                    $name = $this->xpath_text($pi, "./*[local-name()='name']");
                    if ($name === '') {
                        continue;
                    }
                    $params[$name] = $this->xpath_text($pi, "./*[local-name()='value']");
                }
            }

            $units[] = array(
                'unit_id' => $unit_id,
                'unittype' => $unittype,
                'profile' => $profile,
                'parameters' => $params,
            );
        }

        return array('success' => true, 'message' => 'OK', 'data' => $units);
    }

    private function extract_hosts_from_parameters(array $parameters, $root)
    {
        $root = trim((string) $root);
        if ($root === '') {
            return array();
        }

        $regex = '/^' . preg_quote($root, '/') . '([0-9]+)\.([A-Za-z0-9_\-]+)$/';
        $rows = array();

        foreach ($parameters as $name => $value) {
            $name = (string) $name;
            if (!preg_match($regex, $name, $m)) {
                continue;
            }

            $idx = (string) $m[1];
            $field = strtolower((string) $m[2]);
            if (!isset($rows[$idx])) {
                $rows[$idx] = array('hostname' => '', 'mac' => '', 'ip' => '');
            }

            if (in_array($field, array('hostname', 'alias', 'x_zte-com_hostname'), true)) {
                $rows[$idx]['hostname'] = trim((string) $value);
            }

            if (in_array($field, array('physaddress', 'macaddress', 'mac'), true)) {
                $rows[$idx]['mac'] = trim((string) $value);
            }

            if ($field === 'ipaddress' || $field === 'ip') {
                $rows[$idx]['ip'] = trim((string) $value);
            }
        }

        $hosts = array();
        foreach ($rows as $row) {
            if ($row['hostname'] === '' && $row['mac'] === '' && $row['ip'] === '') {
                continue;
            }
            $hosts[] = $row;
        }

        return $hosts;
    }

    private function resolve_profile_sequence($profile)
    {
        $profile = strtolower(trim((string) $profile));
        if (in_array($profile, array('tr181', '181', 'device'), true)) {
            return array('tr181', 'tr098');
        }

        if (in_array($profile, array('tr098', '098', 'igd', 'internetgatewaydevice'), true)) {
            return array('tr098', 'tr181');
        }

        return array('tr181', 'tr098');
    }

    private function get_parameter_map($profile_key)
    {
        $maps = isset($this->cfg['tr069_parameter_map']) && is_array($this->cfg['tr069_parameter_map'])
            ? $this->cfg['tr069_parameter_map']
            : array();

        return isset($maps[$profile_key]) && is_array($maps[$profile_key])
            ? $maps[$profile_key]
            : array();
    }

    private function mode()
    {
        $mode = strtolower(trim((string) ($this->cfg['tr069_freeacs_mode'] ?? 'soap')));
        return $mode === 'rest' ? 'rest' : 'soap';
    }

    private function soap_login_xml()
    {
        $user = (string) ($this->cfg['tr069_freeacs_soap_login_user'] ?? '');
        $pass = (string) ($this->cfg['tr069_freeacs_soap_login_pass'] ?? '');

        return '<ws:login>'
            . '<ws:username>' . $this->xml_escape($user) . '</ws:username>'
            . '<ws:password>' . $this->xml_escape($pass) . '</ws:password>'
            . '</ws:login>';
    }

    private function extract_soap_fault($xml_string)
    {
        $xml = $this->load_xml($xml_string);
        if (!$xml) {
            return '';
        }

        $fault = $xml->xpath("//*[local-name()='Fault']");
        if (!is_array($fault) || empty($fault)) {
            return '';
        }

        $fault_node = $fault[0];
        $fault_string = $this->xpath_text($fault_node, ".//*[local-name()='faultstring']");
        if ($fault_string !== '') {
            return $fault_string;
        }

        $reason = $this->xpath_text($fault_node, ".//*[local-name()='Reason']/*[local-name()='Text']");
        return $reason;
    }

    private function load_xml($xml_string)
    {
        libxml_use_internal_errors(true);
        $xml = simplexml_load_string((string) $xml_string, 'SimpleXMLElement', LIBXML_NOCDATA);
        if ($xml === false) {
            libxml_clear_errors();
            return false;
        }

        return $xml;
    }

    private function xpath_text($node, $xpath)
    {
        if (!($node instanceof SimpleXMLElement)) {
            return '';
        }

        $result = $node->xpath((string) $xpath);
        if (!is_array($result) || empty($result)) {
            return '';
        }

        return trim((string) $result[0]);
    }

    private function has_customer_field($field)
    {
        return in_array((string) $field, $this->customer_fields, true);
    }

    private function xml_escape($value)
    {
        return htmlspecialchars((string) $value, ENT_XML1 | ENT_COMPAT, 'UTF-8');
    }

    private function apply_task_throttle()
    {
        $seconds = (int) ($this->cfg['tr069_task_rate_limit_seconds'] ?? 0);
        if ($seconds > 0) {
            usleep($seconds * 1000000);
        }
    }
}
