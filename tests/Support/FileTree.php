<?php

declare(strict_types=1);

namespace AgentCat\Tests\Support;

/** Removes the directories that tests make under the test HOME: the bootstrap removes only plain files there (Ruling R8). */
final class FileTree
{
    /** Removes a file, a link, or a directory and everything in it. A path that does not exist, or `''`, is left alone. */
    public static function remove(string $path): void
    {
        if ($path === '' || (!is_dir($path) && !is_file($path))) {
            return;
        }
        if (is_file($path) || is_link($path)) {
            unlink($path);

            return;
        }
        @chmod($path, 0o700); // a directory made without permissions (for example by a mutant of a mkdir() mode) can be read
        $names = scandir($path);
        foreach ($names === false ? [] : $names as $name) {
            if ($name !== '.' && $name !== '..') {
                self::remove($path . '/' . $name);
            }
        }
        rmdir($path);
    }
}
