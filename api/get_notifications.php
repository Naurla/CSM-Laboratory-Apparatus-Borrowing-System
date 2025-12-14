<?php
session_start();
// Load Transaction class
require_once "../classes/Transaction.php";

header('Content-Type: application/json');

// Check for user authentication
if (!isset($_SESSION["user"])) {
    http_response_code(401);
    echo json_encode(['error' => 'Authentication required.']);
    exit();
}

$user_id = $_SESSION["user"]["id"];
$user_role = $_SESSION["user"]["role"];

$transaction = new Transaction(); // Instantiate Transaction object

try {
    // Get the total count of unread notifications for the user
    $unread_count = $transaction->getUnreadNotificationCount($user_id);

    // Prepare to fetch the latest notifications
    $conn = $transaction->connect();
    $stmt_alerts = $conn->prepare("
        SELECT id, type, message, link, created_at, is_read
        FROM notifications 
        WHERE user_id = :user_id 
        ORDER BY created_at DESC 
        LIMIT 10
    ");
    $stmt_alerts->execute([':user_id' => $user_id]);
    $alerts = $stmt_alerts->fetchAll(PDO::FETCH_ASSOC);

    // Return success response with count and alerts
    echo json_encode([
        'success' => true,
        'count' => (int)$unread_count,
        'alerts' => $alerts
    ]);

} catch (Exception $e) {
    // Handle database or execution errors
    http_response_code(500);
    error_log("Notification API Error: " . $e->getMessage());
    echo json_encode(['error' => 'Database error.']);
}

?>