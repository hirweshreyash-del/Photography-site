# Nadkarphotohub - Event Photography Booking & Razorpay Payment System 📷

A professional, student-level photography booking web platform with **Razorpay Payment Gateway Integration**, **Automatic PDF Invoice Generation**, and an **Admin Payment & Invoice Dashboard**. Built with pure standard PHP, MySQL, HTML5, CSS3, and JavaScript.

---

## 📁 Upgraded Project Structure

```text
photographybooking/
├── .env                        # Environment variables (Razorpay keys, business details)
├── .env.example                # Example environment template
├── config.php                  # Database connection, env loader & auto-schema migration
├── index.php                   # Home page with photography packages & booking form
├── checkout.php                # Booking checkout page with Razorpay payment gateway
├── confirmation.php            # Post-payment confirmation & PDF invoice download
├── my_bookings.php             # Customer dashboard & payment transaction history
├── dashboard.php               # Customer account profile & shoot reservation overview
├── admin_dashboard.php         # Administrator Payment & Invoice Management Dashboard
├── download_invoice.php        # Authorized PDF invoice streaming & download handler
├── webhook.php                 # Verified Razorpay webhook handler (HMAC SHA256)
├── login.php                   # Customer & Admin authentication page
├── signup.php                  # Customer registration page
├── logout.php                  # Session logout script
├── database.sql                # Complete MySQL schema & demo data
├── api/
│   ├── create_order.php        # Backend Razorpay Order creation API
│   └── verify_payment.php      # Server-side payment signature verification API
├── includes/
│   ├── pdf_engine.php          # Zero-dependency Pure-PHP Vector PDF Generator
│   └── invoice_generator.php   # Unique invoice numbering, DB storage & PDF builder
├── invoices/                   # Stored generated PDF invoices (protected)
│   └── .htaccess               # Prevents unauthorized direct file access
├── css/
│   └── style.css               # Clean responsive styling & print media rules
└── js/
    └── main.js                 # Frontend form validation
```

---

## 🚀 How to Run in XAMPP

1. Open **XAMPP Control Panel** and start **Apache** and **MySQL**.
2. Open your web browser and navigate to:
   ```text
   http://localhost/photographybooking
   ```
3. **Automatic Database Migration**:
   On your first page visit, `config.php` automatically creates the database `nadkarphotohub` and tables (`users`, `bookings`, `payments`, `invoices`) and seeds default demo accounts.

---

## 💳 Razorpay Payment Gateway Integration

### Supported Payment Methods:
- **UPI**: Google Pay, PhonePe, Paytm, BHIM, CRED, and any UPI VPA ID.
- **Credit & Debit Cards**: Visa, MasterCard, RuPay, Maestro.
- **Net Banking**: All major Indian banks (SBI, HDFC, ICICI, Axis, Kotak, PNB, etc.).
- **Wallets**: Popular Indian digital wallets.

### Payment Flow:
1. Customer selects a photography package on `index.php` and submits the shoot booking form.
2. The system calculates the base package fee + 18% GST and saves a `Pending` booking.
3. The customer is redirected to `checkout.php?booking_id={id}`.
4. Clicking **Pay Now** calls `api/create_order.php` on the backend to create an official Razorpay order.
5. The Razorpay checkout modal opens securely.
6. Upon payment completion, Razorpay returns the transaction details to `api/verify_payment.php`.
7. The server verifies the HMAC SHA256 payment signature using `RAZORPAY_KEY_SECRET`.
8. Once verified:
   - Payment record is updated to `Paid` in the database.
   - Booking status is updated to `Confirmed` and `payment_status = 'Paid'`.
   - An **Official Tax Invoice** with unique sequential number (`INV-YYYY-XXXX`) and PDF file is automatically generated!
   - Customer is redirected to `confirmation.php` with direct **Download PDF Invoice** and **View/Print** buttons.

---

## 📄 Automatic PDF Invoice Generation

- **Zero External Dependencies**: Powered by `includes/pdf_engine.php` (pure PHP, no Composer or node required).
- **Unique Invoice Numbering**: `INV-YYYY-XXXX` sequential invoice numbers.
- **Duplicate Prevention**: Database checks ensure no duplicate invoices are created for the same payment.
- **Invoice Content**:
  - Studio Name (`Nadkarphotohub`), Tagline, Address, Phone, Email, GSTIN.
  - Customer Details: Name, Email, Phone, Client ID.
  - Shoot Details: Photography Package, Event Date, Location, Notes.
  - Itemized Charges: Base Package Price, Additional Services, Subtotal, 18% GST (CGST 9% + SGST 9%), Grand Total.
  - Payment Details: Razorpay Transaction ID, Order ID, Method, Date, and green `PAID` stamp.
  - Authorized Signatory stamp for lead photographer Rahul Nadkar.
- **Access Control**: Only the customer who owns the booking or an authorized administrator can view or download the invoice (`download_invoice.php`).

---

## 🛡️ Admin Payment & Invoice Dashboard

- **URL**: `http://localhost/photographybooking/admin_dashboard.php`
- **Protected by Role-Based Access Control**: Only users with role `admin` can access.
- **Features**:
  - Financial Metrics: Total Net Revenue, Successful Payments count, Pending Payments count, Failed Transactions, and Refunds.
  - Search & Filters: Search by customer name, email, booking ID, transaction ID, or invoice number. Filter by payment status and date range.
  - One-click PDF Invoice Download and Print for any booking.
  - One-click Refund processing with database update.

---

## 🧪 Demo Credentials & Testing

### 1. Test Accounts:
- **Client Account**:
  - Email: `student@gmail.com`
  - Password: `password123`
- **Administrator Account**:
  - Email: `admin@nadkarphotohub.com`
  - Password: `Admin@123`

### 2. Testing Payment in Developer Test Mode:
- On `checkout.php`, the page detects `RAZORPAY_MODE=test` and displays the **Developer Test Sandbox**:
  - Click **✓ Simulate Successful Payment**: Tests order creation, server-side signature verification, database updates, automatic PDF generation, and redirect to confirmation.
  - Click **✗ Simulate Payment Failure**: Tests failure notification and the **Retry Payment** option.
