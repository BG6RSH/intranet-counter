/*
 * 报表图表初始化（ES5，无构建、无外部依赖）
 *
 * 数据来自页面里的 <script type="application/json" id="ic-chart-data">，
 * 图表引擎为同站点本地文件 ../static/echarts/6.0.0/echarts.min.js。
 *
 * 设计要点：
 *   - ECharts 未加载 / 初始化抛错时，不给 slot 加 ec-ready，
 *     服务端生成的 SVG 兜底会保持可见，后台永远不会出现空白图表。
 *   - 数据不由 PHP 拼接成 JS 代码，避免 XSS 与 CSP 对 inline script 的限制。
 *   - tooltip 中的名称全部转义（数据来自数据库，可能含 HTML 字符）。
 */
(function () {
  'use strict';

  var dataNode = document.getElementById('ic-chart-data');
  if (!dataNode || typeof window.echarts === 'undefined') return;

  var payload;
  try {
    payload = JSON.parse(dataNode.textContent || '{}');
  } catch (err) {
    return;
  }
  if (!payload || typeof payload !== 'object') return;

  var C = {
    text: '#8a919c',
    label: '#4a505b',
    grid: '#eef0f3',
    axis: '#e3e6ea'
  };

  var TOOLTIP = {
    backgroundColor: '#fff',
    borderColor: '#e3e6ea',
    borderWidth: 1,
    textStyle: { color: '#22262e', fontSize: 12 },
    extraCssText: 'box-shadow:0 6px 20px rgba(20,30,50,.10);border-radius:8px;padding:8px 12px;'
  };

  function merge(a, b) {
    var out = {}, k;
    for (k in a) if (Object.prototype.hasOwnProperty.call(a, k)) out[k] = a[k];
    for (k in b) if (Object.prototype.hasOwnProperty.call(b, k)) out[k] = b[k];
    return out;
  }

  function esc(s) {
    return String(s === null || s === undefined ? '' : s).replace(/[&<>"']/g, function (ch) {
      return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[ch];
    });
  }

  function trunc(s, n) {
    var a = String(s === null || s === undefined ? '' : s).split('');
    return a.length > n ? a.slice(0, n - 1).join('') + '…' : a.join('');
  }

  function rgba(hex, alpha) {
    var h = String(hex || '#2f6fd0').replace('#', '');
    if (h.length === 3) h = h.charAt(0) + h.charAt(0) + h.charAt(1) + h.charAt(1) + h.charAt(2) + h.charAt(2);
    var n = parseInt(h, 16);
    if (isNaN(n)) n = 0x2f6fd0;
    return 'rgba(' + ((n >> 16) & 255) + ',' + ((n >> 8) & 255) + ',' + (n & 255) + ',' + alpha + ')';
  }

  /** 用配置对象写渐变，不依赖 echarts.graphic 命名空间 */
  function fade(hex, from, to, horizontal) {
    return {
      type: 'linear',
      x: 0, y: 0,
      x2: horizontal ? 1 : 0,
      y2: horizontal ? 0 : 1,
      colorStops: [
        { offset: 0, color: rgba(hex, from) },
        { offset: 1, color: rgba(hex, to) }
      ]
    };
  }

  function valueAxis() {
    return {
      type: 'value', min: 0, minInterval: 1,
      axisLine: { show: false }, axisTick: { show: false },
      axisLabel: { color: C.text, fontSize: 11 },
      splitLine: { lineStyle: { color: C.grid, width: 1 } }
    };
  }

  /* ---------------- 折线图：按日 PV / UV 趋势 ---------------- */
  function lineOption(d) {
    var labels = d.labels || [];
    var series = d.series || [];
    var many = labels.length > 45;
    var names = [], colors = [], i;
    for (i = 0; i < series.length; i++) {
      names.push(series[i].name);
      colors.push(series[i].color);
    }
    return {
      color: colors,
      animationDuration: 420,
      textStyle: { fontFamily: '-apple-system,BlinkMacSystemFont,Segoe UI,Microsoft YaHei,sans-serif' },
      tooltip: merge(TOOLTIP, {
        trigger: 'axis',
        axisPointer: { type: 'line', lineStyle: { color: '#c9d2de', type: 'dashed' } }
      }),
      legend: {
        data: names, top: 0, left: 0, itemGap: 18,
        itemWidth: 12, itemHeight: 8, icon: 'roundRect',
        textStyle: { color: C.label, fontSize: 12 }
      },
      toolbox: {
        show: true, right: 4, top: -2, itemSize: 13,
        iconStyle: { borderColor: C.text },
        emphasis: { iconStyle: { borderColor: '#2f6fd0' } },
        feature: {
          saveAsImage: { title: '保存为图片', name: '访问趋势', pixelRatio: 2, backgroundColor: '#fff' }
        }
      },
      grid: { left: 48, right: 18, top: 38, bottom: 30 },
      xAxis: {
        type: 'category', boundaryGap: false, data: labels,
        axisLine: { lineStyle: { color: C.axis } }, axisTick: { show: false },
        axisLabel: {
          color: C.text, fontSize: 11, hideOverlap: true,
          formatter: function (v) {
            return /^\d{4}-\d{2}-\d{2}$/.test(v) ? v.slice(5) : trunc(v, 8);
          }
        }
      },
      yAxis: valueAxis(),
      series: (function () {
        var out = [];
        for (var j = 0; j < series.length; j++) {
          var s = series[j];
          var item = {
            name: s.name, type: 'line', smooth: 0.28,
            symbol: 'circle', showSymbol: !many, symbolSize: 6,
            connectNulls: true,
            lineStyle: { width: 2.2 },
            itemStyle: { color: s.color, borderColor: '#fff', borderWidth: 1.5 },
            emphasis: { focus: 'series' },
            data: s.data || []
          };
          if (s.area) item.areaStyle = { color: fade(s.color, 0.22, 0.01, false) };
          out.push(item);
        }
        return out;
      })()
    };
  }

  /* ---------------- 垂直柱状图：各维度分布对比 ---------------- */
  function barOption(d) {
    var rotate = !!d.rotate;
    var every = Math.max(1, parseInt(d.labelEvery, 10) || 1);
    var color = d.color || '#2f6fd0';
    var values = d.values || [];
    var total = 0, i;
    for (i = 0; i < values.length; i++) total += Number(values[i]) || 0;

    return {
      color: [color],
      animationDuration: 420,
      textStyle: { fontFamily: '-apple-system,BlinkMacSystemFont,Segoe UI,Microsoft YaHei,sans-serif' },
      tooltip: merge(TOOLTIP, {
        trigger: 'axis',
        axisPointer: { type: 'shadow' },
        formatter: function (params) {
          var p = params && params[0];
          if (!p) return '';
          var v = Number(p.value) || 0;
          var share = total > 0 ? (v / total * 100).toFixed(1) : '0.0';
          return '<b>' + esc(p.name) + '</b><br/>' + v + ' ' + esc(d.unit || 'PV') + ' · 占 ' + share + '%';
        }
      }),
      grid: { left: 44, right: 12, top: 26, bottom: rotate ? 68 : 34 },
      xAxis: {
        type: 'category', data: d.labels || [],
        axisLine: { lineStyle: { color: C.axis } }, axisTick: { show: false },
        axisLabel: {
          color: C.text, fontSize: 11,
          rotate: rotate ? 30 : 0,
          interval: function (idx) { return idx % every === 0; },
          formatter: function (v) { return trunc(v, rotate ? 7 : 5); }
        }
      },
      yAxis: valueAxis(),
      series: [{
        type: 'bar', data: values, barMaxWidth: 42,
        itemStyle: { color: fade(color, 0.95, 0.52, false), borderRadius: [3, 3, 0, 0] },
        label: {
          show: d.showValues !== false, position: 'top',
          color: C.label, fontSize: 11, fontWeight: 600
        },
        emphasis: { itemStyle: { opacity: 0.75 } }
      }]
    };
  }

  /* ---------------- 水平柱状图：热门页面（长路径） ---------------- */
  function hbarOption(d) {
    var labels = (d.labels || []).slice().reverse();
    var subs = (d.subs || []).slice().reverse();
    var values = (d.values || []).slice().reverse();

    return {
      color: ['#2f6fd0'],
      animationDuration: 420,
      textStyle: { fontFamily: '-apple-system,BlinkMacSystemFont,Segoe UI,Microsoft YaHei,sans-serif' },
      tooltip: merge(TOOLTIP, {
        trigger: 'item',
        formatter: function (p) {
          var i = p.dataIndex;
          var sub = subs[i] ? '<br/><span style="color:#8a919c">' + esc(subs[i]) + '</span>' : '';
          return '<b>' + esc(labels[i]) + '</b>' + sub + '<br/>' + p.value + ' PV';
        }
      }),
      grid: { left: 8, right: 56, top: 8, bottom: 8, containLabel: true },
      xAxis: { type: 'value', show: false },
      yAxis: {
        type: 'category', data: labels,
        axisLine: { show: false }, axisTick: { show: false },
        axisLabel: {
          color: C.label, fontSize: 12, width: 220, overflow: 'truncate',
          formatter: function (v) { return trunc(v, 24); }
        }
      },
      series: [{
        type: 'bar', data: values, barMaxWidth: 14,
        itemStyle: { color: fade('#2f6fd0', 1, 0.62, true), borderRadius: [0, 6, 6, 0] },
        label: { show: true, position: 'right', color: C.label, fontSize: 12, fontWeight: 600 },
        emphasis: { itemStyle: { opacity: 0.8 } }
      }]
    };
  }

  /* ---------------- 逐个接管图表槽位 ---------------- */
  var instances = [];
  var slots = document.querySelectorAll('.chart-slot');

  function each(list, fn) {
    for (var i = 0; i < list.length; i++) fn(list[i], i);
  }

  each(slots, function (slot) {
    var id = slot.getAttribute('data-slot');
    var d = payload[id];
    if (!d) return;
    var box = document.getElementById('ec-' + id);
    if (!box) return;

    var option;
    try {
      option = d.type === 'line' ? lineOption(d) : (d.type === 'hbar' ? hbarOption(d) : barOption(d));
    } catch (err) {
      return; // 保留 SVG
    }

    // 先显示容器再 init，确保 ECharts 能取到真实宽高
    slot.className = slot.className.replace(/\s*ec-ready\b/g, '') + ' ec-ready';
    try {
      var inst = window.echarts.init(box, null, { renderer: 'canvas' });
      inst.setOption(option, true);
      instances.push(inst);
    } catch (err2) {
      slot.className = slot.className.replace(/\s*ec-ready\b/g, '');
      if (window.console && window.console.warn) {
        window.console.warn('[ic-charts] 初始化失败，已回退服务端 SVG：' + id, err2);
      }
    }
  });

  if (window.addEventListener) {
    window.addEventListener('resize', function () {
      each(instances, function (inst) {
        try { inst.resize(); } catch (e) { /* noop */ }
      });
    });
  }

  var note = document.getElementById('ic-engine-note');
  if (note && instances.length > 0) {
    note.textContent = '图表引擎：ECharts ' + (window.echarts.version || '6.0.0') +
      '（本地加载，已启用 ' + instances.length + ' 张交互图表）· 图例可点击切换序列，趋势图右上角可保存为图片。' +
      '若本地脚本未加载或浏览器禁用 JS，页面会自动回退到服务端 SVG 图表。CSV 导出仍包含逐日与全部维度明细。';
  }
})();
