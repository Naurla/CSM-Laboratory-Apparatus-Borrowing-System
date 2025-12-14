<?php

define('ROOT_PATH', __DIR__ . '/../');

require_once ROOT_PATH . 'classes/Transaction.php';
require_once ROOT_PATH . 'classes/Mailer.php';
require_once ROOT_PATH . 'vendor/autoload.php'; 



$transaction = new Transaction(/* ... pass DB connection ... */);
$mailer = new Mailer();
$today = date('Y-m-d');


$overdue_loans = $transaction->getOverdueLoansForNotification(); 

if (empty($overdue_loans)) {
    
    exit;
}


$template_path = ROOT_PATH . 'templates/overdue_notice.html';
if (!file_exists($template_path)) {
    error_log("CRITICAL ERROR: Overdue email template not found at: {$template_path}");
    exit; 
}
$html_template = file_get_contents($template_path);


foreach ($overdue_loans as $loan) {
   
    $user_email = $loan['user_email']; 
    $user_name = htmlspecialchars($loan['user_name']); 
    $form_id = $loan['id'];
    $expected_date = $loan['expected_return_date'];
    
    
    $itemTableRowsHtml = '';
   
    foreach ($loan['items'] as $item) { 
        $itemTableRowsHtml .= '
            <tr>
                <td>' . htmlspecialchars($item['name']) . '</td> <td>' . htmlspecialchars($item['quantity']) . '</td>
            </tr>
        ';
    }

    // // --- CRITICAL FIX 2: Define Dynamic Variables and URLs ---
    // $dynamic_url = 'http://localhost/wd123/student/student_transaction.php?id=' . $form_id; // FIX: Use dynamic ID
    // // IMPORTANT: Replace 'http://localhost/wd123' with your live domain when deployed.

  
    $placeholders = [
        '{{ USER_NAME }}', 
        '{{ FORM_ID }}', 
        '{{ EXPECTED_DATE }}',
        
        '{{ OVERDUE_ITEMS_HTML }}',
        '{{ RETURN_URL }}'
    ];
    $data = [
        $user_name, 
        $form_id, 
        date('F j, Y', strtotime($expected_date)),
        $itemTableRowsHtml, 
        $dynamic_url        
    ];
    
    
    $body = str_replace($placeholders, $data, $html_template);
    

    $subject = "🚨 URGENT: Overdue Item Notice - Form ID #{$form_id}";

    
    $email_sent = $mailer->sendRawEmail($user_email, $subject, $body);

    if ($email_sent) {
        
        $transaction->logOverdueNotice($form_id, $today); 
    } else {
        error_log("Failed to send overdue notice for Form ID {$form_id} to {$user_email}.");
    }
}
?>