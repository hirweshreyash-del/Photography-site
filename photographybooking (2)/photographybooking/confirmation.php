<?php
// confirmation.php - Payment Success & Booking Confirmation Page
require_once __DIR__ . '/config.php';
require_login();

$user_id    = $_SESSION['user_id'];
$user_name  = $_SESSION['user_name'] ?? 'Client';
$booking_id = intval($_GET['booking_id'] ?? 0);

if ($booking_id <= 0) {
    header("Location: my_bookings.php");
    exit;
}

// Fetch booking & verify user ownership
$stmt = $conn->prepare("
    SELECT b.*, u.full_name, u.email, u.phone 
    FROM bookings b 
    JOIN users u ON b.user_id = u.id 
    WHERE b.id = :bid AND (b.user_id = :uid OR :is_admin = 1) 
    LIMIT 1
");
$stmt->execute([
    'bid'      => $booking_id,
    'uid'      => $user_id,
    'is_admin' => is_admin() ? 1 : 0
]);
$booking = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$booking) {
    header("Location: my_bookings.php");
    exit;
}

// Fetch invoice and payment
$inv_stmt = $conn->prepare("SELECT * FROM invoices WHERE booking_id = :bid LIMIT 1");
$inv_stmt->execute(['bid' => $booking_id]);
$invoice = $inv_stmt->fetch(PDO::FETCH_ASSOC);

$pay_stmt = $conn->prepare("SELECT * FROM payments WHERE booking_id = :bid ORDER BY id DESC LIMIT 1");
$pay_stmt->execute(['bid' => $booking_id]);
$payment = $pay_stmt->fetch(PDO::FETCH_ASSOC);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Booking Confirmed - Nadkarphotohub</title>
    <link rel="stylesheet" href="css/style.css">
    <style>
        .confirmation-wrapper {
            padding: 50px 0 80px 0;
            max-width: 780px;
            margin: 0 auto;
        }
        .success-hero-card {
            background: #fff;
            border-radius: 10px;
            box-shadow: 0 4px 20px rgba(0,0,0,0.08);
            border-top: 6px solid #27ae60;
            padding: 40px;
            text-align: center;
        }
        .success-icon-badge {
            width: 75px;
            height: 75px;
            background: #dcfce7;
            color: #16a34a;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 2.5rem;
            margin: 0 auto 20px auto;
        }
        .confirmation-title {
            color: #1a2b4c;
            font-size: 1.8rem;
            margin-bottom: 8px;
        }
        .confirmation-subtitle {
            color: #64748b;
            font-size: 1rem;
            margin-bottom: 30px;
        }

        .summary-box {
            background: #f8fafc;
            border: 1px solid #e2e8f0;
            border-radius: 8px;
            padding: 24px;
            text-align: left;
            margin-bottom: 30px;
        }
        .summary-grid {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 15px;
        }
        @media (max-width: 600px) {
            .summary-grid {
                grid-template-columns: 1fr;
            }
        }
        .sum-item {
            display: flex;
            flex-direction: column;
            gap: 4px;
        }
        .sum-label {
            font-size: 0.8rem;
            color: #64748b;
            text-transform: uppercase;
            font-weight: 600;
        }
        .sum-value {
            font-size: 1rem;
            color: #1e293b;
            font-weight: 700;
        }

        .action-button-row {
            display: flex;
            justify-content: center;
            gap: 15px;
            flex-wrap: wrap;
        }
        .btn-download-inv {
            background: #27ae60;
            color: #fff;
            padding: 12px 24px;
            border-radius: 6px;
            font-weight: bold;
            font-size: 1rem;
            display: inline-flex;
            align-items: center;
            gap: 8px;
            transition: all 0.2s;
        }
        .btn-download-inv:hover {
            background: #219150;
        }
        .btn-print-inv {
            background: #1a2b4c;
            color: #fff;
            padding: 12px 24px;
            border-radius: 6px;
            font-weight: bold;
            font-size: 1rem;
            display: inline-flex;
            align-items: center;
            gap: 8px;
            transition: all 0.2s;
        }
        .btn-print-inv:hover {
            background: #2c3e50;
        }
    </style>
