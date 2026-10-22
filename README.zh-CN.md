# pig-fff

专为 [pig](https://pigagent.dev) 打造的高性能、Git 状态感知智能搜索扩展 —— 灵感源自 `@ff-labs/pi-fff`。

提供高效的 `ffgrep` 与 `fffind` 工具，自动将当前修改中、暂存或未跟踪的文件置顶展示在搜索结果最前列，大幅削减大模型 Token 消耗并彻底杜绝缓存脱节问题。

## 核心特性

- **⚡ 实时 Git 状态加权（Git-Aware Boosting）**：工作区中处于修改（modified）、暂存（staged）或未跟踪（untracked）状态的文件，会被自动强行置顶，并带有清晰的 `[git: status]` 徽标。Agent 第一眼即可看到当前正在变更的代码。
- **🛡️ 纯无状态设计，零缓存脱节**：不同于常驻内存、外部代码改动后容易过期的静态预索引引擎，`pig-fff` 每次检索均实时结合 Git 状态与真实磁盘数据。即使 Claude、IDE 或外部脚本在后台高频生成或修改代码，也绝对不会发生“查不到新代码”的缓存漂移，告别手动刷新。
- **🚀 双引擎加持**：优先调用宿主机的 `ripgrep` (`rg`) 与 `fd` 执行微秒级物理扫描；若外部工具不存在，自动平滑退避至纯 PHP 高性能迭代器。
- **🔍 多模式 OR 并行检索**：支持结构化入参 `patterns: ["Foo", "Bar"]`，无需手工拼凑复杂正则即可同时匹配多组关键词。
- **📦 零 C/Rust 动态库编译依赖**：纯 PHP 8.3/8.4+ 实现，开箱即用，零配置负担。

## 安装方式

```bash
pig install git:github.com/owner888/pig-fff
```

安装完成后，pig 将自动注册 `ffgrep` 与 `fffind` 工具。

## 工具列表

### `ffgrep`

检索工作区文件内容，并按 Git 变动状态进行智能置顶。

**参数说明**：
- `pattern` *(string)*: 检索文本或正则表达式。
- `patterns` *(string[], 可选)*: 多个字面量匹配词（OR 逻辑并行检索）。
- `path` *(string, 可选)*: 检索起始目录或文件（默认当前工作目录 `.`）。
- `glob` *(string, 可选)*: 文件名过滤模式（如 `'*.php'`、`'!*Test.php'`）。
- `literal` *(boolean, 可选)*: 是否按字面纯文本检索（默认 `true`）。
- `ignoreCase` *(boolean, 可选)*: 忽略大小写（默认 `false`，启用智能大小写 smart-case）。
- `context` *(integer, 可选)*: 匹配行前后的上下文行数（默认 `0`）。
- `limit` *(integer, 可选)*: 最大返回匹配行数（默认 `100`）。

### `fffind`

检索文件路径与文件名，优先展示近期改动的文件。

**参数说明**：
- `pattern` *(string)*: 文件名通配符或子串（如 `'*.php'`、`'Service'`）。
- `path` *(string, 可选)*: 检索起始目录（默认当前工作目录 `.`）。
- `limit` *(integer, 可选)*: 最大返回文件数（默认 `200`）。

## 内置命令

- `/fff-status`: 查看当前工作区的 Git 状态、改动文件总数及底层检索引擎（`rg`/`fd`）的就绪情况。

## 许可证

MIT
