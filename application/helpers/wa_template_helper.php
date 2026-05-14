<?php
defined('BASEPATH') OR exit('No direct script access allowed');

if (!function_exists('wa_format_amount')) {
    function wa_format_amount($amount)
    {
        return number_format((float) $amount, 0, ',', '.');
    }
}

if (!function_exists('wa_format_date')) {
    function wa_format_date($date)
    {
        $date = trim((string) $date);
        if ($date === '' || strtotime($date) === false) {
            return '-';
        }

        return date('d-m-Y', strtotime($date));
    }
}

if (!function_exists('wa_customer_label')) {
    function wa_customer_label($customer_name)
    {
        $customer_name = trim((string) $customer_name);
        return $customer_name !== '' ? $customer_name : 'Pelanggan';
    }
}

if (!function_exists('invoice_created_message')) {
    function invoice_created_message($customer_name, $invoice_no, $amount, $due_date, $payment_info)
    {
        return "Halo Bapak/Ibu " . wa_customer_label($customer_name) . ",\n\n"
            . "Tagihan Anda telah terbit.\n\n"
            . "No Invoice: " . trim((string) $invoice_no) . "\n"
            . "Nominal: Rp " . wa_format_amount($amount) . "\n"
            . "Jatuh Tempo: " . wa_format_date($due_date) . "\n\n"
            . "Silakan lakukan pembayaran melalui:\n"
            . trim((string) $payment_info) . "\n\n"
            . "Abaikan pesan ini jika sudah membayar.\n"
            . "Terima kasih.";
    }
}

if (!function_exists('invoice_due_reminder_message')) {
    function invoice_due_reminder_message($customer_name, $invoice_no, $amount, $due_date, $payment_info)
    {
        return "Halo Bapak/Ibu " . wa_customer_label($customer_name) . ",\n\n"
            . "Kami mengingatkan bahwa tagihan berikut masih tercatat belum lunas.\n\n"
            . "No Invoice: " . trim((string) $invoice_no) . "\n"
            . "Nominal: Rp " . wa_format_amount($amount) . "\n"
            . "Jatuh Tempo: " . wa_format_date($due_date) . "\n\n"
            . "Silakan lakukan pembayaran melalui:\n"
            . trim((string) $payment_info) . "\n\n"
            . "Jika sudah membayar, mohon abaikan pesan ini atau konfirmasi ke admin billing.\n"
            . "Terima kasih.";
    }
}

if (!function_exists('invoice_overdue_message')) {
    function invoice_overdue_message($customer_name, $invoice_no, $amount, $due_date, $payment_info)
    {
        return "Halo Bapak/Ibu " . wa_customer_label($customer_name) . ",\n\n"
            . "Tagihan Anda telah melewati jatuh tempo dan masih tercatat belum lunas.\n\n"
            . "No Invoice: " . trim((string) $invoice_no) . "\n"
            . "Nominal: Rp " . wa_format_amount($amount) . "\n"
            . "Jatuh Tempo: " . wa_format_date($due_date) . "\n\n"
            . "Mohon segera melakukan pembayaran melalui:\n"
            . trim((string) $payment_info) . "\n\n"
            . "Jika sudah membayar, mohon abaikan pesan ini atau konfirmasi ke admin billing.\n"
            . "Terima kasih.";
    }
}

if (!function_exists('payment_received_message')) {
    function payment_received_message($customer_name, $invoice_no, $amount, $paid_at)
    {
        return "Halo Bapak/Ibu " . wa_customer_label($customer_name) . ",\n\n"
            . "Pembayaran Anda telah kami terima.\n\n"
            . "No Invoice: " . trim((string) $invoice_no) . "\n"
            . "Nominal Dibayar: Rp " . wa_format_amount($amount) . "\n"
            . "Tanggal Bayar: " . wa_format_date($paid_at) . "\n\n"
            . "Terima kasih atas pembayaran Anda.";
    }
}
