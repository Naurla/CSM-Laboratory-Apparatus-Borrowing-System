<?php
session_start();
require_once "../classes/Transaction.php";

if (!isset($_SESSION["user"]) || $_SESSION["user"]["role"] != "student") {
    header("Location: ../pages/login.php");
    exit();
}

$transaction = new Transaction();

if (!isset($_GET["form_id"])) {
    http_response_code(400); 
    echo '<!DOCTYPE html><html lang="en"><head><meta charset="UTF-8"><title>Error</title><link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet"></head><body><div class="container mt-5"><div class="alert alert-danger" role="alert">No form ID provided.</div><a href="student_dashboard.php" class="btn btn-secondary">Back to Dashboard</a></div></body></html>';
    exit();
}

$form_id = $_GET["form_id"];
// This call will now be defined in Transaction.php:
$form = $transaction->getBorrowFormById($form_id);

if (!$form) {
    http_response_code(404);
    echo '<!DOCTYPE html><html lang="en"><head><meta charset="UTF-8"><title>Error</title><link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet"></head><body><div class="container mt-5"><div class="alert alert-danger" role="alert">Form not found.</div><a href="student_dashboard.php" class="btn btn-secondary">Back to Dashboard</a></div></body></html>';
    exit();
}

// Ensure form_type exists, default to 'Form' if not set
$form_type = isset($form["form_type"]) ? ucfirst($form["form_type"]) : 'Form';

// $items uses getBorrowFormItems, which fetches aggregated details via JOINs
$items = $transaction->getBorrowFormItems($form_id);

// --- NAVIGATION CONTEXT LOGIC FIX ---
$context = $_GET["context"] ?? '';

$back_url = 'student_dashboard.php'; // Default is Current Activity
$back_text = 'Back to Current Activity';

if ($context === 'history') {
    $back_url = 'student_transaction.php';
    $back_text = 'Back to Transaction History';
}
// ------------------------------------

