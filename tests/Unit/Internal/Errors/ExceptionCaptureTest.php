<?php

declare(strict_types=1);

namespace AgentCat\Tests\Unit\Internal\Errors;

use AgentCat\Event;
use AgentCat\Internal\Errors\ExceptionCapture;
use AgentCat\Internal\Errors\PathNormalizer;
use AgentCat\Internal\Errors\StackFrames;
use AgentCat\Internal\Json\Json;
use AgentCat\Internal\Wire\WireEncoder;
use AgentCat\Tests\Fixtures\Errors\Thrower;
use AgentCat\Tests\Support\JsonAssert;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

final class ExceptionCaptureTest extends TestCase
{
    /** @var list<string> */
    private array $tempFiles = [];

    #[\Override]
    protected function tearDown(): void
    {
        foreach ($this->tempFiles as $file) {
            unlink($file);
        }
    }

    /** @param \Closure(): mixed $throws */
    private static function caught(\Closure $throws): \Throwable
    {
        try {
            $throws();
        } catch (\Throwable $error) {
            return $error;
        }
        self::fail('nothing was thrown');
    }

    private static function viaStatic(string $message): \Throwable
    {
        return self::caught(static fn(): never => Thrower::viaStatic($message));
    }

    /** @param list<\stdClass> $list */
    private static function at(array $list, int $index): \stdClass
    {
        return $list[$index] ?? self::fail('no entry at index ' . $index);
    }

    /** @return list<\stdClass> */
    private static function frames(\stdClass $data): array
    {
        $frames = $data->frames;
        self::assertIsArray($frames);
        self::assertNotSame([], $frames);
        self::assertTrue(array_is_list($frames));
        self::assertContainsOnlyInstancesOf(\stdClass::class, $frames);

        return $frames;
    }

    /** @return list<\stdClass> */
    private static function chained(\stdClass $data): array
    {
        $chained = $data->chained_errors;
        self::assertIsArray($chained);
        self::assertTrue(array_is_list($chained));
        self::assertContainsOnlyInstancesOf(\stdClass::class, $chained);

        return $chained;
    }

    /**
     * @param list<\stdClass> $chained
     *
     * @return list<mixed>
     */
    private static function messages(array $chained): array
    {
        return array_map(static fn(\stdClass $entry): mixed => $entry->message, $chained);
    }

    private static function str(mixed $value): string
    {
        self::assertIsString($value);

        return $value;
    }

    /** @return iterable<string, array{string, int|string}> */
    public static function constantProvider(): iterable
    {
        yield 'platform' => ['PLATFORM', 'php'];
        yield 'chain cap' => ['MAX_CHAINED_ERRORS', 10];
    }

    #[DataProvider('constantProvider')]
    public function testConstants(string $name, int|string $expected): void
    {
        $this->assertSame(ExceptionCapture::{$name}, $expected);
    }

    #[Group('ac-05-E1')]
    #[Group('ac-05-C1')]
    public function testThrowableGivesFullErrorData(): void
    {
        $error = self::viaStatic('Test error message');

        $data = ExceptionCapture::capture($error);

        $this->assertSame(['message', 'type', 'platform', 'stack', 'frames'], array_keys(get_object_vars($data)));
        $this->assertSame('Test error message', $data->message);
        $this->assertSame('RuntimeException', $data->type);
        $this->assertSame('php', $data->platform);
        $this->assertSame(StackFrames::stackString($error), $data->stack);

        $frames = self::frames($data);
        $this->assertSame(14, $error->getLine());
        $this->assertSame(
            [
                'function' => 'fail',
                'module' => Thrower::class,
                'filename' => PathNormalizer::relative($error->getFile(), getcwd()),
                'abs_path' => $error->getFile(),
                'lineno' => $error->getLine(),
                'in_app' => true,
                'context_line' => '        throw new \RuntimeException($message);',
            ],
            get_object_vars(self::at($frames, 0)),
        );
        $this->assertSame('Thrower.php', basename(self::str(self::at($frames, 0)->filename)));

        $second = self::at($frames, 1);
        $this->assertSame('viaStatic', $second->function);
        $this->assertSame(Thrower::class, $second->module);
        $this->assertSame(Thrower::$callLine, $second->lineno);
        $this->assertSame('        (new self())->fail($message);', $second->context_line);

        $vendorFrames = array_values(array_filter(
            $frames,
            static fn(\stdClass $frame): bool => isset($frame->abs_path) && str_contains(self::str($frame->abs_path), '/vendor/phpunit/'),
        ));
        $this->assertNotSame([], $vendorFrames);
        foreach ($vendorFrames as $frame) {
            $this->assertFalse($frame->in_app);
            $this->assertStringStartsWith('vendor/phpunit/', self::str($frame->filename));
            $this->assertObjectNotHasProperty('context_line', $frame);
        }
        foreach ($frames as $frame) {
            $this->assertIsString($frame->filename);
            $this->assertIsString($frame->function);
        }
    }

