<?php

declare(strict_types=1);

namespace NM\Services\CashRegister\Models;

use Core\Database\Model;

final class CashMovementRecord extends Model
{
    protected static string $table = 'cash_movements';
    protected static bool $timestamps = false;
}
