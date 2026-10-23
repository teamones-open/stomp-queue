<?php

declare(strict_types=1);

namespace Webman\Stomp\Process;

use Webman\Stomp\AmqpLib\Enforcer;
use Webman\Stomp\Client;
use Webman\Stomp\Consumer as ConsumerInterface;
use Webman\Stomp\Logger;
use Webman\Stomp\RetryAbleConsumer;
use Workerman\Worker;

/**
 * Discovers consumer classes, declares the AMQP topology and subscribes over STOMP.
 */
class Consumer
{
    /**
     * @var string
     */
    protected string $_consumerDir = '';

    /**
     * @param string $consumer_dir
     */
    public function __construct($consumer_dir = '')
    {
        $this->_consumerDir = (string)$consumer_dir;
    }

    /**
     * onWorkerStart.
     *
     * @throws \Exception
     */
    public function onWorkerStart(): void
    {
        $connectionList = $this->discoverConsumers();

        foreach ($connectionList as $connectionName => $consumers) {
            $this->bootstrapConnection($connectionName, $consumers);
        }
    }

    /**
     * @return array<string, array<int, object>>
     */
    protected function discoverConsumers(): array
    {
        if ($this->_consumerDir === '' || !is_dir($this->_consumerDir)) {
            throw new \RuntimeException("Stomp consumer directory {$this->_consumerDir} does not exist");
        }

        $basePath = rtrim(base_path(), '/\\') . DIRECTORY_SEPARATOR;
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($this->_consumerDir, \FilesystemIterator::SKIP_DOTS)
        );

        $connectionList = [];
        /** @var \SplFileInfo $file */
        foreach ($iterator as $file) {
            if (!$file->isFile() || $file->getExtension() !== 'php') {
                continue;
            }
            $class = $this->classFromFile($file->getPathname(), $basePath);
            if ($class === '' || !class_exists($class)) {
                continue;
            }
            if (!is_a($class, ConsumerInterface::class, true)) {
                continue;
            }

            $consumer = $this->makeConsumer($class);
            $connectionName = $this->stringProperty($consumer, 'connection', 'default');
            $connectionList[$connectionName][] = $consumer;
        }

