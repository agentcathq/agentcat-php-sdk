<?php

declare(strict_types=1);

namespace AgentCat\Tests\Fixtures\Errors;

/** A named namespaced function; ExceptionCaptureTest expects its namespace as the frame module. */
function failInFunction(string $message): never
{
    throw new \RuntimeException($message);
}

/**
 * Loads the file-scope closure outside any class: an included file runs in the scope of the code that includes it.
 *
 * @return \Closure(string): never
 */
function fileScopeClosure(): \Closure
{
    $closure = require __DIR__ . '/file-scope-closure.php';
    if (!$closure instanceof \Closure) {
        throw new \LogicException('file-scope-closure.php must return a closure');
    }

    return $closure;
}
