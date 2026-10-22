<?php

declare(strict_types=1);

namespace PigFff;

use RuntimeException;

/**
 * High-performance, Git-aware content search engine.
 */
final class FastGrep
{
    private const int DEFAULT_LIMIT = 100;
    private const int MAX_MATCH_CHARS = 300;
    private const int MAX_OUTPUT_BYTES = 50 * 1024; // 50KB

    public function __construct(
        private readonly string $cwd,
        private readonly GitStatusProvider $git,
    ) {
    }

    /**
     * @param array{
     *     pattern?: string,
     *     patterns?: list<string>,
     *     path?: string,
     *     glob?: string,
     *     literal?: bool,
     *     ignoreCase?: bool,
     *     context?: int,
     *     limit?: int
     * } $params
     * @return array{content: string, totalMatches: int, gitBoostedMatches: int}
     */
    public function search(array $params): array
    {
        $patterns = $this->resolvePatterns($params);
        if ($patterns === []) {
            throw new \InvalidArgumentException('Either "pattern" or "patterns" must be provided and non-empty.');
        }

        $givenPath = (string) ($params['path'] ?? '.');
        $resolvedPath = $this->resolvePath($givenPath);

        if (!file_exists($resolvedPath)) {
            throw new RuntimeException("No such file or directory: {$givenPath}");
        }

        $limit = max(1, min(1000, (int) ($params['limit'] ?? self::DEFAULT_LIMIT)));
        $context = max(0, min(10, (int) ($params['context'] ?? 0)));
        $literal = (bool) ($params['literal'] ?? true);
        $ignoreCase = (bool) ($params['ignoreCase'] ?? false);
        $glob = isset($params['glob']) && is_string($params['glob']) ? trim($params['glob']) : null;

        $rgPath = $this->findRipgrep();

        if ($rgPath !== null) {
            return $this->searchWithRipgrep($rgPath, $patterns, $resolvedPath, $glob, $literal, $ignoreCase, $context, $limit);
        }

        return $this->searchWithPhp($patterns, $resolvedPath, $glob, $literal, $ignoreCase, $context, $limit);
    }

    /**
     * @param array<string, mixed> $params
     * @return list<string>
     */
    private function resolvePatterns(array $params): array
    {
        $list = [];
        if (isset($params['pattern']) && is_string($params['pattern'])) {
            $p = trim($params['pattern']);
            if ($p !== '') {
                $list[] = $p;
            }
        }

        if (isset($params['patterns']) && is_array($params['patterns'])) {
            foreach ($params['patterns'] as $p) {
                if (is_string($p)) {
                    $p = trim($p);
                    if ($p !== '' && !in_array($p, $list, true)) {
                        $list[] = $p;
                    }
                }
            }
        }

        return $list;
    }

    private function resolvePath(string $path): string
    {
        if (str_starts_with($path, '/')) {
            return $path;
        }
        return rtrim($this->cwd, '/') . '/' . ltrim($path, './');
    }

    private function findRipgrep(): ?string
    {
        $which = trim((string) shell_exec('which rg 2>/dev/null'));
        if ($which !== '' && is_executable($which)) {
            return $which;
        }

        $candidates = [
            '/usr/local/bin/rg',
            '/opt/homebrew/bin/rg',
            '/usr/bin/rg',
        ];

        foreach ($candidates as $candidate) {
            if (is_file($candidate) && is_executable($candidate)) {
                return $candidate;
            }
        }

        return null;
    }

    /**
     * @param list<string> $patterns
     */
    private function searchWithRipgrep(
        string $rg,
        array $patterns,
        string $targetPath,
        ?string $glob,
        bool $literal,
        bool $ignoreCase,
        int $context,
        int $limit,
    ): array {
        $cmd = [escapeshellarg($rg), '--json', '--color=never'];

        if ($literal && count($patterns) === 1) {
            $cmd[] = '--fixed-strings';
        }

        if ($ignoreCase) {
            $cmd[] = '--ignore-case';
        } else {
            $cmd[] = '--smart-case';
        }

        if ($glob !== null && $glob !== '') {
            $cmd[] = '--glob ' . escapeshellarg($glob);
        }

        if ($context > 0) {
            $cmd[] = '-C ' . $context;
        }

        // Handle multi-pattern OR logic
        if (count($patterns) === 1) {
            $cmd[] = '-e ' . escapeshellarg($patterns[0]);
        } else {
            foreach ($patterns as $p) {
                $cmd[] = '-e ' . escapeshellarg($literal ? preg_quote($p, '/') : $p);
            }
        }

        $cmd[] = escapeshellarg($targetPath);
        $fullCmd = implode(' ', $cmd) . ' 2>/dev/null';

        $handle = popen($fullCmd, 'r');
        if (!$handle) {
            throw new RuntimeException("Failed to spawn ripgrep at {$rg}");
        }

        /** @var array<string, list<array{line: int, text: string, submatches?: list<array{start: int, end: int}>}>> $fileMatches */
        $fileMatches = [];
        $totalCount = 0;

        while (($raw = fgets($handle)) !== false) {
            $data = json_decode($raw, true);
            if (!is_array($data) || !isset($data['type'])) {
                continue;
            }

            if ($data['type'] === 'match') {
                $path = (string) ($data['data']['path']['text'] ?? '');
                $lineNum = (int) ($data['data']['line_number'] ?? 0);
                $lineText = (string) ($data['data']['lines']['text'] ?? '');

                if ($path !== '') {
                    $rel = $this->toRelative($path);
                    $fileMatches[$rel][] = [
                        'line' => $lineNum,
                        'text' => rtrim($lineText, "\r\n"),
                    ];
                    $totalCount++;

                    if ($totalCount >= $limit * 3) {
                        // Gather enough candidates for git-ranking
                        break;
                    }
                }
            }
        }

        pclose($handle);

        return $this->rankAndFormat($fileMatches, $limit);
    }

