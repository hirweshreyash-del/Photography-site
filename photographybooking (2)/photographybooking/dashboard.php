<?php
require_once 'config.php';
require_login();

// If admin visits dashboard.php, redirect to admin_dashboard.php
if (is_admin()) {
    header("Location: admin_dashboard.php");
    exit;
}

$user_id = $_SESSION['user_id'];

// Fetch user profile
$u_stmt = $conn->prepare("SELECT * FROM users WHERE id = :uid");
$u_stmt->execute(['uid' => $user_id]);
$u_info = $u_stmt->fetch(PDO::FETCH_ASSOC);

$full_name = $u_info['full_name'] ?? $_SESSION['user_name'] ?? 'Client';
$email     = $u_info['email'] ?? $_SESSION['user_email'] ?? '';
$phone     = $u_info['phone'] ?? 'Not provided';

// Fetch user's bookings
$b_stmt = $conn->prepare("SELECT * FROM bookings WHERE user_id = :uid ORDER BY id DESC");
$b_stmt->execute(['uid' => $user_id]);
$bookings = $b_stmt->fetchAll(PDO::FETCH_ASSOC);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>My Account & Bookings | Nadkarphotohub</title>
    <link rel="stylesheet" href="css/style.css">
    <style>
        .dashboard-container {
            padding-top: 40px;
            padding-bottom: 80px;
            min-height: 85vh;
        }
        .dashboard-grid {
            display: grid;
            grid-template-columns: 320px 1fr;
            gap: 30px;
        }
        @media (max-width: 992px) {
            .dashboard-grid {
                grid-template-columns: 1fr;
            }
        }
        .profile-card {
            background: #fff;
            border: 1px solid #e2e8f0;
            border-radius: 8px;
            padding: 30px;
            text-align: center;
            box-shadow: 0 4px 15px rgba(0,0,0,0.05);
        }
        .profile-avatar-large {
            width: 80px;
            height: 80px;
            border-radius: 50%;
            background: #1a2b4c;
            color: #f1c40f;
            font-size: 2.2rem;
            font-weight: 800;
            display: flex;
            align-items: center;
            justify-content: center;
            margin: 0 auto 18px auto;
        }
        .profile-name {
            font-size: 1.35rem;
            color: #1a2b4c;
            margin-bottom: 4px;
        }
        .profile-email {
            color: #718096;
            font-size: 0.9rem;
            margin-bottom: 20px;
        }
        .profile-details-list {
            list-style: none;
            text-align: left;
            border-top: 1px solid #edf2f7;
            padding-top: 15px;
            margin-bottom: 20px;
        }
        .profile-details-list li {
            padding: 8px 0;
            display: flex;
            justify-content: space-between;
            font-size: 0.9rem;
            border-bottom: 1px solid #f1f5f9;
        }
        .profile-details-list .label {
            color: #64748b;
        }
        .profile-details-list .value {
            color: #1e293b;
            font-weight: 600;
        }
        .dashboard-content-card {
            background: #fff;
            border: 1px solid #e2e8f0;
            border-radius: 8px;
            padding: 30px;
            box-shadow: 0 4px 15px rgba(0,0,0,0.05);
        }
        .dash-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 25px;
            border-bottom: 1px solid #edf2f7;
            padding-bottom: 15px;
        }
        .dash-title {
            font-size: 1.4rem;
            color: #1a2b4c;
        }
        .booking-table {
            width: 100%;
            border-collapse: collapse;
            text-align: left;
        }
        .booking-table th {
            background: #1a2b4c;
            color: #fff;
            font-weight: 600;
            font-size: 0.85rem;
            text-transform: uppercase;
            padding: 12px 14px;
        }
        .booking-table td {
            padding: 14px;
            border-bottom: 1px solid #edf2f7;
            font-size: 0.9rem;
            color: #334155;
        }
        .btn-table-pay {
            background: #27ae60;
            color: #fff;
            padding: 5px 10px;
            border-radius: 4px;
            font-size: 0.8rem;
            font-weight: bold;
            display: inline-block;
        }
        .btn-table-inv {
            background: #1a2b4c;
            color: #fff;
            padding: 5px 10px;
            border-radius: 4px;
            font-size: 0.8rem;
            font-weight: bold;
            display: inline-block;
        }
    </style>
