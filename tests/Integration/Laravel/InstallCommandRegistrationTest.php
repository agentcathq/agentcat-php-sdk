<?php

declare(strict_types=1);

namespace AgentCat\Tests\Integration\Laravel;

use AgentCat\Laravel\AgentCatServiceProvider;
use AgentCat\Tests\Support\Environment;
use AgentCat\Tests\Support\LaravelMcp\LaravelTestCase;
use Ergebnis\PHPUnit\SlowTestDetector\Attribute\MaximumDuration;
use Illuminate\Console\Application as Artisan;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Foundation\Application;

/** The command exists in the console and not in a web request, where nothing needs it. */
final class InstallCommandRegistrationTest extends LaravelTestCase
{
    private ?Environment $consoleEnvironment = null;

    #[\Override]
    protected function setUp(): void
    {
        Artisan::forgetBootstrappers(); // Artisan::starting() callbacks are static: an earlier application's would leak in
        parent::setUp();
    }

    #[\Override]
    protected function tearDown(): void
    {
        parent::tearDown();
        Artisan::forgetBootstrappers();
        $this->consoleEnvironment?->restore();
    }

    #[\Override]
    protected function getPackageProviders(mixed $app): array
    {
        return [...parent::getPackageProviders($app), AgentCatServiceProvider::class];
    }

    #[MaximumDuration(500)]
    public function testTheConsoleKnowsTheCommand(): void
    {
        $this->assertContains('agentcat:install', $this->commands());
    }

    public function testAnHttpRequestDoesNotRegisterIt(): void
    {
        $this->consoleEnvironment = Environment::clear('APP_RUNNING_IN_CONSOLE');
        putenv('APP_RUNNING_IN_CONSOLE=false');
        $_ENV['APP_RUNNING_IN_CONSOLE'] = $_SERVER['APP_RUNNING_IN_CONSOLE'] = 'false';
        Artisan::forgetBootstrappers(); // the console application that setUp() booted registered it
        $this->refreshApplication();

        $this->assertInstanceOf(Application::class, $this->app);
        $this->assertFalse($this->app->runningInConsole());
        $this->assertNotContains('agentcat:install', $this->commands());
    }

    /** @return list<string> */
    private function commands(): array
    {
        $this->assertInstanceOf(Application::class, $this->app);

        return array_map(strval(...), array_keys($this->app->make(Kernel::class)->all()));
    }
}
