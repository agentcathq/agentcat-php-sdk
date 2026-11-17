<?php

declare(strict_types=1);

namespace AgentCat\Internal\ServerInfo;

/**
 * Runs the customer's `redactSensitiveInformation` function on the text of a heartbeat: the title, the instructions,
 * and every string value of every list item. It leaves out what names things: the server name and version, and the
 * `name`, `uri` and `uriTemplate` at the top of a list item. It never changes a key.
 *
 * @internal
 */
final class TextRedaction
{
    /** The members at the top of a list item that name it: a tool call or a resource read is found by them. */
    private const array NAMES = ['name', 'uri', 'uriTemplate'];

    /**
     * @param \Closure(string): string $redactor
     * @param \Closure(): void         $check    runs before each call of `$redactor`; throws `Skipped` when the build has no time left
     *
     * @throws Skipped    when `$redactor` returns something that is no string, or `$check` stops the build
     * @throws \Throwable what `$redactor` throws
     */
    public static function apply(ServerInfoBody $body, \Closure $redactor, \Closure $check): ServerInfoBody
    {
        $text = static function (string $value) use ($redactor, $check): string {
            $check();
            $redacted = $redactor($value);
            // @phpstan-ignore function.alreadyNarrowedType (a customer callback can return any type at runtime)
            if (!is_string($redacted)) {
                throw new Skipped(sprintf('redactSensitiveInformation must return a string, got %s', get_debug_type($redacted)));
            }

            return $redacted;
        };
        $title = $text($body->title);
        $instructions = $text($body->instructions);
        $features = [];
        foreach ($body->features as $list => $items) {
            $features[$list] = array_map(static fn(mixed $item): mixed => self::walk($item, $text, self::NAMES), $items);
        }

        return $body->withText($title, $instructions, $features);
    }

    /**
     * A copy of a JSON tree with every string value redacted, except the members named in `$kept` of this level.
     *
     * @param \Closure(string): string $text
     * @param list<string>             $kept
     */
    private static function walk(mixed $value, \Closure $text, array $kept = []): mixed
    {
        if (is_string($value)) {
            return $text($value);
        }
        if (is_array($value)) {
            return array_map(static fn(mixed $item): mixed => self::walk($item, $text), $value);
        }
        if (!$value instanceof \stdClass) {
            return $value;
        }
        $copy = new \stdClass();
        foreach (get_object_vars($value) as $key => $member) {
            $copy->{$key} = in_array($key, $kept, true) ? $member : self::walk($member, $text);
        }

        return $copy;
    }
}
