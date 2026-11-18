SET NAMES utf8mb4;
SET FOREIGN_KEY_CHECKS=0;
SET UNIQUE_CHECKS=0;
-- CVCarts deployment SQL export
-- Import this file in MySQL or MariaDB using phpMyAdmin or mysql command line.
CREATE DATABASE IF NOT EXISTS cscqc_ecommerce;
USE cscqc_ecommerce;

DROP TABLE IF EXISTS `account_tokens`;
CREATE TABLE `account_tokens` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `user_id` int(11) NOT NULL,
  `token_type` varchar(32) NOT NULL,
  `token_hash` char(64) NOT NULL,
  `expires_at` datetime NOT NULL,
  `used_at` datetime DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `account_tokens_user_type_idx` (`user_id`,`token_type`,`created_at`),
  KEY `account_tokens_expiry_idx` (`token_type`,`expires_at`,`used_at`),
  KEY `account_tokens_hash_idx` (`token_hash`,`token_type`,`user_id`),
  CONSTRAINT `account_tokens_user_fk` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=28 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO `account_tokens` VALUES
(24,28,'student_verification','6ce334649c7fab310717b9d5358cc81ae006739c0b2d45b1ce42c747779b76d1','2026-11-16 13:23:09','2026-11-16 13:08:13','2026-11-16 05:08:09'),
(25,28,'student_verification','0b684e679166cb0b1f91eb5176df361452dc44905c31acb5a639cd42f55d8803','2026-11-16 13:23:13','2026-11-16 13:53:01','2026-11-16 05:08:13'),
(26,28,'student_verification','7edf0693b271ce790e641c7e826166807f2d97045c50beffcf855aeda0c742bd','2026-11-16 14:08:01','2026-11-16 14:04:39','2026-11-16 05:53:01'),
(27,28,'student_verification','c913d2ae87d12a57f9cfba9de90b2906bb2952027037882183dbe0e63c4a08fd','2026-11-16 14:19:39','2026-11-16 14:04:53','2026-11-16 06:04:39');
DROP TABLE IF EXISTS `admin_activity`;
CREATE TABLE `admin_activity` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `admin_id` int(11) NOT NULL,
  `action` varchar(255) NOT NULL,
  `details` text DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `admin_id` (`admin_id`),
  CONSTRAINT `admin_activity_ibfk_1` FOREIGN KEY (`admin_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=45 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO `admin_activity` VALUES
(6,1,'Admin password changed','Password changed from Admin Settings.','2026-11-15 06:14:04'),
(14,1,'Payment confirmed','Cash payment confirmed for CC-5F2DF2F1-20261116.','2026-11-16 06:10:16'),
(15,1,'Order status updated','CC-5F2DF2F1-20261116: Pending → In Transit','2026-11-16 06:15:16'),
(16,1,'Pickup scheduled','CC-5F2DF2F1-20261116 scheduled for 2026-11-16 14:15:00.','2026-11-16 06:15:32'),
(17,1,'Pickup scheduled','CC-5F2DF2F1-20261116 scheduled for 2026-11-16 14:15:00.','2026-11-16 06:15:44'),
(18,1,'Pickup scheduled','CC-5F2DF2F1-20261116 scheduled for 2026-11-16 14:15:00.','2026-11-16 06:15:44'),
(19,1,'Student account suspended','Student ID 28 account marked suspended.','2026-11-16 06:29:10'),
(20,1,'Student account activated','Student ID 28 account marked active.','2026-11-16 06:29:12'),
(21,1,'Order status updated','CC-5F2DF2F1-20261116: In Transit → Ready to Claim','2026-11-16 07:19:10'),
(22,1,'Pickup schedule cleared','CC-5F2DF2F1-20261116 pickup schedule cleared.','2026-11-16 07:19:39'),
(23,1,'Pickup schedule cleared','CC-5F2DF2F1-20261116 pickup schedule cleared.','2026-11-16 07:19:44'),
(24,1,'Pickup schedule cleared','CC-5F2DF2F1-20261116 pickup schedule cleared.','2026-11-16 07:19:45'),
(25,1,'Pickup schedule cleared','CC-5F2DF2F1-20261116 pickup schedule cleared.','2026-11-16 07:30:36'),
(26,1,'Pickup schedule cleared','CC-5F2DF2F1-20261116 pickup schedule cleared.','2026-11-16 07:30:37'),
(27,1,'Pickup slot created','2026-11-16 16:28-21:28; capacity 10.','2026-11-16 08:28:14'),
(28,1,'Pickup scheduled','CC-5F2DF2F1-20261116 scheduled for 2026-11-16 16:28:00.','2026-11-16 08:28:17'),
(29,1,'Pickup scheduled','CC-5F2DF2F1-20261116 scheduled for 2026-11-16 16:28:00.','2026-11-16 08:28:23'),
(30,1,'Pickup scheduled','CC-5F2DF2F1-20261116 scheduled for 2026-11-16 16:28:00.','2026-11-16 08:53:41'),
(42,1,'Product deleted','Product ID 3 removed from inventory.','2026-11-17 05:37:04'),
(43,1,'Product deleted','Product ID 5 removed from inventory.','2026-11-17 05:47:58'),
(44,42,'Admin password changed','Password changed from Admin Settings.','2026-11-17 07:21:35');
DROP TABLE IF EXISTS `auth_attempts`;
CREATE TABLE `auth_attempts` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `action` varchar(32) NOT NULL,
  `identifier_hash` char(64) NOT NULL,
  `ip_hash` char(64) NOT NULL,
  `succeeded` tinyint(1) NOT NULL DEFAULT 0,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `auth_attempts_identifier_idx` (`action`,`identifier_hash`,`created_at`),
  KEY `auth_attempts_ip_idx` (`action`,`ip_hash`,`created_at`)
) ENGINE=InnoDB AUTO_INCREMENT=155 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO `auth_attempts` VALUES
(1,'login','563cf1a3f07011edf4dcaf90d464f4132b802af8badcdb4511e767fd1ad41e6a','9c55d14ae84f6e5b484bd34d1fc4d2d9fa029acc18d33dfe9c19000c81f71f7c',1,'2026-11-15 06:01:45'),
(2,'login','a789b161da8616d5cad3b6102bafc204b56242792d4c929987a2b04574dc93d4','2ca55f01524e0a4ffa8027b3f857def8593c8321bfa099a14ddca1647058252f',1,'2026-11-15 06:04:37'),
(3,'admin_recovery_email','a789b161da8616d5cad3b6102bafc204b56242792d4c929987a2b04574dc93d4','2ca55f01524e0a4ffa8027b3f857def8593c8321bfa099a14ddca1647058252f',0,'2026-11-15 06:04:37'),
(4,'admin_recovery_lookup','a789b161da8616d5cad3b6102bafc204b56242792d4c929987a2b04574dc93d4','2ca55f01524e0a4ffa8027b3f857def8593c8321bfa099a14ddca1647058252f',1,'2026-11-15 06:04:37'),
(5,'admin_security_answer','c64b19cd00f33608ab36a6bb0c5b4b9f3d90f0d3151a88b585b621deb038dec1','2ca55f01524e0a4ffa8027b3f857def8593c8321bfa099a14ddca1647058252f',0,'2026-11-15 06:04:38'),
(6,'admin_security_answer','c64b19cd00f33608ab36a6bb0c5b4b9f3d90f0d3151a88b585b621deb038dec1','2ca55f01524e0a4ffa8027b3f857def8593c8321bfa099a14ddca1647058252f',1,'2026-11-15 06:04:38'),
(7,'admin_security_otp_send','c64b19cd00f33608ab36a6bb0c5b4b9f3d90f0d3151a88b585b621deb038dec1','2ca55f01524e0a4ffa8027b3f857def8593c8321bfa099a14ddca1647058252f',0,'2026-11-15 06:04:38'),
(8,'admin_password_reset','4e07408562bedb8b60ce05c1decfe3ad16b72230967de01f640b7e4729b49fce','2ca55f01524e0a4ffa8027b3f857def8593c8321bfa099a14ddca1647058252f',1,'2026-11-15 06:04:38'),
(12,'student_signup','19c20c8e6d0dab8feec4e2291ae2637859b5a5ba20166586253153e9e215ea65','2ca55f01524e0a4ffa8027b3f857def8593c8321bfa099a14ddca1647058252f',1,'2026-11-15 06:06:49'),
(13,'verification_send','19c20c8e6d0dab8feec4e2291ae2637859b5a5ba20166586253153e9e215ea65','2ca55f01524e0a4ffa8027b3f857def8593c8321bfa099a14ddca1647058252f',0,'2026-11-15 06:06:49'),
(14,'login','19c20c8e6d0dab8feec4e2291ae2637859b5a5ba20166586253153e9e215ea65','2ca55f01524e0a4ffa8027b3f857def8593c8321bfa099a14ddca1647058252f',0,'2026-11-15 06:06:50'),
(15,'login','19c20c8e6d0dab8feec4e2291ae2637859b5a5ba20166586253153e9e215ea65','2ca55f01524e0a4ffa8027b3f857def8593c8321bfa099a14ddca1647058252f',1,'2026-11-15 06:06:50'),
(16,'login','a090e6b08c90b34099e0d258be7df6adb579dbd7aa2b57bb30b32699ebf78dc2','2ca55f01524e0a4ffa8027b3f857def8593c8321bfa099a14ddca1647058252f',1,'2026-11-15 06:07:25'),
(17,'admin_recovery_email','a090e6b08c90b34099e0d258be7df6adb579dbd7aa2b57bb30b32699ebf78dc2','2ca55f01524e0a4ffa8027b3f857def8593c8321bfa099a14ddca1647058252f',0,'2026-11-15 06:07:26'),
(18,'admin_recovery_lookup','a090e6b08c90b34099e0d258be7df6adb579dbd7aa2b57bb30b32699ebf78dc2','2ca55f01524e0a4ffa8027b3f857def8593c8321bfa099a14ddca1647058252f',1,'2026-11-15 06:07:26'),
(19,'admin_security_answer','3ccceabd80e4bdb2cfdfc3ddfdbc707d25c02375a04b3860b63c3dd6482405a8','2ca55f01524e0a4ffa8027b3f857def8593c8321bfa099a14ddca1647058252f',0,'2026-11-15 06:07:26'),
(20,'admin_security_answer','3ccceabd80e4bdb2cfdfc3ddfdbc707d25c02375a04b3860b63c3dd6482405a8','2ca55f01524e0a4ffa8027b3f857def8593c8321bfa099a14ddca1647058252f',1,'2026-11-15 06:07:27'),
(21,'admin_security_otp_send','3ccceabd80e4bdb2cfdfc3ddfdbc707d25c02375a04b3860b63c3dd6482405a8','2ca55f01524e0a4ffa8027b3f857def8593c8321bfa099a14ddca1647058252f',0,'2026-11-15 06:07:27'),
(22,'admin_password_reset','e7f6c011776e8db7cd330b54174fd76f7d0216b612387a5ffcfb81e6f0919683','2ca55f01524e0a4ffa8027b3f857def8593c8321bfa099a14ddca1647058252f',1,'2026-11-15 06:07:27'),
(23,'login','a090e6b08c90b34099e0d258be7df6adb579dbd7aa2b57bb30b32699ebf78dc2','2ca55f01524e0a4ffa8027b3f857def8593c8321bfa099a14ddca1647058252f',1,'2026-11-15 06:07:27'),
(24,'login','8c6976e5b5410415bde908bd4dee15dfb167a9c873fc4bb8a81f6f2ab448a918','2ca55f01524e0a4ffa8027b3f857def8593c8321bfa099a14ddca1647058252f',1,'2026-11-15 06:09:24'),
(25,'login','563cf1a3f07011edf4dcaf90d464f4132b802af8badcdb4511e767fd1ad41e6a','2ca55f01524e0a4ffa8027b3f857def8593c8321bfa099a14ddca1647058252f',1,'2026-11-15 06:09:25'),
(26,'login','5c570e7ec04bd3b5d9031c63fa4c03da5df50516177377fca2f0250912de019a','2ca55f01524e0a4ffa8027b3f857def8593c8321bfa099a14ddca1647058252f',1,'2026-11-15 06:09:54'),
(27,'admin_recovery_lookup','5c570e7ec04bd3b5d9031c63fa4c03da5df50516177377fca2f0250912de019a','2ca55f01524e0a4ffa8027b3f857def8593c8321bfa099a14ddca1647058252f',1,'2026-11-15 06:09:55'),
(28,'admin_security_answer','a7d79793dacc36027a8435a8d10cf5a437b7bdae15f0b9aac478fc625928a283','2ca55f01524e0a4ffa8027b3f857def8593c8321bfa099a14ddca1647058252f',1,'2026-11-15 06:09:55'),
(29,'admin_security_otp_send','a7d79793dacc36027a8435a8d10cf5a437b7bdae15f0b9aac478fc625928a283','2ca55f01524e0a4ffa8027b3f857def8593c8321bfa099a14ddca1647058252f',0,'2026-11-15 06:09:55'),
(31,'student_signup','855ad55b7b2dd940edbb796bce68a90d49eb5ee584285ab628d90a6d9555d343','2ca55f01524e0a4ffa8027b3f857def8593c8321bfa099a14ddca1647058252f',1,'2026-11-15 06:10:36'),
(32,'verification_send','855ad55b7b2dd940edbb796bce68a90d49eb5ee584285ab628d90a6d9555d343','2ca55f01524e0a4ffa8027b3f857def8593c8321bfa099a14ddca1647058252f',0,'2026-11-15 06:10:37'),
(33,'verification_resend','855ad55b7b2dd940edbb796bce68a90d49eb5ee584285ab628d90a6d9555d343','2ca55f01524e0a4ffa8027b3f857def8593c8321bfa099a14ddca1647058252f',0,'2026-11-15 06:10:37'),
(34,'verification_resend','855ad55b7b2dd940edbb796bce68a90d49eb5ee584285ab628d90a6d9555d343','2ca55f01524e0a4ffa8027b3f857def8593c8321bfa099a14ddca1647058252f',0,'2026-11-15 06:10:37'),
(36,'login','563cf1a3f07011edf4dcaf90d464f4132b802af8badcdb4511e767fd1ad41e6a','9c55d14ae84f6e5b484bd34d1fc4d2d9fa029acc18d33dfe9c19000c81f71f7c',1,'2026-11-15 06:12:10'),
(39,'login','563cf1a3f07011edf4dcaf90d464f4132b802af8badcdb4511e767fd1ad41e6a','9c55d14ae84f6e5b484bd34d1fc4d2d9fa029acc18d33dfe9c19000c81f71f7c',0,'2026-11-15 06:15:39'),
(40,'login','563cf1a3f07011edf4dcaf90d464f4132b802af8badcdb4511e767fd1ad41e6a','9c55d14ae84f6e5b484bd34d1fc4d2d9fa029acc18d33dfe9c19000c81f71f7c',0,'2026-11-15 06:16:23'),
(41,'login','563cf1a3f07011edf4dcaf90d464f4132b802af8badcdb4511e767fd1ad41e6a','9c55d14ae84f6e5b484bd34d1fc4d2d9fa029acc18d33dfe9c19000c81f71f7c',0,'2026-11-15 06:16:30'),
(42,'login','563cf1a3f07011edf4dcaf90d464f4132b802af8badcdb4511e767fd1ad41e6a','9c55d14ae84f6e5b484bd34d1fc4d2d9fa029acc18d33dfe9c19000c81f71f7c',1,'2026-11-15 06:16:41'),
(43,'login','8c6976e5b5410415bde908bd4dee15dfb167a9c873fc4bb8a81f6f2ab448a918','9c55d14ae84f6e5b484bd34d1fc4d2d9fa029acc18d33dfe9c19000c81f71f7c',0,'2026-11-15 06:29:49'),
(44,'login','8c6976e5b5410415bde908bd4dee15dfb167a9c873fc4bb8a81f6f2ab448a918','9c55d14ae84f6e5b484bd34d1fc4d2d9fa029acc18d33dfe9c19000c81f71f7c',0,'2026-11-15 06:30:34'),
(45,'login','249f0587579a586548e79a0abbe672b20956679fcc1abcae110f79ab24cf6d5d','9c55d14ae84f6e5b484bd34d1fc4d2d9fa029acc18d33dfe9c19000c81f71f7c',1,'2026-11-15 06:30:58'),
(46,'login','51d86b6852899cf889bd6f2b7ae096a9c87669e61409556ebcbd7c428e2d0713','9c55d14ae84f6e5b484bd34d1fc4d2d9fa029acc18d33dfe9c19000c81f71f7c',1,'2026-11-15 06:31:19'),
(47,'login','18e663dd25c5d62638657996f9aa37c65389f0f1c20dcb2af4318ebcb299994a','9c55d14ae84f6e5b484bd34d1fc4d2d9fa029acc18d33dfe9c19000c81f71f7c',1,'2026-11-15 06:31:40'),
(48,'login','6456298ea2baa9a1c39f19b788bcb7a5ce8c0fbade267efa640a13e4eb01542c','9c55d14ae84f6e5b484bd34d1fc4d2d9fa029acc18d33dfe9c19000c81f71f7c',1,'2026-11-15 06:32:03'),
(49,'login','5a4c0ae11b57e7583ba31c9fcfa914ea99fefe440afbf6607ed128ed46108820','9c55d14ae84f6e5b484bd34d1fc4d2d9fa029acc18d33dfe9c19000c81f71f7c',1,'2026-11-15 06:32:50'),
(50,'login','d7f5aa4d3c23b6c040fe08b901bb2eed256a47d5182f060b15b6aef6863d93cb','9c55d14ae84f6e5b484bd34d1fc4d2d9fa029acc18d33dfe9c19000c81f71f7c',1,'2026-11-15 06:33:42'),
(51,'student_signup','068816f44bfc99ce8551b9214e1d75bf4e5cb4fb56ea1edc3dd72efc916304c8','9c55d14ae84f6e5b484bd34d1fc4d2d9fa029acc18d33dfe9c19000c81f71f7c',1,'2026-11-15 06:36:54'),
(52,'verification_send','068816f44bfc99ce8551b9214e1d75bf4e5cb4fb56ea1edc3dd72efc916304c8','9c55d14ae84f6e5b484bd34d1fc4d2d9fa029acc18d33dfe9c19000c81f71f7c',0,'2026-11-15 06:36:54'),
(53,'login','0c653faa628b49c5b6e022bb43db7c8650468a8d8e6940d4a0fc230c8cf71bb0','9c55d14ae84f6e5b484bd34d1fc4d2d9fa029acc18d33dfe9c19000c81f71f7c',1,'2026-11-15 06:37:10'),
(54,'login','563cf1a3f07011edf4dcaf90d464f4132b802af8badcdb4511e767fd1ad41e6a','9c55d14ae84f6e5b484bd34d1fc4d2d9fa029acc18d33dfe9c19000c81f71f7c',0,'2026-11-15 06:39:31'),
(55,'login','563cf1a3f07011edf4dcaf90d464f4132b802af8badcdb4511e767fd1ad41e6a','9c55d14ae84f6e5b484bd34d1fc4d2d9fa029acc18d33dfe9c19000c81f71f7c',0,'2026-11-15 06:39:32'),
(56,'login','563cf1a3f07011edf4dcaf90d464f4132b802af8badcdb4511e767fd1ad41e6a','9c55d14ae84f6e5b484bd34d1fc4d2d9fa029acc18d33dfe9c19000c81f71f7c',0,'2026-11-15 06:39:36'),
(57,'login','563cf1a3f07011edf4dcaf90d464f4132b802af8badcdb4511e767fd1ad41e6a','9c55d14ae84f6e5b484bd34d1fc4d2d9fa029acc18d33dfe9c19000c81f71f7c',1,'2026-11-15 06:39:44'),
(58,'login','563cf1a3f07011edf4dcaf90d464f4132b802af8badcdb4511e767fd1ad41e6a','9c55d14ae84f6e5b484bd34d1fc4d2d9fa029acc18d33dfe9c19000c81f71f7c',1,'2026-11-15 07:24:28'),
(65,'login','563cf1a3f07011edf4dcaf90d464f4132b802af8badcdb4511e767fd1ad41e6a','9c55d14ae84f6e5b484bd34d1fc4d2d9fa029acc18d33dfe9c19000c81f71f7c',0,'2026-11-15 08:12:59'),
(66,'login','8d752d3482f23f2923a4ef090657fb0f15a4c9a8cefa2782afdcb0b80d2fb69d','9c55d14ae84f6e5b484bd34d1fc4d2d9fa029acc18d33dfe9c19000c81f71f7c',1,'2026-11-15 08:13:15'),
(78,'login','8d752d3482f23f2923a4ef090657fb0f15a4c9a8cefa2782afdcb0b80d2fb69d','9c55d14ae84f6e5b484bd34d1fc4d2d9fa029acc18d33dfe9c19000c81f71f7c',0,'2026-11-15 08:28:42'),
(79,'login','8d752d3482f23f2923a4ef090657fb0f15a4c9a8cefa2782afdcb0b80d2fb69d','9c55d14ae84f6e5b484bd34d1fc4d2d9fa029acc18d33dfe9c19000c81f71f7c',0,'2026-11-15 08:29:02'),
(80,'login','343c88a77843001e76506f1a28eb078874c2c77c5067d7ba424b154a82ad0e96','9c55d14ae84f6e5b484bd34d1fc4d2d9fa029acc18d33dfe9c19000c81f71f7c',0,'2026-11-15 08:29:06'),
(81,'login','fee80fed48ffe382b86784779c5a036d88a8c17822e1aed2ceb79c802dcb3eee','9c55d14ae84f6e5b484bd34d1fc4d2d9fa029acc18d33dfe9c19000c81f71f7c',0,'2026-11-15 08:29:16'),
(82,'login','8d752d3482f23f2923a4ef090657fb0f15a4c9a8cefa2782afdcb0b80d2fb69d','9c55d14ae84f6e5b484bd34d1fc4d2d9fa029acc18d33dfe9c19000c81f71f7c',1,'2026-11-15 08:29:58'),
(83,'student_signup','db9bbd7f31f79a3ffc9bfc9cfe84360f40704fd5e94ba8baafdb582119176ba0','9c55d14ae84f6e5b484bd34d1fc4d2d9fa029acc18d33dfe9c19000c81f71f7c',1,'2026-11-16 05:08:08'),
(84,'verification_send','db9bbd7f31f79a3ffc9bfc9cfe84360f40704fd5e94ba8baafdb582119176ba0','9c55d14ae84f6e5b484bd34d1fc4d2d9fa029acc18d33dfe9c19000c81f71f7c',0,'2026-11-16 05:08:09'),
(85,'verification_resend','db9bbd7f31f79a3ffc9bfc9cfe84360f40704fd5e94ba8baafdb582119176ba0','9c55d14ae84f6e5b484bd34d1fc4d2d9fa029acc18d33dfe9c19000c81f71f7c',0,'2026-11-16 05:08:13'),
(86,'verification_resend','db9bbd7f31f79a3ffc9bfc9cfe84360f40704fd5e94ba8baafdb582119176ba0','9672b26e9a6e229f584a4041c4177c49608d3ace034f999be01b9b7b83c18751',0,'2026-11-16 05:53:01'),
(87,'verification_resend','db9bbd7f31f79a3ffc9bfc9cfe84360f40704fd5e94ba8baafdb582119176ba0','9672b26e9a6e229f584a4041c4177c49608d3ace034f999be01b9b7b83c18751',0,'2026-11-16 05:53:03'),
(88,'verification_resend','db9bbd7f31f79a3ffc9bfc9cfe84360f40704fd5e94ba8baafdb582119176ba0','9672b26e9a6e229f584a4041c4177c49608d3ace034f999be01b9b7b83c18751',0,'2026-11-16 05:53:41'),
(89,'verification_resend','db9bbd7f31f79a3ffc9bfc9cfe84360f40704fd5e94ba8baafdb582119176ba0','9672b26e9a6e229f584a4041c4177c49608d3ace034f999be01b9b7b83c18751',0,'2026-11-16 05:53:44'),
(90,'verification_resend','db9bbd7f31f79a3ffc9bfc9cfe84360f40704fd5e94ba8baafdb582119176ba0','6ea994344d1f8e88069560148a0bfed708004aa555921db1a242673b6f8e995a',0,'2026-11-16 05:59:29'),
(91,'verification_resend_delivery','db9bbd7f31f79a3ffc9bfc9cfe84360f40704fd5e94ba8baafdb582119176ba0','6ea994344d1f8e88069560148a0bfed708004aa555921db1a242673b6f8e995a',1,'2026-11-16 06:04:39'),
(92,'login','db9bbd7f31f79a3ffc9bfc9cfe84360f40704fd5e94ba8baafdb582119176ba0','6ea994344d1f8e88069560148a0bfed708004aa555921db1a242673b6f8e995a',0,'2026-11-16 06:05:08'),
(93,'login','db9bbd7f31f79a3ffc9bfc9cfe84360f40704fd5e94ba8baafdb582119176ba0','6ea994344d1f8e88069560148a0bfed708004aa555921db1a242673b6f8e995a',1,'2026-11-16 06:05:14'),
(94,'login','db9bbd7f31f79a3ffc9bfc9cfe84360f40704fd5e94ba8baafdb582119176ba0','9c55d14ae84f6e5b484bd34d1fc4d2d9fa029acc18d33dfe9c19000c81f71f7c',1,'2026-11-16 06:05:15'),
(95,'login','8d752d3482f23f2923a4ef090657fb0f15a4c9a8cefa2782afdcb0b80d2fb69d','6ea994344d1f8e88069560148a0bfed708004aa555921db1a242673b6f8e995a',0,'2026-11-16 06:06:10'),
(96,'login','db9bbd7f31f79a3ffc9bfc9cfe84360f40704fd5e94ba8baafdb582119176ba0','6ea994344d1f8e88069560148a0bfed708004aa555921db1a242673b6f8e995a',1,'2026-11-16 06:07:06'),
(97,'login','9aa1e983a8740a0cf3e067bf2a3461e7023708a0797d3e86d0bb008a8fc32d4c','6ea994344d1f8e88069560148a0bfed708004aa555921db1a242673b6f8e995a',0,'2026-11-16 06:08:13'),
(98,'login','297cefb8ec339aec32169d7c78aaa605f938ddc2dc4d01905a3fdaaedad6e259','6ea994344d1f8e88069560148a0bfed708004aa555921db1a242673b6f8e995a',0,'2026-11-16 06:09:14'),
(99,'login','297cefb8ec339aec32169d7c78aaa605f938ddc2dc4d01905a3fdaaedad6e259','6ea994344d1f8e88069560148a0bfed708004aa555921db1a242673b6f8e995a',0,'2026-11-16 06:09:20'),
(100,'login','8d752d3482f23f2923a4ef090657fb0f15a4c9a8cefa2782afdcb0b80d2fb69d','6ea994344d1f8e88069560148a0bfed708004aa555921db1a242673b6f8e995a',1,'2026-11-16 06:09:57'),
(101,'login','8d752d3482f23f2923a4ef090657fb0f15a4c9a8cefa2782afdcb0b80d2fb69d','6ea994344d1f8e88069560148a0bfed708004aa555921db1a242673b6f8e995a',1,'2026-11-16 06:11:06'),
(102,'login','db9bbd7f31f79a3ffc9bfc9cfe84360f40704fd5e94ba8baafdb582119176ba0','6ea994344d1f8e88069560148a0bfed708004aa555921db1a242673b6f8e995a',0,'2026-11-16 06:11:43'),
(103,'login','db9bbd7f31f79a3ffc9bfc9cfe84360f40704fd5e94ba8baafdb582119176ba0','6ea994344d1f8e88069560148a0bfed708004aa555921db1a242673b6f8e995a',0,'2026-11-16 06:11:54'),
(104,'login','db9bbd7f31f79a3ffc9bfc9cfe84360f40704fd5e94ba8baafdb582119176ba0','6ea994344d1f8e88069560148a0bfed708004aa555921db1a242673b6f8e995a',1,'2026-11-16 06:12:18'),
(105,'login','563cf1a3f07011edf4dcaf90d464f4132b802af8badcdb4511e767fd1ad41e6a','6ea994344d1f8e88069560148a0bfed708004aa555921db1a242673b6f8e995a',0,'2026-11-16 06:14:25'),
(106,'login','563cf1a3f07011edf4dcaf90d464f4132b802af8badcdb4511e767fd1ad41e6a','6ea994344d1f8e88069560148a0bfed708004aa555921db1a242673b6f8e995a',0,'2026-11-16 06:14:32'),
(107,'login','563cf1a3f07011edf4dcaf90d464f4132b802af8badcdb4511e767fd1ad41e6a','6ea994344d1f8e88069560148a0bfed708004aa555921db1a242673b6f8e995a',0,'2026-11-16 06:14:41'),
(108,'login','8d752d3482f23f2923a4ef090657fb0f15a4c9a8cefa2782afdcb0b80d2fb69d','6ea994344d1f8e88069560148a0bfed708004aa555921db1a242673b6f8e995a',1,'2026-11-16 06:14:54'),
(109,'login','8d752d3482f23f2923a4ef090657fb0f15a4c9a8cefa2782afdcb0b80d2fb69d','6ea994344d1f8e88069560148a0bfed708004aa555921db1a242673b6f8e995a',1,'2026-11-16 07:15:45'),
(110,'login','db9bbd7f31f79a3ffc9bfc9cfe84360f40704fd5e94ba8baafdb582119176ba0','6ea994344d1f8e88069560148a0bfed708004aa555921db1a242673b6f8e995a',1,'2026-11-16 08:08:31'),
(111,'login','8d752d3482f23f2923a4ef090657fb0f15a4c9a8cefa2782afdcb0b80d2fb69d','6ea994344d1f8e88069560148a0bfed708004aa555921db1a242673b6f8e995a',1,'2026-11-16 08:17:49'),
(112,'login','db9bbd7f31f79a3ffc9bfc9cfe84360f40704fd5e94ba8baafdb582119176ba0','6ea994344d1f8e88069560148a0bfed708004aa555921db1a242673b6f8e995a',1,'2026-11-16 09:16:34'),
(113,'login','8d752d3482f23f2923a4ef090657fb0f15a4c9a8cefa2782afdcb0b80d2fb69d','6ea994344d1f8e88069560148a0bfed708004aa555921db1a242673b6f8e995a',1,'2026-11-16 09:22:11'),
(114,'login','8c6976e5b5410415bde908bd4dee15dfb167a9c873fc4bb8a81f6f2ab448a918','6ea994344d1f8e88069560148a0bfed708004aa555921db1a242673b6f8e995a',0,'2026-11-16 09:28:40'),
(115,'login','8c6976e5b5410415bde908bd4dee15dfb167a9c873fc4bb8a81f6f2ab448a918','6ea994344d1f8e88069560148a0bfed708004aa555921db1a242673b6f8e995a',0,'2026-11-16 09:28:48'),
(116,'login','ff7dbb2cd3d5443267401398b509ac4ec06c91909a85f5a8b289427f315d537c','6ea994344d1f8e88069560148a0bfed708004aa555921db1a242673b6f8e995a',1,'2026-11-16 09:29:35'),
(117,'login','ff7dbb2cd3d5443267401398b509ac4ec06c91909a85f5a8b289427f315d537c','6ea994344d1f8e88069560148a0bfed708004aa555921db1a242673b6f8e995a',1,'2026-11-16 09:36:14'),
(118,'login','ff7dbb2cd3d5443267401398b509ac4ec06c91909a85f5a8b289427f315d537c','6ea994344d1f8e88069560148a0bfed708004aa555921db1a242673b6f8e995a',1,'2026-11-16 09:39:12'),
(119,'login','ff7dbb2cd3d5443267401398b509ac4ec06c91909a85f5a8b289427f315d537c','6ea994344d1f8e88069560148a0bfed708004aa555921db1a242673b6f8e995a',1,'2026-11-16 09:41:52'),
(120,'login','ff7dbb2cd3d5443267401398b509ac4ec06c91909a85f5a8b289427f315d537c','6ea994344d1f8e88069560148a0bfed708004aa555921db1a242673b6f8e995a',1,'2026-11-16 09:48:05'),
(121,'login','ff7dbb2cd3d5443267401398b509ac4ec06c91909a85f5a8b289427f315d537c','6ea994344d1f8e88069560148a0bfed708004aa555921db1a242673b6f8e995a',1,'2026-11-16 09:49:35'),
(122,'login','ff7dbb2cd3d5443267401398b509ac4ec06c91909a85f5a8b289427f315d537c','6ea994344d1f8e88069560148a0bfed708004aa555921db1a242673b6f8e995a',1,'2026-11-16 09:51:01'),
(123,'login','ff7dbb2cd3d5443267401398b509ac4ec06c91909a85f5a8b289427f315d537c','6ea994344d1f8e88069560148a0bfed708004aa555921db1a242673b6f8e995a',1,'2026-11-16 09:54:26'),
(124,'login','ff7dbb2cd3d5443267401398b509ac4ec06c91909a85f5a8b289427f315d537c','6ea994344d1f8e88069560148a0bfed708004aa555921db1a242673b6f8e995a',1,'2026-11-16 09:55:14'),
(125,'login','43d20cc01d41d847ecda1c5b6ab6d14eb42d55a34262af687e3265c4022eda7f','6ea994344d1f8e88069560148a0bfed708004aa555921db1a242673b6f8e995a',1,'2026-11-16 09:59:21'),
(126,'login','ff7dbb2cd3d5443267401398b509ac4ec06c91909a85f5a8b289427f315d537c','6ea994344d1f8e88069560148a0bfed708004aa555921db1a242673b6f8e995a',1,'2026-11-16 10:01:44'),
(127,'login','ff7dbb2cd3d5443267401398b509ac4ec06c91909a85f5a8b289427f315d537c','6ea994344d1f8e88069560148a0bfed708004aa555921db1a242673b6f8e995a',1,'2026-11-16 10:06:07'),
(128,'login','db9bbd7f31f79a3ffc9bfc9cfe84360f40704fd5e94ba8baafdb582119176ba0','6ea994344d1f8e88069560148a0bfed708004aa555921db1a242673b6f8e995a',0,'2026-11-16 10:30:37'),
(129,'login','db9bbd7f31f79a3ffc9bfc9cfe84360f40704fd5e94ba8baafdb582119176ba0','6ea994344d1f8e88069560148a0bfed708004aa555921db1a242673b6f8e995a',1,'2026-11-16 10:30:50'),
(130,'login','8d752d3482f23f2923a4ef090657fb0f15a4c9a8cefa2782afdcb0b80d2fb69d','6ea994344d1f8e88069560148a0bfed708004aa555921db1a242673b6f8e995a',1,'2026-11-16 10:32:03'),
(131,'login','563cf1a3f07011edf4dcaf90d464f4132b802af8badcdb4511e767fd1ad41e6a','6ea994344d1f8e88069560148a0bfed708004aa555921db1a242673b6f8e995a',0,'2026-11-17 04:55:08'),
(132,'login','8d752d3482f23f2923a4ef090657fb0f15a4c9a8cefa2782afdcb0b80d2fb69d','6ea994344d1f8e88069560148a0bfed708004aa555921db1a242673b6f8e995a',1,'2026-11-17 04:55:20'),
(133,'login','8d752d3482f23f2923a4ef090657fb0f15a4c9a8cefa2782afdcb0b80d2fb69d','6ea994344d1f8e88069560148a0bfed708004aa555921db1a242673b6f8e995a',1,'2026-11-17 06:00:40'),
(134,'login','8d752d3482f23f2923a4ef090657fb0f15a4c9a8cefa2782afdcb0b80d2fb69d','6ea994344d1f8e88069560148a0bfed708004aa555921db1a242673b6f8e995a',0,'2026-11-17 06:56:25'),
(135,'login','8d752d3482f23f2923a4ef090657fb0f15a4c9a8cefa2782afdcb0b80d2fb69d','6ea994344d1f8e88069560148a0bfed708004aa555921db1a242673b6f8e995a',0,'2026-11-17 06:56:34'),
(136,'login','8d752d3482f23f2923a4ef090657fb0f15a4c9a8cefa2782afdcb0b80d2fb69d','6ea994344d1f8e88069560148a0bfed708004aa555921db1a242673b6f8e995a',0,'2026-11-17 07:05:40'),
(137,'login','297cefb8ec339aec32169d7c78aaa605f938ddc2dc4d01905a3fdaaedad6e259','6ea994344d1f8e88069560148a0bfed708004aa555921db1a242673b6f8e995a',0,'2026-11-17 07:06:04'),
(138,'login','297cefb8ec339aec32169d7c78aaa605f938ddc2dc4d01905a3fdaaedad6e259','6ea994344d1f8e88069560148a0bfed708004aa555921db1a242673b6f8e995a',0,'2026-11-17 07:06:21'),
(139,'login','297cefb8ec339aec32169d7c78aaa605f938ddc2dc4d01905a3fdaaedad6e259','6ea994344d1f8e88069560148a0bfed708004aa555921db1a242673b6f8e995a',0,'2026-11-17 07:07:05'),
(140,'login','8d752d3482f23f2923a4ef090657fb0f15a4c9a8cefa2782afdcb0b80d2fb69d','6ea994344d1f8e88069560148a0bfed708004aa555921db1a242673b6f8e995a',0,'2026-11-17 07:07:59'),
(141,'login','297cefb8ec339aec32169d7c78aaa605f938ddc2dc4d01905a3fdaaedad6e259','6ea994344d1f8e88069560148a0bfed708004aa555921db1a242673b6f8e995a',0,'2026-11-17 07:11:25'),
(142,'login','db9bbd7f31f79a3ffc9bfc9cfe84360f40704fd5e94ba8baafdb582119176ba0','6ea994344d1f8e88069560148a0bfed708004aa555921db1a242673b6f8e995a',1,'2026-11-17 07:11:55'),
(143,'login','297cefb8ec339aec32169d7c78aaa605f938ddc2dc4d01905a3fdaaedad6e259','6ea994344d1f8e88069560148a0bfed708004aa555921db1a242673b6f8e995a',0,'2026-11-17 07:18:56'),
(144,'login','297cefb8ec339aec32169d7c78aaa605f938ddc2dc4d01905a3fdaaedad6e259','6ea994344d1f8e88069560148a0bfed708004aa555921db1a242673b6f8e995a',0,'2026-11-17 07:19:08'),
(145,'login','563cf1a3f07011edf4dcaf90d464f4132b802af8badcdb4511e767fd1ad41e6a','6ea994344d1f8e88069560148a0bfed708004aa555921db1a242673b6f8e995a',0,'2026-11-17 07:20:58'),
(146,'login','297cefb8ec339aec32169d7c78aaa605f938ddc2dc4d01905a3fdaaedad6e259','6ea994344d1f8e88069560148a0bfed708004aa555921db1a242673b6f8e995a',1,'2026-11-17 07:21:15'),
(147,'login','db9bbd7f31f79a3ffc9bfc9cfe84360f40704fd5e94ba8baafdb582119176ba0','6ea994344d1f8e88069560148a0bfed708004aa555921db1a242673b6f8e995a',0,'2026-11-17 07:27:35'),
(148,'login','db9bbd7f31f79a3ffc9bfc9cfe84360f40704fd5e94ba8baafdb582119176ba0','6ea994344d1f8e88069560148a0bfed708004aa555921db1a242673b6f8e995a',1,'2026-11-17 07:27:52'),
(149,'login','297cefb8ec339aec32169d7c78aaa605f938ddc2dc4d01905a3fdaaedad6e259','6ea994344d1f8e88069560148a0bfed708004aa555921db1a242673b6f8e995a',1,'2026-11-17 08:20:42'),
(150,'login','db9bbd7f31f79a3ffc9bfc9cfe84360f40704fd5e94ba8baafdb582119176ba0','6ea994344d1f8e88069560148a0bfed708004aa555921db1a242673b6f8e995a',0,'2026-11-17 09:22:35'),
(151,'login','db9bbd7f31f79a3ffc9bfc9cfe84360f40704fd5e94ba8baafdb582119176ba0','6ea994344d1f8e88069560148a0bfed708004aa555921db1a242673b6f8e995a',0,'2026-11-17 09:22:53'),
(152,'login','db9bbd7f31f79a3ffc9bfc9cfe84360f40704fd5e94ba8baafdb582119176ba0','6ea994344d1f8e88069560148a0bfed708004aa555921db1a242673b6f8e995a',1,'2026-11-17 09:23:06'),
(153,'login','563cf1a3f07011edf4dcaf90d464f4132b802af8badcdb4511e767fd1ad41e6a','6ea994344d1f8e88069560148a0bfed708004aa555921db1a242673b6f8e995a',0,'2026-11-18 02:20:39'),
(154,'login','563cf1a3f07011edf4dcaf90d464f4132b802af8badcdb4511e767fd1ad41e6a','6ea994344d1f8e88069560148a0bfed708004aa555921db1a242673b6f8e995a',0,'2026-11-18 02:20:40');
DROP TABLE IF EXISTS `cart_items`;
CREATE TABLE `cart_items` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `user_id` int(11) NOT NULL,
  `product_id` int(11) NOT NULL,
  `selected_size` varchar(50) DEFAULT NULL,
  `quantity` int(11) NOT NULL DEFAULT 1,
  `created_at` timestamp NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `unique_cart_item` (`user_id`,`product_id`,`selected_size`),
  KEY `product_id` (`product_id`),
  CONSTRAINT `cart_items_ibfk_1` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE,
  CONSTRAINT `cart_items_ibfk_2` FOREIGN KEY (`product_id`) REFERENCES `products` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=5 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO `cart_items` VALUES
(4,28,2,'',5,'2026-11-16 10:31:09');
DROP TABLE IF EXISTS `email_delivery_log`;
CREATE TABLE `email_delivery_log` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `purpose` varchar(40) NOT NULL,
  `recipient_hash` char(64) NOT NULL,
  `delivery_status` enum('accepted','failed') NOT NULL,
  `error_message` varchar(500) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `email_delivery_purpose_idx` (`purpose`,`created_at`),
  KEY `email_delivery_recipient_idx` (`recipient_hash`,`created_at`)
) ENGINE=InnoDB AUTO_INCREMENT=25 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO `email_delivery_log` VALUES
(1,'admin_password_reset','c64b19cd00f33608ab36a6bb0c5b4b9f3d90f0d3151a88b585b621deb038dec1','failed','SMTP_HOST and a valid MAIL_FROM_ADDRESS must be configured.','2026-11-15 06:04:37'),
(2,'admin_security_otp','c64b19cd00f33608ab36a6bb0c5b4b9f3d90f0d3151a88b585b621deb038dec1','failed','SMTP_HOST and a valid MAIL_FROM_ADDRESS must be configured.','2026-11-15 06:04:38'),
(5,'student_verification','19c20c8e6d0dab8feec4e2291ae2637859b5a5ba20166586253153e9e215ea65','failed','SMTP_HOST and a valid MAIL_FROM_ADDRESS must be configured.','2026-11-15 06:06:49'),
(6,'admin_password_reset','3ccceabd80e4bdb2cfdfc3ddfdbc707d25c02375a04b3860b63c3dd6482405a8','failed','SMTP_HOST and a valid MAIL_FROM_ADDRESS must be configured.','2026-11-15 06:07:26'),
(7,'admin_security_otp','3ccceabd80e4bdb2cfdfc3ddfdbc707d25c02375a04b3860b63c3dd6482405a8','failed','SMTP_HOST and a valid MAIL_FROM_ADDRESS must be configured.','2026-11-15 06:07:27'),
(8,'admin_security_otp','a7d79793dacc36027a8435a8d10cf5a437b7bdae15f0b9aac478fc625928a283','failed','SMTP_HOST and a valid MAIL_FROM_ADDRESS must be configured.','2026-11-15 06:09:55'),
(10,'student_verification','855ad55b7b2dd940edbb796bce68a90d49eb5ee584285ab628d90a6d9555d343','failed','SMTP_HOST and a valid MAIL_FROM_ADDRESS must be configured.','2026-11-15 06:10:37'),
(11,'student_verification','855ad55b7b2dd940edbb796bce68a90d49eb5ee584285ab628d90a6d9555d343','failed','SMTP_HOST and a valid MAIL_FROM_ADDRESS must be configured.','2026-11-15 06:10:37'),
(14,'student_verification','068816f44bfc99ce8551b9214e1d75bf4e5cb4fb56ea1edc3dd72efc916304c8','failed','SMTP_HOST and a valid MAIL_FROM_ADDRESS must be configured.','2026-11-15 06:36:54'),
(20,'student_verification','db9bbd7f31f79a3ffc9bfc9cfe84360f40704fd5e94ba8baafdb582119176ba0','failed','Gmail SMTP requires SMTP_USERNAME and SMTP_PASSWORD. Use an authorized Workspace SMTP relay if app passwords are unavailable.','2026-11-16 05:08:09'),
(21,'student_verification','db9bbd7f31f79a3ffc9bfc9cfe84360f40704fd5e94ba8baafdb582119176ba0','failed','Gmail SMTP requires SMTP_USERNAME and SMTP_PASSWORD. Use an authorized Workspace SMTP relay if app passwords are unavailable.','2026-11-16 05:08:13'),
(22,'student_verification','db9bbd7f31f79a3ffc9bfc9cfe84360f40704fd5e94ba8baafdb582119176ba0','failed','SMTP_USERNAME and SMTP_PASSWORD are required. Use the authorized Gmail account and its App Password.','2026-11-16 05:53:01'),
(23,'smtp_test','db9bbd7f31f79a3ffc9bfc9cfe84360f40704fd5e94ba8baafdb582119176ba0','accepted',NULL,'2026-11-16 05:57:57'),
(24,'student_verification','db9bbd7f31f79a3ffc9bfc9cfe84360f40704fd5e94ba8baafdb582119176ba0','accepted',NULL,'2026-11-16 06:04:42');
DROP TABLE IF EXISTS `inventory_movements`;
CREATE TABLE `inventory_movements` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `product_id` int(11) NOT NULL,
  `selected_size` varchar(50) DEFAULT NULL,
  `order_id` int(11) DEFAULT NULL,
  `admin_id` int(11) DEFAULT NULL,
  `quantity_change` int(11) NOT NULL,
  `reason` varchar(100) NOT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `inventory_movements_product_idx` (`product_id`,`created_at`),
  KEY `inventory_movements_order_idx` (`order_id`,`created_at`),
  KEY `inventory_movements_admin_fk` (`admin_id`),
  CONSTRAINT `inventory_movements_admin_fk` FOREIGN KEY (`admin_id`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  CONSTRAINT `inventory_movements_order_fk` FOREIGN KEY (`order_id`) REFERENCES `orders` (`id`) ON DELETE SET NULL,
  CONSTRAINT `inventory_movements_product_fk` FOREIGN KEY (`product_id`) REFERENCES `products` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=20 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO `inventory_movements` VALUES
(16,1,NULL,NULL,1,-5,'admin_adjustment','2026-11-17 05:48:02'),
(17,21,'S',NULL,1,50,'initial_stock','2026-11-17 06:05:49'),
(18,21,'M',NULL,1,500,'initial_stock','2026-11-17 06:05:49'),
(19,21,'M',19,NULL,-1,'order_placed','2026-11-17 09:25:11');
DROP TABLE IF EXISTS `notifications`;
CREATE TABLE `notifications` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `user_id` int(11) NOT NULL,
  `title` varchar(255) NOT NULL,
  `message` text NOT NULL,
  `related_order_id` int(11) DEFAULT NULL,
  `is_read` tinyint(1) NOT NULL DEFAULT 0,
  `created_at` timestamp NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `user_id` (`user_id`),
  KEY `related_order_id` (`related_order_id`),
  CONSTRAINT `notifications_ibfk_1` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE,
  CONSTRAINT `notifications_ibfk_2` FOREIGN KEY (`related_order_id`) REFERENCES `orders` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB AUTO_INCREMENT=25 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO `notifications` VALUES
(8,28,'Order placed','Your order CC-5F2DF2F1-20261116 has been created.',14,1,'2026-11-16 06:07:41'),
(9,28,'Cash payment received','Cash payment for order CC-5F2DF2F1-20261116 has been confirmed.',14,1,'2026-11-16 06:10:16'),
(10,28,'Order updated','Your order CC-5F2DF2F1-20261116 is now In Transit.',14,1,'2026-11-16 06:15:16'),
(11,28,'Pickup scheduled','Pickup for order CC-5F2DF2F1-20261116 is scheduled for Nov 16, 2026 2:15 PM.',14,1,'2026-11-16 06:15:32'),
(12,28,'Pickup scheduled','Pickup for order CC-5F2DF2F1-20261116 is scheduled for Nov 16, 2026 2:15 PM.',14,1,'2026-11-16 06:15:44'),
(13,28,'Pickup scheduled','Pickup for order CC-5F2DF2F1-20261116 is scheduled for Nov 16, 2026 2:15 PM.',14,1,'2026-11-16 06:15:44'),
(14,28,'Order updated','Your order CC-5F2DF2F1-20261116 is now Ready to Claim.',14,1,'2026-11-16 07:19:10'),
(15,28,'Pickup scheduled','Pickup for order CC-5F2DF2F1-20261116 is scheduled for Nov 16, 2026 4:28 PM.',14,1,'2026-11-16 08:28:17'),
(16,28,'Pickup scheduled','Pickup for order CC-5F2DF2F1-20261116 is scheduled for Nov 16, 2026 4:28 PM.',14,1,'2026-11-16 08:28:23'),
(17,28,'Pickup scheduled','Pickup for order CC-5F2DF2F1-20261116 is scheduled for Nov 16, 2026 4:28 PM.',14,1,'2026-11-16 08:53:41'),
(18,28,'Order updated','Your order CC-SMOKE-6261FD is now Ready to Claim.',NULL,1,'2026-11-16 09:31:05'),
(19,28,'Order claimed','Your order CC-SMOKE-6261FD has been marked as claimed.',NULL,1,'2026-11-16 09:32:25'),
(24,28,'Order placed','Your order CC-526D4BE7-20261117 has been created.',19,0,'2026-11-17 09:25:11');
DROP TABLE IF EXISTS `order_items`;
CREATE TABLE `order_items` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `order_id` int(11) NOT NULL,
  `product_id` int(11) NOT NULL,
  `quantity` int(11) NOT NULL,
  `selected_size` varchar(50) DEFAULT NULL,
  `unit_price` decimal(10,2) NOT NULL,
  PRIMARY KEY (`id`),
  KEY `order_id` (`order_id`),
  KEY `product_id` (`product_id`),
  CONSTRAINT `order_items_ibfk_1` FOREIGN KEY (`order_id`) REFERENCES `orders` (`id`) ON DELETE CASCADE,
  CONSTRAINT `order_items_ibfk_2` FOREIGN KEY (`product_id`) REFERENCES `products` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=15 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO `order_items` VALUES
(10,14,1,2,'',250.00),
(14,19,21,1,'M',50.00);
DROP TABLE IF EXISTS `order_status_history`;
CREATE TABLE `order_status_history` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `order_id` int(11) NOT NULL,
  `old_status` varchar(30) DEFAULT NULL,
  `new_status` varchar(30) NOT NULL,
  `changed_by_user_id` int(11) DEFAULT NULL,
  `reason` varchar(500) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `order_status_history_order_idx` (`order_id`,`created_at`),
  KEY `order_status_history_user_fk` (`changed_by_user_id`),
  CONSTRAINT `order_status_history_order_fk` FOREIGN KEY (`order_id`) REFERENCES `orders` (`id`) ON DELETE CASCADE,
  CONSTRAINT `order_status_history_user_fk` FOREIGN KEY (`changed_by_user_id`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB AUTO_INCREMENT=22 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO `order_status_history` VALUES
(15,14,NULL,'Pending',28,'Order placed by student.','2026-11-16 06:07:41'),
(16,14,'Pending','In Transit',1,NULL,'2026-11-16 06:15:16'),
(17,14,'In Transit','Ready to Claim',1,NULL,'2026-11-16 07:19:10'),
(21,19,NULL,'Pending',28,'Order placed by student.','2026-11-17 09:25:11');
DROP TABLE IF EXISTS `orders`;
CREATE TABLE `orders` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `user_id` int(11) NOT NULL,
  `reference_number` varchar(50) NOT NULL,
  `total_amount` decimal(10,2) NOT NULL,
  `payment_method` varchar(50) NOT NULL,
  `payment_status` varchar(30) NOT NULL DEFAULT 'Unpaid',
  `order_status` varchar(30) NOT NULL DEFAULT 'Pending',
  `payment_reference` varchar(150) DEFAULT NULL,
  `payment_proof_path` varchar(255) DEFAULT NULL,
  `qr_token` varchar(255) DEFAULT NULL,
  `claim_date` datetime DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  `scheduled_pickup_at` datetime DEFAULT NULL,
  `claimed_by_admin_id` int(11) DEFAULT NULL,
  `qr_token_hash` char(64) DEFAULT NULL,
  `qr_token_nonce` char(32) DEFAULT NULL,
  `pickup_schedule_id` int(11) DEFAULT NULL,
  `verified_at` datetime DEFAULT NULL,
  `cancelled_at` datetime DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `reference_number` (`reference_number`),
  UNIQUE KEY `qr_token` (`qr_token`),
  UNIQUE KEY `orders_qr_token_hash_unique` (`qr_token_hash`),
  KEY `user_id` (`user_id`),
  KEY `orders_claimed_by_admin_idx` (`claimed_by_admin_id`),
  KEY `orders_pickup_schedule_idx` (`pickup_schedule_id`),
  KEY `orders_ready_to_claim_idx` (`payment_status`,`order_status`,`verified_at`),
  CONSTRAINT `orders_claimed_by_admin_fk` FOREIGN KEY (`claimed_by_admin_id`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  CONSTRAINT `orders_ibfk_1` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE,
  CONSTRAINT `orders_pickup_schedule_fk` FOREIGN KEY (`pickup_schedule_id`) REFERENCES `pickup_schedules` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB AUTO_INCREMENT=20 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO `orders` VALUES
(14,28,'CC-5F2DF2F1-20261116',500.00,'Cash','Paid','Ready to Claim','143',NULL,'5a23f486705dfa0ddb638366e768c474',NULL,'2026-11-16 06:07:41','2026-11-17 05:10:55','2026-11-16 16:28:00',NULL,'f0378c4ee7a4ac79cab8d3793d756a2635e5c094c998bb4777e80f0cd40c5f46',NULL,1,'2026-11-16 14:10:16',NULL),
(19,28,'CC-526D4BE7-20261117',50.00,'Cash','Unpaid','Pending',NULL,NULL,NULL,NULL,'2026-11-17 09:25:11','2026-11-17 09:25:11',NULL,NULL,NULL,NULL,NULL,NULL,NULL);
DROP TABLE IF EXISTS `password_reset_tokens`;
CREATE TABLE `password_reset_tokens` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `user_id` int(11) NOT NULL,
  `token` varchar(255) NOT NULL,
  `expires_at` datetime NOT NULL,
  `used_at` datetime DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `user_id` (`user_id`),
  CONSTRAINT `password_reset_tokens_ibfk_1` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

DROP TABLE IF EXISTS `payments`;
CREATE TABLE `payments` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `order_id` int(11) NOT NULL,
  `payment_method` varchar(50) NOT NULL,
  `amount` decimal(10,2) NOT NULL,
  `status` varchar(32) NOT NULL DEFAULT 'Pending Verification',
  `transaction_reference` varchar(150) DEFAULT NULL,
  `proof_path` varchar(255) DEFAULT NULL,
  `rejection_reason` varchar(500) DEFAULT NULL,
  `submitted_at` datetime NOT NULL DEFAULT current_timestamp(),
  `reviewed_by_admin_id` int(11) DEFAULT NULL,
  `reviewed_at` datetime DEFAULT NULL,
  `paid_at` datetime DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `payments_order_status_idx` (`order_id`,`status`,`created_at`),
  KEY `payments_review_idx` (`status`,`submitted_at`),
  KEY `payments_reviewed_by_admin_fk` (`reviewed_by_admin_id`),
  CONSTRAINT `payments_order_fk` FOREIGN KEY (`order_id`) REFERENCES `orders` (`id`) ON DELETE CASCADE,
  CONSTRAINT `payments_reviewed_by_admin_fk` FOREIGN KEY (`reviewed_by_admin_id`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB AUTO_INCREMENT=13 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO `payments` VALUES
(10,14,'Cash',500.00,'Paid','143',NULL,NULL,'2026-11-16 14:07:41',1,'2026-11-16 14:10:16','2026-11-16 14:10:16','2026-11-16 06:07:41','2026-11-16 06:10:16'),
(12,19,'Cash',50.00,'Unpaid',NULL,NULL,NULL,'2026-11-17 17:25:11',NULL,NULL,NULL,'2026-11-17 09:25:11','2026-11-17 09:25:11');
DROP TABLE IF EXISTS `pickup_schedules`;
CREATE TABLE `pickup_schedules` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `pickup_date` date NOT NULL,
  `start_time` time NOT NULL,
  `end_time` time NOT NULL,
  `capacity` int(11) DEFAULT NULL,
  `is_active` tinyint(1) NOT NULL DEFAULT 1,
  `created_by_admin_id` int(11) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `pickup_schedules_available_idx` (`is_active`,`pickup_date`,`start_time`),
  KEY `pickup_schedules_admin_fk` (`created_by_admin_id`),
  CONSTRAINT `pickup_schedules_admin_fk` FOREIGN KEY (`created_by_admin_id`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB AUTO_INCREMENT=5 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO `pickup_schedules` VALUES
(1,'2026-11-16','16:28:00','21:28:00',10,1,1,'2026-11-16 08:28:14','2026-11-16 08:28:14');
DROP TABLE IF EXISTS `product_sizes`;
CREATE TABLE `product_sizes` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `product_id` int(11) NOT NULL,
  `size` varchar(50) NOT NULL,
  `stock` int(11) NOT NULL DEFAULT 0,
  `is_active` tinyint(1) NOT NULL DEFAULT 1,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `product_sizes_product_size_unique` (`product_id`,`size`),
  KEY `product_sizes_available_idx` (`product_id`,`is_active`,`stock`),
  CONSTRAINT `product_sizes_product_fk` FOREIGN KEY (`product_id`) REFERENCES `products` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=11 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO `product_sizes` VALUES
(9,21,'S',50,1,'2026-11-17 06:05:49','2026-11-17 06:07:16'),
(10,21,'M',499,1,'2026-11-17 06:05:49','2026-11-17 09:25:11');
DROP TABLE IF EXISTS `products`;
CREATE TABLE `products` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `name` varchar(150) NOT NULL,
  `description` text DEFAULT NULL,
  `category` varchar(100) NOT NULL,
  `price` decimal(10,2) NOT NULL,
  `stock` int(11) NOT NULL DEFAULT 0,
  `image_url` varchar(255) DEFAULT NULL,
  `is_active` tinyint(1) NOT NULL DEFAULT 1,
  `created_at` timestamp NULL DEFAULT current_timestamp(),
  `has_sizes` tinyint(1) NOT NULL DEFAULT 0,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB AUTO_INCREMENT=25 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO `products` VALUES
(1,'TYPE B Uniform','Premium school uniform with breathable fabric.','Uniforms',250.00,43,'https://images.unsplash.com/photo-1521572267360-ee0c2909d518?auto=format&fit=crop&w=900&q=80',1,'2026-11-15 05:33:32',0),
(2,'ID Lace','Official student identification lace accessory.','Accessories',120.00,100,'https://images.unsplash.com/photo-1521572267360-ee0c2909d518?auto=format&fit=crop&w=900&q=80',1,'2026-11-15 05:33:32',0),
(21,'helo','dasd','dsad',50.00,549,NULL,1,'2026-11-17 06:05:49',1);
DROP TABLE IF EXISTS `realtime_events`;
CREATE TABLE `realtime_events` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `event_type` varchar(64) NOT NULL,
  `audience` enum('admin','student','public') NOT NULL,
  `recipient_user_id` int(11) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `realtime_events_created_idx` (`created_at`),
  KEY `realtime_events_recipient_idx` (`audience`,`recipient_user_id`,`id`)
) ENGINE=InnoDB AUTO_INCREMENT=11 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO `realtime_events` VALUES
(1,'notifications.updated','student',28,'2026-11-17 07:14:50'),
(2,'notifications.updated','student',28,'2026-11-17 07:15:00'),
(3,'notifications.updated','student',28,'2026-11-17 07:15:01'),
(4,'notifications.updated','student',28,'2026-11-17 07:15:02'),
(6,'notifications.updated','student',28,'2026-11-17 08:14:13'),
(7,'order.created','admin',NULL,'2026-11-17 09:25:11'),
(8,'order.updated','student',28,'2026-11-17 09:25:11'),
(9,'catalog.updated','public',NULL,'2026-11-17 09:25:11'),
(10,'cart.updated','student',28,'2026-11-17 09:42:41');
DROP TABLE IF EXISTS `schema_migrations`;
CREATE TABLE `schema_migrations` (
  `migration_key` varchar(80) NOT NULL,
  `applied_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`migration_key`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO `schema_migrations` VALUES
('auth_security_20261115','2026-11-15 06:00:04'),
('auth_security_20261115_v2','2026-11-15 06:09:05'),
('disable_legacy_seeded_admin_20261117_v1','2026-11-17 06:55:33'),
('order_claim_qr_20261116_v1','2026-11-16 06:37:40'),
('order_verification_dates_20261117_v1','2026-11-17 05:10:55'),
('payments_orders_20261115_v1','2026-11-15 06:43:43'),
('product_sizes_pickup_slots_20261116_v1','2026-11-16 06:44:04'),
('student_profile_images_20261117_v1','2026-11-17 06:07:06');
DROP TABLE IF EXISTS `users`;
CREATE TABLE `users` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `first_name` varchar(100) NOT NULL,
  `middle_name` varchar(100) DEFAULT NULL,
  `last_name` varchar(100) NOT NULL,
  `email` varchar(150) NOT NULL,
  `profile_image` varchar(255) DEFAULT NULL,
  `password_hash` varchar(255) NOT NULL,
  `role` enum('student','admin') NOT NULL DEFAULT 'student',
  `security_question` varchar(255) DEFAULT NULL,
  `security_answer_hash` varchar(255) DEFAULT NULL,
  `is_verified` tinyint(1) NOT NULL DEFAULT 0,
  `is_active` tinyint(1) NOT NULL DEFAULT 1,
  `must_change_password` tinyint(1) NOT NULL DEFAULT 0,
  `last_login` datetime DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  `username` varchar(50) DEFAULT NULL,
  `security_question_id` tinyint(3) unsigned DEFAULT NULL,
  `account_status` varchar(24) NOT NULL DEFAULT 'active',
  `registration_status` varchar(24) NOT NULL DEFAULT 'completed',
  `email_verified_at` datetime DEFAULT NULL,
  `last_login_at` datetime DEFAULT NULL,
  `password_changed_at` datetime DEFAULT NULL,
  `security_updated_at` datetime DEFAULT NULL,
  `auth_version` int(10) unsigned NOT NULL DEFAULT 1,
  PRIMARY KEY (`id`),
  UNIQUE KEY `email` (`email`),
  UNIQUE KEY `users_username_unique` (`username`)
) ENGINE=InnoDB AUTO_INCREMENT=43 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO `users` VALUES
(1,'System',NULL,'Admin','admin123@cscqc.edu.ph',NULL,'$2y$12$GLSz98n9dSKBBify1V5/W.SdOlb4vsrW/ZPJ70YUOUBprKrJ6jqSC','admin',NULL,NULL,1,0,0,'2026-11-17 14:00:40','2026-11-15 05:33:32','2026-11-17 06:55:33','admin',NULL,'inactive','completed','2026-11-15 13:33:32','2026-11-17 14:00:40','2026-11-15 14:14:04',NULL,2),
(28,'Vince','DelosSantos','Sareno','sareno.20242247@cscqc.edu.ph',NULL,'$2y$12$hwaUaadYIjAv0ew550T7f.ijqwkZzzkGcBiFozz4hCdIj7bFCHG.C','student','What is your mother\'s maiden name?','$2y$12$ntnA2Ara59hgsgzPUATHsuo2eMurFbCgqT/8mKMet0YvWE0zB7zXO',1,1,0,'2026-11-17 17:23:06','2026-11-16 05:08:09','2026-11-17 09:23:06',NULL,NULL,'active','completed','2026-11-16 14:04:53','2026-11-17 17:23:06',NULL,NULL,5),
(42,'CVCarts',NULL,'Administrator','cvcarts40@gmail.com',NULL,'$2y$12$0zDNSb8.hLP1FRBPUlqq/.K44kwPWUTFMcwxd.n0FX0ov1yHHb7p6','admin',NULL,NULL,1,1,0,'2026-11-17 16:20:42','2026-11-17 07:20:48','2026-11-17 08:20:42','admin1',NULL,'active','completed','2026-11-17 15:20:48','2026-11-17 16:20:42','2026-11-17 15:21:35',NULL,2);

SET UNIQUE_CHECKS=1;
SET FOREIGN_KEY_CHECKS=1;