        return $connectionList;
    }

    /**
     * @param string $connectionName
     * @param array<int, object> $consumers
     * @return void
     */
    protected function bootstrapConnection(string $connectionName, array $consumers): void
    {
        Enforcer::connection($connectionName);

        try {
            Enforcer::createDelayedExchange($connectionName);

            foreach ($consumers as $consumer) {
                $this->subscribeConsumer($connectionName, $consumer);
            }
        } finally {
            // The AMQP connection is only needed for topology declaration.
            Enforcer::destroy($connectionName);
        }
    }

    /**
     * @param string $connectionName
     * @param object $consumer
     * @return void
     */
    protected function subscribeConsumer(string $connectionName, object $consumer): void
    {
        $ack = $this->ackOf($consumer);
        $prefetch = $this->prefetchOf($consumer);
        $client = Client::connection($connectionName);

        foreach ($this->queuesOf($consumer) as $queue) {
            Enforcer::createAndBindQueue($connectionName, $queue);
            $client->subscribe($queue, $this->makeCallback($consumer, $queue, $ack), [
                'ack' => $ack,
                'prefetch-count' => $prefetch,
            ]);
        }
    }

    /**
     * @param object $consumer
     * @param string $queue
     * @param string $ack
     * @return \Closure
     */
    protected function makeCallback(object $consumer, string $queue, string $ack): \Closure
    {
        return function ($client, array $package, $ackResolver) use ($consumer, $queue, $ack): void {
            // Workerman is about to close the connection during reload/shutdown.
            // Leave the message unacknowledged so the broker redelivers it.
            if (in_array(Worker::getStatus(), [Worker::STATUS_SHUTDOWN, Worker::STATUS_RELOADING], true)) {
                return;
            }

            try {
                \call_user_func([$consumer, 'consume'], $package['body'] ?? '', $ackResolver, $client);
            } catch (\Throwable $e) {
                $this->handleFailure($client, $package, $ackResolver, $consumer, $queue, $ack, $e);
            }
        };
    }

    /**
     * @param mixed $client
     * @param array $package
     * @param mixed $ackResolver
     * @param object $consumer
     * @param string $queue
     * @param string $ack
     * @param \Throwable $e
     * @return void
     */
    protected function handleFailure(
        $client,
        array $package,
        $ackResolver,
        object $consumer,
        string $queue,
        string $ack,
        \Throwable $e
    ): void {
        $attempt = (int)($package['headers']['attempt-times'] ?? 0);

        if (!$consumer instanceof RetryAbleConsumer) {
            $this->logError(
                sprintf('Stomp consumer %s failed on queue %s: %s', $consumer::class, $queue, $e->getMessage()),
                $e
            );
            // Keep the previous at-least-once behaviour: fail the worker so the
            // broker redelivers the unacknowledged message.
            throw $e;
        }

        if ($ack !== 'auto' && $ackResolver !== null) {
            try {
                $ackResolver->ack();
            } catch (\Throwable $ackError) {
                $this->logError("Stomp ack failed on queue {$queue}: " . $ackError->getMessage(), $ackError);
            }
        }

        if ($consumer->canRetry($attempt, $e)) {
            $this->requeue($client, $queue, $package, (int)$consumer->getTtr(), $attempt + 1);
            return;
        }

        $this->logError(
            sprintf(
                'Stomp consumer %s gave up on queue %s after %d attempt(s): %s',
                $consumer::class,
                $queue,
                $attempt,
                $e->getMessage()
            ),
            $e
        );
    }

    /**
     * Re-publish the message with the consumer's TTR as delay.
     *
     * @param mixed $client
     * @param string $queue
     * @param array $package
     * @param int $ttr time to reserve, in seconds
     * @param int $attempt
     * @return void
     */
    protected function requeue($client, string $queue, array $package, int $ttr, int $attempt): void
    {
        $headers = ['attempt-times' => $attempt];
        $body = $package['body'] ?? '';

        try {
            $client->send($queue, $body, $ttr, $headers);
        } catch (\RuntimeException $e) {
            // exchange_delay is disabled: retry immediately instead of dropping
            // the message, and surface the configuration problem.
            $this->logError("Stomp delayed retry unavailable on queue {$queue}: " . $e->getMessage(), $e);
            $client->send($queue, $body, 0, $headers);
        }
    }

    /**
     * @param object $consumer
     * @return string
     */
    protected function ackOf(object $consumer): string
    {
        $ack = $consumer->ack ?? 'auto';
        if (!is_string($ack) || !in_array($ack, ['auto', 'client', 'client-individual'], true)) {
            throw new \RuntimeException(
                sprintf('%s::$ack must be one of auto, client, client-individual', $consumer::class)
            );
        }
        return $ack;
    }

    /**
     * @param object $consumer
     * @return int
     */
    protected function prefetchOf(object $consumer): int
    {
        $prefetch = $consumer->prefetch ?? 10;
        if (!is_numeric($prefetch) || (int)$prefetch < 1) {
            throw new \RuntimeException(sprintf('%s::$prefetch must be a positive integer', $consumer::class));
        }
        return (int)$prefetch;
    }

    /**
     * @param object $consumer
     * @return string[]
     */
    protected function queuesOf(object $consumer): array
    {
        $queue = $consumer->queue ?? null;
        if (is_string($queue)) {
            $queue = [$queue];
        }
        if (!is_array($queue) || $queue === []) {
            throw new \RuntimeException(sprintf('%s::$queue must be a non-empty string or array', $consumer::class));
        }

        $queues = [];
        foreach ($queue as $item) {
            if (!is_string($item) || trim($item) === '') {
                throw new \RuntimeException(sprintf('%s::$queue contains an invalid queue name', $consumer::class));
            }
            $queues[] = trim($item);
        }
        return array_values(array_unique($queues));
    }

    /**
     * @param object $consumer
     * @param string $property
     * @param string $default
     * @return string
     */
    protected function stringProperty(object $consumer, string $property, string $default): string
    {
        $value = $consumer->{$property} ?? $default;
        if (!is_string($value) || trim($value) === '') {
            throw new \RuntimeException(
                sprintf('%s::$%s must be a non-empty string', $consumer::class, $property)
            );
        }
        return trim($value);
    }

    /**
     * @param string $file
     * @param string $basePath
     * @return string
     */
    protected function classFromFile(string $file, string $basePath): string
    {
        if (!str_starts_with($file, $basePath)) {
            return '';
        }
        $relative = substr($file, strlen($basePath), -4);
        return str_replace(['/', '\\'], '\\', $relative);
    }

    /**
     * @param string $class
     * @return object
     */
    protected function makeConsumer(string $class): object
    {
        if (class_exists(\support\Container::class)) {
            // Newer webman moved the container out of support\bootstrap.
            return \support\Container::get($class);
        }
        if (class_exists(\support\bootstrap\Container::class)) {
            return \support\bootstrap\Container::get($class);
        }
        return new $class();
    }

    /**
     * @param string $message
     * @param \Throwable|null $exception
     * @return void
     */
    protected function logError(string $message, ?\Throwable $exception = null): void
    {
        Logger::error($message . ($exception instanceof \Throwable ? ' | ' . $exception::class : ''));
    }
}
