<?php
defined('BASEPATH') OR exit('No direct script access allowed');

class NetworkMap_model extends CI_Model
{
    private $table_routers = 'routers';
    private $table_customers = 'customers';
    private $table_odp = 'fiber_odp';
    private $table_odc = 'fiber_odc';
    private $table_olts = 'master_olts';
    private $router_scope_id = null;

    public function __construct()
    {
        parent::__construct();
        $this->load->database();
        if (!$this->db->table_exists($this->table_olts) && $this->db->table_exists('master_olt')) {
            $this->table_olts = 'master_olt';
        }
    }

    public function set_router_scope($router_id = null)
    {
        $router_id = (int) $router_id;
        $this->router_scope_id = $router_id > 0 ? $router_id : null;
        return $this;
    }

    public function get_all_routers($router_id = 0)
    {
        if (!$this->db->table_exists($this->table_routers)) {
            return array();
        }

        $requested_router_id = $this->resolve_requested_router_id($router_id);
        $fields = $this->db->list_fields($this->table_routers);

        $has_name = in_array('name', $fields, true);
        $has_router_name = in_array('router_name', $fields, true);
        if ($has_name && $has_router_name) {
            $name_expr = "COALESCE(NULLIF(`name`, ''), NULLIF(`router_name`, ''), CONCAT('Router #', `id`))";
        } elseif ($has_name) {
            $name_expr = "COALESCE(NULLIF(`name`, ''), CONCAT('Router #', `id`))";
        } elseif ($has_router_name) {
            $name_expr = "COALESCE(NULLIF(`router_name`, ''), CONCAT('Router #', `id`))";
        } else {
            $name_expr = "CONCAT('Router #', `id`)";
        }

        $status_expr = in_array('is_active', $fields, true)
            ? "IF(`is_active` = 1, 'active', 'inactive')"
            : (in_array('status', $fields, true) ? "LOWER(`status`)" : "'active'");

        $latitude_expr = in_array('latitude', $fields, true) ? '`latitude`' : 'NULL';
        $longitude_expr = in_array('longitude', $fields, true) ? '`longitude`' : 'NULL';
        $ip_address_expr = in_array('ip_address', $fields, true)
            ? '`ip_address`'
            : (in_array('api_host', $fields, true) ? '`api_host`' : "''");
        $api_port_expr = in_array('api_port', $fields, true) ? '`api_port`' : '8728';
        $username_expr = in_array('username', $fields, true)
            ? '`username`'
            : (in_array('api_username', $fields, true) ? '`api_username`' : "''");
        $description_expr = in_array('description', $fields, true) ? '`description`' : "''";

        $qb = $this->db
            ->select("`id`, {$name_expr} AS `name`, {$latitude_expr} AS `latitude`, {$longitude_expr} AS `longitude`, {$status_expr} AS `status`, {$ip_address_expr} AS `ip_address`, {$api_port_expr} AS `api_port`, {$username_expr} AS `username`, {$description_expr} AS `description`", false)
            ->from($this->table_routers);

        if ($requested_router_id > 0) {
            $qb->where('id', $requested_router_id);
        }
        if (in_array('is_active', $fields, true)) {
            $qb->where('is_active', 1);
        }

        $qb->order_by($name_expr, 'ASC', false);
        $rows = $qb->get()->result_array();

        $result = array();
        foreach ($rows as $row) {
            $router_id_row = (int) ($row['id'] ?? 0);
            if ($router_id_row <= 0) {
                continue;
            }
            $result[] = array(
                'id' => $router_id_row,
                'name' => trim((string) ($row['name'] ?? ('Router #' . $router_id_row))),
                'latitude' => $this->to_float_or_null($row['latitude'] ?? null),
                'longitude' => $this->to_float_or_null($row['longitude'] ?? null),
                'status' => $this->normalize_status($row['status'] ?? 'active'),
                'router_id' => $router_id_row,
                'metadata' => array(
                    'ip_address' => (string) ($row['ip_address'] ?? ''),
                    'api_port' => (int) ($row['api_port'] ?? 8728),
                    'username' => (string) ($row['username'] ?? ''),
                    'description' => (string) ($row['description'] ?? ''),
                ),
            );
        }

        return $result;
    }

    public function get_router_row($id)
    {
        $id = (int) $id;
        if ($id <= 0) {
            return array();
        }

        $rows = $this->get_all_routers($id);
        return !empty($rows[0]) ? $rows[0] : array();
    }

    public function update_router_geo($id, array $payload)
    {
        if (!$this->db->table_exists($this->table_routers)) {
            return false;
        }

        $id = (int) $id;
        if ($id <= 0) {
            return false;
        }

        $fields = $this->db->list_fields($this->table_routers);
        $data = array();

        if (in_array('latitude', $fields, true)) {
            $lat = trim((string) ($payload['latitude'] ?? ''));
            $data['latitude'] = $lat === '' ? null : (float) $lat;
        }
        if (in_array('longitude', $fields, true)) {
            $lng = trim((string) ($payload['longitude'] ?? ''));
            $data['longitude'] = $lng === '' ? null : (float) $lng;
        }
        if (in_array('updated_at', $fields, true)) {
            $data['updated_at'] = date('Y-m-d H:i:s');
        }

        if (empty($data)) {
            return true;
        }

        return $this->db->where('id', $id)->update($this->table_routers, $data);
    }

