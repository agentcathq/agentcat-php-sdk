<?php

declare(strict_types=1);

namespace AgentCat\Tests\Unit\Internal\Support;

use AgentCat\Internal\Support\Env;
use PHPUnit\Framework\TestCase;

final class EnvTest extends TestCase
{
    private const string NAME = 'AGENTCAT_ENV_TEST';

    private string|false $savedGetenv = false;

    private mixed $savedEnv = null;

    private mixed $savedServer = null;

    #[\Override]
    protected function setUp(): void
    {
        $this->savedGetenv = getenv(self::NAME);
        $this->savedEnv = $_ENV[self::NAME] ?? null;
        $this->savedServer = $_SERVER[self::NAME] ?? null;
        putenv(self::NAME);
        unset($_ENV[self::NAME], $_SERVER[self::NAME]);
    }

    #[\Override]
    protected function tearDown(): void
    {
        putenv($this->savedGetenv === false ? self::NAME : self::NAME . '=' . $this->savedGetenv);
        unset($_ENV[self::NAME], $_SERVER[self::NAME]);
        if ($this->savedEnv !== null) {
            $_ENV[self::NAME] = $this->savedEnv;
        }
        if ($this->savedServer !== null) {
            $_SERVER[self::NAME] = $this->savedServer;
        }
    }

    public function testGetenvWinsOverEnvAndServer(): void
    {
        putenv(self::NAME . '=from-getenv');
        $_ENV[self::NAME] = 'from-env';
        $_SERVER[self::NAME] = 'from-server';

        $this->assertSame('from-getenv', Env::get(self::NAME));
    }

    public function testEnvWinsOverServerWhenGetenvIsUnset(): void
    {
        $_ENV[self::NAME] = 'from-env';
        $_SERVER[self::NAME] = 'from-server';

        $this->assertSame('from-env', Env::get(self::NAME));
    }

    public function testServerAloneIsFound(): void
    {
        $_SERVER[self::NAME] = 'from-server';

        $this->assertSame('from-server', Env::get(self::NAME));
    }

    public function testEmptyStringsAreSkippedLikeJsOr(): void
    {
        putenv(self::NAME . '=');
        $_ENV[self::NAME] = '';
        $_SERVER[self::NAME] = 'from-server';

        $this->assertSame('from-server', Env::get(self::NAME));
    }

    public function testEmptyEverywhereIsNull(): void
    {
        putenv(self::NAME . '=');
        $_ENV[self::NAME] = '';
        $_SERVER[self::NAME] = '';

        $this->assertNull(Env::get(self::NAME));
    }

    public function testUnsetEverywhereIsNull(): void
    {
        $this->assertNull(Env::get(self::NAME));
    }

    public function testNonStringValuesAreIgnored(): void
    {
        $_ENV[self::NAME] = ['not', 'a', 'string'];
        $_SERVER[self::NAME] = 42;

        $this->assertNull(Env::get(self::NAME));
    }
}
