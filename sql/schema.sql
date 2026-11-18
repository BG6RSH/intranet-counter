-- 在宝塔面板里先创建 MySQL 数据库（库名/账号/密码自行设定），
-- 然后选中该数据库，用 phpMyAdmin 或面板 SQL 工具执行本文件。
-- 不需要 CREATE DATABASE / USE，避免与面板建库冲突。

CREATE TABLE IF NOT EXISTS `sites` (
  `id`         INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `site_key`   VARCHAR(64)  NOT NULL,
  `name`       VARCHAR(100) NOT NULL DEFAULT '',
  `origin`     VARCHAR(255) NOT NULL DEFAULT '',
  `status`     TINYINT      NOT NULL DEFAULT 1,
  `created_at` DATETIME     NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_site_key` (`site_key`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `events` (
  `id`       BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `site_id`  INT UNSIGNED    NOT NULL,
  `eid`      VARCHAR(40)     NOT NULL,
  `kind`     VARCHAR(16)     NOT NULL DEFAULT 'pv',
  `ts`       DATETIME        NOT NULL,
  `day`      DATE            NOT NULL,
  `path`     VARCHAR(512)    NOT NULL DEFAULT '',
  `query`    VARCHAR(512)    NOT NULL DEFAULT '',
  `title`    VARCHAR(255)    NOT NULL DEFAULT '',
  `ref`      VARCHAR(512)    NOT NULL DEFAULT '',
  `vid`      VARCHAR(40)     NOT NULL DEFAULT '',
  `sid`      VARCHAR(40)     NOT NULL DEFAULT '',
  `ip_hash`      CHAR(32)     NOT NULL DEFAULT '',
  `ip`           VARCHAR(45)  NOT NULL DEFAULT '',
  `ua`           VARCHAR(255) NOT NULL DEFAULT '',
  `browser`      VARCHAR(32)  NOT NULL DEFAULT '',
  `browser_ver`  VARCHAR(16)  NOT NULL DEFAULT '',
  `os`           VARCHAR(32)  NOT NULL DEFAULT '',
  `device`       VARCHAR(16)  NOT NULL DEFAULT '',
  `lang`         VARCHAR(16)  NOT NULL DEFAULT '',
  `screen`       VARCHAR(24)  NOT NULL DEFAULT '',
  `duration`     INT UNSIGNED NOT NULL DEFAULT 0,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_eid` (`site_id`, `eid`),
  KEY `idx_site_day_kind` (`site_id`, `day`, `kind`),
  KEY `idx_rate` (`site_id`, `ip_hash`, `ts`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 精确 UV 去重：同一站点同一天同一访客只记一行
CREATE TABLE IF NOT EXISTS `uv_daily` (
  `site_id` INT UNSIGNED NOT NULL,
  `d`       DATE         NOT NULL,
  `vid`     VARCHAR(40)  NOT NULL,
  PRIMARY KEY (`site_id`, `d`, `vid`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 可选：数据保留策略（日 PV <100 时一年仅数万行，不清理也完全可以）
-- DELETE FROM events WHERE day < DATE_SUB(CURDATE(), INTERVAL 365 DAY);
-- DELETE FROM uv_daily WHERE d < DATE_SUB(CURDATE(), INTERVAL 365 DAY);
