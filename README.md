# 网页访问量计数器（intranet-counter）

一套零依赖前端 SDK + PHP + MySQL 的访问统计系统。往任意网页贴一行 `<script>` 即可统计 PV / UV / 会话 / 停留时长，并提供后台查看各站点报表。最初为内网 `IP:PORT`、HTTP、日访问量 <100 的场景设计，同时支持公网 HTTPS 站点、Cloudflare Tunnel、frp、跨服务器部署。

- 页面上**不显示数字徽章**，只在后台看数据。
- 前端 SDK 只有一个 `sdk.js`，无第三方运行时依赖；图表用的 ECharts 已下载到本地，不依赖外网 CDN。
- 宿主页面与统计服务可以不同域、不同服务器。

---

## 1. 目录结构

```
intranet-counter/
├── app/                          # 后端逻辑，必须放在 Web 运行目录之外
│   ├── config.php                # ★ 配置文件：数据库、密码、对外地址、代理等
│   ├── bootstrap.php             # 引导：数据库、公共函数、base_url、查询串脱敏
│   ├── collector.php             # 采集与落库核心（幂等、限流、IP 还原）
│   ├── ip.php                    # 客户端 IP / 代理链解析
│   ├── ua.php                    # User-Agent 实时解析（浏览器/系统/设备）
│   └── chart.php                 # SVG 图表兜底数据
├── public/                       # ★ Web 运行目录（宝塔站点运行目录指向这里）
│   ├── sdk.js                    # 前端统计脚本（贴到被统计页面）
│   ├── collect.php               # PV 上报端点（返回 1×1 GIF）
│   ├── beacon.php                # 离开页面上报端点（sendBeacon）
│   ├── check.php                 # 自检页，部署后排障用，用完务必删除
│   ├── admin/                    # 后台
│   │   ├── login.php             # 登录页
│   │   ├── _guard.php            # 登录守卫
│   │   ├── index.php             # 报表主页 + CSV 导出
│   │   ├── sites.php             # 站点与嵌入代码（新增/编辑/删除/启停）
│   │   ├── charts.js             # ECharts 渲染
│   │   └── style.css             # 样式
│   └── static/echarts/6.0.0/     # 本地 ECharts（含 echarts.min.js）
└── sql/
    ├── schema.sql                # ★ 全新部署：建表
    ├── upgrade_clientinfo.sql    # 可选：客户端语言/分辨率/IP 字段
    ├── upgrade_origin.sql        # 可选：允许来源扩到 1024
    ├── upgrade_site_base_url.sql # 可选：站点级专用嵌入地址
    └── upgrade_query.sql         # 可选：URL 查询参数字段
```

> **安全要点**：`app/` 目录不能被浏览器直接访问，只有 `public/` 对外。宝塔站点的「运行目录」必须设为 `/public`。

---

## 2. 统计口径

| 指标 | 口径 |
|---|---|
| **PV** | 每次页面加载记 1 条；SPA（History 路由）pathname 切换时也记 1 条。仅 query 变化不额外计 PV。 |
| **UV** | 按访客 ID `vid`（第一方 Cookie）精确去重，同一站点同一天同一访客只算 1 个。Cookie 写不进去时用服务端指纹兜底。 |
| **会话 sid** | 存于 `sessionStorage`，30 分钟无操作过期。 |
| **停留时长** | 从进入页面到离开（`pagehide` / `visibilitychange:hidden`）的时间差，向上报一条 `unload` 事件。 |
| **来源 ref** | `document.referrer` 的纯路径。 |
| **去重 / 限流** | 事件 ID `eid` 唯一索引防止重试重复计数；同一站点 + IP + 访客 2 秒内默认最多 20 条上报。 |

前端所有异常都被静默吞掉，统计脚本绝不影响宿主页面。

---

## 3. 快速部署（宝塔面板）

### 3.1 建站

1. 宝塔新建站点，域名可先填 IP 或 `stats.example.com`；PHP 版本选 **7.4 ~ 8.x**。
2. **运行目录**改为 `/public`（站点配置 → 基本配置 → 运行目录）。这一步最关键，改完 `app/` 就不在 Web 可访问范围内。
3. 若开启「防跨站攻击 / open_basedir」导致报 `open_basedir restriction in effect`，二选一：
   - 关闭该站点的防跨站；
   - 或在站点 `.user.ini` 的 `open_basedir` 中追加 `:/www/wwwroot/<你的目录>/app/:/tmp/`。

### 3.2 建库并导入表结构

