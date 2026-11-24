# ssh_check — SSH 账号池连通性测试模块包

由 1071 行单文件脚本拆分而来（旧入口 `scripts/test-ssh-connect.py` 已删除，
描述信息全部收敛到本目录）。调用方式（等价）:

```bash
cd scripts && python3 -m ssh_check [参数]        # 推荐
python3 scripts/ssh_check/__main__.py [参数]     # 任意目录直接执行
PYTHONPATH=scripts python3 -m ssh_check [参数]   # 仓库根免 cd
```

## 模块分工

| 模块 | 职责 | 外部依赖 |
| --- | --- | --- |
| `models.py` | `Server` / `HostResult` 数据类、状态与认证模式常量、汇总分类 | 无 |
| `envfile.py` | `.env` 服务器清单解析（纯文本，不触网） | 无 |
| `keys.py` | 私钥路径解析、按地址自动识别、可用性校验（纯本地文件，不触网） | 标准库 + git（仅用于判断是否被忽略） |
| `metrics.py` | 资源阈值判定、跨次对比与快照读写（`--report` 的判定内核） | 标准库 |
| `checks.py` | DNS 解析 + TCP 连通性探测，返回分层 debug 信息 | 标准库 socket |
| `sshclient.py` | SSH 登录测试：密码 / 私钥 / 本机身份多方式尝试 + 系统信息收集（paramiko 优先，ssh+sshpass CLI 兜底） | paramiko（可选） |
| `batch.py` | 单台全链路编排（DNS/TCP → SSH）与线程池并发批量执行 | 标准库 |
| `report.py` | 报告渲染：控制台彩色版与日志纯文本版同一版式 | 无 |
| `runlog.py` | 运行日志（logging 事件流 + 报告块落盘） | 标准库 logging |
| `cli.py` | argparse 命令行入口 | — |

依赖方向单向（cli → batch → checks/sshclient → keys/metrics → models，report 只读结果），
可单独复用任何下层模块——`metrics` 与 `keys` 都不触网，可以拿来只算不连。

## .env 清单格式

```ini
# 全局默认（出现在任何服务器块之前）
ssh_user = aiadmin
ssh_port = 22
ssh_auth = password

# --- 每台服务器一个块：名称 / 地址 / 密码，块间空行或 ### 分隔 ---
Txy 4H4G-70GB
192.0.2.10
示例密码A

Hey 2H2G
192.0.2.11:2222
示例密码B
ssh_user = root

# 只允许密钥登录的机器（专属 .pem，放 scripts/ssh_check/key/<地址>.pem 即自动识别）
Go 1h2g
192.0.2.12:56442
-
ssh_auth = key
ssh_user = deploy

# 密码与密钥都要验
Bwg 3h2g-40G
192.0.2.13
示例密码C
ssh_auth = auto

# 用被口令保护的私钥
某机
192.0.2.14
ssh_auth = key
ssh_key = ~/keys/id_ed25519
ssh_key_pass = 私钥口令

# 靠本机默认身份（~/.ssh/id_* 或 ssh-agent，公钥已配到服务器）直连的机器
本机身份机
192.0.2.15
ssh_auth = agent
```

- 第 1 行名称、第 2 行地址（可 `host:port` 简写端口）、第 3 行密码。
- 块内 `ssh_user =` / `ssh_port =` / `ssh_auth =` / `ssh_key =` / `ssh_key_pass =`
  行随时可覆盖本块默认（写在文件顶部即全局默认）。
- `#` 整行为注释；`###` 为块分隔线；密码行写 `-` 表示无密码；
  `ssh_auth = key` / `agent` 的块可以只有两行（无需密码行），其余模式不足三行跳过并告警。
- `ssh_key` 支持 `~`、绝对路径、相对清单目录或模块目录；`--key` / `--key-dir` 可从命令行给默认值。

## 四种认证模式（`ssh_auth =`，命令行 `--auth` 可整体覆盖）

