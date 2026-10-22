<?php

declare(strict_types=1);

namespace PigFff;

use RuntimeException;

/**
 * High-performance, Git-aware file search engine (fffind).
 */
final class FastFind
{
    private const int DEFAULT_LIMIT = 200;

    public function __construct(
        private readonly string $cwd,
        private readonly GitStatusProvider $git,
    ) {
    }

    /**
     * @param array{
     *     pattern: string,
     *     path?: string,
     *     limit?: int
     * } $params
     * @return array{content: string, totalFiles: int, gitBoostedFiles: int}
     */
    public function find(array $params): array
    {
        $pattern = trim((string) ($params['pattern'] ?? ''));
        if ($pattern === '') {
            throw new \InvalidArgumentException('Parameter "pattern" must not be empty.');
        }

        $givenPath = (string) ($params['path'] ?? '.');
        $resolvedPath = $this->resolvePath($givenPath);

        if (!is_dir($resolvedPath)) {
            throw new RuntimeException("No such directory: {$givenPath}");
        }

        $limit = max(1, min(1000, (int) ($params['limit'] ?? self::DEFAULT_LIMIT)));

        $fd = $this->findFd();
        if ($fd !== null) {
            $files = $this->findWithFd($fd, $pattern, $resolvedPath, $limit * 2);
        } else {
            $files = $this->findWithPhp($pattern, $resolvedPath, $limit * 2);
        }

        return $this->rankAndFormat($files, $limit);
    }

    private function resolvePath(string $path): string
    {
        if (str_starts_with($path, '/')) {
            return $path;
        }
        return rtrim($this->cwd, '/') . '/' . ltrim($path, './');
    }

    private function findFd(): ?string
    {
        $which = trim((string) shell_exec('which fd 2>/dev/null'));
        if ($which !== '' && is_executable($which)) {
            return $which;
        }

        $candidates = [
            '/usr/local/bin/fd',
            '/opt/homebrew/bin/fd',
            '/usr/bin/fd',
            '/usr/bin/fdfind',
        ];

        foreach ($candidates as $candidate) {
            if (is_file($candidate) && is_executable($candidate)) {
                return $candidate;
            }
        }

        return null;
    }

    /**
     * @return list<string>
     */
    private function findWithFd(string $fd, string $pattern, string $dir, int $max): array
    {
        $cmd = [
            escapeshellarg($fd),
            '--color=never',
            '--hidden',
            '--no-require-git',
            '--max-results', (string) $max,
        ];

        if (str_contains($pattern, '/')) {
            $cmd[] = '--full-path';
        }

        $cmd[] = escapeshellarg($pattern);
        $cmd[] = escapeshellarg($dir);

        $output = shell_exec(implode(' ', $cmd) . ' 2>/dev/null');
        if ($output === null || trim($output) === '') {
            return [];
        }

        $lines = explode("\n", trim($output));
        $results = [];

        foreach ($lines as $line) {
            $line = trim($line);
            if ($line !== '') {
                $results[] = $this->toRelative($line);
            }
        }

        return $results;
    }

    /**
     * Fallback pure PHP file search.
     *
     * @return list<string>
     */
    private function findWithPhp(string $pattern, string $dir, int $max): array
    {
        $results = [];
        $isGlob = str_contains($pattern, '*') || str_contains($pattern, '?');
        $regex = $isGlob
            ? '#^' . str_replace(['\\*', '\\?'], ['.*', '.'], preg_quote($pattern, '#')) . '$#i'
            : null;

        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS)
        );

        /** @var \SplFileInfo $file */
        foreach ($iterator as $file) {
            $pathname = $file->getPathname();
            if (str_contains($pathname, '/.git/') || str_contains($pathname, '/vendor/') || str_contains($pathname, '/node_modules/')) {
                continue;
            }

            $rel = $this->toRelative($pathname);
            $filename = $file->getFilename();

            $matched = false;
            if ($regex !== null) {
                $matched = (bool) preg_match($regex, str_contains($pattern, '/') ? $rel : $filename);
            } else {
                $matched = stripos($filename, $pattern) !== false || stripos($rel, $pattern) !== false;
            }

            if ($matched) {
                $results[] = $rel;
                if (count($results) >= $max) {
                    break;
                }
            }
        }

        return $results;
    }

    private function toRelative(string $absPath): string
    {
        $prefix = rtrim($this->cwd, '/') . '/';
        if (str_starts_with($absPath, $prefix)) {
            return substr($absPath, strlen($prefix));
        }
        return $absPath;
    }

    /**
     * Rank results: Git-modified files placed at the top.
     *
     * @param list<string> $files
     * @return array{content: string, totalFiles: int, gitBoostedFiles: int}
     */
    private function rankAndFormat(array $files, int $limit): array
    {
        $this->git->refresh();

        $boosted = [];
        $regular = [];

        foreach ($files as $file) {
            $status = $this->git->statusOf($file);
            if ($status !== null) {
                $boosted[] = ['path' => $file, 'status' => $status];
            } else {
                $regular[] = ['path' => $file, 'status' => null];
            }
        }

        $all = array_merge($boosted, $regular);

        if ($all === []) {
            return [
                'content' => 'No files found matching pattern.',
                'totalFiles' => 0,
                'gitBoostedFiles' => 0,
            ];
        }

        $count = 0;
        $boostedCount = count($boosted);
        $lines = [];

        foreach ($all as $item) {
            if ($count >= $limit) {
                break;
            }
            $badge = $item['status'] !== null ? "  [git: {$item['status']}]" : '';
            $lines[] = $item['path'] . $badge;
            $count++;
        }

        $summary = "Found {$count} files";
        if ($boostedCount > 0) {
            $summary .= " (⚡ {$boostedCount} modified/untracked files boosted to top)";
        }
        $summary .= ":\n\n";

        return [
            'content' => $summary . implode("\n", $lines),
            'totalFiles' => $count,
            'gitBoostedFiles' => $boostedCount,
        ];
    }
}
