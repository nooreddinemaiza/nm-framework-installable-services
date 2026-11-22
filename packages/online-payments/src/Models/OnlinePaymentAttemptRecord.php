<?php

declare(strict_types=1);

namespace NM\Services\OnlinePayments\Models;

use Core\Database\Model;

final class OnlinePaymentAttemptRecord extends Model
{
    protected static string $table = 'online_payment_attempts';
}
