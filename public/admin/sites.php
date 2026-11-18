<?php
declare(strict_types=1);
require_once __DIR__ . '/_guard.php';
require_once APP_ROOT . '/app/collector.php';

$pdo = db();
$msg = '';
$ok  = true;

// 站点级嵌入地址是可选升级：未执行 sql/upgrade_site_base_url.sql 时自动降级为全局地址
$hasSiteBase = false;
try {
    $hasSiteBase = (bool)$pdo->query("SHOW COLUMNS FROM `sites` LIKE 'base_url'")->fetch();
} catch (Throwable $ex) {
    $hasSiteBase = false;
}

/** 站点级嵌入地址规范化；返回 '' = 留空，使用全局 public_base_url */
function ic_normalise_site_base(string $raw, bool &$ok, string &$msg): string {
    $raw = trim($raw);
    if ($raw === '') return '';
    if (!preg_match('#^https?://#i', $raw)) {
        // 不带协议时不能一律补 http://：公网统计域名基本都是 HTTPS，
        // 补成 http:// 会被 HTTPS 宿主页面按混合内容拦截，导致该站点恒零数据。
        $hostOnly = (string)preg_replace('#[:/?].*$#', '', $raw);
        $private  = (bool)preg_match('/^(?:10\.|192\.168\.|127\.|169\.254\.|172\.(?:1[6-9]|2\d|3[01])\.|localhost$)/i', $hostOnly);
        $raw = ($private ? 'http://' : 'https://') . $raw;
    }
    if (strlen($raw) > 255 || preg_match('/[\s"\'<>]/', $raw)) {
        $msg = '嵌入地址不合法（超过 255 字符或含空格 / 引号）。'; $ok = false; return '';
    }
    $p = parse_url($raw);
    if (!$p || empty($p['host']) ||
        !in_array(strtolower((string)($p['scheme'] ?? '')), ['http', 'https'], true)) {
        $msg = '嵌入地址格式不正确，请填 http:// 或 https:// 开头的统计服务地址。'; $ok = false; return '';
    }
    return rtrim($raw, '/');
}

/**
 * 「允许来源」列表规范化：支持多个入口（逗号 / 空格 / 分号分隔），
 * 按 origin 去重、去掉尾斜杠，返回可直接入库的字符串。
 * 公网域名不带协议时报错要求写全，避免静默补成 http:// 后被 HTTPS 页面白名单全部拒收。
 */
