<?php

declare(strict_types=1);

namespace AgentCat\Laravel\Internal;

use AgentCat\Internal\Delivery\ContentLength;
use AgentCat\Internal\Delivery\FinishRequest;
use AgentCat\LaravelMcp\LaravelMcpAdapter;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Foundation\Http\Events\RequestHandled;
use Laravel\Mcp\Server;

/**
 * D59: laravel/mcp sends its answers without `Content-Length`. On a SAPI that sends a response only when the script
 * ends (mod_php, php-cgi, `php -S`: `FinishRequest::responseEndsWithTheScript()`), the client of such an answer then
 * waits for AgentCat's hooks and delivery. So there, in Laravel's `RequestHandled`, the plain answer of a tracked
 * server gets its length, by `ContentLength`'s rule, and with D57 the client has all of it before the shutdown function
 * delivers. PHP-FPM, LiteSpeed, FrankenPHP and Octane get no listener.
 *
 * The tracker marks the request that a tracked server answers with a request attribute: the mark lives and dies with
 * the request, so a worker that serves many requests carries none of it to the next one.
 *
 * @internal
 */
final readonly class AnswerLength
{
    /** The request attribute: a laravel/mcp server that AgentCat tracks answers this request. */
    public const string TRACKED = 'agentcat.tracked_mcp_server';

    private ContentLength $length;

    /** @param (\Closure(): bool)|null $headersSent default `headers_sent()` */
    public function __construct(?\Closure $headersSent = null)
    {
        $this->length = new ContentLength($headersSent);
    }

    /**
     * Listens for `RequestHandled` when responses end with the script. Nothing runs here.
     *
     * @param bool|null $responseEndsWithTheScript null: this process's SAPI decides
     */
    public static function register(Application $app, ?bool $responseEndsWithTheScript = null): void
    {
        if ($responseEndsWithTheScript ?? FinishRequest::responseEndsWithTheScript(PHP_SAPI, $_SERVER, static fn(string $name): bool => function_exists($name))) {
            $app->make('events')->listen(RequestHandled::class, (new self())->handle(...));
        }
    }

    /** Marks the request that a tracked server answers, read from its HTTP transport; a stdio server answers none. */
    public static function mark(Server $server): void
    {
        LaravelMcpAdapter::request($server)?->attributes->set(self::TRACKED, true);
    }

    /** Gives the plain answer to a marked request its length in bytes. Never throws: it runs in Laravel's HTTP kernel (D32). */
    public function handle(RequestHandled $handled): void
    {
        try {
            if ($handled->request->attributes->get(self::TRACKED) !== true) {
                return;
            }
            $response = $handled->response;
            $length = $this->length->of($handled->request->getMethod(), $response->getStatusCode(), $response->headers->all(), $response->getContent());
            if ($length !== null) {
                $response->headers->set('Content-Length', $length);
            }
        } catch (\Throwable) {
            // the answer goes out as it is
        }
    }
}
