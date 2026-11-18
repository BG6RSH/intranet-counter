<?php
declare(strict_types=1);
require_once dirname(__DIR__) . '/app/bootstrap.php';
require_once APP_ROOT . '/app/collector.php';

// 跨服务器 / 跨域名部署时按站点白名单精确回显 CORS 头；同源部署无副作用
ic_emit_cors($_GET);

// 宿主若改用 fetch 并带自定义头，浏览器会先发 OPTIONS 预检
if (strtoupper((string)($_SERVER['REQUEST_METHOD'] ?? 'GET')) === 'OPTIONS') {
    http_response_code(204);
    exit;
}

ic_accept($_GET, 'pv');

// 返回 1×1 透明 GIF：img 标签 / new Image() 都不会出现破图，且完全不受 CORS 限制
header('Content-Type: image/gif');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');
header('X-Content-Type-Options: nosniff');
echo base64_decode('R0lGODlhAQABAIAAAAAAAP///yH5BAEAAAAALAAAAAABAAEAAAIBRAA7');
