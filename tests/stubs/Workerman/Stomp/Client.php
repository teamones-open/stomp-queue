<?php

declare(strict_types=1);

namespace Workerman\Stomp;

/**
 * Minimal stand-in for the real STOMP client.
 *
 * tests/run.php loads this file instead of composer's autoloader so the package
 * can be verified without installing dependencies or running a broker.
 */
class Client
{
    public const STATE_INITIAL = 1;
    public const STATE_CONNECTING = 2;
    public const STATE_WAITCONACK = 3;
    public const STATE_ESTABLISHED = 4;
    public const STATE_DISCONNECTING = 5;
    public const STATE_DISCONNECT = 6;

    public $onConnect = null;
    public $onReconnect = null;
    public $onClose = null;
    public $onError = null;

    /**
     * Packages passed to sendPackage(), in order.
     *
     * @var array<int, array>
     */
    public array $packages = [];

    protected int $_state = self::STATE_ESTABLISHED;

    /**
     * @var array<string, array>
     */
    protected array $_subscriptions = [];

    /**
     * @var array
     */
    protected array $_options = [];

    protected string $_address = '';

    public function __construct($address, $options = [])
    {
        $this->_address = (string)$address;
        $this->_options = is_array($options) ? $options : [];
    }

    public function connect(): void
    {
        // No event loop in unit tests.
    }

    public function getState()
    {
        return $this->_state;
    }

    public function setState(int $state): void
    {
        $this->_state = $state;
    }

    public function getAddress(): string
    {
        return $this->_address;
    }

    /**
     * @return array<string, array>
     */
    public function subscriptions(): array
    {
        return $this->_subscriptions;
    }

    public function lastPackage(): ?array
    {
        if ($this->packages === []) {
            return null;
        }
        return $this->packages[array_key_last($this->packages)];
    }

    protected function checkDisconnecting()
    {
        return false;
    }

    protected function createClientId()
    {
        static $id = 0;
        return 'client-' . (++$id);
    }

    protected function sendPackage($package)
    {
        $this->packages[] = $package;
    }
}