    /** @return iterable<string, array{\Throwable, string}> */
    public static function typeProvider(): iterable
    {
        yield 'TypeError' => [new \TypeError('x'), 'TypeError'];
        yield 'Error' => [new \Error('x'), 'Error'];
        yield 'namespaced' => [new \Random\RandomException('x'), \Random\RandomException::class];
    }

    #[DataProvider('typeProvider')]
    #[Group('ac-05-E2')]
    public function testTypeIsTheFullyQualifiedClassName(\Throwable $error, string $type): void
    {
        $this->assertSame($type, ExceptionCapture::capture($error)->type);
    }

    #[Group('ac-05-E3')]
    public function testEmptyMessageStaysEmpty(): void
    {
        $data = ExceptionCapture::capture(new \RuntimeException(''));

        $this->assertSame('', $data->message);
        $this->assertSame('RuntimeException', $data->type);
    }

    /** @return iterable<string, array{string, string}> */
    public static function toolResultProvider(): iterable
    {
        yield 'text blocks joined and trimmed' => [
            '{"isError":true,"content":[{"type":"text","text":" a "},{"type":"image","data":"x"},{"type":"text","text":"b"}]}',
            'a  b',
        ];
        yield 'empty content' => ['{"isError":true,"content":[]}', 'Unknown error'];
        yield 'holes and primitives' => ['{"isError":true,"content":[null,5,{"type":"text","text":"z"}]}', 'z'];
        yield 'non-string and missing text' => ['{"isError":true,"content":[{"type":"text","text":5},{"type":"text"}]}', '5'];
        yield 'null text' => ['{"isError":true,"content":[{"type":"text","text":null},{"type":"text","text":"q"}]}', 'q'];
        yield 'list and object text' => ['{"isError":true,"content":[{"type":"text","text":[1,2]},{"type":"text","text":{"a":1}}]}', '1,2 [object Object]'];
        yield 'text "0" is kept' => ['{"isError":true,"content":[{"type":"text","text":"0"}]}', '0'];
        yield 'whitespace only' => ['{"isError":true,"content":[{"type":"text","text":" \n "}]}', 'Unknown error'];
        yield 'isError false still matches' => ['{"isError":false,"content":[{"type":"text","text":"ok"}]}', 'ok'];
        yield 'isError null still matches' => ['{"isError":null,"content":[{"type":"text","text":"n"}]}', 'n'];
    }

    #[DataProvider('toolResultProvider')]
    #[Group('ac-05-E4')]
    public function testToolResultGivesTextMessageOnly(string $resultJson, string $message): void
    {
        $result = Json::decode($resultJson);

        $this->assertTrue(ExceptionCapture::isToolResult($result));
        $this->assertSame(
            Json::encodeJs(['message' => $message, 'platform' => 'php']),
            Json::encodeJs(ExceptionCapture::capture($result)),
        );
    }

    #[Group('ac-05-E4')]
    public function testAssociativeArrayToolResultMatches(): void
    {
        $result = ['isError' => true, 'content' => [['type' => 'text', 'text' => 'from array'], ['type' => 'image']]];

        $this->assertTrue(ExceptionCapture::isToolResult($result));
        $this->assertSame('{"message":"from array","platform":"php"}', Json::encodeJs(ExceptionCapture::capture($result)));
    }

    #[Group('ac-05-E4')]
    public function testObjectWithPublicPropertiesMatches(): void
    {
        $result = new class {
            public bool $isError = true;

            /** @var list<array<string, string>> */
            public array $content = [['type' => 'text', 'text' => 'from object']];
        };

        $this->assertTrue(ExceptionCapture::isToolResult($result));
        $this->assertSame('from object', ExceptionCapture::capture($result)->message);
    }

    /** @return iterable<string, array{mixed}> */
    public static function notToolResultProvider(): iterable
    {
        yield 'null' => [null];
        yield 'string' => ['isError'];
        yield 'list' => [[true, []]];
        yield 'missing isError' => [Json::decode('{"content":[]}')];
        yield 'missing content' => [Json::decode('{"isError":true}')];
        yield 'object content' => [Json::decode('{"isError":true,"content":{"0":{"type":"text","text":"x"}}}')];
        yield 'string content' => [Json::decode('{"isError":true,"content":"x"}')];
        yield 'non-list array content' => [['isError' => true, 'content' => [1 => ['type' => 'text', 'text' => 'x']]]];
        yield 'closure' => [static fn(): null => null];
    }

    #[DataProvider('notToolResultProvider')]
    #[Group('ac-05-E4')]
    public function testNotToolResult(mixed $value): void
    {
        $this->assertFalse(ExceptionCapture::isToolResult($value));
    }

