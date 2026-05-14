<?php
$notif_user_id = (int) $this->session->userdata('user_id');
$notif_role = strtolower(trim((string) $this->session->userdata('role')));

$this->config->load('pusher', true);
$pusher_cfg = (array) $this->config->item('pusher');

$pusher_enabled = !empty($pusher_cfg['pusher_enabled']);
$pusher_key = $pusher_enabled
    ? trim((string) ($pusher_cfg['pusher_key'] ?? ''))
    : '';
$pusher_cluster = trim((string) ($pusher_cfg['pusher_cluster'] ?? 'ap1'));
$pusher_public_channel = trim((string) ($pusher_cfg['pusher_channel_public'] ?? 'superapps-channel'));
$pusher_private_prefix = trim((string) ($pusher_cfg['pusher_channel_private_prefix'] ?? 'private-user-'));
$pusher_event_name = trim((string) ($pusher_cfg['pusher_event_new_notification'] ?? 'new-notification'));
?>
<div id="notificationRealtimeRoot" class="dropdown notification-dropdown">
    <button
        type="button"
        class="header-action position-relative"
        id="notificationDropdownBtn"
        data-bs-toggle="dropdown"
        aria-expanded="false"
        title="Notifikasi">
        <i class="ti ti-bell"></i>
        <span id="notificationBadge" class="notif-badge d-none">0</span>
    </button>

    <div class="dropdown-menu dropdown-menu-end notif-dropdown-menu p-0" aria-labelledby="notificationDropdownBtn">
        <div class="notif-dropdown-header d-flex align-items-center justify-content-between">
            <div class="fw-semibold">Notifikasi</div>
            <button type="button" id="notificationMarkAllBtn" class="btn btn-sm btn-light border">Mark all read</button>
        </div>
        <div id="notificationList" class="notif-list">
            <div class="notif-empty">Belum ada notifikasi.</div>
        </div>
        <div class="notif-dropdown-footer d-flex justify-content-between align-items-center">
            <span id="notificationUnreadLabel" class="text-muted small">0 unread</span>
        </div>
    </div>
</div>

<script>
window.APP_NOTIFICATION = {
    userId: <?php echo (int) $notif_user_id; ?>,
    role: <?php echo json_encode($notif_role); ?>,
    csrf: {
        name: <?php echo json_encode($this->security->get_csrf_token_name()); ?>,
        hash: <?php echo json_encode($this->security->get_csrf_hash()); ?>
    },
    pusher: {
        enabled: <?php echo json_encode($pusher_enabled); ?>,
        key: <?php echo json_encode($pusher_key); ?>,
        cluster: <?php echo json_encode($pusher_cluster); ?>,
        publicChannel: <?php echo json_encode($pusher_public_channel); ?>,
        privatePrefix: <?php echo json_encode($pusher_private_prefix); ?>,
        eventName: <?php echo json_encode($pusher_event_name); ?>,
        allowPublic: <?php echo json_encode(in_array($notif_role, array('superadmin', 'admin'), true)); ?>
    },
    endpoints: {
        latest: <?php echo json_encode(site_url('notification/latest')); ?>,
        unreadCount: <?php echo json_encode(site_url('notification/unread_count')); ?>,
        markReadBase: <?php echo json_encode(site_url('notification/mark_read')); ?>,
        markAll: <?php echo json_encode(site_url('notification/mark_all_read')); ?>,
        auth: <?php echo json_encode(site_url('notification/auth')); ?>
    }
};
</script>
