<?php
defined('BASEPATH') OR exit('No direct script access allowed');

class Master_references extends MY_Controller
{
    public function __construct()
    {
        parent::__construct();
        $this->require_role(array('superadmin', 'admin'));
        $this->load->database();
        $this->load->model('master_reference_model');
        $this->load->library(array('form_validation', 'session'));
        $this->load->helper(array('url', 'form'));

        if (method_exists($this->master_reference_model, 'set_router_scope')) {
            $this->master_reference_model->set_router_scope($this->getEffectiveRouterId());
        }
    }

    public function index()
    {
        return redirect('master-references/locations');
    }

    public function locations()
    {
        $keyword = trim((string) $this->input->get('search', true));
        $pager = $this->init_pagination(
            'master-references/locations',
            $this->master_reference_model->count_locations($keyword, false),
            20,
            3
        );

        $this->load->view('master_references/locations', array(
            'rows' => $this->master_reference_model->get_locations_paginated($pager['per_page'], $pager['offset'], $keyword, false),
            'search' => $keyword,
            'pagination' => $pager['links'],
            'total_rows' => $pager['total_rows'],
            'per_page' => (int) $pager['per_page'],
            'per_page_options' => $this->get_per_page_options(),
            'active_menu' => 'master_locations',
        ));
    }

    public function store_location()
    {
        if (strtoupper((string) $this->input->method()) !== 'POST') {
            show_error('Method Not Allowed', 405);
            return;
        }

        if (!$this->ensure_router_selected_for_create('master_locations', 'master-references/locations')) {
            return;
        }

        $this->form_validation->set_rules('name', 'Lokasi', 'trim|required|min_length[2]|max_length[120]');
        $this->form_validation->set_rules('latitude', 'Latitude', 'trim|decimal');
        $this->form_validation->set_rules('longitude', 'Longitude', 'trim|decimal');
        if ($this->form_validation->run() === false) {
            $this->session->set_flashdata('error', validation_errors());
            return redirect('master-references/locations');
        }

        $ok = $this->master_reference_model->insert_location(array(
            'name' => $this->input->post('name', true),
            'description' => $this->input->post('description', true),
            'latitude' => $this->normalize_coordinate_input($this->input->post('latitude', true)),
            'longitude' => $this->normalize_coordinate_input($this->input->post('longitude', true)),
            'is_active' => 1,
        ));

        if (!$ok) {
            $this->session->set_flashdata('error', 'Gagal menambahkan lokasi. Pastikan nama unik.');
            return redirect('master-references/locations');
        }

        $this->session->set_flashdata('success', 'Lokasi berhasil ditambahkan.');
        return redirect('master-references/locations');
    }

    public function update_location($id = 0)
    {
        if (strtoupper((string) $this->input->method()) !== 'POST') {
            show_error('Method Not Allowed', 405);
            return;
        }

        $this->form_validation->set_rules('name', 'Lokasi', 'trim|required|min_length[2]|max_length[120]');
        $this->form_validation->set_rules('latitude', 'Latitude', 'trim|decimal');
        $this->form_validation->set_rules('longitude', 'Longitude', 'trim|decimal');
        if ($this->form_validation->run() === false) {
            $this->session->set_flashdata('error', validation_errors());
            return redirect('master-references/locations');
        }

        $ok = $this->master_reference_model->update_location((int) $id, array(
            'name' => $this->input->post('name', true),
            'description' => $this->input->post('description', true),
            'latitude' => $this->normalize_coordinate_input($this->input->post('latitude', true)),
            'longitude' => $this->normalize_coordinate_input($this->input->post('longitude', true)),
            'is_active' => (int) $this->input->post('is_active', true) === 1 ? 1 : 0,
        ));

        if (!$ok) {
            $this->session->set_flashdata('error', 'Gagal update lokasi.');
            return redirect('master-references/locations');
        }

        $this->session->set_flashdata('success', 'Lokasi berhasil diperbarui.');
        return redirect('master-references/locations');
    }

    public function delete_location($id = 0)
    {
        if (strtoupper((string) $this->input->method()) !== 'POST') {
            show_error('Method Not Allowed', 405);
            return;
        }

        if ($this->is_superadmin()) {
            $ok = $this->master_reference_model->delete_location((int) $id);
            if (!$ok) {
                $this->session->set_flashdata('error', 'Gagal hapus lokasi.');
                return redirect('master-references/locations');
            }
        } else {
            $affected = $this->master_reference_model->bulk_update_location_status(array((int) $id), 0);
            if ($affected < 0) {
                $this->session->set_flashdata('error', 'Gagal hapus lokasi.');
                return redirect('master-references/locations');
            }
        }

        $this->session->set_flashdata('success', 'Lokasi berhasil dihapus.');
        return redirect('master-references/locations');
    }

