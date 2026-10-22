<?php

declare(strict_types=1);

namespace AgentCat\Tests\Unit\Internal\Tokens;

use AgentCat\Internal\Json\Json;
use AgentCat\Internal\Tokens\TokenEstimator;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

final class TokenEstimatorTest extends TestCase
{
    /**
     * @return iterable<string, array{int, int}>
     */
    public static function estimateProvider(): iterable
    {
        yield '0' => [0, 0];
        yield '1' => [1, 1];
        yield '7' => [7, 2];
        yield '13' => [13, 4];
        yield '4096' => [4096, 1171];
        yield 'clamp' => [7516192765, 2147483647];
        yield 'negative' => [-5, 0];
    }

    #[DataProvider('estimateProvider')]
    #[Group('ac-03-39')]
    public function testEstimate(int $bytes, int $expected): void
    {
        $this->assertSame($expected, TokenEstimator::estimate($bytes));
    }

    /**
     * @return iterable<string, array{string, int}>
     */
    public static function inputProvider(): iterable
    {
        yield 'simple' => ['{"q":"hello"}', 4];
        yield 'empty object' => ['{}', 1];
        yield 'accent' => ['{"name":"café"}', 5];
        yield 'html not escaped' => ['{"html":"<a>&</a>"}', 6];
        yield 'cjk' => ['{"t":"你好"}', 4];
        yield 'nested' => ['{"ids":[1,2,3],"opts":{"deep":true,"n":null}}', 13];
        yield 'numeric key stays object' => ['{"0":"x"}', 3];
        yield 'slash not escaped' => ['{"p":"a/b"}', 4];
        yield 'zero fraction' => ['{"n":1.0}', 2];
    }

    #[DataProvider('inputProvider')]
    #[Group('ac-03-39')]
    public function testInput(string $json, int $expected): void
    {
        $this->assertSame($expected, TokenEstimator::input(Json::decode($json)));
    }

    #[Group('ac-03-39')]
    public function testInputOmitted(): void
    {
        $this->assertNull(TokenEstimator::input(null));
        $this->assertNull(TokenEstimator::input((object) ['bad' => "\xB1\x31"]));
        $this->assertNull(TokenEstimator::input(NAN));
    }

    /**
     * @return iterable<string, array{string, ?int}>
     */
    public static function outputProvider(): iterable
    {
        $big = '{"a":"' . str_repeat('x', 5000) . '"}';
        yield 'text' => ['{"content":[{"type":"text","text":"hello world"}]}', 4];
        yield 'two texts one ceil' => ['{"content":[{"type":"text","text":"abcd"},{"type":"text","text":"e"}]}', 2];
        yield 'empty text' => ['{"content":[{"type":"text","text":""}]}', 0];
        yield 'image' => ['{"content":[{"type":"image","data":"AAAA","mimeType":"image/png"}]}', 0];
        yield 'resource text' => ['{"content":[{"type":"resource","resource":{"uri":"u","text":"resource body"}}]}', 4];
        yield 'resource blob' => ['{"content":[{"type":"resource","resource":{"uri":"u","blob":"AAAA"}}]}', 0];
        yield 'content wins over structured' => ['{"content":[{"type":"text","text":"hi"}],"structuredContent":' . $big . '}', 1];
        yield '4096 x' => ['{"content":[{"type":"text","text":"' . str_repeat('x', 4096) . '"}]}', 1171];
        yield 'empty content + structured' => ['{"content":[],"structuredContent":{"result":"ok"}}', 5];
        yield 'empty content' => ['{"content":[]}', 0];
        yield 'empty content null structured' => ['{"content":[],"structuredContent":null}', 0];
        yield 'image + structured' => ['{"content":[{"type":"image","data":"A"}],"structuredContent":{"result":"ok"}}', 0];
        yield 'no content' => ['{"result":"ok"}', 5];
        yield 'content object' => ['{"content":{"a":1}}', 6];
        yield 'content null' => ['{"content":null}', 5];
        yield 'non-string text' => ['{"content":[{"type":"text","text":5}]}', 0];
        yield 'resource non-string text' => ['{"content":[{"type":"resource","resource":{"text":5}}]}', 0];
        yield 'resource not object' => ['{"content":[{"type":"resource","resource":"x"}]}', 0];
        yield 'null and scalar blocks' => ['{"content":[null,"x",5,{"type":"text"},{"text":"abcd"}]}', 0];
        yield 'ignored null adds nothing' => ['{"content":[{"type":"text","text":"abcdefgh"},null]}', 3];
        yield 'ignored scalar adds nothing' => ['{"content":[{"type":"text","text":"abcdefgh"},5]}', 3];
        yield 'ignored non-string text adds nothing' => ['{"content":[{"type":"text","text":"abcdefgh"},{"type":"text","text":5}]}', 3];
        yield 'ignored unknown type adds nothing' => ['{"content":[{"type":"text","text":"abcdefgh"},{"type":"image"}]}', 3];
        yield 'ignored typeless adds nothing' => ['{"content":[{"type":"text","text":"abcdefgh"},{"text":"zz"}]}', 3];
        yield 'ignored resource without text adds nothing' => ['{"content":[{"type":"text","text":"abcdefgh"},{"type":"resource","resource":{}}]}', 3];
        yield 'multibyte bytes' => ['{"content":[{"type":"text","text":"你好"}]}', 2];
        yield 'list response' => ['[1,2]', 2];
        yield 'scalar response' => ['"hello"', 2];
    }

    #[DataProvider('outputProvider')]
    #[Group('ac-03-39')]
    public function testOutput(string $json, ?int $expected): void
    {
        $this->assertSame($expected, TokenEstimator::output(Json::decode($json)));
    }

    #[Group('ac-03-39')]
    public function testOutputOmitted(): void
    {
        $this->assertNull(TokenEstimator::output(null));
        $this->assertNull(TokenEstimator::output((object) ['result' => "\xB1\x31"]));
        $this->assertNull(TokenEstimator::output((object) ['content' => [], 'structuredContent' => "\xB1\x31"]));
    }
}
