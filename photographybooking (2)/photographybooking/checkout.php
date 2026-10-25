<?php
// checkout.php - Booking Checkout & Razorpay Payment Gateway
require_once __DIR__ . '/config.php';
require_login();

$user_id   = $_SESSION['user_id'];
$user_name = $_SESSION['user_name'] ?? 'Client';
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
    header("Location: my_bookings.php?error=not_found");
    exit;
}

// If already paid, redirect to confirmation / invoice page
if ($booking['payment_status'] === 'Paid') {
    header("Location: confirmation.php?booking_id=" . $booking_id . "&msg=already_paid");
    exit;
}

// Calculate or ensure pricing breakdown
$package_pricing = get_event_pricing($booking['event_type']);
$base_price      = floatval($booking['package_price'] > 0 ? $booking['package_price'] : $package_pricing['base_price']);
$addons_fee      = floatval($booking['additional_services_fee'] ?? 0.00);
$subtotal        = $base_price + $addons_fee;
$discount        = floatval($booking['discount_amount'] ?? 0.00);
$taxable_amt     = max(0, $subtotal - $discount);
$tax_amount      = round(($taxable_amt * 18) / 100, 2);
$grand_total     = $taxable_amt + $tax_amount;

// Update booking table if total wasn't stored yet
if (floatval($booking['total_amount']) <= 0) {
    $upd = $conn->prepare("UPDATE bookings SET package_price = :base, tax_amount = :tax, total_amount = :tot WHERE id = :bid");
    $upd->execute(['base' => $base_price, 'tax' => $tax_amount, 'tot' => $grand_total, 'bid' => $booking_id]);
    $booking['total_amount'] = $grand_total;
}

