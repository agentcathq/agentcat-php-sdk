<?php

declare(strict_types=1);

namespace AgentCat\Internal\Events;

use AgentCat\Event;
use AgentCat\Internal\SdkVersion;

/**
 * Server and client identity stamped on every event (TS `buildSessionInfo`).
 *
 * @internal
 */
final readonly class SessionInfo
{
    public const string SDK_LANGUAGE = 'PHP';

    public function __construct(
        public ?string $serverName = null,
        public ?string $serverVersion = null,
        public ?string $clientName = null,
        public ?string $clientVersion = null,
    ) {}

    public function stamp(Event $event): void
    {
        $event->ipAddress = null;
        $event->sdkLanguage = self::SDK_LANGUAGE;
        $event->agentcatVersion = SdkVersion::get();
        $event->serverName = $this->serverName;
        $event->serverVersion = $this->serverVersion;
        $event->clientName = $this->clientName;
        $event->clientVersion = $this->clientVersion;
        $event->identifyActorGivenId = null;
        $event->identifyActorName = null;
        $event->identifyActorData = new \stdClass();
    }
}
