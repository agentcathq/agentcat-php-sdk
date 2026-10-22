<?php

declare(strict_types=1);

namespace AgentCat\Tests\Integration\Fixtures;

use AgentCat\Internal\Delivery\RequestEnd;

require dirname(__DIR__, 3) . '/vendor/autoload.php';

// Run with -d output_buffering=4096, so PHP's own buffer is the bottom one. The default close must send and close it
// too. A write to the STDOUT stream bypasses the buffers: it lands after "held" only when the close sent "held" first.
// Writes the buffer names before the close and the level after it to stderr.
ob_start();
echo 'held';
$before = array_column(ob_get_status(true), 'name');

RequestEnd::closeOutputBuffers();
$after = ob_get_level();

fwrite(STDOUT, ' after the close');
fwrite(STDERR, json_encode(['before' => $before, 'after' => $after], JSON_THROW_ON_ERROR));
