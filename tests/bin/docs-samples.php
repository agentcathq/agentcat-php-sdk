<?php

declare(strict_types=1);

// Usage: php tests/bin/docs-samples.php <docs directory>
//
// Checks the PHP samples of a docs checkout, for example the apps/docs directory of the Mintlify site behind
// docs.agentcat.com. It extracts every fenced code block whose language is php from the .mdx and .md files under the
// directory into .cache/docs-samples/, runs php -l on each sample, and then runs PHPStan at level 6 over all of them
// with this repository's autoloader. Not part of CI: run it before a docs change goes out.
//
// A sample that starts with <?php is a whole file. Any other sample is a fragment, and the checker adds the opening tag
// and declare(strict_types=1) before it. Unless the fragment declares a namespace, the checker also adds
// `$builder = Mcp\Server::builder();`: the fragments track that Builder. PHPStan errors about classes that it cannot
// find in the App\ namespace are ignored: those classes belong to the reader's own application. Tests\TestCase, the base
// test case of every Laravel application, is declared for PHPStan as Laravel's skeleton declares it.
//
// Exits 0 when every sample passes both checks, 1 when a sample fails one of them, and 2 on a usage or setup error.

const OUTPUT = '.cache/docs-samples';
const SAMPLES = OUTPUT . '/samples';
const STUBS = OUTPUT . '/stubs.php';
const STUB_CLASSES = <<<'PHP'
    <?php

    declare(strict_types=1);

    namespace Tests;

    // The base test case of a Laravel application, as Laravel's application skeleton declares it.
    abstract class TestCase extends \Illuminate\Foundation\Testing\TestCase {}

    PHP;
const FRAGMENT_PRELUDE = "<?php\n\ndeclare(strict_types=1);\n\n";
const BUILDER_PRELUDE = "\$builder = \\Mcp\\Server::builder(); // the Builder that the docs fragments track\n\n";

/** Prints the message to STDERR and ends with the exit code of a usage or setup error. */
function setupError(string $message): never
{
    fwrite(STDERR, $message . "\n");

    exit(2);
}

/**
 * Runs a command in the repository and returns its exit code and its output, STDERR included.
 *
 * @param list<string> $command
 *
 * @return array{int, string}
 */
function run(array $command): array
{
    $output = [];
    $code = 1;
    exec(implode(' ', array_map(escapeshellarg(...), $command)) . ' 2>&1 </dev/null', $output, $code);

    return [$code, implode("\n", $output)];
}

/**
 * The fenced code blocks of a Markdown page whose language (the first word after the fence) is php, by the line of
 * their opening fence. A fence may be indented, as inside MDX components; the content loses the fence's indentation.
 *
 * @return array<int, string>
 */
function phpBlocks(string $markdown): array
{
    $blocks = [];
    $open = null;
    $lines = [];
    $markdownLines = preg_split('/\R/', $markdown);
    foreach ($markdownLines === false ? [] : $markdownLines as $index => $line) {
        if ($open === null) {
            if (preg_match('/^(\s*)(`{3,}|~{3,})\s*(\S*)/', $line, $fence) === 1) {
                $open = ['line' => $index + 1, 'indent' => strlen($fence[1]), 'fence' => $fence[2], 'php' => strtolower($fence[3]) === 'php'];
                $lines = [];
            }

            continue;
        }
        if (preg_match('/^\s*' . preg_quote($open['fence'][0], '/') . '{' . strlen($open['fence']) . ',}\s*$/', $line) === 1) {
            if ($open['php']) {
                $blocks[$open['line']] = implode("\n", $lines) . "\n";
            }
            $open = null;

            continue;
        }
        $lines[] = preg_replace('/^\s{0,' . $open['indent'] . '}/', '', $line) ?? $line;
    }

    return $blocks;
}

/** A sample as a PHP file: a whole file stays as it is; a fragment gets the opening tag and, without a namespace, the Builder. */
function asFile(string $sample): string
{
    if (str_starts_with(ltrim($sample), '<?php')) {
        return $sample;
    }

    return FRAGMENT_PRELUDE . (preg_match('/^namespace\s/m', $sample) === 1 ? '' : BUILDER_PRELUDE) . $sample;
}

