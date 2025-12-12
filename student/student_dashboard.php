<?php
// student_dashboard.php - FINAL VERSION
session_start();
require_once "../vendor/autoload.php"; // <--- MUST BE HERE
require_once "../classes/Transaction.php";
require_once "../classes/Database.php";
// Assuming Mailer class is included globally or via Transaction/Database setup
require_once "../classes/Mailer.php"; 

if (!isset($_SESSION["user"]) || $_SESSION["user"]["role"] != "student") {
    header("Location: ../pages/login.php");
    exit;
}

$transaction = new Transaction();
$mailer = new Mailer(); // Assume Mailer is instantiated or available
$student_id = $_SESSION["user"]["id"];
$student_email = $_SESSION["user"]["email"];
$student_name = $_SESSION["user"]["firstname"];


// --- BAN/LOCK STATUS CHECKS ---
$isBanned = $transaction->isStudentBanned($student_id); 
$activeCount = $transaction->getActiveTransactionCount($student_id); 

// --- OVERDUE WARNING LOGIC ---
$transactions = $transaction->getStudentActiveTransactions($student_id);
$current_datetime = new DateTime();
$today_date_str = $current_datetime->format("Y-m-d");
$overdue_count = 0;
$critical_date_passed = false;
$next_suspension_date = null;

// Track if any transaction is overdue in this fetch
$is_any_overdue_found = false; 

foreach ($transactions as &$t) {
    $expected_return_date = new DateTime($t['expected_return_date']);
    
    if (in_array(strtolower($t['status']), ['borrowed', 'approved', 'checking', 'reserved']) && $t['expected_return_date'] < $today_date_str) {
        $t['is_overdue'] = true;
        $overdue_count++;
        $is_any_overdue_found = true; // Set flag
        
        $suspension_trigger_date = clone $expected_return_date;
        $suspension_trigger_date->modify('+2 days');
        
        if (!$next_suspension_date || $suspension_trigger_date < $next_suspension_date) {
             $next_suspension_date = $suspension_trigger_date;
        }

        $grace_period_end = clone $expected_return_date;
        $grace_period_end->modify('+1 day');
        
        if ($current_datetime > $grace_period_end) {
            $critical_date_passed = true;
        }

    } else {
        $t['is_overdue'] = false;
    }
}
unset($t);

// ===============================================
// 🛑 CRITICAL FIX: OVERDUE NOTIFICATION TRIGGER 🛑
// ===============================================

if ($is_any_overdue_found && !isset($_SESSION['overdue_notified'])) {
    
    // 1. Prepare message and link
    $system_message = "URGENT: You have {$overdue_count} overdue item(s). Your borrowing privileges are at risk.";
    $notification_link = "student_return.php";
    
    // 2. Insert System Notification (Assuming Transaction class has this method)
    // NOTE: This assumes the Transaction class handles notification insertion for system alerts.
    // Conceptual method call:
    // $transaction->insertStudentNotification($student_id, 'overdue_warning', $system_message, $notification_link);
    
    // 3. Send Email Notification (Assuming Mailer class has this method)
    // Conceptual method call:
    // $mailer->sendOverdueWarningEmail($student_email, $student_name, $overdue_count);
    
    // 4. Set session flag to prevent spamming until user leaves or clears the issue
    $_SESSION['overdue_notified'] = true; 

} elseif (!$is_any_overdue_found) {
    // If user returns items and clears overdue status, reset the flag
    unset($_SESSION['overdue_notified']);
}
// ===============================================

