<?php
session_start();
require_once "../vendor/autoload.php";
require_once "../classes/Transaction.php";
require_once "../classes/Database.php";
require_once "../classes/Student.php"; 
require_once "../classes/Mailer.php";

if (!isset($_SESSION["user"]) || $_SESSION["user"]["role"] != "student") {
    header("Location: login.php");
    exit();
}

$transaction = new Transaction();
$mailer = new Mailer();
$student_id = $_SESSION["user"]["id"];

$student_db = new Student();
$db_conn = $student_db->connect(); 


// --- BAN/LOCK LOGIC ---
$isBanned = $transaction->isStudentBanned($student_id);
$activeCount = $transaction->getActiveTransactionCount($student_id);
$hasOverdueLock = $transaction->hasOverdueLoansPendingReturn($student_id);
$is_locked = ($activeCount >= 3 || $isBanned || $hasOverdueLock); 
// --- END LOCK LOGIC ---


// --- STICKINESS LOGIC: Check POST first, then GET ---
if ($_SERVER["REQUEST_METHOD"] == "POST") {
    $type = $_POST["type"] ?? '';
    $borrow_date = $_POST["borrow_date"] ?? '';
    $expected_return_date = $_POST["expected_return_date"] ?? '';
    $agreed_terms = isset($_POST["agree_terms"]) ? 1 : 0;
    $request_array_json = $_POST['request_array_json'] ?? '[]'; 
} else {
    $type = $_GET["type"] ?? '';
    $borrow_date = $_GET["borrow_date"] ?? '';
    $expected_return_date = $_GET["expected_return_date"] ?? '';
    $agreed_terms = filter_var($_GET["agree_terms"] ?? false, FILTER_VALIDATE_BOOLEAN) ? 1 : 0;
    $request_array_json = $_GET['request_array_json'] ?? '[]'; 
}
// --- END STICKINESS LOGIC ---


// --- SEARCH & FILTER PARAMETERS ---
$search_term = $_GET['s'] ?? '';
$filter_type = $_GET['filter_type'] ?? '';

$apparatus_types = $transaction->getUniqueApparatusTypes(); 
$itemsPerPage = 6; 
$currentPage = isset($_GET['page']) ? (int)$_GET['page'] : 1;
if ($currentPage < 1) $currentPage = 1;

$all_apparatus = $transaction->getAllApparatusIncludingZeroStock($search_term, $filter_type); 
$totalItems = count($all_apparatus);
$totalPages = ceil($totalItems / $itemsPerPage);
$offset = ($currentPage - 1) * $itemsPerPage;
$available_apparatus = array_slice($all_apparatus, $offset, $itemsPerPage);

// Build BASE parameters 
$base_params = http_build_query(array_filter([
    's' => $search_term, 
    'filter_type' => $filter_type
]));

// Build STICKY parameters
$sticky_params = http_build_query(array_filter([
    'type' => $type, 
    'borrow_date' => $borrow_date, 
    'expected_return_date' => $expected_return_date,
    'request_array_json' => $request_array_json, 
    'agree_terms' => $agreed_terms ? 1 : 0, 
    's' => $search_term, 
    'filter_type' => $filter_type
]));
// ------------------------------------

$errors = [];
$message = "";
$is_success = false;

// Determine the specific error message for rendering if locked
$lock_message = ""; 

if ($hasOverdueLock) {
    $lock_message = "🚫 **OVERDUE LOCK:** You have item(s) past the expected return date. Please return them immediately before borrowing again.";
} elseif ($isBanned) {
    $ban_until_date_obj = $transaction->getBanUntilDate($student_id); 
    $ban_until_date_str = $ban_until_date_obj ? (new DateTime($ban_until_date_obj))->format('Y-m-d h:i A') : 'an unknown date';
    
    $lock_message = "🚫 **SUSPENDED:** Your account is suspended. Privileges restored on **{$ban_until_date_str}**.";
} elseif ($activeCount >= 3) {
    $lock_message = "🚫 **Max Active Requests Reached:** You already have **{$activeCount} active transactions** (Limit is 3). Please return or wait for completion before borrowing again.";
}

// --- FINAL MESSAGE RESOLUTION (Handles modal display on page load) ---
$secondary_message = ""; 

if (isset($_SESSION['submission_status'])) {
    $message = $_SESSION['submission_status']['message'];
    $is_success = $_SESSION['submission_status']['success'];
    $secondary_message = $_SESSION['submission_status']['secondary_message'] ?? ''; 
    
    // Clear the session variable after loading it
    unset($_SESSION['submission_status']); 
    
} elseif ($is_locked) {
    $message = $lock_message;
    $is_success = false;
}
// ------------------------------------


