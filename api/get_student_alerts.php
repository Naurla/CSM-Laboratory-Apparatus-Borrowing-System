<?php
// Set up session and response header
session_start();
header('Content-Type: application/json');

// Load database connection class
require_once '../classes/Database.php'; 
$db = new Database(); 
$db_conn = $db->connect(); 

// Enforce student authentication
if (!isset($_SESSION['user']) || $_SESSION['user']['role'] !== 'student') {
    http_response_code(403);
    echo json_encode(['count' => 0, 'notifications' => []]);
    exit;
}

$current_student_id = $_SESSION['user']['id']; 

$response = [
    'count' => 0,        // Count of unread notifications
    'notifications' => [] // List of notifications
];

try {
    // Fetch up to 5 most recent unread notifications for the student
    $notif_sql = "SELECT message, link, created_at FROM notifications 
                  WHERE user_id = :user_id AND is_read = 0 
                  ORDER BY created_at DESC 
                  LIMIT 5";

    $notif_stmt = $db_conn->prepare($notif_sql);
    $notif_stmt->bindParam(":user_id", $current_student_id, PDO::PARAM_INT);
    $notif_stmt->execute();
    $notifications = $notif_stmt->fetchAll(PDO::FETCH_ASSOC);

    // Set the count and notification data in the response
    $response['count'] = count($notifications);
    $response['notifications'] = $notifications;
    
    // Return the successful JSON response
    echo json_encode($response);

} catch (PDOException $e) {
    // Handle database errors
    error_log("Student Alert fetch error: " . $e->getMessage());
    echo json_encode(['count' => 0, 'notifications' => []]);
}
?>