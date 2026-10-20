<?php

declare(strict_types=1);

// The acceptance criteria that no test proves, by ID, with the reason. An ID leaves this list when a test gets its group.
return [
    'ac-02-55' => 'The cross-SDK health harness lives in the agentcat-server repository: its PHP lanes run there, not in this test suite.',
    'ac-03-15' => 'Synchronous PHP cannot preempt a hook: hooks have no 30 s budget, and the event waits until each hook returns.',
];