1. 宝塔新建 MySQL 数据库（如 `intranet_counter`）及账号密码。
2. 选中该库，用 phpMyAdmin 或 SQL 工具执行 `sql/schema.sql`（文件内不含 `CREATE DATABASE` / `USE`，不会与面板建库冲突）。
3. **全新部署到此为止**；只有从旧版升级时才需要执行 `sql/upgrade_*.sql`（见第 8 节）。

### 3.3 配置 `app/config.php`

用编辑器打开 `app/config.php`，至少修改这几项：

```php
'db_dsn'  => 'mysql:host=127.0.0.1;port=3306;dbname=intranet_counter;charset=utf8mb4',
'db_user' => '你的数据库账号',
'db_pass' => '你的数据库密码',

// 生成方法见下方
'admin_password_hash' => '$2y$10$...',

'salt' => '换成任意长随机字符串',
'public_base_url' => 'https://stats.example.com',
```

生成后台密码哈希（在宝塔终端执行，把 `你的后台密码` 换成实际密码）：

```bash
php -r "echo password_hash('你的后台密码', PASSWORD_DEFAULT), PHP_EOL;"
```

把输出的整串字符（以 `$2y$...` 开头）粘贴到 `admin_password_hash` 的单引号里。**留空则后台拒绝登录**，防止裸奔。

### 3.4 首次登录

浏览器打开：

```
http://你的统计地址/admin/login.php
```

输入在 config 里设置的后台密码。进入后先到「站点与嵌入代码」新增站点，再复制嵌入代码贴到被统计页面。

---

## 4. `app/config.php` 配置项速查

| 配置 | 说明 |
|---|---|
| `db_dsn` / `db_user` / `db_pass` | 数据库连接，按宝塔实际填写。 |
| `admin_password_hash` | 后台登录密码哈希，用 `password_hash()` 生成；留空拒绝登录。 |
| `salt` | IP 脱敏哈希用盐，改成随机串即可，不影响 PV/UV。 |
| `public_base_url` | **统计服务对外根地址**，会原样写进嵌入代码。非 80 端口、反代、跨服务器时必须显式填写。详见第 6 节。留空则自动从请求推断。 |
| `timezone` | 时区，默认 `Asia/Shanghai`。 |
| `require_origin_check` | 是否按站点「允许来源」校验 Referer/Origin。缺失请求头时仍放行。用于防误报/防串站，不是防恶意伪造。 |
| `store_client_ip` | 是否存明文 IP（内网排障用）。`false` 时只存哈希。开启需执行 `upgrade_clientinfo.sql`。按单位隐私要求决定。 |
| `store_query` | 是否保存 URL 查询参数，默认 `true`。敏感参数自动打码为 `***`。需执行 `upgrade_query.sql`。 |
| `query_extra_sensitive` | 本站特有的敏感参数名数组，大小写不敏感，如 `['staffid', 'tel']`。 |
| `trusted_proxies` | **统计服务自己前面的代理 IP**（精确 IP 或 CIDR）。经 frp / Cloudflare Tunnel 时必填，详见第 5、6 节。留空 = 只信 `REMOTE_ADDR`。切勿填 `['*']`。 |
| `real_ip_headers` | 转发头优先级。Cloudflare 场景已把 `CF-Connecting-IP` 放首位（边缘注入、无法伪造）。一般不用改。 |
| `rate_limit_per_2s` | 每 2 秒每站点每访客最多上报条数，`0` 关闭。 |

---

## 5. 客户端 IP 还原（代理 / frp / Tunnel）

如果统计服务经过反向代理，`REMOTE_ADDR` 会是代理的地址，所有访客都被记成同一个 IP。解决方式是配置 `trusted_proxies`：

1. 从公网打开 `https://<统计域名>/check.php`，找到「REMOTE_ADDR（TCP 对端）」这一行的实际值。
2. 把该值（或所在网段）填进 `trusted_proxies`。

```php
// 例：cloudflared 跑在 Docker 网桥，出口是 172.17.0.5，容器重建后可能变
'trusted_proxies' => ['172.17.0.0/16', '192.168.3.1'],
```

- 支持精确 IP 和 CIDR。用网段可覆盖容器/代理 IP 变动。
- **只填统计服务前面的代理**，与被统计网站服务器的代理无关。
- 填错不会报错，但转发头会被安全策略忽略，所有访客仍显示成代理 IP。
- Cloudflare 场景以 `CF-Connecting-IP` 为权威头；XFF 可被伪造，优先级排在后面。

---

## 6. 三种地址别混淆（Cloudflare Tunnel / 跨服务器核心）

同一套部署里可能同时出现三个地址，**用途完全不同，混用会直接零数据**：

