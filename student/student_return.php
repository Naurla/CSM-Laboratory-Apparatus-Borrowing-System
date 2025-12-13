<?php
// student_return.php - ADDED TOP BAR AND NOTIFICATION LOGIC
session_start();
// Include the PHPMailer autoloader first (assuming it's in vendor)
require_once "../vendor/autoload.php"; 
require_once "../classes/Transaction.php";
require_once "../classes/Mailer.php"; // Include Mailer class
require_once "../classes/Database.php"; // Keep Database for base class

if (!isset($_SESSION["user"]) || $_SESSION["user"]["role"] != "student") {
    header("Location: ../pages/login.php");
    exit();
}

$transaction = new Transaction();
$mailer = new Mailer(); // Instantiate Mailer
$student_id = $_SESSION["user"]["id"];
$message = "";
$is_success = false;
$email_message = ""; // To track email status

// --- BAN CHECK for sidebar display ---
$isBanned = $transaction->isStudentBanned($student_id);
// -------------------------------------

// When student clicks “Return”
if ($_SERVER["REQUEST_METHOD"] === "POST" && isset($_POST["return"])) {
    $form_id = $_POST["form_id"];
    $remarks = $_POST["remarks"] ?? "";
    
    // Uses the internal method to fetch form data from the single borrow_forms table
    $form_data = $transaction->getBorrowFormById($form_id); 
    $borrowDate = $form_data['borrow_date'];

    if ($borrowDate <= date("Y-m-d")) {
        // --- Step 1: Execute Transaction (DB Commit & System Notification to staff) ---
        // markAsChecking now handles the database updates AND the email confirmation internally.
        if ($transaction->markAsChecking($form_id, $student_id, $remarks)) {
            
            // =================================================================
            // REMOVED DUPLICATE EMAIL LOGIC FROM HERE (Lines 33-54 removed)
            // =================================================================
            
            // Final message shown to student - adjusted to confirm email was sent (by Transaction.php)
            $message = "Your return request (ID: **$form_id**) has been submitted and is pending staff verification. A confirmation email has been sent.";
            $is_success = true;

        } else {
            // --- FIX: Improved Error Message Block ---
            $form_status_check = $transaction->getBorrowFormById($form_id);
            $current_status = $form_status_check['status'] ?? 'Unknown/Missing';
            
            $message = "Failed to submit return request for ID $form_id. Current database status is **{$current_status}**. The item may be fully returned, rejected, or already pending check.";
            $is_success = false;
            // --- END FIX ---
        }
    } else {
        $message = "Cannot submit return yet. The borrow date ($borrowDate) for this request is in the future.";
        $is_success = false;
    }
}