if ($_SERVER["REQUEST_METHOD"] == "POST") {

    // 1. CRITICAL: Re-check Lock State on POST (Server-side defense)
    if ($is_locked) {
        $_SESSION['submission_status'] = [
            'message' => $lock_message, 
            'success' => false
        ];
        header("Location: student_borrow.php?" . $base_params); 
        exit;
    }
    
    // --- START VALIDATION ---
    $apparatus_details_for_transaction = json_decode(urldecode($request_array_json), true);
    
    if (empty($type)) {
        $errors['type'] = "Request type (**Borrow** or **Reserve**) is required.";
    }
    
    if (empty($apparatus_details_for_transaction) || $request_array_json === '[]') {
        $errors['apparatus'] = "Please add at least one item to your request list.";
    }
    
    if (!$agreed_terms) {
        $errors['terms'] = "You must agree to the Terms and Conditions.";
    }

    if (empty($borrow_date)) {
        $errors['borrow_date'] = "Borrow/Reserve date is required.";
    } elseif (empty($errors['type'])) { 
        try {
            $today = new DateTime('today');
            $borrow_dt = new DateTime($borrow_date);
            $three_days_later = (clone $today)->modify('+3 days');
            
            $borrow_date_str = $borrow_dt->format('Y-m-d');
            $today_str = $today->format('Y-m-d');
            $three_days_later_str = $three_days_later->format('Y-m-d');

            if ($type === 'borrow') {
                if ($borrow_date_str !== $today_str) {
                    $errors['borrow_date'] = "Borrow requests must use today's date (" . $today_str . ").";
                }
            } elseif ($type === 'reserve') {
                if ($borrow_date_str <= $today_str || $borrow_date_str > $three_days_later_str) {
                    $errors['borrow_date'] = "Reserve requests must be for the future, up to 3 days maximum (" . $three_days_later_str . ").";
                }
            }
        } catch (Exception $e) {
            $errors['borrow_date'] = "Invalid date format submitted.";
        }
    }
    // --- END VALIDATION ---


    if (empty($errors)) {
        
        // FIX: createTransaction must return the new form ID for the notification trigger
        $result = $transaction->createTransaction($student_id, $type, $apparatus_details_for_transaction, $borrow_date, $expected_return_date, $agreed_terms);

        if (is_numeric($result)) { // SUCCESS!
            
            $new_borrow_form_id = (int)$result; 
            
            // ------------------------------------------------------------
            // 🛑 NOTIFICATION CODE REMOVED HERE TO PREVENT DUPLICATES 🛑
            // ------------------------------------------------------------

            // ================================================
            // ✉️ EMAIL NOTIFICATION TRIGGER (Student Confirmation) ✉️
            // ================================================
            
            // 1. Fetch student details (Name and Email)
            $student_details = $transaction->getUserDetails($student_id, null); 
            
            // 2. Fetch apparatus list for the email context
            $apparatus_details_decoded = json_decode(urldecode($request_array_json), true);
            $apparatus_names = array_column($apparatus_details_decoded, 'name');
            $items_list = implode(', ', $apparatus_names);
            
            $email_msg = 'Error sending confirmation email.';
            
            if ($student_details) {
                $email_sent = $mailer->sendTransactionStatusEmail(
                    $student_details['email'], 
                    $student_details['firstname'], 
                    $new_borrow_form_id, 
                    'waiting_for_approval', 
                    "Items requested: {$items_list}. Expected return: {$expected_return_date}." 
                );
                
                $email_msg = $email_sent ? 'A confirmation email was sent.' : 'Error sending confirmation email.';
            } else {
                $email_msg = 'Error: Could not retrieve student email details.';
            }
            // ================================================
            
            $newActiveCount = $transaction->getActiveTransactionCount($student_id);
            $final_secondary_message = '';

            if ($newActiveCount >= 3) {
                $final_secondary_message = "🚫 **Maximum Active Requests Reached:** You now have {$newActiveCount} active requests (Limit is 3). Further requests are temporarily blocked.";
            } 
            
            $_SESSION['submission_status'] = [
                'message' => "Successfully submitted your request! It is now awaiting staff approval. " . $email_msg,
                'success' => true, 
                'secondary_message' => $final_secondary_message 
            ];
            
            $request_array_json = '[]'; 
            header("Location: student_borrow.php?" . $base_params); 
            exit;
            
        } else {
            if ($result === 'stock_error') { 
                $message = "❌ The stock for one or more selected items became unavailable during submission. Please check quantities and try again.";
            } elseif (is_array($result) && $result['error_type'] === 'duplicate_item_request') { 
                $conflicting_item_name = htmlspecialchars($result['item_name']); 
                $message = "🚫 **Duplicate Item Error:** You already have an active request or borrowed item for the apparatus **{$conflicting_item_name}**. Please complete the existing loan before submitting a new one for this item.";
            } elseif ($result === 'db_error') { 
                $message = "❌ A critical database error occurred while finalizing the transaction. Please try again. If the error persists, contact staff.";
            } else {
                $message = "A critical database error occurred while finalizing the transaction. Please try again.";
            }
            
            $_SESSION['submission_status'] = ['message' => $message, 'success' => false];
            
            $error_params = http_build_query(array_filter([
                'type' => $type, 
                'borrow_date' => $borrow_date, 
                'expected_return_date' => $expected_return_date,
                'request_array_json' => $request_array_json, 
                'agree_terms' => $agreed_terms ? 1 : 0, 
                's' => $search_term, 
                'filter_type' => $filter_type
            ]));

            header("Location: student_borrow.php?" . $error_params); 
            exit;
        }
    } else {
        $message = "Please correct the highlighted errors before submitting.";
        $is_success = false;
    }
}

$activeCount = $transaction->getActiveTransactionCount($student_id);
?>

