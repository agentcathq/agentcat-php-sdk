<?php

declare(strict_types=1);

namespace AgentCat\Internal\Errors;

/**
 * Turns an absolute source path into the relative `filename` the server groups on.
 *
 * Port of TS `makeRelativePath` (steps 1-10). The `node:` steps are dropped, and the `vendor/` rule takes the
 * place of the `node_modules/` rule.
 *
 * @internal
 */
final class PathNormalizer
{
    /** Primary markers (`src`, `lib`, `dist`, `build`) first, then the secondary ones; the first marker found wins. */
    private const array PROJECT_MARKERS = [
        '/src/', '/lib/', '/dist/', '/build/',
        '/app/', '/components/', '/pages/', '/api/', '/utils/', '/services/', '/modules/',
    ];

    /** User home directories (macOS, Linux, Windows), each applied in order. */
    private const array HOME_PREFIXES = [
        '#^/Users/[^/]+/#', '#^/home/[^/]+/#', '#^[A-Za-z]:[\\\\/]Users[\\\\/][^\\\\/]+[\\\\/]#',
    ];

    /** Deployment prefixes, each applied in order. */
    private const array DEPLOYMENT_PREFIXES = [
        '#^/var/www/[^/]+/#', '#^/var/task/#', '#^/usr/src/app/#', '#^/app/#', '#^/opt/[^/]+/#', '#^/srv/[^/]+/#',
    ];

    public static function relative(string $filename, string|false $cwd): string
    {
        $result = $filename;
        if (str_starts_with($result, 'file://')) {
            $result = substr($result, 7);
            if (!str_starts_with($result, '/') && preg_match('/^[A-Za-z]:/', $result) !== 1) {
                $result = '/' . $result;
            }
        }
        if (!str_starts_with($result, '/') && preg_match('/^[A-Za-z]:\\\\/', $result) !== 1) {
            return $result;
        }
        $result = str_replace('\\', '/', $result);
        $vendor = strrpos($result, '/vendor/');
        if ($vendor !== false) {
            return substr($result, $vendor + 1);
        }
        $result = preg_replace(self::HOME_PREFIXES, '~/', $result) ?? $result;
        $result = preg_replace(self::DEPLOYMENT_PREFIXES, '', $result) ?? $result;
        if ($cwd !== false && $cwd !== '' && str_starts_with($result, $cwd)) {
            $result = substr($result, strlen($cwd) + 1);
        }
        if (str_starts_with($result, '/') || preg_match('#^[A-Za-z]:/#', $result) === 1) {
            $result = self::projectPath($result);
        } elseif (str_starts_with($result, '~')) {
            $withoutTilde = '/' . substr($result, 2);
            $projectPath = self::projectPath($withoutTilde);
            if ($projectPath !== $withoutTilde) {
                $result = $projectPath;
            }
        }

        return str_starts_with($result, '/') ? substr($result, 1) : $result;
    }

    private static function projectPath(string $path): string
    {
        foreach (self::PROJECT_MARKERS as $marker) {
            $index = strrpos($path, $marker);
            if ($index !== false) {
                return substr($path, $index + 1);
            }
        }

        return $path;
    }
}
