<?php
// index.php - Home Page for Nadkarphotohub
require_once 'config.php';

$booking_msg = "";
$booking_error = "";

// Check if user is logged in
$is_logged_in = is_logged_in();
$user_name    = $_SESSION['user_name'] ?? '';

// Handle Event Shoot Booking submission
if ($_SERVER["REQUEST_METHOD"] == "POST" && isset($_POST['submit_booking'])) {
    if (!$is_logged_in) {
        header("Location: login.php");
        exit;
    }

    $user_id    = $_SESSION['user_id'];
    $event_type = trim($_POST['event_type']);
    $event_date = trim($_POST['event_date']);
    $location   = trim($_POST['location']);
    $notes      = trim($_POST['notes']);

    if (empty($event_type) || empty($event_date) || empty($location)) {
        $booking_error = "Please fill in all required booking details.";
    } else {
        // Calculate package pricing and GST
        $pricing     = get_event_pricing($event_type);
        $base_price  = $pricing['base_price'];
        $tax_amount  = round(($base_price * 18) / 100, 2);
        $total_amount= $base_price + $tax_amount;

        $stmt = $conn->prepare("
            INSERT INTO bookings 
            (user_id, event_type, event_date, location, notes, package_price, tax_amount, total_amount, payment_status, status) 
            VALUES 
            (:uid, :etype, :edate, :loc, :notes, :base, :tax, :tot, 'Pending', 'Pending')
        ");
        $result = $stmt->execute([
            'uid'   => $user_id,
            'etype' => $event_type,
            'edate' => $event_date,
            'loc'   => $location,
            'notes' => $notes,
            'base'  => $base_price,
            'tax'   => $tax_amount,
            'tot'   => $total_amount
        ]);

        if ($result) {
            $new_booking_id = $conn->lastInsertId();
            // Seamlessly redirect to payment checkout
            header("Location: checkout.php?booking_id=" . $new_booking_id);
            exit;
        } else {
            $booking_error = "Failed to submit booking. Please try again.";
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Nadkarphotohub - Event Photography Booking</title>
    <link rel="stylesheet" href="css/style.css">
    <style>
        .price-badge-tag {
            display: inline-block;
            background: #e0f2fe;
            color: #0284c7;
            font-size: 0.85rem;
            font-weight: bold;
            padding: 4px 10px;
            border-radius: 20px;
            margin: 8px 0 12px 0;
        }
        .event-card .btn-book-card {
            display: inline-block;
            margin-top: 14px;
            padding: 7px 14px;
            font-size: 0.85rem;
            border-radius: 4px;
            background: #1a2b4c;
            color: #fff;
            font-weight: 600;
            transition: all 0.2s;
        }
        .event-card .btn-book-card:hover {
            background: #27ae60;
        }
    </style>
</head>
<body>

    <!-- Header Navbar -->
    <header class="navbar">
        <div class="container nav-container">
            <a href="index.php" class="logo">📷 Nadkarphotohub</a>
            <nav>
                <a href="#home">Home</a>
                <a href="#events">Event Types</a>
                <a href="#booking">Book Shoot</a>
                <a href="#contact">Contact</a>

                <?php if ($is_logged_in): ?>
                    <span class="user-badge">Hi, <?php echo htmlspecialchars($user_name); ?></span>
                    <a href="my_bookings.php" class="btn-secondary">My Bookings</a>
                    <?php if (is_admin()): ?>
                        <a href="admin_dashboard.php" class="btn-primary" style="background: #f1c40f; color: #1a2b4c;">Admin Panel</a>
                    <?php endif; ?>
                    <a href="logout.php" class="btn-danger">Logout</a>
                <?php else: ?>
                    <a href="login.php" class="btn-secondary">Login</a>
                    <a href="signup.php" class="btn-primary">Sign Up</a>
                <?php endif; ?>
            </nav>
        </div>
    </header>

    <!-- Main Hero Banner -->
    <section id="home" class="hero-banner">
        <div class="container hero-content">
            <h1>Nadkarphotohub</h1>
            <h3>Event Photography & Videography Booking</h3>
            <p>Capturing your beautiful moments for weddings, birthdays, pre-wedding shoots, and special events with professional equipment and cinematic editing.</p>
            
            <?php if ($is_logged_in): ?>
                <a href="#booking" class="btn-primary">Book Event Shoot Now</a>
                <a href="my_bookings.php" class="btn-secondary" style="margin-left: 10px;">View My Bookings</a>
            <?php else: ?>
                <a href="login.php" class="btn-primary">Login to Book Shoot</a>
                <a href="signup.php" class="btn-secondary" style="margin-left: 10px;">Create Account</a>
            <?php endif; ?>
        </div>
    </section>

    <!-- Notification Messages -->
    <div class="container" style="margin-top: 20px;">
        <?php if (isset($_GET['msg']) && $_GET['msg'] == 'login_success'): ?>
            <div class="alert success">Welcome back, <?php echo htmlspecialchars($user_name); ?>! You are now logged in.</div>
        <?php endif; ?>

        <?php if (isset($_GET['msg']) && $_GET['msg'] == 'logged_out'): ?>
            <div class="alert success">You have been logged out successfully.</div>
        <?php endif; ?>
    </div>

    <!-- Event Photography Services Section -->
    <section id="events" class="section">
        <div class="container">
            <h2 class="section-title">Different Types of Event Shoots We Offer</h2>
            <div class="events-grid">
                
                <div class="event-card">
                    <div class="event-icon">💍</div>
                    <h3>Wedding Photography</h3>
                    <div class="price-badge-tag">₹25,000 + GST</div>
                    <p>Complete wedding ritual coverage, candid photography, traditional portraits, and cinematic 4K video recording.</p>
                    <a href="#booking" onclick="selectEvent('Wedding Photography')" class="btn-book-card">Book Wedding Shoot</a>
                </div>

                <div class="event-card">
                    <div class="event-icon">👩‍❤️‍👨</div>
                    <h3>Pre-Wedding Shoots</h3>
                    <div class="price-badge-tag">₹15,000 + GST</div>
                    <p>Outdoor romantic pre-wedding shoots at beach, fort, or garden locations with cinematic drone editing.</p>
                    <a href="#booking" onclick="selectEvent('Pre-Wedding Shoot')" class="btn-book-card">Book Pre-Wedding</a>
                </div>

                <div class="event-card">
                    <div class="event-icon">🎂</div>
                    <h3>Birthday Parties</h3>
                    <div class="price-badge-tag">₹8,000 + GST</div>
                    <p>Fun and colorful party photography for kids, adults, cake cutting ceremonies, and family candid moments.</p>
                    <a href="#booking" onclick="selectEvent('Birthday Party')" class="btn-book-card">Book Birthday Party</a>
                </div>

                <div class="event-card">
                    <div class="event-icon">👔</div>
                    <h3>Corporate Events</h3>
                    <div class="price-badge-tag">₹12,000 + GST</div>
                    <p>Official coverage for company seminars, annual office functions, award nights, and keynote launches.</p>
                    <a href="#booking" onclick="selectEvent('Corporate Event')" class="btn-book-card">Book Corporate</a>
                </div>

                <div class="event-card">
                    <div class="event-icon">👶</div>
                    <h3>Baby & Family Shoots</h3>
                    <div class="price-badge-tag">₹6,000 + GST</div>
                    <p>Cute newborn baby photoshoots with creative props, maternity photos, and family portrait sessions.</p>
                    <a href="#booking" onclick="selectEvent('Baby & Family Shoot')" class="btn-book-card">Book Baby Shoot</a>
                </div>

            </div>
        </div>
    </section>

    <!-- Event Booking Form Section -->
    <section id="booking" class="section bg-light">
        <div class="container">
            <h2 class="section-title">Book an Event Shoot</h2>
            
            <div class="booking-card">
                <?php if (!empty($booking_msg)): ?>
                    <div class="alert success"><?php echo htmlspecialchars($booking_msg); ?></div>
                <?php endif; ?>

                <?php if (!empty($booking_error)): ?>
                    <div class="alert error"><?php echo htmlspecialchars($booking_error); ?></div>
                <?php endif; ?>

                <?php if (!$is_logged_in): ?>
                    <div class="alert info">
                        <strong>Note:</strong> Please <a href="login.php" style="color: #0056b3; font-weight: bold;">Login</a> or <a href="signup.php" style="color: #0056b3; font-weight: bold;">Sign Up</a> first to book an event shoot!
                    </div>
                <?php else: ?>
                    <form action="index.php#booking" method="POST">
                        <div class="form-group">
                            <label>Photography Package:</label>
                            <select name="event_type" id="eventSelect" required>
                                <option value="">-- Select Event Package --</option>
                                <option value="Wedding Photography">Wedding Photography (₹25,000 + 18% GST)</option>
                                <option value="Pre-Wedding Shoot">Pre-Wedding Shoot (₹15,000 + 18% GST)</option>
                                <option value="Birthday Party">Birthday Party (₹8,000 + 18% GST)</option>
                                <option value="Corporate Event">Corporate Event (₹12,000 + 18% GST)</option>
                                <option value="Baby & Family Shoot">Baby & Family Shoot (₹6,000 + 18% GST)</option>
                            </select>
                        </div>

                        <div class="form-group">
                            <label>Event Date:</label>
                            <input type="date" name="event_date" min="<?php echo date('Y-m-d'); ?>" required>
                        </div>

                        <div class="form-group">
                            <label>Event Location / Venue Address:</label>
                            <input type="text" name="location" placeholder="e.g. Hotel Sunshine Hall, MG Road" required>
                        </div>

                        <div class="form-group">
                            <label>Special Instructions / Notes:</label>
                            <textarea name="notes" rows="3" placeholder="Any special timing, drone coverage, or theme requirements..."></textarea>
                        </div>

                        <button type="submit" name="submit_booking" class="btn-primary" style="width: 100%; font-size: 1.05rem; padding: 12px;">
                            Proceed to Payment Checkout →
                        </button>
                    </form>
                <?php endif; ?>
            </div>
        </div>
    </section>

    <!-- Contact Section -->
    <section id="contact" class="section">
        <div class="container">
            <h2 class="section-title">Contact Studio</h2>
            <div class="contact-box">
                <p><strong>Shop Name:</strong> Nadkarphotohub</p>
                <p><strong>Owner / Lead Photographer:</strong> Rahul Nadkar</p>
                <p><strong>Phone:</strong> +91 98765 43210</p>
                <p><strong>Email:</strong> contact@nadkarphotohub.com</p>
                <p><strong>Address:</strong> Shop No. 5, Near Central Market, Main Road, City</p>
            </div>
        </div>
    </section>

    <!-- Footer -->
    <footer class="footer">
        <div class="container">
            <p>&copy; <?php echo date('Y'); ?> Nadkarphotohub - Event Photography Booking. Simple Student Web Project (PHP & MySQL).</p>
        </div>
    </footer>

    <script>
        function selectEvent(eventType) {
            const select = document.getElementById('eventSelect');
            if (select) {
                for (let i = 0; i < select.options.length; i++) {
                    if (select.options[i].value === eventType) {
                        select.selectedIndex = i;
                        break;
                    }
                }
            }
        }
    </script>

</body>
</html>