<?php

declare(strict_types=1);

namespace NM\Services\SalesService\Sales\Domain;

final class SalesOrderState
{
    public const PENDING = 'pending';
    public const CONFIRMED = 'confirmed';
    public const PROCESSING = 'processing';
    public const READY = 'ready';
    public const COMPLETED = 'completed';
    public const CANCELLED = 'cancelled';

    /** @var array<string, list<string>> */
    private const TRANSITIONS = [
        self::PENDING => [self::CONFIRMED, self::CANCELLED],
        self::CONFIRMED => [self::PROCESSING, self::CANCELLED],
        self::PROCESSING => [self::READY],
        self::READY => [self::COMPLETED],
        self::COMPLETED => [],
        self::CANCELLED => [],
    ];

    private function __construct()
    {
    }

    public static function isKnown(string $status): bool
    {
        return array_key_exists($status, self::TRANSITIONS);
    }

    public static function allows(string $from, string $to): bool
    {
        return in_array($to, self::TRANSITIONS[$from] ?? [], true);
    }
}
