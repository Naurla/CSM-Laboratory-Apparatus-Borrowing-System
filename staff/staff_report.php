<?php
session_start();
// Include the Transaction class (now BCNF-compliant)
require_once "../classes/Transaction.php";

if (!isset($_SESSION['user']) || $_SESSION['user']['role'] !== 'staff') {
    header("Location: ../pages/login.php");
    exit;
}

$transaction = new Transaction();

// --- Determine Mode and Report Type ---
$mode = $_GET['mode'] ?? 'hub'; 
$report_view_type = $_GET['report_view_type'] ?? 'all'; 

// --- Helper Functions (Retained) ---
function isOverdue($expected_return_date) {
    if (!$expected_return_date) return false;
    $expected_date = new DateTime($expected_return_date);
    $today = new DateTime();
    return $expected_date->format('Y-m-d') < $today->format('Y-m-d');
}

/**
 * Helper to fetch and format apparatus list for display, including individual item status.
 */
function getFormItemsText($form_id, $transaction) {
    $items = $transaction->getFormItems($form_id); 
    if (empty($items)) return 'N/A';
    $output = '';
    
    // Default Hub View (Showing status tags with color)
    foreach ($items as $item) {
        $name = htmlspecialchars($item['name'] ?? 'Unknown');
        $item_status = strtolower($item['item_status'] ?? 'pending');
        $quantity = $item['quantity'] ?? 1;

        $tag_class = 'bg-secondary'; 
        $tag_text = ucfirst(str_replace('_', ' ', $item_status));
        
        if ($item_status === 'damaged') {
             $tag_class = 'bg-dark-monochrome'; 
             $tag_text = 'Damaged';
        } elseif ($item_status === 'returned') {
             $tag_class = 'bg-success'; 
             $tag_text = 'Returned';
        } elseif ($item_status === 'overdue') {
             $tag_class = 'bg-danger'; 
             $tag_text = 'Overdue';
        } elseif ($item_status === 'borrowed') {
             $tag_class = 'bg-primary'; 
        }
        
        $output .= '<div class="d-flex align-items-center justify-content-between my-1">';
        $output .= '    <span class="me-2">' . $name . ' (x' . $quantity . ')</span>';
        $output .= '    <span class="badge ' . $tag_class . '">' . $tag_text . '</span>';
        $output .= '</div>';
    }
    return $output;
}


/**
 * Helper to generate status badge for history table. 
 */
function getStatusBadge(array $form) {
    // Hub view: HTML badge output (with color)
    $status = $form['status'];
    $clean_status = strtolower(str_replace(' ', '_', $status));
    $display_status = ucfirst(str_replace('_', ' ', $clean_status));
    
    $color_map = [
        'returned' => 'success', 'approved' => 'info', 'borrowed' => 'primary',
        'overdue' => 'danger', 'damaged' => 'dark-monochrome', 'rejected' => 'secondary',
        'waiting_for_approval' => 'warning'
    ];
    $color = $color_map[$clean_status] ?? 'secondary';
    
    if ($status === 'returned' && isset($form['is_late_return']) && $form['is_late_return'] == 1) {
        $color = 'danger'; 
        $display_status = 'LATE';
    }

    return '<span class="badge bg-' . $color . '">' . $display_status . '</span>';
}


// --- Data Retrieval and Filtering Logic (Retained) ---

$allApparatus = $transaction->getAllApparatus(); 
$allForms = $transaction->getAllForms(); 

$apparatus_filter_id = $_GET['apparatus_id'] ?? '';
$start_date = $_GET['start_date'] ?? '';
$end_date = $_GET['end_date'] ?? '';
$status_filter = $_GET['status_filter'] ?? ''; 
$form_type_filter = $_GET['form_type_filter'] ?? ''; 

$filteredForms = $allForms; 

// Apply Filtering Logic 
if ($start_date) {
    $start_dt = new DateTime($start_date);
    $filteredForms = array_filter($filteredForms, fn($f) => (new DateTime($f['created_at']))->format('Y-m-d') >= $start_dt->format('Y-m-d'));
}
if ($end_date) {
    $end_dt = new DateTime($end_date);
    $filteredForms = array_filter($filteredForms, fn($f) => (new DateTime($f['created_at']))->format('Y-m-d') <= $end_dt->format('Y-m-d'));
}
if ($apparatus_filter_id) {
    $apparatus_filter_id = (string)$apparatus_filter_id;
    $forms_with_apparatus = [];
    foreach ($filteredForms as $form) {
        $items = $transaction->getFormItems($form['id']);
        foreach ($items as $item) {
            if ((string)$item['apparatus_id'] === $apparatus_filter_id) {
                $forms_with_apparatus[] = $form;
                break;
            }
        }
    }
    $filteredForms = $forms_with_apparatus;
}
if ($form_type_filter) {
    $form_type_filter = strtolower($form_type_filter);
    $filteredForms = array_filter($filteredForms, fn($f) => strtolower(trim($f['form_type'])) === $form_type_filter);
}
if ($status_filter) {
    $status_filter = strtolower($status_filter);
    if ($status_filter === 'overdue') {
        $filteredForms = array_filter($filteredForms, fn($f) => ($f['status'] === 'borrowed' || $f['status'] === 'approved') && isOverdue($f['expected_return_date']));
    } elseif ($status_filter === 'late_returns') {
         $filteredForms = array_filter($filteredForms, fn($f) => $f['status'] === 'returned' && ($f['is_late_return'] ?? 0) == 1);
    } elseif ($status_filter === 'returned') { 
         $filteredForms = array_filter($filteredForms, fn($f) => $f['status'] === 'returned' && ($f['is_late_return'] ?? 0) == 0);
    } elseif ($status_filter === 'borrowed_reserved') { 
        $filteredForms = array_filter($filteredForms, fn($f) => $f['status'] !== 'waiting_for_approval' && $f['status'] !== 'rejected');
    } elseif ($status_filter !== 'all') {
        $filteredForms = array_filter($filteredForms, fn($f) => strtolower(str_replace('_', ' ', $f['status'])) === strtolower(str_replace('_', ' ', $status_filter)));
    }
}


