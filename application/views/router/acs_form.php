<?php
$page_title = 'Edit Config ACS Router - ' . app_name();
$page_heading = 'Edit Config ACS Router';
$page_subheading = 'Set ACS Inform URL dan NBI URL per router.';
$active_menu = 'router_acs';

$row = isset($row) && is_array($row) ? $row : array();
$router_id = (int) ($row['id'] ?? 0);
$router_name = (string) ($row['name'] ?? ('Router #' . $router_id));

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
<?php if (validation_errors()): ?>
    <div class="alert alert-danger"><?php echo validation_errors(); ?></div>
<?php endif; ?>

<div class="card stat-card mb-3">
    <div class="card-header bg-white fw-semibold d-flex justify-content-between align-items-center">
        <span>Router: <?php echo html_escape($router_name); ?></span>
        <a href="<?php echo site_url('router-acs'); ?>" class="btn btn-sm btn-outline-secondary">Kembali</a>
    </div>
    <div class="card-body">
        <?php echo form_open('router-acs/update/' . $router_id, array('id' => 'formRouterAcs')); ?>
            <div class="row g-3">
                <div class="col-md-6">
                    <label class="form-label">ACS Inform URL</label>
                    <input type="url"
                           name="acs_url"
                           class="form-control"
                           maxlength="255"
                           required
                           value="<?php echo html_escape((string) set_value('acs_url', $row['acs_url'] ?? '')); ?>"
                           placeholder="http://10.10.10.2:7547">
                    <div class="form-text">Contoh: <code>http://10.10.10.2:7547</code></div>
                </div>
                <div class="col-md-6">
                    <label class="form-label">ACS NBI URL</label>
                    <input type="url"
                           name="acs_nbi_url"
                           class="form-control"
                           maxlength="255"
                           required
                           value="<?php echo html_escape((string) set_value('acs_nbi_url', $row['acs_nbi_url'] ?? '')); ?>"
                           placeholder="http://10.10.10.2:7557">
                    <div class="form-text">Digunakan untuk endpoint API <code>/devices</code>.</div>
                </div>
                <div class="col-md-6">
                    <label class="form-label">Username (Opsional)</label>
                    <input type="text"
                           name="acs_username"
                           class="form-control"
                           maxlength="100"
                           value="<?php echo html_escape((string) set_value('acs_username', $row['acs_username'] ?? '')); ?>"
                           placeholder="admin">
                </div>
                <div class="col-md-6">
                    <label class="form-label">Password (Opsional)</label>
                    <input type="password"
                           name="acs_password"
                           class="form-control"
                           maxlength="100"
                           value=""
                           placeholder="Kosongkan jika tidak diubah">
                    <div class="form-text">Password disimpan terenkripsi. Tidak ditampilkan plaintext.</div>
                </div>
            </div>

            <div class="d-flex flex-wrap gap-2 mt-4">
                <button type="submit" class="btn btn-primary">Simpan</button>
                <button type="button"
                        id="btnTestConnection"
                        class="btn btn-outline-success"
                        data-url="<?php echo site_url('router-acs/test-connection/' . $router_id); ?>">
                    Test Connection
                </button>
            </div>
        <?php echo form_close(); ?>
    </div>
</div>

<?php
$csrf_name = $this->security->get_csrf_token_name();
$csrf_hash = $this->security->get_csrf_hash();
ob_start();
?>
<script>
document.addEventListener('DOMContentLoaded', function () {
  var btn = document.getElementById('btnTestConnection');
  if (!btn) return;
  var form = document.getElementById('formRouterAcs');

  var csrfName = <?php echo json_encode($csrf_name); ?>;
  var csrfHash = <?php echo json_encode($csrf_hash); ?>;

  function syncCsrf(nextHash) {
    if (!nextHash) return;
    csrfHash = String(nextHash);
    if (!form) return;
    var csrfInput = form.querySelector('input[name="' + csrfName + '"]');
    if (csrfInput) {
      csrfInput.value = csrfHash;
    }
  }

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
        syncCsrf(json.csrf_hash);
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
</script>
<?php
$page_scripts = ob_get_clean();

$content = ob_get_clean();
include APPPATH . 'views/layout/master.php';
