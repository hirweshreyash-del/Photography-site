<?php
// my_bookings.php - Customer Shoot Reservations & Payment History Page
require_once 'config.php';
require_login();

$user_id   = $_SESSION['user_id'];
$user_name = $_SESSION['user_name'] ?? 'Client';

// Fetch user's bookings from database
$stmt = $conn->prepare("SELECT * FROM bookings WHERE user_id = :uid ORDER BY id DESC");
$stmt->execute(['uid' => $user_id]);
$bookings = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Fetch user's payment transaction history
$pay_stmt = $conn->prepare("
    SELECT p.*, b.event_type, b.event_date 
    FROM payments p 
    JOIN bookings b ON p.booking_id = b.id 
    WHERE p.user_id = :uid 
    ORDER BY p.id DESC
");
$pay_stmt->execute(['uid' => $user_id]);
$payments = $pay_stmt->fetchAll(PDO::FETCH_ASSOC);

// Calculate quick stats
$total_bookings = count($bookings);
$paid_count     = 0;
$pending_count  = 0;

foreach ($bookings as &$b) {
    if (floatval($b['total_amount']) <= 0) {
        $p = get_event_pricing($b['event_type']);
        $b['package_price'] = $p['base_price'];
        $b['tax_amount']    = round(($p['base_price'] * 18) / 100, 2);
        $b['total_amount']  = $b['package_price'] + $b['tax_amount'];
    }

    if ($b['payment_status'] === 'Paid') {
        $paid_count++;
    } elseif ($b['payment_status'] === 'Pending' || $b['payment_status'] === 'Failed') {
        $pending_count++;
    }
}
unset($b);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>My Bookings & Payments - Nadkarphotohub</title>
    <link rel="stylesheet" href="css/style.css">
    <style>
        .stats-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(220px, 1fr));
            gap: 20px;
            margin-bottom: 30px;
        }
        .stat-card {
            background: #fff;
            padding: 22px;
            border-radius: 8px;
            box-shadow: 0 2px 10px rgba(0,0,0,0.06);
            border-left: 5px solid #1a2b4c;
        }
        .stat-card.stat-green {
            border-left-color: #27ae60;
        }
        .stat-card.stat-amber {
            border-left-color: #f59e0b;
        }
        .stat-number {
            font-size: 2rem;
            font-weight: 800;
            color: #1a2b4c;
        }
        .stat-label {
            font-size: 0.85rem;
            color: #64748b;
            text-transform: uppercase;
            font-weight: 600;
            margin-top: 4px;
        }

        .action-btns {
            display: flex;
            gap: 6px;
            flex-wrap: wrap;
        }
        .btn-pay {
            background: #27ae60;
            color: #fff;
            padding: 6px 12px;
            border-radius: 4px;
            font-size: 0.82rem;
            font-weight: bold;
            display: inline-block;
            transition: all 0.2s;
        }
        .btn-pay:hover {
            background: #219150;
        }
        .btn-invoice {
            background: #1a2b4c;
            color: #fff;
            padding: 6px 12px;
            border-radius: 4px;
            font-size: 0.82rem;
            font-weight: bold;
            display: inline-block;
            transition: all 0.2s;
        }
        .btn-invoice:hover {
            background: #2c3e50;
        }

        .badge-status-paid {
            background: #dcfce7;
            color: #15803d;
            padding: 4px 10px;
            border-radius: 12px;
            font-size: 0.8rem;
            font-weight: bold;
            display: inline-block;
        }
        .badge-status-pending {
            background: #fef3c7;
            color: #92400e;
            padding: 4px 10px;
            border-radius: 12px;
            font-size: 0.8rem;
            font-weight: bold;
            display: inline-block;
        }
        .badge-status-failed {
            background: #fee2e2;
            color: #b91c1c;
            padding: 4px 10px;
            border-radius: 12px;
            font-size: 0.8rem;
            font-weight: bold;
            display: inline-block;
        }
        .badge-status-refunded {
            background: #e0f2fe;
            color: #0369a1;
            padding: 4px 10px;
            border-radius: 12px;
            font-size: 0.8rem;
            font-weight: bold;
            display: inline-block;
        }

        .nav-tabs-wrapper {
            display: flex;
            border-bottom: 2px solid #e2e8f0;
            margin-bottom: 25px;
            gap: 15px;
        }
        .tab-btn {
            background: none;
            border: none;
            padding: 12px 18px;
            font-size: 1rem;
            font-weight: bold;
            color: #64748b;
            cursor: pointer;
            border-bottom: 3px solid transparent;
            margin-bottom: -2px;
            transition: all 0.2s;
        }
        .tab-btn:hover {
            color: #1a2b4c;
        }
        .tab-btn.active {
            color: #1a2b4c;
            border-bottom-color: #27ae60;
        }
        .tab-panel {
            display: none;
        }
        .tab-panel.active {
            display: block;
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
                <a href="index.php#booking">Book Shoot</a>
                <?php if (is_admin()): ?>
                    <a href="admin_dashboard.php" class="btn-primary" style="background: #f1c40f; color: #1a2b4c;">Admin Panel</a>
                <?php endif; ?>
                <span class="user-badge">Hi, <?php echo htmlspecialchars($user_name); ?></span>
                <a href="logout.php" class="btn-danger">Logout</a>
            </nav>
        </div>
    </header>

    <div class="container section">
        
        <h2 class="section-title">My Event Bookings & Payment History</h2>
        <p style="text-align: center; color: #555; margin-bottom: 30px;">
            Track your shoot reservations, complete online payments, and download official PDF tax invoices.
        </p>

        <!-- Metrics Stats Grid -->
        <div class="stats-grid">
            <div class="stat-card">
                <div class="stat-number"><?php echo $total_bookings; ?></div>
                <div class="stat-label">Total Reservations</div>
            </div>
            <div class="stat-card stat-green">
                <div class="stat-number"><?php echo $paid_count; ?></div>
                <div class="stat-label">Paid & Confirmed Shoots</div>
            </div>
            <div class="stat-card stat-amber">
                <div class="stat-number"><?php echo $pending_count; ?></div>
                <div class="stat-label">Pending Payments</div>
            </div>
        </div>

        <?php if ($pending_count > 0): ?>
            <div class="alert info" style="margin-bottom: 25px;">
                💡 <strong>Action Required:</strong> You have <strong><?php echo $pending_count; ?></strong> shoot booking(s) awaiting payment. Click <strong>Pay Now</strong> below to secure your date with Razorpay.
            </div>
        <?php endif; ?>

        <!-- Tabs -->
        <div class="nav-tabs-wrapper">
            <button type="button" class="tab-btn active" onclick="switchSection('bookings')">
                📷 Event Shoot Bookings (<?php echo $total_bookings; ?>)
            </button>
            <button type="button" class="tab-btn" onclick="switchSection('payments')">
                💳 Payment Transactions History (<?php echo count($payments); ?>)
            </button>
        </div>

        <!-- Tab 1: Bookings List -->
        <div class="tab-panel active" id="panel-bookings">
            <?php if (count($bookings) == 0): ?>
                <div class="alert info" style="text-align: center; max-width: 600px; margin: 30px auto;">
                    You haven't submitted any event bookings yet! <br><br>
                    <a href="index.php#booking" class="btn-primary">Book an Event Shoot Now</a>
                </div>
            <?php else: ?>
                <div style="overflow-x: auto;">
                    <table class="simple-table">
                        <thead>
                            <tr>
                                <th>Booking Ref</th>
                                <th>Photography Package</th>
                                <th>Scheduled Date</th>
                                <th>Venue Location</th>
                                <th>Total (incl. GST)</th>
                                <th>Payment Status</th>
                                <th>Shoot Status</th>
                                <th>Invoice & Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($bookings as $b): ?>
                                <tr>
                                    <td><strong>#NPH-<?php echo $b['id']; ?></strong></td>
                                    <td><strong><?php echo htmlspecialchars($b['event_type']); ?></strong></td>
                                    <td>📅 <?php echo date('d M, Y', strtotime($b['event_date'])); ?></td>
                                    <td>📍 <?php echo htmlspecialchars($b['location']); ?></td>
                                    <td>
                                        <strong>₹<?php echo number_format(floatval($b['total_amount']), 2); ?></strong>
                                    </td>
                                    <td>
                                        <?php if ($b['payment_status'] === 'Paid'): ?>
                                            <span class="badge-status-paid">✓ Paid</span>
                                        <?php elseif ($b['payment_status'] === 'Pending'): ?>
                                            <span class="badge-status-pending">⏳ Pending</span>
                                        <?php elseif ($b['payment_status'] === 'Failed'): ?>
                                            <span class="badge-status-failed">✗ Failed</span>
                                        <?php elseif ($b['payment_status'] === 'Refunded'): ?>
                                            <span class="badge-status-refunded">↩ Refunded</span>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <?php if ($b['status'] === 'Confirmed'): ?>
                                            <span style="color: #166534; font-weight: bold;">✓ Confirmed</span>
                                        <?php else: ?>
                                            <span style="color: #d97706; font-weight: bold;">⏳ Pending</span>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <div class="action-btns">
                                            <?php if ($b['payment_status'] === 'Paid'): ?>
                                                <a href="download_invoice.php?booking_id=<?php echo $b['id']; ?>" class="btn-invoice">
                                                    📥 Download PDF
                                                </a>
                                                <a href="download_invoice.php?booking_id=<?php echo $b['id']; ?>&inline=1" target="_blank" class="btn-invoice" style="background: #2563eb;">
                                                    🖨️ View
                                                </a>
                                            <?php else: ?>
                                                <a href="checkout.php?booking_id=<?php echo $b['id']; ?>" class="btn-pay">
                                                    💳 Pay Now
                                                </a>
                                            <?php endif; ?>
                                        </div>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php endif; ?>
        </div>

        <!-- Tab 2: Payment Transactions History -->
        <div class="tab-panel" id="panel-payments">
            <?php if (count($payments) == 0): ?>
                <div class="alert info" style="text-align: center; max-width: 600px; margin: 30px auto;">
                    No payment transactions recorded yet.
                </div>
            <?php else: ?>
                <div style="overflow-x: auto;">
                    <table class="simple-table">
                        <thead>
                            <tr>
                                <th>Transaction Ref</th>
                                <th>Booking Ref</th>
                                <th>Photography Package</th>
                                <th>Amount Paid</th>
                                <th>Payment Method</th>
                                <th>Status</th>
                                <th>Date & Time</th>
                                <th>Invoice</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($payments as $p): ?>
                                <tr>
                                    <td><code style="font-size: 0.85rem;"><?php echo htmlspecialchars($p['transaction_id']); ?></code></td>
                                    <td><strong>#NPH-<?php echo $p['booking_id']; ?></strong></td>
                                    <td><?php echo htmlspecialchars($p['event_type']); ?></td>
                                    <td><strong>₹<?php echo number_format(floatval($p['amount']), 2); ?></strong></td>
                                    <td><?php echo htmlspecialchars($p['payment_method']); ?></td>
                                    <td>
                                        <?php if ($p['payment_status'] === 'Paid'): ?>
                                            <span class="badge-status-paid">✓ Success</span>
                                        <?php elseif ($p['payment_status'] === 'Pending'): ?>
                                            <span class="badge-status-pending">⏳ Pending</span>
                                        <?php elseif ($p['payment_status'] === 'Failed'): ?>
                                            <span class="badge-status-failed">✗ Failed</span>
                                        <?php elseif ($p['payment_status'] === 'Refunded'): ?>
                                            <span class="badge-status-refunded">↩ Refunded</span>
                                        <?php endif; ?>
                                    </td>
                                    <td><?php echo date('d M Y, h:i A', strtotime($p['created_at'])); ?></td>
                                    <td>
                                        <?php if ($p['payment_status'] === 'Paid'): ?>
                                            <a href="download_invoice.php?booking_id=<?php echo $p['booking_id']; ?>" class="btn-invoice">
                                                📥 Invoice
                                            </a>
                                        <?php else: ?>
                                            <span style="color: #94a3b8; font-size: 0.85rem;">N/A</span>
                                        <?php endif; ?>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php endif; ?>
        </div>

        <div style="text-align: center; margin-top: 35px; display: flex; justify-content: center; gap: 15px;">
            <a href="index.php" class="btn-secondary">← Back to Home Page</a>
            <a href="index.php#booking" class="btn-primary">+ Book Another Shoot</a>
        </div>

    </div>

    <!-- Footer -->
    <footer class="footer">
        <div class="container">
            <p>&copy; <?php echo date('Y'); ?> Nadkarphotohub - Event Photography Booking. Simple Student Web Project (PHP & MySQL).</p>
        </div>
    </footer>

    <script>
        function switchSection(tabName) {
            document.querySelectorAll('.tab-btn').forEach(b => b.classList.remove('active'));
            document.querySelectorAll('.tab-panel').forEach(p => p.classList.remove('active'));

            event.currentTarget.classList.add('active');
            document.getElementById('panel-' + tabName).classList.add('active');
        }
    </script>

</body>
</html>