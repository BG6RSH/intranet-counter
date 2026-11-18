<?php
/**
 * 部署自检页（自包含，不 require app/bootstrap.php）。
 * 即使 open_basedir 配置错误导致后台打不开，本页也能显示诊断结果。
 * 会暴露服务器路径与请求头，诊断完成后请删除本文件。
 */
declare(strict_types=1);
error_reporting(E_ALL);
ini_set('display_errors', '0');

$root = dirname(__DIR__);

// app/ 可能受 open_basedir 限制读不到，读不到时降级为只展示原始请求头
@include_once $root . '/app/ip.php';
$ipLib = function_exists('ic_resolve_client_ip');

$cfg = @include $root . '/app/config.php';
if (!is_array($cfg)) $cfg = [];
$cfgReadable = is_file($root . '/app/config.php') && is_readable($root . '/app/config.php');
$trusted = (array)($cfg['trusted_proxies'] ?? []);
$headers = (array)($cfg['real_ip_headers'] ?? []);

if ($ipLib) {
    $ip = ic_resolve_client_ip($_SERVER, $trusted, $headers);
} else {
    $ip = [
        'ip' => (string)($_SERVER['REMOTE_ADDR'] ?? ''), 'remote' => (string)($_SERVER['REMOTE_ADDR'] ?? ''),
        'source' => 'REMOTE_ADDR', 'trusted' => false, 'present' => [], 'chain' => [],
        'reason' => '无法加载 app/ip.php，仅显示原始 REMOTE_ADDR',
    ];
}

