<?php

declare(strict_types=1);

namespace AgentCat\Internal\Logging;

use AgentCat\Internal\SdkVersion;
use Psr\Log\AbstractLogger;

/** @internal */
final class FileLogger extends AbstractLogger
{
    private readonly ?string $path;

    /**
     * @param ?\Closure(): ?string $homeLookup resolves the home directory when `$path` is null
     */
    public function __construct(?string $path = null, ?\Closure $homeLookup = null)
    {
        if ($path === null) {
            $home = ($homeLookup ?? static fn(): ?string => self::resolveHome(getenv('HOME'), self::posixHome(...)))();
            $path = $home === null ? null : $home . '/agentcat.log';
        }
        $this->path = $path;
    }

    public function log($level, string|\Stringable $message, array $context = []): void
    {
        if ($this->path === null) {
            return;
        }
        $timestamp = (new \DateTimeImmutable('now', new \DateTimeZone('UTC')))->format('Y-m-d\TH:i:s.v\Z');
        $line = sprintf("[%s] [sdk=%s php=%s] %s\n", $timestamp, SdkVersion::get(), PHP_VERSION, $message);
        // A telemetry log must never warn: suppress the E_WARNING for unwritable paths.
        @file_put_contents($this->path, $line, FILE_APPEND | LOCK_EX);
    }

    /**
     * Home directory from `$HOME`, else from `$posixLookup`; null when neither is available.
     *
     * @param \Closure(): ?string $posixLookup
     */
    public static function resolveHome(string|false $env, \Closure $posixLookup): ?string
    {
        if ($env !== false && $env !== '') {
            return $env;
        }

        return $posixLookup();
    }

    /** Home directory of the effective user, when ext-posix is loaded. */
    public static function posixHome(): ?string
    {
        if (!function_exists('posix_getpwuid') || !function_exists('posix_geteuid')) {
            return null;
        }
        $entry = posix_getpwuid(posix_geteuid());

        return (is_array($entry) && $entry['dir'] !== '') ? $entry['dir'] : null;
    }
}
