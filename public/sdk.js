/*
 * 访问统计 SDK（零依赖）
 * 用法：<script async src="https://stats.example.com/sdk.js?v=1" data-site="站点KEY"></script>
 *
 * 设计约束：
 *  - 同一套服务可同时服务 HTTP 内网 IP:PORT 站点与公网 HTTPS 域名站点，
 *    因此不使用 crypto.randomUUID / crypto.subtle 等需要安全上下文的 API
 *  - vid 写在宿主页面自己的第一方 host-only Cookie 上（与统计服务域名无关）；
 *    HTTPS 宿主页面自动补 Secure，HTTP 页面不能加，否则 Cookie 写不进去
 *  - 不使用 fetch / XHR：PV 走 Image GET，卸载走 sendBeacon(text/plain)，
 *    二者都是 no-cors 简单请求，统计服务与宿主不同源也能正常上报
 *  - 任何异常都静默，不写全局变量，不影响宿主页面
 */
(function () {
  'use strict';
  try {
    // 定位承载 data-site 的 <script> 标签。
    // currentScript 最可靠；但 Cloudflare Rocket Loader 会把 src 移到 data-rocketsrc、
    // 把 type 改成 text/rocketscript，并用 XHR 取回脚本再执行，此时 currentScript 为空，
    // 必须遍历标签并兼容 data-rocketsrc，否则 SDK 会因「tag.src 为空」静默退出、一条 PV 都不发。
    function pickTag() {
      var cs = document.currentScript;
      if (cs && cs.getAttribute && cs.getAttribute('data-site')) return cs;
      var tags = document.getElementsByTagName('script');
      var fallback = null;
      for (var i = 0; i < tags.length; i++) {
        var t = tags[i];
        if (!t.getAttribute || !t.getAttribute('data-site')) continue;
        if (!fallback) fallback = t;
        var s = t.getAttribute('src') || t.getAttribute('data-rocketsrc') || '';
        if (/sdk\.js/i.test(s)) return t;
      }
      return fallback;
    }

    var tag = pickTag();
    if (!tag) return;
    var key = tag.getAttribute('data-site');
    var rawSrc = tag.src || tag.getAttribute('src') ||
                 tag.getAttribute('data-rocketsrc') || '';
    if (!rawSrc) return;

    // 排障开关：给 script 加 data-debug 属性，或在 URL 上加 &ic_debug=1，
    // 控制台会打印脚本定位、目标地址、vid 与每次上报的结果（默认完全静默）。
    var DEBUG = !!tag.getAttribute('data-debug') || /[?&]ic_debug=1(?:&|$)/.test(rawSrc);
    function log() {
      if (!DEBUG) return;
      try {
        if (window.console && console.log) {
          console.log.apply(console, ['[counter]'].concat(Array.prototype.slice.call(arguments)));
        }
      } catch (err) {}
    }

    // 用 <a> 解析而不是 new URL：兼容老浏览器，且 a.host 天然保留 IP:PORT
    var a = document.createElement('a');
    a.href = rawSrc;
    var dir = a.pathname.replace(/sdk\.js.*$/, '');
    var base = a.protocol + '//' + a.host + dir;
    var pvUrl = base + 'collect.php';
    var beaconUrl = base + 'beacon.php';
    log('script =', rawSrc, '| base =', base, '| key =', key,
        '| page =', location.protocol + '//' + location.host + location.pathname + (location.search || ''));

    function rnd() {
      return (Date.now().toString(36) + Math.random().toString(36).slice(2) +
        Math.random().toString(36).slice(2)).replace(/[^a-z0-9]/gi, '').slice(0, 24);
    }

    function readVid() {
      try {
        var m = document.cookie.match(/(?:^|;\s*)_ic_vid=([a-z0-9]{8,32})/i);
        return m ? m[1] : '';
      } catch (err) { return ''; }
    }

    // 第一方 host-only Cookie。注意 Cookie 不区分端口：同一 IP 上多个站点会共享 vid，
    // 但服务端按 site_id 隔离统计，结果互不影响。写不进去就留空，由服务端指纹兜底。
    var vid = readVid();
    if (!vid) {
      var candidate = rnd();
      try {
        // 同一统计服务可能同时服务 HTTP 内网站点与 HTTPS 公网站点，
        // Secure 必须按宿主页面协议动态决定，不能无条件加。
        var secure = (location.protocol === 'https:') ? '; Secure' : '';
        document.cookie = '_ic_vid=' + candidate
          + '; path=/; max-age=31536000; SameSite=Lax' + secure;
        vid = readVid();
      } catch (err) { vid = ''; }
    }
    log('vid =', vid || '(Cookie 不可写，由服务端指纹兜底)');

    function getSid() {
      try {
        var now = Date.now(), obj = null;
        var raw = window.sessionStorage.getItem('_ic_sid');
        if (raw) { try { obj = JSON.parse(raw); } catch (err) {} }
        if (obj && obj.v && (now - obj.t) < 1800000) {
          obj.t = now;
          window.sessionStorage.setItem('_ic_sid', JSON.stringify(obj));
          return obj.v;
        }
        var s = rnd();
        window.sessionStorage.setItem('_ic_sid', JSON.stringify({ v: s, t: now }));
        return s;
      } catch (err) { return rnd(); }
    }
    var sid = getSid();

    function refPath() {
      try {
        if (!document.referrer) return '';
        var b = document.createElement('a');
        b.href = document.referrer;
        return b.pathname || '';
      } catch (err) { return ''; }
    }

    function qs(params) {
      var out = [];
      for (var n in params) {
        if (Object.prototype.hasOwnProperty.call(params, n) &&
            params[n] !== '' && params[n] !== null && params[n] !== undefined) {
          out.push(encodeURIComponent(n) + '=' + encodeURIComponent(params[n]));
        }
      }
      out.push('_=' + rnd());   // 防内网代理 / 浏览器缓存
      return out.join('&');
    }

    // 保持 Image 引用直到请求结束，避免被 GC 提前取消
    var pending = [];
    function img(url) {
      try {
        var im = new Image();
        pending.push(im);
        im.onload = im.onerror = function (ev) {
          log('response', (ev && ev.type) || '?', url.slice(0, 180));
          var idx = pending.indexOf(im);
          if (idx >= 0) pending.splice(idx, 1);
        };
        log('send', url.slice(0, 180));
        im.src = url;
      } catch (err) { log('send failed', err && err.message); }
    }

    function common() {
      return {
        k: key,
        e: rnd(),                         // 事件 ID，服务端据此幂等去重
        t: Math.floor(Date.now() / 1000),
        v: vid,
        s: sid,
        p: location.pathname,            // 纯路径，后台热门页面仍按它聚合，避免参数把同一页面拆散
        q: location.search || '',        // 查询串（含 ?）；敏感参数由服务端打码，这里原样上报
        ti: (document.title || '').slice(0, 200),
        r: refPath(),
        // 浏览器语言与屏幕分辨率，HTTP 非安全上下文下同样可用
        l: (navigator.language || navigator.userLanguage || '').slice(0, 16),
        sc: (screen.width && screen.height) ? (screen.width + 'x' + screen.height) : ''
      };
    }

    var enterAt = Date.now();
    function pv() { img(pvUrl + '?' + qs(common())); }

    var left = false;
    function leave() {
      if (left) return;
      left = true;
      var p = common();
      p.kind = 'unload';
      p.d = Math.max(0, Math.round((Date.now() - enterAt) / 1000));
      try {
        if (navigator.sendBeacon && window.Blob) {
          var ok = navigator.sendBeacon(
            beaconUrl,
            new Blob([JSON.stringify(p)], { type: 'text/plain' })
          );
          log('beacon', ok ? 'queued' : 'rejected', beaconUrl);
          if (ok) return;
        }
      } catch (err) {}
      img(beaconUrl + '?' + qs(p));      // 老浏览器降级，beacon.php 同时支持 GET
    }

    pv();

    // SPA / History 路由切换也计一次 PV
    var lastPath = location.pathname;
    function routeCheck() {
      if (location.pathname !== lastPath) {
        lastPath = location.pathname;
        enterAt = Date.now();
        pv();
      }
    }
    try {
      var wrap = function (name) {
        var orig = history[name];
        if (typeof orig !== 'function') return;
        history[name] = function () {
          var r = orig.apply(this, arguments);
          setTimeout(routeCheck, 0);
          return r;
        };
      };
      wrap('pushState');
      wrap('replaceState');
    } catch (err) {}

    window.addEventListener('popstate', routeCheck);
    window.addEventListener('pagehide', leave);
    document.addEventListener('visibilitychange', function () {
      if (document.visibilityState === 'hidden') leave();
    });
  } catch (err) {
    // 静默：宿主页面永远不该因为统计脚本出错而受影响
  }
})();
