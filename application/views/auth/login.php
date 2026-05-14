<?php
defined('BASEPATH') OR exit('No direct script access allowed');
$pwa_base = rtrim(base_url(), '/') . '/';
$pwa_manifest = $pwa_base . 'manifest.json';
$pwa_icon_192 = $pwa_base . 'pwa/icon-192.png';
$pwa_icon_512 = $pwa_base . 'pwa/icon-512.png';
$brand_name = app_name();
$brand_tagline = app_tagline();
$brand_company = app_company();
$brand_logo = base_url(ltrim(app_logo_url(false), '/'));
$brand_logo_dark = base_url(ltrim(app_logo_url(true), '/'));
$brand_icon = base_url(ltrim(app_icon_url(), '/'));
?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="theme-color" content="#1d4ed8">
    <meta name="mobile-web-app-capable" content="yes">
    <meta name="apple-mobile-web-app-capable" content="yes">
    <meta name="apple-mobile-web-app-title" content="<?php echo html_escape($brand_name); ?>">
    <link rel="manifest" href="<?php echo html_escape($pwa_manifest); ?>">
    <link rel="icon" type="image/png" href="<?php echo html_escape($brand_icon); ?>">
    <link rel="shortcut icon" type="image/png" href="<?php echo html_escape($brand_icon); ?>">
    <link rel="apple-touch-icon" href="<?php echo html_escape($brand_icon); ?>">
    <title><?php echo html_escape($brand_name . ' - ' . $brand_tagline); ?></title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <style>
        body {
            min-height: 100vh;
            position: relative;
            overflow: hidden;
            background: linear-gradient(135deg, #f8fafc 0%, #e2e8f0 100%);
        }
        .brand-overlay {
            position: absolute;
            inset: 0;
            pointer-events: none;
            display: flex;
            align-items: center;
            justify-content: center;
            flex-direction: column;
            opacity: 0.08;
            text-transform: uppercase;
            font-weight: 800;
            letter-spacing: 0.15em;
            color: #1e293b;
        }
        .brand-overlay-title {
            font-size: clamp(2.2rem, 8vw, 5rem);
            line-height: 1;
        }
        .brand-overlay-subtitle {
            font-size: clamp(0.7rem, 2vw, 1.2rem);
            margin-top: 0.5rem;
        }
        .login-card {
            width: 100%;
            max-width: 420px;
            position: relative;
            z-index: 2;
            border: 1px solid #e5e7eb;
            border-radius: 14px;
            box-shadow: 0 18px 40px rgba(2, 6, 23, 0.08);
        }
        .hero-copy {
            font-size: 0.85rem;
            color: #64748b;
            line-height: 1.45;
        }
        .powered-by {
            font-size: 0.78rem;
            color: #64748b;
        }
        .login-logo {
            display: block;
            margin: 0 auto;
            width: auto;
            max-height: 60px;
            max-width: min(72%, 280px);
            object-fit: contain;
        }
        @media (max-width: 575.98px) {
            .login-card {
                border-radius: 12px;
            }
            .card-body {
                padding: 1rem !important;
            }
            h1.h4 {
                font-size: 1.15rem;
            }
            .login-logo {
                max-height: 52px;
                max-width: min(84%, 240px);
            }
        }
    </style>
</head>
<body class="d-flex align-items-center justify-content-center p-3">
    <div class="brand-overlay">
        <div class="brand-overlay-title"><?php echo html_escape($brand_name); ?></div>
        <div class="brand-overlay-subtitle"><?php echo html_escape($brand_tagline); ?></div>
    </div>
    <div class="card login-card">
        <div class="card-body p-4">
            <div class="text-center mb-3">
                <img
                    src="<?php echo html_escape($brand_logo); ?>"
                    alt="<?php echo html_escape($brand_name); ?> Logo"
                    class="login-logo"
                    data-logo-light="<?php echo html_escape($brand_logo); ?>"
                    data-logo-dark="<?php echo html_escape($brand_logo_dark); ?>"
                >
            </div>
            <h1 class="h4 fw-bold mb-1"><?php echo html_escape($brand_name); ?></h1>
            <p class="text-muted mb-1"><?php echo html_escape($brand_tagline); ?></p>
            <p class="hero-copy mb-2">
                <?php echo html_escape($brand_name); ?> is a centralized ISP automation platform designed to manage MikroTik, Radius, Billing, Hotspot vouchers, and network monitoring in one integrated system.
            </p>
            <p class="powered-by mb-4">Powered by <?php echo html_escape($brand_company); ?></p>

            <?php if ($this->session->flashdata('error')): ?>
                <div class="alert alert-danger"><?php echo $this->session->flashdata('error'); ?></div>
            <?php endif; ?>

            <?php echo form_open('auth/process_login', array('class' => 'row g-3')); ?>
                <div class="col-12">
                    <label for="username" class="form-label">Username</label>
                    <input
                        type="text"
                        class="form-control"
                        id="username"
                        name="username"
                        value="<?php echo html_escape(set_value('username')); ?>"
                        required
                        autofocus
                    >
                </div>
                <div class="col-12">
                    <label for="password" class="form-label">Password</label>
                    <input
                        type="password"
                        class="form-control"
                        id="password"
                        name="password"
                        required
                    >
                </div>
                <div class="col-12">
                    <label for="captcha_answer" class="form-label">Verifikasi Human</label>
                    <div class="input-group">
                        <span class="input-group-text fw-semibold"><?php echo html_escape(isset($captcha_question) ? $captcha_question : '1 + 1 = ?'); ?></span>
                        <input
                            type="number"
                            class="form-control"
                            id="captcha_answer"
                            name="captcha_answer"
                            inputmode="numeric"
                            required
                        >
                    </div>
                    <small class="text-muted">Isi jawaban pertambahan/pengurangan di atas.</small>
                </div>
                <div class="col-12 d-grid">
                    <button type="submit" class="btn btn-primary">Login</button>
                </div>
            <?php echo form_close(); ?>
        </div>
    </div>
    <script>
    (function () {
        if (!('serviceWorker' in navigator)) {
            return;
        }
        window.addEventListener('load', function () {
            navigator.serviceWorker
                .register(<?php echo json_encode($pwa_base . 'pwa-sw.js'); ?>, { scope: <?php echo json_encode($pwa_base); ?> })
                .catch(function (error) {
                    console.warn('[PWA] Service worker registration failed:', error);
                });
        });
    })();
    </script>
</body>
</html>
