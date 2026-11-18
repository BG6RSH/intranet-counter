-- 可选升级：允许一个站点配置多个「允许来源」（www / 裸域、HTTP / HTTPS、预发布域名）。
-- 默认 sites.origin 为 VARCHAR(255)，通常够放 2~5 个域名；只有需要更多来源时才执行本文件。
-- 在宝塔 phpMyAdmin 或终端执行（库名按实际修改）：
--   mysql -u root -p intranet_counter < sql/upgrade_origin.sql
-- 重复执行无害（MODIFY 是幂等的）。
-- 注意：执行前请确认 MySQL 版本与字符集，本语句只改长度，不影响已有数据。

ALTER TABLE `sites`
  MODIFY COLUMN `origin` VARCHAR(1024) NOT NULL DEFAULT '';
