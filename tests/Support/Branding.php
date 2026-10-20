<?php

declare(strict_types=1);

namespace AgentCat\Tests\Support;

/**
 * The parts of the README that the PHP SDK shares with the TypeScript SDK's README (D65), read the same way from both.
 * ReadmeTest compares the PHP README with the TypeScript README in `tests/Fixtures/branding`, and
 * `tests/bin/branding-drift.php` compares that copy with the TypeScript repository's main branch.
 *
 * A section's prose is its text outside fenced code blocks. Blocks are separated by one blank line, the soft line
 * breaks of a paragraph become spaces (Markdown renders them so), and an image URL of this repository's main branch
 * becomes the path that the TypeScript README writes.
 */
final class Branding
{
    /** Every image of the PHP README is this URL plus its path in the repository, so Packagist shows it too. */
    public const string IMAGE_BASE = 'https://raw.githubusercontent.com/agentcathq/agentcat-php-sdk/main/';

    /** The first line of the TypeScript README copy; the README follows it byte for byte. */
    public const string FIXTURE_HEADER = '<!-- agentcat-typescript-sdk 9b56e9e README.md, verbatim below this line -->';

    /** The shared sections under a heading of their own, by heading text. Getting Started shares its first paragraph only. */
    private const array HEADED = [
        'Why use AgentCat? 🤔',
        'How it works',
        'Getting Started',
        'Identifying users',
        'Redacting sensitive data',
        'Vendor Support',
        'Internal diagnostics',
        'Free for open source',
        'Community Cats 🐱',
    ];

    /** The section of which only the first paragraph is shared. */
    private const string FIRST_PARAGRAPH_ONLY = 'Getting Started';

    /** A line that starts a Markdown block of its own instead of continuing a paragraph. */
    private const string BLOCK_START = '/\A(?:[\s\-*+>|<#]|\d+\.)/';

    private const string CODE = 'code';

    private const string HEADING = 'heading';

    private const string QUOTE = 'quote';

    private const string TEXT = 'text';

    /**
     * The TypeScript README of a copy that starts with FIXTURE_HEADER.
     *
     * @throws \UnexpectedValueException when the file cannot be read or does not start with the header line
     */
    public static function typescriptReadme(string $path): string
    {
        $contents = is_file($path) ? file_get_contents($path) : false;
        if ($contents === false || !str_starts_with($contents, self::FIXTURE_HEADER . "\n")) {
            throw new \UnexpectedValueException(sprintf('%s must start with the line %s', $path, self::FIXTURE_HEADER));
        }

        return substr($contents, strlen(self::FIXTURE_HEADER) + 1);
    }

    /**
     * Each shared section's prose, by section name: `header` (the logo, the links and the badges), `note` (the NOTE
     * callout), `tagline` (the paragraphs after the callouts), `learn more` (the paragraph after the install command)
     * and the headed sections by their heading text.
     *
     * @return array<string, string|null> null for a section the README lacks
     */
    public static function sections(string $markdown): array
    {
        $blocks = self::blocks($markdown);
        $preamble = [];
        foreach ($blocks as $block) {
            if ($block['kind'] === self::HEADING) {
                break;
            }
            $preamble[] = $block;
        }
        $sections = self::preamble($preamble);
        foreach (self::HEADED as $title) {
            $sections[$title] = self::headed($blocks, $title);
        }

        return $sections;
    }

    /**
     * The bodies of the fenced code blocks whose info string is `$language`.
     *
     * @return list<string>
     */
    public static function codeBlocks(string $markdown, string $language): array
    {
        $bodies = [];
        foreach (self::blocks($markdown) as $block) {
            if ($block['kind'] === self::CODE && $block['info'] === $language) {
                $bodies[] = $block['text'];
            }
        }

        return $bodies;
    }

    /** The README without its fenced code blocks: what renders as text, links and images. */
    public static function withoutCode(string $markdown): string
    {
        $texts = [];
        foreach (self::blocks($markdown) as $block) {
            if ($block['kind'] !== self::CODE) {
                $texts[] = $block['text'];
            }
        }

        return implode("\n\n", $texts);
    }

    /**
     * The text of every heading, without its `#` marks.
     *
     * @return list<string>
     */
    public static function headings(string $markdown): array
    {
        $headings = [];
        foreach (self::blocks($markdown) as $block) {
            if ($block['kind'] === self::HEADING) {
                $headings[] = self::headingText($block['text']);
            }
        }

        return $headings;
    }

