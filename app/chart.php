<?php
declare(strict_types=1);
/**
 * 零依赖 SVG 图表组件（服务端渲染）
 *
 * 内网环境不能引 CDN / npm 包，也不想让后台依赖 Canvas 或 JS，
 * 所以这里全部用 PHP 直接拼 SVG：
 *   - 离线可用、打印友好、不受 CSP script-src 限制；
 *   - 悬浮提示用原生 <title>，无需任何脚本；
 *   - 所有文本都经过 e() 转义，坐标只由数字计算。
 *
 * 提供的函数：
 *   ic_line_chart()  多序列折线图（PV / UV 趋势）
 *   ic_bar_chart()   垂直柱状对比图（各维度分布）
 *   ic_hbar_chart()  水平条形图（热门页面，适合长路径）
 *   ic_sparkline()   KPI 卡片迷你趋势线
 */

/** 同一页面内生成多个渐变时避免 id 冲突 */
function ic_uid(string $prefix = 'g'): string {
    static $i = 0;
    return $prefix . (++$i);
}

/** 不依赖 mbstring 的按字符截断（PCRE 的 /u 是内置能力） */
function ic_trunc(string $s, int $limit): string {
    $ch = preg_split('//u', $s, -1, PREG_SPLIT_NO_EMPTY);
    if (!is_array($ch)) return $s;                 // 非 UTF-8 时原样返回
    if (count($ch) <= $limit) return $s;
    return implode('', array_slice($ch, 0, max(1, $limit - 1))) . '…';
}

/** 把数值去掉多余的 0，用于 SVG 坐标，避免 "100.0" 这种长字符串 */
function ic_f(float $v): string {
    $s = number_format($v, 2, '.', '');
    return rtrim(rtrim($s, '0'), '.');
}

/**
 * 计算「好看」的坐标轴刻度： step 取 1/2/2.5/5 × 10^n，并保证为整数，
 * 这样 PV 这类计数不会出现 2.5 次访问这种刻度。
 * @return array{step:int,top:int}
 */
function ic_axis(int $max, int $ticks = 4): array {
    $max  = max(1, $max);
    $raw  = $max / max(1, $ticks);
    $exp  = pow(10, floor(log10($raw)));
    $step = $exp;
    foreach ([1, 2, 2.5, 5, 10] as $m) {
        if ($raw <= $m * $exp + 1e-9) { $step = $m * $exp; break; }
    }
    $step = max(1, (int)ceil($step));
    $top  = max($ticks, (int)(ceil($max / $step) * $step));
    return ['step' => $step, 'top' => $top];
}

/**
 * 折线图
 * @param array<int,string> $labels X 轴标签（日期字符串或任意文本）
 * @param array<int,array{name:string,data:array,color:string,fill?:bool}> $series
 * @param array $opt summary => [['峰值日','11-05 · 12 PV'], ...], aria => 无障碍标签
 */
