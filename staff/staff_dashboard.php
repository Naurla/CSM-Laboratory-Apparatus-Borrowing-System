<?php
// staff_dashboard.php
session_start();
require_once "../classes/Transaction.php";
require_once "../classes/Database.php";

if (!isset($_SESSION["user"]) || $_SESSION["user"]["role"] !== "staff") {
    header("Location: ../pages/login.php");
    exit;
}

$transaction = new Transaction();

// Get all forms (transactions)
$allForms = $transaction->getAllForms();
$apparatusList = $transaction->getAllApparatus();

// Count summary stats
$totalForms = count($allForms);
$totalApparatus = count($apparatusList); 
$pendingForms = count(array_filter($allForms, fn($f) => $f['status'] === 'waiting_for_approval' || $f['status'] === 'checking'));
$reservedForms = count(array_filter($allForms, fn($f) => $f['status'] === 'approved' || $f['status'] === 'reserved')); 
$borrowedForms = count(array_filter($allForms, fn($f) => $f['status'] === 'borrowed')); 
$returnedForms = count(array_filter($allForms, fn($f) => $f['status'] === 'returned'));
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Staff Dashboard</title>
    
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0-beta3/css/all.min.css" rel="stylesheet">
    
    <script src="https://code.jquery.com/jquery-3.6.0.min.js"></script> 
    
    <style>
        :root {
            --msu-red: #A40404; 
            --msu-red-dark: #820303; 
            --msu-blue: #007bff;
            --sidebar-width: 280px;
            --bg-light: #f5f6fa;
            --header-height: 60px;
            --danger-solid: #dc3545;
            --status-returned-solid: #28a745; 
            --status-overdue-solid: #dc3545;
            --main-text: #333;
        }
        
        body { 
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif; 
            background: #f5f6fa; 
            min-height: 100vh;
            display: flex; 
            padding: 0;
            margin: 0;
            font-size: 1.05rem;
            overflow-x: hidden; /* Prevent horizontal scrollbar on body */
        }
        
        /* NEW CSS for Mobile Toggle and Backdrop */
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
            width: 44px;  
            height: 44px;  
            display: flex;
            justify-content: center;
            align-items: center;
        }
        
        .sidebar-backdrop {
            position: fixed;
            top: 0;
            left: 0;
            right: 0;
            bottom: 0;
            background-color: rgba(0, 0, 0, 0.5);
            z-index: 1000;
            display: none;
            opacity: 0;
            transition: opacity 0.3s ease;
        }
        .sidebar.active ~ .sidebar-backdrop {
            display: block;
            opacity: 1;
        }


        /* --- Top Header Bar Styles --- */
        .top-header-bar {
            position: fixed;
            top: 0;
            left: var(--sidebar-width); /* Default desktop position */
            right: 0;
            height: var(--header-height);
            background-color: #fcfcfc; /* Lighter background for consistency */
            border-bottom: 1px solid #ddd;
            box-shadow: 0 2px 5px rgba(0,0,0,0.05);
            display: flex;
            align-items: center;
            justify-content: flex-end; 
            padding: 0 30px; 
            z-index: 1050; /* Ensure it's above content */
        }
        
        /* Bell badge container style */
        .notification-bell-container {
            position: relative;
            list-style: none; 
            padding: 0;
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
        
        /* --- Sidebar Styles (Fixed for Consistency) --- */
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
            transition: left 0.3s ease; /* Added for mobile transition */
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
        /* FIX: Set consistent size for the logo */
        .sidebar-header img { 
            width: 100px; 
            height: 100px;
            object-fit: contain; 
            margin-bottom: 15px; 
            max-width: 100px;
        }
        .sidebar-header .title { font-size: 1.4rem; line-height: 1.1; }
        
        .sidebar-nav { flex-grow: 1; }

        .sidebar-nav .nav-link {
            color: white;
            padding: 18px 25px; 
            font-size: 1.05rem;
            font-weight: 600;
            transition: background-color 0.2s;
            display: flex;
            align-items: center;
        }
        .sidebar-nav .nav-link:hover { 
            background-color: var(--msu-red-dark); 
        }
        /* REMOVED: Gold active link border for consistency with student side */
        .sidebar-nav .nav-link.active {
            background-color: var(--msu-red-dark);
            /* border-left: 5px solid #ffc107; */ 
            /* padding-left: 25px; // Reset padding as border is gone */
        }
        
        .logout-link {
            margin-top: auto; 
            padding: 0; 
            border-top: 1px solid rgba(255, 255, 255, 0.1); 
            width: 100%; 
            background-color: var(--msu-red); 
        }
        .logout-link .nav-link { 
            background-color: #C62828 !important; 
            color: white !important;
            padding: 18px 25px; 
            border-radius: 0; 
            text-decoration: none;
            font-size: 1.05rem; 
            font-weight: 600; 
        }
        .logout-link .nav-link:hover {
            background-color: var(--msu-red-dark) !important; 
        }
        
        /* Main content needs top padding and margin-left on desktop */
        .main-content {
            margin-left: var(--sidebar-width); 
            flex-grow: 1;
            padding: 30px;
            padding-top: calc(var(--header-height) + 30px); 
            transition: margin-left 0.3s ease; /* Added for mobile/desktop toggle */
        }
        .content-area {
            background: #fff;
            border-radius: 12px; 
            padding: 30px; 
            box-shadow: 0 5px 15px rgba(0,0,0,0.1);
        }
        /* Themed Page Header */
        .page-header {
            color: #333; 
            margin-bottom: 30px;
            padding-bottom: 15px;
            border-bottom: 2px solid var(--msu-red); /* MSU Red underline */
            font-weight: 600;
            font-size: 2rem; /* Adjusted for better hierarchy */
        }

        /* --- STAT CARD STYLES --- */
        .stat-card {
            padding: 25px; 
            border-radius: 10px;
            box-shadow: 0 4px 12px rgba(0,0,0,0.08);
            text-align: center;
            height: 100%;
            border: 1px solid #eee; 
            transition: transform 0.3s ease-in-out, box-shadow 0.3s ease-in-out; 
        }
        .stat-card:hover {
            transform: translateY(-5px); 
            box-shadow: 0 6px 20px rgba(0,0,0,0.15);
        }

        .stat-card h3 { 
            font-size: 1.1rem; 
            color: #6c757d; 
            margin-bottom: 8px; 
            text-transform: uppercase; 
            font-weight: 600; 
        }
        .stat-card p { 
            font-size: 3.5rem; 
            font-weight: 800; 
            color: var(--msu-red); 
            margin-bottom: 0; 
            line-height: 1; 
        }
        .stat-icon-wrapper { 
            font-size: 4.5rem; 
            display: block; 
            margin-bottom: 10px; 
        }

        /* Status-specific Colors (Matching theme colors) */
        .stat-card.total p, .stat-card.total .stat-icon-wrapper i { color: #333; } 
        .stat-card.pending p, .stat-card.pending .stat-icon-wrapper i { color: #ffc107; } /* Warning/Yellow */
        .stat-card.reserved p, .stat-card.reserved .stat-icon-wrapper i { color: #198754; } /* Success/Green */
        .stat-card.borrowed p, .stat-card.borrowed .stat-icon-wrapper i { color: #0d6efd; } /* Primary/Blue */
        
        /* Table Styles */
        .table-container { background: white; border-radius: 10px; overflow: hidden; box-shadow: 0 2px 10px rgba(0,0,0,0.05); margin-top: 25px; }
        .table thead th { 
            background: var(--msu-red); /* MSU Red Table Header */
            color: white; 
            font-weight: 700;
            vertical-align: middle; 
            text-align: center; 
            font-size: 0.95rem; /* Slightly smaller font */
            padding: 10px 12px; /* Tighter padding */
        }
        .table tbody td { 
            vertical-align: middle; 
            font-size: 0.95rem; 
            padding: 10px; 
            text-align: center; 
        }
        
        .status-tag {
            display: inline-block; 
            padding: 6px 14px; 
            border-radius: 18px; 
            font-weight: 700; 
            text-transform: capitalize; 
            font-size: 0.85rem; /* Smaller status font */
            line-height: 1.2; 
            white-space: nowrap;
        }
        /* Status Tags with solid color backgrounds for better visibility */
        .status-tag.waiting_for_approval, .status-tag.checking { background-color: #ffc107; color: #333; }
        .status-tag.approved, .status-tag.reserved { background-color: #198754; color: white; }
        .status-tag.rejected { background-color: #dc3545; color: white; }
        .status-tag.borrowed { background-color: #0d6efd; color: white; }
        .status-tag.returned { background-color: var(--status-returned-solid); color: white; } 
        .status-tag.damaged { background-color: #343a40; color: white; border: 1px solid #212529; }
        
        /* Highlight for Overdue/Late returns */
        .status-tag.overdue, .status-tag.returned-late { 
            background-color: var(--danger-solid); /* Use the defined danger color */
            color: white; 
        } 

        /* --- RESPONSIVE ADJUSTMENTS --- */
        @media (max-width: 992px) {
            /* Mobile Sidebar Toggle: Ensure it's displayed and positioned for mobile */
            .menu-toggle { display: flex; }
            /* Hide sidebar by default on mobile, prepare for overlay */
            .sidebar { left: calc(var(--sidebar-width) * -1); transition: left 0.3s ease; box-shadow: none; --sidebar-width: 250px; } 
            .sidebar.active { left: 0; box-shadow: 2px 0 5px rgba(0, 0, 0, 0.2); }

            /* Main Content and Header Adjustments */
            .main-content { 
                margin-left: 0; 
                padding-left: 15px; 
                padding-right: 15px;
                padding-top: calc(var(--header-height) + 15px); 
            }
            .top-header-bar { 
                left: 0; 
                padding-left: 70px; /* Space for the menu toggle button */
                padding-right: 15px;
            }
            .content-area { padding: 25px; } 
            .page-header { font-size: 1.8rem; }
            
            /* Table mobile stacking rules (Keep logic from original file) */
            .table thead { display: none; } 
            .table tbody, .table tr, .table td { display: block; width: 100%; }
            .table tr { margin-bottom: 10px; border: 1px solid #ddd; border-left: 4px solid var(--msu-red); border-radius: 8px; }
            .table td { 
                text-align: right !important; 
                padding-left: 50% !important;
                position: relative;
                border: none;
                border-bottom: 1px solid #eee;
            }
            .table td::before {
                content: attr(data-label);
                position: absolute;
                left: 10px;
                width: 45%;
                padding-right: 10px;
                white-space: nowrap;
                text-align: left;
                font-weight: 600;
                color: #555;
            }
            .table tbody tr:last-child { border-bottom: none; }
            .status-tag { margin: 0 auto; display: block; width: fit-content; }
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
        <a class="nav-link active" href="staff_dashboard.php">
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

<div class="sidebar-backdrop" id="sidebarBackdrop"></div>

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
            <i class="fas fa-tachometer-alt fa-fw me-2 text-secondary"></i> Dashboard Overview
        </h2>
        
        <h4 class="mb-4 text-dark">Welcome, <span class="text-danger fw-bold"><?= htmlspecialchars($_SESSION['user']['firstname'] ?? 'Staff') ?></span>!</h4>

        <div class="row g-4 mb-5">
            
            <div class="col-lg-3 col-md-6">
                <div class="stat-card bg-white total">
                    <span class="stat-icon-wrapper"><i class="fas fa-file-alt"></i></span>
                    <h3>Total Forms</h3>
                    <p><?= $totalForms ?></p>
                </div>
            </div>
            
            <div class="col-lg-3 col-md-6">
                <div class="stat-card bg-white pending">
                    <span class="stat-icon-wrapper"><i class="fas fa-clock"></i></span>
                    <h3>Pending</h3>
                    <p><?= $pendingForms ?></p>
                </div>
            </div>
            
            <div class="col-lg-3 col-md-6">
                <div class="stat-card bg-white reserved">
                    <span class="stat-icon-wrapper"><i class="fas fa-check-circle"></i></span>
                    <h3>Reserved (Approved)</h3>
                    <p><?= $reservedForms ?></p>
                </div>
            </div>

            <div class="col-lg-3 col-md-6">
                <div class="stat-card bg-white borrowed">
                    <span class="stat-icon-wrapper"><i class="fas fa-people-carry"></i></span>
                    <h3>Currently Borrowed</h3>
                    <p><?= $borrowedForms ?></p>
                </div>
            </div>
            
        </div>
        
        <h4 class="mb-3 text-secondary"><i class="fas fa-history me-2"></i>Recent Activity (Last 10 Forms)</h4>
        <div class="table-responsive table-container">
            <table class="table table-striped table-hover align-middle">
                <thead>
                    <tr>
                        <th>Form ID</th>
                        <th>Student ID</th>
                        <th>Type</th>
                        <th>Status</th>
                        <th>Borrow Date</th>
                        <th>Expected Return</th>
                        </tr>
                </thead>
                <tbody>
                    <?php if (!empty($allForms)): ?>
                        <?php foreach (array_slice($allForms, 0, 10) as $form): 
                            
                            $clean_status = strtolower($form['status']);
                            $display_status_text = ucfirst(str_replace('_', ' ', $clean_status));
                            $status_class = $clean_status;

                            // *** MODIFIED STATUS LOGIC FOR DASHBOARD ***
                            // Check for LATE RETURN and override class/text
                            if ($form['status'] === 'returned' && (isset($form['is_late_return']) && $form['is_late_return'] == 1)) {
                                $status_class = 'returned-late'; 
                                $display_status_text = 'Returned (LATE)';
                            } 
                            elseif (in_array($clean_status, ['returned', 'overdue', 'damaged'])) {
                                $status_class = $clean_status;
                            }
                        ?>
                            <tr>
                                <td data-label="Form ID:"><?= htmlspecialchars($form['id']) ?></td>
                                <td data-label="Student ID:"><?= htmlspecialchars($form['user_id']) ?></td>
                                <td data-label="Type:"><?= htmlspecialchars(ucfirst($form['form_type'])) ?></td>
                                <td data-label="Status:">
                                    <span class="status-tag <?= $status_class ?>">
                                        <?= $display_status_text ?>
                                    </span>
                                </td>
                                <td data-label="Borrow Date:"><?= htmlspecialchars($form['borrow_date'] ?? '-') ?></td>
                                <td data-label="Expected Return:"><?= htmlspecialchars($form['expected_return_date'] ?? '-') ?></td>
                                </tr>
                        <?php endforeach; ?>
                    <?php else: ?>
                        <tr><td colspan="6" class="text-muted py-3">No recent forms found.</td></tr>
                    <?php endif; ?>
                </tbody>
                </table>
            </div>
        </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
<script>
    // --- JAVASCRIPT FOR SYSTEM NOTIFICATION (Bell Alert) ---

    // 1. New global function to handle marking ALL staff notifications as read (MODIFIED to reload)
    window.markAllStaffAsRead = function() {
        // Use the generalized API endpoint for staff batch read
        $.post('../api/mark_notification_as_read.php', { mark_all: true, role: 'staff' }, function(response) {
            if (response.success) {
                // Reload the page to refresh the badge and dropdown content
                window.location.reload(); 
            } else {
                console.error("Failed to mark all staff notifications as read.");
                alert("Failed to clear all notifications.");
            }
        }).fail(function() {
            console.error("API call failed.");
        });
    };
    
    // 2. New global function to handle single notification click (Mark as read + navigate) (FIXED in previous turn)
    window.handleNotificationClick = function(event, element, notificationId) {
        event.preventDefault(); 
        const linkHref = element.getAttribute('href');

        // Explicitly close the Bootstrap Dropdown
        const $dropdownToggle = $('#alertsDropdown');
        const dropdownInstance = bootstrap.Dropdown.getInstance($dropdownToggle[0]);
        if (dropdownInstance) { dropdownInstance.hide(); }
        
        // Use the generalized API endpoint to mark the single alert as read
        $.post('../api/mark_notification_as_read.php', { notification_id: notificationId, role: 'staff' }, function(response) {
            if (response.success) {
                // Navigate after marking as read
                window.location.href = linkHref;
            } else {
                console.error("Failed to mark notification as read. Navigating anyway.");
                window.location.href = linkHref;
            }
        }).fail(function() {
            console.error("API call failed. Navigating anyway.");
            window.location.href = linkHref;
        });
    };

    // 3. Function to fetch the count and populate the dropdown (UPDATED for dynamic Mark All)
    function fetchStaffNotifications() {
        const apiPath = '../api/get_notifications.php'; 

        $.getJSON(apiPath, function(response) { 
            
            const unreadCount = response.count; 
            const notifications = response.alerts || []; 
            
            const $badge = $('#notification-bell-badge');
            const $dropdown = $('#notification-dropdown');
            
            // Find and detach the static 'View All' link
            const $viewAllLink = $dropdown.find('a[href="staff_pending.php"]').detach();
            
            // Clear previous dynamic items and dynamic Mark All link
            $dropdown.find('.dynamic-notif-item').remove(); 
            $dropdown.find('.mark-all-link-wrapper').remove(); 
            
            // 1. Update the Badge Count
            $badge.text(unreadCount);
            $badge.toggle(unreadCount > 0); 

            // 2. Populate the Dropdown Menu
            const $placeholder = $dropdown.find('.dynamic-notif-placeholder').empty();
            
            if (notifications.length > 0) {
                
                // *** DYNAMIC MARK ALL BUTTON CREATION ***
                if (unreadCount > 0) {
                    $placeholder.append(`
                        <a class="dropdown-item text-center small text-muted dynamic-notif-item mark-all-link-wrapper" href="#" 
                            onclick="event.preventDefault(); window.markAllStaffAsRead();">
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
                    
                    const is_read = notif.is_read == 1;
                    const itemClass = is_read ? 'text-muted' : 'fw-bold'; // Highlight unread

                    $placeholder.append(`
                             <a class="dropdown-item d-flex align-items-center dynamic-notif-item" 
                                 href="${notif.link}"
                                 data-id="${notif.id}"
                                 onclick="handleNotificationClick(event, this, ${notif.id})">
                                 <div class="me-3"><i class="${iconClass} fa-fw"></i></div>
                                 <div>
                                     <div class="small text-gray-500">${notif.created_at.split(' ')[0]}</div>
                                     <span class="d-block ${itemClass}">${notif.message}</span>
                                 </div>
                             </a>
                     `);
                });
            } else {
                // Display a "No Alerts" message
                $placeholder.html(`
                    <a class="dropdown-item text-center small text-muted dynamic-notif-item">No New Notifications</a>
                `);
            }
            
            // Re-append the View All link at the bottom
            $dropdown.append($viewAllLink);
            
        }).fail(function(jqXHR, textStatus, errorThrown) {
            console.error("Error fetching staff notifications:", textStatus, errorThrown);
            // Ensure the badge is hidden on failure
            $('#notification-bell-badge').text('0').hide();
        });
    }
    // --- END JAVASCRIPT FOR SYSTEM NOTIFICATION ---

    document.addEventListener('DOMContentLoaded', () => {
        // Sidebar activation logic (fixed for consistency)
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
        
        // --- NEW DESKTOP/MOBILE COLLAPSE LOGIC (Matching staff_apparatus.php) ---
        const menuToggle = document.getElementById('menuToggle');
        const sidebar = document.querySelector('.sidebar');
        const sidebarBackdrop = document.getElementById('sidebarBackdrop'); 
        
        // Function to set the initial state (open on desktop, closed on mobile)
        function setInitialState() {
            if (window.innerWidth > 992) {
                // Desktop: Default is permanently OPEN
                sidebar.classList.remove('active');
                if (sidebarBackdrop) sidebarBackdrop.style.display = 'none';
                if (menuToggle) menuToggle.style.display = 'none';  
            } else {
                // Mobile: Default is hidden
                sidebar.classList.remove('active');
                if (sidebarBackdrop) sidebarBackdrop.style.display = 'none';
                if (menuToggle) menuToggle.style.display = 'flex'; 
            }
        }
        
        // Function to toggle the state of the sidebar and layout
        function toggleSidebar() {
            if (window.innerWidth <= 992) {
                // Mobile behavior: Toggle 'active' class for overlay/menu
                sidebar.classList.toggle('active');
                if (sidebarBackdrop) {
                    const isActive = sidebar.classList.contains('active');
                    sidebarBackdrop.style.display = isActive ? 'block' : 'none';
                    sidebarBackdrop.style.opacity = isActive ? '1' : '0';
                }
            }
        }

        if (menuToggle && sidebar) {
            menuToggle.addEventListener('click', toggleSidebar);
            
            // Backdrop click handler (only for mobile overlay)
            if (sidebarBackdrop) {
                sidebarBackdrop.addEventListener('click', () => {
                    sidebar.classList.remove('active'); 
                    sidebarBackdrop.style.display = 'none';
                    sidebarBackdrop.style.opacity = '0';
                });
            }
            
            // Hide mobile overlay when navigating
            const navLinks = sidebar.querySelectorAll('.nav-link');
            navLinks.forEach(link => {
                link.addEventListener('click', () => {
                    if (window.innerWidth <= 992) {
                        setTimeout(() => {
                           sidebar.classList.remove('active');
                           if (sidebarBackdrop) {
                               sidebarBackdrop.style.display = 'none';
                               sidebarBackdrop.style.opacity = '0';
                           }
                        }, 100);
                    }
                });
            });

            // Handle window resize (switching between mobile/desktop layouts)
            window.addEventListener('resize', setInitialState);

            // Set initial state on load
            setInitialState();
        }
        // --- END NEW DESKTOP/MOBILE COLLAPSE LOGIC ---

        // Initial fetch on page load
        fetchStaffNotifications();
        
        // Poll the server every 30 seconds for new alerts
        setInterval(fetchStaffNotifications, 30000); 
    });
</script>
</body>
</html>