<?php
session_start();
require_once "../classes/Transaction.php";
require_once "../classes/Database.php";

if (!isset($_SESSION["user"]) || $_SESSION["user"]["role"] != "student") {
    header("Location: ../pages/login.php");
    exit;
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

// Define the Web URL base path for the browser (assumes 'uploads/apparatus_images/' relative to pages/student/)
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
        /* --- THEME MATCHING (Consistent Theme) --- */
        :root {
            --primary-color: #A40404; /* Dark Red / Maroon (WMSU-inspired) */
            --primary-color-dark: #820303; 
            --secondary-color: #f4b400; /* Gold/Yellow Accent */
            --text-dark: #2c3e50;
            --bg-light: #f5f6fa;
            --header-height: 60px;
            --danger-color: #dc3545;
            --success-color: #28a745;
            --info-color: #0d6efd; 
            --warning-color: #ffc107;
        }

        body { 
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif; 
            background: var(--bg-light); 
            padding-top: calc(var(--header-height) + 20px); 
            padding-bottom: 30px;
            padding-left: 20px;
            padding-right: 20px;
            display: flex;
            justify-content: center;
            align-items: flex-start;
        }
        
        /* === TOP HEADER BAR STYLES === */
        .top-header-bar {
            position: fixed;
            top: 0;
            left: 0;
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
        .edit-profile-link { color: var(--primary-color); font-weight: 600; text-decoration: none; }
        .notification-bell-container { position: relative; margin-right: 25px; list-style: none; padding: 0; }
        .notification-bell-container .badge-counter { background-color: var(--secondary-color); color: var(--text-dark); }
        .dropdown-menu { min-width: 300px; padding: 0; z-index: 1051; }
        .dropdown-item.unread-item { font-weight: 600; background-color: #f8f8ff; }
        /* === END TOP HEADER BAR STYLES === */
        
        .container {
            background: #fff; 
            border-radius: 12px; 
            padding: 40px;
            max-width: 1000px; /* Max width constraint */
            width: 100%;
            margin: 0 auto; 
            box-shadow: 0 8px 25px rgba(0,0,0,0.1);
        }

        /* --- Page Header --- */
        .page-header {
            color: var(--text-dark); 
            margin-bottom: 30px;
            padding-bottom: 15px;
            border-bottom: 3px solid var(--primary-color);
            font-weight: 700;
            font-size: 2.2rem;
        }
        .page-header i {
            color: var(--secondary-color);
        }

        /* --- Details Grid --- */
        .form-details-grid {
            background: #f8f9fa;
            border: 1px solid #dee2e6;
            border-radius: 8px;
            padding: 25px;
            margin-bottom: 30px;
        }
        .detail-label {
            font-weight: 700;
            color: var(--primary-color);
            margin-bottom: 5px;
            display: block;
            font-size: 0.95rem;
        }
        .detail-label i {
            color: var(--secondary-color);
            margin-right: 5px;
        }
        .detail-value {
            font-size: 1.05rem;
            color: var(--text-dark);
            word-wrap: break-word; 
        }
        .remarks-container {
             border-top: 1px solid #e9ecef;
             margin-top: 15px;
             padding-top: 15px;
        }
    
        /* --- Status Tag Theming --- */
        .status-tag {
            display: inline-block;
            padding: 6px 12px;
            border-radius: 20px;
            font-weight: 700;
            text-transform: capitalize;
            font-size: 0.85rem;
            line-height: 1.2;
        }
        .status-tag.waiting_for_approval { background-color: var(--warning-color); color: var(--text-dark); } 
        .status-tag.approved { background-color: var(--info-color); color: white; } 
        .status-tag.rejected { background-color: var(--danger-color); color: white; } 
        .status-tag.borrowed { background-color: var(--info-color); color: white; } 
        .status-tag.returned { background-color: var(--success-color); color: white; }
        .status-tag.overdue, .status-tag.damaged { background-color: var(--danger-color); color: white; }
        .status-tag.checking { background-color: var(--warning-color); color: var(--text-dark); }

        /* --- Items Table --- */
        .table-responsive {
            border-radius: 10px;
            overflow: hidden;
            box-shadow: 0 2px 10px rgba(0,0,0,0.05);
            margin-top: 20px;
        }
        .table thead th { 
            background: var(--primary-color); 
            color: white; 
            font-weight: 600;
            vertical-align: middle;
            text-align: center;
            font-size: 1rem;
        }
        .table tbody td {
            vertical-align: middle;
            font-size: 0.95rem;
            text-align: center;
        }
        .table-image-cell {
            width: 80px;
        }
        .table-image-cell img {
             border-radius: 6px !important;
             border: 1px solid #eee;
        }
        
        .btn-msu-red {
            background-color: var(--primary-color); 
            border-color: var(--primary-color);
            color: #fff;
            padding: 10px 25px;
            font-weight: 600;
            border-radius: 50px; /* Pill shape */
            transition: background-color 0.2s, border-color 0.2s, transform 0.2s;
        }
        .btn-msu-red:hover {
            background-color: var(--primary-color-dark);
            border-color: var(--primary-color-dark);
            transform: translateY(-1px);
        }
        
        /* --- RESPONSIVE ADJUSTMENTS --- */
        @media (max-width: 992px) {
             .top-header-bar { padding: 0 15px; justify-content: space-between; }
             .notification-bell-container { margin-right: 15px; }
             .container { max-width: 100%; padding: 30px; }
        }

        @media (max-width: 768px) {
            .page-header { font-size: 1.8rem; }
            
            /* Details grid stacking */
            .form-details-grid .col-md-3, .form-details-grid .col-sm-6 {
                width: 50%;
            }
            .form-details-grid .col-12 {
                width: 100%;
            }

            /* Item Table Responsive Styling */
            .table thead th:nth-child(3), /* Type */
            .table tbody td:nth-child(3),
            .table thead th:nth-child(4), /* Size */
            .table tbody td:nth-child(4),
            .table thead th:nth-child(5), /* Material */
            .table tbody td:nth-child(5) {
                display: none; /* Hide less critical columns */
            }
            
            .table thead th, .table tbody td {
                padding: 10px 10px; /* Reduce padding more */
                font-size: 0.9rem;
            }
            .table-image-cell { width: 60px; }
            .table-image-cell img { width: 40px !important; height: 40px !important; }
            .table tbody td:nth-child(2) { text-align: left !important; } /* Name: Left align */
        }
        
        @media (max-width: 576px) {
            .top-header-bar {
                 padding: 0 10px;
                 justify-content: flex-end;
            }
            .edit-profile-link {
                 font-size: 0.9rem;
            }
            .form-details-grid .col-md-3, .form-details-grid .col-sm-6 {
                width: 100%; /* Full stack on XS screens */
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
        Form #<?= htmlspecialchars($form["id"]) ?> - <?= $form_type ?> Details
    </h2>
    <p class="mb-4"><a href="<?= htmlspecialchars($back_url) ?>" class="text-decoration-none text-secondary"><i class="fas fa-arrow-left fa-fw me-1"></i> <?= htmlspecialchars($back_text) ?></a></p>


    <div class="form-details-grid">
        <div class="row g-3">
            <div class="col-md-3 col-sm-6">
                <span class="detail-label"><i class="fas fa-bookmark fa-fw"></i> Status</span>
                <span class="status-tag <?= htmlspecialchars(str_replace(' ', '_', strtolower($form["status"]))) ?>">
                    <?= htmlspecialchars(str_replace('_', ' ', $form["status"])) ?>
                </span>
            </div>
            <div class="col-md-3 col-sm-6">
                <span class="detail-label"><i class="fas fa-calendar-day fa-fw"></i> Borrow Date</span>
                <span class="detail-value"><?= htmlspecialchars($form["borrow_date"] ?? '-') ?></span>
            </div>
            <div class="col-md-3 col-sm-6">
                <span class="detail-label"><i class="fas fa-clock fa-fw"></i> Expected Return</span>
                <span class="detail-value"><?= htmlspecialchars($form["expected_return_date"] ?? '-') ?></span>
            </div>
            <div class="col-md-3 col-sm-6">
                <span class="detail-label"><i class="fas fa-calendar-check fa-fw"></i> Actual Return</span>
                <span class="detail-value"><?= htmlspecialchars($form["actual_return_date"] ?? '-') ?></span>
            </div>
            <div class="col-12 remarks-container">
                <span class="detail-label"><i class="fas fa-comment-dots fa-fw"></i> Remarks</span>
                <p class="detail-value mb-0"><?= htmlspecialchars($form["staff_remarks"] ?? 'No remarks provided.') ?></p>
            </div>
        </div>
    </div>
    
    <h4 class="mb-3 text-secondary"><i class="fas fa-boxes fa-fw me-2"></i> Borrowed Items</h4>
    <div class="table-responsive">
        <table class="table table-striped align-middle">
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
                    // Determine image URL with fallback
                    $imagePath = "../uploads/apparatus_images/" . ($item["image"] ?? 'default.jpg');
                    $imageURL = $baseURL . ($item["image"] ?? 'default.jpg');

                    // Note: file_exists() check is not executable here, so we rely on the URL path logic.
                ?>
                    <tr>
                        <td class="table-image-cell">
                            <img src="<?= htmlspecialchars($imageURL) ?>" 
                                alt="<?= htmlspecialchars($item["name"] ?? 'N/A') ?>" 
                                class="img-fluid rounded"
                                style="width: 50px; height: 50px; object-fit: cover;">
                        </td>
                        <td class="text-start fw-bold"><?= htmlspecialchars($item["name"]) ?></td>
                        <td><?= htmlspecialchars($item["apparatus_type"]) ?></td>
                        <td><?= htmlspecialchars($item["size"]) ?></td>
                        <td><?= htmlspecialchars($item["material"]) ?></td>
                        <td><?= htmlspecialchars($item["quantity"] ?? 1) ?></td> <td>
                            <span class="status-tag <?= htmlspecialchars(str_replace('_', '', strtolower($item["item_status"]))) ?>">
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
            const $placeholder = $dropdown.find('.dynamic-notif-placeholder').empty();
            
            const $viewAllLink = $dropdown.find('a[href="student_transaction.php"]').detach(); 
            
            $dropdown.find('.mark-all-btn-wrapper').remove(); 

            // 1. Update the Badge Count
            $badge.text(unreadCount);
            $badge.toggle(unreadCount > 0); 

            // 2. Populate the Dropdown Menu
            if (notifications.length > 0) {
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
        // 1. Notification Logic Setup
        fetchStudentAlerts(); // Initial fetch on page load
        setInterval(fetchStudentAlerts, 30000); // Poll the server every 30 seconds
    });
</script>
</body>
</html>