<?php

declare(strict_types=1);

namespace NM\Services\OnlinePayments\Models;

use Core\Database\Model;

final class OnlinePaymentProviderRecord extends Model
{
    protected static string $table = 'online_payment_providers';
    protected static array $hidden = ['credentials_ciphertext'];
}