<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Borrow or Reserve Apparatus</title>
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
<link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0-beta3/css/all.min.css" rel="stylesheet">
<script src="https://code.jquery.com/jquery-3.6.0.min.js"></script> 
<style>
    /* CSS is the same as previous step */
    :root {
        --msu-red: #A40404; /* CHANGED FROM #b8312d */
        --msu-red-dark: #820303; /* CHANGED FROM #a82e2a */
        --sidebar-width: 280px; 
        --main-text: #333;
        --header-height: 60px; /* Added for Top Bar reference */
    }
    
    body { 
        font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif; 
        background: #f5f6fa; 
        padding: 0;
        margin: 0;
        display: flex;
        min-height: 100vh;
        color: var(--main-text);
        font-size: 1.05rem; 
    }

    /* --- Top Header Bar Styles (COPIED FROM DASHBOARD) --- */
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
    /* Dropdown Menu Styles */
    .dropdown-menu {
        min-width: 300px;
        padding: 0;
    }
    /* Dropdown Item Styling */
    .dropdown-item {
        padding: 10px 15px;
        white-space: normal;
        position: relative;
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
    }
    .dropdown-item:hover .mark-read-hover-btn {
        opacity: 1;
    }
    .dropdown-item.read-item .mark-read-hover-btn {
        display: none !important; 
    }
    /* --- END Top Header Bar Styles --- */


    /* Standard Sidebar Styles */
    .sidebar { width: var(--sidebar-width); min-width: var(--sidebar-width); background-color: var(--msu-red); color: white; padding: 0; position: fixed; height: 100%; top: 0; left: 0; display: flex; flex-direction: column; box-shadow: 2px 0 5px rgba(0, 0, 0, 0.2); z-index: 1050; }
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
    .sidebar-header img { max-width: 90px; height: auto; margin-bottom: 15px; }
    
    .sidebar-header .title { font-size: 1.3rem; line-height: 1.1; }
    
    /* INCREASED FONT SIZE FOR SIDEBAR LINKS */
    .sidebar .nav-link { color: white; padding: 18px 20px; font-size: 1.1rem; font-weight: 600; transition: background-color 0.3s; }
    .sidebar .nav-link.banned { background-color: #5a2624; opacity: 0.6; cursor: not-allowed; pointer-events: none; }
    .sidebar .nav-link:hover, .sidebar .nav-link.active { background-color: var(--msu-red-dark); }
    
    .sidebar .nav-link.history { 
        border-top: 1px solid rgba(255, 255, 255, 0.1); 
        margin-top: 5px; 
    }
    
    /* Logout Link Styles - FIXED */
    .logout-link { 
        margin-top: auto; 
        border-top: 1px solid rgba(255, 255, 255, 0.1);
    }
    .logout-link .nav-link { 
        background-color: #C62828 !important; /* FIXED to match student_dashboard/return base color */
        color: white !important;
    }
    .logout-link .nav-link:hover {
        background-color: var(--msu-red-dark) !important; 
    }
    
    /* MODIFIED: Added padding-top for fixed header */
    .main-wrapper { 
        margin-left: var(--sidebar-width); 
        padding: 25px; 
        padding-top: calc(var(--header-height) + 25px); 
        flex-grow: 1; 
    }
    
    /* INCREASED CONTAINER PADDING */
    .container { 
        max-width: none; 
        width: 95%; 
        margin: 0 auto; 
        background: white; 
        padding: 40px 50px; 
        border-radius: 12px; 
        box-shadow: 0 5px 20px rgba(0,0,0,0.1); 
    }
    
    /* INCREASED MAIN HEADER SIZE */
    h2 { text-align: left; margin-bottom: 30px; color: var(--main-text); border-bottom: 2px solid var(--msu-red); padding-bottom: 15px; font-size: 2.2rem; font-weight: 700; }
    h3 { font-size: 1.75rem; font-weight: 600; }
    .error { color: #dc3545; font-size: 1rem; margin-top: 5px; font-weight: bold; } 
    .disabled, button[disabled] { background-color: #aaa !important; cursor: not-allowed !important; }

    /* --- UI STYLES --- */
    .apparatus-card { 
        border: 1px solid #ddd; 
        border-radius: 12px; 
        overflow: hidden; 
        margin-bottom: 25px; 
        box-shadow: 0 4px 10px rgba(0,0,0,0.05);
        display: flex;
        flex-direction: column; 
        height: 100%; 
    }
    .card-img-top { 
        width: 100%; 
        height: 200px; 
        object-fit: contain; 
        background-color: white; 
        border-bottom: 1px solid #eee; 
        padding: 15px; 
    }
    .card-body-custom { 
        padding: 20px; 
        flex-grow: 1; 
        display: flex; 
        flex-direction: column; 
    }
    .card-title {
        font-size: 1.25rem;
    }
    .item-details { 
        font-size: 1rem; 
        margin-bottom: 12px; 
        color: #6c757d; 
    }
    .item-details strong { 
        color: var(--msu-red-dark); 
        font-size: 1.2rem; 
    }
    .item-description { 
        font-size: 0.9rem; 
        height: 4.5em; 
        overflow: hidden; 
        text-overflow: ellipsis; 
        display: -webkit-box; 
        -webkit-line-clamp: 3; 
        -webkit-box-orient: vertical; 
        margin-bottom: 20px; 
    }
    .action-area { 
        margin-top: auto; 
        display: flex;
        align-items: center;
        justify-content: space-between; 
        padding-top: 15px; 
        border-top: 1px solid #eee; 
    }
    .qty-input { 
        width: 80px; 
        font-weight: 700; 
        border-color: #ccc; 
        font-size: 1.05rem;
        height: 40px;
    }
    
    .btn-add-request {
        background-color: var(--msu-red);
        color: white;
        font-size: 1rem;
        padding: 8px 18px;
        border-radius: 6px;
        transition: background-color 0.2s;
        font-weight: bold;
    }
    .btn-add-request:hover {
        background-color: var(--msu-red-dark);
    }
    .out-of-stock-card { opacity: 0.7; background-color: #fdf6f6; }
    .remove-btn { 
        color: var(--msu-red); 
        border: none;
        background: none;
        font-size: 1.1rem;
        padding: 0 5px;
    }
    .request-item {
        display: flex;
        justify-content: space-between;
        align-items: center;
        padding: 8px 5px;
        border-bottom: 1px dashed #eee;
        font-size: 1.1rem;
    }

    /* VISUAL TYPE SELECTION STYLES */
    .type-selection { display: flex; gap: 15px; margin-bottom: 15px; }
    .type-btn {
        padding: 12px 20px;
        border: 2px solid #ddd;
        border-radius: 10px;
        background-color: #f9f9f9;
        cursor: pointer;
        transition: all 0.2s;
        font-weight: 600;
        flex-grow: 1;
        text-align: center;
        color: var(--main-text);
        box-shadow: 0 1px 5px rgba(0,0,0,0.1);
        font-size: 1.1rem;
    }
    .type-btn.selected {
        background-color: var(--msu-red);
        color: white;
        border-color: var(--msu-red);
    }
    .type-btn:hover:not(.selected):not([disabled]) {
        background-color: #f0f0f0;
        border-color: var(--msu-red);
        color: var(--main-text);
    }
    
    /* Pagination Styles */
    .pagination .page-item.active .page-link {
        background-color: var(--msu-red);
        border-color: var(--msu-red);
        color: white;
    }
    .pagination .page-link {
        color: var(--msu-red-dark);
        font-size: 1rem;
        padding: 0.5rem 1rem;
    }
    /* Date Input Specific UI improvements */
    .date-input-group {
        position: relative;
    }
    .date-input-group input[type="date"]::-webkit-calendar-picker-indicator {
        position: absolute;
        width: 100%;
        height: 100%;
        top: 0;
        left: 0;
        cursor: pointer;
        opacity: 0; 
    }
    .date-input-group .input-group-text {
        background: #fcfcfc;
        border-left: none;
        color: var(--main-text);
    }
    .date-input-group input[type="date"] {
        color: #666; 
        font-weight: 600;
        font-size: 1.05rem;
        height: 40px;
    }
    input[type="date"]:valid {
        color: var(--main-text);
    }
    input[type="date"]:not([value]) {
        color: transparent;
    }
    input[type="date"]:not([value]):before {
        content: attr(placeholder);
        color: #999;
        position: absolute;
    }
    input[type="date"]::-webkit-datetime-edit {
        color: var(--main-text);
    }
    /* Expected Return Display Fix */
    #expected_return_date_display {
        font-weight: 600;
        background-color: #f9f9f9; 
        font-size: 1.05rem;
    }
    .btn-submit {
        background-color: var(--msu-red);
        border: 1px solid var(--msu-red-dark);
        color: white;
        font-weight: 700;
        padding: 12px 30px;
        font-size: 1.1rem;
        border-radius: 8px;
        transition: background-color 0.2s;
    }
    .btn-submit:hover:not([disabled]) {
        background-color: var(--msu-red-dark);
        box-shadow: 0 2px 4px rgba(0,0,0,0.1);
    }

    /* --- SEARCH & FILTER STYLES --- */
    .filter-container {
        padding: 20px;
        border-radius: 10px;
        background-color: #f8f9fa;
        border: 1px solid #e9ecef;
        margin-bottom: 30px;
    }
    .search-input-group .input-group-text {
        background-color: var(--msu-red);
        color: white;
    }
    .filter-select {
        border-left: 1px solid #ced4da;
        font-size: 1rem;
        height: 40px;
    }
    .form-control, .form-select {
        height: 40px;
    }
    .terms-check .form-check-label {
        font-size: 1.05rem;
    }

    /* MODAL STYLING FIX: Large Modal for Status */
    #statusModal .modal-content {
        border-radius: 12px;
        box-shadow: 0 10px 20px rgba(0,0,0,0.2);
    }
    #statusModal .modal-body {
        font-size: 1.1rem;
        line-height: 1.6;
        padding: 30px;
    }
    #statusModal .modal-footer {
        justify-content: center;
    }
    #statusModal .modal-header {
        border-bottom: none;
    }
