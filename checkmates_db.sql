-- phpMyAdmin SQL Dump
-- version 5.2.1
-- https://www.phpmyadmin.net/
--
-- Host: 127.0.0.1
-- Generation Time: Feb 04, 2026 at 08:24 AM
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
(1, 'Emiart Resort 1', '94 Pasig St, 35 Zone 3, Caloocan', '09337766862', NULL, 'https://images.unsplash.com/photo-1540541338287-41700207dee6?auto=format&fit=crop&w=800&q=80'),
(2, 'Emiart Resort 2', '31 Laon Laan St, Maypajo, Caloocan', '09327815012', NULL, 'https://www.johansens.com/wp-content/uploads/2016/05/Philippines-Crimson-Resort-and-Spa-Mactan-267-768x512.jpg'),
(3, 'Emiart Resort 3', '94 Pasig St, 35 Zone 3, Caloocan', '09327815012', NULL, 'https://images.unsplash.com/photo-1445019980597-93fa8acb246c?auto=format&fit=crop&w=800&q=80'),
(4, 'Hacienda Emiart', 'Purok 7, Barangay Tibangan, Bustos Bulacan', '09337766862', NULL, 'https://images.unsplash.com/photo-1566073771259-6a8506099945?auto=format&fit=crop&w=800&q=80');

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
(7, 'Test', 'test12@gmail.com', '1234', NULL);

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
  `feedback_date` datetime NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `feedback`
--

INSERT INTO `feedback` (`feedback_id`, `customer_id`, `reservation_id`, `rating`, `comments`, `feedback_date`) VALUES
(3, 3, NULL, 4, 'Lorem ipsum dolor sit amet... quis nostrud exercitation ullamco laboris.', '2026-01-25 22:14:31'),
(4, 7, NULL, 3, 'dsbdhha (Occupation: NA)', '2026-01-28 00:14:46'),
(5, 7, NULL, 5, 'sdadsadaa (Occupation: Student)', '2026-01-28 19:11:12'),
(6, 7, NULL, 3, 'dcsdadf (Occupation: Student)', '2026-01-28 19:33:43'),
(7, 7, NULL, 1, 'dnjs nkjflf (Occupation: doctor)', '2026-01-31 09:39:24');

-- --------------------------------------------------------

--
-- Table structure for table `reservations`
--

CREATE TABLE `reservations` (
  `reservation_id` int(11) NOT NULL,
  `customer_id` int(11) NOT NULL,
  `branch_id` int(11) NOT NULL,
  `reservation_date` date NOT NULL,
  `reservation_type` enum('Day','Night','Overnight') NOT NULL,
  `total_amount` decimal(10,2) NOT NULL,
  `status` enum('Pending','Confirmed','Completed','Cancelled') DEFAULT 'Pending'
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `reservations`
--

INSERT INTO `reservations` (`reservation_id`, `customer_id`, `branch_id`, `reservation_date`, `reservation_type`, `total_amount`, `status`) VALUES
(1, 7, 1, '2026-02-05', 'Day', 5000.00, 'Confirmed'),
(2, 7, 1, '2026-02-05', 'Day', 5000.00, 'Confirmed'),
(3, 7, 4, '2026-02-05', 'Overnight', 10000.00, 'Confirmed');

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
  ADD KEY `customer_id` (`customer_id`);

--
-- Indexes for table `reservations`
--
ALTER TABLE `reservations`
  ADD PRIMARY KEY (`reservation_id`),
  ADD KEY `customer_id` (`customer_id`),
  ADD KEY `branch_id` (`branch_id`);

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
  MODIFY `customer_id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=8;

--
-- AUTO_INCREMENT for table `feedback`
--
ALTER TABLE `feedback`
  MODIFY `feedback_id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=8;

--
-- AUTO_INCREMENT for table `reservations`
--
ALTER TABLE `reservations`
  MODIFY `reservation_id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=4;

--
-- AUTO_INCREMENT for table `users`
--
ALTER TABLE `users`
  MODIFY `user_id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=9;

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
  ADD CONSTRAINT `feedback_ibfk_1` FOREIGN KEY (`customer_id`) REFERENCES `customers` (`customer_id`) ON DELETE CASCADE;

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
