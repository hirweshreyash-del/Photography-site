<?php
// config.php - System Configuration & Database Migration for Nadkarphotohub
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// 1. Simple Environment Variables (.env) Loader
function load_env($filePath) {
    if (!file_exists($filePath)) {
        return;
    }
    $lines = file($filePath, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    foreach ($lines as $line) {
        $line = trim($line);
        if (empty($line) || strpos($line, '#') === 0) {
            continue;
        }
        if (strpos($line, '=') !== false) {
            list($key, $val) = explode('=', $line, 2);
            $key = trim($key);
            $val = trim($val);
            // Strip outer quotes
            if ((str_starts_with($val, '"') && str_ends_with($val, '"')) ||
                (str_starts_with($val, "'") && str_ends_with($val, "'"))) {
                $val = substr($val, 1, -1);
            }
            if (!isset($_ENV[$key])) {
                $_ENV[$key] = $val;
                putenv("$key=$val");
            }
        }
    }
}

// Load .env
load_env(__DIR__ . '/.env');

// Helper to retrieve environment variables safely
function env($key, $default = null) {
    if (isset($_ENV[$key])) {
        return $_ENV[$key];
    }
    $val = getenv($key);
    return ($val !== false) ? $val : $default;
}

// 2. Database Connection (PDO)
$host   = env('DB_HOST', 'localhost');
$user   = env('DB_USER', 'root');
$pass   = env('DB_PASS', ''); // Default XAMPP MySQL has blank password
$dbname = env('DB_NAME', 'nadkarphotohub');

$conn = null;
try {
    $conn = new PDO("mysql:host=$host;dbname=$dbname;charset=utf8mb4", $user, $pass);
    $conn->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
} catch (PDOException $e) {
    // If database does not exist, create it automatically
    try {
        $conn = new PDO("mysql:host=$host;charset=utf8mb4", $user, $pass);
        $conn->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $conn->exec("CREATE DATABASE IF NOT EXISTS `$dbname` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
        $conn->exec("USE `$dbname`");
    } catch (PDOException $ex) {
        die("Database Connection Error: " . $ex->getMessage());
    }
}

// 3. Database Schema Auto-Migration
if ($conn) {
    try {
        // Users Table
        $conn->exec("CREATE TABLE IF NOT EXISTS `users` (
            `id` INT AUTO_INCREMENT PRIMARY KEY,
            `full_name` VARCHAR(100) NOT NULL,
            `email` VARCHAR(100) NOT NULL UNIQUE,
            `phone` VARCHAR(20) NOT NULL,
            `password` VARCHAR(255) NOT NULL,
            `role` VARCHAR(20) NOT NULL DEFAULT 'customer',
            `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;");

        // Ensure 'role' column exists in users
        $chkRole = $conn->query("SHOW COLUMNS FROM `users` LIKE 'role'")->fetchAll();
        if (empty($chkRole)) {
            $conn->exec("ALTER TABLE `users` ADD COLUMN `role` VARCHAR(20) NOT NULL DEFAULT 'customer' AFTER `password`");
        }

        // Bookings Table
        $conn->exec("CREATE TABLE IF NOT EXISTS `bookings` (
            `id` INT AUTO_INCREMENT PRIMARY KEY,
            `user_id` INT NOT NULL,
            `event_type` VARCHAR(100) NOT NULL,
            `event_date` DATE NOT NULL,
            `location` VARCHAR(255) NOT NULL,
            `notes` TEXT NULL,
            `package_price` DECIMAL(10,2) NOT NULL DEFAULT 0.00,
            `additional_services_fee` DECIMAL(10,2) NOT NULL DEFAULT 0.00,
            `discount_amount` DECIMAL(10,2) NOT NULL DEFAULT 0.00,
            `tax_amount` DECIMAL(10,2) NOT NULL DEFAULT 0.00,
            `total_amount` DECIMAL(10,2) NOT NULL DEFAULT 0.00,
            `payment_status` ENUM('Pending','Paid','Failed','Refunded') NOT NULL DEFAULT 'Pending',
            `status` VARCHAR(50) NOT NULL DEFAULT 'Pending',
            `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            FOREIGN KEY (`user_id`) REFERENCES `users`(`id`) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;");

        // Check and migrate columns on bookings table
        $chkBookings = $conn->query("SHOW COLUMNS FROM `bookings` LIKE 'total_amount'")->fetchAll();
        if (empty($chkBookings)) {
            $conn->exec("ALTER TABLE `bookings` ADD COLUMN `package_price` DECIMAL(10,2) NOT NULL DEFAULT 0.00 AFTER `notes`");
            $conn->exec("ALTER TABLE `bookings` ADD COLUMN `additional_services_fee` DECIMAL(10,2) NOT NULL DEFAULT 0.00 AFTER `package_price`");
            $conn->exec("ALTER TABLE `bookings` ADD COLUMN `discount_amount` DECIMAL(10,2) NOT NULL DEFAULT 0.00 AFTER `additional_services_fee`");
            $conn->exec("ALTER TABLE `bookings` ADD COLUMN `tax_amount` DECIMAL(10,2) NOT NULL DEFAULT 0.00 AFTER `discount_amount`");
            $conn->exec("ALTER TABLE `bookings` ADD COLUMN `total_amount` DECIMAL(10,2) NOT NULL DEFAULT 0.00 AFTER `tax_amount`");
            $conn->exec("ALTER TABLE `bookings` ADD COLUMN `payment_status` ENUM('Pending','Paid','Failed','Refunded') NOT NULL DEFAULT 'Pending' AFTER `total_amount`");
        }

        // Payments Table
        $conn->exec("CREATE TABLE IF NOT EXISTS `payments` (
            `id` INT AUTO_INCREMENT PRIMARY KEY,
            `booking_id` INT NOT NULL,
            `user_id` INT NOT NULL,
            `razorpay_order_id` VARCHAR(100) NULL,
            `razorpay_payment_id` VARCHAR(100) NULL,
            `razorpay_signature` VARCHAR(255) NULL,
            `transaction_id` VARCHAR(100) NOT NULL UNIQUE,
            `amount` DECIMAL(10,2) NOT NULL,
            `currency` VARCHAR(10) NOT NULL DEFAULT 'INR',
            `payment_method` VARCHAR(50) NOT NULL DEFAULT 'Razorpay',
            `payment_status` ENUM('Pending','Paid','Failed','Refunded') NOT NULL DEFAULT 'Pending',
            `payment_response` TEXT NULL,
            `failure_reason` VARCHAR(255) NULL,
            `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            FOREIGN KEY (`booking_id`) REFERENCES `bookings`(`id`) ON DELETE CASCADE,
            FOREIGN KEY (`user_id`) REFERENCES `users`(`id`) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;");

        // Invoices Table
        $conn->exec("CREATE TABLE IF NOT EXISTS `invoices` (
            `id` INT AUTO_INCREMENT PRIMARY KEY,
            `invoice_number` VARCHAR(50) NOT NULL UNIQUE,
            `booking_id` INT NOT NULL UNIQUE,
            `payment_id` INT NOT NULL UNIQUE,
            `user_id` INT NOT NULL,
            `subtotal` DECIMAL(10,2) NOT NULL,
            `discount` DECIMAL(10,2) NOT NULL DEFAULT 0.00,
            `tax_rate` DECIMAL(5,2) NOT NULL DEFAULT 18.00,
            `tax_amount` DECIMAL(10,2) NOT NULL DEFAULT 0.00,
            `total_amount` DECIMAL(10,2) NOT NULL,
            `invoice_date` DATE NOT NULL,
            `pdf_path` VARCHAR(255) NULL,
            `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            FOREIGN KEY (`booking_id`) REFERENCES `bookings`(`id`) ON DELETE CASCADE,
            FOREIGN KEY (`payment_id`) REFERENCES `payments`(`id`) ON DELETE CASCADE,
            FOREIGN KEY (`user_id`) REFERENCES `users`(`id`) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;");

        // 4. Auto-Seed Default Administrator Account if not exists
        $admin_email = env('ADMIN_EMAIL', 'admin@nadkarphotohub.com');
        $admin_check = $conn->prepare("SELECT id FROM users WHERE email = :email LIMIT 1");
        $admin_check->execute(['email' => $admin_email]);
        if (!$admin_check->fetch()) {
            $admin_pass_hash = password_hash(env('ADMIN_DEFAULT_PASSWORD', 'Admin@123'), PASSWORD_BCRYPT);
            $seed_admin = $conn->prepare("INSERT INTO users (full_name, email, phone, password, role) VALUES (:name, :email, :phone, :pass, 'admin')");
            $seed_admin->execute([
                'name'  => env('ADMIN_NAME', 'Studio Administrator'),
                'email' => $admin_email,
                'phone' => env('ADMIN_PHONE', '9876543210'),
                'pass'  => $admin_pass_hash
            ]);
        }
    } catch (PDOException $e) {
        error_log("Schema migration error: " . $e->getMessage());
    }
}

// 5. Photography Package Pricing Helper
function get_event_pricing($event_type) {
    $packages = [
        'Wedding Photography' => [
            'base_price' => 25000.00,
            'desc'       => 'Complete wedding ritual coverage, candid photography, traditional portraits, and cinematic 4K video.'
        ],
        'Pre-Wedding Shoot' => [
            'base_price' => 15000.00,
            'desc'       => 'Outdoor scenic romantic photoshoot at beach, fort, or garden with teaser video and drone footage.'
        ],
        'Birthday Party' => [
            'base_price' => 8000.00,
            'desc'       => 'High-energy celebration coverage, cake-cutting ceremony, family candid moments, and digital album.'
        ],
        'Corporate Event' => [
            'base_price' => 12000.00,
            'desc'       => 'Official executive coverage, keynote speeches, team seminars, corporate portraits, and event recap.'
        ],
        'Baby & Family Shoot' => [
            'base_price' => 6000.00,
            'desc'       => 'Newborn baby props photoshoot, maternity session, and studio family portrait session.'
        ]
    ];

    if (isset($packages[$event_type])) {
        return $packages[$event_type];
    }
    return [
        'base_price' => 10000.00,
        'desc'       => 'Custom photography & videography shoot session.'
    ];
}

// 6. Authentication & Role Helpers
function is_logged_in() {
    return isset($_SESSION['user_id']);
}

function is_admin() {
    return isset($_SESSION['role']) && $_SESSION['role'] === 'admin';
}

function require_login() {
    if (!is_logged_in()) {
        header("Location: login.php");
        exit;
    }
}

function require_admin() {
    if (!is_logged_in() || !is_admin()) {
        header("Location: login.php?error=admin_unauthorized");
        exit;
    }
}
?>