    public function get_olts_by_router($router_id = 0)
    {
        if (!$this->db->table_exists($this->table_olts)) {
            return array();
        }

        $requested_router_id = $this->resolve_requested_router_id($router_id);
        $fields = $this->db->list_fields($this->table_olts);

        $has_router = in_array('router_id', $fields, true);
        $has_latitude = in_array('latitude', $fields, true);
        $has_longitude = in_array('longitude', $fields, true);
        $has_desc = in_array('description', $fields, true);
        $has_active = in_array('is_active', $fields, true);
        $has_name = in_array('name', $fields, true);

        $name_expr = $has_name ? '`o`.`name`' : "CONCAT('OLT #', `o`.`id`)";
        $router_expr = $has_router ? '`o`.`router_id`' : '0';
        $status_expr = $has_active ? "IF(`o`.`is_active` = 1, 'active', 'inactive')" : "'active'";
        $lat_expr = $has_latitude ? '`o`.`latitude`' : 'NULL';
        $lng_expr = $has_longitude ? '`o`.`longitude`' : 'NULL';
        $desc_expr = $has_desc ? '`o`.`description`' : "''";

        $qb = $this->db
            ->select("`o`.`id`, {$name_expr} AS `name`, {$router_expr} AS `router_id`, {$lat_expr} AS `latitude`, {$lng_expr} AS `longitude`, {$status_expr} AS `status`, {$desc_expr} AS `description`", false)
            ->from($this->table_olts . ' o');

        if ($has_router && $requested_router_id > 0) {
            $qb->where('o.router_id', $requested_router_id);
        }
        if ($has_active) {
            $qb->where('o.is_active', 1);
        }

        $qb->order_by('o.id', 'ASC');
        $rows = $qb->get()->result_array();

        $odp_count_map = $this->get_odp_count_map($requested_router_id);
        $odc_count_map = $this->get_odc_count_map($requested_router_id);
        $onu_count_map = $this->get_onu_count_map($requested_router_id);
        $router_name_map = $this->get_router_name_map($requested_router_id);

        $result = array();
        foreach ($rows as $row) {
            $olt_id = (int) ($row['id'] ?? 0);
            if ($olt_id <= 0) {
                continue;
            }

            $router_id_row = (int) ($row['router_id'] ?? 0);
            $result[] = array(
                'id' => $olt_id,
                'name' => trim((string) ($row['name'] ?? ('OLT #' . $olt_id))),
                'latitude' => $this->to_float_or_null($row['latitude'] ?? null),
                'longitude' => $this->to_float_or_null($row['longitude'] ?? null),
                'status' => $this->normalize_status($row['status'] ?? 'active'),
                'router_id' => $router_id_row,
                'metadata' => array(
                    'description' => (string) ($row['description'] ?? ''),
                    'router_name' => (string) ($router_name_map[$router_id_row] ?? ('Router #' . $router_id_row)),
                    'total_odc' => (int) ($odc_count_map[$olt_id] ?? 0),
                    'total_odp' => (int) ($odp_count_map[$olt_id] ?? 0),
                    'total_onu' => (int) ($onu_count_map[$olt_id] ?? 0),
                ),
            );
        }

        return $result;
    }

    public function create_olt(array $payload)
    {
        if (!$this->db->table_exists($this->table_olts)) {
            return false;
        }

        $data = $this->sanitize_olt_payload($payload, true);
        if (empty($data['name'])) {
            return false;
        }
        if ($this->db->field_exists('router_id', $this->table_olts) && (int) ($data['router_id'] ?? 0) <= 0) {
            return false;
        }

        $ok = $this->db->insert($this->table_olts, $data);
        if (!$ok) {
            return false;
        }

        return (int) $this->db->insert_id();
    }

    public function get_olt_row($id)
    {
        if (!$this->db->table_exists($this->table_olts)) {
            return array();
        }

        $id = (int) $id;
        if ($id <= 0) {
            return array();
        }

        $qb = $this->db
            ->from($this->table_olts)
            ->where('id', $id)
            ->limit(1);

        if ($this->router_scope_id !== null && $this->db->field_exists('router_id', $this->table_olts)) {
            $qb->where('router_id', (int) $this->router_scope_id);
        }

        return (array) $qb->get()->row_array();
    }

    public function update_olt($id, array $payload)
    {
        if (!$this->db->table_exists($this->table_olts)) {
            return false;
        }

        $id = (int) $id;
        if ($id <= 0) {
            return false;
        }

        $existing = $this->get_olt_row($id);
        if (empty($existing)) {
            return false;
        }

        $data = $this->sanitize_olt_payload($payload, false);
        if (isset($data['name']) && trim((string) $data['name']) === '') {
            return false;
        }
        if (empty($data)) {
            return true;
        }

        return $this->db
            ->where('id', $id)
            ->update($this->table_olts, $data);
    }