// FIX: Fetch all relevant active/pending forms, including 'overdue' and 'checking'
$activeForms = $transaction->getStudentFormsByStatus($student_id, 'borrowed,approved,reserved,overdue,checking');
$today = date("Y-m-d"); 
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Initiate Apparatus Return</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0-beta3/css/all.min.css" rel="stylesheet">
    
    <script src="https://code.jquery.com/jquery-3.6.0.min.js"></script> 
    
    <style>
    /* Custom Variables and Base Layout (MSU Theme) */
    :root {
        --msu-red: #A40404;
        --msu-red-dark: #820303;
        --sidebar-width: 280px; 
        --bg-light: #f5f6fa;
        --danger-dark: #8b0000;
        --main-text: #333;
        --header-height: 60px;
    }
    
    body { 
        font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif; 
        background: #f5f6fa; 
        padding: 0;
        margin: 0;
        display: flex; 
        min-height: 100vh;
        font-size: 1.05rem; 
        color: var(--main-text);
    }
    
    /* NEW CSS for Mobile Toggle */
    .menu-toggle {
        display: none; 
        position: fixed;
        top: 15px;
        left: 20px;
        z-index: 1060; 
        background: var(--msu-red);
        color: white;
        border: none;
        padding: 8px 12px;
        border-radius: 6px;
        font-size: 1.2rem;
        box-shadow: 0 2px 5px rgba(0,0,0,0.2);
    }
    
    /* === NEW: TOP HEADER BAR STYLES (Copied from dashboard) === */
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
    /* Dropdown menu styles (simplified inclusion) */
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
    .dropdown-item.unread-item {
        font-weight: 600;
        background-color: #f8f8ff;
    }
    .dropdown-item small {
        display: block;
        font-size: 0.8em;
        color: #999;
    }
    .mark-read-hover-btn {
        position: absolute;
        top: 50%;
        right: 10px;
        transform: translateY(-50%);
        background: none;
        border: none;
        color: #6c757d;
        opacity: 0;
        padding: 5px;
        cursor: pointer;
        transition: opacity 0.2s;
        z-index: 10;
    }
    .dropdown-item:hover .mark-read-hover-btn {
        opacity: 1;
    }
    .dropdown-item.read-item .mark-read-hover-btn {
        display: none !important; 
    }
    /* === END TOP HEADER BAR STYLES === */

    /* === SIDEBAR STYLES (Consistent Look) === */
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
        width: 90px; 
        height: 90px;
        object-fit: contain; 
        margin-bottom: 15px; 
    }
    .sidebar-header .title { font-size: 1.3rem; line-height: 1.1; }
    .sidebar .nav-link {
        color: white;
        padding: 15px 20px; 
        font-size: 1.1rem;
        font-weight: 600;
        transition: background-color 0.3s;
        display: flex; 
        align-items: center;
        justify-content: flex-start;
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
    .logout-link .nav-link { 
        background-color: #C62828 !important; 
        color: white !important;
    }
    .logout-link .nav-link:hover {
        background-color: var(--msu-red-dark) !important; 
    }
    /* END SIDEBAR FIXES */
    
    /* === MAIN CONTENT STYLES === */
    .main-wrapper {
        margin-left: var(--sidebar-width); 
        /* ADDED PADDING TOP TO ACCOUNT FOR THE NEW FIXED HEADER */
        padding: 25px;
        padding-top: calc(var(--header-height) + 25px); 
        flex-grow: 1;
    }
    .container {
        max-width: none; 
        width: 95%; 
        margin: 0 auto;
        background: white;
        padding: 40px 50px;
        border-radius: 12px;
        box-shadow: 0 5px 20px rgba(0,0,0,0.1);
    }
    
    h2 { 
        text-align: left; 
        color: #333; 
        margin-bottom: 30px;
        border-bottom: 2px solid var(--msu-red);
        padding-bottom: 15px;
        font-size: 2.2rem;
        font-weight: 700;
    }
    .lead {
        font-size: 1.25rem;
    }
    .alert {
        font-size: 1.1rem;
        padding: 15px 20px;
        font-weight: 500;
    }

    .form-list {
        display: flex;
        flex-direction: column;
        gap: 20px;
        padding: 0;
        list-style: none;
        margin-top: 25px;
    }
    
    .return-card {
        background-color: #fff;
        border: 1px solid #e0e0e0;
        border-radius: 12px;
        padding: 20px 25px;
        box-shadow: 0 4px 10px rgba(0,0,0,0.05);
        display: flex; 
        align-items: center; 
        gap: 20px; 
    }

    .return-card-checking {
        border-left: 5px solid #ffc107;
    }
    .return-card-borrowed, .return-card-approved { 
        border-left: 5px solid #007bff;
    }
    .return-card-overdue {
        border-left: 5px solid var(--danger-dark);
        background-color: #fff4f4;
    }

    .apparatus-thumbnail {
        width: 80px; 
        height: 80px;
        object-fit: contain; 
        border-radius: 10px;
        border: 1px solid #eee;
        background-color: #fcfcfc;
        padding: 8px;
        flex-shrink: 0; 
    }

    .card-col-info { 
        flex-grow: 1; 
        text-align: left; 
    }
    .card-col-action { 
        flex-shrink: 0; 
        width: 450px; 
        display: flex; 
        align-items: center; 
        justify-content: flex-end; 
        gap: 15px;
    }

    .card-col-info h5 {
        font-size: 1.4rem;
    }
    .app-list { 
        font-size: 1.1rem; 
        margin-top: 5px;
        color: #555;
    }
    .date-info span {
        display: inline-block;
        margin-right: 20px;
        font-size: 1rem;
        color: #6c757d;
    }
    .date-info .expected-return {
        color: var(--danger-dark);
        font-weight: 600;
    }
    .date-info .expected-return.ok {
        color: #007bff;
    }

    .action-container {
        display: flex;
        gap: 15px;
        align-items: stretch; 
        width: 100%;
        max-width: 450px;
    }
    .action-container textarea {
        flex-grow: 1;
        min-height: 50px;
        max-height: 50px;
        resize: none;
        font-size: 1rem;
        border-radius: 8px;
        padding: 10px;
    }
    .btn-return { 
        width: 160px; 
        padding: 12px 18px; 
        background: #28a745; 
        color: white; 
        font-weight: 700;
        font-size: 1rem;
        border-radius: 8px;
        border: none;
    }
    .btn-return:hover:not(:disabled) { 
        background: #1e7e34; 
        color: white;
    }
    .btn-return:disabled { 
        background: #adb5bd; 
        cursor: not-allowed; 
    }
    
    .action-message-checking {
        display: inline-block;
        padding: 12px 18px;
        font-weight: 600;
        font-size: 1rem;
        border-radius: 8px;
        background: #ffc107;
        color: #333;
        flex-grow: 1; 
        text-align: center;
    }

    /* --- RESPONSIVE ADJUSTMENTS --- */
    @media (max-width: 992px) {
        /* Mobile Sidebar */
        .menu-toggle {
            display: block;
        }
        .sidebar {
            left: calc(var(--sidebar-width) * -1); 
            transition: left 0.3s ease;
            box-shadow: none;
            --sidebar-width: 250px; 
        }
        .sidebar.active {
            left: 0; 
            box-shadow: 2px 0 5px rgba(0, 0, 0, 0.2);
        }
        .main-wrapper {
            margin-left: 0;
            padding-left: 15px;
            padding-right: 15px;
        }
        .top-header-bar {
            left: 0;
            padding-left: 70px;
        }
    }
    
    @media (max-width: 768px) {
        /* Content Stacking for Return Cards */
        .return-card {
            flex-direction: column;
            align-items: flex-start;
            padding: 15px;
        }
        .card-col-action {
            width: 100%; 
            justify-content: flex-start;
            margin-top: 15px;
        }
        .action-container {
            flex-direction: column; /* Stack textarea and button */
            max-width: 100%;
        }
        .action-container textarea {
            max-height: 80px; 
            min-height: 50px;
        }
        .btn-return, .action-message-checking {
            width: 100%;
            text-align: center;
        }
        .card-col-info {
             width: 100%;
        }
        .card-col-info h5 {
            font-size: 1.2rem;
        }
        .app-list { 
            font-size: 1rem;
        }
        .date-info span {
            display: block;
            margin-right: 0;
            font-size: 0.95rem;
        }
        .apparatus-thumbnail {
            width: 60px;
            height: 60px;
        }
    }
    @media (max-width: 576px) {
        .top-header-bar {
            padding: 0 15px;
            justify-content: flex-end;
            padding-left: 65px;
        }
        .top-header-bar .notification-bell-container {
             margin-right: 15px;
        }
        .container {
             padding: 20px;
        }
    }
</style>
</head>
<body>

<button class="menu-toggle" id="menuToggle" aria-label="Toggle navigation menu">
    <i class="fas fa-bars"></i>
</button>

<div class="sidebar">
    <div class="sidebar-header">
        <img src="../wmsu_logo/wmsu.png" alt="WMSU Logo"> 
        <div class="title">
            CSM LABORATORY <br>APPARATUS <br>BORROWING
        </div>
    </div>

    <ul class="nav flex-column mb-4 sidebar-nav">
        <li class="nav-item">
            <a href="student_dashboard.php" class="nav-link">
                <i class="fas fa-clock fa-fw me-2"></i> Current Activity
            </a>
        </li>
        <li class="nav-item">
            <a href="student_borrow.php" class="nav-link <?= $isBanned ? 'banned' : '' ?>">
                <i class="fas fa-plus-circle fa-fw me-2"></i> Borrow/Reserve <?= $isBanned ? ' (BANNED)' : '' ?>
            </a>
        </li>
        <li class="nav-item">
            <a href="student_return.php" class="nav-link active">
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
        <h2><i class="fas fa-redo fa-fw me-2"></i> Initiate Apparatus Return</h2>
        <p class="lead text-start">Select items currently borrowed or reserved that you are ready to return to staff.</p>
        
        <?php if (!empty($message)): ?>
            <div id="status-alert" class="alert <?= $is_success ? 'alert-success' : 'alert-warning' ?> fade show" role="alert">
                <?= $is_success ? '<i class="fas fa-check-circle fa-fw"></i>' : '<i class="fas fa-exclamation-triangle fa-fw"></i>' ?>
                <?= $message ?>
                <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
            </div>
        <?php endif; ?>

        <div class="form-list">
            <?php if (!empty($activeForms)): ?>
                <?php foreach ($activeForms as $form): 
                    $clean_status = strtolower($form["status"]);
                    $is_pending_check = ($clean_status === "checking"); 
                    $is_overdue = ($clean_status === "overdue"); 
                    $is_borrow_date_reached = ($form["borrow_date"] <= $today);
                    $is_expected_return_date_reached = ($form["expected_return_date"] <= $today);
                    
                    if ($is_pending_check) {
                        $card_status_class = 'return-card-checking';
                    } elseif ($is_overdue) {
                        $card_status_class = 'return-card-overdue'; 
                    } else { 
                        $card_status_class = 'return-card-borrowed';
                    }

                    $apparatusListForForm = $transaction->getFormApparatus($form["id"]); 
                    $firstApparatus = $apparatusListForForm[0] ?? null;
                    $imageFile = $firstApparatus["image"] ?? 'default.jpg';
                    $imagePath = "../uploads/apparatus_images/" . $imageFile;
                    
                    if (!file_exists($imagePath) || is_dir($imagePath)) {
                        $imagePath = "../uploads/apparatus_images/default.jpg";
                    }

                    $action_content = '';
                    if ($is_pending_check) {
                        $action_content = '<span class="action-message-checking">
                                             <i class="fas fa-clock me-1"></i> Pending Staff Check
                                           </span>';
                    } elseif (
                        (!$is_expected_return_date_reached) && 
                        ($clean_status === 'reserved' || $clean_status === 'approved')
                    ) {
                        $action_content = '<span class="action-message-checking bg-info text-white">
                                             <i class="fas fa-lock me-1"></i> Return available on **' . htmlspecialchars($form["expected_return_date"]) . '**
                                           </span>';
                    } else {
                        $overdue_warning = '';
                        if ($is_overdue) {
                            $overdue_warning = '<p class="text-danger fw-bold small mb-2"><i class="fas fa-exclamation-circle me-1"></i> **LATE RETURN:** This loan was marked overdue by staff. Submit now to finalize the return.</p>';
                        }
                        
                        $action_content = '
                            <form method="POST" class="action-container">
                                <input type="hidden" name="form_id" value="' . htmlspecialchars($form["id"]) . '">
                                ' . $overdue_warning . '
                                <textarea name="remarks" rows="2" class="form-control" placeholder="Optional notes for staff..."></textarea>
                                <button type="submit" name="return" class="btn btn-return">
                                    <i class="fas fa-paper-plane fa-fw"></i> Submit Return
                                </button>
                            </form>';
                    }
                    
                    $expected_class = $is_overdue ? 'expected-return' : 'expected-return ok';
                ?>
                    <div class="return-card <?= $card_status_class ?>">
                        
                        
                        
                        
                        <img src="<?= htmlspecialchars($imagePath) ?>" 
                            alt="Apparatus Image" 
                            class="apparatus-thumbnail">

                        <div class="card-col-info">
                            <h5 class="fw-bold mb-1" style="color: var(--msu-red);">
                                Request ID: <?= htmlspecialchars($form["id"]) ?> 
                                <small class="text-muted fw-normal">(<?= htmlspecialchars(ucfirst($form["form_type"])) ?>)</small>
                            </h5>
                            <p class="app-list mb-1">
                                <i class="fas fa-tools fa-fw me-1"></i> Items: <?= htmlspecialchars($form["apparatus_list"]) ?>
                            </p>
                            <div class="date-info">
                                <span><i class="fas fa-calendar-alt me-1"></i> Borrow: <?= htmlspecialchars($form["borrow_date"]) ?></span>
                                <span class="<?= $expected_class ?>"><i class="fas fa-clock me-1"></i> Expected: <?= htmlspecialchars($form["expected_return_date"]) ?></span>
                            </div>
                        </div>

                        <div class="card-col-action">
                            <?= $action_content ?>
                        </div>
                    </div>
                <?php endforeach; ?>
            <?php else: ?>
                <div class="alert alert-success text-center">
                    <i class="fas fa-check-circle me-2"></i> All borrowed or approved items have been returned or are pending staff verification.
                </div>
            <?php endif; ?>
        </div>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
<script>
    // --- GLOBAL HANDLERS (for notification dropdown logic - Restored) ---
    // New API function to mark a single notification as read (Used by the hover button)
    window.markSingleAlertAndGo = function(event, element, isHoverClick = false) {
        event.preventDefault();
        
        const $element = $(element);
        const item = isHoverClick ? $element.closest('.dropdown-item') : $element;

        const notifId = item.data('id');
        const linkHref = item.attr('href');
        const isRead = item.data('isRead');
        
        if (isHoverClick || isRead === 0) {
             event.preventDefault();
        }

        if (isRead === 0) {
            $.post('../api/mark_notification_as_read.php', { notification_id: notifId }, function(response) {
                if (response.success) {
                    if (!isHoverClick) {
                        window.location.href = linkHref;
                    } else {
                        window.location.reload(); 
                    }
                } else {
                    console.error("Failed to mark notification as read.");
                }
            }).fail(function() {
                console.error("API call failed.");
            });
        } else if (isRead === 1 && !isHoverClick) {
            window.location.href = linkHref;
        }
    }
    
    // New API function to mark ALL notifications as read (Used by the Mark All button)
    window.markAllAsRead = function() {
        $.post('../api/mark_notification_as_read.php', { mark_all: true }, function(response) {
            if (response.success) {
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
        // NOTE: Uses jQuery's $.getJSON because the notification logic relies on jQuery elements ($badge, $dropdown, etc.)
        const apiPath = '../api/get_notifications.php'; 
        
        $.getJSON(apiPath, function(response) { 
            
            const unreadCount = response.count; 
            const notifications = response.alerts || []; 
            
            const $badge = $('#notification-bell-badge');
            const $dropdown = $('#notification-dropdown');
            const $placeholder = $dropdown.find('.dynamic-notif-placeholder').empty();
            // Detach the 'View All' link to re-append it at the end
            const $viewAllLink = $dropdown.find('a[href="student_transaction.php"]').detach(); 
            
            // Clear old Mark All buttons
            $dropdown.find('.mark-all-btn-wrapper').remove(); 

            // 1. Update the Badge Count
            $badge.text(unreadCount);
            $badge.toggle(unreadCount > 0); 

            // 2. Populate the Dropdown Menu
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
                    // Determine icon based on message content
                    if (notif.message.includes('rejected') || notif.message.includes('OVERDUE') || notif.message.includes('URGENT')) {
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
    // --- END NOTIFICATION LOGIC ---


    // --- DOMContentLoaded Execution ---
    document.addEventListener('DOMContentLoaded', () => {
        // Run initial fetch on page load
        fetchStudentAlerts();
        
        // Refresh every 30 seconds
        setInterval(fetchStudentAlerts, 30000); 

        // --- Auto-hide message functionality ---
        const messageAlert = document.getElementById('status-alert');
        
        if (messageAlert && messageAlert.classList.contains('alert-success')) {
            setTimeout(() => {
                const bsAlert = bootstrap.Alert.getOrCreateInstance(messageAlert);
                bsAlert.close();
            }, 5000); 
        }

        // --- Sidebar active link script (unchanged) ---
        const path = window.location.pathname.split('/').pop();
        const links = document.querySelectorAll('.sidebar .nav-link');
        
        links.forEach(link => {
            const linkPath = link.getAttribute('href').split('/').pop();
            
            if (linkPath === 'student_return.php') {
                link.classList.add('active');
            } else {
                 link.classList.remove('active');
            }
        });
        
        // New Mobile Toggle Logic
        const menuToggle = document.getElementById('menuToggle');
        const sidebar = document.querySelector('.sidebar');
        const mainWrapper = document.querySelector('.main-wrapper');

        if (menuToggle && sidebar) {
            menuToggle.addEventListener('click', () => {
                sidebar.classList.toggle('active');
                if (sidebar.classList.contains('active')) {
                    mainWrapper.addEventListener('click', closeSidebarOnce);
                } else {
                    mainWrapper.removeEventListener('click', closeSidebarOnce);
                }
            });
            
            function closeSidebarOnce() {
                sidebar.classList.remove('active');
                mainWrapper.removeEventListener('click', closeSidebarOnce);
            }
            
            const navLinks = sidebar.querySelectorAll('.nav-link');
            navLinks.forEach(link => {
                link.addEventListener('click', () => {
                    if (window.innerWidth <= 992) {
                        sidebar.classList.remove('active');
                    }
                });
            });
        }
    });
</script>
</body>
</html>