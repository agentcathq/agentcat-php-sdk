<?php

declare(strict_types=1);

namespace AgentCat\Tests\Support;

/** Turns the OpenAPI 3.0 request schema of the publish endpoint into a JSON Schema that Opis can check. */
final class OpenApiSchema
{
    public const string POINTER = '/paths/~1v1~1public~1events~1/post/requestBody/content/application~1json/schema';

    private const string CUSTOM_EVENT_TYPE = 'agentcat:custom';

    /** Reads the request schema out of a whole OpenAPI document by `POINTER`. */
    public static function extract(\stdClass $document): \stdClass
    {
        $node = $document;
        foreach (explode('/', substr(self::POINTER, 1)) as $token) {
            $key = str_replace(['~1', '~0'], ['/', '~'], $token);
            if (!$node instanceof \stdClass || !property_exists($node, $key)) {
                throw new \UnexpectedValueException('Schema pointer does not resolve at ' . $key);
            }
            $node = $node->{$key};
        }
        if (!$node instanceof \stdClass) {
            throw new \UnexpectedValueException('Schema pointer does not resolve to an object');
        }

        return $node;
    }

    public static function toJsonSchema(\stdClass $openApiSchema): \stdClass
    {
        $schema = self::convertObject($openApiSchema);
        $schema->additionalProperties = false;
        $properties = $schema->properties ?? null;
        $eventType = $properties instanceof \stdClass ? ($properties->event_type ?? null) : null;
        if ($eventType instanceof \stdClass && isset($eventType->enum) && is_array($eventType->enum)) {
            $eventType->enum[] = self::CUSTOM_EVENT_TYPE;
        }

        return $schema;
    }

    private static function convert(mixed $node): mixed
    {
        if (is_array($node)) {
            return array_map(self::convert(...), $node);
        }

        return $node instanceof \stdClass ? self::convertObject($node) : $node;
    }

    private static function convertObject(\stdClass $node): \stdClass
    {
        $out = new \stdClass();
        foreach (get_object_vars($node) as $key => $value) {
            if ($key === 'nullable') {
                continue;
            }
            $out->{$key} = self::convert($value);
        }
        if (($node->nullable ?? false) === true) {
            if (isset($out->type) && is_string($out->type)) {
                $out->type = [$out->type, 'null'];
            }
            if (isset($out->enum) && is_array($out->enum)) {
                $out->enum[] = null;
            }
        }

        return $out;
    }
}
