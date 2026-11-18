-- 已按旧版 schema.sql 建过表时，执行本文件「一次」即可补齐客户端字段。
-- 全新部署请直接用 schema.sql，不要再执行本文件。
-- MySQL 8 不支持 ADD COLUMN IF NOT EXISTS，重复执行会提示 Duplicate column，属正常现象。
--
-- 即使不执行也不会丢数据：采集端会自动检测列是否存在并退回旧列写入；
-- 后台的浏览器 / 系统 / 设备分布是按已存的 ua 实时解析的，历史数据同样能显示。
-- 只有「语言、屏幕分辨率、来源 IP」这三个维度需要这些新列，且仅对升级后产生的数据生效。

ALTER TABLE `events`
  ADD COLUMN `ip`           VARCHAR(45)  NOT NULL DEFAULT '',
  ADD COLUMN `browser`      VARCHAR(32)  NOT NULL DEFAULT '',
  ADD COLUMN `browser_ver`  VARCHAR(16)  NOT NULL DEFAULT '',
  ADD COLUMN `os`           VARCHAR(32)  NOT NULL DEFAULT '',
  ADD COLUMN `device`       VARCHAR(16)  NOT NULL DEFAULT '',
  ADD COLUMN `lang`         VARCHAR(16)  NOT NULL DEFAULT '',
  ADD COLUMN `screen`       VARCHAR(24)  NOT NULL DEFAULT '';
