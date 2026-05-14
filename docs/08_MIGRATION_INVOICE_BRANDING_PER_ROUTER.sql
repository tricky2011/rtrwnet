-- =====================================================================
-- MIGRATION: INVOICE BRANDING PER ROUTER (SINGLE INSTALL MULTI ROUTER)
-- Aman dijalankan berulang (idempotent) pada MariaDB/MySQL modern.
-- =====================================================================

SET @db_name := DATABASE();

-- ---------------------------------------------------------------------
-- STEP 1: Tambah kolom branding pada tabel routers (jika belum ada)
-- ---------------------------------------------------------------------
ALTER TABLE `routers`
    ADD COLUMN IF NOT EXISTS `brand_name` VARCHAR(150) NULL AFTER `description`,
    ADD COLUMN IF NOT EXISTS `brand_logo` VARCHAR(255) NULL AFTER `brand_name`,
    ADD COLUMN IF NOT EXISTS `brand_address` TEXT NULL AFTER `brand_logo`,
    ADD COLUMN IF NOT EXISTS `brand_phone` VARCHAR(50) NULL AFTER `brand_address`,
    ADD COLUMN IF NOT EXISTS `brand_email` VARCHAR(100) NULL AFTER `brand_phone`,
    ADD COLUMN IF NOT EXISTS `brand_website` VARCHAR(150) NULL AFTER `brand_email`,
    ADD COLUMN IF NOT EXISTS `brand_bank_name` VARCHAR(150) NULL AFTER `brand_website`,
    ADD COLUMN IF NOT EXISTS `brand_bank_account` VARCHAR(100) NULL AFTER `brand_bank_name`,
    ADD COLUMN IF NOT EXISTS `brand_bank_holder` VARCHAR(150) NULL AFTER `brand_bank_account`,
    ADD COLUMN IF NOT EXISTS `invoice_footer` TEXT NULL AFTER `brand_bank_holder`;

-- ---------------------------------------------------------------------
-- STEP 2: Bind branding legacy ke router Kalisari (jika table settings ada)
-- Mendukung 2 format:
--   a) settings(key, value)
--   b) settings(setting_key, setting_value)
-- ---------------------------------------------------------------------
SET @has_settings_table := (
    SELECT COUNT(*)
    FROM information_schema.tables
    WHERE table_schema = @db_name
      AND table_name = 'settings'
);

SET @has_key_value := (
    SELECT COUNT(*)
    FROM information_schema.columns
    WHERE table_schema = @db_name
      AND table_name = 'settings'
      AND column_name IN ('key', 'value')
);

SET @has_setting_key_value := (
    SELECT COUNT(*)
    FROM information_schema.columns
    WHERE table_schema = @db_name
      AND table_name = 'settings'
      AND column_name IN ('setting_key', 'setting_value')
);

