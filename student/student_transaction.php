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
        // Check for late return status separately if filter is 'returned_late'
        if ($filter === 'returned_late' && strtolower($t["status"]) === 'returned' && ($t['is_late_return'] ?? 0) == 1) {
             return true;
        }
        return strtolower($t["status"]) === strtolower($filter);
    });
} else {
    $filtered_transactions = $transactions;
}

// Re-index array after filtering for use with foreach
$filtered_transactions = array_values($filtered_transactions);

// --- NEW FUNCTION: FLATTEN FORMS INTO ITEM-ROWS ---
function getStudentDetailedItemRows(array $forms, $transaction) {
    $rows = [];
    foreach ($forms as $form) {
        $form_id = $form['id'];
        $detailed_items = $transaction->getFormItems($form_id);

        if (empty($detailed_items)) {
            $detailed_items = [
                ['name' => 'N/A', 
                'quantity' => 1, 
                'item_status' => $form['status'],
                'is_late_return' => $form['is_late_return'] ?? 0] 
            ];
        }

        foreach ($detailed_items as $index => $item) {
            $raw_status = strtolower($form['status']);
            $item_status = strtolower($item['item_status'] ?? $raw_status);
            $is_late_return = $item['is_late_return'] ?? ($form['is_late_return'] ?? 0);
            
            $display_status = str_replace('_', ' ', $item_status);
            $status_class = str_replace('_', '', $item_status);
            
            if ($item_status === 'returned' && $is_late_return) {
                 $display_status = 'returned (late)';
                 $status_class = 'returnedlate';
            }
            // For overdue, just use the raw status but ensure the status tag styling works
            if ($item_status === 'overdue') {
                 $status_class = 'overdue';
            }
            
            $rows[] = [
                // Transaction-level details, repeated for every item row
                'form_id' => $form['id'],
                'form_type' => ucfirst($form['form_type']),
                'borrow_date' => $form['borrow_date'],
                'expected_return_date' => $form['expected_return_date'],
                'actual_return_date' => $form['actual_return_date'] ?? '-',
                'staff_remarks' => $form['staff_remarks'] ?? '-',
                
                // Item-level details
                'item_name' => htmlspecialchars($item['name'] ?? '-'),
                'item_quantity' => $item['quantity'] ?? 1,
                'item_status_display' => htmlspecialchars(ucwords($display_status)),
                'item_status_class' => htmlspecialchars($status_class),
                
                // For CSS grouping
                'is_first_item' => ($index === 0) 
            ];
        }
    }
    return $rows;
}

