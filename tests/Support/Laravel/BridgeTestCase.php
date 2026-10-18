<?php

declare(strict_types=1);

namespace AgentCat\Tests\Support\Laravel;

use AgentCat\Internal\Delivery\EventQueue;
use AgentCat\Internal\Json\Json;
use AgentCat\Internal\Support\TestMode;
use AgentCat\Internal\Transport\Delivery;
use AgentCat\Laravel\AgentCatServiceProvider;
use AgentCat\Tests\Support\Environment;
use AgentCat\Tests\Support\JsonAssert;
use AgentCat\Tests\Support\LaravelMcp\LaravelTestCase;
use AgentCat\Tests\Support\RecordingTransport;

/**
 * A Laravel application with laravel/mcp and AgentCat's service provider. The AGENTCAT_* variables of the process that
 * runs the tests are cleared, so the configuration decides, and the global event queue records its deliveries.
 */
abstract class BridgeTestCase extends LaravelTestCase
{
    /** The variables a test can set; they are cleared before each test and put back after it. */
    private const array VARIABLES = ['AGENTCAT_ENABLED', 'AGENTCAT_PROJECT_ID', 'AGENTCAT_API_URL', 'AGENTCAT_LOG_CHANNEL'];

    protected RecordingTransport $transport;

    private ?Environment $agentcatEnvironment = null;

    #[\Override]
    protected function setUp(): void
    {
        $this->agentcatEnvironment = Environment::clear(...self::VARIABLES);
        TestMode::reset(); // an earlier test can have logged the once-per-process line of AGENTCAT_ENABLED
        parent::setUp();
        EventQueue::setGlobal(new EventQueue(transport: $this->transport = new RecordingTransport()));
    }

    #[\Override]
    protected function tearDown(): void
    {
        parent::tearDown();
        $this->agentcatEnvironment?->restore();
    }

    /** @return list<class-string> */
    #[\Override]
    protected function getPackageProviders(mixed $app): array
    {
        return [...parent::getPackageProviders($app), AgentCatServiceProvider::class];
    }

    /**
     * What `php artisan config:cache` stores for `agentcat`: config/agentcat.php evaluated with these `.env` values,
     * then the edits of a published file. The process keeps none of the values: with a cached configuration, Laravel
     * does not load `.env`. Call it before the providers boot (a `DefineEnvironment` method).
     *
     * @param array<string, string> $dotEnv
     * @param array<string, mixed>  $edits
     */
    protected static function cachedConfiguration(array $dotEnv, array $edits = []): void
    {
        $loaded = Environment::clear(...array_keys($dotEnv));
        foreach ($dotEnv as $name => $value) {
            putenv($name . '=' . $value);
        }
        $cached = require dirname(__DIR__, 3) . '/config/agentcat.php';
        $loaded->restore();
        config(['agentcat' => array_replace(is_array($cached) ? $cached : [], $edits)]);
    }

    /**
     * Every event the API got, decoded, after a flush.
     *
     * @return list<mixed>
     */
    protected function deliveries(): array
    {
        $this->assertTrue(EventQueue::global()->flush(1.0));

        return array_map(static fn(Delivery $delivery): mixed => Json::decode($delivery->body), $this->transport->deliveries);
    }

    /** A field of the one event the API got. */
    protected function deliveredField(string $field): mixed
    {
        $deliveries = $this->deliveries();
        $this->assertCount(1, $deliveries);

        return JsonAssert::at($deliveries[0], $field);
    }

    /** What the application's default log channel wrote (`LaravelTestCase`); '' before its first line. */
    protected function defaultChannelLog(): string
    {
        $path = $this->laravelDirectory . '/laravel.log';

        return is_file($path) ? (string) file_get_contents($path) : '';
    }
}
