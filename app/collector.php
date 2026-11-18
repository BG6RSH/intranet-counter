<?php
declare(strict_types=1);

if (!defined('APP_ROOT')) {
    require_once dirname(__DIR__) . '/app/bootstrap.php';
}
require_once APP_ROOT . '/app/ua.php';

function ic_normalise_id($v, int $min = 8, int $max = 40): string {
    $v = (string)$v;
    return preg_match('/^[A-Za-z0-9]{' . $min . ',' . $max . '}$/', $v) ? $v : '';
}

/**
 * 允许来源白名单。sites.origin 支持逗号 / 分号 / 空白分隔多个 origin。
 * 统计服务与被统计网站不在同一台服务器时，一个站点常常有多个入口
 * （www 与裸域、HTTP 与 HTTPS、预发布域名），逐项规范化后精确比对。
 * 返回 [小写origin => 原样origin]，方便大小写不敏感查找。
 */
function ic_allowed_origins(string $raw): array {
    $raw = cut($raw, 1024);
    if ($raw === '') return [];
    $parts = preg_split('/[\s,;]+/', $raw);
    $out = [];
    foreach ((array)$parts as $p) {
        $p = trim((string)$p);
        if ($p === '') continue;
        $o = origin_from_url($p);
        if ($o !== '') $out[strtolower($o)] = $o;
    }
    return $out;
}

/** 取该站点 Key 的允许来源列表；查询失败返回空数组（= 不限制），绝不抛异常 */
function ic_site_origins(string $key): array {
    if (!preg_match('/^[A-Za-z0-9_-]{6,64}$/', $key)) return [];
    try {
        $q = db()->prepare('SELECT origin FROM sites WHERE site_key = ? AND status = 1 LIMIT 1');
        $q->execute([$key]);
        $row = $q->fetch();
        return $row ? ic_allowed_origins((string)($row['origin'] ?? '')) : [];
    } catch (Throwable $ex) {
        return [];
    }
}

/**
 * 跨域响应头。Image GET 与 sendBeacon(text/plain) 都是不触发预检的简单请求，
 * 不加这些头也能正常上报；这里按站点白名单精确回显 Origin，便于宿主改用
 * fetch(keepalive) 或浏览器 / 代理策略更严格时仍然可用。
 * 绝不使用 Access-Control-Allow-Origin: *，也不允许携带凭证，避免统计域名被滥用。
 */
function ic_emit_cors(array $in): void {
    if (headers_sent()) return;
    $allow = ic_site_origins(trim((string)($in['k'] ?? '')));
    if (!$allow) return;

    $candidate = '';
    foreach (['HTTP_ORIGIN', 'HTTP_REFERER'] as $h) {
        $o = origin_from_url((string)($_SERVER[$h] ?? ''));
        if ($o !== '') { $candidate = $o; break; }
    }
    if ($candidate === '' || !isset($allow[strtolower($candidate)])) return;

    header('Access-Control-Allow-Origin: ' . $candidate);
    header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
    header('Access-Control-Allow-Headers: Content-Type');
    header('Vary: Origin');
}

/**
 * 接收一条上报并落库。任何异常都静默吞掉，宿主页面永远无感。
 */
