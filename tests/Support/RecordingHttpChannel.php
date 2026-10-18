<?php

declare(strict_types=1);

namespace AgentCat\Tests\Support;

use AgentCat\Internal\Transport\HttpChannel;
use AgentCat\Internal\Transport\HttpRequest;
use AgentCat\Internal\Transport\HttpResponse;

/** Records every request; `pump()` answers them in order (scripted, else 200 OK), callbacks' new posts included. */
final class RecordingHttpChannel implements HttpChannel
{
    /** @var list<HttpRequest> */
    public array $requests = [];

    /** @var list<float> the `$seconds` of every waitForActivity() call */
    public array $waits = [];

    /** @var list<array{index: int, onDone: \Closure(HttpResponse): void, background: bool}> */
    private array $held = [];

    private readonly HttpResponse $default;

    /**
     * @param list<HttpResponse>           $responses the answers to requests 0, 1, 2, …
     * @param (\Closure(float): void)|null $onWait    runs in waitForActivity() with its seconds (a test can advance a clock)
     */
    public function __construct(
        private readonly array $responses = [],
        ?HttpResponse $default = null,
        public bool $complete = true,
        private readonly ?\Closure $onWait = null,
    ) {
        $this->default = $default ?? new HttpResponse(200, 'OK', 'ok');
    }

    #[\Override]
    public function post(HttpRequest $request, \Closure $onDone): void
    {
        $this->requests[] = $request;
        $this->held[] = ['index' => count($this->requests) - 1, 'onDone' => $onDone, 'background' => $request->background];
    }

    #[\Override]
    public function pump(): void
    {
        while ($this->complete && $this->held !== []) {
            $next = array_shift($this->held);
            ($next['onDone'])($this->responses[$next['index']] ?? $this->default);
        }
    }

    #[\Override]
    public function pending(bool $includeBackground = true): int
    {
        $pending = 0;
        foreach ($this->held as $held) {
            if ($includeBackground || !$held['background']) {
                ++$pending;
            }
        }

        return $pending;
    }

    #[\Override]
    public function waitForActivity(float $seconds): void
    {
        $this->waits[] = $seconds;
        if ($this->onWait instanceof \Closure) {
            ($this->onWait)($seconds);
        }
    }

    /** @return list<string> the URLs, in post order */
    public function urls(): array
    {
        return array_map(static fn(HttpRequest $request): string => $request->url, $this->requests);
    }
}
