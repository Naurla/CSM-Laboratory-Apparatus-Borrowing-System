<?php
// student_notifications_content.php (NOW A CONTENT ENDPOINT FOR MODAL)
session_start();
// Include the PHPMailer autoloader first (assuming it's in vendor)
require_once "../vendor/autoload.php"; 
require_once "../classes/Transaction.php";
require_once "../classes/Database.php"; 

if (!isset($_SESSION["user"]) || $_SESSION["user"]["role"] != "student") {
    http_response_code(401);
    exit("Unauthorized");
}

$transaction = new Transaction();
$student_id = $_SESSION["user"]["id"];

$notifications = [];

// Helper to format timestamps nicely (replicate the function here or ensure it's globally available)
function time_ago($timestamp) {
    $datetime = new DateTime($timestamp);
    $now = new DateTime();
    $interval = $now->diff($datetime);
    
    if ($interval->y >= 1) return $interval->y . " year" . ($interval->y > 1 ? "s" : "") . " ago";
    if ($interval->m >= 1) return $interval->m . " month" . ($interval->m > 1 ? "s" : "") . " ago";
    if ($interval->d >= 1) return $interval->d . " day" . ($interval->d > 1 ? "s" : "") . " ago";
    if ($interval->h >= 1) return $interval->h . " hour" . ($interval->h > 1 ? "s" : "") . " ago";
    if ($interval->i >= 1) return $interval->i . " minute" . ($interval->i > 1 ? "s" : "") . " ago";
    return "just now";
}

// Injecting the theme variables and enhanced CSS
echo '<style>
    /* Theme Variables for consistency */
    :root {
        --primary-color: #A40404; 
        --primary-color-dark: #820303; 
        --secondary-color: #f4b400;
        --text-dark: #2c3e50;
        --danger-color: #dc3545;
        --success-color: #28a745;
    }

    .alert-item {
        padding: 15px;
        border-radius: 8px;
        text-decoration: none;
        color: var(--text-dark);
        transition: background-color 0.1s, box-shadow 0.2s;
        border: 1px solid #eee;
    }
    
    /* Highlight UNREAD items with primary color border */
    .alert-unread {
        background-color: #fff9f9; /* Very light red tint */
        font-weight: 600;
        border-left: 5px solid var(--primary-color);
    }
    .alert-unread:hover {
        background-color: #faeaea;
    }
    .alert-read {
        background-color: #fff;
        font-weight: normal;
    }
    .alert-read:hover {
        background-color: #f9f9f9;
        box-shadow: 0 1px 5px rgba(0,0,0,0.05); 
    }
    .alert-icon {
        font-size: 1.3rem;
        flex-shrink: 0;
        width: 30px;
    }
    .alert-message {
        font-size: 1rem;
        line-height: 1.4;
        word-wrap: break-word;
        white-space: normal;
    }
    .alert-timestamp {
        display: block;
        font-size: 0.8em;
        color: #999;
        margin-top: 2px;
    }
    .unread-badge {
        font-size: 0.75em;
        padding: 0.4em 0.7em;
        background-color: var(--primary-color) !important; 
    }
    /* Mark All Button Styling - Uses accent color */
    .btn-mark-all {
        border: 1px solid var(--secondary-color);
        color: var(--text-dark);
        background-color: #fff;
        font-weight: 600;
        border-radius: 6px;
        transition: all 0.2s;
        padding: 5px 10px;
        font-size: 0.9rem;
    }
    .btn-mark-all:hover {
        background-color: var(--secondary-color);
        color: var(--text-dark);
    }

    /* Override Bootstrap text colors to use theme primary for specific states */
    .text-danger { color: var(--danger-color) !important; }
    .text-success { color: var(--success-color) !important; }
    .text-primary { color: var(--primary-color) !important; }
    .text-warning { color: var(--secondary-color) !important; }
</style>';


try {
    $conn = $transaction->connect();
    
    // Fetch alerts ordered by newest first
    $stmt_alerts = $conn->prepare("
        SELECT id, type, message, link, created_at, is_read
        FROM notifications 
        WHERE user_id = :user_id 
        ORDER BY created_at DESC 
        LIMIT 50 
    ");
    $stmt_alerts->execute([':user_id' => $student_id]);
    $notifications = $stmt_alerts->fetchAll(PDO::FETCH_ASSOC);

    // FIX: Calculate unread count by filtering where is_read is 0
    $unread_count = count(array_filter($notifications, fn($n) => $n['is_read'] == 0)); 
    
    // --- 1. Render Mark All Button ---
    if ($unread_count > 0) {
        echo '<div class="mb-3 text-end">';
        // CRITICAL: Button calls the global JS function markAllAsRead()
        echo '  <button type="button" class="btn-mark-all" onclick="markAllAsRead()">';
        echo '      <i class="fas fa-check-double me-1"></i> Mark All ' . $unread_count . ' as Read';
        echo '  </button>';
        echo '</div>';
    } else {
        echo '<div class="mb-3 text-center text-muted small">All caught up! No unread messages.</div>';
    }


    // --- 2. Render HTML Content for the Modal/Overlay ---
    if (!empty($notifications)):
        foreach ($notifications as $n):
            // The is_read status here is based on what the DB returned
            $is_read = $n['is_read'];
            $alert_class = $is_read ? 'alert-read' : 'alert-unread';
            
            // Determine icon and link behavior
            $icon = 'fas fa-info-circle text-secondary'; 
            if (strpos($n['type'], 'approved') !== false || strpos($n['type'], 'good') !== false) {
                // Approved/Good Status: Success Green
                $icon = 'fas fa-check-circle text-success';
            } elseif (strpos($n['type'], 'rejected') !== false || strpos($n['type'], 'damaged') !== false || strpos($n['type'], 'late') !== false || strpos($n['type'], 'overdue') !== false) {
                // Critical/Bad Status: Danger Red
                $icon = 'fas fa-exclamation-triangle text-danger';
            } elseif (strpos($n['type'], 'sent') !== false || strpos($n['type'], 'checking') !== false || strpos($n['type'], 'verification') !== false) {
                // Pending/In-progress Status: Primary Red (Theme Color)
                $icon = 'fas fa-hourglass-half text-primary';
            }
            ?>
            <a href="<?= htmlspecialchars($n['link']) ?>" 
                class="alert-item <?= $alert_class ?> d-flex align-items-start mb-2" 
                data-notification-id="<?= $n['id'] ?>"
                data-is-read="<?= $is_read ?>"
                onclick="markSingleAsRead(event, <?= $n['id'] ?>)">
                <i class="<?= $icon ?> alert-icon me-3 mt-1"></i>
                <div class="alert-body flex-grow-1">
                    <p class="alert-message mb-0"><?= htmlspecialchars($n['message']) ?></p>
                    <small class="alert-timestamp"><?= time_ago($n['created_at']) ?></small>
                </div>
                <?php if (!$is_read): ?>
                <span class="badge ms-2 unread-badge">New</span>
                <?php endif; ?>
            </a>
            <?php
        endforeach;
    else:
        echo '<div class="alert alert-info text-center mt-3" role="alert">You have no recent notifications.</div>';
    endif;

} catch (Exception $e) {
    error_log("Notification content generation error: " . $e->getMessage());
    echo '<div class="alert alert-danger text-center mt-3" role="alert">Error loading notifications.</div>';
}
?>