function ic_line_chart(array $labels, array $series, array $opt = []): string {
    $labels = array_values($labels);
    $n = count($labels);
    if ($n === 0 || !$series) return '<p class="muted">该区间暂无数据</p>';

    $max = 0;
    foreach ($series as $s) {
        foreach ($s['data'] as $v) $max = max($max, (int)$v);
    }
    $ax  = ic_axis($max);
    $top = $ax['top'];

    $W = 1000; $H = 300; $L = 48; $R = 18; $T = 18; $B = 36;
    $pw = $W - $L - $R; $ph = $H - $T - $B; $base = $T + $ph;

    $xf = function (int $i) use ($n, $L, $pw): float {
        return $n === 1 ? $L + $pw / 2 : $L + $pw * $i / ($n - 1);
    };
    $yf = function ($v) use ($T, $ph, $top): float {
        return $T + $ph - ((float)$v / $top) * $ph;
    };

    $o = '<div class="chart-box"><div class="chart-head"><div class="chart-legend">';
    foreach ($series as $s) {
        $o .= '<span class="lg"><i style="background:' . e((string)$s['color']) . '"></i>'
            . e((string)$s['name']) . '</span>';
    }
    $o .= '</div>';
    if (!empty($opt['summary'])) {
        $o .= '<div class="chart-summary">';
        foreach ($opt['summary'] as $x) {
            $o .= '<span>' . e((string)$x[0]) . ' <b>' . e((string)$x[1]) . '</b></span>';
        }
        $o .= '</div>';
    }
    $o .= '</div>';

    $gid = ic_uid('line');
    $o .= '<svg class="chart chart-line" viewBox="0 0 ' . $W . ' ' . $H . '" role="img" aria-label="'
        . e((string)($opt['aria'] ?? '按日趋势折线图')) . '">';
    $o .= '<defs><linearGradient id="' . $gid . '" x1="0" y1="0" x2="0" y2="1">'
        . '<stop offset="0%" stop-color="' . e((string)$series[0]['color']) . '" stop-opacity="0.22"></stop>'
        . '<stop offset="100%" stop-color="' . e((string)$series[0]['color']) . '" stop-opacity="0.01"></stop>'
        . '</linearGradient></defs>';

    // 横向网格与 Y 轴刻度
    for ($v = 0; $v <= $top; $v += $ax['step']) {
        $yy = $yf($v);
        $o .= '<line class="cg" x1="' . $L . '" y1="' . ic_f($yy) . '" x2="' . ($W - $R) . '" y2="' . ic_f($yy) . '"></line>'
            . '<text class="ca" x="' . ($L - 10) . '" y="' . ic_f($yy + 4) . '" text-anchor="end">' . (int)$v . '</text>';
    }

    // 面积 + 折线 + 数据点
    foreach ($series as $s) {
        $vals = array_values($s['data']);
        $pts  = [];
        for ($i = 0; $i < $n; $i++) {
            $pts[] = ic_f($xf($i)) . ',' . ic_f($yf((int)($vals[$i] ?? 0)));
        }
        $d = 'M' . implode(' L', $pts);
        if (!empty($s['fill']) && $n > 1) {
            $area = $d . ' L' . ic_f($xf($n - 1)) . ',' . ic_f($base)
                  . ' L' . ic_f($xf(0)) . ',' . ic_f($base) . ' Z';
            $o .= '<path d="' . $area . '" fill="url(#' . $gid . ')"></path>';
        }
        $o .= '<path class="cl" d="' . $d . '" fill="none" stroke="' . e((string)$s['color']) . '"></path>';
        if ($n <= 45) {
            for ($i = 0; $i < $n; $i++) {
                $o .= '<circle class="cl-dot" cx="' . ic_f($xf($i)) . '" cy="'
                    . ic_f($yf((int)($vals[$i] ?? 0))) . '" r="3" fill="'
                    . e((string)$s['color']) . '"></circle>';
            }
        }
    }

    // 透明命中区：鼠标移到任意一天都能看到当天全部序列的数值
    $cell = $n > 1 ? $pw / ($n - 1) : $pw;
    for ($i = 0; $i < $n; $i++) {
        $cx  = $xf($i);
        $rx  = max($L, $cx - $cell / 2);
        $rgt = min($W - $R, $cx + $cell / 2);
        if ($rgt <= $rx) $rgt = $rx + max(2.0, $cell / 2);
        $tip = (string)$labels[$i];
        foreach ($series as $s) {
            $vals = array_values($s['data']);
            $tip .= '  ·  ' . $s['name'] . ' ' . (int)($vals[$i] ?? 0);
        }
        $o .= '<rect class="chit" fill="transparent" pointer-events="all" x="' . ic_f($rx) . '" y="' . $T
            . '" width="' . ic_f($rgt - $rx) . '" height="' . $ph . '"><title>'
            . e($tip) . '</title></rect>';
    }

    // X 轴标签：最多约 9 个，首尾必定显示
    $every = max(1, (int)ceil($n / 9));
    for ($i = 0; $i < $n; $i++) {
        $show = ($i % $every === 0) || ($n > 1 && $i === $n - 1);
        if (!$show) continue;
        $txt = (string)$labels[$i];
        $txt = preg_match('/^\d{4}-\d{2}-\d{2}$/', $txt) ? substr($txt, 5) : ic_trunc($txt, 8);
        $anchor = $i === 0 ? 'start' : (($i === $n - 1) ? 'end' : 'middle');
        $o .= '<text class="ca" x="' . ic_f($xf($i)) . '" y="' . ($H - 12)
            . '" text-anchor="' . $anchor . '">' . e($txt) . '</text>';
    }

    return $o . '</svg></div>';
}

