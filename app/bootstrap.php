<?php
declare(strict_types=1);

define('APP_ROOT', dirname(__DIR__));
require_once APP_ROOT . '/app/ip.php';

/** 读取配置项 */
function cfg(string $key, $default = null) {
    static $conf = null;
    if ($conf === null) {
        $file = APP_ROOT . '/app/config.php';
        if (!is_file($file)) {
            http_response_code(500);
            exit('Missing app/config.php');
        }
        $conf = require $file;
        date_default_timezone_set($conf['timezone'] ?? 'Asia/Shanghai');
    }
    return array_key_exists($key, $conf) ? $conf[$key] : $default;
}

/** 单例 PDO */
function db(): PDO {
    static $pdo = null;
    if ($pdo === null) {
        $pdo = new PDO(cfg('db_dsn'), cfg('db_user'), cfg('db_pass'), [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES   => false,
        ]);
    }
    return $pdo;
}

function e($s): string {
    return htmlspecialchars((string)$s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function cut($s, int $len): string {
    return substr((string)$s, 0, $len);
}

/**
 * 客户端 IP。经 frp / Nginx 等可信反代时，从转发头还原真实客户端 IP；
 * 未配置 trusted_proxies 时保持原行为（只信 REMOTE_ADDR），避免伪造头污染统计。
 */
function client_ip(): string {
    return client_ip_info()['ip'];
}

/** 完整解析结果，供后台与自检页展示代理链路诊断 */
function client_ip_info(): array {
    static $info = null;
    if ($info === null) {
        $info = ic_resolve_client_ip(
            $_SERVER,
            (array)cfg('trusted_proxies', []),
            (array)cfg('real_ip_headers', [])
        );
    }
    return $info;
}

/** 取 URL 的 origin（含端口），用于 Referer 白名单比对 */
function origin_from_url(string $url): string {
    $p = parse_url($url);
    if (!$p || empty($p['host'])) return '';
    $scheme = strtolower($p['scheme'] ?? 'http');
    $port = isset($p['port']) ? ':' . (int)$p['port'] : '';
    return $scheme . '://' . $p['host'] . $port;
}

/** 取 URL 的 path，丢弃 query，避免把 token / 手机号写进日志 */
function path_from_url(string $url): string {
    $p = parse_url($url);
    return isset($p['path']) && $p['path'] !== '' ? cut($p['path'], 512) : '';
}

/**
 * 统计服务的对外根地址，后台生成嵌入代码时使用。
 * 优先取 app/config.php 的 public_base_url（非 80 端口 / 有反向代理时必须显式配置）；
 * 留空才从当前请求推断，并在 HTTP_HOST 缺端口时用 SERVER_PORT 补齐。
 */
function base_url(): string {
    $configured = trim((string)cfg('public_base_url', ''));
    if ($configured !== '') {
        if (!preg_match('#^https?://#i', $configured)) {
            $configured = 'http://' . $configured;   // 允许只填 192.168.10.5:8899
        }
        return rtrim($configured, '/');
    }

    $https = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off');
    // Cloudflare Tunnel / frp / Nginx 在边缘终结 TLS、回源仍是 HTTP，此时 $_SERVER['HTTPS'] 为空。
    // 若不还原协议，自动推断会生成 http:// 的嵌入代码，被 HTTPS 宿主页当成混合内容拦截。
    // 只信任来自 trusted_proxies 的转发协议头，避免客户端伪造 X-Forwarded-Proto 影响生成的地址。
    // TLS 在边缘终结时，源站 SERVER_PORT 通常是 80，与对外端口（443）无关，
    // 绝不能拿它去补 https:// 地址的端口，否则会生成 https://域名:80 导致握手失败。
    $protoFromProxy = false;
    if (!$https) {
        $trusted = (array)cfg('trusted_proxies', []);
        $remote  = ic_norm_ip((string)($_SERVER['REMOTE_ADDR'] ?? ''));
        if ($trusted && ic_ip_trusted($remote, $trusted)) {
            $proto = strtolower(trim(explode(',', (string)($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? ''))[0]));
            if ($proto !== 'https') {
                // Cloudflare 还会发 CF-Visitor: {"scheme":"https"}
                $cv = (string)($_SERVER['HTTP_CF_VISITOR'] ?? '');
                if ($cv !== '' && preg_match('/"scheme"\s*:\s*"https"/i', $cv)) $proto = 'https';
            }
            if ($proto === 'https') {
                $https = true;
                $protoFromProxy = true;
            }
        }
    }
    $scheme = $https ? 'https' : 'http';
    $host   = (string)($_SERVER['HTTP_HOST'] ?? '');
    if ($host === '') {
        $host = (string)($_SERVER['SERVER_NAME'] ?? '127.0.0.1');
    }

    // parse_url 能正确区分 "IP:8899" 与 "[::1]"，不会把 IPv6 的冒号误判成端口
    $parts = parse_url($scheme . '://' . $host);
    if ($parts && !isset($parts['port']) && !$protoFromProxy) {
        $port = (int)($_SERVER['SERVER_PORT'] ?? 0);
        $defaultPort = $https ? 443 : 80;
        if ($port > 0 && $port !== $defaultPort) {
            $host .= ':' . $port;
        }
    }
    return $scheme . '://' . $host;
}

/**
 * events 表是否已升级出客户端字段。
 * 用于向后兼容：没执行升级 SQL 时采集端会退回旧列写入，绝不丢数据。
 */
function ic_has_client_columns(): bool {
    static $has = null;
    if ($has === null) {
        try {
            $has = (bool)db()->query("SHOW COLUMNS FROM `events` LIKE 'browser'")->fetch();
        } catch (Throwable $ex) {
            $has = false;
        }
    }
    return $has;
}

/**
 * events 表是否已升级出 URL 查询串列。
 * 未执行 sql/upgrade_query.sql 时采集端跳过写入 query，绝不丢整条上报。
 */
function ic_has_query_column(): bool {
    static $has = null;
    if ($has === null) {
        try {
            $has = (bool)db()->query("SHOW COLUMNS FROM `events` LIKE 'query'")->fetch();
        } catch (Throwable $ex) {
            $has = false;
        }
    }
    return $has;
}

/**
 * URL 查询串清洗：敏感参数值打码为 ***，非法参数名丢弃，长度封顶 512。
 * 打码放在服务端，更新敏感词规则不需要改已贴到页面里的嵌入代码。
 * 保留参数名与值的原始编码，不改变 URL 语义。
 */
function ic_clean_query(string $raw, array $extra = []): string {
    $raw = trim($raw);
    if ($raw === '') return '';
    if ($raw[0] === '?') $raw = substr($raw, 1);
    if ($raw === '') return '';

    // 常见敏感词，参数名大小写不敏感；可在 config.php 里补充本站特有的词
    $sensitive = [
        'token', 'access_token', 'refresh_token', 'id_token', 'bearer',
        'pwd', 'password', 'passwd', 'pass', 'passcode', 'pin',
        'secret', 'client_secret', 'apikey', 'api_key', 'key',
        'session', 'sessionid', 'session_id', 'auth', 'authorization',
        'code', 'signature', 'sign', 'sig', 'ticket', 'otp',
        'csrf', '_csrf', 'csrf_token', 'verify', 'verification',
    ];
    foreach ($extra as $n) {
        $n = strtolower(trim((string)$n));
        if ($n !== '') $sensitive[] = $n;
    }
    $sensitive = array_unique($sensitive);

    $out = [];
    foreach (explode('&', $raw) as $part) {
        if ($part === '') continue;
        $kv = explode('=', $part, 2);
        $nameRaw = $kv[0];
        // 只放行安全字符（含 %XX 编码与 ids[] 这类数组参数），杜绝脚本片段进报表
        if (!preg_match('/^[A-Za-z0-9_.%\[\]-]+$/', $nameRaw)) continue;
        $name = strtolower(rawurldecode($nameRaw));
        $val  = $kv[1] ?? '';
        if (in_array($name, $sensitive, true)) {
            $val = '***';
        } elseif (strlen($val) > 128) {
            $val = substr($val, 0, 128) . '...';
        }
        $out[] = $nameRaw . '=' . $val;
    }
    if (!$out) return '';
    $res = substr(implode('&', $out), 0, 512);
    $res = preg_replace('/[\x80-\xBF]+$/', '', $res);   // 去掉末尾可能被截断的 UTF-8 字节
    return $res === '' ? '' : '?' . $res;
}
