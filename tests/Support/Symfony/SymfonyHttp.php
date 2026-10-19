<?php

declare(strict_types=1);

namespace AgentCat\Tests\Support\Symfony;

use AgentCat\Tests\Support\McpSdk\HttpHarness;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Symfony\Component\HttpKernel\KernelInterface;
use Symfony\Component\HttpKernel\TerminableInterface;

/**
 * `HttpHarness`'s API over a Symfony kernel: each message is one request to the bundle's route of an MCP server (the
 * `conformance` server's by default), handled by `$kernel->handle()` in process, with no sockets. By default there is no
 * `terminate()`: a test that needs the terminate phase builds the request with `callRequest()` and runs both itself.
 * With `$terminate`, each request ends with `terminate()`, as a customer's `KernelBrowser` does. Handshake-era
 * calls carry the `Mcp-Session-Id` that `initialize()` received; `listTools()`, `callTool()` and `callRequest()` send
 * `initialize()` first when there is no session yet. `modern()` sends one self-contained 2026-07-28 request instead.
 */
final class SymfonyHttp
{
    public const string PATH = '/mcp/conformance';

    private const array MODERN_CLIENT = ['name' => 'modern-client', 'version' => '2.0.0'];

    private int $nextId = 1;

    private ?string $sessionId = null;

    private string $protocolVersion = '2025-06-18';

    /**
     * @param string $path      the HTTP path of the server: the `conformance` server's by default
     * @param bool   $terminate whether each request ends with the kernel's `terminate()`, as in Symfony's
     *                          `HttpKernelBrowser::doRequest()`
     */
    public function __construct(
        private readonly KernelInterface $kernel,
        private readonly string $path = self::PATH,
        private readonly bool $terminate = false,
    ) {}

    /** The `Mcp-Session-Id` the server issued at `initialize()`; null before. */
    public function sessionId(): ?string
    {
        return $this->sessionId;
    }

    /** Sends `initialize` and `notifications/initialized`; returns the `initialize` result. */
    public function initialize(string $clientName = 'harness-client', string $clientVersion = '1.0.0', string $protocolVersion = '2025-06-18'): \stdClass
    {
        $result = HttpHarness::result($this->request('initialize', HttpHarness::initializeParams($clientName, $clientVersion, $protocolVersion)));
        $this->protocolVersion = HttpHarness::negotiated($result, $protocolVersion);

        $response = $this->handle($this->build(HttpHarness::INITIALIZED, [], true));
        HttpHarness::assertInitialized($response->getStatusCode(), (string) $response->getContent());

        return $result;
    }

    /**
     * One JSON-RPC request in the session; returns the whole answer (`result` or `error`).
     *
     * @param array<string, mixed>  $params
     * @param array<string, string> $headers
     */
    public function request(string $method, array $params = [], array $headers = []): \stdClass
    {
        $id = $this->nextId;

        return $this->answer($this->handle($this->message($method, $params, $headers, true)), $id);
    }

    /** The `result` of a `tools/list` request. */
    public function listTools(?string $cursor = null): \stdClass
    {
        $this->session();

        return HttpHarness::result($this->request('tools/list', $cursor === null ? [] : ['cursor' => $cursor]));
    }

    /**
     * The whole answer to a `tools/call` request: `result` or `error`.
     *
     * @param array<array-key, mixed>   $arguments
     * @param array<string, mixed>|null $meta
     */
    public function callTool(string $name, array $arguments, ?array $meta = null): \stdClass
    {
        $this->session();
        $id = $this->nextId;

        return $this->answer($this->handle($this->callRequest($name, $arguments, $meta)), $id);
    }

    /**
     * The request of a `tools/call` in the session, not handled: the test hands it to the kernel itself.
     *
     * @param array<array-key, mixed>   $arguments
     * @param array<string, mixed>|null $meta
     * @param array<string, string>     $headers more headers of the request
     */
    public function callRequest(string $name, array $arguments, ?array $meta = null, array $headers = []): Request
    {
        $this->session();

        return $this->message('tools/call', HttpHarness::callParams($name, $arguments, $meta), $headers, true);
    }

    /**
     * One 2026-07-28 request: no session, the protocol version, client capabilities and client info in `params._meta`,
     * and the standard headers. Returns the whole answer.
     *
     * @param array<string, mixed>                      $params
     * @param array{name: string, version: string}|null $clientInfo
     */
    public function modern(string $method, array $params, ?string $name = null, ?array $clientInfo = self::MODERN_CLIENT): \stdClass
    {
        $id = $this->nextId;

        return $this->answer($this->handle($this->message($method, HttpHarness::modernParams($params, $clientInfo), HttpHarness::modernHeaders($method, $name), false)), $id);
    }

    /** Sends `initialize()` when there is no session yet. */
    private function session(): void
    {
        if ($this->sessionId === null) {
            $this->initialize();
        }
    }

    /**
     * The request of the next JSON-RPC message.
     *
     * @param array<string, mixed>  $params
     * @param array<string, string> $headers
     */
    private function message(string $method, array $params, array $headers, bool $withSession): Request
    {
        $message = ['jsonrpc' => '2.0', 'id' => $this->nextId++, 'method' => $method, 'params' => $params === [] ? new \stdClass() : $params];

        return $this->build($message, $headers, $withSession);
    }

    /**
     * @param array<string, mixed>  $message
     * @param array<string, string> $headers
     */
    private function build(array $message, array $headers, bool $withSession): Request
    {
        $all = ['Content-Type' => 'application/json', 'Accept' => 'application/json, text/event-stream'];
        if ($withSession && $this->sessionId !== null) {
            $all += ['Mcp-Session-Id' => $this->sessionId, 'MCP-Protocol-Version' => $this->protocolVersion];
        }
        $server = ['HTTP_HOST' => '127.0.0.1'];
        foreach ([...$all, ...$headers] as $name => $value) {
            $key = strtoupper(str_replace('-', '_', $name));
            $server[$key === 'CONTENT_TYPE' ? $key : 'HTTP_' . $key] = $value;
        }

        return Request::create('http://127.0.0.1' . $this->path, 'POST', server: $server, content: json_encode($message, JSON_THROW_ON_ERROR));
    }

    private function handle(Request $request): Response
    {
        $response = $this->kernel->handle($request);
        if ($this->terminate && $this->kernel instanceof TerminableInterface) {
            $this->kernel->terminate($request, $response);
        }
        $session = $response->headers->get('Mcp-Session-Id');
        if (is_string($session) && $session !== '') {
            $this->sessionId = $session;
        }

        return $response;
    }

    /**
     * The body of an answer. A streamed one (SSE) is sent into an output buffer whose handler keeps every chunk:
     * mcp/sdk flushes each event (`@ob_flush()`), which would hand a plain buffer's bytes to the buffer below it.
     */
    public static function body(Response $response): string
    {
        if (!$response instanceof StreamedResponse) {
            return (string) $response->getContent();
        }
        $chunks = new \ArrayObject();
        ob_start(static function (string $chunk) use ($chunks): string {
            $chunks->append($chunk);

            return '';
        });
        try {
            $response->sendContent();
        } finally {
            ob_end_flush(); // the handler gets the rest and passes on nothing
        }

        return implode('', $chunks->getArrayCopy());
    }

    /** The message of an answer that answers request `$id`: a JSON body, or a `data:` line of an SSE stream. */
    private function answer(Response $response, int $id): \stdClass
    {
        return HttpHarness::decode(self::body($response), (string) $response->headers->get('Content-Type'), $id);
    }
}
