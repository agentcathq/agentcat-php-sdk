<?php

declare(strict_types=1);

namespace AgentCat\Tests\Unit;

use AgentCat\Tests\Support\GlobalHandlers;
use AgentCat\Tests\Support\SrcErrorGuard;
use AgentCat\Tests\Support\Thrown;
use PHPUnit\Framework\Assert;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * The guard of the bridge test cases in front of a framework's error handler. This file stands in for a file of
 * `src/` (each test names its directory as the source), and a handler of the test stands in for the framework's: it
 * takes every issue, as Laravel's `HandleExceptions` does.
 */
final class SrcErrorGuardTest extends TestCase
{
    private GlobalHandlers $handlers;

    /** @var \ArrayObject<int, string> the messages that the framework's handler took */
    private \ArrayObject $framework;

    #[\Override]
    protected function setUp(): void
    {
        $this->handlers = GlobalHandlers::save();
        $framework = $this->framework = new \ArrayObject();
        set_error_handler(static function (int $level, string $message) use ($framework): bool {
            $framework->append($message);

            return true;
        });
    }

    #[\Override]
    protected function tearDown(): void
    {
        $this->handlers->restore();
    }

    /** @return iterable<string, array{int, string}> */
    public static function issues(): iterable
    {
        yield 'a deprecation' => [E_USER_DEPRECATED, 'E_USER_DEPRECATED'];
        yield 'a notice' => [E_USER_NOTICE, 'E_USER_NOTICE'];
        yield 'a warning' => [E_USER_WARNING, 'E_USER_WARNING'];
    }

    /** The framework's handler still takes the issue; `assertNone()` then fails with it and its `file:line`, once. */
    #[DataProvider('issues')]
    public function testAnIssueFromASourceFileFailsTheTest(int $level, string $name): void
    {
        SrcErrorGuard::install(__DIR__ . '/');
        trigger_error('raised by src', $level);
        $line = __LINE__ - 1;
        $this->handlers->restore();

        $failure = $this->failureOfAssertNone();

        $this->assertSame(['raised by src'], $this->framework->getArrayCopy());
        $this->assertSame("A file of src/ raised 1 issue(s) that a framework's error handler took:\n" . $name . ': raised by src at ' . __FILE__ . ':' . $line . "\nFailed asserting that actual size 1 matches expected size 0.", $failure);
        SrcErrorGuard::assertNone($this); // the issue is forgotten: the next test starts clean
    }

    /** @return iterable<string, array{string, bool}> the source directory, and whether the issue is suppressed */
    public static function otherIssues(): iterable
    {
        yield 'from another file, as vendor/' => ['/nonexistent/src/', false];
        yield 'from a source file, suppressed with @' => [__DIR__ . '/', true];
    }

    /** Any other issue goes to the framework's handler alone. */
    #[DataProvider('otherIssues')]
    public function testAnyOtherIssueIsNotRecorded(string $source, bool $suppressed): void
    {
        SrcErrorGuard::install($source);
        $suppressed ? @trigger_error('not ours', E_USER_DEPRECATED) : trigger_error('not ours', E_USER_DEPRECATED);
        $this->handlers->restore();

        SrcErrorGuard::assertNone($this);
        $this->assertSame(['not ours'], $this->framework->getArrayCopy());
    }

    /** A second kernel of a test installs nothing while the guard is in front: each issue is recorded once. */
    public function testAGuardInFrontIsNotInstalledAgain(): void
    {
        SrcErrorGuard::install(__DIR__ . '/');
        $guard = SrcErrorGuard::inPlace();
        SrcErrorGuard::install(__DIR__ . '/');
        $this->assertSame($guard, SrcErrorGuard::inPlace());
        trigger_error('raised by src', E_USER_DEPRECATED);
        $this->handlers->restore();

        $failure = $this->failureOfAssertNone();

        $this->assertStringStartsWith("A file of src/ raised 1 issue(s) that a framework's error handler took:\nE_USER_DEPRECATED: raised by src at ", $failure);
    }

    /** With nothing recorded, `assertNone()` passes and counts one assertion. */
    public function testNothingRecordedCountsOneAssertion(): void
    {
        $before = Assert::getCount();

        SrcErrorGuard::assertNone($this);

        $this->assertSame($before + 1, Assert::getCount());
    }

    /** The message of the assertion failure that `assertNone()` throws. */
    private function failureOfAssertNone(): string
    {
        $failure = Thrown::by(\Throwable::class, function (): void {
            SrcErrorGuard::assertNone($this);
        });
        $this->assertStringEndsWith('ExpectationFailedException', $failure::class); // PHPUnit's failure of an assertion

        return $failure->getMessage();
    }
}