    #[Group('ac-05-E8')]
    public function testPreviousExceptionBecomesChainedError(): void
    {
        $data = ExceptionCapture::capture(new \Exception('Wrapper error', 0, new \Exception('Root cause error')));

        $this->assertSame(['message', 'type', 'platform', 'stack', 'frames', 'chained_errors'], array_keys(get_object_vars($data)));
        $chained = self::chained($data);
        $this->assertCount(1, $chained);
        $this->assertSame(['message', 'type', 'stack', 'frames'], array_keys(get_object_vars(self::at($chained, 0))));
        $this->assertSame('Root cause error', self::at($chained, 0)->message);
        $this->assertSame('Exception', self::at($chained, 0)->type);
        $this->assertIsString(self::at($chained, 0)->stack);
        $this->assertStringStartsWith('Exception: Root cause error in ' . __FILE__, self::at($chained, 0)->stack);
        $this->assertNotSame([], self::frames(self::at($chained, 0)));
    }

    #[Group('ac-05-E8')]
    public function testChainIsOrderedOutermostFirst(): void
    {
        $error = new \LogicException('Top', 0, new \RuntimeException('Middle', 0, new \DomainException('Root')));

        $chained = self::chained(ExceptionCapture::capture($error));

        $this->assertSame(['Middle', 'Root'], self::messages($chained));
        $this->assertSame(['RuntimeException', 'DomainException'], array_map(static fn(\stdClass $entry): mixed => $entry->type, $chained));
    }

    #[Group('ac-05-E9')]
    public function testChainKeepsTheTenNearestCauses(): void
    {
        $error = new \Exception('Level 0');
        for ($level = 1; $level <= 20; ++$level) {
            $error = new \Exception('Level ' . $level, 0, $error);
        }

        $chained = self::chained(ExceptionCapture::capture($error));

        $expected = [];
        for ($level = 19; $level >= 10; --$level) {
            $expected[] = 'Level ' . $level;
        }
        $this->assertSame($expected, self::messages($chained));
        foreach ($chained as $entry) {
            $this->assertObjectNotHasProperty('platform', $entry);
        }
    }

    #[Group('ac-05-E10')]
    public function testCyclicChainRepeatsTheTopErrorOnceAndStops(): void
    {
        // D15: TS leaves the top-level error out of the seen-set, so it appears once in the chain.
        $e2 = new \Exception('Error 2');
        $e1 = new \Exception('Error 1', 0, $e2);
        (new \ReflectionProperty(\Exception::class, 'previous'))->setValue($e2, $e1);

        $this->assertSame(['Error 2', 'Error 1'], self::messages(self::chained(ExceptionCapture::capture($e1))));
    }

    #[Group('ac-05-E12')]
    public function testDeepRecursionIsCappedAtFiftyFrames(): void
    {
        $error = self::caught(static fn(): never => Thrower::recurse(80));

        $frames = self::frames(ExceptionCapture::capture($error));

        $this->assertCount(50, $frames);
        $this->assertSame('recurse', self::at($frames, 0)->function);
        $this->assertSame(Thrower::class, self::at($frames, 0)->module);
        $this->assertSame(27, self::at($frames, 0)->lineno);
        $this->assertSame('recurse', self::at($frames, 49)->function);
    }

    private function crlfClosureFile(): string
    {
        $path = tempnam(sys_get_temp_dir(), 'agentcat-crlf-');
        $this->assertIsString($path);
        $this->tempFiles[] = $path;
        file_put_contents($path, "<?php\r\nreturn static function (): never {\r\n    throw new \\RuntimeException('x');   \r\n};\r\n");

        return $path;
    }

    private function crlfError(string $path): \Throwable
    {
        $throws = require $path;
        $this->assertInstanceOf(\Closure::class, $throws);

        return self::caught($throws);
    }

    #[Group('ac-05-E14')]
    public function testContextLineKeepsTrailingSpacesAndCarriageReturn(): void
    {
        $path = $this->crlfClosureFile();

        $frames = self::frames(ExceptionCapture::capture($this->crlfError($path)));

        $this->assertSame($path, self::at($frames, 0)->abs_path);
        $this->assertSame('{closure}', self::at($frames, 0)->function);
        $this->assertSame(3, self::at($frames, 0)->lineno);
        $this->assertSame("    throw new \\RuntimeException('x');   \r", self::at($frames, 0)->context_line);
    }

