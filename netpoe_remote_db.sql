-- --------------------------------------------------------
-- Host:                         202.47.185.158
-- Server version:               8.0.46 - MySQL Community Server - GPL
-- Server OS:                    Linux
-- HeidiSQL Version:             12.8.0.6908
-- --------------------------------------------------------

/*!40101 SET @OLD_CHARACTER_SET_CLIENT=@@CHARACTER_SET_CLIENT */;
/*!40101 SET NAMES utf8 */;
/*!50503 SET NAMES utf8mb4 */;
/*!40103 SET @OLD_TIME_ZONE=@@TIME_ZONE */;
/*!40103 SET TIME_ZONE='+00:00' */;
/*!40014 SET @OLD_FOREIGN_KEY_CHECKS=@@FOREIGN_KEY_CHECKS, FOREIGN_KEY_CHECKS=0 */;
/*!40101 SET @OLD_SQL_MODE=@@SQL_MODE, SQL_MODE='NO_AUTO_VALUE_ON_ZERO' */;
/*!40111 SET @OLD_SQL_NOTES=@@SQL_NOTES, SQL_NOTES=0 */;

-- Dumping structure for table netpoe_remote.routers
CREATE TABLE IF NOT EXISTS `routers` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `user_id` int unsigned NOT NULL,
  `router_name` varchar(100) COLLATE utf8mb4_unicode_ci NOT NULL,
  `ip_address` varchar(45) COLLATE utf8mb4_unicode_ci NOT NULL,
  `api_user` varchar(100) COLLATE utf8mb4_unicode_ci NOT NULL,
  `api_pass` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `api_port` smallint unsigned NOT NULL DEFAULT '8728',
  `public_host` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `remote_port` smallint unsigned NOT NULL DEFAULT '8080',
  `remote_nat_comment` varchar(100) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'DYNAMIC_REMOTE_MODEM',
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_routers_user_id` (`user_id`),
  CONSTRAINT `fk_routers_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=13 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Dumping data for table netpoe_remote.routers: ~11 rows (approximately)
INSERT INTO `routers` (`id`, `user_id`, `router_name`, `ip_address`, `api_user`, `api_pass`, `api_port`, `public_host`, `remote_port`, `remote_nat_comment`, `created_at`) VALUES
	(1, 2, 'Router MikroTik', '10.99.99.2', 'maja', 'maja2022', 8229, '202.47.185.158', 8188, 'DYNAMIC_REMOTE_MODEM', '2026-07-31 13:39:49'),
	(2, 3, 'Router MikroTik', '10.99.99.4', 'maja', 'maja2022', 8728, '202.47.185.158', 8688, 'DYNAMIC_REMOTE_MODEM', '2026-07-31 15:26:59'),
	(3, 8, 'Router MikroTik', '10.99.99.8', 'maja', 'maja2022', 8728, '202.47.185.158', 8488, 'DYNAMIC_REMOTE_MODEM', '2026-07-31 15:45:28'),
	(4, 4, 'Router MikroTik', '10.99.99.42', 'maja', 'maja2022', 8728, '202.47.185.158', 8588, 'DYNAMIC_REMOTE_MODEM', '2026-07-31 17:07:16'),
	(5, 9, 'Router MikroTik', '10.10.10.10', 'Diaz_Jayasampurna', '857121', 8728, '202.47.185.158', 1088, 'DYNAMIC_REMOTE_MODEM', '2026-07-31 17:13:54'),
	(6, 10, 'Router MikroTik', '10.99.99.28', 'Diaz_Jayasampurna', '857121', 8728, '202.47.185.158', 1388, 'DYNAMIC_REMOTE_MODEM', '2026-07-31 17:20:54'),
	(8, 7, 'Router MikroTik', '10.99.99.24', 'reza', 'rezapalingganteng', 8282, '202.47.185.158', 8988, 'DYNAMIC_REMOTE_MODEM', '2026-07-31 17:32:39'),
	(9, 12, 'Router MikroTik', '10.99.99.16', 'admin', 'telkomjuara', 8728, '202.47.185.158', 1888, 'DYNAMIC_REMOTE_MODEM', '2026-07-31 17:40:21'),
	(10, 13, 'Router MikroTik', '10.99.99.34', 'Ijenk3brmb', '18088482', 8728, '202.47.185.158', 8980, 'DYNAMIC_REMOTE_MODEM', '2026-08-02 15:31:18'),
	(11, 14, 'Router MikroTik', '10.99.99.48', 'jajang', 'jajang2025', 8728, '202.47.185.158', 1090, 'DYNAMIC_REMOTE_MODEM', '2026-08-03 03:19:35'),
	(12, 15, 'Router MikroTik', '10.99.99.10', 'Obeth', 'obeth1093', 8728, '202.47.185.158', 6288, 'DYNAMIC_REMOTE_MODEM', '2026-08-03 03:51:39');

