<?php

declare(strict_types=1);

namespace PigFff;

/**
 * IME-style Frecency (Frequency + Recency) tracking engine for files.
 *
 * Automatically learns which files the agent consults most often and
 * decays scores over time, mimicking modern input method candidate ranking.
 */
final class FrecencyTracker
{
    /**
     * @var array<string, array{count: int, last_time: int}>
     */
    private array $records = [];

    private bool $dirty = false;

    public function __construct(
        private readonly string $storageFile,
        private readonly int $maxEntries = 500,
    ) {
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

        // Keep only top entries by score
        if (count($this->records) > $this->maxEntries) {
            uasort($this->records, function (array $a, array $b): int {
                $scoreA = $this->scoreOfRecord($a);
                $scoreB = $this->scoreOfRecord($b);
                return $scoreB <=> $scoreA;
            });
            $this->records = array_slice($this->records, 0, $this->maxEntries, true);
        }

        $dir = dirname($this->storageFile);
        if (!is_dir($dir)) {
            @mkdir($dir, 0755, true);
        }

        @file_put_contents(
            $this->storageFile,
            json_encode($this->records, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES),
            LOCK_EX
        );

        $this->dirty = false;
    }

    private function load(): void
    {
        if (!is_file($this->storageFile)) {
            return;
        }

        $raw = @file_get_contents($this->storageFile);
        if ($raw === false || $raw === '') {
            return;
        }

        $data = json_decode($raw, true);
        if (is_array($data)) {
            foreach ($data as $path => $item) {
                if (is_array($item) && isset($item['count'], $item['last_time'])) {
                    $this->records[(string) $path] = [
                        'count' => (int) $item['count'],
                        'last_time' => (int) $item['last_time'],
                    ];
                }
            }
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
