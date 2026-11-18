<?php
declare(strict_types=1);
require_once dirname(__DIR__, 2) . '/app/bootstrap.php';

if (session_status() === PHP_SESSION_NONE) session_start();
if (!empty($_SESSION['ic_admin'])) {
    header('Location: index.php');
    exit;
}

$err = '';
$configured = ((string)cfg('admin_password_hash', '') !== '');

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $p = (string)($_POST['password'] ?? '');
    $hash = (string)cfg('admin_password_hash', '');
    if ($hash !== '' && password_verify($p, $hash)) {
        session_regenerate_id(true);
        $_SESSION['ic_admin'] = true;
        header('Location: index.php');
        exit;
    }
    $err = '密码不正确，或后台密码尚未配置。';
}
?>
<!doctype html>
<html lang="zh-CN">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>登录 · 内网访问统计</title>
<link rel="stylesheet" href="style.css">
</head>
<body class="login-body">
<div class="login-card">
  <h1>内网访问统计</h1>
  <p class="muted">后台查询 · 请登录</p>
  <?php if (!$configured): ?>
    <p class="notice">尚未设置后台密码。请在宝塔终端执行
      <code>php -r "echo password_hash('你的密码', PASSWORD_DEFAULT), PHP_EOL;"</code>
      ，把输出填入 <code>app/config.php</code> 的 <code>admin_password_hash</code>。</p>
  <?php endif; ?>
  <?php if ($err !== ''): ?><p class="error"><?= e($err) ?></p><?php endif; ?>
  <form method="post" autocomplete="off">
    <label for="password">后台密码</label>
    <input id="password" name="password" type="password" required autofocus>
    <button type="submit">登录</button>
  </form>
</div>
</body>
</html>
