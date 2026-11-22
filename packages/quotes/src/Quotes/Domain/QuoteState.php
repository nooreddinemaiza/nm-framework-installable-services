<?php

declare(strict_types=1);

namespace NM\Services\Quotes\Quotes\Domain;

use InvalidArgumentException;

final class QuoteState
{
    public const PENDING = 'pending';
    public const PROCESSING = 'processing';
    public const SENT = 'sent';
    public const ACCEPTED = 'accepted';
    public const REJECTED = 'rejected';
    public const EXPIRED = 'expired';
    public const CONVERTED = 'converted';
    public const CANCELLED = 'cancelled';

    private const TRANSITIONS = [
        self::PENDING => [self::PROCESSING, self::CANCELLED],
        self::PROCESSING => [self::PENDING, self::SENT, self::EXPIRED, self::CANCELLED],
        self::SENT => [self::ACCEPTED, self::REJECTED, self::EXPIRED, self::CANCELLED],
        self::ACCEPTED => [self::CONVERTED, self::EXPIRED, self::CANCELLED],
        self::REJECTED => [self::PROCESSING, self::CANCELLED],
        self::EXPIRED => [self::PROCESSING, self::CANCELLED],
        self::CONVERTED => [],
        self::CANCELLED => [],
    ];

    private function __construct()
    {
    }

    public static function all(): array
    {
        return array_keys(self::TRANSITIONS);
    }

    public static function isKnown(string $status): bool
    {
        return isset(self::TRANSITIONS[$status]);
    }

    public static function allows(string $from, string $to): bool
    {
        if (!self::isKnown($from) || !self::isKnown($to)) {
            throw new InvalidArgumentException('Statut de devis inconnu.');
        }

        return in_array($to, self::TRANSITIONS[$from], true);
    }
}
