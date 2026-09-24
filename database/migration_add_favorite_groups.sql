-- 收藏分组与批量整理迁移脚本
-- 执行此 SQL 来添加收藏分组功能所需的表结构
-- 已取消的收藏采用软删除（status = 0），以便在回收站中恢复
-- 注意：本脚本中的 ALTER 语句不可重复执行；如需重试，请跳过已成功的语句

USE `community_board`;

-- 收藏分组表
CREATE TABLE IF NOT EXISTS `favorite_groups` (
    `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `visitor_id` VARCHAR(64) NOT NULL COMMENT '访客唯一标识',
    `name` VARCHAR(50) NOT NULL COMMENT '分组名称',
    `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP COMMENT '创建时间',
    INDEX `idx_visitor_id` (`visitor_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='收藏分组表';

-- 收藏表增加分组与状态字段（兼容重复执行，先加列再加索引）
ALTER TABLE `favorites` ADD COLUMN `group_id` INT UNSIGNED NULL DEFAULT NULL COMMENT '所属收藏分组ID，NULL为未分组' AFTER `message_id`;
ALTER TABLE `favorites` ADD COLUMN `status` TINYINT NOT NULL DEFAULT 1 COMMENT '状态: 0已取消(回收站), 1有效' AFTER `group_id`;
ALTER TABLE `favorites` ADD INDEX `idx_visitor_status` (`visitor_id`, `status`);
ALTER TABLE `favorites` ADD INDEX `idx_group_id` (`group_id`);
ALTER TABLE `favorites` ADD CONSTRAINT `fk_favorites_group` FOREIGN KEY (`group_id`) REFERENCES `favorite_groups`(`id`) ON DELETE SET NULL;

-- 执行完成后，可以通过以下命令验证：
-- SHOW TABLES LIKE 'favorite_groups';
-- DESCRIBE favorites;
