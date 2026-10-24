-- MariaDB dump 10.19  Distrib 10.4.32-MariaDB, for Win64 (AMD64)
--
-- Host: localhost    Database: sipala
-- ------------------------------------------------------
-- Server version	10.4.32-MariaDB

/*!40101 SET @OLD_CHARACTER_SET_CLIENT=@@CHARACTER_SET_CLIENT */;
/*!40101 SET @OLD_CHARACTER_SET_RESULTS=@@CHARACTER_SET_RESULTS */;
/*!40101 SET @OLD_COLLATION_CONNECTION=@@COLLATION_CONNECTION */;
/*!40101 SET NAMES utf8mb4 */;
/*!40103 SET @OLD_TIME_ZONE=@@TIME_ZONE */;
/*!40103 SET TIME_ZONE='+00:00' */;
/*!40014 SET @OLD_UNIQUE_CHECKS=@@UNIQUE_CHECKS, UNIQUE_CHECKS=0 */;
/*!40014 SET @OLD_FOREIGN_KEY_CHECKS=@@FOREIGN_KEY_CHECKS, FOREIGN_KEY_CHECKS=0 */;
/*!40101 SET @OLD_SQL_MODE=@@SQL_MODE, SQL_MODE='NO_AUTO_VALUE_ON_ZERO' */;
/*!40111 SET @OLD_SQL_NOTES=@@SQL_NOTES, SQL_NOTES=0 */;

--
-- Current Database: `sipala`
--

CREATE DATABASE /*!32312 IF NOT EXISTS*/ `sipala` /*!40100 DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci */;

USE `sipala`;

--
-- Table structure for table `detail_peminjaman`
--

DROP TABLE IF EXISTS `detail_peminjaman`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `detail_peminjaman` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `peminjaman_id` int(10) unsigned NOT NULL,
  `peralatan_id` int(10) unsigned NOT NULL,
  `kode_barang_snapshot` varchar(50) DEFAULT NULL,
  `nama_barang_snapshot` varchar(150) DEFAULT NULL,
  `unit_peralatan_id` int(10) unsigned DEFAULT NULL,
  `kode_unit_snapshot` varchar(60) DEFAULT NULL,
  `barcode_snapshot` varchar(64) DEFAULT NULL,
  `jumlah` int(10) unsigned NOT NULL,
  `kondisi_awal` enum('Baik','Rusak Ringan','Rusak Berat') NOT NULL DEFAULT 'Baik',
  `kondisi_akhir` enum('Baik','Rusak Ringan','Rusak Berat') DEFAULT NULL,
  `catatan_kondisi` text DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_peminjaman_unit` (`peminjaman_id`,`unit_peralatan_id`),
  KEY `idx_detail_peralatan` (`peralatan_id`),
  KEY `fk_detail_unit_peralatan` (`unit_peralatan_id`),
  KEY `idx_detail_peminjaman` (`peminjaman_id`),
  CONSTRAINT `fk_detail_peminjaman` FOREIGN KEY (`peminjaman_id`) REFERENCES `peminjaman` (`id`) ON UPDATE CASCADE,
  CONSTRAINT `fk_detail_peralatan` FOREIGN KEY (`peralatan_id`) REFERENCES `peralatan` (`id`) ON UPDATE CASCADE,
  CONSTRAINT `fk_detail_unit_peralatan` FOREIGN KEY (`unit_peralatan_id`) REFERENCES `unit_peralatan` (`id`) ON DELETE SET NULL,
  CONSTRAINT `chk_detail_jumlah` CHECK (`jumlah` > 0)
) ENGINE=InnoDB AUTO_INCREMENT=2 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `detail_peminjaman`
--

LOCK TABLES `detail_peminjaman` WRITE;
/*!40000 ALTER TABLE `detail_peminjaman` DISABLE KEYS */;
INSERT INTO `detail_peminjaman` VALUES (1,1,2,NULL,NULL,NULL,NULL,NULL,1,'Baik',NULL,NULL);
/*!40000 ALTER TABLE `detail_peminjaman` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `kategori`
--

DROP TABLE IF EXISTS `kategori`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `kategori` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `nama_kategori` varchar(100) NOT NULL,
  `deskripsi` text DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_kategori_nama` (`nama_kategori`)
) ENGINE=InnoDB AUTO_INCREMENT=10 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `kategori`
--