/**
 * 垂直柱状对比图
 * @param array<string,int> $data 名称 => 数值（内部会再按数值倒序）
 * @param array $opt limit / color / unit / rotate / label_every / aria / foot
 */
function ic_bar_chart(array $data, array $opt = []): string {
    if (!$data) {
        return '<p class="muted">该区间暂无数据</p>'
            . (!empty($opt['foot']) ? '<p class="chart-foot">' . e((string)$opt['foot']) . '</p>' : '');
    }
    // 分布类按数值降序更直观；时段等有序维度必须保留原始顺序
    if ($opt['sort'] ?? true) arsort($data);
    $limit  = max(1, (int)($opt['limit'] ?? 8));
    $color  = (string)($opt['color'] ?? '#2f6fd0');
    $unit   = (string)($opt['unit'] ?? 'PV');
    $rotate = (bool)($opt['rotate'] ?? true);
    $every  = max(1, (int)($opt['label_every'] ?? 1));
    $showVals = (bool)($opt['show_values'] ?? true);

    $items = array_slice($data, 0, $limit, true);
    if (($opt['sort'] ?? true) && count($data) > $limit) {
        $rest = 0;
        foreach (array_slice($data, $limit) as $v) $rest += (int)$v;
        if ($rest > 0) $items['其余 ' . (count($data) - $limit) . ' 项'] = $rest;
    }
    $total = 0; $max = 0;
    foreach ($items as $v) { $total += (int)$v; $max = max($max, (int)$v); }
    $ax  = ic_axis($max);
    $top = $ax['top'];

    $W = 520; $H = 238; $L = 44; $R = 12; $T = 20;
    $B = $rotate ? 62 : 32;
    $pw = $W - $L - $R; $ph = $H - $T - $B; $base = $T + $ph;
    $k = count($items);
    $slot = $pw / $k;
    $bw = max(3, min(42, $slot * 0.62));
    $yf = function ($v) use ($T, $ph, $top): float {
        return $T + $ph - ((float)$v / $top) * $ph;
    };

    $gid = ic_uid('bar');
    $o = '<svg class="chart chart-bar" viewBox="0 0 ' . $W . ' ' . $H . '" role="img" aria-label="'
       . e((string)($opt['aria'] ?? '分布柱状对比图')) . '">';
    $o .= '<defs><linearGradient id="' . $gid . '" x1="0" y1="0" x2="0" y2="1">'
        . '<stop offset="0%" stop-color="' . e($color) . '" stop-opacity="0.95"></stop>'
        . '<stop offset="100%" stop-color="' . e($color) . '" stop-opacity="0.52"></stop>'
        . '</linearGradient></defs>';

    for ($v = 0; $v <= $top; $v += $ax['step']) {
        $yy = $yf($v);
        $o .= '<line class="cg" x1="' . $L . '" y1="' . ic_f($yy) . '" x2="' . ($W - $R) . '" y2="' . ic_f($yy) . '"></line>'
            . '<text class="ca" x="' . ($L - 8) . '" y="' . ic_f($yy + 4) . '" text-anchor="end">' . (int)$v . '</text>';
    }

    $i = 0;
    foreach ($items as $name => $v0) {
        $v   = (int)$v0;
        $cx  = $L + $slot * $i + $slot / 2;
        $bx  = $cx - $bw / 2;
        $by  = $yf($v);
        $bh  = max(1.0, $base - $by);
        $pct = $total > 0 ? round($v / $total * 100, 1) : 0;
        $tip = (string)$name . '：' . $v . ' ' . $unit . '（占 ' . $pct . '%）';

        $o .= '<g class="cb-g"><rect class="cb-bar" x="' . ic_f($bx) . '" y="' . ic_f($by)
            . '" width="' . ic_f($bw) . '" height="' . ic_f($bh) . '" rx="3" fill="url(#'
            . $gid . ')"><title>' . e($tip) . '</title></rect>';
        if ($showVals) {
            $o .= '<text class="cb-val" x="' . ic_f($cx) . '" y="' . ic_f($by - 6)
                . '" text-anchor="middle">' . $v . '</text>';
        }

        if ($i % $every === 0) {
            $lbl = ic_trunc((string)$name, $rotate ? 7 : 5);
            $ly  = $base + 16;
            if ($rotate) {
                $o .= '<text class="ca cb-lbl" x="' . ic_f($cx) . '" y="' . ic_f($ly)
                    . '" text-anchor="end" transform="rotate(-30 ' . ic_f($cx) . ' ' . ic_f($ly)
                    . ')">' . e($lbl) . '</text>';
            } else {
                $o .= '<text class="ca cb-lbl" x="' . ic_f($cx) . '" y="' . ic_f($ly)
                    . '" text-anchor="middle">' . e($lbl) . '</text>';
            }
        }
        $o .= '</g>';
        $i++;
    }
    $o .= '</svg>';
    if (!empty($opt['foot'])) $o .= '<p class="chart-foot">' . e((string)$opt['foot']) . '</p>';
    return $o;
}

