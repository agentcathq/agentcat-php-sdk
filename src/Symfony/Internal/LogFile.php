<?php

declare(strict_types=1);

namespace AgentCat\Symfony\Internal;

use AgentCat\Internal\Logging\FileLogger;
use Psr\Log\AbstractLogger;

/**
 * D61: the file of AgentCat's lines in the kernel's log directory. Symfony makes that directory only when something
 * writes there, and so does this logger: at a line, never before. A process that logs nothing (AgentCat off, an
 * excluded server) makes no directory.
 *
 * @internal
 */
final class LogFile extends AbstractLogger
{
    private readonly FileLogger $file;

    public function __construct(private readonly string $path)
    {
        $this->file = new FileLogger($path);
    }

    /** @param mixed[] $context */
    #[\Override]
    public function log($level, string|\Stringable $message, array $context = []): void
    {
        $directory = dirname($this->path);
        if (!is_dir($directory)) {
            @mkdir($directory, recursive: true); // 0777 less the umask, as Symfony makes its directories
        }
        $this->file->log($level, $message, $context);
    }
}
