<?php
session_start();
require_once "../vendor/autoload.php";
require_once "../classes/Transaction.php";
require_once "../classes/Database.php";

if (!isset($_SESSION["user"]) || $_SESSION["user"]["role"] != "student") {
    header("Location: ../pages/login.php");
    exit;
}

$transaction = new Transaction();
$student_id = $_SESSION["user"]["id"];

// --- BAN/LOCK STATUS CHECKS (used only for sidebar rendering) ---
$isBanned = $transaction->isStudentBanned($student_id); 
$activeCount = $transaction->getActiveTransactionCount($student_id);

// --- FILTERING LOGIC ---
$filter = isset($_GET["filter"]) ? $_GET["filter"] : "all";

// Fetch ALL student transactions for history view (targets borrow_forms)
$transactions = $transaction->getStudentTransactions($student_id);

// We rely on PHP's array filtering for simplicity, as in the original code.
if ($filter != "all") {
    $filtered_transactions = array_filter($transactions, function($t) use ($filter) {
        return strtolower($t["status"]) === strtolower($filter);
    });
} else {
    $filtered_transactions = $transactions;
}

// Re-index array after filtering for use with foreach
$filtered_transactions = array_values($filtered_transactions);

// Define the absolute web root path. If your site is http://localhost/wd123/...
$webRootURL = "/wd123/uploads/apparatus_images/"; 
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Student - Transaction History</title>
    
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
        --header-bg: #e9ecef;
        --danger-light: #fbe6e7;
        --danger-dark: #8b0000;
        --main-text: #333;
        --header-height: 60px; 
        
        /* Define solid colors based on staff_dashboard.php */
        --status-returned-solid: #198754; 
        --status-overdue-solid: #dc3545; 
        --status-borrowed-solid: #0d6efd; 
        --status-pending-solid: #ffc107; 
    }
    
    body {
        font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
        background: var(--bg-light); 
        padding: 0;
        margin: 0;
        display: flex; 
        min-height: 100vh;
        /* INCREASED BASE FONT SIZE */
        font-size: 1.05rem;
    }

    /* NEW CSS for Mobile Toggle */
    .menu-toggle {
        display: none; /* Hidden on desktop */
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
    
    /* === TOP HEADER BAR STYLES (Restored Bell Position) === */
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
        z-index: 1051; 
    }
    .dropdown-header {
        font-size: 1rem;
        color: #6c757d;
        padding: 10px 15px;
        text-align: center;
        border-bottom: 1px solid #eee;
        margin-bottom: 0;
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
    /* === END TOP HEADER BAR STYLES === */


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
        width: 90px; /* FIXED WIDTH */
        height: 90px; /* FIXED HEIGHT */
        object-fit: contain; 
        margin: 0 auto 15px auto; 
        display: block; 
    }
    
    .sidebar-header .title { font-size: 1.3rem; line-height: 1.1; }

    /* FIX: Set consistent padding (15px) and enforce flex for consistent icon spacing */
    .sidebar .nav-link {
        color: white;
        padding: 15px 20px; /* FIXED: from 18px */
        font-size: 1.1rem;
        font-weight: 600;
        transition: background-color 0.3s;
        display: flex; /* Added for text/icon spacing consistency */
        align-items: center; /* Added for vertical alignment */
    }
    .sidebar .nav-link:hover, .sidebar .nav-link.active {
        background-color: var(--msu-red-dark);
    }
    .sidebar .nav-link.banned { 
        background-color: #5a2624; 
        opacity: 0.8; 
    }
    .logout-link { margin-top: auto; border-top: 1px solid rgba(255, 255, 255, 0.1); }
    .logout-link .nav-link { 
        background-color: #C62828 !important; 
        color: white !important;
    }
    .logout-link .nav-link:hover {
        background-color: var(--msu-red-dark) !important; 
    }
    
    .main-wrapper {
        margin-left: var(--sidebar-width); 
        /* ADDED PADDING TOP for fixed header */
        padding: 25px;
        padding-top: calc(var(--header-height) + 25px); 
        flex-grow: 1;
    }
    
    /* MODIFIED: Stretched Container for Full Width */
    .container {
        background: #fff;
        border-radius: 12px;
        /* INCREASED CONTAINER PADDING */
        padding: 40px 50px;
        box-shadow: 0 5px 20px rgba(0,0,0,0.1);
        max-width: none;
        width: 95%;
        margin: 0 auto;
    }
    
    /* INCREASED HEADER FONT SIZE */
    h2 { 
        border-bottom: 2px solid var(--msu-red); 
        padding-bottom: 15px; 
        font-size: 2.2rem;
        font-weight: 700;
    }
    /* INCREASED LEAD FONT SIZE */
    .lead { 
        font-size: 1.25rem;
        margin-bottom: 30px; 
    }

    /* --- Filter Styles (Improved Spacing/Size) --- */
    .filter {
        margin-bottom: 25px;
        font-size: 1.05rem;
    }
    .filter .form-select {
        font-size: 1rem;
        padding: 0.5rem 1rem;
        height: 40px;
        border-radius: 8px;
    }

    /* --- Table Redesign Styles --- */
    .table-responsive {
        border-radius: 10px;
        overflow: hidden;
        border: 1px solid #e0e0e0;
        margin-top: 20px;
    }
    .table {
        --bs-table-bg: #fff;
        --bs-table-striped-bg: #f8f8f8;
        font-size: 1.05rem; /* Base table font size */
    }

    /* Table Header */
    .table thead th { 
        background: #e9ecef !important; 
        color: #555; 
        font-weight: 700;
        border-bottom: 2px solid #ccc;
        vertical-align: middle;
        font-size: 1.05rem; /* INCREASED HEADER FONT SIZE */
        padding: 15px 15px; /* INCREASED HEADER PADDING */
    }

    /* Table Body */
    .table td {
        /* INCREASED ROW PADDING */
        padding: 18px 15px;
        border-top: 1px solid #e9ecef;
        vertical-align: middle;
        font-size: 1rem; /* INCREASED BODY FONT SIZE */
    }

    /* Row Highlight for Critical Statuses */
    .status-danger-row {
        background-color: var(--danger-light) !important;
    }
    .table-striped > tbody > .status-danger-row:nth-of-type(odd) > * {
        background-color: #fcebeb !important;
    }

    /* Status Tags (Increased Size) */
    .status {
        display: inline-block;
        /* INCREASED BADGE SIZE */
        padding: 8px 15px;
        border-radius: 20px;
        font-weight: 700;
        text-transform: uppercase; 
        font-size: 0.85rem; 
        line-height: 1.2;
        min-width: 110px; /* Increased minimum width */
        text-align: center;
        color: white;
    }
    
    /* --- MODIFIED STATUS STYLES TO MATCH PHP OUTPUT --- */
    .status.waitingforapproval { 
        background-color: var(--status-pending-solid); 
        color: #333; 
    } 
    .status.approved { 
        background-color: #0d6efd; /* Blue */
    }
    .status.rejected { 
        background-color: #6c757d; /* Gray for rejected */
    }
    .status.borrowed { 
        background-color: var(--status-borrowed-solid); 
    }
    .status.returned { 
        background-color: var(--status-returned-solid); 
    }
    
    /* Critical Status Highlight */
    .status.overdue, .status.returned_late { 
        background-color: var(--status-overdue-solid); 
        color: white; 
    }
    
    .status.damaged { 
        background-color: #343a40; 
        color: white; 
    }
    /* --- END MODIFIED STATUS STYLES --- */

    /* View Button (Increased Size) */
    .btn-view-items {
        background: var(--msu-red); 
        color: white;
        /* INCREASED BUTTON SIZE */
        padding: 10px 20px;
        border-radius: 8px;
        font-size: 1rem;
        font-weight: 600;
        white-space: nowrap; /* Prevent button text from wrapping on desktop */
    }
    .btn-view-items:hover { background: var(--msu-red-dark); color: white; }

    /* Transaction Details Column (Image + ID/Type) */
    .trans-details {
        display: flex;
        align-items: center;
        text-align: left !important;
    }
    /* INCREASED IMAGE SIZE */
    .trans-details img {
        width: 60px;
        height: 60px;
        object-fit: contain;
        border-radius: 8px; 
        margin-right: 15px;
        border: 1px solid #ddd;
        padding: 5px;
    }
    /* INCREASED ID/TYPE TEXT SIZE */
    .trans-id {
        font-weight: 700;
        font-size: 1.2rem;
        color: #333;
    }
    .trans-type {
        font-size: 0.95rem;
        color: #6c757d;
        display: block;
    }
    
    /* Date Styles */
    /* INCREASED DATE TEXT SIZE */
    .date-col {
        line-height: 1.6;
        font-size: 1.05rem;
    }
    .date-col span {
        display: block;
    }
    .date-col .expected { color: var(--danger-dark); font-weight: 600; }
    .date-col .actual { color: var(--status-returned-solid); font-weight: 600; }
    .date-col .borrow { color: #333; font-weight: 500;}

    /* Remarks style */
    .remarks-col {
        max-width: 250px;
        white-space: nowrap;
        overflow: hidden;
        text-overflow: ellipsis; 
        text-align: left !important;
        font-size: 1rem;
    }
    .remarks-col:hover {
        overflow: visible; 
        white-space: normal;
        position: relative;
        background: #fff;
        z-index: 10;
        box-shadow: 0 0 5px rgba(0,0,0,0.2);
        border: 1px solid #ccc;
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
        
        /* HIDE EYE ICON ON TABLET/LAPTOP (<= 992px) */
        .btn-view-items .fas.fa-eye {
            display: none;
        }
    }
    
    /* Tablet/Medium Screen (Max 768px) */
    @media (max-width: 768px) {
        /* HIDE STAFF REMARKS COLUMN */
        .table thead th:nth-child(4), 
        .table tbody td:nth-child(4) { 
            display: none;
        }

        /* Adjust Transaction Details column for stacking/spacing */
        .trans-details {
            flex-direction: column;
            align-items: flex-start;
        }
        .trans-details img {
            margin-right: 0;
            margin-bottom: 5px;
            width: 40px !important;
            height: 40px !important;
        }
        .trans-id {
            font-size: 1rem;
        }
        .trans-type {
            font-size: 0.85rem;
        }
        
        /* Adjust Dates column */
        .date-col {
            font-size: 0.95rem; 
            line-height: 1.4;
        }
        
        /* Adjust Action column: Button becomes full width of the cell */
        .table tbody td:last-child {
            width: 15%; 
        }
        .btn-view-items {
            padding: 8px 15px; /* Maintain smaller padding on tablets */
            width: 100%; 
            text-align: center;
        }
        
        /* Adjust Filter row */
        .filter form {
            flex-direction: column;
            align-items: stretch !important;
        }
        .filter label {
            margin-bottom: 5px !important;
        }
        .filter .form-select {
            width: 100% !important;
        }
        
        /* General Table Cell/Row Padding */
        .table td {
             padding: 10px 8px;
        }
    }
    
    /* Small Mobile Screen (Max 576px) */
    @media (max-width: 576px) {
         /* HIDE DATES COLUMN (Small Mobile) */
        .table thead th:nth-child(2), 
        .table tbody td:nth-child(2) { 
            display: none; 
        }
        .top-header-bar {
            padding: 0 15px;
            justify-content: flex-end;
            padding-left: 65px;
        }
        .container {
             padding: 20px;
        }
        
         .table thead th, .table tbody td {
            font-size: 0.8rem;
         }

         /* Re-confirm full width view button on smallest screen */
         .btn-view-items {
             padding: 6px 10px;
         }
         .status {
            min-width: 80px;
            font-size: 0.75rem;
            padding: 6px 8px;
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
            <a href="student_return.php" class="nav-link">
                <i class="fas fa-redo fa-fw me-2"></i> Initiate Return
            </a>
        </li>
        <li class="nav-item">
            <a href="student_transaction.php" class="nav-link active">
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
                    <a class="dropdown-item text-center small text-muted dynamic-notif-item">Loading notifications...</a>
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
        <h2 class="mb-4"><i class="fas fa-history me-2 text-secondary"></i> Full Transaction History</h2>
        <p class="lead text-start">View all past and current borrowing/reservation records.</p>

        <div class="filter">
            <form method="get" action="student_transaction.php" class="d-flex align-items-center">
                <label class="form-label me-2 mb-0 fw-bold text-secondary">Filter by Status:</label>
                <select name="filter" onchange="this.form.submit()" class="form-select form-select-sm w-auto">
                    <option value="all" <?= $filter == 'all' ? 'selected' : '' ?>>Show All</option>
                    <option value="waiting_for_approval" <?= $filter == 'waiting_for_approval' ? 'selected' : '' ?>>Pending Approval</option>
                    <option value="approved" <?= $filter == 'approved' ? 'selected' : '' ?>>Approved/Reserved</option>
                    <option value="rejected" <?= $filter == 'rejected' ? 'selected' : '' ?>>Rejected</option>
                    <option value="borrowed" <?= $filter == 'borrowed' ? 'selected' : '' ?>>Borrowed (Active)</option>
                    <option value="returned" <?= $filter == 'returned' ? 'selected' : '' ?>>Returned</option>
                    <option value="overdue" <?= $filter == 'overdue' ? 'selected' : '' ?>>Overdue</option>
                    <option value="damaged" <?= $filter == 'damaged' ? 'selected' : '' ?>>Damaged/Lost</option>
                </select>
            </form>
        </div>

        <div class="table-responsive">
            <table class="table table-striped align-middle">
            <thead>
            <tr>
                <th style="width: 25%;">Transaction Details</th> 
                <th style="width: 30%;">Dates (Borrow / Expected / Actual)</th>
                <th style="width: 15%;">Status</th>
                <th style="width: 20%;">Staff Remarks</th>
                <th style="width: 10%;">Action</th>
            </tr>
            </thead>
            <tbody>
            <?php
            $hasData = false;
            
            foreach ($filtered_transactions as $t):
                $hasData = true;
                
                // --- MODIFIED PHP LOGIC TO HANDLE RETURNED (LATE) CONSISTENTLY ---
                $raw_status = strtolower($t['status']);
                $display_status = $raw_status; // Base status text

                // Note: The status_class is generated here, removing underscores
                $status_class = str_replace('_', '', $raw_status);
                $row_class = ''; // Initialize row class

                // Check for LATE RETURN and override class/text
                if ($raw_status === 'returned' && (isset($t['is_late_return']) && $t['is_late_return'] == 1)) {
                    $display_status = 'returned (late)';
                    // Use a clean, consistent class name for CSS targeting
                    $status_class = 'returned_late'; 
                }

                // If the form status is a critical one (Damaged/Overdue) OR if it is a LATE RETURN, highlight the row.
                if (in_array($raw_status, ['overdue', 'damaged']) || $status_class === 'returned_late') {
                    $row_class = 'status-danger-row';
                }
                // --- END MODIFIED PHP LOGIC ---
                
                // FIX: Call getFormApparatus to ensure we have the image path
                $apparatusList = $transaction->getFormApparatus($t["id"]); 
                $firstApparatus = $apparatusList[0] ?? null;
                
                $imageFile = $firstApparatus["image"] ?? "default.jpg";
                
                // CRITICAL FIX: The full URL path (browser side)
                $imageURL = "../uploads/apparatus_images/" . $imageFile; 
                
                // Server-side check for robust fallback path
                $serverPath = __DIR__ . "/../uploads/apparatus_images/" . $imageFile;
                
                if (!file_exists($serverPath) || is_dir($serverPath)) {
                    $imageURL = "../uploads/apparatus_images/default.jpg";
                }
            ?>
                <tr class="<?= $row_class ?>">
                    <td class="trans-details">
                        <img src="<?= htmlspecialchars($imageURL) ?>" 
                            alt="Apparatus Image"
                            title="<?= htmlspecialchars($firstApparatus["name"] ?? 'N/A') ?>"
                            class="me-2"
                            style="width: 50px; height: 50px; object-fit: contain; border-radius: 6px; border: 1px solid #ddd; padding: 4px;">
                        <div>
                            <span class="trans-id">ID: <?= htmlspecialchars($t["id"]) ?></span>
                            <span class="trans-type"><?= htmlspecialchars(ucfirst($t["form_type"])) ?></span>
                        </div>
                    </td>

                    <td class="date-col">
                        <span class="borrow" title="Borrow Date"><i class="fas fa-calendar-alt fa-fw me-2 text-secondary"></i> **Borrow:** <?= htmlspecialchars($t["borrow_date"]) ?></span>
                        <span class="expected" title="Expected Return"><i class="fas fa-clock fa-fw me-2"></i> **Expected:** <?= htmlspecialchars($t["expected_return_date"]) ?></span>
                        <span class="actual" title="Actual Return Date"><i class="fas fa-check-circle fa-fw me-2"></i> **Actual:** <?= htmlspecialchars($t["actual_return_date"] ?? '-') ?></span>
                    </td>

                    <td>
                        <span class="status <?= htmlspecialchars($status_class) ?>">
                            <?= htmlspecialchars(str_replace('_', ' ', $display_status)) ?>
                        </span>
                    </td>
                    <td class="remarks-col text-start" title="<?= htmlspecialchars($t["staff_remarks"] ?? '-') ?>">
                        <?= htmlspecialchars($t["staff_remarks"] ?? '-') ?>
                    </td>
                    <td>
                        <a href="student_view_items.php?form_id=<?= $t["id"] ?>&context=history" class="btn-view-items">
                            <i class="fas fa-eye"></i> View
                        </a>
                    </td>
                </tr>
            <?php endforeach; ?>

            <?php if (!$hasData): ?>
                <tr><td colspan="5" class="text-center text-muted py-3">No transactions found for the selected filter.</td></tr>
            <?php endif; ?>
            </tbody>
            </table>
        </div>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
<script>
    // --- DROPDOWN NOTIFICATION LOGIC (Restored) ---

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
            const $placeholder = $dropdown.find('.dynamic-notif-placeholder').empty();
            
            // Re-append the 'View All' link to the end of the dropdown
            const $viewAllLink = $dropdown.find('a[href="student_transaction.php"]').detach(); 
            
            // 1. Update the Badge Count
            $badge.text(unreadCount);
            $badge.toggle(unreadCount > 0); 

            // 2. Clear previous dynamic items
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
        // 1. Sidebar activation logic
        const path = window.location.pathname.split('/').pop() || 'student_dashboard.php';
        const links = document.querySelectorAll('.sidebar .nav-link');
        links.forEach(link => {
            const linkPath = link.getAttribute('href').split('/').pop();
            
            // This ensures the link is active based on the current file name
            if (linkPath === path) {
                link.classList.add('active');
            } else {
                link.classList.remove('active');
            }
        });
        
        // 2. Notification Logic Setup
        fetchStudentAlerts(); // Initial fetch on page load
        setInterval(fetchStudentAlerts, 30000); // Poll the server every 30 seconds

        // 3. New Mobile Toggle Logic
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