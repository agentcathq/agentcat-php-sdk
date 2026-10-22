<?php

declare(strict_types=1);

namespace AgentCat\Testing\Internal;

use AgentCat\Client;
use Psr\Log\LoggerInterface;
use Psr\Log\LoggerTrait;

/**
 * The log of one `AgentCatFake`: every message AgentCat logs while the fake's client is the shared one.
 * `AgentCat::setClient()` finds it by that client.
 *
 * @internal
 */
final class FakeLog implements LoggerInterface
{
    // A trait, not AbstractLogger: the contract tests list every library class that a class in src/*/Internal extends.
    use LoggerTrait;

    /** @var \WeakMap<Client, self>|null */
    private static ?\WeakMap $byClient = null;

    /** @var list<string> */
    public array $messages = [];

    /** A new, empty log for the fake that owns `$client`. */
    public static function attach(Client $client): self
    {
        return self::byClient()[$client] = new self();
    }

    /** The log of a fake's client; null for any other client. */
    public static function of(Client $client): ?self
    {
        return self::byClient()[$client] ?? null;
    }

    /** @param mixed[] $context */
    #[\Override]
    public function log($level, string|\Stringable $message, array $context = []): void
    {
        $this->messages[] = (string) $message;
    }

    /** @return \WeakMap<Client, self> */
    private static function byClient(): \WeakMap
    {
        return self::$byClient ??= new \WeakMap();
    }
}
