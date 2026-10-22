<?php

declare(strict_types=1);

namespace AgentCat\Tests\Fixtures\Errors;

// PHP 8.3 names this closure `AgentCat\Tests\Fixtures\Errors\{closure}`, PHP 8.4+ `{closure:<this file>:<line>}`.
return static function (string $message): never {
    throw new \RuntimeException($message);
};
