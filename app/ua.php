<?php
declare(strict_types=1);

/**
 * 轻量 User-Agent 解析器，零依赖（内网不能引 CDN / Composer 包）。
 * 覆盖办公内网常见的国产壳浏览器与工具请求。
 * 返回：browser / browser_ver / os / device
 */
function ic_parse_ua(string $ua): array {
    $r = ['browser' => '其他', 'browser_ver' => '', 'os' => '其他', 'device' => '桌面'];
    $u = trim($ua);
    if ($u === '') {
        return ['browser' => '未知', 'browser_ver' => '', 'os' => '未知', 'device' => '未知'];
    }

    // 工具 / 脚本请求：连通性自检用的 curl 会归到这里，方便识别后忽略
    if (preg_match('#curl/|Wget/|PostmanRuntime|python-requests|Go-http-client|HttpClient|okhttp|node-fetch|Java/#i', $u)) {
        $r['browser'] = '工具/脚本';
        $r['os'] = '—';
        $r['device'] = '工具';
        if (preg_match('#(?:curl|Wget)/([\d.]+)#i', $u, $m)) $r['browser_ver'] = $m[1];
        return $r;
    }

    // ---- 操作系统 ----
    if (preg_match('/Windows Phone(?: OS)? ([\d.]+)/i', $u, $m)) {
        $r['os'] = 'Windows Phone ' . $m[1];
    } elseif (preg_match('/Windows NT ([\d.]+)/i', $u, $m)) {
        $map = [
            '10.0' => 'Windows 10/11',   // Win11 的 UA 仍报 NT 10.0，无法区分
            '6.4'  => 'Windows 10',
            '6.3'  => 'Windows 8.1',
            '6.2'  => 'Windows 8',
            '6.1'  => 'Windows 7',
            '6.0'  => 'Windows Vista',
            '5.2'  => 'Windows XP x64',
            '5.1'  => 'Windows XP',
        ];
        $r['os'] = $map[$m[1]] ?? ('Windows NT ' . $m[1]);
    } elseif (preg_match('/HarmonyOS|HuaweiBrowser/i', $u)) {
        $r['os'] = 'HarmonyOS';
    } elseif (preg_match('/(?:iPhone|iPad|iPod).*?OS (\d+)[_.](\d+)/i', $u, $m)) {
        $r['os'] = 'iOS ' . $m[1] . '.' . $m[2];
    } elseif (preg_match('/CPU (?:iPhone )?OS (\d+)[_.](\d+)/i', $u, $m)) {
        $r['os'] = 'iOS ' . $m[1] . '.' . $m[2];
    } elseif (preg_match('/Android ([\d.]+)/i', $u, $m)) {
        $r['os'] = 'Android ' . $m[1];
    } elseif (preg_match('/CrOS/i', $u)) {
        $r['os'] = 'Chrome OS';
    } elseif (preg_match('/Mac OS X (\d+)[_.](\d+)/i', $u, $m)) {
        $r['os'] = 'macOS ' . $m[1] . '.' . $m[2];
    } elseif (preg_match('/Macintosh|Mac OS/i', $u)) {
        $r['os'] = 'macOS';
    } elseif (preg_match('/Linux/i', $u)) {
        $r['os'] = 'Linux';
    }

    // ---- 设备类型 ----
    if (preg_match('/iPad|Tablet|PlayBook|Silk/i', $u) ||
        (stripos($u, 'Android') !== false && stripos($u, 'Mobile') === false)) {
        $r['device'] = '平板';
    } elseif (preg_match('/iPhone|iPod|Windows Phone|Mobile|HarmonyOS/i', $u)) {
        $r['device'] = '手机';
    }

    // ---- 浏览器（顺序敏感：国产壳浏览器 UA 里也含 Chrome / Safari）----
    $rules = [
        '微信内置'   => '/MicroMessenger\/([\d.]+)/i',
        'Edge'      => '/(?:Edg|Edge|EdgA|EdgiOS)\/([\d.]+)/i',
        'QQ浏览器'   => '/QQBrowser\/([\d.]+)/i',
        '360浏览器'  => '/(?:QIHU 360SE|360SE|360EE|360 Aphone Browser)/i',
        '搜狗浏览器' => '/(?:MetaSr|SE 2\.X MetaSr)/i',
        'UC浏览器'   => '/UCBrowser\/([\d.]+)/i',
        '夸克浏览器' => '/Quark\/([\d.]+)/i',
        'Opera'     => '/(?:OPR|Opera)\/([\d.]+)/i',
        'Firefox'   => '/(?:Firefox|FxiOS)\/([\d.]+)/i',
        'IE'        => '/(?:MSIE ([\d.]+)|Trident\/.*rv:([\d.]+))/i',
        'Chrome'    => '/(?:Chrome|CriOS)\/([\d.]+)/i',
        'Safari'    => '/Version\/([\d.]+).*Safari/i',
    ];
    foreach ($rules as $name => $pattern) {
        if (preg_match($pattern, $u, $m)) {
            $r['browser'] = $name;
            // 部分规则（如 IE）有两个捕获组，取第一个非空的版本号
            for ($i = 1; $i < count($m); $i++) {
                if (isset($m[$i]) && $m[$i] !== '') {
                    $r['browser_ver'] = $m[$i];
                    break;
                }
            }
            break;
        }
    }
    if ($r['browser'] === '其他' && preg_match('/Safari\//i', $u)) $r['browser'] = 'Safari';

    return $r;
}

/** 单条明细用的短标签，例如 Chrome 120 / Windows 10/11 · 桌面 */
function ic_client_label(array $p): string {
    $b = $p['browser'] . ($p['browser_ver'] !== '' ? ' ' . $p['browser_ver'] : '');
    return trim($b . ' / ' . $p['os']);
}
