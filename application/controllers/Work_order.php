<?php
defined('BASEPATH') OR exit('No direct script access allowed');

/**
 * Work Order backend controller (tanpa UI).
 * Fokus endpoint logic lifecycle WO.
 */
class Work_order extends MY_Controller
{
    public function __construct()
    {
        parent::__construct();
        $this->require_module_access('work_orders', 'Akses ditolak. Modul Work Order hanya untuk role yang diizinkan.');
        $this->load->library('wo_manager');
        $this->load->model(['wo_model', 'wo_history_model']);
    }

    /**
     * Start WO: OPEN -> PROCESS
     * POST: wo_id, teknisi_id, notes(optional)
     */
    public function start()
    {
        $wo_id = (int) $this->input->post('wo_id');
        $teknisi_id = (int) $this->input->post('teknisi_id');
        $notes = trim((string) $this->input->post('notes'));

        if ($wo_id <= 0 || $teknisi_id <= 0) {
            return $this->json(['success' => false, 'message' => 'wo_id dan teknisi_id wajib valid'], 422);
        }

        try {
            $this->wo_manager->start_wo($wo_id, $teknisi_id, $notes);
            return $this->json(['success' => true, 'message' => 'WO masuk status PROCESS']);
        } catch (Exception $e) {
            return $this->json(['success' => false, 'message' => $e->getMessage()], 409);
        }
    }

    /**
     * Complete WO: PROCESS -> DONE, lalu auto activate jika installation.
     * POST: wo_id, teknisi_id, notes(optional), photo_before/after/odp(optional)
     */
    public function done()
    {
        $wo_id = (int) $this->input->post('wo_id');
        $teknisi_id = (int) $this->input->post('teknisi_id');

        if ($wo_id <= 0 || $teknisi_id <= 0) {
            return $this->json(['success' => false, 'message' => 'wo_id dan teknisi_id wajib valid'], 422);
        }

        $completion_data = [
            'notes' => trim((string) $this->input->post('notes')),
            'photo_before' => trim((string) $this->input->post('photo_before')),
            'photo_after' => trim((string) $this->input->post('photo_after')),
            'photo_odp' => trim((string) $this->input->post('photo_odp')),
        ];

        try {
            $this->wo_manager->complete_wo($wo_id, $teknisi_id, $completion_data);
            $wo = $this->wo_model->get($wo_id);

            return $this->json([
                'success' => true,
                'message' => 'WO selesai diproses',
                'wo_status' => $wo ? $wo->status : null,
            ]);
        } catch (Exception $e) {
            return $this->json(['success' => false, 'message' => $e->getMessage()], 409);
        }
    }

    /**
     * Retry aktivasi manual: DONE -> ACTIVATED.
     * POST: wo_id, user_id, notes(optional)
     */
    public function activate()
    {
        $wo_id = (int) $this->input->post('wo_id');
        $user_id = (int) $this->input->post('user_id');
        $notes = trim((string) $this->input->post('notes'));

        if ($wo_id <= 0 || $user_id <= 0) {
            return $this->json(['success' => false, 'message' => 'wo_id dan user_id wajib valid'], 422);
        }

        try {
            $activation = $this->wo_manager->activate_wo($wo_id, $user_id, $notes);
            $wo = $this->wo_model->get($wo_id);

            return $this->json([
                'success' => true,
                'message' => 'Aktivasi WO berhasil',
                'wo_status' => $wo ? $wo->status : null,
                'activation' => $activation,
            ]);
        } catch (Exception $e) {
            return $this->json(['success' => false, 'message' => $e->getMessage()], 409);
        }
    }

    /**
     * Cancel WO.
     * POST: wo_id, admin_id, reason
     */
    public function cancel()
    {
        $wo_id = (int) $this->input->post('wo_id');
        $admin_id = (int) $this->input->post('admin_id');
        $reason = trim((string) $this->input->post('reason'));

        if ($wo_id <= 0 || $admin_id <= 0 || $reason === '') {
            return $this->json(['success' => false, 'message' => 'wo_id, admin_id, reason wajib valid'], 422);
        }

        try {
            $this->wo_manager->cancel_wo($wo_id, $admin_id, $reason);
            return $this->json(['success' => true, 'message' => 'WO dibatalkan']);
        } catch (Exception $e) {
            return $this->json(['success' => false, 'message' => $e->getMessage()], 409);
        }
    }

    /**
     * Detail WO + history.
     * GET: id
     */
    public function detail($id = null)
    {
        $id = (int) $id;
        if ($id <= 0) {
            return $this->json(['success' => false, 'message' => 'WO id tidak valid'], 422);
        }

        $wo = $this->wo_model->get_with_customer($id);
        if (!$wo) {
            return $this->json(['success' => false, 'message' => 'WO tidak ditemukan'], 404);
        }

        $history = $this->wo_history_model->get_by_wo($id);
        return $this->json(['success' => true, 'wo' => $wo, 'history' => $history]);
    }

    private function json(array $payload, $status = 200)
    {
        return $this->output
            ->set_content_type('application/json')
            ->set_status_header((int) $status)
            ->set_output(json_encode($payload));
    }
}
