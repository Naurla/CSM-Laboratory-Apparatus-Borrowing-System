<?php
session_start();
// Include the Transaction class (now BCNF-compliant)
require_once "../classes/Transaction.php";

if (!isset($_SESSION['user']) || $_SESSION['user']['role'] !== 'staff') {
    header("Location: ../pages/login.php");
    exit;
}

$transaction = new Transaction();

// --- CRITICAL FIX 1: Read the desired filter and search term ---
$filter = $_GET['filter'] ?? 'all';
$search = $_GET['search'] ?? '';

// 1. Data Retrieval (Assuming getAllFormsFiltered accepts these parameters)
$transactions = $transaction->getAllFormsFiltered($filter, $search);

?><!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Transactions - Staff</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0-beta3/css/all.min.css" rel="stylesheet">
    <script src="https://code.jquery.com/jquery-3.6.0.min.js"></script> 
    <style>
        
        :root {
            --msu-red: #A40404;
            --msu-red-dark: #820303;
            --msu-blue: #007bff;
            --sidebar-width: 280px; 
            --header-height: 60px;
            --student-logout-red: #C62828;
            --base-font-size: 15px; 
            --main-text: #333;
            --label-bg: #e9ecef;

            /* Standardized Status Colors */
            --status-pending-bg: #ffc10730;
            --status-pending-color: #b8860b;
            --status-borrowed-bg: #cce5ff;
            --status-borrowed-color: #004085;
            --status-overdue-bg: #f8d7da;
            --status-overdue-color: #721c24;
            --status-rejected-bg: #6c757d30;
            --status-rejected-color: #6c757d;
        }

        body { 
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif; 
            background: #f5f6fa;
            min-height: 100vh;
            display: flex; 
            padding: 0;
            margin: 0;
            font-size: var(--base-font-size);
            overflow-x: hidden;
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
        
        /* --- Top Header Bar Styles --- */
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
            padding: 0 30px; 
            z-index: 1000;
        }
        .notification-bell-container {
            position: relative;
            list-style: none; 
            padding: 0;
            margin: 0;
        }
        .notification-bell-container .nav-link {
            padding: 0.5rem 0.5rem;
            color: var(--main-text);
        }
        .notification-bell-container .badge-counter {
            position: absolute;
            top: 5px; 
            right: 0px;
            font-size: 0.8em; 
            padding: 0.35em 0.5em;
            background-color: #ffc107; 
            color: var(--main-text);
            font-weight: bold;
        }
        .dropdown-menu {
            min-width: 300px;
            padding: 0;
        }
        .dropdown-item {
            padding: 10px 15px;
            white-space: normal;
            transition: background-color 0.1s;
        }
        .dropdown-item:hover {
            background-color: #f5f5f5;
        }
        .mark-all-link {
            cursor: pointer;
            color: var(--main-text); 
            font-weight: 600;
            padding: 8px 15px;
            display: block;
            text-align: center;
            border-top: 1px solid #eee;
            border-bottom: 1px solid #eee;
        }
        /* --- END Top Header Bar Styles --- */
        
        
        .sidebar {
            width: var(--sidebar-width);
            min-width: var(--sidebar-width);
            height: 100vh;
            background-color: var(--msu-red);
            color: white;
            padding: 0;
            position: fixed; 
            top: 0;
            left: 0;
            display: flex;
            flex-direction: column;
            box-shadow: 2px 0 5px rgba(0, 0, 0, 0.2);
            z-index: 1010;
        }

        .sidebar-header {
            text-align: center;
            padding: 25px 15px; 
            font-size: 1.3rem;
            font-weight: 700;
            line-height: 1.2;
            color: #fff;
            border-bottom: 1px solid rgba(255, 255, 255, 0.4);
            margin-bottom: 25px; 
        }

        .sidebar-header img { 
            max-width: 100px; 
            height: auto; 
            margin-bottom: 15px; 
        }
        .sidebar-header .title { 
            font-size: 1.4rem; 
            line-height: 1.1; 
        }
        .sidebar-nav { flex-grow: 1; }
        .sidebar-nav .nav-link { 
            color: white; 
            padding: 18px 25px; 
            font-size: 1.05rem; 
            font-weight: 600; 
            transition: background-color 0.2s; 
        }
        .sidebar-nav .nav-link:hover { background-color: var(--msu-red-dark); }
        .sidebar-nav .nav-link.active { background-color: var(--msu-red-dark); }
        
        /* --- FINAL LOGOUT FIX --- */
        .logout-link {
            margin-top: auto; 
            padding: 0; 
            border-top: 1px solid rgba(255, 255, 255, 0.1); 
            width: 100%; 
            background-color: var(--msu-red); 
        }
        .logout-link .nav-link { 
            display: flex; 
            align-items: center;
            justify-content: flex-start; 
            background-color: var(--student-logout-red) !important;
            color: white !important;
            padding: 18px 25px; 
            border-radius: 0; 
            text-decoration: none;
            font-weight: 600; 
            font-size: 1.05rem; 
            transition: background 0.3s;
        }
        .logout-link .nav-link:hover {
            background-color: var(--msu-red-dark) !important;
        }
        /* --- END FINAL LOGOUT FIX --- */

        .main-content {
            margin-left: var(--sidebar-width); 
            flex-grow: 1;
            padding: 30px;
            /* CRITICAL: Adjusted for fixed header */
            padding-top: calc(var(--header-height) + 30px); 
            width: calc(100% - var(--sidebar-width)); 
        }
        .content-area {
            background: #fff; 
            border-radius: 12px; 
            padding: 30px; 
            box-shadow: 0 5px 15px rgba(0, 0, 0, 0.1);
        }

        
        .page-header {
            color: #333; 
            border-bottom: 2px solid var(--msu-red);
            padding-bottom: 15px; 
            margin-bottom: 30px; 
            font-weight: 600;
            font-size: 2rem; 
        }
        
        
        .table-responsive {
            border-radius: 8px;
            /* CRITICAL: Allows table to scroll horizontally if necessary */
            overflow-x: auto; 
            box-shadow: 0 2px 10px rgba(0, 0, 0, 0.05);
            margin-top: 25px; 
        }
        
        /* Ensure the table is wide enough to contain content and trigger scroll */
        .table {
             min-width: 1350px;
             border-collapse: separate; 
        }
        
        .table thead th {
            background: var(--msu-red);
            color: white;
            font-weight: 700;
            vertical-align: middle;
            text-align: center;
            font-size: 0.95rem; 
            padding: 10px 5px; 
            white-space: nowrap;
        }
        
        /* IMPORTANT: Apply a vertical-align to the item column for better presentation */
        .table tbody td {
            vertical-align: top; /* Changed from middle to top */
            font-size: 0.95rem; 
            padding: 8px 4px; 
            text-align: center;
            border-bottom: 1px solid #e9ecef; /* Default separator for every row */
        }
        
        
        /* --- ITEM-SPECIFIC CELL STYLE (NEW) --- */
        td.item-cell {
            text-align: left !important;
            padding: 8px 10px !important; 
        }

        /* --- VISUAL SEPARATION ENHANCEMENT (MODIFIED FOR NO ROWSPAN) --- */
        /* Target the FIRST item row of each form to apply a strong top border */
        .table tbody tr.item-row.first-item-of-group td {
            border-top: 2px solid #ccc; /* Strong border for group start */
        }

        /* Adjust the very first row of the entire table */
        .table tbody tr:first-child.item-row.first-item-of-group td {
             border-top: 0; /* Remove top border on the very first row of the table */
        }
        
        /* Define column widths for better layout balance */
        .table th:nth-child(1) { width: 5%; } /* Form ID */
        .table th:nth-child(2) { width: 15%; } /* Student Details */
        .table th:nth-child(3) { width: 7%; } /* Type */
        .table th:nth-child(4) { width: 10%; } /* Status */
        .table th:nth-child(5) { width: 8%; } /* Borrow Date */
        .table th:nth-child(6) { width: 10%; } /* Expected Return */
        .table th:nth-child(7) { width: 10%; } /* Actual Return */
        .table th:nth-child(8) { width: 15%; } /* Apparatus (Item & Status) */
        .table th:nth-child(9) { width: 20%; } /* Staff Remarks */

        
        /* --- STATUS TAGS & COLORS (PROFESSIONAL FIX) --- */
        .status-tag {
            display: inline-block;
            padding: 4px 8px; 
            border-radius: 4px;
            font-weight: 700;
            text-transform: uppercase;
            font-size: 0.8rem; 
            line-height: 1;
            white-space: nowrap;
            border: 1px solid transparent;
        }

        /* Dedicated status styles for item status table */
        .status-tag.returned { 
             background-color: #e9ecef; 
             color: #333; 
             border-color: #ddd; 
             font-weight: 600;
        }
        .status-tag.damaged { 
             background-color: #dc3545 !important;
             color: white !important; 
             font-weight: 800; 
        }
        
        /* Standard Colors (Lighter background, dark text for better contrast) */
        .status-tag.waiting_for_approval, .status-tag.pending, .status-tag.reserved { background-color: var(--status-pending-bg); color: var(--status-pending-color); border-color: #ffeeba; }
        .status-tag.approved, .status-tag.borrowed, .status-tag.checking { background-color: var(--status-borrowed-bg); color: var(--status-borrowed-color); border-color: #b8daff; }
        .status-tag.rejected { background-color: var(--status-rejected-bg); color: var(--status-rejected-color); border-color: #ccc; }
        .status-tag.overdue, .status-tag.returned-late { background-color: var(--status-overdue-bg); color: var(--status-overdue-color); border-color: #f5c6cb; }
        
        
        /* --- RESPONSIVE CSS --- */
        
        @media (max-width: 992px) {
            /* Enable mobile toggle and shift main content */
            .menu-toggle { display: block; }
            .sidebar { left: calc(var(--sidebar-width) * -1); transition: left 0.3s ease; box-shadow: none; --sidebar-width: 250px; } 
            .sidebar.active { left: 0; box-shadow: 2px 0 5px rgba(0, 0, 0, 0.2); }
            .main-content { margin-left: 0; padding-left: 15px; padding-right: 15px; padding-top: calc(var(--header-height) + 15px); }
            .top-header-bar { left: 0; padding-left: 70px; padding-right: 15px; }
            .content-area { padding: 20px 15px; }
            .page-header { font-size: 1.8rem; }
            
            /* Filter/Search stacking below 992px */
            #transactionFilterForm .d-flex { flex-direction: column; align-items: stretch !important; }
            #transactionFilterForm .d-flex > * { width: 100%; margin-bottom: 10px; }
            #transactionFilterForm .form-select-sm { width: 100% !important; }
        }

        @media (max-width: 768px) {
             /* When using the no-rowspan method, standard mobile stacking works better */
             .table { min-width: auto; }
             .table thead { display: none; }
             .table, .table tbody, .table tr, .table td { display: block; width: 100%; }
             
             .table tbody tr {
                 border: 1px solid #ddd;
                 margin-bottom: 15px;
                 border-radius: 8px;
                 box-shadow: 0 2px 5px rgba(0,0,0,0.05);
                 background-color: white !important;
             }
             
             .table tbody tr.item-row.first-item-of-group td {
                 border-top: 1px solid #ddd !important; /* Reset from desktop style */
             }

             .table td {
                 text-align: right !important;
                 padding-left: 50% !important;
                 position: relative;
                 border: none !important;
                 border-bottom: 1px dotted #eee !important;
                 white-space: normal;
             }
             
             /* Remove bottom border on the last cell of each mobile row to clean up spacing */
             .table tbody tr td:last-child {
                 border-bottom: none !important;
             }
             
             .table td::before {
                 content: attr(data-label);
                 position: absolute;
                 left: 10px;
                 width: 45%;
                 padding-right: 10px;
                 white-space: nowrap;
                 font-weight: 600;
                 text-align: left;
                 color: var(--main-text);
                 background-color: transparent;
             }
             
             .table tbody tr:first-child.item-row.first-item-of-group {
                 margin-top: 0; 
             }
             
             .table tbody tr.item-row.first-item-of-group {
                 /* Separate visual form groups with space in mobile view */
                 margin-top: 20px; 
             }

             /* Special Mobile Styling for key cells (Form ID / Student Details) */
             .table tbody tr td:nth-child(1) { /* Form ID - Use full width label, no separator on its own line */
                 font-size: 1rem;
                 font-weight: 700;
                 text-align: left !important;
                 color: var(--msu-red-dark);
                 border-bottom: 1px solid #ddd !important;
             }
             .table tbody tr td:nth-child(1)::before {
                 content: "Form "; 
                 position: static;
                 display: inline;
                 color: #6c757d;
                 font-size: 0.9rem;
                 font-weight: 600;
             }
             .table tbody tr td:nth-child(2) { /* Student Details */
                 font-size: 1.05rem;
                 font-weight: 700;
                 color: var(--main-text);
                 border-bottom: 2px solid var(--msu-red) !important;
             }
             .table tbody tr td:nth-child(2)::before {
                 font-weight: 700;
                 color: var(--msu-red-dark);
                 background-color: #f8d7da; /* Light red background */
                 padding-left: 0;
                 left: 0;
                 width: 50%;
                 text-align: center;
                 display: flex;
                 align-items: center;
                 justify-content: center;
             }
        }

        @media (max-width: 576px) {
             .main-content { padding: 10px; padding-top: calc(var(--header-height) + 10px); }
             .content-area { padding: 10px; }
             .top-header-bar { padding-left: 65px; }
        }
    </style>
</head>
<body>

<button class="menu-toggle" id="menuToggle" aria-label="Toggle navigation menu">
    <i class="fas fa-bars"></i>
</button>

<div class="sidebar">
    <div class="sidebar-header">
        <img src="../wmsu_logo/wmsu.png" alt="WMSU Logo" class="img-fluid"> 
        <div class="title">
            CSM LABORATORY <br>APPARATUS BORROWING
        </div>
    </div>
    
    <div class="sidebar-nav nav flex-column">
        <a class="nav-link" href="staff_dashboard.php">
            <i class="fas fa-chart-line fa-fw me-2"></i>Dashboard
        </a>
        <a class="nav-link" href="staff_apparatus.php">
            <i class="fas fa-vials fa-fw me-2"></i>Apparatus List
        </a>
        <a class="nav-link" href="staff_pending.php">
            <i class="fas fa-hourglass-half fa-fw me-2"></i>Pending Approvals
        </a>
        <a class="nav-link active" href="staff_transaction.php">
            <i class="fas fa-list-alt fa-fw me-2"></i>All Transactions
        </a>
        <a class="nav-link" href="staff_report.php">
            <i class="fas fa-print fa-fw me-2"></i>Generate Reports
        </a>
    </div>
    
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
                <span class="badge rounded-pill badge-counter" id="notification-bell-badge" style="display:none;"></span>
            </a>
            <div class="dropdown-menu dropdown-menu-end shadow animated--grow-in" 
                aria-labelledby="alertsDropdown" id="notification-dropdown">
                
                <h6 class="dropdown-header text-center">New Requests</h6>
                
                <div class="dynamic-content-area">
                    <a class="dropdown-item text-center small text-muted dynamic-notif-item">Fetching notifications...</a>
                </div>
                
                <a class="dropdown-item text-center small text-muted" href="staff_pending.php">View All Pending Requests</a>
            </div>
        </li>
    </ul>
    </header>
<div class="main-content">
    <div class="content-area">

        <h2 class="page-header">
            <i class="fas fa-list-alt fa-fw me-2 text-secondary"></i> All Transactions History
        </h2>

        <form method="GET" class="mb-4" id="transactionFilterForm">
            <div class="d-flex flex-wrap align-items-center justify-content-between">
                
                <div class="d-flex align-items-center mb-2 mb-md-0 me-md-3">
                    <label for="statusFilter" class="form-label me-2 mb-0 fw-bold text-secondary text-nowrap">Filter by Status:</label>
                    <select name="filter" id="statusFilter" class="form-select form-select-sm w-auto">
                        <option value="all" <?= $filter === 'all' ? 'selected' : '' ?>>All</option>
                        <option value="waiting_for_approval" <?= $filter === 'waiting_for_approval' ? 'selected' : '' ?>>Waiting for Approval</option>
                        <option value="borrowed" <?= $filter === 'borrowed' ? 'selected' : '' ?>>Borrowed (Approved)</option>
                        <option value="reserved" <?= $filter === 'reserved' ? 'selected' : '' ?>>Reserved</option>
                        <option value="returned" <?= $filter === 'returned' ? 'selected' : '' ?>>Returned (Completed)</option>
                        <option value="overdue" <?= $filter === 'overdue' ? 'selected' : '' ?>>Overdue</option>
                        <option value="rejected" <?= $filter === 'rejected' ? 'selected' : '' ?>>Rejected</option>
                        <option value="damaged" <?= $filter === 'damaged' ? 'selected' : '' ?>>Damaged Unit</option>
                    </select>
                </div>

                <div class="d-flex align-items-center">
                    <input type="text" name="search" class="form-control form-control-sm me-2" placeholder="Search student/apparatus..." value="<?= htmlspecialchars($search) ?>">
                    <button type="submit" class="btn btn-sm btn-outline-secondary">
                        <i class="fas fa-search"></i>
                    </button>
                </div>

            </div>
        </form>

        <div class="table-responsive">
            <table class="table table-striped table-hover align-middle">
                <thead>
                    <tr>
                        <th>Form ID</th>
                        <th>Student Details</th> <th>Type</th>
                        <th>Item Status</th>
                        <th>Borrow Date</th>
                        <th>Expected Return</th>
                        <th>Actual Return</th>
                        <th>Apparatus (Name & Unit)</th> <th>Staff Remarks</th>
                    </tr>
                </thead>
                <tbody>
                    <?php 
                        $previous_form_id = null;
                        if (!empty($transactions)): 
                            
                            $transactions_with_items = []; 

                            // First pass: Prepare data for display
                            foreach ($transactions as $trans) {
                                $form_id = $trans['id'];
                                // IMPORTANT: Assuming getFormItems returns an array of individual item units, 
                                // including item_status and is_late_return flags.
                                $detailed_items = $transaction->getFormItems($form_id); 
                                
                                // If the form has no items (e.g., rejected early), ensure we loop once
                                if (empty($detailed_items)) {
                                    $detailed_items = [null]; 
                                }
                                
                                $transactions_with_items[$form_id] = [
                                    'form' => $trans,
                                    'items' => $detailed_items
                                ];
                            }

                            // Second pass: Output rows, one per item
                            foreach ($transactions_with_items as $form_id => $data):
                                $trans = $data['form'];
                                $detailed_items = $data['items'];

                                // Determine the main form status/class 
                                $form_status = strtolower($trans['status']);

                                // Loop through the items for this single form
                                foreach ($detailed_items as $index => $unit):
                                    
                                    // Item status logic: falls back to form status if item status is missing
                                    $name = htmlspecialchars($unit['name'] ?? 'N/A');
                                    $unit_tag = (isset($unit['unit_id'])) ? ' (Unit ' . htmlspecialchars($unit['unit_id']) . ')' : '';
                                    
                                    // CRITICAL: Use Item Status for the Status column
                                    $item_status = strtolower($unit['item_status'] ?? $form_status);
                                    
                                    $item_tag_class = $item_status;
                                    $item_tag_text = ucfirst(str_replace('_', ' ', $item_status));
                                    
                                    if ($item_status === 'returned' && (isset($unit['is_late_return']) && $unit['is_late_return'] == 1)) {
                                         $item_tag_class = 'returned-late';
                                         $item_tag_text = 'Returned (Late)';
                                    } elseif ($item_status === 'damaged') {
                                         $item_tag_class = 'damaged';
                                         $item_tag_text = 'Damaged';
                                    } elseif ($item_status === 'overdue') {
                                         $item_tag_class = 'overdue';
                                         $item_tag_text = 'Overdue';
                                    }
                                    
                                    // Add a visual class if this is the first item of a new form group
                                    $row_classes = 'item-row';
                                    if ($previous_form_id !== $form_id) {
                                         $row_classes .= ' first-item-of-group';
                                         $previous_form_id = $form_id; // Update tracker
                                    }

                                 ?>
                                 <tr class="<?= $row_classes ?>">
                                     <td data-label="Form ID:"><?= $trans['id'] ?></td>
                                     <td data-label="Student Details:">
                                         <strong><?= htmlspecialchars($trans['firstname'] ?? '') ?> <?= htmlspecialchars($trans['lastname'] ?? '') ?></strong>
                                         <br>
                                         <small class="text-muted">(ID: <?= htmlspecialchars($trans['user_id']) ?>)</small>
                                     </td>
                                     <td data-label="Type:"><?= ucfirst($trans['form_type']) ?></td>
                                     
                                     <td data-label="Status:">
                                         <span class="status-tag <?= $item_tag_class ?>">
                                             <?= $item_tag_text ?>
                                         </span>
                                     </td>
                                     
                                     <td data-label="Borrow Date:"><?= $trans['borrow_date'] ?: '-' ?></td>
                                     <td data-label="Expected Return:"><?= $trans['expected_return_date'] ?: '-' ?></td>
                                     <td data-label="Actual Return:"><?= $trans['actual_return_date'] ?: '-' ?></td>
                                     
                                     <td data-label="Apparatus (Item):" class="item-cell">
                                         <div class="p-0">
                                             <span><?= $name ?> (x<?= $unit['quantity'] ?? 1 ?>)<?= $unit_tag ?></span>
                                         </div>
                                     </td>
                                     
                                     <td data-label="Staff Remarks:"><?= htmlspecialchars($trans['staff_remarks'] ?? '-') ?></td>
                                 </tr>
                                 <?php 
                                         endforeach; // End item loop
                                     endforeach; // End form loop 
                                 ?>
                    <?php else: ?>
                        <tr><td colspan="9" class="text-muted py-3">No transactions found matching the selected filter or search term.</td></tr>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
<script>
    // --- JAVASCRIPT FOR STAFF NOTIFICATION LOGIC ---
    // Function to handle clicking a notification link
    window.handleNotificationClick = function(event, element, notificationId) {
        event.preventDefault(); 
        const linkHref = element.getAttribute('href');

        $.post('../api/mark_notification_as_read.php', { notification_id: notificationId, role: 'staff' }, function(response) {
            if (response.success) {
                window.location.href = linkHref;
            } else {
                console.error("Failed to mark notification as read.");
                window.location.href = linkHref; 
            }
        }).fail(function() {
            console.error("API call failed.");
            window.location.href = linkHref;
        });
    };

    // Function to mark ALL staff notifications as read
    window.markAllStaffAsRead = function() {
        $.post('../api/mark_notification_as_read.php', { mark_all: true, role: 'staff' }, function(response) {
            if (response.success) {
                window.location.reload(); 
            } else {
                alert("Failed to clear all notifications.");
                console.error("Failed to mark all staff notifications as read.");
            }
        }).fail(function() {
            console.error("API call failed.");
        });
    };
    
    // Function to fetch the count and populate the dropdown
    function fetchStaffNotifications() {
        const apiPath = '../api/get_notifications.php'; 

        $.getJSON(apiPath, function(response) { 
            
            const unreadCount = response.count; 
            const notifications = response.alerts || []; 
            
            const $badge = $('#notification-bell-badge');
            const $dropdown = $('#notification-dropdown');
            const $header = $dropdown.find('.dropdown-header');
            
            // Find and temporarily detach the static View All link
            const $viewAllLink = $dropdown.find('a[href="staff_pending.php"]').detach();
            
            // Clear previous dynamic content
            $dropdown.children('.dynamic-notif-item').remove();
            $dropdown.children('.mark-all-btn-wrapper').remove(); 
            
            // Update badge display
            $badge.text(unreadCount);
            $badge.toggle(unreadCount > 0); 
            
            
            // Clear the obsolete placeholder content
            $dropdown.find('.dynamic-content-area').remove();

            // Create a new area for dynamic content (notifications and mark-all)
            const $dynamicArea = $('<div>').addClass('dynamic-content-area');
            let contentToInsert = [];
            
            if (notifications.length > 0) {
                
                // 1. Mark All button (Must be inserted before notifications)
                if (unreadCount > 0) {
                     contentToInsert.push(`
                            <a class="dropdown-item text-center small text-muted dynamic-notif-item mark-all-btn-wrapper" href="#" onclick="event.preventDefault(); window.markAllStaffAsRead();">
                                <i class="fas fa-check-double me-1"></i> Mark All ${unreadCount} as Read
                            </a>
                        `);
                }
                
                // 2. Individual Notifications
                notifications.slice(0, 5).forEach(notif => {
                    
                    let iconClass = 'fas fa-info-circle text-info'; 
                    if (notif.type.includes('form_pending')) {
                            iconClass = 'fas fa-hourglass-half text-warning';
                    } else if (notif.type.includes('checking')) {
                            iconClass = 'fas fa-redo text-primary';
                    }
                    
                    const itemClass = notif.is_read == 0 ? 'fw-bold' : 'text-muted';

                    contentToInsert.push(`
                        <a class="dropdown-item d-flex align-items-center dynamic-notif-item" 
                            href="${notif.link}"
                            data-id="${notif.id}"
                            onclick="handleNotificationClick(event, this, ${notif.id})">
                            <div class="me-3"><i class="${iconClass} fa-fw"></i></div>
                            <div>
                                <div class="small text-gray-500">${notif.created_at.split(' ')[0]}</div>
                                <span class="${itemClass}">${notif.message}</span>
                            </div>
                        </a>
                    `);
                });
                
            } else {
                // Display a "No Alerts" message
                contentToInsert.push(`
                    <a class="dropdown-item text-center small text-muted dynamic-notif-item">No New Notifications</a>
                `);
            }
            
            // 3. Insert the entire dynamic content block after the header
            $dynamicArea.html(contentToInsert.join(''));
            $header.after($dynamicArea);
            
            // 4. Re-append the 'View All' link to the end of the dropdown
            $dropdown.append($viewAllLink);
            

        }).fail(function(jqXHR, textStatus, errorThrown) {
            console.error("Error fetching staff notifications:", textStatus, errorThrown);
            $('#notification-bell-badge').text('0').hide();
        });
    }
    // --- END JAVASCRIPT FOR STAFF NOTIFICATION LOGIC ---


    // Script to ensure the correct link remains active
    document.addEventListener('DOMContentLoaded', () => {
        // --- Sidebar Activation ---
        const path = window.location.pathname.split('/').pop() || 'staff_dashboard.php';
        const links = document.querySelectorAll('.sidebar .nav-link');
        
        links.forEach(link => {
            const linkPath = link.getAttribute('href').split('/').pop();
            
            if (linkPath === path) {
                link.classList.add('active');
            } else {
                 link.classList.remove('active');
            }
        });
        
        // --- Mobile Toggle Logic (Simplified for brevity as it's repetitive) ---
        const menuToggle = document.getElementById('menuToggle');
        const sidebar = document.querySelector('.sidebar');
        const mainContent = document.querySelector('.main-content'); 

        if (menuToggle && sidebar) {
            menuToggle.addEventListener('click', () => {
                sidebar.classList.toggle('active');
                // Simple backdrop effect on mobile
                if (window.innerWidth <= 992) {
                    const isActive = sidebar.classList.contains('active');
                    if (isActive) {
                        mainContent.style.pointerEvents = 'none';
                        mainContent.style.opacity = '0.5';
                    } else {
                        mainContent.style.pointerEvents = 'auto';
                        mainContent.style.opacity = '1';
                    }
                }
            });
            
            // Hide sidebar if content is clicked (mobile view)
            mainContent.addEventListener('click', () => {
                if (sidebar.classList.contains('active') && window.innerWidth <= 992) {
                    sidebar.classList.remove('active');
                    mainContent.style.pointerEvents = 'auto';
                    mainContent.style.opacity = '1';
                }
            });
            
            // Prevent sidebar closing when clicking inside itself
            sidebar.addEventListener('click', (e) => {
                e.stopPropagation();
            });
        }
        
        // --- Filter Submission Logic ---
        const statusFilter = document.getElementById('statusFilter');
        const form = document.getElementById('transactionFilterForm');

        if (statusFilter && form) {
            statusFilter.addEventListener('change', function() {
                // This submits the entire form, ensuring both 'filter' and 'search' inputs are sent via GET
                form.submit();
            });
        }
        
        // --- Notification Initialization ---
        fetchStaffNotifications();
        setInterval(fetchStaffNotifications, 30000); 
    });
</script>
</body>
</html>