function ic_normalise_origins(string $raw, bool &$ok, string &$msg): string {
    $raw = trim($raw);
    if ($raw === '') return '';
    $norm = [];
    foreach ((array)preg_split('/[\s,;]+/', $raw) as $p) {
        $p = trim((string)$p);
        if ($p === '') continue;
        if (preg_match('#^https?://#i', $p)) {
            $candidate = $p;
        } else {
            $hostOnly = (string)preg_replace('#[:/?].*$#', '', $p);
            $private  = (bool)preg_match('/^(?:10\.|192\.168\.|127\.|169\.254\.|172\.(?:1[6-9]|2\d|3[01])\.|localhost$)/i', $hostOnly);
            if (!$private) {
                $msg = '允许来源「' . $p . '」缺少协议。公网域名请写完整，例如 https://www.tansoo.cn；'
                     . '写成 http:// 会被 HTTPS 页面的白名单全部拒收。';
                $ok = false;
                return '';
            }
            $candidate = 'http://' . $p;   // 内网 IP:PORT 保持原有便利写法
        }
        $o = origin_from_url($candidate);
        if ($o === '') {
            $msg = '允许来源「' . $p . '」格式不正确，请填宿主页面地址，例如 https://www.tansoo.cn 或 http://192.168.1.10:8080';
            $ok = false;
            return '';
        }
        $norm[strtolower($o)] = $o;
    }
    $origin = implode(', ', $norm);
    // sites.origin 是 VARCHAR(255)，超长会被 MySQL 静默截断导致白名单不完整
    if (strlen($origin) > 255) {
        $msg = '允许来源过多（超过 255 字符）。请精简，或先执行 sql/upgrade_origin.sql 扩容后再填。';
        $ok = false;
        return '';
    }
    return $origin;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = (string)($_POST['action'] ?? '');
    if ($action === 'add') {
        $name      = trim((string)($_POST['name'] ?? ''));
        $originRaw = trim((string)($_POST['origin'] ?? ''));
        $key       = trim((string)($_POST['key'] ?? ''));
        if ($key === '') $key = substr(md5(uniqid('', true)), 0, 16);

        // 允许多个入口（www / 裸域、HTTP / HTTPS、预发布域名），统一存成逗号分隔的 origin 列表
        $origin   = $ok ? ic_normalise_origins($originRaw, $ok, $msg) : '';
        $siteBase = $ok ? ic_normalise_site_base((string)($_POST['base_url'] ?? ''), $ok, $msg) : '';

        if ($ok && !preg_match('/^[A-Za-z0-9_-]{6,64}$/', $key)) {
            $msg = '站点 Key 只能用字母、数字、下划线、短横线，且至少 6 位。'; $ok = false;
        } elseif ($ok && $name === '') {
            $msg = '请填写站点名称。'; $ok = false;
        } elseif ($ok && strlen($name) > 100) {
            $msg = '站点名称不能超过 100 字符。'; $ok = false;
        } elseif ($ok) {
            try {
                if ($hasSiteBase) {
                    $pdo->prepare('INSERT INTO sites (site_key, name, origin, base_url, status, created_at)
                                   VALUES (?,?,?,?,1,NOW())')
                        ->execute([$key, $name, $origin, $siteBase]);
                } else {
                    $pdo->prepare('INSERT INTO sites (site_key, name, origin, status, created_at)
                                   VALUES (?,?,?,1,NOW())')
                        ->execute([$key, $name, $origin]);
                }
                $msg = '站点已新增，请复制下方的嵌入代码。';
                if (!$hasSiteBase && $siteBase !== '') {
                    $msg .= ' 注意：数据库尚未升级 base_url 字段，该站点专用地址未保存，请先执行 sql/upgrade_site_base_url.sql。';
                }
            } catch (Throwable $ex) {
                $msg = '新增失败：站点 Key 可能已存在。'; $ok = false;
            }
        }
    } elseif ($action === 'setbase') {
        $id = (int)($_POST['id'] ?? 0);
        $siteBase = ic_normalise_site_base((string)($_POST['base_url'] ?? ''), $ok, $msg);
        if ($ok && !$hasSiteBase) {
            $msg = '请先执行 sql/upgrade_site_base_url.sql 给 sites 表增加 base_url 字段。'; $ok = false;
        }
        if ($ok) {
            $pdo->prepare('UPDATE sites SET base_url = ? WHERE id = ?')->execute([$siteBase, $id]);
            $msg = '该站点的嵌入地址已保存为 ' . ($siteBase !== '' ? $siteBase : '（留空，使用全局地址）')
                 . '，请重新复制它的嵌入代码（已贴出去的旧代码不会自动变化）。';
        }
    } elseif ($action === 'toggle') {
        $id = (int)($_POST['id'] ?? 0);
        $pdo->prepare('UPDATE sites SET status = 1 - status WHERE id = ?')->execute([$id]);
        $msg = '状态已更新。';
    } elseif ($action === 'update') {
        $id        = (int)($_POST['id'] ?? 0);
        $oldKey    = (string)($_POST['old_key'] ?? '');
        $name      = trim((string)($_POST['name'] ?? ''));
        $key       = trim((string)($_POST['key'] ?? ''));
        $originRaw = trim((string)($_POST['origin'] ?? ''));
        $status    = ((int)($_POST['status'] ?? 1)) === 1 ? 1 : 0;

        $exists = $pdo->prepare('SELECT id FROM sites WHERE id = ?');
        $exists->execute([$id]);
        if (!$exists->fetch()) {
            $msg = '站点不存在或已被删除。'; $ok = false;
        }

        $origin   = $ok ? ic_normalise_origins($originRaw, $ok, $msg) : '';
        $siteBase = $ok ? ic_normalise_site_base((string)($_POST['base_url'] ?? ''), $ok, $msg) : '';

        if ($ok && !preg_match('/^[A-Za-z0-9_-]{6,64}$/', $key)) {
            $msg = '站点 Key 只能用字母、数字、下划线、短横线，且至少 6 位。'; $ok = false;
        } elseif ($ok && $name === '') {
            $msg = '请填写站点名称。'; $ok = false;
        } elseif ($ok && strlen($name) > 100) {
            $msg = '站点名称不能超过 100 字符。'; $ok = false;
        }
        if ($ok) {
            // 改 Key 不得与其它站点冲突（排除自己）
            $dup = $pdo->prepare('SELECT COUNT(*) FROM sites WHERE site_key = ? AND id <> ?');
            $dup->execute([$key, $id]);
            if ((int)$dup->fetchColumn() > 0) {
                $msg = '站点 Key「' . $key . '」已被其它站点使用，请换一个。'; $ok = false;
            }
        }
        if ($ok) {
            try {
                if ($hasSiteBase) {
                    $pdo->prepare('UPDATE sites SET name=?, site_key=?, origin=?, base_url=?, status=? WHERE id=?')
                        ->execute([$name, $key, $origin, $siteBase, $status, $id]);
                } else {
                    $pdo->prepare('UPDATE sites SET name=?, site_key=?, origin=?, status=? WHERE id=?')
                        ->execute([$name, $key, $origin, $status, $id]);
                }
                $msg = '站点「' . $name . '」已更新。';
                if ($oldKey !== '' && $key !== $oldKey) {
                    $msg .= ' 站点 Key 已变化，请重新复制该站点的嵌入代码替换页面上的旧代码（历史统计数据不受影响）。';
                }
                if (!$hasSiteBase && $siteBase !== '') {
                    $msg .= ' 注意：数据库尚未升级 base_url 字段，专用地址未保存，请先执行 sql/upgrade_site_base_url.sql。';
                }
            } catch (Throwable $ex) {
                $msg = '保存失败：' . $ex->getMessage(); $ok = false;
            }
        }
    } elseif ($action === 'delete') {
        $id = (int)($_POST['id'] ?? 0);
        $q = $pdo->prepare('SELECT name FROM sites WHERE id = ?');
        $q->execute([$id]);
        $row = $q->fetch(PDO::FETCH_ASSOC);
        if (!$row) {
            $msg = '站点不存在或已被删除。'; $ok = false;
        } else {
            $delName = (string)$row['name'];
            try {
                $pdo->beginTransaction();
                $pdo->prepare('DELETE FROM events WHERE site_id = ?')->execute([$id]);
                // uv_daily 为辅助去重表，极老的数据库可能没有，忽略即可
                try {
                    $pdo->prepare('DELETE FROM uv_daily WHERE site_id = ?')->execute([$id]);
                } catch (Throwable $ignored) {}
                $pdo->prepare('DELETE FROM sites WHERE id = ?')->execute([$id]);
                $pdo->commit();
                $msg = '站点「' . $delName . '」及其全部统计数据已删除，不可恢复。';
            } catch (Throwable $ex) {
                if ($pdo->inTransaction()) $pdo->rollBack();
                $msg = '删除失败：' . $ex->getMessage(); $ok = false;
            }
        }
    }
}

// 用 s.* 以兼容尚未升级 base_url 字段的旧库
$sites = $pdo->query(
    'SELECT s.*,
            (SELECT COUNT(*) FROM events e WHERE e.site_id = s.id) AS total_events,
            (SELECT COUNT(*) FROM events e WHERE e.site_id = s.id AND e.kind = \'pv\') AS total_pv,
            (SELECT COUNT(*) FROM events e WHERE e.site_id = s.id AND e.kind = \'pv\'
              AND e.ts > DATE_SUB(NOW(), INTERVAL 1 HOUR)) AS pv_1h,
            (SELECT MAX(e.ts) FROM events e WHERE e.site_id = s.id AND e.kind = \'pv\') AS last_pv
     FROM sites s ORDER BY s.id'
)->fetchAll();

// 待编辑 / 待删除的站点：由 URL 参数切换页面呈现，不直接改动数据
$editId = (int)($_GET['edit'] ?? 0);
$pendingDelete = null;
if (isset($_GET['del'])) {
    $delId = (int)$_GET['del'];
    foreach ($sites as $s) {
        if ((int)$s['id'] === $delId) { $pendingDelete = $s; break; }
    }
}

// 对外地址以 config.php 的 public_base_url 为准（含端口）；未配置时才自动推断并补子目录
$configuredBase = trim((string)cfg('public_base_url', ''));
$scriptRoot = '';
if ($configuredBase === '') {
    $scriptRoot = preg_replace('#/admin/[^/]*$#', '', (string)($_SERVER['SCRIPT_NAME'] ?? ''));
}
$base = base_url() . rtrim((string)$scriptRoot, '/');

// 诊断信息：非 80 端口或反代场景下，让管理员一眼看出端口有没有丢
$reqHost = (string)($_SERVER['HTTP_HOST'] ?? '—');
$reqPort = (string)($_SERVER['SERVER_PORT'] ?? '—');
$baseIsHttps = (stripos($base, 'https://') === 0);
$viaCloudflare = isset($_SERVER['HTTP_CF_RAY'])
    || isset($_SERVER['HTTP_CF_CONNECTING_IP'])
    || isset($_SERVER['HTTP_CF_VISITOR']);

// 跨服务器 / 跨域名部署检测：站点允许来源的主机与统计服务对外主机不同即为跨源。
// 同时按「站点宿主协议 × 该站点嵌入地址协议」逐站判断，避免把正常的 HTTP 内网站点误报成错误。
$baseHost = strtolower((string)(parse_url($base, PHP_URL_HOST) ?? ''));
$crossOrigins = [];
$siteAddr = [];       // 每个站点实际使用的嵌入地址与协议
$mixedSites = [];     // HTTPS 宿主 + http:// 统计地址 → 混合内容，必然零数据
$upgradedSites = [];  // HTTP 宿主 + https:// 统计地址 → 允许，但需浏览器能访问该域名
$httpPublic = [];     // 白名单写成 http:// 但主机名是公网域名 → HTTPS 宿主会被全部拒收
foreach ($sites as $s) {
    $originHttps = $originHttp = false;
    $publicHttp = [];
    foreach (ic_allowed_origins((string)$s['origin']) as $origin) {
        $host = strtolower((string)(parse_url($origin, PHP_URL_HOST) ?? ''));
        if ($host !== '' && $baseHost !== '' && $host !== $baseHost) $crossOrigins[$origin] = true;
        if (stripos($origin, 'https://') === 0) $originHttps = true;
        if (stripos($origin, 'http://') === 0) {
            $originHttp = true;
            if ($host !== '' && !preg_match('/^(?:10\.|192\.168\.|127\.|169\.254\.|172\.(?:1[6-9]|2\d|3[01])\.|localhost$)/', $host)) {
                $publicHttp[] = $origin;
            }
        }
    }
    if ($publicHttp) $httpPublic[(int)$s['id']] = $publicHttp;
    $custom = $hasSiteBase ? trim((string)($s['base_url'] ?? '')) : '';
    $embedBase = $custom !== '' ? rtrim($custom, '/') : $base;
    $addr = [
        'base'         => $embedBase,
        'custom'       => $custom !== '',
        'https'        => stripos($embedBase, 'https://') === 0,
        'origin_https' => $originHttps,
        'origin_http'  => $originHttp,
    ];
    $siteAddr[(int)$s['id']] = $addr;
    if ($addr['origin_https'] && !$addr['https']) $mixedSites[] = (string)$s['name'];
    if ($addr['origin_http']  && $addr['https'])  $upgradedSites[] = (string)$s['name'];
}
?>
<!doctype html>
<html lang="zh-CN">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>站点与嵌入代码 · 内网访问统计</title>
<link rel="stylesheet" href="style.css">
</head>
<body>
<div class="wrap">
<header class="top">
  <h1>站点与嵌入代码</h1>
  <nav><a href="index.php">报表</a><a href="sites.php">站点与嵌入代码</a><a href="index.php?logout=1">退出</a></nav>
</header>

<?php if ($msg !== ''): ?>
  <p class="<?= $ok ? 'notice' : 'error' ?>"><?= e($msg) ?></p>
<?php endif; ?>

<?php if ($pendingDelete): ?>
<div class="error" style="margin-bottom:18px">
  <strong>确认删除站点？此操作不可恢复</strong><br>
  站点名称：<strong><?= e($pendingDelete['name']) ?></strong>
  · Key：<code><?= e($pendingDelete['site_key']) ?></code><br>
  将<b>同时删除该站点的 <?= (int)$pendingDelete['total_events'] ?> 条历史统计记录</b>，
  删除后报表数据无法恢复。<br>
  <form method="post" action="sites.php" style="display:inline;margin-top:10px">
    <input type="hidden" name="action" value="delete">
    <input type="hidden" name="id" value="<?= (int)$pendingDelete['id'] ?>">
    <button type="submit" class="danger">确认删除</button>
  </form>
  <a class="btn ghost" href="sites.php" style="margin-left:8px">取消</a>
</div>
<?php endif; ?>

<h2>统计服务对外地址</h2>
<div class="panel" style="padding-top:16px">
  <p style="margin-top:0">后台生成的嵌入代码使用下面这个地址，请确认它与宿主机器实际能访问到的 IP 和端口一致：</p>
  <pre class="code"><?= e($base) ?></pre>
  <?php if ($configuredBase === ''): ?>
  <p class="notice">当前为自动推断。统计站点不是 80 端口、或前面有反向代理时，建议在
     <code>app/config.php</code> 里显式设置
     <code>'public_base_url' =&gt; 'http://统计机IP:端口'</code>，后台会原样使用，不再依赖请求头。<br>
     当前请求：<code>HTTP_HOST=<?= e($reqHost) ?></code> ·
     <code>SERVER_PORT=<?= e($reqPort) ?></code></p>
  <?php else: ?>
  <p class="muted">来自 <code>app/config.php</code> 的 <code>public_base_url</code> 配置。
     当前请求：<code>HTTP_HOST=<?= e($reqHost) ?></code> ·
     <code>SERVER_PORT=<?= e($reqPort) ?></code></p>
  <?php endif; ?>
  <?php if ($viaCloudflare && !$baseIsHttps): ?>
  <p class="error">当前请求经 Cloudflare 边缘（HTTPS），但对外地址仍是 <code>http://</code>。
     HTTPS 宿主页加载 HTTP 脚本与图片会被浏览器按混合内容拦截，计数会恒为 0。
     请把 <code>public_base_url</code> 改成 <code>https://统计域名</code>，并重新复制嵌入代码。</p>
  <?php else: ?>
  <p class="muted">宿主页是 HTTPS 时，这个地址也必须是 <code>https://</code>；
     经 Cloudflare Tunnel / frp 时 TLS 在边缘终结，源站保持 HTTP 即可，不必给宝塔站点开 SSL。</p>
  <?php endif; ?>
  <p class="muted" style="margin-bottom:0"><strong>这是全局默认值</strong>，不是每个网站各配一份：
     <?php if ($hasSiteBase): ?>
     下方每个站点可以单独设置「专用嵌入地址」覆盖它，例如 HTTPS 公网站点留空用
     <code>https://stats.tansoo.cn</code>、HTTP 内网站点填 <code>http://192.168.3.29:666</code>（或原 frp 地址）。
     <?php else: ?>
     若需要不同站点使用不同地址，请先执行 <code>sql/upgrade_site_base_url.sql</code>，
     之后每个站点会出现「专用嵌入地址」输入框。
     <?php endif; ?>
     <strong>改这里不会影响已经贴出去的嵌入代码</strong>，只有重新从后台复制才会变。</p>
</div>

<h2>统计服务与被统计网站不在同一台服务器</h2>
<div class="panel" style="padding-top:16px">
  <p style="margin-top:0"><strong>可以，而且推荐这么部署。</strong>判定标准只有一个：<strong>访客浏览器能否访问上面这个对外地址</strong>。
     两台服务器之间不需要任何网络互通、不需要共享数据库、不需要同域名同 IP，
     <code>sdk.js</code> 会自动从自己的 <code>src</code> 推导出上报地址。</p>
  <?php if ($crossOrigins): ?>
  <p class="notice">检测到跨源部署：允许来源
     <code><?= e(implode('、', array_keys($crossOrigins))) ?></code>
     与统计服务 <code><?= e($baseHost) ?></code> 不在同一主机。当前上报链路按跨源处理，属正常状态。</p>
  <?php else: ?>
  <p class="muted">当前未检测到跨源站点（可能未填写「允许来源」或与统计服务同主机）。填写宿主页面地址后这里会自动识别。</p>
  <?php endif; ?>
  <?php if ($mixedSites): ?>
  <p class="error">站点「<?= e(implode('、', $mixedSites)) ?>」的宿主页面是 HTTPS，但嵌入代码用的是 <code>http://</code> 统计地址，
     会被浏览器按混合内容拦截、计数恒为 0。请把该站点的嵌入地址改成 <code>https://</code>。</p>
  <?php endif; ?>
  <?php if ($upgradedSites): ?>
  <p class="notice">站点「<?= e(implode('、', $upgradedSites)) ?>」是 HTTP 页面、嵌入代码用的是 <code>https://</code> 统计地址。
     <strong>这是允许的</strong>（浏览器只拦「HTTPS 页面加载 HTTP 资源」，不拦反向），
     但访客浏览器必须能解析并访问该域名。纯隔离内网请在下方给该站点单独设置
     <code>http://</code> 内网地址，或保留它原有的旧嵌入代码不要重新复制。</p>
  <?php endif; ?>
  <ol class="muted" style="margin-bottom:0;line-height:1.9">
    <li><code>app/config.php</code> 的 <code>public_base_url</code> 是<strong>全局默认值</strong>（只有一份），
        建议填 <code>https://stats.tansoo.cn</code>，因为公网 HTTPS 站点必需。不要把 Tunnel <code>service</code> 的源站地址
        <code>http://192.168.3.29:666</code> 当全局值——公网浏览器访问不到。
        <strong>HTTP 内网站点不必跟着改</strong>：给它单独设置专用嵌入地址，或保留原有旧嵌入代码即可。</li>
    <li>HTTPS 宿主页必须用 <code>https://</code> 统计地址，否则脚本与图片会被浏览器按混合内容拦截、计数恒为 0；
        反过来 <strong>HTTP 宿主页可以加载 HTTPS 统计地址</strong>，浏览器允许，只是访客需能解析并访问该域名。
        纯隔离内网请给该站点用 <code>http://</code> 内网地址。</li>
    <li>一套服务同时统计公网 HTTPS 与内网 HTTP 站点时，两种地址可长期并存：执行
        <code>sql/upgrade_site_base_url.sql</code> 后，给内网站点填「专用嵌入地址」
        <code>http://192.168.3.29:666</code>（或原 frp 地址），公网站点留空用全局 HTTPS 地址。
        不升级也能用，只要<strong>不重新复制内网站点已贴出去的旧嵌入代码</strong>。</li>
    <li>「允许来源」填<strong>宿主页面</strong>的 origin，可填多个（逗号分隔），例如
        <code>https://www.tansoo.cn, https://tansoo.cn</code>；协议与端口必须完全一致，尾斜杠可省略。</li>
    <li>宿主站点若有 CSP，需放行统计域名：
        <code>script-src</code>（sdk.js）、<code>img-src</code>（PV）、<code>connect-src</code>（sendBeacon）。
        Cloudflare 侧关闭统计域名的 Rocket Loader / JS Challenge / Bot Fight。</li>
    <li>客户端 IP 只取决于<strong>统计链路</strong>上的代理：<code>trusted_proxies</code> 填
        <code>collect.php</code> 实际看到的 <code>REMOTE_ADDR</code>（cloudflared 连过来的源地址，
        用 <code>check.php</code> 从对外域名打开查看），<strong>不是</strong>源站地址
        <code>192.168.3.29</code>、不是端口 <code>666</code>、也不是 <code>127.0.0.1</code> 的默认值；
        与 www 服务器的代理设置无关。</li>
    <li>访客标识 <code>vid</code> 写在宿主域自己的第一方 Cookie、随 URL 参数上报，跨服务器不会丢 UV；
        但宿主域名更换会让 vid 重新生成，历史 UV 不可与新域名直接比较。</li>
    <li>Tunnel 的 <code>service: http://192.168.3.29:666</code> 只决定 cloudflared 回源到哪台机器的哪个端口，
        与嵌入代码无关。需确认该端口对 connector 可达（防火墙放行），且宝塔站点绑定了
        <code>stats.tansoo.cn</code> 这个 Host 或改写了 <code>httpHostHeader</code>，
        否则 Nginx 按 <code>server_name</code> 匹配不上会落到默认站点返回 404。</li>
    <li>统计域名在公网暴露，请给 <code>/admin/</code> 加强密码或 Cloudflare Access 保护；
        <code>check.php</code> 自检完记得删除。</li>
  </ol>
</div>

<h2>新增站点</h2>
<div class="panel" style="padding-top:16px">
<form class="filters" method="post" action="sites.php">
  <input type="hidden" name="action" value="add">
  <div><label for="name">站点名称（必填）</label><input id="name" name="name" type="text" required placeholder="例如：OA 系统"></div>
  <div><label for="origin">允许来源（选填，含协议与端口，多个用逗号分隔）</label><input id="origin" name="origin" type="text" placeholder="https://www.tansoo.cn, https://tansoo.cn"></div>
  <div><label for="key">站点 Key（选填，留空自动生成）</label><input id="key" name="key" type="text" placeholder="自动生成"></div>
  <?php if ($hasSiteBase): ?>
  <div><label for="base_url">专用嵌入地址（选填，留空用全局地址）</label><input id="base_url" name="base_url" type="text" placeholder="例如 http://192.168.3.29:666"></div>
  <?php endif; ?>
  <div><button type="submit">新增</button></div>
</form>
<p class="muted" style="margin-bottom:0">允许来源填<strong>宿主页面</strong>的 origin，用于 Referer / Origin 白名单；
  同一站点有多个入口时用逗号分隔（如 <code>https://www.tansoo.cn, https://tansoo.cn</code>）。
  HTTP 内网站点的允许来源就该填 <code>http://</code>，不必改成 https。
  统计服务与宿主网站不在同一台服务器时照常填写，不必与统计域名一致。
  若内网代理会剥离 Referer 与 Origin，留空即可（请求头缺失时服务端放行）。
  <?php if (!$hasSiteBase): ?><br>需要按站点使用不同统计地址时，先执行
  <code>sql/upgrade_site_base_url.sql</code>，本表单会出现「专用嵌入地址」输入框。<?php endif; ?></p>
</div>

<h2>已注册站点（<?= count($sites) ?>）</h2>
<?php if (!$sites): ?>
  <div class="panel"><p class="muted">还没有站点，先在上方新增一个。</p></div>
<?php endif; ?>

<?php foreach ($sites as $s): ?>
<?php
  $key = (string)$s['site_key'];
  $addr = $siteAddr[(int)$s['id']];
  $embedBase = $addr['base'];   // 站点专用地址优先，留空则用全局 public_base_url
  // 带文件版本号，SDK 更新后重新复制嵌入代码即可绕过浏览器缓存
  $sdkFile = dirname(__DIR__) . '/sdk.js';
  $sdkVer = is_file($sdkFile) ? (string)filemtime($sdkFile) : '1';
  // data-cfasync="false" 让 Cloudflare Rocket Loader 跳过本脚本，
  // 否则它会把 src 改写到 data-rocketsrc 并延迟执行，老版本 SDK 会静默不发 PV。
  $embed = '<script data-cfasync="false" async src="' . $embedBase . '/sdk.js?v=' . $sdkVer . '" data-site="' . $key . '"></script>' . "\n"
         . '<noscript><img src="' . $embedBase . '/collect.php?k=' . rawurlencode($key) . '" width="1" height="1" alt=""></noscript>';

  $sid     = (int)$s['id'];
  $editing = ($sid === $editId);
  $st      = (int)$s['status'];
?>
<div class="panel" style="margin-bottom:14px">
<?php if ($editing): ?>
  <h3 style="margin:14px 0 10px">编辑站点 #<?= $sid ?></h3>
  <form class="filters" method="post" action="sites.php?edit=<?= $sid ?>">
    <input type="hidden" name="action" value="update">
    <input type="hidden" name="id" value="<?= $sid ?>">
    <input type="hidden" name="old_key" value="<?= e($key) ?>">
    <div><label for="ename_<?= $sid ?>">站点名称（必填）</label>
      <input id="ename_<?= $sid ?>" name="name" type="text" required maxlength="100"
             value="<?= e((string)$s['name']) ?>"></div>
    <div><label for="ekey_<?= $sid ?>">站点 Key（改后需重新贴嵌入代码）</label>
      <input id="ekey_<?= $sid ?>" name="key" type="text" required
             pattern="[A-Za-z0-9_-]{6,64}" value="<?= e($key) ?>"></div>
    <div><label for="est_<?= $sid ?>">状态</label>
      <select id="est_<?= $sid ?>" name="status">
        <option value="1"<?= $st === 1 ? ' selected' : '' ?>>启用</option>
        <option value="0"<?= $st === 0 ? ' selected' : '' ?>>停用</option>
      </select></div>
    <div style="flex:1 1 340px"><label for="eorigin_<?= $sid ?>">允许来源（多个用逗号分隔，留空 = 不限制）</label>
      <input id="eorigin_<?= $sid ?>" name="origin" type="text" style="width:100%"
             value="<?= e((string)$s['origin']) ?>"
             placeholder="https://www.tansoo.cn, https://tansoo.cn"></div>
    <?php if ($hasSiteBase): ?>
    <div style="flex:1 1 300px"><label for="ebase_<?= $sid ?>">专用嵌入地址（留空 = 用全局 <?= e($base) ?>）</label>
      <input id="ebase_<?= $sid ?>" name="base_url" type="text" style="width:100%"
             value="<?= e((string)($s['base_url'] ?? '')) ?>" placeholder="http://192.168.3.29:666"></div>
    <?php endif; ?>
    <div style="width:100%">
      <button type="submit">保存修改</button>
      <a class="btn ghost" href="sites.php">取消</a>
    </div>
  </form>
  <p class="muted" style="margin:12px 0 0">该站点共有 <strong><?= (int)$s['total_events'] ?></strong> 条历史记录。
     修改「站点 Key」不会丢失历史数据，但已贴到页面里的旧嵌入代码会立即失效，必须重新复制替换。
     「允许来源」公网域名要写完整协议（<code>https://</code>），HTTP 内网站点写 <code>http://</code>。</p>
<?php else: ?>
  <table>
    <thead><tr><th>名称</th><th>站点 Key</th><th>允许来源</th><th>状态</th><th class="num">累计 PV</th><th></th></tr></thead>
    <tbody><tr>
      <td><?= e($s['name']) ?></td>
      <td><code><?= e($key) ?></code></td>
      <td class="muted"><?= e($s['origin'] !== '' ? $s['origin'] : '不限制') ?></td>
      <td><span class="tag <?= (int)$s['status'] === 1 ? 'on' : 'off' ?>"><?= (int)$s['status'] === 1 ? '启用' : '停用' ?></span></td>
      <td class="num"><?= (int)$s['total_pv'] ?></td>
      <td class="num">
        <a class="btn ghost sm" href="sites.php?edit=<?= $sid ?>">编辑</a>
        <form method="post" action="sites.php" style="display:inline">
          <input type="hidden" name="action" value="toggle">
          <input type="hidden" name="id" value="<?= $sid ?>">
          <button type="submit" class="ghost sm"><?= $st === 1 ? '停用' : '启用' ?></button>
        </form>
        <a class="btn danger sm" href="sites.php?del=<?= $sid ?>">删除</a>
      </td>
    </tr></tbody>
  </table>
  <?php
    $lastPv = (string)($s['last_pv'] ?? '');
    $pv1h   = (int)($s['pv_1h'] ?? 0);
    $never  = ($lastPv === '');
  ?>
  <p class="muted" style="margin:12px 0 0">收数状态：近 1 小时 PV <strong><?= $pv1h ?></strong>
     · 累计 PV <strong><?= (int)$s['total_pv'] ?></strong>
     · 最后一条 PV <strong><?= $never ? '从未收到' : e($lastPv) ?></strong>
     <?php if ((int)$s['status'] !== 1): ?>· <span style="color:#c0392b">站点已停用，上报会被直接丢弃</span><?php endif; ?></p>
  <?php if (!empty($httpPublic[(int)$s['id']])): ?>
  <p class="error">「允许来源」中的 <code><?= e(implode('、', $httpPublic[(int)$s['id']])) ?></code>
     是 <code>http://</code> 的公网域名。若宿主页面实际是 <strong>HTTPS</strong>，浏览器发来的
     Referer / Origin 是 <code>https://</code>，白名单逐字节比对不匹配，
     <strong>该站点的上报会被静默拒收</strong>（请求照常返回 200 GIF，但一条都不入库）。
     请改成 <code>https://域名</code>；宿主确实是 HTTP 页面时可忽略本提示。</p>
  <?php endif; ?>
  <?php if ($never && (int)$s['status'] === 1): ?>
  <p class="notice">该站点从未收到 PV。请按本页底部「零数据排查」的顺序逐项检查：嵌入代码是否已重新粘贴、
     是否仍指向旧的 <code>http://</code> 内网地址、Cloudflare 是否缓存了旧页面或拦截了采集请求。</p>
  <?php endif; ?>
  <p style="margin:14px 0 4px"><strong>嵌入代码</strong> · 统计地址 <code><?= e($embedBase) ?></code>
     <span class="muted"><?= $addr['custom'] ? '（站点专用）' : '（全局默认）' ?></span>
     · 贴到该站点每个页面 <code>&lt;/body&gt;</code> 之前</p>
  <pre class="code"><?= e($embed) ?></pre>
  <?php if ($addr['origin_https'] && !$addr['https']): ?>
  <p class="error">该站点宿主页面是 HTTPS，统计地址却是 <code>http://</code>，脚本与图片会被浏览器按混合内容拦截。
     请在下方把专用地址改成 <code>https://</code>。</p>
  <?php elseif ($addr['origin_http'] && $addr['https']): ?>
  <p class="notice">HTTP 页面加载 HTTPS 统计地址是允许的，但访客浏览器必须能访问
     <code><?= e($embedBase) ?></code>。纯隔离内网请在下方填 <code>http://</code> 内网地址（如
     <code>http://192.168.3.29:666</code>），或保留该站点原有的旧嵌入代码不替换。</p>
  <?php endif; ?>
  <?php if ($hasSiteBase): ?>
  <form class="filters" method="post" action="sites.php" style="margin-top:12px">
    <input type="hidden" name="action" value="setbase">
    <input type="hidden" name="id" value="<?= (int)$s['id'] ?>">
    <div><label>该站点专用嵌入地址（留空 = 用全局 <code><?= e($base) ?></code>）</label>
      <input name="base_url" type="text" value="<?= e((string)($s['base_url'] ?? '')) ?>"
             placeholder="例如 http://192.168.3.29:666"></div>
    <div><button type="submit" class="ghost">保存地址</button></div>
  </form>
  <?php endif; ?>
<?php endif; ?>
</div>
<?php endforeach; ?>

<h2>连通性自检与「零数据」排查</h2>
<div class="panel" style="padding-top:16px">
  <p style="margin-top:0"><strong>第 1 步：确认服务端能入库</strong>（Key 换成该站点的实际 Key）：</p>
  <pre class="code">curl -i "<?= e($base) ?>/collect.php?k=站点Key"</pre>
  <p class="muted"><code>200 OK</code> + <code>Content-Type: image/gif</code> 只说明 PHP 执行成功 ——
     <strong>被白名单拒收时同样返回 200</strong>。执行后必须刷新本页，看该站点「近 1 小时 PV」是否 +1。
     没 +1 就是服务端拒收（站点停用 / Key 不对 / 白名单不匹配）。</p>

  <p><strong>第 2 步：模拟 HTTPS 宿主页面，验证白名单</strong></p>
  <pre class="code">curl -i -e "https://www.tansoo.cn/" "<?= e($base) ?>/collect.php?k=站点Key"</pre>
  <p class="muted"><code>-e</code> 换成宿主页面真实 origin（协议与端口必须完全一致）。
     若第 1 步 +1、这一条不 +1，则是<strong>「允许来源」协议写错</strong>：
     把 <code>http://www.tansoo.cn</code> 改成 <code>https://www.tansoo.cn</code>。</p>

  <p><strong>第 3 步：宿主页面按 F12，Network 过滤 <code>collect.php</code> 后刷新</strong>，按结果对号入座：</p>
  <ol class="muted" style="line-height:1.9">
    <li><strong>完全没有这条请求</strong> → 脚本没跑起来：页面源码里的 <code>script src</code> 仍是旧的
        <code>http://192.168.3.29:666/sdk.js</code>（HTTPS 页面按混合内容直接拦截）；
        Cloudflare 缓存了旧 HTML（改完要 Purge Cache）；宿主 CSP 未放行
        <code>script-src</code> / <code>img-src</code> / <code>connect-src</code>；
        或浏览器广告拦截插件拦了含 <code>stats</code> / <code>collect</code> 的 URL。</li>
    <li><strong>标为 blocked:mixed-content</strong> → 嵌入代码仍是 <code>http://</code>，从本页重新复制整段替换。</li>
    <li><strong>403 / 503 / 返回 HTML 而非 GIF / 404</strong> → Cloudflare 的 JS Challenge、Bot Fight、WAF，
        或宝塔站点没绑定 <code>stats.tansoo.cn</code> 这个 Host。关闭统计域名的 Rocket Loader 与五秒盾，
        并在宝塔站点「域名」里追加该域名。</li>
    <li><strong>200 且返回 GIF，但数据不增</strong> → 服务端拒收：站点 Key 与嵌入代码不一致、站点被停用、
        或「允许来源」协议 / 端口不匹配。用上面两步 curl 与自检页的「按站点上报诊断」表格确认。</li>
  </ol>
  <p class="muted">想在控制台看 SDK 内部状态，可临时给嵌入的 script 加 <code>data-debug</code> 属性
     （或在 URL 加 <code>&amp;ic_debug=1</code>），它会打印脚本定位、上报地址、vid 与每次请求结果，排查完去掉即可。
     新版 SDK 已兼容 Cloudflare Rocket Loader 的 <code>data-rocketsrc</code>，嵌入代码也加了
     <code>data-cfasync="false"</code> 防止脚本被改写。<br>
     <strong>注意：</strong>覆盖 <code>sdk.js</code> 后，下方嵌入代码里的 <code>?v=</code> 版本号会变化，
     必须<strong>重新复制整段代码</strong>或在 Cloudflare 里 Purge <code>sdk.js</code> 缓存，
     否则浏览器 / CDN 仍会命中旧脚本，修复不生效。</p>
  <p class="muted" style="margin-bottom:0">若统计服务经 <strong>frp / Nginx / Cloudflare Tunnel</strong> 访问，所有访客的来源 IP 会显示成同一个代理地址：
     在 <code>app/config.php</code> 把代理 IP 加进 <code>trusted_proxies</code>（frp 用 <code>X-Forwarded-For</code>，
     Cloudflare 必须用边缘注入的 <code>CF-Connecting-IP</code>，不能先读可被伪造的 XFF），
     再用 <a href="../check.php" target="_blank" rel="noopener">部署自检页</a> 确认真实 IP 已还原。
     PV / UV 不受影响；修复前已入库的历史数据无法回填。自检页会显示服务器路径，诊断完请删除。</p>
</div>
</div>
</body>
</html>
