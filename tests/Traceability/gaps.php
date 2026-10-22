<?php

declare(strict_types=1);

// The acceptance criteria that no test proves, by ID, with the reason. An ID leaves this list when a test gets its group.
return [
    'ac-02-51' => 'Version 1 does not support async runtimes that interleave calls in one process, such as Swoole coroutines or ReactPHP.',
    'ac-02-55' => 'The cross-SDK health harness lives in the agentcat-server repository. Its PHP lanes are a release step: they will run there, not in this test suite, once agentcat/sdk 1.0.0 is published.',
    'ac-03-15' => 'The criterion names an async runtime. Synchronous PHP cannot preempt a hook: hooks have no 30 s budget, and the event waits until each hook returns.',
];