| 模式 | 用什么凭据 | 状态语义 | 适用 |
| --- | --- | --- | --- |
| `password`（默认） | 只用 `.env` 的密码：禁 publickey、禁 agent、`look_for_keys=False` | 密码登录成功才算 `成功`；服务器不收密码报 `不接受密码`，收了但被拒报 `密码错误` | 账号池审计：确认每台登记的密码还有效 |
| `key` | 只用指定/自动识别的**专属私钥文件**（`IdentitiesOnly=yes`，来源可追溯） | 该密钥被授权才算成功 | 只有云商下发 .pem、root/admin 免密的机器 |
| `auto` | 先密码，密码不通再私钥 | 成功行标注生效方式；`成功(密钥)` 意味着**这一行没验证密码** | 混合池，一次看清两条腿 |
| `agent` | 本机默认身份：不带 `-i`、不禁 agent，`~/.ssh/config` 与 `~/.ssh/id_*` 全部生效 | 报 `成功(本机)` | 你把自己的 `id_ed25519.pub` 配到服务器、本机直接 ssh 就通的那类 |

`key` 与 `agent` 的区别就是「哪把钥匙」：前者是清单里点名的一把文件，后者是本机已有身份。
两者都强制 `BatchMode=yes`（除私钥带口令时交给 sshpass 应答），**绝不会卡在交互提示上**。

失败归因（`--json` 的 `auth_tried` / 报告的「认证尝试」逐条留痕）：

| 状态 | 含义 | 下一步 |
| --- | --- | --- |
| `不接受密码` | 服务器只允许 publickey | 该块写 `ssh_auth = key`（或 `--auth auto` 先看密钥能不能通） |
| `密码错误` | 服务器收密码，这个密码被拒 | 更新 `.env`；注意本机免密能登不代表密码是对的 |
| `缺凭据` | 没密码 / 找不到私钥 / 私钥权限过宽 / 私钥被口令保护但没给口令 | 按提示补 `ssh_key` / `ssh_key_pass`，或 `chmod 600` |
| `密钥被拒` | 服务器收 publickey，但这把密钥没被该账号授权 | 核对公钥是否在该用户 `authorized_keys` 里、用户名是否正确 |

> ⚠️ 两条纪律：**私钥永不提交**（`.gitignore` 已排除 `scripts/ssh_check/key/`、`*.pem`；日志与 `--json` 只输出密钥文件名，不含私钥内容、密码与口令）；
> 反复试错密码可能触发服务器 fail2ban 把你的 IP 关进小黑屋，跑全池前先 `--list` 自检。

## 命令行

```
python3 -m ssh_check [.env 路径] [-j 并发] [--timeout 秒]
    [--log 路径 | --no-log] [--no-console] [--trust-host-key]
    [--auth password|key|auto|agent] [--key PATH] [--key-dir DIR]
    [--list] [--json] [-v]
    [--report [--report-dir DIR] [--state PATH] [--keep N]
             [--threshold 指标=warn[:critical]] [--fail-on-alert]]
    [--inventory [--md 路径]]
```

- `--list` 只解析并打印清单（含每台认证模式、密钥来源与自动识别目录），不发起任何连接
  （改完 .env 先跑这个自检）。
- `--auth key` / `--auth auto` / `--auth agent` 临时换整池口径，不改 `.env`；
  `--key` 给默认私钥，`--key-dir` 追加自动识别目录（可重复）。
- `--json` 输出机器可读结果（不含密码），供其他脚本/CI 复用。
- `--inventory` 登录成功的机器在同一次登录里跑一组**只读**采集命令
  （hostname / os-release / CPU / 内存 / 根分区 / 负载 / uptime / 内网IP），
  输出 Markdown「配置运行清单」，默认落盘 `.logs/inventory-<时间戳>.md`（`--md` 可指定路径）。
- 日志默认写 `.logs/ssh-test-<时间戳>.log`：前半是逐台事件流，末尾附纯文本报告。
- 退出码：全部 connected 为 0，否则 1；清单缺失/解析为空为 2/1。

## 服务器配置与运行报告（`--report`，给其他 AI / 定时任务用）

