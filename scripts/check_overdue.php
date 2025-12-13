<?php
// WD123/scripts/check_overdue.php

// This script is designed to be run via a cron job/task scheduler, NOT directly in a browser.

// Define the full path to the project root for includes
define('ROOT_PATH', __DIR__ . '/../');

// --- 1. Include necessary classes ---
require_once ROOT_PATH . 'classes/Transaction.php';
require_once ROOT_PATH . 'classes/Mailer.php';
require_once ROOT_PATH . 'vendor/autoload.php'; // Composer Autoloader

// NOTE: You must include database connection setup here as well if the classes don't handle it
// Example: require_once ROOT_PATH . 'classes/database.php';
// $db = new Database(); 
// $transaction = new Transaction($db);
// Assuming Transaction is initialized correctly.

$transaction = new Transaction(/* ... pass DB connection ... */);
$mailer = new Mailer();
$today = date('Y-m-d');

// --- 2. Fetch Overdue Loans (Assuming this method now returns items as well) ---
// Note: You may need to change 'getOverdueLoansForNotification' to 'getOverdueTransactions'
// if the latter includes the item list.
$overdue_loans = $transaction->getOverdueTransactions(); 

if (empty($overdue_loans)) {
    // Keep silent output for cron job success
    exit;
}

// --- 3. Load HTML Template (CRITICAL STEP) ---
// NOTE: Make sure the template path is correct: 'templates/overdue_notice.html'
$template_path = ROOT_PATH . 'templates/overdue_notice.html';
if (!file_exists($template_path)) {
    error_log("CRITICAL ERROR: Overdue email template not found at: {$template_path}");
    exit; 
}
$html_template = file_get_contents($template_path);

// --- 4. Process and Send Notifications ---
foreach ($overdue_loans as $loan) {
    $user_email = $loan['user_email']; // Corrected variable name from transaction object
    $user_name = htmlspecialchars($loan['user_name']); // Corrected variable name from transaction object
    $form_id = $loan['id'];
    $expected_date = $loan['expected_return_date'];
    
    // --- CRITICAL FIX 1: Generate HTML for the Overdue Items Table ---
    $itemTableRowsHtml = '';
    // The 'items' property comes from Transaction::getOverdueTransactions
    foreach ($loan['items'] as $item) { 
        $itemTableRowsHtml .= '
            <tr>
                <td>' . htmlspecialchars($item['apparatus_name']) . '</td>
                <td>' . htmlspecialchars($item['quantity']) . '</td>
            </tr>
        ';
    }

    // --- CRITICAL FIX 2: Define Dynamic Variables and URLs ---
    $dynamic_url = 'http://localhost/wd123/student/student_transaction.php?id=' . $form_id; // FIX: Use dynamic ID
    // IMPORTANT: Replace 'http://localhost/wd123' with your live domain when deployed.

    // --- Dynamic Template Population (Replaced by the logic below) ---
    $placeholders = [
        '{{ USER_NAME }}', 
        '{{ FORM_ID }}', 
        '{{ EXPECTED_DATE }}',
        // NEW PLACEHOLDERS
        '{{ OVERDUE_ITEMS_HTML }}',
        '{{ RETURN_URL }}'
    ];
    $data = [
        $user_name, 
        $form_id, 
        date('F j, Y', strtotime($expected_date)),
        $itemTableRowsHtml, // Inject the generated HTML
        $dynamic_url        // Inject the dynamic URL
    ];
    
    // Perform all replacements
    $body = str_replace($placeholders, $data, $html_template);
    // ---------------------------------------------------------------

    $subject = "🚨 URGENT: Overdue Item Notice - Form ID #{$form_id}";

    // Send the email (now using the populated HTML body)
    $email_sent = $mailer->sendRawEmail($user_email, $subject, $body);

    if ($email_sent) {
        // Log the notification date to prevent duplicates
        $transaction->logOverdueNotice($form_id, $today); 
    } else {
        error_log("Failed to send overdue notice for Form ID {$form_id} to {$user_email}.");
    }
}
?>