    public function delete_olt($id)
    {
        if (!$this->db->table_exists($this->table_olts)) {
            return false;
        }

        $id = (int) $id;
        if ($id <= 0) {
            return false;
        }

        $existing = $this->get_olt_row($id);
        if (empty($existing)) {
            return false;
        }

        if ($this->db->field_exists('is_active', $this->table_olts)) {
            $data = array('is_active' => 0);
            if ($this->db->field_exists('updated_at', $this->table_olts)) {
                $data['updated_at'] = date('Y-m-d H:i:s');
            }

            return $this->db
                ->where('id', $id)
                ->update($this->table_olts, $data);
        }

        return $this->db
            ->where('id', $id)
            ->delete($this->table_olts);
    }

    public function get_odc_by_router($router_id = 0)
    {
        if (!$this->db->table_exists($this->table_odc)) {
            return array();
        }

        $requested_router_id = $this->resolve_requested_router_id($router_id);
        $fields = $this->db->list_fields($this->table_odc);
        $olt_fields = $this->db->table_exists($this->table_olts) ? $this->db->list_fields($this->table_olts) : array();

        $has_router = in_array('router_id', $fields, true);
        $has_olt = in_array('olt_id', $fields, true);
        $has_latitude = in_array('latitude', $fields, true);
        $has_longitude = in_array('longitude', $fields, true);
        $has_capacity = in_array('capacity', $fields, true);
        $has_used = in_array('used_ports', $fields, true);
        $has_desc = in_array('description', $fields, true);
        $has_active = in_array('is_active', $fields, true);

        $router_expr = $has_router ? '`o`.`router_id`' : '0';
        $olt_expr = $has_olt ? '`o`.`olt_id`' : 'NULL';
        $lat_expr = $has_latitude ? '`o`.`latitude`' : 'NULL';
        $lng_expr = $has_longitude ? '`o`.`longitude`' : 'NULL';
        $capacity_expr = $has_capacity ? '`o`.`capacity`' : '0';
        $used_expr = $has_used ? '`o`.`used_ports`' : '0';
        $desc_expr = $has_desc ? '`o`.`description`' : "''";
        $status_expr = $has_active ? "IF(`o`.`is_active` = 1, 'active', 'inactive')" : "'active'";

        $olt_name_expr = "''";
        if ($this->db->table_exists($this->table_olts) && in_array('name', $olt_fields, true)) {
            $olt_name_expr = 'COALESCE(olt.name, \'\')';
        }

        $qb = $this->db
            ->select("`o`.`id`, `o`.`name`, {$router_expr} AS `router_id`, {$olt_expr} AS `olt_id`, {$lat_expr} AS `latitude`, {$lng_expr} AS `longitude`, {$capacity_expr} AS `capacity`, {$used_expr} AS `used_ports`, {$desc_expr} AS `description`, {$status_expr} AS `status`, {$olt_name_expr} AS `olt_name`", false)
            ->from($this->table_odc . ' o');

        if ($this->db->table_exists($this->table_olts) && $has_olt) {
            $qb->join($this->table_olts . ' olt', 'olt.id = o.olt_id', 'left');
        }

        if ($has_router && $requested_router_id > 0) {
            $qb->where('o.router_id', $requested_router_id);
        }
        if ($has_active) {
            $qb->where('o.is_active', 1);
        }

        $qb->order_by('o.id', 'ASC');
        $rows = $qb->get()->result_array();

        $odp_count_by_odc = $this->get_odp_count_by_odc_map($requested_router_id);

        $result = array();
        foreach ($rows as $row) {
            $odc_id = (int) ($row['id'] ?? 0);
            if ($odc_id <= 0) {
                continue;
            }

            $capacity = (int) ($row['capacity'] ?? 0);
            $used_ports = (int) ($row['used_ports'] ?? 0);
            $usage_percent = $capacity > 0 ? round(($used_ports / $capacity) * 100, 2) : 0;
            $warning_level = 'normal';
            if ($capacity > 0 && $used_ports >= $capacity) {
                $warning_level = 'full';
            } elseif ($capacity > 0 && $usage_percent >= 80) {
                $warning_level = 'high';
            }

            $result[] = array(
                'id' => $odc_id,
                'name' => trim((string) ($row['name'] ?? ('ODC #' . $odc_id))),
                'latitude' => $this->to_float_or_null($row['latitude'] ?? null),
                'longitude' => $this->to_float_or_null($row['longitude'] ?? null),
                'status' => $this->normalize_status($row['status'] ?? 'active'),
                'router_id' => (int) ($row['router_id'] ?? 0),
                'metadata' => array(
                    'olt_id' => (int) ($row['olt_id'] ?? 0),
                    'olt_name' => (string) ($row['olt_name'] ?? ''),
                    'capacity' => $capacity,
                    'used_ports' => $used_ports,
                    'usage_percent' => $usage_percent,
                    'warning_level' => $warning_level,
                    'total_odp' => (int) ($odp_count_by_odc[$odc_id] ?? 0),
                    'description' => (string) ($row['description'] ?? ''),
                ),
            );
        }

        return $result;
    }

    public function create_odc(array $payload)
    {
        if (!$this->db->table_exists($this->table_odc)) {
            return false;
        }

        $data = $this->sanitize_odc_payload($payload, true);
        if (empty($data['name'])) {
            return false;
        }
        if ($this->db->field_exists('router_id', $this->table_odc) && (int) ($data['router_id'] ?? 0) <= 0) {
            return false;
        }

        $ok = $this->db->insert($this->table_odc, $data);
        if (!$ok) {
            return false;
        }

        return (int) $this->db->insert_id();
    }