LOCK TABLES `kategori` WRITE;
/*!40000 ALTER TABLE `kategori` DISABLE KEYS */;
INSERT INTO `kategori` VALUES (2,'RFID READER','alat untuk scan gelombang elektronik','2026-09-10 02:46:47','2026-09-10 07:13:03'),(5,'IoT & Embedded Systems','Mikrokontroler, modul sensor, kit robotika, dan development board IoT untuk praktikum perangkat pintar.','2026-09-24 07:29:12','2026-09-24 07:29:12'),(6,'Jaringan Komputer','Router, switch managed/unmanaged, access point, tang crimping, tester kabel LAN, dan perangkat transmisi jaringan.','2026-09-24 07:29:12','2026-09-24 07:29:12'),(7,'Hardware & Servis Komputer','Obeng presisi, multimeter digital, solder station temperatur, PSU tester, dan alat diagnostik reparasi PC/laptop.','2026-09-24 07:29:12','2026-09-24 07:29:12'),(8,'Multimedia & Desain UI/UX','Pen tablet grafis, kamera dokumentasi sistem, tripod studio, wireless microphone, dan pencahayaan digital.','2026-09-24 07:29:12','2026-09-24 07:29:12'),(9,'Peripheral & Testing Tools','Barcode/QR scanner handheld, smart card reader RFID, flashdisk bootable multi-OS, portable SSD, dan monitor eksternal.','2026-09-24 07:29:12','2026-09-24 07:29:12');
/*!40000 ALTER TABLE `kategori` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `log_aktivitas`
--

DROP TABLE IF EXISTS `log_aktivitas`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `log_aktivitas` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `user_id` int(10) unsigned DEFAULT NULL,
  `aksi` varchar(50) NOT NULL DEFAULT 'UMUM',
  `modul` varchar(50) NOT NULL DEFAULT 'SYSTEM',
  `reference_id` int(10) unsigned DEFAULT NULL,
  `aktivitas` varchar(100) NOT NULL DEFAULT '',
  `deskripsi` text NOT NULL,
  `ip_address` varchar(45) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_log_user_created` (`user_id`,`created_at`),
  KEY `idx_log_aktivitas_created` (`aktivitas`,`created_at`),
  CONSTRAINT `fk_log_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=175 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `log_aktivitas`
--

LOCK TABLES `log_aktivitas` WRITE;
/*!40000 ALTER TABLE `log_aktivitas` DISABLE KEYS */;
INSERT INTO `log_aktivitas` VALUES (1,1,'UMUM','SYSTEM',NULL,'LOGIN','User berhasil login ke SIPALA.','::1','2026-09-09 19:26:19'),(2,1,'UMUM','SYSTEM',NULL,'LOGIN','User berhasil login ke SIPALA.','::1','2026-09-09 20:00:35'),(3,1,'UMUM','SYSTEM',NULL,'LOGIN','User berhasil login ke SIPALA.','::1','2026-09-09 20:05:08'),(4,1,'UMUM','SYSTEM',NULL,'LOGIN','User berhasil login ke SIPALA.','::1','2026-09-09 20:06:37'),(5,1,'UMUM','SYSTEM',NULL,'LOGIN','User berhasil login ke SIPALA.','::1','2026-09-09 20:08:48'),(6,1,'UMUM','SYSTEM',NULL,'LOGIN','User berhasil login ke SIPALA.','::1','2026-09-09 20:09:22'),(7,1,'UMUM','SYSTEM',NULL,'LOGIN','User berhasil login ke SIPALA.','::1','2026-09-09 20:32:10'),(8,1,'UMUM','SYSTEM',NULL,'LOGIN','User berhasil login ke SIPALA.','::1','2026-09-09 20:32:54'),(9,1,'UMUM','SYSTEM',NULL,'LOGIN','User berhasil login ke SIPALA.','::1','2026-09-10 01:16:13'),(10,1,'UMUM','SYSTEM',NULL,'LOGIN','User berhasil login ke SIPALA.','::1','2026-09-10 01:35:24'),(11,NULL,'REQUEST','PEMINJAMAN',12,'','User M ABIJAR SEPTIAN mengajukan peminjaman TEST-20260917091148 (Test)','127.0.0.1','2026-09-17 07:11:48'),(12,1,'APPROVE','PEMINJAMAN',12,'','Admin menyetujui peminjaman TEST-20260917091148','127.0.0.1','2026-09-17 07:11:48'),(13,1,'HANDOVER','PEMINJAMAN',12,'','Admin menyerahkan unit 123-001 untuk peminjaman TEST-20260917091148','127.0.0.1','2026-09-17 07:11:48'),(22,1,'UPDATE','PERALATAN',2,'','Memperbarui master peralatan rf id reader (123)','::1','2026-09-17 07:21:10'),(23,1,'UPDATE','PERALATAN',2,'','Memperbarui master peralatan rf id reader (123)','::1','2026-09-17 07:21:40'),(24,1,'LOGIN_SUCCESS','AUTH',1,'','Login berhasil via akun: admin','::1','2026-09-17 07:26:14'),(25,1,'LOGIN_SUCCESS','AUTH',1,'','Login berhasil via akun: admin','::1','2026-09-17 07:26:30'),(26,1,'LOGIN_SUCCESS','AUTH',1,'','Login berhasil via akun: admin','::1','2026-09-17 07:26:41'),(27,1,'LOGIN_SUCCESS','AUTH',1,'','Login berhasil via akun: admin','::1','2026-09-17 07:26:45'),(28,1,'LOGIN_SUCCESS','AUTH',1,'','Login berhasil via akun: admin','::1','2026-09-17 07:26:53'),(29,1,'LOGIN_SUCCESS','AUTH',1,'','Login berhasil via akun: admin','::1','2026-09-17 07:27:11'),(30,1,'UPDATE','PERALATAN',2,'','Memperbarui master peralatan rf id reader (123), total stok: 18, tersedia: 18','::1','2026-09-17 07:27:12'),(31,1,'UPDATE','PERALATAN',2,'','Memperbarui master peralatan rf id reader (123), total stok: 15, tersedia: 15','::1','2026-09-17 07:27:12'),(32,1,'UPDATE','PERALATAN',2,'','Memperbarui master peralatan rf id reader (123), total stok: 10, tersedia: 10','::1','2026-09-17 07:28:00'),(33,NULL,'LOGIN_FAILED','AUTH',NULL,'','Login gagal untuk username/NIS: abijar','::1','2026-09-17 07:50:20'),(34,NULL,'LOGIN_FAILED','AUTH',NULL,'','Login gagal untuk username/NIS: abijar','::1','2026-09-17 07:50:36'),(35,1,'LOGIN_SUCCESS','AUTH',1,'','Login berhasil via akun: admin','::1','2026-09-17 07:50:55'),(36,NULL,'LOGIN_SUCCESS','AUTH',14,'','Login berhasil via akun: bijar','::1','2026-09-17 07:51:24'),(37,1,'LOGIN_SUCCESS','AUTH',1,'','Login berhasil via akun: admin','::1','2026-09-17 07:59:10'),(38,1,'LOGIN_SUCCESS','AUTH',1,'','Login berhasil via akun: admin','::1','2026-09-17 08:08:25'),(39,NULL,'LOGIN_SUCCESS','AUTH',14,'','Login berhasil via akun: bijar','::1','2026-09-17 08:08:25'),(40,1,'LOGIN_SUCCESS','AUTH',1,'','Login berhasil via akun: admin','::1','2026-09-17 08:13:15'),(41,1,'LOGIN_SUCCESS','AUTH',1,'','Login berhasil via akun: admin','::1','2026-09-17 08:17:31'),(42,1,'LOGIN_SUCCESS','AUTH',1,'','Login berhasil via akun: admin','::1','2026-09-17 08:20:15'),(43,1,'LOGIN_SUCCESS','AUTH',1,'','Login berhasil via akun: admin','::1','2026-09-17 08:53:02'),(44,1,'LOGIN_SUCCESS','AUTH',1,'','Login berhasil via akun: admin','::1','2026-09-17 09:13:11'),(45,1,'LOGIN_SUCCESS','AUTH',1,'','Login berhasil via akun: admin','::1','2026-09-17 09:13:21'),(46,1,'CREATE','PERALATAN',3,'','Menambahkan master peralatan Multimeter Digital Test (TEST-KAT-3112) dan 3 unit fisik','::1','2026-09-17 09:13:21'),(47,NULL,'LOGIN_FAILED','AUTH',NULL,'','Login gagal untuk identitas: invalid_user','127.0.0.1','2026-09-17 13:37:14'),(48,1,'LOGIN_SUCCESS','AUTH',1,'','Login berhasil via Akun: admin','::1','2026-09-17 14:09:41'),(49,1,'LOGIN_SUCCESS','AUTH',1,'','Login berhasil via Akun: admin','::1','2026-09-17 14:11:23'),(50,1,'LOGIN_SUCCESS','AUTH',1,'','Login berhasil via Akun: admin','::1','2026-09-17 14:16:07'),(51,1,'LOGIN_SUCCESS','AUTH',1,'','Login berhasil via Akun: admin','::1','2026-09-17 14:16:40'),(52,NULL,'LOGIN_SUCCESS','AUTH',14,'','Login berhasil via Akun: bijar','::1','2026-09-17 14:17:32'),(53,NULL,'LOGIN_FAILED','AUTH',NULL,'','Login gagal untuk identitas: admin','::1','2026-09-17 14:20:49'),(54,1,'LOGIN_SUCCESS','AUTH',1,'','Login berhasil via Akun: admin','::1','2026-09-17 14:20:49'),(55,1,'LOGIN_SUCCESS','AUTH',1,'','Login berhasil via Akun: admin','::1','2026-09-17 14:23:20'),(56,1,'LOGIN_SUCCESS','AUTH',1,'','Login berhasil via Akun: admin','::1','2026-09-18 01:10:38'),(57,NULL,'LOGIN_SUCCESS','AUTH',14,'','Login berhasil via Akun: bijar','::1','2026-09-18 01:33:19'),(58,NULL,'LOGIN_SUCCESS','AUTH',14,'','Login berhasil via Akun: bijar','::1','2026-09-18 01:38:16'),(59,1,'LOGIN_SUCCESS','AUTH',1,'','Login berhasil via Akun: admin','::1','2026-09-18 02:19:40'),(60,1,'LOGIN_SUCCESS','AUTH',1,'','Login berhasil via Akun: admin','::1','2026-09-18 02:24:15'),(61,1,'LOGIN_SUCCESS','AUTH',1,'','Login berhasil via Akun: admin','::1','2026-09-18 02:44:39'),(62,NULL,'LOGIN_FAILED','AUTH',NULL,'','Login gagal untuk identitas: bijar','127.0.0.1','2026-09-18 03:31:51'),(63,NULL,'LOGIN_SUCCESS','AUTH',14,'','Login berhasil via Akun: bijar','127.0.0.1','2026-09-18 03:32:38'),(64,NULL,'LOGIN_SUCCESS','AUTH',14,'','Login berhasil via Akun: bijar','127.0.0.1','2026-09-18 03:33:17'),(65,NULL,'LOGIN_SUCCESS','AUTH',14,'','Login berhasil via Akun: bijar','127.0.0.1','2026-09-18 03:34:49'),(66,NULL,'LOGIN_SUCCESS','AUTH',14,'','Login berhasil via Akun: bijar','127.0.0.1','2026-09-18 03:35:15'),(67,1,'LOGIN_SUCCESS','AUTH',1,'','Login berhasil via Akun: admin','::1','2026-09-18 03:38:24'),(68,1,'LOGIN_SUCCESS','AUTH',1,'','Login Admin berhasil via RFID','::1','2026-09-21 01:43:59'),(69,1,'LOGIN_SUCCESS','AUTH',1,'','Login Admin berhasil via Akun: admin','::1','2026-09-21 04:05:33'),(70,1,'CREATE','PENGGUNA',15,'','Menambahkan pengguna baru PETUGAS (petugas, role: petugas)','::1','2026-09-21 04:06:52'),(71,NULL,'LOGIN_SUCCESS','AUTH',15,'','Login Admin berhasil via Akun: petugas','::1','2026-09-21 04:07:31'),(72,1,'LOGIN_SUCCESS','AUTH',1,'','Login Admin berhasil via Akun: admin','::1','2026-09-21 04:14:35'),(73,NULL,'LOGIN_SUCCESS','AUTH',14,'','Login berhasil via Akun: bijar','192.168.7.12','2026-09-21 04:16:10'),(74,NULL,'REQUEST','PEMINJAMAN',18,'','Pengajuan pinjaman PJM-20260921-B1DA3 (M ABIJAR SEPTIAN - 1 unit rf id reader)','192.168.7.12','2026-09-21 04:16:33'),(75,NULL,'CANCEL','PEMINJAMAN',18,'','Membatalkan pengajuan PJM-20260921-B1DA3','192.168.7.12','2026-09-21 04:17:02'),(76,1,'LOGIN_SUCCESS','AUTH',1,'','Login Admin berhasil via Akun: admin','::1','2026-09-21 09:43:13'),(77,1,'LOGIN_SUCCESS','AUTH',1,'','Login Admin berhasil via Akun: admin','::1','2026-09-22 00:53:50'),(78,NULL,'LOGIN_SUCCESS','AUTH',14,'','Login berhasil via Akun: bijar','::1','2026-09-22 01:03:16'),(79,NULL,'REQUEST','PEMINJAMAN',19,'','Pengajuan pinjaman PJM-20260922-10A0D (M ABIJAR SEPTIAN - 1 unit rf id reader)','::1','2026-09-22 01:04:03'),(80,1,'LOGIN_SUCCESS','AUTH',1,'','Login Admin berhasil via RFID','::1','2026-09-22 01:06:54'),(81,NULL,'REGISTER','AUTH',16,'','Registrasi akun peminjam baru: REVANDY (00000000, xii rpl 2)','192.168.7.16','2026-09-22 01:07:48'),(82,NULL,'LOGIN_SUCCESS','AUTH',16,'','Login berhasil via Akun: revan','192.168.7.16','2026-09-22 01:08:00'),(83,NULL,'REQUEST','PEMINJAMAN',20,'','Pengajuan pinjaman PJM-20260922-2C600 (REVANDY - 1 unit rf id reader)','192.168.7.16','2026-09-22 01:08:18'),(84,1,'APPROVE','PEMINJAMAN',20,'','Menyetujui pengajuan peminjaman PJM-20260922-2C600','::1','2026-09-22 01:08:59'),(85,1,'BORROW','PEMINJAMAN',20,'','Menyerahkan alat untuk transaksi PJM-20260922-2C600 (Verifikasi RFID: 1706318558)','::1','2026-09-22 01:09:27'),(86,1,'LOGIN_SUCCESS','AUTH',1,'','Login Admin berhasil via Akun: admin','::1','2026-09-22 01:14:39'),(87,1,'LOGIN_SUCCESS','AUTH',1,'','Login Admin berhasil via Akun: admin','::1','2026-09-22 01:14:49'),(88,1,'RETURN','PENGEMBALIAN',20,'','Memproses pengembalian PJM-20260922-2C600 (Kondisi: Baik, Terlambat: 0 hari, Denda: Rp 0)','::1','2026-09-22 03:20:30'),(89,NULL,'LOGIN_SUCCESS','AUTH',14,'','Login berhasil via Akun: bijar','::1','2026-09-22 04:46:36'),(90,1,'LOGIN_SUCCESS','AUTH',1,'','Login berhasil via Akun: admin','::1','2026-09-22 04:56:27'),(91,1,'APPROVE','PEMINJAMAN',19,'','Menyetujui pengajuan peminjaman PJM-20260922-10A0D','127.0.0.1','2026-09-22 05:09:50'),(92,1,'RETURN','PENGEMBALIAN',19,'','Memproses pengembalian PJM-20260922-10A0D (Kondisi: Baik, Terlambat: 0 hari, Denda: Rp 0)','127.0.0.1','2026-09-22 05:09:50'),(93,NULL,'LOGIN_SUCCESS','AUTH',14,'','Login berhasil via Akun: bijar','::1','2026-09-22 05:11:33'),(94,NULL,'REQUEST','PEMINJAMAN',21,'','Pengajuan pinjaman PJM-20260922-DCC97 (M ABIJAR SEPTIAN - 1 unit rf id reader)','::1','2026-09-22 05:11:33'),(95,NULL,'LOGIN_SUCCESS','AUTH',15,'','Login Admin berhasil via Akun: petugas','::1','2026-09-22 05:11:34'),(96,NULL,'APPROVE','PEMINJAMAN',21,'','Menyetujui pengajuan peminjaman PJM-20260922-DCC97','::1','2026-09-22 05:11:34'),(97,NULL,'BORROW','PEMINJAMAN',21,'','Menyerahkan alat untuk transaksi PJM-20260922-DCC97 (Manual)','::1','2026-09-22 05:11:34'),(98,NULL,'RETURN','PENGEMBALIAN',21,'','Memproses pengembalian PJM-20260922-DCC97 (Kondisi: Baik, Terlambat: 0 hari, Denda: Rp 0)','::1','2026-09-22 05:11:34'),(99,NULL,'LOGIN_SUCCESS','AUTH',15,'','Login Admin berhasil via Akun: petugas','::1','2026-09-22 05:11:50'),(100,NULL,'RETURN','PENGEMBALIAN',22,'','Memproses pengembalian PJM-20260922-LATE1 (Kondisi: Baik, Terlambat: 3 hari, Denda: Rp 9.000)','::1','2026-09-22 05:11:50'),(101,NULL,'PAYMENT','DENDA',12,'','Pelunasan denda PJM-20260922-LATE1 sebesar Rp 9.000','::1','2026-09-22 05:11:51'),(102,NULL,'LOGIN_SUCCESS','AUTH',14,'','Login berhasil via Akun: bijar','::1','2026-09-22 05:21:14'),(103,NULL,'REQUEST','PEMINJAMAN',23,'','Pengajuan pinjaman PJM-20260922-A3A75 (M ABIJAR SEPTIAN - 1 unit rf id reader)','::1','2026-09-22 05:21:14'),(104,NULL,'LOGIN_SUCCESS','AUTH',15,'','Login Admin berhasil via Akun: petugas','::1','2026-09-22 05:21:14'),(105,NULL,'APPROVE','PEMINJAMAN',23,'','Menyetujui pengajuan peminjaman PJM-20260922-A3A75','::1','2026-09-22 05:21:15'),(106,NULL,'BORROW','PEMINJAMAN',23,'','Menyerahkan alat untuk transaksi PJM-20260922-A3A75 (Manual)','::1','2026-09-22 05:21:15'),(107,NULL,'RETURN','PENGEMBALIAN',23,'','Memproses pengembalian PJM-20260922-A3A75 (Kondisi: Baik, Terlambat: 0 hari, Denda: Rp 0)','::1','2026-09-22 05:21:15'),(108,1,'LOGIN_SUCCESS','AUTH',1,'','Login Admin berhasil via Akun: admin','::1','2026-09-22 06:19:29'),(109,1,'LOGIN_SUCCESS','AUTH',1,'','Login Admin berhasil via Akun: admin','::1','2026-09-22 06:19:51'),(110,1,'LOGIN_SUCCESS','AUTH',1,'','Login Admin berhasil via Akun: admin','::1','2026-09-22 06:28:50'),(111,1,'LOGIN_SUCCESS','AUTH',1,'','Login Admin berhasil via Akun: admin','::1','2026-09-22 06:29:25'),(112,NULL,'LOGIN_SUCCESS','AUTH',14,'','Login berhasil via Akun: bijar','::1','2026-09-22 06:29:25'),(113,1,'LOGIN_SUCCESS','AUTH',1,'','Login Admin berhasil via Akun: admin','::1','2026-09-22 07:12:11'),(114,NULL,'LOGIN_SUCCESS','AUTH',14,'','Login berhasil via Akun: bijar','::1','2026-09-22 07:14:42'),(115,1,'LOGIN_SUCCESS','AUTH',1,'','Login Admin berhasil via Akun: admin','::1','2026-09-22 07:15:11'),(116,NULL,'LOGIN_SUCCESS','AUTH',14,'','Login berhasil via Akun: bijar','::1','2026-09-22 07:18:23'),(117,NULL,'LOGIN_SUCCESS','AUTH',14,'','Login berhasil via Akun: bijar','::1','2026-09-22 08:16:52'),(118,NULL,'REQUEST','PEMINJAMAN',24,'','Pengajuan pinjaman PJM-20260922-927C8 (M ABIJAR SEPTIAN - 1 unit rf id reader)','::1','2026-09-22 08:17:13'),(119,NULL,'CANCEL','PEMINJAMAN',24,'','Membatalkan pengajuan PJM-20260922-927C8','::1','2026-09-22 08:17:36'),(120,1,'LOGIN_SUCCESS','AUTH',1,'','Login Admin berhasil via Akun: admin','::1','2026-09-22 08:20:38'),(121,NULL,'LOGIN_SUCCESS','AUTH',14,'','Login berhasil via Akun: bijar','::1','2026-09-22 08:24:40'),(122,1,'LOGIN_SUCCESS','AUTH',1,'','Login Admin berhasil via Akun: admin','::1','2026-09-22 08:26:23'),(123,NULL,'LOGIN_SUCCESS','AUTH',14,'','Login berhasil via Akun: bijar','::1','2026-09-22 08:36:41'),(124,NULL,'LOGIN_SUCCESS','AUTH',14,'','Login berhasil via Akun: bijar','::1','2026-09-22 08:38:40'),(125,NULL,'LOGIN_SUCCESS','AUTH',14,'','Login berhasil via Akun: bijar','::1','2026-09-22 08:41:40'),(126,NULL,'LOGIN_SUCCESS','AUTH',14,'','Login berhasil via Akun: bijar','::1','2026-09-23 01:17:49'),(127,NULL,'LOGIN_SUCCESS','AUTH',14,'','Login berhasil via Akun: bijar','::1','2026-09-23 01:18:42'),(128,1,'LOGIN_SUCCESS','AUTH',1,'','Login Admin berhasil via Akun: admin','::1','2026-09-23 01:23:42'),(129,1,'LOGIN_SUCCESS','AUTH',1,'','Login Admin berhasil via Akun: admin','::1','2026-09-24 03:24:32'),(130,1,'UPDATE','PERALATAN',2,'','Memperbarui master peralatan rf id reader (123), total stok: 10, tersedia: 10','::1','2026-09-24 03:29:23'),(131,NULL,'LOGIN_FAILED','AUTH',NULL,'','Login Admin Akun gagal: kredensial salah (admin)','::1','2026-09-24 07:22:29'),(132,1,'LOGIN_SUCCESS','AUTH',1,'','Login Admin berhasil via Akun: admin','::1','2026-09-24 07:22:39'),(133,NULL,'CREATE','PERALATAN',NULL,'','Seeder: Menambahkan 28 master peralatan baru dan 242 unit fisik untuk ujicoba laboratorium','127.0.0.1','2026-09-24 07:29:15'),(134,NULL,'REPAIR','DATABASE',NULL,'REPAIR','Pemulihan konsistensi: 20 unit fisik ghost-borrowed dipulihkan ke status TERSEDIA','127.0.0.1','2026-10-23 06:46:44'),(137,1,'LOGIN_SUCCESS','AUTH',1,'LOGIN_SUCCESS','Login Admin berhasil via Akun: admin','::1','2026-10-23 06:48:39'),(138,NULL,'LOGIN_SUCCESS','AUTH',14,'LOGIN_SUCCESS','Login berhasil via Kartu RFID','::1','2026-10-23 06:49:09'),(139,1,'LOGIN_SUCCESS','AUTH',1,'LOGIN_SUCCESS','Login Admin berhasil via Akun: admin','::1','2026-10-23 06:51:44'),(140,1,'KIRIM_WA','PEMINJAMAN',24,'KIRIM_WA','Kirim WA (manual) ke 085864656928: ⏰ *PENGINGAT PENGEMBALIAN ALAT SIPALA*\r\n\r\nHalo *...','::1','2026-10-23 06:52:18'),(141,1,'DELETE','UNIT_PERALATAN',123,'DELETE','Menghapus unit peralatan NETWFI04-001 (Barcode: SIP-ALT-000123)','::1','2026-10-23 06:54:06'),(142,1,'DELETE','PERALATAN',NULL,'DELETE','Menghapus seluruh inventaris peralatan (29 master, 251 unit fisik, 15 tag RFID alat) untuk reset inventaris.','127.0.0.1','2026-10-23 06:58:20'),(143,1,'DELETE','PERALATAN',NULL,'DELETE','Menghapus seluruh inventaris peralatan dan unit fisik laboratorium (Reset master peralatan)','127.0.0.1','2026-10-23 06:59:01'),(144,NULL,'REGISTER','AUTH',17,'REGISTER','Registrasi akun peminjam baru: FAHRI HIDAYAT (12333211, 12 RPL 2)','::1','2026-10-23 07:05:53'),(145,NULL,'LOGIN_SUCCESS','AUTH',17,'LOGIN_SUCCESS','Login berhasil via Akun: fahri','::1','2026-10-23 07:06:18'),(146,1,'LOGIN_SUCCESS','AUTH',1,'LOGIN_SUCCESS','Login Admin berhasil via RFID','::1','2026-10-23 07:07:31'),(147,1,'CREATE','PERALATAN',1,'CREATE','Menambahkan master peralatan Test Solder Station 60W (HW-001) dan 2 unit fisik ber-barcode','::1','2026-10-23 08:15:05'),(148,1,'LOGIN_SUCCESS','AUTH',1,'LOGIN_SUCCESS','Login Admin berhasil via RFID','::1','2026-10-23 08:19:56'),(149,1,'LOGIN_SUCCESS','AUTH',1,'LOGIN_SUCCESS','Login Admin berhasil via RFID','::1','2026-10-23 08:38:04'),(150,1,'CREATE','PERALATAN',2,'CREATE','Menambahkan master peralatan reader rf id (DEV-001) dan 5 unit fisik ber-barcode','::1','2026-10-23 08:42:25'),(151,1,'LOGIN_SUCCESS','AUTH',1,'LOGIN_SUCCESS','Login Admin berhasil via RFID','::1','2026-10-24 07:12:58'),(152,NULL,'LOGIN_FAILED','AUTH',NULL,'LOGIN_FAILED','Login Admin Akun gagal: kredensial salah (petugas)','::1','2026-10-24 07:32:54'),(153,NULL,'LOGIN_FAILED','AUTH',NULL,'LOGIN_FAILED','Login Admin Akun gagal: kredensial salah (PETUGAS)','::1','2026-10-24 07:33:04'),(154,NULL,'LOGIN_FAILED','AUTH',NULL,'LOGIN_FAILED','Login Admin Akun gagal: kredensial salah (PETUGAS)','::1','2026-10-24 07:33:16'),(155,1,'LOGIN_SUCCESS','AUTH',1,'LOGIN_SUCCESS','Login Admin berhasil via RFID','::1','2026-10-24 07:33:38'),(156,1,'CREATE','PENGGUNA',18,'CREATE','Menambahkan pengguna baru petugas (petugas, role: petugas)','::1','2026-10-24 07:45:28'),(157,18,'LOGIN_SUCCESS','AUTH',18,'LOGIN_SUCCESS','Login berhasil via Akun: petugas','::1','2026-10-24 07:45:51'),(158,1,'LOGIN_SUCCESS','AUTH',1,'LOGIN_SUCCESS','Login berhasil via Akun: admin','::1','2026-10-24 07:46:13'),(159,1,'LOGIN_SUCCESS','AUTH',1,'LOGIN_SUCCESS','Login berhasil via Akun: admin','::1','2026-10-24 08:11:46'),(160,18,'LOGIN_SUCCESS','AUTH',18,'LOGIN_SUCCESS','Login berhasil via Akun: petugas','::1','2026-10-24 08:12:04'),(161,1,'LOGIN_SUCCESS','AUTH',1,'LOGIN_SUCCESS','Login berhasil via Akun: admin','::1','2026-10-24 09:14:47'),(162,1,'LOGIN_SUCCESS','AUTH',1,'LOGIN_SUCCESS','Login berhasil via Akun: admin','::1','2026-10-24 09:27:50'),(163,19,'REGISTER','AUTH',19,'REGISTER','Registrasi akun peminjam baru: uhen (78965478, 12 RPL 2)','::1','2026-10-24 09:32:31'),(164,19,'LOGIN_SUCCESS','AUTH',19,'LOGIN_SUCCESS','Login berhasil via Akun: uhen','::1','2026-10-24 09:32:38'),(165,19,'REQUEST','PEMINJAMAN',1,'REQUEST','Pengajuan pinjaman PJM-20261024-E2764 (uhen - 1 unit reader rf id)','::1','2026-10-24 09:32:59'),(166,19,'NOTIFIKASI_WA','WHATSAPP',1,'NOTIFIKASI_WA','Mengirim WA (pengajuan) ke 081234567890 [FALLBACK/GAGAL]','::1','2026-10-24 09:32:59'),(167,19,'NOTIFIKASI_WA','WHATSAPP',1,'NOTIFIKASI_WA','Mengirim WA (pengajuan_siswa) ke 0881023805792 [FALLBACK/GAGAL]','::1','2026-10-24 09:32:59'),(168,1,'LOGIN_SUCCESS','AUTH',1,'LOGIN_SUCCESS','Login berhasil via Akun: admin','::1','2026-10-24 09:33:46'),(169,18,'LOGIN_SUCCESS','AUTH',18,'LOGIN_SUCCESS','Login berhasil via Akun: petugas','::1','2026-10-24 09:37:43'),(170,1,'LOGIN_SUCCESS','AUTH',1,'LOGIN_SUCCESS','Login berhasil via Akun: admin','::1','2026-10-24 09:40:58'),(171,18,'LOGIN_SUCCESS','AUTH',18,'LOGIN_SUCCESS','Login berhasil via Akun: petugas','::1','2026-10-24 09:56:43'),(172,18,'KIRIM_WA','PEMINJAMAN',1,'KIRIM_WA','Kirim WA (manual) ke 0881023805792: ✅ *PEMINJAMAN DISETUJUI - SIPALA*\r\n\r\nHalo *uhen*...','::1','2026-10-24 09:57:01'),(173,18,'LOGIN_SUCCESS','AUTH',18,'LOGIN_SUCCESS','Login berhasil via Akun: petugas','::1','2026-10-24 09:57:58'),(174,1,'LOGIN_SUCCESS','AUTH',1,'LOGIN_SUCCESS','Login berhasil via Akun: admin','::1','2026-10-24 09:58:11');
/*!40000 ALTER TABLE `log_aktivitas` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `notifikasi`
--

DROP TABLE IF EXISTS `notifikasi`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `notifikasi` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `user_id` int(10) unsigned NOT NULL,
  `judul` varchar(150) NOT NULL,
  `pesan` text NOT NULL,
  `tipe` enum('info','success','warning','danger') NOT NULL DEFAULT 'info',
  `link` varchar(255) DEFAULT NULL,
  `is_read` tinyint(1) NOT NULL DEFAULT 0,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_notif_user` (`user_id`),
  KEY `idx_notif_read` (`is_read`),
  CONSTRAINT `fk_notif_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=55 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `notifikasi`
--

LOCK TABLES `notifikasi` WRITE;
/*!40000 ALTER TABLE `notifikasi` DISABLE KEYS */;
INSERT INTO `notifikasi` VALUES (1,1,'Pengajuan Peminjaman Baru','Pengajuan baru TEST-20260917091148 dari M ABIJAR SEPTIAN.','info','peminjaman/index.php',1,'2026-09-17 07:11:48'),(4,1,'Pengajuan Peminjaman Baru','Pengajuan baru TEST-20260917091159 dari M ABIJAR SEPTIAN.','info','peminjaman/index.php',1,'2026-09-17 07:11:59'),(8,1,'Pengajuan Peminjaman Baru','Pengajuan baru TEST-20260917091211 dari M ABIJAR SEPTIAN.','info','peminjaman/index.php',1,'2026-09-17 07:12:11'),(13,1,'Pengajuan Pinjaman Baru: PJM-20260921-B1DA3','Siswa M ABIJAR SEPTIAN mengajukan 1 unit rf id reader.','info','peminjaman/index.php',1,'2026-09-21 04:16:33'),(16,1,'Pengajuan Pinjaman Baru: PJM-20260922-10A0D','Siswa M ABIJAR SEPTIAN mengajukan 1 unit rf id reader.','info','peminjaman/index.php',1,'2026-09-22 01:04:03'),(19,1,'Peminjam Baru Terdaftar','Siswa baru mendaftar: REVANDY (00000000 - xii rpl 2)','info','pengguna/index.php',1,'2026-09-22 01:07:48'),(22,1,'Pengajuan Pinjaman Baru: PJM-20260922-2C600','Siswa REVANDY mengajukan 1 unit rf id reader.','info','peminjaman/index.php',1,'2026-09-22 01:08:18'),(30,1,'Pengajuan Pinjaman Baru: PJM-20260922-DCC97','Siswa M ABIJAR SEPTIAN mengajukan 1 unit rf id reader.','info','peminjaman/index.php',1,'2026-09-22 05:11:33'),(38,1,'Pengajuan Pinjaman Baru: PJM-20260922-A3A75','Siswa M ABIJAR SEPTIAN mengajukan 1 unit rf id reader.','info','peminjaman/index.php',1,'2026-09-22 05:21:14'),(44,1,'Pengajuan Pinjaman Baru: PJM-20260922-927C8','Siswa M ABIJAR SEPTIAN mengajukan 1 unit rf id reader.','info','peminjaman/index.php',1,'2026-09-22 08:17:13'),(47,1,'Peminjam Baru Terdaftar','Siswa baru mendaftar: FAHRI HIDAYAT (12333211 - 12 RPL 2)','info','pengguna/index.php',1,'2026-10-23 07:05:53'),(49,19,'Selamat Datang di SIPALA!','Akun Anda berhasil didaftarkan. Anda kini dapat meminjam alat laboratorium langsung dari aplikasi.','success','index.php',0,'2026-10-24 09:32:31'),(50,1,'Peminjam Baru Terdaftar','Siswa baru mendaftar: uhen (78965478 - 12 RPL 2)','info','pengguna/index.php',0,'2026-10-24 09:32:31'),(51,18,'Peminjam Baru Terdaftar','Siswa baru mendaftar: uhen (78965478 - 12 RPL 2)','info','pengguna/index.php',0,'2026-10-24 09:32:31'),(52,19,'Pengajuan Berhasil Dikirim','Pengajuan peminjaman PJM-20261024-E2764 telah terkirim dan menunggu verifikasi petugas Lab RPL.','info','index.php',0,'2026-10-24 09:32:59'),(53,1,'Pengajuan Pinjaman Baru: PJM-20261024-E2764','Siswa uhen mengajukan 1 unit reader rf id.','info','peminjaman/index.php',0,'2026-10-24 09:32:59'),(54,18,'Pengajuan Pinjaman Baru: PJM-20261024-E2764','Siswa uhen mengajukan 1 unit reader rf id.','info','peminjaman/index.php',0,'2026-10-24 09:32:59');
/*!40000 ALTER TABLE `notifikasi` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `peminjaman`
--

DROP TABLE IF EXISTS `peminjaman`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `peminjaman` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `kode_peminjaman` varchar(30) NOT NULL,
  `user_id` int(10) unsigned NOT NULL,
  `rfid_uid` varchar(100) DEFAULT NULL,
  `rfid_verified_at` datetime DEFAULT NULL,
  `rfid_verified_by` int(10) unsigned DEFAULT NULL,
  `tanggal_pengajuan` date NOT NULL,
  `tanggal_pinjam` date NOT NULL,
  `tanggal_rencana_kembali` date NOT NULL,
  `tanggal_realisasi_kembali` date DEFAULT NULL,
  `keperluan` text NOT NULL,
  `status` enum('MENUNGGU','DISETUJUI','SIAP_DIAMBIL','DIPINJAM','MENUNGGU_PENGEMBALIAN','SELESAI','DITOLAK','TERLAMBAT','DIBATALKAN') NOT NULL DEFAULT 'MENUNGGU',
  `alasan_penolakan` text DEFAULT NULL,
  `catatan` text DEFAULT NULL,
  `disetujui_oleh` int(10) unsigned DEFAULT NULL,
  `tanggal_disetujui` datetime DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_peminjaman_kode` (`kode_peminjaman`),
  KEY `idx_peminjaman_user_status` (`user_id`,`status`),
  KEY `idx_peminjaman_status` (`status`),
  KEY `idx_peminjaman_petugas` (`disetujui_oleh`),
  KEY `idx_peminjaman_tanggal_pinjam` (`tanggal_pinjam`),
  KEY `idx_peminjaman_tanggal_kembali` (`tanggal_rencana_kembali`),
  CONSTRAINT `fk_peminjaman_petugas` FOREIGN KEY (`disetujui_oleh`) REFERENCES `users` (`id`) ON UPDATE CASCADE,
  CONSTRAINT `fk_peminjaman_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON UPDATE CASCADE,
  CONSTRAINT `chk_tanggal_peminjaman` CHECK (`tanggal_rencana_kembali` >= `tanggal_pinjam`)
) ENGINE=InnoDB AUTO_INCREMENT=2 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `peminjaman`
--

LOCK TABLES `peminjaman` WRITE;
/*!40000 ALTER TABLE `peminjaman` DISABLE KEYS */;
INSERT INTO `peminjaman` VALUES (1,'PJM-20261024-E2764',19,NULL,NULL,NULL,'2026-10-24','2026-10-24','2026-10-26',NULL,'untuk praktik','MENUNGGU',NULL,NULL,NULL,NULL,'2026-10-24 09:32:59','2026-10-24 09:32:59');
/*!40000 ALTER TABLE `peminjaman` ENABLE KEYS */;
UNLOCK TABLES;
/*!50003 SET @saved_cs_client      = @@character_set_client */ ;
/*!50003 SET @saved_cs_results     = @@character_set_results */ ;
/*!50003 SET @saved_col_connection = @@collation_connection */ ;
/*!50003 SET character_set_client  = cp850 */ ;
/*!50003 SET character_set_results = cp850 */ ;
/*!50003 SET collation_connection  = cp850_general_ci */ ;
/*!50003 SET @saved_sql_mode       = @@sql_mode */ ;
/*!50003 SET sql_mode              = 'NO_ZERO_IN_DATE,NO_ZERO_DATE,NO_ENGINE_SUBSTITUTION' */ ;
DELIMITER ;;
/*!50003 CREATE*/ /*!50017 DEFINER=`root`@`localhost`*/ /*!50003 TRIGGER trg_after_insert_peminjaman
AFTER INSERT ON peminjaman
FOR EACH ROW
BEGIN
    INSERT INTO log_aktivitas (user_id, aksi, modul, reference_id, aktivitas, deskripsi, created_at)
    VALUES (
        NEW.user_id, 
        'INSERT_TRIGGER', 
        'PEMINJAMAN', 
        NEW.id,
        'Pengajuan Baru Terbuat', 
        CONCAT('Trigger Database: Transaksi baru tercatat dengan kode ', NEW.kode_peminjaman), 
        NOW()
    );
END */;;
DELIMITER ;
/*!50003 SET sql_mode              = @saved_sql_mode */ ;
/*!50003 SET character_set_client  = @saved_cs_client */ ;
/*!50003 SET character_set_results = @saved_cs_results */ ;
/*!50003 SET collation_connection  = @saved_col_connection */ ;

