<?php

declare(strict_types=1);

namespace AgentCat\Tests\Architecture;

use AgentCat\Tests\Support\Branding;
use AgentCat\Tests\Support\FileTree;
use AgentCat\Tests\Support\Thrown;
use Ergebnis\PHPUnit\SlowTestDetector\Attribute\MaximumDuration;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Process\Process;

/**
 * The README carries the TypeScript README's branding (D65). Its shared sections are the TypeScript sections word for
 * word, except the sentences that `tests/Fixtures/branding/exceptions.php` replaces, each with its reason. Its images
 * are the TypeScript images, byte for byte, and every link and image also works where Packagist shows the README.
 */
final class ReadmeTest extends TestCase
{
    private const string FIXTURES = __DIR__ . '/../Fixtures/branding';

    /** The pages of the PHP SDK on docs.agentcat.com. */
    private const array DOCS_PAGES = [
        'https://docs.agentcat.com/quickstart',
        'https://docs.agentcat.com/sdk/php/mcp-sdk',
        'https://docs.agentcat.com/sdk/php/laravel',
        'https://docs.agentcat.com/sdk/php/symfony',
        'https://docs.agentcat.com/sdk/php/runtimes',
        'https://docs.agentcat.com/sdk/php/limits',
    ];

    private const string DOCS_ROOT = 'https://docs.agentcat.com';

    /** The badges of the header, the only images that are not files of this repository. */
    private const array BADGE_HOSTS = ['https://img.shields.io/', 'https://github.com/agentcathq/agentcat-php-sdk/actions/workflows/ci.yml/badge.svg'];

    /** Every `src` and `href` value, every Markdown link or image target, and every reference definition (`[ref]: target`). */
    private const string TARGETS = '/(?:src|href)="([^"]*)"|\]\(([^)\s]*)|^ {0,3}\[[^\]]+\]:[ \t]*<?([^\s>]+)/m';

    /** Every HTML or Markdown image URL. */
    private const string IMAGES = '/<img\b[^>]*\bsrc="([^"]*)"|!\[[^\]]*\]\(([^)\s]*)/';

    private const float LINT_SECONDS = 30.0;

    public function testTheSharedSectionsMatchTheTypeScriptReadme(): void
    {
        $typescript = Branding::sections($this->typescriptReadme());
        $php = Branding::sections($this->readme());
        $exceptions = $this->exceptions();
        $this->assertSame([], array_values(array_diff(array_keys($exceptions), array_keys($typescript))), 'every exception names a shared section');

        foreach ($typescript as $name => $prose) {
            $this->assertNotNull($prose, sprintf('the TypeScript README has the section "%s"', $name));
            $expected = $prose;
            foreach ($exceptions[$name] ?? [] as $exception) {
                $this->assertSame(1, substr_count($expected, $exception['typescript']), sprintf('section "%s": the TypeScript README has this text once: %s', $name, $exception['typescript']));
                $expected = str_replace($exception['typescript'], $exception['php'] ?? '', $expected);
            }
            $expected = trim((string) preg_replace("/\n{3,}/", "\n\n", $expected));
            $actual = $php[$name] ?? null;
            $this->assertNotNull($actual, sprintf('the README has the section "%s"', $name));
            $this->assertSame($expected, $actual, sprintf('section "%s" differs from the TypeScript README with exceptions.php, %s', $name, Branding::firstDifference($expected, $actual)));
        }
    }

    public function testTheOutlineIsTheTypeScriptOutline(): void
    {
        $typescript = $this->typescriptReadme();
        $readme = $this->readme();

        $this->assertSame(['text', 'quote', 'quote', 'text', 'text', 'code', 'text'], $this->preambleKinds($typescript), 'TypeScript: the header, the NOTE, the MCPcat box, the tagline, the install command, learn more');
        // D33: no notice at the top. The NOTE is the README's only callout before the first heading.
        $this->assertSame(['text', 'quote', 'text', 'text', 'code', 'text'], $this->preambleKinds($readme), 'the header, the NOTE, the tagline, the install command, learn more');
        $this->assertCount(9, Branding::sharedHeadings($typescript), 'TypeScript has every shared heading once');
        $this->assertSame(Branding::sharedHeadings($typescript), Branding::sharedHeadings($readme), 'the shared sections come once each, in the TypeScript order and at its heading levels');
    }

