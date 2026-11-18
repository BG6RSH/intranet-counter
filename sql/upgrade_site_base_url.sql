-- 可选升级：允许每个站点使用不同的「统计服务嵌入地址」。
-- 场景：同一套统计服务同时服务公网 HTTPS 站点与纯隔离内网 HTTP 站点，
--       公网站点必须用 https://stats.tansoo.cn，内网站点需要用 http://内网IP:PORT。
--
-- 在宝塔 phpMyAdmin 或终端执行（库名按实际修改）：
--   mysql -u root -p intranet_counter < sql/upgrade_site_base_url.sql
--
-- 不执行本文件也完全可用：后台会自动降级为全局 public_base_url，行为与升级前一致。
-- 已存在的站点 base_url 默认为空字符串（= 使用全局地址），不会改变任何已贴出去的嵌入代码。
--
-- MySQL 8 / 5.7 重复执行会报 "Duplicate column name"，属正常，确认字段已存在即可。
-- MariaDB 用户可改用幂等写法：
--   ALTER TABLE `sites` ADD COLUMN IF NOT EXISTS `base_url` VARCHAR(255) NOT NULL DEFAULT '';

ALTER TABLE `sites`
  ADD COLUMN `base_url` VARCHAR(255) NOT NULL DEFAULT ''
  COMMENT '该站点嵌入代码使用的统计服务地址，空=使用全局 public_base_url';