    /**
     * Fallback pure PHP search if rg is missing.
     *
     * @param list<string> $patterns
     */
    private function searchWithPhp(
        array $patterns,
        string $targetPath,
        ?string $glob,
        bool $literal,
        bool $ignoreCase,
        int $context,
        int $limit,
    ): array {
        $fileMatches = [];
        $totalCount = 0;

        $files = is_file($targetPath) ? [$targetPath] : $this->scanDirectory($targetPath, $glob);

        foreach ($files as $file) {
            if ($totalCount >= $limit * 2) {
                break;
            }

            $content = @file_get_contents($file);
            if ($content === false) {
                continue;
            }

            $lines = explode("\n", $content);
            $rel = $this->toRelative($file);

            foreach ($lines as $idx => $line) {
                $matched = false;
                foreach ($patterns as $pattern) {
                    if ($literal) {
                        $matched = $ignoreCase
                            ? (stripos($line, $pattern) !== false)
                            : (str_contains($line, $pattern));
                    } else {
                        $modifier = $ignoreCase ? 'iu' : 'u';
                        $matched = (bool) @preg_match('/' . str_replace('/', '\/', $pattern) . '/' . $modifier, $line);
                    }
                    if ($matched) {
                        break;
                    }
                }

                if ($matched) {
                    $fileMatches[$rel][] = [
                        'line' => $idx + 1,
                        'text' => rtrim($line, "\r\n"),
                    ];
                    $totalCount++;
                    if ($totalCount >= $limit * 2) {
                        break 2;
                    }
                }
            }
        }

        return $this->rankAndFormat($fileMatches, $limit);
    }

    /**
     * @return list<string>
     */
    private function scanDirectory(string $dir, ?string $glob): array
    {
        $result = [];
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS | \FilesystemIterator::FOLLOW_SYMLINKS)
        );

        $regex = $glob !== null
            ? '#^' . str_replace(['\\*', '\\?'], ['.*', '.'], preg_quote($glob, '#')) . '$#i'
            : null;

        /** @var \SplFileInfo $file */
        foreach ($iterator as $file) {
            if (!$file->isFile()) {
                continue;
            }
            $filename = $file->getFilename();
            if (str_starts_with($filename, '.')) {
                continue;
            }
            if ($regex !== null && !preg_match($regex, $filename)) {
                continue;
            }
            // Skip common vendor/build dirs
            $path = $file->getPathname();
            if (str_contains($path, '/vendor/') || str_contains($path, '/node_modules/') || str_contains($path, '/.git/')) {
                continue;
            }
            $result[] = $path;
            if (count($result) > 500) {
                break;
            }
        }

        return $result;
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
     * Rank results: Git-modified files placed at the top with status badges.
     *
     * @param array<string, list<array{line: int, text: string}>> $fileMatches
     * @return array{content: string, totalMatches: int, gitBoostedMatches: int}
     */
    private function rankAndFormat(array $fileMatches, int $limit): array
    {
        $this->git->refresh();

        // Separate boosted and regular files
        $boosted = [];
        $regular = [];

        foreach ($fileMatches as $file => $matches) {
            $status = $this->git->statusOf($file);
            if ($status !== null) {
                $boosted[$file] = ['status' => $status, 'matches' => $matches];
            } else {
                $regular[$file] = ['status' => null, 'matches' => $matches];
            }
        }

        $allFiles = array_merge($boosted, $regular);

        if ($allFiles === []) {
            return [
                'content' => 'No matches found.',
                'totalMatches' => 0,
                'gitBoostedMatches' => 0,
            ];
        }

        $output = '';
        $matchCount = 0;
        $boostedCount = 0;

        foreach ($allFiles as $file => $data) {
            if ($matchCount >= $limit) {
                break;
            }

            $badge = $data['status'] !== null ? " [git: {$data['status']}]" : '';
            $header = "{$file}{$badge}\n";
            $output .= $header;

            foreach ($data['matches'] as $m) {
                if ($matchCount >= $limit) {
                    break;
                }

                $text = $m['text'];
                if (mb_strlen($text) > self::MAX_MATCH_CHARS) {
                    $text = mb_substr($text, 0, self::MAX_MATCH_CHARS) . '…';
                }

                $output .= "  {$m['line']}: {$text}\n";
                $matchCount++;

                if ($data['status'] !== null) {
                    $boostedCount++;
                }

                if (strlen($output) >= self::MAX_OUTPUT_BYTES) {
                    $output .= "\n... [Search output truncated at " . round(self::MAX_OUTPUT_BYTES / 1024) . "KB]\n";
                    break 2;
                }
            }

            $output .= "\n";
        }

        $summary = "Found {$matchCount} matches across " . count($fileMatches) . " files";
        if ($boostedCount > 0) {
            $summary .= " (⚡ {$boostedCount} matches in modified/untracked files boosted to top)";
        }
        $summary .= ":\n\n";

        return [
            'content' => $summary . trim($output),
            'totalMatches' => $matchCount,
            'gitBoostedMatches' => $boostedCount,
        ];
    }
}