--
-- Table structure for table `pengaturan`
--

DROP TABLE IF EXISTS `pengaturan`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `pengaturan` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `nama` varchar(100) NOT NULL,
  `nilai` text NOT NULL,
  `deskripsi` varchar(255) DEFAULT NULL,
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_pengaturan_nama` (`nama`)
) ENGINE=InnoDB AUTO_INCREMENT=21 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `pengaturan`
--

LOCK TABLES `pengaturan` WRITE;
/*!40000 ALTER TABLE `pengaturan` DISABLE KEYS */;
INSERT INTO `pengaturan` VALUES (1,'nama_aplikasi','SIPALA','Nama aplikasi','2026-09-09 19:17:21'),(2,'nama_instansi','SMKN 1 PANYINGKIRAN','Nama sekolah atau instansi','2026-09-11 07:10:45'),(3,'tarif_denda_harian','3000','Denda keterlambatan per hari','2026-09-11 07:08:43'),(4,'maks_hari_pinjam','3','Batas maksimal durasi peminjaman dalam hari','2026-09-11 07:10:45'),(5,'maks_unit_pinjam','3','Batas maksimal unit alat per transaksi peminjaman','2026-09-11 07:08:43'),(6,'kontak_lab_wa','0881023805792','Nomor WhatsApp Helpdesk / Petugas Laboratorium','2026-09-11 07:10:45'),(7,'jam_operasional_lab','Senin - Jumat: 07.30 - 15.30 WIB','Jam buka layanan laboratorium','2026-09-11 07:07:18'),(8,'lokasi_ruang_lab','LAB RPL','Lokasi fisik ruang laboratorium','2026-09-11 07:10:45'),(9,'wa_gateway_status','aktif','Status aktifasi Gateway WhatsApp (aktif/nonaktif)','2026-09-24 04:11:45'),(10,'wa_gateway_provider','fonnte','Provider WhatsApp (fonnte/wablas/starsender/custom)','2026-09-24 04:11:45'),(11,'wa_api_token','','API Token / Secret Key dari penyedia Bot WhatsApp','2026-09-24 04:11:45'),(12,'wa_api_endpoint','https://api.fonnte.com/send','URL Endpoint pengiriman pesan WhatsApp','2026-09-24 04:11:45'),(13,'wa_admin_phone','081234567890','Nomor WhatsApp Admin Lab untuk notifikasi masuk','2026-09-24 04:11:45'),(14,'wa_template_pengajuan','🔔 *NOTIFIKASI SIPALA - PENGAJUAN PINJAMAN BARU*\n\nHalo Admin Lab, terdapat pengajuan peminjaman baru:\n• *Peminjam:* {nama} ({kelas})\n• *NIS:* {nis}\n• *Kode:* {kode_peminjaman}\n• *Alat:* {alat} ({jumlah} unit)\n• *Tenggat Kembali:* {tgl_kembali}\n• *Keperluan','Template WA untuk Admin saat ada pengajuan baru','2026-09-24 04:11:45'),(15,'wa_template_pengajuan_siswa','📋 *SIPALA - BUKTI PENGAJUAN PINJAMAN*\n\nHalo *{nama}*,\nPengajuan peminjaman alat lab Anda telah berhasil dikirim:\n• *Kode Pinjam:* {kode_peminjaman}\n• *Alat:* {alat} ({jumlah} unit)\n• *Rencana Kembali:* {tgl_kembali}\n• *Status:* Menunggu Persetujuan Petuga','Template WA ke siswa saat pengajuan terkirim','2026-09-24 04:11:45'),(16,'wa_template_disetujui','✅ *SIPALA - PEMINJAMAN DISETUJUI*\n\nHalo *{nama}*,\nPengajuan peminjaman alat laboratorium Anda telah *DISETUJUI*:\n• *Kode Pinjam:* {kode_peminjaman}\n• *Alat:* {alat}\n• *Lokasi Pengambilan:* {lokasi_ruang_lab}\n• *Batas Pengembalian:* {tgl_kembali}\n\nSilakan ','Template WA ke siswa saat pinjaman disetujui','2026-09-24 04:11:45'),(17,'wa_template_ditolak','❌ *SIPALA - PENGAJUAN PINJAMAN DITOLAK*\n\nHalo *{nama}*,\nMohon maaf, pengajuan peminjaman alat *{alat}* (Kode: {kode_peminjaman}) *DITOLAK* oleh petugas laboratorium.\n• *Alasan:* {alasan}\n\nSilakan hubungi petugas lab atau ajukan kembali sesuai jadwal prakt','Template WA ke siswa saat pinjaman ditolak','2026-09-24 04:11:45'),(18,'wa_template_serah_terima','📦 *SIPALA - SERAH TERIMA BARANG BERHASIL*\n\nHalo *{nama}*,\nBarang laboratorium telah diserahterimakan kepada Anda:\n• *Kode Pinjam:* {kode_peminjaman}\n• *Alat:* {alat}\n• *Unit Fisik:* {kode_unit}\n• *Tanggal Pinjam:* {tgl_pinjam}\n• *Batas Jatuh Tempo:* {tgl_','Template WA ke siswa saat serah terima barang','2026-09-24 04:11:45'),(19,'wa_template_pengembalian','🎉 *SIPALA - PENGEMBALIAN ALAT SELESAI*\n\nHalo *{nama}*,\nTerima kasih telah mengembalikan alat laboratorium:\n• *Kode Pinjam:* {kode_peminjaman}\n• *Alat:* {alat}\n• *Kondisi Akhir:* {kondisi}\n• *Keterlambatan:* {hari_terlambat} hari\n• *Denda:* {denda} ({statu','Template WA ke siswa saat alat dikembalikan','2026-09-24 04:11:45'),(20,'wa_template_pengingat','⏰ *SIPALA - PENGINGAT PENGEMBALIAN ALAT LAB*\n\nHalo *{nama}*,\nMengingatkan bahwa peminjaman alat laboratorium Anda memiliki jadwal pengembalian:\n• *Kode Pinjam:* {kode_peminjaman}\n• *Alat:* {alat}\n• *Jatuh Tempo:* {tgl_kembali}\n• *Status Saat Ini:* {status','Template WA pengingat batas pengembalian','2026-09-24 04:11:45');
/*!40000 ALTER TABLE `pengaturan` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `pengembalian`
--

DROP TABLE IF EXISTS `pengembalian`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `pengembalian` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `peminjaman_id` int(10) unsigned NOT NULL,
  `tanggal_pengembalian` date NOT NULL,
  `terlambat_hari` int(10) unsigned NOT NULL DEFAULT 0,
  `denda` decimal(12,2) NOT NULL DEFAULT 0.00,
  `status_denda` enum('LUNAS','BELUM_LUNAS') NOT NULL DEFAULT 'LUNAS',
  `kondisi_barang` enum('Baik','Rusak Ringan','Rusak Berat') NOT NULL,
  `catatan` text DEFAULT NULL,
  `diproses_oleh` int(10) unsigned NOT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_pengembalian_peminjaman` (`peminjaman_id`),
  KEY `idx_pengembalian_tanggal` (`tanggal_pengembalian`),
  KEY `idx_pengembalian_petugas` (`diproses_oleh`),
  CONSTRAINT `fk_pengembalian_peminjaman` FOREIGN KEY (`peminjaman_id`) REFERENCES `peminjaman` (`id`) ON UPDATE CASCADE,
  CONSTRAINT `fk_pengembalian_petugas` FOREIGN KEY (`diproses_oleh`) REFERENCES `users` (`id`) ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `pengembalian`
