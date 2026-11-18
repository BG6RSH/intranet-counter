<?php
declare(strict_types=1);
require_once __DIR__ . '/_guard.php';
require_once APP_ROOT . '/app/ua.php';
require_once APP_ROOT . '/app/chart.php';

if (isset($_GET['logout'])) {
    $_SESSION = [];
    session_destroy();
    header('Location: login.php');
    exit;
}

function ic_valid_date(string $s): bool {
    if (!preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $s, $m)) return false;
    return checkdate((int)$m[2], (int)$m[3], (int)$m[1]);
}

/** 与上一等长周期对比的升降标记 */
function ic_delta($cur, $prev): string {
    $cur = (float)$cur; $prev = (float)$prev;
    if ($prev <= 0) return '<span class="delta flat">无对比数据</span>';
    $p = ($cur - $prev) / $prev * 100;
    if ($p > 0.5)       return '<span class="delta up">↑ ' . round($p, 1) . '%</span>';
    if ($p < -0.5)      return '<span class="delta down">↓ ' . round(abs($p), 1) . '%</span>';
    return '<span class="delta flat">→ 持平</span>';
}

/**
 * 图表槽位：ECharts 容器 + 服务端 SVG 兜底。
 * 只有 charts.js 成功初始化后才加 ec-ready 类切换显示，
 * 因此本地 echarts.min.js 缺失 / 被拦截 / 浏览器禁用 JS 时不会留下空白。
 */
function ic_slot(string $id, string $fallback, string $height): string {
    return '<div class="chart-slot" data-slot="' . e($id) . '">'
        . '<div class="ec-box" id="ec-' . e($id) . '" style="height:' . e($height) . '"></div>'
        . '<div class="ec-fallback">' . $fallback . '</div>'
        . '</div>';
}

/** 给 ECharts 用的「标签 / 数值」数组，与 ic_bar_chart 的归并规则保持一致 */
function ic_bar_payload(array $data, int $limit = 8, bool $sort = true): array {
    if (!$data) return ['labels' => [], 'values' => []];
    if ($sort) arsort($data);
    $items = array_slice($data, 0, $limit, true);
    if ($sort && count($data) > $limit) {
        $rest = 0;
        foreach (array_slice($data, $limit) as $v) $rest += (int)$v;
        if ($rest > 0) $items['其余 ' . (count($data) - $limit) . ' 项'] = $rest;
    }
    return [
        'labels' => array_map('strval', array_keys($items)),
        'values' => array_map('intval', array_values($items)),
    ];
}

$pdo = db();
$sites = $pdo->query('SELECT id, name, site_key, status FROM sites ORDER BY id')->fetchAll();

$siteId = (int)($_GET['site'] ?? 0);
$range  = (string)($_GET['range'] ?? '30');
$today  = date('Y-m-d');

if ($range === 'today') {
    $from = $to = $today;
} elseif ($range === '7') {
    $from = date('Y-m-d', strtotime('-6 days'));
    $to   = $today;
} elseif ($range === 'custom') {
    $f  = (string)($_GET['from'] ?? '');
    $t2 = (string)($_GET['to'] ?? '');
    $from = ic_valid_date($f)  ? $f  : date('Y-m-d', strtotime('-29 days'));
    $to   = ic_valid_date($t2) ? $t2 : $today;
} else {
    $range = '30';
    $from = date('Y-m-d', strtotime('-29 days'));
    $to   = $today;
}
if ($from > $to) { $tmp = $from; $from = $to; $to = $tmp; }

// events 用 day，uv_daily 用 d，分别构造条件；所有值都走占位符
$w  = 'day BETWEEN ? AND ?';
$wa = [$from, $to];
$uw = 'd BETWEEN ? AND ?';
$ua = [$from, $to];
if ($siteId > 0) {
    $w .= ' AND site_id = ?';   $wa[] = $siteId;
    $uw .= ' AND site_id = ?';  $ua[] = $siteId;
}

