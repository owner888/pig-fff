<?php

declare(strict_types=1);

namespace PigFff;

use RuntimeException;

require_once __DIR__ . '/FastGrep.php';

/**
 * High-performance, Git-aware file search engine (fffind).
 */
final class FastFind
{
    private const int DEFAULT_LIMIT = 200;

    public function __construct(
        private readonly string $cwd,
        private readonly GitStatusProvider $git,
        private readonly ?FrecencyTracker $frecency = null,
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
            } elseif (self::matchesCamelCase($pattern, $filename)) {
                $results[] = $rel;
                if (count($results) >= $max) {
                    break;
                }
            }
        }

        return $results;
    }

    public static function matchesCamelCase(string $pattern, string $filename): bool
    {
        $stem = pathinfo($filename, PATHINFO_FILENAME);
        $words = preg_split('/(?=[A-Z])/', $stem, -1, PREG_SPLIT_NO_EMPTY);
        if ($words === false || count($words) < 2) {
            return false;
        }

        // 1. Pure acronym matching: e.g. "REM" matches "ResponseEnvelopeMiddleware"
        $acronym = '';
        foreach ($words as $w) {
            $acronym .= $w[0];
        }
        if (strcasecmp($pattern, $acronym) === 0) {
            return true;
        }

        // 2. Prefix acronym + suffix word matching: e.g. "ACHandler" or "REMiddleware"
        for ($i = 1; $i < count($words); $i++) {
            $prefixAcronym = '';
            for ($j = 0; $j < $i; $j++) {
                $prefixAcronym .= $words[$j][0];
            }
            $suffix = implode('', array_slice($words, $i));
            if (strcasecmp($pattern, $prefixAcronym . $suffix) === 0) {
                return true;
            }
        }

        return false;
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
     * Rank results: Git-modified files at top, followed by CamelCase matches, frecency hot files,
     * and sinking noise/lockfiles to the bottom.
     *
     * @param list<string> $files
     * @return array{content: string, totalFiles: int, gitBoostedFiles: int}
     */
    private function rankAndFormat(array $files, int $limit): array
    {
        $this->git->refresh();

        if ($files === []) {
            return [
                'content' => 'No files found matching pattern.',
                'totalFiles' => 0,
                'gitBoostedFiles' => 0,
            ];
        }

        /** @var array<string, array{path: string, status: ?string, noise: ?string, frecency: float, score: float}> $scored */
        $scored = [];

        foreach ($files as $file) {
            $status = $this->git->statusOf($file);
            $frecencyScore = $this->frecency?->scoreOf($file) ?? 0.0;
            $noise = FastGrep::noiseCategory($file);

            // Tier 1: Git-modified files (+10,000)
            $gitBonus = $status !== null ? 10000.0 : 0.0;

            // Tier 2: Frecency score
            // Tier 3: Shallow paths
            $depth = substr_count($file, '/');
            $depthBonus = max(0.0, (10.0 - $depth) * 0.5);

            // Tier 4: Noise penalty (-5,000)
            $noisePenalty = $noise !== null ? -5000.0 : 0.0;

            $compositeScore = $gitBonus + $frecencyScore + $depthBonus + $noisePenalty;

            $scored[$file] = [
                'path' => $file,
                'status' => $status,
                'noise' => $noise,
                'frecency' => $frecencyScore,
                'score' => $compositeScore,
            ];
        }

        uasort($scored, static fn (array $a, array $b): int => $b['score'] <=> $a['score']);

        $count = 0;
        $boostedCount = 0;
        $lines = [];
        $hitFiles = [];

        foreach ($scored as $item) {
            if ($count >= $limit) {
                break;
            }

            $badges = [];
            if ($item['status'] !== null) {
                $badges[] = "git: {$item['status']}";
                $boostedCount++;
            } elseif ($item['noise'] !== null) {
                $badges[] = "noise: {$item['noise']}";
            } elseif ($item['frecency'] >= 20.0) {
                $badges[] = 'hot';
            }

            $badgeStr = $badges !== [] ? '  [' . implode(', ', $badges) . ']' : '';
            $lines[] = $item['path'] . $badgeStr;
            $hitFiles[] = $item['path'];
            $count++;
        }

        if ($this->frecency !== null) {
            foreach ($hitFiles as $f) {
                if (FastGrep::noiseCategory($f) === null) {
                    $this->frecency->recordHit($f);
                }
            }
            $this->frecency->save();
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