/** The PHPStan configuration of the check: level 6, the samples only, none of the repository's own stricter settings. */
function phpstanConfiguration(): string
{
    return <<<NEON
        parameters:
            level: 6
            paths:
                - '%currentWorkingDirectory%/.cache/docs-samples/samples'
            scanFiles:
                - '%currentWorkingDirectory%/.cache/docs-samples/stubs.php'
            tmpDir: '%currentWorkingDirectory%/.cache/phpstan-docs-samples'
            reportUnmatchedIgnoredErrors: false
            strictRules:
                allRules: false
            type_coverage:
                return: 0
                param: 0
                property: 0
                constant: 0
            noEnvCallsOutsideOfConfig: false
            ignoreErrors:
                -
                    identifier: class.notFound
                    message: '#\\bApp\\\\#'
        NEON;
}

// $_SERVER, not $argv: PHPStan on PHP 8.5 cannot prove that the global $argv exists.
$serverArgv = $_SERVER['argv'] ?? [];
$arguments = array_values(array_filter(array_slice(is_array($serverArgv) ? $serverArgv : [], 1), is_string(...)));
if (count($arguments) !== 1) {
    setupError('usage: php tests/bin/docs-samples.php <docs directory>');
}
$docs = realpath($arguments[0]);
if ($docs === false || !is_dir($docs)) {
    setupError('not a directory: ' . $arguments[0]);
}
// Every path below is relative to the repository, the working directory of PHPStan and of php -l too.
if (!chdir(dirname(__DIR__, 2))) {
    setupError('cannot change to the repository directory');
}

if (!is_dir(SAMPLES) && !mkdir(SAMPLES, 0o777, true) && !is_dir(SAMPLES)) {
    setupError('cannot create ' . SAMPLES);
}
$stale = glob(SAMPLES . '/*.php');
foreach ($stale === false ? [] : $stale as $staleFile) {
    unlink($staleFile);
}

$pages = [];
$files = new RecursiveIteratorIterator(new RecursiveCallbackFilterIterator(
    new RecursiveDirectoryIterator($docs, FilesystemIterator::SKIP_DOTS),
    static fn(SplFileInfo $file): bool => $file->isDir() ? !in_array($file->getFilename(), ['node_modules', '.git', '.mintlify'], true) : in_array($file->getExtension(), ['md', 'mdx'], true),
));
foreach ($files as $file) {
    if ($file instanceof SplFileInfo) {
        $pages[] = substr($file->getPathname(), strlen($docs) + 1);
    }
}
sort($pages);

/** @var array<string, string> $samples sample file name => page:line */
$samples = [];
foreach ($pages as $page) {
    $markdown = file_get_contents($docs . '/' . $page);
    if ($markdown === false) {
        setupError('cannot read ' . $page);
    }
    foreach (phpBlocks($markdown) as $line => $sample) {
        $name = str_replace(['/', '.'], ['__', '_'], $page) . '__L' . $line . '.php';
        if (file_put_contents(SAMPLES . '/' . $name, asFile($sample)) === false) {
            setupError('cannot write ' . $name);
        }
        $samples[$name] = $page . ':' . $line;
    }
}
if ($samples === []) {
    fwrite(STDERR, 'no php samples under ' . $docs . "\n");

    exit(1);
}

$lintFailures = 0;
foreach ($samples as $name => $where) {
    [$code, $output] = run([PHP_BINARY, '-l', SAMPLES . '/' . $name]);
    if ($code !== 0) {
        ++$lintFailures;
        echo $where . ': php -l failed' . "\n" . $output . "\n";
    }
}

$configuration = OUTPUT . '/phpstan.neon';
if (file_put_contents($configuration, phpstanConfiguration() . "\n") === false || file_put_contents(STUBS, STUB_CLASSES) === false) {
    setupError('cannot write the PHPStan configuration');
}
[$phpstanCode, $phpstanOutput] = run([PHP_BINARY, 'vendor/bin/phpstan', 'analyse', '--no-progress', '--memory-limit=1G', '--configuration=' . $configuration]);
if ($phpstanCode !== 0) {
    echo $phpstanOutput . "\n";
}

$pageCount = count(array_unique(array_map(static fn(string $where): string => explode(':', $where)[0], $samples)));
printf(
    "docs samples: %d php blocks from %d pages in %s\nphp -l: %s\nPHPStan level 6: %s\n",
    count($samples),
    $pageCount,
    SAMPLES,
    $lintFailures === 0 ? 'OK' : $lintFailures . ' failed',
    $phpstanCode === 0 ? 'OK' : 'failed (exit ' . $phpstanCode . ')',
);

exit($lintFailures === 0 && $phpstanCode === 0 ? 0 : 1);
