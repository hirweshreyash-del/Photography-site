<?php
// admin_dashboard.php - Administrator Payment & Invoice Management Dashboard
require_once 'config.php';
require_admin();

$admin_id   = $_SESSION['user_id'];
$admin_name = $_SESSION['user_name'] ?? 'Administrator';

$msg = "";
$err = "";

// 1. Handle Admin Action: Process Refund
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'refund_payment') {
    $pay_id = intval($_POST['payment_id'] ?? 0);
    $refund_reason = trim($_POST['refund_reason'] ?? 'Customer cancellation requested');

    if ($pay_id > 0) {
        $find_p = $conn->prepare("SELECT * FROM payments WHERE id = :id LIMIT 1");
        $find_p->execute(['id' => $pay_id]);
        $target_pay = $find_p->fetch(PDO::FETCH_ASSOC);

        if ($target_pay && $target_pay['payment_status'] === 'Paid') {
            $upd_pay = $conn->prepare("UPDATE payments SET payment_status = 'Refunded', failure_reason = :reason WHERE id = :id");
            $upd_pay->execute(['reason' => 'Refunded: ' . $refund_reason, 'id' => $pay_id]);

            $upd_book = $conn->prepare("UPDATE bookings SET payment_status = 'Refunded', status = 'Cancelled' WHERE id = :bid");
            $upd_book->execute(['bid' => $target_pay['booking_id']]);

            $msg = "Transaction #{$target_pay['transaction_id']} successfully refunded and booking status updated.";
        } else {
            $err = "Invalid transaction or transaction is not eligible for refund.";
        }
    }
}