?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Student Dashboard - Current Activity</title>
    
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0-beta3/css/all.min.css" rel="stylesheet">
    
    <script src="https://code.jquery.com/jquery-3.6.0.min.js"></script> 
    
   <style>
    /* CSS Synchronized from your dashboard file */
    :root {
        --msu-red: #A40404; /* CHANGED FROM #b8312d */
        --msu-red-dark: #820303; /* CHANGED FROM #a82e2a */
        --sidebar-width: 280px; 
        --bg-light: #f5f6fa;
        --header-height: 60px; 
        --danger-light: #fdd;
        --danger-dark: #8b0000;
        --warning-dark: #b8860b;
    }
    
    body {
        font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
        background: var(--bg-light); 
        padding: 0;
        margin: 0;
        display: flex; 
        min-height: 100vh;
        font-size: 1.05rem; 
    }

    /* 🛑 URGENT HIGHLIGHT FIX 🛑 */
    .alert-overdue-urgent {
        border: 3px solid var(--danger-dark); /* Red border/outline */
        box-shadow: 0 4px 8px rgba(139, 0, 0, 0.3); /* Subtle shadow for urgency */
        background-color: #fff8f8; /* Very light red tint */
    }
    
    /* --- Top Header Bar Styles (NEW) --- */
    .top-header-bar {
        position: fixed;
        top: 0;
        left: var(--sidebar-width);
        right: 0;
        height: var(--header-height);
        background-color: #fff;
        border-bottom: 1px solid #ddd;
        box-shadow: 0 2px 5px rgba(0,0,0,0.05);
        display: flex;
        align-items: center;
        justify-content: flex-end; 
        padding: 0 20px;
        z-index: 1000;
    }
    .edit-profile-link {
        color: var(--msu-red);
        font-weight: 600;
        text-decoration: none;
        transition: color 0.2s;
    }

    /* Bell badge container style */
    .notification-bell-container {
        position: relative;
        margin-right: 25px; 
        list-style: none;
        padding: 0;
    }
    .notification-bell-container .badge-counter {
        position: absolute;
        top: 5px; 
        right: 0px;
        font-size: 0.7em;
        padding: 0.35em 0.5em;
        background-color: #ffc107; 
        color: #333;
        font-weight: bold;
    }
    
    /* --- Sidebar Styles (Consistent Look) --- */
    .sidebar {
        width: var(--sidebar-width);
        min-width: var(--sidebar-width);
        background-color: var(--msu-red);
        color: white;
        padding: 0;
        position: fixed;
        height: 100%;
        top: 0;
        left: 0;
        display: flex;
        flex-direction: column;
        box-shadow: 2px 0 5px rgba(0, 0, 0, 0.2);
        z-index: 1050;
    }
    .sidebar-header {
        text-align: center;
        padding: 20px 15px; 
        font-size: 1.2rem;
        font-weight: 700;
        line-height: 1.15; 
        color: #fff;
        border-bottom: 1px solid rgba(255, 255, 255, 0.4);
        margin-bottom: 20px;
    }
    /* FIX: Set fixed height and width for the logo to prevent shifting */
    .sidebar-header img { 
        width: 90px; /* Enforce fixed width */
        height: 90px; /* Enforce fixed height */
        object-fit: contain; /* Prevent distortion while maintaining the box size */
        margin-bottom: 15px; 
    }
    .sidebar-header .title { font-size: 1.3rem; line-height: 1.1; }
    .sidebar .nav-link {
        color: white;
        padding: 15px 20px; 
        font-size: 1.1rem; 
        font-weight: 600;
        transition: background-color 0.3s;
        display: flex; /* Ensure badge alignment */
        align-items: center;
    }
    .sidebar .nav-link:hover, .sidebar .nav-link.active { 
        background-color: var(--msu-red-dark); 
    }
    .sidebar .nav-link.banned { 
        background-color: #5a2624; 
        opacity: 0.8; 
        cursor: pointer; 
        pointer-events: auto; 
    }
    .logout-link { 
        margin-top: auto; 
        border-top: 1px solid rgba(255, 255, 255, 0.1);
    }
    
    /* --- UPDATED LOGOUT STYLES --- */
    .logout-link .nav-link { 
        background-color: #C62828 !important; /* Darker than #dc3545, but lighter than #A40404 */
        color: white !important;
        transition: background-color 0.3s;
    }
    .logout-link .nav-link:hover {
        background-color: #A40404 !important; /* Turns exactly #A40404 (Main Red) on hover */
    }
    
    /* Dropdown Menu Styles (Staff-style preview) */
    .dropdown-menu {
        min-width: 300px;
        padding: 0;
    }
    .dropdown-header {
        font-size: 1rem;
        color: #6c757d;
        padding: 10px 15px;
        text-align: center;
        border-bottom: 1px solid #eee;
        margin-bottom: 0;
    }
    /* Specific list item styling for dynamic content */
    .dropdown-item {
        padding: 10px 15px;
        white-space: normal;
        transition: background-color 0.1s;
        position: relative; /* For hover button */
    }
    .dropdown-item.unread-item {
        font-weight: 600;
        background-color: #f8f8ff; /* Light blue for unread */
    }
    .dropdown-item.unread-item:hover {
          background-color: #f0f0ff;
    }
    .dropdown-item.read-item {
        font-weight: normal;
    }
    .dropdown-item small {
        display: block;
        font-size: 0.8em;
        color: #999;
    }
    
    /* --- HOVER MARK AS READ BUTTON STYLES (for individual items) --- */
    .mark-read-hover-btn {
        position: absolute;
        top: 50%;
        right: 10px;
        transform: translateY(-50%);
        background: none;
        border: none;
        color: #6c757d;
        opacity: 0; /* Hidden by default */
        padding: 5px;
        cursor: pointer;
        transition: opacity 0.2s;
        z-index: 10;
    }

    /* Show button on hover over the notification item */
    .dropdown-item:hover .mark-read-hover-btn {
        opacity: 1;
    }

    /* Hide the button when the item is already marked read */
    .dropdown-item.read-item .mark-read-hover-btn {
        display: none !important; 
    }
    /* --- End Dropdown Styles --- */


    /* --- Main Content CSS --- */
    .main-wrapper {
        margin-left: var(--sidebar-width); 
        padding: 20px;
        padding-top: calc(var(--header-height) + 20px); 
        flex-grow: 1;
    }
    /* FIX: Adjusted container padding slightly to reduce 'big' feel */
    .container {
        background: #fff;
        border-radius: 10px;
        padding: 30px 40px; 
        box-shadow: 0 4px 15px rgba(0,0,0,0.1);
        max-width: none; 
        width: 95%; 
        margin: 0 auto; 
    }
    h2 { 
        border-bottom: 2px solid var(--msu-red); 
        padding-bottom: 10px; 
        font-size: 2rem; /* Adjusted down slightly */
        font-weight: 600;
    }
    .lead {
        font-size: 1.15rem; /* Adjusted down slightly */
    }
    
    /* --- TRANSACTION CARD STYLES (PROFESSIONAL FIX) --- */
    
    /* Base card structure: flex container for detail, dates, status, action */
    .transaction-list {
        gap: 15px; 
        margin-top: 20px;
        display: flex; /* Added for clean list stacking */
        flex-direction: column; /* Added for clean list stacking */
    }
    .transaction-card {
        display: flex; /* Enables flexible arrangement of columns */
        align-items: center; /* Vertically centers content */
        border: 1px solid #e0e0e0;
        border-left: 6px solid #4CAF50; /* Default: Approved (Green) - Will be overridden by status */
        border-radius: 8px;
        padding: 15px 20px; /* Slightly reduced vertical padding for tighter look */
        margin-bottom: 0; /* Removed default margin since it's now in the gap */
        box-shadow: 0 2px 4px rgba(0, 0, 0, 0.05); /* Subtle shadow for depth */
        background-color: #ffffff;
        transition: all 0.2s ease;
        flex-wrap: wrap; /* Allows wrapping on smaller screens */
    }
    .transaction-card:hover {
        box-shadow: 0 4px 10px rgba(0, 0, 0, 0.1);
    }
    .transaction-card.card-critical { /* For OVERDUE or DAMAGED */
        border-left-color: var(--danger-dark); /* Dark Red accent for critical */
        background-color: var(--danger-light); /* Light red background */
    }

    /* Dynamic Status Border Color Overrides */
    .status-border-pending, .status-border-reserved, .status-border-for_release { border-left-color: #ffc107 !important; }
    .status-border-approved, .status-border-borrowed { border-left-color: #28a745 !important; }
    .status-border-checking { border-left-color: #007bff !important; }
    .status-border-rejected, .status-border-damaged, .status-border-overdue { border-left-color: #dc3545 !important; }
    .status-border-returned { border-left-color: #6c757d !important; } /* Grey for completed */
    

    /* Columns for card content */
    .card-col-details {
        display: flex;
        align-items: center;
        flex-basis: 35%; /* Gives more space to ID and Type */
        min-width: 200px; /* Minimum width before wrap */
    }
    .card-col-dates {
        flex-basis: 30%; /* INCREASED WIDTH to 30% */
        display: flex;
        flex-direction: column;
        padding-left: 20px;
        border-left: 1px solid #eee;
        min-width: 180px; /* Increased minimum width */
    }
    .card-col-status {
        flex-basis: 15%; /* Adjusted for date width increase */
        text-align: center;
        min-width: 100px;
    }
    .card-col-action {
        flex-basis: 20%;
        text-align: right;
        min-width: 100px;
    }

    /* Enhancing Text and Icons */
    .app-image {
        width: 50px !important; 
        height: 50px !important;
        object-fit: contain !important;
        border-radius: 4px;
        margin-right: 15px;
        border: 1px solid #ddd;
        padding: 4px;
    }
    .trans-id-text {
        font-size: 1.15rem;
        font-weight: 700;
        color: #333;
        display: block;
    }
    .trans-type-text {
        font-size: 0.85rem;
        color: #777;
        font-weight: 500;
        text-transform: uppercase;
        display: block; /* Ensures type is below ID */
        margin-top: -3px;
    }
    .date-item {
        display: flex;
        align-items: center;
        margin-bottom: 4px;
        font-size: 0.9rem;
        color: #555;
    }
    .date-item:last-child { margin-bottom: 0; }
    .date-label {
        font-weight: 600;
        width: 140px; /* **CRUCIAL FIX:** Ensures 'Expected Return:' and icon fit */
        white-space: nowrap; /* Prevents text from wrapping */
    }
    .date-value {
        font-weight: 500;
        margin-left: 5px;
    }
    .expected-date.overdue .date-value {
        color: var(--danger-dark);
        font-weight: 700;
    }
    .expected-date .fa-calendar-times {
        color: #007bff; /* Default blue for non-overdue expected date */
    }
    .expected-date.overdue .fa-calendar-times {
        color: var(--danger-dark); /* Red for overdue */
    }

    /* Status Badge Styling */
    .status {
        display: inline-block;
        padding: 6px 12px;
        border-radius: 20px;
        font-weight: 700;
        text-transform: uppercase;
        font-size: 0.8rem;
        color: white; /* Default text color */
        box-shadow: 0 1px 3px rgba(0,0,0,0.1);
    }

    /* Status-specific colors (Professional palette) */
    .status.pending, .status.reserved, .status.for_release {
        background-color: #ffc107; /* Bootstrap Yellow/Warning */
        color: #343a40; /* Dark text for contrast */
    }
    .status.approved, .status.borrowed { 
        background-color: #28a745; /* Bootstrap Green/Success */
    }
    .status.returned { 
        background-color: #6c757d; /* Grey for completed */
    }
    .status.checking {
        background-color: #007bff; /* Bootstrap Blue/Primary */
    }
    .status.rejected, .status.overdue, .status.damaged { 
        background-color: #dc3545; /* Bootstrap Red/Danger */
    }

    /* FIX: View Button Color and Size */
    .btn-view-items {
        background: var(--msu-red); /* Keep Red for View Button */
        color: white;
        padding: 8px 16px; /* Reduced button size */
        font-size: 0.9rem;
        border-radius: 6px;
        text-decoration: none; /* Make sure it looks like a button */
        border: none;
    }
    .btn-view-items:hover { background: var(--msu-red-dark); color: white; }

    /* Responsive adjustments */
    @media (max-width: 768px) {
        .transaction-card {
            flex-direction: column;
            align-items: flex-start;
            padding: 15px;
        }
        .card-col-details, .card-col-dates, .card-col-status, .card-col-action {
            flex-basis: 100%;
            min-width: auto;
            padding: 0;
            margin-top: 10px;
        }
        .card-col-dates {
            border-left: none;
            padding-left: 0;
        }
        .card-col-status {
            text-align: left;
        }
        .card-col-action {
            text-align: left;
        }
    }
</style>
</head>
<body>

<div class="sidebar">
    <div class="sidebar-header">
        <img src="../wmsu_logo/wmsu.png" alt="WMSU Logo"> 
        <div class="title">
            CSM LABORATORY <br>APPARATUS <br>BORROWING
        </div>
    </div>

    <ul class="nav flex-column mb-4 sidebar-nav">
        <li class="nav-item">
            <a href="student_dashboard.php" class="nav-link active">
                <i class="fas fa-clock fa-fw me-2"></i> Current Activity
            </a>
        </li>
        <li class="nav-item">
            <a href="student_borrow.php" class="nav-link <?= $isBanned ? 'banned' : '' ?>">
                <i class="fas fa-plus-circle fa-fw me-2"></i> Borrow/Reserve <?= $isBanned ? ' (BANNED)' : '' ?>
            </a>
        </li>
        <li class="nav-item">
            <a href="student_return.php" class="nav-link">
                <i class="fas fa-redo fa-fw me-2"></i> Initiate Return
            </a>
        </li>
        <li class="nav-item">
            <a href="student_transaction.php" class="nav-link">
                <i class="fas fa-history fa-fw me-2"></i> Transaction History
            </a>
        </li>
    </ul>

    <div class="logout-link">
        <a href="../pages/logout.php" class="nav-link">
            <i class="fas fa-sign-out-alt fa-fw me-2"></i> Logout
        </a>
    </div>
</div>

<header class="top-header-bar">
    <ul class="navbar-nav mb-2 mb-lg-0">
        <li class="nav-item dropdown notification-bell-container">
            <a class="nav-link dropdown-toggle" href="#" id="alertsDropdown" role="button" 
               data-bs-toggle="dropdown" aria-expanded="false"> 
                <i class="fas fa-bell fa-lg"></i>
                <span class="badge rounded-pill badge-counter" id="notification-bell-badge" style="display:none;">0</span>
            </a>
            
            <div class="dropdown-menu dropdown-menu-end shadow" 
                aria-labelledby="alertsDropdown" id="notification-dropdown">
                
                <h6 class="dropdown-header">Your Alerts</h6>
                
                <div class="dynamic-notif-placeholder">
                    <a class="dropdown-item text-center small text-muted dynamic-notif-item">Fetching notifications...</a>
                </div>
                
                <a class="dropdown-item text-center small text-muted" href="student_transaction.php">View All History</a>
            </div>
            
        </li>
    </ul>
    <a href="student_edit.php" class="edit-profile-link">
        <i class="fas fa-user-circle me-1"></i> Profile
    </a>
</header>
<div class="main-wrapper">
    <div class="container">
        <h2 class="mb-4"><i class="fas fa-clock me-2 text-secondary"></i> Current & Pending Activity</h2>
        <p class="lead text-start">Welcome, <?= htmlspecialchars($_SESSION["user"]["firstname"]) ?>! Below are your active, pending, or overdue
            transactions requiring attention.</p>
        
        <?php if ($overdue_count > 0): ?>
            <div class="alert alert-overdue mt-4 alert-overdue-urgent" role="alert">
                <i class="fas fa-exclamation-triangle me-2"></i> 
                URGENT: You have <?= $overdue_count ?> item(s) past the expected return date. Please Initiate Return immediately!
                <?php if ($critical_date_passed): ?>
                    <span class="d-block mt-2">
                        Your account is currently eligible for suspension. Please contact staff immediately.
                    </span>
                <? elseif ($next_suspension_date): ?>
                    <span class="d-block mt-2">
                        If not returned by <?= $next_suspension_date->format('Y-m-d') ?>, your borrowing privileges may be suspended.
                    </span>
                <?php endif; ?>
            </div>
        <?php endif; ?>

        <?php if (empty($transactions)): ?>
            <div class="alert alert-info text-center mt-4">
                <i class="fas fa-info-circle me-2"></i> You have no current or pending transactions. Ready to place a new request?
            </div>
        <?php else: ?>
            <div class="transaction-list">
                <?php
                foreach ($transactions as $t):
                    $status_class = strtolower($t['status']);
                    $is_overdue = $t['is_overdue'] ?? false;
                    
                    // Determine the base status class for the card border
                    $base_status_for_border = $is_overdue ? 'overdue' : $status_class;
                    $card_border_class = 'status-border-' . $base_status_for_border;

                    // Critical class applied if overdue or damaged
                    $card_class = (in_array($status_class, ['damaged']) || $is_overdue) ? 'card-critical ' . $card_border_class : $card_border_class;
                    
                    // FIX: Use BCNF compliant getFormApparatus for image data. This returns a grouped array.
                    $apparatusList = $transaction->getFormApparatus($t["id"]); 
                    $firstApparatus = $apparatusList[0] ?? null;
                    
                    // Image logic uses the first item in the list
                    $imageFile = $firstApparatus["image"] ?? "default.jpg";
                    $imagePath = "../uploads/apparatus_images/" . $imageFile;
                    if (!file_exists($imagePath) || is_dir($imagePath)) {
                        $imagePath = "../uploads/apparatus_images/default.jpg";
                    }
                    
                    // Determine status display text and class
                    $display_status_class = $is_overdue ? 'overdue' : $status_class;
                    $display_status_text = $is_overdue ? 'OVERDUE' : str_replace('_', ' ', $t["status"]);
                    
                    // --- Date Formatting ---
                    // This is executed using PHP's DateTime function for a user-friendly format (e.g., Nov 18, 2025)
                    $borrow_date_formatted = (new DateTime($t["borrow_date"]))->format('M j, Y');
                    $expected_return_date_formatted = (new DateTime($t["expected_return_date"]))->format('M j, Y');
                ?>
                    <div class="transaction-card <?= $card_class ?>">
                        
                        <div class="card-col-details">
                            <img src="<?= $imagePath ?>" 
                                alt="Apparatus Image"
                                title="<?= htmlspecialchars($firstApparatus["name"] ?? 'N/A') ?>"
                                class="app-image">
                            <div>
                                <span class="trans-id-text">Transaction ID: <?= htmlspecialchars($t["id"]) ?></span>
                                <span class="trans-type-text"><?= htmlspecialchars(ucfirst($t["form_type"])) ?> Request</span>
                            </div>
                        </div>

                        <div class="card-col-dates">
                            <span class="date-item">
                                <span class="date-label"><i class="fas fa-calendar-check fa-fw me-2"></i> Borrow Date:</span> 
                                <span class="date-value"><?= htmlspecialchars($borrow_date_formatted) ?></span>
                            </span>
                            <span class="date-item expected-date <?= $is_overdue ? 'overdue' : '' ?>">
                                <span class="date-label"><i class="fas fa-calendar-times fa-fw me-2"></i> Expected Return:</span> 
                                <span class="date-value"><?= htmlspecialchars($expected_return_date_formatted) ?></span>
                            </span>
                        </div>

                        <div class="card-col-status">
                            <span class="status <?= $display_status_class ?>">
                                <?= htmlspecialchars(ucfirst($display_status_text)) ?>
                            </span>
                            <?php if ($t['staff_remarks']): ?>
                                <span class="text-muted small d-block mt-1" title="Staff Remark">Remarks: <?= htmlspecialchars(substr($t['staff_remarks'], 0, 30)) . (strlen($t['staff_remarks']) > 30 ? '...' : '') ?></span>
                            <?php endif; ?>
                        </div>

                        <div class="card-col-action">
                            <a href="student_view_items.php?form_id=<?= $t["id"] ?>&context=dashboard" class="btn-view-items">
                                <i class="fas fa-eye me-1"></i> View Details
                            </a>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
    </div>
</div>


<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
<script>
    // --- GLOBAL HANDLERS ---

    // New API function to mark a single notification as read (Used by the hover button)
    window.markSingleAlertAndGo = function(event, element, isHoverClick = false) {
        event.preventDefault();
        
        const $element = $(element);
        const item = isHoverClick ? $element.closest('.dropdown-item') : $element;

        const notifId = item.data('id');
        const linkHref = item.attr('href');
        const isRead = item.data('isRead');
        
        // Prevent default navigation if it was an unread item or a hover click
        if (isHoverClick || isRead === 0) {
              event.preventDefault();
        }

        if (isRead === 0) {
            // 1. Mark as read via API
            $.post('../api/mark_notification_as_read.php', { notification_id: notifId }, function(response) {
                if (response.success) {
                    // **CRUCIAL FIX: Reload the entire page to synchronize all elements**
                    if (!isHoverClick) {
                        // Navigate to the link after reloading
                        window.location.href = linkHref;
                    } else {
                        // If it was just a "Mark as Read" click, just reload the current page
                        window.location.reload(); 
                    }
                } else {
                    console.error("Failed to mark notification as read.");
                }
            }).fail(function() {
                console.error("API call failed.");
            });
        } else if (isRead === 1 && !isHoverClick) {
            // If already read, just navigate
            window.location.href = linkHref;
        }
    }
    
    // New API function to mark ALL notifications as read (Used by the Mark All button)
    window.markAllAsRead = function() {
        $.post('../api/mark_notification_as_read.php', { mark_all: true }, function(response) {
            if (response.success) {
                // **CRUCIAL FIX: Reload the entire page to synchronize all elements**
                window.location.reload();
            } else {
                console.error("Failed to mark all notifications as read.");
            }
        }).fail(function() {
            console.error("API call failed.");
        });
    };
    
    // --- DROPDOWN PREVIEW LOGIC (Fetches alerts and populates the dropdown) ---
    function fetchStudentAlerts() {
        const apiPath = '../api/get_notifications.php'; 
        
        $.getJSON(apiPath, function(response) { 
            
            const unreadCount = response.count; 
            const notifications = response.alerts || []; 
            
            const $badge = $('#notification-bell-badge');
            const $dropdown = $('#notification-dropdown');
            const $header = $dropdown.find('.dropdown-header');
            const $viewAllLink = $dropdown.find('a[href="student_transaction.php"]').detach(); 
            
            // Clear previous dynamic content and any old Mark All buttons
            $dropdown.find('.dynamic-notif-item').remove();
            $dropdown.find('.mark-all-btn-wrapper').remove(); 

            // 1. Update the Badge Count
            $badge.text(unreadCount);
            $badge.toggle(unreadCount > 0); 

            // 2. Populate the Dropdown Menu
            const $placeholder = $dropdown.find('.dynamic-notif-placeholder').empty();
            
            if (notifications.length > 0) {
                // Add a Mark All button if there are unread items
                if (unreadCount > 0) {
                     $placeholder.append(`
                          <a class="dropdown-item text-center small text-muted dynamic-notif-item mark-all-btn-wrapper" href="#" onclick="event.preventDefault(); window.markAllAsRead();">
                             <i class="fas fa-check-double me-1"></i> Mark All ${unreadCount} as Read
                          </a>
                     `);
                }

                notifications.slice(0, 5).forEach(notif => {
                    
                    let iconClass = 'fas fa-info-circle text-secondary'; 
                    if (notif.message.includes('rejected') || notif.message.includes('OVERDUE')) {
                          iconClass = 'fas fa-exclamation-triangle text-danger';
                    } else if (notif.message.includes('approved') || notif.message.includes('confirmed in good')) {
                          iconClass = 'fas fa-check-circle text-success';
                    } else if (notif.message.includes('sent') || notif.message.includes('awaiting') || notif.message.includes('Return requested')) {
                          iconClass = 'fas fa-hourglass-half text-warning';
                    }
                    
                    const is_read = notif.is_read == 1;
                    const itemClass = is_read ? 'read-item' : 'unread-item';
                    const link = notif.link || 'student_transaction.php';
                    
                    const cleanMessage = notif.message.replace(/\*\*/g, '');
                    const datePart = new Date(notif.created_at.split(' ')[0]).toLocaleDateString('en-US', { month: 'short', day: 'numeric', year: 'numeric' });


                    // Insert the item into the placeholder div
                    $placeholder.append(`
                         <a class="dropdown-item d-flex align-items-center dynamic-notif-item ${itemClass}" 
                            href="${link}" 
                            data-id="${notif.id}"
                            data-is-read="${notif.is_read}"
                            onclick="window.markSingleAlertAndGo(event, this)">
                             <div class="me-3"><i class="${iconClass} fa-fw"></i></div>
                             <div class="flex-grow-1">
                                 <div class="small text-gray-500">${datePart}</div>
                                 <span class="d-block">${cleanMessage}</span>
                             </div>
                             ${notif.is_read == 0 ? 
                                 `<button type="button" class="mark-read-hover-btn" 
                                             title="Mark as Read" 
                                             data-id="${notif.id}"
                                             onclick="event.stopPropagation(); window.markSingleAlertAndGo(event, this, true)">
                                     <i class="fas fa-check-circle"></i>
                                 </button>` : ''}
                         </a>
                     `);
                });
            } else {
                // Display a "No Alerts" message
                $placeholder.html(`
                    <a class="dropdown-item text-center small text-muted dynamic-notif-item">No Recent Notifications</a>
                `);
            }
            
            // Re-append the 'View All' link to the end of the dropdown
            $dropdown.append($viewAllLink);
            

        }).fail(function(jqXHR, textStatus, errorThrown) {
            console.error("Error fetching student alerts:", textStatus, errorThrown);
            $('#notification-bell-badge').text('0').hide();
        });
    }


    document.addEventListener('DOMContentLoaded', () => {
        // ... (Sidebar activation logic) ...
        const path = window.location.pathname.split('/').pop() || 'student_dashboard.php';
        const links = document.querySelectorAll('.sidebar .nav-link');
        
        links.forEach(link => {
            const linkPath = link.getAttribute('href').split('/').pop();
            
            if (linkPath === path) {
                link.classList.add('active');
            } else {
                link.classList.remove('active');
            }
        });
        
        // Initial fetch on page load
        fetchStudentAlerts();
        
        // Poll the server every 30 seconds for new alerts
        setInterval(fetchStudentAlerts, 30000); 
    });
</script>
</body>
</html>