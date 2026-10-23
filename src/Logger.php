<?php

declare(strict_types=1);

namespace Webman\Stomp;

/**
 * Minimal logging facade.
 *
 * Prefers the webman log facade when available, otherwise falls back to
 * error_log(). Tests may install a handler to capture messages.
 */
final class Logger
{
    /**
     * @var callable|null
     */
    private static $handler = null;

    public static function setHandler(?callable $handler): void
    {
        self::$handler = $handler;
    }

    public static function error(string $message): void
    {
        if (self::$handler !== null) {
            (self::$handler)($message);
            return;
        }

        $text = '[stomp-queue] ' . $message;

        try {
            if (class_exists(\support\Log::class)) {
                \support\Log::error($text);
                return;
            }
            if (class_exists(\support\bootstrap\Log::class)) {
                \support\bootstrap\Log::error($text);
                return;
            }
        } catch (\Throwable) {
            // Logging backend is not bootstrapped yet; fall back to error_log.
        }

        error_log($text);
    }
}