    /** GitHub's anchor for a heading: lower case, punctuation and emoji removed, each space a hyphen. */
    public static function slug(string $heading): string
    {
        $kept = preg_replace('/[^\p{L}\p{M}\p{N}\p{Pc}\- ]/u', '', mb_strtolower($heading));

        return str_replace(' ', '-', $kept ?? '');
    }

    /**
     * What changed on the TypeScript main branch since the copy: one line per shared section or image that differs,
     * by name.
     *
     * @param array<string, string> $images         path => git blob SHA-1 of the copied image
     * @param array<string, string> $upstreamImages path => git blob SHA-1 on the main branch
     *
     * @return list<string>
     */
    public static function drift(string $copy, string $upstream, array $images, array $upstreamImages): array
    {
        $changes = [];
        $now = self::sections($upstream);
        foreach (self::sections($copy) as $name => $prose) {
            $current = $now[$name] ?? null;
            if ($current === null) {
                $changes[] = sprintf('section "%s": not found on main', $name);
            } elseif ($current !== $prose) {
                $changes[] = sprintf('section "%s": %s', $name, self::firstDifference($prose ?? '', $current));
            }
        }
        foreach ($images as $path => $hash) {
            $current = $upstreamImages[$path] ?? null;
            if ($current === null) {
                $changes[] = sprintf('image %s: not found on main', $path);
            } elseif ($current !== $hash) {
                $changes[] = sprintf('image %s: blob %s on main, %s here', $path, $current, $hash);
            }
        }

        return $changes;
    }

    /**
     * The git blob SHA-1 of each file under `docs/static/` and `docs/cats/` in the JSON of GitHub's recursive tree API.
     *
     * @return array<string, string> path => blob SHA-1
     *
     * @throws \JsonException when the answer is not JSON
     * @throws \UnexpectedValueException when the answer holds no tree, or a truncated one
     */
    public static function treeHashes(string $json): array
    {
        $tree = json_decode($json, true, 512, JSON_THROW_ON_ERROR);
        $entries = is_array($tree) ? ($tree['tree'] ?? null) : null;
        if (!is_array($entries)) {
            throw new \UnexpectedValueException('the GitHub answer holds no tree');
        }
        if (($tree['truncated'] ?? false) !== false) {
            throw new \UnexpectedValueException('GitHub truncated the tree');
        }
        $hashes = [];
        foreach ($entries as $entry) {
            $path = is_array($entry) ? ($entry['path'] ?? null) : null;
            $sha = is_array($entry) ? ($entry['sha'] ?? null) : null;
            if (is_array($entry) && ($entry['type'] ?? null) === 'blob' && is_string($path) && is_string($sha) && preg_match('#\Adocs/(?:static|cats)/#', $path) === 1) {
                $hashes[$path] = $sha;
            }
        }

        return $hashes;
    }

    /** The first line where two texts differ, as `line N: "<before>" became "<after>"`; '' when they are equal. */
    public static function firstDifference(string $before, string $after): string
    {
        if ($before === $after) {
            return '';
        }
        $old = explode("\n", $before);
        $new = explode("\n", $after);
        $line = 0;
        while (($old[$line] ?? null) === ($new[$line] ?? null)) {
            ++$line;
        }

        return sprintf('line %d: "%s" became "%s"', $line + 1, $old[$line] ?? '(end)', $new[$line] ?? '(end)');
    }

    /**
     * The sections before the first heading.
     *
     * @param list<array{kind: string, text: string, info: string}> $blocks
     *
     * @return array<string, string|null>
     */
    private static function preamble(array $blocks): array
    {
        $firstOther = count($blocks);
        $lastQuote = -1;
        $firstCode = count($blocks);
        $note = null;
        foreach ($blocks as $index => $block) {
            if ($block['kind'] !== self::TEXT && $firstOther === count($blocks)) {
                $firstOther = $index;
            }
            if ($block['kind'] === self::QUOTE) {
                $lastQuote = $index;
                $note ??= str_starts_with($block['text'], '> [!NOTE]') ? $block['text'] : null;
            }
            if ($block['kind'] === self::CODE && $firstCode === count($blocks)) {
                $firstCode = $index;
            }
        }

        return [
            'header' => self::prose(array_slice($blocks, 0, $firstOther)),
            'note' => $note,
            'tagline' => self::prose(array_slice($blocks, $lastQuote + 1, max(0, $firstCode - $lastQuote - 1))),
            'learn more' => self::prose(array_slice($blocks, $firstCode + 1)),
        ];
    }