</style>

</head>
<body>

<div class="sidebar">
    <div class="sidebar-header">
        <img src="../wmsu_logo/wmsu.png" alt="WMSU Logo" class="img-fluid"> 
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
            <a href="student_borrow.php" class="nav-link active <?= $isBanned ? 'banned' : '' ?>">
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
        <h2 class="mb-4"><i class="fas fa-plus-circle me-3 text-secondary"></i> Borrow or Reserve Apparatus</h2>

        <?php 
        // --- MODAL TRIGGER LOGIC (PRIMARY MODAL) ---
        if (!empty($message)) {
            // If this is a submission status, use the session values.
            $modal_status = ($is_success) ? 'success' : 'error';
            echo '<input type="hidden" id="modalMessage" value="' . htmlspecialchars($message) . '">';
            echo '<input type="hidden" id="modalStatus" value="' . $modal_status . '">';
            // Add the secondary message to a hidden input for JS to use.
            if (!empty($secondary_message)) {
                echo '<input type="hidden" id="modalSecondaryMessage" value="' . htmlspecialchars($secondary_message) . '">';
            }
        }
        // --- END MODAL TRIGGER LOGIC ---
        ?>
        
        <div class="card mb-4 border-0 shadow-sm">
            <div class="card-header bg-light border-0">
                <h5 class="mb-0 text-secondary fw-bold"><i class="fas fa-sliders-h me-2"></i> Request Parameters</h5>
            </div>
            <div class="card-body">
                
                <form method="POST" action="" id="borrowForm"> 
                    <div class="row">
                        <div class="col-md-4 mb-3">
                            <label class="form-label fw-bold">Form Type <span class="text-danger">*</span>:</label>
                            
                            <div class="type-selection" id="type-selection">
                                <button type="button" class="type-btn <?= ($type=="borrow") ? "selected" : "" ?> <?= $is_locked ? "disabled" : "" ?>" data-value="borrow" onclick="toggleType('borrow')" <?= $is_locked ? "disabled" : "" ?>>
                                    <i class="fas fa-plus-circle me-1"></i> Borrow (Today)
                                </button>
                                <button type="button" class="type-btn <?= ($type=="reserve") ? "selected" : "" ?> <?= $is_locked ? "disabled" : "" ?>" data-value="reserve" onclick="toggleType('reserve')" <?= $is_locked ? "disabled" : "" ?>>
                                    <i class="fas fa-calendar-check me-1"></i> Reserve (Future)
                                </button>
                            </div>

                            <input type="hidden" name="type" id="type_hidden" value="<?= htmlspecialchars($type) ?>">

                            <?php if(isset($errors["type"])): ?><div class="error"><?= $errors["type"] ?></div><?php endif; ?>
                        </div>

                        <div class="col-md-4 mb-3">
                            <label class="form-label fw-bold">Borrow/Reserve Date <span class="text-danger">*</span>:</label>
                             <div class="input-group date-input-group">
                                 <input type="date" name="borrow_date" id="borrow_date" class="form-control" 
                                     value="<?= htmlspecialchars($borrow_date) ?>" 
                                     onchange="updateExpectedReturnDate()"
                                     placeholder="YYYY-MM-DD"
                                     <?= $is_locked ? "disabled" : "" ?>>
                                 <span class="input-group-text"><i class="fas fa-calendar-day"></i></span>
                             </div>
                            <?php if(isset($errors["borrow_date"])): ?><div class="error"><?= $errors["borrow_date"] ?></div><?php endif; ?>
                        </div>

                        <div class="col-md-4 mb-3">
                            <label class="form-label fw-bold">Expected Return Date <span class="text-danger">*</span>:</label>
                            <div class="input-group">
                                <input type="text" id="expected_return_date_display" class="form-control" 
                                        value="<?= htmlspecialchars($expected_return_date) ?>" 
                                        placeholder="Auto-filled"
                                        readonly> 
                                <span class="input-group-text"><i class="fas fa-clock"></i></span>
                            </div>
                            
                            <input type="hidden" name="expected_return_date" id="expected_return_date_hidden" 
                                value="<?= htmlspecialchars($expected_return_date) ?>">
                            
                            <?php if(isset($errors["expected_return_date"])): ?><div class="error"><?= $errors["expected_return_date"] ?></div><?php endif; ?>
                        </div>
                    </div>

                    <p class="text-muted small mt-2 mb-4">
                        <i class="fas fa-clock me-1 text-danger"></i> No Overnight Loans. All items must be returned on the same day of borrowing/reservation.
                        <br>
                        <i class="fas fa-calendar-check me-1 text-primary"></i> Reservations are allowed for dates up to 3 days in the future.
                    </p>
                    
                    <h5 class="mt-4 mb-3 text-secondary fw-bold"><i class="fas fa-shopping-basket me-2"></i> Current Request List (<span id="request-count">0</span> items)</h5>
                    <div id="request-list-display">
                        <p class="text-muted small mb-0">No items added to the request yet.</p>
                    </div>
                    <input type="hidden" name="request_array_json" id="request_array_json" value="<?= htmlspecialchars($request_array_json) ?>">


                    <?php if(isset($errors["apparatus"])): ?><div class="error"><?= $errors["apparatus"] ?></div><?php endif; ?>
                    
                    <div class="terms-check form-check mt-3">
                        <input type="checkbox" name="agree_terms" id="agree_terms" class="form-check-input" <?= $agreed_terms ? "checked" : "" ?> <?= $is_locked ? "disabled" : "" ?>>
                        
                        <label class="form-check-label d-inline fw-normal" for="agree_terms">
                            I agree to the <span class="terms-link text-danger" onclick="openTermsModal(event)" style="cursor: pointer; text-decoration: underline;">Terms and Conditions of Borrowing</span>.
                        </label>
                        <?php if(isset($errors["terms"])): ?><div class="error"><?= $errors["terms"] ?></div><?php endif; ?>
                    </div>
                    
                    <button type="submit" 
                        class="btn-submit mt-3" 
                        id="submitButton"
                        <?= $is_locked ? 'disabled' : '' ?>
                        data-is-locked="<?= $is_locked ? "true" : "false" ?>"
                        data-lock-reason="<?= htmlspecialchars($lock_message) ?>">
                        <i class="fas fa-share-square fa-fw me-2"></i> Submit Request
                    </button>
                </form>
            </div>
        </div>

        <h3 class="mb-4 mt-5" id="available-apparatus-section"><i class="fas fa-vials me-2 text-secondary"></i> Available Apparatus (Page <?= $currentPage ?> of <?= $totalPages ?>)</h3>
        
        <div class="filter-container">
            <form method="GET" action="student_borrow.php" class="row g-3 align-items-end" id="filterForm">
                <input type="hidden" name="type" id="filter_type_sticky" value="<?= htmlspecialchars($type) ?>">
                <input type="hidden" name="borrow_date" id="filter_borrow_date_sticky" value="<?= htmlspecialchars($borrow_date) ?>">
                <input type="hidden" name="expected_return_date" id="filter_expected_return_date_sticky" value="<?= htmlspecialchars($expected_return_date) ?>">
                <input type="hidden" name="request_array_json" id="filter_request_array_json_sticky" value="<?= htmlspecialchars($request_array_json) ?>">
                <input type="hidden" name="agree_terms" id="filter_agree_terms_sticky" value="<?= htmlspecialchars($agreed_terms) ?>">
                <div class="col-md-6">
                    <label for="search" class="form-label fw-bold small text-muted">Search by Name:</label>
                    <div class="input-group search-input-group">
                        <span class="input-group-text"><i class="fas fa-search"></i></span>
                        <input type="text" name="s" id="search" class="form-control" placeholder="e.g., Beaker, Pipette, Thermometer" value="<?= htmlspecialchars($search_term) ?>">
                    </div>
                </div>
                <div class="col-md-4">
                    <label for="filter_type_select" class="form-label fw-bold small text-muted">Filter by Type:</label>
                    <select name="filter_type" id="filter_type_select" class="form-select filter-select">
                        <option value="">All Types</option>
                        <?php foreach ($apparatus_types as $type_option): ?>
                            <option value="<?= htmlspecialchars($type_option) ?>" <?= ($filter_type === $type_option) ? 'selected' : '' ?>>
                                <?= htmlspecialchars($type_option) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-md-2 text-end">
                    <button type="submit" class="btn btn-dark w-100">
                        <i class="fas fa-filter me-1"></i> Apply
                    </button>
                </div>
            </form>
            <?php if (!empty($search_term) || !empty($filter_type)): ?>
                <div class="mt-2 small text-muted">
                    Show results for: 
                    <?php if (!empty($search_term)) echo "<strong>Search:</strong> " . htmlspecialchars($search_term) . " | "; ?>
                    <?php if (!empty($filter_type)) echo "<strong>Type:</strong> " . htmlspecialchars($filter_type); ?>
                    <a href="student_borrow.php" class="ms-2 text-danger text-decoration-none">Clear Filters</a>
                </div>
            <?php endif; ?>
        </div>
        <div class="row">
            <?php if (!empty($available_apparatus)): ?>
                <?php foreach ($available_apparatus as $app): 
                    $is_out_of_stock = ($app['available_stock'] <= 0);
                    $card_class = $is_out_of_stock ? 'out-of-stock-card' : '';
                    $max_qty = $app['available_stock'] > 0 ? $app['available_stock'] : 0;
                    $input_disabled = ($is_locked || $is_out_of_stock) ? "disabled" : "";
                    
                    $imageFile = "../uploads/apparatus_images/" . ($app['image'] ?? 'default.jpg');
                    if (empty($app['image']) || !file_exists("../uploads/apparatus_images/" . ($app['image'] ?? ''))) {
                        $imageFile = "../uploads/apparatus_images/default.jpg";
                    }
                ?>
                <div class="col-md-6 col-lg-4 mb-4">
                    <div class="apparatus-card <?= $card_class ?>">
                        <img src="<?= $imageFile ?>" class="card-img-top" alt="<?= htmlspecialchars($app['name']) ?>">
                        <div class="card-body-custom">
                            <h5 class="card-title fw-bold text-start text-dark"><?= htmlspecialchars($app['name']) ?></h5>
                            <div class="item-details text-start">
                                Type: <span class="fw-bold"><?= htmlspecialchars($app['apparatus_type']) ?></span> | 
                                Size: <span><?= htmlspecialchars($app['size']) ?></span>
                            </div>
                            <div class="item-description">
                                <?= htmlspecialchars($app['description']) ?>
                            </div>
                            <div class="action-area">
                                <div>
                                    Available: <strong class="<?= $is_out_of_stock ? 'text-danger' : 'text-success' ?>"><?= $max_qty ?></strong>
                                </div>
                                <div class="d-flex align-items-center">
                                    <input type="number" 
                                        data-apparatus-id="<?= $app['id'] ?>"
                                        data-apparatus-name="<?= htmlspecialchars($app['name']) ?>"
                                        data-max-qty="<?= $max_qty ?>"
                                        value="0" 
                                        min="0" 
                                        max="<?= $max_qty ?>"
                                        class="form-control form-control-sm qty-input me-2" 
                                        <?= $input_disabled ?>>
                                    
                                    <button type="button" 
                                        class="btn-add-request"
                                        onclick="addToRequest(this)"
                                        <?= $input_disabled ?>>
                                        <i class="fas fa-plus"></i> Add
                                    </button>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
                <?php endforeach; ?>
            <?php else: ?>
                <div class="col-12">
                    <p class="text-center text-muted py-3">No apparatus matched your search or filter criteria.</p>
                </div>
            <?php endif; ?>
        </div>
        
        <?php if ($totalPages > 1): ?>
        <nav>
            <ul class="pagination justify-content-center">
                <li class="page-item <?= ($currentPage <= 1) ? 'disabled' : '' ?>">
                    <a class="page-link" href="#" data-page="<?= $currentPage - 1 ?>" onclick="manualPagination(event, this.dataset.page)"><i class="fas fa-chevron-left"></i> Previous</a>
                </li>
                <?php 
                $start_page = max(1, $currentPage - 1);
                $end_page = min($totalPages, $currentPage + 1);
                
                if ($currentPage == 1 && $totalPages >= 3) $end_page = 3;
                if ($currentPage == $totalPages && $totalPages >= 3) $start_page = $totalPages - 2;

                for ($i = $start_page; $i <= $end_page; $i++): ?>
                    <li class="page-item <?= ($currentPage == $i) ? 'active' : '' ?>">
                            <a class="page-link" href="#" data-page="<?= $i ?>" onclick="manualPagination(event, this.dataset.page)"><?= $i ?></a>
                    </li>
                <?php endfor; ?>
                <li class="page-item <?= ($currentPage >= $totalPages) ? 'disabled' : '' ?>">
                    <a class="page-link" href="#" data-page="<?= $currentPage + 1 ?>" onclick="manualPagination(event, this.dataset.page)">Next <i class="fas fa-chevron-right"></i></a>
                </li>
            </ul>
        </nav>
        <?php endif; ?>

    </div>
