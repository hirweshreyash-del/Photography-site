<?php
// download_invoice.php - Authorized PDF Invoice Download & Print Handler
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/includes/invoice_generator.php';

// 1. Require user authentication
if (!is_logged_in()) {
    header("Location: login.php?msg=login_required");
    exit;
}

$user_id    = $_SESSION['user_id'];
$booking_id = intval($_GET['booking_id'] ?? 0);
$invoice_id = intval($_GET['invoice_id'] ?? 0);
$is_inline  = !empty($_GET['inline']);

// 2. Locate invoice or booking
$invoice = null;
$booking = null;

if ($invoice_id > 0) {
    $stmt = $conn->prepare("SELECT * FROM invoices WHERE id = :id LIMIT 1");
    $stmt->execute(['id' => $invoice_id]);
    $invoice = $stmt->fetch(PDO::FETCH_ASSOC);

    if ($invoice) {
        $booking_id = intval($invoice['booking_id']);
    }
}

if ($booking_id > 0) {
    $b_stmt = $conn->prepare("SELECT * FROM bookings WHERE id = :bid LIMIT 1");
    $b_stmt->execute(['bid' => $booking_id]);
    $booking = $b_stmt->fetch(PDO::FETCH_ASSOC);

    if (!$invoice) {
        $i_stmt = $conn->prepare("SELECT * FROM invoices WHERE booking_id = :bid LIMIT 1");
        $i_stmt->execute(['bid' => $booking_id]);
        $invoice = $i_stmt->fetch(PDO::FETCH_ASSOC);
    }
}

if (!$booking) {
    http_response_code(404);
    die("<h3>404 Not Found: Booking not found.</h3>");
}

// 3. Strict Role-Based Authorization Check
// Access allowed ONLY to booking owner or authorized administrators
$is_owner = ($booking['user_id'] == $user_id);
$is_admin = is_admin();

if (!$is_owner && !$is_admin) {
    http_response_code(403);
    die("<h3>403 Forbidden: You are not authorized to access this invoice.</h3>");
}

// 4. Ensure booking is paid
if ($booking['payment_status'] !== 'Paid') {
    die("<h3>Invoice Unavailable: This booking has not been paid yet.</h3><p><a href='checkout.php?booking_id={$booking_id}'>Proceed to Payment</a></p>");
}

// 5. If invoice record or PDF does not exist yet, generate it on demand
if (!$invoice) {
    $p_stmt = $conn->prepare("SELECT id FROM payments WHERE booking_id = :bid AND payment_status = 'Paid' ORDER BY id DESC LIMIT 1");
    $p_stmt->execute(['bid' => $booking_id]);
    $pay_row = $p_stmt->fetch(PDO::FETCH_ASSOC);
    $payment_id = $pay_row ? $pay_row['id'] : 0;

    $gen = generate_booking_invoice($booking_id, $payment_id);
    $i_stmt = $conn->prepare("SELECT * FROM invoices WHERE id = :id LIMIT 1");
    $i_stmt->execute(['id' => $gen['invoice_id']]);
    $invoice = $i_stmt->fetch(PDO::FETCH_ASSOC);
}

$pdf_file_path = __DIR__ . '/' . $invoice['pdf_path'];

if (!file_exists($pdf_file_path)) {
    // Rebuild PDF file
    $p_stmt = $conn->prepare("SELECT id FROM payments WHERE booking_id = :bid AND payment_status = 'Paid' ORDER BY id DESC LIMIT 1");
    $p_stmt->execute(['bid' => $booking_id]);
    $pay_row = $p_stmt->fetch(PDO::FETCH_ASSOC);
    $payment_id = $pay_row ? $pay_row['id'] : $invoice['payment_id'];

    generate_booking_invoice($booking_id, $payment_id);
}

// 6. Output PDF to Browser
$filename = $invoice['invoice_number'] . '.pdf';
$disposition = $is_inline ? 'inline' : 'attachment';

header('Content-Type: application/pdf');
header('Content-Disposition: ' . $disposition . '; filename="' . $filename . '"');
header('Content-Length: ' . filesize($pdf_file_path));
header('Cache-Control: private, max-age=0, must-revalidate');
header('Pragma: public');

readfile($pdf_file_path);
exit;
?>