/**
 * 水平条形图（热门页面这类标签很长的场景，比柱状图更好读）
 * @param array<int,array{label:string,sub?:string,value:int}> $rows
 */
function ic_hbar_chart(array $rows): string {
    if (!$rows) return '<p class="muted">该区间暂无数据</p>';
    $max = 1; $total = 0;
    foreach ($rows as $r) { $max = max($max, (int)$r['value']); $total += (int)$r['value']; }

    $o = '<div class="hbar-list">';
    foreach ($rows as $r) {
        $v     = (int)$r['value'];
        $pct   = round($v / $max * 100, 1);
        $share = $total > 0 ? round($v / $total * 100, 1) : 0;
        $o .= '<div class="hbar-row">'
            . '<div class="hbar-name" title="' . e((string)$r['label']) . '"><span>' . e((string)$r['label']) . '</span>'
            . (!empty($r['sub']) ? '<small>' . e((string)$r['sub']) . '</small>' : '')
            . '</div>'
            . '<div class="hbar-track" title="' . e($v . ' PV · 占 Top 列表的 ' . $share . '%') . '"><i style="width:' . $pct . '%"></i></div>'
            . '<div class="hbar-val">' . $v . '</div>'
            . '</div>';
    }
    return $o . '</div>';
}

/** KPI 卡片里的迷你趋势线，无坐标轴 */
function ic_sparkline(array $values, string $color = '#2f6fd0'): string {
    $values = array_values($values);
    $n = count($values);
    if ($n === 0) return '';
    $max = 0.0;
    foreach ($values as $v) $max = max($max, (float)$v);
    if ($max <= 0) $max = 1.0;

    $W = 130; $H = 36; $pad = 3;
    $pw = $W - $pad * 2; $ph = $H - $pad * 2;
    $xf = function (int $i) use ($n, $pad, $pw): float {
        return $n === 1 ? $pad + $pw / 2 : $pad + $pw * $i / ($n - 1);
    };
    $yf = function ($v) use ($pad, $ph, $max): float {
        return $pad + $ph - ((float)$v / $max) * $ph;
    };

    $pts = [];
    for ($i = 0; $i < $n; $i++) $pts[] = ic_f($xf($i)) . ',' . ic_f($yf($values[$i]));
    $d = 'M' . implode(' L', $pts);
    $area = $d . ' L' . ic_f($xf($n - 1)) . ',' . ($H - $pad)
          . ' L' . ic_f($xf(0)) . ',' . ($H - $pad) . ' Z';

    return '<svg class="spark" viewBox="0 0 ' . $W . ' ' . $H . '" aria-hidden="true">'
        . '<path d="' . $area . '" fill="' . e($color) . '" fill-opacity="0.12"></path>'
        . '<path d="' . $d . '" fill="none" stroke="' . e($color)
        . '" stroke-width="1.8" stroke-linejoin="round" stroke-linecap="round"></path>'
        . '<circle cx="' . ic_f($xf($n - 1)) . '" cy="' . ic_f($yf($values[$n - 1]))
        . '" r="2.6" fill="' . e($color) . '"></circle></svg>';
}
