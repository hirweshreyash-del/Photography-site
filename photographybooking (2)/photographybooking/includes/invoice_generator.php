<?php
// includes/invoice_generator.php - Automatic Invoice Generation & PDF Storage Engine
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/pdf_engine.php';

/**
 * Generates an official invoice and PDF document for a successful booking payment.
 * Automatically prevents duplicate invoices for the same booking/payment.
 *
 * @param int $booking_id
 * @param int $payment_id
 * @return array
 */
function generate_booking_invoice($booking_id, $payment_id) {
    global $conn;

    // 1. Check if invoice already exists for this booking or payment to prevent duplicates
    $check_stmt = $conn->prepare("SELECT * FROM invoices WHERE booking_id = :bid OR payment_id = :pid LIMIT 1");
    $check_stmt->execute(['bid' => $booking_id, 'pid' => $payment_id]);
    $existing_invoice = $check_stmt->fetch(PDO::FETCH_ASSOC);

    if ($existing_invoice) {
        return [
            'status'     => 'existing',
            'invoice_id' => $existing_invoice['id'],
            'invoice_no' => $existing_invoice['invoice_number'],
            'pdf_path'   => $existing_invoice['pdf_path'],
            'data'       => $existing_invoice
        ];
    }

    // 2. Fetch Booking, User, and Payment records
    $b_stmt = $conn->prepare("
        SELECT b.*, u.full_name, u.email, u.phone 
        FROM bookings b 
        JOIN users u ON b.user_id = u.id 
        WHERE b.id = :bid LIMIT 1
    ");
    $b_stmt->execute(['bid' => $booking_id]);
    $booking = $b_stmt->fetch(PDO::FETCH_ASSOC);

    if (!$booking) {
        throw new Exception("Booking #{$booking_id} not found.");
    }

    $p_stmt = $conn->prepare("SELECT * FROM payments WHERE id = :pid LIMIT 1");
    $p_stmt->execute(['pid' => $payment_id]);
    $payment = $p_stmt->fetch(PDO::FETCH_ASSOC);

    if (!$payment) {
        throw new Exception("Payment #{$payment_id} not found.");
    }

    // 3. Calculate Financial Breakdown
    $package_pricing = get_event_pricing($booking['event_type']);
    $base_price      = floatval($booking['package_price'] > 0 ? $booking['package_price'] : $package_pricing['base_price']);
    $addons_fee      = floatval($booking['additional_services_fee'] ?? 0.00);
    $subtotal        = $base_price + $addons_fee;
    $discount        = floatval($booking['discount_amount'] ?? 0.00);
    $taxable_amt     = max(0, $subtotal - $discount);
    
    // 18% GST (CGST 9% + SGST 9%)
    $tax_rate        = 18.00;
    $tax_amount      = round(($taxable_amt * $tax_rate) / 100, 2);
    $cgst_amount     = round($tax_amount / 2, 2);
    $sgst_amount     = $tax_amount - $cgst_amount;
    $grand_total     = $taxable_amt + $tax_amount;

    // 4. Generate Unique Sequential Invoice Number: INV-YYYY-XXXX
    $year = date('Y');
    $invoice_number = sprintf("INV-%s-%04d", $year, $booking_id);

    // Ensure invoice number uniqueness
    $uniq_check = $conn->prepare("SELECT id FROM invoices WHERE invoice_number = :inv LIMIT 1");
    $uniq_check->execute(['inv' => $invoice_number]);
    if ($uniq_check->fetch()) {
        $invoice_number = sprintf("INV-%s-%04d-%s", $year, $booking_id, substr(uniqid(), -4));
    }

    $invoice_date = date('Y-m-d');
    $pdf_filename = $invoice_number . '.pdf';
    $pdf_relative_path = 'invoices/' . $pdf_filename;
    $pdf_absolute_path = __DIR__ . '/../' . $pdf_relative_path;

    // 5. Store Invoice Record in Database
    $insert_inv = $conn->prepare("
        INSERT INTO invoices 
        (invoice_number, booking_id, payment_id, user_id, subtotal, discount, tax_rate, tax_amount, total_amount, invoice_date, pdf_path)
        VALUES 
        (:inv_no, :bid, :pid, :uid, :subtotal, :discount, :tax_rate, :tax_amt, :total_amt, :inv_date, :pdf_path)
    ");
    $insert_inv->execute([
        'inv_no'    => $invoice_number,
        'bid'       => $booking_id,
        'pid'       => $payment_id,
        'uid'       => $booking['user_id'],
        'subtotal'  => $subtotal,
        'discount'  => $discount,
        'tax_rate'  => $tax_rate,
        'tax_amt'   => $tax_amount,
        'total_amt' => $grand_total,
        'inv_date'  => $invoice_date,
        'pdf_path'  => $pdf_relative_path
    ]);
    $new_invoice_id = $conn->lastInsertId();

    // 6. Build the Professional Vector PDF Document
    $pdf = new SimplePDF();

    // Background Top Banner (Navy Blue #1a2b4c)
    $pdf->rect(0, 0, 595.28, 115, [26, 43, 76]);

    // Top Gold Accent Stripe (#f1c40f)
    $pdf->rect(0, 115, 595.28, 4, [241, 196, 15]);

    // Studio Business Branding (Left Header)
    $business_name    = env('BUSINESS_NAME', 'Nadkarphotohub');
    $business_tagline = env('BUSINESS_TAGLINE', 'Professional Event Photography & Cinematic Studio');
    $business_address = env('BUSINESS_ADDRESS', 'Shop No. 5, Near Central Market, Main Road, City');
    $business_phone   = env('BUSINESS_PHONE', '+91 98765 43210');
    $business_email   = env('BUSINESS_EMAIL', 'contact@nadkarphotohub.com');
    $business_gstin   = env('BUSINESS_GSTIN', '27AABCN1234F1Z5');

    $pdf->text($business_name, 40, 42, 22, 'Helvetica-Bold', [241, 196, 15]);
    $pdf->text($business_tagline, 40, 58, 9, 'Helvetica', [226, 232, 240]);
    $pdf->text($business_address, 40, 72, 8.5, 'Helvetica', [203, 213, 225]);
    $pdf->text("Phone: " . $business_phone . " | Email: " . $business_email, 40, 84, 8.5, 'Helvetica', [203, 213, 225]);
    $pdf->text("GSTIN: " . $business_gstin, 40, 96, 8.5, 'Helvetica-Bold', [241, 196, 15]);

    // Header Right (TAX INVOICE + Details)
    $pdf->text("TAX INVOICE", 555, 42, 18, 'Helvetica-Bold', [255, 255, 255], 'right');
    $pdf->text("Invoice No: " . $invoice_number, 555, 60, 10, 'Helvetica-Bold', [241, 196, 15], 'right');
    $pdf->text("Invoice Date: " . date('d M, Y', strtotime($invoice_date)), 555, 74, 9, 'Helvetica', [226, 232, 240], 'right');
    $pdf->text("Booking Ref: #NPH-" . $booking_id, 555, 87, 9, 'Helvetica', [226, 232, 240], 'right');

    // "PAID" Stamp Badge
    $pdf->rect(475, 96, 80, 15, [39, 174, 96]);
    $pdf->text("STATUS: PAID", 515, 107, 8.5, 'Helvetica-Bold', [255, 255, 255], 'center');

    // Section 1: Customer & Shoot Reservation Details Box
    $pdf->rect(40, 135, 250, 95, [248, 250, 252], [226, 232, 240]);
    $pdf->rect(40, 135, 250, 22, [237, 242, 247]);
    $pdf->text("BILLED TO (CUSTOMER)", 50, 150, 9, 'Helvetica-Bold', [26, 43, 76]);

    $pdf->text($booking['full_name'], 50, 172, 10.5, 'Helvetica-Bold', [30, 41, 59]);
    $pdf->text("Email: " . $booking['email'], 50, 188, 9, 'Helvetica', [71, 85, 105]);
    $pdf->text("Phone: " . $booking['phone'], 50, 202, 9, 'Helvetica', [71, 85, 105]);
    $pdf->text("Client ID: #USR-" . $booking['user_id'], 50, 216, 9, 'Helvetica', [100, 116, 139]);

    // Shoot Details Box (Right Box)
    $pdf->rect(305, 135, 250, 95, [248, 250, 252], [226, 232, 240]);
    $pdf->rect(305, 135, 250, 22, [237, 242, 247]);
    $pdf->text("EVENT SHOOT RESERVATION", 315, 150, 9, 'Helvetica-Bold', [26, 43, 76]);

    $pdf->text("Event: " . $booking['event_type'], 315, 172, 10, 'Helvetica-Bold', [30, 41, 59]);
    $pdf->text("Scheduled Date: " . date('d F, Y', strtotime($booking['event_date'])), 315, 188, 9, 'Helvetica', [71, 85, 105]);
    $pdf->text("Venue: " . substr($booking['location'], 0, 36), 315, 202, 9, 'Helvetica', [71, 85, 105]);
    $notes_clean = !empty($booking['notes']) ? substr($booking['notes'], 0, 38) : 'Standard shoot package coverage';
    $pdf->text("Notes: " . $notes_clean, 315, 216, 8.5, 'Helvetica', [100, 116, 139]);

    // Section 2: Itemized Charges Table
    $tableY = 248;
    // Table Header
    $pdf->rect(40, $tableY, 515, 24, [26, 43, 76]);
    $pdf->text("SL", 50, $tableY + 16, 9, 'Helvetica-Bold', [255, 255, 255]);
    $pdf->text("PHOTOGRAPHY SERVICES / PACKAGE", 80, $tableY + 16, 9, 'Helvetica-Bold', [255, 255, 255]);
    $pdf->text("EVENT DATE", 320, $tableY + 16, 9, 'Helvetica-Bold', [255, 255, 255]);
    $pdf->text("RATE (INR)", 450, $tableY + 16, 9, 'Helvetica-Bold', [255, 255, 255], 'right');
    $pdf->text("AMOUNT (INR)", 545, $tableY + 16, 9, 'Helvetica-Bold', [255, 255, 255], 'right');

    // Row 1: Photography Package
    $row1Y = $tableY + 24;
    $pdf->rect(40, $row1Y, 515, 30, [255, 255, 255], [226, 232, 240]);
    $pdf->text("01", 50, $row1Y + 16, 9, 'Helvetica', [71, 85, 105]);
    $pdf->text($booking['event_type'] . " Package", 80, $row1Y + 14, 9.5, 'Helvetica-Bold', [30, 41, 59]);
    $pdf->text($package_pricing['desc'] ?? 'Complete event coverage with edited photos', 80, $row1Y + 24, 7.8, 'Helvetica', [100, 116, 139]);
    $pdf->text(date('d M Y', strtotime($booking['event_date'])), 320, $row1Y + 16, 9, 'Helvetica', [71, 85, 105]);
    $pdf->text(number_format($base_price, 2), 450, $row1Y + 16, 9, 'Helvetica', [71, 85, 105], 'right');
    $pdf->text(number_format($base_price, 2), 545, $row1Y + 16, 9.5, 'Helvetica-Bold', [30, 41, 59], 'right');

    $curY = $row1Y + 30;

    // Row 2: Additional Services / Add-ons (if applicable)
    if ($addons_fee > 0) {
        $pdf->rect(40, $curY, 515, 22, [248, 250, 252], [226, 232, 240]);
        $pdf->text("02", 50, $curY + 14, 9, 'Helvetica', [71, 85, 105]);
        $pdf->text("Additional Creative Services & Equipment", 80, $curY + 14, 9, 'Helvetica', [30, 41, 59]);
        $pdf->text("-", 320, $curY + 14, 9, 'Helvetica', [71, 85, 105]);
        $pdf->text(number_format($addons_fee, 2), 450, $curY + 14, 9, 'Helvetica', [71, 85, 105], 'right');
        $pdf->text(number_format($addons_fee, 2), 545, $curY + 14, 9, 'Helvetica', [30, 41, 59], 'right');
        $curY += 22;
    }

    // Section 3: Financial Calculations & Payment Summary
    $summaryTop = $curY + 15;

    // Left Box: Payment Details
    $pdf->rect(40, $summaryTop, 270, 110, [248, 250, 252], [226, 232, 240]);
    $pdf->rect(40, $summaryTop, 270, 20, [237, 242, 247]);
    $pdf->text("PAYMENT TRANSACTION DETAILS", 50, $summaryTop + 14, 8.5, 'Helvetica-Bold', [26, 43, 76]);

    $pdf->text("Payment Gateway:", 50, $summaryTop + 35, 8.5, 'Helvetica', [100, 116, 139]);
    $pdf->text("Razorpay (Online Payment Gateway)", 140, $summaryTop + 35, 8.5, 'Helvetica-Bold', [30, 41, 59]);

    $pdf->text("Payment Method:", 50, $summaryTop + 50, 8.5, 'Helvetica', [100, 116, 139]);
    $pdf->text($payment['payment_method'] ?? 'UPI / Cards / NetBanking', 140, $summaryTop + 50, 8.5, 'Helvetica', [30, 41, 59]);

    $pdf->text("Transaction ID:", 50, $summaryTop + 65, 8.5, 'Helvetica', [100, 116, 139]);
    $pdf->text($payment['transaction_id'], 140, $summaryTop + 65, 8.5, 'Courier', [30, 41, 59]);

    $pdf->text("Razorpay Order ID:", 50, $summaryTop + 80, 8.5, 'Helvetica', [100, 116, 139]);
    $pdf->text($payment['razorpay_order_id'] ?? 'N/A', 140, $summaryTop + 80, 8, 'Courier', [100, 116, 139]);

    $pdf->text("Payment Date:", 50, $summaryTop + 95, 8.5, 'Helvetica', [100, 116, 139]);
    $pdf->text(date('d M Y, h:i A', strtotime($payment['created_at'])), 140, $summaryTop + 95, 8.5, 'Helvetica', [30, 41, 59]);

    // Right Box: Totals & Tax Table
    $totX = 330;
    $totValX = 545;
    $totY = $summaryTop;

    $pdf->text("Subtotal:", $totX, $totY + 12, 9, 'Helvetica', [71, 85, 105]);
    $pdf->text("INR " . number_format($subtotal, 2), $totValX, $totY + 12, 9, 'Helvetica', [30, 41, 59], 'right');

    if ($discount > 0) {
        $pdf->text("Discount Applied:", $totX, $totY + 28, 9, 'Helvetica', [39, 174, 96]);
        $pdf->text("- INR " . number_format($discount, 2), $totValX, $totY + 28, 9, 'Helvetica-Bold', [39, 174, 96], 'right');
        $totY += 16;
    }

    $pdf->text("CGST (9.0%):", $totX, $totY + 28, 8.5, 'Helvetica', [100, 116, 139]);
    $pdf->text("INR " . number_format($cgst_amount, 2), $totValX, $totY + 28, 8.5, 'Helvetica', [71, 85, 105], 'right');

    $pdf->text("SGST (9.0%):", $totX, $totY + 44, 8.5, 'Helvetica', [100, 116, 139]);
    $pdf->text("INR " . number_format($sgst_amount, 2), $totValX, $totY + 44, 8.5, 'Helvetica', [71, 85, 105], 'right');

    $pdf->line($totX, $totY + 54, 555, $totY + 54, [203, 213, 225], 1);

    // Total Amount Highlight
    $pdf->rect($totX, $totY + 60, 225, 28, [240, 253, 244], [39, 174, 96], 1.5);
    $pdf->text("FINAL TOTAL PAID:", $totX + 10, $totY + 77, 10, 'Helvetica-Bold', [22, 101, 52]);
    $pdf->text("INR " . number_format($grand_total, 2), $totValX - 10, $totY + 77, 12, 'Helvetica-Bold', [22, 101, 52], 'right');

    // Section 4: Terms & Signatory
    $footerY = 510;
    $pdf->line(40, $footerY, 555, $footerY, [226, 232, 240], 1);

    $pdf->text("TERMS & CONDITIONS & SHOOT GUIDELINES:", 40, $footerY + 16, 8, 'Helvetica-Bold', [71, 85, 105]);
    $pdf->text("1. Photography team arrives 30 mins prior to event. Please ensure venue access is arranged.", 40, $footerY + 28, 7.5, 'Helvetica', [100, 116, 139]);
    $pdf->text("2. Raw photo previews delivered via secure cloud link within 48-72 hours post-event.", 40, $footerY + 38, 7.5, 'Helvetica', [100, 116, 139]);
    $pdf->text("3. High-resolution color corrected digital album delivered within 10 business days.", 40, $footerY + 48, 7.5, 'Helvetica', [100, 116, 139]);
    $pdf->text("4. All intellectual property & copyright protected by Nadkarphotohub.", 40, $footerY + 58, 7.5, 'Helvetica', [100, 116, 139]);

    // Signatory
    $pdf->text("For " . $business_name, 450, $footerY + 16, 8.5, 'Helvetica-Bold', [26, 43, 76], 'center');
    $pdf->text("Rahul Nadkar", 450, $footerY + 44, 9.5, 'Helvetica-Bold', [30, 41, 59], 'center');
    $pdf->text("Lead Photographer & Studio Director", 450, $footerY + 56, 7.5, 'Helvetica', [100, 116, 139], 'center');
    $pdf->text("(Authorized Signatory)", 450, $footerY + 66, 7, 'Helvetica', [148, 163, 184], 'center');

    // Bottom Decorative Bar
    $pdf->rect(0, 830, 595.28, 12, [26, 43, 76]);

    // Save PDF to disk
    $pdf->saveToFile($pdf_absolute_path);

    return [
        'status'     => 'generated',
        'invoice_id' => $new_invoice_id,
        'invoice_no' => $invoice_number,
        'pdf_path'   => $pdf_relative_path,
        'total'      => $grand_total
    ];
}
?>