    public function testEveryExceptionQuotesTypeScriptAndStatesItsReason(): void
    {
        $count = 0;
        foreach ($this->exceptions() as $name => $entries) {
            $this->assertNotSame([], $entries, $name . ' lists its replaced sentences');
            foreach ($entries as $entry) {
                $this->assertNotSame('', trim($entry['typescript']), $name . ': the TypeScript text');
                $this->assertNotSame($entry['typescript'], $entry['php'], $name . ': a replacement changes the text');
                $this->assertNotSame('', trim($entry['reason']), $name . ': the reason');
                $this->assertStringNotContainsString("\n", $entry['reason'] . $entry['typescript'] . ($entry['php'] ?? ''), $name . ': one line each');
                ++$count;
            }
        }
        $this->assertGreaterThan(0, $count);
    }

    public function testTheImagesAreTheTypeScriptImages(): void
    {
        $images = $this->images();
        $this->assertNotSame([], $images);
        foreach ($images as $path => $hash) {
            $file = dirname(__DIR__, 2) . '/' . $path;
            $this->assertFileExists($file);
            $contents = file_get_contents($file);
            $this->assertIsString($contents);
            $this->assertSame($hash, sha1('blob ' . strlen($contents) . "\0" . $contents), $path . ' is the TypeScript image byte for byte');
        }
        $attributes = file(dirname(__DIR__, 2) . '/.gitattributes', FILE_IGNORE_NEW_LINES);
        $this->assertIsArray($attributes);
        $this->assertContains('/docs export-ignore', array_map(trim(...), $attributes), 'the images stay out of the Composer package');
    }

    public function testEveryLinkAndImageIsAbsolute(): void
    {
        $text = Branding::withoutCode($this->readme());
        $targets = $this->captures(self::TARGETS, $text);
        $this->assertNotSame([], $targets);
        foreach ($targets as $target) {
            $this->assertMatchesRegularExpression('/\A(?:https:\/\/|mailto:|#)/', $target, 'a link or image outside GitHub needs an absolute URL');
        }

        $used = [];
        foreach ($this->captures(self::IMAGES, $text) as $url) {
            if ($this->isBadge($url)) {
                continue;
            }
            $this->assertStringStartsWith(Branding::IMAGE_BASE . 'docs/', $url, 'an image of the README is a file of this repository on main');
            $used[] = substr($url, strlen(Branding::IMAGE_BASE));
        }
        $expected = array_keys($this->images());
        sort($expected);
        $used = array_values(array_unique($used));
        sort($used);
        $this->assertSame($expected, $used, 'the README shows the copied TypeScript images, and only them');
    }

    public function testEveryAnchorExists(): void
    {
        foreach (['README.md' => $this->readme(), 'the TypeScript README' => $this->typescriptReadme()] as $name => $markdown) {
            $slugs = array_map(Branding::slug(...), Branding::headings($markdown));
            $anchors = array_values(array_filter($this->captures(self::TARGETS, Branding::withoutCode($markdown)), static fn(string $target): bool => str_starts_with($target, '#')));
            $this->assertNotSame([], $anchors, $name . ' links its own headings');
            foreach ($anchors as $anchor) {
                $this->assertContains(substr($anchor, 1), $slugs, sprintf('%s: %s names a heading', $name, $anchor));
            }
        }
    }

