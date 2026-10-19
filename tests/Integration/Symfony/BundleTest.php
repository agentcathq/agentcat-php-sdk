<?php

declare(strict_types=1);

namespace AgentCat\Tests\Integration\Symfony;

use AgentCat\AgentCat;
use AgentCat\Internal\Constants;
use AgentCat\Internal\Delivery\EventQueue;
use AgentCat\Internal\Json\Json;
use AgentCat\Internal\Logging\Log;
use AgentCat\Internal\Tools\GetMoreTools;
use AgentCat\Internal\Transport\Delivery;
use AgentCat\Tests\Support\Environment;
use AgentCat\Tests\Support\JsonAssert;
use AgentCat\Tests\Support\Laravel\InvokableIdentify;
use AgentCat\Tests\Support\MemoryLogger;
use AgentCat\Tests\Support\Symfony\CustomerCallHandler;
use AgentCat\Tests\Support\Symfony\FailingHook;
use AgentCat\Tests\Support\Symfony\SymfonyTestCase;
use AgentCat\Tests\Support\Symfony\TestKernel;
use AgentCat\Tests\Support\ToolCalls;
use Ergebnis\PHPUnit\SlowTestDetector\Attribute\MaximumDuration;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;

/**
 * Every symfony/mcp-bundle server of an application is tracked once `AgentCatBundle` is registered (D27, D53, D55,
 * D61). Each test boots its own kernel, which compiles a container: 0.2 to 0.3 s measured on PHP 8.3 and 8.5 (about
 * 0.5 s for the first kernel of a process), and about 0.4 s for the tests that compare with a kernel without AgentCat.
 */
final class BundleTest extends SymfonyTestCase
{
    private const string PROJECT = 'proj_bbbbbbbbbbbbbbbbbbbbbbbbbbb';

    private const string OTHER_PROJECT = 'proj_ppppppppppppppppppppppppppp';

    private const string SESSION = 'ses_bbbbbbbbbbbbbbbbbbbbbbbbbbb';

    private const array ON = ['enabled' => 'true', 'project_id' => self::PROJECT];

    private const string OWN = 'ERROR: Tool "own" already declares a \'session_id\' parameter.';

    private const string MISSING_IDENTIFY = 'Error: identify callback threw - Symfony\Component\DependencyInjection\Exception\ServiceNotFoundException: Service "app.missing_identify" not found';

    /** Two servers: `conformance` and `second` (`second-server`, on `/mcp/second`), with the same tools. */
    private const array TWO_SERVERS = ['servers' => [
        'conformance' => TestKernel::CONFORMANCE,
        'second' => ['name' => 'second-server', 'http' => ['path' => '/mcp/second']] + TestKernel::CONFORMANCE,
    ]];

    #[MaximumDuration(1000)]
    public function testEveryServerIsTrackedWithNoCode(): void
    {
        $kernel = $this->kernel(agentcat: self::ON);

        $tools = $this->http($kernel)->listTools()->tools;

        $this->assertIsArray($tools);
        $this->assertSame(GetMoreTools::NAME, JsonAssert::at(end($tools), 'name'));
    }

    #[MaximumDuration(1000)]
    public function testBootingTracksNothing(): void
    {
        $logger = new MemoryLogger();
        Log::setLogger($logger);

        $kernel = $this->kernel(agentcat: self::ON);

        $this->assertTrue($kernel->getContainer()->has('mcp.server.conformance'));
        $this->assertSame([], $logger->messages());
        $this->assertFalse(EventQueue::global()->hasWork());
    }

    /** The container builds the server once, so its Builder is tracked once, for every request of the container. */
    #[MaximumDuration(1000)]
    public function testTheServerIsTrackedOncePerContainer(): void
    {
        $logger = new MemoryLogger();
        Log::setLogger($logger);
        $http = $this->http($this->kernel(agentcat: self::ON));

        $http->listTools();
        $http->callTool('echo', ['msg' => 'hi', 'session_id' => self::SESSION]);
        $http->listTools();

        $started = array_filter($logger->messages(), static fn(string $line): bool => str_starts_with($line, 'AgentCat setup started'));
        $this->assertSame(['AgentCat setup started | project ' . self::PROJECT . ' | sdk mcp-sdk/builder | signals mcpSdkBuilder'], array_values($started));
    }