$razorpay_key_id = env('RAZORPAY_KEY_ID', 'rzp_test_NADKARHUB2026');
$razorpay_mode   = env('RAZORPAY_MODE', 'test');
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Checkout & Payment - Nadkarphotohub</title>
    <link rel="stylesheet" href="css/style.css">
    <!-- Razorpay Standard Checkout SDK -->
    <script src="https://checkout.razorpay.com/v1/checkout.js"></script>
    <style>
        .checkout-wrapper {
            padding: 40px 0 70px 0;
        }
        .step-progress {
            display: flex;
            justify-content: center;
            align-items: center;
            margin-bottom: 35px;
            gap: 15px;
        }
        .step-item {
            display: flex;
            align-items: center;
            gap: 8px;
            font-size: 0.95rem;
            color: #7f8c8d;
            font-weight: bold;
        }
        .step-item.active {
            color: #27ae60;
        }
        .step-item.completed {
            color: #1a2b4c;
        }
        .step-number {
            width: 28px;
            height: 28px;
            border-radius: 50%;
            background: #e2e8f0;
            color: #555;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 0.85rem;
        }
        .step-item.active .step-number {
            background: #27ae60;
            color: #fff;
        }
        .step-item.completed .step-number {
            background: #1a2b4c;
            color: #fff;
        }
        .step-divider {
            width: 40px;
            height: 2px;
            background: #cbd5e1;
        }

        .checkout-grid {
            display: grid;
            grid-template-columns: 1.15fr 1fr;
            gap: 30px;
            align-items: start;
        }
        @media (max-width: 860px) {
            .checkout-grid {
                grid-template-columns: 1fr;
            }
        }

        .card-box {
            background: #fff;
            border-radius: 8px;
            box-shadow: 0 4px 15px rgba(0,0,0,0.06);
            padding: 28px;
        }
        .card-box.left-summary {
            border-top: 4px solid #1a2b4c;
        }
        .card-box.right-payment {
            border-top: 4px solid #27ae60;
        }

        .card-header-title {
            display: flex;
            justify-content: space-between;
            align-items: center;
            border-bottom: 1px solid #edf2f7;
            padding-bottom: 14px;
            margin-bottom: 20px;
        }
        .card-header-title h3 {
            color: #1a2b4c;
            font-size: 1.25rem;
            margin: 0;
        }
        .badge-ref {
            background: #e0f2fe;
            color: #0284c7;
            padding: 4px 10px;
            border-radius: 4px;
            font-weight: bold;
            font-size: 0.85rem;
        }

        .detail-row {
            display: flex;
            justify-content: space-between;
            padding: 10px 0;
            font-size: 0.95rem;
            border-bottom: 1px dashed #f1f5f9;
        }
        .detail-row .lbl {
            color: #64748b;
        }
        .detail-row .val {
            color: #1e293b;
            font-weight: 600;
            text-align: right;
        }

        .pricing-table-box {
            background: #f8fafc;
            border: 1px solid #e2e8f0;
            border-radius: 6px;
            padding: 16px;
            margin-top: 20px;
        }
        .pricing-row {
            display: flex;
            justify-content: space-between;
            padding: 6px 0;
            font-size: 0.92rem;
            color: #475569;
        }
        .pricing-row.total-row {
            border-top: 2px solid #cbd5e1;
            padding-top: 12px;
            margin-top: 8px;
            font-size: 1.25rem;
            font-weight: 800;
            color: #166534;
        }

        /* Razorpay Payment Method Card */
        .gateway-card {
            border: 2px solid #27ae60;
            border-radius: 8px;
            padding: 18px;
            background: #f0fdf4;
            margin-bottom: 20px;
        }
        .gateway-title-row {
            display: flex;
            align-items: center;
            justify-content: space-between;
            margin-bottom: 10px;
        }
        .gateway-name {
            font-size: 1.1rem;
            font-weight: bold;
            color: #1a2b4c;
            display: flex;
            align-items: center;
            gap: 8px;
        }
        .mode-badge {
            background: #fef08a;
            color: #854d0e;
            padding: 3px 8px;
            border-radius: 12px;
            font-size: 0.75rem;
            font-weight: bold;
            text-transform: uppercase;
        }
        .methods-pills {
            display: flex;
            gap: 8px;
            flex-wrap: wrap;
            margin-top: 10px;
        }
        .method-pill {
            background: #fff;
            border: 1px solid #cbd5e1;
            padding: 4px 10px;
            border-radius: 16px;
            font-size: 0.8rem;
            color: #334155;
            font-weight: 600;
        }

        /* Pay Button */
        .btn-pay-now {
            width: 100%;
            background: #27ae60;
            color: #fff;
            border: none;
            padding: 15px;
            border-radius: 6px;
            font-size: 1.15rem;
            font-weight: bold;
            cursor: pointer;
            transition: all 0.2s;
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 10px;
            box-shadow: 0 4px 12px rgba(39, 174, 96, 0.3);
        }
        .btn-pay-now:hover {
            background: #219150;
            transform: translateY(-1px);
        }
        .btn-pay-now:disabled {
            background: #94a3b8;
            cursor: not-allowed;
            box-shadow: none;
            transform: none;
        }

        /* Test Mode Simulator Box */
        .test-simulator-box {
            background: #fffbeb;
            border: 1px dashed #f59e0b;
            border-radius: 8px;
            padding: 18px;
            margin-top: 25px;
        }
        .test-simulator-box h4 {
            color: #b45309;
            font-size: 0.95rem;
            margin-bottom: 6px;
            display: flex;
            align-items: center;
            gap: 6px;
        }
        .btn-sim-success {
            background: #16a34a;
            color: #fff;
            border: none;
            padding: 8px 14px;
            border-radius: 4px;
            font-size: 0.85rem;
            font-weight: bold;
            cursor: pointer;
            margin-right: 8px;
            margin-top: 8px;
        }
        .btn-sim-fail {
            background: #dc2626;
            color: #fff;
            border: none;
            padding: 8px 14px;
            border-radius: 4px;
            font-size: 0.85rem;
            font-weight: bold;
            cursor: pointer;
            margin-top: 8px;
        }

        /* Notification Badges */
        .notification-alert {
            padding: 14px 18px;
            border-radius: 6px;
            margin-bottom: 20px;
            font-size: 0.92rem;
            display: none;
        }
        .notification-alert.error {
            background: #fee2e2;
            color: #991b1b;
            border: 1px solid #fecaca;
        }
        .notification-alert.success {
            background: #dcfce7;
            color: #166534;
            border: 1px solid #bbf7d0;
        }

        /* Spinner */
        .spinner {
            width: 20px;
            height: 20px;
            border: 3px solid rgba(255,255,255,0.3);
            border-top-color: #fff;
            border-radius: 50%;
            animation: spin 0.8s linear infinite;
            display: none;
        }
        @keyframes spin {
            to { transform: rotate(360deg); }
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
                <a href="my_bookings.php" class="btn-secondary">My Bookings</a>
                <?php if (is_admin()): ?>
                    <a href="admin_dashboard.php" class="btn-primary" style="background: #f1c40f; color: #1a2b4c;">Admin Panel</a>
                <?php endif; ?>
                <span class="user-badge">Hi, <?php echo htmlspecialchars($user_name); ?></span>
                <a href="logout.php" class="btn-danger">Logout</a>
            </nav>
        </div>
    </header>

    <div class="container checkout-wrapper">
        
        <!-- Multi-Step Progress Tracker -->
        <div class="step-progress">
            <div class="step-item completed">
                <span class="step-number">✓</span>
                <span>1. Shoot Booked</span>
            </div>
            <div class="step-divider"></div>
            <div class="step-item active">
                <span class="step-number">2</span>
                <span>2. Payment Checkout</span>
            </div>
            <div class="step-divider"></div>
            <div class="step-item">
                <span class="step-number">3</span>
                <span>3. Confirmed & Invoice</span>
            </div>
        </div>

        <!-- Failure & Retry Notification Banner -->
        <div class="notification-alert error" id="paymentAlertBox">
            <div style="display: flex; justify-content: space-between; align-items: center;">
                <span id="paymentAlertMsg">Payment could not be completed.</span>
                <button type="button" onclick="retryPayment()" class="btn-primary" style="padding: 5px 12px; font-size: 0.82rem; background: #991b1b;">
                    🔄 Retry Payment
                </button>
            </div>
        </div>

        <div class="checkout-grid">
            
            <!-- Left Column: Shoot Reservation & Pricing Details -->
            <div class="card-box left-summary">
                <div class="card-header-title">
                    <h3>Shoot Booking Summary</h3>
                    <span class="badge-ref">#NPH-<?php echo $booking['id']; ?></span>
                </div>

                <div class="detail-row">
                    <span class="lbl">Photography Package:</span>
                    <span class="val">📷 <?php echo htmlspecialchars($booking['event_type']); ?></span>
                </div>
                <div class="detail-row">
                    <span class="lbl">Scheduled Shoot Date:</span>
                    <span class="val">📅 <?php echo date('d F, Y', strtotime($booking['event_date'])); ?></span>
                </div>
                <div class="detail-row">
                    <span class="lbl">Venue / Location:</span>
                    <span class="val">📍 <?php echo htmlspecialchars($booking['location']); ?></span>
                </div>
                <div class="detail-row">
                    <span class="lbl">Client Name:</span>
                    <span class="val"><?php echo htmlspecialchars($booking['full_name']); ?></span>
                </div>
                <div class="detail-row">
                    <span class="lbl">Client Contact:</span>
                    <span class="val"><?php echo htmlspecialchars($booking['email']); ?> | <?php echo htmlspecialchars($booking['phone']); ?></span>
                </div>
                <?php if (!empty($booking['notes'])): ?>
                <div class="detail-row">
                    <span class="lbl">Special Instructions:</span>
                    <span class="val"><?php echo htmlspecialchars($booking['notes']); ?></span>
                </div>
                <?php endif; ?>

                <!-- Itemized Pricing Breakdown -->
                <div class="pricing-table-box">
                    <div style="font-weight: bold; color: #1a2b4c; margin-bottom: 8px; font-size: 0.95rem;">
                        Itemized Pricing Breakdown
                    </div>
                    <div class="pricing-row">
                        <span>Base Package Rate:</span>
                        <span>₹<?php echo number_format($base_price, 2); ?></span>
                    </div>
                    <?php if ($addons_fee > 0): ?>
                    <div class="pricing-row">
                        <span>Additional Services:</span>
                        <span>₹<?php echo number_format($addons_fee, 2); ?></span>
                    </div>
                    <?php endif; ?>
                    <?php if ($discount > 0): ?>
                    <div class="pricing-row" style="color: #16a34a;">
                        <span>Discount:</span>
                        <span>- ₹<?php echo number_format($discount, 2); ?></span>
                    </div>
                    <?php endif; ?>
                    <div class="pricing-row">
                        <span>Applicable Taxes (18% GST):</span>
                        <span>₹<?php echo number_format($tax_amount, 2); ?></span>
                    </div>
                    <div class="pricing-row total-row">
                        <span>Final Total:</span>
                        <span>₹<?php echo number_format($grand_total, 2); ?></span>
                    </div>
                </div>

                <div style="margin-top: 20px; font-size: 0.82rem; color: #64748b; line-height: 1.5;">
                    💡 <em>Upon successful payment, an official tax invoice with a unique invoice number and PDF download will be automatically generated.</em>
                </div>
            </div>

            <!-- Right Column: Payment Method & Razorpay Gateway -->
            <div class="card-box right-payment">
                <div class="card-header-title">
                    <h3>Select Payment Method</h3>
                    <span style="font-size: 0.85rem; color: #16a34a; font-weight: bold;">🔒 Secure Checkout</span>
                </div>

                <!-- Razorpay Gateway Card -->
                <div class="gateway-card">
                    <div class="gateway-title-row">
                        <div class="gateway-name">
                            <span>💳 Razorpay Gateway</span>
                        </div>
                        <span class="mode-badge"><?php echo strtoupper($razorpay_mode); ?> MODE</span>
                    </div>
                    <p style="font-size: 0.88rem; color: #334155; margin-bottom: 8px;">
                        Pay securely with your preferred Indian payment method:
                    </p>
                    <div class="methods-pills">
                        <span class="method-pill">⚡ UPI (GPay, PhonePe, Paytm, BHIM)</span>
                        <span class="method-pill">💳 Debit & Credit Cards</span>
                        <span class="method-pill">🏦 Net Banking (All Indian Banks)</span>
                        <span class="method-pill">👛 Wallets</span>
                    </div>
                </div>

                <!-- Pay Now Button -->
                <button type="button" id="payNowBtn" onclick="initiatePayment()" class="btn-pay-now">
                    <div class="spinner" id="paySpinner"></div>
                    <span id="btnText">🔒 Pay Now ₹<?php echo number_format($grand_total, 2); ?></span>
                </button>

                <div style="text-align: center; margin-top: 15px; font-size: 0.82rem; color: #64748b;">
                    🛡️ 256-Bit SSL Encrypted • PCI-DSS Compliant • Instant Confirmation
                </div>

                <!-- Development Test Mode Simulator -->
                <?php if ($razorpay_mode === 'test'): ?>
                <div class="test-simulator-box">
                    <h4>🧪 Developer Test Sandbox</h4>
                    <p style="font-size: 0.82rem; color: #78350f; margin-bottom: 8px;">
                        Quickly verify the complete end-to-end payment verification and automatic PDF invoice generation workflow:
                    </p>
                    <div>
                        <button type="button" onclick="simulatePayment(true)" class="btn-sim-success">
                            ✓ Simulate Successful Payment
                        </button>
                        <button type="button" onclick="simulatePayment(false)" class="btn-sim-fail">
                            ✗ Simulate Payment Failure
                        </button>
                    </div>
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

    <!-- Payment Gateway Script -->
    <script>
        const bookingId = <?php echo $booking_id; ?>;
        const totalAmountINR = "<?php echo number_format($grand_total, 2); ?>";

        function setButtonLoading(loading) {
            const btn = document.getElementById('payNowBtn');
            const spinner = document.getElementById('paySpinner');
            const btnText = document.getElementById('btnText');

            if (loading) {
                btn.disabled = true;
                spinner.style.display = 'inline-block';
                btnText.textContent = 'Processing Payment Order...';
            } else {
                btn.disabled = false;
                spinner.style.display = 'none';
                btnText.textContent = '🔒 Pay Now ₹' + totalAmountINR;
            }
        }

        function showFailureAlert(message) {
            const box = document.getElementById('paymentAlertBox');
            const msg = document.getElementById('paymentAlertMsg');
            msg.textContent = message;
            box.style.display = 'block';
            window.scrollTo({ top: box.offsetTop - 30, behavior: 'smooth' });
        }

        function retryPayment() {
            document.getElementById('paymentAlertBox').style.display = 'none';
            initiatePayment();
        }

        // 1. Create Razorpay Order on Backend
        async function initiatePayment() {
            setButtonLoading(true);
            document.getElementById('paymentAlertBox').style.display = 'none';

            try {
                const response = await fetch('api/create_order.php', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({ booking_id: bookingId })
                });

                const data = await response.json();

                if (!data.success) {
                    setButtonLoading(false);
                    if (data.already_paid && data.redirect) {
                        window.location.href = data.redirect;
                        return;
                    }
                    showFailureAlert(data.message || 'Failed to initialize payment.');
                    return;
                }

                // If simulated order in test mode without Razorpay live keys
                if (data.is_simulated) {
                    setButtonLoading(false);
                    // Prompt developer for simulated test execution
                    if (confirm("Razorpay Test Mode: Simulated test order #" + data.order_id + " generated.\n\nClick OK to simulate instant payment verification & automatic PDF invoice generation!")) {
                        simulatePayment(true, data.order_id);
                    }
                    return;
                }

                // 2. Open Standard Razorpay Checkout Modal
                const options = {
                    key: data.key_id,
                    amount: data.amount,
                    currency: data.currency,
                    name: data.business_name || "Nadkarphotohub",
                    description: data.booking.event_type + " Shoot Booking (#NPH-" + data.booking.id + ")",
                    order_id: data.order_id,
                    prefill: {
                        name: data.customer.name,
                        email: data.customer.email,
                        contact: data.customer.contact
                    },
                    theme: {
                        color: "#1a2b4c"
                    },
                    handler: async function (paymentResponse) {
                        // 3. Send Signature to Backend for Verification
                        setButtonLoading(true);
                        await verifyPaymentOnBackend(paymentResponse);
                    },
                    modal: {
                        ondismiss: function () {
                            setButtonLoading(false);
                            showFailureAlert("Payment cancelled by customer. You can retry payment anytime.");
                        }
                    }
                };

                const rzp = new Razorpay(options);
                rzp.on('payment.failed', function (failResponse) {
                    setButtonLoading(false);
                    const reason = failResponse.error ? failResponse.error.description : 'Payment transaction failed.';
                    showFailureAlert("Payment Failed: " + reason);
                });

                setButtonLoading(false);
                rzp.open();

            } catch (err) {
                setButtonLoading(false);
                showFailureAlert("Network or communication error: " + err.message);
            }
        }

        // 4. Verify Payment Signature on Backend
        async function verifyPaymentOnBackend(paymentResponse) {
            try {
                const response = await fetch('api/verify_payment.php', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({
                        booking_id: bookingId,
                        razorpay_order_id: paymentResponse.razorpay_order_id,
                        razorpay_payment_id: paymentResponse.razorpay_payment_id,
                        razorpay_signature: paymentResponse.razorpay_signature
                    })
                });

                const verifyData = await response.json();

                if (verifyData.success && verifyData.redirect) {
                    window.location.href = verifyData.redirect;
                } else {
                    setButtonLoading(false);
                    showFailureAlert(verifyData.message || 'Signature verification failed.');
                }
            } catch (err) {
                setButtonLoading(false);
                showFailureAlert("Error during payment verification: " + err.message);
            }
        }

        // 5. Test Mode Simulator (For Testing All Paths & PDF Invoice Generation)
        async function simulatePayment(success, existingOrderId = null) {
            setButtonLoading(true);
            document.getElementById('paymentAlertBox').style.display = 'none';

            const testOrderId   = existingOrderId || ('order_sim_' + Math.random().toString(36).substr(2, 9));
            const testPaymentId = 'pay_sim_' + Math.random().toString(36).substr(2, 9);
            const testSignature = 'sim_test_sig_' + Math.random().toString(36).substr(2, 12);

            try {
                const response = await fetch('api/verify_payment.php', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({
                        booking_id: bookingId,
                        razorpay_order_id: testOrderId,
                        razorpay_payment_id: testPaymentId,
                        razorpay_signature: testSignature,
                        is_simulated: true,
                        simulate_failure: !success,
                        payment_method: 'Razorpay Test Simulation (UPI / Card)'
                    })
                });

                const data = await response.json();
                setButtonLoading(false);

                if (data.success && data.redirect) {
                    window.location.href = data.redirect;
                } else {
                    showFailureAlert(data.message || 'Payment simulation failed.');
                }
            } catch (err) {
                setButtonLoading(false);
                showFailureAlert("Simulation error: " + err.message);
            }
        }
    </script>

</body>
</html>