<?php

declare(strict_types=1);

namespace Workerman\Stomp;

/**
 * Stand-in for the STOMP client exception used by onError.
 */
class Exception extends \RuntimeException
{
}
