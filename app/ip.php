<?php
declare(strict_types=1);

/**
 * 反向代理 / frp / Cloudflare Tunnel 环境下的真实客户端 IP 解析。
 *
 * 纯函数、无外部依赖：app/bootstrap.php 与 public/check.php 都能单独引用。
 *
 * 安全前提：只有当 REMOTE_ADDR 命中 trusted_proxies 时才信任转发头。
 * 否则任何人都能用伪造的 X-Forwarded-For 污染来源 IP 并绕过限流。
 *
 * Cloudflare 的特殊之处：CF-Connecting-IP 由边缘无条件覆盖，是单值且客户端无法伪造；
 * 而 X-Forwarded-For 是原样转发客户端请求链，攻击者可以 prepend 任意地址。
 * 因此经 Cloudflare 时必须优先采信 CF-Connecting-IP，绝不能先读 X-Forwarded-For。
 */

/** 规范化并校验一个 IP；兼容 [IPv6]、IPv4:port、Forwarded 头里的引号 */
function ic_norm_ip(string $s): string {
    $s = trim($s, " \t\n\r\0\x0B\"'");
    if ($s === '') return '';
    if ($s[0] === '[') {
        $end = strpos($s, ']');
        if ($end === false) return '';
        $v6 = substr($s, 1, $end - 1);
        return filter_var($v6, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6) ? $v6 : '';
    }
    // 个别代理会写成 1.2.3.4:5678；IPv6 不含点，不会被误伤
    if (substr_count($s, ':') === 1 && strpos($s, '.') !== false) {
        $s = substr($s, 0, (int)strrpos($s, ':'));
    }
    return filter_var($s, FILTER_VALIDATE_IP) ? $s : '';
}

/** CIDR 匹配，IPv4 / IPv6 通用 */
function ic_ip_in_cidr(string $ip, string $cidr): bool {
    $parts = explode('/', $cidr, 2);
    if (count($parts) !== 2) return false;
    $bits = (int)$parts[1];
    $a = @inet_pton($ip);
    $b = @inet_pton(trim($parts[0]));
    if ($a === false || $b === false || strlen($a) !== strlen($b)) return false;
    $max = strlen($a) * 8;
    if ($bits < 0 || $bits > $max) return false;
    $whole = intdiv($bits, 8);
    $rem   = $bits % 8;
    if ($whole > 0 && substr($a, 0, $whole) !== substr($b, 0, $whole)) return false;
    if ($rem === 0) return true;
    $mask = (0xFF << (8 - $rem)) & 0xFF;
    return (ord($a[$whole]) & $mask) === (ord($b[$whole]) & $mask);
}

/** 是否信任该 IP 作为代理；'*' 表示信任任意 REMOTE_ADDR（仅限完全隔离的内网） */
function ic_ip_trusted(string $ip, array $rules): bool {
    if ($ip === '') return false;
    foreach ($rules as $rule) {
        $rule = trim((string)$rule);
        if ($rule === '*') return true;
        if ($rule === '') continue;
        if (strpos($rule, '/') !== false) {
            if (ic_ip_in_cidr($ip, $rule)) return true;
        } elseif (strcasecmp($rule, $ip) === 0) {
            return true;
        }
    }
    return false;
}

function ic_trust_any(array $rules): bool {
    foreach ($rules as $r) {
        if (trim((string)$r) === '*') return true;
    }
    return false;
}

function ic_default_headers(): array {
    return [
        // Cloudflare 边缘注入，优先级最高。CF 下 X-Forwarded-For 可被客户端伪造，
        // 故必须排在它前面；非 Cloudflare 环境没有这些头，会自动跳过，互不影响。
        'HTTP_CF_CONNECTING_IP', 'HTTP_TRUE_CLIENT_IP',
        'HTTP_X_FORWARDED_FOR', 'HTTP_X_REAL_IP', 'HTTP_X_FORWARDED',
        'HTTP_X_CLIENT_IP', 'HTTP_FORWARDED',
    ];
}

/**
 * 边缘代理「无条件覆盖」的权威头。
 * 只要 REMOTE_ADDR 已通过 trusted_proxies 校验，这些头里的合法 IP 就是最终答案：
 * 不做剔除可信代理链、也不做「等于代理自身就跳过」的处理，避免被后续可伪造的头夺走结果。
 */
function ic_authoritative_headers(): array {
    return ['HTTP_CF_CONNECTING_IP', 'HTTP_TRUE_CLIENT_IP'];
}