    public function bulk_update_locations()
    {
        if (strtoupper((string) $this->input->method()) !== 'POST') {
            show_error('Method Not Allowed', 405);
            return;
        }

        $ids = $this->parse_bulk_ids();
        if (empty($ids)) {
            $this->session->set_flashdata('error', 'Pilih minimal 1 lokasi.');
            return redirect('master-references/locations');
        }

        $is_active_raw = (string) $this->input->post('is_active', true);
        if ($is_active_raw !== '0' && $is_active_raw !== '1') {
            $this->session->set_flashdata('error', 'Status bulk edit tidak valid.');
            return redirect('master-references/locations');
        }

        $affected = $this->master_reference_model->bulk_update_location_status($ids, (int) $is_active_raw);
        if ($affected < 0) {
            $this->session->set_flashdata('error', 'Gagal melakukan bulk edit lokasi.');
            return redirect('master-references/locations');
        }

        $label = ((int) $is_active_raw === 1) ? 'aktif' : 'nonaktif';
        $this->session->set_flashdata('success', 'Bulk edit lokasi berhasil. Status diubah ke ' . $label . ' untuk ' . (int) $affected . ' data.');
        return redirect('master-references/locations');
    }

    public function bulk_delete_locations()
    {
        if (strtoupper((string) $this->input->method()) !== 'POST') {
            show_error('Method Not Allowed', 405);
            return;
        }

        $ids = $this->parse_bulk_ids();
        if (empty($ids)) {
            $this->session->set_flashdata('error', 'Pilih minimal 1 lokasi.');
            return redirect('master-references/locations');
        }

        if ($this->is_superadmin()) {
            $affected = $this->master_reference_model->bulk_delete_locations($ids);
        } else {
            $affected = $this->master_reference_model->bulk_update_location_status($ids, 0);
        }
        if ($affected < 0) {
            $this->session->set_flashdata('error', 'Gagal melakukan bulk hapus lokasi.');
            return redirect('master-references/locations');
        }

        $this->session->set_flashdata('success', 'Bulk hapus lokasi berhasil. Total diproses: ' . (int) $affected . ' data.');
        return redirect('master-references/locations');
    }

    public function olts()
    {
        $keyword = trim((string) $this->input->get('search', true));
        $pager = $this->init_pagination(
            'master-references/olts',
            $this->master_reference_model->count_olts($keyword, false),
            20,
            3
        );

        $this->load->view('master_references/olts', array(
            'rows' => $this->master_reference_model->get_olts_paginated($pager['per_page'], $pager['offset'], $keyword, false),
            'search' => $keyword,
            'pagination' => $pager['links'],
            'total_rows' => $pager['total_rows'],
            'per_page' => (int) $pager['per_page'],
            'per_page_options' => $this->get_per_page_options(),
            'active_menu' => 'master_olts',
        ));
    }

    public function store_olt()
    {
        if (strtoupper((string) $this->input->method()) !== 'POST') {
            show_error('Method Not Allowed', 405);
            return;
        }

        if (!$this->ensure_router_selected_for_create('master_olts', 'master-references/olts')) {
            return;
        }

        $this->form_validation->set_rules('name', 'OLT', 'trim|required|min_length[2]|max_length[120]');
        $this->form_validation->set_rules('latitude', 'Latitude', 'trim|decimal');
        $this->form_validation->set_rules('longitude', 'Longitude', 'trim|decimal');
        if ($this->form_validation->run() === false) {
            $this->session->set_flashdata('error', validation_errors());
            return redirect('master-references/olts');
        }

        $ok = $this->master_reference_model->insert_olt(array(
            'name' => $this->input->post('name', true),
            'description' => $this->input->post('description', true),
            'latitude' => $this->normalize_coordinate_input($this->input->post('latitude', true)),
            'longitude' => $this->normalize_coordinate_input($this->input->post('longitude', true)),
            'is_active' => 1,
        ));

        if (!$ok) {
            $this->session->set_flashdata('error', 'Gagal menambahkan OLT. Pastikan nama unik.');
            return redirect('master-references/olts');
        }

        $this->session->set_flashdata('success', 'OLT berhasil ditambahkan.');
        return redirect('master-references/olts');
    }