    public function get_odc_row($id)
    {
        if (!$this->db->table_exists($this->table_odc)) {
            return array();
        }

        $id = (int) $id;
        if ($id <= 0) {
            return array();
        }

        $qb = $this->db
            ->from($this->table_odc)
            ->where('id', $id)
            ->limit(1);

        if ($this->router_scope_id !== null && $this->db->field_exists('router_id', $this->table_odc)) {
            $qb->where('router_id', (int) $this->router_scope_id);
        }

        return (array) $qb->get()->row_array();
    }

    public function update_odc($id, array $payload)
    {
        if (!$this->db->table_exists($this->table_odc)) {
            return false;
        }

        $id = (int) $id;
        if ($id <= 0) {
            return false;
        }

        $existing = $this->get_odc_row($id);
        if (empty($existing)) {
            return false;
        }

        $data = $this->sanitize_odc_payload($payload, false);
        if (isset($data['name']) && trim((string) $data['name']) === '') {
            return false;
        }
        if (empty($data)) {
            return true;
        }

        return $this->db
            ->where('id', $id)
            ->update($this->table_odc, $data);
    }

    public function delete_odc($id)
    {
        if (!$this->db->table_exists($this->table_odc)) {
            return false;
        }

        $id = (int) $id;
        if ($id <= 0) {
            return false;
        }

        $existing = $this->get_odc_row($id);
        if (empty($existing)) {
            return false;
        }

        if ($this->db->field_exists('is_active', $this->table_odc)) {
            $data = array('is_active' => 0);
            if ($this->db->field_exists('updated_at', $this->table_odc)) {
                $data['updated_at'] = date('Y-m-d H:i:s');
            }

            return $this->db
                ->where('id', $id)
                ->update($this->table_odc, $data);
        }

        return $this->db
            ->where('id', $id)
            ->delete($this->table_odc);
    }

    public function get_odp_by_router($router_id = 0)
    {
        if (!$this->db->table_exists($this->table_odp)) {
            return array();
        }

        $requested_router_id = $this->resolve_requested_router_id($router_id);
        $fields = $this->db->list_fields($this->table_odp);
        $olt_fields = $this->db->table_exists($this->table_olts) ? $this->db->list_fields($this->table_olts) : array();
        $odc_fields = $this->db->table_exists($this->table_odc) ? $this->db->list_fields($this->table_odc) : array();

        $has_router = in_array('router_id', $fields, true);
        $has_olt = in_array('olt_id', $fields, true);
        $has_odc = in_array('odc_id', $fields, true);
        $has_pon = in_array('pon_port', $fields, true);
        $has_latitude = in_array('latitude', $fields, true);
        $has_longitude = in_array('longitude', $fields, true);
        $has_capacity = in_array('capacity', $fields, true);
        $has_used = in_array('used_ports', $fields, true);
        $has_desc = in_array('description', $fields, true);
        $has_active = in_array('is_active', $fields, true);

        $router_expr = $has_router ? '`o`.`router_id`' : '0';
        $olt_expr = $has_olt ? '`o`.`olt_id`' : 'NULL';
        $odc_expr = $has_odc ? '`o`.`odc_id`' : 'NULL';
        $pon_expr = $has_pon ? '`o`.`pon_port`' : "''";
        $lat_expr = $has_latitude ? '`o`.`latitude`' : 'NULL';
        $lng_expr = $has_longitude ? '`o`.`longitude`' : 'NULL';
        $capacity_expr = $has_capacity ? '`o`.`capacity`' : '0';
        $used_expr = $has_used ? '`o`.`used_ports`' : '0';
        $desc_expr = $has_desc ? '`o`.`description`' : "''";
        $status_expr = $has_active ? "IF(`o`.`is_active` = 1, 'active', 'inactive')" : "'active'";

        $olt_name_expr = "''";
        if ($this->db->table_exists($this->table_olts) && in_array('name', $olt_fields, true)) {
            $olt_name_expr = 'COALESCE(olt.name, \'\')';
        }

        $odc_name_expr = "''";
        if ($this->db->table_exists($this->table_odc) && in_array('name', $odc_fields, true)) {
            $odc_name_expr = 'COALESCE(odc.name, \'\')';
        }

        $qb = $this->db
            ->select("`o`.`id`, `o`.`name`, {$router_expr} AS `router_id`, {$olt_expr} AS `olt_id`, {$odc_expr} AS `odc_id`, {$pon_expr} AS `pon_port`, {$lat_expr} AS `latitude`, {$lng_expr} AS `longitude`, {$capacity_expr} AS `capacity`, {$used_expr} AS `used_ports`, {$desc_expr} AS `description`, {$status_expr} AS `status`, {$olt_name_expr} AS `olt_name`, {$odc_name_expr} AS `odc_name`", false)
            ->from($this->table_odp . ' o');

        if ($this->db->table_exists($this->table_olts) && $has_olt) {
            $qb->join($this->table_olts . ' olt', 'olt.id = o.olt_id', 'left');
        }

        if ($this->db->table_exists($this->table_odc) && $has_odc) {
            $qb->join($this->table_odc . ' odc', 'odc.id = o.odc_id', 'left');
        }

        if ($has_router && $requested_router_id > 0) {
            $qb->where('o.router_id', $requested_router_id);
        }
        if ($has_active) {
            $qb->where('o.is_active', 1);
        }

        $qb->order_by('o.id', 'ASC');
        $rows = $qb->get()->result_array();

        $result = array();
        foreach ($rows as $row) {
            $odp_id = (int) ($row['id'] ?? 0);
            if ($odp_id <= 0) {
                continue;
            }

            $capacity = (int) ($row['capacity'] ?? 0);
            $used_ports = (int) ($row['used_ports'] ?? 0);
            $usage_percent = $capacity > 0 ? round(($used_ports / $capacity) * 100, 2) : 0;
            $warning_level = 'normal';
            if ($capacity > 0 && $used_ports >= $capacity) {
                $warning_level = 'full';
            } elseif ($capacity > 0 && $usage_percent >= 80) {
                $warning_level = 'high';
            }

            $router_id_row = (int) ($row['router_id'] ?? 0);
            if ($router_id_row <= 0 && !empty($row['odc_id'])) {
                $router_id_row = $this->resolve_router_id_by_odc((int) $row['odc_id']);
            }

            $result[] = array(
                'id' => $odp_id,
                'name' => trim((string) ($row['name'] ?? ('ODP #' . $odp_id))),
                'latitude' => $this->to_float_or_null($row['latitude'] ?? null),
                'longitude' => $this->to_float_or_null($row['longitude'] ?? null),
                'status' => $this->normalize_status($row['status'] ?? 'active'),
                'router_id' => $router_id_row,
                'metadata' => array(
                    'olt_id' => (int) ($row['olt_id'] ?? 0),
                    'olt_name' => (string) ($row['olt_name'] ?? ''),
                    'odc_id' => (int) ($row['odc_id'] ?? 0),
                    'odc_name' => (string) ($row['odc_name'] ?? ''),
                    'pon_port' => (string) ($row['pon_port'] ?? ''),
                    'capacity' => $capacity,
                    'used_ports' => $used_ports,
                    'usage_percent' => $usage_percent,
                    'warning_level' => $warning_level,
                    'description' => (string) ($row['description'] ?? ''),
                ),
            );
        }

        return $result;
    }

