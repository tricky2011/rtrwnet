# WhatsApp Gateway Billing

## SQL

Jalankan query manual:

```sql
SOURCE docs/11_MIGRATION_WHATSAPP_GATEWAY.sql;
```

Atau jalankan migration CodeIgniter sesuai mekanisme migrasi aplikasi:

```bash
php index.php migrate latest
```

## Environment

```bash
WA_ENABLED=true
WA_PROVIDER=gateway
WA_API_URL=https://gateway.example.com/api/send
WA_API_TOKEN=replace-with-real-token
WA_SENDER=62812xxxxxxx
WA_DELAY_SECONDS=15
WA_QUEUE_LIMIT=10
WA_MAX_RETRY=3
WA_PAYMENT_INFO="BCA 123456789 a.n. Perusahaan / konfirmasi ke admin billing"
WA_CRON_SECRET=replace-with-secret
```

## Cron

```cron
*/5 * * * * php /path/to/project/index.php cron_whatsapp process_queue
0 8 * * * php /path/to/project/index.php cron_whatsapp reminder_due
0 9 * * * php /path/to/project/index.php cron_whatsapp reminder_overdue
```

Jika harus via HTTP, gunakan secret:

```bash
curl "https://domain.example/index.php/cron_whatsapp/process_queue?key=replace-with-secret"
```

## Contoh Enqueue Saat Invoice Dibuat

```php
$this->load->helper('wa_template');
$this->load->library('Whatsapp_service');

$message = invoice_created_message(
    $customer->full_name,
    $invoice->invoice_number,
    $invoice->total_amount,
    $invoice->due_date,
    $this->config->item('wa_payment_info')
);

$this->whatsapp_service->send_message(
    $customer->phone,
    $message,
    $invoice->id,
    $customer->id
);
```

## Contoh Enqueue Saat Pembayaran Di-approve

```php
$this->load->helper('wa_template');
$this->load->library('Whatsapp_service');

$message = payment_received_message(
    $customer->full_name,
    $invoice->invoice_number,
    $payment->amount,
    $payment->payment_date
);

$this->whatsapp_service->send_message(
    $customer->phone,
    $message,
    $invoice->id,
    $customer->id
);
```

Implementasi utama sudah ditempel di `Billing_automation_service`: invoice created dan pembayaran lunas otomatis membuat log `pending`; cron `process_queue` yang mengirim ke gateway.
