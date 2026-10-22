<?php

declare(strict_types=1);

namespace AgentCat\Tests\Integration\Laravel;

use AgentCat\Laravel\AgentCatServiceProvider;
use AgentCat\Tests\Support\Environment;
use AgentCat\Tests\Support\LaravelMcp\LaravelTestCase;
use Ergebnis\PHPUnit\SlowTestDetector\Attribute\MaximumDuration;
use Illuminate\Foundation\Application;
use Illuminate\Testing\PendingCommand;

final class InstallCommandTest extends LaravelTestCase
{
    private const string PROJECT = 'proj_abcdefghijklmnopqrstuvwxyz0';

    private string $dir = '';

    private ?Environment $environment = null;

    #[\Override]
    protected function setUp(): void
    {
        // The kernel loads the .env this test writes into the process environment; the next test must not see it.
        $this->environment = Environment::clear('AGENTCAT_PROJECT_ID');
        parent::setUp();
        $this->dir = $this->laravelDirectory;
        file_put_contents($this->dir . '/.env', "APP_NAME=Demo\n");
        file_put_contents($this->dir . '/.env.example', "APP_NAME=\n");
    }

    #[\Override]
    protected function tearDown(): void
    {
        parent::tearDown();
        $this->environment?->restore();
    }

    #[\Override]
    protected function getPackageProviders(mixed $app): array
    {
        return [...parent::getPackageProviders($app), AgentCatServiceProvider::class];
    }

    #[MaximumDuration(500)]
    public function testItWritesTheProjectIdAndNothingElse(): void
    {
        $this->install('agentcat:install', ['project' => self::PROJECT])
            ->expectsOutputToContain('AgentCat is set up: AGENTCAT_PROJECT_ID is in ' . $this->dir . '/.env.')
            ->assertExitCode(0);

        $this->assertSame("APP_NAME=Demo\nAGENTCAT_PROJECT_ID=" . self::PROJECT . "\n", file_get_contents($this->dir . '/.env'));
        $this->assertSame("APP_NAME=\nAGENTCAT_PROJECT_ID=\n", file_get_contents($this->dir . '/.env.example'));
        $this->assertFileDoesNotExist($this->configFile());
    }

    #[MaximumDuration(500)]
    public function testItReplacesAKeyThatIsAlreadyThere(): void
    {
        file_put_contents($this->dir . '/.env', "A=1\r\nAGENTCAT_PROJECT_ID=old\r\nB=2\r\n");
        file_put_contents($this->dir . '/.env.example', "AGENTCAT_PROJECT_ID=\n");

        $this->install('agentcat:install', ['project' => self::PROJECT])->assertExitCode(0);

        $this->assertSame("A=1\r\nAGENTCAT_PROJECT_ID=" . self::PROJECT . "\r\nB=2\r\n", file_get_contents($this->dir . '/.env'));
        $this->assertSame("AGENTCAT_PROJECT_ID=\n", file_get_contents($this->dir . '/.env.example'));
    }

    #[MaximumDuration(500)]
    public function testItCreatesAMissingEnvAndLeavesAMissingExampleAlone(): void
    {
        unlink($this->dir . '/.env');
        unlink($this->dir . '/.env.example');

        $this->install('agentcat:install', ['project' => self::PROJECT])->assertExitCode(0);

        $this->assertSame('AGENTCAT_PROJECT_ID=' . self::PROJECT . "\n", file_get_contents($this->dir . '/.env'));
        $this->assertFileDoesNotExist($this->dir . '/.env.example');
    }

    #[MaximumDuration(500)]
    public function testItAsksWhenNoIdIsGivenAndRejectsABadOne(): void
    {
        $this->install('agentcat:install')
            ->expectsQuestion('Your AgentCat project ID (proj_…)', 'nope')
            ->expectsOutputToContain('The project ID must be proj_ followed by 27 letters or digits.')
            ->assertExitCode(1);

        $this->assertSame("APP_NAME=Demo\n", file_get_contents($this->dir . '/.env'));
    }

    #[MaximumDuration(500)]
    public function testItAcceptsAnAnsweredQuestion(): void
    {
        $this->install('agentcat:install')
            ->expectsQuestion('Your AgentCat project ID (proj_…)', self::PROJECT)
            ->assertExitCode(0);

        $this->assertStringContainsString('AGENTCAT_PROJECT_ID=' . self::PROJECT . "\n", (string) file_get_contents($this->dir . '/.env'));
    }

    #[MaximumDuration(500)]
    public function testAnIdThatNeedsQuotingIsNeverWritten(): void
    {
        foreach (['proj_abcdefghijklmnopqrstuvwxyz0 #x', "proj_abcdefghijklmnopqrstuvwxyz0\n", 'proj_abcdefghijklmnopqrstuvwxy"0', 'proj_abcdefghijklmnopqrstuvwxy$1'] as $bad) {
            $this->install('agentcat:install', ['project' => $bad])->assertExitCode(1);
        }

        $this->assertSame("APP_NAME=Demo\n", file_get_contents($this->dir . '/.env'));
    }

    #[MaximumDuration(500)]
    public function testAReadOnlyEnvIsOneErrorLineAndAFailure(): void
    {
        chmod($this->dir . '/.env', 0o400);

        $this->install('agentcat:install', ['project' => self::PROJECT])
            ->expectsOutputToContain('Cannot write ' . $this->dir . '/.env')
            ->assertExitCode(1);

        $this->assertSame("APP_NAME=Demo\n", file_get_contents($this->dir . '/.env'));
        $this->assertSame("APP_NAME=\n", file_get_contents($this->dir . '/.env.example'));
    }

    #[MaximumDuration(500)]
    public function testTheConfigOptionPublishesTheFile(): void
    {
        $this->install('agentcat:install', ['project' => self::PROJECT, '--config' => true])->assertExitCode(0);

        $this->assertFileExists($this->configFile());
        $this->assertStringStartsWith($this->dir . '/config/', $this->configFile());
    }

    #[MaximumDuration(500)]
    public function testAnUnreadableEnvIsOneErrorLineAndNothingIsWritten(): void
    {
        if (posix_geteuid() === 0) {
            self::markTestSkipped('File modes do not apply to root');
        }
        chmod($this->dir . '/.env', 0o200);

        $this->install('agentcat:install', ['project' => self::PROJECT])
            ->expectsOutputToContain('Cannot read ' . $this->dir . '/.env')
            ->assertExitCode(1);

        chmod($this->dir . '/.env', 0o600);
        $this->assertSame("APP_NAME=Demo\n", file_get_contents($this->dir . '/.env'));
        $this->assertSame("APP_NAME=\n", file_get_contents($this->dir . '/.env.example'));
    }

    #[MaximumDuration(500)]
    public function testTheConfigOptionLeavesAnExistingFileAlone(): void
    {
        file_put_contents($this->configFile(), "<?php // mine\n");

        $this->install('agentcat:install', ['project' => self::PROJECT, '--config' => true])->assertExitCode(0);

        $this->assertSame("<?php // mine\n", file_get_contents($this->configFile()));
    }

    /** @param array<string, mixed> $arguments */
    private function install(string $name, array $arguments = []): PendingCommand
    {
        $command = $this->artisan($name, $arguments);
        $this->assertInstanceOf(PendingCommand::class, $command);

        return $command;
    }

    private function configFile(): string
    {
        $this->assertInstanceOf(Application::class, $this->app);

        return $this->app->configPath('agentcat.php');
    }
}