function ic_accept(array $in, string $defaultKind): void {
    // 站点 Key 与后台新增表单保持一致，允许下划线与短横线
    $key = trim((string)($in['k'] ?? ''));
    if (!preg_match('/^[A-Za-z0-9_-]{6,64}$/', $key)) return;

    $kind = (string)($in['kind'] ?? $defaultKind);
    if (!in_array($kind, ['pv', 'unload', 'heartbeat'], true)) return;

    try {
        $pdo = db();
        $q = $pdo->prepare('SELECT id, origin, status FROM sites WHERE site_key = ? LIMIT 1');
        $q->execute([$key]);
        $site = $q->fetch();
        if (!$site || (int)$site['status'] !== 1) return;

        $siteId = (int)$site['id'];
        // 规范化成 origin 列表，兼容管理员填写时带了尾斜杠 / 路径 / 多个入口
        $allow = ic_allowed_origins((string)$site['origin']);

        // 来源白名单：浏览器跨站请求默认只发送 origin（含端口），缺失时放行。
        // 统计服务与宿主网站不在同一台服务器时，某些反代会剥离 Referer 却保留 Origin，
        // 此时用 Origin 兜底，避免白名单形同虚设；两者都缺失仍按原有策略放行。
        if (cfg('require_origin_check', true) && $allow) {
            $refOrigin = origin_from_url((string)($_SERVER['HTTP_REFERER'] ?? ''));
            $orgOrigin = origin_from_url((string)($_SERVER['HTTP_ORIGIN'] ?? ''));
            $check = $refOrigin !== '' ? $refOrigin : $orgOrigin;
            if ($check !== '' && !isset($allow[strtolower($check)])) return;
        }

        // 客户端时间戳只用于过滤明显的过期/重放请求，一切时间以服务端为准
        $ts = (int)($in['t'] ?? 0);
        if ($ts > 0 && abs(time() - $ts) > 600) return;

        $ip    = client_ip();                           // 经可信反代时已还原为真实客户端 IP
        $ua    = cut((string)($_SERVER['HTTP_USER_AGENT'] ?? ''), 255);
        $salt  = (string)cfg('salt', '');
        $today = date('Y-m-d');
        $ipHash = md5($ip . '|' . $salt . '|' . $today);   // 每日轮换，默认不存明文 IP
        $client = ic_parse_ua($ua);                        // 浏览器 / 版本 / 系统 / 设备
        $ipPlain = cfg('store_client_ip', false) ? cut($ip, 45) : '';

        // 浏览器语言与屏幕分辨率，由 SDK 上报；格式不合法一律丢弃。
        // 在 frp / NAT 共享出口下，这两项也能帮忙区分禁用 Cookie 的访客。
        $lang = cut((string)($in['l'] ?? ''), 16);
        if (!preg_match('/^[A-Za-z0-9\-]{2,16}$/', $lang)) $lang = '';
        $screen = cut((string)($in['sc'] ?? ''), 24);
        if (!preg_match('/^\d{2,5}x\d{2,5}$/', $screen)) $screen = '';

        // vid 由各站点自己的第一方 Cookie 提供，是 UV 的主口径，与客户端 IP 无关。
        // 只有取不到 Cookie 时才退化为服务端指纹（f 前缀），此时 UV 为估算值。
        $vid = ic_normalise_id($in['v'] ?? '');
        if ($vid === '') {
            $vid = 'f' . substr(md5(
                $ip . '|' . $ua . '|' . $lang . '|' . $screen . '|' . $salt . '|' . $today
            ), 0, 24);
        }
        $sid = ic_normalise_id($in['s'] ?? '');
        $eid = ic_normalise_id($in['e'] ?? '', 8, 40);
        if ($eid === '') $eid = substr(md5(uniqid('', true)), 0, 24);

        // 限流必须带上 vid：经 frp / NAT 时所有访客共享同一个出口 IP，
        // 只按 ip_hash 计数会把整个站点 2 秒内的上报一起挡掉，造成漏计。
        $rate = (int)cfg('rate_limit_per_2s', 20);
        if ($rate > 0) {
            $c = $pdo->prepare(
                'SELECT COUNT(*) FROM events
                 WHERE site_id = ? AND ip_hash = ? AND vid = ? AND ts > DATE_SUB(NOW(), INTERVAL 2 SECOND)'
            );
            $c->execute([$siteId, $ipHash, $vid]);
            if ((int)$c->fetchColumn() >= $rate) return;
        }

        $path  = cut((string)($in['p'] ?? ''), 512);
        if ($path === '') $path = '/';
        // URL 查询串在服务端脱敏后才落库，token / 密码等敏感参数只存 ***
        // 未执行 sql/upgrade_query.sql 时自动跳过该列，整条上报照常入库
        $storeQuery = (bool)cfg('store_query', true);
        $query = $storeQuery
            ? ic_clean_query((string)($in['q'] ?? ''), (array)cfg('query_extra_sensitive', []))
            : '';
        $title = cut((string)($in['ti'] ?? ''), 255);
        $ref   = path_from_url((string)($in['r'] ?? ''));
        $duration = (int)($in['d'] ?? 0);
        $duration = max(0, min(86400, $duration));

        $now = date('Y-m-d H:i:s');

        // eid 唯一索引保证网络重试 / 重复信标不会被计成两次 PV
        // 列名按表结构动态选择：未执行升级 SQL 时退回旧列，避免整条上报被丢弃
        $cols = ['site_id', 'eid', 'kind', 'ts', 'day', 'path', 'title', 'ref', 'vid', 'sid', 'ip_hash'];
        $vals = [$siteId, $eid, $kind, $now, $today, $path, $title, $ref, $vid, $sid, $ipHash];
        if ($storeQuery && $query !== '' && ic_has_query_column()) {
            $pos = array_search('path', $cols, true);
            array_splice($cols, $pos + 1, 0, ['query']);
            array_splice($vals, $pos + 1, 0, [$query]);
        }
        if (ic_has_client_columns()) {
            array_push($cols, 'ip', 'ua', 'browser', 'browser_ver', 'os', 'device', 'lang', 'screen');
            array_push($vals, $ipPlain, $ua, $client['browser'], $client['browser_ver'],
                $client['os'], $client['device'], $lang, $screen);
        } else {
            $cols[] = 'ua';
            $vals[] = $ua;
        }
        $cols[] = 'duration';
        $vals[] = $duration;

        $sql = 'INSERT IGNORE INTO events (' . implode(',', $cols) . ') VALUES ('
             . implode(',', array_fill(0, count($cols), '?')) . ')';

        $pdo->beginTransaction();
        $ins = $pdo->prepare($sql);
        $ins->execute($vals);
        $inserted = ($ins->rowCount() === 1);

        if ($inserted && $kind === 'pv') {
            $pdo->prepare('INSERT IGNORE INTO uv_daily (site_id, d, vid) VALUES (?,?,?)')
                ->execute([$siteId, $today, $vid]);
        }
        $pdo->commit();
    } catch (Throwable $ex) {
        try {
            if (db()->inTransaction()) db()->rollBack();
        } catch (Throwable $ignored) {
        }
        // 采集失败绝不能影响宿主页面
    }
}
