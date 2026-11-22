<?php

declare(strict_types=1);

namespace NM\Services\CashRegister\Models;

use Core\Database\Model;

final class CashReceiptRecord extends Model
{
    protected static string $table = 'cash_receipts';
    protected static bool $timestamps = false;
}
