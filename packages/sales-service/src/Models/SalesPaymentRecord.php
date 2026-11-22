<?php

declare(strict_types=1);

namespace NM\Services\SalesService\Models;

use Core\Database\Model;

final class SalesPaymentRecord extends Model
{
    protected static string $table = 'sales_payments';
    protected static bool $timestamps = false;
}