    public function create_odp(array $payload)
    {
        if (!$this->db->table_exists($this->table_odp)) {
            return false;
        }

        $data = $this->sanitize_odp_payload($payload, true);
        if (empty($data['name']) || (int) ($data['router_id'] ?? 0) <= 0) {
            return false;
        }

        $ok = $this->db->insert($this->table_odp, $data);
        if (!$ok) {
            return false;
        }

        return (int) $this->db->insert_id();
    }

    public function update_odp($id, array $payload)
    {
        if (!$this->db->table_exists($this->table_odp)) {
            return false;
        }

        $id = (int) $id;
        if ($id <= 0) {
            return false;
        }

        $existing = $this->get_odp_row($id);
        if (empty($existing)) {
            return false;
        }

        $data = $this->sanitize_odp_payload($payload, false);
        if (isset($data['name']) && trim((string) $data['name']) === '') {
            return false;
        }

        if (empty($data)) {
            return true;
        }

        return $this->db
            ->where('id', $id)
            ->update($this->table_odp, $data);
    }

    public function delete_odp($id)
    {
        if (!$this->db->table_exists($this->table_odp)) {
            return false;
        }

        $id = (int) $id;
        if ($id <= 0) {
            return false;
        }

        $existing = $this->get_odp_row($id);
        if (empty($existing)) {
            return false;
        }

        return $this->db
            ->where('id', $id)
            ->delete($this->table_odp);
    }

    public function get_odp_row($id)
    {
        if (!$this->db->table_exists($this->table_odp)) {
            return array();
        }

        $id = (int) $id;
        if ($id <= 0) {
            return array();
        }

        $qb = $this->db
            ->from($this->table_odp)
            ->where('id', $id)
            ->limit(1);

        if ($this->router_scope_id !== null && $this->db->field_exists('router_id', $this->table_odp)) {
            $qb->where('router_id', (int) $this->router_scope_id);
        }

        return (array) $qb->get()->row_array();
    }

