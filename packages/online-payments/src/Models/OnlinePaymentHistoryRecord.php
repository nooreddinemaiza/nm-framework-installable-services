<?php

declare(strict_types=1);

namespace NM\Services\OnlinePayments\Models;

use Core\Database\Model;

final class OnlinePaymentHistoryRecord extends Model
{
    protected static string $table = 'online_payment_status_history';
    protected static bool $timestamps = false;
}
