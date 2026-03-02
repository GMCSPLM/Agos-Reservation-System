-- phpMyAdmin SQL Dump
-- version 5.2.1
-- https://www.phpmyadmin.net/
--
-- Host: 127.0.0.1
-- Generation Time: Mar 02, 2026 at 04:39 PM
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
-- Database: `checkmates_db`
--

-- --------------------------------------------------------

--
-- Table structure for table `amenities`
--

CREATE TABLE `amenities` (
  `amenity_id` int(11) NOT NULL,
  `branch_id` int(11) NOT NULL,
  `amenity_name` varchar(100) NOT NULL,
  `description` text DEFAULT NULL,
  `deposit_amount` decimal(10,2) DEFAULT 0.00,
  `availability` enum('Available','Unavailable') DEFAULT 'Available'
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `amenities`
--

INSERT INTO `amenities` (`amenity_id`, `branch_id`, `amenity_name`, `description`, `deposit_amount`, `availability`) VALUES
(1, 4, 'Swimming Pool', 'Pool with slide and waterfalls', 0.00, 'Available'),
(2, 4, 'Half Basketball Court', 'Standard half court size', 0.00, 'Available'),
(3, 4, 'Videoke', 'Unlimited songs until 10PM', 0.00, 'Available'),
(4, 4, 'Billiards', 'Pool table available', 0.00, 'Available');

-- --------------------------------------------------------

--
-- Table structure for table `branches`
--

CREATE TABLE `branches` (
  `branch_id` int(11) NOT NULL,
  `branch_name` varchar(100) NOT NULL,
  `location` varchar(200) NOT NULL,
  `contact_number` varchar(15) DEFAULT NULL,
  `opening_hours` varchar(50) DEFAULT NULL,
  `image_url` varchar(255) DEFAULT 'assets/default.jpg'
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `branches`
--

INSERT INTO `branches` (`branch_id`, `branch_name`, `location`, `contact_number`, `opening_hours`, `image_url`) VALUES
(1, 'Emiart Resort 1', '94 Pasig St, 35 Zone 3, Caloocan', '09337766862', NULL, 'Emiart-One.jpg'),
(2, 'Emiart Resort 2', '31 Laon Laan St, Maypajo, Caloocan', '09327815012', NULL, 'Emiart-Two.png'),
(3, 'Emiart Resort 3', '94 Pasig St, 35 Zone 3, Caloocan', '09327815012', NULL, 'Emiart-Three.png'),
(4, 'Hacienda Emiart', 'Purok 7, Barangay Tibangan, Bustos Bulacan', '09337766862', NULL, 'Hacienda-One.jpg');

-- --------------------------------------------------------

--
-- Table structure for table `branch_capacity`
--

CREATE TABLE `branch_capacity` (
  `capacity_id` int(11) NOT NULL,
  `branch_id` int(11) NOT NULL,
  `max_capacity` int(11) NOT NULL,
  `current_capacity` int(11) DEFAULT 0,
  `date_updated` date DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Stand-in structure for view `branch_performance`
-- (See below for the actual view)
--
CREATE TABLE `branch_performance` (
`branch_id` int(11)
,`branch_name` varchar(100)
,`total_reservations` bigint(21)
,`confirmed_count` decimal(22,0)
,`completed_count` decimal(22,0)
,`total_revenue` decimal(32,2)
,`avg_revenue_per_booking` decimal(14,6)
,`avg_rating` decimal(7,4)
);

-- --------------------------------------------------------

--
-- Table structure for table `customers`
--

CREATE TABLE `customers` (
  `customer_id` int(11) NOT NULL,
  `full_name` varchar(255) NOT NULL,
  `email` varchar(100) NOT NULL,
  `contact_number` varchar(15) DEFAULT NULL,
  `address` text DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `customers`
--

INSERT INTO `customers` (`customer_id`, `full_name`, `email`, `contact_number`, `address`) VALUES
(1, 'Patrick Star', 'patrick@bikini.bottom', '09123456789', 'Bikini Bottom'),
(2, 'Spongebob Squarepants', 'spongebob@bikini.bottom', '09987654321', 'Pineapple House'),
(3, 'Squidward Tentacles', 'squidward@bikini.bottom', '09112223333', 'Moai Head House'),
(4, 'test', 'test@gmail.com', '091234', NULL),
(7, 'Test', 'test12@gmail.com', '1234', NULL),
(9, 'TEST', 'test1234@gmail.com', '09123458732', NULL),
(10, 'test', 'test12345@gmail.com', 'a09302922332323', NULL);

-- --------------------------------------------------------

--
-- Stand-in structure for view `dashboard_overview`
-- (See below for the actual view)
--
CREATE TABLE `dashboard_overview` (
`pending_reservations` bigint(21)
,`todays_reservations` bigint(21)
,`this_month_bookings` bigint(21)
,`this_month_revenue` decimal(32,2)
,`total_customers` bigint(21)
,`avg_rating_this_month` decimal(7,4)
);

-- --------------------------------------------------------

--
-- Table structure for table `feedback`
--

CREATE TABLE `feedback` (
  `feedback_id` int(11) NOT NULL,
  `customer_id` int(11) NOT NULL,
  `reservation_id` int(11) DEFAULT NULL,
  `rating` tinyint(4) NOT NULL,
  `comments` text DEFAULT NULL,
  `feedback_date` datetime NOT NULL,
  `branch_id` int(11) DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `feedback`
--

INSERT INTO `feedback` (`feedback_id`, `customer_id`, `reservation_id`, `rating`, `comments`, `feedback_date`, `branch_id`) VALUES
(3, 3, NULL, 4, 'Lorem ipsum dolor sit amet... quis nostrud exercitation ullamco laboris.', '2026-01-25 22:14:31', NULL),
(4, 7, NULL, 3, 'dsbdhha (Occupation: NA)', '2026-01-28 00:14:46', NULL),
(5, 7, NULL, 5, 'sdadsadaa (Occupation: Student)', '2026-01-28 19:11:12', NULL),
(6, 7, NULL, 3, 'dcsdadf (Occupation: Student)', '2026-01-28 19:33:43', NULL),
(7, 7, NULL, 1, 'dnjs nkjflf (Occupation: doctor)', '2026-01-31 09:39:24', NULL),
(8, 7, NULL, 3, 'sddbinsjcnsckjn (Occupation: Tambay)', '2026-02-06 14:37:18', NULL),
(9, 7, NULL, 5, 'MAJNDIJANDIUSDBAIUUA (Occupation: TEST)', '2026-02-11 16:00:28', NULL),
(10, 7, NULL, 5, 'dsadadadaddd (Occupation: Test)', '2026-03-02 16:51:01', 4);

-- --------------------------------------------------------

--
-- Stand-in structure for view `monthly_booking_stats`
-- (See below for the actual view)
--
CREATE TABLE `monthly_booking_stats` (
`year` int(4)
,`month` int(2)
,`month_name` varchar(9)
,`total_bookings` bigint(21)
,`confirmed_bookings` decimal(22,0)
,`completed_bookings` decimal(22,0)
,`cancelled_bookings` decimal(22,0)
,`total_revenue` decimal(32,2)
,`avg_booking_value` decimal(14,6)
,`confirmed_revenue` decimal(32,2)
);

-- --------------------------------------------------------

--
-- Table structure for table `reservations`
--

CREATE TABLE `reservations` (
  `reservation_id` int(11) NOT NULL,
  `customer_id` int(11) NOT NULL,
  `branch_id` int(11) NOT NULL,
  `reservation_date` date NOT NULL,
  `check_out_date` date DEFAULT NULL,
  `reservation_type` enum('Day','Night','Overnight') NOT NULL,
  `number_of_guests` int(11) DEFAULT 1,
  `total_amount` decimal(10,2) NOT NULL,
  `payment_status` enum('Unpaid','Paid','Partial','Refunded') DEFAULT 'Unpaid',
  `notes` text DEFAULT NULL,
  `status` enum('Pending','Confirmed','Completed','Cancelled') DEFAULT 'Pending',
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `reservations`
--

INSERT INTO `reservations` (`reservation_id`, `customer_id`, `branch_id`, `reservation_date`, `check_out_date`, `reservation_type`, `number_of_guests`, `total_amount`, `payment_status`, `notes`, `status`, `created_at`) VALUES
(1, 7, 1, '2026-02-05', NULL, 'Day', 1, 5000.00, 'Unpaid', NULL, 'Completed', '2026-02-06 05:55:32'),
(2, 7, 1, '2026-02-05', NULL, 'Day', 1, 5000.00, 'Unpaid', NULL, 'Completed', '2026-02-06 05:55:32'),
(4, 7, 4, '2026-02-08', NULL, 'Overnight', 1, 10000.00, 'Unpaid', NULL, 'Pending', '2026-02-07 12:03:54'),
(5, 7, 4, '2026-02-08', NULL, 'Overnight', 1, 10000.00, 'Unpaid', NULL, 'Pending', '2026-02-07 12:05:30'),
(6, 7, 1, '2026-02-15', NULL, 'Day', 1, 9000.00, 'Unpaid', NULL, 'Pending', '2026-02-07 12:25:17'),
(7, 7, 1, '2026-02-15', NULL, 'Overnight', 1, 10000.00, 'Unpaid', NULL, 'Pending', '2026-02-07 12:25:49'),
(8, 7, 1, '2026-02-15', NULL, 'Overnight', 1, 10000.00, 'Unpaid', NULL, 'Pending', '2026-02-07 12:28:25'),
(9, 7, 1, '2026-02-15', NULL, 'Overnight', 1, 10000.00, 'Unpaid', NULL, 'Pending', '2026-02-07 12:29:50'),
(10, 7, 1, '2026-02-09', NULL, 'Day', 1, 9000.00, 'Unpaid', NULL, 'Pending', '2026-02-08 05:35:06'),
(11, 7, 1, '2026-02-09', NULL, 'Day', 1, 9000.00, 'Unpaid', NULL, 'Pending', '2026-02-08 05:39:24'),
(12, 7, 4, '2026-02-09', NULL, 'Overnight', 1, 10000.00, 'Unpaid', NULL, 'Pending', '2026-02-08 05:40:29'),
(13, 7, 1, '2026-02-19', NULL, 'Day', 1, 9000.00, 'Unpaid', NULL, 'Pending', '2026-02-08 06:03:17'),
(14, 7, 1, '2026-02-19', NULL, 'Day', 1, 9000.00, 'Unpaid', NULL, 'Pending', '2026-02-08 06:03:39'),
(15, 7, 1, '2026-02-20', NULL, 'Day', 1, 9000.00, 'Unpaid', NULL, 'Pending', '2026-02-08 06:19:52'),
(16, 7, 1, '2026-02-20', NULL, 'Day', 1, 9000.00, 'Unpaid', NULL, 'Pending', '2026-02-08 06:25:25'),
(17, 7, 1, '2026-02-20', NULL, 'Day', 1, 9000.00, 'Unpaid', NULL, 'Pending', '2026-02-08 06:27:09'),
(18, 7, 1, '2026-03-05', NULL, 'Day', 1, 9000.00, 'Unpaid', NULL, 'Pending', '2026-02-08 06:27:50'),
(19, 7, 1, '2026-03-05', NULL, 'Day', 1, 1.00, 'Unpaid', NULL, 'Cancelled', '2026-02-08 06:28:46'),
(20, 7, 1, '2026-03-05', NULL, 'Day', 1, 1.00, 'Unpaid', NULL, 'Cancelled', '2026-02-08 06:34:53'),
(21, 7, 1, '2026-03-05', NULL, 'Day', 1, 1.00, 'Unpaid', NULL, 'Cancelled', '2026-02-08 06:35:37'),
(22, 7, 1, '2026-02-19', NULL, 'Day', 1, 1.00, 'Unpaid', NULL, 'Completed', '2026-02-11 07:55:54'),
(23, 9, 1, '2026-02-19', NULL, 'Day', 1, 1.00, 'Unpaid', NULL, 'Cancelled', '2026-02-11 10:44:52'),
(24, 9, 1, '2026-02-26', NULL, 'Day', 1, 1.00, 'Unpaid', NULL, 'Cancelled', '2026-02-11 10:46:26'),
(25, 9, 1, '2026-02-26', NULL, 'Day', 1, 1.00, 'Unpaid', NULL, 'Cancelled', '2026-02-11 10:46:35'),
(26, 9, 1, '2026-02-26', NULL, 'Day', 1, 1.00, 'Unpaid', NULL, 'Cancelled', '2026-02-11 10:46:55'),
(27, 7, 4, '2026-02-24', NULL, 'Day', 1, 1.00, 'Unpaid', NULL, 'Cancelled', '2026-02-22 12:50:44'),
(28, 7, 4, '2026-02-26', NULL, 'Day', 1, 1.00, 'Unpaid', NULL, 'Cancelled', '2026-02-22 12:55:52'),
(29, 7, 1, '2026-02-28', NULL, 'Day', 1, 1.00, 'Unpaid', NULL, 'Cancelled', '2026-02-22 12:56:49'),
(30, 7, 2, '2026-02-25', NULL, 'Day', 1, 900.00, 'Unpaid', NULL, 'Cancelled', '2026-02-22 12:59:28'),
(31, 7, 1, '2026-02-26', NULL, 'Overnight', 1, 1000.00, 'Unpaid', NULL, 'Cancelled', '2026-02-24 06:01:44'),
(32, 7, 1, '2026-02-26', NULL, 'Overnight', 1, 1000.00, 'Unpaid', NULL, 'Cancelled', '2026-02-24 07:30:11'),
(33, 7, 1, '2026-02-28', NULL, 'Day', 1, 900.00, 'Unpaid', NULL, 'Cancelled', '2026-02-24 15:29:55'),
(34, 7, 1, '2026-03-27', NULL, 'Overnight', 1, 1000.00, 'Unpaid', NULL, 'Cancelled', '2026-02-24 15:35:15'),
(35, 7, 2, '2026-02-28', NULL, 'Overnight', 1, 1000.00, 'Unpaid', NULL, 'Cancelled', '2026-02-25 08:41:13'),
(36, 7, 1, '2026-03-11', NULL, 'Overnight', 1, 1000.00, 'Unpaid', NULL, 'Pending', '2026-03-02 09:10:37');

-- --------------------------------------------------------

--
-- Table structure for table `users`
--

CREATE TABLE `users` (
  `user_id` int(11) NOT NULL,
  `username` varchar(100) NOT NULL,
  `password_hash` varchar(255) NOT NULL,
  `role` enum('Customer','Admin','Staff') DEFAULT 'Customer',
  `is_active` tinyint(1) DEFAULT 1,
  `customer_id` int(11) DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `users`
--

INSERT INTO `users` (`user_id`, `username`, `password_hash`, `role`, `is_active`, `customer_id`) VALUES
(1, 'admin@gmail.com', '$2y$10$QIK49hLDMmbCbKB8cWHDnOutsd9BAjKWe4MlxLG5utXDGKwedCWWO', 'Admin', 1, NULL),
(2, 'patrick@bikini.bottom', '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi', 'Customer', 1, 1),
(3, 'spongebob@bikini.bottom', '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi', 'Customer', 1, 2),
(4, 'squidward@bikini.bottom', '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi', 'Customer', 1, 3),
(5, 'test@gmail.com', '$2y$10$8.HWt5t.HW8swy4.ZFz6leAur1Fl07Kbm4AQwADZ6sePAA1I15Qri', 'Customer', 1, 4),
(8, 'test12@gmail.com', '$2y$10$hy7hE.iPgv5re27TCVrPNOwypCE/6KlUchHfTkedEqHDNY5sr8HFC', 'Customer', 1, 7);

-- --------------------------------------------------------

--
-- Structure for view `branch_performance`
--
DROP TABLE IF EXISTS `branch_performance`;

CREATE ALGORITHM=UNDEFINED DEFINER=`root`@`localhost` SQL SECURITY DEFINER VIEW `branch_performance`  AS SELECT `b`.`branch_id` AS `branch_id`, `b`.`branch_name` AS `branch_name`, count(`r`.`reservation_id`) AS `total_reservations`, sum(case when `r`.`status` = 'Confirmed' then 1 else 0 end) AS `confirmed_count`, sum(case when `r`.`status` = 'Completed' then 1 else 0 end) AS `completed_count`, sum(`r`.`total_amount`) AS `total_revenue`, avg(`r`.`total_amount`) AS `avg_revenue_per_booking`, coalesce(avg(`f`.`rating`),0) AS `avg_rating` FROM ((`branches` `b` left join `reservations` `r` on(`b`.`branch_id` = `r`.`branch_id`)) left join `feedback` `f` on(`r`.`reservation_id` = `f`.`reservation_id`)) GROUP BY `b`.`branch_id`, `b`.`branch_name` ;

-- --------------------------------------------------------

--
-- Structure for view `dashboard_overview`
--
DROP TABLE IF EXISTS `dashboard_overview`;

CREATE ALGORITHM=UNDEFINED DEFINER=`root`@`localhost` SQL SECURITY DEFINER VIEW `dashboard_overview`  AS SELECT (select count(0) from `reservations` where `reservations`.`status` = 'Pending') AS `pending_reservations`, (select count(0) from `reservations` where `reservations`.`status` = 'Confirmed' and `reservations`.`reservation_date` = curdate()) AS `todays_reservations`, (select count(0) from `reservations` where month(`reservations`.`reservation_date`) = month(curdate()) and year(`reservations`.`reservation_date`) = year(curdate())) AS `this_month_bookings`, (select sum(`reservations`.`total_amount`) from `reservations` where month(`reservations`.`reservation_date`) = month(curdate()) and year(`reservations`.`reservation_date`) = year(curdate()) and `reservations`.`status` in ('Confirmed','Completed')) AS `this_month_revenue`, (select count(0) from `customers`) AS `total_customers`, (select avg(`feedback`.`rating`) from `feedback` where month(`feedback`.`feedback_date`) = month(curdate())) AS `avg_rating_this_month` ;

-- --------------------------------------------------------

--
-- Structure for view `monthly_booking_stats`
--
DROP TABLE IF EXISTS `monthly_booking_stats`;

CREATE ALGORITHM=UNDEFINED DEFINER=`root`@`localhost` SQL SECURITY DEFINER VIEW `monthly_booking_stats`  AS SELECT year(`reservations`.`reservation_date`) AS `year`, month(`reservations`.`reservation_date`) AS `month`, monthname(`reservations`.`reservation_date`) AS `month_name`, count(0) AS `total_bookings`, sum(case when `reservations`.`status` = 'Confirmed' then 1 else 0 end) AS `confirmed_bookings`, sum(case when `reservations`.`status` = 'Completed' then 1 else 0 end) AS `completed_bookings`, sum(case when `reservations`.`status` = 'Cancelled' then 1 else 0 end) AS `cancelled_bookings`, sum(`reservations`.`total_amount`) AS `total_revenue`, avg(`reservations`.`total_amount`) AS `avg_booking_value`, sum(case when `reservations`.`status` in ('Confirmed','Completed') then `reservations`.`total_amount` else 0 end) AS `confirmed_revenue` FROM `reservations` GROUP BY year(`reservations`.`reservation_date`), month(`reservations`.`reservation_date`) ORDER BY year(`reservations`.`reservation_date`) DESC, month(`reservations`.`reservation_date`) DESC ;

--
-- Indexes for dumped tables
--

--
-- Indexes for table `amenities`
--
ALTER TABLE `amenities`
  ADD PRIMARY KEY (`amenity_id`),
  ADD KEY `branch_id` (`branch_id`);

--
-- Indexes for table `branches`
--
ALTER TABLE `branches`
  ADD PRIMARY KEY (`branch_id`);

--
-- Indexes for table `branch_capacity`
--
ALTER TABLE `branch_capacity`
  ADD PRIMARY KEY (`capacity_id`),
  ADD KEY `branch_id` (`branch_id`);

--
-- Indexes for table `customers`
--
ALTER TABLE `customers`
  ADD PRIMARY KEY (`customer_id`),
  ADD UNIQUE KEY `email` (`email`);

--
-- Indexes for table `feedback`
--
ALTER TABLE `feedback`
  ADD PRIMARY KEY (`feedback_id`),
  ADD KEY `customer_id` (`customer_id`),
  ADD KEY `idx_feedback_date` (`feedback_date`),
  ADD KEY `idx_rating` (`rating`),
  ADD KEY `fk_feedback_branch` (`branch_id`);

--
-- Indexes for table `reservations`
--
ALTER TABLE `reservations`
  ADD PRIMARY KEY (`reservation_id`),
  ADD KEY `customer_id` (`customer_id`),
  ADD KEY `branch_id` (`branch_id`),
  ADD KEY `idx_reservation_date` (`reservation_date`),
  ADD KEY `idx_created_at` (`created_at`),
  ADD KEY `idx_status` (`status`);

--
-- Indexes for table `users`
--
ALTER TABLE `users`
  ADD PRIMARY KEY (`user_id`),
  ADD UNIQUE KEY `username` (`username`),
  ADD KEY `customer_id` (`customer_id`);

--
-- AUTO_INCREMENT for dumped tables
--

--
-- AUTO_INCREMENT for table `amenities`
--
ALTER TABLE `amenities`
  MODIFY `amenity_id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=5;

--
-- AUTO_INCREMENT for table `branches`
--
ALTER TABLE `branches`
  MODIFY `branch_id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=5;

--
-- AUTO_INCREMENT for table `branch_capacity`
--
ALTER TABLE `branch_capacity`
  MODIFY `capacity_id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `customers`
--
ALTER TABLE `customers`
  MODIFY `customer_id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=11;

--
-- AUTO_INCREMENT for table `feedback`
--
ALTER TABLE `feedback`
  MODIFY `feedback_id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=11;

--
-- AUTO_INCREMENT for table `reservations`
--
ALTER TABLE `reservations`
  MODIFY `reservation_id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=37;

--
-- AUTO_INCREMENT for table `users`
--
ALTER TABLE `users`
  MODIFY `user_id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=11;

--
-- Constraints for dumped tables
--

--
-- Constraints for table `amenities`
--
ALTER TABLE `amenities`
  ADD CONSTRAINT `amenities_ibfk_1` FOREIGN KEY (`branch_id`) REFERENCES `branches` (`branch_id`) ON DELETE CASCADE;

--
-- Constraints for table `branch_capacity`
--
ALTER TABLE `branch_capacity`
  ADD CONSTRAINT `branch_capacity_ibfk_1` FOREIGN KEY (`branch_id`) REFERENCES `branches` (`branch_id`) ON DELETE CASCADE;

--
-- Constraints for table `feedback`
--
ALTER TABLE `feedback`
  ADD CONSTRAINT `feedback_ibfk_1` FOREIGN KEY (`customer_id`) REFERENCES `customers` (`customer_id`) ON DELETE CASCADE,
  ADD CONSTRAINT `fk_feedback_branch` FOREIGN KEY (`branch_id`) REFERENCES `branches` (`branch_id`) ON DELETE SET NULL;

--
-- Constraints for table `reservations`
--
ALTER TABLE `reservations`
  ADD CONSTRAINT `reservations_ibfk_1` FOREIGN KEY (`customer_id`) REFERENCES `customers` (`customer_id`) ON DELETE CASCADE,
  ADD CONSTRAINT `reservations_ibfk_2` FOREIGN KEY (`branch_id`) REFERENCES `branches` (`branch_id`) ON DELETE CASCADE;

--
-- Constraints for table `users`
--
ALTER TABLE `users`
  ADD CONSTRAINT `users_ibfk_1` FOREIGN KEY (`customer_id`) REFERENCES `customers` (`customer_id`) ON DELETE SET NULL;
COMMIT;

/*!40101 SET CHARACTER_SET_CLIENT=@OLD_CHARACTER_SET_CLIENT */;
/*!40101 SET CHARACTER_SET_RESULTS=@OLD_CHARACTER_SET_RESULTS */;
/*!40101 SET COLLATION_CONNECTION=@OLD_COLLATION_CONNECTION */;
