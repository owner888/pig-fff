<?php

declare(strict_types=1);

namespace PigFff;

/**
 * IME-style Frecency (Frequency + Recency) tracking engine for files.
 *
 * Automatically learns which files the agent consults most often and
 * decays scores over time, mimicking modern input method candidate ranking.
 *
 * Scoped strictly by workspace directory (cwd) to prevent cross-project pollution.
 */
final class FrecencyTracker
{
    /**
     * Full multitenant store indexed by workspace root:
     * [scopePath => [relativePath => ['count' => int, 'last_time' => int]]]
     *
     * @var array<string, array<string, array{count: int, last_time: int}>>
     */
    private array $allScopes = [];

    /**
     * Active scope's records for fast lookup.
     *
     * @var array<string, array{count: int, last_time: int}>
     */
    private array $records = [];

    private readonly string $scopeKey;
    private bool $dirty = false;

    public function __construct(
        private readonly string $storageFile,
        string $cwd,
        private readonly int $maxEntriesPerScope = 300,
    ) {
        $this->scopeKey = realpath($cwd) ?: $cwd;
        $this->load();
    }

    /**
     * Default storage path located in ~/.pig/agent/fff-frecency.json.
     */
    public static function defaultStorageFile(): string
    {
        $home = getenv('HOME') ?: (getenv('USERPROFILE') ?: '');
        if ($home !== '') {
            $dir = rtrim($home, '/') . '/.pig/agent';
            if (!is_dir($dir)) {
                @mkdir($dir, 0755, true);
            }
            return $dir . '/fff-frecency.json';
        }
        return sys_get_temp_dir() . '/pig-fff-frecency.json';
    }

    public function getScope(): string
    {
        return $this->scopeKey;
    }

    /**
     * Record a hit (match opened or examined) for a given relative path.
     */
    public function recordHit(string $relPath): void
    {
        $clean = ltrim($relPath, './');
        if ($clean === '') {
            return;
        }

        $now = time();
        if (isset($this->records[$clean])) {
            $this->records[$clean]['count']++;
            $this->records[$clean]['last_time'] = $now;
        } else {
            $this->records[$clean] = [
                'count' => 1,
                'last_time' => $now,
            ];
        }

        $this->dirty = true;
    }

    /**
     * Compute current frecency score for a file.
     *
     * Higher score = higher frequency + more recent access.
     */
    public function scoreOf(string $relPath): float
    {
        $clean = ltrim($relPath, './');
        if (!isset($this->records[$clean])) {
            return 0.0;
        }

        $record = $this->records[$clean];
        $count = $record['count'];
        $lastTime = $record['last_time'];
        $now = time();

        $ageHours = max(0.0, ($now - $lastTime) / 3600.0);

        // Time decay factor: 1.0 at 0 hours, ~0.5 at 48 hours, ~0.2 at 7 days
        $decay = 1.0 / (1.0 + (0.02 * $ageHours));

        // Score formula: logarithmic frequency boost multiplied by time decay
        return (float) (log(1.0 + $count) * 100.0 * $decay);
    }

    /**
     * Persist frecency cache to disk if modified.
     */
    public function save(): void
    {
        if (!$this->dirty) {
            return;
        }

        // Prune active scope to limit
        if (count($this->records) > $this->maxEntriesPerScope) {
            uasort($this->records, function (array $a, array $b): int {
                $scoreA = $this->scoreOfRecord($a);
                $scoreB = $this->scoreOfRecord($b);
                return $scoreB <=> $scoreA;
            });
            $this->records = array_slice($this->records, 0, $this->maxEntriesPerScope, true);
        }

        $this->allScopes[$this->scopeKey] = $this->records;

        $dir = dirname($this->storageFile);
        if (!is_dir($dir)) {
            @mkdir($dir, 0755, true);
        }

        @file_put_contents(
            $this->storageFile,
            json_encode($this->allScopes, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES),
            LOCK_EX
        );

        $this->dirty = false;
    }

    private function load(): void
    {
        $this->records = [];
        $this->allScopes = [];

        if (!is_file($this->storageFile)) {
            return;
        }

        $raw = @file_get_contents($this->storageFile);
        if ($raw === false || $raw === '') {
            return;
        }

        $data = json_decode($raw, true);
        if (!is_array($data)) {
            return;
        }

        // Backward compatibility: If it's a legacy flat structure without scopes, wrap under root
        $isScoped = false;
        foreach ($data as $k => $v) {
            if (is_array($v) && (str_starts_with((string) $k, '/') || str_contains((string) $k, ':\\'))) {
                $isScoped = true;
                break;
            }
        }

        if ($isScoped) {
            /** @var array<string, array<string, array{count: int, last_time: int}>> $data */
            $this->allScopes = $data;
            if (isset($data[$this->scopeKey]) && is_array($data[$this->scopeKey])) {
                $this->records = $data[$this->scopeKey];
            }
        } else {
            // Legacy format migration
            $this->allScopes = [];
            $this->records = [];
        }
    }

    /**
     * @param array{count: int, last_time: int} $record
     */
    private function scoreOfRecord(array $record): float
    {
        $now = time();
        $ageHours = max(0.0, ($now - $record['last_time']) / 3600.0);
        $decay = 1.0 / (1.0 + (0.02 * $ageHours));
        return (float) (log(1.0 + $record['count']) * 100.0 * $decay);
    }

    /**
     * @return array<string, array{count: int, last_time: int, score: float}>
     */
    public function topEntries(int $limit = 10): array
    {
        $scored = [];
        foreach ($this->records as $path => $record) {
            $scored[$path] = [
                'count' => $record['count'],
                'last_time' => $record['last_time'],
                'score' => round($this->scoreOf($path), 2),
            ];
        }

        uasort($scored, static fn (array $a, array $b): int => $b['score'] <=> $a['score']);
        return array_slice($scored, 0, $limit, true);
    }
}
