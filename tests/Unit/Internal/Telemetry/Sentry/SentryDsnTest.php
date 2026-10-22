<?php

declare(strict_types=1);

namespace AgentCat\Tests\Unit\Internal\Telemetry\Sentry;

use AgentCat\Exporters\SentryExporter;
use AgentCat\Internal\Telemetry\Sentry\SentryDsn;
use AgentCat\Internal\Telemetry\Sentry\SentryPayload;
use AgentCat\Tests\Support\Thrown;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

final class SentryDsnTest extends TestCase
{
    #[Group('ac-06-31')]
    public function testTheEndpointAndKeyComeFromTheDsn(): void
    {
        $dsn = SentryDsn::parse('https://abc123@o1.ingest.sentry.io/42');

        $this->assertSame('https://o1.ingest.sentry.io/api/42/envelope/', $dsn->endpoint);
        $this->assertSame('abc123', $dsn->publicKey);
    }

    #[Group('ac-06-31')]
    public function testTheAuthHeaderNamesTheClientAndTheKey(): void
    {
        $payload = SentryPayload::of(new SentryExporter('https://abc123@o1.ingest.sentry.io/42'));

        $this->assertSame(
            ['X-Sentry-Auth' => 'Sentry sentry_version=7, sentry_client=agentcat/1.0.0, sentry_key=abc123', 'Content-Type' => 'application/x-sentry-envelope'],
            $payload->headers(),
        );
        $this->assertSame('https://o1.ingest.sentry.io/api/42/envelope/', $payload->endpoint);
    }

    #[Group('ac-06-32')]
    #[DataProvider('validDsns')]
    public function testPortAndPathAreKept(string $dsn, string $endpoint): void
    {
        $this->assertSame($endpoint, SentryDsn::parse($dsn)->endpoint);
    }

    /** @return iterable<string, array{string, string}> */
    public static function validDsns(): iterable
    {
        yield 'port and path' => ['https://abc123@sentry.example.com:9000/sub/path/42', 'https://sentry.example.com:9000/sub/path/api/42/envelope/'];
        yield 'http stays http' => ['http://abc123@localhost/7', 'http://localhost/api/7/envelope/'];
        yield 'port only' => ['http://abc123@localhost:8080/7', 'http://localhost:8080/api/7/envelope/'];
        yield 'path only' => ['https://abc123@h.io/team/7', 'https://h.io/team/api/7/envelope/'];
        yield 'hyphen and digits in the host' => ['https://0af@my-host.example.com/1', 'https://my-host.example.com/api/1/envelope/'];
        yield 'empty path segment' => ['https://abc@h//5', 'https://h//api/5/envelope/'];
    }

    #[Group('ac-06-32')]
    public function testThePublicKeyOfAnotherDsnIsUsed(): void
    {
        $this->assertSame('0123456789abcdef', SentryDsn::parse('https://0123456789abcdef@h/1')->publicKey);
    }

    /**
     * The message names the DSN with its user-info masked: the public key, and the secret of a legacy
     * `https://public:secret@host/1` DSN, never reach a log line or diagnostics. Scheme, host, port and path stay.
     * A deliberate divergence from TypeScript, which prints the whole DSN.
     */
    #[Group('ac-06-33')]
    #[DataProvider('invalidDsns')]
    public function testAnInvalidDsnThrowsWithTheMaskedDsnInTheMessage(string $dsn, string $logged): void
    {
        $this->assertSame('Invalid Sentry DSN: ' . $logged, Thrown::by(\InvalidArgumentException::class, static fn(): SentryDsn => SentryDsn::parse($dsn))->getMessage());
        $this->assertSame('Invalid Sentry DSN: ' . $logged, Thrown::by(\InvalidArgumentException::class, static fn(): SentryPayload => SentryPayload::of(new SentryExporter($dsn)))->getMessage());
    }

    /** @return iterable<string, array{string, string}> the DSN, then how the message names it */
    public static function invalidDsns(): iterable
    {
        yield 'upper-case key' => ['https://ABC@o1.ingest.sentry.io/42', 'https://***@o1.ingest.sentry.io/42'];
        yield 'secret' => ['https://abc:secret@o1.ingest.sentry.io/42', 'https://***@o1.ingest.sentry.io/42'];
        yield 'an @ in the secret' => ['https://abc:se@cret@o1.ingest.sentry.io/42', 'https://***@o1.ingest.sentry.io/42'];
        yield 'non-numeric project' => ['https://abc@o1.ingest.sentry.io/proj', 'https://***@o1.ingest.sentry.io/proj'];
        yield 'scheme' => ['ftp://abc@h/1', 'ftp://***@h/1'];
        yield 'no scheme' => ['abc@o1.ingest.sentry.io/42', '***@o1.ingest.sentry.io/42'];
        yield 'trailing newline' => ["https://abc@h/42\n", "https://***@h/42\n"];
        yield 'leading text' => [' https://abc@h/42', ' https://***@h/42'];
        yield 'no key' => ['https://h/42', 'https://h/42'];
        yield 'no project' => ['https://abc@h/', 'https://***@h/'];
        yield 'empty key: nothing to mask' => ['https://@h/42', 'https://@h/42'];
        yield 'non-hex key' => ['https://xyz@h/42', 'https://***@h/42'];
        yield 'port without digits' => ['https://abc@h:/42', 'https://***@h:/42'];
        yield 'letters in the port' => ['https://abc@h:80a/42', 'https://***@h:80a/42'];
        yield 'space in the host' => ['https://abc@h h/42', 'https://***@h h/42'];
        yield 'carriage return in the path' => ["https://abc@h/a\rb/42", "https://***@h/a\rb/42"];
        yield 'line separator in the path' => ["https://abc@h/a\u{2028}b/42", "https://***@h/a\u{2028}b/42"];
        yield 'paragraph separator in the path' => ["https://abc@h/a\u{2029}b/42", "https://***@h/a\u{2029}b/42"];
        yield 'trailing project text' => ['https://abc@h/42x', 'https://***@h/42x'];
        yield 'non-ASCII letter in the host' => ['https://abc@é.example.com/42', 'https://***@é.example.com/42'];
        yield 'non-ASCII digits in the project' => ['https://abc@o1.sentry.io/٤٢', 'https://***@o1.sentry.io/٤٢'];
        yield 'non-ASCII digits in the port' => ['https://abc@h:٨٠/42', 'https://***@h:٨٠/42'];
        yield 'a :// after the @' => ['abc@h/x://y', '***@h/x://y'];
        yield 'empty' => ['', ''];
    }
}
