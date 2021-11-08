<?php

namespace Webman\Stomp;


/**
 * Interface Consumer
 * @package Webman\Stomp
 */
interface RetryAbleConsumer
{
    /**
     * 消息重新消费间隔时间
     * @return int time to reserve in seconds
     */
    public function getTtr();

    /**
     * 判断消息是否能重复消费
     * @param int $attempt number
     * @param \Exception|\Throwable $error from last execute of the job
     * @return bool
     */
    public function canRetry($attempt, $error);
}