<?php $setting_menu = isset($setting_menu) ? $setting_menu : 'router'; ?>
<div class="card stat-card mb-3">
    <div class="card-body py-2">
        <ul class="nav nav-pills flex-column flex-md-row gap-2">
            <li class="nav-item">
                <a class="nav-link <?php echo $setting_menu === 'router' ? 'active' : 'text-dark'; ?>" href="<?php echo site_url('settings/routers'); ?>">Router List</a>
            </li>
            <li class="nav-item">
                <a class="nav-link <?php echo $setting_menu === 'router_acs' ? 'active' : 'text-dark'; ?>" href="<?php echo site_url('settings/router-acs'); ?>">Config ACS</a>
            </li>
            <li class="nav-item">
                <a class="nav-link <?php echo $setting_menu === 'telegram' ? 'active' : 'text-dark'; ?>" href="<?php echo site_url('settings/telegram'); ?>">Telegram</a>
            </li>
            <li class="nav-item">
                <a class="nav-link <?php echo $setting_menu === 'database' ? 'active' : 'text-dark'; ?>" href="<?php echo site_url('settings/database'); ?>">Database</a>
            </li>
        </ul>
    </div>
</div>
