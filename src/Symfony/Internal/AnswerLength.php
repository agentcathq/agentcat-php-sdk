<?php

declare(strict_types=1);

namespace AgentCat\Symfony\Internal;

use AgentCat\Internal\Delivery\ContentLength;
use AgentCat\Internal\Delivery\FinishRequest;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;
use Symfony\Component\HttpKernel\Event\ResponseEvent;
use Symfony\Component\HttpKernel\KernelEvents;

/**
 * D62, the analogue of the Laravel bridge's D59: mcp/sdk, and so symfony/mcp-bundle, sends its JSON answers without
 * `Content-Length`. On a SAPI that sends a response only when the script ends (mod_php, php-cgi, `php -S`:
 * `FinishRequest::responseEndsWithTheScript()`), the client of such an answer then waits for `kernel.terminate`, the
 * hooks and the delivery. So there, last of `kernel.response`, the plain answer of a tracked server's route gets its
 * length, by `ContentLength`'s rule, and with D57 the client has all of it before the shutdown function delivers.
 * PHP-FPM, LiteSpeed, FrankenPHP and Symfony Runtime workers get no listener.
 *
 * symfony/mcp-bundle routes each HTTP server to the controller `mcp.server.<name>.controller::handle`; the tracker
 * knows which servers it tracked. A server that AgentCat did not track (off, excluded, or failed) keeps its answer.
 *
 * @internal
 */
final readonly class AnswerLength
{
    /** The listener's service, which `AgentCatBundle::loadExtension()` defines. */
    public const string SERVICE = 'agentcat.answer_length';

    /** Last of `kernel.response`, after Symfony's own listeners (`Response::prepare()` at 0, the session's at -1000). */
    public const int PRIORITY = -2048;

    /** The controller of a symfony/mcp-bundle server's route: the server's name in it. */
    private const string CONTROLLER = '/^mcp\.server\.(.+)\.controller::handle$/s';

    private ContentLength $length;

    /** @param (\Closure(): bool)|null $headersSent default `headers_sent()` */
    public function __construct(
        private ServerTracker $tracker,
        ?\Closure $headersSent = null,
    ) {
        $this->length = new ContentLength($headersSent);
    }

    /**
     * Listens for `kernel.response` when responses end with the script: at boot, where this process's SAPI is known (a
     * container compiled by `cache:warmup` on the command line serves mod_php too). Nothing runs here, and nothing
     * throws (D32).
     *
     * @param bool|null $responseEndsWithTheScript null: this process's SAPI decides
     */
    public static function register(ContainerInterface $container, ?bool $responseEndsWithTheScript = null): void
    {
        try {
            if (!($responseEndsWithTheScript ?? FinishRequest::responseEndsWithTheScript(PHP_SAPI, $_SERVER, static fn(string $name): bool => function_exists($name)))) {
                return;
            }
            $dispatcher = $container->get('event_dispatcher');
            $listener = $container->get(self::SERVICE);
            if ($dispatcher instanceof EventDispatcherInterface && $listener instanceof self) {
                $dispatcher->addListener(KernelEvents::RESPONSE, $listener, self::PRIORITY);
            }
        } catch (\Throwable) {
            // no listener: the answers go as symfony/mcp-bundle sends them
        }
    }

    /** Gives the plain answer of a tracked server's route its length in bytes. Never throws (D32). */
    public function __invoke(ResponseEvent $event): void
    {
        try {
            $controller = $event->getRequest()->attributes->get('_controller');
            if (!$event->isMainRequest() || !is_string($controller) || preg_match(self::CONTROLLER, $controller, $server) !== 1 || !$this->tracker->tracked($server[1])) {
                return;
            }
            $response = $event->getResponse();
            $length = $this->length->of($event->getRequest()->getMethod(), $response->getStatusCode(), $response->headers->all(), $response->getContent());
            if ($length !== null) {
                $response->headers->set('Content-Length', $length);
            }
        } catch (\Throwable) {
            // the answer goes out as it is
        }
    }
}