纯 Python，不依赖本项目的 PHP 与 Web 端；把「查服务器配置与运行」变成一条可交给别的
AI 每天跑一次的命令，产出**结果**（Markdown 正文 + 机器可读 JSON）与**日志**（事件流 + 逐台表格 + 正文）。

```bash
python3 -m ssh_check --report                      # 人在终端看：直接打印正文
python3 -m ssh_check --report --no-console         # cron：只写文件与日志
python3 -m ssh_check --report --json               # AI 读结构化数据（findings / changes / hosts）
cat .logs/ssh-report/report.md                     # 固定路径正文，任何 AI 都能直接读
```

### 产出物（默认目录 `.logs/ssh-report/`，已在 .gitignore 内）

| 文件 | 内容 | 给谁 |
| --- | --- | --- |
| `report.md` | 正文，每次覆盖（固定路径，可直接贴给 AI） | 人 / AI |
| `report-<时间戳>.md` | 同期归档（`--keep` 控制份数，默认 14） | 回溯 |
| `report-<时间戳>.json` | `{report, changes}` 全量结构化数据 | AI / 脚本 |
| `state.json` | 对比基线（地址、用户与数字，**无凭据**，权限 0600） | 下次运行 |
| `.logs/ssh-test-*.log` | 逐台事件流 + 连通表 + 正文 | 排障 |

### 正文结构（结论在前，异常在前，口径在尾）

1. `结论`：🔴严重 / 🟠警告 / 🔵提示 / 变化 的计数——AI 先读这一行就知道要不要动手
2. `需要关注（阈值异常）`：表格，每行给「当前值 / 阈值 / 为什么要紧 / 建议动作」
3. `与上次对比`：新增·下线主机、状态翻转、**已重启**（启动时间变化）、磁盘·内存·inode·Swap·负载位移、内核与规格变更
4. `全量配置与运行`：含内存使用率、磁盘用量、inode、每核负载、进程/僵尸数的总表
5. `逐台明细`：每台一段键值行（含启动时刻、内存占用前 3 进程），最适合喂给 LLM
6. `判定口径与阈值`：默认阈值表 + 「留空不推断」说明——与大屏快照同一套语义纪律

### 阈值（`--threshold` 可覆盖，可重复）

| 指标 | warn | critical | 口径 |
| --- | --- | --- | --- |
| `disk_pct` | 85 | 95 | 根分区使用率 % |
| `inode_pct` | 85 | 95 | 根分区 inode 使用率 %（耗尽时磁盘有余也建不了文件） |
| `mem_pct` | 85 | 95 | 内存实际使用率 %（按 `MemAvailable`，不按 `free`） |
| `load_per_core` | 1.0 | 2.0 | load1 ÷ CPU 核数 |
| `swap_pct` | 50 | 80 | Swap 使用率（无 Swap 的机器跳过此项） |
| `zombies` | 1 | 10 | 僵尸进程数 |

- 机器**不可达 / 登录失败**一律进异常表（critical 或 warn），绝不出现在「无异常」里；
- 采不到的字段（老内核无 `MemAvailable`、非 Linux 无 `/proc/loadavg`）留空并跳过判定，
  **不做推断**，宁缺不假。
- 变化判定步长：磁盘/inode ±5%、内存 ±10%、Swap ±20%、load1 ±1，`boot_time` 变化即记「已重启」。
- 退出码：连通性优先——全通 0 / 有失败 1 / 清单缺失 2 / 解析为空 1；
  加 `--fail-on-alert` 后，只要存在 🔴 严重项就返回 **3**（给 cron 做「异常才通知」的门禁）。

### 定时任务示例

```cron
# 每天 06:15 跑一次报告，静默写文件；有严重异常时退出码 3（可据此发通知）
15 6 * * * cd /path/to/repo/scripts && python3 -m ssh_check --report --no-console --fail-on-alert >> ../.logs/ssh-report/cron.out 2>&1
```

