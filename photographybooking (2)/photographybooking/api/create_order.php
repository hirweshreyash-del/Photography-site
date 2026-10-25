<?php
// api/create_order.php - Backend Razorpay Order Creation API
header('Content-Type: application/json');
require_once __DIR__ . '/../config.php';

// 1. Authenticate user
if (!is_logged_in()) {
    http_response_code(401);
    echo json_encode(['success' => false, 'message' => 'Please login to proceed with payment.']);
    exit;
}

$user_id = $_SESSION['user_id'];

// 2. Parse input (JSON or POST)
$raw_input = file_get_contents('php://input');
$data = json_decode($raw_input, true);
$booking_id = intval($data['booking_id'] ?? $_POST['booking_id'] ?? 0);

if ($booking_id <= 0) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'Invalid booking ID specified.']);
    exit;
}

// 3. Fetch booking record & verify ownership (or admin)
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
    http_response_code(404);
    echo json_encode(['success' => false, 'message' => 'Booking not found or permission denied.']);
    exit;
}

// Check if booking is already paid
if ($booking['payment_status'] === 'Paid') {
    echo json_encode([
        'success'      => false,
        'already_paid' => true,
        'message'      => 'This booking has already been paid in full.',
        'redirect'     => 'confirmation.php?booking_id=' . $booking_id
    ]);
    exit;
}

// 4. Calculate total amount in INR and in paise
$total_amount = floatval($booking['total_amount']);
if ($total_amount <= 0) {
    $pricing = get_event_pricing($booking['event_type']);
    $base    = $pricing['base_price'];
    $tax     = round(($base * 18) / 100, 2);
    $total_amount = $base + $tax;

    // Update in database
    $upd = $conn->prepare("UPDATE bookings SET package_price = :base, tax_amount = :tax, total_amount = :tot WHERE id = :bid");
    $upd->execute(['base' => $base, 'tax' => $tax, 'tot' => $total_amount, 'bid' => $booking_id]);
}

$amount_in_paise = intval(round($total_amount * 100));
$currency = 'INR';

$key_id     = env('RAZORPAY_KEY_ID', 'rzp_test_NADKARHUB2026');
$key_secret = env('RAZORPAY_KEY_SECRET', 'test_secret_nadkarphotohub_2026');
$mode       = env('RAZORPAY_MODE', 'test');

$order_id = null;
$is_simulated = false;

// 5. Create Order via Razorpay REST API
if (!empty($key_id) && !empty($key_secret) && strpos($key_id, 'rzp_') === 0 && !str_contains($key_id, 'YourTestKey')) {
    $payload = json_encode([
        'amount'   => $amount_in_paise,
        'currency' => $currency,
        'receipt'  => 'order_nph_' . $booking_id . '_' . time(),
        'notes'    => [
            'booking_id'    => $booking_id,
            'event_type'    => $booking['event_type'],
            'customer_name' => $booking['full_name']
        ]
    ]);

    $ch = curl_init('https://api.razorpay.com/v1/orders');
    curl_setopt($ch, CURLOPT_USERPWD, $key_id . ':' . $key_secret);
    curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json']);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, $payload);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_TIMEOUT, 15);
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false); // For local XAMPP environments

    $response = curl_exec($ch);
    $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($http_code === 200 && $response) {
        $resp_data = json_decode($response, true);
        if (!empty($resp_data['id'])) {
            $order_id = $resp_data['id'];
        }
    }
}

// 6. Test Mode Fallback / Simulator if API is unreachable or keys are mock
if (empty($order_id)) {
    $order_id = 'order_test_' . date('Ymd') . '_' . substr(md5(uniqid()), 0, 10);
    $is_simulated = true;
}

// 7. Store / Update Pending Payment Record in Database
$initial_txn_id = 'TXN-PENDING-' . $booking_id . '-' . time();
$save_pay = $conn->prepare("
    INSERT INTO payments 
    (booking_id, user_id, razorpay_order_id, transaction_id, amount, currency, payment_method, payment_status)
    VALUES 
    (:bid, :uid, :order_id, :txnid, :amt, :curr, 'Razorpay', 'Pending')
    ON DUPLICATE KEY UPDATE 
    razorpay_order_id = VALUES(razorpay_order_id),
    amount = VALUES(amount),
    payment_status = 'Pending'
");
$save_pay->execute([
    'bid'      => $booking_id,
    'uid'      => $booking['user_id'],
    'order_id' => $order_id,
    'txnid'    => $initial_txn_id,
    'amt'      => $total_amount,
    'curr'     => $currency
]);

// 8. Return order details to frontend checkout
echo json_encode([
    'success'       => true,
    'order_id'      => $order_id,
    'amount'        => $amount_in_paise,
    'total_inr'     => number_format($total_amount, 2),
    'currency'      => $currency,
    'key_id'        => $key_id,
    'is_simulated'  => $is_simulated,
    'mode'          => $mode,
    'business_name' => env('BUSINESS_NAME', 'Nadkarphotohub'),
    'booking'       => [
        'id'         => $booking['id'],
        'event_type' => $booking['event_type'],
        'event_date' => date('d M, Y', strtotime($booking['event_date'])),
        'location'   => $booking['location']
    ],
    'customer'      => [
        'name'    => $booking['full_name'],
        'email'   => $booking['email'],
        'contact' => $booking['phone']
    ]
]);
?>