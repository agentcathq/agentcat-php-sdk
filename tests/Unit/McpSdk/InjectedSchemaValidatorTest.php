<?php

declare(strict_types=1);

namespace AgentCat\Tests\Unit\McpSdk;

use AgentCat\McpSdk\Internal\InjectedSchemaValidator;
use AgentCat\Tests\Support\MemoryLogger;
use Mcp\Capability\Discovery\SchemaValidator;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

final class InjectedSchemaValidatorTest extends TestCase
{
    private const array OWN = ['type' => 'object', 'properties' => ['msg' => ['type' => 'string']], 'required' => ['msg'], 'additionalProperties' => false];

    private const array INJECTED = [
        'type' => 'object',
        'properties' => ['msg' => ['type' => 'string'], 'session_id' => ['type' => 'string'], 'context' => ['type' => 'string']],
        'required' => ['msg', 'session_id', 'context'],
        'additionalProperties' => false,
    ];

    private const array GET_MORE_TOOLS = ['type' => 'object', 'properties' => ['context' => ['type' => 'string']], 'required' => ['context']];

    public function testAnInjectedSchemaChecksTheOtherArgumentsAgainstTheToolsOwnSchema(): void
    {
        $validator = $this->validator([[self::INJECTED, self::OWN, ['session_id', 'context']]]);

        $this->assertSame([], $validator->validateAgainstJsonSchema(['msg' => 'hi'], self::INJECTED), 'without the injected arguments');
        $this->assertSame([], $validator->validateAgainstJsonSchema(['msg' => 'hi', 'session_id' => 'ses_x', 'context' => 'why'], self::INJECTED));
    }

    public function testTheErrorsAreTheLibrarysForTheToolsOwnSchema(): void
    {
        $validator = $this->validator([[self::INJECTED, self::OWN, ['session_id', 'context']]]);

        $errors = $validator->validateAgainstJsonSchema(['session_id' => 'ses_x', 'msg' => 7, 'extra' => true], self::INJECTED);

        $this->assertEquals((new SchemaValidator())->validateAgainstJsonSchema(['msg' => 7, 'extra' => true], self::OWN), $errors);
        $this->assertNotSame([], $errors);
    }

    public function testNoArgumentsLeftAreAnEmptyObject(): void
    {
        $empty = ['type' => 'object', 'properties' => new \stdClass()];
        $validator = $this->validator([[self::INJECTED, $empty, ['session_id', 'context']]]);

        $this->assertSame([], $validator->validateAgainstJsonSchema(['session_id' => 'ses_x', 'context' => 'why'], self::INJECTED));
    }

    public function testAToolThatAgentCatAnswersIsNotChecked(): void
    {
        $validator = $this->validator([[self::GET_MORE_TOOLS, null, []]]);

        $this->assertSame([], $validator->validateAgainstJsonSchema(['context' => 7], self::GET_MORE_TOOLS));
    }

    public function testAnySchemaAgentCatDidNotInjectIsCheckedUnchanged(): void
    {
        $validator = $this->validator([[self::INJECTED, self::OWN, ['session_id', 'context']]]);

        $this->assertSame([], $validator->validateAgainstJsonSchema(['context' => 'kept'], self::GET_MORE_TOOLS));
        $this->assertEquals((new SchemaValidator())->validateAgainstJsonSchema(['msg' => 'hi', 'session_id' => 'kept'], self::OWN), $validator->validateAgainstJsonSchema(['msg' => 'hi', 'session_id' => 'kept'], self::OWN));
        $this->assertNotSame([], $validator->validateAgainstJsonSchema(['msg' => 'hi', 'session_id' => 'kept'], self::OWN), 'session_id is not stripped for a schema AgentCat did not inject');
    }

    public function testTheLibrarysLoggerGetsTheValidatorsLines(): void
    {
        $logger = new MemoryLogger();
        $broken = ['type' => 'object', 'properties' => ['msg' => ['type' => 7]]];
        $validator = new InjectedSchemaValidator($logger, [[self::INJECTED, $broken, ['session_id', 'context']]]);

        $validator->validateAgainstJsonSchema(['msg' => 'hi', 'session_id' => 'ses_x'], self::INJECTED);

        $this->assertNotSame([], $logger->messages(), 'the library logs a schema it cannot use');
    }

    /** @param list<array{array<string, mixed>, array<string, mixed>|null, array<array-key>}> $injected */
    private function validator(array $injected): InjectedSchemaValidator
    {
        return new InjectedSchemaValidator(new NullLogger(), $injected);
    }
}
