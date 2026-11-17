<?php

declare(strict_types=1);

namespace AgentCat\Internal\ServerInfo;

use AgentCat\Api\Model\PublishServerInfoRequest;
use AgentCat\Internal\Wire\WireEncoder;

/**
 * What one heartbeat says about a server, before it is encoded.
 *
 * @internal
 */
final readonly class ServerInfoBody
{
    /**
     * @param array<string, list<mixed>> $features    the lists by their MCP name (`ServerInfoSource::LISTS`), each item a JSON tree
     * @param array<string, string>      $failedLists the lists left out because they could not be read, each with its reason
     */
    public function __construct(
        public string $projectId,
        public string $serverName,
        public ?string $serverVersion,
        public string $title,
        public string $instructions,
        public array $features,
        public array $failedLists = [],
    ) {}

    /**
     * The same body with other text: the names, the project and the failed lists stay.
     *
     * @param array<string, list<mixed>> $features
     */
    public function withText(string $title, string $instructions, array $features): self
    {
        return new self($this->projectId, $this->serverName, $this->serverVersion, $title, $instructions, $features, $this->failedLists);
    }

    /** The same body without the `inputSchema` and `outputSchema` of each tool; every other member of a tool stays. */
    public function withoutSchemas(): self
    {
        $features = $this->features;
        $features[ServerInfoSource::TOOLS] = array_map(static function (mixed $tool): mixed {
            if (!$tool instanceof \stdClass) {
                return $tool;
            }
            $small = clone $tool;
            unset($small->inputSchema, $small->outputSchema);

            return $small;
        }, $features[ServerInfoSource::TOOLS] ?? []);

        return $this->withText($this->title, $this->instructions, $features);
    }

    public function toolCount(): int
    {
        return count($this->features[ServerInfoSource::TOOLS] ?? []);
    }

    /**
     * The JSON body of `POST /v1/public/server-info/`.
     *
     * @throws \JsonException for a value JSON cannot carry
     */
    public function encode(): string
    {
        return json_encode(new PublishServerInfoRequest(
            projectId: $this->projectId,
            serverName: $this->serverName,
            serverVersion: $this->serverVersion,
            serverTitle: $this->title,
            instructions: $this->instructions,
            features: $this->features,
        ), WireEncoder::FLAGS);
    }
}
