<?php

declare(strict_types=1);

namespace AgentCat\McpSdk;

use AgentCat\Internal\Engine\Publisher;
use AgentCat\Internal\Engine\TrackedServer;
use AgentCat\Internal\Logging\Log;
use AgentCat\Internal\SdkVersion;
use AgentCat\Internal\Support\ErrorText;
use AgentCat\Internal\Support\StrictErrors;
use AgentCat\McpSdk\Internal\Access\BuilderAccess;
use AgentCat\McpSdk\Internal\Delegates;
use AgentCat\McpSdk\Internal\ListToolsInterceptor;
use Mcp\Schema\Implementation;
use Mcp\Server;
use Mcp\Server\Builder;
use Psr\Log\LoggerInterface;

/**
 * Detects and instruments mcp/sdk servers. AgentCat changes the Builder through its public setters only.
 *
 * @internal
 */
final class McpSdkAdapter
{
    public const string SUPPORT_MATRIX = 'AgentCat supports mcp/sdk 0.8.1+ (pass the Mcp\Server\Builder to AgentCat::track() before build()).';

    /** The line logged when mcp/sdk internals are not what AgentCat expects. */
    public const string COMPATIBILITY_LINE = 'AgentCat SDK Compatibility: ' . self::SUPPORT_MATRIX;

    /** @phpstan-assert-if-true Builder $server */
    public static function supports(object $server): bool
    {
        return $server instanceof Builder;
    }

    /** @return list<string> */
    public static function signals(object $server): array
    {
        $signals = [];
        if ($server instanceof Builder) {
            $signals[] = 'mcpSdkBuilder';
        }
        if ($server instanceof Server) {
            $signals[] = 'mcpSdkServer';
        }
        try {
            if ($server instanceof Builder && BuilderAccess::isBuilt($server)) {
                $signals[] = 'built';
            }
        } catch (\Throwable) {
            // drift is reported by inspect()
        }

        return $signals;
    }

    public static function version(): string
    {
        return SdkVersion::installed('mcp/sdk') ?? 'unknown';
    }

    /**
     * Checks that AgentCat can instrument this Builder: its private members have the expected shape, and it is not
     * built yet.
     *
     * @throws \UnexpectedValueException with the compatibility message, after logging the compatibility line
     */
    public static function inspect(Builder $builder): void
    {
        try {
            BuilderAccess::probe($builder);
        } catch (\Throwable $e) {
            throw self::incompatible(sprintf('mcp/sdk %s internals changed (%s).', self::version(), ErrorText::of($e)), $e);
        }
        if (BuilderAccess::isBuilt($builder)) {
            throw self::incompatible('the mcp/sdk Builder was already built.');
        }
    }

    public static function logger(Builder $builder): ?LoggerInterface
    {
        try {
            return BuilderAccess::logger($builder);
        } catch (\Throwable) {
            return null; // inspect() reports the drift
        }
    }

    /**
     * Reads the server name and version when an event is published: build() sets them. When they cannot be read
     * (C6), logs one compatibility line and reports them unknown from then on.
     *
     * @return \Closure(): array{0: ?string, 1: ?string}
     */
    public static function serverInfo(Builder $builder): \Closure
    {
        $drifted = false;

        return static function () use ($builder, &$drifted): array {
            if ($drifted) {
                return [null, null];
            }
            try {
                return StrictErrors::run(static function () use ($builder): array {
                    $info = BuilderAccess::serverInfo($builder);

                    return $info instanceof Implementation ? [$info->name, $info->version] : [null, null];
                });
            } catch (\Throwable) {
                $drifted = true;
                Log::write(self::COMPATIBILITY_LINE);

                return [null, null];
            }
        };
    }

    /** Registers AgentCat's request handlers. They run before the library's own (02 §1.4). */
    public static function install(Builder $builder, TrackedServer $server, Publisher $publisher): void
    {
        $builder->addRequestHandler(new ListToolsInterceptor($server, new Delegates($builder), $publisher));
    }

    private static function incompatible(string $reason, ?\Throwable $previous = null): \UnexpectedValueException
    {
        Log::write(self::COMPATIBILITY_LINE);

        return new \UnexpectedValueException('AgentCat SDK compatibility error: ' . $reason . ' ' . self::SUPPORT_MATRIX, previous: $previous);
    }
}
