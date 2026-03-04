-- phpMyAdmin SQL Dump
-- version 5.2.1
-- https://www.phpmyadmin.net/
--
-- Host: 162.210.103.253:3306
-- Generation Time: Feb 25, 2026 at 04:31 PM
-- Server version: 8.0.40
-- PHP Version: 8.2.29

SET SQL_MODE = "NO_AUTO_VALUE_ON_ZERO";
START TRANSACTION;
SET time_zone = "+00:00";


/*!40101 SET @OLD_CHARACTER_SET_CLIENT=@@CHARACTER_SET_CLIENT */;
/*!40101 SET @OLD_CHARACTER_SET_RESULTS=@@CHARACTER_SET_RESULTS */;
/*!40101 SET @OLD_COLLATION_CONNECTION=@@COLLATION_CONNECTION */;
/*!40101 SET NAMES utf8mb4 */;

--
-- Database: `pipezafra_soporte_db`
--
CREATE DATABASE IF NOT EXISTS `pipezafra_soporte_db` DEFAULT CHARACTER SET utf8mb3 COLLATE utf8mb3_unicode_ci;
USE `pipezafra_soporte_db`;

-- --------------------------------------------------------

--
-- Table structure for table `acciones`
--

DROP TABLE IF EXISTS `acciones`;
CREATE TABLE `acciones` (
  `id` int NOT NULL,
  `nombre` varchar(100) COLLATE utf8mb3_unicode_ci NOT NULL,
  `descripcion` text COLLATE utf8mb3_unicode_ci,
  `recurso_id` int NOT NULL,
  `activa` tinyint(1) DEFAULT '1',
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb3 COLLATE=utf8mb3_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `areas`
--

DROP TABLE IF EXISTS `areas`;
CREATE TABLE `areas` (
  `id` int NOT NULL,
  `nombre` varchar(255) COLLATE utf8mb3_unicode_ci NOT NULL,
  `descripcion` text COLLATE utf8mb3_unicode_ci,
  `padre_id` int DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb3 COLLATE=utf8mb3_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `asignaciones_mesa`
--

DROP TABLE IF EXISTS `asignaciones_mesa`;
CREATE TABLE `asignaciones_mesa` (
  `id` int NOT NULL,
  `mesa_id` int NOT NULL,
  `empleado_id` int NOT NULL,
  `fecha_asignacion` date DEFAULT NULL,
  `fecha_desasignacion` date DEFAULT NULL,
  `activo` tinyint(1) DEFAULT '1',
  `creado_en` timestamp NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb3 COLLATE=utf8mb3_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `cargos`
--

DROP TABLE IF EXISTS `cargos`;
CREATE TABLE `cargos` (
  `id` int NOT NULL,
  `nombre` varchar(100) COLLATE utf8mb3_unicode_ci NOT NULL,
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb3 COLLATE=utf8mb3_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `categorias`
--

DROP TABLE IF EXISTS `categorias`;
CREATE TABLE `categorias` (
  `id` int NOT NULL,
  `nombre` varchar(50) CHARACTER SET utf8mb3 COLLATE utf8mb3_unicode_ci NOT NULL,
  `descripcion` text CHARACTER SET utf8mb3 COLLATE utf8mb3_unicode_ci,
  `creado_en` timestamp NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb3 COLLATE=utf8mb3_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `cumpleanos_likes`
--

DROP TABLE IF EXISTS `cumpleanos_likes`;
CREATE TABLE `cumpleanos_likes` (
  `id` int NOT NULL,
  `empleado_id` int NOT NULL,
  `usuario_id` int NOT NULL,
  `creado_en` timestamp NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb3 COLLATE=utf8mb3_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `elementos_plano`
--

DROP TABLE IF EXISTS `elementos_plano`;
CREATE TABLE `elementos_plano` (
  `id` int NOT NULL,
  `piso_id` int NOT NULL,
  `tipo` enum('oficina','muro') CHARACTER SET utf8mb3 COLLATE utf8mb3_unicode_ci NOT NULL,
  `nombre` varchar(100) CHARACTER SET utf8mb3 COLLATE utf8mb3_unicode_ci DEFAULT NULL,
  `puntos` text CHARACTER SET utf8mb3 COLLATE utf8mb3_unicode_ci NOT NULL COMMENT 'Coordenadas JSON del polígono',
  `color` varchar(7) CHARACTER SET utf8mb3 COLLATE utf8mb3_unicode_ci DEFAULT '#cccccc',
  `bloqueado` tinyint(1) DEFAULT '1',
  `creado_en` timestamp NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb3 COLLATE=utf8mb3_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `employee`
--

DROP TABLE IF EXISTS `employee`;
CREATE TABLE `employee` (
  `id` int NOT NULL,
  `id_firm` int DEFAULT NULL,
  `locacion_id` int DEFAULT NULL,
  `type_CC` int NOT NULL DEFAULT '1',
  `CC` int NOT NULL,
  `first_Name` varchar(100) CHARACTER SET utf8mb3 COLLATE utf8mb3_unicode_ci NOT NULL,
  `second_Name` varchar(100) CHARACTER SET utf8mb3 COLLATE utf8mb3_unicode_ci DEFAULT NULL,
  `first_LastName` varchar(100) CHARACTER SET utf8mb3 COLLATE utf8mb3_unicode_ci NOT NULL,
  `second_LastName` varchar(100) CHARACTER SET utf8mb3 COLLATE utf8mb3_unicode_ci DEFAULT NULL,
  `mail` varchar(100) CHARACTER SET utf8mb3 COLLATE utf8mb3_unicode_ci DEFAULT NULL,
  `personal_mail` varchar(100) CHARACTER SET utf8mb3 COLLATE utf8mb3_unicode_ci DEFAULT NULL,
  `birthdate` date NOT NULL,
  `dval` int DEFAULT NULL COMMENT 'Estado civil',
  `final_status` varchar(50) CHARACTER SET utf8mb3 COLLATE utf8mb3_unicode_ci DEFAULT NULL,
  `phone` varchar(20) CHARACTER SET utf8mb3 COLLATE utf8mb3_unicode_ci DEFAULT NULL,
  `country` varchar(100) CHARACTER SET utf8mb3 COLLATE utf8mb3_unicode_ci DEFAULT 'Colombia',
  `city` varchar(100) CHARACTER SET utf8mb3 COLLATE utf8mb3_unicode_ci DEFAULT NULL,
  `address` varchar(500) COLLATE utf8mb3_unicode_ci DEFAULT NULL,
  `sede_id` int DEFAULT NULL,
  `activo_fijo` varchar(50) CHARACTER SET utf8mb3 COLLATE utf8mb3_unicode_ci DEFAULT NULL,
  `extension` varchar(20) CHARACTER SET utf8mb3 COLLATE utf8mb3_unicode_ci DEFAULT NULL,
  `company` int DEFAULT '1',
  `position` varchar(255) CHARACTER SET utf8mb3 COLLATE utf8mb3_unicode_ci DEFAULT NULL,
  `exit_status` int DEFAULT '0',
  `pin` varchar(4) CHARACTER SET utf8mb3 COLLATE utf8mb3_unicode_ci DEFAULT NULL,
  `password` varchar(255) CHARACTER SET utf8mb3 COLLATE utf8mb3_unicode_ci NOT NULL,
  `needs_phone` tinyint DEFAULT '0',
  `id_phone` int DEFAULT NULL,
  `role` enum('empleado','administrador','nomina','talento_humano','it','retirado','candidato') COLLATE utf8mb3_unicode_ci DEFAULT NULL,
  `supervisor_id` int DEFAULT NULL,
  `area_id` int DEFAULT NULL,
  `photo` varchar(255) COLLATE utf8mb3_unicode_ci DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  `last_status` enum('Online','Away','Offline') CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'Offline',
  `geo` varchar(32) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `position_id` int DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb3 COLLATE=utf8mb3_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `equipos`
--

DROP TABLE IF EXISTS `equipos`;
CREATE TABLE `equipos` (
  `id` int NOT NULL,
  `activo_fijo` varchar(50) CHARACTER SET utf8mb3 COLLATE utf8mb3_unicode_ci NOT NULL,
  `marca` varchar(50) CHARACTER SET utf8mb3 COLLATE utf8mb3_unicode_ci NOT NULL,
  `modelo` varchar(100) CHARACTER SET utf8mb3 COLLATE utf8mb3_unicode_ci NOT NULL,
  `serial_number` varchar(100) CHARACTER SET utf8mb3 COLLATE utf8mb3_unicode_ci NOT NULL,
  `procesador` varchar(100) CHARACTER SET utf8mb3 COLLATE utf8mb3_unicode_ci DEFAULT NULL,
  `ram` varchar(50) CHARACTER SET utf8mb3 COLLATE utf8mb3_unicode_ci DEFAULT NULL,
  `disco_duro` varchar(100) CHARACTER SET utf8mb3 COLLATE utf8mb3_unicode_ci DEFAULT NULL,
  `precio` decimal(10,2) DEFAULT NULL,
  `foto` varchar(255) CHARACTER SET utf8mb3 COLLATE utf8mb3_unicode_ci DEFAULT NULL,
  `usuario_asignado` varchar(50) CHARACTER SET utf8mb3 COLLATE utf8mb3_unicode_ci DEFAULT NULL,
  `estado` enum('activo','mantenimiento','baja') CHARACTER SET utf8mb3 COLLATE utf8mb3_unicode_ci DEFAULT 'activo',
  `creado_en` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  `actualizado_en` timestamp NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  `sede_id` int DEFAULT NULL,
  `en_it` tinyint(1) DEFAULT '0',
  `estado_it` enum('mantenimiento_azc','mantenimiento_computacion','descompuesto') CHARACTER SET utf8mb3 COLLATE utf8mb3_unicode_ci DEFAULT NULL,
  `notas_it` text CHARACTER SET utf8mb3 COLLATE utf8mb3_unicode_ci,
  `it_admin_id` int DEFAULT NULL,
  `it_fecha` timestamp NULL DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb3 COLLATE=utf8mb3_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `files`
--

DROP TABLE IF EXISTS `files`;
CREATE TABLE `files` (
  `id` int NOT NULL,
  `id_employee` int NOT NULL,
  `file_type` enum('documento_identidad','otros','hoja_de_vida','copia_documento_de_identidad','certificado_de_afiliacion_eps','certificado_de_afiliacion_eps_empresa','certificado_de_afiliacion_pension','certificado_de_afiliacion_arl','certificado_de_afiliacion_caja_de_compensacion','tarjeta_profesional','diploma_pregrado','diploma_posgrado','certificados_de_formacion','contrato_laboral','otro_si','autorizacion_tratamiento_de_datos_personales','sarlaft','soporte_de_entrega_rit','soporte_induccion','acta_entrega_equipo_ti','acuerdo_de_confidencialidad','acta_de_autorizacion_de_descuento','certificado_de_ingles','certificado_de_cesantias','contactos_de_emergencias','certificado_bancario','certificado_policia_nacional','certificado_procuraduria','certificado_contraloria','certificado_vigencia','certificado_laboral') CHARACTER SET utf8mb3 COLLATE utf8mb3_unicode_ci NOT NULL,
  `file_name` varchar(255) CHARACTER SET utf8mb3 COLLATE utf8mb3_unicode_ci NOT NULL,
  `file_path` varchar(500) CHARACTER SET utf8mb3 COLLATE utf8mb3_unicode_ci NOT NULL,
  `file_content` longblob NOT NULL,
  `file_hash` varchar(64) CHARACTER SET utf8mb3 COLLATE utf8mb3_unicode_ci NOT NULL,
  `file_size` int NOT NULL,
  `mime_type` varchar(100) CHARACTER SET utf8mb3 COLLATE utf8mb3_unicode_ci NOT NULL,
  `uploaded_by` int DEFAULT NULL,
  `upload_date` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  `description` text CHARACTER SET utf8mb3 COLLATE utf8mb3_unicode_ci,
  `signed` tinyint DEFAULT '0',
  `signed_date` timestamp NULL DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb3 COLLATE=utf8mb3_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `firm`
--

DROP TABLE IF EXISTS `firm`;
CREATE TABLE `firm` (
  `id` int NOT NULL,
  `name` varchar(255) CHARACTER SET utf8mb3 COLLATE utf8mb3_unicode_ci NOT NULL,
  `manager` varchar(255) CHARACTER SET utf8mb3 COLLATE utf8mb3_unicode_ci DEFAULT NULL,
  `mail_manager` varchar(255) CHARACTER SET utf8mb3 COLLATE utf8mb3_unicode_ci DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb3 COLLATE=utf8mb3_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `historial`
--

DROP TABLE IF EXISTS `historial`;
CREATE TABLE `historial` (
  `id` int NOT NULL,
  `ticket_id` int DEFAULT NULL,
  `usuario_id` varchar(50) CHARACTER SET utf8mb3 COLLATE utf8mb3_unicode_ci DEFAULT NULL,
  `admin_id` int DEFAULT NULL,
  `item_id` int DEFAULT NULL,
  `accion` enum('añadir','eliminar','intercambio','solicitud','devolucion','solicitud_aprobada','devolucion_recibida') CHARACTER SET utf8mb3 COLLATE utf8mb3_unicode_ci DEFAULT NULL,
  `cantidad` int DEFAULT NULL,
  `notas` text CHARACTER SET utf8mb3 COLLATE utf8mb3_unicode_ci,
  `creado_en` timestamp NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb3 COLLATE=utf8mb3_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `historial_items`
--

DROP TABLE IF EXISTS `historial_items`;
CREATE TABLE `historial_items` (
  `id` int NOT NULL,
  `item_id` int NOT NULL,
  `admin_id` int DEFAULT NULL COMMENT 'ID del administrador que realizó la acción',
  `accion` varchar(50) COLLATE utf8mb3_unicode_ci NOT NULL COMMENT 'crear, editar_stock, mover, eliminar, etc.',
  `cantidad` int DEFAULT '0' COMMENT 'Cantidad modificada o movida',
  `stock_anterior` int DEFAULT '0' COMMENT 'Stock antes de la operación',
  `stock_nuevo` int DEFAULT '0' COMMENT 'Stock después de la operación',
  `notas` text COLLATE utf8mb3_unicode_ci,
  `sede_id` int DEFAULT NULL COMMENT 'Sede donde ocurrió el evento',
  `referencia_id` int DEFAULT NULL COMMENT 'ID de referencia (ej: movimiento_sedes_id)',
  `creado_en` timestamp NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb3 COLLATE=utf8mb3_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `history`
--

DROP TABLE IF EXISTS `history`;
CREATE TABLE `history` (
  `id` int NOT NULL,
  `id_employee` int NOT NULL,
  `event_type` enum('postulacion','contratacion','ascenso','despido','renuncia','otros') CHARACTER SET utf8mb3 COLLATE utf8mb3_unicode_ci NOT NULL,
  `event_date` date NOT NULL,
  `description` text CHARACTER SET utf8mb3 COLLATE utf8mb3_unicode_ci,
  `contract_file` int DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb3 COLLATE=utf8mb3_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `items`
--

DROP TABLE IF EXISTS `items`;
CREATE TABLE `items` (
  `id` int NOT NULL,
  `nombre` varchar(100) CHARACTER SET utf8mb3 COLLATE utf8mb3_unicode_ci NOT NULL,
  `categoria_id` int DEFAULT NULL,
  `descripcion` text CHARACTER SET utf8mb3 COLLATE utf8mb3_unicode_ci,
  `stock` int DEFAULT '0',
  `stock_minimo` int DEFAULT '0',
  `creado_en` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  `tipo` enum('consumible','equipo') CHARACTER SET utf8mb3 COLLATE utf8mb3_unicode_ci DEFAULT 'consumible',
  `necesita_restock` tinyint NOT NULL DEFAULT '1',
  `sede_id` int NOT NULL DEFAULT '1'
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb3 COLLATE=utf8mb3_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `keeper_activity_day`
--

DROP TABLE IF EXISTS `keeper_activity_day`;
CREATE TABLE `keeper_activity_day` (
  `id` bigint NOT NULL,
  `user_id` bigint NOT NULL,
  `device_id` bigint NOT NULL,
  `day_date` date NOT NULL,
  `tz_offset_minutes` smallint NOT NULL DEFAULT '-300',
  `active_seconds` int NOT NULL DEFAULT '0',
  `work_hours_active_seconds` decimal(12,3) DEFAULT '0.000',
  `work_hours_idle_seconds` decimal(12,3) DEFAULT '0.000',
  `lunch_active_seconds` decimal(12,3) DEFAULT '0.000',
  `lunch_idle_seconds` decimal(12,3) DEFAULT '0.000',
  `after_hours_active_seconds` decimal(12,3) DEFAULT '0.000',
  `after_hours_idle_seconds` decimal(12,3) DEFAULT '0.000',
  `is_workday` tinyint(1) NOT NULL DEFAULT '1' COMMENT '1=día laborable (lun-vie), 0=fin de semana (sáb-dom)',
  `idle_seconds` int NOT NULL DEFAULT '0',
  `call_seconds` int NOT NULL DEFAULT '0',
  `samples_count` int NOT NULL DEFAULT '0',
  `first_event_at` datetime DEFAULT NULL,
  `last_event_at` datetime DEFAULT NULL,
  `payload_json` json DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `keeper_audit_log`
--

DROP TABLE IF EXISTS `keeper_audit_log`;
CREATE TABLE `keeper_audit_log` (
  `id` bigint NOT NULL,
  `user_id` bigint DEFAULT NULL,
  `device_id` bigint DEFAULT NULL,
  `event_type` varchar(100) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci NOT NULL,
  `message` varchar(512) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci DEFAULT NULL,
  `meta_json` json DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `keeper_client_releases`
--

DROP TABLE IF EXISTS `keeper_client_releases`;
CREATE TABLE `keeper_client_releases` (
  `id` int NOT NULL,
  `version` varchar(50) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci NOT NULL COMMENT 'Semantic version (e.g., 3.0.0.1)',
  `download_url` varchar(500) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci NOT NULL COMMENT 'URL to download ZIP package',
  `file_size` bigint DEFAULT '0' COMMENT 'File size in bytes',
  `release_notes` text CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci COMMENT 'Release notes in markdown',
  `is_beta` tinyint(1) DEFAULT '0' COMMENT 'Is this a beta version?',
  `force_update` tinyint(1) DEFAULT '0' COMMENT 'Force clients to update immediately',
  `minimum_version` varchar(50) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci DEFAULT NULL COMMENT 'Minimum required version (older = critical)',
  `is_active` tinyint(1) DEFAULT '1' COMMENT 'Is this release available?',
  `release_date` date DEFAULT NULL COMMENT 'Official release date',
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `keeper_daily_metrics`
--

DROP TABLE IF EXISTS `keeper_daily_metrics`;
CREATE TABLE `keeper_daily_metrics` (
  `id` bigint NOT NULL,
  `user_id` bigint NOT NULL,
  `device_id` bigint NOT NULL,
  `day_date` date NOT NULL,
  `metric_key` varchar(120) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci NOT NULL,
  `metric_value` bigint NOT NULL DEFAULT '0',
  `meta_json` json DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `keeper_devices`
--

DROP TABLE IF EXISTS `keeper_devices`;
CREATE TABLE `keeper_devices` (
  `id` bigint NOT NULL,
  `user_id` bigint NOT NULL,
  `device_guid` char(36) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci NOT NULL,
  `device_name` varchar(190) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci DEFAULT NULL,
  `serial_hint` varchar(190) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci DEFAULT NULL,
  `status` enum('active','revoked') CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci NOT NULL DEFAULT 'active',
  `last_seen_at` timestamp NULL DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `keeper_device_locks`
--

DROP TABLE IF EXISTS `keeper_device_locks`;
CREATE TABLE `keeper_device_locks` (
  `id` bigint NOT NULL,
  `device_id` bigint NOT NULL,
  `user_id` bigint NOT NULL,
  `locked_by_admin_id` bigint DEFAULT NULL,
  `lock_reason` varchar(500) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci DEFAULT NULL,
  `locked_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `unlocked_at` timestamp NULL DEFAULT NULL,
  `unlock_pin_hash` char(64) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci DEFAULT NULL,
  `is_active` tinyint(1) NOT NULL DEFAULT '1'
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `keeper_events`
--

DROP TABLE IF EXISTS `keeper_events`;
CREATE TABLE `keeper_events` (
  `id` bigint NOT NULL,
  `user_id` bigint NOT NULL,
  `device_id` bigint NOT NULL,
  `module_code` varchar(64) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci NOT NULL,
  `event_type` varchar(100) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci NOT NULL,
  `start_at` datetime DEFAULT NULL,
  `end_at` datetime DEFAULT NULL,
  `duration_seconds` int DEFAULT NULL,
  `numeric_1` bigint DEFAULT NULL,
  `numeric_2` bigint DEFAULT NULL,
  `numeric_3` bigint DEFAULT NULL,
  `numeric_4` bigint DEFAULT NULL,
  `text_1` varchar(190) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci DEFAULT NULL,
  `text_2` varchar(190) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci DEFAULT NULL,
  `payload_json` json DEFAULT NULL,
  `day_date` date NOT NULL,
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `keeper_handshake_log`
--

DROP TABLE IF EXISTS `keeper_handshake_log`;
CREATE TABLE `keeper_handshake_log` (
  `id` bigint NOT NULL,
  `user_id` bigint NOT NULL,
  `device_id` bigint NOT NULL,
  `client_version` varchar(50) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci DEFAULT NULL,
  `request_json` json DEFAULT NULL,
  `response_json` json DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `keeper_module_catalog`
--

DROP TABLE IF EXISTS `keeper_module_catalog`;
CREATE TABLE `keeper_module_catalog` (
  `module_code` varchar(64) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci NOT NULL,
  `name` varchar(190) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci NOT NULL,
  `default_enabled` tinyint(1) NOT NULL DEFAULT '0',
  `config_schema_json` json DEFAULT NULL,
  `active` tinyint(1) NOT NULL DEFAULT '1',
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `keeper_policy_assignments`
--

DROP TABLE IF EXISTS `keeper_policy_assignments`;
CREATE TABLE `keeper_policy_assignments` (
  `id` bigint NOT NULL,
  `scope` enum('global','user','device') CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci NOT NULL,
  `user_id` bigint DEFAULT NULL,
  `device_id` bigint DEFAULT NULL,
  `version` int NOT NULL DEFAULT '1',
  `priority` int NOT NULL DEFAULT '100',
  `is_active` tinyint(1) NOT NULL DEFAULT '1',
  `policy_json` json NOT NULL,
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `keeper_sessions`
--

DROP TABLE IF EXISTS `keeper_sessions`;
CREATE TABLE `keeper_sessions` (
  `id` bigint NOT NULL,
  `user_id` bigint NOT NULL,
  `device_id` bigint DEFAULT NULL,
  `token_hash` char(64) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci NOT NULL,
  `refresh_hash` char(64) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci DEFAULT NULL,
  `issued_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `expires_at` timestamp NULL DEFAULT NULL,
  `revoked_at` timestamp NULL DEFAULT NULL,
  `ip` varchar(64) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci DEFAULT NULL,
  `user_agent` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `keeper_users`
--

DROP TABLE IF EXISTS `keeper_users`;
CREATE TABLE `keeper_users` (
  `id` bigint NOT NULL,
  `legacy_employee_id` int NOT NULL,
  `cc` varchar(20) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci DEFAULT NULL,
  `email` varchar(190) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci DEFAULT NULL,
  `display_name` varchar(190) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci DEFAULT NULL,
  `status` enum('active','inactive','locked') CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci NOT NULL DEFAULT 'active',
  `password_hash` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `keeper_window_episode`
--

DROP TABLE IF EXISTS `keeper_window_episode`;
CREATE TABLE `keeper_window_episode` (
  `id` bigint NOT NULL,
  `user_id` bigint NOT NULL,
  `device_id` bigint NOT NULL,
  `start_at` datetime NOT NULL,
  `end_at` datetime NOT NULL,
  `duration_seconds` int NOT NULL,
  `process_name` varchar(190) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `app_name` varchar(190) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `window_title` varchar(512) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `is_in_call` tinyint(1) NOT NULL DEFAULT '0',
  `call_app_hint` varchar(190) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `day_date` date NOT NULL,
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `keeper_work_schedules`
--

DROP TABLE IF EXISTS `keeper_work_schedules`;
CREATE TABLE `keeper_work_schedules` (
  `id` bigint NOT NULL,
  `user_id` bigint DEFAULT NULL,
  `work_start_time` time DEFAULT '07:00:00',
  `work_end_time` time DEFAULT '19:00:00',
  `lunch_start_time` time DEFAULT '12:00:00',
  `lunch_end_time` time DEFAULT '13:00:00',
  `timezone` varchar(50) DEFAULT 'America/Bogota',
  `is_active` tinyint(1) DEFAULT '1',
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

-- --------------------------------------------------------

--
-- Table structure for table `location`
--

DROP TABLE IF EXISTS `location`;
CREATE TABLE `location` (
  `id` int NOT NULL,
  `id_firm` int NOT NULL,
  `name` varchar(255) CHARACTER SET utf8mb3 COLLATE utf8mb3_unicode_ci NOT NULL,
  `address` text CHARACTER SET utf8mb3 COLLATE utf8mb3_unicode_ci,
  `city` varchar(100) CHARACTER SET utf8mb3 COLLATE utf8mb3_unicode_ci DEFAULT NULL,
  `country` varchar(100) CHARACTER SET utf8mb3 COLLATE utf8mb3_unicode_ci DEFAULT 'USA',
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb3 COLLATE=utf8mb3_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `marcas_equipos`
--

DROP TABLE IF EXISTS `marcas_equipos`;
CREATE TABLE `marcas_equipos` (
  `id` int NOT NULL,
  `nombre` varchar(50) CHARACTER SET utf8mb3 COLLATE utf8mb3_unicode_ci NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb3 COLLATE=utf8mb3_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `mesas`
--

DROP TABLE IF EXISTS `mesas`;
CREATE TABLE `mesas` (
  `id` int NOT NULL,
  `piso_id` int NOT NULL,
  `tipo_mesa_id` int NOT NULL,
  `numero` varchar(20) CHARACTER SET utf8mb3 COLLATE utf8mb3_unicode_ci NOT NULL,
  `posicion_x` int DEFAULT '0',
  `posicion_y` int DEFAULT '0',
  `rotacion` int DEFAULT '0',
  `activo` tinyint(1) DEFAULT '1',
  `creado_en` timestamp NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb3 COLLATE=utf8mb3_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `movimientos_sedes`
--

DROP TABLE IF EXISTS `movimientos_sedes`;
CREATE TABLE `movimientos_sedes` (
  `id` int NOT NULL,
  `item_id` int NOT NULL,
  `cantidad` int NOT NULL,
  `sede_origen_id` int NOT NULL,
  `sede_destino_id` int NOT NULL,
  `persona_envia_id` int NOT NULL,
  `persona_recibe_id` int DEFAULT NULL,
  `fecha_envio` datetime NOT NULL,
  `fecha_recibido` datetime DEFAULT NULL,
  `estado` enum('enviado','recibido','cancelado') COLLATE utf8mb3_unicode_ci NOT NULL DEFAULT 'enviado',
  `notas` text COLLATE utf8mb3_unicode_ci,
  `creado_en` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb3 COLLATE=utf8mb3_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `permisos`
--

DROP TABLE IF EXISTS `permisos`;
CREATE TABLE `permisos` (
  `id` int NOT NULL,
  `empleado_id` varchar(50) COLLATE utf8mb3_unicode_ci NOT NULL,
  `empleado_nombre` varchar(100) COLLATE utf8mb3_unicode_ci NOT NULL,
  `empleado_departamento` varchar(100) COLLATE utf8mb3_unicode_ci NOT NULL,
  `supervisor_id` int DEFAULT NULL,
  `tipo_permiso` enum('no_remunerado','remunerado','por_hora','matrimonio','trabajo_casa') COLLATE utf8mb3_unicode_ci NOT NULL,
  `fecha_inicio` date NOT NULL,
  `fecha_fin` date NOT NULL,
  `horas` time DEFAULT NULL,
  `motivo` text COLLATE utf8mb3_unicode_ci NOT NULL,
  `soporte_ruta` varchar(500) COLLATE utf8mb3_unicode_ci DEFAULT NULL,
  `estado_supervisor` enum('pendiente','aprobado','rechazado') COLLATE utf8mb3_unicode_ci DEFAULT 'pendiente',
  `estado_talento_humano` enum('pendiente','aprobado','rechazado') COLLATE utf8mb3_unicode_ci DEFAULT 'pendiente',
  `notas_supervisor` text COLLATE utf8mb3_unicode_ci,
  `notas_talento_humano` text COLLATE utf8mb3_unicode_ci,
  `responsable_id` int DEFAULT NULL,
  `creado_en` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  `actualizado_en` timestamp NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb3 COLLATE=utf8mb3_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `permisos_cargos`
--

DROP TABLE IF EXISTS `permisos_cargos`;
CREATE TABLE `permisos_cargos` (
  `id` int NOT NULL,
  `cargo_id` int NOT NULL,
  `recurso_id` int NOT NULL,
  `accion_id` int NOT NULL,
  `permitido` tinyint(1) DEFAULT '1',
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb3 COLLATE=utf8mb3_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `permisos_roles`
--

DROP TABLE IF EXISTS `permisos_roles`;
CREATE TABLE `permisos_roles` (
  `id` int NOT NULL,
  `rol` varchar(50) COLLATE utf8mb3_unicode_ci NOT NULL,
  `recurso_id` int NOT NULL,
  `accion_id` int NOT NULL,
  `permitido` tinyint(1) DEFAULT '1',
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb3 COLLATE=utf8mb3_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `permisos_usuarios`
--

DROP TABLE IF EXISTS `permisos_usuarios`;
CREATE TABLE `permisos_usuarios` (
  `id` int NOT NULL,
  `usuario_id` int NOT NULL,
  `recurso_id` int NOT NULL,
  `accion_id` int NOT NULL,
  `permitido` tinyint(1) DEFAULT '1',
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb3 COLLATE=utf8mb3_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `phones`
--

DROP TABLE IF EXISTS `phones`;
CREATE TABLE `phones` (
  `id` int NOT NULL,
  `id_firm` int NOT NULL,
  `phone` varchar(20) CHARACTER SET utf8mb3 COLLATE utf8mb3_unicode_ci NOT NULL,
  `purchase_date` date DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb3 COLLATE=utf8mb3_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `pisos_sede`
--

DROP TABLE IF EXISTS `pisos_sede`;
CREATE TABLE `pisos_sede` (
  `id` int NOT NULL,
  `sede_id` int NOT NULL,
  `nombre` varchar(100) CHARACTER SET utf8mb3 COLLATE utf8mb3_unicode_ci NOT NULL,
  `numero` int NOT NULL,
  `oficina` varchar(50) CHARACTER SET utf8mb3 COLLATE utf8mb3_unicode_ci DEFAULT NULL,
  `activo` tinyint(1) DEFAULT '1',
  `creado_en` timestamp NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb3 COLLATE=utf8mb3_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `publicaciones`
--

DROP TABLE IF EXISTS `publicaciones`;
CREATE TABLE `publicaciones` (
  `id` int NOT NULL,
  `usuario_id` int DEFAULT NULL,
  `contenido` text COLLATE utf8mb3_unicode_ci,
  `imagen` varchar(255) COLLATE utf8mb3_unicode_ci DEFAULT NULL,
  `tipo` enum('texto','imagen','mixto','cumpleanos') COLLATE utf8mb3_unicode_ci NOT NULL DEFAULT 'texto',
  `activo` tinyint DEFAULT '1',
  `creado_en` timestamp NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb3 COLLATE=utf8mb3_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `publicaciones_likes`
--

DROP TABLE IF EXISTS `publicaciones_likes`;
CREATE TABLE `publicaciones_likes` (
  `id` int NOT NULL,
  `publicacion_id` int NOT NULL,
  `usuario_id` int NOT NULL,
  `creado_en` timestamp NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb3 COLLATE=utf8mb3_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `recursos`
--

DROP TABLE IF EXISTS `recursos`;
CREATE TABLE `recursos` (
  `id` int NOT NULL,
  `nombre` varchar(100) COLLATE utf8mb3_unicode_ci NOT NULL,
  `descripcion` text COLLATE utf8mb3_unicode_ci,
  `ruta` varchar(255) COLLATE utf8mb3_unicode_ci NOT NULL,
  `icono` varchar(50) COLLATE utf8mb3_unicode_ci DEFAULT NULL,
  `categoria` varchar(50) COLLATE utf8mb3_unicode_ci DEFAULT 'general',
  `activo` tinyint(1) DEFAULT '1',
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb3 COLLATE=utf8mb3_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `sedes`
--

DROP TABLE IF EXISTS `sedes`;
CREATE TABLE `sedes` (
  `id` int NOT NULL,
  `nombre` varchar(100) CHARACTER SET utf8mb3 COLLATE utf8mb3_unicode_ci NOT NULL,
  `codigo` varchar(50) CHARACTER SET utf8mb3 COLLATE utf8mb3_unicode_ci NOT NULL,
  `descripcion` text CHARACTER SET utf8mb3 COLLATE utf8mb3_unicode_ci,
  `activa` tinyint(1) DEFAULT '1',
  `creado_en` timestamp NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb3 COLLATE=utf8mb3_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `supervisores`
--

DROP TABLE IF EXISTS `supervisores`;
CREATE TABLE `supervisores` (
  `id` int NOT NULL,
  `empleado_id` int NOT NULL,
  `tipo_supervisor` enum('coordinador','director','gerente') COLLATE utf8mb3_unicode_ci NOT NULL,
  `area_id` int NOT NULL,
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb3 COLLATE=utf8mb3_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `tickets`
--

DROP TABLE IF EXISTS `tickets`;
CREATE TABLE `tickets` (
  `id` int NOT NULL,
  `empleado_id` varchar(50) CHARACTER SET utf8mb3 COLLATE utf8mb3_unicode_ci NOT NULL,
  `empleado_nombre` varchar(100) CHARACTER SET utf8mb3 COLLATE utf8mb3_unicode_ci DEFAULT NULL,
  `empleado_departamento` varchar(100) CHARACTER SET utf8mb3 COLLATE utf8mb3_unicode_ci DEFAULT NULL,
  `item_id` int DEFAULT NULL,
  `item_devuelto_id` int DEFAULT NULL,
  `asunto` varchar(255) CHARACTER SET utf8mb3 COLLATE utf8mb3_unicode_ci DEFAULT NULL,
  `equipo_id` int DEFAULT NULL,
  `cantidad` int DEFAULT '1',
  `tipo` enum('solicitud','devolucion','intercambio','email','ticket','onboarding','prestamo') COLLATE utf8mb3_unicode_ci NOT NULL,
  `notas` text CHARACTER SET utf8mb3 COLLATE utf8mb3_unicode_ci,
  `mal_estado` tinyint(1) DEFAULT '0',
  `notas_admin` text CHARACTER SET utf8mb3 COLLATE utf8mb3_unicode_ci,
  `imagen_ruta` varchar(500) COLLATE utf8mb3_unicode_ci DEFAULT NULL,
  `estado` enum('pendiente','aprobado','rechazado','completado') CHARACTER SET utf8mb3 COLLATE utf8mb3_unicode_ci DEFAULT 'pendiente',
  `responsable_id` int DEFAULT NULL,
  `creado_en` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  `actualizado_en` timestamp NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb3 COLLATE=utf8mb3_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `ticket_calificaciones`
--

DROP TABLE IF EXISTS `ticket_calificaciones`;
CREATE TABLE `ticket_calificaciones` (
  `id` int NOT NULL,
  `ticket_id` int NOT NULL,
  `calificacion` int NOT NULL,
  `comentario` text CHARACTER SET utf8mb3 COLLATE utf8mb3_unicode_ci,
  `fecha_calificacion` timestamp NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb3 COLLATE=utf8mb3_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `time_logs`
--

DROP TABLE IF EXISTS `time_logs`;
CREATE TABLE `time_logs` (
  `log_id` int NOT NULL,
  `employee_id` int NOT NULL,
  `log_type` enum('Entrada','Salida') COLLATE utf8mb4_general_ci NOT NULL,
  `log_time` datetime NOT NULL,
  `photo_url` varchar(512) COLLATE utf8mb4_general_ci DEFAULT NULL,
  `location` varchar(255) COLLATE utf8mb4_general_ci DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `tipos_mesa`
--

DROP TABLE IF EXISTS `tipos_mesa`;
CREATE TABLE `tipos_mesa` (
  `id` int NOT NULL,
  `nombre` varchar(50) CHARACTER SET utf8mb3 COLLATE utf8mb3_unicode_ci NOT NULL,
  `descripcion` text CHARACTER SET utf8mb3 COLLATE utf8mb3_unicode_ci,
  `color` varchar(7) CHARACTER SET utf8mb3 COLLATE utf8mb3_unicode_ci DEFAULT '#003a5d',
  `ancho` int DEFAULT '80',
  `alto` int DEFAULT '60',
  `activo` tinyint(1) DEFAULT '1'
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb3 COLLATE=utf8mb3_unicode_ci;

--
-- Indexes for dumped tables
--

--
-- Indexes for table `acciones`
--
ALTER TABLE `acciones`
  ADD PRIMARY KEY (`id`),
  ADD KEY `recurso_id` (`recurso_id`);

--
-- Indexes for table `areas`
--
ALTER TABLE `areas`
  ADD PRIMARY KEY (`id`),
  ADD KEY `padre_id` (`padre_id`);

--
-- Indexes for table `asignaciones_mesa`
--
ALTER TABLE `asignaciones_mesa`
  ADD PRIMARY KEY (`id`),
  ADD KEY `fk_asignaciones_mesa` (`mesa_id`),
  ADD KEY `fk_asignaciones_empleado` (`empleado_id`);

--
-- Indexes for table `cargos`
--
ALTER TABLE `cargos`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `nombre` (`nombre`);

--
-- Indexes for table `categorias`
--
ALTER TABLE `categorias`
  ADD PRIMARY KEY (`id`);

--
-- Indexes for table `cumpleanos_likes`
--
ALTER TABLE `cumpleanos_likes`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `unique_cumpleanos_like` (`empleado_id`,`usuario_id`),
  ADD KEY `usuario_id` (`usuario_id`);

--
-- Indexes for table `elementos_plano`
--
ALTER TABLE `elementos_plano`
  ADD PRIMARY KEY (`id`),
  ADD KEY `fk_elementos_piso` (`piso_id`);

--
-- Indexes for table `employee`
--
ALTER TABLE `employee`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `CC` (`CC`),
  ADD UNIQUE KEY `activo_fijo` (`activo_fijo`),
  ADD KEY `id_firm` (`id_firm`),
  ADD KEY `id_phone` (`id_phone`),
  ADD KEY `fk_employee_sede_id` (`sede_id`),
  ADD KEY `locacion_id` (`locacion_id`),
  ADD KEY `supervisor_id` (`supervisor_id`),
  ADD KEY `area_id` (`area_id`),
  ADD KEY `position_id` (`position_id`);

--
-- Indexes for table `equipos`
--
ALTER TABLE `equipos`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `activo_fijo` (`activo_fijo`),
  ADD UNIQUE KEY `serial_number` (`serial_number`),
  ADD KEY `it_admin_id` (`it_admin_id`),
  ADD KEY `fk_equipos_sede_id` (`sede_id`);

--
-- Indexes for table `files`
--
ALTER TABLE `files`
  ADD PRIMARY KEY (`id`),
  ADD KEY `id_employee` (`id_employee`),
  ADD KEY `idx_file_hash` (`file_hash`);

--
-- Indexes for table `firm`
--
ALTER TABLE `firm`
  ADD PRIMARY KEY (`id`);

--
-- Indexes for table `historial`
--
ALTER TABLE `historial`
  ADD PRIMARY KEY (`id`),
  ADD KEY `ticket_id` (`ticket_id`),
  ADD KEY `item_id` (`item_id`),
  ADD KEY `admin_id` (`admin_id`);

--
-- Indexes for table `historial_items`
--
ALTER TABLE `historial_items`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_item_id` (`item_id`),
  ADD KEY `idx_admin_id` (`admin_id`),
  ADD KEY `idx_accion` (`accion`),
  ADD KEY `idx_sede_id` (`sede_id`),
  ADD KEY `idx_creado_en` (`creado_en`),
  ADD KEY `idx_item_creado` (`item_id`,`creado_en`);

--
-- Indexes for table `history`
--
ALTER TABLE `history`
  ADD PRIMARY KEY (`id`),
  ADD KEY `id_employee` (`id_employee`),
  ADD KEY `contract_file` (`contract_file`);

--
-- Indexes for table `items`
--
ALTER TABLE `items`
  ADD PRIMARY KEY (`id`),
  ADD KEY `categoria_id` (`categoria_id`),
  ADD KEY `fk_items_sede` (`sede_id`);

--
-- Indexes for table `keeper_activity_day`
--
ALTER TABLE `keeper_activity_day`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `uk_keeper_activity_unique` (`user_id`,`device_id`,`day_date`),
  ADD UNIQUE KEY `uq_activity_day` (`user_id`,`device_id`,`day_date`),
  ADD KEY `ix_keeper_activity_day` (`day_date`),
  ADD KEY `ix_keeper_activity_user_day` (`user_id`,`day_date`),
  ADD KEY `fk_keeper_activity_device` (`device_id`),
  ADD KEY `idx_is_workday` (`is_workday`),
  ADD KEY `idx_activity_device_day` (`device_id`,`day_date`);

--
-- Indexes for table `keeper_audit_log`
--
ALTER TABLE `keeper_audit_log`
  ADD PRIMARY KEY (`id`),
  ADD KEY `ix_keeper_audit_user` (`user_id`,`created_at`),
  ADD KEY `ix_keeper_audit_device` (`device_id`,`created_at`),
  ADD KEY `ix_keeper_audit_type` (`event_type`,`created_at`);

--
-- Indexes for table `keeper_client_releases`
--
ALTER TABLE `keeper_client_releases`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `idx_version` (`version`),
  ADD KEY `idx_is_active` (`is_active`),
  ADD KEY `idx_is_beta` (`is_beta`);

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
-- Indexes for table `keeper_devices`
--
ALTER TABLE `keeper_devices`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `uk_keeper_devices_guid` (`device_guid`),
  ADD KEY `ix_keeper_devices_user` (`user_id`),
  ADD KEY `ix_keeper_devices_last_seen` (`last_seen_at`),
  ADD KEY `idx_devices_guid` (`device_guid`);

--
-- Indexes for table `keeper_device_locks`
--
ALTER TABLE `keeper_device_locks`
  ADD PRIMARY KEY (`id`),
  ADD KEY `ix_device_locks_device` (`device_id`,`is_active`),
  ADD KEY `ix_device_locks_user` (`user_id`,`is_active`);

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
-- Indexes for table `keeper_policy_assignments`
--
ALTER TABLE `keeper_policy_assignments`
  ADD PRIMARY KEY (`id`),
  ADD KEY `ix_keeper_policy_scope_active` (`scope`,`is_active`,`priority`),
  ADD KEY `ix_keeper_policy_user_active` (`user_id`,`is_active`,`priority`),
  ADD KEY `ix_keeper_policy_device_active` (`device_id`,`is_active`,`priority`),
  ADD KEY `idx_policy_global` (`scope`,`is_active`),
  ADD KEY `idx_policy_user` (`scope`,`user_id`,`is_active`),
  ADD KEY `idx_policy_device` (`scope`,`device_id`,`is_active`);

--
-- Indexes for table `keeper_sessions`
--
ALTER TABLE `keeper_sessions`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `uk_keeper_sessions_tokenhash` (`token_hash`),
  ADD KEY `ix_keeper_sessions_user` (`user_id`),
  ADD KEY `ix_keeper_sessions_device` (`device_id`),
  ADD KEY `ix_keeper_sessions_expires` (`expires_at`),
  ADD KEY `idx_sessions_token_expires` (`token_hash`,`expires_at`);

--
-- Indexes for table `keeper_users`
--
ALTER TABLE `keeper_users`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `uk_keeper_users_legacy` (`legacy_employee_id`),
  ADD UNIQUE KEY `uk_keeper_users_cc` (`cc`),
  ADD KEY `ix_keeper_users_email` (`email`);

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
-- Indexes for table `location`
--
ALTER TABLE `location`
  ADD PRIMARY KEY (`id`),
  ADD KEY `id_firm` (`id_firm`);

--
-- Indexes for table `marcas_equipos`
--
ALTER TABLE `marcas_equipos`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `nombre` (`nombre`);

--
-- Indexes for table `mesas`
--
ALTER TABLE `mesas`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `numero_piso` (`numero`,`piso_id`),
  ADD KEY `fk_mesas_piso` (`piso_id`),
  ADD KEY `fk_mesas_tipo` (`tipo_mesa_id`);

--
-- Indexes for table `movimientos_sedes`
--
ALTER TABLE `movimientos_sedes`
  ADD PRIMARY KEY (`id`),
  ADD KEY `item_id` (`item_id`),
  ADD KEY `sede_origen_id` (`sede_origen_id`),
  ADD KEY `sede_destino_id` (`sede_destino_id`),
  ADD KEY `persona_envia_id` (`persona_envia_id`),
  ADD KEY `persona_recibe_id` (`persona_recibe_id`);

--
-- Indexes for table `permisos`
--
ALTER TABLE `permisos`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_empleado_id` (`empleado_id`),
  ADD KEY `idx_supervisor_id` (`supervisor_id`),
  ADD KEY `idx_estado_supervisor` (`estado_supervisor`),
  ADD KEY `idx_estado_th` (`estado_talento_humano`),
  ADD KEY `idx_fechas` (`fecha_inicio`,`fecha_fin`);

--
-- Indexes for table `permisos_cargos`
--
ALTER TABLE `permisos_cargos`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `unique_permiso_cargo` (`cargo_id`,`recurso_id`,`accion_id`),
  ADD KEY `recurso_id` (`recurso_id`),
  ADD KEY `accion_id` (`accion_id`);

--
-- Indexes for table `permisos_roles`
--
ALTER TABLE `permisos_roles`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `unique_permiso` (`rol`,`recurso_id`,`accion_id`),
  ADD KEY `recurso_id` (`recurso_id`),
  ADD KEY `accion_id` (`accion_id`);

--
-- Indexes for table `permisos_usuarios`
--
ALTER TABLE `permisos_usuarios`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `unique_permiso_usuario` (`usuario_id`,`recurso_id`,`accion_id`),
  ADD KEY `recurso_id` (`recurso_id`),
  ADD KEY `accion_id` (`accion_id`);

--
-- Indexes for table `phones`
--
ALTER TABLE `phones`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `phone` (`phone`),
  ADD KEY `id_firm` (`id_firm`);

--
-- Indexes for table `pisos_sede`
--
ALTER TABLE `pisos_sede`
  ADD PRIMARY KEY (`id`),
  ADD KEY `fk_pisos_sede` (`sede_id`);

--
-- Indexes for table `publicaciones`
--
ALTER TABLE `publicaciones`
  ADD PRIMARY KEY (`id`),
  ADD KEY `usuario_id` (`usuario_id`);

--
-- Indexes for table `publicaciones_likes`
--
ALTER TABLE `publicaciones_likes`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `unique_like` (`publicacion_id`,`usuario_id`),
  ADD KEY `usuario_id` (`usuario_id`);

--
-- Indexes for table `recursos`
--
ALTER TABLE `recursos`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `nombre` (`nombre`);

--
-- Indexes for table `sedes`
--
ALTER TABLE `sedes`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `codigo` (`codigo`);

--
-- Indexes for table `supervisores`
--
ALTER TABLE `supervisores`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `unique_supervisor_area` (`area_id`,`tipo_supervisor`),
  ADD KEY `empleado_id` (`empleado_id`);

--
-- Indexes for table `tickets`
--
ALTER TABLE `tickets`
  ADD PRIMARY KEY (`id`),
  ADD KEY `item_id` (`item_id`),
  ADD KEY `equipo_id` (`equipo_id`),
  ADD KEY `responsable_id` (`responsable_id`),
  ADD KEY `item_devuelto_id` (`item_devuelto_id`);

--
-- Indexes for table `ticket_calificaciones`
--
ALTER TABLE `ticket_calificaciones`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_ticket_calificaciones_ticket_id` (`ticket_id`);

--
-- Indexes for table `time_logs`
--
ALTER TABLE `time_logs`
  ADD PRIMARY KEY (`log_id`),
  ADD KEY `fk_time_logs_employee` (`employee_id`);

--
-- Indexes for table `tipos_mesa`
--
ALTER TABLE `tipos_mesa`
  ADD PRIMARY KEY (`id`);

--
-- AUTO_INCREMENT for dumped tables
--

--
-- AUTO_INCREMENT for table `acciones`
--
ALTER TABLE `acciones`
  MODIFY `id` int NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `areas`
--
ALTER TABLE `areas`
  MODIFY `id` int NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `asignaciones_mesa`
--
ALTER TABLE `asignaciones_mesa`
  MODIFY `id` int NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `cargos`
--
ALTER TABLE `cargos`
  MODIFY `id` int NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `categorias`
--
ALTER TABLE `categorias`
  MODIFY `id` int NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `cumpleanos_likes`
--
ALTER TABLE `cumpleanos_likes`
  MODIFY `id` int NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `elementos_plano`
--
ALTER TABLE `elementos_plano`
  MODIFY `id` int NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `employee`
--
ALTER TABLE `employee`
  MODIFY `id` int NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `equipos`
--
ALTER TABLE `equipos`
  MODIFY `id` int NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `files`
--
ALTER TABLE `files`
  MODIFY `id` int NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `firm`
--
ALTER TABLE `firm`
  MODIFY `id` int NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `historial`
--
ALTER TABLE `historial`
  MODIFY `id` int NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `historial_items`
--
ALTER TABLE `historial_items`
  MODIFY `id` int NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `history`
--
ALTER TABLE `history`
  MODIFY `id` int NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `items`
--
ALTER TABLE `items`
  MODIFY `id` int NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `keeper_activity_day`
--
ALTER TABLE `keeper_activity_day`
  MODIFY `id` bigint NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `keeper_audit_log`
--
ALTER TABLE `keeper_audit_log`
  MODIFY `id` bigint NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `keeper_client_releases`
--
ALTER TABLE `keeper_client_releases`
  MODIFY `id` int NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `keeper_daily_metrics`
--
ALTER TABLE `keeper_daily_metrics`
  MODIFY `id` bigint NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `keeper_devices`
--
ALTER TABLE `keeper_devices`
  MODIFY `id` bigint NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `keeper_device_locks`
--
ALTER TABLE `keeper_device_locks`
  MODIFY `id` bigint NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `keeper_events`
--
ALTER TABLE `keeper_events`
  MODIFY `id` bigint NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `keeper_handshake_log`
--
ALTER TABLE `keeper_handshake_log`
  MODIFY `id` bigint NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `keeper_policy_assignments`
--
ALTER TABLE `keeper_policy_assignments`
  MODIFY `id` bigint NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `keeper_sessions`
--
ALTER TABLE `keeper_sessions`
  MODIFY `id` bigint NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `keeper_users`
--
ALTER TABLE `keeper_users`
  MODIFY `id` bigint NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `keeper_window_episode`
--
ALTER TABLE `keeper_window_episode`
  MODIFY `id` bigint NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `keeper_work_schedules`
--
ALTER TABLE `keeper_work_schedules`
  MODIFY `id` bigint NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `location`
--
ALTER TABLE `location`
  MODIFY `id` int NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `marcas_equipos`
--
ALTER TABLE `marcas_equipos`
  MODIFY `id` int NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `mesas`
--
ALTER TABLE `mesas`
  MODIFY `id` int NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `movimientos_sedes`
--
ALTER TABLE `movimientos_sedes`
  MODIFY `id` int NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `permisos`
--
ALTER TABLE `permisos`
  MODIFY `id` int NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `permisos_cargos`
--
ALTER TABLE `permisos_cargos`
  MODIFY `id` int NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `permisos_roles`
--
ALTER TABLE `permisos_roles`
  MODIFY `id` int NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `permisos_usuarios`
--
ALTER TABLE `permisos_usuarios`
  MODIFY `id` int NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `phones`
--
ALTER TABLE `phones`
  MODIFY `id` int NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `pisos_sede`
--
ALTER TABLE `pisos_sede`
  MODIFY `id` int NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `publicaciones`
--
ALTER TABLE `publicaciones`
  MODIFY `id` int NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `publicaciones_likes`
--
ALTER TABLE `publicaciones_likes`
  MODIFY `id` int NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `recursos`
--
ALTER TABLE `recursos`
  MODIFY `id` int NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `sedes`
--
ALTER TABLE `sedes`
  MODIFY `id` int NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `supervisores`
--
ALTER TABLE `supervisores`
  MODIFY `id` int NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `tickets`
--
ALTER TABLE `tickets`
  MODIFY `id` int NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `ticket_calificaciones`
--
ALTER TABLE `ticket_calificaciones`
  MODIFY `id` int NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `time_logs`
--
ALTER TABLE `time_logs`
  MODIFY `log_id` int NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `tipos_mesa`
--
ALTER TABLE `tipos_mesa`
  MODIFY `id` int NOT NULL AUTO_INCREMENT;

--
-- Constraints for dumped tables
--

--
-- Constraints for table `acciones`
--
ALTER TABLE `acciones`
  ADD CONSTRAINT `acciones_ibfk_1` FOREIGN KEY (`recurso_id`) REFERENCES `recursos` (`id`) ON DELETE CASCADE;

--
-- Constraints for table `areas`
--
ALTER TABLE `areas`
  ADD CONSTRAINT `areas_ibfk_1` FOREIGN KEY (`padre_id`) REFERENCES `areas` (`id`) ON DELETE SET NULL;

--
-- Constraints for table `asignaciones_mesa`
--
ALTER TABLE `asignaciones_mesa`
  ADD CONSTRAINT `fk_asignaciones_empleado` FOREIGN KEY (`empleado_id`) REFERENCES `employee` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `fk_asignaciones_mesa` FOREIGN KEY (`mesa_id`) REFERENCES `mesas` (`id`) ON DELETE CASCADE;

--
-- Constraints for table `cumpleanos_likes`
--
ALTER TABLE `cumpleanos_likes`
  ADD CONSTRAINT `cumpleanos_likes_ibfk_1` FOREIGN KEY (`empleado_id`) REFERENCES `employee` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `cumpleanos_likes_ibfk_2` FOREIGN KEY (`usuario_id`) REFERENCES `employee` (`id`) ON DELETE CASCADE;

--
-- Constraints for table `elementos_plano`
--
ALTER TABLE `elementos_plano`
  ADD CONSTRAINT `fk_elementos_piso` FOREIGN KEY (`piso_id`) REFERENCES `pisos_sede` (`id`) ON DELETE CASCADE;

--
-- Constraints for table `employee`
--
ALTER TABLE `employee`
  ADD CONSTRAINT `employee_ibfk_2` FOREIGN KEY (`id_phone`) REFERENCES `phones` (`id`) ON DELETE SET NULL,
  ADD CONSTRAINT `employee_ibfk_3` FOREIGN KEY (`locacion_id`) REFERENCES `location` (`id`),
  ADD CONSTRAINT `employee_ibfk_4` FOREIGN KEY (`supervisor_id`) REFERENCES `employee` (`id`) ON DELETE SET NULL,
  ADD CONSTRAINT `employee_ibfk_5` FOREIGN KEY (`area_id`) REFERENCES `areas` (`id`) ON DELETE SET NULL,
  ADD CONSTRAINT `employee_ibfk_6` FOREIGN KEY (`position_id`) REFERENCES `cargos` (`id`),
  ADD CONSTRAINT `fk_employee_sede_id` FOREIGN KEY (`sede_id`) REFERENCES `sedes` (`id`) ON DELETE SET NULL;

--
-- Constraints for table `equipos`
--
ALTER TABLE `equipos`
  ADD CONSTRAINT `fk_equipos_sede_id` FOREIGN KEY (`sede_id`) REFERENCES `sedes` (`id`) ON DELETE SET NULL;

--
-- Constraints for table `files`
--
ALTER TABLE `files`
  ADD CONSTRAINT `files_ibfk_1` FOREIGN KEY (`id_employee`) REFERENCES `employee` (`id`) ON DELETE CASCADE;

--
-- Constraints for table `historial`
--
ALTER TABLE `historial`
  ADD CONSTRAINT `historial_ibfk_1` FOREIGN KEY (`ticket_id`) REFERENCES `tickets` (`id`) ON DELETE SET NULL,
  ADD CONSTRAINT `historial_ibfk_2` FOREIGN KEY (`item_id`) REFERENCES `items` (`id`) ON DELETE SET NULL;

--
-- Constraints for table `historial_items`
--
ALTER TABLE `historial_items`
  ADD CONSTRAINT `historial_items_ibfk_1` FOREIGN KEY (`item_id`) REFERENCES `items` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `historial_items_ibfk_2` FOREIGN KEY (`admin_id`) REFERENCES `employee` (`id`) ON DELETE SET NULL,
  ADD CONSTRAINT `historial_items_ibfk_3` FOREIGN KEY (`sede_id`) REFERENCES `sedes` (`id`) ON DELETE SET NULL;

--
-- Constraints for table `history`
--
ALTER TABLE `history`
  ADD CONSTRAINT `history_ibfk_1` FOREIGN KEY (`id_employee`) REFERENCES `employee` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `history_ibfk_2` FOREIGN KEY (`contract_file`) REFERENCES `files` (`id`) ON DELETE SET NULL;

--
-- Constraints for table `items`
--
ALTER TABLE `items`
  ADD CONSTRAINT `fk_items_sede` FOREIGN KEY (`sede_id`) REFERENCES `sedes` (`id`),
  ADD CONSTRAINT `items_ibfk_1` FOREIGN KEY (`categoria_id`) REFERENCES `categorias` (`id`) ON DELETE SET NULL;

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

--
-- Constraints for table `location`
--
ALTER TABLE `location`
  ADD CONSTRAINT `location_ibfk_1` FOREIGN KEY (`id_firm`) REFERENCES `firm` (`id`) ON DELETE CASCADE;

--
-- Constraints for table `mesas`
--
ALTER TABLE `mesas`
  ADD CONSTRAINT `fk_mesas_piso` FOREIGN KEY (`piso_id`) REFERENCES `pisos_sede` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `fk_mesas_tipo` FOREIGN KEY (`tipo_mesa_id`) REFERENCES `tipos_mesa` (`id`);

--
-- Constraints for table `movimientos_sedes`
--
ALTER TABLE `movimientos_sedes`
  ADD CONSTRAINT `movimientos_sedes_ibfk_1` FOREIGN KEY (`item_id`) REFERENCES `items` (`id`),
  ADD CONSTRAINT `movimientos_sedes_ibfk_2` FOREIGN KEY (`sede_origen_id`) REFERENCES `sedes` (`id`),
  ADD CONSTRAINT `movimientos_sedes_ibfk_3` FOREIGN KEY (`sede_destino_id`) REFERENCES `sedes` (`id`),
  ADD CONSTRAINT `movimientos_sedes_ibfk_4` FOREIGN KEY (`persona_envia_id`) REFERENCES `employee` (`id`),
  ADD CONSTRAINT `movimientos_sedes_ibfk_5` FOREIGN KEY (`persona_recibe_id`) REFERENCES `employee` (`id`);

--
-- Constraints for table `permisos_cargos`
--
ALTER TABLE `permisos_cargos`
  ADD CONSTRAINT `permisos_cargos_ibfk_1` FOREIGN KEY (`cargo_id`) REFERENCES `cargos` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `permisos_cargos_ibfk_2` FOREIGN KEY (`recurso_id`) REFERENCES `recursos` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `permisos_cargos_ibfk_3` FOREIGN KEY (`accion_id`) REFERENCES `acciones` (`id`) ON DELETE CASCADE;

--
-- Constraints for table `permisos_roles`
--
ALTER TABLE `permisos_roles`
  ADD CONSTRAINT `permisos_roles_ibfk_1` FOREIGN KEY (`recurso_id`) REFERENCES `recursos` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `permisos_roles_ibfk_2` FOREIGN KEY (`accion_id`) REFERENCES `acciones` (`id`) ON DELETE CASCADE;

--
-- Constraints for table `permisos_usuarios`
--
ALTER TABLE `permisos_usuarios`
  ADD CONSTRAINT `permisos_usuarios_ibfk_1` FOREIGN KEY (`usuario_id`) REFERENCES `employee` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `permisos_usuarios_ibfk_2` FOREIGN KEY (`recurso_id`) REFERENCES `recursos` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `permisos_usuarios_ibfk_3` FOREIGN KEY (`accion_id`) REFERENCES `acciones` (`id`) ON DELETE CASCADE;

--
-- Constraints for table `phones`
--
ALTER TABLE `phones`
  ADD CONSTRAINT `phones_ibfk_1` FOREIGN KEY (`id_firm`) REFERENCES `firm` (`id`) ON DELETE CASCADE;

--
-- Constraints for table `pisos_sede`
--
ALTER TABLE `pisos_sede`
  ADD CONSTRAINT `fk_pisos_sede` FOREIGN KEY (`sede_id`) REFERENCES `sedes` (`id`) ON DELETE CASCADE;

--
-- Constraints for table `publicaciones`
--
ALTER TABLE `publicaciones`
  ADD CONSTRAINT `publicaciones_ibfk_1` FOREIGN KEY (`usuario_id`) REFERENCES `employee` (`id`) ON DELETE CASCADE;

--
-- Constraints for table `publicaciones_likes`
--
ALTER TABLE `publicaciones_likes`
  ADD CONSTRAINT `publicaciones_likes_ibfk_1` FOREIGN KEY (`publicacion_id`) REFERENCES `publicaciones` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `publicaciones_likes_ibfk_2` FOREIGN KEY (`usuario_id`) REFERENCES `employee` (`id`) ON DELETE CASCADE;

--
-- Constraints for table `supervisores`
--
ALTER TABLE `supervisores`
  ADD CONSTRAINT `supervisores_ibfk_1` FOREIGN KEY (`empleado_id`) REFERENCES `employee` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `supervisores_ibfk_2` FOREIGN KEY (`area_id`) REFERENCES `areas` (`id`) ON DELETE CASCADE;

--
-- Constraints for table `tickets`
--
ALTER TABLE `tickets`
  ADD CONSTRAINT `tickets_ibfk_1` FOREIGN KEY (`item_id`) REFERENCES `items` (`id`) ON DELETE SET NULL,
  ADD CONSTRAINT `tickets_ibfk_2` FOREIGN KEY (`equipo_id`) REFERENCES `equipos` (`id`) ON DELETE SET NULL,
  ADD CONSTRAINT `tickets_ibfk_3` FOREIGN KEY (`item_devuelto_id`) REFERENCES `items` (`id`);

--
-- Constraints for table `ticket_calificaciones`
--
ALTER TABLE `ticket_calificaciones`
  ADD CONSTRAINT `ticket_calificaciones_ibfk_1` FOREIGN KEY (`ticket_id`) REFERENCES `tickets` (`id`) ON DELETE CASCADE;

--
-- Constraints for table `time_logs`
--
ALTER TABLE `time_logs`
  ADD CONSTRAINT `fk_time_logs_employee` FOREIGN KEY (`employee_id`) REFERENCES `employee` (`id`) ON DELETE CASCADE ON UPDATE CASCADE;
COMMIT;

/*!40101 SET CHARACTER_SET_CLIENT=@OLD_CHARACTER_SET_CLIENT */;
/*!40101 SET CHARACTER_SET_RESULTS=@OLD_CHARACTER_SET_RESULTS */;
/*!40101 SET COLLATION_CONNECTION=@OLD_COLLATION_CONNECTION */;
