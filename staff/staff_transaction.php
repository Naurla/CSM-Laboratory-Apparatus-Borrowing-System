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

// Helper function for Item Status in cells (Moved here for self-contained execution)
function getFormItemsText($form_id, $transaction) {
    // We assume getFormItems returns the detailed unit-level status
    $items = $transaction->getFormItems($form_id); 
    if (empty($items)) return '<span class="text-muted">N/A</span>';
    $output = '';
    
    foreach ($items as $item) {
        $name = htmlspecialchars($item['name'] ?? 'Unknown');
        $item_status = strtolower($item['item_status']);
        $tag_class = $item_status;
        $tag_text = ucfirst(str_replace('_', ' ', $item_status));
        
        // --- MONOCHROME DAMAGE FIX APPLIED HERE (Item Status) ---
        if ($item_status === 'damaged') {
             $tag_class = 'damaged'; // Uses the new dark gray style
             $tag_text = 'Damaged';
        } elseif ($item_status === 'returned' && strtolower($item['form_status'] ?? '') === 'returned-late') {
             $tag_class = 'returned-late'; // Red for late return
             $tag_text = 'Returned (Late)';
        } elseif ($item_status === 'returned') {
             $tag_class = 'returned'; // Green for normal return
             $tag_text = 'Returned';
        }
        
        $output .= '<div class="d-flex align-items-center justify-content-between mb-1">';
        $output .= '    <span class="me-2">' . $name . ' (x' . ($item['quantity'] ?? 1) . ')</span>';
        $output .= '    <span class="status-tag ' . $tag_class . '">' . $tag_text . '</span>';
        $output .= '</div>';
    }
    return $output;
}
?>

