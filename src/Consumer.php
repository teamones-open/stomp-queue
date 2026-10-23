<?php

declare(strict_types=1);

namespace Webman\Stomp;

/**
 * A consumer class scanned by Process\Consumer.
 *
 * Public properties read by the process:
 *  - string        $queue       queue name (or array of names)
 *  - string        $connection  connection name from config/stomp.php
 *  - string        $ack         auto|client|client-individual
 *  - int           $prefetch    STOMP prefetch-count, defaults to 10
 */
interface Consumer
{
    /**
     * @param string $data raw message body
     * @param \Workerman\Stomp\AckResolver|null $ackResolver
     * @param \Workerman\Stomp\Client|null $client
     * @return mixed
     */
    public function consume($data);
}
