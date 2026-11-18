<?php
/**
 * 站点配置文件模板。
 * 部署时复制本文件为 app/config.php，并修改其中标了 TODO 的项。
 * 本文件位于 app/ 目录，必须放在 Web 运行目录之外（站点运行目录指向 public/）。
 * 真实配置 config.php 已被 .gitignore 排除，不会进入版本库。
 */
return [
    // TODO: 改成宝塔面板里实际创建的数据库名 / 账号 / 密码
    'db_dsn'  => 'mysql:host=127.0.0.1;port=3306;dbname=intranet_counter;charset=utf8mb4',
    'db_user' => 'intranet_counter',
    'db_pass' => 'CHANGE_ME_DB_PASSWORD',

    // TODO: 后台登录密码哈希。在宝塔终端执行：
    //   php -r "echo password_hash('你的后台密码', PASSWORD_DEFAULT), PHP_EOL;"
    // 把输出的整串字符粘贴到下面的单引号里（含 $ 符号，单引号不会做变量解析）。
    // 留空时后台会拒绝登录，避免用默认密码裸奔。
    'admin_password_hash' => '',

    // TODO: 换成任意长随机字符串，用于 IP 脱敏哈希
    'salt' => 'CHANGE_ME_RANDOM_SALT',

    // 统计服务的对外根地址（含端口）。跨服务器 / 公网 HTTPS / 非 80 端口时【必须显式填写】，
    // 后台生成的嵌入代码会原样使用这个值，不受反向代理 / HTTP_HOST 丢失端口影响。
    //
    // 这是【全局默认值】，只有一份，不是每个网站各配一份。
    //   HTTPS 公网站点必须用 https:// 开头的对外域名
    //   HTTP 内网站点可用站点级「专用嵌入地址」单独指定 http://内网IP:PORT（见下）
    //
    // Cloudflare Tunnel / frp 场景三个地址各填各的，切勿混用：
    //   对外域名（如 https://stats.example.com） → 这里（浏览器访问，会写进嵌入代码）
    //   源站地址（如 http://192.168.1.10:8080） → 只填 cloudflared / frp 的 service
    //   代理出口 REMOTE_ADDR                    → 填下面的 trusted_proxies
    // 源站地址绝不能填到这里，公网浏览器访问不到，会导致该站点零数据。
    // 纯内网直连场景：'http://192.168.1.10:8080'
    // 留空 = 自动从当前请求推断，并尝试用 SERVER_PORT 补齐非默认端口。
    'public_base_url' => '',

    'timezone' => 'Asia/Shanghai',

    // 校验 Referer / Origin 来源。请求头缺失时仍放行（内网代理经常剥离）。
    // 站点「允许来源」在后台填写，支持逗号分隔多个 origin；
    // HTTP 内网站点的允许来源就该填 http://；
    // 公网 HTTPS 站点必须填 https:// 开头（写成 http:// 会被白名单静默拒收，
    // 请求仍返回 200 GIF 但一条都不入库，是「HTTPS 站点零数据」最常见原因）。
    'require_origin_check' => true,

    // 是否记录客户端明文 IP。默认 false，只存哈希。
    // 开启后需先执行 sql/upgrade_clientinfo.sql。按单位隐私要求决定，注意数据保留期。
    'store_client_ip' => false,

    // 是否保存访问 URL 的查询参数（?page=2&id=123）。默认 true。
    // token / password / key / session / code / signature 等敏感参数自动打码为 ***；
    // 本站特有的敏感参数名可加进 query_extra_sensitive（大小写不敏感，如 ['staffid', 'tel']）。
    // 需执行 sql/upgrade_query.sql 增加 events.query 列；未升级时自动跳过，不影响采集。
    'store_query' => true,
    'query_extra_sensitive' => [],

    // ==== 反向代理 / frp / Cloudflare Tunnel：还原真实客户端 IP ====
    // 填「采集端看到的 REMOTE_ADDR（TCP 对端）」，不是源站地址、不是对外域名、
    // 也不是 Cloudflare 公网 IP 段。可先用 /check.php 查看本请求的实际 REMOTE_ADDR。
    // 支持精确 IP 与 CIDR，例：['127.0.0.1', '192.168.1.10', '10.0.0.0/8']。
    // 容器化 connector 的 IP 可能变动，用网段可覆盖（如 Docker 默认网桥 172.17.0.0/16）。
    // 留空 = 只信 REMOTE_ADDR（直连部署）；此时即使代理传了 X-Forwarded-For 也不会采用。
    // 切勿填 ['*']：会信任任意来源的转发头，统计可被伪造。
    'trusted_proxies' => [],

    // 转发头优先级。Cloudflare 必须把 CF-Connecting-IP 放首位（边缘无条件注入、无法伪造），
    // 不能先读可被伪造的 X-Forwarded-For。frp 链路没有 CF 头会自动跳过，继续读 XFF。
    // 某个头只给出代理自身地址时会自动跳到下一个头（权威头除外）。一般不用改。
    'real_ip_headers' => [
        'HTTP_CF_CONNECTING_IP', 'HTTP_TRUE_CLIENT_IP',
        'HTTP_X_FORWARDED_FOR', 'HTTP_X_REAL_IP', 'HTTP_X_FORWARDED',
        'HTTP_X_CLIENT_IP', 'HTTP_FORWARDED',
    ],

    // 同一站点 + 同一 IP + 同一访客，两秒内最多接收的上报条数，防脚本误刷；0 表示关闭
    'rate_limit_per_2s' => 20,
];
