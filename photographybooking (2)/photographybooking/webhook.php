<?php
// webhook.php - Verified Razorpay Webhook Handler
header('Content-Type: application/json');
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/includes/invoice_generator.php';

// 1. Read Raw Payload & Signature Header
$payload = file_get_contents('php://input');
$signature = $_SERVER['HTTP_X_RAZORPAY_SIGNATURE'] ?? '';

$webhook_secret = env('RAZORPAY_WEBHOOK_SECRET', 'test_webhook_secret_2026');

if (empty($payload)) {
    http_response_code(400);
    echo json_encode(['status' => 'error', 'message' => 'Empty payload']);
    exit;
}

// 2. Verify Webhook Signature (HMAC SHA256)
$expected_signature = hash_hmac('sha256', $payload, $webhook_secret);

// In development test mode, allow verification or simulation
if (!hash_equals($expected_signature, $signature) && env('RAZORPAY_MODE') !== 'test') {
    http_response_code(400);
    echo json_encode(['status' => 'error', 'message' => 'Invalid webhook signature']);
    exit;
}

// 3. Decode Event Payload
$event = json_decode($payload, true);
$event_type = $event['event'] ?? '';
$payload_data = $event['payload'] ?? [];

// Log webhook for debugging & audit trail
$log_entry = sprintf("[%s] Webhook Event: %s\n", date('Y-m-d H:i:s'), $event_type);
@file_put_contents(__DIR__ . '/webhook_audit.log', $log_entry, FILE_APPEND);

// 4. Handle Webhook Events
try {
    switch ($event_type) {
        case 'payment.captured':
        case 'order.paid':
            $pay_entity = $payload_data['payment']['entity'] ?? [];
            $order_id   = $pay_entity['order_id'] ?? '';
            $payment_id = $pay_entity['id'] ?? '';
            $method     = $pay_entity['method'] ?? 'Razorpay';
            $booking_id = intval($pay_entity['notes']['booking_id'] ?? 0);

            // If booking_id not in notes, find by order_id in payments table
            if ($booking_id <= 0 && !empty($order_id)) {
                $f_stmt = $conn->prepare("SELECT booking_id FROM payments WHERE razorpay_order_id = :oid LIMIT 1");
                $f_stmt->execute(['oid' => $order_id]);
                $f_row = $f_stmt->fetch(PDO::FETCH_ASSOC);
                if ($f_row) {
                    $booking_id = intval($f_row['booking_id']);
                }
            }

            if ($booking_id > 0) {
                // Fetch or update payment record
                $chk_pay = $conn->prepare("SELECT id, payment_status FROM payments WHERE booking_id = :bid AND (razorpay_order_id = :oid OR transaction_id = :pid) LIMIT 1");
                $chk_pay->execute(['bid' => $booking_id, 'oid' => $order_id, 'pid' => $payment_id]);
                $existing_pay = $chk_pay->fetch(PDO::FETCH_ASSOC);

                $payment_record_id = 0;
                if ($existing_pay) {
                    $payment_record_id = $existing_pay['id'];
                    // Prevent duplicate processing if already paid
                    if ($existing_pay['payment_status'] === 'Paid') {
                        echo json_encode(['status' => 'ok', 'message' => 'Already processed']);
                        exit;
                    }
                    $upd_p = $conn->prepare("UPDATE payments SET payment_status = 'Paid', razorpay_payment_id = :pid, transaction_id = :txnid, payment_method = :meth, payment_response = :resp WHERE id = :id");
                    $upd_p->execute([
                        'pid'   => $payment_id,
                        'txnid' => $payment_id,
                        'meth'  => 'Razorpay (' . ucfirst($method) . ')',
                        'resp'  => $payload,
                        'id'    => $payment_record_id
                    ]);
                } else {
                    $b_user = $conn->prepare("SELECT user_id, total_amount FROM bookings WHERE id = :bid LIMIT 1");
                    $b_user->execute(['bid' => $booking_id]);
                    $b_info = $b_user->fetch(PDO::FETCH_ASSOC);

                    $ins_p = $conn->prepare("INSERT INTO payments (booking_id, user_id, razorpay_order_id, razorpay_payment_id, transaction_id, amount, payment_method, payment_status, payment_response) VALUES (:bid, :uid, :oid, :pid, :txnid, :amt, :meth, 'Paid', :resp)");
                    $ins_p->execute([
                        'bid'   => $booking_id,
                        'uid'   => $b_info['user_id'],
                        'oid'   => $order_id,
                        'pid'   => $payment_id,
                        'txnid' => $payment_id,
                        'amt'   => $b_info['total_amount'],
                        'meth'  => 'Razorpay (' . ucfirst($method) . ')',
                        'resp'  => $payload
                    ]);
                    $payment_record_id = $conn->lastInsertId();
                }

                // Update booking status
                $upd_b = $conn->prepare("UPDATE bookings SET payment_status = 'Paid', status = 'Confirmed' WHERE id = :bid");
                $upd_b->execute(['bid' => $booking_id]);

                // Automatically generate invoice (prevents duplicates internally)
                generate_booking_invoice($booking_id, $payment_record_id);
            }
            break;

        case 'payment.failed':
            $pay_entity = $payload_data['payment']['entity'] ?? [];
            $order_id   = $pay_entity['order_id'] ?? '';
            $err_desc   = $pay_entity['error_description'] ?? 'Payment failed';

            if (!empty($order_id)) {
                $upd_fail = $conn->prepare("UPDATE payments SET payment_status = 'Failed', failure_reason = :err WHERE razorpay_order_id = :oid");
                $upd_fail->execute(['err' => $err_desc, 'oid' => $order_id]);

                $upd_b_fail = $conn->prepare("UPDATE bookings SET payment_status = 'Failed' WHERE id IN (SELECT booking_id FROM payments WHERE razorpay_order_id = :oid)");
                $upd_b_fail->execute(['oid' => $order_id]);
            }
            break;

        case 'refund.processed':
        case 'refund.created':
            $refund_entity = $payload_data['refund']['entity'] ?? [];
            $payment_id    = $refund_entity['payment_id'] ?? '';

            if (!empty($payment_id)) {
                $upd_ref = $conn->prepare("UPDATE payments SET payment_status = 'Refunded' WHERE razorpay_payment_id = :pid");
                $upd_ref->execute(['pid' => $payment_id]);

                $upd_b_ref = $conn->prepare("UPDATE bookings SET payment_status = 'Refunded' WHERE id IN (SELECT booking_id FROM payments WHERE razorpay_payment_id = :pid)");
                $upd_b_ref->execute(['pid' => $payment_id]);
            }
            break;

        default:
            // Unhandled event acknowledged
            break;
    }

    echo json_encode(['status' => 'ok', 'event' => $event_type]);
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['status' => 'error', 'message' => $e->getMessage()]);
}
?>