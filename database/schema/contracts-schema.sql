/*M!999999\- enable the sandbox mode */ 
/*!40103 SET @OLD_TIME_ZONE=@@TIME_ZONE */;
/*!40103 SET TIME_ZONE='+00:00' */;
/*!40014 SET @OLD_UNIQUE_CHECKS=@@UNIQUE_CHECKS, UNIQUE_CHECKS=0 */;
/*!40014 SET @OLD_FOREIGN_KEY_CHECKS=@@FOREIGN_KEY_CHECKS, FOREIGN_KEY_CHECKS=0 */;
/*!40101 SET @OLD_SQL_MODE=@@SQL_MODE, SQL_MODE='NO_AUTO_VALUE_ON_ZERO' */;
/*M!100616 SET @OLD_NOTE_VERBOSITY=@@NOTE_VERBOSITY, NOTE_VERBOSITY=0 */;
DROP TABLE IF EXISTS `admin_rate_limits`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `admin_rate_limits` (
  `bucket` varchar(191) NOT NULL,
  `window_sec` int(10) unsigned NOT NULL,
  `max_hits` int(10) unsigned NOT NULL,
  `hits` int(10) unsigned NOT NULL DEFAULT 0,
  `window_start` datetime NOT NULL,
  PRIMARY KEY (`bucket`,`window_sec`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_uca1400_ai_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `contract_addon_otps`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `contract_addon_otps` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `contract_id` int(11) NOT NULL,
  `otp_hash` char(64) CHARACTER SET ascii COLLATE ascii_general_ci NOT NULL,
  `attempts_left` tinyint(4) NOT NULL DEFAULT 5,
  `expires_at` datetime NOT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `contract_id` (`contract_id`),
  CONSTRAINT `contract_addon_otps_ibfk_1` FOREIGN KEY (`contract_id`) REFERENCES `contracts` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_czech_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `contract_addons`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `contract_addons` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `contract_id` int(10) unsigned NOT NULL,
  `type` varchar(32) NOT NULL DEFAULT 'tariff_change',
  `addon_no` int(10) unsigned DEFAULT NULL,
  `old_speed_name` varchar(100) DEFAULT NULL,
  `old_price` decimal(10,2) DEFAULT NULL,
  `old_price_after_discount` decimal(10,2) DEFAULT NULL,
  `new_speed_name` varchar(100) DEFAULT NULL,
  `new_price` decimal(10,2) DEFAULT NULL,
  `new_price_after_discount` decimal(10,2) DEFAULT NULL,
  `new_speed_class_id` int(10) unsigned DEFAULT NULL,
  `discount_until` date DEFAULT NULL,
  `service_name` varchar(150) DEFAULT NULL,
  `service_price` decimal(10,2) DEFAULT NULL,
  `service_action` varchar(10) DEFAULT NULL,
  `fee_id` int(10) unsigned DEFAULT NULL,
  `place_speed_name` varchar(100) DEFAULT NULL,
  `allowed_subnet_id` int(10) unsigned DEFAULT NULL,
  `effective_date` date NOT NULL,
  `status` varchar(20) NOT NULL DEFAULT 'draft',
  `pdf_path` varchar(255) DEFAULT NULL,
  `signed_at` datetime DEFAULT NULL,
  `created_by` varchar(64) DEFAULT NULL,
  `created_at` datetime DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `contract_addons_contract_id_index` (`contract_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_czech_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `contract_events`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `contract_events` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `contract_id` int(11) NOT NULL,
  `event` varchar(64) NOT NULL,
  `meta_json` text DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_events_contract` (`contract_id`),
  CONSTRAINT `fk_events_contract` FOREIGN KEY (`contract_id`) REFERENCES `contracts` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_czech_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `contract_otps`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `contract_otps` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `contract_id` int(11) NOT NULL,
  `otp_hash` char(64) CHARACTER SET ascii COLLATE ascii_general_ci NOT NULL,
  `attempts_left` tinyint(4) NOT NULL DEFAULT 5,
  `expires_at` datetime NOT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_otps_contract` (`contract_id`),
  KEY `idx_otps_expires` (`expires_at`),
  CONSTRAINT `fk_otps_contract` FOREIGN KEY (`contract_id`) REFERENCES `contracts` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_czech_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `contract_parties`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `contract_parties` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `contract_id` int(11) NOT NULL,
  `full_name` varchar(255) NOT NULL,
  `street` varchar(255) DEFAULT NULL,
  `town` varchar(100) DEFAULT NULL,
  `service_street` varchar(255) DEFAULT NULL,
  `service_town` varchar(100) DEFAULT NULL,
  `service_zip` varchar(20) DEFAULT NULL,
  `service_full_address` varchar(255) DEFAULT NULL,
  `country` varchar(100) DEFAULT NULL,
  `ico` varchar(32) DEFAULT NULL,
  `dic` varchar(32) DEFAULT NULL,
  `speed_name` varchar(100) DEFAULT NULL,
  `variable_symbol` varchar(32) DEFAULT NULL,
  `price` decimal(10,2) NOT NULL DEFAULT 320.00,
  `price_after_discount` decimal(10,2) DEFAULT NULL,
  `discount_until` date DEFAULT NULL,
  `additional_services_json` text DEFAULT NULL,
  `additional_services_total` decimal(10,2) DEFAULT NULL,
  `phone` varchar(64) DEFAULT NULL,
  `email` varchar(255) DEFAULT NULL,
  `birthday` date DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `active` tinyint(1) NOT NULL DEFAULT 1,
  `termination` enum('ano','ne') NOT NULL DEFAULT 'ne',
  PRIMARY KEY (`id`),
  KEY `idx_cp_contract` (`contract_id`),
  KEY `cp_variable_symbol_idx` (`variable_symbol`),
  CONSTRAINT `fk_cp_contract` FOREIGN KEY (`contract_id`) REFERENCES `contracts` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_czech_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `contracts`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `contracts` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `member_id` int(11) NOT NULL,
  `contract_no` varchar(64) NOT NULL,
  `status` enum('draft','otp_sent','otp_verified','signed','canceled','terminated') NOT NULL DEFAULT 'draft',
  `addon` tinyint(1) NOT NULL DEFAULT 0,
  `addon_signed` tinyint(1) NOT NULL DEFAULT 0,
  `pdf_path` varchar(255) DEFAULT NULL,
  `pdf_sha256` varbinary(32) DEFAULT NULL,
  `signature_path` varchar(255) DEFAULT NULL,
  `phone` varchar(32) DEFAULT NULL,
  `signed_at` datetime DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `addon_pdf_path` varchar(255) DEFAULT NULL,
  `addon_signed_at` datetime DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_contract_no` (`contract_no`),
  KEY `idx_contract_member` (`member_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_czech_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `download_tokens`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `download_tokens` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `token` char(64) NOT NULL,
  `contract_id` bigint(20) unsigned NOT NULL,
  `addon_id` int(10) unsigned DEFAULT NULL,
  `file_type` enum('contract','termination','addon','tariff_addon','service_addon','connection_point_addon') NOT NULL,
  `expires_at` datetime NOT NULL,
  `used_at` datetime DEFAULT NULL,
  `max_uses` int(10) unsigned NOT NULL DEFAULT 1,
  `bind_ip` varchar(45) DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `token` (`token`),
  KEY `contract_id` (`contract_id`),
  KEY `expires_at` (`expires_at`),
  KEY `token_2` (`token`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_uca1400_ai_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `mail_queue`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `mail_queue` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `contract_id` int(11) NOT NULL,
  `party_id` int(11) DEFAULT NULL,
  `email` varchar(255) NOT NULL,
  `status` enum('pending','sending','sent','error') NOT NULL DEFAULT 'pending',
  `last_error` text DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `sent_at` datetime DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_mail_queue_status` (`status`),
  KEY `idx_mail_queue_sent_at` (`sent_at`),
  KEY `idx_mail_queue_contract` (`contract_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_czech_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `sms_queue`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `sms_queue` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `contract_id` int(11) NOT NULL,
  `party_id` int(11) DEFAULT NULL,
  `phone` varchar(64) NOT NULL,
  `status` enum('pending','sending','sent','error') NOT NULL DEFAULT 'pending',
  `last_error` text DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `sent_at` datetime DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_sms_queue_status` (`status`),
  KEY `idx_sms_queue_sent_at` (`sent_at`),
  KEY `idx_sms_queue_contract` (`contract_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_czech_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!40103 SET TIME_ZONE=@OLD_TIME_ZONE */;

/*!40101 SET SQL_MODE=@OLD_SQL_MODE */;
/*!40014 SET FOREIGN_KEY_CHECKS=@OLD_FOREIGN_KEY_CHECKS */;
/*!40014 SET UNIQUE_CHECKS=@OLD_UNIQUE_CHECKS */;
/*M!100616 SET NOTE_VERBOSITY=@OLD_NOTE_VERBOSITY */;