    /**
     * The section under the heading `$title`, its heading line first, up to the next heading.
     *
     * @param list<array{kind: string, text: string, info: string}> $blocks
     */
    private static function headed(array $blocks, string $title): ?string
    {
        foreach ($blocks as $index => $block) {
            if ($block['kind'] !== self::HEADING || self::headingText($block['text']) !== $title) {
                continue;
            }
            $section = [$block];
            foreach (array_slice($blocks, $index + 1) as $next) {
                if ($next['kind'] === self::HEADING) {
                    break;
                }
                if ($next['kind'] === self::CODE) {
                    continue;
                }
                $section[] = $next;
                if ($title === self::FIRST_PARAGRAPH_ONLY) {
                    break;
                }
            }

            return self::prose($section);
        }

        return null;
    }

    /** @param list<array{kind: string, text: string, info: string}> $blocks */
    private static function prose(array $blocks): ?string
    {
        $texts = [];
        foreach ($blocks as $block) {
            if ($block['kind'] !== self::CODE) {
                $texts[] = $block['text'];
            }
        }

        return $texts === [] ? null : str_replace('src="' . self::IMAGE_BASE, 'src="', implode("\n\n", $texts));
    }

    private static function headingText(string $line): string
    {
        return trim((string) preg_replace('/\A#{1,6}\s+/', '', $line));
    }

    /**
     * The Markdown blocks: fenced code (its body and info string), headings, block quotes and other text.
     *
     * @return list<array{kind: string, text: string, info: string}>
     */
    private static function blocks(string $markdown): array
    {
        $blocks = [];
        $lines = [];
        $fence = null;
        $info = '';
        $split = preg_split('/\r\n|\n|\r/', $markdown);
        foreach (is_array($split) ? $split : [] as $line) {
            if ($fence !== null) {
                if (preg_match('/\A {0,3}' . preg_quote($fence[0], '/') . '{' . strlen($fence) . ',}\s*\z/', $line) === 1) {
                    $blocks[] = ['kind' => self::CODE, 'text' => implode("\n", $lines), 'info' => $info];
                    $lines = [];
                    $fence = null;
                } else {
                    $lines[] = $line;
                }

                continue;
            }
            if (preg_match('/\A {0,3}(`{3,}|~{3,})\s*([^\s`]*)/', $line, $opening) === 1) {
                self::endParagraph($blocks, $lines);
                $fence = $opening[1];
                $info = $opening[2];

                continue;
            }
            if (trim($line) === '') {
                self::endParagraph($blocks, $lines);
            } elseif (preg_match('/\A#{1,6}\s/', $line) === 1) {
                self::endParagraph($blocks, $lines);
                $blocks[] = ['kind' => self::HEADING, 'text' => rtrim($line), 'info' => ''];
            } else {
                $lines[] = $line;
            }
        }
        if ($fence !== null) {
            $blocks[] = ['kind' => self::CODE, 'text' => implode("\n", $lines), 'info' => $info]; // an unclosed fence runs to the end
        } else {
            self::endParagraph($blocks, $lines);
        }

        return $blocks;
    }

    /**
     * Adds the lines read so far as a block quote or a text block, and starts the next block.
     *
     * @param list<array{kind: string, text: string, info: string}> $blocks
     * @param list<string> $lines
     */
    private static function endParagraph(array &$blocks, array &$lines): void
    {
        if ($lines === []) {
            return;
        }
        $blocks[] = ['kind' => str_starts_with(ltrim($lines[0]), '>') ? self::QUOTE : self::TEXT, 'text' => self::unwrap($lines), 'info' => ''];
        $lines = [];
    }

    /**
     * The lines of a block, each soft line break of a paragraph a space.
     *
     * @param non-empty-list<string> $lines
     */
    private static function unwrap(array $lines): string
    {
        $joined = [];
        foreach ($lines as $line) {
            $line = rtrim($line);
            $last = array_key_last($joined);
            if ($last !== null && preg_match(self::BLOCK_START, $line) !== 1) {
                $joined[$last] .= ' ' . $line;
            } else {
                $joined[] = $line;
            }
        }

        return implode("\n", $joined);
    }
}
