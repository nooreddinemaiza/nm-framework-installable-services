<?php

declare(strict_types=1);

namespace NM\Services\OnlinePayments\Models;

use Core\Database\Model;

final class OnlinePaymentEventRecord extends Model
{
    protected static string $table = 'online_payment_events';
    protected static bool $timestamps = false;
}
