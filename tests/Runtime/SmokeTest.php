<?php

declare(strict_types=1);

namespace AgentCat\Tests\Runtime;

use AgentCat\Tests\Runtime\Support\DockerRuntime;
use AgentCat\Tests\Runtime\Support\Images;
use AgentCat\Tests\Runtime\Support\RuntimeTestCase;
use Ergebnis\PHPUnit\SlowTestDetector\Attribute\MaximumDuration;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;

/** Every pinned image runs PHP 8.3 with curl and mbstring, and loads the SDK from the read-only repository. */
#[Group('runtime')]
final class SmokeTest extends RuntimeTestCase
{
    /** @return iterable<string, array{string}> */
    public static function images(): iterable
    {
        yield 'fpm' => [Images::fpm()];
        yield 'apache' => [Images::apache()];
        yield 'cli' => [Images::cli()];
        yield 'frankenphp' => [Images::frankenphp()];
        yield 'swoole' => [Images::swoole()];
        yield 'unit' => [Images::unit()];
    }

    #[DataProvider('images')]
    #[MaximumDuration(60000)]
    public function testTheImageRunsTheRepositorysPhp(string $image): void
    {
        $run = DockerRuntime::run($image, ['php', self::repo() . '/tests/Runtime/Fixtures/smoke.php'], $this->env(), []);

        $this->assertSame(0, $run['exitCode'], $image . ': ' . $run['stderr']);
        $this->assertMatchesRegularExpression('/\A8\.3\.\d+ curl mbstring\z/', $run['stdout'], $image);
    }

    /** The Swoole guard test needs ext-pcntl next to ext-swoole: `docker/swoole/Dockerfile` adds it to the image. */
    #[MaximumDuration(60000)]
    public function testTheSwooleImageLoadsSwooleAndPcntl(): void
    {
        $run = DockerRuntime::run(Images::swoole(), ['php', '-m'], $this->env(), []);

        $this->assertSame(0, $run['exitCode'], Images::swoole() . ': ' . $run['stderr']);
        $modules = preg_split('/\R/', $run['stdout']);
        $this->assertIsArray($modules);
        $this->assertContains('swoole', $modules);
        $this->assertContains('pcntl', $modules);
    }
}