--

LOCK TABLES `pengembalian` WRITE;
/*!40000 ALTER TABLE `pengembalian` DISABLE KEYS */;
/*!40000 ALTER TABLE `pengembalian` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `peralatan`
--

DROP TABLE IF EXISTS `peralatan`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `peralatan` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `kode_barang` varchar(50) NOT NULL,
  `nama_barang` varchar(150) NOT NULL,
  `kategori_id` int(10) unsigned NOT NULL,
  `jumlah` int(10) unsigned NOT NULL DEFAULT 0,
  `jumlah_tersedia` int(10) unsigned NOT NULL DEFAULT 0,
  `kondisi` enum('Baik','Rusak Ringan','Rusak Berat') NOT NULL DEFAULT 'Baik',
  `lokasi` varchar(150) NOT NULL,
  `deskripsi` text DEFAULT NULL,
  `foto` varchar(255) DEFAULT NULL,
  `status` enum('aktif','nonaktif') NOT NULL DEFAULT 'aktif',
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_peralatan_kode` (`kode_barang`),
  KEY `idx_peralatan_kategori` (`kategori_id`),
  KEY `idx_peralatan_status` (`status`),
  KEY `idx_peralatan_nama` (`nama_barang`),
  CONSTRAINT `fk_peralatan_kategori` FOREIGN KEY (`kategori_id`) REFERENCES `kategori` (`id`) ON UPDATE CASCADE,
  CONSTRAINT `chk_peralatan_stok` CHECK (`jumlah_tersedia` <= `jumlah`)
) ENGINE=InnoDB AUTO_INCREMENT=3 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `peralatan`
--

LOCK TABLES `peralatan` WRITE;
/*!40000 ALTER TABLE `peralatan` DISABLE KEYS */;
INSERT INTO `peralatan` VALUES (2,'DEV-001','reader rf id',2,5,5,'Baik','Rak Utama Lab RPL','unruk scan kaertu absensi','assets/uploads/peralatan/alat_20261023_154225_e0af1dc2.jpg','aktif','2026-10-23 08:42:25','2026-10-23 08:42:25');
/*!40000 ALTER TABLE `peralatan` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `rfid_cards`
--

DROP TABLE IF EXISTS `rfid_cards`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `rfid_cards` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `user_id` int(10) unsigned DEFAULT NULL,
  `unit_id` int(10) unsigned DEFAULT NULL,
  `uid` varchar(100) NOT NULL,
  `tipe` enum('user','alat') NOT NULL DEFAULT 'user',
  `status` enum('aktif','nonaktif') NOT NULL DEFAULT 'aktif',
  `catatan` varchar(255) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uid` (`uid`),
  KEY `idx_rfid_user` (`user_id`),
  KEY `idx_rfid_unit` (`unit_id`),
  KEY `idx_rfid_status` (`status`),
  CONSTRAINT `fk_rfid_unit` FOREIGN KEY (`unit_id`) REFERENCES `unit_peralatan` (`id`) ON DELETE SET NULL,
  CONSTRAINT `fk_rfid_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB AUTO_INCREMENT=22 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `rfid_cards`
--

LOCK TABLES `rfid_cards` WRITE;
/*!40000 ALTER TABLE `rfid_cards` DISABLE KEYS */;
INSERT INTO `rfid_cards` VALUES (1,1,NULL,'1707573134','user','aktif','Kartu pengguna: Administrator SIPALA','2026-09-17 07:00:19','2026-09-17 07:00:19'),(2,NULL,NULL,'1701947326','user','aktif','Kartu pengguna: M ABIJAR SEPTIAN','2026-09-17 07:00:19','2026-09-17 07:00:19');
/*!40000 ALTER TABLE `rfid_cards` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `unit_peralatan`
--

DROP TABLE IF EXISTS `unit_peralatan`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `unit_peralatan` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `peralatan_id` int(10) unsigned NOT NULL,
  `kode_unit` varchar(60) NOT NULL,
  `barcode` varchar(64) DEFAULT NULL,
  `serial_number` varchar(100) DEFAULT NULL,
  `rfid_uid` varchar(100) DEFAULT NULL,
  `kondisi` enum('Baik','Rusak Ringan','Rusak Berat') NOT NULL DEFAULT 'Baik',
  `lokasi` varchar(150) DEFAULT NULL,
  `status` enum('TERSEDIA','DIPINJAM','RUSAK','HILANG','MAINTENANCE') NOT NULL DEFAULT 'TERSEDIA',
  `status_label` enum('BELUM_DILABELI','SUDAH_DILABELI','TERVERIFIKASI') NOT NULL DEFAULT 'BELUM_DILABELI',
  `label_printed_at` datetime DEFAULT NULL,
  `catatan` text DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `kode_unit` (`kode_unit`),
  UNIQUE KEY `rfid_uid` (`rfid_uid`),
  UNIQUE KEY `idx_unit_barcode` (`barcode`),
  KEY `idx_unit_peralatan` (`peralatan_id`),
  KEY `idx_unit_status` (`status`),
  KEY `idx_unit_kondisi` (`kondisi`),
  KEY `idx_unit_status_label` (`status_label`),
  CONSTRAINT `fk_unit_peralatan_master` FOREIGN KEY (`peralatan_id`) REFERENCES `peralatan` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=8 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `unit_peralatan`
--

LOCK TABLES `unit_peralatan` WRITE;
/*!40000 ALTER TABLE `unit_peralatan` DISABLE KEYS */;
INSERT INTO `unit_peralatan` VALUES (3,2,'DEV001-001','SIP-ALT-000003',NULL,NULL,'Baik','Rak Utama Lab RPL','TERSEDIA','SUDAH_DILABELI','2026-10-24 14:31:08','Unit inisialisasi awal saat registrasi alat master','2026-10-23 08:42:25','2026-10-24 07:31:08'),(4,2,'DEV001-002','SIP-ALT-000004',NULL,NULL,'Baik','Rak Utama Lab RPL','TERSEDIA','BELUM_DILABELI',NULL,'Unit inisialisasi awal saat registrasi alat master','2026-10-23 08:42:25','2026-10-23 08:42:25'),(5,2,'DEV001-003','SIP-ALT-000005',NULL,NULL,'Baik','Rak Utama Lab RPL','TERSEDIA','BELUM_DILABELI',NULL,'Unit inisialisasi awal saat registrasi alat master','2026-10-23 08:42:25','2026-10-23 08:42:25'),(6,2,'DEV001-004','SIP-ALT-000006',NULL,NULL,'Baik','Rak Utama Lab RPL','TERSEDIA','BELUM_DILABELI',NULL,'Unit inisialisasi awal saat registrasi alat master','2026-10-23 08:42:25','2026-10-23 08:42:25'),(7,2,'DEV001-005','SIP-ALT-000007',NULL,NULL,'Baik','Rak Utama Lab RPL','TERSEDIA','BELUM_DILABELI',NULL,'Unit inisialisasi awal saat registrasi alat master','2026-10-23 08:42:25','2026-10-23 08:42:25');
/*!40000 ALTER TABLE `unit_peralatan` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `users`
--

DROP TABLE IF EXISTS `users`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `users` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `nama` varchar(100) NOT NULL,
  `nis` varchar(32) DEFAULT NULL,
  `username` varchar(50) NOT NULL,
  `password` varchar(255) NOT NULL,
  `role` enum('admin','petugas','peminjam') NOT NULL DEFAULT 'peminjam',
  `kelas` varchar(50) DEFAULT NULL,
  `rfid_uid` varchar(100) DEFAULT NULL,
  `no_hp` varchar(25) DEFAULT NULL,
  `alamat` text DEFAULT NULL,
  `foto` varchar(255) DEFAULT NULL,
  `status` enum('aktif','nonaktif') NOT NULL DEFAULT 'aktif',
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_users_username` (`username`),
  UNIQUE KEY `uq_users_nis` (`nis`),
  UNIQUE KEY `uq_users_rfid` (`rfid_uid`),
  KEY `idx_users_role_status` (`role`,`status`),
  KEY `idx_users_nama` (`nama`)
) ENGINE=InnoDB AUTO_INCREMENT=20 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `users`
--

LOCK TABLES `users` WRITE;
/*!40000 ALTER TABLE `users` DISABLE KEYS */;
INSERT INTO `users` VALUES (1,'HENDRIK WILDANSYAH',NULL,'admin','$2y$10$q2fZ871iBT4xeoLE.e/34uNhaVl2giThHSyWNiaeSb8F54TEjYxZy','admin',NULL,'1707573134','0882002378557',NULL,'assets/uploads/profile/profile_1_20260917_101015.jpeg','aktif','2026-09-09 19:17:20','2026-09-17 08:10:15'),(18,'petugas','112233321','petugas','$2y$10$iWKbMkNBZRe.Ty4LuXxMHOW3yhIMeOpyEzGPdMXk47zoDJ.jmZ70C','petugas',NULL,NULL,'0881023805792',NULL,NULL,'aktif','2026-10-24 07:45:28','2026-10-24 07:45:28'),(19,'uhen','78965478','uhen','$2y$10$24ZtGm6ZnAGFcAvoZyyPs.nuES9vV0OzBj9q3q3VlGxnDvk8K0E7i','peminjam','12 RPL 2',NULL,'0881023805792','Sumedang',NULL,'aktif','2026-10-24 09:32:31','2026-10-24 09:32:31');
/*!40000 ALTER TABLE `users` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Dumping routines for database 'sipala'
--
/*!50003 SET @saved_sql_mode       = @@sql_mode */ ;
/*!50003 SET sql_mode              = 'NO_ZERO_IN_DATE,NO_ZERO_DATE,NO_ENGINE_SUBSTITUTION' */ ;
/*!50003 DROP FUNCTION IF EXISTS `fn_hitung_denda` */;
/*!50003 SET @saved_cs_client      = @@character_set_client */ ;
/*!50003 SET @saved_cs_results     = @@character_set_results */ ;
/*!50003 SET @saved_col_connection = @@collation_connection */ ;
/*!50003 SET character_set_client  = cp850 */ ;
/*!50003 SET character_set_results = cp850 */ ;
/*!50003 SET collation_connection  = cp850_general_ci */ ;
DELIMITER ;;
CREATE DEFINER=`root`@`localhost` FUNCTION `fn_hitung_denda`(tgl_rencana DATE, tgl_kembali DATE, tarif INT) RETURNS int(11)
    DETERMINISTIC