    #[MaximumDuration(5000)] // one php -l process for every sample
    public function testEveryPhpSampleIsValid(): void
    {
        $samples = Branding::codeBlocks($this->readme(), 'php');
        $this->assertNotSame([], $samples);
        $home = getenv('HOME');
        $this->assertIsString($home);
        $directory = $home . '/readme-samples';
        FileTree::remove($directory);
        $this->assertTrue(mkdir($directory, 0o700));

        try {
            $files = [];
            foreach ($samples as $index => $sample) {
                $file = sprintf('%s/sample-%d.php', $directory, $index + 1);
                $this->assertNotFalse(file_put_contents($file, (str_starts_with($sample, '<?php') ? '' : "<?php\n") . $sample . "\n"));
                $files[] = $file;
            }
            $lint = new Process([PHP_BINARY, '-l', ...$files], timeout: self::LINT_SECONDS);
            $lint->run();
            $output = $lint->getOutput() . $lint->getErrorOutput();
            $this->assertSame(0, $lint->getExitCode(), $output);
            $this->assertSame(count($files), substr_count($output, 'No syntax errors detected'), $output);
        } finally {
            FileTree::remove($directory);
        }
    }

    public function testTheDocsLinksAreTheFinalUrls(): void
    {
        $links = $this->docsLinks($this->readme());
        $allowed = [...self::DOCS_PAGES, self::DOCS_ROOT, ...$this->docsLinks($this->typescriptReadme())];
        foreach ($links as $link) {
            $this->assertContains($link, $allowed, 'a docs link is a final URL of the PHP pages, the site root, or a link of the TypeScript README');
        }
        foreach (self::DOCS_PAGES as $page) {
            $this->assertContains($page, $links, 'the README links ' . $page);
        }
    }

    public function testTheDriftCheckNamesEachChangedSectionAndImage(): void
    {
        $copy = $this->typescriptReadme();
        $images = $this->images();
        $this->assertSame([], Branding::drift($copy, $copy, $images, $images), 'no change, nothing named');

        $upstream = str_replace(
            ['Concretely, AgentCat adds the following to your server:', "## Free for open source\n"],
            ['AgentCat adds the following to your server:', "## Free for open-source projects\n"],
            $copy,
        );
        $upstreamImages = [...$images, 'docs/cats/void.jpg' => str_repeat('0', 40)];
        unset($upstreamImages['docs/cats/bibi.png']);
        $changes = Branding::drift($copy, $upstream, $images, $upstreamImages);

        $this->assertCount(5, $changes, implode("\n", $changes));
        $this->assertSame('outline (the blocks before the first heading, then the headings): line 15: "## Free for open source" became "## Free for open-source projects"', $changes[0]);
        $this->assertStringStartsWith('section "How it works": line 3: "AgentCat works as a lightweight middleware', $changes[1]);
        $this->assertStringContainsString('" became "AgentCat works as a lightweight middleware', $changes[1]);
        $this->assertSame('section "Free for open source": not found on main', $changes[2]);
        $this->assertSame('image docs/cats/bibi.png: not found on main', $changes[3]);
        $this->assertSame('image docs/cats/void.jpg: blob ' . str_repeat('0', 40) . ' on main, 11486d1ffb74367d5a135272ee0468bb85cad40b here', $changes[4]);

        $callout = str_replace("\n\nAgentCat is an analytics platform", "\n\n> [!WARNING]\n> A new callout.\n\nAgentCat is an analytics platform", $copy);
        $this->assertSame(['outline (the blocks before the first heading, then the headings): line 4: "text" became "quote"'], Branding::drift($copy, $callout, $images, $images), 'a callout that changes no shared section still shows');
    }

