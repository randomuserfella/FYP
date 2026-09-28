-- ProcraTrack: database structure only (no data).
-- Import into an empty database named `procratrack` (MariaDB 10.4+).

-- phpMyAdmin SQL Dump
-- version 5.2.1
-- https://www.phpmyadmin.net/
--
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
-- Database: `procratrack`
--

-- --------------------------------------------------------

--
-- Table structure for table `access_requests`
--

CREATE TABLE IF NOT EXISTS `access_requests` (
  `id` int(11) NOT NULL,
  `lecturer_id` int(11) NOT NULL,
  `student_id` int(11) NOT NULL,
  `status` enum('pending','approved','denied','revoked') NOT NULL DEFAULT 'pending',
  `requested_at` datetime NOT NULL DEFAULT current_timestamp(),
  `responded_at` datetime DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `bot_sessions`
--

CREATE TABLE IF NOT EXISTS `bot_sessions` (
  `chat_id` varchar(20) NOT NULL,
  `step` varchar(30) NOT NULL DEFAULT 'idle',
  `data` text NOT NULL DEFAULT '{}',
  `updated_at` datetime DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `chat_invites`
--

CREATE TABLE IF NOT EXISTS `chat_invites` (
  `id` int(10) UNSIGNED NOT NULL,
  `sender_id` int(10) UNSIGNED NOT NULL,
  `sender_university` varchar(120) DEFAULT NULL,
  `recipient_id` int(10) UNSIGNED NOT NULL,
  `recipient_university` varchar(120) DEFAULT NULL,
  `status` enum('pending','accepted','denied') NOT NULL DEFAULT 'pending',
  `message` varchar(255) DEFAULT NULL,
  `room_id` int(10) UNSIGNED DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `responded_at` timestamp NULL DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `chat_members`
--

CREATE TABLE IF NOT EXISTS `chat_members` (
  `id` int(10) UNSIGNED NOT NULL,
  `room_id` int(10) UNSIGNED NOT NULL,
  `user_id` int(10) UNSIGNED NOT NULL,
  `joined_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `seen` tinyint(1) NOT NULL DEFAULT 1
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `chat_messages`
--

CREATE TABLE IF NOT EXISTS `chat_messages` (
  `id` int(10) UNSIGNED NOT NULL,
  `room_id` int(10) UNSIGNED NOT NULL,
  `user_id` int(10) UNSIGNED NOT NULL,
  `message` text NOT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `chat_message_hidden`
--

CREATE TABLE IF NOT EXISTS `chat_message_hidden` (
  `message_id` int(10) UNSIGNED NOT NULL,
  `user_id` int(10) UNSIGNED NOT NULL,
  `hidden_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `chat_read_at`
--

CREATE TABLE IF NOT EXISTS `chat_read_at` (
  `room_id` int(10) UNSIGNED NOT NULL,
  `user_id` int(10) UNSIGNED NOT NULL,
  `last_read_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `chat_rooms`
--

CREATE TABLE IF NOT EXISTS `chat_rooms` (
  `id` int(10) UNSIGNED NOT NULL,
  `name` varchar(255) NOT NULL,
  `type` enum('group','direct','class','note') DEFAULT 'group',
  `university` varchar(255) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `created_by` int(10) UNSIGNED DEFAULT NULL,
  `class_uniq_key` varchar(150) GENERATED ALWAYS AS (case when `type` = 'class' then `university` else NULL end) STORED
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `distraction_events`
--

CREATE TABLE IF NOT EXISTS `distraction_events` (
  `id` int(10) UNSIGNED NOT NULL,
  `user_id` int(10) UNSIGNED NOT NULL,
  `session_id` int(10) UNSIGNED DEFAULT NULL,
  `event_type` enum('tab_switch','manual','idle') DEFAULT 'manual',
  `occurred_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `duration_away` int(11) DEFAULT NULL,
  `note` varchar(255) DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `habits`
--

CREATE TABLE IF NOT EXISTS `habits` (
  `id` int(10) UNSIGNED NOT NULL,
  `user_id` int(10) UNSIGNED NOT NULL,
  `habit_name` varchar(255) NOT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `is_private` tinyint(1) NOT NULL DEFAULT 0
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `habit_logs`
--

CREATE TABLE IF NOT EXISTS `habit_logs` (
  `id` int(10) UNSIGNED NOT NULL,
  `habit_id` int(10) UNSIGNED NOT NULL,
  `log_date` date NOT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `invite_codes`
--

CREATE TABLE IF NOT EXISTS `invite_codes` (
  `id` int(10) UNSIGNED NOT NULL,
  `code` varchar(50) NOT NULL,
  `institution` varchar(255) DEFAULT NULL,
  `created_by` int(10) UNSIGNED DEFAULT NULL,
  `used_count` int(11) DEFAULT 0,
  `active` tinyint(1) DEFAULT 1,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `notification_log`
--

CREATE TABLE IF NOT EXISTS `notification_log` (
  `id` int(10) UNSIGNED NOT NULL,
  `user_id` int(10) UNSIGNED NOT NULL,
  `channel` enum('telegram','email') NOT NULL,
  `type` varchar(50) DEFAULT NULL,
  `message` text DEFAULT NULL,
  `status` enum('sent','failed') DEFAULT 'sent',
  `sent_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `password_resets`
--

CREATE TABLE IF NOT EXISTS `password_resets` (
  `id` int(10) UNSIGNED NOT NULL,
  `email` varchar(255) NOT NULL,
  `token` varchar(64) NOT NULL,
  `expires_at` datetime NOT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `push_log`
--

CREATE TABLE IF NOT EXISTS `push_log` (
  `id` int(10) UNSIGNED NOT NULL,
  `user_id` int(10) UNSIGNED NOT NULL,
  `type` varchar(50) NOT NULL,
  `title` varchar(255) NOT NULL,
  `body` varchar(500) NOT NULL,
  `status` enum('sent','failed') DEFAULT 'sent',
  `sent_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `push_subscriptions`
--

CREATE TABLE IF NOT EXISTS `push_subscriptions` (
  `id` int(10) UNSIGNED NOT NULL,
  `user_id` int(10) UNSIGNED NOT NULL,
  `endpoint` text NOT NULL,
  `p256dh` text NOT NULL,
  `auth` varchar(255) NOT NULL,
  `user_agent` varchar(255) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `remember_tokens`
--

CREATE TABLE IF NOT EXISTS `remember_tokens` (
  `id` int(10) UNSIGNED NOT NULL,
  `user_id` int(10) UNSIGNED NOT NULL,
  `selector` varchar(24) NOT NULL,
  `validator_hash` varchar(255) NOT NULL,
  `expires_at` datetime NOT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `sessions`
--

CREATE TABLE IF NOT EXISTS `sessions` (
  `id` int(10) UNSIGNED NOT NULL,
  `user_id` int(10) UNSIGNED NOT NULL,
  `duration_minutes` int(11) DEFAULT NULL,
  `session_date` date DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `start_time` datetime DEFAULT NULL,
  `end_time` datetime DEFAULT NULL,
  `distraction_count` int(11) DEFAULT 0,
  `task_id` int(10) UNSIGNED DEFAULT NULL,
  `session_type` varchar(50) DEFAULT 'pomodoro'
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `tasks`
--

CREATE TABLE IF NOT EXISTS `tasks` (
  `id` int(10) UNSIGNED NOT NULL,
  `user_id` int(10) UNSIGNED NOT NULL,
  `task_name` varchar(255) NOT NULL,
  `course` varchar(255) DEFAULT NULL,
  `priority` enum('high','medium','low') DEFAULT 'medium',
  `status` enum('pending','done') DEFAULT 'pending',
  `due_date` date DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `start_time` time DEFAULT NULL,
  `end_time` time DEFAULT NULL,
  `custom_color` varchar(20) DEFAULT NULL,
  `recur_type` varchar(20) DEFAULT 'none',
  `recur_end` date DEFAULT NULL,
  `notes` text DEFAULT NULL,
  `start_date` date DEFAULT NULL,
  `due_time` time DEFAULT NULL,
  `is_private` tinyint(1) NOT NULL DEFAULT 0,
  `archived_at` datetime DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `users`
--

CREATE TABLE IF NOT EXISTS `users` (
  `id` int(10) UNSIGNED NOT NULL,
  `name` varchar(255) NOT NULL,
  `email` varchar(255) NOT NULL,
  `password` varchar(255) NOT NULL,
  `role` enum('student','lecturer') NOT NULL DEFAULT 'student',
  `university` varchar(255) DEFAULT NULL,
  `semester` varchar(50) DEFAULT NULL,
  `staff_id` varchar(100) DEFAULT NULL,
  `department` varchar(255) DEFAULT NULL,
  `status` enum('active','pending','suspended') DEFAULT 'active',
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `telegram_chat_id` varchar(100) DEFAULT NULL,
  `notify_telegram` tinyint(1) DEFAULT 0,
  `notify_email` tinyint(1) DEFAULT 0,
  `notification_email` varchar(255) DEFAULT NULL,
  `avatar` varchar(255) DEFAULT NULL,
  `remind_7day` tinyint(1) DEFAULT 1,
  `remind_3day` tinyint(1) DEFAULT 1,
  `remind_1day` tinyint(1) DEFAULT 1,
  `remind_today` tinyint(1) DEFAULT 1,
  `remind_60min` tinyint(1) DEFAULT 1,
  `remind_30min` tinyint(1) DEFAULT 1,
  `remind_ondue` tinyint(1) DEFAULT 1,
  `remind_overdue` tinyint(1) DEFAULT 1,
  `theme` varchar(10) NOT NULL DEFAULT 'dark',
  `bg_theme` varchar(20) NOT NULL DEFAULT 'default'
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Indexes for dumped tables
--

--
-- Indexes for table `access_requests`
--
ALTER TABLE `access_requests`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_lecturer` (`lecturer_id`),
  ADD KEY `idx_student` (`student_id`);

--
-- Indexes for table `bot_sessions`
--
ALTER TABLE `bot_sessions`
  ADD PRIMARY KEY (`chat_id`);

--
-- Indexes for table `chat_invites`
--
ALTER TABLE `chat_invites`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_recipient` (`recipient_id`,`status`),
  ADD KEY `idx_sender` (`sender_id`,`status`);

--
-- Indexes for table `chat_members`
--
ALTER TABLE `chat_members`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `uq_room_user` (`room_id`,`user_id`);

--
-- Indexes for table `chat_messages`
--
ALTER TABLE `chat_messages`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_room` (`room_id`),
  ADD KEY `idx_created` (`created_at`);

--
-- Indexes for table `chat_message_hidden`
--
ALTER TABLE `chat_message_hidden`
  ADD PRIMARY KEY (`message_id`,`user_id`);

--
-- Indexes for table `chat_read_at`
--
ALTER TABLE `chat_read_at`
  ADD PRIMARY KEY (`room_id`,`user_id`);

--
-- Indexes for table `chat_rooms`
--
ALTER TABLE `chat_rooms`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `uq_class_university` (`class_uniq_key`);

--
-- Indexes for table `distraction_events`
--
ALTER TABLE `distraction_events`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_user` (`user_id`),
  ADD KEY `idx_sess` (`session_id`),
  ADD KEY `idx_date` (`occurred_at`);

--
-- Indexes for table `habits`
--
ALTER TABLE `habits`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_user_id` (`user_id`);

--
-- Indexes for table `habit_logs`
--
ALTER TABLE `habit_logs`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `uq_habit_date` (`habit_id`,`log_date`),
  ADD KEY `idx_habit_id` (`habit_id`),
  ADD KEY `idx_log_date` (`log_date`);

--
-- Indexes for table `invite_codes`
--
ALTER TABLE `invite_codes`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `code` (`code`),
  ADD KEY `idx_code` (`code`);

--
-- Indexes for table `notification_log`
--
ALTER TABLE `notification_log`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_user` (`user_id`),
  ADD KEY `idx_sent` (`sent_at`);

--
-- Indexes for table `password_resets`
--
ALTER TABLE `password_resets`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `uq_email` (`email`),
  ADD UNIQUE KEY `uq_token` (`token`),
  ADD KEY `idx_token` (`token`),
  ADD KEY `idx_expires` (`expires_at`);

--
-- Indexes for table `push_log`
--
ALTER TABLE `push_log`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_user` (`user_id`),
  ADD KEY `idx_type` (`type`);

--
-- Indexes for table `push_subscriptions`
--
ALTER TABLE `push_subscriptions`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `uq_user_endpoint` (`user_id`,`endpoint`(200)),
  ADD KEY `idx_user_id` (`user_id`);

--
-- Indexes for table `remember_tokens`
--
ALTER TABLE `remember_tokens`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `uq_selector` (`selector`),
  ADD KEY `idx_user_id` (`user_id`);

--
-- Indexes for table `sessions`
--
ALTER TABLE `sessions`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_user_id` (`user_id`),
  ADD KEY `idx_session_date` (`session_date`);

--
-- Indexes for table `tasks`
--
ALTER TABLE `tasks`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_user_id` (`user_id`),
  ADD KEY `idx_status` (`status`),
  ADD KEY `idx_due_date` (`due_date`);

--
-- Indexes for table `users`
--
ALTER TABLE `users`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `email` (`email`),
  ADD KEY `idx_email` (`email`),
  ADD KEY `idx_role` (`role`);

--
-- AUTO_INCREMENT for dumped tables
--

--
-- AUTO_INCREMENT for table `access_requests`
--
ALTER TABLE `access_requests`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `chat_invites`
--
ALTER TABLE `chat_invites`
  MODIFY `id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `chat_members`
--
ALTER TABLE `chat_members`
  MODIFY `id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `chat_messages`
--
ALTER TABLE `chat_messages`
  MODIFY `id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `chat_rooms`
--
ALTER TABLE `chat_rooms`
  MODIFY `id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `distraction_events`
--
ALTER TABLE `distraction_events`
  MODIFY `id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `habits`
--
ALTER TABLE `habits`
  MODIFY `id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `habit_logs`
--
ALTER TABLE `habit_logs`
  MODIFY `id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `invite_codes`
--
ALTER TABLE `invite_codes`
  MODIFY `id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `notification_log`
--
ALTER TABLE `notification_log`
  MODIFY `id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `password_resets`
--
ALTER TABLE `password_resets`
  MODIFY `id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `push_log`
--
ALTER TABLE `push_log`
  MODIFY `id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `push_subscriptions`
--
ALTER TABLE `push_subscriptions`
  MODIFY `id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `remember_tokens`
--
ALTER TABLE `remember_tokens`
  MODIFY `id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `sessions`
--
ALTER TABLE `sessions`
  MODIFY `id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `tasks`
--
ALTER TABLE `tasks`
  MODIFY `id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `users`
--
ALTER TABLE `users`
  MODIFY `id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT;
COMMIT;

/*!40101 SET CHARACTER_SET_CLIENT=@OLD_CHARACTER_SET_CLIENT */;
/*!40101 SET CHARACTER_SET_RESULTS=@OLD_CHARACTER_SET_RESULTS */;
/*!40101 SET COLLATION_CONNECTION=@OLD_COLLATION_CONNECTION */;