| 地址 | 填在哪里 | 谁访问 |
|---|---|---|
| 对外域名，如 `https://stats.example.com` | `public_base_url` + 嵌入代码 | 访客浏览器 |
| 源站地址，如 `http://192.168.3.29:666` | 只在 cloudflared / frp 的 `service` 里 | cloudflared 回源，浏览器看不到 |
| 代理出口 `REMOTE_ADDR` | `trusted_proxies` | 采集端看到的 TCP 对端 |

**`public_base_url` 绝不能填成源站地址**：它会被写进嵌入代码，公网浏览器根本连不上内网 IP:PORT，导致计数恒为 0。

### Cloudflare Tunnel 示例

```yaml
# cloudflared 的 ingress（兜底规则必须放最后）
ingress:
  - hostname: www.example.com
    service: http://127.0.0.1:80
  - hostname: stats.example.com
    service: http://192.168.3.29:666   # 统计源站，仅此处出现
  - service: http_status:404
```

配套注意：

- 宝塔站点「域名」里要追加 `stats.example.com`，否则 Nginx 按 Host 匹配不上会 404；源站**不要开强制 HTTPS / SSL**，回源保持 HTTP。
- HTTPS 宿主页必须用 HTTPS 统计地址，否则脚本/图片被浏览器按混合内容拦截。TLS 由 Cloudflare 边缘终结即可。
- Cloudflare 侧关闭 **Rocket Loader / Bot Fight / JS Challenge**，否则上报请求可能被改写或 403。
- 若宿主站有 CSP，需放行 `script-src` / `img-src` / `connect-src` 里的统计域名。

### 跨服务器部署

统计服务装到另一台服务器完全没问题——判定标准只有「访客浏览器能否访问统计对外地址」。两台机器之间不需要互通、不需要同 IP。`vid` 写在宿主页面自己域的第一方 Cookie，随 URL 参数上报，不受第三方 Cookie 限制。

---

## 7. 站点管理与嵌入代码

后台「站点与嵌入代码」页支持**新增、编辑、启停、删除**站点。每个站点需配置：

- **站点名称**：便于识别。
- **站点 Key**：6–64 位字母、数字、下划线、短横线；改 Key 后必须重新贴代码（历史数据按 site_id 关联，不会丢）。
- **允许来源**：宿主页面的 origin，用于 Referer/Origin 白名单。多个入口用逗号分隔，例如 `https://www.example.com, https://example.com`。
  - 公网域名必须写完整协议，**HTTPS 站点写 `http://` 会被白名单全部拒收**（请求返回 200 但不入库，是「HTTPS 站点零数据」最常见原因）。
  - 内网 IP:PORT 可省略协议，自动补 `http://`。
  - 代理会剥离 Referer/Origin 时留空即可（缺失时放行）。
- **专用嵌入地址**（可选，需先执行 `upgrade_site_base_url.sql`）：给单个站点指定统计地址。留空则用全局 `public_base_url`。同一套服务同时服务公网 HTTPS 站点和纯内网 HTTP 站点时很有用。

新增站点后，复制生成的嵌入代码贴到该站点每个页面的 `</body>` 之前：

```html
<script data-cfasync="false" async src="https://stats.example.com/sdk.js?v=1234567890" data-site="站点KEY"></script>
<noscript><img src="https://stats.example.com/collect.php?k=站点KEY" width="1" height="1" alt=""></noscript>
```

> **重要**：`sdk.js` 更新后，`?v=` 版本号会变化。**必须重新复制整段代码替换旧代码**，或在 Cloudflare 里 Purge `sdk.js` 缓存，否则浏览器/CDN 仍命中旧脚本，修复不生效。改服务端配置不会让已贴出去的旧代码自动更新。

排障时给 script 加 `data-debug` 属性，控制台会打印脚本定位、上报地址、vid 和每次请求结果；排查完去掉。

---

## 8. 数据库升级（仅旧版升级时需要）

全新部署只需 `schema.sql`。从旧版升级时，按需执行对应脚本（在宝塔选中统计库，用 phpMyAdmin / SQL 工具执行一次）：

| 脚本 | 作用 | 不执行的后果 |
|---|---|---|
| `upgrade_clientinfo.sql` | events 增加语言/分辨率/IP 等字段 | 浏览器/系统/设备仍可统计（按 UA 实时解析）；但语言、分辨率、来源 IP 无数据 |
| `upgrade_origin.sql` | sites.origin 扩到 1024 | 允许来源超过 255 字符时存不下 |
| `upgrade_site_base_url.sql` | sites 增加 base_url 字段 | 无法给单站点指定专用嵌入地址，统一用全局地址 |
| `upgrade_query.sql` | events 增加 query 字段 | 不保存 URL 查询参数，PV/UV 和其余报表照常 |

