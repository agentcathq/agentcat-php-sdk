<?php

declare(strict_types=1);

namespace AgentCat\Tests\Support;

use AgentCat\Internal\Transport\CurlMulti;
use AgentCat\Internal\Transport\CurlTransport;

/**
 * The transfers that libcurl finished and the transport has not read yet, off the transport's multi handle. No time
 * shows them: a finished transfer that waits in libcurl costs one wait at most (1 s), within a timing test's slack.
 */
final class UnreadTransfers
{
    /**
     * Reads them off the multi handle, so a test that sees one fails at once. Call it after the first delivery started,
     * which makes the handle.
     *
     * @throws \LogicException when a member is not what the transport keeps there: the check would pass for nothing
     */
    public static function of(CurlTransport $transport): int
    {
        $multi = (new \ReflectionProperty(CurlTransport::class, 'multi'))->getValue($transport);
        if (!$multi instanceof CurlMulti) {
            throw new \LogicException('CurlTransport::$multi is ' . get_debug_type($multi) . ', not a CurlMulti');
        }
        $handle = (new \ReflectionProperty(CurlMulti::class, 'multi'))->getValue($multi);
        if (!$handle instanceof \CurlMultiHandle) {
            throw new \LogicException('CurlMulti::$multi is ' . get_debug_type($handle) . ', not a CurlMultiHandle: no delivery started, or the member changed');
        }
        $unread = 0;
        while (curl_multi_info_read($handle) !== false) {
            ++$unread;
        }

        return $unread;
    }
}
