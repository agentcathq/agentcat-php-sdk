<?php

declare(strict_types=1);

namespace AgentCat\Tests\Runtime;

use AgentCat\Internal\Json\Json;
use AgentCat\Tests\Runtime\Support\DockerRuntime;
use AgentCat\Tests\Runtime\Support\FastCgiClient;
use AgentCat\Tests\Runtime\Support\Images;
use AgentCat\Tests\Runtime\Support\RuntimeTestCase;
use AgentCat\Tests\Support\Thrown;
use Ergebnis\PHPUnit\SlowTestDetector\Attribute\MaximumDuration;
use PHPUnit\Framework\Attributes\Group;

/** The FastCGI client against PHP-FPM: what FPM receives is what the client sent. */
#[Group('runtime')]
final class FastCgiClientTest extends RuntimeTestCase
{
    #[MaximumDuration(60000)]
    public function testALongValueManyParameterRecordsAndALargeBodyReachFpm(): void
    {
        $port = $this->fpm();
        $headers = [
            'Content-Type' => 'text/plain',
            'X-Long' => str_repeat('l', 200), // a 4-byte value length
            'X-Fill-One' => str_repeat('a', 30000), // with the next two: 90 kB of parameters, three FCGI_PARAMS records
            'X-Fill-Two' => str_repeat('b', 30000),
            'X-Fill-Three' => str_repeat('c', 30000),
        ];
        $body = str_repeat('z', 70000); // two FCGI_STDIN records
        $sentAt = microtime(true);

        $answer = (new FastCgiClient('127.0.0.1', $port))->request(self::repo() . '/tests/Runtime/Fixtures/fastcgi-echo.php', 'POST', '/echo?x=1', $headers, $body);

        $this->assertSame(200, $answer['status'], $answer['body']);
        $this->assertSame('application/json', $answer['headers']['content-type'] ?? null);
        $echo = Json::decode($answer['body']);
        $this->assertInstanceOf(\stdClass::class, $echo);
        $this->assertSame(['POST', '/echo?x=1', 'x=1', 'text/plain'], [$echo->method, $echo->uri, $echo->query, $echo->contentType]);
        $this->assertEquals((object) [
            'HTTP_X_LONG' => $headers['X-Long'],
            'HTTP_X_FILL_ONE' => $headers['X-Fill-One'],
            'HTTP_X_FILL_TWO' => $headers['X-Fill-Two'],
            'HTTP_X_FILL_THREE' => $headers['X-Fill-Three'],
        ], $echo->headers);
        $this->assertSame($body, $echo->body);
        $this->assertGreaterThan($sentAt, $answer['endedAt']);
        $this->assertLessThanOrEqual(microtime(true), $answer['endedAt']);
    }

    /** A pair never spans two records (FPM parses each record on its own), so one longer than a record is refused. */
    #[MaximumDuration(60000)]
    public function testAParameterLongerThanOneRecordIsRefusedBeforeTheClientConnects(): void
    {
        $client = new FastCgiClient('127.0.0.1', $this->freePort()); // nothing listens there

        $refused = Thrown::by(\InvalidArgumentException::class, static fn(): array => $client->request('/echo.php', 'GET', '/', ['X-Huge' => str_repeat('h', 70000)], ''));

        $this->assertStringContainsString('HTTP_X_HUGE does not fit in one record', $refused->getMessage());
    }

    /** PHP-FPM on 127.0.0.1, with a pool on a free port; returns the port. */
    private function fpm(): int
    {
        $out = $this->out();
        $port = $this->freePort();
        file_put_contents($out . '/pool.conf', "[www]\nlisten = 127.0.0.1:{$port}\npm = static\npm.max_children = 1\n");
        DockerRuntime::start(Images::fpm(), ['php-fpm', '-F'], $this->env(), [$out . '/pool.conf' => '/usr/local/etc/php-fpm.d/zzz-agentcat.conf'], port: $port);

        return $port;
    }
}