    public function update_olt($id = 0)
    {
        if (strtoupper((string) $this->input->method()) !== 'POST') {
            show_error('Method Not Allowed', 405);
            return;
        }

        $this->form_validation->set_rules('name', 'OLT', 'trim|required|min_length[2]|max_length[120]');
        $this->form_validation->set_rules('latitude', 'Latitude', 'trim|decimal');
        $this->form_validation->set_rules('longitude', 'Longitude', 'trim|decimal');
        if ($this->form_validation->run() === false) {
            $this->session->set_flashdata('error', validation_errors());
            return redirect('master-references/olts');
        }

        $ok = $this->master_reference_model->update_olt((int) $id, array(
            'name' => $this->input->post('name', true),
            'description' => $this->input->post('description', true),
            'latitude' => $this->normalize_coordinate_input($this->input->post('latitude', true)),
            'longitude' => $this->normalize_coordinate_input($this->input->post('longitude', true)),
            'is_active' => (int) $this->input->post('is_active', true) === 1 ? 1 : 0,
        ));

        if (!$ok) {
            $this->session->set_flashdata('error', 'Gagal update OLT.');
            return redirect('master-references/olts');
        }

        $this->session->set_flashdata('success', 'OLT berhasil diperbarui.');
        return redirect('master-references/olts');
    }

    public function delete_olt($id = 0)
    {
        if (strtoupper((string) $this->input->method()) !== 'POST') {
            show_error('Method Not Allowed', 405);
            return;
        }

        if ($this->is_superadmin()) {
            $ok = $this->master_reference_model->delete_olt((int) $id);
            if (!$ok) {
                $this->session->set_flashdata('error', 'Gagal hapus OLT.');
                return redirect('master-references/olts');
            }
        } else {
            $affected = $this->master_reference_model->bulk_update_olt_status(array((int) $id), 0);
            if ($affected < 0) {
                $this->session->set_flashdata('error', 'Gagal hapus OLT.');
                return redirect('master-references/olts');
            }
        }

        $this->session->set_flashdata('success', 'OLT berhasil dihapus.');
        return redirect('master-references/olts');
    }

    public function bulk_update_olts()
    {
        if (strtoupper((string) $this->input->method()) !== 'POST') {
            show_error('Method Not Allowed', 405);
            return;
        }

        $ids = $this->parse_bulk_ids();
        if (empty($ids)) {
            $this->session->set_flashdata('error', 'Pilih minimal 1 OLT.');
            return redirect('master-references/olts');
        }

        $is_active_raw = (string) $this->input->post('is_active', true);
        if ($is_active_raw !== '0' && $is_active_raw !== '1') {
            $this->session->set_flashdata('error', 'Status bulk edit tidak valid.');
            return redirect('master-references/olts');
        }

        $affected = $this->master_reference_model->bulk_update_olt_status($ids, (int) $is_active_raw);
        if ($affected < 0) {
            $this->session->set_flashdata('error', 'Gagal melakukan bulk edit OLT.');
            return redirect('master-references/olts');
        }

        $label = ((int) $is_active_raw === 1) ? 'aktif' : 'nonaktif';
        $this->session->set_flashdata('success', 'Bulk edit OLT berhasil. Status diubah ke ' . $label . ' untuk ' . (int) $affected . ' data.');
        return redirect('master-references/olts');
    }

    public function bulk_delete_olts()
    {
        if (strtoupper((string) $this->input->method()) !== 'POST') {
            show_error('Method Not Allowed', 405);
            return;
        }

        $ids = $this->parse_bulk_ids();
        if (empty($ids)) {
            $this->session->set_flashdata('error', 'Pilih minimal 1 OLT.');
            return redirect('master-references/olts');
        }

        if ($this->is_superadmin()) {
            $affected = $this->master_reference_model->bulk_delete_olts($ids);
        } else {
            $affected = $this->master_reference_model->bulk_update_olt_status($ids, 0);
        }
        if ($affected < 0) {
            $this->session->set_flashdata('error', 'Gagal melakukan bulk hapus OLT.');
            return redirect('master-references/olts');
        }

        $this->session->set_flashdata('success', 'Bulk hapus OLT berhasil. Total diproses: ' . (int) $affected . ' data.');
        return redirect('master-references/olts');
    }

    private function parse_bulk_ids()
    {
        $raw = $this->input->post('ids', true);
        $ids = array();

        if (is_array($raw)) {
            $ids = $raw;
        } elseif (is_string($raw) && trim($raw) !== '') {
            $ids = explode(',', $raw);
        }

        $result = array();
        foreach ($ids as $id) {
            $value = (int) $id;
            if ($value > 0) {
                $result[$value] = $value;
            }
        }

        return array_values($result);
    }

    private function normalize_coordinate_input($value)
    {
        $value = trim((string) $value);
        return $value === '' ? null : $value;
    }

    private function ensure_router_selected_for_create($table, $redirect_path)
    {
        if (!$this->db->table_exists($table)) {
            return true;
        }

        $fields = $this->db->list_fields($table);
        if (!in_array('router_id', $fields, true)) {
            return true;
        }

        $effective_router_id = $this->getEffectiveRouterId();
        if ($effective_router_id !== null && (int) $effective_router_id > 0) {
            return true;
        }

        $this->session->set_flashdata('error', 'Pilih router tertentu terlebih dahulu sebelum menambah data master.');
        redirect($redirect_path);
        return false;
    }
}
