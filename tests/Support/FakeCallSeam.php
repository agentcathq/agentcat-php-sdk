<?php

declare(strict_types=1);

namespace AgentCat\Tests\Support;

use AgentCat\Internal\Engine\CallContext;
use AgentCat\Internal\Engine\CallSeam;
use AgentCat\Internal\Engine\Observed;

/**
 * A scripted `CallSeam` whose results are plain tool-result trees. Every knob is public. `calls` records what the
 * interceptor asked the library to do, in order: `['original']`, `['stripped', $names]`, `['answer']`, `['replace']`.
 *
 * @implements CallSeam<\stdClass>
 */
final class FakeCallSeam implements CallSeam
{
    /** @var list<array{0: string, 1?: list<string>}> */
    public array $calls = [];

    public int $listCalls = 0;

    /** What the library answers, or throws. Default `{"content":[{"type":"text","text":"ok"}]}`. */
    public \stdClass|\Throwable $result;

    /** What `observe()` reports; null observes the result itself as the tree. */
    public ?Observed $observed = null;

    /** @var list<mixed>|\Throwable|null the library's first `tools/list` page, or what listing it throws */
    public array|\Throwable|null $listing = null;

    /** The first page's next cursor. */
    public ?string $nextCursor = null;

    /**
     * @var array<string, array{list<mixed>|\Throwable|null, ?string}> the later pages by the cursor that asks for them:
     *                                                                  tools (as `$listing`) and next cursor
     */
    public array $pages = [];

    /** @var list<?string> the cursor of each listing, in order */
    public array $cursors = [];

    public ?\Throwable $replaceFailure = null;

    /**
     * What `later()` does with a result: null answers null (an ordinary result); a closure gets the result and the
     * interceptor's two callbacks and returns the result in its place.
     *
     * @var (\Closure(\stdClass, \Closure(\stdClass): \stdClass, \Closure(\Throwable): void): \stdClass)|null
     */
    public ?\Closure $later = null;

    public function __construct(
        public string $name,
        public mixed $arguments,
        public CallContext|\Throwable $context,
    ) {
        $this->result = self::ok();
    }

    /** `{"content":[{"type":"text","text":"ok"}]}` */
    public static function ok(): \stdClass
    {
        return (object) ['content' => [(object) ['type' => 'text', 'text' => 'ok']]];
    }

    #[\Override]
    public function toolName(): string
    {
        return $this->name;
    }

    #[\Override]
    public function arguments(): mixed
    {
        return $this->arguments;
    }

    #[\Override]
    public function describe(): CallContext
    {
        if ($this->context instanceof \Throwable) {
            throw $this->context;
        }

        return $this->context;
    }

    #[\Override]
    public function callOriginal(): \stdClass
    {
        $this->calls[] = ['original'];

        return $this->respond();
    }

    #[\Override]
    public function callStripped(array $names): \stdClass
    {
        $this->calls[] = ['stripped', $names];

        return $this->respond();
    }

    #[\Override]
    public function observe(mixed $result): Observed
    {
        return $this->observed ?? new Observed($result);
    }

    #[\Override]
    public function replace(mixed $result, \stdClass $tree): \stdClass
    {
        $this->calls[] = ['replace'];
        if ($this->replaceFailure instanceof \Throwable) {
            throw $this->replaceFailure;
        }

        return $tree;
    }

    #[\Override]
    public function answer(\stdClass $tree): \stdClass
    {
        $this->calls[] = ['answer'];

        return $tree;
    }

    #[\Override]
    public function listOriginal(?string $cursor): ?array
    {
        ++$this->listCalls;
        $this->cursors[] = $cursor;
        [$tools, $next] = $cursor === null ? [$this->listing, $this->nextCursor] : ($this->pages[$cursor] ?? [null, null]);
        if ($tools instanceof \Throwable) {
            throw $tools;
        }

        return $tools === null ? null : [$tools, $next];
    }

    #[\Override]
    public function later(mixed $result, \Closure $complete, \Closure $fail): ?\stdClass
    {
        return $this->later instanceof \Closure ? ($this->later)($result, $complete, $fail) : null;
    }

    private function respond(): \stdClass
    {
        if ($this->result instanceof \Throwable) {
            throw $this->result;
        }

        return $this->result;
    }
}