</head>
<body>

    <!-- Header Navbar -->
    <header class="navbar">
        <div class="container nav-container">
            <a href="index.php" class="logo">📷 Nadkarphotohub</a>
            <nav>
                <a href="index.php">Home</a>
                <a href="index.php#events">Events</a>
                <a href="my_bookings.php" class="btn-secondary">My Bookings</a>
                <span class="user-badge">Hi, <?php echo htmlspecialchars($user_name); ?></span>
                <a href="logout.php" class="btn-danger">Logout</a>
            </nav>
        </div>
    </header>

    <div class="container confirmation-wrapper">
        
        <div class="success-hero-card">
            
            <div class="success-icon-badge">✓</div>
            <h1 class="confirmation-title">Payment Confirmed!</h1>
            <p class="confirmation-subtitle">
                Thank you, <strong><?php echo htmlspecialchars($booking['full_name']); ?></strong>! Your photography session has been confirmed and locked on our schedule.
            </p>

            <!-- Reservation & Invoice Summary -->
            <div class="summary-box">
                <div class="summary-grid">
                    <div class="sum-item">
                        <span class="sum-label">Booking Reference:</span>
                        <span class="sum-value" style="color: #0284c7;">#NPH-<?php echo $booking['id']; ?></span>
                    </div>
                    <div class="sum-item">
                        <span class="sum-label">Official Invoice Number:</span>
                        <span class="sum-value" style="color: #16a34a; font-family: monospace;">
                            <?php echo htmlspecialchars($invoice['invoice_number'] ?? 'INV-' . date('Y') . '-' . str_pad($booking['id'], 4, '0', STR_PAD_LEFT)); ?>
                        </span>
                    </div>
                    <div class="sum-item">
                        <span class="sum-label">Photography Package:</span>
                        <span class="sum-value">📷 <?php echo htmlspecialchars($booking['event_type']); ?></span>
                    </div>
                    <div class="sum-item">
                        <span class="sum-label">Scheduled Event Date:</span>
                        <span class="sum-value">📅 <?php echo date('d F, Y', strtotime($booking['event_date'])); ?></span>
                    </div>
                    <div class="sum-item">
                        <span class="sum-label">Venue Location:</span>
                        <span class="sum-value">📍 <?php echo htmlspecialchars($booking['location']); ?></span>
                    </div>
                    <div class="sum-item">
                        <span class="sum-label">Amount Paid:</span>
                        <span class="sum-value" style="color: #166534; font-size: 1.15rem;">
                            ₹<?php echo number_format(floatval($booking['total_amount']), 2); ?>
                        </span>
                    </div>
                    <div class="sum-item">
                        <span class="sum-label">Payment Transaction ID:</span>
                        <span class="sum-value" style="font-size: 0.88rem; font-family: monospace;">
                            <?php echo htmlspecialchars($payment['transaction_id'] ?? 'pay_verified'); ?>
                        </span>
                    </div>
                    <div class="sum-item">
                        <span class="sum-label">Payment Status:</span>
                        <span class="sum-value" style="color: #16a34a;">
                            ✓ Verified & Confirmed
                        </span>
                    </div>
                </div>
            </div>

            <!-- Action Buttons: Download PDF Invoice -->
            <div class="action-button-row">
                <a href="download_invoice.php?booking_id=<?php echo $booking['id']; ?>" class="btn-download-inv">
                    📥 Download PDF Invoice
                </a>
                <a href="download_invoice.php?booking_id=<?php echo $booking['id']; ?>&inline=1" target="_blank" class="btn-print-inv">
                    🖨️ View & Print Invoice
                </a>
            </div>

            <div style="margin-top: 35px; border-top: 1px dashed #cbd5e1; padding-top: 20px; display: flex; justify-content: center; gap: 20px;">
                <a href="my_bookings.php" class="btn-secondary">View All My Bookings</a>
                <a href="index.php#booking" class="btn-secondary">+ Book Another Shoot</a>
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