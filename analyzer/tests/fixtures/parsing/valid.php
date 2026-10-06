<?php
// A comment line.
namespace App\Billing;

use App\Models\Invoice;

/**
 * Doc block.
 */
final class InvoiceService extends Service implements Billable
{
    public function total(array $items, bool $strict = false): int
    {
        $sum = 0;
        foreach ($items as $item) {
            if ($item > 0 && $strict) {
                $sum += $item;
            } elseif ($item < 0) {
                $sum -= 1;
            }
        }
        return $sum > 100 ? 100 : $sum;
    }

    private static function check($a) { return $a ?? 0; }
}

function helper($x) {
    switch ($x) { case 1: return 1; case 2: return 2; default: return 0; }
}