BEGIN
    DECLARE hari_telat INT;
    SET hari_telat = DATEDIFF(tgl_kembali, tgl_rencana);
    IF hari_telat > 0 THEN
        RETURN hari_telat * tarif;
    ELSE
        RETURN 0;
    END IF;
END ;;
DELIMITER ;
/*!50003 SET sql_mode              = @saved_sql_mode */ ;
/*!50003 SET character_set_client  = @saved_cs_client */ ;
/*!50003 SET character_set_results = @saved_cs_results */ ;
/*!50003 SET collation_connection  = @saved_col_connection */ ;
/*!50003 SET @saved_sql_mode       = @@sql_mode */ ;
/*!50003 SET sql_mode              = 'NO_ZERO_IN_DATE,NO_ZERO_DATE,NO_ENGINE_SUBSTITUTION' */ ;
/*!50003 DROP PROCEDURE IF EXISTS `sp_rekap_peminjaman_alat` */;
/*!50003 SET @saved_cs_client      = @@character_set_client */ ;
/*!50003 SET @saved_cs_results     = @@character_set_results */ ;
/*!50003 SET @saved_col_connection = @@collation_connection */ ;
/*!50003 SET character_set_client  = cp850 */ ;
/*!50003 SET character_set_results = cp850 */ ;
/*!50003 SET collation_connection  = cp850_general_ci */ ;
DELIMITER ;;
CREATE DEFINER=`root`@`localhost` PROCEDURE `sp_rekap_peminjaman_alat`()
BEGIN
    SELECT 
        COUNT(*) AS total_transaksi,
        SUM(CASE WHEN status = 'DIPINJAM' THEN 1 ELSE 0 END) AS sedang_dipinjam,
        SUM(CASE WHEN status = 'MENUNGGU' THEN 1 ELSE 0 END) AS menunggu_verifikasi,
        SUM(CASE WHEN status = 'SELESAI' THEN 1 ELSE 0 END) AS selesai
    FROM peminjaman;
END ;;
DELIMITER ;
/*!50003 SET sql_mode              = @saved_sql_mode */ ;
/*!50003 SET character_set_client  = @saved_cs_client */ ;
/*!50003 SET character_set_results = @saved_cs_results */ ;
/*!50003 SET collation_connection  = @saved_col_connection */ ;
/*!40103 SET TIME_ZONE=@OLD_TIME_ZONE */;

/*!40101 SET SQL_MODE=@OLD_SQL_MODE */;
/*!40014 SET FOREIGN_KEY_CHECKS=@OLD_FOREIGN_KEY_CHECKS */;
/*!40014 SET UNIQUE_CHECKS=@OLD_UNIQUE_CHECKS */;
/*!40101 SET CHARACTER_SET_CLIENT=@OLD_CHARACTER_SET_CLIENT */;
/*!40101 SET CHARACTER_SET_RESULTS=@OLD_CHARACTER_SET_RESULTS */;
/*!40101 SET COLLATION_CONNECTION=@OLD_COLLATION_CONNECTION */;
/*!40111 SET SQL_NOTES=@OLD_SQL_NOTES */;

-- Dump completed on 2026-10-24 17:58:43
