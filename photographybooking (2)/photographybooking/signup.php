<?php
// signup.php - User Registration Page
require_once 'config.php';

$error_msg = "";
$success_msg = "";

// Check if user submitted registration form
if ($_SERVER["REQUEST_METHOD"] == "POST") {
    $full_name = trim($_POST['full_name']);
    $email     = trim($_POST['email']);
    $phone     = trim($_POST['phone']);
    $password  = $_POST['password'];
    $confirm_p = $_POST['confirm_password'];

    // Validation
    if (empty($full_name) || empty($email) || empty($phone) || empty($password)) {
        $error_msg = "Please fill in all fields.";
    } elseif ($password !== $confirm_p) {
        $error_msg = "Passwords do not match!";
    } elseif (strlen($password) < 6) {
        $error_msg = "Password must be at least 6 characters long.";
    } else {
        // Check if email already exists in database
        $stmt = $conn->prepare("SELECT id FROM users WHERE email = :email");
        $stmt->execute(['email' => $email]);

        if ($stmt->fetch()) {
            $error_msg = "Email is already registered! Please login instead.";
        } else {
            // Secure Password Hashing
            $hashed_password = password_hash($password, PASSWORD_BCRYPT);

            // Insert new user into database
            $insert_stmt = $conn->prepare("INSERT INTO users (full_name, email, phone, password, role) VALUES (:name, :email, :phone, :password, 'customer')");
            $result = $insert_stmt->execute([
                'name'     => $full_name,
                'email'    => $email,
                'phone'    => $phone,
                'password' => $hashed_password
            ]);

            if ($result) {
                header("Location: login.php?msg=registered");
                exit;
            } else {
                $error_msg = "Registration failed. Please try again.";
            }
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Sign Up - Nadkarphotohub</title>
    <link rel="stylesheet" href="css/style.css">
</head>
<body>

    <!-- Header Navigation -->
    <header class="navbar">
        <div class="container nav-container">
            <a href="index.php" class="logo">📷 Nadkarphotohub</a>
            <nav>
                <a href="index.php">Home</a>
                <a href="index.php#events">Events</a>
                <a href="login.php" class="btn-secondary">Login</a>
            </nav>
        </div>
    </header>

    <div class="auth-wrapper">
        <div class="auth-box">
            <h2>User Registration</h2>
            <p>Create your Nadkarphotohub account</p>

            <?php if (!empty($error_msg)): ?>
                <div class="alert error"><?php echo htmlspecialchars($error_msg); ?></div>
            <?php endif; ?>

            <form action="signup.php" method="POST">
                <div class="form-group">
                    <label>Full Name:</label>
                    <input type="text" name="full_name" placeholder="Enter your full name" required>
                </div>

                <div class="form-group">
                    <label>Email Address:</label>
                    <input type="email" name="email" placeholder="Enter your email" required>
                </div>

                <div class="form-group">
                    <label>Phone Number:</label>
                    <input type="tel" name="phone" placeholder="Enter your 10-digit mobile number" required>
                </div>

                <div class="form-group">
                    <label>Password:</label>
                    <input type="password" name="password" placeholder="Create a password" required>
                </div>

                <div class="form-group">
                    <label>Confirm Password:</label>
                    <input type="password" name="confirm_password" placeholder="Confirm your password" required>
                </div>

                <button type="submit" class="btn-primary" style="width: 100%;">Sign Up</button>
            </form>

            <p class="auth-footer">
                Already have an account? <a href="login.php">Login here</a>
            </p>
        </div>
    </div>

</body>
</html>