// --- Data Assignments for Hub View (Retained) ---

$reportForms = $filteredForms; 

$totalForms = count($allForms);
$pendingForms = count(array_filter($allForms, fn($f) => $f['status'] === 'waiting_for_approval'));
$reservedForms = count(array_filter($allForms, fn($f) => $f['status'] === 'approved'));
$borrowedForms = count(array_filter($allForms, fn($f) => $f['status'] === 'borrowed'));
$returnedForms = count(array_filter($allForms, fn($f) => $f['status'] === 'returned'));
$damagedForms = count(array_filter($allForms, fn($f) => $f['status'] === 'damaged'));

$overdueFormsList = array_filter($allForms, fn($f) => 
    ($f['status'] === 'borrowed' || $f['status'] === 'approved') && isOverdue($f['expected_return_date'])
);
$overdueFormsCount = count($overdueFormsList);

$totalApparatusCount = 0; 
$availableApparatusCount = 0;
$damagedApparatusCount = 0;
$lostApparatusCount = 0;
foreach ($allApparatus as $app) {
    $totalApparatusCount += (int)$app['total_stock'];
    $availableApparatusCount += (int)$app['available_stock'];
    $damagedApparatusCount += (int)$app['damaged_stock'];
    $lostApparatusCount += (int)$app['lost_stock'];
}

?>


