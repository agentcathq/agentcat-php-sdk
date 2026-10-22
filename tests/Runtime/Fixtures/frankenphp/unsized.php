<?php

declare(strict_types=1);

// classic.php without the Content-Length of its answer: the client sees the end of the answer only when FrankenPHP
// ends the response, so only frankenphp_finish_request() (AgentCat's shutdown function calls it) ends the answer
// before the delivery. Closing the output buffers and flushing them (D57) sends the bytes, not the end.
header_register_callback(static function (): void {
    header_remove('Content-Length');
    header('X-E2E-Content-Length: removed');
});

require __DIR__ . '/classic.php';
