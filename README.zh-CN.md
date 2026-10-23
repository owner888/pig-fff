# pig-fff

专为 [pig](https://pigagent.dev) 打造的高性能、Git 状态感知智能搜索扩展 —— 灵感源自 `@ff-labs/pi-fff`。

提供高效的 `ffgrep` 与 `fffind` 工具，自动将当前修改中、暂存或未跟踪的文件置顶展示在搜索结果最前列，大幅削减大模型 Token 消耗并彻底杜绝缓存脱节问题。

## 核心特性

- **⚡ 实时 Git 状态加权（Git-Aware Boosting）**：工作区中处于修改（modified）、暂存（staged）或未跟踪（untracked）状态的文件，会被自动强行置顶，并带有清晰的 `[git: status]` 徽标。Agent 第一眼即可看到当前正在变更的代码。
- **🧠 输入法式词频记忆（按项目 CWD 严格隔离）**：跨会话自动沉淀文件检索与命中频次（保存在 `~/.pig/agent/fff-frecency.json`）。经常触达和近期访问的核心代码会自动获得 `[hot]` 标签并优先前排展示；各项目工作区严格隔离，换项目绝不串词。
- **🔄 读写生命周期闭环联动（Lifecycle Hook）**：通过挂载 pig 的 `tool_result` 钩子，Agent 刚执行过 `edit`、`write`（5倍暴涨权重）或 `read`（2倍权重）的文件，热度瞬间拉满，后续几小时内相关检索必定稳居第一行。
- **🔇 智能降噪与锁文件沉底（Noise Sinking）**：自动识别 `composer.lock`、`package-lock.json`、`*.min.js`、`*.map` 等无业务意义的大文件并执行 -5,000 分严厉扣分，强制沉底，彻底杜绝垃圾文件霸占输出或撑爆 Token。
- **🐪 驼峰首字母缩写智能匹配（CamelCase Matcher for `fffind`）**：输入 `REM` 或 `REMiddleware` 直接秒出 `ResponseEnvelopeMiddleware.php`；输入 `ACHandler` 直接命中 `ActivationCodeHandler.php`，大幅提升模糊查找文件的命中率。
- **🎯 多词检索覆盖度加权（Pattern Coverage Boost）**：当传入 `patterns: ["Order", "Pay", "Status"]` 时，同时涵盖多个关键词的核心文件优先置顶展示，带有清晰的 `[3/3 terms]` 标识。
- **🛡️ 纯无状态设计，零缓存脱节**：不同于常驻内存、外部代码改动后容易过期的静态预索引引擎，`pig-fff` 每次检索均实时结合 Git 状态与真实磁盘数据。即使 Claude、IDE 或外部脚本在后台高频生成或修改代码，也绝对不会发生“查不到新代码”的缓存漂移，告别手动刷新。
- **🚀 双引擎加持**：优先调用宿主机的 `ripgrep` (`rg`) 与 `fd` 执行微秒级物理扫描；若外部工具不存在，自动平滑退避至纯 PHP 高性能迭代器。
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
