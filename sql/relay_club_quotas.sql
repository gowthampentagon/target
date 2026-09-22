-- phpMyAdmin SQL Dump
-- version 5.2.1
-- https://www.phpmyadmin.net/
--
-- Host: 127.0.0.1:3307
-- Generation Time: Aug 01, 2026 at 09:06 PM
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
-- Database: `ssachampionship`
--

-- --------------------------------------------------------

--
-- Table structure for table `relay_club_quotas`
--

CREATE TABLE `relay_club_quotas` (
  `id` int(11) NOT NULL,
  `championship_id` int(11) NOT NULL DEFAULT 1,
  `event_base_type` varchar(100) NOT NULL,
  `club_name` varchar(255) NOT NULL,
  `scheduled_date` date NOT NULL,
  `relay_no` int(11) NOT NULL,
  `quota` int(11) NOT NULL DEFAULT 0,
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Indexes for dumped tables
--

--
-- Indexes for table `relay_club_quotas`
--
ALTER TABLE `relay_club_quotas`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `uniq_quota` (`championship_id`,`event_base_type`,`club_name`,`scheduled_date`,`relay_no`);

--
-- AUTO_INCREMENT for dumped tables
--

--
-- AUTO_INCREMENT for table `relay_club_quotas`
--
ALTER TABLE `relay_club_quotas`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=17;
COMMIT;

/*!40101 SET CHARACTER_SET_CLIENT=@OLD_CHARACTER_SET_CLIENT */;
/*!40101 SET CHARACTER_SET_RESULTS=@OLD_CHARACTER_SET_RESULTS */;
/*!40101 SET COLLATION_CONNECTION=@OLD_COLLATION_CONNECTION */;
