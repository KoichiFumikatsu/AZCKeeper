-- phpMyAdmin SQL Dump
-- version 5.2.1
-- https://www.phpmyadmin.net/
--
-- Host: 127.0.0.1
-- Generation Time: Apr 30, 2026 at 06:34 PM
-- Server version: 10.4.32-MariaDB
-- PHP Version: 8.2.12

SET SQL_MODE = "NO_AUTO_VALUE_ON_ZERO";
START TRANSACTION;
SET time_zone = "+00:00";


/*!40101 SET @OLD_CHARACTER_SET_CLIENT=@@CHARACTER_SET_CLIENT */;
/*!40101 SET @OLD_CHARACTER_SET_RESULTS=@@CHARACTER_SET_RESULTS */;
/*!40101 SET @OLD_COLLATION_CONNECTION=@@COLLATION_CONNECTION */;
/*!40101 SET NAMES utf8mb4 */;

--
-- Database: `azckeeper_local`
--

-- --------------------------------------------------------

--
-- Table structure for table `employee`
--

CREATE TABLE `employee` (
  `id` int(11) NOT NULL,
  `CC` varchar(20) DEFAULT NULL,
  `first_Name` varchar(80) DEFAULT NULL,
  `second_Name` varchar(80) DEFAULT NULL,
  `first_LastName` varchar(80) DEFAULT NULL,
  `second_LastName` varchar(80) DEFAULT NULL,
  `mail` varchar(190) DEFAULT NULL,
  `exit_status` tinyint(1) NOT NULL DEFAULT 0,
  `company` bigint(20) DEFAULT NULL,
  `area_id` bigint(20) DEFAULT NULL,
  `position_id` bigint(20) DEFAULT NULL,
  `sede_id` bigint(20) DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `employee`
--

INSERT INTO `employee` (`id`, `CC`, `first_Name`, `second_Name`, `first_LastName`, `second_LastName`, `mail`, `exit_status`, `company`, `area_id`, `position_id`, `sede_id`) VALUES
(9001, '1151963002', 'Koichi', NULL, 'Fumikatsu', NULL, 'koichi.fumikatsu@azc.com.co', 0, 1, 1, 1, 1),
(9002, '1000000001', 'Ana', NULL, 'Soporte', NULL, 'ana.soporte@azc.local', 0, 1, 1, 2, 2),
(9003, '1000000002', 'Carlos', NULL, 'Operaciones', NULL, 'carlos.ops@azc.local', 0, 1, 2, 3, 1);

-- --------------------------------------------------------

--
-- Table structure for table `keeper_activity_day`
--

CREATE TABLE `keeper_activity_day` (
  `id` bigint(20) NOT NULL,
  `user_id` bigint(20) NOT NULL,
  `device_id` bigint(20) NOT NULL,
  `day_date` date NOT NULL,
  `tz_offset_minutes` smallint(6) NOT NULL DEFAULT -300,
  `is_workday` tinyint(1) NOT NULL DEFAULT 1,
  `active_seconds` int(11) NOT NULL DEFAULT 0,
  `work_hours_active_seconds` decimal(12,3) DEFAULT 0.000,
  `work_hours_idle_seconds` decimal(12,3) DEFAULT 0.000,
  `lunch_active_seconds` decimal(12,3) DEFAULT 0.000,
  `lunch_idle_seconds` decimal(12,3) DEFAULT 0.000,
  `after_hours_active_seconds` decimal(12,3) DEFAULT 0.000,
  `after_hours_idle_seconds` decimal(12,3) DEFAULT 0.000,
  `idle_seconds` int(11) NOT NULL DEFAULT 0,
  `call_seconds` int(11) NOT NULL DEFAULT 0,
  `samples_count` int(11) NOT NULL DEFAULT 0,
  `first_event_at` datetime DEFAULT NULL,
  `last_event_at` datetime DEFAULT NULL,
  `payload_json` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL CHECK (json_valid(`payload_json`)),
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `keeper_activity_day`
--

INSERT INTO `keeper_activity_day` (`id`, `user_id`, `device_id`, `day_date`, `tz_offset_minutes`, `is_workday`, `active_seconds`, `work_hours_active_seconds`, `work_hours_idle_seconds`, `lunch_active_seconds`, `lunch_idle_seconds`, `after_hours_active_seconds`, `after_hours_idle_seconds`, `idle_seconds`, `call_seconds`, `samples_count`, `first_event_at`, `last_event_at`, `payload_json`, `created_at`, `updated_at`) VALUES
(1, 1, 1, '2026-04-29', -300, 1, 27000, 21600.000, 3600.000, 900.000, 300.000, 1800.000, 600.000, 5400, 1200, 180, '2026-04-29 07:05:00', '2026-04-29 18:40:00', '{\"source\":\"synthetic\"}', '2026-04-29 20:59:27', '2026-04-29 20:59:27'),
(2, 2, 2, '2026-04-29', -300, 1, 25200, 19800.000, 4200.000, 600.000, 600.000, 900.000, 300.000, 6600, 600, 160, '2026-04-29 08:02:00', '2026-04-29 17:20:00', '{\"source\":\"synthetic\"}', '2026-04-29 20:59:27', '2026-04-29 20:59:27'),
(3, 3, 3, '2026-04-29', -300, 1, 19800, 14400.000, 5400.000, 300.000, 900.000, 3600.000, 1200.000, 8400, 300, 140, '2026-04-29 07:20:00', '2026-04-29 20:10:00', '{\"source\":\"synthetic\"}', '2026-04-29 20:59:27', '2026-04-29 20:59:27'),
(4, 1, 1, '2026-04-28', -300, 1, 25800, 21000.000, 3000.000, 1200.000, 300.000, 1200.000, 300.000, 4500, 900, 170, '2026-04-28 07:00:00', '2026-04-28 18:10:00', '{\"source\":\"synthetic\"}', '2026-04-29 20:59:27', '2026-04-29 20:59:27'),
(5, 2, 2, '2026-04-28', -300, 1, 24000, 19200.000, 3600.000, 900.000, 600.000, 600.000, 300.000, 6000, 300, 150, '2026-04-28 08:10:00', '2026-04-28 17:00:00', '{\"source\":\"synthetic\"}', '2026-04-29 20:59:27', '2026-04-29 20:59:27'),
(6, 3, 3, '2026-04-28', -300, 1, 18000, 12600.000, 5400.000, 600.000, 900.000, 4200.000, 1800.000, 9600, 0, 130, '2026-04-28 07:50:00', '2026-04-28 21:15:00', '{\"source\":\"synthetic\"}', '2026-04-29 20:59:27', '2026-04-29 20:59:27'),
(7, 2, 4, '2026-04-30', -300, 1, 0, 0.000, 0.000, 0.000, 0.000, 0.000, 0.000, 0, 0, 1, '2026-04-30 10:35:48', '2026-04-30 10:35:48', '{\"deviceId\":\"c7d15e48-06d3-40c4-9e64-0fbce19e213a\",\"dayDate\":\"2026-04-30\",\"tzOffsetMinutes\":-300,\"activeSeconds\":0,\"idleSeconds\":0,\"callSeconds\":0,\"samplesCount\":1,\"firstEventAt\":\"2026-04-30 10:35:48\",\"lastEventAt\":\"2026-04-30 10:35:48\",\"workHoursActiveSeconds\":0,\"workHoursIdleSeconds\":0,\"lunchActiveSeconds\":0,\"lunchIdleSeconds\":0,\"afterHoursActiveSeconds\":0,\"afterHoursIdleSeconds\":0,\"isWorkday\":true}', '2026-04-30 15:35:48', '2026-04-30 15:35:48');

-- --------------------------------------------------------

--
-- Table structure for table `keeper_admin_accounts`
--

CREATE TABLE `keeper_admin_accounts` (
  `id` bigint(20) NOT NULL,
  `keeper_user_id` bigint(20) NOT NULL,
  `display_name` varchar(190) DEFAULT NULL,
  `panel_role` varchar(64) NOT NULL,
  `firm_scope_id` bigint(20) DEFAULT NULL,
  `area_scope_id` bigint(20) DEFAULT NULL,
  `sede_scope_id` bigint(20) DEFAULT NULL,
  `sociedad_scope_id` bigint(20) DEFAULT NULL,
  `is_active` tinyint(1) NOT NULL DEFAULT 1,
  `created_by` bigint(20) DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT current_timestamp(),
  `last_login_at` timestamp NULL DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `keeper_admin_accounts`
--

INSERT INTO `keeper_admin_accounts` (`id`, `keeper_user_id`, `display_name`, `panel_role`, `firm_scope_id`, `area_scope_id`, `sede_scope_id`, `sociedad_scope_id`, `is_active`, `created_by`, `created_at`, `last_login_at`) VALUES
(1, 1, 'Koichi Fumikatsu', 'superadmin', NULL, NULL, NULL, NULL, 1, NULL, '2026-04-29 20:59:27', '2026-04-30 13:02:05');

-- --------------------------------------------------------

--
-- Table structure for table `keeper_admin_sessions`
--

CREATE TABLE `keeper_admin_sessions` (
  `id` bigint(20) NOT NULL,
  `admin_id` bigint(20) NOT NULL,
  `token_hash` char(64) NOT NULL,
  `ip` varchar(64) DEFAULT NULL,
  `user_agent` varchar(255) DEFAULT NULL,
  `expires_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  `revoked_at` timestamp NULL DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `keeper_admin_sessions`
--

INSERT INTO `keeper_admin_sessions` (`id`, `admin_id`, `token_hash`, `ip`, `user_agent`, `expires_at`, `revoked_at`, `created_at`) VALUES
(1, 1, 'c22e65bd63d5d1002cfd96268cb08db1087f830bdf78a7ca62e59361732802ec', '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/147.0.0.0 Safari/537.36', '2026-04-29 21:10:23', '2026-04-29 21:10:23', '2026-04-29 21:09:55'),
(2, 1, '2ef79cb24e35f1360f5fee6d90c9e0d679795c602a5a2e172482347cf24ed24b', '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/147.0.0.0 Safari/537.36', '2026-04-29 22:23:14', '2026-04-29 22:23:14', '2026-04-29 21:10:25'),
(3, 1, 'd0cd19f41eee4953f2051278b0c29ee26dc8befa82524806aa7c12ad4eacb536', '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/147.0.0.0 Safari/537.36', '2026-04-30 11:23:23', NULL, '2026-04-29 22:23:23'),
(4, 1, 'd27a7450c39c85b6ecbbf82f7f6dbe88323ee11ec935eef8a33edf5126e6c79d', '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/147.0.0.0 Safari/537.36', '2026-05-01 02:02:05', NULL, '2026-04-30 13:02:05');

-- --------------------------------------------------------

--
-- Table structure for table `keeper_app_classifications`
--

CREATE TABLE `keeper_app_classifications` (
  `id` bigint(20) NOT NULL,
  `app_pattern` varchar(190) NOT NULL,
  `classification` enum('productive','unproductive') NOT NULL,
  `description` varchar(255) DEFAULT NULL,
  `is_active` tinyint(1) NOT NULL DEFAULT 1,
  `created_at` timestamp NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `keeper_app_classifications`
--

INSERT INTO `keeper_app_classifications` (`id`, `app_pattern`, `classification`, `description`, `is_active`, `created_at`, `updated_at`) VALUES
(1, 'phpstorm', 'productive', 'IDE productivo', 1, '2026-04-29 20:59:28', '2026-04-29 20:59:28'),
(2, 'youtube', 'unproductive', 'Sitio de ocio', 1, '2026-04-29 20:59:28', '2026-04-29 20:59:28');

-- --------------------------------------------------------

--
-- Table structure for table `keeper_areas`
--

CREATE TABLE `keeper_areas` (
  `id` bigint(20) NOT NULL,
  `nombre` varchar(190) NOT NULL,
  `descripcion` varchar(255) DEFAULT NULL,
  `activa` tinyint(1) NOT NULL DEFAULT 1,
  `created_at` timestamp NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `keeper_areas`
--

INSERT INTO `keeper_areas` (`id`, `nombre`, `descripcion`, `activa`, `created_at`, `updated_at`) VALUES
(1, 'Tecnologia', 'Area sintetica de tecnologia', 1, '2026-04-29 20:59:27', '2026-04-29 20:59:27'),
(2, 'Operaciones', 'Area sintetica de operaciones', 1, '2026-04-29 20:59:27', '2026-04-29 20:59:27');

-- --------------------------------------------------------

--
-- Table structure for table `keeper_audit_log`
--

CREATE TABLE `keeper_audit_log` (
  `id` bigint(20) NOT NULL,
  `user_id` bigint(20) DEFAULT NULL,
  `device_id` bigint(20) DEFAULT NULL,
  `event_type` varchar(100) NOT NULL,
  `message` varchar(512) DEFAULT NULL,
  `meta_json` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL CHECK (json_valid(`meta_json`)),
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `keeper_audit_log`
--

INSERT INTO `keeper_audit_log` (`id`, `user_id`, `device_id`, `event_type`, `message`, `meta_json`, `created_at`) VALUES
(1, 2, 4, 'login_ok', 'Login exitoso CC=1000000001', '{\"cc\":\"1000000001\",\"deviceGuid\":\"c7d15e48-06d3-40c4-9e64-0fbce19e213a\",\"ip\":\"::1\"}', '2026-04-30 15:17:22'),
(2, 2, 4, 'login_ok', 'Login exitoso CC=1000000001', '{\"cc\":\"1000000001\",\"deviceGuid\":\"c7d15e48-06d3-40c4-9e64-0fbce19e213a\",\"ip\":\"::1\"}', '2026-04-30 15:22:17');

-- --------------------------------------------------------

--
-- Table structure for table `keeper_cargos`
--

CREATE TABLE `keeper_cargos` (
  `id` bigint(20) NOT NULL,
  `nombre` varchar(190) NOT NULL,
  `descripcion` varchar(255) DEFAULT NULL,
  `nivel_jerarquico` int(11) NOT NULL DEFAULT 0,
  `activo` tinyint(1) NOT NULL DEFAULT 1,
  `created_at` timestamp NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `keeper_cargos`
--

INSERT INTO `keeper_cargos` (`id`, `nombre`, `descripcion`, `nivel_jerarquico`, `activo`, `created_at`, `updated_at`) VALUES
(1, 'Administrador del Sistema', 'Cargo sintetico para superadmin', 100, 1, '2026-04-29 20:59:27', '2026-04-29 20:59:27'),
(2, 'Analista de Soporte', 'Cargo sintetico operativo', 30, 1, '2026-04-29 20:59:27', '2026-04-29 20:59:27'),
(3, 'Coordinador', 'Cargo sintetico coordinacion', 50, 1, '2026-04-29 20:59:27', '2026-04-29 20:59:27');

-- --------------------------------------------------------

--
-- Table structure for table `keeper_client_releases`
--

CREATE TABLE `keeper_client_releases` (
  `id` bigint(20) NOT NULL,
  `version` varchar(40) NOT NULL,
  `download_url` varchar(500) NOT NULL,
  `file_size` bigint(20) NOT NULL DEFAULT 0,
  `release_notes` text DEFAULT NULL,
  `is_beta` tinyint(1) NOT NULL DEFAULT 0,
  `force_update` tinyint(1) NOT NULL DEFAULT 0,
  `minimum_version` varchar(40) DEFAULT NULL,
  `is_active` tinyint(1) NOT NULL DEFAULT 1,
  `release_date` date DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `keeper_client_releases`
--

INSERT INTO `keeper_client_releases` (`id`, `version`, `download_url`, `file_size`, `release_notes`, `is_beta`, `force_update`, `minimum_version`, `is_active`, `release_date`, `created_at`, `updated_at`) VALUES
(1, '1.2.0', 'https://example.com/AZCKeeper-1.2.0.zip', 15728640, 'Release estable sintetica local', 0, 0, '1.1.0', 1, '2026-04-29', '2026-04-29 20:59:27', '2026-04-29 20:59:27'),
(2, '1.3.0-beta', 'https://example.com/AZCKeeper-1.3.0-beta.zip', 16252928, 'Beta sintetica para pruebas', 1, 0, '1.2.0', 1, '2026-04-28', '2026-04-29 20:59:27', '2026-04-29 20:59:27');

-- --------------------------------------------------------

--
-- Table structure for table `keeper_daily_metrics`
--

CREATE TABLE `keeper_daily_metrics` (
  `id` bigint(20) NOT NULL,
  `user_id` bigint(20) NOT NULL,
  `device_id` bigint(20) NOT NULL,
  `day_date` date NOT NULL,
  `metric_key` varchar(120) NOT NULL,
  `metric_value` bigint(20) NOT NULL DEFAULT 0,
  `meta_json` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL CHECK (json_valid(`meta_json`)),
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `keeper_data_sources`
--

CREATE TABLE `keeper_data_sources` (
  `id` bigint(20) NOT NULL,
  `firma_id` bigint(20) NOT NULL,
  `source_type` varchar(40) NOT NULL DEFAULT 'mysql',
  `label` varchar(190) DEFAULT NULL,
  `db_host` varchar(190) DEFAULT NULL,
  `db_port` int(11) NOT NULL DEFAULT 3306,
  `db_name` varchar(190) DEFAULT NULL,
  `db_user` varchar(190) DEFAULT NULL,
  `db_pass` text DEFAULT NULL,
  `employee_table` varchar(120) DEFAULT 'employee',
  `is_active` tinyint(1) NOT NULL DEFAULT 1,
  `last_sync` timestamp NULL DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `keeper_data_sources`
--

INSERT INTO `keeper_data_sources` (`id`, `firma_id`, `source_type`, `label`, `db_host`, `db_port`, `db_name`, `db_user`, `db_pass`, `employee_table`, `is_active`, `last_sync`, `created_at`, `updated_at`) VALUES
(1, 1, 'mysql', 'Legacy local', 'localhost', 3306, 'azckeeper_local', 'root', '', 'employee', 1, NULL, '2026-04-29 20:59:28', '2026-04-29 20:59:28');

-- --------------------------------------------------------

--
-- Table structure for table `keeper_devices`
--

CREATE TABLE `keeper_devices` (
  `id` bigint(20) NOT NULL,
  `user_id` bigint(20) NOT NULL,
  `device_guid` char(36) NOT NULL,
  `device_name` varchar(190) DEFAULT NULL,
  `client_version` varchar(50) DEFAULT NULL,
  `serial_hint` varchar(190) DEFAULT NULL,
  `status` enum('active','revoked') NOT NULL DEFAULT 'active',
  `last_seen_at` timestamp NULL DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `keeper_devices`
--

INSERT INTO `keeper_devices` (`id`, `user_id`, `device_guid`, `device_name`, `client_version`, `serial_hint`, `status`, `last_seen_at`, `created_at`, `updated_at`) VALUES
(1, 1, '11111111-1111-1111-1111-111111111111', 'KOICHI-LT', '1.2.0', 'KCH-001', 'active', '2026-04-29 20:59:27', '2026-04-29 20:59:27', '2026-04-29 20:59:27'),
(2, 2, '22222222-2222-2222-2222-222222222222', 'ANA-PC', '1.2.0', 'ANA-002', 'active', '2026-04-29 20:56:27', '2026-04-29 20:59:27', '2026-04-29 20:59:27'),
(3, 3, '33333333-3333-3333-3333-333333333333', 'CARLOS-PC', '1.1.5', 'CAR-003', 'active', '2026-04-29 20:39:27', '2026-04-29 20:59:27', '2026-04-29 20:59:27'),
(4, 2, 'c7d15e48-06d3-40c4-9e64-0fbce19e213a', 'DESKTOP-7PJHG4T', '1.0.0.0', NULL, 'active', '2026-04-30 15:37:41', '2026-04-30 15:17:22', '2026-04-30 15:37:41');

-- --------------------------------------------------------

--
-- Table structure for table `keeper_device_locks`
--

CREATE TABLE `keeper_device_locks` (
  `id` bigint(20) NOT NULL,
  `device_id` bigint(20) NOT NULL,
  `user_id` bigint(20) NOT NULL,
  `locked_by_admin_id` bigint(20) DEFAULT NULL,
  `lock_reason` varchar(500) DEFAULT NULL,
  `locked_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `unlocked_at` timestamp NULL DEFAULT NULL,
  `unlock_pin_hash` char(64) DEFAULT NULL,
  `is_active` tinyint(1) NOT NULL DEFAULT 1
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `keeper_dual_job_alerts`
--

CREATE TABLE `keeper_dual_job_alerts` (
  `id` bigint(20) NOT NULL,
  `user_id` bigint(20) NOT NULL,
  `day_date` date NOT NULL,
  `alert_type` varchar(80) NOT NULL,
  `severity` enum('low','medium','high') NOT NULL DEFAULT 'low',
  `evidence_json` longtext DEFAULT NULL,
  `is_reviewed` tinyint(1) NOT NULL DEFAULT 0,
  `review_result` enum('productive','unproductive') DEFAULT NULL,
  `reviewed_by` bigint(20) DEFAULT NULL,
  `reviewed_at` timestamp NULL DEFAULT NULL,
  `notes` text DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `keeper_dual_job_alerts`
--

INSERT INTO `keeper_dual_job_alerts` (`id`, `user_id`, `day_date`, `alert_type`, `severity`, `evidence_json`, `is_reviewed`, `review_result`, `reviewed_by`, `reviewed_at`, `notes`, `created_at`) VALUES
(1, 3, '2026-04-29', 'remote_desktop', 'high', '{\"days_detected\":4,\"sample_days\":[{\"date\":\"synthetic\",\"seconds\":3600}]}', 0, NULL, NULL, NULL, 'Alerta sintetica para pruebas', '2026-04-29 20:59:27');

-- --------------------------------------------------------

--
-- Table structure for table `keeper_enrollment_requests`
--

CREATE TABLE `keeper_enrollment_requests` (
  `id` bigint(20) NOT NULL,
  `cc` varchar(20) NOT NULL,
  `password_hash` varchar(255) NOT NULL,
  `device_guid` char(36) DEFAULT NULL,
  `device_name` varchar(190) DEFAULT NULL,
  `attempted_ip` varchar(64) DEFAULT NULL,
  `status` enum('pending','approved','rejected') NOT NULL DEFAULT 'pending',
  `reviewed_by` bigint(20) DEFAULT NULL,
  `reviewed_at` timestamp NULL DEFAULT NULL,
  `notes` text DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `keeper_enrollment_requests`
--

INSERT INTO `keeper_enrollment_requests` (`id`, `cc`, `password_hash`, `device_guid`, `device_name`, `attempted_ip`, `status`, `reviewed_by`, `reviewed_at`, `notes`, `created_at`, `updated_at`) VALUES
(1, '999000111', '$2y$10$RZvL0bbzSEWmHS07Zyfil.m03ZTbDTkikPb0wEb7EdwHaDJva1MqO', '44444444-4444-4444-4444-444444444444', 'VISITOR-LAPTOP', '127.0.0.1', 'pending', NULL, NULL, 'Solicitud sintetica', '2026-04-29 20:59:27', '2026-04-29 20:59:27');

-- --------------------------------------------------------

--
-- Table structure for table `keeper_events`
--

CREATE TABLE `keeper_events` (
  `id` bigint(20) NOT NULL,
  `user_id` bigint(20) NOT NULL,
  `device_id` bigint(20) NOT NULL,
  `module_code` varchar(64) NOT NULL,
  `event_type` varchar(100) NOT NULL,
  `start_at` datetime DEFAULT NULL,
  `end_at` datetime DEFAULT NULL,
  `duration_seconds` int(11) DEFAULT NULL,
  `numeric_1` bigint(20) DEFAULT NULL,
  `numeric_2` bigint(20) DEFAULT NULL,
  `numeric_3` bigint(20) DEFAULT NULL,
  `numeric_4` bigint(20) DEFAULT NULL,
  `text_1` varchar(190) DEFAULT NULL,
  `text_2` varchar(190) DEFAULT NULL,
  `payload_json` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL CHECK (json_valid(`payload_json`)),
  `day_date` date NOT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `keeper_firmas`
--

CREATE TABLE `keeper_firmas` (
  `id` bigint(20) NOT NULL,
  `nombre` varchar(190) NOT NULL,
  `manager` varchar(190) DEFAULT NULL,
  `mail_manager` varchar(190) DEFAULT NULL,
  `activa` tinyint(1) NOT NULL DEFAULT 1,
  `created_at` timestamp NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `keeper_firmas`
--

INSERT INTO `keeper_firmas` (`id`, `nombre`, `manager`, `mail_manager`, `activa`, `created_at`, `updated_at`) VALUES
(1, 'AZC Operaciones', 'Gerencia Local', 'gerencia.local@azc.com.co', 1, '2026-04-29 20:59:27', '2026-04-29 20:59:27');

-- --------------------------------------------------------

--
-- Table structure for table `keeper_focus_daily`
--

CREATE TABLE `keeper_focus_daily` (
  `id` bigint(20) NOT NULL,
  `user_id` bigint(20) NOT NULL,
  `device_id` bigint(20) NOT NULL,
  `day_date` date NOT NULL,
  `context_switches` int(11) NOT NULL DEFAULT 0,
  `deep_work_seconds` int(11) NOT NULL DEFAULT 0,
  `deep_work_sessions` int(11) NOT NULL DEFAULT 0,
  `distraction_seconds` int(11) NOT NULL DEFAULT 0,
  `longest_focus_streak_seconds` int(11) NOT NULL DEFAULT 0,
  `focus_score` int(11) NOT NULL DEFAULT 0,
  `productivity_pct` int(11) NOT NULL DEFAULT 0,
  `constancy_pct` int(11) NOT NULL DEFAULT 0,
  `first_activity_time` time DEFAULT NULL,
  `scheduled_start` time DEFAULT NULL,
  `punctuality_minutes` int(11) NOT NULL DEFAULT 0,
  `created_at` timestamp NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `keeper_focus_daily`
--

INSERT INTO `keeper_focus_daily` (`id`, `user_id`, `device_id`, `day_date`, `context_switches`, `deep_work_seconds`, `deep_work_sessions`, `distraction_seconds`, `longest_focus_streak_seconds`, `focus_score`, `productivity_pct`, `constancy_pct`, `first_activity_time`, `scheduled_start`, `punctuality_minutes`, `created_at`, `updated_at`) VALUES
(1, 1, 1, '2026-04-29', 12, 14400, 3, 1800, 7200, 86, 90, 84, '07:05:00', '07:00:00', -5, '2026-04-29 20:59:27', '2026-04-29 20:59:27'),
(2, 2, 2, '2026-04-29', 18, 10800, 2, 2400, 5400, 73, 82, 77, '08:02:00', '08:00:00', -2, '2026-04-29 20:59:27', '2026-04-29 20:59:27'),
(3, 3, 3, '2026-04-29', 24, 7200, 1, 4200, 3600, 58, 63, 61, '07:20:00', '07:00:00', -20, '2026-04-29 20:59:27', '2026-04-29 20:59:27'),
(4, 1, 1, '2026-04-28', 10, 13800, 3, 1500, 6900, 88, 92, 86, '07:00:00', '07:00:00', 0, '2026-04-29 20:59:27', '2026-04-29 20:59:27'),
(5, 2, 2, '2026-04-28', 15, 9900, 2, 2100, 4200, 76, 84, 79, '08:10:00', '08:00:00', -10, '2026-04-29 20:59:27', '2026-04-29 20:59:27'),
(6, 3, 3, '2026-04-28', 28, 6300, 1, 4800, 3000, 49, 57, 54, '07:50:00', '07:00:00', -50, '2026-04-29 20:59:27', '2026-04-29 20:59:27');

-- --------------------------------------------------------

--
-- Table structure for table `keeper_handshake_log`
--

CREATE TABLE `keeper_handshake_log` (
  `id` bigint(20) NOT NULL,
  `user_id` bigint(20) NOT NULL,
  `device_id` bigint(20) NOT NULL,
  `client_version` varchar(50) DEFAULT NULL,
  `request_json` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL CHECK (json_valid(`request_json`)),
  `response_json` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL CHECK (json_valid(`response_json`)),
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `keeper_module_catalog`
--

CREATE TABLE `keeper_module_catalog` (
  `module_code` varchar(64) NOT NULL,
  `name` varchar(190) NOT NULL,
  `default_enabled` tinyint(1) NOT NULL DEFAULT 0,
  `config_schema_json` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL CHECK (json_valid(`config_schema_json`)),
  `active` tinyint(1) NOT NULL DEFAULT 1,
  `created_at` timestamp NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `keeper_panel_roles`
--

CREATE TABLE `keeper_panel_roles` (
  `id` bigint(20) NOT NULL,
  `slug` varchar(64) NOT NULL,
  `label` varchar(120) NOT NULL,
  `description` varchar(255) DEFAULT NULL,
  `hierarchy_level` int(11) NOT NULL DEFAULT 0,
  `color_bg` varchar(80) DEFAULT NULL,
  `color_text` varchar(80) DEFAULT NULL,
  `is_system` tinyint(1) NOT NULL DEFAULT 0,
  `permissions` longtext DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `keeper_panel_roles`
--

INSERT INTO `keeper_panel_roles` (`id`, `slug`, `label`, `description`, `hierarchy_level`, `color_bg`, `color_text`, `is_system`, `permissions`, `created_at`, `updated_at`) VALUES
(1, 'superadmin', 'Superadmin', 'Acceso total', 100, 'bg-red-100', 'text-red-800', 1, NULL, '2026-04-29 20:59:27', '2026-04-29 20:59:27'),
(2, 'admin', 'Admin', 'Gestion operativa', 50, 'bg-blue-100', 'text-blue-800', 1, '{\"dashboard\":{\"can_view\":true},\"users\":{\"can_view\":true,\"can_create\":true,\"can_edit\":true},\"devices\":{\"can_view\":true,\"can_edit\":true},\"productivity\":{\"can_view\":true},\"dual_job\":{\"can_view\":true}}', '2026-04-29 20:59:27', '2026-04-29 20:59:27'),
(3, 'viewer', 'Viewer', 'Solo lectura', 10, 'bg-gray-100', 'text-gray-600', 1, '{\"dashboard\":{\"can_view\":true},\"users\":{\"can_view\":true},\"productivity\":{\"can_view\":true}}', '2026-04-29 20:59:27', '2026-04-29 20:59:27');

-- --------------------------------------------------------

--
-- Table structure for table `keeper_panel_settings`
--

CREATE TABLE `keeper_panel_settings` (
  `id` bigint(20) NOT NULL,
  `setting_key` varchar(120) NOT NULL,
  `setting_value` longtext DEFAULT NULL,
  `updated_by` bigint(20) DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `keeper_panel_settings`
--

INSERT INTO `keeper_panel_settings` (`id`, `setting_key`, `setting_value`, `updated_by`, `created_at`, `updated_at`) VALUES
(1, 'menu_visibility', '{\"dashboard\":[\"superadmin\",\"admin\",\"viewer\"],\"sedes-dashboard\":[\"superadmin\"],\"users\":[\"superadmin\",\"admin\",\"viewer\"],\"devices\":[\"superadmin\",\"admin\"],\"productivity\":[\"superadmin\",\"admin\",\"viewer\"],\"policies\":[\"superadmin\"],\"releases\":[\"superadmin\"],\"admin-users\":[\"superadmin\"],\"assignments\":[\"superadmin\"],\"organization\":[\"superadmin\"],\"roles\":[\"superadmin\"],\"settings\":[\"superadmin\"],\"server-health\":[\"superadmin\"],\"dual_job\":[\"superadmin\",\"admin\"],\"pending_users\":[\"superadmin\"]}', 1, '2026-04-29 20:59:27', '2026-04-29 20:59:27'),
(2, 'leisure_apps', '{\"apps\":[\"chrome.exe\",\"spotify.exe\"],\"windows\":[\"facebook.com\"]}', 1, '2026-04-29 20:59:27', '2026-04-30 15:33:08'),
(3, 'productivity.focus_weights', '{\"context_switches\":20,\"deep_work\":25,\"distraction\":20,\"punctuality\":15,\"constancy\":20}', 1, '2026-04-29 20:59:27', '2026-04-29 20:59:27'),
(4, 'productivity.deep_work_threshold_minutes', '25', 1, '2026-04-29 20:59:27', '2026-04-29 20:59:27'),
(5, 'productivity.enabled', 'true', 1, '2026-04-29 20:59:27', '2026-04-29 20:59:27'),
(6, 'dual_job.enabled', 'true', 1, '2026-04-29 20:59:27', '2026-04-29 20:59:27'),
(7, 'dual_job.after_hours_threshold_days', '5', 1, '2026-04-29 20:59:27', '2026-04-29 20:59:27'),
(8, 'dual_job.after_hours_min_seconds', '3600', 1, '2026-04-29 20:59:27', '2026-04-29 20:59:27'),
(9, 're_enroll_enabled', 'true', 1, '2026-04-29 20:59:27', '2026-04-29 20:59:27');

-- --------------------------------------------------------

--
-- Table structure for table `keeper_policy_assignments`
--

CREATE TABLE `keeper_policy_assignments` (
  `id` bigint(20) NOT NULL,
  `scope` enum('global','user','device') NOT NULL,
  `user_id` bigint(20) DEFAULT NULL,
  `device_id` bigint(20) DEFAULT NULL,
  `version` int(11) NOT NULL DEFAULT 1,
  `priority` int(11) NOT NULL DEFAULT 100,
  `is_active` tinyint(1) NOT NULL DEFAULT 1,
  `policy_json` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin NOT NULL CHECK (json_valid(`policy_json`)),
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `keeper_policy_assignments`
--

INSERT INTO `keeper_policy_assignments` (`id`, `scope`, `user_id`, `device_id`, `version`, `priority`, `is_active`, `policy_json`, `created_at`) VALUES
(1, 'global', NULL, NULL, 1, 1, 1, '{\"blocking\":{\"enabled\":true},\"productivity\":{\"enabled\":true},\"workSchedule\":{\"timezone\":\"America/Bogota\"}}', '2026-04-29 20:59:27');

-- --------------------------------------------------------

--
-- Table structure for table `keeper_sedes`
--

CREATE TABLE `keeper_sedes` (
  `id` bigint(20) NOT NULL,
  `nombre` varchar(190) NOT NULL,
  `codigo` varchar(50) NOT NULL,
  `descripcion` varchar(255) DEFAULT NULL,
  `activa` tinyint(1) NOT NULL DEFAULT 1,
  `created_at` timestamp NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `keeper_sedes`
--

INSERT INTO `keeper_sedes` (`id`, `nombre`, `codigo`, `descripcion`, `activa`, `created_at`, `updated_at`) VALUES
(1, 'Principal Bogota', 'BOG-PPAL', 'Sede sintetica principal', 1, '2026-04-29 20:59:27', '2026-04-29 20:59:27'),
(2, 'Soporte Remoto', 'REM-001', 'Sede sintetica remota', 1, '2026-04-29 20:59:27', '2026-04-29 20:59:27');

-- --------------------------------------------------------

--
-- Table structure for table `keeper_sessions`
--

CREATE TABLE `keeper_sessions` (
  `id` bigint(20) NOT NULL,
  `user_id` bigint(20) NOT NULL,
  `device_id` bigint(20) DEFAULT NULL,
  `token_hash` char(64) NOT NULL,
  `refresh_hash` char(64) DEFAULT NULL,
  `issued_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `expires_at` timestamp NULL DEFAULT NULL,
  `revoked_at` timestamp NULL DEFAULT NULL,
  `ip` varchar(64) DEFAULT NULL,
  `user_agent` varchar(255) DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `keeper_sessions`
--

INSERT INTO `keeper_sessions` (`id`, `user_id`, `device_id`, `token_hash`, `refresh_hash`, `issued_at`, `expires_at`, `revoked_at`, `ip`, `user_agent`) VALUES
(1, 2, 4, '1dde3d665e12fdd24d3a05ea9317f9edec26cea1b6cc895339a35ad48ff67e4a', NULL, '2026-04-30 15:17:22', NULL, NULL, '::1', 'Mozilla/5.0 (Windows NT; Windows NT 10.0; es-CO) WindowsPowerShell/5.1.26100.8115'),
(2, 2, 4, 'e42e486d5a539921255ad900e30631cfedad255931599d28703e3886a55b72d3', NULL, '2026-04-30 15:22:17', NULL, NULL, '::1', 'AZCKeeper-Cliente/1.0.0.0');

-- --------------------------------------------------------

--
-- Table structure for table `keeper_sociedades`
--

CREATE TABLE `keeper_sociedades` (
  `id` bigint(20) NOT NULL,
  `nombre` varchar(190) NOT NULL,
  `nit` varchar(50) DEFAULT NULL,
  `descripcion` varchar(255) DEFAULT NULL,
  `activa` tinyint(1) NOT NULL DEFAULT 1,
  `created_at` timestamp NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `keeper_sociedades`
--

INSERT INTO `keeper_sociedades` (`id`, `nombre`, `nit`, `descripcion`, `activa`, `created_at`, `updated_at`) VALUES
(1, 'AZC Holding', '900100200-1', 'Sociedad sintetica local', 1, '2026-04-29 20:59:27', '2026-04-29 20:59:27');

-- --------------------------------------------------------

--
-- Table structure for table `keeper_suspicious_apps`
--

CREATE TABLE `keeper_suspicious_apps` (
  `id` bigint(20) NOT NULL,
  `app_pattern` varchar(190) NOT NULL,
  `category` varchar(80) NOT NULL,
  `description` varchar(255) DEFAULT NULL,
  `is_active` tinyint(1) NOT NULL DEFAULT 1,
  `created_at` timestamp NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `keeper_suspicious_apps`
--

INSERT INTO `keeper_suspicious_apps` (`id`, `app_pattern`, `category`, `description`, `is_active`, `created_at`, `updated_at`) VALUES
(1, 'anydesk', 'remote_desktop', 'Cliente de escritorio remoto', 1, '2026-04-29 20:59:28', '2026-04-29 20:59:28'),
(2, 'mstsc', 'remote_desktop', 'Remote Desktop de Windows', 1, '2026-04-29 20:59:28', '2026-04-29 20:59:28');

-- --------------------------------------------------------

--
-- Table structure for table `keeper_users`
--

CREATE TABLE `keeper_users` (
  `id` bigint(20) NOT NULL,
  `legacy_employee_id` int(11) DEFAULT NULL,
  `cc` varchar(20) DEFAULT NULL,
  `email` varchar(190) DEFAULT NULL,
  `display_name` varchar(190) DEFAULT NULL,
  `status` enum('active','inactive','locked') NOT NULL DEFAULT 'active',
  `password_hash` varchar(255) DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `keeper_users`
--

INSERT INTO `keeper_users` (`id`, `legacy_employee_id`, `cc`, `email`, `display_name`, `status`, `password_hash`, `created_at`, `updated_at`) VALUES
(1, 9001, '1151963002', 'koichi.fumikatsu@azc.com.co', 'Koichi Fumikatsu', 'active', '$2y$10$RZvL0bbzSEWmHS07Zyfil.m03ZTbDTkikPb0wEb7EdwHaDJva1MqO', '2026-04-29 20:59:27', '2026-04-29 20:59:27'),
(2, 9002, '1000000001', 'ana.soporte@azc.local', 'Ana Soporte', 'active', '$2y$10$lVbt2GC70iLcmPGwJzR36eHQ.MVBeqvWsv7k0GmoBL3CYltdW7rCi', '2026-04-29 20:59:27', '2026-04-30 15:16:56'),
(3, 9003, '1000000002', 'carlos.ops@azc.local', 'Carlos Operaciones', 'active', '$2y$10$RZvL0bbzSEWmHS07Zyfil.m03ZTbDTkikPb0wEb7EdwHaDJva1MqO', '2026-04-29 20:59:27', '2026-04-29 20:59:27');

-- --------------------------------------------------------

--
-- Table structure for table `keeper_user_assignments`
--

CREATE TABLE `keeper_user_assignments` (
  `id` bigint(20) NOT NULL,
  `keeper_user_id` bigint(20) NOT NULL,
  `sociedad_id` bigint(20) DEFAULT NULL,
  `firm_id` bigint(20) DEFAULT NULL,
  `area_id` bigint(20) DEFAULT NULL,
  `cargo_id` bigint(20) DEFAULT NULL,
  `sede_id` bigint(20) DEFAULT NULL,
  `assigned_by` bigint(20) DEFAULT NULL,
  `manual_override` tinyint(1) NOT NULL DEFAULT 0,
  `assigned_at` timestamp NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `keeper_user_assignments`
--

INSERT INTO `keeper_user_assignments` (`id`, `keeper_user_id`, `sociedad_id`, `firm_id`, `area_id`, `cargo_id`, `sede_id`, `assigned_by`, `manual_override`, `assigned_at`, `updated_at`) VALUES
(1, 1, 1, 1, 1, 1, 1, 1, 1, '2026-04-29 20:59:27', '2026-04-29 20:59:27'),
(2, 2, 1, 1, 1, 2, 2, 1, 1, '2026-04-29 20:59:27', '2026-04-29 20:59:27'),
(3, 3, 1, 1, 2, 3, 1, 1, 1, '2026-04-29 20:59:27', '2026-04-29 20:59:27');

-- --------------------------------------------------------

--
-- Table structure for table `keeper_window_episode`
--

CREATE TABLE `keeper_window_episode` (
  `id` bigint(20) NOT NULL,
  `user_id` bigint(20) NOT NULL,
  `device_id` bigint(20) NOT NULL,
  `start_at` datetime NOT NULL,
  `end_at` datetime NOT NULL,
  `duration_seconds` int(11) NOT NULL,
  `process_name` varchar(190) DEFAULT NULL,
  `app_name` varchar(190) DEFAULT NULL,
  `window_title` varchar(512) DEFAULT NULL,
  `is_in_call` tinyint(1) NOT NULL DEFAULT 0,
  `call_app_hint` varchar(190) DEFAULT NULL,
  `day_date` date NOT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Dumping data for table `keeper_window_episode`
--

INSERT INTO `keeper_window_episode` (`id`, `user_id`, `device_id`, `start_at`, `end_at`, `duration_seconds`, `process_name`, `app_name`, `window_title`, `is_in_call`, `call_app_hint`, `day_date`, `created_at`) VALUES
(1, 1, 1, '2026-04-29 07:05:00', '2026-04-29 09:30:00', 8700, 'phpstorm64.exe', 'PhpStorm', 'AZCKeeper - Proyecto local', 0, NULL, '2026-04-29', '2026-04-29 20:59:27'),
(2, 1, 1, '2026-04-29 09:35:00', '2026-04-29 10:10:00', 2100, 'chrome.exe', 'Google Chrome', 'GitHub - issues y pull requests', 0, NULL, '2026-04-29', '2026-04-29 20:59:27'),
(3, 1, 1, '2026-04-29 10:15:00', '2026-04-29 11:00:00', 2700, 'teams.exe', 'Microsoft Teams', 'Daily sync AZC', 1, 'teams', '2026-04-29', '2026-04-29 20:59:27'),
(4, 2, 2, '2026-04-29 08:02:00', '2026-04-29 10:00:00', 7080, 'excel.exe', 'Microsoft Excel', 'Reporte seguimiento diario', 0, NULL, '2026-04-29', '2026-04-29 20:59:27'),
(5, 2, 2, '2026-04-29 10:05:00', '2026-04-29 10:35:00', 1800, 'chrome.exe', 'Google Chrome', 'YouTube - tutorial soporte', 0, NULL, '2026-04-29', '2026-04-29 20:59:27'),
(6, 3, 3, '2026-04-29 19:10:00', '2026-04-29 20:10:00', 3600, 'anydesk.exe', 'AnyDesk', 'Remote session support', 0, NULL, '2026-04-29', '2026-04-29 20:59:27'),
(7, 1, 1, '2026-04-28 07:00:00', '2026-04-28 09:00:00', 7200, 'phpstorm64.exe', 'PhpStorm', 'Implementacion dashboard', 0, NULL, '2026-04-28', '2026-04-29 20:59:27'),
(8, 2, 2, '2026-04-28 08:10:00', '2026-04-28 09:20:00', 4200, 'outlook.exe', 'Outlook', 'Bandeja de entrada - soporte', 0, NULL, '2026-04-28', '2026-04-29 20:59:27'),
(9, 3, 3, '2026-04-28 20:00:00', '2026-04-28 21:15:00', 4500, 'mstsc.exe', 'Remote Desktop', 'Servidor externo', 0, NULL, '2026-04-28', '2026-04-29 20:59:27'),
(10, 2, 4, '2026-04-30 10:19:17', '2026-04-30 10:19:39', 22, 'WindowsTerminal', 'WindowsTerminal', 'Windows PowerShell', 0, NULL, '2026-04-30', '2026-04-30 15:22:38'),
(11, 2, 4, '2026-04-30 10:19:39', '2026-04-30 10:19:55', 15, 'Code', 'Code', 'index.php - AZCKeeper - Visual Studio Code', 0, NULL, '2026-04-30', '2026-04-30 15:22:38'),
(12, 2, 4, '2026-04-30 10:19:55', '2026-04-30 10:20:08', 13, 'WindowsTerminal', 'WindowsTerminal', 'Windows PowerShell', 0, NULL, '2026-04-30', '2026-04-30 15:22:38'),
(13, 2, 4, '2026-04-30 10:20:09', '2026-04-30 10:20:26', 17, 'WindowsTerminal', 'WindowsTerminal', 'Windows PowerShell', 0, NULL, '2026-04-30', '2026-04-30 15:22:39');

-- --------------------------------------------------------

--
-- Table structure for table `keeper_work_schedules`
--

CREATE TABLE `keeper_work_schedules` (
  `id` bigint(20) NOT NULL,
  `user_id` bigint(20) DEFAULT NULL,
  `work_start_time` time DEFAULT '07:00:00',
  `work_end_time` time DEFAULT '19:00:00',
  `lunch_start_time` time DEFAULT '12:00:00',
  `lunch_end_time` time DEFAULT '13:00:00',
  `applicable_days` varchar(50) DEFAULT '1,2,3,4,5',
  `timezone` varchar(50) DEFAULT 'America/Bogota',
  `is_active` tinyint(1) DEFAULT 1,
  `created_at` timestamp NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `keeper_work_schedules`
--

INSERT INTO `keeper_work_schedules` (`id`, `user_id`, `work_start_time`, `work_end_time`, `lunch_start_time`, `lunch_end_time`, `applicable_days`, `timezone`, `is_active`, `created_at`) VALUES
(1, NULL, '07:00:00', '19:00:00', '12:00:00', '13:00:00', '1,2,3,4,5', 'America/Bogota', 1, '2026-04-29 20:59:27'),
(2, 2, '08:00:00', '17:00:00', '12:30:00', '13:30:00', '1,2,3,4,5', 'America/Bogota', 1, '2026-04-29 20:59:27');

--
-- Indexes for dumped tables
--

--
-- Indexes for table `employee`
--
ALTER TABLE `employee`
  ADD PRIMARY KEY (`id`);

--
-- Indexes for table `keeper_activity_day`
--
ALTER TABLE `keeper_activity_day`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `uk_keeper_activity_unique` (`user_id`,`device_id`,`day_date`),
  ADD KEY `ix_keeper_activity_day` (`day_date`),
  ADD KEY `ix_keeper_activity_user_day` (`user_id`,`day_date`),
  ADD KEY `fk_keeper_activity_device` (`device_id`);

--
-- Indexes for table `keeper_admin_accounts`
--
ALTER TABLE `keeper_admin_accounts`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `uk_keeper_admin_accounts_user` (`keeper_user_id`);

--
-- Indexes for table `keeper_admin_sessions`
--
ALTER TABLE `keeper_admin_sessions`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `uk_keeper_admin_sessions_hash` (`token_hash`),
  ADD KEY `ix_keeper_admin_sessions_admin` (`admin_id`);

--
-- Indexes for table `keeper_app_classifications`
--
ALTER TABLE `keeper_app_classifications`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `uk_keeper_app_classifications_pattern` (`app_pattern`);

--
-- Indexes for table `keeper_areas`
--
ALTER TABLE `keeper_areas`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `uk_keeper_areas_nombre` (`nombre`);

--
-- Indexes for table `keeper_audit_log`
--
ALTER TABLE `keeper_audit_log`
  ADD PRIMARY KEY (`id`),
  ADD KEY `ix_keeper_audit_user` (`user_id`,`created_at`),
  ADD KEY `ix_keeper_audit_device` (`device_id`,`created_at`),
  ADD KEY `ix_keeper_audit_type` (`event_type`,`created_at`);

--
-- Indexes for table `keeper_cargos`
--
ALTER TABLE `keeper_cargos`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `uk_keeper_cargos_nombre` (`nombre`);

--
-- Indexes for table `keeper_client_releases`
--
ALTER TABLE `keeper_client_releases`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `uk_keeper_client_releases_version` (`version`);

--
-- Indexes for table `keeper_daily_metrics`
--
ALTER TABLE `keeper_daily_metrics`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `uk_keeper_daily_metrics_unique` (`user_id`,`device_id`,`day_date`,`metric_key`),
  ADD KEY `ix_keeper_daily_metrics_user_day` (`user_id`,`day_date`),
  ADD KEY `ix_keeper_daily_metrics_key_day` (`metric_key`,`day_date`),
  ADD KEY `fk_keeper_daily_metrics_device` (`device_id`);

--
-- Indexes for table `keeper_data_sources`
--
ALTER TABLE `keeper_data_sources`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `uk_keeper_data_sources_firma` (`firma_id`);

--
-- Indexes for table `keeper_devices`
--
ALTER TABLE `keeper_devices`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `uk_keeper_devices_guid` (`device_guid`),
  ADD KEY `ix_keeper_devices_user` (`user_id`),
  ADD KEY `ix_keeper_devices_last_seen` (`last_seen_at`);

--
-- Indexes for table `keeper_device_locks`
--
ALTER TABLE `keeper_device_locks`
  ADD PRIMARY KEY (`id`),
  ADD KEY `ix_device_locks_device` (`device_id`,`is_active`),
  ADD KEY `ix_device_locks_user` (`user_id`,`is_active`);

--
-- Indexes for table `keeper_dual_job_alerts`
--
ALTER TABLE `keeper_dual_job_alerts`
  ADD PRIMARY KEY (`id`),
  ADD KEY `ix_keeper_dual_job_alerts_user` (`user_id`,`created_at`);

--
-- Indexes for table `keeper_enrollment_requests`
--
ALTER TABLE `keeper_enrollment_requests`
  ADD PRIMARY KEY (`id`);

--
-- Indexes for table `keeper_events`
--
ALTER TABLE `keeper_events`
  ADD PRIMARY KEY (`id`),
  ADD KEY `ix_keeper_events_user_day` (`user_id`,`day_date`),
  ADD KEY `ix_keeper_events_device_day` (`device_id`,`day_date`),
  ADD KEY `ix_keeper_events_type` (`event_type`,`day_date`),
  ADD KEY `ix_keeper_events_module` (`module_code`,`day_date`);

--
-- Indexes for table `keeper_firmas`
--
ALTER TABLE `keeper_firmas`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `uk_keeper_firmas_nombre` (`nombre`);

--
-- Indexes for table `keeper_focus_daily`
--
ALTER TABLE `keeper_focus_daily`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `uk_keeper_focus_daily` (`user_id`,`device_id`,`day_date`);

--
-- Indexes for table `keeper_handshake_log`
--
ALTER TABLE `keeper_handshake_log`
  ADD PRIMARY KEY (`id`),
  ADD KEY `ix_keeper_handshake_user` (`user_id`,`created_at`),
  ADD KEY `ix_keeper_handshake_device` (`device_id`,`created_at`);

--
-- Indexes for table `keeper_module_catalog`
--
ALTER TABLE `keeper_module_catalog`
  ADD PRIMARY KEY (`module_code`),
  ADD KEY `ix_keeper_module_active` (`active`);

--
-- Indexes for table `keeper_panel_roles`
--
ALTER TABLE `keeper_panel_roles`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `uk_keeper_panel_roles_slug` (`slug`);

--
-- Indexes for table `keeper_panel_settings`
--
ALTER TABLE `keeper_panel_settings`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `uk_keeper_panel_settings_key` (`setting_key`);

--
-- Indexes for table `keeper_policy_assignments`
--
ALTER TABLE `keeper_policy_assignments`
  ADD PRIMARY KEY (`id`),
  ADD KEY `ix_keeper_policy_scope_active` (`scope`,`is_active`,`priority`),
  ADD KEY `ix_keeper_policy_user_active` (`user_id`,`is_active`,`priority`),
  ADD KEY `ix_keeper_policy_device_active` (`device_id`,`is_active`,`priority`);

--
-- Indexes for table `keeper_sedes`
--
ALTER TABLE `keeper_sedes`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `uk_keeper_sedes_codigo` (`codigo`);

--
-- Indexes for table `keeper_sessions`
--
ALTER TABLE `keeper_sessions`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `uk_keeper_sessions_tokenhash` (`token_hash`),
  ADD KEY `ix_keeper_sessions_user` (`user_id`),
  ADD KEY `ix_keeper_sessions_device` (`device_id`),
  ADD KEY `ix_keeper_sessions_expires` (`expires_at`);

--
-- Indexes for table `keeper_sociedades`
--
ALTER TABLE `keeper_sociedades`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `uk_keeper_sociedades_nombre` (`nombre`);

--
-- Indexes for table `keeper_suspicious_apps`
--
ALTER TABLE `keeper_suspicious_apps`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `uk_keeper_suspicious_apps_pattern` (`app_pattern`);

--
-- Indexes for table `keeper_users`
--
ALTER TABLE `keeper_users`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `uk_keeper_users_legacy` (`legacy_employee_id`),
  ADD UNIQUE KEY `uk_keeper_users_cc` (`cc`),
  ADD KEY `ix_keeper_users_email` (`email`);

--
-- Indexes for table `keeper_user_assignments`
--
ALTER TABLE `keeper_user_assignments`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `uk_keeper_user_assignments_user` (`keeper_user_id`);

--
-- Indexes for table `keeper_window_episode`
--
ALTER TABLE `keeper_window_episode`
  ADD PRIMARY KEY (`id`),
  ADD KEY `ix_keeper_we_user_day` (`user_id`,`day_date`),
  ADD KEY `ix_keeper_we_device_day` (`device_id`,`day_date`),
  ADD KEY `ix_keeper_we_start` (`start_at`),
  ADD KEY `ix_keeper_we_process` (`process_name`);

--
-- Indexes for table `keeper_work_schedules`
--
ALTER TABLE `keeper_work_schedules`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `unique_user_schedule` (`user_id`,`is_active`);

--
-- AUTO_INCREMENT for dumped tables
--

--
-- AUTO_INCREMENT for table `keeper_activity_day`
--
ALTER TABLE `keeper_activity_day`
  MODIFY `id` bigint(20) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=8;

--
-- AUTO_INCREMENT for table `keeper_admin_accounts`
--
ALTER TABLE `keeper_admin_accounts`
  MODIFY `id` bigint(20) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=2;

--
-- AUTO_INCREMENT for table `keeper_admin_sessions`
--
ALTER TABLE `keeper_admin_sessions`
  MODIFY `id` bigint(20) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=5;

--
-- AUTO_INCREMENT for table `keeper_app_classifications`
--
ALTER TABLE `keeper_app_classifications`
  MODIFY `id` bigint(20) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=3;

--
-- AUTO_INCREMENT for table `keeper_areas`
--
ALTER TABLE `keeper_areas`
  MODIFY `id` bigint(20) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=3;

--
-- AUTO_INCREMENT for table `keeper_audit_log`
--
ALTER TABLE `keeper_audit_log`
  MODIFY `id` bigint(20) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=3;

--
-- AUTO_INCREMENT for table `keeper_cargos`
--
ALTER TABLE `keeper_cargos`
  MODIFY `id` bigint(20) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=4;

--
-- AUTO_INCREMENT for table `keeper_client_releases`
--
ALTER TABLE `keeper_client_releases`
  MODIFY `id` bigint(20) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=3;

--
-- AUTO_INCREMENT for table `keeper_daily_metrics`
--
ALTER TABLE `keeper_daily_metrics`
  MODIFY `id` bigint(20) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `keeper_data_sources`
--
ALTER TABLE `keeper_data_sources`
  MODIFY `id` bigint(20) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=2;

--
-- AUTO_INCREMENT for table `keeper_devices`
--
ALTER TABLE `keeper_devices`
  MODIFY `id` bigint(20) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=5;

--
-- AUTO_INCREMENT for table `keeper_device_locks`
--
ALTER TABLE `keeper_device_locks`
  MODIFY `id` bigint(20) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `keeper_dual_job_alerts`
--
ALTER TABLE `keeper_dual_job_alerts`
  MODIFY `id` bigint(20) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=2;

--
-- AUTO_INCREMENT for table `keeper_enrollment_requests`
--
ALTER TABLE `keeper_enrollment_requests`
  MODIFY `id` bigint(20) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=2;

--
-- AUTO_INCREMENT for table `keeper_events`
--
ALTER TABLE `keeper_events`
  MODIFY `id` bigint(20) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `keeper_firmas`
--
ALTER TABLE `keeper_firmas`
  MODIFY `id` bigint(20) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=2;

--
-- AUTO_INCREMENT for table `keeper_focus_daily`
--
ALTER TABLE `keeper_focus_daily`
  MODIFY `id` bigint(20) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=7;

--
-- AUTO_INCREMENT for table `keeper_handshake_log`
--
ALTER TABLE `keeper_handshake_log`
  MODIFY `id` bigint(20) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `keeper_panel_roles`
--
ALTER TABLE `keeper_panel_roles`
  MODIFY `id` bigint(20) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=4;

--
-- AUTO_INCREMENT for table `keeper_panel_settings`
--
ALTER TABLE `keeper_panel_settings`
  MODIFY `id` bigint(20) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=10;

--
-- AUTO_INCREMENT for table `keeper_policy_assignments`
--
ALTER TABLE `keeper_policy_assignments`
  MODIFY `id` bigint(20) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=2;

--
-- AUTO_INCREMENT for table `keeper_sedes`
--
ALTER TABLE `keeper_sedes`
  MODIFY `id` bigint(20) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=3;

--
-- AUTO_INCREMENT for table `keeper_sessions`
--
ALTER TABLE `keeper_sessions`
  MODIFY `id` bigint(20) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=3;

--
-- AUTO_INCREMENT for table `keeper_sociedades`
--
ALTER TABLE `keeper_sociedades`
  MODIFY `id` bigint(20) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=2;

--
-- AUTO_INCREMENT for table `keeper_suspicious_apps`
--
ALTER TABLE `keeper_suspicious_apps`
  MODIFY `id` bigint(20) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=3;

--
-- AUTO_INCREMENT for table `keeper_users`
--
ALTER TABLE `keeper_users`
  MODIFY `id` bigint(20) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=4;

--
-- AUTO_INCREMENT for table `keeper_user_assignments`
--
ALTER TABLE `keeper_user_assignments`
  MODIFY `id` bigint(20) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=4;

--
-- AUTO_INCREMENT for table `keeper_window_episode`
--
ALTER TABLE `keeper_window_episode`
  MODIFY `id` bigint(20) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=14;

--
-- AUTO_INCREMENT for table `keeper_work_schedules`
--
ALTER TABLE `keeper_work_schedules`
  MODIFY `id` bigint(20) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=3;

--
-- Constraints for dumped tables
--

--
-- Constraints for table `keeper_activity_day`
--
ALTER TABLE `keeper_activity_day`
  ADD CONSTRAINT `fk_keeper_activity_device` FOREIGN KEY (`device_id`) REFERENCES `keeper_devices` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `fk_keeper_activity_user` FOREIGN KEY (`user_id`) REFERENCES `keeper_users` (`id`) ON DELETE CASCADE;

--
-- Constraints for table `keeper_audit_log`
--
ALTER TABLE `keeper_audit_log`
  ADD CONSTRAINT `fk_keeper_audit_device` FOREIGN KEY (`device_id`) REFERENCES `keeper_devices` (`id`) ON DELETE SET NULL,
  ADD CONSTRAINT `fk_keeper_audit_user` FOREIGN KEY (`user_id`) REFERENCES `keeper_users` (`id`) ON DELETE SET NULL;

--
-- Constraints for table `keeper_daily_metrics`
--
ALTER TABLE `keeper_daily_metrics`
  ADD CONSTRAINT `fk_keeper_daily_metrics_device` FOREIGN KEY (`device_id`) REFERENCES `keeper_devices` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `fk_keeper_daily_metrics_user` FOREIGN KEY (`user_id`) REFERENCES `keeper_users` (`id`) ON DELETE CASCADE;

--
-- Constraints for table `keeper_devices`
--
ALTER TABLE `keeper_devices`
  ADD CONSTRAINT `fk_keeper_devices_user` FOREIGN KEY (`user_id`) REFERENCES `keeper_users` (`id`) ON DELETE CASCADE;

--
-- Constraints for table `keeper_device_locks`
--
ALTER TABLE `keeper_device_locks`
  ADD CONSTRAINT `fk_device_locks_device` FOREIGN KEY (`device_id`) REFERENCES `keeper_devices` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `fk_device_locks_user` FOREIGN KEY (`user_id`) REFERENCES `keeper_users` (`id`) ON DELETE CASCADE;

--
-- Constraints for table `keeper_events`
--
ALTER TABLE `keeper_events`
  ADD CONSTRAINT `fk_keeper_events_device` FOREIGN KEY (`device_id`) REFERENCES `keeper_devices` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `fk_keeper_events_user` FOREIGN KEY (`user_id`) REFERENCES `keeper_users` (`id`) ON DELETE CASCADE;

--
-- Constraints for table `keeper_handshake_log`
--
ALTER TABLE `keeper_handshake_log`
  ADD CONSTRAINT `fk_keeper_handshake_device` FOREIGN KEY (`device_id`) REFERENCES `keeper_devices` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `fk_keeper_handshake_user` FOREIGN KEY (`user_id`) REFERENCES `keeper_users` (`id`) ON DELETE CASCADE;

--
-- Constraints for table `keeper_policy_assignments`
--
ALTER TABLE `keeper_policy_assignments`
  ADD CONSTRAINT `fk_keeper_policy_device` FOREIGN KEY (`device_id`) REFERENCES `keeper_devices` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `fk_keeper_policy_user` FOREIGN KEY (`user_id`) REFERENCES `keeper_users` (`id`) ON DELETE CASCADE;

--
-- Constraints for table `keeper_sessions`
--
ALTER TABLE `keeper_sessions`
  ADD CONSTRAINT `fk_keeper_sessions_device` FOREIGN KEY (`device_id`) REFERENCES `keeper_devices` (`id`) ON DELETE SET NULL,
  ADD CONSTRAINT `fk_keeper_sessions_user` FOREIGN KEY (`user_id`) REFERENCES `keeper_users` (`id`) ON DELETE CASCADE;

--
-- Constraints for table `keeper_window_episode`
--
ALTER TABLE `keeper_window_episode`
  ADD CONSTRAINT `fk_keeper_we_device` FOREIGN KEY (`device_id`) REFERENCES `keeper_devices` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `fk_keeper_we_user` FOREIGN KEY (`user_id`) REFERENCES `keeper_users` (`id`) ON DELETE CASCADE;

--
-- Constraints for table `keeper_work_schedules`
--
ALTER TABLE `keeper_work_schedules`
  ADD CONSTRAINT `keeper_work_schedules_ibfk_1` FOREIGN KEY (`user_id`) REFERENCES `keeper_users` (`id`) ON DELETE CASCADE;
COMMIT;

/*!40101 SET CHARACTER_SET_CLIENT=@OLD_CHARACTER_SET_CLIENT */;
/*!40101 SET CHARACTER_SET_RESULTS=@OLD_CHARACTER_SET_RESULTS */;
/*!40101 SET COLLATION_CONNECTION=@OLD_COLLATION_CONNECTION */;