// 2. Fetch Dashboard Statistics
$rev_stmt = $conn->query("
    SELECT 
        COALESCE(SUM(CASE WHEN payment_status = 'Paid' THEN amount ELSE 0 END), 0) AS total_revenue,
        COUNT(CASE WHEN payment_status = 'Paid' THEN 1 END) AS count_paid,
        COUNT(CASE WHEN payment_status = 'Pending' THEN 1 END) AS count_pending,
        COUNT(CASE WHEN payment_status = 'Failed' THEN 1 END) AS count_failed,
        COUNT(CASE WHEN payment_status = 'Refunded' THEN 1 END) AS count_refunded,
        COALESCE(SUM(CASE WHEN payment_status = 'Refunded' THEN amount ELSE 0 END), 0) AS total_refunded
    FROM payments
");
$stats = $rev_stmt->fetch(PDO::FETCH_ASSOC);

// 3. Process Search & Filters
$search        = trim($_GET['search'] ?? '');
$status_filter = trim($_GET['status'] ?? 'all');
$from_date     = trim($_GET['from_date'] ?? '');
$to_date       = trim($_GET['to_date'] ?? '');

$sql = "
    SELECT p.*, b.event_type, b.event_date, b.location, u.full_name AS customer_name, u.email AS customer_email, u.phone AS customer_phone, i.id AS invoice_id, i.invoice_number
    FROM payments p
    JOIN bookings b ON p.booking_id = b.id
    JOIN users u ON p.user_id = u.id
    LEFT JOIN invoices i ON b.id = i.booking_id
    WHERE 1=1
";
$params = [];

if (!empty($search)) {
    $sql .= " AND (u.full_name LIKE :s OR u.email LIKE :s OR p.transaction_id LIKE :s OR p.booking_id LIKE :s OR i.invoice_number LIKE :s)";
    $params['s'] = '%' . $search . '%';
}

if ($status_filter !== 'all' && in_array($status_filter, ['Paid', 'Pending', 'Failed', 'Refunded'])) {
    $sql .= " AND p.payment_status = :status";
    $params['status'] = $status_filter;
}

if (!empty($from_date)) {
    $sql .= " AND DATE(p.created_at) >= :from_date";
    $params['from_date'] = $from_date;
}

if (!empty($to_date)) {
    $sql .= " AND DATE(p.created_at) <= :to_date";
    $params['to_date'] = $to_date;
}

$sql .= " ORDER BY p.id DESC";

$tx_stmt = $conn->prepare($sql);
$tx_stmt->execute($params);
$transactions = $tx_stmt->fetchAll(PDO::FETCH_ASSOC);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Admin Payment & Invoice Management - Nadkarphotohub</title>
    <link rel="stylesheet" href="css/style.css">
    <style>
        .admin-page-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 25px;
            padding-bottom: 15px;
            border-bottom: 2px solid #e2e8f0;
        }
        .admin-title-badge {
            display: flex;
            align-items: center;
            gap: 12px;
        }
        .admin-title-badge h2 {
            margin: 0;
            color: #1a2b4c;
            font-size: 1.7rem;
        }
        .badge-role-admin {
            background: #f1c40f;
            color: #1a2b4c;
            padding: 4px 10px;
            border-radius: 4px;
            font-weight: 800;
            font-size: 0.75rem;
            text-transform: uppercase;
        }

        /* 5-Column Metrics Grid */
        .metrics-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 16px;
            margin-bottom: 30px;
        }
        .metric-card {
            background: #fff;
            padding: 20px;
            border-radius: 8px;
            box-shadow: 0 2px 10px rgba(0,0,0,0.06);
            border-left: 5px solid #1a2b4c;
        }
        .metric-card.card-revenue {
            border-left-color: #27ae60;
        }
        .metric-card.card-pending {
            border-left-color: #f59e0b;
        }
        .metric-card.card-failed {
            border-left-color: #ef4444;
        }
        .metric-card.card-refund {
            border-left-color: #3b82f6;
        }
        .metric-val {
            font-size: 1.8rem;
            font-weight: 800;
            color: #1a2b4c;
        }
        .metric-lbl {
            font-size: 0.8rem;
            color: #64748b;
            text-transform: uppercase;
            font-weight: 700;
            margin-top: 4px;
        }

        /* Filter Box */
        .filter-card {
            background: #fff;
            padding: 20px;
            border-radius: 8px;
            box-shadow: 0 2px 10px rgba(0,0,0,0.06);
            margin-bottom: 25px;
        }
        .filter-form-grid {
            display: grid;
            grid-template-columns: 2fr 1.2fr 1fr 1fr auto auto;
            gap: 12px;
            align-items: flex-end;
        }
        @media (max-width: 992px) {
            .filter-form-grid {
                grid-template-columns: 1fr 1fr;
            }
        }
        @media (max-width: 600px) {
            .filter-form-grid {
                grid-template-columns: 1fr;
            }
        }

        .filter-group label {
            display: block;
            font-size: 0.82rem;
            font-weight: bold;
            color: #475569;
            margin-bottom: 5px;
        }
        .filter-group input, .filter-group select {
            width: 100%;
            padding: 8px 10px;
            border: 1px solid #cbd5e1;
            border-radius: 4px;
            font-size: 0.88rem;
        }

        /* Badges */
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

        .table-actions {
            display: flex;
            gap: 5px;
            flex-wrap: wrap;
        }
        .btn-act {
            padding: 5px 9px;
            border-radius: 4px;
            font-size: 0.78rem;
            font-weight: bold;
            display: inline-block;
            border: none;
            cursor: pointer;
            transition: all 0.2s;
        }
        .btn-act-pdf {
            background: #1a2b4c;
            color: #fff;
        }
        .btn-act-pdf:hover {
            background: #2c3e50;
        }
        .btn-act-refund {
            background: #f1f5f9;
            color: #dc2626;
            border: 1px solid #fecaca;
        }
        .btn-act-refund:hover {
            background: #fee2e2;
        }
    </style>
