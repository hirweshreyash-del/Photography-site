<?php
// login.php - User & Administrator Login Page
require_once 'config.php';

$error_msg = "";
$success_msg = "";

// Check if user submitted the login form
if ($_SERVER["REQUEST_METHOD"] == "POST") {
    $email    = trim($_POST['email']);
    $password = trim($_POST['password']);

    if (empty($email) || empty($password)) {
        $error_msg = "Please enter both Email and Password.";
    } else {
        // Query to find user by email
        $stmt = $conn->prepare("SELECT * FROM users WHERE email = :email");
        $stmt->execute(['email' => $email]);
        $user = $stmt->fetch(PDO::FETCH_ASSOC);

        if ($user && password_verify($password, $user['password'])) {
            // Login Success: Save user info and role in Session
            $_SESSION['user_id']    = $user['id'];
            $_SESSION['user_name']  = $user['full_name'];
            $_SESSION['user_email'] = $user['email'];
            $_SESSION['role']       = $user['role'] ?? 'customer';

            if ($_SESSION['role'] === 'admin') {
                header("Location: admin_dashboard.php");
            } else {
                header("Location: index.php?msg=login_success");
            }
            exit;
        } else {
            $error_msg = "Invalid Email or Password!";
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Login - Nadkarphotohub</title>
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
                <a href="signup.php" class="btn-secondary">Sign Up</a>
            </nav>
        </div>
    </header>

    <div class="auth-wrapper">
        <div class="auth-box">
            <h2>User & Admin Login</h2>
            <p>Welcome back to Nadkarphotohub</p>

            <?php if (!empty($error_msg)): ?>
                <div class="alert error"><?php echo htmlspecialchars($error_msg); ?></div>
            <?php endif; ?>

            <?php if (isset($_GET['msg']) && $_GET['msg'] == 'registered'): ?>
                <div class="alert success">Account created successfully! Please login below.</div>
            <?php endif; ?>

            <?php if (isset($_GET['error']) && $_GET['error'] == 'admin_unauthorized'): ?>
                <div class="alert error">Administrator access required. Please login with an admin account.</div>
            <?php endif; ?>

            <form action="login.php" method="POST">
                <div class="form-group">
                    <label>Email Address:</label>
                    <input type="email" name="email" placeholder="Enter your email" required>
                </div>

                <div class="form-group">
                    <label>Password:</label>
                    <input type="password" name="password" placeholder="Enter your password" required>
                </div>

                <button type="submit" class="btn-primary" style="width: 100%;">Login</button>
            </form>

            <div style="margin-top: 20px; background: #f8fafc; border: 1px dashed #cbd5e1; border-radius: 6px; padding: 12px; font-size: 0.8rem; color: #64748b; line-height: 1.5;">
                <strong>Demo Accounts:</strong><br>
                👤 <strong>Client:</strong> student@gmail.com / <code>password123</code><br>
                🛡️ <strong>Admin:</strong> admin@nadkarphotohub.com / <code>Admin@123</code>
            </div>

            <p class="auth-footer">
                Don't have an account? <a href="signup.php">Create Account / Sign Up</a>
            </p>
        </div>
    </div>

</body>
</html>