<!DOCTYPE html>
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
            overflow-x: hidden; /* CRITICAL: Prevent page-level scrollbar */
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
             min-width: 1350px; /* Define a generous minimum width to activate internal scroll */
        }
        
        .table thead th {
            background: var(--msu-red);
            color: white;
            font-weight: 700; /* Bolder */
            vertical-align: middle;
            text-align: center;
            font-size: 0.95rem; 
            padding: 10px 5px; 
            white-space: nowrap;
        }
        .table tbody td {
            vertical-align: middle;
            font-size: 0.95rem; 
            padding: 8px 4px; 
            text-align: center;
        }
        
        
        td.apparatus-list-cell {
            text-align: left;
            padding: 8px 10px !important; 
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
            padding: 4px 10px; 
            border-radius: 14px;
            font-weight: 700;
            text-transform: capitalize;
            font-size: 0.8rem; 
            white-space: nowrap;
        }

        /* MONOCHROME FIX: Dark Gray/Black for Damaged (Form Status and Item Status) */
        .status-tag.damaged { 
            background-color: #343a40 !important; /* Dark Gray/Black */
            color: white !important; 
            font-weight: 800; 
        }

        /* Standard Colors (Lighter background, dark text for better contrast) */
        .status-tag.waiting_for_approval, .status-tag.pending { background-color: #ffc10730; color: #b8860b; }
        .status-tag.approved, .status-tag.borrowed, .status-tag.checking { background-color: #007bff30; color: #007bff; }
        .status-tag.rejected { background-color: #6c757d30; color: #6c757d; }
        .status-tag.returned { background-color: #28a74530; color: #28a745; }
        .status-tag.overdue, .status-tag.returned-late { background-color: #dc354530; color: #dc3545; border: 1px solid #dc3545; }
        
    </style>
</head>
<body>

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
            <i class="fas fa-list-alt fa-fw me-2 text-secondary"></i> All Transactions History
        </h2>

        <form method="GET" class="mb-3" id="transactionFilterForm">
            <div class="d-flex flex-wrap align-items-center justify-content-between">
                
                <div class="d-flex align-items-center mb-2 mb-md-0">
                    <label class="form-label me-2 mb-0 fw-bold text-secondary">Filter by Status:</label>
                    <select name="filter" id="statusFilter" class="form-select form-select-sm w-auto">
                        <option value="all" <?= $filter === 'all' ? 'selected' : '' ?>>All</option>
                        <option value="waiting_for_approval" <?= $filter === 'waiting_for_approval' ? 'selected' : '' ?>>Waiting for Approval</option>
                        <option value="borrowed" <?= $filter === 'borrowed' ? 'selected' : '' ?>>Borrowed</option>
                        <option value="reserved" <?= $filter === 'reserved' ? 'selected' : '' ?>>Reserved</option>
                        <option value="returned" <?= $filter === 'returned' ? 'selected' : '' ?>>Returned</option>
                        <option value="overdue" <?= $filter === 'overdue' ? 'selected' : '' ?>>Overdue</option>
                        <option value="rejected" <?= $filter === 'rejected' ? 'selected' : '' ?>>Rejected</option>
                        <option value="damaged" <?= $filter === 'damaged' ? 'selected' : '' ?>>Damaged</option>
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
                        <th>Status</th>
                        <th>Borrow Date</th>
                        <th>Expected Return</th>
                        <th>Actual Return</th>
                        <th>Apparatus (Item & Status)</th> <th>Staff Remarks</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (!empty($transactions)): ?>
                        <?php foreach ($transactions as $trans): 
                            // Fetch the detailed, unit-level item list
                            $detailed_items = $transaction->getFormItems($trans['id']); 
                            $clean_status = strtolower($trans['status']);
                            
                            // Determine the final status class and text for the main form status
                            $status_class = $clean_status;
                            $display_status_text = ucfirst(str_replace('_', ' ', $trans['status']));

                            // 1. Handle LATE RETURN: Overrides 'returned' class and text
                            if ($trans['status'] === 'returned' && (isset($trans['is_late_return']) && $trans['is_late_return'] == 1)) {
                                $status_class = 'returned-late'; // Custom class for coloring/border
                                $display_status_text = 'Returned (LATE)';
                            } 
                            // 2. Handle DAMAGED: Use the monochrome dark class
                            elseif ($trans['status'] === 'damaged') {
                                $status_class = 'damaged'; 
                            }
                        ?>
                        <tr>
                            <td class="fw-bold"><?= $trans['id'] ?></td>
                            <td class="text-start">
                                <strong><?= htmlspecialchars($trans['firstname'] ?? '') ?> <?= htmlspecialchars($trans['lastname'] ?? '') ?></strong>
                                <br>
                                <small class="text-muted">(ID: <?= htmlspecialchars($trans['user_id']) ?>)</small>
                            </td>
                            <td><?= ucfirst($trans['form_type']) ?></td>
                            
                            <td>
                                <span class="status-tag <?= $status_class ?>">
                                    <?= $display_status_text ?>
                                </span>
                            </td>
                            <td><?= $trans['borrow_date'] ?: '-' ?></td>
                            <td><?= $trans['expected_return_date'] ?: '-' ?></td>
                            <td><?= $trans['actual_return_date'] ?: '-' ?></td>
                            
                            <td class="apparatus-list-cell">
                                <?php 
                                // Pass the main form status to the helper function for item-level status logic
                                $form_status_for_helper = $trans['status'];
                                
                                foreach ($detailed_items as $it): 
                                    $name = htmlspecialchars($it['name'] ?? 'Unknown');
                                    $item_status = strtolower($it['item_status']);
                                    
                                    $tag_class = $item_status;
                                    $tag_text = ucfirst(str_replace('_', ' ', $item_status));
                                    
                                    // Apply MONOCHROME and custom coloring based on item status
                                    if ($item_status === 'damaged') {
                                             $tag_class = 'damaged'; 
                                             $tag_text = 'Damaged';
                                    } elseif ($item_status === 'returned') {
                                             // Check for LATE RETURN status consistency
                                             if ($form_status_for_helper === 'returned' && (isset($trans['is_late_return']) && $trans['is_late_return'] == 1)) {
                                                $tag_class = 'returned-late'; // Red for late return
                                                $tag_text = 'Returned (Late)';
                                             } else {
                                                $tag_class = 'returned'; // Green for normal return
                                                $tag_text = 'Returned';
                                             }
                                    }
                                ?>
                                    <div class="d-flex align-items-center justify-content-between mb-1">
                                        <span class="me-2"><?= $name ?> (x<?= $it['quantity'] ?? 1 ?>)</span>
                                        <span class="status-tag <?= $tag_class ?>">
                                            <?= $tag_text ?>
                                        </span>
                                    </div>
                                <?php endforeach; ?>
                            </td>
                            <td><?= htmlspecialchars($trans['staff_remarks'] ?? '-') ?></td>
                        </tr>
                        <?php endforeach; ?>
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
    // --- JAVASCRIPT FOR STAFF NOTIFICATION LOGIC (COPIED) ---
    // Function to handle clicking a notification link
    window.handleNotificationClick = function(event, element, notificationId) {
        // Prevent default navigation initially
        event.preventDefault(); 
        const linkHref = element.getAttribute('href');

        // Mark as read via API endpoint
        $.post('../api/mark_notification_as_read.php', { notification_id: notificationId, role: 'staff' }, function(response) {
            if (response.success) {
                // Navigate after marking as read
                window.location.href = linkHref;
            } else {
                console.error("Failed to mark notification as read.");
                // Fallback: navigate anyway if DB update fails
                window.location.href = linkHref; 
            }
        }).fail(function() {
            console.error("API call failed.");
            // Fallback: navigate anyway if API call fails
            window.location.href = linkHref;
        });
    };

    // Function to mark ALL staff notifications as read
    window.markAllStaffAsRead = function() {
        $.post('../api/mark_notification_as_read.php', { mark_all: true, role: 'staff' }, function(response) {
            if (response.success) {
                // Reload the page to clear the badge
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
            
            // 1. Update the Badge Count
            const unreadCount = response.count; 
            const notifications = response.alerts || []; 
            
            const $badge = $('#notification-bell-badge');
            const $dropdown = $('#notification-dropdown');
            
            // Find and temporarily detach the static View All link
            const $viewAllLink = $dropdown.find('a[href="staff_pending.php"]').detach();
            
            // Find the dropdown header
            const $header = $dropdown.find('.dropdown-header');
            
            // Clear previous dynamic content and any old Mark All buttons
            $dropdown.find('.dynamic-notif-item').remove();
            $dropdown.find('.mark-all-btn-wrapper').remove(); 
            
            // Update badge display
            $badge.text(unreadCount);
            $badge.toggle(unreadCount > 0); 
            
            
            if (notifications.length > 0) {
                // Prepend Mark All button if there are unread items
                if (unreadCount > 0) {
                     $header.after(`
                          <a class="dropdown-item text-center small text-muted dynamic-notif-item mark-all-btn-wrapper" href="#" onclick="event.preventDefault(); window.markAllStaffAsRead();">
                             <i class="fas fa-check-double me-1"></i> Mark All ${unreadCount} as Read
                          </a>
                     `);
                }

                // Iterate and insert notifications
                notifications.slice(0, 5).forEach(notif => {
                    
                    // Determine icon based on notification type
                    let iconClass = 'fas fa-info-circle text-info'; 
                    if (notif.type.includes('form_pending')) {
                         iconClass = 'fas fa-hourglass-half text-warning';
                    } else if (notif.type.includes('checking')) {
                         iconClass = 'fas fa-redo text-primary';
                    }
                    
                    // Style unread items slightly differently
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
                // Display a "No Alerts" message immediately after the header
                $header.after(`
                    <a class="dropdown-item text-center small text-muted dynamic-notif-item">No New Notifications</a>
                `);
            }
            
            // Re-append the 'View All' link to the end of the dropdown
            $dropdown.append($viewAllLink);
            

        }).fail(function(jqXHR, textStatus, errorThrown) {
            console.error("Error fetching staff notifications:", textStatus, errorThrown);
            // Ensure the badge is hidden on failure
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