</head>
<body>

    <!-- Header Navigation -->
    <header class="navbar">
        <div class="container nav-container">
            <a href="index.php" class="logo">📷 Nadkarphotohub</a>
            <nav>
                <a href="index.php">Home</a>
                <a href="index.php#events">Events</a>
                <a href="index.php#booking">Book Shoot</a>
                <a href="my_bookings.php" class="btn-secondary">My Bookings</a>
                <span class="user-badge">Hi, <?php echo htmlspecialchars($full_name); ?></span>
                <a href="logout.php" class="btn-danger">Logout</a>
            </nav>
        </div>
    </header>

    <div class="container dashboard-container">
        <div class="dashboard-grid">
            
            <!-- Left Column: User Profile -->
            <div class="profile-card">
                <div class="profile-avatar-large">
                    <?php echo strtoupper(substr($full_name, 0, 1)); ?>
                </div>
                <h2 class="profile-name"><?php echo htmlspecialchars($full_name); ?></h2>
                <p class="profile-email"><?php echo htmlspecialchars($email); ?></p>

                <ul class="profile-details-list">
                    <li>
                        <span class="label">Phone:</span>
                        <span class="value"><?php echo htmlspecialchars($phone); ?></span>
                    </li>
                    <li>
                        <span class="label">Account Status:</span>
                        <span class="value" style="color: #16a34a;">Active Client</span>
                    </li>
                    <li>
                        <span class="label">Member Since:</span>
                        <span class="value"><?php echo date('M Y'); ?></span>
                    </li>
                </ul>

                <a href="index.php#booking" class="btn-primary" style="width: 100%; text-align: center; margin-bottom: 10px; display: inline-block;">
                    + Book New Event Shoot
                </a>
                <a href="logout.php" class="btn-danger" style="width: 100%; text-align: center; display: inline-block;">
                    Sign Out
                </a>
            </div>

            <!-- Right Column: My Booked Events -->
            <div class="dashboard-content-card">
                <div class="dash-header">
                    <div>
                        <h2 class="dash-title">My Event Shoots & Invoices</h2>
                        <p style="color: #64748b; font-size: 0.9rem; margin-top: 4px;">Track your upcoming photography sessions with Nadkarphotohub</p>
                    </div>
                </div>

                <?php if (count($bookings) === 0): ?>
                    <div class="alert info">
                        No bookings found. <a href="index.php#booking" style="font-weight: bold; color: #0056b3;">Click here to book an event shoot!</a>
                    </div>
                <?php else: ?>
                    <div style="overflow-x: auto;">
                        <table class="booking-table">
                            <thead>
                                <tr>
                                    <th>Ref ID</th>
                                    <th>Event Type</th>
                                    <th>Scheduled Date</th>
                                    <th>Amount</th>
                                    <th>Payment</th>
                                    <th>Status</th>
                                    <th>Action</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($bookings as $b): ?>
                                    <tr>
                                        <td><strong>#NPH-<?php echo $b['id']; ?></strong></td>
                                        <td><strong><?php echo htmlspecialchars($b['event_type']); ?></strong></td>
                                        <td>📅 <?php echo date('d M, Y', strtotime($b['event_date'])); ?></td>
                                        <td>₹<?php echo number_format(floatval($b['total_amount']), 2); ?></td>
                                        <td>
                                            <?php if ($b['payment_status'] === 'Paid'): ?>
                                                <span class="badge-status-paid" style="background: #dcfce7; color: #166534; padding: 3px 8px; border-radius: 10px; font-weight: bold; font-size: 0.8rem;">Paid</span>
                                            <?php else: ?>
                                                <span class="badge-status-pending" style="background: #fef3c7; color: #92400e; padding: 3px 8px; border-radius: 10px; font-weight: bold; font-size: 0.8rem;">Pending</span>
                                            <?php endif; ?>
                                        </td>
                                        <td>
                                            <span class="status-badge" style="background: <?php echo ($b['status'] === 'Confirmed') ? '#27ae60' : '#f39c12'; ?>;">
                                                <?php echo htmlspecialchars($b['status']); ?>
                                            </span>
                                        </td>
                                        <td>
                                            <?php if ($b['payment_status'] === 'Paid'): ?>
                                                <a href="download_invoice.php?booking_id=<?php echo $b['id']; ?>" class="btn-table-inv">📥 Invoice</a>
                                            <?php else: ?>
                                                <a href="checkout.php?booking_id=<?php echo $b['id']; ?>" class="btn-table-pay">💳 Pay Now</a>
                                            <?php endif; ?>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                <?php endif; ?>
            </div>

        </div>
    </div>

    <!-- Footer -->
    <footer class="footer">
        <div class="container">
            <p>&copy; <?php echo date('Y'); ?> Nadkarphotohub - Event Photography Booking. Simple Student Web Project (PHP & MySQL).</p>
        </div>
    </footer>

</body>
</html>