-- Dumping structure for table netpoe_remote.users
CREATE TABLE IF NOT EXISTS `users` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `username` varchar(50) COLLATE utf8mb4_unicode_ci NOT NULL,
  `password` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `role` enum('superuser','user') COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'user',
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `username` (`username`)
) ENGINE=InnoDB AUTO_INCREMENT=16 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Dumping data for table netpoe_remote.users: ~13 rows (approximately)
INSERT INTO `users` (`id`, `username`, `password`, `role`, `created_at`) VALUES
	(1, 'maja', '$2y$10$5ha6.4UQzPU3AmVD1KgPu.YJXpuhifZLYE0Sjiepenf50zPYWBAwa', 'superuser', '2026-07-31 12:14:02'),
	(2, 'firdaus', '$2y$10$YwqlFoE7x.cg1DLkq5088ev8EbA9MPftvlcAhWvq70p8GcMO.WlL2', 'user', '2026-07-31 12:18:32'),
	(3, 'ricka', '$2y$10$cprko2Y4/IQeqNACL7jIQe.d2vpTPfEAEkXvnWMNCvJvfXSDnFN3m', 'user', '2026-07-31 14:57:26'),
	(4, 'erbe', '$2y$10$YdiHxVCYOs92Rp9fNjJ1f.GMBwU7N6tR4b6mhzkf9mVXmRMHHOWji', 'user', '2026-07-31 14:57:37'),
	(5, 'pkr', '$2y$10$F709w6YKDeTOwHVhlSCZteNku10JaqrJUlZ/VGaRQ1hGh9oWXlbiO', 'user', '2026-07-31 14:57:51'),
	(7, 'dns', '$2y$10$.qabq2BRY.AXdI00Vd0iSet2Sk8v/FG90Ehz86k5VaQoyx/vgxARC', 'user', '2026-07-31 14:58:13'),
	(8, 'pyon', '$2y$10$Aef9NiBBK.99pIzGWUxePOzz2vBoCKJdg6RyuFZl/BZgOy5dvXRN.', 'user', '2026-07-31 15:44:49'),
	(9, 'ghaib', '$2y$10$GQq6MhjHeoFKiVAQCcaujOlAistUPQnur5OSSBtsLNOwbyhywifHm', 'user', '2026-07-31 17:11:07'),
	(10, 'jaya', '$2y$10$jb/g60o89rfOpEZFLQkZQOdsJdj8nQnCboE76vBzOn0fiolXSRCSi', 'user', '2026-07-31 17:19:03'),
	(12, 'gabel', '$2y$10$6m2fUpUujVRZImaiSEJkYumzw/VKGW1ba9SDd.v8QprW.wPavDA1S', 'user', '2026-07-31 17:39:12'),
	(13, 'brimob', '$2y$10$J/mzbq.1jOK5UI8OLe6kQumkbbB52ms49WPEqLjXIM86fEbO4wtAq', 'user', '2026-08-02 15:28:44'),
	(14, 'gtv', '$2y$10$jcx6/6PD1Z.TkKxOpaBBbOqu5yKrbNBErnnZoXeDkx3adOdJmn4RG', 'user', '2026-08-03 03:18:32'),
	(15, 'ksb', '$2y$10$9g.mK8RXcV34eBFQtGSgCOTH8IdLNHEOWlkmTJzjs8ykVTjGmQ/Z2', 'user', '2026-08-03 03:50:03');

/*!40103 SET TIME_ZONE=IFNULL(@OLD_TIME_ZONE, 'system') */;
/*!40101 SET SQL_MODE=IFNULL(@OLD_SQL_MODE, '') */;
/*!40014 SET FOREIGN_KEY_CHECKS=IFNULL(@OLD_FOREIGN_KEY_CHECKS, 1) */;
/*!40101 SET CHARACTER_SET_CLIENT=@OLD_CHARACTER_SET_CLIENT */;
/*!40111 SET SQL_NOTES=IFNULL(@OLD_SQL_NOTES, 1) */;