    public function get_customers_by_router($router_id = 0)
    {
        if (!$this->db->table_exists($this->table_customers)) {
            return array();
        }

        $requested_router_id = $this->resolve_requested_router_id($router_id);
        $fields = $this->db->list_fields($this->table_customers);

        $has_router = in_array('router_id', $fields, true);
        $has_odp_id = in_array('odp_id', $fields, true);
        $has_latitude = in_array('latitude', $fields, true);
        $has_longitude = in_array('longitude', $fields, true);
        $has_status = in_array('status', $fields, true);
        $has_ip = in_array('ip_address', $fields, true);
        $has_full_name = in_array('full_name', $fields, true);
        $has_nama = in_array('nama', $fields, true);
        $has_profile_id = in_array('profile_id', $fields, true);
        $has_service_mode = in_array('service_mode', $fields, true);

        $has_customer_services = $this->db->table_exists('customer_services');
        $customer_service_fields = $has_customer_services ? $this->db->list_fields('customer_services') : array();
        $can_join_service = $has_customer_services
            && in_array('id', $customer_service_fields, true)
            && in_array('customer_id', $customer_service_fields, true);
        $has_cs_profile = in_array('ppp_profile_id', $customer_service_fields, true);
        $cs_username_col = '';
        if (in_array('pppoe_username', $customer_service_fields, true)) {
            $cs_username_col = 'pppoe_username';
        } elseif (in_array('username', $customer_service_fields, true)) {
            $cs_username_col = 'username';
        }

        if ($has_full_name && $has_nama) {
            $customer_name_expr = "COALESCE(NULLIF(c.full_name, ''), NULLIF(c.nama, ''), CONCAT('Customer #', c.id))";
        } elseif ($has_full_name) {
            $customer_name_expr = "COALESCE(NULLIF(c.full_name, ''), CONCAT('Customer #', c.id))";
        } elseif ($has_nama) {
            $customer_name_expr = "COALESCE(NULLIF(c.nama, ''), CONCAT('Customer #', c.id))";
        } else {
            $customer_name_expr = "CONCAT('Customer #', c.id)";
        }
        $router_expr = $has_router ? 'c.router_id' : '0';
        $lat_expr = $has_latitude ? 'c.latitude' : 'NULL';
        $lng_expr = $has_longitude ? 'c.longitude' : 'NULL';
        $status_expr = $has_status ? 'LOWER(c.status)' : "'active'";
        $ip_expr = $has_ip ? 'c.ip_address' : "''";
        $odp_expr = ($this->db->table_exists($this->table_odp) && $has_odp_id) ? 'c.odp_id' : 'NULL';
        $service_mode_expr = $has_service_mode ? "LOWER(COALESCE(NULLIF(c.service_mode, ''), ''))" : "''";

        $qb = $this->db
            ->select("c.id, {$customer_name_expr} AS name, {$router_expr} AS router_id, {$lat_expr} AS latitude, {$lng_expr} AS longitude, {$status_expr} AS status, {$ip_expr} AS ip_address, {$odp_expr} AS odp_id, {$service_mode_expr} AS service_mode_customer", false)
            ->from($this->table_customers . ' c');

        if ($this->db->table_exists($this->table_odp) && $has_odp_id) {
            $qb->select('o.name AS odp_name, o.latitude AS odp_latitude, o.longitude AS odp_longitude', false);
            $qb->join($this->table_odp . ' o', 'o.id = c.odp_id', 'left');
        } else {
            $qb->select("'' AS odp_name, NULL AS odp_latitude, NULL AS odp_longitude", false);
        }

        if ($can_join_service) {
            $qb->join('(SELECT csx.customer_id, MAX(csx.id) AS max_id FROM customer_services csx GROUP BY csx.customer_id) csm', 'csm.customer_id = c.id', 'left', false);
            $qb->join('customer_services cs', 'cs.id = csm.max_id', 'left');
            if ($has_cs_profile) {
                $qb->select('cs.ppp_profile_id AS cs_ppp_profile_id', false);
            } else {
                $qb->select('0 AS cs_ppp_profile_id', false);
            }
            if ($cs_username_col !== '') {
                $qb->select('cs.`' . $cs_username_col . '` AS cs_pppoe_username', false);
            } else {
                $qb->select("'' AS cs_pppoe_username", false);
            }

            if ($has_cs_profile && $this->db->table_exists('ppp_profiles')) {
                $qb->join('ppp_profiles pp', 'pp.id = cs.ppp_profile_id', 'left');
                $qb->select('pp.name AS service_plan_name', false);
            } elseif ($has_profile_id && $this->db->table_exists('ppp_profiles')) {
                $qb->join('ppp_profiles pp', 'pp.id = c.profile_id', 'left');
                $qb->select('pp.name AS service_plan_name', false);
            } else {
                $qb->select("'' AS service_plan_name", false);
            }
        } elseif ($has_profile_id && $this->db->table_exists('ppp_profiles')) {
            $qb->join('ppp_profiles pp', 'pp.id = c.profile_id', 'left');
            $qb->select('pp.name AS service_plan_name', false);
            $qb->select('0 AS cs_ppp_profile_id', false);
            $qb->select("'' AS cs_pppoe_username", false);
        } else {
            $qb->select("'' AS service_plan_name", false);
            $qb->select('0 AS cs_ppp_profile_id', false);
            $qb->select("'' AS cs_pppoe_username", false);
        }

        if ($requested_router_id > 0) {
            if ($has_router) {
                $qb->where('c.router_id', $requested_router_id);
            } elseif ($this->db->table_exists($this->table_odp) && $has_odp_id && $this->db->field_exists('router_id', $this->table_odp)) {
                $qb->where('o.router_id', $requested_router_id);
            }
        }

        $qb->order_by('c.id', 'ASC');
        $rows = $qb->get()->result_array();

        $result = array();
        foreach ($rows as $row) {
            $customer_id = (int) ($row['id'] ?? 0);
            if ($customer_id <= 0) {
                continue;
            }

            $router_id_row = (int) ($row['router_id'] ?? 0);
            if ($router_id_row <= 0 && !empty($row['odp_id'])) {
                $router_id_row = $this->resolve_router_id_by_odp((int) $row['odp_id']);
            }

            $service_plan_name = (string) ($row['service_plan_name'] ?? '');
            $cs_ppp_profile_id = (int) ($row['cs_ppp_profile_id'] ?? 0);
            $cs_pppoe_username = trim((string) ($row['cs_pppoe_username'] ?? ''));
            $service_mode = strtolower(trim((string) ($row['service_mode_customer'] ?? '')));
            if (!in_array($service_mode, array('pppoe', 'static'), true)) {
                if ($cs_ppp_profile_id > 0 || $cs_pppoe_username !== '') {
                    $service_mode = 'pppoe';
                } elseif (trim($service_plan_name) !== '') {
                    $service_mode = 'pppoe';
                } else {
                    $service_mode = 'static';
                }
            }

            $result[] = array(
                'id' => $customer_id,
                'name' => trim((string) ($row['name'] ?? ('Customer #' . $customer_id))),
                'latitude' => $this->to_float_or_null($row['latitude'] ?? null),
                'longitude' => $this->to_float_or_null($row['longitude'] ?? null),
                'status' => $this->normalize_status($row['status'] ?? 'active'),
                'router_id' => $router_id_row,
                'metadata' => array(
                    'service_plan' => $service_plan_name,
                    'service_mode' => $service_mode,
                    'pppoe_username' => $cs_pppoe_username,
                    'ip_address' => (string) ($row['ip_address'] ?? ''),
                    'odp_id' => (int) ($row['odp_id'] ?? 0),
                    'odp_name' => (string) ($row['odp_name'] ?? ''),
                    'odp_latitude' => $this->to_float_or_null($row['odp_latitude'] ?? null),
                    'odp_longitude' => $this->to_float_or_null($row['odp_longitude'] ?? null),
                ),
            );
        }

        return $result;
    }