    /** Review Focus 1, header conflict 1. */
    #[MaximumDuration(1000)]
    public function testACustomerToolsCallHandlerRunsAfterAgentCats(): void
    {
        $kernel = $this->kernel(agentcat: self::ON, services: [CustomerCallHandler::class => CustomerCallHandler::class]);
        $http = $this->http($kernel);
        $http->listTools();

        $answer = $http->callTool('echo', ['msg' => 'hi', 'context' => 'why', 'session_id' => self::SESSION]);

        $this->assertSame([['msg' => 'hi']], ToolCalls::of(CustomerCallHandler::RECORDED_AS));
        $this->assertSame([], ToolCalls::of('echo'));
        $this->assertSame('customer', $this->text($answer, 0));
        $this->assertCount(1, $this->deliveries());
    }

    /** A customer's handler answers on the first call too, before AgentCat has a session: with the mint-back. */
    #[MaximumDuration(1000)]
    public function testACustomerHandlersAnswerGetsTheMintBack(): void
    {
        $kernel = $this->kernel(agentcat: self::ON, services: [CustomerCallHandler::class => CustomerCallHandler::class]);
        $http = $this->http($kernel);
        $http->listTools();

        $answer = $http->callTool('echo', ['msg' => 'hi', 'session_id' => 'start']);

        $this->assertStringStartsWith(Constants::MINT_BACK_HEADER_ISSUED, $this->text($answer, 0));
        $this->assertSame('customer', $this->text($answer, 1));
    }

    /** @return iterable<string, array{array<string, mixed>, array<string, mixed>|null}> */
    public static function exclusions(): iterable
    {
        yield 'a list' => [['exclude_servers' => ['conformance']], null];
        yield 'a string' => [['exclude_servers' => 'conformance'], null];
        // symfony/config writes the key my-conformance of mcp.servers as my_conformance
        yield 'a name with a hyphen' => [['exclude_servers' => ['my-conformance']], ['servers' => ['my-conformance' => TestKernel::CONFORMANCE]]];
    }

    /**
     * D53 by `mcp.servers` name, at compile time.
     *
     * @param array<string, mixed>      $exclusion
     * @param array<string, mixed>|null $mcp
     */
    #[DataProvider('exclusions')]
    #[MaximumDuration(2000)]
    public function testAnExcludedServerIsLeftAlone(array $exclusion, ?array $mcp): void
    {
        $this->assertUntouched([...self::ON, ...$exclusion], $mcp);
    }

    #[MaximumDuration(1000)]
    public function testAnExclusionLeavesTheOtherServersTracked(): void
    {
        $kernel = $this->kernel(agentcat: [...self::ON, 'exclude_servers' => ['conformance']], mcp: self::TWO_SERVERS);

        $tools = $this->http($kernel, '/mcp/second')->listTools()->tools;

        $this->assertIsArray($tools);
        $this->assertSame(GetMoreTools::NAME, JsonAssert::at(end($tools), 'name'));
    }

    /** D31: under PHPUnit, AgentCat is off unless `enabled` says otherwise. */
    #[MaximumDuration(2000)]
    public function testWithoutASwitchInATestTheServerIsLeftAlone(): void
    {
        $this->assertUntouched(['project_id' => self::PROJECT]);
    }

    /** D44: a false value turns AgentCat off, also where AGENTCAT_ENABLED turns it on. */
    #[MaximumDuration(2000)]
    public function testWithAgentCatOffTheServerIsLeftAlone(): void
    {
        putenv('AGENTCAT_ENABLED=true');

        $this->assertUntouched(['enabled' => false, 'project_id' => self::PROJECT]);
    }

    /** Review Focus 3, ruling P9: `AGENTCAT_ENABLED=maybe` gives one line, and the D31 default (off under PHPUnit). */
    #[MaximumDuration(2000)]
    public function testAnUnknownEnabledWordLogsOneLineAndLeavesTheServerAlone(): void
    {
        putenv('AGENTCAT_ENABLED=maybe');

        $lines = $this->assertUntouched([], logs: true);

        $this->assertSame(['WARN: AgentCat config "agentcat.enabled" is "maybe", not one of true, 1, yes, on, false, 0, no, off; the default is used'], $lines);
    }

    #[MaximumDuration(1000)]
    public function testAServerCanGoToItsOwnProject(): void
    {
        $kernel = $this->kernel(agentcat: [...self::ON, 'projects' => ['conformance' => self::OTHER_PROJECT]]);
        $http = $this->http($kernel);
        $http->listTools();

        $http->callTool('echo', ['msg' => 'hi', 'session_id' => self::SESSION]);

        $this->assertSame([self::OTHER_PROJECT], $this->projects());
    }