<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Staff Reports Hub - WMSU CSM</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0-beta3/css/all.min.css" rel="stylesheet">
    <script src="https://code.jquery.com/jquery-3.6.0.min.js"></script> 
    <style>
        /* --- General and Hub View CSS --- */
        :root {
            --msu-red: #A40404; /* FIXED to consistent staff/student red */
            --msu-red-dark: #820303; /* FIXED to consistent dark red */
            --msu-blue: #007bff;
            --sidebar-width: 280px;
            --header-height: 60px; /* ADDED for top bar */
            --student-logout-red: #C62828; /* FIXED to consistent base red */
            --base-font-size: 15px;
            --main-text: #333; /* ADDED for top bar */
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

        /* --- Top Header Bar Styles (ADDED) --- */
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
            justify-content: flex-end; /* Align content to the right */
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
            color: var(--msu-red);
            font-weight: 600;
            padding: 8px 15px;
            display: block;
            text-align: center;
            border-top: 1px solid #eee;
        }
        /* --- END Top Header Bar Styles --- */
        
        .sidebar {
            width: var(--sidebar-width);
            min-width: var(--sidebar-width);
            height: 100vh;
            background-color: var(--msu-red);
            color: white;
            padding: 0;
            box-shadow: 2px 0 5px rgba(0, 0, 0, 0.2);
            position: fixed; 
            top: 0;
            left: 0;
            display: flex;
            flex-direction: column;
            z-index: 1010;
        }
        .sidebar-header { text-align: center; padding: 25px 15px; font-size: 1.3rem; font-weight: 700; line-height: 1.2; color: #fff; border-bottom: 1px solid rgba(255, 255, 255, 0.4); margin-bottom: 25px; }
        .sidebar-header img { max-width: 100px; height: auto; margin-bottom: 15px; }
        .sidebar-header .title { font-size: 1.4rem; line-height: 1.1; }
        .sidebar-nav { flex-grow: 1; }
        .sidebar-nav .nav-link { color: white; padding: 18px 25px; font-size: 1.05rem; font-weight: 600; transition: background-color 0.2s; border-left: none !important; }
        .sidebar-nav .nav-link:hover { background-color: var(--msu-red-dark); }
        .sidebar-nav .nav-link.active { background-color: var(--msu-red-dark); }
        
        .logout-link { 
            margin-top: auto; 
            border-top: 1px solid rgba(255, 255, 255, 0.1); 
            width: 100%; 
            background-color: var(--msu-red); 
        }
        .logout-link .nav-link { 
            display: flex; 
            align-items: center;
            justify-content: flex-start; 
            background-color: var(--student-logout-red) !important; /* FIXED to consistent base red */
            color: white !important;
            padding: 18px 25px; 
            border-radius: 0; 
            text-decoration: none;
            font-weight: 600; 
            font-size: 1.05rem; 
            transition: background 0.3s; 
        }
        .logout-link .nav-link:hover { 
            background-color: var(--msu-red-dark) !important; /* FIXED to consistent dark hover color */
        }

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
            padding: 30px 40px;
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
        
        /* The rest of the CSS remains unchanged */
        .report-section {
            border: 1px solid #ddd;
            border-radius: 8px;
            padding: 25px; 
            margin-bottom: 35px; 
            background: #fff;
            box-shadow: 0 5px 15px rgba(0, 0, 0, 0.1);
        }
        .report-section h3 {
            color: var(--msu-red);
            padding-bottom: 10px;
            border-bottom: 1px dashed #eee;
            margin-bottom: 25px; 
            font-weight: 600;
            font-size: 1.5rem; 
        }
        
        /* --- Dashboard Stat Card Styling --- */
        .stat-card {
            display: flex;
            align-items: center;
            background: #fff;
            border: 1px solid #e0e0e0;
            border-radius: 8px;
            padding: 15px 20px;
            box-shadow: 0 2px 4px rgba(0, 0, 0, 0.05);
            transition: all 0.2s;
            height: 100%; /* Ensure cards in a row have equal height */
        }
        .stat-card:hover {
            box-shadow: 0 4px 8px rgba(0, 0, 0, 0.1);
        }
        .stat-icon {
            display: flex;
            align-items: center;
            justify-content: center;
            width: 50px;
            height: 50px;
            border-radius: 50%;
            font-size: 1.4rem;
            color: white;
            margin-right: 15px;
            flex-shrink: 0;
        }
        .stat-body {
            flex-grow: 1;
        }
        .stat-value {
            font-size: 1.6rem;
            font-weight: 700;
            line-height: 1.1;
            margin-bottom: 3px;
        }
        .stat-label {
            font-size: 0.9rem;
            color: #6c757d;
            font-weight: 500;
            white-space: nowrap;
            overflow: hidden;
            text-overflow: ellipsis;
        }
        .bg-light-gray {
            background-color: #f9f9f9 !important;
        }
        .border-danger {
            border-left: 5px solid var(--student-logout-red) !important; /* Highlight overdue */
        }
        .report-stat-box { display: none !important; }

        /* --- DETAILED HISTORY STYLES (Screen View) --- */
        
        /* FIX: Remove all borders from the table for the screen view */
        .table-no-border {
            border: none !important; 
            /* Ensure the main div doesn't overflow if the table is too wide, but table-responsive was removed */
            width: 100%; 
        }
        .table-no-border tbody tr, 
        .table-no-border tbody td, 
        .table-no-border thead th {
            border: none !important; 
        }
        /* Ensure striped rows are transparent if borderless */
        .table-no-border.table-striped tbody tr:nth-of-type(odd) {
            background-color: transparent !important; 
        }
        
        /* FIX: Remove outline/border from status badge in screen view */
        .table tbody td .badge {
            border: none !important;
            box-shadow: none !important;
            min-width: 85px; 
            white-space: nowrap;
        }

        /* Normal table headers (for screen view if table-no-border is removed) */
        .table thead th {
            background-color: var(--msu-red);
            color: white;
            font-weight: 700; 
            vertical-align: middle;
            font-size: 1rem; 
            padding: 10px 5px;
            /* Allow wrapping if necessary to fit */
            white-space: normal; 
        }
        
        .table tbody td { 
            vertical-align: top;
            padding-top: 8px; 
            font-size: 1rem; 
        }
        
        .detailed-items-cell {
            white-space: normal !important; 
            word-break: break-word; 
            overflow: visible; 
            text-align: left !important;
            padding-left: 10px !important;
        }
        
        /* Badges for Detailed Items (Screens) */
        .detailed-items-cell .badge { margin-left: 5px; font-size: 0.85rem; font-weight: 700; }
        .detailed-items-cell .d-flex { 
            line-height: 1.4; 
            font-size: 1rem; 
            min-height: 1.5em; 
        }
        
        /* FIX: Adjusted column widths for better fit on screen view */
        .table th:nth-child(1) { width: 4%; } 
        .table th:nth-child(2) { width: 8%; } 
        .table th:nth-child(3) { width: 12%; } 
        .table th:nth-child(4) { width: 6%; } 
        .table th:nth-child(5) { width: 10%; } 
        .table th:nth-child(6), 
        .table th:nth-child(7), 
        .table th:nth-child(8) { width: 9%; font-size: 0.95rem; } /* Slightly smaller date columns */
        .table th:nth-child(9) { width: 33%; } 
        
        /* --- STATUS TAGS & ITEM DETAILS --- (Screens) */
        .badge.bg-dark-monochrome { background-color: #343a40 !important; color: white !important; } 
        .badge.bg-success { background-color: #28a745 !important; } 
        .badge.bg-warning { background-color: #ffc107 !important; color: #343a40 !important; } 
        .badge.bg-danger { background-color: #dc3545 !important; } 
        .badge.bg-secondary { background-color: #6c757d !important; } 
        .badge.bg-primary { background-color: #007bff !important; } 
        .badge.bg-info { background-color: #17a2b8 !important; } 


        /* --- PRINT STYLING (Monochrome & Unified) --- */
        
        .print-header {
            display: none; 
            padding-bottom: 10px;
            margin-bottom: 20px;
            border-bottom: 2px solid #333; 
            text-align: center;
        }
        .wmsu-logo-print {
            display: none;
        }

        @media print {
            /* 1. Page, General Reset, and Hide Elements */
            body, .main-content {
                margin: 0 !important;
                padding: 0 !important;
                background: white !important; 
                width: 100%;
                color: #000;
            }
            .sidebar, .page-header, .filter-form, .print-summary-footer, .top-header-bar { /* HIDDEN TOP BAR */
                display: none !important;
            }
            @page {
                size: A4 portrait; 
                margin: 0.7cm; 
            }
            
            /* Print Header */
            .print-header {
                display: flex !important; 
                flex-direction: column;
                align-items: center;
                text-align: center;
                padding-bottom: 15px;
                margin-bottom: 25px;
                border-bottom: 3px solid #000;
            }
            .wmsu-logo-print {
                display: block !important;
                width: 70px; 
                height: auto;
                margin-bottom: 5px;
            }
            .print-header .logo { 
                font-size: 0.9rem; 
                font-weight: 600; 
                margin-bottom: 2px;
                color: #555;
            }
            .print-header h1 { 
                font-size: 1.5rem; 
                font-weight: 700; 
                margin: 0; 
                color: #000; 
            } 
            .print-header p { 
                font-size: 0.8rem; 
                margin: 0; 
                color: #555; 
            }
            
            /* 2. Unified Report Section Styling */
            .report-section {
                border: none !important;
                box-shadow: none !important;
                padding: 0;
                margin-bottom: 25px; 
            }
            .report-section h3 {
                color: #333 !important; 
                border-bottom: 1px solid #ccc !important;
                padding-bottom: 5px;
                margin-bottom: 15px;
                font-size: 1.4rem; 
                font-weight: 600;
                page-break-after: avoid; 
                text-align: left;
            }

            /* 3. Style Summary and Inventory back to Monochrome Tables for Print */
            /* Hiding the card structure for print */
            .print-summary .row, .print-inventory .row { display: none !important; }
            
            .print-stat-table-container { 
                display: block !important;
                margin-bottom: 30px;
            }
            .print-stat-table {
                width: 100%;
                border-collapse: collapse !important;
                font-size: 0.9rem;
            }
            .print-stat-table th, 
            .print-stat-table td {
                border: 1px solid #000 !important; /* Re-apply borders for print */
                padding: 8px 10px !important;
                vertical-align: middle;
                color: #000;
                font-size: 0.9rem;
                line-height: 1.2;
            }
            .print-stat-table th {
                background-color: #eee !important; 
                font-weight: 700;
                width: 70%; 
            }
            .print-stat-table td {
                text-align: center;
                font-weight: 700;
                width: 30%;
                color: #000 !important; 
            }
            .print-stat-table tr:nth-child(even) td {
                background-color: #f9f9f9 !important;
            }
            
            /* Force monochrome for colors */
            .print-stat-table * {
                -webkit-print-color-adjust: exact;
                print-color-adjust: exact;
            }


            /* 4. Detailed History Table Styles (Monochrome Status Badges) */
            
            body[data-print-view="detailed"] @page {
                size: A4 landscape; 
            }
            
            /* Print Column Widths (Different than screen to ensure landscape fit) */
            .table th:nth-child(1) { width: 3%; } /* Form ID */
            .table th:nth-child(2) { width: 7%; } /* Student ID */
            .table th:nth-child(3) { width: 10%; } /* Borrower Name */
            .table th:nth-child(4) { width: 5%; } /* Type */
            .table th:nth-child(5) { width: 8%; } /* Status */
            .table th:nth-child(6) { width: 9%; } /* Borrow Date */
            .table th:nth-child(7) { width: 9%; } /* Expected Return */
            .table th:nth-child(8) { width: 9%; } /* Actual Return */
            .table th:nth-child(9) { width: 40%; } /* Items Borrowed */

            .table thead th, .table tbody td {
                border: 1px solid #000 !important; /* Re-apply borders for print */
                padding: 6px !important; 
                color: #000 !important;
                vertical-align: top !important; 
                font-size: 0.85rem !important; 
            }

            .table thead th {
                background-color: #eee !important; 
                font-weight: 700 !important;
                white-space: normal; 
            }
            .table tbody tr:nth-child(odd) { background-color: #f9f9f9 !important; }
            .table tbody td.detailed-items-cell { padding-left: 10px !important; }


            /* Hide the individual item status badges in print view */
            .detailed-items-cell .badge { display: none !important; }
            .detailed-items-cell .d-flex { 
                display: block !important;
                margin: 0 !important;
                padding: 0 !important;
                font-size: 0.85rem !important;
                line-height: 1.1;
                border-bottom: 1px dotted #eee; 
            }
            .detailed-items-cell .d-flex:last-child {
                border-bottom: none;
            }

            /* CRITICAL: Status Badge - Set to Monochrome */
            .table tbody td .badge {
                min-width: 90px;
                white-space: nowrap;
                line-height: 1.1;
                font-size: 0.85rem !important;
                color: #000 !important; /* Force text to black */
                background-color: transparent !important; /* Remove background color */
                border: 1px solid #000; /* Add a border to distinguish the box */
                -webkit-print-color-adjust: exact; 
                print-color-adjust: exact; 
                box-shadow: none !important;
            }


            /* 5. Conditional Section Display (Crucial for Print Fix) */
            .print-target { display: none; }

            body[data-print-view="summary"] .print-summary,
            body[data-print-view="inventory"] .print-inventory,
            body[data-print-view="detailed"] .print-detailed,
            body[data-print-view="all"] .print-target {
                display: block !important;
            }
            
            body[data-print-view="summary"] .print-summary .print-stat-table-container,
            body[data-print-view="inventory"] .print-inventory .print-stat-table-container,
            body[data-print-view="all"] .print-summary .print-stat-table-container,
            body[data-print-view="all"] .print-inventory .print-stat-table-container {
                display: block !important;
            }
        }
    </style>
</head>
<body data-print-view="<?= htmlspecialchars($report_view_type) ?>">

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
        <a class="nav-link" href="staff_transaction.php">
            <i class="fas fa-list-alt fa-fw me-2"></i>All Transactions
        </a>
        <a class="nav-link active" href="staff_report.php">
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
                
                <div class="dynamic-notif-placeholder">
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
            <i class="fas fa-print fa-fw me-2 text-secondary"></i> Printable Reports Hub
        </h2>
        
        <div class="print-header">
            <img src="../wmsu_logo/wmsu.png" alt="WMSU Logo" class="wmsu-logo-print">
            <div class="logo">WESTERN MINDANAO STATE UNIVERSITY</div>
            <div class="logo">CSM LABORATORY APPARATUS BORROWING SYSTEM</div>
            <h1>
            <?php 
                if ($report_view_type === 'summary') echo 'Transaction Status Summary Report';
                elseif ($report_view_type === 'inventory') echo 'Apparatus Inventory Stock Report';
                elseif ($report_view_type === 'detailed') echo 'Detailed Transaction History Report';
                else echo 'All Reports Hub View';
            ?>
            </h1>
            <p>Generated by Staff: <?= date('F j, Y, g:i a') ?></p>
        </div>
        <div class="d-flex justify-content-between align-items-center mb-4 print-summary-footer">
            <p class="text-muted mb-0">Report Date: <?= date('F j, Y, g:i a') ?></p>
            <button class="btn btn-lg btn-danger btn-print" id="main-print-button">
                <i class="fas fa-print me-2"></i> Print Selected Report
            </button>
        </div>

        <div class="report-section filter-form mb-4">
            <h3><i class="fas fa-filter me-2"></i> Filter Report Data</h3>
            <form method="GET" action="staff_report.php" class="row g-3 align-items-end" id="report-filter-form">
                
                <div class="col-md-3">
                    <label for="report_view_type_select" class="form-label">**Select Report View Type**</label>
                    <select name="report_view_type" id="report_view_type_select" class="form-select">
                        <option value="all" <?= ($report_view_type === 'all') ? 'selected' : '' ?>>Print: All Sections (Hub View)</option>
                        <option value="summary" <?= ($report_view_type === 'summary') ? 'selected' : '' ?>>Print: Transaction Summary Only</option>
                        <option value="inventory" <?= ($report_view_type === 'inventory') ? 'selected' : '' ?>>Print: Apparatus Inventory Only</option>
                        <option value="detailed" <?= ($report_view_type === 'detailed') ? 'selected' : '' ?>>Filter & Print: Detailed History</option>
                    </select>
                </div>

                <div class="col-md-3">
                    <label for="apparatus_id" class="form-label">Specific Apparatus</label>
                    <select name="apparatus_id" id="apparatus_id" class="form-select">
                        <option value="">-- All Apparatus --</option>
                        <?php foreach ($allApparatus as $app): ?>
                            <option 
                                value="<?= htmlspecialchars($app['id']) ?>"
                                <?= ((string)$apparatus_filter_id === (string)$app['id']) ? 'selected' : '' ?>
                            >
                                <?= htmlspecialchars($app['name']) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-md-3">
                    <label for="start_date" class="form-label">Start Date (Form Created)</label>
                    <input type="date" name="start_date" id="start_date" class="form-control" 
                                     value="<?= htmlspecialchars($start_date) ?>">
                </div>
                <div class="col-md-3">
                    <label for="end_date" class="form-label">End Date (Form Created)</label>
                    <input type="date" name="end_date" id="end_date" class="form-control" 
                                     value="<?= htmlspecialchars($end_date) ?>">
                </div>
                
                <div class="col-md-3 mt-3">
                    <label for="form_type_filter" class="form-label">Filter by Form Type</label>
                    <select name="form_type_filter" id="form_type_filter" class="form-select">
                        <option value="">-- All Form Types --</option>
                        <option value="borrow" <?= (strtolower($form_type_filter) === 'borrow') ? 'selected' : '' ?>>Direct Borrow</option>
                        <option value="reserved" <?= (strtolower($form_type_filter) === 'reserved') ? 'selected' : '' ?>>Reservation Request</option>
                    </select>
                </div>
                
                <div class="col-md-3 mt-3">
                    <label for="status_filter" class="form-label">Filter by Status</label>
                    <select name="status_filter" id="status_filter" class="form-select">
                        <option value="">-- All Statuses --</option>
                        <option value="waiting_for_approval" <?= ($status_filter === 'waiting_for_approval') ? 'selected' : '' ?>>Pending Approval</option>
                        <option value="approved" <?= ($status_filter === 'approved') ? 'selected' : '' ?>>Reserved (Approved)</option>
                        <option value="borrowed" <?= ($status_filter === 'borrowed') ? 'selected' : '' ?>>Currently Borrowed</option>
                        <option value="borrowed_reserved" <?= ($status_filter === 'borrowed_reserved') ? 'selected' : '' ?>>All Completed/Active Forms (Exclude Pending/Rejected)</option>
                        <option value="overdue" <?= ($status_filter === 'overdue') ? 'selected' : '' ?>>** Overdue **</option>
                        <option value="returned" <?= ($status_filter === 'returned') ? 'selected' : '' ?>>Returned (On Time)</option>
                        <option value="late_returns" <?= ($status_filter === 'late_returns') ? 'selected' : '' ?>>Returned (LATE)</option>
                        <option value="damaged" <?= ($status_filter === 'damaged') ? 'selected' : '' ?>>Damaged/Lost</option>
                        <option value="rejected" <?= ($status_filter === 'rejected') ? 'selected' : '' ?>>Rejected</option>
                    </select>
                </div>

                <div class="col-md-6 mt-3 d-flex align-items-end justify-content-end">
                    <button type="submit" class="btn btn-primary me-2">Apply Filters</button>
                    <a href="staff_report.php" class="btn btn-secondary">Clear</a>
                </div>
            </form>
            <p class="text-muted small mt-2 mb-0">Note: Filters apply immediately to the **Detailed Transaction History** table below, and are applied when **Detailed History** is selected for printing.</p>
        </div>
        
        <div class="report-section print-summary print-target" id="report-summary">
            <h3><i class="fas fa-clipboard-list me-2"></i> Transaction Status Summary</h3>
            
            <div class="row g-3">
                <div class="col-xl-3 col-md-4 col-sm-6 col-12">
                    <div class="stat-card bg-light-gray">
                        <div class="stat-icon bg-secondary"><i class="fas fa-file-alt"></i></div>
                        <div class="stat-body">
                            <div class="stat-value text-dark"><?= $totalForms ?></div>
                            <div class="stat-label">Total Forms</div>
                        </div>
                    </div>
                </div>
                <div class="col-xl-3 col-md-4 col-sm-6 col-12">
                    <div class="stat-card bg-light-gray">
                        <div class="stat-icon bg-warning"><i class="fas fa-hourglass-half"></i></div>
                        <div class="stat-body">
                            <div class="stat-value text-warning"><?= $pendingForms ?></div>
                            <div class="stat-label">Pending Approval</div>
                        </div>
                    </div>
                </div>
                <div class="col-xl-3 col-md-4 col-sm-6 col-12">
                    <div class="stat-card bg-light-gray">
                        <div class="stat-icon bg-info"><i class="fas fa-book-reader"></i></div>
                        <div class="stat-body">
                            <div class="stat-value text-info"><?= $reservedForms ?></div>
                            <div class="stat-label">Reserved (Approved)</div>
                        </div>
                    </div>
                </div>
                <div class="col-xl-3 col-md-4 col-sm-6 col-12">
                    <div class="stat-card bg-light-gray">
                        <div class="stat-icon bg-primary"><i class="fas fa-hand-holding"></i></div>
                        <div class="stat-body">
                            <div class="stat-value text-primary"><?= $borrowedForms ?></div>
                            <div class="stat-label">Currently Borrowed</div>
                        </div>
                    </div>
                </div>
                <div class="col-xl-3 col-md-4 col-sm-6 col-12">
                    <div class="stat-card bg-light-gray border-danger">
                        <div class="stat-icon bg-danger"><i class="fas fa-exclamation-triangle"></i></div>
                        <div class="stat-body">
                            <div class="stat-value text-danger"><?= $overdueFormsCount ?></div>
                            <div class="stat-label">Overdue (Active)</div>
                        </div>
                    </div>
                </div>
                <div class="col-xl-3 col-md-4 col-sm-6 col-12">
                    <div class="stat-card bg-light-gray">
                        <div class="stat-icon bg-success"><i class="fas fa-check-circle"></i></div>
                        <div class="stat-body">
                            <div class="stat-value text-success"><?= $returnedForms ?></div>
                            <div class="stat-label">Successfully Returned</div>
                        </div>
                    </div>
                </div>
                <div class="col-xl-3 col-md-4 col-sm-6 col-12">
                    <div class="stat-card bg-light-gray">
                        <div class="stat-icon bg-dark-monochrome"><i class="fas fa-times-circle"></i></div>
                        <div class="stat-body">
                            <div class="stat-value text-dark"><?= $damagedForms ?></div>
                            <div class="stat-label">Damaged/Lost Forms</div>
                        </div>
                    </div>
                </div>
            </div>
            
            <div class="print-stat-table-container" style="display:none;">
                <table class="print-stat-table">
                    <thead>
                        <tr><th>Status Description</th><th>Count</th></tr>
                    </thead>
                    <tbody>
                        <tr><th>Total Forms</th><td class="text-dark"><?= $totalForms ?></td></tr>
                        <tr><th>Pending Approval</th><td class="text-warning"><?= $pendingForms ?></td></tr>
                        <tr><th>Reserved (Approved)</th><td class="text-info"><?= $reservedForms ?></td></tr>
                        <tr><th>Currently Borrowed</th><td class="text-primary"><?= $borrowedForms ?></td></tr>
                        <tr><th>Overdue (Active)</th><td class="text-danger"><?= $overdueFormsCount ?></td></tr>
                        <tr><th>Successfully Returned</th><td class="text-success"><?= $returnedForms ?></td></tr>
                        <tr><th>Damaged/Lost Forms</th><td class="text-danger"><?= $damagedForms ?></td></tr>
                    </tbody>
                </table>
            </div>
            
        </div>
        
        <div class="report-section print-inventory print-target" id="report-inventory">
            <h3><i class="fas fa-flask me-2"></i> Apparatus Inventory Stock Status</h3>
            
            <div class="row g-3">
                <div class="col-md-4 col-12">
                    <div class="stat-card bg-light-gray">
                        <div class="stat-icon bg-secondary"><i class="fas fa-boxes"></i></div>
                        <div class="stat-body">
                            <div class="stat-value text-dark"><?= $totalApparatusCount ?></div>
                            <div class="stat-label">Total Inventory Units</div>
                        </div>
                    </div>
                </div>
                <div class="col-md-4 col-12">
                    <div class="stat-card bg-light-gray">
                        <div class="stat-icon bg-success"><i class="fas fa-box-open"></i></div>
                        <div class="stat-body">
                            <div class="stat-value text-success"><?= $availableApparatusCount ?></div>
                            <div class="stat-label">Units Available for Borrowing</div>
                        </div>
                    </div>
                </div>
                <div class="col-md-4 col-12">
                    <div class="stat-card bg-light-gray">
                        <div class="stat-icon bg-danger"><i class="fas fa-trash-alt"></i></div>
                        <div class="stat-body">
                            <div class="stat-value text-danger"><?= $damagedApparatusCount + $lostApparatusCount ?></div>
                            <div class="stat-label">Units Unavailable (Damaged/Lost)</div>
                        </div>
                    </div>
                </div>
            </div>
            <p class="text-muted small mt-3">*Note: Units marked Unavailable are not available for borrowing until their stock count is adjusted.</p>
            
            <div class="print-stat-table-container" style="display:none;">
                <table class="print-stat-table">
                    <thead>
                        <tr><th>Inventory Metric</th><th>Units</th></tr>
                    </thead>
                    <tbody>
                        <tr><th>Total Inventory Units</th><td class="text-dark"><?= $totalApparatusCount ?></td></tr>
                        <tr><th>Units Available for Borrowing</th><td class="text-success"><?= $availableApparatusCount ?></td></tr>
                        <tr><th>Units Unavailable (Damaged/Lost)</th><td class="text-danger"><?= $damagedApparatusCount + $lostApparatusCount ?></td></tr>
                    </tbody>
                </table>
                <p class="text-muted small mt-3">*Note: Units marked Unavailable are not available for borrowing until their stock count is adjusted.</p>
            </div>
        </div>

        <div class="report-section print-detailed print-target" id="report-detailed-table">
            <h3><i class="fas fa-history me-2"></i> Detailed Transaction History (Filtered: <?= count($reportForms) ?> Forms)</h3>
            <div>
                <table class="table table-striped table-sm align-middle table-no-border">
                    <thead>
                        <tr>
                            <th>Form ID</th>
                            <th>Student ID</th>
                            <th>Borrower Name</th>
                            <th>Type</th>
                            <th>Status</th>
                            <th>Borrow Date</th>
                            <th>Expected Return</th>
                            <th>Actual Return</th>
                            <th>Items Borrowed</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php 
                        if (!empty($reportForms)): 
                            foreach ($reportForms as $form): ?>
                                <tr>
                                    <td><?= htmlspecialchars($form['id']) ?></td>
                                    <td><?= htmlspecialchars($form['user_id']) ?></td>
                                    <td><?= htmlspecialchars($form['firstname'] . ' ' . $form['lastname']) ?></td>
                                    <td><?= htmlspecialchars(ucfirst($form['form_type'])) ?></td>
                                    <td><?= getStatusBadge($form) ?></td> 
                                    <td><?= htmlspecialchars($form['borrow_date'] ?? 'N/A') ?></td>
                                    <td><?= htmlspecialchars($form['expected_return_date'] ?? 'N/A') ?></td>
                                    <td><?= htmlspecialchars($form['actual_return_date'] ?? '-') ?></td> 
                                    <td class="detailed-items-cell text-start"><?= getFormItemsText($form['id'], $transaction) ?></td> 
                                </tr>
                            <?php endforeach; ?>
                        <?php else: ?>
                            <tr><td colspan="9" class="text-muted text-center">No transactions match the current filter criteria.</td></tr>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
        
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
<script>
    // --- JAVASCRIPT FOR STAFF NOTIFICATION LOGIC (COPIED) ---
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
            
            const $viewAllLink = $dropdown.find('a[href="staff_pending.php"]').detach();
            const $header = $dropdown.find('.dropdown-header');
            
            $dropdown.find('.dynamic-notif-item').remove();
            $dropdown.find('.mark-all-btn-wrapper').remove(); 
            
            $badge.text(unreadCount);
            $badge.toggle(unreadCount > 0); 
            
            
            if (notifications.length > 0) {
                if (unreadCount > 0) {
                     $header.after(`
                          <a class="dropdown-item text-center small text-muted dynamic-notif-item mark-all-btn-wrapper" href="#" onclick="event.preventDefault(); window.markAllStaffAsRead();">
                             <i class="fas fa-check-double me-1"></i> Mark All ${unreadCount} as Read
                          </a>
                     `);
                }

                notifications.slice(0, 5).forEach(notif => {
                    
                    let iconClass = 'fas fa-info-circle text-info'; 
                    if (notif.type.includes('form_pending')) {
                         iconClass = 'fas fa-hourglass-half text-warning';
                    } else if (notif.type.includes('checking')) {
                         iconClass = 'fas fa-redo text-primary';
                    }
                    
                    const itemClass = notif.is_read == 0 ? 'fw-bold' : 'text-muted';

                    $header.after(`
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
                $header.after(`
                    <a class="dropdown-item text-center small text-muted dynamic-notif-item">No New Notifications</a>
                `);
            }
            
            $dropdown.append($viewAllLink);
            

        }).fail(function(jqXHR, textStatus, errorThrown) {
            console.error("Error fetching staff notifications:", textStatus, errorThrown);
            $('#notification-bell-badge').text('0').hide();
        });
    }
    // --- END JAVASCRIPT FOR STAFF NOTIFICATION LOGIC ---


    // --- Print Fix Logic (Alternative Method - Recommended for Chrome/Opera) ---
    function handlePrint() {
        const viewType = document.getElementById('report_view_type_select').value;
        
        // 1. Set the print mode immediately (before window.print)
        document.body.setAttribute('data-print-view', viewType);
        
        // 2. Trigger the print dialogue
        window.print();

        // 3. Use setTimeout to defer the cleanup.
        setTimeout(() => {
            document.body.removeAttribute('data-print-view');
        }, 100); 
    }


    document.addEventListener('DOMContentLoaded', () => {
        // --- Sidebar Activation ---
        const reportsLink = document.querySelector('a[href="staff_report.php"]');
        if (reportsLink) {
            // Ensure only the current link is active
            document.querySelectorAll('.sidebar .nav-link').forEach(link => link.classList.remove('active'));
            reportsLink.classList.add('active');
        }
        
        // **CRITICAL FIX:** Update CSS variables to current standard
        document.querySelector(':root').style.setProperty('--msu-red', '#A40404');
        document.querySelector(':root').style.setProperty('--msu-red-dark', '#820303');
        
        // Update Logout Hover to use the new dark red
        document.styleSheets[0].insertRule('.logout-link .nav-link:hover { background-color: var(--msu-red-dark) !important; }', 0);
        
        // Set initial view state
        updateHubView();

        // Attach event listener for dynamic changes in the Hub View
        const select = document.getElementById('report_view_type_select');
        select.addEventListener('change', updateHubView);
        
        // Attach print handler to button
        document.getElementById('main-print-button').addEventListener('click', handlePrint);
        
        // --- Notification Initialization ---
        fetchStaffNotifications();
        setInterval(fetchStaffNotifications, 30000); 
    });
</script>
</body>
</html>