# pig-fff

Fast, Git-aware smart search extension for [pig](https://pigagent.dev) — inspired by `@ff-labs/pi-fff`.

Provides high-performance `ffgrep` and `fffind` tools that prioritize active, modified, and untracked files right at the top of search results, slashing LLM token consumption and eliminating stale cache issues.

## Key Features

- **⚡ Real-Time Git-Aware Boosting**: Files currently modified, staged, or untracked in Git are boosted to the very top of search results with explicit `[git: status]` badges. The agent sees changes immediately.
- **🧠 IME-Style Frecency Memory (CWD-Scoped)**: Automatically learns file access patterns and query hits per workspace root (`~/.pig/agent/fff-frecency.json`). Frequently and recently examined files earn a `[hot]` badge and climb up rankings without cross-project pollution.
- **🔄 Read/Write Lifecycle Hook**: Hooks directly into pig's tool lifecycle (`tool_result`). Files touched by `edit`, `write` (5x boost) or `read` (2x boost) are instantly boosted in subsequent searches for seamless context continuity.
- **🔇 Intelligent Noise & Lockfile Sinking**: Automatically detects lockfiles (`composer.lock`, `package-lock.json`), minified scripts (`*.min.js`), and build maps, slapping a -5,000 penalty so massive non-code files never flood top results or waste tokens.
- **🐪 CamelCase & Acronym Matching for `fffind`**: Type `REM` or `REMiddleware` to immediately target `ResponseEnvelopeMiddleware.php`; type `ACHandler` to find `ActivationCodeHandler.php`.
- **🎯 Multi-Pattern Coverage Boosting**: Searching with `patterns: ["Order", "Pay", "Status"]` rewards files containing multiple matching terms (e.g. `[3/3 terms]`) to rank high-density files first.
- **🛡️ 100% Stateless & Zero Cache Drift**: Unlike in-memory pre-indexed engines that desync when external agents (like Claude or IDEs) modify code, `pig-fff` inspects real-time Git status alongside disk contents. No stale cache, zero manual rescan needed.
- **🚀 Dual-Engine Execution**: Powered by `ripgrep` (`rg`) and `fd` when available on the host machine for microsecond-level scans, with pure PHP fallback when external binaries are not present.
- **📦 Zero C/Rust Binary Extensions**: Pure PHP 8.3/8.4+ implementation with zero compilation overhead.

## Installation

```bash
pig install git:github.com/owner888/pig-fff
```

Once installed, `ffgrep` and `fffind` will be automatically registered into pig.

## Tools

### `ffgrep`

Searches file contents across your workspace with Git-aware prioritization.

**Parameters**:
- `pattern` *(string)*: Search text or regular expression.
- `patterns` *(string[], optional)*: Multiple literal patterns to match with OR logic.
- `path` *(string, optional)*: Search directory constraint (defaults to `.`).
- `glob` *(string, optional)*: Glob filter (e.g. `'*.php'`, `'!*Test.php'`).
- `literal` *(boolean, optional)*: Treat pattern as literal string (default: `true`).
- `ignoreCase` *(boolean, optional)*: Case-insensitive search (default: `false`, smart-case).
- `context` *(integer, optional)*: Context lines around matches (default: `0`).
- `limit` *(integer, optional)*: Maximum matches to return (default: `100`).

### `fffind`

Searches file paths and filenames with Git-aware prioritization.

**Parameters**:
- `pattern` *(string)*: Glob or substring query (e.g. `'*.php'`, `'Service'`).
- `path` *(string, optional)*: Search directory (defaults to `.`).
- `limit` *(integer, optional)*: Maximum results (default: `200`).

## Commands

- `/fff-status`: Displays diagnostic info, including detected Git status, modified file count, and engine binary availability.

## License

MIT
