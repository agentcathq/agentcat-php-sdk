<?php

declare(strict_types=1);

namespace AgentCat\Tests\Unit\Internal\Errors;

use AgentCat\Internal\Errors\PathNormalizer;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

final class PathNormalizerTest extends TestCase
{
    /** @return iterable<string, array{string, string}> */
    public static function tsGoldenProvider(): iterable
    {
        $rows = [
            '/Users/john/project/src/index.ts' => 'src/index.ts',
            '/home/ubuntu/app/src/server.ts' => 'src/server.ts',
            'C:\Users\Jane\projects\myapp\src\index.ts' => 'src/index.ts',
            '/var/www/myapp/src/api/users.ts' => 'src/api/users.ts',
            '/app/dist/server.js' => 'dist/server.js',
            '/var/task/src/handler.ts' => 'src/handler.ts',
            '/usr/src/app/src/index.ts' => 'src/index.ts',
            '/opt/myservice/lib/main.ts' => 'lib/main.ts',
            '/srv/webapp/src/routes.ts' => 'src/routes.ts',
            '/some/long/path/to/project/src/components/Button.tsx' => 'src/components/Button.tsx',
            '/random/path/myproject/lib/utils/helper.ts' => 'lib/utils/helper.ts',
            '/deployment/path/dist/server.js' => 'dist/server.js',
            '/Users/john/src/project/src/index.ts' => 'src/index.ts',
            'C:\projects\myapp\src\utils\helper.ts' => 'src/utils/helper.ts',
            'C:\projects/myapp\src/index.ts' => 'src/index.ts',
            '/Users/dev/project/src/api/handler.ts' => 'src/api/handler.ts',
            '/app/dist/api/users.ts' => 'dist/api/users.ts',
            'file:///Users/john/project/src/index.ts' => 'src/index.ts',
            'file:///C:/Users/Jane/project/src/index.ts' => 'src/index.ts',
            'src/index.ts' => 'src/index.ts',
            '/random/path/without/markers/file.ts' => 'random/path/without/markers/file.ts',
            '/index.ts' => 'index.ts',
            '/Users/john/long/path/to/project/src/index.ts' => 'src/index.ts',
            'C:\Users\Bob\proj\x.js' => '~/proj/x.js',
            'file:///C:/Users/Bob/proj/x.js' => 'C:/Users/Bob/proj/x.js',
            '/home/bob/proj/x.js' => '~/proj/x.js',
            '/home/bob/my proj/src/x.js' => 'src/x.js',
            'https://deno.land/x/mod.ts' => 'https://deno.land/x/mod.ts',
        ];
        foreach ($rows as $input => $expected) {
            yield $input => [$input, $expected];
        }
    }

    /** @return iterable<string, array{string, string}> */
    public static function phpPathProvider(): iterable
    {
        $rows = [
            '/var/www/html/app/Http/Controllers/UserController.php' => 'app/Http/Controllers/UserController.php',
            '/home/forge/example.com/app/Models/User.php' => 'app/Models/User.php',
            '/app/vendor/laravel/framework/src/Illuminate/Foundation/Application.php' => 'vendor/laravel/framework/src/Illuminate/Foundation/Application.php',
            '/var/www/html/vendor/php-mcp/server/src/Server.php' => 'vendor/php-mcp/server/src/Server.php',
            '/app/vendor/acme/pkg/src/A.php' => 'vendor/acme/pkg/src/A.php',
            '/srv/mcp/bin/server.php' => 'bin/server.php',
            '/opt/app/public/index.php' => 'public/index.php',
            'phar:///usr/local/bin/composer.phar/src/Composer/Console/Application.php' => 'phar:///usr/local/bin/composer.phar/src/Composer/Console/Application.php',
            "/home/u/proj/x.php(12) : eval()'d code" => "~/proj/x.php(12) : eval()'d code",
            '/usr/src/app/mcp.php' => 'mcp.php',
        ];
        foreach ($rows as $input => $expected) {
            yield $input => [$input, $expected];
        }
    }

    /**
     * Extra rows checked against a copy of the TS `makeRelativePath` under Node (marker order, steps 1 and 6).
     *
     * @return iterable<string, array{string, string}>
     */
    public static function tsParityProvider(): iterable
    {
        $rows = [
            'file://home/bob/src/x.ts' => 'src/x.ts',
            'file://C:/x/src/a.ts' => 'C:/x/src/a.ts',
            '/x/app/src/a.php' => 'src/a.php',
            '/x/build/a.php' => 'build/a.php',
            '/x/components/a.php' => 'components/a.php',
            '/x/pages/a.php' => 'pages/a.php',
            '/x/api/a.php' => 'api/a.php',
            '/x/utils/a.php' => 'utils/a.php',
            '/x/services/a.php' => 'services/a.php',
            '/x/modules/a.php' => 'modules/a.php',
            '/x/lib/y/src/a.php' => 'src/a.php',
            '/x/dist/y/lib/a.php' => 'lib/a.php',
            '/x/build/y/dist/a.php' => 'dist/a.php',
            '/x/modules/y/services/a.php' => 'services/a.php',
            '/Users/jo/x/y.php' => '~/x/y.php',
            'D:/Users/jo/x.php' => 'D:/Users/jo/x.php',
            'D:\Users\jo\x.php' => '~/x.php',
            'c:\x\y.php' => 'c:/x/y.php',
            '/var/www/a.php' => 'var/www/a.php',
            '/var/task/x/y.php' => 'x/y.php',
            '/opt/a.php' => 'opt/a.php',
            '/app/app/a.php' => 'app/a.php',
            '~/x/src/a.php' => '~/x/src/a.php',
            '/app/vendor/a/vendor/b/c.php' => 'vendor/b/c.php',
            'C:\app\vendor\acme\pkg\A.php' => 'vendor/acme/pkg/A.php',
            'file://home/bob/a:b/src/x.ts' => 'src/x.ts',
            'x\C:\src\a.php' => 'x\C:\src\a.php',
            '/app/x/C:/src/a.php' => 'x/C:/src/a.php',
            '/app/~xsrc/a.php' => 'src/a.php',
            '/app/~lib/x.php' => '~lib/x.php',
        ];
        foreach ($rows as $input => $expected) {
            yield $input => [$input, $expected];
        }
    }

    #[DataProvider('tsGoldenProvider')]
    #[DataProvider('phpPathProvider')]
    #[DataProvider('tsParityProvider')]
    #[Group('ac-05-E15')]
    public function testRelativePath(string $input, string $expected): void
    {
        $this->assertSame($expected, PathNormalizer::relative($input, false));
    }

    #[Group('ac-05-E16')]
    public function testWorkingDirectoryIsStrippedWithoutSeparatorCheck(): void
    {
        $this->assertSame('bin/run.php', PathNormalizer::relative('/work/repo/bin/run.php', '/work/repo'));
        // TS step 8 has no separator check: a cwd that is a prefix of a longer directory name cuts mid-name.
        $this->assertSame('o/bin/run.php', PathNormalizer::relative('/work/repo/bin/run.php', '/work/re'));
        $this->assertSame('c.php', PathNormalizer::relative('/a/b/c.php', '/a/b'));
    }

    #[Group('ac-05-E16')]
    public function testMissingOrEmptyWorkingDirectoryIsIgnored(): void
    {
        $this->assertSame('work/repo/bin/run.php', PathNormalizer::relative('/work/repo/bin/run.php', false));
        $this->assertSame('src/a.php', PathNormalizer::relative('/x/src/a.php', ''));
    }
}