    /** D53: two servers of one application send to two projects. */
    #[MaximumDuration(1000)]
    public function testTwoServersSendToTwoProjects(): void
    {
        $kernel = $this->kernel(agentcat: [...self::ON, 'projects' => ['second' => self::OTHER_PROJECT]], mcp: self::TWO_SERVERS);

        $this->http($kernel)->callTool('echo', ['msg' => 'hi', 'session_id' => self::SESSION]);
        $this->http($kernel, '/mcp/second')->callTool('echo', ['msg' => 'hi', 'session_id' => self::SESSION]);

        $events = array_map(static fn(Delivery $delivery): mixed => Json::decode($delivery->body), $this->deliveries());
        $this->assertSame([[self::PROJECT, 'conformance-server'], [self::OTHER_PROJECT, 'second-server']], array_map(static fn(mixed $event): array => [JsonAssert::at($event, 'project_id'), JsonAssert::at($event, 'server_name')], $events));
    }

    /** A projects entry from an unset variable resolves to null at runtime: the server goes to `project_id`. */
    #[MaximumDuration(1000)]
    public function testANullProjectEntryGoesToTheProjectId(): void
    {
        $kernel = $this->kernel(agentcat: [...self::ON, 'projects' => ['conformance' => '%env(default::AGENTCAT_BILLING_PROJECT_ID)%', 'second' => self::OTHER_PROJECT]], mcp: self::TWO_SERVERS);

        $this->http($kernel)->callTool('echo', ['msg' => 'hi', 'session_id' => self::SESSION]);

        $this->assertSame([self::PROJECT], $this->projects());
    }

    #[MaximumDuration(1000)]
    public function testAProjectsKeyWithAHyphenNamesTheServer(): void
    {
        $kernel = $this->kernel(agentcat: [...self::ON, 'projects' => ['my-conformance' => self::OTHER_PROJECT]], mcp: ['servers' => ['my-conformance' => TestKernel::CONFORMANCE]]);

        $this->http($kernel)->callTool('echo', ['msg' => 'hi', 'session_id' => self::SESSION]);

        $this->assertSame([self::OTHER_PROJECT], $this->projects());
    }

    /** Review Focus 3 for the bundle's own keys: each of a wrong type logs one line, once, and its default is used. */
    #[MaximumDuration(1000)]
    public function testBundleKeysOfAWrongTypeLogOneLineEachAndFallBack(): void
    {
        putenv('AGENTCAT_PROJECT_ID=' . self::PROJECT); // the default of a project_id that is no string
        $logger = new MemoryLogger();
        Log::setLogger($logger);
        $kernel = $this->kernel(agentcat: ['enabled' => true, 'project_id' => 12345, 'projects' => ['conformance' => 7, 'second' => self::OTHER_PROJECT], 'logger' => 5, 'diagnostics_endpoint' => 5, 'diagnostics_token' => false], mcp: self::TWO_SERVERS);

        $this->http($kernel)->callTool('echo', ['msg' => 'hi', 'session_id' => self::SESSION]);
        $this->http($kernel, '/mcp/second')->callTool('echo', ['msg' => 'hi', 'session_id' => self::SESSION]);

        $this->assertSame([
            'WARN: AgentCat option "agentcat.project_id" must be string or null, got int; the default is used',
            'WARN: AgentCat option "agentcat.projects.conformance" must be string or null, got int; the entry is ignored',
            'WARN: AgentCat option "agentcat.logger" must be string or null, got int; the default is used',
            'WARN: AgentCat option "agentcat.diagnostics_endpoint" must be string or null, got int; the default is used',
            'WARN: AgentCat option "agentcat.diagnostics_token" must be string or null, got bool; the default is used',
        ], array_values(array_filter($logger->messages(), static fn(string $line): bool => str_starts_with($line, 'WARN:'))));
        $this->assertSame([self::PROJECT, self::OTHER_PROJECT], $this->projects());
    }

    /** M3 of Plan E1: `enable_tracing: '%env(AGENTCAT_ENABLE_TRACING)%'` with `AGENTCAT_ENABLE_TRACING=0` turns tracing off. */
    #[MaximumDuration(1000)]
    public function testAZeroFromTheEnvironmentTurnsTracingOff(): void
    {
        $tracing = Environment::clear('AGENTCAT_ENABLE_TRACING');
        putenv('AGENTCAT_ENABLE_TRACING=0');
        try {
            $kernel = $this->kernel(agentcat: [...self::ON, 'enable_tracing' => '%env(AGENTCAT_ENABLE_TRACING)%']);

            $answer = $this->http($kernel)->callTool('echo', ['msg' => 'hi', 'session_id' => 'start']);
        } finally {
            $tracing->restore();
        }

        $this->assertSame('echo: hi', $this->text($answer, 0));
        $this->assertSame([], $this->deliveries());
    }