$detailedItemRows = getStudentDetailedItemRows($filtered_transactions, $transaction);

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
        --primary-color: #A40404; /* Dark Red / Maroon (WMSU-inspired) */
        --primary-color-dark: #820303; 
        --secondary-color: #f4b400; /* Gold/Yellow Accent */
        --text-dark: #2c3e50;
        --sidebar-width: 280px; 
        --bg-light: #f5f6fa;
        --header-height: 60px; 
        
        /* Status Colors */
        --danger-color: #dc3545; 
        --warning-color: #ffc107;
        --success-color: #28a745; 
        --info-color: #0d6efd; 

        /* Define solid colors for status tags */
        --status-returned-solid: var(--success-color); 
        --status-overdue-solid: var(--danger-color); 
        --status-borrowed-solid: var(--info-color); 
        --status-pending-solid: var(--warning-color);
        --status-rejected-solid: #6c757d; 
        --status-damaged-solid: var(--text-dark); 
    }
    
    body {
        font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
        background: var(--bg-light); 
        padding: 0;
        margin: 0;
        display: flex; 
        min-height: 100vh;
        font-size: 1.05rem;
        color: var(--text-dark);
    }

    /* NEW CSS for Mobile Toggle */
    .menu-toggle {
        display: none;
        position: fixed;
        top: 15px;
        left: 20px;
        z-index: 1060; 
        background: var(--primary-color);
        color: white;
        border: none;
        padding: 8px 12px;
        border-radius: 6px;
        box-shadow: 0 2px 5px rgba(0,0,0,0.2);
    }
    
    /* === TOP HEADER BAR STYLES === */
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
        color: var(--primary-color);
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
        background-color: var(--secondary-color); 
        color: var(--text-dark);
        font-weight: bold;
    }
    .dropdown-menu { min-width: 300px; padding: 0; z-index: 1051; }
    .dropdown-item.unread-item { font-weight: 600; background-color: #f8f8ff; }
    .mark-read-hover-btn { opacity: 0; }
    .dropdown-item:hover .mark-read-hover-btn { opacity: 1; }
    /* === END TOP HEADER BAR STYLES === */


    /* --- Sidebar Styles (Consistent Look) --- */
    .sidebar { width: var(--sidebar-width); min-width: var(--sidebar-width); background-color: var(--primary-color); color: white; padding: 0; position: fixed; height: 100%; top: 0; left: 0; display: flex; flex-direction: column; box-shadow: 2px 0 5px rgba(0, 0, 0, 0.2); z-index: 1050; }
    .sidebar-header { text-align: center; padding: 20px 15px; font-size: 1.2rem; font-weight: 700; line-height: 1.15; color: #fff; border-bottom: 1px solid rgba(255, 255, 255, 0.4); margin-bottom: 20px; }
    .sidebar-header img { width: 90px; height: 90px; object-fit: contain; margin: 0 auto 15px auto; display: block; }
    .sidebar-header .title { font-size: 1.3rem; line-height: 1.1; }
    .sidebar .nav-link { color: white; padding: 15px 20px; font-size: 1.1rem; font-weight: 600; transition: background-color 0.3s; display: flex; align-items: center; }
    .sidebar .nav-link:hover, .sidebar .nav-link.active { background-color: var(--primary-color-dark); }
    .sidebar .nav-link.banned { background-color: #5a2624; opacity: 0.8; }
    .logout-link { margin-top: auto; border-top: 1px solid rgba(255, 255, 255, 0.1); }
    .logout-link .nav-link { background-color: #C62828 !important; color: white !important; } 
    .logout-link .nav-link:hover { background-color: var(--primary-color-dark) !important; }
    
    .main-wrapper {
        margin-left: var(--sidebar-width); 
        padding: 25px;
        padding-top: calc(var(--header-height) + 25px); 
        flex-grow: 1;
    }
    
    .container {
        background: #fff;
        border-radius: 12px;
        padding: 40px 50px;
        box-shadow: 0 8px 25px rgba(0,0,0,0.1); /* Stronger shadow */
        max-width: none;
        width: 95%;
        margin: 0 auto;
    }
    
    h2 { 
        border-bottom: 2px solid var(--primary-color); 
        padding-bottom: 15px; 
        font-size: 2.2rem;
        font-weight: 700;
    }
    .lead { 
        font-size: 1.15rem;
        margin-bottom: 30px; 
        color: #555;
    }

    /* --- Filter Styles --- */
    .filter {
        margin-bottom: 25px;
    }
    .filter .form-select {
        font-size: 1rem;
        padding: 0.5rem 1rem;
        height: 40px;
        border-radius: 8px;
        border-color: #ccc;
    }
    .filter .form-select:focus {
        border-color: var(--secondary-color);
        box-shadow: 0 0 0 3px rgba(244, 180, 0, 0.2);
    }


    /* --- Table Redesign Styles --- */
    .table-responsive {
        border-radius: 10px;
        overflow-x: auto; /* Ensure horizontal scroll on overflow */
        border: 1px solid #e0e0e0;
        margin-top: 20px;
        box-shadow: 0 2px 10px rgba(0,0,0,0.05);
    }
    .table {
        --bs-table-bg: #fff;
        --bs-table-striped-bg: #f8f8f8;
        font-size: 1.05rem;
        min-width: 950px;
    }

    /* Table Header */
    .table thead th { 
        background: #e9ecef !important; 
        color: var(--text-dark); 
        font-weight: 700;
        border-bottom: 2px solid #ccc;
        vertical-align: middle;
        font-size: 1rem;
        padding: 15px 15px;
        text-align: center; /* Ensure header alignment is center */
    }

    /* Table Body */
    .table td {
        padding: 18px 15px;
        border-top: 1px solid #e9ecef;
        vertical-align: middle;
        font-size: 1rem;
        text-align: center; /* Default cell alignment */
    }
    
    /* FIX 1: Explicitly align content that should be left-aligned */
    .table thead th:nth-child(2), /* Item Borrowed Header */
    .item-borrowed {
        text-align: left !important;
    }
    .table thead th:nth-child(7), /* Remarks Header (Desktop/Tablet view) */
    .remarks-col {
        text-align: left !important;
    }


    /* --- CUSTOM ROW GROUPING (Screen View Only) --- */
    .table tbody tr.first-item-of-group td {
        border-top: 2px solid #ccc !important;
    }
    .table tbody tr:first-child.first-item-of-group td {
        border-top: 1px solid #e9ecef !important;
    }

    /* Row Highlight for Critical Statuses */
    .status-danger-row {
        background-color: #fff0f0 !important;
    }
    .table-striped > tbody > .status-danger-row:nth-of-type(odd) > * {
        background-color: #fae0e0 !important;
    }

    /* Status Tags */
    .status {
        display: inline-block;
        padding: 8px 15px;
        border-radius: 20px;
        font-weight: 700;
        text-transform: uppercase; 
        font-size: 0.8rem; 
        line-height: 1.2;
        min-width: 110px;
        text-align: center;
        color: white;
    }
    
    /* --- STATUS STYLES --- */
    /* FIX 2: Pending/Waiting for Approval now uses warning color with DARK text */
    .status.waitingforapproval, .status.checking, .status.reserved { 
        background-color: var(--warning-color); 
        color: var(--text-dark); 
    } 
    .status.approved { background-color: var(--info-color); }
    .status.rejected { background-color: var(--status-rejected-solid); }
    .status.borrowed { background-color: var(--status-borrowed-solid); }
    .status.returned { background-color: var(--status-returned-solid); }
    .status.overdue, .status.returnedlate { background-color: var(--status-overdue-solid); color: white; }
    .status.damaged { background-color: var(--status-damaged-solid); color: white; }

    /* --- COLUMN STYLING --- */
    /* Ensure only ID/Type is centered for better mobile presentation */
    .form-details {
        text-align: center !important;
        font-weight: 700;
        font-size: 1.1rem;
    }
    .form-details .form-type {
        font-size: 0.85rem;
        color: #6c757d;
        font-weight: 500;
        display: block;
    }
    
    .date-col {
        line-height: 1.6;
    }
    .date-col span {
        display: block;
    }
    .date-col .expected { color: var(--danger-color); font-weight: 600; }
    .date-col .actual { color: var(--status-returned-solid); font-weight: 600; }
    .date-col .borrow { color: #333; font-weight: 500;}
    
    /* --- RESPONSIVE ADJUSTMENTS --- */
    @media (max-width: 992px) {
        .menu-toggle { display: block; }
        .sidebar { left: calc(var(--sidebar-width) * -1); transition: left 0.3s ease; box-shadow: none; --sidebar-width: 250px; }
        .sidebar.active { left: 0; box-shadow: 2px 0 5px rgba(0, 0, 0, 0.2); }
        .main-wrapper { margin-left: 0; padding-left: 15px; padding-right: 15px; }
        .top-header-bar { left: 0; padding-left: 70px; }
        
        /* Hide Remarks column for smaller desktop/tablet */
        .table thead th:nth-child(7), 
        .table tbody td:nth-child(7) { display: none; }
        
        .container { padding: 25px; }
    }
    
    @media (max-width: 768px) {
        /* Mobile View: Card View structure */
        .table-responsive { border: none; box-shadow: none; }
        .table { min-width: 100%; border: none; }
        .table thead { display: none; }
        .table tbody, .table tr, .table td { display: block; width: 100%; }
        .table tr {
            margin-bottom: 15px;
            border: 1px solid #ccc;
            border-left: 5px solid var(--primary-color);
            border-radius: 8px;
            box-shadow: 0 2px 4px rgba(0, 0, 0, 0.05);
            padding-bottom: 1px;
        }
        
        .table td {
            text-align: right !important;
            padding-left: 50% !important;
            position: relative;
            border: none;
            border-bottom: 1px solid #eee;
            padding: 10px 15px !important;
        }
        .table td:last-child { border-bottom: none; }

        /* Mobile Label */
        .table td::before {
            content: attr(data-label);
            position: absolute;
            left: 0;
            width: 50%;
            padding: 10px 15px;
            text-align: left;
            font-weight: 600;
            color: #6c757d;
            font-size: 0.9rem;
            display: flex;
            align-items: center;
        }
        
        /* Mobile Specific Card Formatting */
        .table tbody tr td:nth-child(1) { /* Form ID/Type - Header block */
            background-color: #f8f8f8;
            border-bottom: 1px solid #ddd;
            text-align: left !important;
        }
        .table tbody tr td:nth-child(1)::before {
            content: "Form ID";
            font-weight: 700;
            color: var(--text-dark);
            width: auto;
        }
        .table tbody tr td:nth-child(2) { /* Item Borrowed - Primary row data */
            text-align: left !important;
            font-size: 1.1rem;
            font-weight: 700;
        }
    }
    
    @media (max-width: 576px) {
        .top-header-bar { padding-left: 65px; }
        
        .table tbody tr td:nth-child(1) .form-details {
            flex-direction: column;
            align-items: flex-start;
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
                    <option value="returned" <?= $filter == 'returned' ? 'selected' : '' ?>>Returned (On Time)</option>
                    <option value="returned_late" <?= $filter == 'returned_late' ? 'selected' : '' ?>>Returned (Late)</option>
                    <option value="overdue" <?= $filter == 'overdue' ? 'selected' : '' ?>>Overdue</option>
                    <option value="damaged" <?= $filter == 'damaged' ? 'selected' : '' ?>>Damaged/Lost</option>
                </select>
            </form>
        </div>

        <div class="table-responsive">
            <table class="table table-striped align-middle">
            <thead>
            <tr>
                <th style="width: 10%;">Form ID / Type</th> 
                <th style="width: 25%;">Item Borrowed</th> 
                <th style="width: 15%;">Status</th>
                <th style="width: 15%;">Borrow Date</th>
                <th style="width: 15%;">Expected Return</th>
                <th style="width: 15%;">Actual Return</th>
                <th style="width: 5%;" class="d-none d-md-table-cell">Remarks</th>
            </tr>
            </thead>
            <tbody>
            <?php
            $hasData = false;
            foreach ($detailedItemRows as $row):
                $hasData = true;
                
                $row_class = '';
                if (in_array($row['item_status_class'], ['overdue', 'returnedlate', 'damaged'])) {
                    $row_class = 'status-danger-row';
                }
                if ($row['is_first_item']) {
                    $row_class .= ' first-item-of-group';
                }
            ?>
                <tr class="<?= $row_class ?>">
                    
                    <td data-label="Form ID / Type:" class="form-details-cell">
                        <div class="form-details">
                            <span class="form-id"><?= htmlspecialchars($row["form_id"]) ?></span>
                            <span class="form-type"><?= htmlspecialchars($row["form_type"]) ?></span>
                        </div>
                    </td>

                    <td data-label="Item Borrowed:" class="item-borrowed">
                        <?= $row['item_name'] ?> (x<?= $row['item_quantity'] ?>)
                    </td>

                    <td data-label="Status:">
                        <span class="status <?= $row['item_status_class'] ?>">
                            <?= $row['item_status_display'] ?>
                        </span>
                    </td>
                    
                    <td data-label="Borrow Date:" class="date-col">
                        <span class="borrow"><?= htmlspecialchars($row["borrow_date"]) ?></span>
                    </td>
                    
                    <td data-label="Expected Return:" class="date-col">
                        <span class="expected"><?= htmlspecialchars($row["expected_return_date"]) ?></span>
                    </td>
                    
                    <td data-label="Actual Return:" class="date-col">
                        <span class="actual"><?= htmlspecialchars($row["actual_return_date"]) ?></span>
                    </td>
                    
                    <td class="remarks-col d-none d-md-table-cell" data-label="Staff Remarks:">
                        <?= htmlspecialchars($row["staff_remarks"]) ?>
                    </td>
                </tr>
            <?php endforeach; ?>

            <?php if (!$hasData): ?>
                <tr><td colspan="7" class="text-center text-muted py-3">No transactions found for the selected filter.</td></tr>
            <?php endif; ?>
            </tbody>
            </table>
        </div>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
<script>
    // --- DROPDOWN NOTIFICATION LOGIC ---

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
            
            // Detach the 'View All' link to re-append it at the end
            const $viewAllLink = $dropdown.find('a[href="student_transaction.php"]').detach(); 
            
            // 1. Clear previous dynamic items
            $dropdown.find('.mark-all-btn-wrapper').remove(); 
            
            // 2. Update the Badge Count
            $badge.text(unreadCount);
            $badge.toggle(unreadCount > 0); 

            // 3. Populate the Dropdown Menu
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

        // 3. Mobile Toggle Logic
        const menuToggle = document.getElementById('menuToggle');
        const sidebar = document.querySelector('.sidebar');
        const mainWrapper = document.querySelector('.main-wrapper');
        const topHeaderBar = document.querySelector('.top-header-bar');

        if (menuToggle && sidebar) {
            menuToggle.addEventListener('click', () => {
                sidebar.classList.toggle('active');
                if (window.innerWidth <= 992) {
                    if (sidebar.classList.contains('active')) {
                        document.body.style.overflow = 'hidden'; 
                        mainWrapper.addEventListener('click', closeSidebarOnce);
                        topHeaderBar.addEventListener('click', closeSidebarOnce);
                    } else {
                        document.body.style.overflow = 'auto'; 
                        mainWrapper.removeEventListener('click', closeSidebarOnce);
                        topHeaderBar.removeEventListener('click', closeSidebarOnce);
                    }
                }
            });
            
            function closeSidebarOnce() {
                 sidebar.classList.remove('active');
                 document.body.style.overflow = 'auto'; 
                 mainWrapper.removeEventListener('click', closeSidebarOnce);
                 topHeaderBar.removeEventListener('click', closeSidebarOnce);
            }
            
            const navLinks = sidebar.querySelectorAll('.nav-link');
            navLinks.forEach(link => {
                 link.addEventListener('click', () => {
                     if (window.innerWidth <= 992) {
                         setTimeout(() => {
                             sidebar.classList.remove('active');
                             document.body.style.overflow = 'auto';
                        }, 100);
                     }
                 });
            });
        }
    });
</script>
</body>
</html>