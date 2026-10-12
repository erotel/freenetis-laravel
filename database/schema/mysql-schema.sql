/*M!999999\- enable the sandbox mode */ 
/*!40103 SET @OLD_TIME_ZONE=@@TIME_ZONE */;
/*!40103 SET TIME_ZONE='+00:00' */;
/*!40014 SET @OLD_UNIQUE_CHECKS=@@UNIQUE_CHECKS, UNIQUE_CHECKS=0 */;
/*!40014 SET @OLD_FOREIGN_KEY_CHECKS=@@FOREIGN_KEY_CHECKS, FOREIGN_KEY_CHECKS=0 */;
/*!40101 SET @OLD_SQL_MODE=@@SQL_MODE, SQL_MODE='NO_AUTO_VALUE_ON_ZERO' */;
/*M!100616 SET @OLD_NOTE_VERBOSITY=@@NOTE_VERBOSITY, NOTE_VERBOSITY=0 */;
DROP TABLE IF EXISTS `account_attributes`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `account_attributes` (
  `id` int(11) NOT NULL DEFAULT 0,
  `name` varchar(230) DEFAULT NULL,
  `type` tinyint(4) DEFAULT NULL COMMENT 'aktivní, pasivní, daňový, nedaňový',
  `kind` tinyint(4) DEFAULT NULL COMMENT 'rozvahový, výsledovkový, závěrkový, podrozvahový',
  `activity` tinyint(4) DEFAULT NULL COMMENT 'hlavní/hospodářská činnost',
  `track_balance` tinyint(4) DEFAULT NULL COMMENT 'true, false',
  `line` tinyint(4) DEFAULT NULL COMMENT 'řádek plné výsledovky',
  `line_short` tinyint(4) DEFAULT NULL COMMENT 'řádek zkrácené výsledovky',
  `line_credits` tinyint(4) DEFAULT NULL COMMENT 'řádek pasiv',
  `line_credits_short` tinyint(4) DEFAULT NULL COMMENT 'řádek zkrácených pasiv ',
  `comment` varchar(255) DEFAULT NULL,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb3 COLLATE=utf8mb3_czech_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `accounts`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `accounts` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `member_id` int(11) DEFAULT NULL,
  `name` varchar(100) DEFAULT NULL,
  `account_attribute_id` int(11) NOT NULL,
  `balance` double DEFAULT 0 COMMENT 'Account balance, value is updated with respect to transfers of account',
  `comment` varchar(254) DEFAULT NULL,
  `comments_thread_id` int(11) DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `is_owned_by` (`member_id`),
  KEY `comments_thread_id` (`comments_thread_id`),
  KEY `account_attribute_id` (`account_attribute_id`),
  KEY `balance` (`balance`),
  CONSTRAINT `accounts_ibfk_1` FOREIGN KEY (`member_id`) REFERENCES `members` (`id`) ON DELETE SET NULL,
  CONSTRAINT `accounts_ibfk_2` FOREIGN KEY (`account_attribute_id`) REFERENCES `account_attributes` (`id`),
  CONSTRAINT `accounts_ibfk_3` FOREIGN KEY (`comments_thread_id`) REFERENCES `comments_threads` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb3 COLLATE=utf8mb3_czech_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `accounts_bank_accounts`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `accounts_bank_accounts` (
  `account_id` int(11) NOT NULL,
  `bank_account_id` int(11) NOT NULL,
  PRIMARY KEY (`account_id`,`bank_account_id`),
  KEY `bank_account_id` (`bank_account_id`),
  CONSTRAINT `accounts_bank_accounts_ibfk_1` FOREIGN KEY (`account_id`) REFERENCES `accounts` (`id`) ON DELETE CASCADE,
  CONSTRAINT `accounts_bank_accounts_ibfk_2` FOREIGN KEY (`bank_account_id`) REFERENCES `bank_accounts` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb3 COLLATE=utf8mb3_czech_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `acl`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `acl` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `note` text DEFAULT NULL,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb3 COLLATE=utf8mb3_czech_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `aco`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `aco` (
  `id` int(11) NOT NULL DEFAULT 0,
  `value` varchar(240) NOT NULL,
  `name` varchar(255) NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `section_value_value_aco` (`value`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb3 COLLATE=utf8mb3_czech_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `aco_map`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `aco_map` (
  `acl_id` int(11) NOT NULL DEFAULT 0,
  `value` varchar(230) NOT NULL,
  PRIMARY KEY (`acl_id`,`value`),
  CONSTRAINT `aco_map_ibfk_1` FOREIGN KEY (`acl_id`) REFERENCES `acl` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb3 COLLATE=utf8mb3_czech_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `address_points`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `address_points` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `name` varchar(255) DEFAULT NULL,
  `country_id` smallint(6) NOT NULL,
  `town_id` int(11) NOT NULL,
  `street_id` int(11) DEFAULT NULL,
  `street_number` varchar(50) DEFAULT NULL,
  `gps` point DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `street_id` (`street_id`),
  KEY `town_id` (`town_id`),
  KEY `country_id` (`country_id`),
  CONSTRAINT `address_points_ibfk_1` FOREIGN KEY (`street_id`) REFERENCES `streets` (`id`) ON DELETE CASCADE,
  CONSTRAINT `address_points_ibfk_2` FOREIGN KEY (`town_id`) REFERENCES `towns` (`id`) ON DELETE CASCADE,
  CONSTRAINT `address_points_ibfk_3` FOREIGN KEY (`country_id`) REFERENCES `countries` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb3 COLLATE=utf8mb3_czech_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `allowed_subnets`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `allowed_subnets` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `member_id` int(11) NOT NULL,
  `subnet_id` int(11) NOT NULL,
  `speed_class_id` int(10) unsigned DEFAULT NULL,
  `charged` tinyint(1) NOT NULL DEFAULT 0,
  `enabled` tinyint(1) NOT NULL DEFAULT 1,
  `last_update` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `member_id` (`member_id`),
  KEY `subnet_id` (`subnet_id`),
  CONSTRAINT `allowed_subnets_ibfk_1` FOREIGN KEY (`member_id`) REFERENCES `members` (`id`) ON DELETE CASCADE,
  CONSTRAINT `allowed_subnets_ibfk_2` FOREIGN KEY (`subnet_id`) REFERENCES `subnets` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb3 COLLATE=utf8mb3_czech_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `allowed_subnets_counts`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `allowed_subnets_counts` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `member_id` int(11) NOT NULL,
  `count` int(11) NOT NULL,
  PRIMARY KEY (`id`),
  KEY `member_id` (`member_id`),
  CONSTRAINT `allowed_subnets_counts_ibfk_1` FOREIGN KEY (`member_id`) REFERENCES `members` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb3 COLLATE=utf8mb3_czech_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `approval_template_items`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `approval_template_items` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `approval_template_id` int(11) DEFAULT NULL,
  `approval_type_id` int(11) NOT NULL,
  `priority` int(11) NOT NULL,
  PRIMARY KEY (`id`),
  KEY `approval_template_id` (`approval_template_id`),
  KEY `approval_type_id` (`approval_type_id`),
  CONSTRAINT `approval_template_items_ibfk_1` FOREIGN KEY (`approval_template_id`) REFERENCES `approval_templates` (`id`) ON DELETE SET NULL,
  CONSTRAINT `approval_template_items_ibfk_2` FOREIGN KEY (`approval_type_id`) REFERENCES `approval_types` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb3 COLLATE=utf8mb3_czech_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `approval_templates`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `approval_templates` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `name` varchar(100) NOT NULL,
  `comment` mediumtext NOT NULL,
  `state` int(11) NOT NULL,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb3 COLLATE=utf8mb3_czech_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `approval_types`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `approval_types` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `name` varchar(100) NOT NULL,
  `comment` mediumtext NOT NULL,
  `type` int(11) NOT NULL,
  `majority_percent` int(11) NOT NULL,
  `aro_group_id` int(11) NOT NULL,
  `interval` datetime NOT NULL,
  `default_vote` tinyint(1) DEFAULT NULL,
  `min_suggest_amount` int(11) NOT NULL,
  `one_vote` tinyint(4) NOT NULL DEFAULT 0 COMMENT 'Only one vote (approve/reject) required to end voting.',
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb3 COLLATE=utf8mb3_czech_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `aro_groups`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `aro_groups` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `parent_id` int(11) NOT NULL DEFAULT 0,
  `lft` int(11) NOT NULL DEFAULT 0,
  `rgt` int(11) NOT NULL DEFAULT 0,
  `name` varchar(255) NOT NULL,
  `value` varchar(255) NOT NULL,
  PRIMARY KEY (`id`,`value`),
  UNIQUE KEY `value_aro_groups` (`value`),
  KEY `parent_id_aro_groups` (`parent_id`),
  KEY `lft_rgt_aro_groups` (`lft`,`rgt`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb3 COLLATE=utf8mb3_czech_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `aro_groups_map`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `aro_groups_map` (
  `acl_id` int(11) NOT NULL DEFAULT 0,
  `group_id` int(11) NOT NULL DEFAULT 0,
  PRIMARY KEY (`acl_id`,`group_id`),
  KEY `group_id` (`group_id`),
  CONSTRAINT `aro_groups_map_ibfk_1` FOREIGN KEY (`acl_id`) REFERENCES `acl` (`id`) ON DELETE CASCADE,
  CONSTRAINT `aro_groups_map_ibfk_2` FOREIGN KEY (`group_id`) REFERENCES `aro_groups` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb3 COLLATE=utf8mb3_czech_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `audit_logs`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `audit_logs` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `occurred_at` datetime NOT NULL,
  `user_id` int(11) DEFAULT NULL,
  `ip_address` varchar(45) DEFAULT NULL,
  `action` varchar(30) NOT NULL,
  `auditable_type` varchar(100) NOT NULL,
  `auditable_id` bigint(20) DEFAULT NULL,
  `old_values` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL CHECK (json_valid(`old_values`)),
  `new_values` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL CHECK (json_valid(`new_values`)),
  KEY `id` (`id`),
  KEY `idx_auditable` (`auditable_type`,`auditable_id`),
  KEY `idx_user` (`user_id`),
  KEY `idx_occurred` (`occurred_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_uca1400_ai_ci
 PARTITION BY RANGE  COLUMNS(`occurred_at`)
(PARTITION `p_2026_08` VALUES LESS THAN ('2026-09-01') ENGINE = InnoDB,
 PARTITION `p_2026_09` VALUES LESS THAN ('2026-10-01') ENGINE = InnoDB,
 PARTITION `p_2026_10` VALUES LESS THAN ('2026-11-01') ENGINE = InnoDB,
 PARTITION `p_2026_11` VALUES LESS THAN ('2026-12-01') ENGINE = InnoDB,
 PARTITION `p_2026_12` VALUES LESS THAN ('2027-01-01') ENGINE = InnoDB,
 PARTITION `p_max` VALUES LESS THAN (MAXVALUE) ENGINE = InnoDB);
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `axo`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `axo` (
  `id` int(11) NOT NULL DEFAULT 0,
  `section_value` varchar(240) NOT NULL DEFAULT '0',
  `value` varchar(240) NOT NULL,
  `name` varchar(255) NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `section_value_value_axo` (`section_value`,`value`),
  KEY `value` (`value`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb3 COLLATE=utf8mb3_czech_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `axo_map`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `axo_map` (
  `acl_id` int(11) NOT NULL DEFAULT 0,
  `section_value` varchar(230) NOT NULL DEFAULT '0',
  `value` varchar(230) NOT NULL,
  PRIMARY KEY (`acl_id`,`section_value`,`value`),
  CONSTRAINT `axo_map_ibfk_1` FOREIGN KEY (`acl_id`) REFERENCES `acl` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb3 COLLATE=utf8mb3_czech_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `axo_sections`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `axo_sections` (
  `id` int(11) NOT NULL DEFAULT 0,
  `value` varchar(230) NOT NULL,
  `name` varchar(230) NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `value_axo_sections` (`value`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb3 COLLATE=utf8mb3_czech_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `bank_accounts`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `bank_accounts` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `name` varchar(254) NOT NULL,
  `member_id` int(11) DEFAULT NULL,
  `account_nr` varchar(254) DEFAULT NULL,
  `bank_nr` varchar(20) DEFAULT NULL,
  `type` int(11) NOT NULL DEFAULT 0,
  `settings` text DEFAULT NULL COMMENT 'JSON',
  `IBAN` varchar(254) DEFAULT NULL,
  `SWIFT` varchar(254) DEFAULT NULL,
  `payment_purpose` tinyint(1) NOT NULL DEFAULT 0 COMMENT '0 - clenstvi, 1 - zakaznici',
  PRIMARY KEY (`id`),
  KEY `member_id` (`member_id`),
  CONSTRAINT `bank_accounts_ibfk_1` FOREIGN KEY (`member_id`) REFERENCES `members` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb3 COLLATE=utf8mb3_czech_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `bank_accounts_automatical_downloads`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `bank_accounts_automatical_downloads` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `bank_account_id` int(11) NOT NULL,
  `type` smallint(6) NOT NULL,
  `attribute` varchar(255) DEFAULT NULL,
  `email_enabled` tinyint(1) NOT NULL DEFAULT 0,
  `sms_enabled` tinyint(1) NOT NULL DEFAULT 0,
  PRIMARY KEY (`id`),
  KEY `bank_account_id_fk` (`bank_account_id`),
  CONSTRAINT `bank_accounts_automatical_downloads_ibfk_1` FOREIGN KEY (`bank_account_id`) REFERENCES `bank_accounts` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb3 COLLATE=utf8mb3_czech_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `bank_statements`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `bank_statements` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `bank_account_id` int(11) NOT NULL,
  `user_id` int(11) DEFAULT NULL,
  `statement_number` int(11) DEFAULT NULL,
  `type` varchar(40) DEFAULT NULL,
  `from` datetime DEFAULT NULL,
  `to` datetime DEFAULT NULL,
  `opening_balance` double DEFAULT NULL,
  `closing_balance` double DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `bank_account_id` (`bank_account_id`),
  KEY `user_id` (`user_id`),
  CONSTRAINT `bank_statements_ibfk_1` FOREIGN KEY (`bank_account_id`) REFERENCES `bank_accounts` (`id`),
  CONSTRAINT `bank_statements_ibfk_2` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb3 COLLATE=utf8mb3_czech_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `bank_transfers`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `bank_transfers` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `origin_id` int(11) DEFAULT NULL COMMENT 'id of the origin bank account in bank_accounts table',
  `destination_id` int(11) DEFAULT NULL COMMENT 'id of the destination bank account in bank_accounts table',
  `transfer_id` int(11) DEFAULT NULL,
  `bank_statement_id` int(11) DEFAULT NULL,
  `transaction_code` bigint(20) DEFAULT NULL,
  `number` int(11) DEFAULT NULL COMMENT 'Line number or number of the bank listing item',
  `variable_symbol` bigint(20) DEFAULT NULL,
  `constant_symbol` bigint(20) DEFAULT NULL,
  `specific_symbol` bigint(20) DEFAULT NULL,
  `comment` varchar(255) DEFAULT NULL,
  `deleted_at` datetime DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `origin_id` (`origin_id`),
  KEY `destination_id` (`destination_id`),
  KEY `transfer_id` (`transfer_id`),
  KEY `number` (`number`,`variable_symbol`),
  KEY `bank_statement_id` (`bank_statement_id`),
  CONSTRAINT `bank_transfers_ibfk_1` FOREIGN KEY (`origin_id`) REFERENCES `bank_accounts` (`id`) ON DELETE SET NULL,
  CONSTRAINT `bank_transfers_ibfk_2` FOREIGN KEY (`destination_id`) REFERENCES `bank_accounts` (`id`) ON DELETE SET NULL,
  CONSTRAINT `bank_transfers_ibfk_3` FOREIGN KEY (`transfer_id`) REFERENCES `transfers` (`id`) ON DELETE SET NULL,
  CONSTRAINT `bank_transfers_ibfk_4` FOREIGN KEY (`bank_statement_id`) REFERENCES `bank_statements` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb3 COLLATE=utf8mb3_czech_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `cache`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `cache` (
  `key` varchar(255) NOT NULL,
  `value` mediumtext NOT NULL,
  `expiration` bigint(20) NOT NULL,
  PRIMARY KEY (`key`),
  KEY `cache_expiration_index` (`expiration`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `cache_locks`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `cache_locks` (
  `key` varchar(255) NOT NULL,
  `owner` varchar(255) NOT NULL,
  `expiration` bigint(20) NOT NULL,
  PRIMARY KEY (`key`),
  KEY `cache_locks_expiration_index` (`expiration`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `cash`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `cash` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `member_id` int(11) NOT NULL,
  `transfer_id` int(11) NOT NULL,
  PRIMARY KEY (`id`),
  KEY `member_id` (`member_id`),
  KEY `transfer_id` (`transfer_id`),
  CONSTRAINT `cash_ibfk_1` FOREIGN KEY (`member_id`) REFERENCES `members` (`id`) ON DELETE CASCADE,
  CONSTRAINT `cash_ibfk_2` FOREIGN KEY (`transfer_id`) REFERENCES `transfers` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb3 COLLATE=utf8mb3_czech_ci COMMENT='implements cash deposits and withdrawals ';
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `clouds`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `clouds` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `name` varchar(50) NOT NULL,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb3 COLLATE=utf8mb3_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `clouds_subnets`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `clouds_subnets` (
  `cloud_id` int(11) NOT NULL,
  `subnet_id` int(11) NOT NULL,
  PRIMARY KEY (`cloud_id`,`subnet_id`),
  KEY `subnet_id` (`subnet_id`),
  CONSTRAINT `clouds_subnets_ibfk_1` FOREIGN KEY (`cloud_id`) REFERENCES `clouds` (`id`) ON DELETE CASCADE,
  CONSTRAINT `clouds_subnets_ibfk_2` FOREIGN KEY (`subnet_id`) REFERENCES `subnets` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb3 COLLATE=utf8mb3_czech_ci COMMENT='Join table between subnets and clouds.';
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `clouds_users`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `clouds_users` (
  `cloud_id` int(11) NOT NULL,
  `user_id` int(11) NOT NULL,
  PRIMARY KEY (`cloud_id`,`user_id`),
  KEY `user_id` (`user_id`),
  CONSTRAINT `clouds_users_ibfk_1` FOREIGN KEY (`cloud_id`) REFERENCES `clouds` (`id`) ON DELETE CASCADE,
  CONSTRAINT `clouds_users_ibfk_2` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb3 COLLATE=utf8mb3_czech_ci COMMENT='Join table between users and clouds.';
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `comments`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `comments` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `comments_thread_id` int(11) NOT NULL,
  `user_id` int(11) NOT NULL,
  `text` text NOT NULL,
  `datetime` datetime NOT NULL,
  PRIMARY KEY (`id`),
  KEY `user_id` (`user_id`),
  KEY `comments_thread_id` (`comments_thread_id`),
  CONSTRAINT `comments_ibfk_1` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE,
  CONSTRAINT `comments_ibfk_2` FOREIGN KEY (`comments_thread_id`) REFERENCES `comments_threads` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb3 COLLATE=utf8mb3_czech_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `comments_threads`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `comments_threads` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `type` varchar(255) NOT NULL,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb3 COLLATE=utf8mb3_czech_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `config`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `config` (
  `name` varchar(100) NOT NULL,
  `value` text NOT NULL,
  PRIMARY KEY (`name`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb3 COLLATE=utf8mb3_czech_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `connection_requests`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `connection_requests` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `member_id` int(11) NOT NULL COMMENT 'Owner of the connection',
  `added_user_id` int(11) DEFAULT NULL COMMENT 'Who made request',
  `decided_user_id` int(11) DEFAULT NULL COMMENT 'User who approve/reject this connection request.',
  `state` int(11) DEFAULT 0,
  `created_at` datetime NOT NULL,
  `decided_at` datetime DEFAULT NULL,
  `ip_address` varchar(39) DEFAULT NULL,
  `subnet_id` int(11) DEFAULT NULL,
  `mac_address` varchar(17) DEFAULT NULL,
  `pppoe_username` varchar(64) DEFAULT NULL,
  `pppoe_secret` varchar(64) DEFAULT NULL,
  `device_id` int(11) DEFAULT NULL COMMENT 'ID of the device that was created from this connection request or null.',
  `device_type_id` int(11) DEFAULT NULL,
  `device_template_id` int(11) DEFAULT NULL,
  `comment` text DEFAULT NULL COMMENT 'Comment of user who made request',
  `comments_thread_id` int(11) DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `member_id_fk` (`member_id`),
  KEY `subnet_id_fk` (`subnet_id`),
  KEY `device_type_id_fk` (`device_type_id`),
  KEY `device_template_id_fk` (`device_template_id`),
  KEY `comments_thread_id_fk` (`comments_thread_id`),
  KEY `decided_user_id_fk` (`decided_user_id`),
  KEY `device_id_fk` (`device_id`),
  KEY `added_user_id_fk` (`added_user_id`),
  CONSTRAINT `connection_requests_ibfk_1` FOREIGN KEY (`member_id`) REFERENCES `members` (`id`) ON DELETE CASCADE,
  CONSTRAINT `connection_requests_ibfk_3` FOREIGN KEY (`subnet_id`) REFERENCES `subnets` (`id`) ON DELETE CASCADE,
  CONSTRAINT `connection_requests_ibfk_4` FOREIGN KEY (`device_type_id`) REFERENCES `enum_types` (`id`) ON DELETE SET NULL,
  CONSTRAINT `connection_requests_ibfk_5` FOREIGN KEY (`device_template_id`) REFERENCES `device_templates` (`id`) ON DELETE SET NULL,
  CONSTRAINT `connection_requests_ibfk_6` FOREIGN KEY (`comments_thread_id`) REFERENCES `comments_threads` (`id`) ON DELETE SET NULL,
  CONSTRAINT `connection_requests_ibfk_7` FOREIGN KEY (`decided_user_id`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  CONSTRAINT `connection_requests_ibfk_8` FOREIGN KEY (`device_id`) REFERENCES `devices` (`id`) ON DELETE SET NULL,
  CONSTRAINT `connection_requests_ibfk_9` FOREIGN KEY (`added_user_id`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb3 COLLATE=utf8mb3_czech_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `contacts`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `contacts` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `type` int(11) NOT NULL,
  `value` varchar(255) DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `type` (`type`),
  KEY `value` (`value`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb3 COLLATE=utf8mb3_czech_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `contacts_countries`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `contacts_countries` (
  `contact_id` int(11) NOT NULL,
  `country_id` smallint(6) NOT NULL,
  PRIMARY KEY (`contact_id`,`country_id`),
  KEY `country_id` (`country_id`),
  CONSTRAINT `contacts_countries_ibfk_1` FOREIGN KEY (`contact_id`) REFERENCES `contacts` (`id`) ON DELETE CASCADE,
  CONSTRAINT `contacts_countries_ibfk_2` FOREIGN KEY (`country_id`) REFERENCES `countries` (`id`) ON DELETE CASCADE,
  CONSTRAINT `contacts_countries_ibfk_3` FOREIGN KEY (`country_id`) REFERENCES `countries` (`id`) ON DELETE CASCADE,
  CONSTRAINT `contacts_countries_ibfk_4` FOREIGN KEY (`contact_id`) REFERENCES `contacts` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb3 COLLATE=utf8mb3_czech_ci COMMENT='Relation between phone number in contacts to countries.';
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `countries`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `countries` (
  `id` smallint(6) NOT NULL AUTO_INCREMENT,
  `country_name` varchar(100) NOT NULL COMMENT 'Country name in english',
  `country_iso` char(3) NOT NULL COMMENT 'ISO 3166-1 alpha-3',
  `country_code` varchar(4) NOT NULL COMMENT 'Telefone prefix of country',
  `enabled` tinyint(4) NOT NULL DEFAULT 1,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb3 COLLATE=utf8mb3_czech_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `device_active_links`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `device_active_links` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `url_pattern` varchar(255) NOT NULL,
  `name` varchar(50) NOT NULL,
  `title` varchar(50) NOT NULL,
  `show_in_user_grid` tinyint(4) NOT NULL,
  `show_in_grid` tinyint(4) NOT NULL,
  `as_form` tinyint(4) NOT NULL,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb3 COLLATE=utf8mb3_czech_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `device_active_links_map`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `device_active_links_map` (
  `device_active_link_id` int(11) NOT NULL,
  `device_id` int(11) NOT NULL,
  `type` smallint(6) NOT NULL,
  KEY `device_active_link_id` (`device_active_link_id`,`device_id`),
  CONSTRAINT `device_active_links_map_ibfk_1` FOREIGN KEY (`device_active_link_id`) REFERENCES `device_active_links` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb3 COLLATE=utf8mb3_czech_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `device_admins`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `device_admins` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `device_id` int(11) DEFAULT NULL,
  `user_id` int(11) DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `user_id` (`user_id`),
  KEY `device_id` (`device_id`),
  CONSTRAINT `device_admins_ibfk_1` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE,
  CONSTRAINT `device_admins_ibfk_2` FOREIGN KEY (`device_id`) REFERENCES `devices` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb3 COLLATE=utf8mb3_czech_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `device_engineers`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `device_engineers` (
  `id` int(10) NOT NULL AUTO_INCREMENT,
  `device_id` int(11) NOT NULL,
  `user_id` int(11) NOT NULL,
  PRIMARY KEY (`id`),
  KEY `user_id` (`user_id`),
  KEY `device_id` (`device_id`),
  CONSTRAINT `device_engineers_ibfk_1` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE,
  CONSTRAINT `device_engineers_ibfk_2` FOREIGN KEY (`device_id`) REFERENCES `devices` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb3 COLLATE=utf8mb3_czech_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `device_templates`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `device_templates` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `enum_type_id` int(11) NOT NULL,
  `name` varchar(80) NOT NULL,
  `values` text NOT NULL,
  `default` tinyint(4) NOT NULL DEFAULT 0,
  PRIMARY KEY (`id`),
  KEY `device_templates_category_id` (`enum_type_id`),
  CONSTRAINT `device_templates_ibfk_enum_type` FOREIGN KEY (`enum_type_id`) REFERENCES `enum_types` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb3 COLLATE=utf8mb3_czech_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `devices`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `devices` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `user_id` int(11) DEFAULT NULL,
  `address_point_id` int(11) DEFAULT NULL,
  `name` varchar(255) DEFAULT NULL,
  `type` int(11) NOT NULL,
  `trade_name` varchar(50) DEFAULT NULL,
  `operating_system` tinyint(4) DEFAULT NULL,
  `PPPoE_logging_in` tinyint(4) DEFAULT NULL,
  `login` varchar(30) DEFAULT NULL,
  `password` text DEFAULT NULL,
  `wpa_key` text DEFAULT NULL,
  `access_time` datetime DEFAULT NULL,
  `price` double DEFAULT NULL,
  `payment_rate` double DEFAULT NULL,
  `buy_date` date DEFAULT NULL,
  `comment` varchar(254) DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `user_id` (`user_id`),
  KEY `address_point_id` (`address_point_id`),
  CONSTRAINT `devices_ibfk_1` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE,
  CONSTRAINT `devices_ibfk_2` FOREIGN KEY (`address_point_id`) REFERENCES `address_points` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb3 COLLATE=utf8mb3_czech_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `dhcp_export_state`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `dhcp_export_state` (
  `client` varchar(64) NOT NULL,
  `exported_at` datetime(6) DEFAULT NULL,
  PRIMARY KEY (`client`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `email_queue_attachments`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `email_queue_attachments` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `email_queue_id` int(11) NOT NULL,
  `path` varchar(1024) NOT NULL,
  `name` varchar(255) DEFAULT NULL,
  `mime` char(100) DEFAULT NULL,
  `inline` tinyint(1) NOT NULL DEFAULT 0,
  `created_at` datetime NOT NULL,
  PRIMARY KEY (`id`),
  KEY `email_queue_id` (`email_queue_id`),
  CONSTRAINT `email_queue_attachments_ibfk_1` FOREIGN KEY (`email_queue_id`) REFERENCES `email_queues` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb3 COLLATE=utf8mb3_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `email_queues`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `email_queues` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `from` varchar(255) NOT NULL,
  `to` varchar(255) NOT NULL,
  `subject` varchar(255) NOT NULL,
  `body` text NOT NULL,
  `state` tinyint(4) NOT NULL,
  `access_time` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `from` (`from`,`to`),
  KEY `idx_state` (`state`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb3 COLLATE=utf8mb3_czech_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `enum_type_names`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `enum_type_names` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `type_name` varchar(255) NOT NULL,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb3 COLLATE=utf8mb3_czech_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `enum_types`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `enum_types` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `type_id` int(11) NOT NULL,
  `value` varchar(254) DEFAULT NULL,
  `read_only` tinyint(1) DEFAULT 0,
  `deprecated` tinyint(1) NOT NULL DEFAULT 0,
  PRIMARY KEY (`id`),
  KEY `type_id` (`type_id`),
  CONSTRAINT `enum_types_ibfk_1` FOREIGN KEY (`type_id`) REFERENCES `enum_type_names` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb3 COLLATE=utf8mb3_czech_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `fees`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `fees` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `readonly` tinyint(1) NOT NULL,
  `archived` tinyint(1) NOT NULL DEFAULT 0,
  `fee` double NOT NULL,
  `from` date NOT NULL,
  `to` date NOT NULL,
  `type_id` int(11) NOT NULL,
  `name` varchar(100) DEFAULT NULL COMMENT 'Optional name for fee, useable as tariff name in case of regular member fee',
  `special_type_id` int(11) DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `type_id` (`type_id`),
  CONSTRAINT `fees_ibfk_1` FOREIGN KEY (`type_id`) REFERENCES `enum_types` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb3 COLLATE=utf8mb3_czech_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `filter_queries`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `filter_queries` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `name` varchar(255) NOT NULL,
  `url` varchar(255) NOT NULL,
  `values` text NOT NULL,
  `default` tinyint(1) NOT NULL DEFAULT 0,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb3 COLLATE=utf8mb3_czech_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `gpon_olts`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `gpon_olts` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `name` varchar(100) NOT NULL,
  `ip` varchar(45) NOT NULL,
  `snmp_user` varchar(100) NOT NULL DEFAULT 'admin',
  `snmp_auth_pass` text DEFAULT NULL,
  `snmp_priv_pass` text DEFAULT NULL,
  `snmp_auth_proto` varchar(10) NOT NULL DEFAULT 'SHA',
  `snmp_priv_proto` varchar(10) NOT NULL DEFAULT 'AES',
  `line_prof` varchar(100) NOT NULL DEFAULT 'line-profile_default_0',
  `service_prof` varchar(100) NOT NULL DEFAULT 'sfu-aio-dmc',
  `traffic_table` varchar(100) NOT NULL DEFAULT 'int',
  `gpon_port` varchar(20) NOT NULL DEFAULT '0/1/0',
  `base_vlan` int(11) DEFAULT NULL,
  `port_count` int(11) DEFAULT NULL,
  `geocode_city` varchar(100) DEFAULT NULL,
  `vlan_map` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL CHECK (json_valid(`vlan_map`)),
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `gpon_olts_ip_unique` (`ip`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `groups_aro_map`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `groups_aro_map` (
  `group_id` int(11) NOT NULL DEFAULT 0,
  `aro_id` int(11) NOT NULL DEFAULT 0,
  PRIMARY KEY (`group_id`,`aro_id`),
  KEY `aro_id` (`aro_id`),
  CONSTRAINT `groups_aro_map_ibfk_1` FOREIGN KEY (`aro_id`) REFERENCES `users` (`id`) ON DELETE CASCADE,
  CONSTRAINT `groups_aro_map_ibfk_2` FOREIGN KEY (`group_id`) REFERENCES `aro_groups` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb3 COLLATE=utf8mb3_czech_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `ifaces`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `ifaces` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `type` int(11) NOT NULL,
  `device_id` int(11) NOT NULL,
  `link_id` int(11) DEFAULT NULL,
  `mac` varchar(20) DEFAULT NULL,
  `name` varchar(254) DEFAULT NULL,
  `number` int(11) DEFAULT NULL,
  `wireless_mode` int(11) DEFAULT NULL,
  `wireless_antenna` int(11) DEFAULT NULL,
  `port_mode` int(11) DEFAULT NULL,
  `comment` varchar(254) DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `device_iface` (`device_id`),
  KEY `segment_iface` (`link_id`),
  KEY `type` (`type`),
  CONSTRAINT `ifaces_ibfk_1` FOREIGN KEY (`device_id`) REFERENCES `devices` (`id`) ON DELETE CASCADE,
  CONSTRAINT `ifaces_ibfk_2` FOREIGN KEY (`link_id`) REFERENCES `links` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb3 COLLATE=utf8mb3_czech_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `ifaces_relationships`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `ifaces_relationships` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `parent_iface_id` int(11) NOT NULL,
  `iface_id` int(11) NOT NULL,
  PRIMARY KEY (`id`),
  KEY `parent_iface_id` (`iface_id`),
  KEY `iface_id` (`iface_id`),
  KEY `parent_iface_id_2` (`parent_iface_id`),
  CONSTRAINT `ifaces_relationships_ibfk_1` FOREIGN KEY (`parent_iface_id`) REFERENCES `ifaces` (`id`) ON DELETE CASCADE,
  CONSTRAINT `ifaces_relationships_ibfk_2` FOREIGN KEY (`iface_id`) REFERENCES `ifaces` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb3 COLLATE=utf8mb3_czech_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `ifaces_vlans`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `ifaces_vlans` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `iface_id` int(11) NOT NULL,
  `vlan_id` int(11) NOT NULL,
  `tagged` tinyint(1) DEFAULT NULL,
  `port_vlan` tinyint(1) DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `iface_id` (`iface_id`),
  KEY `vlan_id` (`vlan_id`),
  CONSTRAINT `ifaces_vlans_ibfk_1` FOREIGN KEY (`iface_id`) REFERENCES `ifaces` (`id`) ON DELETE CASCADE,
  CONSTRAINT `ifaces_vlans_ibfk_2` FOREIGN KEY (`vlan_id`) REFERENCES `vlans` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb3 COLLATE=utf8mb3_czech_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `invoice_items`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `invoice_items` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `invoice_id` int(11) NOT NULL,
  `name` varchar(255) NOT NULL,
  `code` varchar(100) NOT NULL,
  `quantity` double NOT NULL,
  `author_fee` double NOT NULL,
  `contractual_increase` double NOT NULL,
  `service` tinyint(1) NOT NULL,
  `price` double NOT NULL,
  `vat` double NOT NULL,
  PRIMARY KEY (`id`),
  KEY `invoice_id` (`invoice_id`),
  CONSTRAINT `invoice_items_ibfk_1` FOREIGN KEY (`invoice_id`) REFERENCES `invoices` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb3 COLLATE=utf8mb3_czech_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `invoice_templates`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `invoice_templates` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `name` varchar(200) NOT NULL,
  `invoices` varchar(255) DEFAULT NULL,
  `sup_company` text DEFAULT NULL,
  `sup_name` text DEFAULT NULL,
  `sup_street` varchar(255) DEFAULT NULL,
  `sup_street_number` varchar(255) DEFAULT NULL,
  `sup_town` varchar(255) DEFAULT NULL,
  `sup_zip_code` varchar(255) DEFAULT NULL,
  `sup_country` varchar(255) DEFAULT NULL,
  `sup_organization_identifier` varchar(255) DEFAULT NULL,
  `sup_vat_organization_identifier` varchar(255) DEFAULT NULL,
  `sup_phone_number` varchar(255) DEFAULT NULL,
  `sup_email` varchar(255) DEFAULT NULL,
  `cus_company` text DEFAULT NULL,
  `cus_name` text DEFAULT NULL,
  `cus_street` varchar(255) DEFAULT NULL,
  `cus_street_number` varchar(255) DEFAULT NULL,
  `cus_town` varchar(255) DEFAULT NULL,
  `cus_zip_code` varchar(255) DEFAULT NULL,
  `cus_country` varchar(255) DEFAULT NULL,
  `cus_organization_identifier` varchar(255) DEFAULT NULL,
  `cus_phone_number` varchar(255) DEFAULT NULL,
  `cus_email` varchar(255) DEFAULT NULL,
  `org_id` varchar(255) DEFAULT NULL,
  `invoice_nr` varchar(255) DEFAULT NULL,
  `invoice_type` varchar(255) DEFAULT NULL,
  `invoice_type_issued` varchar(255) DEFAULT NULL,
  `account_nr` text DEFAULT NULL,
  `var_sym` varchar(255) DEFAULT NULL,
  `con_sym` varchar(255) DEFAULT NULL,
  `date_inv` varchar(255) DEFAULT NULL,
  `date_due` varchar(255) DEFAULT NULL,
  `date_vat` varchar(255) DEFAULT NULL,
  `vat` varchar(255) DEFAULT NULL,
  `order_nr` varchar(255) DEFAULT NULL,
  `price` text DEFAULT NULL,
  `price_vat` text DEFAULT NULL,
  `currency` varchar(255) DEFAULT NULL,
  `note` varchar(255) DEFAULT NULL,
  `items` varchar(255) DEFAULT NULL,
  `item_name` varchar(255) DEFAULT NULL,
  `item_code` varchar(255) DEFAULT NULL,
  `item_quantity` varchar(255) DEFAULT NULL,
  `item_price` varchar(255) DEFAULT NULL,
  `item_vat` varchar(255) DEFAULT NULL,
  `charset` varchar(100) DEFAULT NULL,
  `namespace` text DEFAULT NULL,
  `vat_variables` text DEFAULT NULL,
  `type` tinyint(1) NOT NULL,
  `begin_tag` varchar(100) DEFAULT NULL,
  `end_tag` varchar(100) DEFAULT NULL,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb3 COLLATE=utf8mb3_czech_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `invoices`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `invoices` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `member_id` int(11) DEFAULT NULL,
  `partner_company` varchar(100) DEFAULT NULL,
  `partner_name` varchar(100) DEFAULT NULL,
  `partner_street` varchar(30) DEFAULT NULL,
  `partner_street_number` varchar(50) DEFAULT NULL,
  `partner_town` varchar(50) DEFAULT NULL,
  `partner_zip_code` varchar(10) DEFAULT NULL,
  `partner_country` varchar(100) DEFAULT NULL,
  `organization_identifier` varchar(20) DEFAULT NULL,
  `vat_organization_identifier` varchar(30) DEFAULT NULL,
  `phone_number` varchar(15) DEFAULT NULL,
  `email` varchar(255) DEFAULT NULL,
  `invoice_nr` bigint(20) unsigned NOT NULL,
  `invoice_type` tinyint(1) NOT NULL,
  `account_nr` varchar(254) DEFAULT NULL,
  `var_sym` double NOT NULL,
  `con_sym` double DEFAULT NULL,
  `date_inv` date NOT NULL,
  `date_due` date NOT NULL,
  `date_vat` date NOT NULL,
  `vat` tinyint(1) NOT NULL,
  `order_nr` double DEFAULT NULL,
  `currency` varchar(3) NOT NULL,
  `note` varchar(240) DEFAULT NULL,
  `pohoda_status` varchar(16) NOT NULL DEFAULT 'new',
  `pohoda_exported_at` datetime DEFAULT NULL,
  `pdf_filename` varchar(255) DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uniq_invoice_nr` (`invoice_nr`),
  KEY `member_id` (`member_id`),
  KEY `idx_pohoda_status` (`pohoda_status`),
  CONSTRAINT `invoices_ibfk_1` FOREIGN KEY (`member_id`) REFERENCES `members` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb3 COLLATE=utf8mb3_czech_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `ip6_addresses`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `ip6_addresses` (
  `iface_id` int(11) DEFAULT NULL,
  `subnet_id` int(11) DEFAULT NULL,
  `member_id` int(11) DEFAULT NULL,
  `ip_address` varchar(45) DEFAULT NULL,
  `dhcp` tinyint(4) DEFAULT NULL,
  `gateway` tinyint(4) DEFAULT NULL,
  `service` tinyint(4) NOT NULL DEFAULT 0,
  `id` int(11) NOT NULL AUTO_INCREMENT,
  PRIMARY KEY (`id`),
  KEY `member_id` (`member_id`),
  KEY `ip_addresses_key_iface_id` (`iface_id`),
  KEY `ip_addresses_key_subnet_id` (`subnet_id`),
  KEY `ip_address` (`ip_address`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb3 COLLATE=utf8mb3_czech_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `ip_addresses`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `ip_addresses` (
  `iface_id` int(11) DEFAULT NULL,
  `subnet_id` int(11) DEFAULT NULL,
  `member_id` int(11) DEFAULT NULL,
  `ip_address` varchar(15) DEFAULT NULL,
  `dhcp` tinyint(4) DEFAULT NULL,
  `gateway` tinyint(4) DEFAULT NULL,
  `service` tinyint(4) NOT NULL DEFAULT 0,
  `id` int(11) NOT NULL AUTO_INCREMENT,
  PRIMARY KEY (`id`),
  KEY `member_id` (`member_id`),
  KEY `ip_addresses_key_iface_id` (`iface_id`),
  KEY `ip_addresses_key_subnet_id` (`subnet_id`),
  KEY `ip_address` (`ip_address`),
  CONSTRAINT `ip_addresses_ibfk_1` FOREIGN KEY (`member_id`) REFERENCES `members` (`id`) ON DELETE SET NULL,
  CONSTRAINT `ip_addresses_ibfk_5` FOREIGN KEY (`iface_id`) REFERENCES `ifaces` (`id`) ON DELETE CASCADE,
  CONSTRAINT `ip_addresses_ibfk_6` FOREIGN KEY (`subnet_id`) REFERENCES `subnets` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb3 COLLATE=utf8mb3_czech_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `ip_addresses_traffics`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `ip_addresses_traffics` (
  `ip_address` varchar(15) NOT NULL,
  `upload` int(11) unsigned NOT NULL,
  `download` int(11) unsigned NOT NULL,
  `local_upload` int(11) NOT NULL,
  `local_download` int(11) NOT NULL,
  `member_id` int(11) DEFAULT NULL,
  PRIMARY KEY (`ip_address`),
  KEY `member_id` (`member_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb3 COLLATE=utf8mb3_czech_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `job_reports`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `job_reports` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `user_id` int(11) NOT NULL,
  `added_by_id` int(11) DEFAULT NULL,
  `transfer_id` int(11) DEFAULT NULL,
  `approval_template_id` int(11) NOT NULL,
  `description` text NOT NULL,
  `type` char(7) DEFAULT NULL COMMENT 'If null then type is gouped report, else type is monthly report and should contains date string in form ''YYYY-mm''',
  `price_per_hour` double NOT NULL COMMENT 'Price per hour for each work in report.',
  `price_per_km` double NOT NULL COMMENT 'Price per kilometre for each work in report.',
  `concept` tinyint(1) NOT NULL DEFAULT 0 COMMENT 'Concept is displayed only to owner',
  `payment_type` tinyint(4) NOT NULL DEFAULT 0 COMMENT 'Specified payment, 0 is credit payment, 1 is cash payment, see model for more details',
  PRIMARY KEY (`id`),
  KEY `user_id` (`user_id`),
  KEY `approval_template_id` (`approval_template_id`),
  KEY `transfer_id` (`transfer_id`),
  KEY `added_by_id` (`added_by_id`),
  CONSTRAINT `job_reports_ibfk_1` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE,
  CONSTRAINT `job_reports_ibfk_2` FOREIGN KEY (`approval_template_id`) REFERENCES `approval_templates` (`id`),
  CONSTRAINT `job_reports_ibfk_4` FOREIGN KEY (`added_by_id`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  CONSTRAINT `job_reports_ibfk_5` FOREIGN KEY (`transfer_id`) REFERENCES `transfers` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb3 COLLATE=utf8mb3_czech_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `jobs`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `jobs` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `job_report_id` int(11) DEFAULT NULL COMMENT 'belongs to job report',
  `user_id` int(11) NOT NULL,
  `previous_rejected_work_id` int(11) DEFAULT NULL,
  `added_by_id` int(11) DEFAULT NULL,
  `approval_template_id` int(11) DEFAULT NULL,
  `description` mediumtext DEFAULT NULL,
  `suggest_amount` int(11) NOT NULL COMMENT 'suggest amount by user',
  `date` date NOT NULL,
  `create_date` datetime NOT NULL COMMENT 'date of creation of work in IS',
  `hours` float DEFAULT NULL,
  `km` int(11) NOT NULL,
  `state` tinyint(1) NOT NULL,
  `transfer_id` int(11) DEFAULT NULL,
  `comments_thread_id` int(11) DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `commited_by` (`user_id`),
  KEY `transfer_salary` (`transfer_id`),
  KEY `comments_thread_id` (`comments_thread_id`),
  KEY `job_report_id` (`job_report_id`),
  KEY `added_by_id` (`added_by_id`),
  KEY `approval_template_id` (`approval_template_id`),
  KEY `previous_rejected_work_id` (`previous_rejected_work_id`),
  CONSTRAINT `jobs_ibfk_1` FOREIGN KEY (`job_report_id`) REFERENCES `job_reports` (`id`) ON DELETE CASCADE,
  CONSTRAINT `jobs_ibfk_2` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE,
  CONSTRAINT `jobs_ibfk_3` FOREIGN KEY (`added_by_id`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  CONSTRAINT `jobs_ibfk_4` FOREIGN KEY (`approval_template_id`) REFERENCES `approval_templates` (`id`) ON DELETE SET NULL,
  CONSTRAINT `jobs_ibfk_5` FOREIGN KEY (`transfer_id`) REFERENCES `transfers` (`id`) ON DELETE SET NULL,
  CONSTRAINT `jobs_ibfk_6` FOREIGN KEY (`comments_thread_id`) REFERENCES `comments_threads` (`id`) ON DELETE SET NULL,
  CONSTRAINT `jobs_ibfk_7` FOREIGN KEY (`previous_rejected_work_id`) REFERENCES `jobs` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb3 COLLATE=utf8mb3_czech_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `line_id_anomalies`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `line_id_anomalies` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `circuit_id` varchar(255) NOT NULL,
  `expected_iface_id` int(10) unsigned DEFAULT NULL,
  `seen_mac` varchar(32) NOT NULL,
  `seen_iface_id` int(10) unsigned DEFAULT NULL,
  `type` varchar(32) NOT NULL,
  `severity` varchar(16) NOT NULL,
  `seen_count` int(10) unsigned NOT NULL DEFAULT 1,
  `first_seen` timestamp NULL DEFAULT NULL,
  `last_seen` timestamp NULL DEFAULT NULL,
  `resolved_at` timestamp NULL DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_anom` (`circuit_id`,`seen_mac`),
  KEY `line_id_anomalies_resolved_at_index` (`resolved_at`),
  KEY `line_id_anomalies_severity_index` (`severity`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `line_id_pending`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `line_id_pending` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `circuit_id_hex` varchar(255) NOT NULL,
  `circuit_id` varchar(255) DEFAULT NULL,
  `mac` varchar(64) DEFAULT NULL,
  `vendor` varchar(32) DEFAULT NULL,
  `device_ident` varchar(128) DEFAULT NULL,
  `port` varchar(64) DEFAULT NULL,
  `seen_count` int(10) unsigned NOT NULL DEFAULT 1,
  `first_seen` timestamp NULL DEFAULT NULL,
  `last_seen` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_pending` (`circuit_id_hex`),
  KEY `line_id_pending_last_seen_index` (`last_seen`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `line_id_seen`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `line_id_seen` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `circuit_id_hex` varchar(255) NOT NULL,
  `remote_id_hex` varchar(255) DEFAULT NULL,
  `mac` varchar(32) NOT NULL,
  `seen_count` int(10) unsigned NOT NULL DEFAULT 1,
  `reconciled` tinyint(1) NOT NULL DEFAULT 0,
  `last_seen` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_seen` (`circuit_id_hex`,`mac`),
  KEY `line_id_seen_reconciled_index` (`reconciled`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `line_ids`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `line_ids` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `circuit_id` varbinary(255) NOT NULL,
  `remote_id` varbinary(255) NOT NULL DEFAULT '',
  `iface_id` int(10) unsigned NOT NULL,
  `vendor` varchar(32) DEFAULT NULL,
  `device_ident` varchar(128) DEFAULT NULL,
  `port` varchar(64) DEFAULT NULL,
  `source` varchar(16) NOT NULL DEFAULT 'manual',
  `last_seen` timestamp NULL DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `line_ids_circuit_remote_unique` (`circuit_id`,`remote_id`),
  KEY `line_ids_iface_id_index` (`iface_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `links`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `links` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `name` varchar(100) DEFAULT NULL,
  `medium` int(11) NOT NULL,
  `bitrate` bigint(20) DEFAULT NULL,
  `duplex` tinyint(4) DEFAULT NULL,
  `wireless_ssid` varchar(255) DEFAULT NULL,
  `wireless_norm` int(11) DEFAULT NULL,
  `wireless_frequency` int(11) DEFAULT NULL,
  `wireless_channel` int(11) DEFAULT NULL,
  `wireless_channel_width` int(11) DEFAULT NULL,
  `wireless_polarization` int(11) DEFAULT NULL,
  `comment` varchar(254) DEFAULT NULL,
  `gps` linestring DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `name` (`name`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb3 COLLATE=utf8mb3_czech_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `local_subnets`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `local_subnets` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `network_address` varchar(15) DEFAULT NULL,
  `netmask` varchar(15) DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `network_address_2` (`network_address`),
  KEY `network_address` (`network_address`),
  KEY `netmask` (`netmask`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb3 COLLATE=utf8mb3_czech_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `log_queues`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `log_queues` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `type` smallint(6) NOT NULL,
  `state` smallint(6) NOT NULL,
  `created_at` datetime NOT NULL,
  `closed_by_user_id` int(11) DEFAULT NULL,
  `closed_at` datetime DEFAULT NULL,
  `description` text NOT NULL,
  `exception_backtrace` text DEFAULT NULL,
  `comments_thread_id` int(11) DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `closed_by_user_id_fk` (`closed_by_user_id`),
  KEY `comments_thread_id_fk` (`comments_thread_id`),
  CONSTRAINT `log_queues_ibfk_1` FOREIGN KEY (`closed_by_user_id`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  CONSTRAINT `log_queues_ibfk_2` FOREIGN KEY (`comments_thread_id`) REFERENCES `comments_threads` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb3 COLLATE=utf8mb3_czech_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `login_logs`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `login_logs` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `user_id` int(11) NOT NULL,
  `time` datetime NOT NULL,
  `IP_address` varchar(45) NOT NULL,
  PRIMARY KEY (`id`),
  KEY `time` (`time`),
  KEY `user_id` (`user_id`),
  CONSTRAINT `login_logs_ibfk_1` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb3 COLLATE=utf8mb3_czech_ci ROW_FORMAT=COMPACT;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `logs`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `logs` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `table_name` varchar(255) NOT NULL,
  `values` text DEFAULT NULL,
  `time` datetime NOT NULL,
  `action` tinyint(2) NOT NULL DEFAULT 1,
  `object_id` int(11) NOT NULL,
  `user_id` int(11) NOT NULL,
  KEY `id` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb3 COLLATE=utf8mb3_czech_ci
 PARTITION BY RANGE (to_days(`time`))
(PARTITION `p_first` VALUES LESS THAN (719528) ENGINE = InnoDB,
 PARTITION `p_2026_04_12` VALUES LESS THAN (740084) ENGINE = InnoDB,
 PARTITION `p_2026_04_13` VALUES LESS THAN (740085) ENGINE = InnoDB,
 PARTITION `p_2026_04_14` VALUES LESS THAN (740086) ENGINE = InnoDB,
 PARTITION `p_2026_04_15` VALUES LESS THAN (740087) ENGINE = InnoDB,
 PARTITION `p_2026_04_16` VALUES LESS THAN (740088) ENGINE = InnoDB,
 PARTITION `p_2026_04_17` VALUES LESS THAN (740089) ENGINE = InnoDB,
 PARTITION `p_2026_04_18` VALUES LESS THAN (740090) ENGINE = InnoDB,
 PARTITION `p_2026_04_19` VALUES LESS THAN (740091) ENGINE = InnoDB,
 PARTITION `p_2026_04_20` VALUES LESS THAN (740092) ENGINE = InnoDB,
 PARTITION `p_2026_04_21` VALUES LESS THAN (740093) ENGINE = InnoDB,
 PARTITION `p_2026_04_22` VALUES LESS THAN (740094) ENGINE = InnoDB,
 PARTITION `p_2026_04_23` VALUES LESS THAN (740095) ENGINE = InnoDB,
 PARTITION `p_2026_04_24` VALUES LESS THAN (740096) ENGINE = InnoDB,
 PARTITION `p_2026_04_25` VALUES LESS THAN (740097) ENGINE = InnoDB,
 PARTITION `p_2026_04_26` VALUES LESS THAN (740098) ENGINE = InnoDB,
 PARTITION `p_2026_04_27` VALUES LESS THAN (740099) ENGINE = InnoDB,
 PARTITION `p_2026_04_28` VALUES LESS THAN (740100) ENGINE = InnoDB,
 PARTITION `p_2026_04_29` VALUES LESS THAN (740101) ENGINE = InnoDB,
 PARTITION `p_2026_04_30` VALUES LESS THAN (740102) ENGINE = InnoDB,
 PARTITION `p_2026_05_01` VALUES LESS THAN (740103) ENGINE = InnoDB,
 PARTITION `p_2026_05_02` VALUES LESS THAN (740104) ENGINE = InnoDB,
 PARTITION `p_2026_05_03` VALUES LESS THAN (740105) ENGINE = InnoDB,
 PARTITION `p_2026_05_04` VALUES LESS THAN (740106) ENGINE = InnoDB,
 PARTITION `p_2026_05_05` VALUES LESS THAN (740107) ENGINE = InnoDB,
 PARTITION `p_2026_05_06` VALUES LESS THAN (740108) ENGINE = InnoDB,
 PARTITION `p_2026_05_07` VALUES LESS THAN (740109) ENGINE = InnoDB,
 PARTITION `p_2026_05_08` VALUES LESS THAN (740110) ENGINE = InnoDB,
 PARTITION `p_2026_05_09` VALUES LESS THAN (740111) ENGINE = InnoDB,
 PARTITION `p_2026_05_10` VALUES LESS THAN (740112) ENGINE = InnoDB,
 PARTITION `p_2026_05_11` VALUES LESS THAN (740113) ENGINE = InnoDB,
 PARTITION `p_2026_05_12` VALUES LESS THAN (740114) ENGINE = InnoDB,
 PARTITION `p_2026_05_13` VALUES LESS THAN (740115) ENGINE = InnoDB);
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `mail_messages`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `mail_messages` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `from_id` int(11) NOT NULL,
  `to_id` int(11) NOT NULL,
  `subject` varchar(150) NOT NULL,
  `body` text NOT NULL,
  `time` datetime NOT NULL,
  `readed` tinyint(1) NOT NULL DEFAULT 0,
  `from_deleted` tinyint(1) NOT NULL DEFAULT 0,
  `to_deleted` tinyint(1) NOT NULL DEFAULT 0,
  PRIMARY KEY (`id`),
  KEY `from_id` (`from_id`),
  KEY `to_id` (`to_id`),
  CONSTRAINT `mail_messages_ibfk_1` FOREIGN KEY (`from_id`) REFERENCES `users` (`id`) ON DELETE CASCADE,
  CONSTRAINT `mail_messages_ibfk_2` FOREIGN KEY (`to_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb3 COLLATE=utf8mb3_czech_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `meeting_attendances`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `meeting_attendances` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `meeting_id` int(10) unsigned NOT NULL,
  `member_id` int(10) unsigned NOT NULL,
  `checked_in_at` timestamp NULL DEFAULT NULL,
  `proxy_holder_id` int(10) unsigned DEFAULT NULL,
  `note` varchar(255) DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_attendance` (`meeting_id`,`member_id`),
  KEY `meeting_attendances_member_id_index` (`member_id`),
  KEY `meeting_attendances_proxy_holder_id_index` (`proxy_holder_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `meetings`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `meetings` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `title` varchar(255) NOT NULL,
  `held_on` date NOT NULL,
  `type` varchar(40) NOT NULL DEFAULT 'clenska_schuze',
  `quorum_percent` tinyint(3) unsigned NOT NULL DEFAULT 50,
  `status` varchar(10) NOT NULL DEFAULT 'planned',
  `comment` varchar(500) DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `meetings_type_held_on_index` (`type`,`held_on`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `member_lan_access`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `member_lan_access` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `member_id` int(11) NOT NULL,
  `destination` varchar(18) NOT NULL,
  `comment` varchar(255) DEFAULT NULL,
  `created_by` int(11) DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `member_lan_access_member_id_destination_unique` (`member_id`,`destination`),
  KEY `member_lan_access_member_id_index` (`member_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `member_voting_logs`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `member_voting_logs` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `member_id` int(10) unsigned NOT NULL,
  `action` varchar(20) NOT NULL,
  `meeting_id` int(10) unsigned DEFAULT NULL,
  `reason` varchar(255) DEFAULT NULL,
  `user_id` int(10) unsigned DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `member_voting_logs_member_id_index` (`member_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `members`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `members` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `user_id` int(11) DEFAULT NULL COMMENT 'id of user who added member',
  `registration` tinyint(1) NOT NULL,
  `registration_target_type` tinyint(3) unsigned DEFAULT 2,
  `name` varchar(255) NOT NULL,
  `address_point_id` int(11) NOT NULL,
  `type` tinyint(4) DEFAULT NULL,
  `can_vote` tinyint(1) NOT NULL DEFAULT 0,
  `external_type` tinyint(4) DEFAULT NULL,
  `organization_identifier` varchar(20) DEFAULT NULL,
  `vat_organization_identifier` varchar(30) DEFAULT NULL,
  `speed_class_id` int(11) DEFAULT NULL,
  `allowed_subnets_count` int(10) unsigned NOT NULL DEFAULT 0,
  `entrance_fee` double DEFAULT NULL,
  `debt_payment_rate` double DEFAULT NULL,
  `entrance_fee_left` double DEFAULT NULL,
  `entrance_fee_date` date DEFAULT NULL,
  `entrance_date` date DEFAULT NULL,
  `entrance_form_received` date DEFAULT NULL,
  `entrance_form_accepted` date DEFAULT NULL,
  `leaving_date` date NOT NULL,
  `oku_code` char(14) DEFAULT NULL,
  `applicant_registration_datetime` datetime DEFAULT NULL COMMENT 'Used only for members who were registered by registration form by them self.',
  `applicant_connected_from` date DEFAULT NULL,
  `locked` tinyint(4) NOT NULL DEFAULT 0,
  `voip_billing_limit` int(11) NOT NULL DEFAULT 0,
  `voip_billing_type` varchar(10) NOT NULL DEFAULT 'prepaid',
  `comment` varchar(250) DEFAULT NULL,
  `notification_by_redirection` tinyint(4) NOT NULL DEFAULT 1,
  `notification_by_email` tinyint(4) NOT NULL DEFAULT 1,
  `notification_by_sms` tinyint(4) NOT NULL DEFAULT 1,
  `tv_active` tinyint(4) NOT NULL DEFAULT 0,
  `tv_valid_until` date DEFAULT NULL,
  `tv_synced_at` datetime DEFAULT NULL,
  `payment_blocked` tinyint(1) NOT NULL DEFAULT 0,
  `payment_blocked_since` date DEFAULT NULL,
  `pending_termination` tinyint(1) NOT NULL DEFAULT 0,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uniq_oku_code` (`oku_code`),
  KEY `address_point_id` (`address_point_id`),
  KEY `user_id` (`user_id`),
  KEY `speed_class_id_fk` (`speed_class_id`),
  KEY `members_payment_blocked_index` (`payment_blocked`),
  KEY `members_payment_blocked_since_index` (`payment_blocked_since`),
  CONSTRAINT `members_ibfk_1` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  CONSTRAINT `members_ibfk_2` FOREIGN KEY (`address_point_id`) REFERENCES `address_points` (`id`),
  CONSTRAINT `members_ibfk_3` FOREIGN KEY (`speed_class_id`) REFERENCES `speed_classes` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb3 COLLATE=utf8mb3_czech_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `members_domiciles`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `members_domiciles` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `member_id` int(11) NOT NULL,
  `address_point_id` int(11) NOT NULL,
  PRIMARY KEY (`id`),
  KEY `member_id` (`member_id`,`address_point_id`),
  KEY `address_point_id` (`address_point_id`),
  CONSTRAINT `members_domiciles_ibfk_1` FOREIGN KEY (`member_id`) REFERENCES `members` (`id`) ON DELETE CASCADE,
  CONSTRAINT `members_domiciles_ibfk_2` FOREIGN KEY (`address_point_id`) REFERENCES `address_points` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb3 COLLATE=utf8mb3_czech_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `members_fees`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `members_fees` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `fee_id` int(11) NOT NULL,
  `member_id` int(11) NOT NULL,
  `activation_date` date NOT NULL DEFAULT '0000-00-00',
  `deactivation_date` date DEFAULT NULL,
  `priority` int(11) NOT NULL DEFAULT 1,
  `comment` text NOT NULL,
  PRIMARY KEY (`id`),
  KEY `member_id` (`member_id`),
  KEY `fee_id` (`fee_id`),
  KEY `activation_date` (`activation_date`,`deactivation_date`),
  CONSTRAINT `members_fees_ibfk_1` FOREIGN KEY (`member_id`) REFERENCES `members` (`id`) ON DELETE CASCADE,
  CONSTRAINT `members_fees_ibfk_2` FOREIGN KEY (`fee_id`) REFERENCES `fees` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb3 COLLATE=utf8mb3_general_ci COMMENT='Junction table between fees and members, used primarily for ';
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `members_traffics_daily`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `members_traffics_daily` (
  `member_id` int(11) NOT NULL,
  `upload` bigint(20) unsigned NOT NULL,
  `download` bigint(20) unsigned NOT NULL,
  `local_upload` bigint(20) unsigned NOT NULL,
  `local_download` bigint(20) unsigned NOT NULL,
  `active` tinyint(1) NOT NULL DEFAULT 0,
  `date` date NOT NULL,
  PRIMARY KEY (`member_id`,`date`),
  KEY `member_id` (`member_id`),
  KEY `date` (`date`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb3 COLLATE=utf8mb3_czech_ci
 PARTITION BY RANGE (to_days(`date`))
(PARTITION `p_first` VALUES LESS THAN (719528) ENGINE = InnoDB,
 PARTITION `p_2016_02_26` VALUES LESS THAN (736386) ENGINE = InnoDB,
 PARTITION `p_2016_02_27` VALUES LESS THAN (736387) ENGINE = InnoDB,
 PARTITION `p_2016_02_28` VALUES LESS THAN (736388) ENGINE = InnoDB,
 PARTITION `p_2016_02_29` VALUES LESS THAN (736389) ENGINE = InnoDB,
 PARTITION `p_2016_03_01` VALUES LESS THAN (736390) ENGINE = InnoDB,
 PARTITION `p_2016_03_02` VALUES LESS THAN (736391) ENGINE = InnoDB,
 PARTITION `p_2016_03_03` VALUES LESS THAN (736392) ENGINE = InnoDB,
 PARTITION `p_2016_03_04` VALUES LESS THAN (736393) ENGINE = InnoDB);
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `members_traffics_monthly`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `members_traffics_monthly` (
  `member_id` int(11) NOT NULL,
  `upload` bigint(20) unsigned NOT NULL,
  `download` bigint(20) unsigned NOT NULL,
  `local_upload` bigint(20) unsigned NOT NULL,
  `local_download` bigint(20) unsigned NOT NULL,
  `date` date NOT NULL,
  PRIMARY KEY (`member_id`,`date`),
  KEY `member_id` (`member_id`),
  KEY `date` (`date`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb3 COLLATE=utf8mb3_czech_ci
 PARTITION BY RANGE (to_days(`date`))
(PARTITION `p_first` VALUES LESS THAN (719528) ENGINE = InnoDB,
 PARTITION `p_2016_03_01` VALUES LESS THAN (736420) ENGINE = InnoDB);
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `members_traffics_yearly`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `members_traffics_yearly` (
  `member_id` int(11) NOT NULL,
  `upload` bigint(20) unsigned NOT NULL,
  `download` bigint(20) unsigned NOT NULL,
  `local_upload` bigint(20) unsigned NOT NULL,
  `local_download` bigint(20) unsigned NOT NULL,
  `date` date NOT NULL,
  PRIMARY KEY (`member_id`,`date`),
  KEY `member_id` (`member_id`),
  KEY `date` (`date`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb3 COLLATE=utf8mb3_czech_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `members_whitelists`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `members_whitelists` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `member_id` int(11) NOT NULL,
  `permanent` tinyint(1) NOT NULL,
  `since` date NOT NULL,
  `until` date NOT NULL,
  `comment` text DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `since_index` (`since`),
  KEY `until` (`until`),
  KEY `member_id_fk` (`member_id`),
  CONSTRAINT `members_whitelists_ibfk_1` FOREIGN KEY (`member_id`) REFERENCES `members` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb3 COLLATE=utf8mb3_czech_ci COMMENT='Redirection member white list.';
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `membership_interrupts`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `membership_interrupts` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `member_id` int(11) NOT NULL,
  `members_fee_id` int(11) DEFAULT NULL,
  `comment` varchar(255) DEFAULT NULL,
  `end_after_interrupt_end` smallint(6) NOT NULL,
  PRIMARY KEY (`id`),
  KEY `member_id` (`member_id`),
  KEY `members_fee_id` (`members_fee_id`),
  CONSTRAINT `membership_interrupts_ibfk_1` FOREIGN KEY (`member_id`) REFERENCES `members` (`id`) ON DELETE CASCADE,
  CONSTRAINT `membership_interrupts_ibfk_2` FOREIGN KEY (`members_fee_id`) REFERENCES `members_fees` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb3 COLLATE=utf8mb3_czech_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `membership_transfers`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `membership_transfers` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `from_member_id` int(11) NOT NULL,
  `to_member_id` int(11) NOT NULL,
  PRIMARY KEY (`id`),
  KEY `from_member_id` (`from_member_id`),
  KEY `to_member_id` (`to_member_id`),
  CONSTRAINT `membership_transfers_ibfk_1` FOREIGN KEY (`to_member_id`) REFERENCES `members` (`id`) ON DELETE CASCADE,
  CONSTRAINT `membership_transfers_ibfk_2` FOREIGN KEY (`from_member_id`) REFERENCES `members` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb3 COLLATE=utf8mb3_czech_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `messages`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `messages` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `name` varchar(100) NOT NULL COMMENT 'Name of the redirection message',
  `text` text DEFAULT NULL COMMENT 'Content of message, can contain HTML code.',
  `email_text` text DEFAULT NULL,
  `sms_text` varchar(760) DEFAULT NULL,
  `type` tinyint(4) DEFAULT NULL COMMENT 'Types of messages are saved in Mesage_Model.',
  `self_cancel` tinyint(4) DEFAULT NULL COMMENT 'Possibility of self-canceling of redirection message.',
  `ignore_whitelist` tinyint(4) DEFAULT 0 COMMENT 'If set, than IP address in whitelist are also redirected.',
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb3 COLLATE=utf8mb3_czech_ci COMMENT='messages used for redirection';
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `messages_automatical_activations`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `messages_automatical_activations` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `message_id` int(11) NOT NULL,
  `type` smallint(6) NOT NULL,
  `attribute` varchar(255) DEFAULT NULL,
  `redirection_enabled` tinyint(1) NOT NULL DEFAULT 0,
  `email_enabled` tinyint(1) NOT NULL DEFAULT 0,
  `sms_enabled` tinyint(1) NOT NULL DEFAULT 0,
  `send_activation_to_email` varchar(255) DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `message_id_fk` (`message_id`),
  CONSTRAINT `messages_automatical_activations_ibfk_1` FOREIGN KEY (`message_id`) REFERENCES `messages` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb3 COLLATE=utf8mb3_czech_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `messages_ip_addresses`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `messages_ip_addresses` (
  `message_id` int(11) NOT NULL COMMENT 'redirection message',
  `ip_address_id` int(11) NOT NULL COMMENT 'redirected ip address',
  `user_id` int(11) DEFAULT NULL COMMENT 'user id of admin who redirects',
  `comment` text DEFAULT NULL COMMENT 'personal comment from admin for redirected user',
  `datetime` datetime NOT NULL COMMENT 'Date and time of setting redirection',
  PRIMARY KEY (`message_id`,`ip_address_id`),
  KEY `user_id` (`user_id`),
  KEY `ip_address_id` (`ip_address_id`),
  CONSTRAINT `messages_ip_addresses_ibfk_1` FOREIGN KEY (`message_id`) REFERENCES `messages` (`id`) ON DELETE CASCADE,
  CONSTRAINT `messages_ip_addresses_ibfk_2` FOREIGN KEY (`ip_address_id`) REFERENCES `ip_addresses` (`id`) ON DELETE CASCADE,
  CONSTRAINT `messages_ip_addresses_ibfk_3` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  CONSTRAINT `messages_ip_addresses_ibfk_4` FOREIGN KEY (`message_id`) REFERENCES `messages` (`id`) ON DELETE CASCADE,
  CONSTRAINT `messages_ip_addresses_ibfk_5` FOREIGN KEY (`ip_address_id`) REFERENCES `ip_addresses` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb3 COLLATE=utf8mb3_czech_ci COMMENT='junction table between redirected ip address and message of ';
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `mfa_recovery_codes`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `mfa_recovery_codes` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `user_id` int(11) NOT NULL,
  `code_hash` varchar(255) NOT NULL,
  `used_at` timestamp NULL DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `mfa_recovery_codes_user_id_index` (`user_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `mfa_required_groups`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `mfa_required_groups` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `group_id` int(11) NOT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `mfa_required_groups_group_id_unique` (`group_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `migrations`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `migrations` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `migration` varchar(255) NOT NULL,
  `batch` int(11) NOT NULL,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `monitor_hosts`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `monitor_hosts` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `device_id` int(11) NOT NULL,
  `state` tinyint(1) NOT NULL,
  `state_changed` tinyint(1) NOT NULL,
  `state_changed_date` datetime NOT NULL,
  `last_attempt_date` datetime NOT NULL,
  `last_notification_date` datetime DEFAULT NULL,
  `latency_current` float DEFAULT NULL,
  `latency_min` float DEFAULT NULL,
  `latency_max` float DEFAULT NULL,
  `latency_avg` float DEFAULT NULL,
  `polls_total` bigint(11) NOT NULL,
  `polls_failed` bigint(11) NOT NULL,
  `availability` float NOT NULL,
  `priority` tinyint(1) NOT NULL,
  PRIMARY KEY (`id`),
  KEY `device_id` (`device_id`),
  CONSTRAINT `monitor_hosts_ibfk_1` FOREIGN KEY (`device_id`) REFERENCES `devices` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb3 COLLATE=utf8mb3_czech_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `onts`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `onts` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `serial` varchar(64) NOT NULL,
  `gpon_port` varchar(64) NOT NULL,
  `port_num` tinyint(3) unsigned NOT NULL,
  `port_index` bigint(20) DEFAULT NULL,
  `ont_id` int(10) unsigned NOT NULL,
  `service_port` int(10) unsigned NOT NULL,
  `vlan` int(10) unsigned NOT NULL,
  `reg_status` enum('new','registered','removed') NOT NULL DEFAULT 'new',
  `house_no` varchar(32) DEFAULT NULL,
  `user_name` varchar(128) DEFAULT NULL,
  `olt_ip` varchar(45) DEFAULT NULL,
  `gps_lat` decimal(10,7) DEFAULT NULL,
  `gps_lng` decimal(10,7) DEFAULT NULL,
  `member_id` int(11) DEFAULT NULL,
  `device_id` int(11) DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `onts_member_id_foreign` (`member_id`),
  KEY `onts_device_id_foreign` (`device_id`),
  KEY `onts_serial_index` (`serial`),
  KEY `onts_olt_ip_index` (`olt_ip`),
  CONSTRAINT `onts_device_id_foreign` FOREIGN KEY (`device_id`) REFERENCES `devices` (`id`) ON DELETE SET NULL,
  CONSTRAINT `onts_member_id_foreign` FOREIGN KEY (`member_id`) REFERENCES `members` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `outgoing_payments`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `outgoing_payments` (
  `id` int(11) unsigned NOT NULL AUTO_INCREMENT,
  `bank_account_id` int(11) unsigned NOT NULL COMMENT 'our bank account (from which we pay)',
  `transfer_id` int(11) unsigned DEFAULT NULL,
  `target_account` varchar(34) NOT NULL COMMENT 'e.g. 123456789/2010',
  `target_name` varchar(128) DEFAULT NULL,
  `amount` decimal(12,2) NOT NULL,
  `currency` char(3) NOT NULL DEFAULT 'CZK',
  `variable_symbol` varchar(20) DEFAULT NULL,
  `message` varchar(140) NOT NULL,
  `reason` enum('unidentified_refund','termination_refund','other') NOT NULL DEFAULT 'other',
  `status` enum('draft','approved','exported','paid','cancelled') NOT NULL DEFAULT 'draft',
  `created_by` int(11) unsigned NOT NULL,
  `approved_by` int(11) unsigned DEFAULT NULL,
  `created_at` datetime NOT NULL,
  `updated_at` datetime NOT NULL,
  `export_ref` varchar(64) DEFAULT NULL,
  `exported_at` datetime DEFAULT NULL,
  `export_filename` varchar(255) DEFAULT NULL,
  `api_response` text DEFAULT NULL,
  `paid_at` datetime DEFAULT NULL,
  `paid_transfer_id` int(10) unsigned DEFAULT NULL,
  `match_method` varchar(32) DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_transfer_refund` (`transfer_id`),
  KEY `idx_status` (`status`),
  KEY `idx_bank_account_id` (`bank_account_id`),
  KEY `idx_reason` (`reason`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb3 COLLATE=utf8mb3_czech_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `password_reset_tokens`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `password_reset_tokens` (
  `email` varchar(255) NOT NULL,
  `token` varchar(255) NOT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`email`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `phone_calls`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `phone_calls` (
  `phone_invoice_user_id` int(11) NOT NULL,
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `datetime` datetime NOT NULL COMMENT 'Call started at',
  `price` float NOT NULL,
  `length` time NOT NULL,
  `number` varchar(15) NOT NULL COMMENT 'Called',
  `period` varchar(100) DEFAULT NULL,
  `description` text DEFAULT NULL,
  `private` tinyint(1) DEFAULT 1 COMMENT 'Was call private?',
  PRIMARY KEY (`id`),
  KEY `phone_invoice_users_id` (`phone_invoice_user_id`),
  KEY `datetime` (`datetime`),
  KEY `number` (`number`),
  CONSTRAINT `phone_calls_ibfk_1` FOREIGN KEY (`phone_invoice_user_id`) REFERENCES `phone_invoice_users` (`id`) ON DELETE CASCADE,
  CONSTRAINT `phone_calls_ibfk_2` FOREIGN KEY (`phone_invoice_user_id`) REFERENCES `phone_invoice_users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb3 COLLATE=utf8mb3_czech_ci COMMENT='Table of call service of phone invoice';
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `phone_connections`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `phone_connections` (
  `phone_invoice_user_id` int(11) NOT NULL,
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `datetime` datetime NOT NULL COMMENT 'Connection started at',
  `price` float NOT NULL,
  `transfered` int(11) NOT NULL COMMENT 'In kB',
  `period` varchar(100) DEFAULT NULL,
  `apn` varchar(100) DEFAULT NULL,
  `private` tinyint(1) DEFAULT 1 COMMENT 'Was it private?',
  PRIMARY KEY (`id`),
  KEY `phone_invoice_users_id` (`phone_invoice_user_id`),
  KEY `datetime` (`datetime`),
  CONSTRAINT `phone_connections_ibfk_1` FOREIGN KEY (`phone_invoice_user_id`) REFERENCES `phone_invoice_users` (`id`) ON DELETE CASCADE,
  CONSTRAINT `phone_connections_ibfk_2` FOREIGN KEY (`phone_invoice_user_id`) REFERENCES `phone_invoice_users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb3 COLLATE=utf8mb3_czech_ci COMMENT='Table of data service(internet connection) of phone invoice';
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `phone_fixed_calls`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `phone_fixed_calls` (
  `phone_invoice_user_id` int(11) NOT NULL,
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `datetime` datetime NOT NULL COMMENT 'Call started at',
  `price` float NOT NULL,
  `length` time NOT NULL,
  `number` varchar(15) NOT NULL COMMENT 'Called',
  `period` varchar(100) DEFAULT NULL,
  `destiny` varchar(200) DEFAULT NULL,
  `private` tinyint(1) DEFAULT 1 COMMENT 'Was fised call private?',
  PRIMARY KEY (`id`),
  KEY `phone_invoice_users_id` (`phone_invoice_user_id`),
  KEY `datetime` (`datetime`),
  KEY `number` (`number`),
  CONSTRAINT `phone_fixed_calls_ibfk_1` FOREIGN KEY (`phone_invoice_user_id`) REFERENCES `phone_invoice_users` (`id`) ON DELETE CASCADE,
  CONSTRAINT `phone_fixed_calls_ibfk_2` FOREIGN KEY (`phone_invoice_user_id`) REFERENCES `phone_invoice_users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb3 COLLATE=utf8mb3_czech_ci COMMENT='Table of fixed call service of phone invoice';
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `phone_invoice_users`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `phone_invoice_users` (
  `user_id` int(11) DEFAULT NULL COMMENT 'ID of user or NULL if user was not assigned yet',
  `phone_invoice_id` int(11) NOT NULL,
  `transfer_id` int(11) DEFAULT NULL,
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `phone_number` varchar(15) NOT NULL COMMENT 'Phone nuber with prefix with leading plus',
  `locked` tinyint(1) unsigned NOT NULL COMMENT 'User locked his invoice as filled in.',
  PRIMARY KEY (`id`),
  KEY `user_id` (`user_id`,`phone_invoice_id`),
  KEY `phone_invoice_id` (`phone_invoice_id`),
  KEY `phone_number` (`phone_number`),
  KEY `transfer_id` (`transfer_id`),
  CONSTRAINT `phone_invoice_users_ibfk_1` FOREIGN KEY (`phone_invoice_id`) REFERENCES `phone_invoices` (`id`) ON DELETE CASCADE,
  CONSTRAINT `phone_invoice_users_ibfk_2` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE,
  CONSTRAINT `phone_invoice_users_ibfk_3` FOREIGN KEY (`phone_invoice_id`) REFERENCES `phone_invoices` (`id`) ON DELETE CASCADE,
  CONSTRAINT `phone_invoice_users_ibfk_4` FOREIGN KEY (`transfer_id`) REFERENCES `transfers` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb3 COLLATE=utf8mb3_czech_ci COMMENT='Table of user invoices for phone';
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `phone_invoices`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `phone_invoices` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `date_of_issuance` date NOT NULL,
  `billing_period_from` date NOT NULL,
  `billing_period_to` date NOT NULL,
  `variable_symbol` bigint(11) NOT NULL,
  `specific_symbol` bigint(11) NOT NULL,
  `total_price` float NOT NULL COMMENT 'Price without taxes',
  `tax` float NOT NULL COMMENT 'DPH',
  `tax_rate` smallint(6) NOT NULL COMMENT 'DPH in percents',
  `locked` tinyint(1) DEFAULT 0 COMMENT 'Indicate if invoice is locked for editing from users (not admin)',
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb3 COLLATE=utf8mb3_czech_ci COMMENT='Table of phone invoices';
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `phone_operator_prefixes`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `phone_operator_prefixes` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `phone_operator_id` int(11) NOT NULL,
  `prefix` varchar(4) NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `prefix` (`prefix`),
  KEY `phone_operator_id` (`phone_operator_id`),
  CONSTRAINT `phone_operator_prefixes_ibfk_1` FOREIGN KEY (`phone_operator_id`) REFERENCES `phone_operators` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb3 COLLATE=utf8mb3_czech_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `phone_operators`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `phone_operators` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `country_id` smallint(6) NOT NULL,
  `name` varchar(255) DEFAULT NULL,
  `phone_number_length` smallint(2) DEFAULT 6,
  `sms_enabled` tinyint(1) DEFAULT 0 COMMENT 'Allows SMS sendind for this operator?',
  PRIMARY KEY (`id`),
  KEY `country_id` (`country_id`),
  CONSTRAINT `phone_operators_ibfk_1` FOREIGN KEY (`country_id`) REFERENCES `countries` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb3 COLLATE=utf8mb3_czech_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `phone_pays`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `phone_pays` (
  `phone_invoice_user_id` int(11) NOT NULL,
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `datetime` datetime NOT NULL COMMENT 'Payed at',
  `price` float NOT NULL,
  `number` varchar(15) NOT NULL COMMENT 'Pay to',
  `description` varchar(200) DEFAULT NULL,
  `private` tinyint(4) DEFAULT 1 COMMENT 'Was pay private?',
  PRIMARY KEY (`id`),
  KEY `phone_invoice_users_id` (`phone_invoice_user_id`),
  KEY `datetime` (`datetime`),
  KEY `number` (`number`),
  CONSTRAINT `phone_pays_ibfk_1` FOREIGN KEY (`phone_invoice_user_id`) REFERENCES `phone_invoice_users` (`id`) ON DELETE CASCADE,
  CONSTRAINT `phone_pays_ibfk_2` FOREIGN KEY (`phone_invoice_user_id`) REFERENCES `phone_invoice_users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb3 COLLATE=utf8mb3_czech_ci COMMENT='Table of pays of phone invoice';
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `phone_roaming_sms_messages`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `phone_roaming_sms_messages` (
  `phone_invoice_user_id` int(11) NOT NULL,
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `datetime` datetime NOT NULL COMMENT 'Message send at',
  `price` float NOT NULL,
  `roaming_zone` varchar(200) DEFAULT NULL,
  `private` tinyint(4) DEFAULT 1 COMMENT 'Was message private?',
  PRIMARY KEY (`id`),
  KEY `phone_invoice_users_id` (`phone_invoice_user_id`),
  KEY `datetime` (`datetime`),
  CONSTRAINT `phone_roaming_sms_messages_ibfk_1` FOREIGN KEY (`phone_invoice_user_id`) REFERENCES `phone_invoice_users` (`id`) ON DELETE CASCADE,
  CONSTRAINT `phone_roaming_sms_messages_ibfk_2` FOREIGN KEY (`phone_invoice_user_id`) REFERENCES `phone_invoice_users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb3 COLLATE=utf8mb3_czech_ci COMMENT='Table of sms messages service of phone invoice';
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `phone_sms_messages`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `phone_sms_messages` (
  `phone_invoice_user_id` int(11) NOT NULL,
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `datetime` datetime NOT NULL COMMENT 'Message send at',
  `price` float NOT NULL,
  `number` varchar(15) NOT NULL COMMENT 'Send to',
  `period` varchar(100) DEFAULT NULL,
  `description` varchar(200) DEFAULT NULL,
  `private` tinyint(4) DEFAULT 1 COMMENT 'Was message private?',
  PRIMARY KEY (`id`),
  KEY `phone_invoice_users_id` (`phone_invoice_user_id`),
  KEY `datetime` (`datetime`),
  KEY `number` (`number`),
  CONSTRAINT `phone_sms_messages_ibfk_1` FOREIGN KEY (`phone_invoice_user_id`) REFERENCES `phone_invoice_users` (`id`) ON DELETE CASCADE,
  CONSTRAINT `phone_sms_messages_ibfk_2` FOREIGN KEY (`phone_invoice_user_id`) REFERENCES `phone_invoice_users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb3 COLLATE=utf8mb3_czech_ci COMMENT='Table of sms messages service of phone invoice';
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `phone_vpn_calls`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `phone_vpn_calls` (
  `phone_invoice_user_id` int(11) NOT NULL,
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `datetime` datetime NOT NULL COMMENT 'Call started at',
  `price` float NOT NULL,
  `length` time NOT NULL,
  `number` varchar(15) NOT NULL COMMENT 'Called to',
  `period` varchar(100) DEFAULT NULL,
  `group` varchar(200) DEFAULT NULL,
  `private` tinyint(1) DEFAULT 1 COMMENT 'Was call private?',
  PRIMARY KEY (`id`),
  KEY `phone_invoice_users_id` (`phone_invoice_user_id`),
  KEY `datetime` (`datetime`),
  KEY `number` (`number`),
  CONSTRAINT `phone_vpn_calls_ibfk_1` FOREIGN KEY (`phone_invoice_user_id`) REFERENCES `phone_invoice_users` (`id`) ON DELETE CASCADE,
  CONSTRAINT `phone_vpn_calls_ibfk_2` FOREIGN KEY (`phone_invoice_user_id`) REFERENCES `phone_invoice_users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb3 COLLATE=utf8mb3_czech_ci COMMENT='Table of vpn call service of phone invoice';
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `pohoda_refund_queue`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `pohoda_refund_queue` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `member_id` int(11) NOT NULL,
  `member_type` int(11) NOT NULL,
  `doc_number` varchar(20) NOT NULL,
  `outgoing_payment_id` int(11) DEFAULT NULL,
  `refund_account` varchar(64) NOT NULL,
  `amount` decimal(12,2) NOT NULL,
  `currency` char(3) NOT NULL DEFAULT 'CZK',
  `reason` varchar(32) NOT NULL DEFAULT 'termination_refund',
  `note` varchar(255) DEFAULT NULL,
  `created_at` datetime NOT NULL,
  `exported_at` datetime DEFAULT NULL,
  `status` varchar(16) NOT NULL DEFAULT 'new',
  PRIMARY KEY (`id`),
  UNIQUE KEY `uniq_doc_number` (`doc_number`),
  KEY `status` (`status`),
  KEY `member_id` (`member_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb3 COLLATE=utf8mb3_czech_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `pppoe_secrets`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `pppoe_secrets` (
  `iface_id` int(10) unsigned NOT NULL,
  `username` varchar(64) NOT NULL,
  `secret` varchar(64) NOT NULL,
  `enabled` tinyint(1) NOT NULL DEFAULT 1,
  PRIMARY KEY (`iface_id`),
  UNIQUE KEY `pppoe_secrets_username_unique` (`username`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `private_users_contacts`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `private_users_contacts` (
  `id` int(10) NOT NULL AUTO_INCREMENT,
  `user_id` int(10) NOT NULL COMMENT 'User who has private contact',
  `contact_id` int(10) NOT NULL,
  `description` varchar(255) DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `user_id` (`user_id`,`contact_id`),
  KEY `private_users_contacts_ibfk_2` (`contact_id`),
  CONSTRAINT `private_users_contacts_ibfk_1` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE,
  CONSTRAINT `private_users_contacts_ibfk_2` FOREIGN KEY (`contact_id`) REFERENCES `contacts` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb3 COLLATE=utf8mb3_czech_ci COMMENT='Private address book of each user';
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `public_ip_nat_1to1`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `public_ip_nat_1to1` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `public_ip` varchar(45) NOT NULL,
  `private_ip` varchar(45) DEFAULT NULL,
  `scope` varchar(64) DEFAULT NULL,
  `enabled` tinyint(1) NOT NULL DEFAULT 1,
  `comment` varchar(255) DEFAULT NULL,
  `created` datetime NOT NULL DEFAULT current_timestamp(),
  `modified` datetime DEFAULT NULL,
  `created_by` int(10) unsigned DEFAULT NULL,
  `modified_by` int(10) unsigned DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uniq_public_scope` (`public_ip`,`scope`),
  KEY `idx_enabled` (`enabled`),
  KEY `idx_scope` (`scope`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb3 COLLATE=utf8mb3_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `public_ips`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `public_ips` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `ip` varchar(45) NOT NULL,
  `enabled` tinyint(1) NOT NULL DEFAULT 1,
  `note` varchar(255) DEFAULT NULL,
  `created` datetime NOT NULL DEFAULT current_timestamp(),
  `modified` datetime DEFAULT NULL,
  `created_by` int(10) unsigned DEFAULT NULL,
  `modified_by` int(10) unsigned DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uniq_ip` (`ip`),
  KEY `idx_enabled` (`enabled`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb3 COLLATE=utf8mb3_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `public_port_forwards`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `public_port_forwards` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `public_ip` varchar(45) NOT NULL,
  `public_port_from` int(10) unsigned NOT NULL,
  `public_port_to` int(10) unsigned NOT NULL,
  `private_ip` varchar(45) NOT NULL,
  `private_port_from` int(10) unsigned NOT NULL,
  `private_port_to` int(10) unsigned NOT NULL,
  `protocol` enum('tcp','udp') NOT NULL,
  `enabled` tinyint(1) NOT NULL DEFAULT 1,
  `created` datetime NOT NULL,
  `modified` datetime DEFAULT NULL,
  `created_by` int(10) unsigned DEFAULT NULL,
  `modified_by` int(10) unsigned DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_public` (`public_ip`,`protocol`,`public_port_from`),
  KEY `idx_private` (`private_ip`),
  KEY `idx_enabled` (`enabled`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb3 COLLATE=utf8mb3_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `radacct`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `radacct` (
  `radacctid` bigint(21) NOT NULL AUTO_INCREMENT,
  `acctsessionid` varchar(64) NOT NULL DEFAULT '',
  `acctuniqueid` varchar(32) NOT NULL DEFAULT '',
  `username` varchar(64) NOT NULL DEFAULT '',
  `realm` varchar(64) DEFAULT '',
  `nasipaddress` varchar(15) NOT NULL DEFAULT '',
  `nasportid` varchar(32) DEFAULT NULL,
  `nasporttype` varchar(32) DEFAULT NULL,
  `acctstarttime` datetime DEFAULT NULL,
  `acctupdatetime` datetime DEFAULT NULL,
  `acctstoptime` datetime DEFAULT NULL,
  `acctinterval` int(12) DEFAULT NULL,
  `acctsessiontime` int(12) unsigned DEFAULT NULL,
  `acctauthentic` varchar(32) DEFAULT NULL,
  `connectinfo_start` varchar(128) DEFAULT NULL,
  `connectinfo_stop` varchar(128) DEFAULT NULL,
  `acctinputoctets` bigint(20) DEFAULT NULL,
  `acctoutputoctets` bigint(20) DEFAULT NULL,
  `calledstationid` varchar(255) NOT NULL DEFAULT '',
  `callingstationid` varchar(255) NOT NULL DEFAULT '',
  `acctterminatecause` varchar(32) NOT NULL DEFAULT '',
  `servicetype` varchar(32) DEFAULT NULL,
  `framedprotocol` varchar(32) DEFAULT NULL,
  `framedipaddress` varchar(15) NOT NULL DEFAULT '',
  `framedipv6address` varchar(45) NOT NULL DEFAULT '',
  `framedipv6prefix` varchar(45) NOT NULL DEFAULT '',
  `framedinterfaceid` varchar(44) NOT NULL DEFAULT '',
  `delegatedipv6prefix` varchar(45) NOT NULL DEFAULT '',
  `class` varchar(64) DEFAULT NULL,
  PRIMARY KEY (`radacctid`),
  UNIQUE KEY `acctuniqueid` (`acctuniqueid`),
  KEY `username` (`username`),
  KEY `framedipaddress` (`framedipaddress`),
  KEY `framedipv6address` (`framedipv6address`),
  KEY `framedipv6prefix` (`framedipv6prefix`),
  KEY `framedinterfaceid` (`framedinterfaceid`),
  KEY `delegatedipv6prefix` (`delegatedipv6prefix`),
  KEY `acctsessionid` (`acctsessionid`),
  KEY `acctsessiontime` (`acctsessiontime`),
  KEY `acctstarttime` (`acctstarttime`),
  KEY `acctinterval` (`acctinterval`),
  KEY `acctstoptime` (`acctstoptime`),
  KEY `nasipaddress` (`nasipaddress`),
  KEY `class` (`class`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `radcheck_kea4_v`;
/*!50001 DROP VIEW IF EXISTS `radcheck_kea4_v`*/;
SET @saved_cs_client     = @@character_set_client;
SET character_set_client = utf8mb4;
/*!50001 CREATE VIEW `radcheck_kea4_v` AS SELECT
 1 AS `id`,
  1 AS `username`,
  1 AS `attribute`,
  1 AS `op`,
  1 AS `value` */;
SET character_set_client = @saved_cs_client;
DROP TABLE IF EXISTS `radcheck_kea6mac_v`;
/*!50001 DROP VIEW IF EXISTS `radcheck_kea6mac_v`*/;
SET @saved_cs_client     = @@character_set_client;
SET character_set_client = utf8mb4;
/*!50001 CREATE VIEW `radcheck_kea6mac_v` AS SELECT
 1 AS `id`,
  1 AS `username`,
  1 AS `attribute`,
  1 AS `op`,
  1 AS `value` */;
SET character_set_client = @saved_cs_client;
DROP TABLE IF EXISTS `radcheck_lineid_v`;
/*!50001 DROP VIEW IF EXISTS `radcheck_lineid_v`*/;
SET @saved_cs_client     = @@character_set_client;
SET character_set_client = utf8mb4;
/*!50001 CREATE VIEW `radcheck_lineid_v` AS SELECT
 1 AS `id`,
  1 AS `username`,
  1 AS `attribute`,
  1 AS `op`,
  1 AS `value` */;
SET character_set_client = @saved_cs_client;
DROP TABLE IF EXISTS `radcheck_pppoe_v`;
/*!50001 DROP VIEW IF EXISTS `radcheck_pppoe_v`*/;
SET @saved_cs_client     = @@character_set_client;
SET character_set_client = utf8mb4;
/*!50001 CREATE VIEW `radcheck_pppoe_v` AS SELECT
 1 AS `id`,
  1 AS `username`,
  1 AS `attribute`,
  1 AS `op`,
  1 AS `value` */;
SET character_set_client = @saved_cs_client;
DROP TABLE IF EXISTS `radcheck_v`;
/*!50001 DROP VIEW IF EXISTS `radcheck_v`*/;
SET @saved_cs_client     = @@character_set_client;
SET character_set_client = utf8mb4;
/*!50001 CREATE VIEW `radcheck_v` AS SELECT
 1 AS `id`,
  1 AS `username`,
  1 AS `attribute`,
  1 AS `op`,
  1 AS `value` */;
SET character_set_client = @saved_cs_client;
DROP TABLE IF EXISTS `radreply_kea4_v`;
/*!50001 DROP VIEW IF EXISTS `radreply_kea4_v`*/;
SET @saved_cs_client     = @@character_set_client;
SET character_set_client = utf8mb4;
/*!50001 CREATE VIEW `radreply_kea4_v` AS SELECT
 1 AS `id`,
  1 AS `username`,
  1 AS `attribute`,
  1 AS `op`,
  1 AS `value` */;
SET character_set_client = @saved_cs_client;
DROP TABLE IF EXISTS `radreply_kea6mac_v`;
/*!50001 DROP VIEW IF EXISTS `radreply_kea6mac_v`*/;
SET @saved_cs_client     = @@character_set_client;
SET character_set_client = utf8mb4;
/*!50001 CREATE VIEW `radreply_kea6mac_v` AS SELECT
 1 AS `id`,
  1 AS `username`,
  1 AS `attribute`,
  1 AS `op`,
  1 AS `value` */;
SET character_set_client = @saved_cs_client;
DROP TABLE IF EXISTS `radreply_lineid_v`;
/*!50001 DROP VIEW IF EXISTS `radreply_lineid_v`*/;
SET @saved_cs_client     = @@character_set_client;
SET character_set_client = utf8mb4;
/*!50001 CREATE VIEW `radreply_lineid_v` AS SELECT
 1 AS `id`,
  1 AS `username`,
  1 AS `attribute`,
  1 AS `op`,
  1 AS `value` */;
SET character_set_client = @saved_cs_client;
DROP TABLE IF EXISTS `radreply_pppoe_v`;
/*!50001 DROP VIEW IF EXISTS `radreply_pppoe_v`*/;
SET @saved_cs_client     = @@character_set_client;
SET character_set_client = utf8mb4;
/*!50001 CREATE VIEW `radreply_pppoe_v` AS SELECT
 1 AS `id`,
  1 AS `username`,
  1 AS `attribute`,
  1 AS `op`,
  1 AS `value` */;
SET character_set_client = @saved_cs_client;
DROP TABLE IF EXISTS `radreply_v`;
/*!50001 DROP VIEW IF EXISTS `radreply_v`*/;
SET @saved_cs_client     = @@character_set_client;
SET character_set_client = utf8mb4;
/*!50001 CREATE VIEW `radreply_v` AS SELECT
 1 AS `id`,
  1 AS `username`,
  1 AS `attribute`,
  1 AS `op`,
  1 AS `value` */;
SET character_set_client = @saved_cs_client;
DROP TABLE IF EXISTS `requests`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `requests` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `user_id` int(11) NOT NULL,
  `approval_template_id` int(11) DEFAULT NULL,
  `type` smallint(6) NOT NULL DEFAULT 0,
  `description` mediumtext DEFAULT NULL,
  `suggest_amount` int(11) NOT NULL COMMENT 'suggest amount by user',
  `date` date NOT NULL,
  `state` tinyint(1) NOT NULL,
  `comments_thread_id` int(11) DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `user_id` (`user_id`),
  KEY `comments_thread_id` (`comments_thread_id`),
  KEY `approval_template_id` (`approval_template_id`),
  CONSTRAINT `requests_ibfk_1` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE,
  CONSTRAINT `requests_ibfk_2` FOREIGN KEY (`approval_template_id`) REFERENCES `approval_templates` (`id`) ON DELETE SET NULL,
  CONSTRAINT `requests_ibfk_3` FOREIGN KEY (`comments_thread_id`) REFERENCES `comments` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb3 COLLATE=utf8mb3_czech_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `saved_filters`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `saved_filters` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `user_id` bigint(20) unsigned NOT NULL,
  `name` varchar(100) NOT NULL,
  `page` varchar(50) NOT NULL,
  `filters` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin NOT NULL CHECK (json_valid(`filters`)),
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `sessions`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `sessions` (
  `id` varchar(255) NOT NULL,
  `user_id` bigint(20) unsigned DEFAULT NULL,
  `ip_address` varchar(45) DEFAULT NULL,
  `user_agent` text DEFAULT NULL,
  `payload` longtext NOT NULL,
  `last_activity` int(11) NOT NULL,
  PRIMARY KEY (`id`),
  KEY `sessions_user_id_index` (`user_id`),
  KEY `sessions_last_activity_index` (`last_activity`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `sms_messages`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `sms_messages` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `user_id` int(11) NOT NULL,
  `sms_message_id` int(11) DEFAULT NULL,
  `stamp` datetime NOT NULL,
  `send_date` datetime NOT NULL,
  `text` varchar(800) NOT NULL,
  `sender` varchar(14) NOT NULL,
  `receiver` varchar(14) NOT NULL,
  `driver` tinyint(4) NOT NULL,
  `type` tinyint(4) NOT NULL,
  `state` tinyint(4) NOT NULL,
  `message` varchar(100) DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `user_id` (`user_id`),
  KEY `sms_message_id` (`sms_message_id`),
  KEY `sender` (`sender`),
  KEY `receiver` (`receiver`),
  CONSTRAINT `sms_messages_ibfk_1` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE,
  CONSTRAINT `sms_messages_ibfk_2` FOREIGN KEY (`sms_message_id`) REFERENCES `sms_messages` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb3 COLLATE=utf8mb3_czech_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `smtp_exceptions`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `smtp_exceptions` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `intip` text CHARACTER SET latin1 COLLATE latin1_swedish_ci NOT NULL,
  `user` varchar(255) CHARACTER SET latin1 COLLATE latin1_swedish_ci NOT NULL,
  `datum` date NOT NULL,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `speed_classes`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `speed_classes` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `name` varchar(50) NOT NULL,
  `d_ceil` bigint(11) NOT NULL COMMENT 'QoS download ceil in bytes',
  `d_rate` bigint(11) NOT NULL COMMENT 'QoS download rate in bytes',
  `u_ceil` bigint(11) NOT NULL COMMENT 'QoS upload ceil in bytes',
  `u_rate` bigint(11) NOT NULL COMMENT 'QoS upload rate in bytes',
  `price` decimal(10,2) DEFAULT NULL,
  `regular_member_default` tinyint(1) NOT NULL DEFAULT 0 COMMENT 'Is this class default for regular members?',
  `applicant_default` tinyint(1) NOT NULL DEFAULT 0 COMMENT 'Is this class default for applicants?',
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb3 COLLATE=utf8mb3_czech_ci COMMENT='Defines speed classes for QoS';
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `streets`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `streets` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `town_id` int(11) DEFAULT NULL,
  `street` varchar(30) NOT NULL,
  `gps` polygon DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `street` (`street`),
  KEY `town_id` (`town_id`),
  CONSTRAINT `streets_ibfk_1` FOREIGN KEY (`town_id`) REFERENCES `towns` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb3 COLLATE=utf8mb3_czech_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `subnets`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `subnets` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `OSPF_area_id` int(11) DEFAULT NULL,
  `name` varchar(254) DEFAULT NULL,
  `network_address` varchar(15) DEFAULT NULL,
  `netmask` varchar(15) DEFAULT NULL,
  `redirect` tinyint(4) DEFAULT NULL COMMENT 'Bit mask for types of redirect',
  `dhcp` tinyint(1) NOT NULL DEFAULT 0,
  `dhcp_expired` tinyint(1) NOT NULL DEFAULT 0 COMMENT 'If DHCP is enabled on this subnet, this value indicates if any of its record was updated and not synchronized to DHCP server.',
  `dhcp_changed_at` datetime(6) DEFAULT NULL,
  `dns` tinyint(1) NOT NULL DEFAULT 0,
  `ipoe` tinyint(1) NOT NULL DEFAULT 0,
  `qos` tinyint(1) NOT NULL DEFAULT 0,
  PRIMARY KEY (`id`),
  KEY `name` (`name`),
  KEY `network_address` (`network_address`),
  KEY `netmask` (`netmask`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb3 COLLATE=utf8mb3_czech_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `subnets_owners`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `subnets_owners` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `subnet_id` int(11) NOT NULL,
  `member_id` int(11) NOT NULL,
  `redirect` tinyint(4) NOT NULL DEFAULT 0,
  PRIMARY KEY (`id`),
  KEY `redirect` (`redirect`),
  KEY `subnet_id` (`subnet_id`),
  KEY `member_id` (`member_id`),
  CONSTRAINT `subnets_owners_ibfk_1` FOREIGN KEY (`subnet_id`) REFERENCES `subnets` (`id`) ON DELETE CASCADE,
  CONSTRAINT `subnets_owners_ibfk_2` FOREIGN KEY (`member_id`) REFERENCES `members` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb3 COLLATE=utf8mb3_czech_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `towns`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `towns` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `zip_code` varchar(10) NOT NULL,
  `town` varchar(50) NOT NULL,
  `quarter` varchar(50) DEFAULT NULL,
  `gps` polygon DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `town` (`town`),
  KEY `zip_code` (`zip_code`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb3 COLLATE=utf8mb3_czech_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `transfers`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `transfers` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `origin_id` int(11) DEFAULT NULL,
  `destination_id` int(11) DEFAULT NULL,
  `previous_transfer_id` int(11) DEFAULT NULL,
  `member_id` int(11) DEFAULT NULL COMMENT 'id of the member, to whom the transaction is assigned',
  `user_id` int(11) DEFAULT NULL COMMENT 'id of user, who added transfer',
  `type` tinyint(4) DEFAULT NULL,
  `datetime` datetime NOT NULL,
  `creation_datetime` datetime NOT NULL,
  `text` varchar(254) DEFAULT NULL,
  `amount` double NOT NULL,
  PRIMARY KEY (`id`),
  KEY `member_id` (`member_id`),
  KEY `datetime` (`datetime`,`text`),
  KEY `origin_id` (`origin_id`),
  KEY `destination_id` (`destination_id`),
  KEY `user_id` (`user_id`),
  CONSTRAINT `transfers_ibfk_1` FOREIGN KEY (`origin_id`) REFERENCES `accounts` (`id`) ON DELETE SET NULL,
  CONSTRAINT `transfers_ibfk_2` FOREIGN KEY (`destination_id`) REFERENCES `accounts` (`id`) ON DELETE SET NULL,
  CONSTRAINT `transfers_ibfk_3` FOREIGN KEY (`member_id`) REFERENCES `members` (`id`) ON DELETE SET NULL,
  CONSTRAINT `transfers_ibfk_4` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb3 COLLATE=utf8mb3_czech_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `translations`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `translations` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `original_term` varchar(254) NOT NULL,
  `translated_term` varchar(254) NOT NULL,
  `lang` varchar(50) NOT NULL,
  PRIMARY KEY (`id`),
  KEY `original_term` (`original_term`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb3 COLLATE=utf8mb3_czech_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `ulog2_ct`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `ulog2_ct` (
  `_ct_id` tinyint(4) DEFAULT NULL,
  `orig_ip_saddr` int(10) unsigned DEFAULT NULL,
  `orig_ip_daddr` int(10) unsigned DEFAULT NULL,
  `orig_ip_protocol` tinyint(3) unsigned DEFAULT NULL,
  `orig_l4_sport` smallint(5) unsigned DEFAULT NULL,
  `orig_l4_dport` smallint(5) unsigned DEFAULT NULL,
  `orig_raw_pktlen` bigint(20) DEFAULT 0,
  `orig_raw_pktcount` int(10) unsigned DEFAULT 0,
  `reply_ip_daddr` int(10) unsigned DEFAULT NULL,
  `reply_l4_dport` smallint(5) unsigned DEFAULT NULL,
  `reply_raw_pktlen` bigint(20) DEFAULT 0,
  `reply_raw_pktcount` int(10) unsigned DEFAULT 0,
  `icmp_code` tinyint(3) DEFAULT NULL,
  `icmp_type` tinyint(3) DEFAULT NULL,
  `flow_start_sec` int(10) DEFAULT 0,
  `flow_end_sec` int(10) DEFAULT 0,
  KEY `ct_tuple` (`flow_start_sec`,`orig_ip_daddr`,`orig_l4_dport`,`reply_l4_dport`)
) ENGINE=MyISAM DEFAULT CHARSET=latin1 COLLATE=latin1_swedish_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `user_favourite_pages`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `user_favourite_pages` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `user_id` int(30) NOT NULL,
  `title` varchar(50) NOT NULL,
  `page` varchar(255) NOT NULL,
  `default_page` tinyint(1) DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `user_id` (`user_id`),
  CONSTRAINT `user_favourite_pages_ibfk_1` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb3 COLLATE=utf8mb3_czech_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `user_login_locks`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `user_login_locks` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `user_id` int(11) NOT NULL,
  `failed_count` int(10) unsigned NOT NULL DEFAULT 0,
  `locked_until` timestamp NULL DEFAULT NULL,
  `last_failed_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `user_login_locks_user_id_unique` (`user_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `user_mfa`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `user_mfa` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `user_id` int(11) NOT NULL,
  `totp_secret` text NOT NULL,
  `confirmed_at` timestamp NULL DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `user_mfa_user_id_unique` (`user_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `users`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `users` (
  `member_id` int(11) NOT NULL,
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `login` varchar(50) DEFAULT NULL,
  `password` varchar(255) NOT NULL,
  `password_request` varchar(64) DEFAULT NULL,
  `password_request_expires_at` datetime DEFAULT NULL,
  `name` varchar(60) DEFAULT NULL,
  `middle_name` varchar(30) DEFAULT NULL,
  `surname` varchar(60) DEFAULT NULL,
  `pre_title` varchar(40) DEFAULT NULL,
  `post_title` varchar(30) DEFAULT NULL,
  `birthday` date DEFAULT NULL,
  `type` tinyint(4) NOT NULL,
  `comment` varchar(250) DEFAULT NULL,
  `application_password` text NOT NULL,
  `settings` text NOT NULL,
  `dark_mode` tinyint(4) NOT NULL DEFAULT 0,
  PRIMARY KEY (`id`),
  UNIQUE KEY `login` (`login`),
  KEY `name` (`name`),
  KEY `surname` (`surname`),
  KEY `member_id` (`member_id`),
  CONSTRAINT `users_ibfk_1` FOREIGN KEY (`member_id`) REFERENCES `members` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb3 COLLATE=utf8mb3_czech_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `users_contacts`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `users_contacts` (
  `user_id` int(10) NOT NULL,
  `contact_id` int(10) NOT NULL,
  `mail_redirection` tinyint(1) NOT NULL DEFAULT 0 COMMENT 'In condition that this contact is an e-mail, this indicator specifies whether the inner system mail is redirected to this user''s e-mail box.',
  PRIMARY KEY (`user_id`,`contact_id`),
  KEY `users_contacts_ibfk_2` (`contact_id`),
  KEY `user_id` (`user_id`),
  CONSTRAINT `users_contacts_ibfk_1` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE,
  CONSTRAINT `users_contacts_ibfk_2` FOREIGN KEY (`contact_id`) REFERENCES `contacts` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb3 COLLATE=utf8mb3_czech_ci COMMENT='Pivot table';
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `users_keys`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `users_keys` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `user_id` int(11) NOT NULL,
  `key` text NOT NULL,
  PRIMARY KEY (`id`),
  KEY `user_id` (`user_id`),
  CONSTRAINT `users_keys_ibfk_1` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb3 COLLATE=utf8mb3_czech_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `users_to_members`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `users_to_members` (
  `userid` int(11) NOT NULL,
  `member_id` int(11) NOT NULL,
  PRIMARY KEY (`userid`,`member_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb3 COLLATE=utf8mb3_czech_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `variable_symbols`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `variable_symbols` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `account_id` int(11) NOT NULL,
  `variable_symbol` varchar(10) NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `variable_symbol` (`variable_symbol`),
  KEY `account_id` (`account_id`),
  CONSTRAINT `variable_symbols_ibfk_1` FOREIGN KEY (`account_id`) REFERENCES `accounts` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb3 COLLATE=utf8mb3_czech_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `vlans`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `vlans` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `name` varchar(254) DEFAULT NULL,
  `tag_802_1q` int(11) DEFAULT NULL,
  `comment` varchar(254) DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `tag_802_1q` (`tag_802_1q`),
  KEY `name` (`name`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb3 COLLATE=utf8mb3_czech_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `voip_sips`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `voip_sips` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `user_id` int(11) NOT NULL,
  `name` varchar(80) NOT NULL,
  `accountcode` varchar(20) DEFAULT NULL,
  `amaflags` varchar(13) DEFAULT NULL,
  `callgroup` varchar(10) DEFAULT NULL,
  `callerid` varchar(80) NOT NULL,
  `canreinvite` char(3) DEFAULT 'no',
  `context` varchar(80) DEFAULT 'internal',
  `defaultip` varchar(15) DEFAULT NULL,
  `dtmfmode` varchar(7) DEFAULT NULL,
  `fromuser` varchar(80) DEFAULT NULL,
  `fromdomain` varchar(80) DEFAULT NULL,
  `fullcontact` varchar(80) DEFAULT NULL,
  `host` varchar(31) NOT NULL DEFAULT 'dynamic',
  `insecure` varchar(4) DEFAULT NULL,
  `language` char(2) DEFAULT 'cz',
  `mailbox` varchar(50) NOT NULL,
  `md5secret` varchar(80) DEFAULT NULL,
  `nat` varchar(5) NOT NULL DEFAULT 'yes',
  `deny` varchar(95) DEFAULT NULL,
  `permit` varchar(95) DEFAULT NULL,
  `mask` varchar(95) DEFAULT NULL,
  `pickupgroup` varchar(10) DEFAULT NULL,
  `port` varchar(5) DEFAULT NULL,
  `qualify` char(3) DEFAULT NULL,
  `restrictcid` char(1) DEFAULT NULL,
  `rtptimeout` char(3) DEFAULT NULL,
  `rtpholdtimeout` char(3) DEFAULT NULL,
  `secret` varchar(80) NOT NULL,
  `type` varchar(6) NOT NULL DEFAULT 'friend',
  `username` varchar(80) NOT NULL,
  `disallow` varchar(100) DEFAULT NULL,
  `allow` varchar(100) DEFAULT NULL,
  `musiconhold` varchar(100) DEFAULT NULL,
  `regseconds` int(11) NOT NULL DEFAULT 0,
  `ipaddr` varchar(15) NOT NULL,
  `regexten` varchar(80) NOT NULL,
  `cancallforward` char(3) DEFAULT 'yes',
  `setvar` varchar(100) NOT NULL,
  `auth` varchar(10) DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `name` (`name`),
  KEY `user_id` (`user_id`),
  CONSTRAINT `voip_sips_ibfk_1` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb3 COLLATE=utf8mb3_czech_ci ROW_FORMAT=DYNAMIC;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `voip_voicemail_users`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `voip_voicemail_users` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `customer_id` int(11) NOT NULL,
  `active` tinyint(4) NOT NULL DEFAULT 1,
  `context` varchar(50) NOT NULL,
  `mailbox` varchar(10) NOT NULL DEFAULT '0',
  `password` varchar(4) NOT NULL DEFAULT '0',
  `fullname` varchar(50) NOT NULL,
  `email` varchar(50) NOT NULL,
  `pager` varchar(50) NOT NULL,
  `stamp` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `customer_id` (`customer_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb3 COLLATE=utf8mb3_czech_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `votes`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `votes` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `user_id` int(11) NOT NULL COMMENT 'id of voter',
  `type` int(11) NOT NULL COMMENT 'type of vote',
  `fk_id` int(11) NOT NULL COMMENT 'id of foreign key',
  `aro_group_id` int(11) NOT NULL,
  `priority` int(11) NOT NULL,
  `vote` tinyint(1) DEFAULT NULL COMMENT 'value of user vote',
  `time` datetime NOT NULL,
  `comment` mediumtext NOT NULL,
  PRIMARY KEY (`id`),
  KEY `user_id` (`user_id`),
  KEY `fk_id` (`fk_id`),
  KEY `aro_group_id` (`aro_group_id`),
  CONSTRAINT `votes_ibfk_1` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`),
  CONSTRAINT `votes_ibfk_3` FOREIGN KEY (`aro_group_id`) REFERENCES `aro_groups` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb3 COLLATE=utf8mb3_czech_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `watchers`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `watchers` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `user_id` int(11) NOT NULL,
  `type` int(11) NOT NULL,
  `fk_id` int(11) NOT NULL,
  PRIMARY KEY (`id`),
  KEY `user_id` (`user_id`,`type`,`fk_id`),
  CONSTRAINT `watchers_ibfk_1` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb3 COLLATE=utf8mb3_czech_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `webauthn_credentials`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `webauthn_credentials` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `user_id` int(11) NOT NULL,
  `credential_id` varchar(255) NOT NULL,
  `public_key` text NOT NULL,
  `sign_count` bigint(20) unsigned NOT NULL DEFAULT 0,
  `device_name` varchar(100) DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `last_used_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `webauthn_credentials_credential_id_unique` (`credential_id`),
  KEY `webauthn_credentials_user_id_index` (`user_id`),
  CONSTRAINT `webauthn_credentials_user_id_foreign` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!50001 DROP VIEW IF EXISTS `radcheck_kea4_v`*/;
/*!50001 SET @saved_cs_client          = @@character_set_client */;
/*!50001 SET @saved_cs_results         = @@character_set_results */;
/*!50001 SET @saved_col_connection     = @@collation_connection */;
/*!50001 SET character_set_client      = utf8mb4 */;
/*!50001 SET character_set_results     = utf8mb4 */;
/*!50001 SET collation_connection      = utf8mb4_uca1400_ai_ci */;
/*!50001 CREATE ALGORITHM=UNDEFINED */

/*!50001 VIEW `radcheck_kea4_v` AS select min(`l`.`id`) AS `id`,concat(`l`.`remote_id`,`l`.`circuit_id`) AS `username`,'Auth-Type' AS `attribute`,':=' AS `op`,'Accept' AS `value` from ((`line_ids` `l` join `ip_addresses` `ip` on(`ip`.`iface_id` = `l`.`iface_id` and `ip`.`gateway` = 0 and `ip`.`ip_address` like '10.%')) join `subnets` `s` on(`s`.`id` = `ip`.`subnet_id` and `s`.`dhcp` = 1 and `s`.`ipoe` = 1)) group by concat(`l`.`remote_id`,`l`.`circuit_id`) */;
/*!50001 SET character_set_client      = @saved_cs_client */;
/*!50001 SET character_set_results     = @saved_cs_results */;
/*!50001 SET collation_connection      = @saved_col_connection */;
/*!50001 DROP VIEW IF EXISTS `radcheck_kea6mac_v`*/;
/*!50001 SET @saved_cs_client          = @@character_set_client */;
/*!50001 SET @saved_cs_results         = @@character_set_results */;
/*!50001 SET @saved_col_connection     = @@collation_connection */;
/*!50001 SET character_set_client      = utf8mb4 */;
/*!50001 SET character_set_results     = utf8mb4 */;
/*!50001 SET collation_connection      = utf8mb4_uca1400_ai_ci */;
/*!50001 CREATE ALGORITHM=UNDEFINED */

/*!50001 VIEW `radcheck_kea6mac_v` AS select min(`ip6`.`id`) AS `id`,replace(ucase(`i`.`mac`),':','') AS `username`,'Auth-Type' AS `attribute`,':=' AS `op`,'Accept' AS `value` from (`ifaces` `i` join `ip6_addresses` `ip6` on(`ip6`.`iface_id` = `i`.`id`)) where `i`.`mac` is not null and `i`.`mac` <> '' group by replace(ucase(`i`.`mac`),':','') having count(distinct `ip6`.`ip_address`) = 1 */;
/*!50001 SET character_set_client      = @saved_cs_client */;
/*!50001 SET character_set_results     = @saved_cs_results */;
/*!50001 SET collation_connection      = @saved_col_connection */;
/*!50001 DROP VIEW IF EXISTS `radcheck_lineid_v`*/;
/*!50001 SET @saved_cs_client          = @@character_set_client */;
/*!50001 SET @saved_cs_results         = @@character_set_results */;
/*!50001 SET @saved_col_connection     = @@collation_connection */;
/*!50001 SET character_set_client      = utf8mb4 */;
/*!50001 SET character_set_results     = utf8mb4 */;
/*!50001 SET collation_connection      = utf8mb4_unicode_ci */;
/*!50001 CREATE ALGORITHM=UNDEFINED */

/*!50001 VIEW `radcheck_lineid_v` AS select min(`l`.`id`) AS `id`,`l`.`circuit_id` AS `username`,'Auth-Type' AS `attribute`,':=' AS `op`,'Accept' AS `value` from ((`line_ids` `l` join `ip_addresses` `ip` on(`ip`.`iface_id` = `l`.`iface_id` and `ip`.`gateway` = 0 and `ip`.`ip_address` like '10.%')) join `subnets` `s` on(`s`.`id` = `ip`.`subnet_id` and `s`.`dhcp` = 1 and `s`.`ipoe` = 1)) group by `l`.`circuit_id` */;
/*!50001 SET character_set_client      = @saved_cs_client */;
/*!50001 SET character_set_results     = @saved_cs_results */;
/*!50001 SET collation_connection      = @saved_col_connection */;
/*!50001 DROP VIEW IF EXISTS `radcheck_pppoe_v`*/;
/*!50001 SET @saved_cs_client          = @@character_set_client */;
/*!50001 SET @saved_cs_results         = @@character_set_results */;
/*!50001 SET @saved_col_connection     = @@collation_connection */;
/*!50001 SET character_set_client      = utf8mb4 */;
/*!50001 SET character_set_results     = utf8mb4 */;
/*!50001 SET collation_connection      = utf8mb4_unicode_ci */;
/*!50001 CREATE ALGORITHM=UNDEFINED */

/*!50001 VIEW `radcheck_pppoe_v` AS select `p`.`iface_id` AS `id`,`p`.`username` AS `username`,'Cleartext-Password' AS `attribute`,':=' AS `op`,`p`.`secret` AS `value` from `pppoe_secrets` `p` where `p`.`enabled` = 1 union all select -`cr`.`id` AS `id`,`cr`.`pppoe_username` AS `username`,'Cleartext-Password' AS `attribute`,':=' AS `op`,`cr`.`pppoe_secret` AS `value` from `connection_requests` `cr` where `cr`.`state` = 0 and `cr`.`pppoe_username` is not null and `cr`.`pppoe_secret` is not null */;
/*!50001 SET character_set_client      = @saved_cs_client */;
/*!50001 SET character_set_results     = @saved_cs_results */;
/*!50001 SET collation_connection      = @saved_col_connection */;
/*!50001 DROP VIEW IF EXISTS `radcheck_v`*/;
/*!50001 SET @saved_cs_client          = @@character_set_client */;
/*!50001 SET @saved_cs_results         = @@character_set_results */;
/*!50001 SET @saved_col_connection     = @@collation_connection */;
/*!50001 SET character_set_client      = utf8mb4 */;
/*!50001 SET character_set_results     = utf8mb4 */;
/*!50001 SET collation_connection      = utf8mb4_uca1400_ai_ci */;
/*!50001 CREATE ALGORITHM=UNDEFINED */

/*!50001 VIEW `radcheck_v` AS select min(`ip6`.`id`) AS `id`,ucase(`i`.`mac`) AS `username`,'Auth-Type' AS `attribute`,':=' AS `op`,'Accept' AS `value` from (`ifaces` `i` join `ip6_addresses` `ip6` on(`ip6`.`iface_id` = `i`.`id`)) where `i`.`mac` is not null group by ucase(`i`.`mac`) */;
/*!50001 SET character_set_client      = @saved_cs_client */;
/*!50001 SET character_set_results     = @saved_cs_results */;
/*!50001 SET collation_connection      = @saved_col_connection */;
/*!50001 DROP VIEW IF EXISTS `radreply_kea4_v`*/;
/*!50001 SET @saved_cs_client          = @@character_set_client */;
/*!50001 SET @saved_cs_results         = @@character_set_results */;
/*!50001 SET @saved_col_connection     = @@collation_connection */;
/*!50001 SET character_set_client      = utf8mb4 */;
/*!50001 SET character_set_results     = utf8mb4 */;
/*!50001 SET collation_connection      = utf8mb4_uca1400_ai_ci */;
/*!50001 CREATE ALGORITHM=UNDEFINED */

/*!50001 VIEW `radreply_kea4_v` AS select `l`.`id` AS `id`,concat(`l`.`remote_id`,`l`.`circuit_id`) AS `username`,'Framed-IP-Address' AS `attribute`,'=' AS `op`,`ip`.`ip_address` AS `value` from ((`line_ids` `l` join `ip_addresses` `ip` on(`ip`.`iface_id` = `l`.`iface_id` and `ip`.`gateway` = 0 and `ip`.`ip_address` like '10.%')) join `subnets` `s` on(`s`.`id` = `ip`.`subnet_id` and `s`.`dhcp` = 1 and `s`.`ipoe` = 1)) */;
/*!50001 SET character_set_client      = @saved_cs_client */;
/*!50001 SET character_set_results     = @saved_cs_results */;
/*!50001 SET collation_connection      = @saved_col_connection */;
/*!50001 DROP VIEW IF EXISTS `radreply_kea6mac_v`*/;
/*!50001 SET @saved_cs_client          = @@character_set_client */;
/*!50001 SET @saved_cs_results         = @@character_set_results */;
/*!50001 SET @saved_col_connection     = @@collation_connection */;
/*!50001 SET character_set_client      = utf8mb4 */;
/*!50001 SET character_set_results     = utf8mb4 */;
/*!50001 SET collation_connection      = utf8mb4_uca1400_ai_ci */;
/*!50001 CREATE ALGORITHM=UNDEFINED */

/*!50001 VIEW `radreply_kea6mac_v` AS select min(`ip6`.`id`) AS `id`,replace(ucase(`i`.`mac`),':','') AS `username`,'Delegated-IPv6-Prefix' AS `attribute`,'=' AS `op`,min(`ip6`.`ip_address`) AS `value` from (`ifaces` `i` join `ip6_addresses` `ip6` on(`ip6`.`iface_id` = `i`.`id`)) where `i`.`mac` is not null and `i`.`mac` <> '' group by replace(ucase(`i`.`mac`),':','') having count(distinct `ip6`.`ip_address`) = 1 */;
/*!50001 SET character_set_client      = @saved_cs_client */;
/*!50001 SET character_set_results     = @saved_cs_results */;
/*!50001 SET collation_connection      = @saved_col_connection */;
/*!50001 DROP VIEW IF EXISTS `radreply_lineid_v`*/;
/*!50001 SET @saved_cs_client          = @@character_set_client */;
/*!50001 SET @saved_cs_results         = @@character_set_results */;
/*!50001 SET @saved_col_connection     = @@collation_connection */;
/*!50001 SET character_set_client      = utf8mb4 */;
/*!50001 SET character_set_results     = utf8mb4 */;
/*!50001 SET collation_connection      = utf8mb4_unicode_ci */;
/*!50001 CREATE ALGORITHM=UNDEFINED */

/*!50001 VIEW `radreply_lineid_v` AS select `l`.`id` AS `id`,`l`.`circuit_id` AS `username`,'Framed-IP-Address' AS `attribute`,'=' AS `op`,`ip`.`ip_address` AS `value` from ((`line_ids` `l` join `ip_addresses` `ip` on(`ip`.`iface_id` = `l`.`iface_id` and `ip`.`gateway` = 0 and `ip`.`ip_address` like '10.%')) join `subnets` `s` on(`s`.`id` = `ip`.`subnet_id` and `s`.`dhcp` = 1 and `s`.`ipoe` = 1)) */;
/*!50001 SET character_set_client      = @saved_cs_client */;
/*!50001 SET character_set_results     = @saved_cs_results */;
/*!50001 SET collation_connection      = @saved_col_connection */;
/*!50001 DROP VIEW IF EXISTS `radreply_pppoe_v`*/;
/*!50001 SET @saved_cs_client          = @@character_set_client */;
/*!50001 SET @saved_cs_results         = @@character_set_results */;
/*!50001 SET @saved_col_connection     = @@collation_connection */;
/*!50001 SET character_set_client      = utf8mb4 */;
/*!50001 SET character_set_results     = utf8mb4 */;
/*!50001 SET collation_connection      = utf8mb4_unicode_ci */;
/*!50001 CREATE ALGORITHM=UNDEFINED */

/*!50001 VIEW `radreply_pppoe_v` AS select `p`.`iface_id` * 10 + 1 AS `id`,`p`.`username` AS `username`,'Framed-IP-Address' AS `attribute`,'=' AS `op`,`ip`.`ip_address` AS `value` from (`pppoe_secrets` `p` join `ip_addresses` `ip` on(`ip`.`iface_id` = `p`.`iface_id` and `ip`.`gateway` = 0 and `ip`.`ip_address` like '10.%')) where `p`.`enabled` = 1 union all select `p`.`iface_id` * 10 + 2 AS `id`,`p`.`username` AS `username`,'Delegated-IPv6-Prefix' AS `attribute`,'=' AS `op`,`ip6`.`ip_address` AS `value` from (`pppoe_secrets` `p` join `ip6_addresses` `ip6` on(`ip6`.`iface_id` = `p`.`iface_id` and `ip6`.`ip_address` like '%/%')) where `p`.`enabled` = 1 union all select -`cr`.`id` AS `id`,`cr`.`pppoe_username` AS `username`,'Framed-IP-Address' AS `attribute`,'=' AS `op`,`cr`.`ip_address` AS `value` from `connection_requests` `cr` where `cr`.`state` = 0 and `cr`.`pppoe_username` is not null and `cr`.`ip_address` is not null and `cr`.`ip_address` like '10.%' */;
/*!50001 SET character_set_client      = @saved_cs_client */;
/*!50001 SET character_set_results     = @saved_cs_results */;
/*!50001 SET collation_connection      = @saved_col_connection */;
/*!50001 DROP VIEW IF EXISTS `radreply_v`*/;
/*!50001 SET @saved_cs_client          = @@character_set_client */;
/*!50001 SET @saved_cs_results         = @@character_set_results */;
/*!50001 SET @saved_col_connection     = @@collation_connection */;
/*!50001 SET character_set_client      = utf8mb4 */;
/*!50001 SET character_set_results     = utf8mb4 */;
/*!50001 SET collation_connection      = utf8mb4_uca1400_ai_ci */;
/*!50001 CREATE ALGORITHM=UNDEFINED */

/*!50001 VIEW `radreply_v` AS select `ip6`.`id` AS `id`,ucase(`i`.`mac`) AS `username`,'Delegated-IPv6-Prefix' AS `attribute`,'=' AS `op`,`ip6`.`ip_address` AS `value` from (`ifaces` `i` join `ip6_addresses` `ip6` on(`ip6`.`iface_id` = `i`.`id`)) where `i`.`mac` is not null */;
/*!50001 SET character_set_client      = @saved_cs_client */;
/*!50001 SET character_set_results     = @saved_cs_results */;
/*!50001 SET collation_connection      = @saved_col_connection */;
/*!40103 SET TIME_ZONE=@OLD_TIME_ZONE */;

/*!40101 SET SQL_MODE=@OLD_SQL_MODE */;
/*!40014 SET FOREIGN_KEY_CHECKS=@OLD_FOREIGN_KEY_CHECKS */;
/*!40014 SET UNIQUE_CHECKS=@OLD_UNIQUE_CHECKS */;
/*M!100616 SET NOTE_VERBOSITY=@OLD_NOTE_VERBOSITY */;

/*M!999999\- enable the sandbox mode */ 
SET @OLD_AUTOCOMMIT=@@AUTOCOMMIT, @@AUTOCOMMIT=0;
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (1,'0001_01_01_000000_create_users_table',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (2,'0001_01_01_000001_create_cache_table',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (3,'0001_01_01_000002_create_jobs_table',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (4,'2026_04_06_082452_add_allowed_subnets_count_to_members',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (5,'2026_04_17_082637_add_dark_mode_to_users_table',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (6,'2026_04_17_093123_create_saved_filters_table',2);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (7,'2026_04_18_000001_create_onts_table',2);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (8,'2026_04_18_145912_fix_gpon_port_length',2);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (9,'2026_04_19_080824_add_gps_to_onts_table',2);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (10,'2026_04_19_085459_create_gpon_olts_table',2);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (11,'2026_04_19_091013_add_geocode_city_to_gpon_olts_table',2);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (12,'2026_04_30_093236_create_smtp_exceptions_table',2);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (13,'2026_05_01_125154_add_password_request_expires_at_to_users',2);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (14,'2026_05_04_120000_add_tv_columns_to_members_table',2);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (15,'2026_05_06_102254_cleanup_enum_types',2);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (16,'2026_05_13_120000_expand_invoices_pdf_filename',3);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (17,'2026_05_13_140000_add_payment_confirmation_messages',4);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (18,'2026_05_15_114928_seed_contract_email_messages',5);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (19,'2026_05_15_115954_fix_phone_contact_type',5);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (20,'2026_05_16_155257_add_debug_acl',6);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (21,'2026_05_18_220000_expand_login_logs_ip_address_for_ipv6',7);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (22,'2026_05_20_120000_expand_password_request_for_token',8);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (23,'2026_05_22_080000_add_unique_doc_number_to_pohoda_refund_queue',9);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (24,'2026_05_22_090000_invoices_invoice_nr_bigint_unique',10);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (25,'2026_05_22_100000_add_pohoda_status_to_invoices',11);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (26,'2026_05_25_120000_add_pending_customer_redirect_message',12);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (27,'2026_05_25_130000_translate_system_messages_to_czech',13);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (28,'2026_06_03_100000_add_payment_blocked_columns_to_members',14);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (29,'2026_06_03_100100_add_payment_blocked_redirect_message',14);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (30,'2026_06_03_140000_cleanup_payment_blocked_message_and_rules',15);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (31,'2026_06_14_120000_create_webauthn_credentials_table',16);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (32,'2026_06_14_140000_encrypt_device_and_gpon_credentials',17);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (33,'2026_07_09_120000_add_inline_to_email_queue_attachments',18);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (34,'2026_07_09_130000_add_payment_qr_to_reminder_messages',18);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (35,'2026_07_15_120000_add_payment_qr_to_debtor_messages',19);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (36,'2026_07_20_120000_add_terminated_to_contract_status',20);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (37,'2026_07_20_130000_cleanup_dangling_draft_contracts',20);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (38,'2026_07_20_140000_terminate_signed_contracts_of_former_members',21);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (39,'2026_07_24_120000_make_connection_request_ip_nullable',22);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (40,'2026_07_27_120000_add_per_client_dhcp_change_detection',23);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (41,'2026_07_30_120000_add_price_to_speed_classes',24);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (42,'2026_07_30_130000_add_discount_to_contract_parties',24);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (43,'2026_07_30_140000_create_contract_addons_table',24);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (44,'2026_07_30_150000_add_tariff_addon_to_download_tokens',24);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (45,'2026_07_30_160000_add_addon_no_to_contract_addons',24);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (46,'2026_07_31_120000_rename_penalty_to_additional_service',25);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (47,'2026_07_31_130000_add_service_fields_to_contract_addons',25);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (48,'2026_07_31_140000_add_services_to_contract_parties',25);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (49,'2026_08_12_120000_make_payment_amount_dynamic_in_reminder',26);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (50,'2026_08_12_130000_delete_canceled_contract_drafts',26);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (51,'2026_08_12_140000_add_archived_to_fees',27);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (52,'2026_08_12_150000_czech_names_for_system_fees',27);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (53,'2026_08_12_160000_backfill_former_members_fee_end_dates',28);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (54,'2026_08_12_170000_delete_fees_assigned_after_leaving',28);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (55,'2026_08_12_180000_add_speed_class_to_allowed_subnets',28);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (56,'2026_08_12_190000_add_billing_to_allowed_subnets',28);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (57,'2026_08_12_200000_add_connection_point_addon',28);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (58,'2026_08_12_210000_drop_fee_override_from_allowed_subnets',28);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (59,'2026_08_12_220000_clear_speed_for_former_members',29);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (60,'2026_08_12_230000_clear_speed_for_regular_members',29);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (61,'2026_08_13_120000_create_audit_logs_table',30);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (62,'2026_08_13_130000_create_mfa_tables',31);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (63,'2026_08_13_140000_hash_password_reset_tokens',32);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (64,'2026_08_13_150000_encrypt_application_password',32);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (65,'2026_08_13_160000_create_user_login_locks',33);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (66,'2026_08_13_170000_create_mfa_required_groups',34);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (67,'2026_08_19_090000_add_new_speed_class_id_to_contract_addons',35);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (68,'2026_08_19_140000_add_fee_id_to_contract_addons',35);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (69,'2026_08_19_170000_add_allowed_subnet_id_to_contract_addons',35);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (70,'2026_08_19_190000_add_wpa_key_to_devices',36);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (71,'2026_08_25_100000_create_pppoe_secrets_table',37);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (72,'2026_08_25_120000_add_pppoe_credential_to_connection_requests',37);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (73,'2026_08_25_130000_create_pppoe_radius_views',37);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (74,'2026_09_02_100000_add_ipoe_to_subnets',38);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (75,'2026_09_02_110000_create_line_ids_and_radius_views',38);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (76,'2026_09_02_120000_create_line_id_seen',38);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (77,'2026_09_02_130000_create_line_id_anomalies',39);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (78,'2026_09_11_120000_create_meetings',40);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (79,'2026_09_29_120000_create_member_lan_access',41);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (80,'2026_10_12_100000_add_remote_id_hex_to_line_id_seen',42);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (81,'2026_10_12_110000_line_ids_unique_circuit_remote',42);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (82,'2026_10_12_120000_line_ids_binary_identity',43);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (83,'2026_10_12_130000_radius_infra_views',43);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (84,'2026_10_12_140000_radacct_station_ids_255',44);
COMMIT;
SET AUTOCOMMIT=@OLD_AUTOCOMMIT;
