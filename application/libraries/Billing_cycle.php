<?php
defined('BASEPATH') OR exit('No direct script access allowed');

/** Calendar-month cycles, independent of the customer's monthly due day. */
class Billing_cycle
{
    public function resolve(array $customer, $period)
    {
        if (!preg_match('/^\d{4}-(0[1-9]|1[0-2])$/D', (string) $period)) {
            throw new InvalidArgumentException('Periode harus YYYY-MM yang valid.');
        }
        $months = (int) ($customer['billing_interval_months'] ?? 1);
        if (!in_array($months, array(1, 2), true)) {
            throw new InvalidArgumentException('Siklus tagihan harus 1 atau 2 bulan.');
        }
        $start = new DateTimeImmutable($period . '-01');
        if ($months === 2) {
            $anchor = (string) ($customer['billing_cycle_start'] ?? '');
            if (!preg_match('/^\d{4}-(0[1-9]|1[0-2])-01$/D', $anchor)) {
                throw new InvalidArgumentException('Bulan awal siklus wajib diisi untuk tagihan 2 bulan.');
            }
            $distance = ((int) $start->format('Y') - (int) substr($anchor, 0, 4)) * 12
                + (int) $start->format('m') - (int) substr($anchor, 5, 2);
            if ($distance < 0 || $distance % $months !== 0) {
                return null;
            }
        }
        return array(
            'months' => $months,
            'start' => $start->format('Y-m-d'),
            'end' => $start->modify('+' . $months . ' months')->modify('-1 day')->format('Y-m-d'),
        );
    }
}
