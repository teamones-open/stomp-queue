<?php

declare(strict_types=1);

namespace Workerman;

/**
 * Minimal Workerman\Worker stand-in for unit tests.
 */
class Worker
{
    public const STATUS_INITIAL = 0;
    public const STATUS_STARTING = 1;
    public const STATUS_RUNNING = 2;
    public const STATUS_SHUTDOWN = 4;
    public const STATUS_RELOADING = 8;

    public static int $status = self::STATUS_RUNNING;

    public static function getStatus(): int
    {
        return self::$status;
    }
}
