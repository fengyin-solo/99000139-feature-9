-- 收藏分组表迁移脚本
-- 执行此 SQL 来添加收藏分组功能所需的表结构和字段

USE `community_board`;

-- 创建收藏分组表
CREATE TABLE IF NOT EXISTS `favorite_groups` (
    `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `visitor_id` VARCHAR(64) NOT NULL COMMENT '访客唯一标识',
    `name` VARCHAR(50) NOT NULL COMMENT '分组名称',
    `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP COMMENT '创建时间',
    UNIQUE KEY `uk_visitor_name` (`visitor_id`, `name`),
    INDEX `idx_visitor_id` (`visitor_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='收藏分组表';

-- 收藏表增加分组ID字段（NULL 表示未分组）
ALTER TABLE `favorites`
    ADD COLUMN `group_id` INT UNSIGNED NULL DEFAULT NULL COMMENT '所属分组ID，NULL为未分组' AFTER `message_id`;

-- 增加分组外键：分组删除时收藏记录自动回到未分组
ALTER TABLE `favorites`
    ADD INDEX `idx_group_id` (`group_id`);

ALTER TABLE `favorites`
    ADD CONSTRAINT `fk_favorites_group` FOREIGN KEY (`group_id`) REFERENCES `favorite_groups`(`id`) ON DELETE SET NULL;

-- 执行完成后，可以通过以下命令验证：
-- SHOW TABLES LIKE 'favorite_groups';
-- DESCRIBE favorites;
