<?php

declare(strict_types=1);

namespace Webman\Stomp;

/**
 * A consumer that controls its own retry policy.
 */
interface RetryAbleConsumer
{
    /**
     * Retry interval, in seconds.
     *
     * @return int time to reserve in seconds
     */
    public function getTtr();

    /**
     * Whether the message may be consumed again.
     *
     * @param int $attempt number of previous attempts
     * @param \Exception|\Throwable $error error from the last execution
     * @return bool
     */
    public function canRetry($attempt, $error);
}
