<?php

declare(strict_types=1);

namespace AgentCat\Tests\Unit\RuntimeSupport;

use AgentCat\Tests\Runtime\Support\Images;
use AgentCat\Tests\Support\Thrown;
use PHPUnit\Framework\TestCase;

/** Guards the image selection of the runtime tests: pinned by default, floating tags for the nightly `runtime-latest` job. */
final class ImagesTest extends TestCase
{
    private string|false $before = false;

    #[\Override]
    protected function setUp(): void
    {
        $this->before = getenv(Images::ENVIRONMENT_VARIABLE);
    }

    #[\Override]
    protected function tearDown(): void
    {
        $this->set($this->before === false ? null : $this->before);
    }

    public function testEveryImageIsPinnedByDefault(): void
    {
        $this->set(null);

        $this->assertSame($this->pinned(), $this->images());
    }

    public function testAnEmptyValueMeansPinned(): void
    {
        $this->set('');

        $this->assertSame($this->pinned(), $this->images());
    }

    public function testLatestSelectsTheFloatingTagOfEveryImage(): void
    {
        $this->set('latest');

        $this->assertSame([
            'fpm' => 'php:8.3-fpm-bookworm',
            'apache' => 'php:8.3-apache-bookworm',
            'cli' => 'agentcat-runtime-cli:latest',
            'frankenphp' => 'dunglas/frankenphp:1-php8.3-bookworm',
            'swoole' => 'agentcat-runtime-swoole:latest',
            'unit' => 'unit:php8.3',
        ], $this->images());
        $this->assertSame(['agentcat-runtime-cli:latest', 'agentcat-runtime-swoole:latest'], Images::initImages());
    }

    public function testAnUnknownValueIsRefused(): void
    {
        $this->set('newest');

        $thrown = Thrown::by(\InvalidArgumentException::class, fn(): string => Images::fpm());

        $this->assertSame('AGENTCAT_RUNTIME_IMAGES must be empty or "latest", got "newest".', $thrown->getMessage());
    }

    public function testTheBuildBasesMatchThePinnedDockerfiles(): void
    {
        $root = dirname(__DIR__, 3) . '/tests/Runtime/docker';

        $this->assertStringContainsString("\nARG BASE=php:8.3.35-cli-bookworm\nFROM \${BASE}\n", (string) file_get_contents($root . '/Dockerfile'));
        $this->assertStringContainsString("\nARG BASE=phpswoole/swoole:6.2.3-php8.3\nFROM \${BASE}\n", (string) file_get_contents($root . '/swoole/Dockerfile'));

        $this->set('latest');
        $workflow = (string) file_get_contents(dirname(__DIR__, 3) . '/.github/workflows/nightly.yml');
        $this->assertStringContainsString('--build-arg BASE=' . Images::CLI_BASE_LATEST . ' --tag ' . Images::cli() . ' ', $workflow);
        $this->assertStringContainsString('--build-arg BASE=' . Images::SWOOLE_BASE_LATEST . ' --tag ' . Images::swoole() . ' ', $workflow);
    }

    /** @return array<string, string> */
    private function pinned(): array
    {
        return [
            'fpm' => 'php:8.3.35-fpm-bookworm',
            'apache' => 'php:8.3.35-apache-bookworm',
            'cli' => 'agentcat-runtime-cli:8.3.35',
            'frankenphp' => 'dunglas/frankenphp:1.13.1-php8.3.35-bookworm',
            'swoole' => 'agentcat-runtime-swoole:6.2.3-php8.3',
            'unit' => 'unit:1.34.2-php8.3',
        ];
    }

    /** @return array<string, string> */
    private function images(): array
    {
        return [
            'fpm' => Images::fpm(),
            'apache' => Images::apache(),
            'cli' => Images::cli(),
            'frankenphp' => Images::frankenphp(),
            'swoole' => Images::swoole(),
            'unit' => Images::unit(),
        ];
    }

    private function set(?string $value): void
    {
        putenv($value === null ? Images::ENVIRONMENT_VARIABLE : Images::ENVIRONMENT_VARIABLE . '=' . $value);
    }
}