    public function testTheTreeHashesAreTheBlobsUnderTheImageDirectories(): void
    {
        $tree = json_encode(['sha' => 'f00', 'truncated' => false, 'tree' => [
            ['path' => 'README.md', 'type' => 'blob', 'sha' => 'aaa'],
            ['path' => 'docs/cats', 'type' => 'tree', 'sha' => 'bbb'],
            ['path' => 'docs/cats/bibi.png', 'type' => 'blob', 'sha' => 'ccc'],
            ['path' => 'docs/static/og-image.png', 'type' => 'blob', 'sha' => 'ddd'],
            ['path' => 'docs/staticfile.png', 'type' => 'blob', 'sha' => 'eee'],
        ]], JSON_THROW_ON_ERROR);

        $this->assertSame(['docs/cats/bibi.png' => 'ccc', 'docs/static/og-image.png' => 'ddd'], Branding::treeHashes($tree));
        $truncated = Thrown::by(\UnexpectedValueException::class, static fn(): array => Branding::treeHashes('{"truncated":true,"tree":[]}'));
        $this->assertSame('GitHub truncated the tree', $truncated->getMessage());
        $empty = Thrown::by(\UnexpectedValueException::class, static fn(): array => Branding::treeHashes('{"message":"Not Found"}'));
        $this->assertSame('the GitHub answer holds no tree', $empty->getMessage());
    }

    private function readme(): string
    {
        $readme = file_get_contents(dirname(__DIR__, 2) . '/README.md');
        $this->assertIsString($readme);

        return $readme;
    }

    private function typescriptReadme(): string
    {
        return Branding::typescriptReadme(self::FIXTURES . '/typescript-readme.md');
    }

    /** @return array<string, string> path => git blob SHA-1 */
    private function images(): array
    {
        $images = require self::FIXTURES . '/images.php';
        $this->assertIsArray($images);
        $typed = [];
        foreach ($images as $path => $hash) {
            $this->assertIsString($path);
            $this->assertIsString($hash);
            $typed[$path] = $hash;
        }

        return $typed;
    }

    /** @return array<string, list<array{typescript: string, php: string|null, reason: string}>> */
    private function exceptions(): array
    {
        $exceptions = require self::FIXTURES . '/exceptions.php';
        $this->assertIsArray($exceptions);
        $typed = [];
        foreach ($exceptions as $name => $entries) {
            $this->assertIsString($name);
            $this->assertIsArray($entries);
            $typed[$name] = [];
            foreach ($entries as $entry) {
                $this->assertIsArray($entry);
                $this->assertSame(['typescript', 'php', 'reason'], array_keys($entry), $name . ': each entry has typescript, php and reason');
                [$typescript, $php, $reason] = [$entry['typescript'] ?? null, $entry['php'] ?? null, $entry['reason'] ?? null];
                $this->assertIsString($typescript);
                $this->assertIsString($reason);
                if ($php !== null) {
                    $this->assertIsString($php);
                }
                $typed[$name][] = ['typescript' => $typescript, 'php' => $php, 'reason' => $reason];
            }
        }

        return $typed;
    }

    /** @return list<string> the first non-empty capture group of each match */
    private function captures(string $pattern, string $text): array
    {
        preg_match_all($pattern, $text, $matches, PREG_SET_ORDER);
        $found = [];
        foreach ($matches as $match) {
            $groups = array_values(array_filter(array_slice($match, 1), static fn(string $group): bool => $group !== ''));
            $found[] = $groups[0] ?? '';
        }

        return $found;
    }

    /** @return list<string> */
    private function docsLinks(string $markdown): array
    {
        return array_values(array_filter($this->captures(self::TARGETS, Branding::withoutCode($markdown)), static fn(string $target): bool => preg_match('#\Ahttps?://docs\.agentcat\.com(?:/|\z)#', $target) === 1));
    }

    /** @return list<string> the kind of each block before the first heading */
    private function preambleKinds(string $markdown): array
    {
        $kinds = [];
        foreach (Branding::outline($markdown) as $entry) {
            if (str_starts_with($entry, '#')) {
                break;
            }
            $kinds[] = $entry;
        }

        return $kinds;
    }

    private function isBadge(string $url): bool
    {
        foreach (self::BADGE_HOSTS as $prefix) {
            if (str_starts_with($url, $prefix)) {
                return true;
            }
        }

        return false;
    }
}