SET @sql_bind_legacy := IF(
    @has_settings_table = 1 AND @has_key_value = 2,
    "UPDATE routers r
     JOIN (
        SELECT
            MAX(CASE WHEN `key` IN ('company_name','site_name','app_name') THEN `value` END) AS brand_name,
            MAX(CASE WHEN `key` IN ('company_address','address') THEN `value` END) AS brand_address,
            MAX(CASE WHEN `key` IN ('company_phone','phone') THEN `value` END) AS brand_phone,
            MAX(CASE WHEN `key` IN ('company_email','email') THEN `value` END) AS brand_email,
            MAX(CASE WHEN `key` IN ('company_website','website') THEN `value` END) AS brand_website,
            MAX(CASE WHEN `key` IN ('company_bank_name','bank_name') THEN `value` END) AS brand_bank_name,
            MAX(CASE WHEN `key` IN ('company_bank_account','bank_account','bank_number') THEN `value` END) AS brand_bank_account,
            MAX(CASE WHEN `key` IN ('company_bank_holder','bank_holder') THEN `value` END) AS brand_bank_holder,
            MAX(CASE WHEN `key` IN ('invoice_footer','company_invoice_footer') THEN `value` END) AS invoice_footer
        FROM settings
     ) s ON 1=1
     SET
        r.brand_name        = COALESCE(NULLIF(r.brand_name,''), NULLIF(s.brand_name,'')),
        r.brand_address     = COALESCE(NULLIF(r.brand_address,''), NULLIF(s.brand_address,'')),
        r.brand_phone       = COALESCE(NULLIF(r.brand_phone,''), NULLIF(s.brand_phone,'')),
        r.brand_email       = COALESCE(NULLIF(r.brand_email,''), NULLIF(s.brand_email,'')),
        r.brand_website     = COALESCE(NULLIF(r.brand_website,''), NULLIF(s.brand_website,'')),
        r.brand_bank_name   = COALESCE(NULLIF(r.brand_bank_name,''), NULLIF(s.brand_bank_name,'')),
        r.brand_bank_account= COALESCE(NULLIF(r.brand_bank_account,''), NULLIF(s.brand_bank_account,'')),
        r.brand_bank_holder = COALESCE(NULLIF(r.brand_bank_holder,''), NULLIF(s.brand_bank_holder,'')),
        r.invoice_footer    = COALESCE(NULLIF(r.invoice_footer,''), NULLIF(s.invoice_footer,''))
     WHERE LOWER(r.name) = 'kalisari'",
    IF(
        @has_settings_table = 1 AND @has_setting_key_value = 2,
        "UPDATE routers r
         JOIN (
            SELECT
                MAX(CASE WHEN `setting_key` IN ('company_name','site_name','app_name') THEN `setting_value` END) AS brand_name,
                MAX(CASE WHEN `setting_key` IN ('company_address','address') THEN `setting_value` END) AS brand_address,
                MAX(CASE WHEN `setting_key` IN ('company_phone','phone') THEN `setting_value` END) AS brand_phone,
                MAX(CASE WHEN `setting_key` IN ('company_email','email') THEN `setting_value` END) AS brand_email,
                MAX(CASE WHEN `setting_key` IN ('company_website','website') THEN `setting_value` END) AS brand_website,
                MAX(CASE WHEN `setting_key` IN ('company_bank_name','bank_name') THEN `setting_value` END) AS brand_bank_name,
                MAX(CASE WHEN `setting_key` IN ('company_bank_account','bank_account','bank_number') THEN `setting_value` END) AS brand_bank_account,
                MAX(CASE WHEN `setting_key` IN ('company_bank_holder','bank_holder') THEN `setting_value` END) AS brand_bank_holder,
                MAX(CASE WHEN `setting_key` IN ('invoice_footer','company_invoice_footer') THEN `setting_value` END) AS invoice_footer
            FROM settings
         ) s ON 1=1
         SET
            r.brand_name        = COALESCE(NULLIF(r.brand_name,''), NULLIF(s.brand_name,'')),
            r.brand_address     = COALESCE(NULLIF(r.brand_address,''), NULLIF(s.brand_address,'')),
            r.brand_phone       = COALESCE(NULLIF(r.brand_phone,''), NULLIF(s.brand_phone,'')),
            r.brand_email       = COALESCE(NULLIF(r.brand_email,''), NULLIF(s.brand_email,'')),
            r.brand_website     = COALESCE(NULLIF(r.brand_website,''), NULLIF(s.brand_website,'')),
            r.brand_bank_name   = COALESCE(NULLIF(r.brand_bank_name,''), NULLIF(s.brand_bank_name,'')),
            r.brand_bank_account= COALESCE(NULLIF(r.brand_bank_account,''), NULLIF(s.brand_bank_account,'')),
            r.brand_bank_holder = COALESCE(NULLIF(r.brand_bank_holder,''), NULLIF(s.brand_bank_holder,'')),
            r.invoice_footer    = COALESCE(NULLIF(r.invoice_footer,''), NULLIF(s.invoice_footer,''))
         WHERE LOWER(r.name) = 'kalisari'",
        "SELECT 'SKIP bind legacy: tabel settings tidak ditemukan / format tidak dikenali' AS info"
    )
);

PREPARE stmt_bind_legacy FROM @sql_bind_legacy;
EXECUTE stmt_bind_legacy;
DEALLOCATE PREPARE stmt_bind_legacy;

-- ---------------------------------------------------------------------
-- STEP 3: Fallback default aman untuk Kalisari jika branding kosong
-- ---------------------------------------------------------------------
UPDATE `routers`
SET
    `brand_name` = COALESCE(NULLIF(`brand_name`, ''), `name`),
    `invoice_footer` = COALESCE(NULLIF(`invoice_footer`, ''), 'Terima kasih telah menggunakan layanan kami.')
WHERE LOWER(`name`) = 'kalisari';

-- ---------------------------------------------------------------------
-- STEP 4: Validasi hasil
-- ---------------------------------------------------------------------
SELECT
    `id`,
    `name`,
    `brand_name`,
    `brand_logo`,
    `brand_phone`,
    `brand_email`,
    `brand_bank_name`,
    `brand_bank_account`
FROM `routers`
ORDER BY `id` ASC;

