<?php
$page_title = 'Settings ACS Router - ' . app_name();
$page_heading = 'Settings: Config ACS';
$page_subheading = 'Konfigurasi GenieACS per router (tanpa config global).';
$active_menu = 'router_acs';

$rows = isset($rows) && is_array($rows) ? $rows : array();
$role = strtolower(trim((string) $this->session->userdata('role')));
$can_manage = in_array($role, array('superadmin', 'admin'), true);

ob_start();
?>

<?php
$setting_menu = 'router_acs';
include APPPATH . 'views/settings/_menu.php';
?>

<?php if ($this->session->flashdata('success')): ?>
    <div class="alert alert-success"><?php echo html_escape((string) $this->session->flashdata('success')); ?></div>
<?php endif; ?>
<?php if ($this->session->flashdata('error')): ?>
    <div class="alert alert-danger"><?php echo html_escape((string) $this->session->flashdata('error')); ?></div>
<?php endif; ?>

<div class="card stat-card">
    <div class="card-header bg-white fw-semibold">Config ACS Per Router</div>
    <div class="card-body p-0">
        <div class="table-responsive">
            <table class="table table-striped table-hover mb-0 align-middle">
                <thead class="table-light">
                    <tr>
                        <th class="ps-3">Nama Router</th>
                        <th>IP Router</th>
                        <th>ACS URL</th>
                        <th>NBI URL</th>
                        <th>Status</th>
                        <th class="text-end pe-3">Aksi</th>
                    </tr>
                </thead>
                <tbody>
                <?php if (empty($rows)): ?>
                    <tr>
                        <td colspan="6" class="text-center text-muted py-4">Belum ada data router.</td>
                    </tr>
                <?php else: ?>
                    <?php foreach ($rows as $r): ?>
                        <?php
                        $id = (int) ($r['id'] ?? 0);
                        $status = strtolower(trim((string) ($r['acs_status'] ?? 'disconnected')));
                        $status = in_array($status, array('connected', 'disconnected'), true) ? $status : 'disconnected';
                        $statusClass = $status === 'connected' ? 'text-bg-success' : 'text-bg-danger';
                        ?>
                        <tr data-router-id="<?php echo $id; ?>">
                            <td class="ps-3 fw-semibold"><?php echo html_escape((string) ($r['name'] ?? ('Router #' . $id))); ?></td>
                            <td><code><?php echo html_escape((string) ($r['ip_address'] ?? '-')); ?></code></td>
                            <td><?php echo html_escape((string) (($r['acs_url'] ?? '') !== '' ? $r['acs_url'] : '-')); ?></td>
                            <td><?php echo html_escape((string) (($r['acs_nbi_url'] ?? '') !== '' ? $r['acs_nbi_url'] : '-')); ?></td>
                            <td>
                                <span class="badge js-acs-status <?php echo $statusClass; ?>"><?php echo strtoupper($status); ?></span>
                            </td>
                            <td class="text-end pe-3">
                                <?php if ($can_manage): ?>
                                    <a href="<?php echo site_url('router-acs/edit/' . $id); ?>" class="btn btn-sm btn-outline-primary">Edit</a>
                                    <button type="button"
                                            class="btn btn-sm btn-outline-success js-test-acs"
                                            data-url="<?php echo site_url('router-acs/test-connection/' . $id); ?>">
                                        Test Connection
                                    </button>
                                <?php else: ?>
                                    <span class="badge text-bg-secondary">Read Only</span>
                                <?php endif; ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<?php
$csrf_name = $this->security->get_csrf_token_name();
$csrf_hash = $this->security->get_csrf_hash();
ob_start();
?>
<script>
document.addEventListener('DOMContentLoaded', function () {
  var csrfName = <?php echo json_encode($csrf_name); ?>;
  var csrfHash = <?php echo json_encode($csrf_hash); ?>;

  document.querySelectorAll('.js-test-acs').forEach(function (btn) {
    btn.addEventListener('click', function () {
      var url = btn.getAttribute('data-url');
      if (!url) return;

      btn.disabled = true;
      btn.textContent = 'Testing...';

      var formData = new URLSearchParams();
      formData.append(csrfName, csrfHash);

      fetch(url, {
        method: 'POST',
        headers: {
          'X-Requested-With': 'XMLHttpRequest',
          'Accept': 'application/json',
          'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8'
        },
        body: formData.toString()
      })
      .then(function (resp) { return resp.json(); })
      .then(function (json) {
        if (json && json.csrf_hash) {
          csrfHash = String(json.csrf_hash);
        }
        var tr = btn.closest('tr');
        if (!tr) return;
        var badge = tr.querySelector('.js-acs-status');
        if (badge) {
          badge.classList.remove('text-bg-success', 'text-bg-danger');
          var connected = json && json.status === 'connected';
          badge.classList.add(connected ? 'text-bg-success' : 'text-bg-danger');
          badge.textContent = connected ? 'CONNECTED' : 'DISCONNECTED';
        }
        if (window.Swal) {
          Swal.fire({
            icon: json && json.success ? 'success' : 'error',
            title: json && json.success ? 'Connected' : 'Disconnected',
            text: json && json.message ? json.message : 'Test koneksi selesai.'
          });
        }
      })
      .catch(function () {
        if (window.Swal) {
          Swal.fire({
            icon: 'error',
            title: 'Error',
            text: 'Gagal menghubungi endpoint test koneksi.'
          });
        }
      })
      .finally(function () {
        btn.disabled = false;
        btn.textContent = 'Test Connection';
      });
    });
  });
});
</script>
<?php
$page_scripts = ob_get_clean();

$content = ob_get_clean();
include APPPATH . 'views/layout/master.php';