    #[Group('ac-05-E14')]
    public function testEvalFrameHasNoContextLine(): void
    {
        $error = self::caught(static function (): void {
            eval('throw new \RuntimeException("e");');
        });

        $frames = self::frames(ExceptionCapture::capture($error));

        $this->assertStringEndsWith(" : eval()'d code", self::str(self::at($frames, 0)->abs_path));
        $this->assertTrue(self::at($frames, 0)->in_app);
        $this->assertSame(1, self::at($frames, 0)->lineno);
        $this->assertObjectNotHasProperty('context_line', self::at($frames, 0));
    }

    #[Group('ac-05-E14')]
    public function testVendorFrameGetsNoContextLine(): void
    {
        $path = $this->crlfClosureFile();
        $frames = new StackFrames(dirname($path), '/nowhere', false);

        $data = ExceptionCapture::capture($this->crlfError($path), $frames);

        $first = self::at(self::frames($data), 0);
        $this->assertSame($path, $first->abs_path);
        $this->assertFalse($first->in_app);
        $this->assertObjectNotHasProperty('context_line', $first);
    }

    public function testGivenStackFramesAreUsedForChainedErrors(): void
    {
        $path = $this->crlfClosureFile();
        $frames = new StackFrames(dirname($path), '/nowhere', false);

        $data = ExceptionCapture::fromThrowable(new \Exception('top', 0, $this->crlfError($path)), $frames);

        $first = self::at(self::frames(self::at(self::chained($data), 0)), 0);
        $this->assertSame($path, $first->abs_path);
        $this->assertFalse($first->in_app);
    }

    #[Group('ac-05-E16')]
    public function testWorksWithoutVendorDirOrWorkingDirectory(): void
    {
        $error = self::viaStatic('no cwd');

        $data = ExceptionCapture::fromThrowable($error, new StackFrames(null, '/nowhere', false));

        $this->assertSame('no cwd', $data->message);
        $first = self::at(self::frames($data), 0);
        $this->assertSame('fail', $first->function);
        $this->assertTrue($first->in_app);
        $this->assertStringEndsWith('Thrower.php', self::str($first->filename));
    }

    #[Group('ac-05-C2')]
    public function testSameThrowSiteGroupsIdentically(): void
    {
        $first = self::at(self::frames(ExceptionCapture::capture(self::viaStatic('request one'))), 0);
        $second = self::at(self::frames(ExceptionCapture::capture(self::caught(static fn(): never => (new Thrower())->fail('request two')))), 0);

        $this->assertSame($first->function, $second->function);
        $this->assertSame($first->module, $second->module);
        $this->assertSame(basename(self::str($first->filename)), basename(self::str($second->filename)));
    }

    /** @return iterable<string, array{mixed, string}> */
    public static function nonThrowableProvider(): iterable
    {
        $cyclic = new \stdClass();
        $cyclic->self = $cyclic;

        yield 'null' => [null, 'null'];
        yield 'string' => ['str', 'str'];
        yield 'empty string' => ['', ''];
        yield 'int' => [42, '42'];
        yield 'float' => [1.5, '1.5'];
        yield 'false' => [false, 'false'];
        yield 'true' => [true, 'true'];
        yield 'assoc array' => [['code' => 404, 'message' => 'Not found'], '{"code":404,"message":"Not found"}'];
        yield 'list' => [[1, 'a'], '[1,"a"]'];
        yield 'object' => [Json::decode('{"a":"é/"}'), '{"a":"é/"}'];
        yield 'cyclic object' => [$cyclic, '[object Object]'];
    }

    #[DataProvider('nonThrowableProvider')]
    public function testNonThrowableIsStringified(mixed $value, string $message): void
    {
        $data = ExceptionCapture::capture($value);

        $this->assertSame(['message', 'platform'], array_keys(get_object_vars($data)));
        $this->assertSame($message, $data->message);
        $this->assertSame('php', $data->platform);
    }

    public function testCaptureFailureFallsBackToMessageAndType(): void
    {
        $error = new \RuntimeException('broken trace');
        (new \ReflectionProperty(\Exception::class, 'trace'))->setValue($error, ['not a trace entry']);

        $data = ExceptionCapture::fromThrowable($error);

        $this->assertSame('{"message":"broken trace","type":"RuntimeException","platform":"php"}', Json::encodeJs($data));
    }

    #[Group('review-focus-1')]
    public function testInvalidUtf8MessageIsKeptRawAndEncodesWithReplacementCharacters(): void
    {
        $message = "Incorrect string value: '\xE9t\xE9'";

        $data = ExceptionCapture::fromThrowable(new \RuntimeException($message));

        $this->assertSame($message, $data->message);
        $body = Json::decode(WireEncoder::encode(new Event(projectId: 'proj_1', isError: true, error: $data)));
        $this->assertSame("Incorrect string value: '\u{FFFD}t\u{FFFD}'", JsonAssert::at($body, 'error', 'message'));
        $this->assertSame('RuntimeException', JsonAssert::at($body, 'error', 'type'));
    }
}
