-- phpMyAdmin SQL Dump
-- version 5.2.1
-- https://www.phpmyadmin.net/
--
-- Host: 127.0.0.1
-- Generation Time: Nov 15, 2026 at 04:28 PM
-- Server version: 8.0.35
-- PHP Version: 8.2.12

SET SQL_MODE = "NO_AUTO_VALUE_ON_ZERO";
START TRANSACTION;
SET time_zone = "+00:00";


/*!40101 SET @OLD_CHARACTER_SET_CLIENT=@@CHARACTER_SET_CLIENT */;
/*!40101 SET @OLD_CHARACTER_SET_RESULTS=@@CHARACTER_SET_RESULTS */;
/*!40101 SET @OLD_COLLATION_CONNECTION=@@COLLATION_CONNECTION */;
/*!40101 SET NAMES utf8mb4 */;

--
-- Database: `du_emba_portal`
--

-- --------------------------------------------------------

--
-- Table structure for table `academic_sessions`
--

CREATE TABLE `academic_sessions` (
  `id` int UNSIGNED NOT NULL,
  `session_name` varchar(100) COLLATE utf8mb4_unicode_ci NOT NULL,
  `start_date` date DEFAULT NULL,
  `end_date` date DEFAULT NULL,
  `status` enum('planned','active','completed') COLLATE utf8mb4_unicode_ci DEFAULT 'planned'
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Dumping data for table `academic_sessions`
--

INSERT INTO `academic_sessions` (`id`, `session_name`, `start_date`, `end_date`, `status`) VALUES
(1, '2026 Academic Session', '2026-01-01', '2026-12-31', 'active'),
(2, '2027 Academic Session', '2027-01-01', '2027-12-31', 'planned');

-- --------------------------------------------------------

--
-- Table structure for table `assessments`
--

CREATE TABLE `assessments` (
  `id` int UNSIGNED NOT NULL,
  `offering_id` int UNSIGNED NOT NULL,
  `assessment_name` varchar(120) COLLATE utf8mb4_unicode_ci NOT NULL,
  `max_marks` decimal(7,2) NOT NULL,
  `weight_percentage` decimal(5,2) NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `attendance`
--

CREATE TABLE `attendance` (
  `id` bigint UNSIGNED NOT NULL,
  `registration_id` int UNSIGNED NOT NULL,
  `class_date` date NOT NULL,
  `status` enum('present','absent','late','excused') COLLATE utf8mb4_unicode_ci NOT NULL,
  `recorded_by` int UNSIGNED NOT NULL,
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `audit_logs`
--

CREATE TABLE `audit_logs` (
  `id` bigint UNSIGNED NOT NULL,
  `user_id` int UNSIGNED DEFAULT NULL,
  `action` varchar(100) COLLATE utf8mb4_unicode_ci NOT NULL,
  `entity_type` varchar(100) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `entity_id` varchar(60) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `details` text COLLATE utf8mb4_unicode_ci,
  `ip_address` varchar(45) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `batches`
--

CREATE TABLE `batches` (
  `id` int UNSIGNED NOT NULL,
  `batch_name` varchar(100) COLLATE utf8mb4_unicode_ci NOT NULL,
  `admission_year` smallint UNSIGNED DEFAULT NULL,
  `status` enum('active','inactive') COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'active',
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Dumping data for table `batches`
--

INSERT INTO `batches` (`id`, `batch_name`, `admission_year`, `status`, `created_at`) VALUES
(1, '1st Batch', 2026, 'active', '2026-10-18 18:22:33'),
(3, '2nd Batch', 2026, 'active', '2026-10-18 18:33:31');

-- --------------------------------------------------------

--
-- Table structure for table `courses`
--

CREATE TABLE `courses` (
  `id` int UNSIGNED NOT NULL,
  `course_code` varchar(30) COLLATE utf8mb4_unicode_ci NOT NULL,
  `course_title` varchar(180) COLLATE utf8mb4_unicode_ci NOT NULL,
  `category_id` int UNSIGNED DEFAULT NULL,
  `teacher_id` int UNSIGNED DEFAULT NULL,
  `credit_hours` decimal(4,1) NOT NULL DEFAULT '3.0',
  `description` text COLLATE utf8mb4_unicode_ci,
  `is_active` tinyint(1) NOT NULL DEFAULT '1'
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Dumping data for table `courses`
--

INSERT INTO `courses` (`id`, `course_code`, `course_title`, `category_id`, `teacher_id`, `credit_hours`, `description`, `is_active`) VALUES
(1, 'EMIS-501', 'Introduction to Business and Management', 1, NULL, 3.0, '', 1),
(2, 'EMIS 509', 'System Analysis and Design', NULL, 1, 3.0, '', 0),
(3, 'EMIS-502', 'Principles of Accounting and Finance', 1, NULL, 3.0, '', 1),
(4, 'EMIS-503', 'International Business and Marketing', 1, NULL, 3.0, '', 1),
(5, 'EMIS-504', 'Emerging Technologies for Business', 1, NULL, 3.0, '', 1),
(6, 'EMIS-505', 'Programming for Business', 1, NULL, 3.0, '', 1),
(7, 'EMIS-506', 'Quantitative Techniques for Managers', 1, NULL, 3.0, '', 1),
(8, 'EMIS-507', 'Management Information Systems', 2, NULL, 3.0, '', 1),
(9, 'EMIS-508', 'Business Analytics', 2, NULL, 3.0, '', 1),
(10, 'EMIS-509', 'System Analysis and Design', 2, NULL, 3.0, '', 1),
(11, 'EMIS-510', 'Database Management Systems', 2, NULL, 3.0, '', 1),
(12, 'EMIS-511', 'Strategic Management and Information Systems', 2, NULL, 3.0, '', 1),
(13, 'EMIS-512', 'Business Communication', 3, NULL, 3.0, '', 1),
(14, 'EMIS-513', 'Managerial Economics', 3, NULL, 3.0, '', 1),
(15, 'EMIS-514', 'Legal and Ethical Aspect of MIS', 3, NULL, 3.0, '', 1),
(16, 'EMIS-517', 'Operations and Supply Chain Management', 3, NULL, 3.0, '', 1),
(17, 'EMIS-518', 'Electronic Commerce', 3, NULL, 3.0, '', 1),
(18, 'EMIS-519', 'Business Research', 3, NULL, 3.0, '', 1),
(19, 'EMIS-522', 'Big Data and Data Mining', 3, NULL, 3.0, '', 1),
(20, 'EMIS-523', 'Electronic Banking and FinTech', 3, NULL, 3.0, '', 1),
(21, 'EMIS-524', 'Supply Chain Analytics', 3, NULL, 3.0, '', 1),
(22, 'EMIS-525', 'Project Management', 3, NULL, 3.0, '', 1),
(23, 'EMIS-528', 'Human Resource Information System', 3, NULL, 3.0, '', 1),
(24, 'EMIS-529', 'Artificial Intelligence and Machine Learning', 3, NULL, 3.0, '', 1),
(25, 'EMIS-530', 'Enterprise Resource Planning', 3, NULL, 3.0, '', 1),
(26, 'EMIS-532', 'Cyber Security and Block Chain Technology', 3, NULL, 3.0, '', 1),
(27, 'EMIS-533', 'Digital Marketing and CRM', 3, NULL, 3.0, '', 1),
(28, 'EMIS-527', 'Total Quality Management', 3, NULL, 3.0, '', 1),
(29, 'EMIS-515', 'Management Science', 3, NULL, 3.0, '', 1),
(30, 'EMIS-516', 'Simulation and Systems Modeling', 3, NULL, 3.0, '', 1),
(31, 'EMIS-520', 'Networking for Corporate Management Systems', 3, NULL, 3.0, '', 1),
(32, 'EMIS-521', 'Management Accounting and Control Systems', 3, NULL, 3.0, '', 1),
(33, 'EMIS-526', 'Knowledge Management', 3, NULL, 3.0, '', 1),
(34, 'EMIS-531', 'IT Policy and Strategy', 3, NULL, 3.0, '', 1),
(35, 'EMIS-534', 'Freelancing and Start-up Management', 3, NULL, 3.0, '', 1);

-- --------------------------------------------------------

--
-- Table structure for table `course_categories`
--

CREATE TABLE `course_categories` (
  `id` int UNSIGNED NOT NULL,
  `category_name` varchar(100) NOT NULL,
  `required_courses` tinyint UNSIGNED NOT NULL DEFAULT '0',
  `total_credit_hours` decimal(5,1) NOT NULL DEFAULT '0.0',
  `description` text,
  `is_active` tinyint(1) NOT NULL DEFAULT '1',
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

--
-- Dumping data for table `course_categories`
--

INSERT INTO `course_categories` (`id`, `category_name`, `required_courses`, `total_credit_hours`, `description`, `is_active`, `created_at`) VALUES
(1, 'Prerequisite', 6, 18.0, 'Prerequisite courses', 1, '2026-11-01 14:09:59'),
(2, 'Core', 5, 15.0, 'Mandatory core courses', 1, '2026-11-01 14:09:59'),
(3, 'Elective', 4, 12.0, 'Elective courses', 1, '2026-11-01 14:09:59'),
(4, 'Internship/Project/Thesis', 1, 3.0, 'Internship, Project or Thesis', 1, '2026-11-01 14:09:59');

-- --------------------------------------------------------

--
-- Table structure for table `course_offerings`
--

CREATE TABLE `course_offerings` (
  `id` int UNSIGNED NOT NULL,
  `course_id` int UNSIGNED NOT NULL,
  `semester_id` int UNSIGNED NOT NULL,
  `teacher_id` int UNSIGNED DEFAULT NULL,
  `capacity` int UNSIGNED NOT NULL DEFAULT '60',
  `registration_open` tinyint(1) NOT NULL DEFAULT '0'
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Dumping data for table `course_offerings`
--

INSERT INTO `course_offerings` (`id`, `course_id`, `semester_id`, `teacher_id`, `capacity`, `registration_open`) VALUES
(1, 1, 7, 3, 40, 0),
(2, 3, 7, 10, 30, 0),
(3, 4, 7, 6, 40, 0),
(4, 5, 7, 9, 30, 0),
(5, 6, 7, 13, 35, 0),
(6, 7, 7, 18, 32, 0),
(7, 8, 7, 4, 40, 0),
(8, 9, 7, 7, 45, 0),
(9, 10, 7, 1, 40, 0),
(10, 11, 7, 8, 30, 0),
(11, 12, 7, 2, 40, 0),
(12, 15, 7, 16, 40, 0),
(13, 28, 7, 12, 30, 0),
(14, 23, 7, 14, 30, 0),
(15, 24, 7, 5, 40, 0),
(16, 27, 7, 17, 40, 0),
(17, 17, 7, 11, 35, 0),
(18, 20, 7, 15, 40, 0);

-- --------------------------------------------------------

--
-- Table structure for table `course_registrations`
--

CREATE TABLE `course_registrations` (
  `id` int UNSIGNED NOT NULL,
  `student_id` int UNSIGNED NOT NULL,
  `offering_id` int UNSIGNED NOT NULL,
  `status` enum('registered','dropped','completed') COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'registered',
  `registered_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `course_schedules`
--

CREATE TABLE `course_schedules` (
  `id` int UNSIGNED NOT NULL,
  `offering_id` int UNSIGNED NOT NULL,
  `class_day` enum('Saturday','Sunday','Monday','Tuesday','Wednesday','Thursday','Friday') NOT NULL,
  `start_time` time NOT NULL,
  `end_time` time NOT NULL,
  `room` varchar(100) NOT NULL,
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

--
-- Dumping data for table `course_schedules`
--

INSERT INTO `course_schedules` (`id`, `offering_id`, `class_day`, `start_time`, `end_time`, `room`, `created_at`) VALUES
(1, 1, 'Saturday', '18:30:00', '21:30:00', '6056', '2026-11-01 18:19:39'),
(2, 5, 'Saturday', '17:30:00', '21:30:00', '504', '2026-11-03 16:06:06'),
(3, 11, 'Saturday', '18:30:00', '21:30:00', '4003', '2026-11-03 16:06:54'),
(4, 6, 'Sunday', '18:30:00', '21:30:00', '7001', '2026-11-03 16:08:10'),
(5, 10, 'Sunday', '18:30:00', '21:30:00', '4003', '2026-11-03 16:22:18'),
(6, 12, 'Sunday', '18:30:00', '21:30:00', '6056', '2026-11-03 16:24:21'),
(7, 7, 'Monday', '18:30:00', '21:30:00', '6056', '2026-11-03 16:24:57'),
(8, 15, 'Monday', '18:30:00', '21:30:00', '4003', '2026-11-03 16:25:23'),
(9, 16, 'Monday', '18:30:00', '21:30:00', '7001', '2026-11-03 16:25:49'),
(10, 3, 'Tuesday', '18:30:00', '21:30:00', '7001', '2026-11-03 16:27:07'),
(11, 8, 'Tuesday', '18:30:00', '21:30:00', '4003', '2026-11-03 16:27:37'),
(12, 17, 'Tuesday', '18:30:00', '21:30:00', '6056', '2026-11-03 16:28:03'),
(13, 4, 'Wednesday', '18:30:00', '21:30:00', '4003', '2026-11-03 16:28:42'),
(14, 9, 'Wednesday', '18:30:00', '21:30:00', '8055', '2026-11-03 16:29:15'),
(15, 18, 'Wednesday', '18:30:00', '21:30:00', '7001', '2026-11-03 16:29:41'),
(16, 2, 'Thursday', '18:30:00', '21:30:00', '6056', '2026-11-03 16:30:08'),
(17, 13, 'Thursday', '18:30:00', '21:30:00', '7001', '2026-11-03 16:30:36'),
(18, 14, 'Thursday', '18:30:00', '21:30:00', '4003', '2026-11-03 16:31:14');

-- --------------------------------------------------------

--
-- Table structure for table `fee_structures`
--

CREATE TABLE `fee_structures` (
  `id` int UNSIGNED NOT NULL,
  `semester_id` int UNSIGNED NOT NULL,
  `fee_name` varchar(120) COLLATE utf8mb4_unicode_ci NOT NULL,
  `amount` decimal(12,2) NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `marks`
--

CREATE TABLE `marks` (
  `id` bigint UNSIGNED NOT NULL,
  `assessment_id` int UNSIGNED NOT NULL,
  `registration_id` int UNSIGNED NOT NULL,
  `marks_obtained` decimal(7,2) NOT NULL,
  `entered_by` int UNSIGNED NOT NULL,
  `updated_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `notices`
--

CREATE TABLE `notices` (
  `id` int UNSIGNED NOT NULL,
  `title` varchar(200) COLLATE utf8mb4_unicode_ci NOT NULL,
  `body` text COLLATE utf8mb4_unicode_ci NOT NULL,
  `audience` enum('all','student','teacher','accounts','academic') COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'all',
  `published_by` int UNSIGNED NOT NULL,
  `published_at` datetime DEFAULT NULL,
  `is_published` tinyint(1) NOT NULL DEFAULT '0',
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `notifications`
--

CREATE TABLE `notifications` (
  `id` bigint UNSIGNED NOT NULL,
  `user_id` int UNSIGNED NOT NULL,
  `title` varchar(180) COLLATE utf8mb4_unicode_ci NOT NULL,
  `message` text COLLATE utf8mb4_unicode_ci NOT NULL,
  `read_at` datetime DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `payments`
--

CREATE TABLE `payments` (
  `id` bigint UNSIGNED NOT NULL,
  `invoice_id` bigint UNSIGNED NOT NULL,
  `amount` decimal(12,2) NOT NULL,
  `payment_method` varchar(50) COLLATE utf8mb4_unicode_ci NOT NULL,
  `transaction_reference` varchar(150) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `status` enum('pending','verified','rejected') COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'pending',
  `verified_by` int UNSIGNED DEFAULT NULL,
  `paid_at` datetime DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `results`
--

CREATE TABLE `results` (
  `id` int UNSIGNED NOT NULL,
  `registration_id` int UNSIGNED NOT NULL,
  `total_marks` decimal(7,2) DEFAULT NULL,
  `letter_grade` varchar(3) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `grade_point` decimal(3,2) DEFAULT NULL,
  `status` enum('draft','submitted','approved','published') COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'draft',
  `published_at` datetime DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `semesters`
--

CREATE TABLE `semesters` (
  `id` int UNSIGNED NOT NULL,
  `session_id` int UNSIGNED NOT NULL,
  `semester_name` varchar(100) COLLATE utf8mb4_unicode_ci NOT NULL,
  `start_date` date DEFAULT NULL,
  `end_date` date DEFAULT NULL,
  `registration_open` tinyint(1) NOT NULL DEFAULT '0',
  `status` enum('upcoming','active','completed') COLLATE utf8mb4_unicode_ci DEFAULT 'upcoming'
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Dumping data for table `semesters`
--

INSERT INTO `semesters` (`id`, `session_id`, `semester_name`, `start_date`, `end_date`, `registration_open`, `status`) VALUES
(4, 1, 'January-April 2026', '2026-01-01', '2026-04-30', 0, 'completed'),
(5, 1, 'May-August 2026', '2026-05-01', '2026-08-31', 0, 'completed'),
(6, 1, 'September-December 2026', '2026-09-01', '2026-12-31', 0, 'active'),
(7, 2, 'January–April 2027', '2027-01-01', '2027-04-30', 0, 'upcoming'),
(8, 2, 'May–August 2027', '2027-05-01', '2027-08-31', 0, 'upcoming'),
(9, 2, 'September–December 2027', '2027-09-01', '2027-12-31', 0, 'upcoming');

-- --------------------------------------------------------

--
-- Table structure for table `students`
--

CREATE TABLE `students` (
  `id` int UNSIGNED NOT NULL,
  `user_id` int UNSIGNED NOT NULL,
  `student_id` varchar(50) COLLATE utf8mb4_unicode_ci NOT NULL,
  `batch_id` int UNSIGNED DEFAULT NULL,
  `admission_semester_id` int UNSIGNED DEFAULT NULL,
  `phone` varchar(25) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `address` text COLLATE utf8mb4_unicode_ci,
  `admission_date` date DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Dumping data for table `students`
--

INSERT INTO `students` (`id`, `user_id`, `student_id`, `batch_id`, `admission_semester_id`, `phone`, `address`, `admission_date`) VALUES
(1, 3, '6260808032', 1, 4, NULL, NULL, NULL),
(2, 2, '6260808018', 1, 4, NULL, NULL, NULL),
(3, 4, '6260808021', 1, 4, NULL, NULL, NULL),
(4, 27, '6260808025', 1, 4, NULL, NULL, NULL),
(5, 28, '6260808031', 1, 4, NULL, NULL, NULL),
(6, 29, '6260808027', 1, 4, NULL, NULL, NULL),
(7, 30, '6260808036', 1, 4, NULL, NULL, NULL),
(8, 31, '6260808017', 1, 4, NULL, NULL, NULL),
(9, 32, '6260909016', 3, 5, NULL, NULL, NULL),
(10, 33, '6260909014', 3, 5, NULL, NULL, NULL),
(11, 34, '6260909011', 3, 5, NULL, NULL, NULL),
(12, 35, '6260909009', 3, 5, NULL, NULL, NULL),
(13, 36, '6260909035', 3, 5, NULL, NULL, NULL),
(14, 37, '6260909028', 3, 5, NULL, NULL, NULL),
(15, 38, '6260909026', 3, 5, NULL, NULL, NULL),
(16, 39, '6260909027', 3, 5, NULL, NULL, NULL);

-- --------------------------------------------------------

--
-- Table structure for table `student_invoices`
--

CREATE TABLE `student_invoices` (
  `id` bigint UNSIGNED NOT NULL,
  `student_id` int UNSIGNED NOT NULL,
  `semester_id` int UNSIGNED NOT NULL,
  `invoice_number` varchar(60) COLLATE utf8mb4_unicode_ci NOT NULL,
  `total_amount` decimal(12,2) NOT NULL DEFAULT '0.00',
  `due_date` date DEFAULT NULL,
  `status` enum('unpaid','partial','paid','overdue') COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'unpaid',
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `teachers`
--

CREATE TABLE `teachers` (
  `id` int UNSIGNED NOT NULL,
  `user_id` int UNSIGNED NOT NULL,
  `employee_id` varchar(50) COLLATE utf8mb4_unicode_ci NOT NULL,
  `designation` varchar(100) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `department` varchar(100) COLLATE utf8mb4_unicode_ci DEFAULT 'MIS',
  `phone` varchar(25) COLLATE utf8mb4_unicode_ci DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Dumping data for table `teachers`
--

INSERT INTO `teachers` (`id`, `user_id`, `employee_id`, `designation`, `department`, `phone`) VALUES
(1, 5, '0001', 'Professor & Chairman', 'MIS', ''),
(2, 6, '0002', 'Professor', 'MIS', ''),
(3, 7, '0003', 'Professor and Professional Masters Program Coordinator', 'MIS', ''),
(4, 8, '0004', 'Professor', 'MIS', ''),
(5, 9, '0005', 'Professor', 'MIS', ''),
(6, 10, '0006', 'Professor', 'MIS', ''),
(7, 11, '0007', 'Professor', 'MIS', ''),
(8, 12, '0008', 'Professor', 'MIS', ''),
(9, 13, '0009', 'Associate Professor', 'MIS', ''),
(10, 14, '0010', 'Associate Professor and Executive MBA Program Coordinator', 'MIS', ''),
(11, 15, '0011', 'Associate Professor', 'MIS', ''),
(12, 16, '0012', 'Associate Professor', 'MIS', ''),
(13, 17, '0013', 'Associate Professor', 'MIS', ''),
(14, 18, '0014', 'Associate Professor', 'MIS', ''),
(15, 19, '0015', 'Assistant Professor', 'MIS', ''),
(16, 20, '0016', 'Assistant Professor', 'MIS', ''),
(17, 21, '0017', 'Assistant Professor', 'MIS', ''),
(18, 22, '0018', 'Assistant Professor', 'MIS', ''),
(19, 23, '0019', 'Lecturer', 'MIS', ''),
(20, 24, '0020', 'Lecturer', 'MIS', ''),
(21, 25, '0021', 'Lecturer', 'MIS', ''),
(22, 26, '0022', 'Lecturer', 'MIS', '');

-- --------------------------------------------------------

--
-- Table structure for table `users`
--

CREATE TABLE `users` (
  `id` int UNSIGNED NOT NULL,
  `full_name` varchar(150) COLLATE utf8mb4_unicode_ci NOT NULL,
  `username` varchar(60) COLLATE utf8mb4_unicode_ci NOT NULL,
  `email` varchar(150) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `password_hash` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `role` enum('student','teacher','accounts','academic','admin') COLLATE utf8mb4_unicode_ci NOT NULL,
  `is_active` tinyint(1) NOT NULL DEFAULT '1',
  `last_login` datetime DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Dumping data for table `users`
--

INSERT INTO `users` (`id`, `full_name`, `username`, `email`, `password_hash`, `role`, `is_active`, `last_login`, `created_at`, `updated_at`) VALUES
(1, 'Demo Admin', 'demo.admin', NULL, '$2y$10$s/kjDwnpdTdKW1g67c4bcuEXTBDHDFiwkCsMOI22GSsbhSofdxzR6', 'admin', 1, '2026-11-15 21:22:48', '2026-10-18 16:55:53', '2026-11-15 15:22:48'),
(2, 'MD MUSTAKIN MAHI', 'Mustakin18', 'mahidumis18@gmail.com', '$2y$10$HaVxPjbLg7VvGaNaM60DeOrVanSDLLK32OYU77CuZWv9sO0si6zri', 'student', 1, '2026-11-13 22:20:15', '2026-10-18 17:53:58', '2026-11-13 16:20:15'),
(3, 'kazi Mahera Hoque', 'Mahera32', 'mahera32@gmail.com', '$2y$10$HHE4n3RcTZPR5ZohXFry0u99S/1WsH.WDuW6xaH5vfUclHr.jZGCa', 'student', 1, '2026-11-15 21:23:01', '2026-10-19 15:16:23', '2026-11-15 15:23:01'),
(4, 'Arpita Saha', 'Arpita21', 'arpita21@gmail.com', '$2y$10$N30KHl8ka7t6I/uE.mCPpu700dc4aoiz6HlldrSo/5zZwuOS79wZ6', 'student', 1, '2026-11-13 20:52:17', '2026-10-19 15:57:08', '2026-11-13 14:52:17'),
(5, 'Dr. Md. Rakibul Hoque', 'Rakibul.hoque', 'rakibul001@gmail.com', '$2y$10$V3xF6SbB2XTiGJ2jQoHVNOrjtbcqLn4cPzx1Go3sBFvJMxW8rpd5K', 'teacher', 1, '2026-11-15 21:24:24', '2026-10-19 16:34:08', '2026-11-15 15:24:24'),
(6, 'Dr. Md. Abdul Hannan Mia', 'abdul.hannan', 'abdul.hannan002@gmail.com', '$2y$10$XusQ7xnl7NPxOz.cMPMDrORyVdYdSt/tll.J/Go3yS8O9pYGwUXXa', 'teacher', 1, '2026-11-12 22:26:05', '2026-10-25 16:21:39', '2026-11-12 16:26:05'),
(7, 'Mohammad Moqbul Hossain Bhuiyan', 'moqbul.hossain', 'moqbul.hossain0003@gmail.com', '$2y$10$JwUoWh.EYTfeIqzOrFLZWe3vktYQp7eOuEm1.p25eGXK/QuUu2iBe', 'teacher', 1, NULL, '2026-10-25 17:53:36', '2026-10-25 17:53:36'),
(8, 'Md. Abul Kashem', 'abul.kashem', 'abul.kashem0004@gmail.com', '$2y$10$j0SVVVzuQPJ89goo9LRxWufFmX0poQ4.631wjFynJCLtG2EUroGEO', 'teacher', 1, NULL, '2026-10-27 16:05:39', '2026-10-27 16:05:39'),
(9, 'Dr. M. Helal Uddin Ahmed', 'helal.uddin', 'helal.uddin0005@gmail.com', '$2y$10$q9Bd8SC8p4u7KiRzmLt/JuTzKU/0ENO9gYXdcXfcFUpZRALbix/jC', 'teacher', 1, NULL, '2026-10-27 16:06:30', '2026-10-27 16:06:30'),
(10, 'Dr. Md. Akram Hossain', 'akram.hossain', 'akram.hossain0006@gmail.com', '$2y$10$CHm4qiar3Fpx4tSMoO3EweIV9V6OVzJDzvZcQ66vxsHwsLeHvP8/O', 'teacher', 1, NULL, '2026-10-27 16:07:04', '2026-10-27 16:07:04'),
(11, 'Dr. K. M. Salah Uddin', 'km.salahuddin', 'km.salahuddin0007@gmail.com', '$2y$10$LW2Q.qi2AvqDzjkkqw26FeiWtl6MEgqgCd2yJguEGM9PkSoi77YNi', 'teacher', 1, NULL, '2026-10-27 16:07:38', '2026-10-27 16:07:38'),
(12, 'Dr. Mohammad Anisur Rahman', 'anisur.rahman', 'anisur.rahman0008@gmail.com', '$2y$10$q5cXB4M03dzwDSx7U1etrOHJLV.QuQzKPChICYy50yEY6Bnxpz5QS', 'teacher', 1, NULL, '2026-10-27 16:08:08', '2026-10-27 16:08:08'),
(13, 'Dr. Ashis Talukder', 'ashis.talukder', 'ashis.talukder0009@gmail.com', '$2y$10$Vk13hld7cbJsk021WgOZpOuBopsoQo2UUTxgKBt4v/h2NSM1UxwFG', 'teacher', 1, NULL, '2026-10-27 16:08:42', '2026-10-27 16:08:42'),
(14, 'Mohammad Tariqul Islam', 'tariqul.islam', 'tariqul.islam0010@gmail.com', '$2y$10$7YvyaRmq4p/zQhY639MhIOFMR2CNJkJNfip.lvN/bUa8y/sidj.me', 'teacher', 1, NULL, '2026-10-27 16:09:23', '2026-10-27 16:09:23'),
(15, 'Dr. Nymatul Jannat Nipa', 'nymatul.nipa', 'nymatul.nipa0011@gmail.com', '$2y$10$m1QZ0aRhcNSm4O/3j4Sugu/zsUEK0PKKchL5uLlDDbn2nONqt7C4C', 'teacher', 1, NULL, '2026-10-27 16:10:33', '2026-10-27 16:10:33'),
(16, 'Farjana Parvin Chowdhury', 'farjana.parvin', 'farjana.parvin0012@gmail.com', '$2y$10$qvotX.odCHye9s8YBDOt3eayIY6F7VUoDORgofJzqqChcWBagcT6W', 'teacher', 1, NULL, '2026-10-27 16:11:58', '2026-10-27 16:11:58'),
(17, 'Jakia Sultana', 'jakia.sultana', 'jakia.sultana0013@gmail.com', '$2y$10$Gzzz7nwaRPGpOAcitGkLP.jyDdvGthJ8vcfk/VKEo.TYv75Bz57lK', 'teacher', 1, NULL, '2026-10-27 16:12:29', '2026-10-27 16:12:29'),
(18, 'Nehad Laila Sanju', 'nehad.laila', 'nehad.laila0014@gmail.com', '$2y$10$0U2tvdNwr7.Ku3kVgFfx6em6qSCm2W6GZqUizQ.CGDCoxAjg3salC', 'teacher', 1, NULL, '2026-10-27 16:13:09', '2026-10-27 16:13:09'),
(19, 'Abu Naser Mohammad Saif', 'mohammad.saif', 'mohammad.saif0015@gmail.com', '$2y$10$hSvPFZdKZK6Fnak/S7TgCeMBSTc0938UK8/Uz17kE9oTP4SzBczb.', 'teacher', 1, NULL, '2026-10-27 16:13:36', '2026-10-27 16:13:36'),
(20, 'Md. Fahami Ahsan Mazmum', 'fahami.ahsan', 'fahami.ahsan0016@gmail.com', '$2y$10$3w/Me6F5MaHkibNsd2Vyr.tE2z0CvB5FzPkhCuEkMb6HMNGmIczz.', 'teacher', 1, NULL, '2026-10-27 16:17:38', '2026-10-27 16:17:38'),
(21, 'Hamida Akhter', 'hamida.akhter', 'hamida.akhter0017@gmail.com', '$2y$10$MLf4xxlhe0QC0HZN6jWIEeO8EFoqi1u85KpQsUkGHFcJ2TlFhpdQK', 'teacher', 1, NULL, '2026-10-27 16:18:08', '2026-10-27 16:18:08'),
(22, 'Jahangir Alam', 'jahangir.alam', 'jahangir.alam0018@gmail.com', '$2y$10$7/DuWjqRjozrzXE5J1vRHe5/MtghjExDuTIAGwiRiFQoYoB0NX7OK', 'teacher', 1, NULL, '2026-10-27 16:18:34', '2026-10-27 16:18:34'),
(23, 'Nahida Sultana', 'nahida.sultana', 'nahida.sultana0019@gmail.com', '$2y$10$qycNLcjAgUXp/e/y8AFilead4WQ1ILnHaEzdypdJluSZxHaV6wO9a', 'teacher', 1, NULL, '2026-10-27 16:19:12', '2026-10-27 16:19:12'),
(24, 'Marzia Tamanna', 'marzia.tamanna', 'marzia.tamanna0020@gmail.com', '$2y$10$zdBlULZzEKE2E9/7N5A5NOTA4V5wlv.j8yxiX7kP5cxBnGbvL02e.', 'teacher', 1, NULL, '2026-10-27 16:19:38', '2026-10-27 16:19:38'),
(25, 'Rasheda Akter Rupa', 'rasheda.rupa', 'rasheda.rupa0021@gmail.com', '$2y$10$8cKkc/lFMlFPlXDdEbyG4eLqbwxWGulWRAAh4TRjnHzix7GWaUk8y', 'teacher', 1, NULL, '2026-10-27 16:20:04', '2026-10-27 16:20:04'),
(26, 'Most. Sadia Akter', 'sadia.akter', 'sadia.akter0022@gmail.com', '$2y$10$Z3O4hwbt8.2dGAvZnSYSEODFQYC9Fj/ccbuU8Zg/J2.nepf/Y3x..', 'teacher', 1, NULL, '2026-10-27 16:20:59', '2026-10-27 16:20:59'),
(27, 'Maisha Hoque', 'Maisha25', NULL, '$2y$10$yzKem3QGG28mHXeX1tmhquhNsnzz2ftEz8aGTvuJHU1jH296ts0X2', 'student', 1, NULL, '2026-10-27 18:06:50', '2026-10-27 18:06:50'),
(28, 'Ramzan Ali', 'Ramzan31', NULL, '$2y$10$a0ognz5/Hq9j6euZNPqw2O/i1FeHa4ISmSSi2/mPziSe1CfXhRcqm', 'student', 1, NULL, '2026-10-27 18:07:37', '2026-10-27 18:07:37'),
(29, 'Tamim Bin Azam', 'Tamim27', NULL, '$2y$10$BoR2UQcuNcmLnbOzKRe7iuy1YEIFSMqtSGb7bP9sAahOjZquFDLve', 'student', 1, NULL, '2026-10-27 18:09:25', '2026-10-27 18:09:25'),
(30, 'Shejuti Shithi Biswas', 'Shejuti36', NULL, '$2y$10$rirkEmYkZxa3LdYcSvDENed0gIcrd.dlJR/NJh8ieJsP.Wbdnr8C6', 'student', 1, NULL, '2026-10-27 18:10:05', '2026-10-27 18:10:05'),
(31, 'Meehnaaz Basit', 'Meehnaaz17', NULL, '$2y$10$HVHAN2IX0sy26PpoGoewzOdqpREno9FpODRwmlFORldWtGKIdPJie', 'student', 1, NULL, '2026-10-27 18:10:32', '2026-10-27 18:10:32'),
(32, 'Fiana Nilhat Faruquee', 'Fiana16', NULL, '$2y$10$kZ6IosnvZEi85PEexYj3zed5MQ3lynNVlpGpgZh0.EQs4XqOGGSW6', 'student', 1, NULL, '2026-10-27 18:12:06', '2026-10-27 18:12:06'),
(33, 'Aoungkyakhai Marma', 'AKMarma14', NULL, '$2y$10$OxHBA9KrWk0PvUBJrxfbBO3G6ImNq3cjK5IufcZ3mbXjapYo7M3Be', 'student', 1, '2026-11-10 21:34:10', '2026-10-27 18:12:45', '2026-11-10 15:34:10'),
(34, 'Tasmia Karim Hridy', 'TasmiaHridy11', NULL, '$2y$10$pG1tcj9lNgzC.vnDIKjn3eF5s/U9ovvHUlGKI1nn8PNv.neGl92uO', 'student', 1, NULL, '2026-10-30 16:24:50', '2026-10-30 16:24:50'),
(35, 'Md Delower Hossen Sumon', 'Delower09', NULL, '$2y$10$E5QB4nqgE9kTNVRuPK2mdeTyMUyPK79k8sUErcuuJQIf7mzsPZFy.', 'student', 1, NULL, '2026-10-30 16:28:50', '2026-10-30 16:28:50'),
(36, 'Faria Hossain Niti', 'Faria.Niti35', NULL, '$2y$10$HJWZAsc8Ag1FbR0/qqExfu1jD3G18VO3zF6OgujrtKbgQaDMRYHCy', 'student', 1, NULL, '2026-10-30 16:46:30', '2026-10-30 16:46:30'),
(37, 'Prokriti Hasan Rodushi', 'Prokriti28', NULL, '$2y$10$HZj1lQ7WbKZLA/z8.m4N0OKecrAxEgb2Pb57NoTnCEfD8RQmzjI/G', 'student', 1, NULL, '2026-10-30 16:47:10', '2026-10-30 16:47:10'),
(38, 'Mubashir Zahid', 'Mubashir26', NULL, '$2y$10$8nMxTmLm4hYknttCrRH9Y.NZjluMzmXceRTzKsuAZ9Eyu3RUK2R8m', 'student', 1, NULL, '2026-10-30 16:47:43', '2026-10-30 16:47:43'),
(39, 'Atkia Fairuz', 'Fairuz27', NULL, '$2y$10$Z2HPerwWMWF7hVV/nIFWP.44wgs0SmtriS0hVU1G0m0//EAUuWcuq', 'student', 1, NULL, '2026-11-15 15:19:00', '2026-11-15 15:19:00');

--
-- Indexes for dumped tables
--

--
-- Indexes for table `academic_sessions`
--
ALTER TABLE `academic_sessions`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `session_name` (`session_name`);

--
-- Indexes for table `assessments`
--
ALTER TABLE `assessments`
  ADD PRIMARY KEY (`id`),
  ADD KEY `offering_id` (`offering_id`);

--
-- Indexes for table `attendance`
--
ALTER TABLE `attendance`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `uq_registration_date` (`registration_id`,`class_date`),
  ADD KEY `recorded_by` (`recorded_by`);

--
-- Indexes for table `audit_logs`
--
ALTER TABLE `audit_logs`
  ADD PRIMARY KEY (`id`),
  ADD KEY `user_id` (`user_id`);

--
-- Indexes for table `batches`
--
ALTER TABLE `batches`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `batch_name` (`batch_name`);

--
-- Indexes for table `courses`
--
ALTER TABLE `courses`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `course_code` (`course_code`),
  ADD KEY `idx_courses_teacher_id` (`teacher_id`),
  ADD KEY `idx_courses_category_id` (`category_id`);

--
-- Indexes for table `course_categories`
--
ALTER TABLE `course_categories`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `category_name` (`category_name`);

--
-- Indexes for table `course_offerings`
--
ALTER TABLE `course_offerings`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `uq_course_offering` (`course_id`,`semester_id`),
  ADD KEY `semester_id` (`semester_id`),
  ADD KEY `teacher_id` (`teacher_id`);

--
-- Indexes for table `course_registrations`
--
ALTER TABLE `course_registrations`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `uq_student_offering` (`student_id`,`offering_id`),
  ADD KEY `offering_id` (`offering_id`);

--
-- Indexes for table `course_schedules`
--
ALTER TABLE `course_schedules`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `unique_offering_schedule` (`offering_id`);

--
-- Indexes for table `fee_structures`
--
ALTER TABLE `fee_structures`
  ADD PRIMARY KEY (`id`),
  ADD KEY `semester_id` (`semester_id`);

--
-- Indexes for table `marks`
--
ALTER TABLE `marks`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `uq_assessment_registration` (`assessment_id`,`registration_id`),
  ADD KEY `registration_id` (`registration_id`),
  ADD KEY `entered_by` (`entered_by`);

--
-- Indexes for table `notices`
--
ALTER TABLE `notices`
  ADD PRIMARY KEY (`id`),
  ADD KEY `published_by` (`published_by`);

--
-- Indexes for table `notifications`
--
ALTER TABLE `notifications`
  ADD PRIMARY KEY (`id`),
  ADD KEY `user_id` (`user_id`);

--
-- Indexes for table `payments`
--
ALTER TABLE `payments`
  ADD PRIMARY KEY (`id`),
  ADD KEY `invoice_id` (`invoice_id`),
  ADD KEY `verified_by` (`verified_by`);

--
-- Indexes for table `results`
--
ALTER TABLE `results`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `registration_id` (`registration_id`);

--
-- Indexes for table `semesters`
--
ALTER TABLE `semesters`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `uq_session_semester` (`session_id`,`semester_name`);

--
-- Indexes for table `students`
--
ALTER TABLE `students`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `user_id` (`user_id`),
  ADD UNIQUE KEY `student_id` (`student_id`),
  ADD KEY `batch_id` (`batch_id`),
  ADD KEY `idx_students_admission_semester` (`admission_semester_id`);

--
-- Indexes for table `student_invoices`
--
ALTER TABLE `student_invoices`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `invoice_number` (`invoice_number`),
  ADD KEY `student_id` (`student_id`),
  ADD KEY `semester_id` (`semester_id`);

--
-- Indexes for table `teachers`
--
ALTER TABLE `teachers`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `user_id` (`user_id`),
  ADD UNIQUE KEY `employee_id` (`employee_id`);

--
-- Indexes for table `users`
--
ALTER TABLE `users`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `username` (`username`),
  ADD UNIQUE KEY `email` (`email`);

--
-- AUTO_INCREMENT for dumped tables
--

--
-- AUTO_INCREMENT for table `academic_sessions`
--
ALTER TABLE `academic_sessions`
  MODIFY `id` int UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=3;

--
-- AUTO_INCREMENT for table `assessments`
--
ALTER TABLE `assessments`
  MODIFY `id` int UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `attendance`
--
ALTER TABLE `attendance`
  MODIFY `id` bigint UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `audit_logs`
--
ALTER TABLE `audit_logs`
  MODIFY `id` bigint UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `batches`
--
ALTER TABLE `batches`
  MODIFY `id` int UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=4;

--
-- AUTO_INCREMENT for table `courses`
--
ALTER TABLE `courses`
  MODIFY `id` int UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=36;

--
-- AUTO_INCREMENT for table `course_categories`
--
ALTER TABLE `course_categories`
  MODIFY `id` int UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=5;

--
-- AUTO_INCREMENT for table `course_offerings`
--
ALTER TABLE `course_offerings`
  MODIFY `id` int UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=19;

--
-- AUTO_INCREMENT for table `course_registrations`
--
ALTER TABLE `course_registrations`
  MODIFY `id` int UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `course_schedules`
--
ALTER TABLE `course_schedules`
  MODIFY `id` int UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=19;

--
-- AUTO_INCREMENT for table `fee_structures`
--
ALTER TABLE `fee_structures`
  MODIFY `id` int UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `marks`
--
ALTER TABLE `marks`
  MODIFY `id` bigint UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `notices`
--
ALTER TABLE `notices`
  MODIFY `id` int UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `notifications`
--
ALTER TABLE `notifications`
  MODIFY `id` bigint UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `payments`
--
ALTER TABLE `payments`
  MODIFY `id` bigint UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `results`
--
ALTER TABLE `results`
  MODIFY `id` int UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `semesters`
--
ALTER TABLE `semesters`
  MODIFY `id` int UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=10;

--
-- AUTO_INCREMENT for table `students`
--
ALTER TABLE `students`
  MODIFY `id` int UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=17;

--
-- AUTO_INCREMENT for table `student_invoices`
--
ALTER TABLE `student_invoices`
  MODIFY `id` bigint UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `teachers`
--
ALTER TABLE `teachers`
  MODIFY `id` int UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=23;

--
-- AUTO_INCREMENT for table `users`
--
ALTER TABLE `users`
  MODIFY `id` int UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=40;

--
-- Constraints for dumped tables
--

--
-- Constraints for table `assessments`
--
ALTER TABLE `assessments`
  ADD CONSTRAINT `assessments_ibfk_1` FOREIGN KEY (`offering_id`) REFERENCES `course_offerings` (`id`);

--
-- Constraints for table `attendance`
--
ALTER TABLE `attendance`
  ADD CONSTRAINT `attendance_ibfk_1` FOREIGN KEY (`registration_id`) REFERENCES `course_registrations` (`id`),
  ADD CONSTRAINT `attendance_ibfk_2` FOREIGN KEY (`recorded_by`) REFERENCES `users` (`id`);

--
-- Constraints for table `audit_logs`
--
ALTER TABLE `audit_logs`
  ADD CONSTRAINT `audit_logs_ibfk_1` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE SET NULL;

--
-- Constraints for table `courses`
--
ALTER TABLE `courses`
  ADD CONSTRAINT `fk_courses_category` FOREIGN KEY (`category_id`) REFERENCES `course_categories` (`id`) ON DELETE RESTRICT ON UPDATE CASCADE,
  ADD CONSTRAINT `fk_courses_teacher` FOREIGN KEY (`teacher_id`) REFERENCES `teachers` (`id`) ON DELETE SET NULL ON UPDATE CASCADE;

--
-- Constraints for table `course_offerings`
--
ALTER TABLE `course_offerings`
  ADD CONSTRAINT `course_offerings_ibfk_1` FOREIGN KEY (`course_id`) REFERENCES `courses` (`id`),
  ADD CONSTRAINT `course_offerings_ibfk_2` FOREIGN KEY (`semester_id`) REFERENCES `semesters` (`id`),
  ADD CONSTRAINT `course_offerings_ibfk_3` FOREIGN KEY (`teacher_id`) REFERENCES `teachers` (`id`) ON DELETE SET NULL;

--
-- Constraints for table `course_registrations`
--
ALTER TABLE `course_registrations`
  ADD CONSTRAINT `course_registrations_ibfk_1` FOREIGN KEY (`student_id`) REFERENCES `students` (`id`),
  ADD CONSTRAINT `course_registrations_ibfk_2` FOREIGN KEY (`offering_id`) REFERENCES `course_offerings` (`id`);

--
-- Constraints for table `course_schedules`
--
ALTER TABLE `course_schedules`
  ADD CONSTRAINT `fk_schedule_offering` FOREIGN KEY (`offering_id`) REFERENCES `course_offerings` (`id`) ON DELETE CASCADE ON UPDATE CASCADE;

--
-- Constraints for table `fee_structures`
--
ALTER TABLE `fee_structures`
  ADD CONSTRAINT `fee_structures_ibfk_1` FOREIGN KEY (`semester_id`) REFERENCES `semesters` (`id`);

--
-- Constraints for table `marks`
--
ALTER TABLE `marks`
  ADD CONSTRAINT `marks_ibfk_1` FOREIGN KEY (`assessment_id`) REFERENCES `assessments` (`id`),
  ADD CONSTRAINT `marks_ibfk_2` FOREIGN KEY (`registration_id`) REFERENCES `course_registrations` (`id`),
  ADD CONSTRAINT `marks_ibfk_3` FOREIGN KEY (`entered_by`) REFERENCES `users` (`id`);

--
-- Constraints for table `notices`
--
ALTER TABLE `notices`
  ADD CONSTRAINT `notices_ibfk_1` FOREIGN KEY (`published_by`) REFERENCES `users` (`id`);

--
-- Constraints for table `notifications`
--
ALTER TABLE `notifications`
  ADD CONSTRAINT `notifications_ibfk_1` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE;

--
-- Constraints for table `payments`
--
ALTER TABLE `payments`
  ADD CONSTRAINT `payments_ibfk_1` FOREIGN KEY (`invoice_id`) REFERENCES `student_invoices` (`id`),
  ADD CONSTRAINT `payments_ibfk_2` FOREIGN KEY (`verified_by`) REFERENCES `users` (`id`) ON DELETE SET NULL;

--
-- Constraints for table `results`
--
ALTER TABLE `results`
  ADD CONSTRAINT `results_ibfk_1` FOREIGN KEY (`registration_id`) REFERENCES `course_registrations` (`id`);

--
-- Constraints for table `semesters`
--
ALTER TABLE `semesters`
  ADD CONSTRAINT `semesters_ibfk_1` FOREIGN KEY (`session_id`) REFERENCES `academic_sessions` (`id`);

--
-- Constraints for table `students`
--
ALTER TABLE `students`
  ADD CONSTRAINT `fk_students_admission_semester` FOREIGN KEY (`admission_semester_id`) REFERENCES `semesters` (`id`) ON DELETE RESTRICT ON UPDATE CASCADE,
  ADD CONSTRAINT `students_ibfk_1` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`),
  ADD CONSTRAINT `students_ibfk_2` FOREIGN KEY (`batch_id`) REFERENCES `batches` (`id`) ON DELETE SET NULL;

--
-- Constraints for table `student_invoices`
--
ALTER TABLE `student_invoices`
  ADD CONSTRAINT `student_invoices_ibfk_1` FOREIGN KEY (`student_id`) REFERENCES `students` (`id`),
  ADD CONSTRAINT `student_invoices_ibfk_2` FOREIGN KEY (`semester_id`) REFERENCES `semesters` (`id`);

--
-- Constraints for table `teachers`
--
ALTER TABLE `teachers`
  ADD CONSTRAINT `teachers_ibfk_1` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`);
COMMIT;

/*!40101 SET CHARACTER_SET_CLIENT=@OLD_CHARACTER_SET_CLIENT */;
/*!40101 SET CHARACTER_SET_RESULTS=@OLD_CHARACTER_SET_RESULTS */;
/*!40101 SET COLLATION_CONNECTION=@OLD_COLLATION_CONNECTION */;