function ic_header_label(string $key): string {
    $map = [
        'HTTP_CF_CONNECTING_IP' => 'CF-Connecting-IP',
        'HTTP_TRUE_CLIENT_IP'   => 'True-Client-IP',
        'HTTP_X_FORWARDED_FOR'  => 'X-Forwarded-For',
        'HTTP_X_REAL_IP'        => 'X-Real-IP',
        'HTTP_FORWARDED'        => 'Forwarded',
        'HTTP_X_FORWARDED'      => 'X-Forwarded',
        'HTTP_X_CLIENT_IP'      => 'X-Client-IP',
        'HTTP_X_ORIGINAL_FORWARDED_FOR' => 'X-Original-Forwarded-For',
        'REMOTE_ADDR'           => 'REMOTE_ADDR',
    ];
    return $map[strtoupper($key)] ?? $key;
}

/**
 * 解析真实客户端 IP。
 *
 * @param array $s       $_SERVER
 * @param array $trusted trusted_proxies 配置（IP / CIDR / '*'）
 * @param array $headers 转发头优先级，空则用默认值
 * @return array{ip:string,remote:string,source:string,trusted:bool,reason:string,present:array,chain:array}
 */
function ic_resolve_client_ip(array $s, array $trusted, array $headers = []): array {
    $remote = ic_norm_ip((string)($s['REMOTE_ADDR'] ?? ''));
    $info = [
        'ip'      => $remote,
        'remote'  => $remote,
        'source'  => 'REMOTE_ADDR',
        'trusted' => false,
        'reason'  => '',
        'present' => [],
        'chain'   => [],
    ];

    foreach (ic_default_headers() as $k) {
        if (isset($s[$k]) && (string)$s[$k] !== '') {
            $info['present'][ic_header_label($k)] = substr((string)$s[$k], 0, 255);
        }
    }

    if (!$headers) $headers = ic_default_headers();
    $info['trusted'] = ic_ip_trusted($remote, $trusted);
    if (!$info['trusted']) {
        $info['reason'] = $info['present']
            ? 'REMOTE_ADDR 不在 trusted_proxies 中，为防止伪造已忽略转发头'
            : '直连请求，未发现转发头';
        return $info;
    }

    foreach ($headers as $key) {
        $raw = (string)($s[$key] ?? '');
        if (trim($raw) === '') continue;

        $candidates = [];
        if (strtoupper($key) === 'HTTP_FORWARDED') {
            if (preg_match_all('/\bfor\s*=\s*("[^"]+"|[^;,\s]+)/i', $raw, $m)) {
                foreach ($m[1] as $v) {
                    $n = ic_norm_ip((string)$v);
                    if ($n !== '') $candidates[] = $n;
                }
            }
        } else {
            foreach (explode(',', $raw) as $v) {
                $n = ic_norm_ip($v);
                if ($n !== '') $candidates[] = $n;
            }
        }
        if (!$candidates) continue;

        if (!$info['chain']) $info['chain'] = $candidates;

        // Cloudflare 边缘注入的头是单值且客户端无法伪造：直接采信并结束，
        // 不能走下面的「从右往左剔除可信代理」逻辑，更不能因为等于代理自身就跳到可伪造的 XFF。
        if (in_array(strtoupper($key), ic_authoritative_headers(), true)) {
            $info['ip']     = $candidates[0];
            $info['source'] = ic_header_label($key);
            $info['reason'] = 'Cloudflare 边缘注入的 ' . ic_header_label($key) . '（客户端无法伪造）';
            return $info;
        }

        if (ic_trust_any($trusted)) {
            // 信任任意代理时取链路最右一项：末端代理写入的地址最可信，伪造项通常在左
            $pick = $candidates[count($candidates) - 1];
            $reason = 'trusted_proxies = *，取转发链最右一项';
        } else {
            // 从右往左剔除所有可信代理，第一个不可信的地址即真实客户端
            $pick = $candidates[0];
            for ($i = count($candidates) - 1; $i >= 0; $i--) {
                if (!ic_ip_trusted($candidates[$i], $trusted)) {
                    $pick = $candidates[$i];
                    break;
                }
            }
            $reason = '已信任代理 ' . $remote . '，从转发链中剔除代理地址';
        }
        // 有些代理（含部分 frp 版本）会写 X-Forwarded-For: <代理自身 IP>，
        // 此时该头没有提供任何真实客户端信息，应继续尝试后续头（如 X-Real-IP），
        // 而不是把代理地址当成结果直接返回。
        if ($pick === '' || $pick === $remote) {
            $stale = [$key, $pick, $reason];
            continue;
        }
        $info['ip']     = $pick;
        $info['source'] = ic_header_label($key);
        $info['reason'] = $reason;
        return $info;
    }

    if (isset($stale)) {
        $info['source'] = ic_header_label($stale[0]);
        $info['reason'] = '转发头里只有代理自身地址 ' . $remote
            . '，没有真实客户端 IP（多为 tcp 类型 frp 或上游覆盖了头）';
    } elseif ($info['present']) {
        $info['reason'] = '转发头存在但没有解析出合法 IP 地址';
    } else {
        $info['reason'] = 'REMOTE_ADDR 是可信代理，但请求中没有可用转发头（tcp 类型 frp 需开 Proxy Protocol）';
    }
    return $info;
}