</div>

<div class="modal fade" id="lockWarningModal" tabindex="-1" aria-labelledby="lockWarningModalLabel" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content">
            <div class="modal-header bg-danger text-white">
                <h5 class="modal-title fw-bold" id="lockWarningModalLabel">🛑 Borrowing Blocked!</h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body" id="lockWarningModalBody">
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-danger" data-bs-dismiss="modal">Acknowledge</button>
            </div>
        </div>
    </div>
</div>

<div class="modal fade" id="statusModal" tabindex="-1" aria-labelledby="statusModalLabel" aria-hidden="true">
    <div class="modal-dialog modal-lg modal-dialog-centered"> 
        <div class="modal-content">
            <div class="modal-header" id="statusModalHeader">
                <h5 class="modal-title fw-bold" id="statusModalLabel"></h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body" id="statusModalBody">
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Close</button>
            </div>
        </div>
    </div>
</div>

<div class="modal fade" id="typeErrorModal" tabindex="-1" aria-labelledby="typeErrorModalLabel" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered modal-sm">
        <div class="modal-content">
            <div class="modal-header bg-danger text-white">
                <h5 class="modal-title" id="typeErrorModalLabel">🚨 Required Selection</h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body text-center">
                <p class="fw-bold mb-0">Please select a request Type (Borrow or Reserve) first.</p>
            </div>
            <div class="modal-footer justify-content-center">
                <button type="button" class="btn btn-danger" data-bs-dismiss="modal">OK</button>
            </div>
        </div>
    </div>