function h($s): string {
    return htmlspecialchars((string)$s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}
function row(string $title, string $state, string $detail): string {
    $cls = $state === 'ok' ? 'ok' : ($state === 'bad' ? 'bad' : 'warn');
    return '<tr><th>' . h($title) . '</th><td class="' . $cls . '">' . h($detail) . '</td></tr>';
}

$checks = [];
$checks[] = row('PHP 版本', version_compare(PHP_VERSION, '7.4.0', '>=') ? 'ok' : 'bad', PHP_VERSION);
$checks[] = row('pdo_mysql 扩展', extension_loaded('pdo_mysql') ? 'ok' : 'bad',
    extension_loaded('pdo_mysql') ? '已加载' : '未加载，宝塔请在 PHP 设置里安装 pdo_mysql');

$ob = ini_get('open_basedir');
$checks[] = row('open_basedir', $ob ? 'warn' : 'ok',
    $ob ?: '未限制（推荐，config.php 可放在 Web 目录之外）');
$checks[] = row('app/config.php', $cfgReadable ? 'ok' : 'bad',
    $cfgReadable ? '可读' : '不可读 / 不存在（常见原因：open_basedir 未放行 app/ 目录）');
$checks[] = row('app/ip.php', $ipLib ? 'ok' : 'bad', $ipLib ? '已加载' : '不可读（真实 IP 还原功能不可用）');

// 确认 frp 增量包是否真的上传过：bootstrap 已接线 + collector 限流带 vid
$bootSrc      = @file_get_contents($root . '/app/bootstrap.php');
$collectorSrc = @file_get_contents($root . '/app/collector.php');
$bootWired    = ($bootSrc !== false && strpos($bootSrc, 'ic_resolve_client_ip') !== false);
$rateFixed    = ($collectorSrc !== false && strpos($collectorSrc, 'AND vid = ?') !== false);
$frpDeployed  = $ipLib && $bootWired && $rateFixed;
$checks[] = row('frp 真实 IP 修复包', $frpDeployed ? 'ok' : 'bad',
    $frpDeployed ? '已部署（ip.php 已接线，限流已带 vid）'
        : '未部署或部分缺失：ip.php ' . ($ipLib ? '✓' : '✗') . ' / bootstrap 接线 '
          . ($bootWired ? '✓' : '✗') . ' / 限流带 vid ' . ($rateFixed ? '✓' : '✗')
          . '。请重新上传 intranet-counter-frp.zip');

// 确认 URL 参数采集包是否上传：SDK 上报 q + 服务端脱敏缺一不可
$sdkSrc         = @file_get_contents($root . '/public/sdk.js');
$sdkQuery       = ($sdkSrc !== false && strpos($sdkSrc, 'q: location.search') !== false);
$collectorQuery = ($collectorSrc !== false && strpos($collectorSrc, 'ic_clean_query') !== false);
$checks[] = row('URL 参数采集包', ($sdkQuery && $collectorQuery) ? 'ok' : 'warn',
    ($sdkQuery && $collectorQuery) ? '已部署（SDK 上报 query，服务端脱敏）'
        : '未部署或部分缺失：sdk.js ' . ($sdkQuery ? '✓' : '✗') . ' / collector 脱敏 '
          . ($collectorQuery ? '✓' : '✗') . '。请覆盖 intranet-counter-query.zip 并重新复制嵌入代码');

$needed = [
    'app/bootstrap.php', 'app/collector.php', 'app/ua.php', 'app/chart.php',
    'public/sdk.js', 'public/collect.php', 'public/beacon.php',
    'public/admin/index.php', 'public/admin/charts.js',
    'sql/schema.sql', 'sql/upgrade_clientinfo.sql', 'sql/upgrade_query.sql',
];
$missing = [];
foreach ($needed as $f) {
    if (!is_file($root . '/' . $f)) $missing[] = $f;
}
$checks[] = row('项目文件完整性', $missing ? 'bad' : 'ok',
    $missing ? '缺失：' . implode(', ', $missing) : count($needed) . ' 个关键文件齐全');

$ec = $root . '/public/static/echarts/6.0.0/echarts.min.js';
$checks[] = row('本地 ECharts', is_file($ec) ? 'ok' : 'warn',
    is_file($ec) ? 'echarts.min.js 已就位（' . round(filesize($ec) / 1024) . ' KB）'
                 : '未找到，报表会自动回退到服务端 SVG 图表');

$dbOk = false;
$tables = [];
$clientCols = false;
$queryCols  = false;
try {
    $dsn  = (string)($cfg['db_dsn'] ?? '');
    $user = (string)($cfg['db_user'] ?? '');
    $pass = (string)($cfg['db_pass'] ?? '');
    if ($dsn === '') throw new RuntimeException('config.php 不可读或 db_dsn 未配置');
    $pdo = new PDO($dsn, $user, $pass, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
    $dbOk = true;
    foreach (['sites', 'events', 'uv_daily'] as $t) {
        $tables[$t] = (bool)$pdo->query("SHOW TABLES LIKE '" . $t . "'")->fetch();
    }
    if ($tables['events']) {
        $clientCols = (bool)$pdo->query("SHOW COLUMNS FROM `events` LIKE 'browser'")->fetch();
        $queryCols  = (bool)$pdo->query("SHOW COLUMNS FROM `events` LIKE 'query'")->fetch();
    }
    $checks[] = row('数据库连接', 'ok', '连接成功');
    $checks[] = row('数据表', (count(array_filter($tables)) === 3) ? 'ok' : 'bad',
        'sites ' . ($tables['sites'] ? '✓' : '✗') . ' / events ' . ($tables['events'] ? '✓' : '✗')
        . ' / uv_daily ' . ($tables['uv_daily'] ? '✓' : '✗')
        . (count(array_filter($tables)) === 3 ? '' : '（请用 phpMyAdmin 导入 sql/schema.sql）'));
    $checks[] = row('客户端字段升级', $clientCols ? 'ok' : 'warn',
        $clientCols ? '已升级（语言 / 分辨率 / 来源 IP 可统计）'
                    : '未升级（浏览器 / 系统 / 设备仍可统计；需要语言、分辨率、IP 请执行 sql/upgrade_clientinfo.sql）');
    $queryOff = !empty($cfg['store_query']) && $cfg['store_query'] === false;
    $checks[] = row('URL 参数字段', ($queryCols || $queryOff) ? 'ok' : 'warn',
        $queryOff ? '已在 config.php 关闭 store_query，不保存查询参数'
        : ($queryCols ? '已升级（查询参数会脱敏后入库，报告与 CSV 可见完整地址）'
                      : '未升级（PV/UV 不受影响；要保存 ?id=123 这类参数请执行 sql/upgrade_query.sql）'));
} catch (Throwable $ex) {
    $checks[] = row('数据库连接', 'bad', $ex->getMessage());
}

// 近 7 天来源 IP 分布：找出主导 IP（frp / NAT 共享出口的典型特征）
$topIp = ''; $topPv = 0; $ipRows = 0; $distinctIp = 0; $singleIp = false;
if ($dbOk && !empty($tables['events'])) {
    try {
        $r = $pdo->query("SELECT COUNT(*) c, COUNT(DISTINCT ip) d FROM events
                          WHERE kind='pv' AND ip <> '' AND ts > DATE_SUB(NOW(), INTERVAL 7 DAY)")->fetch();
        $ipRows     = (int)($r['c'] ?? 0);
        $distinctIp = (int)($r['d'] ?? 0);
        $t = $pdo->query("SELECT ip, COUNT(*) c FROM events
                          WHERE kind='pv' AND ip <> '' AND ts > DATE_SUB(NOW(), INTERVAL 7 DAY)
                          GROUP BY ip ORDER BY c DESC LIMIT 1")->fetch();
        $topIp = (string)($t['ip'] ?? '');
        $topPv = (int)($t['c'] ?? 0);
        // 主导 IP 占比 ≥60% 即认定为共享出口；不再要求「只有一个 IP」
        $singleIp = $ipRows >= 5 && $topPv > 0 && ($topPv / max(1, $ipRows)) >= 0.6;
    } catch (Throwable $ignored) {
    }
}

// 本次请求是否走了与统计流量相同的链路。
// 从内网直连打开本页时看不到 frp 注入的头，诊断结果会误导，必须显式提示。
$samePath = ($topIp !== '' && $topIp === $ip['remote']);
$pathHint = $topIp === ''
    ? '数据库中暂无来源 IP 记录，暂时无法比对请求路径。'
    : ($samePath
        ? '本次请求的 REMOTE_ADDR 与报表中主导来源 IP（' . $topIp . '）一致，下面的诊断结果有效。'
        : '本次请求的 REMOTE_ADDR（' . ($ip['remote'] ?: '—') . '）与报表中主导来源 IP（'
          . $topIp . '）不一致 —— 你很可能是从内网直连打开本页的，'
          . '请改用下方「对外诊断地址」重新打开，否则看不到 frp 注入的请求头。');

// 是否经 Cloudflare 边缘（Tunnel 与普通反代都适用）
$isCloudflare = isset($_SERVER['HTTP_CF_RAY'])
    || isset($_SERVER['HTTP_CF_CONNECTING_IP'])
    || isset($_SERVER['HTTP_CF_VISITOR']);

// 收集所有与转发 / 客户端 IP 相关的请求头，避免非标准头名被漏判
$ipLike = [];
foreach ($_SERVER as $k => $v) {
    if (strpos($k, 'HTTP_') !== 0) continue;
    if (preg_match('/(FORWARD|REAL|CLIENT|PROXY|VIA|_IP$)/i', $k) || strpos($k, 'HTTP_CF_') === 0) {
        $ipLike[$k] = substr((string)$v, 0, 255);
    }
}

// 建议的转发头优先级：把实际出现过的别名头补进列表。
// Cloudflare 头必须排第一，否则会先读到客户端可伪造的 X-Forwarded-For。
$suggestHeaders = [
    'HTTP_CF_CONNECTING_IP', 'HTTP_TRUE_CLIENT_IP',
    'HTTP_X_FORWARDED_FOR', 'HTTP_X_REAL_IP', 'HTTP_X_FORWARDED',
    'HTTP_X_CLIENT_IP', 'HTTP_FORWARDED',
];
foreach (array_keys($ipLike) as $k) {
    if (preg_match('/(FORWARD|REAL|CLIENT)/i', $k) && !in_array($k, $suggestHeaders, true)) {
        array_unshift($suggestHeaders, $k);
    }
}
$suggestHeadersCode = "'real_ip_headers' => ["
    . implode(', ', array_map(function ($x) { return "'" . $x . "'"; }, $suggestHeaders)) . "],";

// 以「实际收到的全部相关头」判断，而不是 ip.php 的标准头集合：
// 若 frp / Nginx 用的是 X-Client-IP 之类别名，仍应提示用户而非误报「没有任何转发头」
$hasForwarded = !empty($ipLike);
$proxyFixed   = $ip['ip'] !== $ip['remote'];
$cfgBase = trim((string)($cfg['public_base_url'] ?? ''));
if ($cfgBase !== '' && !preg_match('#^https?://#i', $cfgBase)) $cfgBase = 'http://' . $cfgBase;
$diagUrl = $cfgBase !== '' ? rtrim($cfgBase, '/') . '/check.php' : '';

$baseHttps = (stripos($cfgBase, 'https://') === 0);
$cfgHost = strtolower((string)(parse_url($cfgBase, PHP_URL_HOST) ?? ''));
$privateHost = (bool)preg_match('/^(?:10\.|192\.168\.|127\.|169\.254\.|172\.(?:1[6-9]|2\d|3[01])\.)/', $cfgHost);
$reqHost = (string)($_SERVER['HTTP_HOST'] ?? '—');
$reqPort = (string)($_SERVER['SERVER_PORT'] ?? '—');
$checks[] = row('反向代理链路', $isCloudflare ? 'warn' : 'ok',
    $isCloudflare ? 'Cloudflare 边缘（检测到 CF-RAY / CF-Connecting-IP / CF-Visitor）'
                  : '未检测到 Cloudflare 头，按 frp / 直连处理');
$checks[] = row('HTTP_HOST / SERVER_PORT', 'ok', $reqHost . ' / ' . $reqPort
    . '（源站监听值，与对外地址 / 端口可以不同）');
$checks[] = row('public_base_url', $cfgBase === '' ? 'warn' : 'ok',
    $cfgBase === '' ? '未配置，将自动从请求推断（多级代理下易出错，建议显式填写）' : $cfgBase);
if ($isCloudflare && !$baseHttps) {
    $checks[] = row('HTTPS / 混合内容', 'bad',
        '对外地址仍是 HTTP（' . ($cfgBase ?: '自动推断') . '）。HTTPS 宿主页加载 HTTP 脚本与图片会被浏览器拦截，计数恒为 0；'
        . '请把 public_base_url 改成 https:// 开头的公网域名');
}
if ($isCloudflare && $privateHost) {
    $checks[] = row('对外地址填成了源站', 'bad',
        'public_base_url 是内网地址 ' . $cfgHost . '：这是 Tunnel ingress 里的 service（回源目的地址），'
        . '不是浏览器能访问的地址。填成它会导致嵌入代码指向内网 IP，公网访客全部计数失败。'
        . '请改成 https://stats.tansoo.cn 这类对外 HTTPS 域名');
}

// ===== 按站点上报诊断：复算 app/collector.php 的拒收条件，定位「某个站点零数据」卡在哪一环 =====
// 本页自包含，不能 require app/bootstrap.php，故在此复刻 origin 规范化逻辑。
function diag_origin(string $u): string {
    $p = parse_url(trim($u));
    if (!$p || empty($p['host'])) return '';
    $scheme = strtolower((string)($p['scheme'] ?? 'http'));
    if (!in_array($scheme, ['http', 'https'], true)) return '';
    $port = isset($p['port']) ? ':' . (int)$p['port'] : '';
    return $scheme . '://' . $p['host'] . $port;
}

$simRaw = trim((string)($_GET['from'] ?? ''));
if ($simRaw === '') $simRaw = 'https://www.tansoo.cn/';
if (!preg_match('#^https?://#i', $simRaw)) $simRaw = 'https://' . $simRaw;
$simOrigin = diag_origin($simRaw);

$siteDiag = [];
if ($dbOk && !empty($tables['sites'])) {
    try {
        $rows = $pdo->query(
            "SELECT s.id, s.site_key, s.name, s.origin, s.status,
                    (SELECT COUNT(*) FROM events e WHERE e.site_id=s.id AND e.kind='pv') AS pv,
                    (SELECT COUNT(*) FROM events e WHERE e.site_id=s.id AND e.kind='pv'
                      AND e.ts > DATE_SUB(NOW(), INTERVAL 1 HOUR)) AS pv1h,
                    (SELECT MAX(e.ts) FROM events e WHERE e.site_id=s.id AND e.kind='pv') AS last_pv
             FROM sites s ORDER BY s.id"
        )->fetchAll();
        foreach ($rows as $r) {
            $allow = [];
            foreach ((array)preg_split('/[\s,;]+/', (string)($r['origin'] ?? '')) as $p) {
                $o = diag_origin((string)$p);
                if ($o !== '') $allow[strtolower($o)] = $o;
            }
            if ((int)$r['status'] !== 1) {
                $verdict = 'bad';
                $why = '站点已停用，采集端直接丢弃';
            } elseif (!$allow) {
                $verdict = 'ok';
                $why = '未设置允许来源 → 放行';
            } elseif ($simOrigin !== '' && isset($allow[strtolower($simOrigin)])) {
                $verdict = 'ok';
                $why = '来源匹配 → 放行';
            } else {
                $verdict = 'bad';
                $why = '来源不匹配 → 拒收（请求照常返回 200 GIF，但一条都不入库）。当前白名单：'
                     . implode('、', $allow);
            }
            $siteDiag[] = [
                'key' => (string)$r['site_key'], 'name' => (string)$r['name'],
                'origin' => (string)($r['origin'] ?? ''), 'status' => (int)$r['status'],
                'pv' => (int)($r['pv'] ?? 0), 'pv1h' => (int)($r['pv1h'] ?? 0),
                'last' => (string)($r['last_pv'] ?? ''),
                'verdict' => $verdict, 'why' => $why,
            ];
        }
    } catch (Throwable $ignored) {
        $siteDiag = [];
    }
}

// 可复制到宿主页面 F12 控制台的排查脚本（由 h() 输出，自动做 HTML 转义）
$probeJs = <<<'JS'
(function () {
  var ss = [].slice.call(document.querySelectorAll('script[data-site]'));
  console.log('[1] 统计脚本标签：', ss.map(function (s) {
    return { src: s.getAttribute('src') || s.getAttribute('data-rocketsrc'),
             key: s.getAttribute('data-site'), type: s.type || '',
             cfasync: s.getAttribute('data-cfasync') };
  }));
  console.log('[2] 页面协议：', location.protocol, '| 混合内容风险：',
    location.protocol === 'https:' && ss.some(function (s) {
      return /^http:/i.test(s.getAttribute('src') || s.getAttribute('data-rocketsrc') || '');
    }));
  var m = document.cookie.match(/_ic_vid=[^;]*/);
  console.log('[3] vid Cookie：', m ? m[0] : '无');
  var key = ss.length ? ss[0].getAttribute('data-site') : prompt('输入站点 Key');
  var url = 'https://stats.tansoo.cn/collect.php?k=' + encodeURIComponent(key) + '&e=diag' + Date.now();
  var im = new Image();
  im.onload = function () { console.log('[4] 返回图片：链路通；是否被白名单拒收请去后台看 PV 是否 +1'); };
  im.onerror = function () { console.error('[4] 请求失败：404 / 403 / CF 挑战页 / 证书错误'); };
  im.src = url;
  console.log('[5] 目标：', url, '；另在 Network 面板过滤 collect.php 看状态码与响应头');
})();
JS;
?>
<!doctype html>
<html lang="zh-CN">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>内网访问统计 · 部署自检</title>
<style>
  body{margin:0;background:#f5f6f8;color:#252a31;font:14px/1.65 -apple-system,"Segoe UI","Microsoft YaHei",sans-serif}
  .wrap{max-width:920px;margin:28px auto 60px;padding:0 18px}
  h1{font-size:21px;margin:0 0 4px}
  h2{font-size:16px;margin:26px 0 10px}
  .muted{color:#78808c;font-size:12px}
  .panel{background:#fff;border:1px solid #e3e6eb;border-radius:10px;padding:16px 20px;margin-bottom:14px}
  table{width:100%;border-collapse:collapse}
  th,td{padding:8px 10px;border-bottom:1px solid #eef0f3;text-align:left;vertical-align:top}
  th{width:190px;font-weight:600;color:#4a505b}
  td.ok{color:#12805c}td.bad{color:#c0392b}td.warn{color:#b8770f}
  code,pre{font-family:Consolas,Monaco,monospace;font-size:12px}
  pre{background:#f7f8fa;border:1px solid #e6e9ee;border-radius:8px;padding:12px;overflow:auto;white-space:pre-wrap;word-break:break-all}
  .big{font-size:17px;font-weight:600}
  .notice{background:#fff8e8;border:1px solid #f0d9a5;border-radius:8px;padding:12px 14px;margin:10px 0}
  .good{background:#eaf7f1;border:1px solid #bfe6d3;border-radius:8px;padding:12px 14px;margin:10px 0}
  a{color:#2f6fd0}
</style>
</head>
<body>
<div class="wrap">
  <h1>部署自检</h1>
  <p class="muted">项目根目录：<?= h($root) ?>　·　本页无需登录，诊断完请删除 check.php</p>

  <h2>运行环境</h2>
  <div class="panel"><table><?= implode('', $checks) ?></table></div>

  <h2>按站点上报诊断（某个站点零数据时先看这里）</h2>
  <div class="panel">
    <p style="margin-top:0">模拟「从 <code><?= h($simOrigin ?: '—') ?></code> 页面发来一条 PV」，
       按 <code>app/collector.php</code> 的站点 Key / 启用状态 / 允许来源白名单逐项复算，
       直接给出服务端会<strong>接受</strong>还是<strong>拒收</strong>。换来源可在地址后加
       <code>?from=https://其它域名/</code>。</p>
    <table>
      <tr><th style="width:auto">站点 / Key</th><th style="width:auto">允许来源</th>
          <th style="width:auto">模拟判定</th><th style="width:auto">近 1h PV</th>
          <th style="width:auto">最后一条 PV</th><th style="width:auto">累计 PV</th></tr>
      <?php if (!$siteDiag): ?>
      <tr><td colspan="6" class="warn">读不到 sites 表（数据库未连接，或未导入 sql/schema.sql），无法做白名单判定。</td></tr>
      <?php else: ?>
      <?php foreach ($siteDiag as $d): ?>
      <tr>
        <td><strong><?= h($d['name']) ?></strong><br><code><?= h($d['key']) ?></code>
            <?php if (!$d['status']): ?><br><span style="color:#c0392b">已停用</span><?php endif; ?></td>
        <td><code><?= h($d['origin'] !== '' ? $d['origin'] : '不限制') ?></code></td>
        <td class="<?= $d['verdict'] === 'ok' ? 'ok' : 'bad' ?>">
          <?= $d['verdict'] === 'ok' ? '接受' : '拒收' ?><br>
          <span class="muted"><?= h($d['why']) ?></span></td>
        <td><?= (int)$d['pv1h'] ?></td>
        <td><?= h($d['last'] ?: '从未收到') ?></td>
        <td><?= (int)$d['pv'] ?></td>
      </tr>
      <?php endforeach; ?>
      <?php endif; ?>
    </table>
    <p class="muted" style="margin-bottom:0">本页请求实际携带：
      <code>Referer=<?= h((string)($_SERVER['HTTP_REFERER'] ?? '（无）')) ?></code> ·
      <code>Origin=<?= h((string)($_SERVER['HTTP_ORIGIN'] ?? '（无）')) ?></code> ·
      <code>Host=<?= h((string)($_SERVER['HTTP_HOST'] ?? '—')) ?></code> ·
      <code>CF-RAY=<?= h((string)($_SERVER['HTTP_CF_RAY'] ?? '（无）')) ?></code><br>
      直接在本页地址栏打开时通常没有 Referer，采集端按「头缺失放行」处理；真实 PV 由宿主页面发出、带 Referer，
      因此上表的模拟判定才是关键。出现「拒收」的站点请第一优先处理。</p>
  </div>

  <h2>客户端 IP / 反向代理链路</h2>
  <div class="panel">
    <div class="<?= $samePath ? 'good' : 'notice' ?>">
      <strong>第一步：确认本页是从「对外地址」打开的</strong><br>
      <?php if ($diagUrl): ?>
        请在浏览器打开 <a href="<?= h($diagUrl) ?>" target="_blank" rel="noopener"><code><?= h($diagUrl) ?></code></a>
        （即 <code>public_base_url</code> 那个对外地址），而不是内网直连 <code>192.168.x.x</code>。
      <?php else: ?>
        <code>public_base_url</code> 未配置，请用嵌入代码里那个域名 / IP:PORT 拼上 <code>/check.php</code> 打开本页。
      <?php endif; ?>
      <br><span class="muted"><?= h($pathHint) ?></span>
    </div>

    <table>
      <tr><th>REMOTE_ADDR（TCP 对端）</th><td><code><?= h($ip['remote'] ?: '—') ?></code></td></tr>
      <?php if ($ipLike): ?>
        <?php foreach ($ipLike as $k => $v): ?>
          <tr><th><?= h($k) ?></th><td><code><?= h($v) ?></code>
            <?php if (strtoupper($k) === strtoupper((string)($ip['source'] ?? ''))
                      || (function_exists('ic_header_label') && $ip['source'] === ic_header_label($k))): ?>
              <span class="muted">（本次解析来源）</span><?php endif; ?></td></tr>
        <?php endforeach; ?>
      <?php else: ?>
        <tr><th>转发头</th><td class="warn">本请求未携带任何 X-Forwarded-For / X-Real-IP / Forwarded / Proxy-* 头</td></tr>
      <?php endif; ?>
      <tr><th>trusted_proxies 当前值</th><td><code><?= h($trusted ? json_encode($trusted, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) : '[]（未配置，只信 REMOTE_ADDR）') ?></code></td></tr>
      <tr><th>real_ip_headers 当前值</th><td><code><?= h($headers ? json_encode($headers, JSON_UNESCAPED_SLASHES) : '默认') ?></code></td></tr>
      <tr><th>最终采用的客户端 IP</th><td class="big"><code><?= h($ip['ip'] ?: '—') ?></code>
        <span class="muted">来自 <?= h($ip['source']) ?></span></td></tr>
      <tr><th>判定说明</th><td class="<?= $proxyFixed ? 'ok' : 'warn' ?>"><?= h($ip['reason']) ?></td></tr>
    </table>

    <?php if ($proxyFixed): ?>
      <div class="good"><strong>已还原真实客户端 IP</strong>（<?= h($ip['remote']) ?> → <?= h($ip['ip']) ?>）。
        采集端写入的「来源 IP」与 UV 降级指纹都会使用还原后的地址；历史数据不会自动回填，之后新产生的 PV 才会区分开。</div>

    <?php elseif (!$trusted): ?>
      <div class="notice"><strong>就差一行配置。</strong>当前 <code>trusted_proxies</code> 是空数组，
        代码按安全设计只信 <code>REMOTE_ADDR</code>，所以即使 frp 传了 X-Forwarded-For 也会被忽略，
        所有访客都记成代理地址 <code><?= h($ip['remote'] ?: ($topIp ?: '—')) ?></code>。
        在 <code>app/config.php</code> 里把这一行改成（地址已按本次请求的 REMOTE_ADDR 自动生成）：
        <pre>'trusted_proxies' =&gt; ['<?= h($ip['remote'] ?: ($topIp ?: '代理IP')) ?>'],

<?= h($suggestHeadersCode) ?></pre>
        保存后刷新本页，「最终采用的客户端 IP」应变成真实地址。若刷新后仍不变，看下面第 ② 种情况。
        <br><span class="muted">注：只覆盖 <code>public/check.php</code> 是不够的，<code>app/ip.php</code>、
        <code>app/bootstrap.php</code>、<code>app/collector.php</code> 必须已由 frp 增量包更新，
        请参照上方「frp 真实 IP 修复包」一项。</span></div>

    <?php elseif (!$hasForwarded): ?>
      <div class="notice"><strong>trusted_proxies 已配置，但本请求没有任何转发头</strong> —— 说明你用的是 frp 的
        <code>tcp</code> / <code>https</code> 类型代理（只有 <code>http</code> 类型才会默认注入 X-Forwarded-For），
        或链路中有一层 Nginx 把头剥掉了。这种情况<strong>改 PHP 配置无效</strong>，必须按下方「② tcp / https 类型」
        启用 Proxy Protocol，或把统计服务单独改成 http 类型代理。</div>

    <?php else: ?>
      <div class="notice"><strong>已配置代理信任，也收到了转发头，但仍未还原。</strong>
        常见原因是转发头里只有代理自身地址，或 frp / Nginx 用了非标准头名。
        上方列出了本请求携带的全部相关头，请核对哪一列含有真实客户端 IP；
        若头名不在默认列表里，把 <code>app/config.php</code> 的 <code>real_ip_headers</code> 改成：
        <pre><?= h($suggestHeadersCode) ?></pre>
        若所有头的值都只是 <code><?= h($ip['remote']) ?></code>，说明 frp 没有把真实 IP 往下游传，
        需按下方「② tcp / https 类型」处理。</div>
    <?php endif; ?>

    <?php if ($singleIp): ?>
      <div class="notice"><strong>近 7 天 <?= $ipRows ?> 条 PV 中，有 <?= $topPv ?> 条（<?= round($topPv / max(1, $ipRows) * 100) ?>%）
        来自 <code><?= h($topIp) ?></code>，共出现 <?= $distinctIp ?> 个不同地址</strong> —— 典型的 frp / NAT 共享出口特征。
        修好真实 IP 后新数据才会区分开；已入库的旧数据无法回填。UV 口径不受影响（主口径是第一方 Cookie）。</div>
    <?php endif; ?>
  </div>

  <?php if ($isCloudflare): ?>
  <h2>公网暴露安全提醒</h2>
  <div class="panel">
    <div class="notice"><strong>本页正从公网经 Cloudflare 访问。</strong>统计服务一旦挂到公网域名，
      <code>/admin/</code> 后台、<code>/check.php</code> 本页也会一起暴露。原设计是内网弱口令场景，
      请务必用 Cloudflare Access（Zero Trust → Access → Applications）为 <code>/admin/*</code> 加一层登录，
      或在宝塔 Nginx 里限制后台只允许内网 IP 访问；<strong>诊断完立刻删除 <code>check.php</code></strong>。
      同时建议给 <code>/collect.php</code>、<code>/beacon.php</code> 配 WAF 速率限制——公网页面里的
      <code>site_key</code> 人人可见，Referer 白名单只能防误报，挡不住刻意刷量。</div>
  </div>
  <?php endif; ?>

  <h2>Cloudflare Tunnel 场景配置指引</h2>
  <div class="panel">
    <p style="margin-top:0"><strong>先分清三个地址，它们各填各的，混用是零数据的最常见原因</strong></p>
    <table>
      <tr><th>对外地址</th><td><code>https://stats.tansoo.cn</code> —— 填 <code>public_base_url</code>，
        会原样写进嵌入代码，由<strong>访客浏览器</strong>直接访问。必须是公网可解析的 HTTPS 域名。</td></tr>
      <tr><th>源站地址</th><td><code>http://192.168.3.29:666</code> —— 只填在 Tunnel 的
        <code>service</code> 里，是 <strong>cloudflared 回源的目的地址</strong>，浏览器永远看不到它，
        <u>绝不能填进 public_base_url</u>。源站不用配证书、不用开 SSL。</td></tr>
      <tr><th>代理出口 IP</th><td><code>REMOTE_ADDR</code>（本页上方显示，当前为
        <code><?= h($ip['remote'] ?: '—') ?></code>）—— 填 <code>trusted_proxies</code>。
        这是 <strong>cloudflared 连过来时的 TCP 对端</strong>，通常既不是 192.168.3.29，也不是 127.0.0.1。</td></tr>
    </table>

    <p><strong>① 统计服务必须也有一个公网 HTTPS 地址（最关键）</strong><br>
      HTTPS 宿主页加载 <code>http://内网IP:PORT/sdk.js</code> 会被浏览器按混合内容直接拦截，
      且公网访客根本连不上内网 IP。Cloudflare 边缘终结 TLS、回源走 HTTP，所以
      <u>源站不用配证书、也不用开 SSL</u>，只要再加一条 Public Hostname 指向内网统计服务即可。
      推荐用独立子域名（<code>stats.tansoo.cn</code>），同域路径方案要额外处理 path 前缀，更麻烦。</p>
    <pre># ~/.cloudflared/config.yml —— ingress 从上到下匹配，兜底规则必须放最后
tunnel: &lt;TUNNEL-UUID&gt;
credentials-file: /root/.cloudflared/&lt;TUNNEL-UUID&gt;.json
ingress:
  - hostname: www.tansoo.cn
    service: http://127.0.0.1:80          # 原有主站，保持不变
  - hostname: stats.tansoo.cn
    service: http://192.168.3.29:666      # 统计源站：宝塔 PHP 站点，非本机亦可
  - service: http_status:404</pre>
    <p class="muted">然后在 Zero Trust → Networks → Tunnels 里给该 hostname 加一条 Public Hostname，
      或执行 <code>cloudflared tunnel route dns &lt;名称&gt; stats.tansoo.cn</code>。
      <strong>DNS 记录、Tunnel、ingress 规则三者缺一不可。</strong>
      可用 <code>cloudflared tunnel ingress validate</code> 和
      <code>cloudflared tunnel ingress rule https://stats.tansoo.cn/sdk.js</code> 验证命中。
      cloudflared 与源站不同机时，还要确认 <strong>192.168.3.29 的 666 端口</strong>对 connector 所在机器可达
      （系统防火墙 / 宝塔「安全」页放行该端口）。</p>

    <p><strong>② 宝塔站点要认得新 Host，且确实监听 666</strong>　cloudflared 默认把 Host 保留为
      <code>stats.tansoo.cn</code>，而宝塔建站时域名填的是 IP，Nginx 按 <code>server_name</code>
      匹配不上会落到默认站点 → 404。二选一：在站点「域名」里追加
      <code>stats.tansoo.cn</code>（推荐），或在 ingress 规则里加
      <code>originRequest.httpHostHeader: 192.168.3.29:666</code> 改写 Host。
      同时确认该站点监听端口就是 <code>666</code>（宝塔 → 站点设置 → 配置文件里的 <code>listen</code>）。
      另外<strong>不要给这个站点开强制 HTTPS / SSL</strong>，回源保持 HTTP，否则 cloudflared 回源会被 301 跳转打断。</p>

    <p><strong>③ app/config.php 必改的四项</strong></p>
    <pre>'public_base_url'  =&gt; 'https://stats.tansoo.cn',
//  ↑ 对外域名，不是 http://192.168.3.29:666（那是 service 源站地址）

'trusted_proxies'  =&gt; ['<?= h($ip['remote'] ?: '代理出口IP') ?>'],
//  ↑ 就填本页上方「REMOTE_ADDR（TCP 对端）」显示的这个值。
//    它既不是源站 192.168.3.29、不是端口 666，也不是 Cloudflare 公网 IP 段，
//    而是 cloudflared 所在机器连过来时的源地址：
//      · connector 与源站不同机 → 常见 192.168.3.1（与 frp 同机时尤其可能）
//      · connector 就在 192.168.3.29 本机但走 LAN IP 回源 → 可能是 192.168.3.29
//      · connector 在 Docker → 常见 172.17.0.1 等网桥地址
//      · 本机回环回源 → 127.0.0.1 / ::1
//    支持 CIDR，例如 '192.168.3.0/24'。填错不会报错，只是真实 IP 还原不生效。
//    若这套服务还同时统计原来的 frp 内网站点，把 192.168.3.1 一并保留。

'real_ip_headers' =&gt; [
    'HTTP_CF_CONNECTING_IP', 'HTTP_TRUE_CLIENT_IP',
    'HTTP_X_FORWARDED_FOR', 'HTTP_X_REAL_IP', 'HTTP_X_FORWARDED',
    'HTTP_X_CLIENT_IP', 'HTTP_FORWARDED',
],</pre>
    <p class="muted"><strong>为什么 CF-Connecting-IP 必须排第一：</strong>它由 Cloudflare 边缘无条件覆盖，
      客户端无法伪造；而 Cloudflare 会原样转发客户端请求链中的 <code>X-Forwarded-For</code>，
      攻击者可以 prepend 任意地址。若先读 XFF，来源 IP 与限流都会被污染。
      本部署已把该头设为「权威头」，命中即直接采用。</p>

    <p><strong>④ 后台新增站点并重新复制嵌入代码</strong>　「允许来源」填
      <code>https://www.tansoo.cn</code>（带 https、不带尾斜杠）。若裸域
      <code>tansoo.cn</code> 也能访问，建议先 301 统一到 www，否则那部分 Referer 会被白名单挡掉。
      <strong>HTTPS 公网站点的旧嵌入代码必须整段替换</strong>：地址从原来的内网 <code>http://IP:PORT</code>
      变成 <code>https://stats.tansoo.cn</code>，改服务端不会让已贴出去的旧代码自动更新。
      <strong>HTTP 内网站点不必跟着改</strong>：<code>public_base_url</code> 只是全局默认值，
      已贴出去的旧代码（指向内网 / frp 地址）继续有效，只要不重新复制就不会变。
      若希望后台按站点分别生成地址，执行 <code>sql/upgrade_site_base_url.sql</code> 后，
      在「站点与嵌入代码」里给内网站点填「专用嵌入地址」<code>http://192.168.3.29:666</code> 即可。
      注意 <code>http://192.168.3.29:666</code> 作为<strong>全局</strong> public_base_url 是错的（公网访客连不上），
      但作为内网站点专用地址是正确的；HTTP 页面加载 HTTPS 统计地址也允许，只是访客需能访问该域名。</p>

    <p><strong>⑤ Cloudflare 侧要关的功能</strong>　在统计子域上加一条 Cache Rule 让
      <code>/collect.php</code>、<code>/beacon.php</code>、<code>/check.php</code> 绕过缓存
      （<code>sdk.js</code> 可以放心缓存，更新靠嵌入代码里的 <code>?v=</code> 版本号 —— 但覆盖新版 SDK 后，
      <strong>必须重新复制嵌入代码或 Purge 该文件的缓存</strong>，旧 <code>?v=</code> 仍会命中 CDN 上的旧脚本）；
      关闭 <strong>Rocket Loader</strong>（会改写 / 延迟脚本执行）、
      <strong>Bot Fight Mode / JS Challenge / 五秒盾</strong>（<code>new Image()</code> 与
      <code>sendBeacon</code> 无法完成 JS 挑战，会直接 403 导致零数据）；
      用 Access 保护 <code>/admin/*</code>。宿主页若有 CSP，还需放行统计子域的
      <code>script-src</code> / <code>img-src</code> / <code>connect-src</code>。</p>

    <p class="muted">补充：Cloudflare 会带 <code>CF-IPCountry</code>，若以后想加「访问地区」维度可直接读这个头。
      UV 口径不受影响——vid 写在宿主页面自己的第一方 Cookie 上，与统计服务域名是否同源无关；
      HTTPS 页面下新版 SDK 会自动给该 Cookie 加 <code>Secure</code>。</p>
  </div>

  <h2>frp 场景配置指引</h2>
  <div class="panel">
    <p style="margin-top:0"><strong>① 代理类型是 http（最常见）</strong>　frp 默认就会在请求里注入
      <code>X-Forwarded-For</code>，<u>不需要改 frp 配置</u>。只需在 <code>app/config.php</code> 里把代理 IP 加进
      <code>trusted_proxies</code>，然后刷新本页确认「最终采用的客户端 IP」已变化。</p>
    <pre>'trusted_proxies'  =&gt; ['<?= h($ip['remote'] ?: ($topIp ?: '代理IP')) ?>'],
<?= h($suggestHeadersCode) ?></pre>
    <p class="muted">关键：<code>trusted_proxies</code> 填的是采集端看到的 <strong>REMOTE_ADDR</strong>，
      即后台「来源 IP」里反复出现的那个地址（本部署为 <code>192.168.3.1</code>），
      不是 frp 服务器的公网 IP、也不是 frps 的 <code>bindPort</code>。填错不会有任何效果，也不会报错。</p>

    <p><strong>② 代理类型是 tcp / https，或链路里还有 Nginx 反代</strong>　HTTP 头拿不到真实 IP，需启用 Proxy Protocol。
      frpc 配置（TOML 新格式，老 INI 格式用 <code>proxy_protocol_version = "v2"</code>）：</p>
    <pre>[[proxies]]
name = "counter"
type = "tcp"                      # 或 https
localIP = "127.0.0.1"
localPort = 8899
remotePort = 8899
transport.proxyProtocolVersion = "v2"</pre>
    <p>宝塔站点「配置文件」中，让 Nginx 解析并把结果写进 PHP 可见的变量：</p>
    <pre>server {
    listen 8899 proxy_protocol;   # 原 listen 8899; 加上 proxy_protocol
    set_real_ip_from 127.0.0.1;  # 填 frpc 所在机器的 IP（即 REMOTE_ADDR）
    real_ip_header proxy_protocol;
    ...
}</pre>
    <p class="muted">注意：该端口开启 proxy_protocol 后，所有连接都必须携带 PROXY 头，直接 <code>curl</code> 本地端口会被拒绝；
      请通过 frp 访问，或另开一个不带 proxy_protocol 的 server 块供本机自测。若 Nginx 已用
      <code>real_ip_header proxy_protocol</code> 改写了 <code>$remote_addr</code>，PHP 的 REMOTE_ADDR 直接就是真实 IP，
      此时 <code>trusted_proxies</code> 可以留空。</p>

    <p><strong>③ 不想动 Nginx 端口时的替代做法（tcp 类型推荐）</strong>　在 frps 前面加一层 Nginx 反代，
      由它写入 <code>X-Forwarded-For</code>。frp 的 tcp 代理是纯 TCP 透传，HTTP 头会原样带到内网统计服务，
      因此<strong>不必改 frp 代理类型、也不用开 Proxy Protocol</strong>。假设 frps 的 remotePort 是 8003，
      把对外端口改到 8004 并由 Nginx 监听：</p>
    <pre>server {
    listen 8004;
    location / {
        proxy_pass http://127.0.0.1:8003;
        proxy_set_header Host              $host;
        proxy_set_header X-Real-IP         $remote_addr;
        proxy_set_header X-Forwarded-For   $proxy_add_x_forwarded_for;
        proxy_set_header X-Forwarded-Proto $scheme;
    }
}</pre>
    <p>然后把 <code>public_base_url</code> 和嵌入代码里的端口换成 8004。
      统计服务侧仍按 ① 配置 <code>trusted_proxies</code>（值依旧是 <code>REMOTE_ADDR</code>，即 frpc 所在机器的 IP）。
      这种方式下 frp 下游看到的还是同一个出口，但 HTTP 头里已带上真实客户端地址。</p>

    <p><strong>④ 实在拿不到真实 IP</strong>　不影响 PV / UV：UV 主口径是各站点自己的第一方 Cookie，
      限流也已改成「站点 + IP + 访客」三维度，共享出口不会再把不同用户互相挤掉。
      只是「来源 IP」这一列会一直是代理地址，可在 <code>config.php</code> 把
      <code>store_client_ip</code> 设为 <code>false</code> 关掉它。</p>
  </div>

  <?php if (!$cfgReadable): ?>
  <h2>open_basedir 报错修复</h2>
  <div class="panel">
    <p style="margin-top:0">当前 PHP 读不到 <code>app/config.php</code>，后台会报
      <code>require_once(): open_basedir restriction in effect</code>。二选一：</p>
    <p><strong>方法 1（推荐）</strong>：宝塔 → 网站 → 站点设置 → 网站目录 → 关闭「防跨站攻击(open_basedir)」，重启 PHP-FPM。</p>
    <p><strong>方法 2</strong>：编辑 <code>public/.user.ini</code>，把值改成（重启 PHP-FPM 或等约 5 分钟）：</p>
    <pre>open_basedir=<?= h($root) ?>/public/:<?= h($root) ?>/app/:/tmp/</pre>
  </div>
  <?php endif; ?>

  <h2>采集链路自检</h2>
  <div class="panel">
    <p style="margin-top:0">在能访问统计服务的机器上执行（Key 换成后台里的实际站点 Key）：</p>
    <?php
      // 优先用 config.php 里显式配置的对外地址，避免非 80 端口 / 反代下拼错
      $curlBase = trim((string)($cfg['public_base_url'] ?? ''));
      if ($curlBase === '') {
          $curlBase = 'http://' . (string)($_SERVER['HTTP_HOST'] ?? '统计IP:端口');
      } elseif (!preg_match('#^https?://#i', $curlBase)) {
          $curlBase = 'http://' . $curlBase;
      }
      $curlBase = rtrim($curlBase, '/');
      if (!preg_match('#^https?://[^/]+/.+#i', $curlBase)) {
          // 未配置子目录时，按当前脚本所在目录补齐（兼容项目放在子目录的部署）
          $dir = preg_replace('#/check\.php$#', '', (string)($_SERVER['SCRIPT_NAME'] ?? ''));
          $curlBase .= rtrim((string)$dir, '/');
      }
    ?>
    <pre># ① 不带 Referer（模拟内网代理剥离请求头）
curl -i "<?= h($curlBase) ?>/collect.php?k=站点Key"

# ② 带 HTTPS 宿主页面的 Referer（验证白名单是否放行）
curl -i -e "https://www.tansoo.cn/" "<?= h($curlBase) ?>/collect.php?k=站点Key"</pre>
    <p class="muted"><code>200 OK</code> + <code>Content-Type: image/gif</code> 只说明 PHP 执行成功，
      <strong>被白名单拒收时同样返回 200</strong>。执行后刷新本页，看上方表格里该站点「近 1h PV」是否 +1。
      若 ① +1 而 ② 不 +1，就是「允许来源」协议写错（应填 <code>https://</code> 而不是 <code>http://</code>）。
      两条测试本身各会记一条 PV，可忽略。</p>

    <p><strong>浏览器侧排查</strong>　在 <code>www.tansoo.cn</code> 页面按 F12 → Console，粘贴下面的脚本回车，
      它会打印嵌入的脚本标签、协议、vid Cookie 与一次采集请求的结果：</p>
    <pre><?= h($probeJs) ?></pre>
    <p class="muted">Network 面板过滤 <code>collect.php</code> 刷新页面：
      <strong>完全没有请求</strong> = 脚本没跑（旧 <code>http://</code> 嵌入代码被混合内容拦截、CF 缓存旧 HTML、CSP、广告拦截插件）；
      <strong>403 / 503 / 返回 HTML</strong> = Cloudflare JS Challenge / Bot Fight / WAF 或宝塔 Host 不匹配；
      <strong>200 GIF 但 PV 不增</strong> = 服务端白名单拒收，看上方表格。
      诊断完成后请删除本文件：<code>rm <?= h(__FILE__) ?></code></p>
  </div>
</div>
</body>
</html>