给外部 AI 的最小接入说明就三行：清单在 `.env`（凭据不提交）、命令是
`python3 -m ssh_check --report`、结果在 `.logs/ssh-report/report.md` 与同名 JSON。

## 作为库复用

```python
import sys; sys.path.insert(0, "scripts")
from ssh_check import parse_env, attach_keys, run_batch, render

servers, warns = parse_env(".env")
attach_keys(servers, env_path=".env")      # 用到 ssh_key / ssh_auth 时必须先挂密钥
results = run_batch(servers, workers=8, timeout=15,
                    on_done=lambda r, done, total, el: print(r.label, r.status))
print(render(results, color=False))
```

单台测试（不经并发）：`from ssh_check import test_one`；
只做端口探测（不登录）：`from ssh_check import probe`；
只判断密钥可用性（不触网）：`from ssh_check.keys import attach, is_encrypted`。
`attach_keys` 支持 `force_auth="auto"`（等价命令行 `--auth`）与 `default_key` / `extra_dirs`。

## 相对旧版的修复

- `full_test_server` 里 `_do_ssh_login` 引用了不存在的 `password` 变量（NameError）。
- `main` 在控制台模式会崩溃（对字符串做下标赋值），且同一批服务器被提交执行两次；
  现在单次提交、按序收集、进度与事件日志共用一个回调。
- 删除重复定义的 `resolve_hostname` 与死代码（`executor._threads` 探测、空转的 `_on_done`）。
- sshpass 兜底改为 argv 列表调用（不再拼 shell 字符串）。
- sshpass 兜底现在**只测密码认证**（禁 publickey/agent，与 paramiko 路径的
  `allow_agent=False, look_for_keys=False` 语义对齐）：否则密钥可登录的机器会
  「假性通过」，`.env` 里的密码根本没被验证。
- `Permission denied (…)` 现在解析服务器自报的认证方式列表：只允许 publickey 的
  机器报「不接受密码认证（本机 ssh 能通是靠密钥）」，允许密码但被拒才报密码错误。

## v2.1 新增：密钥与本机身份认证

- `sshclient` 从「只会试密码」升级为多方式尝试引擎：尝试计划、失败归因、
  状态判定在 paramiko 与 ssh/sshpass 两条路径共用同一套代码（此前两路的
  「不收密码 / 密码错」判定各写一遍，行为并不一致）。
- 新增 `ssh_auth`（password / key / auto / agent）、`ssh_key`、`ssh_key_pass` 与
  `--auth` / `--key` / `--key-dir`；`ssh_check/key/<地址>.pem` 按地址自动识别。
- 新增 `keys.py`：私钥路径解析、自动识别、可用性预检（权限过宽、被口令保护却无口令、
  落在仓库里未被 git 忽略），连接前就给出可照做的修复，而不是让 ssh 报一句晦涩错误。
- 新增状态 `no_credential`（报告标签「缺凭据」）；`成功(密钥)` / `成功(本机)` 显式标注，
  并在表下与汇总里说明「这些行没有验证密码」。
- `agent` 模式强制走真实 ssh（paramiko 不读 `~/.ssh/config`），全程 `BatchMode=yes`；
  CLI 兜底不再回写本机 `known_hosts`。

## v2.2 新增：服务器配置与运行报告

- `--report`：阈值异常 + 与上次对比 + 全量明细 + 逐台键值，正文固定写
  `.logs/ssh-report/report.md`（另有时间戳归档与同名 JSON），给外部 AI 与 cron 直接消费。
- 采集脚本补出**可判定**的纯数字字段（内存实际使用率、磁盘/inode 使用率、load1、
  启动时刻、进程与僵尸数、内存占用前 3 进程），旧版只有给人看的字符串。
- 顺带修 `mem_total` 用 `%.0f` 把 0.5G 的机器显示成「0G」（现在 0.5G），
  macOS 的 `uptime` 文案被误当成负载数字（非数字一律丢弃，宁缺不假）。
- `--threshold` / `--keep` / `--state` / `--fail-on-alert`（严重异常退出码 3）。