</div>

<div class="modal fade" id="qtyErrorModal" tabindex="-1" aria-labelledby="qtyErrorModalLabel" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered modal-sm">
        <div class="modal-content">
            <div class="modal-header bg-warning text-dark">
                <h5 class="modal-title" id="qtyErrorModalLabel"><i class="fas fa-exclamation-triangle"></i> Quantity Error</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body text-center">
                <p class="fw-bold mb-0" id="qtyErrorModalBody"></p>
            </div>
            <div class="modal-footer justify-content-center">
                <button type="button" class="btn btn-warning" data-bs-dismiss="modal">Got it</button>
            </div>
        </div>
    </div>
</div>

<div class="modal fade" id="termsModal" tabindex="-1" aria-labelledby="termsModalLabel" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered modal-dialog-scrollable">
        <div class="modal-content">
            <div class="modal-header" style="background-color: var(--msu-red); color: white;">
                <h5 class="modal-title" id="termsModalLabel">
                    <i class="fas fa-file-contract me-2"></i> Terms and Conditions of Apparatus Borrowing
                </h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body">
                <p class="fw-bold text-danger">By proceeding with this request, you agree to the following conditions:</p>

                <h6>1. Loan Duration (Strict No Overnight)</h6>
                <ul>
                    <li>All items must be returned on the same day they are borrowed or reserved. No apparatus may be kept overnight.</li>
                    <li>Reservations are valid for up to 3 days in the future.</li>
                </ul>

                <h6>2. Active Transaction Limit</h6>
                <p>You are limited to a maximum of 3 active transactions (including pending approvals, reservations, and currently borrowed items) at any time.</p>

                <h6>3. Liability for Loss or Damage</h6>
                <p>You are fully responsible for the borrowed apparatus. Any damage or loss confirmed by staff will result in immediate liability and require payment for replacement or repair.</p>

                <h6>4. Return Procedure and Penalties</h6>
                <p>All items must be returned to laboratory staff for inspection on the expected return date. Failure to return the item on time will result in an immediate warning (1 day grace period). If the item is still not returned after the grace period, your account will be suspended from further borrowing.</p>

                <p class="mt-4 text-center fw-bold text-danger">FAILURE TO COMPLY WILL RESULT IN SUSPENSION OF BORROWING PRIVILEGES.</p>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Close & Acknowledge</button>
            </div>
        </div>
    </div>
