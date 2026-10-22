<?php

declare(strict_types=1);

namespace PigFff;

/**
 * Contract for inspecting Git changes in the workspace.
 */
interface GitStatusProvider
{
    public function isGitRepository(): bool;

    public function statusOf(string $relPath): ?string;

    /**
     * @return array<string, string>
     */
    public function allChanges(): array;

    public function refresh(): void;
}
