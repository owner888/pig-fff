<?php

declare(strict_types=1);

namespace PigFff;

require_once __DIR__ . '/GitStatusProvider.php';

/**
 * Git-aware workspace state inspector.
 *
 * Inspects modified, untracked, and staged files in real-time
 * to boost active code in search results.
 */
final class GitContext implements GitStatusProvider
{
    /**
     * Map of relative file paths to their git status (e.g. 'modified', 'untracked', 'staged').
     *
     * @var array<string, string>
     */
    private array $statusMap = [];

    private bool $isGitRepo = false;

    public function __construct(private readonly string $cwd)
    {
        $this->refresh();
    }

    /**
     * Refresh git status in real-time.
     */
    public function refresh(): void
    {
        $this->statusMap = [];
        $this->isGitRepo = false;

        if (!is_dir($this->cwd . '/.git') && !self::hasGitParent($this->cwd)) {
            return;
        }

        $cmd = 'git -C ' . escapeshellarg($this->cwd) . ' status --porcelain -uall 2>/dev/null';
        $output = shell_exec($cmd);

        if ($output === null || trim($output) === '') {
            $this->isGitRepo = true;
            return;
        }

        $this->isGitRepo = true;
        $lines = explode("\n", trim($output));

        foreach ($lines as $line) {
            if (strlen($line) < 4) {
                continue;
            }

            $x = $line[0];
            $y = $line[1];
            $file = trim(substr($line, 3));

            // Handle rename: "R  old -> new"
            if (str_contains($file, ' -> ')) {
                $parts = explode(' -> ', $file);
                $file = end($parts);
            }

            $file = trim($file, "\"' \t");

            $status = match (true) {
                $x === '?' && $y === '?' => 'untracked',
                $x === 'A' || $y === 'A' => 'added',
                $x === 'M' || $y === 'M' => 'modified',
                $x === 'D' || $y === 'D' => 'deleted',
                $x === 'R' || $y === 'R' => 'renamed',
                default => 'changed',
            };

            $this->statusMap[$file] = $status;
        }
    }

    /**
     * Check if a path or ancestor has .git.
     */
    private static function hasGitParent(string $dir): bool
    {
        $current = realpath($dir) ?: $dir;
        while ($current !== '/' && $current !== '' && dirname($current) !== $current) {
            if (is_dir($current . '/.git')) {
                return true;
            }
            $current = dirname($current);
        }
        return false;
    }

    public function isGitRepository(): bool
    {
        return $this->isGitRepo;
    }

    /**
     * Get the git status string if the file is modified/untracked, or null if untouched.
     */
    public function statusOf(string $relPath): ?string
    {
        $clean = ltrim($relPath, './');
        return $this->statusMap[$clean] ?? null;
    }

    /**
     * @return array<string, string>
     */
    public function allChanges(): array
    {
        return $this->statusMap;
    }
}
