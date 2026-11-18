<?php
declare(strict_types=1);
require_once dirname(__DIR__) . '/app/bootstrap.php';
require_once APP_ROOT . '/app/collector.php';

// sendBeacon 用 text/plain 发送，属于 CORS 安全列表，不会触发预检
$in = [];
$raw = file_get_contents('php://input');
if ($raw) {
    $decoded = json_decode($raw, true);
    if (is_array($decoded)) $in = $decoded;
}
$in = array_merge($_GET, $_POST, $in);

ic_emit_cors($in);
if (strtoupper((string)($_SERVER['REQUEST_METHOD'] ?? 'POST')) === 'OPTIONS') {
    http_response_code(204);
    exit;
}

ic_accept($in, 'unload');
http_response_code(204);
