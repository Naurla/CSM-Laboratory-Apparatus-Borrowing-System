<?php
session_start();
header('Content-Type: application/json');

// Load database connection/Student class
require_once '../classes/Student.php'; 
$student_db = new Student();
$db_conn = $student_db->connect(); 

// Enforce staff authentication
if (!isset($_SESSION['user']) || $_SESSION['user']['role'] !== 'staff') {
    http_response_code(403);
    echo json_encode(['count' => 0, 'notifications' => []]);
    exit;
}

$current_staff_id = $_SESSION['user']['id']; 

$response = [
    'count' => 0,        // Total unread count
    'notifications' => [] 
];

try {
    // Fetch up to 5 most recent unread notifications for the staff user
    $notif_sql = "SELECT id, message, link, created_at FROM notifications 
                  WHERE user_id = :user_id AND is_read = 0 
                  ORDER BY created_at DESC 
                  LIMIT 5";

    $notif_stmt = $db_conn->prepare($notif_sql);
    $notif_stmt->bindParam(":user_id", $current_staff_id, PDO::PARAM_INT);
    $notif_stmt->execute();
    $notifications = $notif_stmt->fetchAll(PDO::FETCH_ASSOC);

    // Set the count and notification data in the response
    $response['count'] = count($notifications);
    $response['notifications'] = $notifications;
    
    // Return the successful JSON response
    echo json_encode($response);

} catch (PDOException $e) {
    // Handle database errors gracefully
    error_log("Alert fetch error: " . $e->getMessage());
    echo json_encode(['count' => 0, 'notifications' => []]);
}

?>