</div>
<div class="modal fade" id="notificationsModal" tabindex="-1" aria-labelledby="notificationsModalLabel" aria-hidden="true">
    <div class="modal-dialog modal-dialog-scrollable">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title" id="notificationsModalLabel"><i class="fas fa-bell me-2"></i> Your Notifications History</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body notification-modal-body" id="notification-history-content">
                <div class="text-center p-4">Loading...</div>
            </div>
            <div class="modal-footer">
                <div class="text-muted small me-auto">Alerts marked as read upon viewing.</div>
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Close</button>
            </div>
        </div>
    </div>
</div>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
<script>
    // Global variable to hold the requested items (The "Cart")
    let requestArray = [];
    const typeErrorModal = new bootstrap.Modal(document.getElementById('typeErrorModal'));
    const qtyErrorModal = new bootstrap.Modal(document.getElementById('qtyErrorModal'));
    
    // Get the DOM elements for the Bootstrap modals
    const statusModalElement = document.getElementById('statusModal');
    const statusModal = new bootstrap.Modal(statusModalElement);
    
    // NEW MODAL FOR WARNING CHAIN
    const lockWarningModalElement = document.getElementById('lockWarningModal');
    const lockWarningModal = new bootstrap.Modal(document.getElementById('lockWarningModal'));
    
    // --- JAVASCRIPT FOR AUTO-CALCULATION (No Overnight Rule) ---
    function updateExpectedReturnDate() {
        const borrowDateInput = document.getElementById('borrow_date');
        const expectedReturnDisplay = document.getElementById('expected_return_date_display');
        const expectedReturnHidden = document.getElementById('expected_return_date_hidden');
        
        const borrowDateStr = borrowDateInput.value;
        
        // 1. Clear fields if prerequisites are missing
        if (!borrowDateStr) {
            expectedReturnDisplay.value = '';
            expectedReturnHidden.value = '';
            return;
        }
        
        // Reformat YYYY-MM-DD input for MM/DD/YYYY display
        let displayDateStr = borrowDateStr;
        try {
            const dateParts = borrowDateStr.split('-');
            if (dateParts.length === 3) {
                // Display in MM/DD/YYYY format for user clarity, although the hidden field remains YYYY-MM-DD
                displayDateStr = `${dateParts[1]}/${dateParts[2]}/${dateParts[0]}`; 
            }
        } catch (e) {
            // Use raw string if formatting fails
        }

        // 2. Set Return Date = Borrow Date (No Overnight Rule)
        expectedReturnDisplay.value = displayDateStr; // Display format for user
        expectedReturnHidden.value = borrowDateStr; // Keep YYYY-MM-DD for PHP validation/database
        
        // Crucial: Update the sticky hidden input in the filter form for date
        document.getElementById('filter_borrow_date_sticky').value = borrowDateStr;
        document.getElementById('filter_expected_return_date_sticky').value = borrowDateStr;
    }

    // --- Function to handle visual selection and update hidden field (Sticky Fix) ---
    window.toggleType = function(typeValue) {
        const typeHiddenInput = document.getElementById('type_hidden');
        const filterTypeHiddenInput = document.getElementById('filter_type_sticky');
        const buttons = document.querySelectorAll('.type-btn');

        // Toggle logic
        if (typeHiddenInput.value === typeValue) {
            // If currently selected, unselect it
            typeHiddenInput.value = '';
            filterTypeHiddenInput.value = '';
            buttons.forEach(btn => btn.classList.remove('selected'));
        } else {
            // If unselected or different, set the new value
            buttons.forEach(btn => {
                if (btn.getAttribute('data-value') === typeValue) {
                    btn.classList.add('selected');
                } else {
                    btn.classList.remove('selected');
                }
            });
            typeHiddenInput.value = typeValue;
            filterTypeHiddenInput.value = typeValue;
        }
        
        updateExpectedReturnDate();
    }

    // --- Function to open the terms modal ---
    window.openTermsModal = function(event) {
        event.preventDefault(); 
        const termsModal = new bootstrap.Modal(document.getElementById('termsModal'));
        termsModal.show();
    }
    
    // --- REQUEST ARRAY (CART) MANAGEMENT (Using Modals) ---
    
    window.addToRequest = function(button) {
        const input = button.previousElementSibling;
        const qty = parseInt(input.value);
        const id = parseInt(input.getAttribute('data-apparatus-id'));
        const name = input.getAttribute('data-apparatus-name');
        const maxQty = parseInt(input.getAttribute('data-max-qty'));
        const typeSelected = document.getElementById('type_hidden').value;
        const qtyErrorBody = document.getElementById('qtyErrorModalBody');

        // This check remains: The button is disabled by PHP/JS if the whole borrowing mechanism is locked.
        const submitButton = document.getElementById('submitButton');
        if (submitButton.disabled && submitButton.getAttribute('data-is-locked') === 'true') {
            return;
        }

        if (typeSelected === '') {
            typeErrorModal.show();
            return;
        }
        if (qty <= 0 || isNaN(qty)) {
            qtyErrorBody.textContent = "Please enter a quantity greater than zero.";
            qtyErrorModal.show();
            return;
        }
        if (qty > maxQty) {
            qtyErrorBody.textContent = `Requested quantity (${qty}) exceeds available stock (${maxQty}).`;
            qtyErrorModal.show();
            return;
        }

        const existingIndex = requestArray.findIndex(item => item.id === id);

        if (existingIndex !== -1) {
            requestArray[existingIndex].quantity = qty;
        } else {
            requestArray.push({ id: id, name: name, quantity: qty });
        }
        
        input.value = 0;
        updateRequestDisplay();
    }
    
    window.removeFromRequest = function(id) {
        requestArray = requestArray.filter(item => item.id !== id);
        updateRequestDisplay();
    }

    // CRITICAL FIX: Initializes JS array from PHP/Hidden Input on page load
    function loadRequestArrayFromHidden() {
        const hiddenInput = document.getElementById('request_array_json');
        let jsonString = hiddenInput.value;

        if (jsonString) {
            // Decode the URL encoding first, then parse the JSON string
            try {
                const decodedString = decodeURIComponent(jsonString);
                requestArray = JSON.parse(decodedString);
            } catch (e) {
                console.error("Error loading request array from hidden input:", e);
                requestArray = []; // Fallback to empty array
            }
        } else {
            requestArray = [];
        }
        updateRequestDisplay(); 
    }


    function updateRequestDisplay() {
        const displayDiv = document.getElementById('request-list-display');
        const hiddenInput = document.getElementById('request_array_json');
        const filterHiddenInput = document.getElementById('filter_request_array_json_sticky');
        const requestCountSpan = document.getElementById('request-count');
        const submitButton = document.getElementById('submitButton');
        
        displayDiv.innerHTML = '';
        
        if (requestArray.length === 0) {
            displayDiv.innerHTML = '<p class="text-muted small mb-0">No items added to the request yet.</p>';
            hiddenInput.value = '[]';
            filterHiddenInput.value = '[]'; // Update filter sticky value
            requestCountSpan.textContent = '0';
        } else {
            let totalQty = 0;
            requestArray.forEach(item => {
                const itemDiv = document.createElement('div');
                itemDiv.className = 'request-item';
                itemDiv.innerHTML = `
                    <span>${item.name} (x${item.quantity})</span>
                    <button type="button" class="remove-btn" onclick="removeFromRequest(${item.id})" aria-label="Remove item">
                        <i class="fas fa-times-circle"></i>
                    </button>
                `;
                displayDiv.appendChild(itemDiv);
                totalQty += item.quantity;
            });
            const jsonString = encodeURIComponent(JSON.stringify(requestArray));
            hiddenInput.value = jsonString; // For form submission
            filterHiddenInput.value = jsonString; // For navigation stickiness
            requestCountSpan.textContent = totalQty;
        }

        // --- SUBMIT BUTTON STATE LOGIC ---
        const isLockedByPHP = submitButton.getAttribute('data-is-locked') === 'true';
        
        if (isLockedByPHP) {
            submitButton.disabled = true;
            submitButton.classList.add('disabled');
        } else {
            submitButton.disabled = false;
            submitButton.classList.remove('disabled');
        }
        // --- END SUBMIT BUTTON STATE LOGIC ---
    }
    // -------------------------------------------------------------

    // --- NAVIGATION FIX (Most Robust Method) ---
    window.manualPagination = function(event, targetPage) {
        event.preventDefault(); 
        const type = document.getElementById('filter_type_sticky').value;
        const borrowDate = document.getElementById('filter_borrow_date_sticky').value;
        const requestJson = document.getElementById('filter_request_array_json_sticky').value; 
        const terms = document.getElementById('filter_agree_terms_sticky').value;
        const searchTerm = document.getElementById('search').value;
        const filterType = document.getElementById('filter_type_select').value;
        const params = {
            page: targetPage,
            type: type,
            borrow_date: borrowDate,
            request_array_json: requestJson, 
            agree_terms: terms,
            s: searchTerm,
            filter_type: filterType,
            scroll_to_apparatus: 'true' 
        };
        const cleanedParams = Object.keys(params).filter(key => params[key] !== '' && params[key] !== '0').reduce((obj, key) => {
            obj[key] = params[key];
            return obj;
        }, {});
        const queryString = new URLSearchParams(cleanedParams).toString();
        window.location.href = 'student_borrow.php?' + queryString;
    }
    // ----------------------
    
    // --- DROPDOWN NOTIFICATION LOGIC (COPIED FROM DASHBOARD) ---

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
        const apiPath = '../api/get_notifications.php'; 
        
        $.getJSON(apiPath, function(response) { 
            
            const unreadCount = response.count; 
            const notifications = response.alerts || []; 
            
            const $badge = $('#notification-bell-badge');
            const $dropdown = $('#notification-dropdown');
            const $viewAllLink = $dropdown.find('a[href="student_transaction.php"]').detach(); 
            
            // 1. Update the Badge Count
            $badge.text(unreadCount);
            $badge.toggle(unreadCount > 0); 

            // 2. Clear previous dynamic items
            const $placeholder = $dropdown.find('.dynamic-notif-placeholder').empty();
            $dropdown.find('.mark-all-btn-wrapper').remove(); 
            
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


    // --- DOMContentLoaded Execution (Initialization) ---
    document.addEventListener('DOMContentLoaded', () => {
        // ... (Initialization logic) ...
        
        const borrowDateInput = document.getElementById('borrow_date');
        borrowDateInput.addEventListener('change', updateExpectedReturnDate);

        // CRITICAL FIX 1A: Ensure the expected return date is calculated/displayed on load
        updateExpectedReturnDate(); 
        
        // CRITICAL FIX 2A: Load the request array from the hidden input on page load and display it
        loadRequestArrayFromHidden(); 

        // --- NOTIFICATION EXECUTION ---
        fetchStudentAlerts(); // Initial fetch on page load
        setInterval(fetchStudentAlerts, 30000); // Poll the server every 30 seconds
        
        // SCROLL LOGIC (remains unchanged)
        const urlParams = new URLSearchParams(window.location.search);
        if (urlParams.get('scroll_to_apparatus') === 'true') {
            const apparatusSection = document.getElementById('available-apparatus-section');
            if (apparatusSection) {
                window.scrollTo({
                    top: apparatusSection.offsetTop - 100, 
                    behavior: 'smooth'
                });
            }
        }
    });
</script>
</body>
</html>