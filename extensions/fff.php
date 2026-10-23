<?php

declare(strict_types=1);

use Pig\Agent\AgentToolResult;
use Pig\Ai\TextContent;
use Pig\Async\AbortSignal;
use Pig\CodingAgent\CustomTools\CustomTool;
use Pig\CodingAgent\Extensions\ExtensionApi;
use Pig\CodingAgent\Hooks\HookContext;
use PigFff\FastFind;
use PigFff\FastGrep;
use PigFff\FrecencyTracker;
use PigFff\GitContext;

require_once __DIR__ . '/GitStatusProvider.php';
require_once __DIR__ . '/GitContext.php';
require_once __DIR__ . '/FrecencyTracker.php';
require_once __DIR__ . '/FastGrep.php';
require_once __DIR__ . '/FastFind.php';

/**
 * Fast & Git-aware smart search extension for pig.
 *
 * Provides `ffgrep` and `fffind` with instant Git-state boosting and
 * input-method style Frecency (Frequency + Recency) ranking.
 */
return static function (ExtensionApi $pi): void {
    $cwd = getcwd() ?: '.';
    $git = new GitContext($cwd);
    $frecency = new FrecencyTracker(FrecencyTracker::defaultStorageFile(), $cwd);
    $grepEngine = new FastGrep($cwd, $git, $frecency);
    $findEngine = new FastFind($cwd, $git, $frecency);

    // 1. Tool: ffgrep (Content Search with Git-Aware Boosting)
    $pi->registerTool(new CustomTool(
        name: 'ffgrep',
        label: 'Fast Smart Grep',
        description: 'Search file contents with ripgrep and real-time Git-aware ranking. '
            . 'Matches in modified, staged, or untracked files are automatically boosted to the top with a [git: status] badge. '
            . 'Supports multi-pattern OR search via "patterns". Respects .gitignore.',
        parameters: [
            'type' => 'object',
            'properties' => [
                'pattern' => [
                    'type' => 'string',
                    'description' => 'The search pattern or regular expression',
                ],
                'patterns' => [
                    'type' => 'array',
                    'items' => ['type' => 'string'],
                    'description' => 'Optional list of multiple literal patterns to search with OR logic (like Aho-Corasick)',
                ],
                'path' => [
                    'type' => 'string',
                    'description' => 'Directory or file to search (defaults to current working directory)',
                ],
                'glob' => [
                    'type' => 'string',
                    'description' => 'Filter files by glob pattern, e.g. "*.php" or "!*Test.php"',
                ],
                'literal' => [
                    'type' => 'boolean',
                    'description' => 'Treat pattern as a literal string rather than regex (default true)',
                ],
                'ignoreCase' => [
                    'type' => 'boolean',
                    'description' => 'Case-insensitive search (default false, uses smart-case)',
                ],
                'context' => [
                    'type' => 'integer',
                    'description' => 'Lines of context to show around each match (default 0)',
                ],
                'limit' => [
                    'type' => 'integer',
                    'description' => 'Maximum matching lines to return (default 100, max 1000)',
                ],
            ],
        ],
        execute: static function (
            string $id,
            array $params,
            ?Closure $onUpdate,
            HookContext $ctx,
            ?AbortSignal $signal,
        ) use ($grepEngine): AgentToolResult {
            $signal?->throwIfAborted();

            try {
                $res = $grepEngine->search($params);
                return new AgentToolResult(
                    [new TextContent($res['content'])],
                    [
                        'totalMatches' => $res['totalMatches'],
                        'gitBoostedMatches' => $res['gitBoostedMatches'],
                    ],
                );
            } catch (\Throwable $e) {
                throw new \RuntimeException('ffgrep failed: ' . $e->getMessage(), 0, $e);
            }
        },
    ));

    // 2. Tool: fffind (File Path Search with Git-Aware Boosting)
    $pi->registerTool(new CustomTool(
        name: 'fffind',
        label: 'Fast Smart Find',
        description: 'Find files by name or glob pattern with real-time Git-aware ranking. '
            . 'Recently modified and untracked files are ranked at the top of results. Respects .gitignore.',
        parameters: [
            'type' => 'object',
            'properties' => [
                'pattern' => [
                    'type' => 'string',
                    'description' => 'Glob pattern or substring to match file names/paths',
                ],
                'path' => [
                    'type' => 'string',
                    'description' => 'Directory to search (defaults to current working directory)',
                ],
                'limit' => [
                    'type' => 'integer',
                    'description' => 'Maximum results to return (default 200, max 1000)',
                ],
            ],
            'required' => ['pattern'],
        ],
        execute: static function (
            string $id,
            array $params,
            ?Closure $onUpdate,
            HookContext $ctx,
            ?AbortSignal $signal,
        ) use ($findEngine): AgentToolResult {
            $signal?->throwIfAborted();

            try {
                $res = $findEngine->find($params);
                return new AgentToolResult(
                    [new TextContent($res['content'])],
                    [
                        'totalFiles' => $res['totalFiles'],
                        'gitBoostedFiles' => $res['gitBoostedFiles'],
                    ],
                );
            } catch (\Throwable $e) {
                throw new \RuntimeException('fffind failed: ' . $e->getMessage(), 0, $e);
            }
        },
    ));

    // 3. Command: /fff-status
    $pi->registerCommand(
        'fff-status',
        static function (string $args, HookContext $ctx) use ($git, $frecency): void {
            $git->refresh();
            $changes = $git->allChanges();
            $repo = $git->isGitRepository() ? 'Yes' : 'No';

            $whichRg = trim((string) shell_exec('which rg 2>/dev/null')) ?: 'not found (using PHP fallback)';
            $whichFd = trim((string) shell_exec('which fd 2>/dev/null')) ?: 'not found (using PHP fallback)';

            $info = "=== pig-fff Search Engine Status ===\n"
                . "Git Repository: {$repo}\n"
                . "Active Modified/Untracked Files: " . count($changes) . "\n"
                . "Ripgrep (rg): {$whichRg}\n"
                . "Fd: {$whichFd}\n"
                . "Frecency DB: " . FrecencyTracker::defaultStorageFile() . "\n";

            if ($changes !== []) {
                $info .= "\nTop Modified Files:\n";
                $i = 0;
                foreach ($changes as $f => $status) {
                    $info .= "  [{$status}] {$f}\n";
                    if (++$i >= 10) {
                        $info .= "  ... and " . (count($changes) - 10) . " more\n";
                        break;
                    }
                }
            }

            $topHot = $frecency->topEntries(5);
            if ($topHot !== []) {
                $info .= "\nTop Frecency Hot Files (Learned Candidate Ranking):\n";
                foreach ($topHot as $path => $data) {
                    $info .= "  [score: {$data['score']}, hits: {$data['count']}] {$path}\n";
                }
            }

            if ($ctx->hasUi) {
                $ctx->ui->notify($info, 'info');
            } else {
                echo $info, "\n";
            }
        },
        description: 'Display pig-fff search engine diagnostics, git boost status and frecency memory',
    );

    // 4. Hook: Auto-boost files directly read, edited, or written by the agent
    $pi->on('tool_result', static function ($event) use ($frecency, $cwd): void {
        if ($event->isError || !in_array($event->toolName, ['read', 'edit', 'write'], true)) {
            return;
        }

        $path = $event->input['path'] ?? null;
        if (!is_string($path) || $path === '') {
            return;
        }

        $clean = ltrim(str_replace(rtrim($cwd, '/') . '/', '', $path), './');
        if ($clean !== '' && FastGrep::noiseCategory($clean) === null) {
            // Edit & write get 5x boost, read gets 2x boost
            $weight = in_array($event->toolName, ['edit', 'write'], true) ? 5 : 2;
            $frecency->recordHit($clean, $weight);
            $frecency->save();
        }
    });
};