    /** D55: a hook is the ID of an invokable service, resolved when the hook runs. */
    #[MaximumDuration(1000)]
    public function testAHookIsAServiceOfTheContainer(): void
    {
        $kernel = $this->kernel(agentcat: [...self::ON, 'identify' => 'app.identify'], services: ['app.identify' => new InvokableIdentify('from-the-container')]);

        $this->http($kernel)->callTool('echo', ['msg' => 'hi', 'session_id' => self::SESSION]);

        $this->assertSame(['from-the-container'], $this->fields('identify_actor_given_id'));
    }

    /** @return iterable<string, array{string}> */
    public static function hooks(): iterable
    {
        foreach (['identify', 'resolve_session_id', 'event_tags', 'event_properties', 'redact_sensitive_information', 'redact_event'] as $hook) {
            yield $hook => [$hook];
        }
    }

    /** D55: each hook key names a service; what the service throws reaches the hook guard's line (D32). */
    #[DataProvider('hooks')]
    #[MaximumDuration(1000)]
    public function testEveryHookIsAServiceOfTheContainer(string $hook): void
    {
        $logger = new MemoryLogger();
        Log::setLogger($logger);
        $kernel = $this->kernel(agentcat: [...self::ON, $hook => 'app.hook'], services: ['app.hook' => new FailingHook($hook)]);

        $this->http($kernel)->callTool('echo', ['msg' => 'hi', 'session_id' => self::SESSION]);
        EventQueue::global()->flush(1.0);

        $this->assertCount(1, array_filter($logger->messages(), static fn(string $line): bool => str_contains($line, 'RuntimeException: ' . $hook . ' ran')));
    }

    /** Header conflict 5: the HTTP context of a call is the RequestStack's main request, not the SAPI globals. */
    #[MaximumDuration(1000)]
    public function testTheEventCarriesTheRequestOfTheKernel(): void
    {
        $http = $this->http($this->kernel(agentcat: self::ON));

        $http->callTool('echo', ['msg' => 'hi', 'session_id' => self::SESSION]);

        $this->assertSame([['http://127.0.0.1/mcp/conformance', $http->sessionId()]], array_map(static fn(mixed $info): array => [JsonAssert::at($info, 'url'), JsonAssert::at($info, 'headers', 'mcp-session-id')], $this->fields('parameters', 'extra', 'requestInfo')));
    }

    /** One tracker serves the container: its client and options are built once, so each bad value logs once. */
    #[MaximumDuration(1000)]
    public function testEachBadValueLogsOnceForEveryServer(): void
    {
        $logger = new MemoryLogger();
        Log::setLogger($logger);
        $kernel = $this->kernel(agentcat: ['enabled' => 'maybe', 'enable_report_missing' => 'maybe'], mcp: self::TWO_SERVERS);

        $this->http($kernel)->listTools();
        $this->http($kernel, '/mcp/second')->listTools();

        $this->assertSame([
            'WARN: AgentCat config "agentcat.enabled" is "maybe", not one of true, 1, yes, on, false, 0, no, off; the default is used',
            'WARN: AgentCat config "agentcat.enable_report_missing" is "maybe", not one of true, 1, yes, on, false, 0, no, off; the default is used',
        ], $logger->messages());
    }

    #[Group('ac-05-E5')]
    #[MaximumDuration(1000)]
    public function testTheToolsOwnExceptionIsRecordedThroughTheBundlesReferenceHandler(): void
    {
        $kernel = $this->kernel(agentcat: self::ON);
        $http = $this->http($kernel);
        $http->listTools();

        $http->callTool('boom', ['session_id' => self::SESSION]);

        $this->assertSame(['RuntimeException'], $this->fields('error', 'type'));
    }

    /** Header conflict 6: a fake records the calls of every server, whatever the configuration says. */
    #[MaximumDuration(1000)]
    public function testAFakeRecordsTheCallsWhateverTheConfigurationSays(): void
    {
        $fake = AgentCat::fake();
        $kernel = $this->kernel(agentcat: ['enabled' => false]);

        $this->http($kernel)->callTool('echo', ['msg' => 'hi']);

        $fake->assertTrackedCount(1);
        $this->assertSame([], $this->transport->deliveries);
        $this->assertDirectoryDoesNotExist($kernel->getLogDir()); // the fake's log keeps priority
    }