    private function sanitize_olt_payload(array $payload, $is_create = false)
    {
        return $this->sanitize_node_payload($this->table_olts, $payload, array(
            'name' => 'string',
            'latitude' => 'float',
            'longitude' => 'float',
            'description' => 'string',
            'is_active' => 'bool',
        ), $is_create);
    }

    private function sanitize_odc_payload(array $payload, $is_create = false)
    {
        return $this->sanitize_node_payload($this->table_odc, $payload, array(
            'olt_id' => 'int',
            'name' => 'string',
            'latitude' => 'float',
            'longitude' => 'float',
            'capacity' => 'int',
            'used_ports' => 'int',
            'description' => 'string',
            'is_active' => 'bool',
        ), $is_create);
    }

    private function sanitize_odp_payload(array $payload, $is_create = false)
    {
        return $this->sanitize_node_payload($this->table_odp, $payload, array(
            'olt_id' => 'int',
            'odc_id' => 'int',
            'pon_port' => 'string',
            'name' => 'string',
            'latitude' => 'float',
            'longitude' => 'float',
            'capacity' => 'int',
            'used_ports' => 'int',
            'description' => 'string',
            'is_active' => 'bool',
        ), $is_create);
    }

    private function sanitize_node_payload($table, array $payload, array $map, $is_create = false)
    {
        if (!$this->db->table_exists($table)) {
            return array();
        }

        $fields = $this->db->list_fields($table);
        $result = array();

        $router_id = (int) ($payload['router_id'] ?? 0);
        if ($this->router_scope_id !== null) {
            $router_id = (int) $this->router_scope_id;
        }

        if (in_array('router_id', $fields, true) && ($is_create || isset($payload['router_id']) || $this->router_scope_id !== null)) {
            $result['router_id'] = $router_id > 0 ? $router_id : 0;
        }

        foreach ($map as $key => $type) {
            if (!in_array($key, $fields, true)) {
                continue;
            }

            if (!$is_create && !array_key_exists($key, $payload)) {
                continue;
            }

            $raw = $payload[$key] ?? null;
            if ($type === 'int') {
                $result[$key] = max(0, (int) $raw);
            } elseif ($type === 'float') {
                $value = trim((string) $raw);
                $result[$key] = $value === '' ? null : (float) $value;
            } elseif ($type === 'bool') {
                if ($is_create && !array_key_exists($key, $payload)) {
                    $result[$key] = 1;
                } else {
                    $result[$key] = (int) $raw === 1 ? 1 : 0;
                }
            } else {
                $value = trim((string) $raw);
                $result[$key] = $value;
            }
        }

        if (in_array('updated_at', $fields, true)) {
            $result['updated_at'] = date('Y-m-d H:i:s');
        }
        if ($is_create && in_array('created_at', $fields, true) && !isset($result['created_at'])) {
            $result['created_at'] = date('Y-m-d H:i:s');
        }

        return $result;
    }

    private function resolve_requested_router_id($requested_router_id = 0)
    {
        if ($this->router_scope_id !== null) {
            return (int) $this->router_scope_id;
        }

        $requested_router_id = (int) $requested_router_id;
        return $requested_router_id > 0 ? $requested_router_id : 0;
    }

