-- 可选升级：保存访问 URL 的查询参数（?page=2&id=123）。
-- 在宝塔面板选中统计数据库，用 phpMyAdmin / SQL 工具执行本文件一次即可。
-- 重复执行会报 Duplicate column name，不影响已有数据，忽略即可。
-- 未执行时采集端会自动跳过 query 列，PV / UV 与所有报表一切照旧。
--
-- 说明：
--   1. 敏感参数（token / password / key / session / code 等）在服务端已打码成 ***，
--      表里保存的是脱敏后的查询串，不是原始值。
--   2. path 列仍保存纯路径，所以「热门页面 Top 10」不会因参数不同而把同一页面拆散；
--      完整地址（path + query）可在后台「最近访问」明细与 CSV 导出里查看。
ALTER TABLE `events`
  ADD COLUMN `query` VARCHAR(512) NOT NULL DEFAULT '' AFTER `path`;