    /** D61: AgentCat's lines go to `%kernel.logs_dir%/agentcat.log`, not to the Builder's `mcp` logger. */
    #[MaximumDuration(1000)]
    public function testByDefaultTheLinesGoToTheirOwnFileInTheLogDirectory(): void
    {
        $kernel = $this->kernel(agentcat: self::ON);

        $this->http($kernel)->listTools();

        $this->assertStringContainsString('AgentCat setup started | project ' . self::PROJECT . ' | sdk mcp-sdk/builder', $this->agentCatLog($kernel));
        $this->assertStringContainsString(self::OWN, $this->agentCatLog($kernel));
        $this->assertSame(0o777 & ~umask(), fileperms($kernel->getLogDir()) & 0o777); // as Symfony creates its directories
        $mcp = $kernel->getContainer()->get('logger');
        $this->assertInstanceOf(MemoryLogger::class, $mcp);
        $this->assertNotSame([], $mcp->messages()); // mcp/sdk's own lines
        $this->assertSame([], array_intersect($this->agentCatLines($kernel), $mcp->messages()));
    }

    /** D61: `logger` names the PSR-3 logger service of AgentCat's lines, for example `monolog.logger.mcp`. */
    #[MaximumDuration(1000)]
    public function testALoggerServiceGetsTheLines(): void
    {
        $logger = new MemoryLogger();
        $kernel = $this->kernel(agentcat: [...self::ON, 'logger' => 'app.agentcat_logger'], services: ['app.agentcat_logger' => $logger]);

        $this->http($kernel)->listTools();

        $this->assertContains('AgentCat setup started | project ' . self::PROJECT . ' | sdk mcp-sdk/builder | signals mcpSdkBuilder', $logger->messages());
        $this->assertDirectoryDoesNotExist($kernel->getLogDir());
    }

    /** @return iterable<string, array{string}> */
    public static function badLoggers(): iterable
    {
        yield 'a missing service' => ['app.missing_logger'];
        yield 'a service that is no logger' => ['request_stack'];
    }

    /** D61, ruling P8: a `logger` that names no logger logs one line to the file and never breaks a request. */
    #[DataProvider('badLoggers')]
    #[MaximumDuration(1000)]
    public function testALoggerThatIsNoLoggerLogsOneLineToTheFile(string $id): void
    {
        $kernel = $this->kernel(agentcat: [...self::ON, 'logger' => $id]);
        $http = $this->http($kernel);

        $answer = $http->callTool('echo', ['msg' => 'hi', 'session_id' => self::SESSION]);

        $this->assertSame('echo: hi', $this->text($answer, 0));
        $lines = file($kernel->getLogDir() . '/agentcat.log', FILE_IGNORE_NEW_LINES);
        $this->assertIsArray($lines);
        $this->assertStringEndsWith(sprintf(' WARN: AgentCat option "agentcat.logger" must be the ID of a PSR-3 logger service, got "%s"; the default is used', $id), $lines[0] ?? '');
        $this->assertCount(1, array_filter($lines, static fn(string $line): bool => str_contains($line, 'agentcat.logger')));
        $this->assertStringContainsString('AgentCat setup started | project ' . self::PROJECT, $lines[1] ?? '');
    }

    /**
     * D32: a configuration that cannot be read at runtime (a variable without a default that is not set) leaves the
     * server untracked with one line, and the request answers.
     */
    #[MaximumDuration(1000)]
    public function testAConfigurationThatCannotBeReadLeavesTheServerUntracked(): void
    {
        $kernel = $this->kernel(agentcat: [...self::ON, 'project_id' => '%env(AGENTCAT_UNSET_PROJECT_ID)%']);
        $http = $this->http($kernel);

        $tools = $http->listTools()->tools;

        $this->assertIsArray($tools);
        $this->assertSame('structured', JsonAssert::at(end($tools), 'name'));
        $this->assertSame(['Warning: Failed to track server - Symfony\Component\DependencyInjection\Exception\EnvNotFoundException: Environment variable not found: "AGENTCAT_UNSET_PROJECT_ID".'], $this->agentCatLines($kernel));
    }