</head>
<body>

    <!-- Header Navbar -->
    <header class="navbar">
        <div class="container nav-container">
            <a href="index.php" class="logo">📷 Nadkarphotohub</a>
            <nav>
                <a href="index.php">Website Home</a>
                <a href="admin_dashboard.php" style="color: #f1c40f; font-weight: bold;">Admin Dashboard</a>
                <a href="my_bookings.php">Customer View</a>
                <span class="user-badge">🛡️ <?php echo htmlspecialchars($admin_name); ?></span>
                <a href="logout.php" class="btn-danger">Logout</a>
            </nav>
        </div>
    </header>

    <div class="container section">

        <div class="admin-page-header">
            <div class="admin-title-badge">
                <h2>Payment & Invoice Management</h2>
                <span class="badge-role-admin">Administrator Control</span>
            </div>
            <div>
                <a href="index.php#booking" class="btn-primary" style="font-size: 0.85rem;">+ New Shoot Booking</a>
            </div>
        </div>

        <?php if (!empty($msg)): ?>
            <div class="alert success"><?php echo htmlspecialchars($msg); ?></div>
        <?php endif; ?>

        <?php if (!empty($err)): ?>
            <div class="alert error"><?php echo htmlspecialchars($err); ?></div>
        <?php endif; ?>

        <!-- Key Financial Metrics -->
        <div class="metrics-grid">
            <div class="metric-card card-revenue">
                <div class="metric-val">₹<?php echo number_format($stats['total_revenue'], 2); ?></div>
                <div class="metric-lbl">Total Net Revenue</div>
            </div>
            <div class="metric-card card-revenue">
                <div class="metric-val"><?php echo $stats['count_paid']; ?></div>
                <div class="metric-lbl">Successful Payments</div>
            </div>
            <div class="metric-card card-pending">
                <div class="metric-val"><?php echo $stats['count_pending']; ?></div>
                <div class="metric-lbl">Pending Payments</div>
            </div>
            <div class="metric-card card-failed">
                <div class="metric-val"><?php echo $stats['count_failed']; ?></div>
                <div class="metric-lbl">Failed Transactions</div>
            </div>
            <div class="metric-card card-refund">
                <div class="metric-val"><?php echo $stats['count_refunded']; ?> (₹<?php echo number_format($stats['total_refunded'], 2); ?>)</div>
                <div class="metric-lbl">Refunded Transactions</div>
            </div>
        </div>

        <!-- Search & Filter Controls -->
        <div class="filter-card">
            <form action="admin_dashboard.php" method="GET" class="filter-form-grid">
                <div class="filter-group">
                    <label>Search Customer / Ref / Invoice:</label>
                    <input type="text" name="search" placeholder="Customer name, email, #NPH ID, txn ID..." value="<?php echo htmlspecialchars($search); ?>">
                </div>

                <div class="filter-group">
                    <label>Payment Status:</label>
                    <select name="status">
                        <option value="all" <?php echo ($status_filter === 'all') ? 'selected' : ''; ?>>All Statuses</option>
                        <option value="Paid" <?php echo ($status_filter === 'Paid') ? 'selected' : ''; ?>>Paid (Successful)</option>
                        <option value="Pending" <?php echo ($status_filter === 'Pending') ? 'selected' : ''; ?>>Pending</option>
                        <option value="Failed" <?php echo ($status_filter === 'Failed') ? 'selected' : ''; ?>>Failed</option>
                        <option value="Refunded" <?php echo ($status_filter === 'Refunded') ? 'selected' : ''; ?>>Refunded</option>
                    </select>
                </div>

                <div class="filter-group">
                    <label>From Date:</label>
                    <input type="date" name="from_date" value="<?php echo htmlspecialchars($from_date); ?>">
                </div>

                <div class="filter-group">
                    <label>To Date:</label>
                    <input type="date" name="to_date" value="<?php echo htmlspecialchars($to_date); ?>">
                </div>

                <div class="filter-group">
                    <button type="submit" class="btn-primary" style="padding: 9px 16px; font-size: 0.88rem;">Filter</button>
                </div>

                <div class="filter-group">
                    <a href="admin_dashboard.php" class="btn-secondary" style="padding: 9px 14px; font-size: 0.88rem; display: inline-block;">Reset</a>
                </div>
            </form>
        </div>

        <!-- Transactions Table -->
        <div style="background: #fff; border-radius: 8px; box-shadow: 0 2px 10px rgba(0,0,0,0.06); padding: 20px;">
            <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 15px;">
                <h3 style="color: #1a2b4c; margin: 0; font-size: 1.2rem;">Payment Transaction Records (<?php echo count($transactions); ?>)</h3>
            </div>

            <?php if (count($transactions) == 0): ?>
                <div class="alert info" style="margin: 20px 0;">No transactions match your search criteria.</div>
            <?php else: ?>
                <div style="overflow-x: auto;">
                    <table class="simple-table">
                        <thead>
                            <tr>
                                <th>Txn Ref / ID</th>
                                <th>Booking Ref</th>
                                <th>Customer Details</th>
                                <th>Package & Date</th>
                                <th>Amount</th>
                                <th>Method</th>
                                <th>Status</th>
                                <th>Date & Time</th>
                                <th>Invoice & Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($transactions as $t): ?>
                                <tr>
                                    <td>
                                        <code style="font-size: 0.82rem; color: #1e293b;"><?php echo htmlspecialchars($t['transaction_id']); ?></code>
                                        <?php if (!empty($t['invoice_number'])): ?>
                                            <br><small style="color: #166534; font-weight: bold;"><?php echo htmlspecialchars($t['invoice_number']); ?></small>
                                        <?php endif; ?>
                                    </td>
                                    <td><strong>#NPH-<?php echo $t['booking_id']; ?></strong></td>
                                    <td>
                                        <strong><?php echo htmlspecialchars($t['customer_name']); ?></strong><br>
                                        <small style="color: #64748b;"><?php echo htmlspecialchars($t['customer_email']); ?><br>📱 <?php echo htmlspecialchars($t['customer_phone']); ?></small>
                                    </td>
                                    <td>
                                        <strong><?php echo htmlspecialchars($t['event_type']); ?></strong><br>
                                        <small style="color: #64748b;">📅 <?php echo date('d M, Y', strtotime($t['event_date'])); ?></small>
                                    </td>
                                    <td>
                                        <strong style="font-size: 1rem; color: #166534;">₹<?php echo number_format(floatval($t['amount']), 2); ?></strong>
                                    </td>
                                    <td><?php echo htmlspecialchars($t['payment_method']); ?></td>
                                    <td>
                                        <?php if ($t['payment_status'] === 'Paid'): ?>
                                            <span class="badge-status-paid">✓ Paid</span>
                                        <?php elseif ($t['payment_status'] === 'Pending'): ?>
                                            <span class="badge-status-pending">⏳ Pending</span>
                                        <?php elseif ($t['payment_status'] === 'Failed'): ?>
                                            <span class="badge-status-failed">✗ Failed</span>
                                        <?php elseif ($t['payment_status'] === 'Refunded'): ?>
                                            <span class="badge-status-refunded">↩ Refunded</span>
                                        <?php endif; ?>
                                    </td>
                                    <td><?php echo date('d M Y, h:i A', strtotime($t['created_at'])); ?></td>
                                    <td>
                                        <div class="table-actions">
                                            <?php if ($t['payment_status'] === 'Paid'): ?>
                                                <a href="download_invoice.php?booking_id=<?php echo $t['booking_id']; ?>" class="btn-act btn-act-pdf" title="Download PDF Invoice">
                                                    📥 PDF
                                                </a>
                                                <a href="download_invoice.php?booking_id=<?php echo $t['booking_id']; ?>&inline=1" target="_blank" class="btn-act btn-act-pdf" style="background: #0284c7;" title="View & Print Invoice">
                                                    🖨️
                                                </a>
                                                <form action="admin_dashboard.php" method="POST" style="display: inline;" onsubmit="return confirm('Are you sure you want to refund Transaction #<?php echo $t['transaction_id']; ?>? This will cancel the shoot.');">
                                                    <input type="hidden" name="action" value="refund_payment">
                                                    <input type="hidden" name="payment_id" value="<?php echo $t['id']; ?>">
                                                    <button type="submit" class="btn-act btn-act-refund" title="Mark as Refunded">↩ Refund</button>
                                                </form>
                                            <?php else: ?>
                                                <span style="color: #94a3b8; font-size: 0.8rem;">No Invoice</span>
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

    </div>

    <!-- Footer -->
    <footer class="footer">
        <div class="container">
            <p>&copy; <?php echo date('Y'); ?> Nadkarphotohub - Event Photography Booking. Simple Student Web Project (PHP & MySQL).</p>
        </div>
    </footer>

</body>
</html>