// ---- 核心指标 ----
$q = $pdo->prepare("SELECT COUNT(*) pv, COUNT(DISTINCT NULLIF(sid,'')) sessions
                    FROM events WHERE kind='pv' AND $w");
$q->execute($wa);
$ov = $q->fetch();
$pv       = (int)($ov['pv'] ?? 0);
$sessions = (int)($ov['sessions'] ?? 0);

$q = $pdo->prepare("SELECT COUNT(DISTINCT CONCAT(site_id,'|',vid)) FROM uv_daily WHERE $uw");
$q->execute($ua);
$uv = (int)$q->fetchColumn();

$q = $pdo->prepare("SELECT AVG(duration) FROM events
                    WHERE kind='unload' AND duration > 0 AND $w");
$q->execute($wa);
$avg = $q->fetchColumn();
$avgText = $avg === null || $avg === false ? '—' : round((float)$avg) . ' 秒';

// ---- 按日趋势：先铺满区间内的日期，再填数据，最多展示 366 天 ----
$trend = [];
$cursor = strtotime($from);
$end    = strtotime($to);
$guard  = 0;
while ($cursor <= $end && $guard < 366) {
    $trend[date('Y-m-d', $cursor)] = ['pv' => 0, 'uv' => 0];
    $cursor = strtotime('+1 day', $cursor);
    $guard++;
}
$q = $pdo->prepare("SELECT day, COUNT(*) pv FROM events WHERE kind='pv' AND $w GROUP BY day");
$q->execute($wa);
foreach ($q as $r) {
    if (isset($trend[$r['day']])) $trend[$r['day']]['pv'] = (int)$r['pv'];
}
$q = $pdo->prepare("SELECT d, COUNT(DISTINCT CONCAT(site_id,'|',vid)) uv
                    FROM uv_daily WHERE $uw GROUP BY d");
$q->execute($ua);
foreach ($q as $r) {
    if (isset($trend[$r['d']])) $trend[$r['d']]['uv'] = (int)$r['uv'];
}

// KPI 卡片迷你趋势线：会话与停留时长也要按日序列
$sparkPv = $sparkUv = $sparkSess = $sparkDur = [];
foreach ($trend as $d => $r) {
    $sparkPv[$d]   = (int)$r['pv'];
    $sparkUv[$d]   = (int)$r['uv'];
    $sparkSess[$d] = 0;
    $sparkDur[$d]  = 0;
}
$q = $pdo->prepare("SELECT day, COUNT(DISTINCT NULLIF(sid,'')) c FROM events
                    WHERE kind='pv' AND $w GROUP BY day");
$q->execute($wa);
foreach ($q as $r) {
    if (isset($sparkSess[$r['day']])) $sparkSess[$r['day']] = (int)$r['c'];
}
$q = $pdo->prepare("SELECT day, AVG(duration) a FROM events
                    WHERE kind='unload' AND duration > 0 AND $w GROUP BY day");
$q->execute($wa);
foreach ($q as $r) {
    if (isset($sparkDur[$r['day']])) $sparkDur[$r['day']] = round((float)$r['a'], 1);
}

// ---- 环比：与紧邻的上一等长周期比较 ----
$dayCount = max(1, count($trend));
$prevTo   = date('Y-m-d', strtotime($from . ' -1 day'));
$prevFrom = date('Y-m-d', strtotime($prevTo . ' -' . ($dayCount - 1) . ' days'));
$pw  = 'day BETWEEN ? AND ?';  $pwa = [$prevFrom, $prevTo];
$puw = 'd BETWEEN ? AND ?';    $pua = [$prevFrom, $prevTo];
if ($siteId > 0) {
    $pw .= ' AND site_id = ?';  $pwa[] = $siteId;
    $puw .= ' AND site_id = ?'; $pua[] = $siteId;
}
$q = $pdo->prepare("SELECT COUNT(*) pv, COUNT(DISTINCT NULLIF(sid,'')) sessions
                    FROM events WHERE kind='pv' AND $pw");
$q->execute($pwa);
$pov = $q->fetch();
$prevPv       = (int)($pov['pv'] ?? 0);
$prevSessions = (int)($pov['sessions'] ?? 0);
$q = $pdo->prepare("SELECT COUNT(DISTINCT CONCAT(site_id,'|',vid)) FROM uv_daily WHERE $puw");
$q->execute($pua);
$prevUv = (int)$q->fetchColumn();
$q = $pdo->prepare("SELECT AVG(duration) FROM events
                    WHERE kind='unload' AND duration > 0 AND $pw");
$q->execute($pwa);
$prevAvgRaw = $q->fetchColumn();
$prevAvg = ($prevAvgRaw === null || $prevAvgRaw === false) ? 0.0 : (float)$prevAvgRaw;
$curAvg  = ($avg === null || $avg === false) ? 0.0 : (float)$avg;

// ---- 访问时段分布（直接复用已存的 ts，无需升级表）----
$hourly = array_fill(0, 24, 0);
$q = $pdo->prepare("SELECT HOUR(ts) h, COUNT(*) c FROM events WHERE kind='pv' AND $w GROUP BY HOUR(ts)");
$q->execute($wa);
foreach ($q as $r) {
    $h = (int)$r['h'];
    if ($h >= 0 && $h < 24) $hourly[$h] = (int)$r['c'];
}
$hourlyLabels = [];
for ($h = 0; $h < 24; $h++) $hourlyLabels[sprintf('%02d:00', $h)] = $hourly[$h];

// ---- 站点 PV 对比（仅在「全部站点」且站点数 > 1 时有意义）----
$sitePv = [];
if ($siteId === 0 && count($sites) > 1) {
    $q = $pdo->prepare("SELECT site_id, COUNT(*) c FROM events
                        WHERE kind='pv' AND $w GROUP BY site_id");
    $q->execute($wa);
    $nameMap = [];
    foreach ($sites as $s) $nameMap[(int)$s['id']] = (string)$s['name'];
    foreach ($q as $r) {
        $nm = $nameMap[(int)$r['site_id']] ?? ('站点 #' . (int)$r['site_id']);
        $sitePv[$nm] = ($sitePv[$nm] ?? 0) + (int)$r['c'];
    }
    arsort($sitePv);
}

// ---- 客户端维度：按已存的 ua 分组后在 PHP 侧解析，历史数据同样能显示 ----
$browsers = $oses = $devices = $langs = $screens = $uaTop = $ipTop = [];
$q = $pdo->prepare("SELECT ua, COUNT(*) c FROM events WHERE kind='pv' AND $w GROUP BY ua");
$q->execute($wa);
foreach ($q as $r) {
    $p = ic_parse_ua((string)$r['ua']);
    $c = (int)$r['c'];
    $browsers[$p['browser']] = ($browsers[$p['browser']] ?? 0) + $c;
    $oses[$p['os']]          = ($oses[$p['os']] ?? 0) + $c;
    $devices[$p['device']]   = ($devices[$p['device']] ?? 0) + $c;
    $uaTop[] = ['ua' => (string)$r['ua'], 'c' => $c, 'label' => ic_client_label($p)];
}
arsort($browsers);
arsort($oses);
arsort($devices);
usort($uaTop, function ($a, $b) { return $b['c'] <=> $a['c']; });
$uaTop = array_slice($uaTop, 0, 10);

// 语言 / 分辨率 / 来源 IP 需要升级后的新列
$hasClient = ic_has_client_columns();
$hasQuery  = ic_has_query_column() && (bool)cfg('store_query', true);
$storeIp   = (bool)cfg('store_client_ip', false);
if ($hasClient) {
    $q = $pdo->prepare("SELECT lang, screen, COUNT(*) c FROM events
                        WHERE kind='pv' AND $w GROUP BY lang, screen");
    $q->execute($wa);
    foreach ($q as $r) {
        $c = (int)$r['c'];
        $l = (string)$r['lang']   !== '' ? (string)$r['lang']   : '未知';
        $s = (string)$r['screen'] !== '' ? (string)$r['screen'] : '未知';
        $langs[$l]   = ($langs[$l] ?? 0) + $c;
        $screens[$s] = ($screens[$s] ?? 0) + $c;
    }
    arsort($langs);
    arsort($screens);

    if ($storeIp) {
        $q = $pdo->prepare("SELECT ip, COUNT(*) c FROM events
                            WHERE kind='pv' AND $w AND ip <> '' GROUP BY ip ORDER BY c DESC LIMIT 10");
        $q->execute($wa);
        foreach ($q as $r) $ipTop[(string)$r['ip']] = (int)$r['c'];
    }
}

// ---- 反向代理 / frp 共享出口识别 ----
// 典型症状：所有访客的「来源 IP」都是同一个 frp 服务器地址。
// PV/UV 不受影响（UV 主口径是第一方 Cookie），但来源 IP 维度与限流需要修正。
$trustedRules = (array)cfg('trusted_proxies', []);
$ipInfo       = client_ip_info();
$proxyNotice      = '';
$proxyConfigLine  = '';
$ipTotal = array_sum($ipTop);
// 主导 IP 占比 ≥60% 即判定为共享出口。早期版本要求「只有一个 IP」，
// 混入少量直连/历史记录时就不会提示，导致 frp 出口被误当成真实用户。
if ($storeIp && $hasClient && $ipTop && $ipTotal >= 5) {
    $onlyIp = (string)array_key_first($ipTop);
    $topPv  = (int)reset($ipTop);
    $share  = (int)round($topPv / max(1, $ipTotal) * 100);
    if ($share >= 60) {
        $proxyNotice = '所选区间 ' . $ipTotal . ' 次访问中有 ' . $topPv . ' 次（' . $share
            . '%）来自 ' . $onlyIp . '，通常是 frp / NAT 共享出口而非真实客户端。'
            . 'PV 与 UV 不受影响（UV 主口径是第一方 Cookie），但「来源 IP」会失真。';
        if (!$trustedRules) {
            $proxyConfigLine = "'trusted_proxies' => ['" . $onlyIp . "'],";
            $proxyNotice .= ' 请在 app/config.php 补上下方这一行（填的是采集端看到的 REMOTE_ADDR，不是 frp 公网 IP），'
                . '并用部署自检页确认 frp 是否传了转发头；修复前已入库的历史数据无法回填。';
        } else {
            $proxyNotice .= ' trusted_proxies 已配置，请用部署自检页确认 frp 是否真的传了 X-Forwarded-For'
                . '（tcp 类型代理不会传，需开 Proxy Protocol 或在 frps 前加一层 Nginx 写头）。';
        }
    }
}
if ($proxyNotice === '' && !$trustedRules && $ipInfo['present'] && !$ipInfo['trusted']) {
    $proxyNotice = '当前请求带有转发头，但尚未配置 trusted_proxies，采集端仍会把代理 IP 当作客户端 IP。'
        . '请在 app/config.php 里把代理 IP 加进 trusted_proxies。';
    $proxyConfigLine = "'trusted_proxies' => ['" . (string)($ipInfo['remote'] ?? '') . "'],";
}
// 命中可信代理白名单的旧数据，标成「代理出口」以免被误读成某个真实用户
$ipLabel = function ($v) use ($trustedRules): string {
    $v = (string)$v;
    return ($v !== '' && ic_ip_trusted($v, $trustedRules)) ? '代理出口 ' . $v : $v;
};

// CSV 导出必须在任何 HTML 输出之前（图表只是可视化，导出仍保留完整明细数据）
if (isset($_GET['export'])) {
    // 生产环境若开着 display_errors，任何警告 / 弃用提示都会以 HTML 形式混进 CSV。
    // 这里只关闭「显示」，错误仍照常写入 PHP 错误日志；并清掉已有的意外缓冲输出。
    @ini_set('display_errors', '0');
    if (ob_get_level() > 0) { @ob_clean(); }

    header('Content-Type: text/csv; charset=UTF-8');
    header('Content-Disposition: attachment; filename="counter_' . $from . '_' . $to . '.csv"');
    header('Cache-Control: no-store');
    echo "\xEF\xBB\xBF";
    $out = fopen('php://output', 'w');

    // PHP 8.4 起 fputcsv() 省略第 5 个参数 $escape 会抛 Deprecated。
    // 显式传 '' 禁用 PHP 专有的反斜杠转义，输出符合 RFC 4180（引号双写），
    // 且 PHP 7.4+ 就支持空字符串，不会引入版本兼容问题。
    $csv = function (array $row) use ($out): void {
        fputcsv($out, $row, ',', '"', '');
    };

    $csv(['日期', 'PV', '独立访客']);
    foreach ($trend as $day => $r) $csv([$day, $r['pv'], $r['uv']]);
    fwrite($out, "\r\n");
    $csv(['时段', 'PV']);
    foreach ($hourlyLabels as $h => $c) $csv([$h, $c]);
    if ($sitePv) {
        fwrite($out, "\r\n");
        $csv(['站点', 'PV']);
        foreach ($sitePv as $k => $v) $csv([$k, $v]);
    }
    if ($hasQuery) {
        // 完整地址（path + 脱敏后的 query）单独成段，不与按纯路径聚合的热门页面混淆
        $q = $pdo->prepare("SELECT path, `query`, COUNT(*) pv FROM events
                            WHERE kind='pv' AND $w GROUP BY path, `query`
                            ORDER BY pv DESC LIMIT 20");
        $q->execute($wa);
        $pages = $q->fetchAll();
        if ($pages) {
            fwrite($out, "\r\n");
            $csv(['访问地址（含参数，敏感值已打码）', 'PV']);
            foreach ($pages as $r) {
                $csv([(string)$r['path'] . (string)($r['query'] ?? ''), (int)$r['pv']]);
            }
        }
    }
    foreach ([
        '浏览器' => $browsers, '操作系统' => $oses, '设备类型' => $devices,
        '语言' => $langs, '屏幕分辨率' => $screens, '来源 IP' => $ipTop,
    ] as $title => $data) {
        fwrite($out, "\r\n");
        $csv([$title, 'PV']);
        foreach ($data as $k => $v) $csv([$k, $v]);
    }
    fclose($out);
    exit;
}

$q = $pdo->prepare("SELECT path, COUNT(*) pv, MAX(title) title FROM events
                    WHERE kind='pv' AND $w GROUP BY path ORDER BY pv DESC LIMIT 10");
$q->execute($wa);
$top = $q->fetchAll();
$topRows = [];
foreach ($top as $r) {
    $topRows[] = [
        'label' => (string)$r['path'],
        'sub'   => (string)($r['title'] ?? ''),
        'value' => (int)$r['pv'],
    ];
}

$rw = 'e.day BETWEEN ? AND ?';
$ra = [$from, $to];
if ($siteId > 0) { $rw .= ' AND e.site_id = ?'; $ra[] = $siteId; }
$clientCols = $hasClient
    ? ', e.browser, e.browser_ver, e.os, e.device, e.ip, e.lang, e.screen'
    : '';
if ($hasQuery) $clientCols .= ', e.`query`';
$q = $pdo->prepare("SELECT e.ts, e.path, e.title, e.vid, e.ua, s.name site_name $clientCols
                    FROM events e LEFT JOIN sites s ON s.id = e.site_id
                    WHERE e.kind='pv' AND $rw ORDER BY e.id DESC LIMIT 20");
$q->execute($ra);
$recent = $q->fetchAll();

// 折线图右上角的摘要
$peakDay = ''; $peakPv = 0; $activeDays = 0;
foreach ($trend as $d => $r) {
    if ($r['pv'] > $peakPv) { $peakPv = $r['pv']; $peakDay = $d; }
    if ($r['pv'] > 0) $activeDays++;
}
$dailyAvg = round($pv / $dayCount, 1);
$lineSummary = [
    ['区间合计', $pv . ' PV'],
    ['日均', ic_f($dailyAvg) . ' PV'],
    ['峰值', $peakPv > 0 ? substr($peakDay, 5) . ' · ' . $peakPv : '—'],
    ['有访问天数', $activeDays . ' / ' . $dayCount],
];

// 客户端各维度统一渲染成柱状对比图，按两列排布；第一项为图表槽位 id
$clientCharts = [
    ['browser', '浏览器',     $browsers, '#2f6fd0', '按 User-Agent 解析，同浏览器不同版本已合并；历史数据同样有效。'],
    ['os',      '操作系统',   $oses,     '#7057c8', 'Windows 11 的 UA 仍上报 NT 10.0，故显示为「Windows 10/11」。'],
    ['device',  '设备类型',   $devices,  '#17a673', '由 UA 中的设备标识推断，桌面 / 手机 / 平板 / 工具。'],
    ['lang',    '浏览器语言', $langs,    '#d9822b', $hasClient ? '由 SDK 上报，仅对执行升级 SQL 之后产生的数据有效。' : '需执行 sql/upgrade_clientinfo.sql 后才有数据。'],
    ['screen',  '屏幕分辨率', $screens,  '#2b8fb3', $hasClient ? '由 SDK 上报，仅对执行升级 SQL 之后产生的数据有效。' : '需执行 sql/upgrade_clientinfo.sql 后才有数据。'],
];
if ($storeIp && $hasClient) {
    $ipTopDisplay = [];
    foreach ($ipTop as $k => $v) $ipTopDisplay[$ipLabel($k)] = $v;
    $clientCharts[] = ['ip', '来源 IP Top 10', $ipTopDisplay, '#b0537c',
        '明文 IP 属个人信息，仅在 config.php 开启后采集与展示。经 frp 反代时需配置 trusted_proxies 才能还原真实地址；带「代理出口」标注的是修复前的历史数据。'];
}

// ---- 交给 ECharts 的数据：放进 application/json 块，不拼接内联 JS，兼容 CSP ----
$ecData = [
    'trend' => [
        'type'   => 'line',
        'labels' => array_map('strval', array_keys($trend)),
        'series' => [
            ['name' => 'PV 浏览量',   'data' => array_values($sparkPv), 'color' => '#2f6fd0', 'area' => true],
            ['name' => 'UV 独立访客', 'data' => array_values($sparkUv), 'color' => '#17a673', 'area' => false],
        ],
    ],
    'pages' => [
        'type'   => 'hbar',
        'labels' => array_column($topRows, 'label'),
        'subs'   => array_column($topRows, 'sub'),
        'values' => array_column($topRows, 'value'),
    ],
    'hours' => array_merge([
        'type' => 'bar', 'color' => '#2b8fb3', 'rotate' => false,
        'labelEvery' => 3, 'showValues' => false, 'unit' => 'PV',
    ], ic_bar_payload($hourlyLabels, 24, false)),
];
if ($sitePv) {
    $ecData['sites'] = array_merge([
        'type' => 'bar', 'color' => '#7057c8', 'rotate' => true,
        'labelEvery' => 1, 'showValues' => true, 'unit' => 'PV',
    ], ic_bar_payload($sitePv, 8));
}
foreach ($clientCharts as $c) {
    $ecData['cc-' . $c[0]] = array_merge([
        'type' => 'bar', 'color' => $c[3], 'rotate' => true,
        'labelEvery' => 1, 'showValues' => true, 'unit' => 'PV',
    ], ic_bar_payload($c[2], 8));
}
$ecJson = json_encode(
    $ecData,
    JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_UNICODE
);
if ($ecJson === false) $ecJson = '{}';
$pagesHeight = max(180, count($topRows) * 34 + 24) . 'px';

$siteName = '全部站点';
foreach ($sites as $s) if ((int)$s['id'] === $siteId) $siteName = $s['name'];

$exportQuery = http_build_query([
    'site' => $siteId, 'range' => $range, 'from' => $from, 'to' => $to, 'export' => 1,
]);
?>
<!doctype html>
<html lang="zh-CN">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>访问统计 · 内网访问统计</title>
<link rel="stylesheet" href="style.css">
</head>
<body>
<div class="wrap">
<header class="top">
  <h1>内网访问统计</h1>
  <nav>
    <a href="index.php">报表</a>
    <a href="sites.php">站点与嵌入代码</a>
    <a href="index.php?logout=1">退出</a>
  </nav>
</header>

<form class="filters" method="get" action="index.php">
  <div><label for="site">站点</label>
    <select id="site" name="site">
      <option value="0"<?= $siteId === 0 ? ' selected' : '' ?>>全部站点</option>
      <?php foreach ($sites as $s): ?>
        <option value="<?= (int)$s['id'] ?>"<?= (int)$s['id'] === $siteId ? ' selected' : '' ?>><?= e($s['name']) ?></option>
      <?php endforeach; ?>
    </select>
  </div>
  <div><label for="range">时间范围</label>
    <select id="range" name="range">
      <option value="today"<?= $range === 'today' ? ' selected' : '' ?>>今天</option>
      <option value="7"<?= $range === '7' ? ' selected' : '' ?>>近 7 天</option>
      <option value="30"<?= $range === '30' ? ' selected' : '' ?>>近 30 天</option>
      <option value="custom"<?= $range === 'custom' ? ' selected' : '' ?>>自定义</option>
    </select>
  </div>
  <div><label for="from">开始日期</label><input id="from" type="date" name="from" value="<?= e($from) ?>"></div>
  <div><label for="to">结束日期</label><input id="to" type="date" name="to" value="<?= e($to) ?>"></div>
  <div><button type="submit">查询</button></div>
  <div><a class="btn ghost" href="index.php?<?= e($exportQuery) ?>">导出 CSV</a></div>
</form>

<h2><?= e($siteName) ?> · <?= e($from) ?> ~ <?= e($to) ?></h2>
<div class="cards">
  <div class="card">
    <div class="card-top">
      <div><div class="label">浏览量 PV</div><div class="value"><?= $pv ?></div></div>
      <?= ic_sparkline($sparkPv, '#2f6fd0') ?>
    </div>
    <div class="card-meta">较上一周期 <?= ic_delta($pv, $prevPv) ?></div>
  </div>
  <div class="card">
    <div class="card-top">
      <div><div class="label">独立访客 UV</div><div class="value"><?= $uv ?></div></div>
      <?= ic_sparkline($sparkUv, '#17a673') ?>
    </div>
    <div class="card-meta">较上一周期 <?= ic_delta($uv, $prevUv) ?></div>
  </div>
  <div class="card">
    <div class="card-top">
      <div><div class="label">会话数</div><div class="value"><?= $sessions ?></div></div>
      <?= ic_sparkline($sparkSess, '#7057c8') ?>
    </div>
    <div class="card-meta">较上一周期 <?= ic_delta($sessions, $prevSessions) ?></div>
  </div>
  <div class="card">
    <div class="card-top">
      <div><div class="label">平均停留</div><div class="value"><?= e($avgText) ?></div></div>
      <?= ic_sparkline($sparkDur, '#d9822b') ?>
    </div>
    <div class="card-meta">较上一周期 <?= ic_delta($curAvg, $prevAvg) ?></div>
  </div>
</div>

<h2>访问趋势 <span class="h2-note">PV / UV 双序列折线 · 悬停查看数值，图例可点击切换</span></h2>
<div class="trend-summary">
  <?php foreach ($lineSummary as $x): ?>
  <span><?= e($x[0]) ?> <b><?= e($x[1]) ?></b></span>
  <?php endforeach; ?>
</div>
<div class="panel chart-panel">
  <?= ic_slot('trend', ic_line_chart(array_keys($trend), [
      ['name' => 'PV 浏览量',   'data' => $sparkPv, 'color' => '#2f6fd0', 'fill' => true],
      ['name' => 'UV 独立访客', 'data' => $sparkUv, 'color' => '#17a673'],
  ], ['aria' => '按日 PV 与 UV 趋势折线图']), '320px') ?>
  <details class="fold inline-fold">
    <summary>查看逐日精确数值（<?= count($trend) ?> 天）</summary>
    <table class="compact">
      <thead><tr><th>日期</th><th class="num">PV</th><th class="num">UV</th></tr></thead>
      <tbody>
      <?php foreach ($trend as $day => $r): ?>
        <tr><td><?= e($day) ?></td><td class="num"><?= (int)$r['pv'] ?></td><td class="num"><?= (int)$r['uv'] ?></td></tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  </details>
</div>

<h2>热门页面 Top 10 <span class="h2-note">按纯路径聚合 · 悬停查看路径与标题；带参数的完整地址见「最近访问」与 CSV</span></h2>
<div class="panel">
  <?= ic_slot('pages', ic_hbar_chart($topRows), $pagesHeight) ?>
</div>

<h2>访问时段与站点对比</h2>
<div class="grid2">
  <div class="panel">
    <h3>访问时段分布（0–23 时）</h3>
    <?= ic_slot('hours', ic_bar_chart($hourlyLabels, [
        'limit' => 24, 'color' => '#2b8fb3', 'rotate' => false, 'label_every' => 3,
        'sort' => false, 'show_values' => false,
        'aria' => '24 小时访问时段分布柱状图',
    ]), '260px') ?>
    <p class="chart-foot">按页面浏览事件的服务端记录时间统计，可直接反映办公时段集中程度。</p>
  </div>
  <div class="panel">
    <h3>各站点 PV 对比</h3>
    <?php if ($sitePv): ?>
      <?= ic_slot('sites', ic_bar_chart($sitePv, [
          'limit' => 8, 'color' => '#7057c8',
          'aria' => '各站点浏览量对比柱状图',
      ]), '260px') ?>
      <p class="chart-foot">仅统计所选区间内有访问的站点；筛选单个站点时不显示此图。</p>
    <?php else: ?>
      <p class="muted">当前按单个站点筛选，或已配置的站点不足 2 个，暂无可对比数据。切回「全部站点」即可查看各站点对比。</p>
    <?php endif; ?>
  </div>
</div>

<?php if ($proxyNotice): ?>
<div class="notice warn-notice">
  <strong>客户端 IP 经反向代理</strong>　<?= e($proxyNotice) ?>
  <?php if ($proxyConfigLine): ?>
    <p style="margin:8px 0 0"><code style="background:#fff;padding:4px 8px;border-radius:4px;display:inline-block"><?= e($proxyConfigLine) ?></code></p>
  <?php endif; ?>
  <a href="../check.php" target="_blank" rel="noopener">用对外地址打开部署自检页 →</a>
</div>
<?php endif; ?>

<h2>客户端分布 <span class="h2-note">柱状对比 · 悬停查看占比</span></h2>
<?php foreach (array_chunk($clientCharts, 2) as $chunk): ?>
<div class="grid2 chart-grid">
  <?php foreach ($chunk as $c): ?>
  <div class="panel">
    <h3><?= e($c[1]) ?></h3>
    <?= ic_slot('cc-' . $c[0], ic_bar_chart($c[2], [
        'limit' => 8, 'color' => $c[3], 'aria' => $c[1] . '分布柱状对比图',
    ]), '260px') ?>
    <p class="chart-foot"><?= e($c[4]) ?></p>
  </div>
  <?php endforeach; ?>
</div>
<?php endforeach; ?>

<?php if (!$hasClient || !$storeIp || !$hasQuery): ?>
<p class="notice" style="margin-top:16px">
  <?php if (!$hasClient): ?>
    语言、屏幕分辨率与来源 IP 需要新字段：在数据库执行 <code>sql/upgrade_clientinfo.sql</code> 后，
    新产生的数据即可显示。浏览器 / 系统 / 设备分布不受影响（按已存 UA 实时解析，历史数据同样有效）。
  <?php endif; ?>
  <?php if (!$hasQuery): ?>
    <?php if (!(bool)cfg('store_query', true)): ?>
      已在 <code>app/config.php</code> 关闭 <code>store_query</code>，因此不保存 URL 查询参数。
    <?php else: ?>
      要保存 URL 查询参数（如 <code>?id=123</code>），在数据库执行 <code>sql/upgrade_query.sql</code>；
      敏感参数会自动打码。之后新产生的数据即可在「最近访问」与 CSV 导出里看到完整地址。
    <?php endif; ?>
  <?php endif; ?>
  <?php if ($hasClient && !$storeIp): ?>
    客户端 IP 默认只存哈希，因此不显示来源 IP。需要内网排障时，可在 <code>app/config.php</code>
    把 <code>store_client_ip</code> 设为 <code>true</code>（请按单位隐私要求决定）。
  <?php endif; ?>
</p>
<?php endif; ?>

<h2>明细数据</h2>
<details class="fold">
  <summary>原始 UA Top 10 <span class="muted">· 查看完整 User-Agent</span></summary>
  <div class="panel">
  <table>
  <thead><tr><th>识别结果</th><th>原始 User-Agent</th><th class="num">PV</th></tr></thead>
  <tbody>
  <?php if (!$uaTop): ?><tr><td colspan="3" class="muted">该区间暂无数据</td></tr><?php endif; ?>
  <?php foreach ($uaTop as $r): ?>
    <tr>
      <td><?= e($r['label']) ?></td>
      <td class="ellipsis muted" title="<?= e($r['ua']) ?>"><code><?= e($r['ua'] !== '' ? $r['ua'] : '（未提供）') ?></code></td>
      <td class="num"><?= (int)$r['c'] ?></td>
    </tr>
  <?php endforeach; ?>
  </tbody>
  </table>
  </div>
</details>

<details class="fold">
  <summary>最近访问（20 条）<span class="muted">· 逐条明细<?= $hasQuery ? '，含已脱敏的 URL 参数' : '' ?></span></summary>
  <div class="panel">
  <table>
  <thead><tr><th>时间</th><th>站点</th><th><?= $hasQuery ? '访问地址（含参数，已脱敏）' : '路径' ?></th><th>浏览器 / 系统</th><th>设备</th><th>访客</th><?php if ($storeIp && $hasClient): ?><th>来源 IP</th><?php endif; ?></tr></thead>
  <tbody>
  <?php if (!$recent): ?><tr><td colspan="<?= $storeIp && $hasClient ? 7 : 6 ?>" class="muted">该区间暂无数据</td></tr><?php endif; ?>
  <?php foreach ($recent as $r): ?>
  <?php
    $full = (string)$r['path'] . ($hasQuery ? (string)($r['query'] ?? '') : '');
    $p  = ic_parse_ua((string)($r['ua'] ?? ''));
    $bl = $hasClient && (string)($r['browser'] ?? '') !== '' ? (string)$r['browser'] : $p['browser'];
    $bv = $hasClient ? (string)($r['browser_ver'] ?? '') : $p['browser_ver'];
    $ol = $hasClient && (string)($r['os'] ?? '') !== '' ? (string)$r['os'] : $p['os'];
    $dl = $hasClient && (string)($r['device'] ?? '') !== '' ? (string)$r['device'] : $p['device'];
  ?>
    <tr>
      <td><?= e($r['ts']) ?></td>
      <td><?= e($r['site_name'] ?? '—') ?></td>
      <td class="ellipsis" title="<?= e($full) ?>"><?= e($full) ?></td>
      <td class="ellipsis" title="<?= e($r['ua'] ?? '') ?>"><?= e($bl . ($bv !== '' ? ' ' . $bv : '') . ' / ' . $ol) ?></td>
      <td><?= e($dl) ?></td>
      <td><code><?= e(substr((string)$r['vid'], 0, 8)) ?></code></td>
      <?php if ($storeIp && $hasClient): ?><td><code><?= e($ipLabel($r['ip'] ?? '')) ?></code></td><?php endif; ?>
    </tr>
  <?php endforeach; ?>
  </tbody>
  </table>
  <p class="muted" style="margin-bottom:0">鼠标悬停在「浏览器 / 系统」列可查看完整原始 UA；连通性自检的 curl 请求会显示为「工具/脚本」。</p>
  </div>
</details>

<details class="fold">
  <summary>口径说明 <span class="muted">· 指标定义与隐私策略</span></summary>
  <div class="notice">
    PV 按每次页面加载或 SPA 路由切换计一次，靠事件 ID 幂等去重；UV 按「站点 + 日期 + 访客标识」精确去重，
    区间 UV 为各站点独立访客之和（不是每日 UV 简单相加）。访客标识优先来自各站点自己的第一方 Cookie；
    浏览器禁用 Cookie 时退化为 <code>IP + UA + 语言 + 分辨率 + 日期</code> 的服务端指纹（以 <code>f</code> 开头），此时为估算值。
    若统计服务经 frp / Nginx 反代，需在 <code>config.php</code> 配置 <code>trusted_proxies</code> 才能从转发头还原真实客户端 IP；
    未还原时所有访客共享一个出口 IP，来源 IP 维度会失真，但 PV/UV 仍准确（限流按「站点 + IP + 访客」三维度，不会互相挤掉）。
    停留时长来自页面卸载上报，部分浏览器关闭时不发送，仅供参考。
    页面地址保存纯 path 与查询参数（需执行 <code>sql/upgrade_query.sql</code>）；token、密码、key、session、
    code、签名等敏感参数的值会自动打码为 <code>***</code>，可在 <code>config.php</code> 的
    <code>query_extra_sensitive</code> 补充本站特有的敏感参数名。热门页面仍按纯路径聚合，
    避免同一页面因参数不同被拆散；带参数的完整地址见「最近访问」明细与 CSV 导出。
    IP 默认只存哈希（要明文 IP 可在 <code>config.php</code> 开启 <code>store_client_ip</code>）。
    客户端信息由服务端按 User-Agent 解析，覆盖 Chrome / Edge / IE / Firefox / Safari 及微信、QQ、360、搜狗等常见内核；
    Windows 11 的 UA 仍上报 NT 10.0，故统一显示为「Windows 10/11」；curl 等工具请求归为「工具/脚本」。
    浏览器语言与屏幕分辨率由 SDK 上报，仅对执行升级 SQL 之后产生的数据有效。
    「较上一周期」对比的是紧邻本区间之前、长度相同的周期；上一周期无数据时不显示百分比。
  </div>
</details>

<p class="muted footer-note" id="ic-engine-note">图表引擎检测中… 若 ECharts 本地脚本未加载或初始化失败，页面会自动回退到服务端生成的 SVG 图表（离线可用、不依赖任何外部资源）。CSV 导出仍包含逐日与全部维度明细。</p>
</div>

<!-- 数据放在 JSON 块里传递，不拼接内联 JS；路径相对 admin/，兼容子目录部署 -->
<script type="application/json" id="ic-chart-data"><?= $ecJson ?></script>
<script src="../static/echarts/6.0.0/echarts.min.js"></script>
<script src="charts.js"></script>
</body>
</html>