- 重复执行报 `Duplicate column name` 属正常，忽略即可。
- 这些升级都是**增量、向后兼容**的：未升级时代码会自动探测列是否存在并降级，不会丢数据或影响采集。
- 历史数据无法回填新字段（如旧记录没有 query 值），只有升级后产生的新数据才有。

---

## 9. 报表功能

后台首页 `/admin/index.php` 提供：

- **筛选**：按站点、日期范围查询。
- **按日趋势**：折线图（ECharts）。
- **客户端分布**：浏览器 / 系统 / 设备柱状对比图。
- **热门页面 Top 10**：横向柱状图，按**纯路径**聚合（不受 query 参数影响）。
- **最近访问明细**：逐条显示时间、站点、完整访问地址（path + query，敏感参数已打码）、浏览器/系统、设备、访客、（可选）来源 IP。
- **CSV 导出**：含多段数据（汇总、站点 PV、热门页面、含参数的访问地址 Top 20 等），带 BOM 兼容 Excel。

### URL 查询参数与脱敏

SDK 上报 `location.search`，服务端 `ic_clean_query()` 做脱敏：

```
?page=2&id=123&token=abc123   →   ?page=2&id=123&token=***
```

- 内置约 32 个常见敏感参数名（token、password、key、session、code、signature 等）自动打码为 `***`，大小写不敏感。
- 本站特有敏感参数加进 `query_extra_sensitive`。
- 脱敏在服务端完成，改规则不需重新贴代码。
- 超长值截断、非法参数名丢弃。
- `path` 列仍存纯路径，所以同一页面不会被不同参数拆散；完整地址在明细和 CSV 里看。

图表优先用本地 ECharts 渲染，加载失败时回退到服务端 SVG，保证一定有图可看。

---

## 10. 自检与排障

部署后从**公网域名**打开自检页（不要用内网直连，否则看不到 Cloudflare 注入的头）：

```
https://stats.example.com/check.php
```

它会检查：PHP / PDO 环境、表结构与字段是否齐全、本地 ECharts 是否存在、open_basedir、代理头与最终采用的客户端 IP、对外地址是否误填成源站、各站点白名单判定等，并生成可复制的排查命令。

> **诊断完成后务必删除 `check.php`**，避免暴露环境信息。

常见问题速查：

| 现象 | 排查方向 |
|---|---|
| 全部站点零数据 | `public_base_url` 是否误填成内网源站；config 是否改对；数据库是否连上 |
| 只有 HTTPS 站点零数据 | 嵌入代码是否仍是旧 `http://` 地址；**允许来源**是否写成 `http://`；Cloudflare 是否开了 Rocket Loader / Challenge |
| HTTP 正常、换公网地址后异常 | 内网访客能否访问公网域名；纯隔离内网应保留旧嵌入代码或给该站点设专用内网地址 |
| 所有访客 IP 都是同一个 | `trusted_proxies` 未填或填错，用 `check.php` 查实际 REMOTE_ADDR |
| CSV 导出内容乱码/带报错 | 确认用的是最新版 `admin/index.php`（旧版 PHP 8.4 下 `fputcsv()` 会因缺 `$escape` 参数报错） |
| 图表不显示 | 本地 `static/echarts/6.0.0/echarts.min.js` 是否存在；加载失败会回退 SVG，若 SVG 也没有则检查 PHP 报错 |

---

## 11. 安全建议

- 运行目录锁定为 `public/`，`app/` 与 `sql/` 不得对外可访问。
- 后台 `/admin/` 暴露在公网时，务必设置强密码；条件允许可叠加 Cloudflare Access 或 IP 白名单。
- 用完删除 `check.php`。
- 按单位隐私要求决定是否开启 `store_client_ip`；开启后注意数据保留期。
- `salt`、`admin_password_hash` 不要用默认值。
- `trusted_proxies` 不要填 `['*']`，否则任何人都可伪造 `CF-Connecting-IP` / XFF 污染统计。

---

## 12. 数据流小结

```
访客浏览器
  │  <script sdk.js>  上报 p(纯路径) / q(查询串) / vid / sid / eid
  ▼
统计域名 collect.php / beacon.php
  │  校验站点状态与允许来源 → 脱敏 query → 还原真实 IP → 幂等/限流 → 落库
  ▼
MySQL：events（PV/停留事件）+ uv_daily（每日 UV 去重）
  │
  ▼
后台 admin/index.php：折线图 / 柱状图 / 明细 / CSV 导出
```

前端只发简单请求（Image GET + sendBeacon `text/plain`），跨源无需 CORS 预检；SDK 零依赖、异常静默，可安全嵌入任意网页。