    private function get_odc_count_map($router_id = 0)
    {
        if (!$this->db->table_exists($this->table_odc) || !$this->db->field_exists('olt_id', $this->table_odc)) {
            return array();
        }

        $router_id = $this->resolve_requested_router_id($router_id);
        $qb = $this->db
            ->select('olt_id, COUNT(*) AS total', false)
            ->from($this->table_odc)
            ->where('olt_id IS NOT NULL', null, false)
            ->group_by('olt_id');

        if ($router_id > 0 && $this->db->field_exists('router_id', $this->table_odc)) {
            $qb->where('router_id', $router_id);
        }

        $rows = $qb->get()->result_array();
        $map = array();
        foreach ($rows as $row) {
            $key = (int) ($row['olt_id'] ?? 0);
            if ($key > 0) {
                $map[$key] = (int) ($row['total'] ?? 0);
            }
        }

        return $map;
    }

    private function get_odp_count_map($router_id = 0)
    {
        if (!$this->db->table_exists($this->table_odp) || !$this->db->field_exists('olt_id', $this->table_odp)) {
            return array();
        }

        $router_id = $this->resolve_requested_router_id($router_id);
        $qb = $this->db
            ->select('olt_id, COUNT(*) AS total', false)
            ->from($this->table_odp)
            ->where('olt_id IS NOT NULL', null, false)
            ->group_by('olt_id');

        if ($router_id > 0 && $this->db->field_exists('router_id', $this->table_odp)) {
            $qb->where('router_id', $router_id);
        }

        $rows = $qb->get()->result_array();
        $map = array();
        foreach ($rows as $row) {
            $key = (int) ($row['olt_id'] ?? 0);
            if ($key > 0) {
                $map[$key] = (int) ($row['total'] ?? 0);
            }
        }
        return $map;
    }

    private function get_odp_count_by_odc_map($router_id = 0)
    {
        if (!$this->db->table_exists($this->table_odp) || !$this->db->field_exists('odc_id', $this->table_odp)) {
            return array();
        }

        $router_id = $this->resolve_requested_router_id($router_id);
        $qb = $this->db
            ->select('odc_id, COUNT(*) AS total', false)
            ->from($this->table_odp)
            ->where('odc_id IS NOT NULL', null, false)
            ->group_by('odc_id');

        if ($router_id > 0 && $this->db->field_exists('router_id', $this->table_odp)) {
            $qb->where('router_id', $router_id);
        }

        $rows = $qb->get()->result_array();
        $map = array();
        foreach ($rows as $row) {
            $key = (int) ($row['odc_id'] ?? 0);
            if ($key > 0) {
                $map[$key] = (int) ($row['total'] ?? 0);
            }
        }

        return $map;
    }

    private function get_onu_count_map($router_id = 0)
    {
        if (
            !$this->db->table_exists($this->table_customers)
            || !$this->db->table_exists($this->table_odp)
            || !$this->db->field_exists('odp_id', $this->table_customers)
            || !$this->db->field_exists('olt_id', $this->table_odp)
        ) {
            return array();
        }

        $router_id = $this->resolve_requested_router_id($router_id);
        $qb = $this->db
            ->select('o.olt_id, COUNT(c.id) AS total', false)
            ->from($this->table_customers . ' c')
            ->join($this->table_odp . ' o', 'o.id = c.odp_id', 'inner')
            ->where('o.olt_id IS NOT NULL', null, false)
            ->group_by('o.olt_id');

        if ($router_id > 0) {
            if ($this->db->field_exists('router_id', $this->table_customers)) {
                $qb->where('c.router_id', $router_id);
            } elseif ($this->db->field_exists('router_id', $this->table_odp)) {
                $qb->where('o.router_id', $router_id);
            }
        }

        $rows = $qb->get()->result_array();
        $map = array();
        foreach ($rows as $row) {
            $key = (int) ($row['olt_id'] ?? 0);
            if ($key > 0) {
                $map[$key] = (int) ($row['total'] ?? 0);
            }
        }
        return $map;
    }

    private function get_router_name_map($router_id = 0)
    {
        $rows = $this->get_all_routers($router_id);
        $map = array();
        foreach ($rows as $row) {
            $key = (int) ($row['id'] ?? 0);
            if ($key > 0) {
                $map[$key] = (string) ($row['name'] ?? ('Router #' . $key));
            }
        }
        return $map;
    }

    private function resolve_router_id_by_odp($odp_id)
    {
        $odp_id = (int) $odp_id;
        if ($odp_id <= 0 || !$this->db->table_exists($this->table_odp) || !$this->db->field_exists('router_id', $this->table_odp)) {
            return 0;
        }

        $row = $this->db
            ->select('router_id')
            ->from($this->table_odp)
            ->where('id', $odp_id)
            ->limit(1)
            ->get()
            ->row_array();

        return (int) ($row['router_id'] ?? 0);
    }

    private function resolve_router_id_by_odc($odc_id)
    {
        $odc_id = (int) $odc_id;
        if ($odc_id <= 0 || !$this->db->table_exists($this->table_odc) || !$this->db->field_exists('router_id', $this->table_odc)) {
            return 0;
        }

        $row = $this->db
            ->select('router_id')
            ->from($this->table_odc)
            ->where('id', $odc_id)
            ->limit(1)
            ->get()
            ->row_array();

        return (int) ($row['router_id'] ?? 0);
    }

    private function to_float_or_null($value)
    {
        if ($value === null || $value === '') {
            return null;
        }
        return (float) $value;
    }

    private function normalize_status($value)
    {
        $status = strtolower(trim((string) $value));
        if ($status === '') {
            return 'active';
        }
        return $status;
    }
}