// Define the Web URL base path for the browser (assumes 'wd123' is the folder under htdocs)
// This must match your web server setup.
$baseURL = "../uploads/apparatus_images/"; 
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>View <?= $form_type ?> #<?= htmlspecialchars($form["id"]) ?></title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0-beta3/css/all.min.css" rel="stylesheet">
    
    <script src="https://code.jquery.com/jquery-3.6.0.min.js"></script> 
    
    <style>
        
        :root {
            --msu-red: #A40404; /* FIXED: Consistent Red */
            --msu-red-dark: #820303; /* FIXED: Consistent Dark Red */
            --primary-blue: #007bff;
            --header-height: 60px; /* Define header height */
            --bg-light: #f5f6fa;
            --danger-dark: #8b0000;
            --status-returned-solid: #198754; 
            --status-overdue-solid: #dc3545; 
        }

        body { 
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif; 
            background: #e9ecef; 
            min-height: 100vh;
            /* ADDED PADDING TOP for fixed header */
            padding-top: calc(var(--header-height) + 20px); 
            padding-bottom: 20px;
            padding-left: 20px;
            padding-right: 20px;
            display: flex;
            justify-content: center; /* Center the container horizontally */
            align-items: flex-start; /* Align container to the top */
        }
        
        /* === TOP HEADER BAR STYLES (Restored Bell) === */
        .top-header-bar {
            position: fixed;
            top: 0;
            left: 0; /* Starts from the left edge */
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
        .dropdown-menu {
            min-width: 300px;
            padding: 0;
            z-index: 1051; 
        }
        .dropdown-item {
            padding: 10px 15px;
            white-space: normal;
            position: relative;
        }
        .dropdown-item.unread-item {
            font-weight: 600;
            background-color: #f8f8ff;
        }
        .dropdown-item:hover .mark-read-hover-btn {
            opacity: 1;
        }
        .mark-read-hover-btn {
            position: absolute;
            top: 50%;
            right: 10px;
            transform: translateY(-50%);
            opacity: 0;
        }
        /* === END TOP HEADER BAR STYLES === */
        

        /* MODIFIED: Stretched Container */
        .container {
            background: #fff; 
            border-radius: 12px; 
            padding: 40px;
            max-width: 95%; 
            width: 95%;
            margin: 0 auto; /* Removed top margin due to body padding-top */
            box-shadow: 0 8px 25px rgba(0,0,0,0.1);
        }
        /* END MODIFIED */

        
        .page-header {
            color: var(--msu-red); 
            margin-bottom: 30px;
            padding-bottom: 15px;
            border-bottom: 3px solid var(--msu-red);
            font-weight: 700;
            font-size: 2.2rem;
        }
        .page-header i {
            margin-right: 10px;
        }

    
        .form-details-grid {
            background: #f8f9fa;
            border: 1px solid #dee2e6;
            border-radius: 8px;
            padding: 20px;
            margin-bottom: 30px;
        }
        .detail-label {
            font-weight: 600;
            color: #495057;
            margin-bottom: 5px;
            display: block;
        }
        .detail-value {
            font-size: 1rem;
            color: #212529;
            word-wrap: break-word; 
        }
        .detail-item {
            padding: 10px 0;
            border-bottom: 1px dotted #e9ecef;
        }
        .detail-item:last-child {
            border-bottom: none;
        }
    
        .status-tag {
            display: inline-block;
            padding: 4px 10px;
            border-radius: 15px;
            font-weight: 700;
            text-transform: capitalize;
            font-size: 0.85rem;
            line-height: 1.2;
        }

        .status-tag.waiting_for_approval { background-color: #ffc10740; color: #ffc107; } 
        .status-tag.approved { background-color: #19875440; color: #198754; } 
        .status-tag.rejected { background-color: #dc354540; color: #dc3545; } 
        .status-tag.borrowed { background-color: #0d6efd40; color: #0d6efd; } 
        .status-tag.returned { background-color: #6c757d40; color: #6c757d; }
        .status-tag.overdue { background-color: #dc354540; color: #dc3545; } 
        /* Match dark status styles if available in other views */
        .status-tag.damaged, .status-tag.overdue { background-color: var(--msu-red); color: white; }

    
        .table {
            border-radius: 8px;
            overflow: hidden;
            box-shadow: 0 2px 10px rgba(0,0,0,0.05);
            margin-top: 20px;
        }
        .table thead th { 
            background: var(--msu-red); 
            color: white; 
            font-weight: 600;
            vertical-align: middle;
            text-align: center;
        }
        .table tbody td {
            vertical-align: middle;
            font-size: 0.95rem;
            text-align: center;
        }
        .table-image-cell {
            width: 80px;
        }

        
        .btn-msu-red {
            background-color: var(--msu-red); 
            border-color: var(--msu-red);
            color: #fff;
            padding: 10px 25px;
            font-weight: 600;
            transition: background-color 0.2s, border-color 0.2s;
        }
        .btn-msu-red:hover {
            background-color: var(--msu-red-dark);
            border-color: var(--msu-red-dark);
            color: #fff;
        }
        
        /* --- RESPONSIVE ADJUSTMENTS --- */
        @media (max-width: 768px) {
            .container {
                padding: 20px;
            }
            .page-header {
                font-size: 1.8rem;
            }
            /* Details grid stacking */
            .form-details-grid .col-md-3, .form-details-grid .col-sm-6 {
                width: 50%; /* Make 50% width on small screens */
            }
            .form-details-grid .col-12 {
                width: 100%;
            }

            /* Table Responsive */
            .table thead th:nth-child(3), /* Type */
            .table tbody td:nth-child(3),
            .table thead th:nth-child(4), /* Size */
            .table tbody td:nth-child(4),
            .table thead th:nth-child(5), /* Material */
            .table tbody td:nth-child(5) {
                display: none; /* Hide less critical columns */
            }
            
            .table thead th:nth-child(2), /* Name: Left align */
            .table tbody td:nth-child(2) {
                text-align: left;
            }

            /* Ensure image column is tight */
            .table-image-cell {
                 width: 60px;
            }
            .table-image-cell img {
                width: 40px !important;
                height: 40px !important;
            }
            
            .table thead th, .table tbody td {
                padding: 10px 5px; /* Reduce padding more */
                font-size: 0.9rem;
            }
        }
        
        @media (max-width: 576px) {
            .top-header-bar {
                padding: 0 15px;
                justify-content: flex-end;
            }
            .edit-profile-link {
                 font-size: 0.9rem;
            }
            .form-details-grid .col-md-3, .form-details-grid .col-sm-6 {
                width: 100%; /* Full stack on XS screens */
            }
            
            .table thead th, .table tbody td {
                font-size: 0.8rem;
            }
        }
    </style>
</head>
<body>

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
<div class="container">
    <h2 class="page-header">
        <i class="fas fa-file-invoice fa-fw"></i> 
        Form #<?= htmlspecialchars($form["id"]) ?> - <?= $form_type ?>
    </h2>

    <div class="form-details-grid">
        <div class="row g-3">
            <div class="col-md-3 col-sm-6">
                <span class="detail-label"><i class="fas fa-bookmark fa-fw me-1"></i> Status</span>
                <span class="status-tag <?= htmlspecialchars($form["status"]) ?>">
                    <?= htmlspecialchars(str_replace('_', ' ', $form["status"])) ?>
                </span>
            </div>
            <div class="col-md-3 col-sm-6">
                <span class="detail-label"><i class="fas fa-calendar-day fa-fw me-1"></i> Borrow Date</span>
                <span class="detail-value"><?= htmlspecialchars($form["borrow_date"] ?? '-') ?></span>
            </div>
            <div class="col-md-3 col-sm-6">
                <span class="detail-label"><i class="fas fa-clock fa-fw me-1"></i> Expected Return</span>
                <span class="detail-value"><?= htmlspecialchars($form["expected_return_date"] ?? '-') ?></span>
            </div>
            <div class="col-md-3 col-sm-6">
                <span class="detail-label"><i class="fas fa-calendar-check fa-fw me-1"></i> Actual Return</span>
                <span class="detail-value"><?= htmlspecialchars($form["actual_return_date"] ?? '-') ?></span>
            </div>
            <div class="col-12 mt-3 pt-3 border-top">
                <span class="detail-label"><i class="fas fa-comment-dots fa-fw me-1"></i> Remarks</span>
                <p class="detail-value mb-0"><?= htmlspecialchars($form["staff_remarks"] ?? 'No remarks provided.') ?></p>
            </div>
        </div>
    </div>
    
    <h4 class="mb-3 text-secondary"><i class="fas fa-boxes fa-fw me-2"></i> Borrowed Items</h4>
    <div class="table-responsive">
        <table class="table table-hover align-middle">
            <thead>
            <tr>
                <th class="table-image-cell">Image</th>
                <th class="text-start">Apparatus Name</th>
                <th>Type</th>
                <th>Size</th>
                <th>Material</th>
                <th>Quantity</th> <th>Item Status</th>
            </tr>
            </thead>
            <tbody>
            <?php if (!empty($items)): ?>
                <?php foreach ($items as $item): 
                    // Server-side check for robust fallback path
                    // This path must be correct relative to the executing PHP file (student_view_items.php)
                    $serverPath = __DIR__ . "/../uploads/apparatus_images/" . ($item["image"] ?? 'default.jpg');
                    
                    // The URL the browser sees (using the file name fetched from the aggregated items)
                    $imageURL = $baseURL . ($item["image"] ?? 'default.jpg');

                    // Check if file exists using PHP's file system
                    if (!file_exists($serverPath) || is_dir($serverPath)) {
                        // Fallback URL: Use the correct path for the default image.
                        $imageURL = $baseURL . "default.jpg";
                    }
                ?>
                    <tr>
                        <td class="table-image-cell">
                            <img src="<?= htmlspecialchars($imageURL) ?>" 
                                alt="<?= htmlspecialchars($item["name"] ?? 'N/A') ?>" 
                                class="img-fluid rounded"
                                style="width: 50px; height: 50px; object-fit: cover; border: 1px solid #ddd;">
                        </td>
                        <td class="text-start fw-bold"><?= htmlspecialchars($item["name"]) ?></td>
                        <td><?= htmlspecialchars($item["apparatus_type"]) ?></td>
                        <td><?= htmlspecialchars($item["size"]) ?></td>
                        <td><?= htmlspecialchars($item["material"]) ?></td>
                        <td><?= htmlspecialchars($item["quantity"] ?? 1) ?></td> <td>
                            <span class="status-tag <?= htmlspecialchars($item["item_status"]) ?>">
                                <?= htmlspecialchars(str_replace('_', ' ', $item["item_status"])) ?>
                            </span>
                        </td>
                    </tr>
                <?php endforeach; ?>
            <?php else: ?>
                <tr><td colspan="7" class="text-muted py-4">No items found for this form.</td></tr> 
            <?php endif; ?>
            </tbody>
        </table>
    </div>

    <div class="text-center pt-4">
        <a href="<?= htmlspecialchars($back_url) ?>" class="btn btn-msu-red shadow-sm">
            <i class="fas fa-arrow-left fa-fw"></i> <?= htmlspecialchars($back_text) ?>
        </a>
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
            // Assuming the dropdown content is NOT included in this file, but is dynamically loaded if needed.
            
            // 1. Update the Badge Count
            $badge.text(unreadCount);
            $badge.toggle(unreadCount > 0); 
            
            // Since this is a view file, we don't fully populate the dropdown here, 
            // relying on the dashboard/other pages to handle the complex rendering. 
            // We just ensure the badge works.
        }).fail(function(jqXHR, textStatus, errorThrown) {
            console.error("Error fetching student alerts:", textStatus, errorThrown);
            $('#notification-bell-badge').text('0').hide();
        });
    }


    // --- DOMContentLoaded Execution (Initialization) ---
    document.addEventListener('DOMContentLoaded', () => {
        // 1. Notification Logic Setup
        fetchStudentAlerts(); // Initial fetch on page load
        setInterval(fetchStudentAlerts, 30000); // Poll the server every 30 seconds
    });
</script>
</body>
</html>