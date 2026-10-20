<?php

declare(strict_types=1);

namespace AgentCat\Tests\Support;

use AgentCat\Internal\Transport\CurlMulti;
use AgentCat\Internal\Transport\CurlTransport;
use PHPUnit\Framework\Assert;

/**
 * The transfers that libcurl finished and the transport has not read yet, off the transport's multi handle. No time
 * shows them: a finished transfer that waits in libcurl costs one wait at most (1 s), within a timing test's slack.
 */
final class UnreadTransfers
{
    /** Reads them off the multi handle, so a test that sees one fails at once. Zero before the first delivery made the handle. */
    public static function of(CurlTransport $transport): int
    {
        $multi = (new \ReflectionProperty(CurlTransport::class, 'multi'))->getValue($transport);
        Assert::assertInstanceOf(CurlMulti::class, $multi);
        $handle = (new \ReflectionProperty(CurlMulti::class, 'multi'))->getValue($multi);
        if (!$handle instanceof \CurlMultiHandle) {
            return 0;
        }
        $unread = 0;
        while (curl_multi_info_read($handle) !== false) {
            ++$unread;
        }

        return $unread;
    }
}