    /** Review Focus 3, ruling P9: mistyped and unset values; the container compiles and the request answers. */
    #[MaximumDuration(1000)]
    public function testBadRuntimeValuesNeverBreakARequest(): void
    {
        putenv('AGENTCAT_ENABLED=true');
        $logger = new MemoryLogger();
        Log::setLogger($logger);
        $kernel = $this->kernel(agentcat: ['identify' => 'app.missing_identify', 'exporters' => ['otlp' => null], 'enable_report_missing' => 'maybe']);
        $http = $this->http($kernel);
        $http->listTools();

        $answer = $http->callTool('echo', ['msg' => 'hi', 'session_id' => 'start']);
        $this->assertTrue(EventQueue::global()->flush(1.0));

        $this->assertStringStartsWith(Constants::MINT_BACK_HEADER_ISSUED, $this->text($answer, 0));
        $this->assertSame('echo: hi', $this->text($answer, 1));
        $this->assertDirectoryDoesNotExist($kernel->getLogDir()); // Log::setLogger() chose the logger
        $this->assertSame([
            'WARN: AgentCat config "agentcat.enable_report_missing" is "maybe", not one of true, 1, yes, on, false, 0, no, off; the default is used',
            'AgentCat setup started | project (telemetry-only) | sdk mcp-sdk/builder | signals mcpSdkBuilder',
            'Warning: No projectId provided and no exporters configured. Events will not be sent anywhere.', // only null exporter entries
            'AgentCat setup complete | project (telemetry-only) | tracing=true context=true reportMissing=true exporters=0',
            self::OWN, // the fixture's own tool
            self::MISSING_IDENTIFY, // the hook guard, when the hook runs (D32); the rest of the message is Symfony's
        ], array_map(static fn(string $line): string => self::prefixed($line, self::OWN, self::MISSING_IDENTIFY), $logger->messages()));
    }

    /**
     * An untracked server answers exactly like the server of an application without AgentCat, and nothing is queued.
     *
     * @param array<string, mixed>      $agentcat
     * @param array<string, mixed>|null $mcp
     *
     * @return list<string> the lines AgentCat logged
     */
    private function assertUntouched(array $agentcat, ?array $mcp = null, bool $logs = false): array
    {
        $plain = $this->answers($this->kernel(withAgentCat: false, mcp: $mcp));
        $logger = new MemoryLogger();
        Log::setLogger($logger);

        $tracked = $this->answers($this->kernel(agentcat: $agentcat, mcp: $mcp));

        $this->assertEquals($plain, $tracked);
        $this->assertFalse(EventQueue::global()->hasWork());
        if (!$logs) {
            $this->assertSame([], $logger->messages());
        }

        return $logger->messages();
    }

    /** @return array{\stdClass, \stdClass} the listing and the answer to one `tools/call` */
    private function answers(TestKernel $kernel): array
    {
        $http = $this->http($kernel);

        return [$http->listTools(), $http->callTool('echo', ['msg' => 'hi', 'session_id' => 'start'])];
    }

    /** The prefix that the line starts with, else the line. */
    private static function prefixed(string $line, string ...$prefixes): string
    {
        foreach ($prefixes as $prefix) {
            if (str_starts_with($line, $prefix)) {
                return $prefix;
            }
        }

        return $line;
    }

    /** @return list<mixed> the `project_id` of each event the API got */
    private function projects(): array
    {
        return $this->fields('project_id');
    }

    /** @return list<mixed> the value at `$path` of each event the API got, after a flush */
    private function fields(string ...$path): array
    {
        return array_map(static fn(Delivery $delivery): mixed => JsonAssert::at(Json::decode($delivery->body), ...$path), $this->deliveries());
    }

    /** The text of content block `$block` of a `tools/call` answer. */
    private function text(\stdClass $answer, int $block): string
    {
        $text = JsonAssert::at($answer, 'result', 'content', $block, 'text');
        $this->assertIsString($text);

        return $text;
    }

    private function agentCatLog(TestKernel $kernel): string
    {
        $path = $kernel->getLogDir() . '/agentcat.log';

        return is_file($path) ? (string) file_get_contents($path) : '';
    }

    /** @return list<string> the messages of agentcat.log, without the prefix of each line */
    private function agentCatLines(TestKernel $kernel): array
    {
        $lines = array_filter(explode("\n", $this->agentCatLog($kernel)), static fn(string $line): bool => $line !== '');

        return array_values(array_map(static fn(string $line): string => (string) preg_replace('/^\[[^\]]*\] \[[^\]]*\] /', '', $line), $lines));
    }
}
