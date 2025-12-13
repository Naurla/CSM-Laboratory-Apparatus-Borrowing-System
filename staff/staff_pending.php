<?php
// File: staff/staff_pending.php

session_start();
// NOTE: Assuming the correct path to autoload.php is one level up relative to where this file runs from.
require_once '../vendor/autoload.php'; 

require_once "../classes/Database.php";
require_once "../classes/Transaction.php";
require_once "../classes/Mailer.php"; 
require_once "../classes/Student.php"; 

$today = new DateTime(); 
$today->setTime(0, 0, 0); 

if (!isset($_SESSION["user"]) || $_SESSION["user"]["role"] != "staff") {
    header("Location: ../login_signup/login.php");
    exit();
}

$transaction = new Transaction();
$mailer = new Mailer(); 
// NOTE: Assuming Student class has the method getUserById used below.
$student_class = new Student(); 
$message = "";
$is_success = false; 

$db_conn = $transaction->connect(); 
$staff_id = $_SESSION["user"]["id"];

// --- HELPER FUNCTION 1: Mark Staff Notification as Read (For legacy compatibility) ---
function markNotificationAsRead($db_conn, $form_id, $staff_id) {
    // This function is generally deprecated by $transaction->clearNotificationsByFormId
    $form_link_pattern = "%staff_pending.php?view={$form_id}%"; 
    $mark_read_sql = "UPDATE notifications SET is_read = 1 
                      WHERE link LIKE :form_link_pattern 
                      AND user_id = :staff_id";

    $mark_read_stmt = $db_conn->prepare($mark_read_sql);
    $mark_read_stmt->bindParam(":staff_id", $staff_id, PDO::PARAM_INT);
    $mark_read_stmt->bindParam(":form_link_pattern", $form_link_pattern);
    $mark_read_stmt->execute();
}

// --- HELPER FUNCTION 2: Insert a Student Notification (System Alert) ---
function insertStudentNotification($db_conn, $student_id, $type, $msg, $link) {
    $sql = "INSERT INTO notifications (user_id, type, message, link, is_read, created_at) 
            VALUES (:user_id, :type, :message, :link, 0, NOW())";
    try {
        $stmt = $db_conn->prepare($sql);
        $stmt->execute([
            ':user_id' => $student_id,
            ':type' => $type,
            ':message' => $msg,
            ':link' => $link
        ]);
    } catch (PDOException $e) {
        // Log the error but don't stop the main process
        error_log("Failed to insert student notification: " . $e->getMessage());
    }
}
// -----------------------------------------------------


if ($_SERVER["REQUEST_METHOD"] === "POST") {
    $form_id = $_POST["form_id"];
    $remarks = $_POST["staff_remarks"] ?? ''; 
    
    // --- CRITICAL PRE-FETCH: Get Form and Student Details ---
    // This uses the improved getBorrowFormById (fixed date selection)
    $form_data = $transaction->getBorrowFormById($form_id); 
    
    if (!$form_data) {
        $_SESSION['status_message'] = "❌ Error: Could not find transaction ID {$form_id}.";
        $_SESSION['is_success'] = false;
        header("Location: staff_pending.php");
        exit;
    }
    
    $student_details = $student_class->getUserById($form_data['user_id']); 
    $student_email = $student_details['email'] ?? null;
    $student_id_to_notify = $form_data['user_id'];
    $student_name = $student_details['firstname'] ?? 'Borrower';
    $student_link = "../student/student_view_items.php?form_id={$form_id}&context=dashboard";

    // --- NEW: FETCH DATES AND ITEM LIST FOR MAILER ---
    // Ensure these variables are populated from the fixed $form_data
    $request_date = $form_data['request_date'] ?? date('Y-m-d');
    $expected_return_date = $form_data['expected_return_date'] ?? date('Y-m-d');
    $item_list_for_email = $transaction->getFormItemsForEmail($form_id);
    // ----------------------------------------------------


    if (isset($_POST["approve"])) {
        
        $result = $transaction->approveForm($form_id, $staff_id, $remarks);
        
        if ($result === true) {
            // CRITICAL FIX: Clear the notification for this form_id (using the central method)
            $transaction->clearNotificationsByFormId($form_id); 
            
            // NOTE: The actual sendTransactionStatusEmail for 'approved' is handled INSIDE Transaction::approveForm. 
            
            $message = "✅ Borrow request approved successfully! Items marked as borrowed.";
            $is_success = true;
        } elseif ($result === 'stock_mismatch_on_approval') {
            $message = "❌ Approval Failed: Stock was depleted before approval could be finalized. Please review the item availability.";
            $is_success = false;
        } else {
            // This captures the "database error during finalization" from Transaction::approveForm
            $message = "❌ Approval Failed: A database error occurred during finalization.";
            $is_success = false;
        }
        
    } elseif (isset($_POST["reject"])) {
        $transaction->rejectForm($form_id, $staff_id, $remarks);
        
        // CRITICAL FIX: Clear the notification for this form_id
        $transaction->clearNotificationsByFormId($form_id); 
        
        $message = "Borrow request rejected.";
        $is_success = true;

        // --- EMAIL ONLY (Using the consistently fetched date variables) ---
        if ($student_email) {
            $mailer->sendTransactionStatusEmail(
                $student_email, 
                $student_name, 
                $form_id, 
                'rejected', 
                $remarks, 
                $request_date, 
                $expected_return_date, 
                '', // No approval date for rejection
                $item_list_for_email
            );
        }
        // ------------------
        
    } elseif (isset($_POST["approve_return"])) {
        $result = $transaction->confirmReturn($form_id, $staff_id, $remarks);
        if ($result === true) {
            
            // CRITICAL FIX: Clear the notification for this form_id
            $transaction->clearNotificationsByFormId($form_id); 
            
            // --- EMAIL ONLY (Using the consistently fetched date variables) ---
            if ($student_email) {
                // Since this is confirming return (good condition), we use the actual return date as approval date
                $actual_return_date = date('Y-m-d');
                $mailer->sendTransactionStatusEmail(
                    $student_email, 
                    $student_name, 
                    $form_id, 
                    'returned', // Using 'returned' status for the email template
                    $remarks, 
                    $request_date, // ADDED
                    $expected_return_date, // ADDED
                    $actual_return_date, // Using actual return date here
                    $item_list_for_email // ADDED
                );
            }
            // ------------------
            
            $message = "✅ Return verified and marked as returned.";
            $is_success = true;
        } else { $message = "❌ Failed to confirm return due to a database error."; $is_success = false; }

    } elseif (isset($_POST["confirm_late_return"])) {
        if ($form_data) {
            $expected_return_date_dt = new DateTime($form_data["expected_return_date"]);
            $expected_return_date_dt->setTime(0, 0, 0); 
            
            if ($expected_return_date_dt < $today) { 
                $result = $transaction->confirmLateReturn($form_id, $staff_id, $remarks);
                if ($result === true) {
                    
                    // CRITICAL FIX: Clear the notification for this form_id
                    $transaction->clearNotificationsByFormId($form_id); 
                    
                    $message = "✅ Late return confirmed and status finalized as RETURNED (Penalty Applied).";
                    $is_success = true;
                    
                    // --- STUDENT NOTIFICATION & EMAIL (Using the consistently fetched date variables) ---
                    insertStudentNotification($db_conn, $student_id_to_notify, 'return_late', "Your late return for request #{$form_id} was confirmed.", $student_link); 
                    if ($student_email) {
                        $actual_return_date = date('Y-m-d');
                        $mailer->sendTransactionStatusEmail(
                            $student_email, 
                            $student_name, 
                            $form_id, 
                            'returned', // Using 'returned' status for the email template
                            $remarks . " (Note: This was a late return.)",
                            $request_date, // ADDED
                            $expected_return_date, // ADDED
                            $actual_return_date, // Using actual return date here
                            $item_list_for_email // ADDED
                        );
                    }
                    // ------------------------------------
                } else { $message = "❌ Failed to confirm late return due to a database error."; $is_success = false; }
            } else { $message = "❌ Error: Cannot manually mark as LATE RETURN before the expected return date."; $is_success = false; }
        } else { $message = "❌ Error: Form ID not found."; $is_success = false; }
        
    } elseif (isset($_POST["mark_damaged"])) {
        $unit_id = $_POST["damaged_unit_id"] ?? null; 
        
        if ($unit_id) {
            $result = $transaction->markAsDamaged($form_id, $staff_id, $remarks, $unit_id);
            if ($result === true) {
                
                // CRITICAL FIX: Clear the notification for this form_id
                $transaction->clearNotificationsByFormId($form_id); 
                
                $message = "✅ Marked as returned with issues. Damaged unit status updated.";
                $is_success = true;
                
                // --- STUDENT NOTIFICATION & EMAIL (Using the consistently fetched date variables) ---
                insertStudentNotification($db_conn, $student_id_to_notify, 'return_damaged', "A unit from request #{$form_id} was marked damaged/returned with issues.", $student_link);
                if ($student_email) {
                    $actual_return_date = date('Y-m-d');
                    $mailer->sendTransactionStatusEmail(
                        $student_email, 
                        $student_name, 
                        $form_id, 
                        'damaged', // Using 'damaged' status for the email template
                        $remarks,
                        $request_date, // ADDED
                        $expected_return_date, // ADDED
                        $actual_return_date, // Using actual return date here
                        $item_list_for_email // ADDED
                    );
                }
                // ------------------------------------
            } else { $message = "❌ Failed to mark as damaged due to a database error."; $is_success = false; }
        } else { $message = "❌ Error: Please select a specific item unit to mark as damaged."; $is_success = false; }

    } elseif (isset($_POST["manually_mark_overdue"])) {
        if ($form_data) {
            $expected_return_date_dt = new DateTime($form_data["expected_return_date"]);
            $expected_return_date_dt->setTime(0, 0, 0); 
            
            if ($expected_return_date_dt < $today) {
                $result = $transaction->markAsOverdue($form_id, $staff_id, $remarks);
                if ($result === true) {
                    
                    // CRITICAL FIX: Clear the notification for this form_id
                    $transaction->clearNotificationsByFormId($form_id); 
                    
                    $message = "✅ Marked as overdue (Units restored & ban checked).";
                    $is_success = true;

                    // --- STUDENT NOTIFICATION & EMAIL (Using the consistently fetched date variables) ---
                    insertStudentNotification($db_conn, $student_id_to_notify, 'form_overdue', "Your request #{$form_id} was marked OVERDUE. Your account is suspended.", $student_link);
                    if ($student_email) {
                        $mailer->sendTransactionStatusEmail(
                            $student_email, 
                            $student_name, 
                            $form_id, 
                            'overdue', // Using 'overdue' status for the email template
                            $remarks,
                            $request_date, // ADDED
                            $expected_return_date, // ADDED
                            '', // No approval date for overdue
                            $item_list_for_email // ADDED
                        );
                    }
                    // ------------------------------------
                } else { $message = "❌ Failed to mark as overdue due to a database error."; $is_success = false; }
            } else { $message = "❌ Error: Cannot manually mark as overdue before the expected return date."; $is_success = false; }
        } else { $message = "❌ Error: Form ID not found."; $is_success = false; }
    }

    $_SESSION['status_message'] = $message;
    $_SESSION['is_success'] = $is_success;
    
    // START FIX: Use cache buster in the redirection URL
    header("Location: staff_pending.php?_t=" . time()); 
    // END FIX
    exit;
}


if (isset($_SESSION['status_message'])) {
    $message = $_SESSION['status_message'];
    $is_success = $_SESSION['is_success'] ?? false;
    unset($_SESSION['status_message']);
    unset($_SESSION['is_success']);
}

$pendingForms = $transaction->getPendingForms();
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Staff Pending Forms</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0-beta3/css/all.min.css" rel="stylesheet">
    <script src="https://code.jquery.com/jquery-3.6.0.min.js"></script> 
    <style>

        :root {
            --msu-red: #A40404; /* FIXED: Consistent Red */
            --msu-red-dark: #820303; /* FIXED: Consistent Dark Red */
            --sidebar-width: 280px; 
            --student-logout-red: #dc3545;
            --base-font-size: 15px; /* Base size for overall clarity */
            --header-height: 60px; /* Top Bar reference */
            --main-text: #333; /* Added for consistency */
            --card-background: #fcfcfc; /* New: for mobile card background */
            --label-bg: #e9ecef; /* Light gray background for mobile labels */
        }

        body { 
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif; 
            background: #f5f6fa;
            min-height: 100vh;
            display: flex; 
            padding: 0;
            margin: 0;
            font-size: var(--base-font-size);
            overflow-x: hidden; /* Prevent page-level scrollbar */
        }
        
        /* NEW CSS for Mobile Toggle */
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
        }
        
        /* --- Top Header Bar Styles (UPDATED: Removed profile spacing from container) --- */
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
            margin: 0; /* Removed fixed margin-right */
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
        /* Removed .edit-profile-link styling */
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
            padding: 25px 15px; /* Increased padding */
            font-size: 1.3rem;
            font-weight: 700;
            line-height: 1.2;
            color: #fff;
            border-bottom: 1px solid rgba(255, 255, 255, 0.4);
            margin-bottom: 25px; /* Increased margin */
        }

        .sidebar-header img {
            max-width: 100px; /* Increased size */
            height: auto;
            margin-bottom: 15px;
        }
        
        .sidebar-header .title {
            font-size: 1.4rem; /* Increased size */
            line-height: 1.1;
        }
        
        .sidebar-nav {
            flex-grow: 1; 
        }

        .sidebar-nav .nav-link {
            color: white;
            padding: 18px 25px; /* Increased padding/size */
            font-size: 1.05rem; /* Increased size */
            font-weight: 600;
            transition: background-color 0.2s;
        }

        .sidebar-nav .nav-link:hover {
            background-color: var(--msu-red-dark);
        }
        .sidebar-nav .nav-link.active {
            background-color: var(--msu-red-dark);
        }
        
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
            background-color: #C62828 !important; /* FIXED: Consistent base color */
            color: white !important;
            padding: 18px 25px; 
            border-radius: 0; 
            text-decoration: none;
            font-weight: 600; 
            font-size: 1.05rem; 
            transition: background 0.3s;
        }
        .logout-link .nav-link:hover {
            background-color: var(--msu-red-dark) !important; /* FIXED: Consistent hover color */
        }

        .main-content {
            margin-left: var(--sidebar-width); 
            flex-grow: 1;
            padding: 30px;
            padding-top: calc(var(--header-height) + 30px); /* Adjusted for fixed header */
            width: calc(100% - var(--sidebar-width)); 
        }
        .content-area {
            background: #fff; 
            border-radius: 12px; 
            padding: 30px; 
            box-shadow: 0 5px 15px rgba(0,0,0,0.1);
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
            overflow-x: auto; 
            box-shadow: 0 2px 10px rgba(0,0,0,0.05);
            margin-top: 25px; 
        }
        .table thead th {
            background: var(--msu-red);
            color: white;
            font-weight: 700; 
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
        
        /* Table column width constraints */
        .table th:nth-child(2), .table td:nth-child(2) { min-width: 150px; } 
        .table th:nth-child(3), .table td:nth-child(3) { min-width: 150px; } 
        .table th:nth-child(9), .table td:nth-child(9) { min-width: 170px; } 
        .table th:nth-child(10), .table td:nth-child(10) { min-width: 110px; } 


        td form {
            margin: 0;
            padding: 0;
            display: inline-block; 
        }
        textarea, select {
            width: 100%; 
            max-width: 170px;
            margin: 5px 0;
            resize: none;
            font-size: 0.9rem; 
            border-radius: 4px;
            border: 1px solid #ccc;
        }
        td.remarks-cell {
            min-width: 180px; 
            text-align: left;
            padding: 8px 10px; 
        }
        td.actions-cell {
            min-width: 120px; 
        }
        
        
        .btn-group-vertical {
            display: flex;
            flex-direction: column;
            gap: 5px;
            margin-top: 5px;
        }
        .btn {
            padding: 8px 10px; 
            border: none;
            border-radius: 5px;
            cursor: pointer;
            font-size: 0.9rem; 
            font-weight: 600;
            color: white;
            transition: background 0.2s;
        }
        /* Button colors (unchanged) */
        .btn.approve { background: #28a745; }
        .btn.reject { background: #dc3545; }
        .btn.return { background: #17a2b8; }
        .btn.warning { background: #ffc107; color: black; }
        .btn.secondary { background: #6c757d; }

        .status-tag {
            display: inline-block; padding: 5px 10px; border-radius: 14px; font-weight: 700; text-transform: capitalize; font-size: 0.85rem; line-height: 1.2; white-space: nowrap;
        }
        .status-tag.waiting_for_approval { background-color: #ffc10740; color: #b8860b; }
        .status-tag.checking { background-color: #007bff30; color: #0056b3; }
        
        
        /* Modal Custom Style for Warning (unchanged) */
        #lateReturnModal .modal-header, #requiredUnitSelectModal .modal-header {
            background-color: #ffc107; 
            color: #333;
            border-bottom: none;
        }
        #lateReturnModal .modal-title, #requiredUnitSelectModal .modal-title {
            font-weight: bold;
        }
        #lateReturnModal .modal-body, #requiredUnitSelectModal .modal-body {
            color: #666;
        }
        #lateReturnModal .btn-danger, #requiredUnitSelectModal .btn-danger {
            background-color: #dc3545;
            border-color: #dc3545;
        }
        
        /* --- RESPONSIVE CSS --- */
        
        @media (max-width: 992px) {
            /* Enable mobile toggle and shift main content */
            .menu-toggle { display: block; }
            .sidebar { left: calc(var(--sidebar-width) * -1); transition: left 0.3s ease; box-shadow: none; --sidebar-width: 250px; } 
            .sidebar.active { left: 0; box-shadow: 2px 0 5px rgba(0, 0, 0, 0.2); }
            .main-content { margin-left: 0; padding-left: 15px; padding-right: 15px; padding-top: calc(var(--header-height) + 15px); }
            .top-header-bar { left: 0; padding-left: 70px; padding-right: 15px; }
            .content-area { padding: 20px 15px; }
            .page-header { font-size: 1.8rem; }
        }

        @media (max-width: 768px) {
            /* Full mobile table stacking */
            .table { min-width: auto; } /* Disable horizontal scrolling */
            .table thead { display: none; } 
            .table tbody, .table tr, .table td { display: block; width: 100%; }
            
            .table tr {
                margin-bottom: 15px; 
                border: 1px solid #ccc;
                border-left: 5px solid var(--msu-red); 
                border-radius: 8px; 
                background-color: #fcfcfc; 
                box-shadow: 0 2px 4px rgba(0, 0, 0, 0.05); 
                padding: 0; 
                overflow: hidden;
            }
            
            .table td {
                text-align: right !important; 
                padding-left: 50% !important;
                position: relative;
                border: none;
                border-bottom: 1px solid #eee;
                padding: 10px 10px !important; 
            }
            .table td:last-child { border-bottom: none; }

            /* --- Label Styling (Clean and Clear) --- */
            .table td::before {
                content: attr(data-label);
                position: absolute;
                left: 0; 
                width: 50%;
                height: 100%;
                padding: 10px;
                white-space: nowrap;
                text-align: left;
                font-weight: 600;
                color: var(--main-text); 
                font-size: 0.9rem;
                background-color: var(--label-bg); 
                border-right: 1px solid #ddd;
                display: flex;
                align-items: center;
            }

            /* --- Header Grouping (Form ID, Student Details, Status) --- */
            
            .table tbody tr td:nth-child(1) { /* Form ID */
                text-align: left !important;
                padding: 10px !important;
                font-size: 0.9rem;
                font-weight: 600;
                color: #6c757d;
                border-bottom: 1px solid #ddd;
            }
            .table tbody tr td:nth-child(1)::before {
                content: "Form "; 
                background: none;
                border: none;
                color: #6c757d;
                font-size: 0.9rem;
                padding: 0;
                position: static;
                width: auto;
                height: auto;
            }
            
            .table tbody tr td:nth-child(2) { /* Student Details - Primary visual item */
                font-size: 1.1rem;
                font-weight: 700;
                color: var(--msu-red-dark);
                border-bottom: 2px solid var(--msu-red);
            }
            .table tbody tr td:nth-child(2)::before {
                content: "Borrower";
                background-color: #f8d7da; /* Very light red background */
                color: var(--msu-red-dark);
                font-weight: 700;
            }
            
            .table tbody tr td:nth-child(4) { /* Status - Special treatment */
                 font-weight: 700;
            }
            
            /* --- Remarks & Actions Grouping --- */
            
            .table tbody tr td:nth-child(8), /* Student Remarks */
            .table tbody tr td:nth-child(9) { /* Staff Remarks/Unit Select */
                padding-left: 10px !important; 
                text-align: left !important;
                font-size: 0.9rem;
            }
            .table tbody tr td:nth-child(8)::before, 
            .table tbody tr td:nth-child(9)::before {
                position: static;
                width: 100%;
                height: auto;
                background: #f8f8f8;
                border-right: none;
                border-bottom: 1px solid #eee;
                margin-bottom: 5px;
                display: block;
                padding: 10px;
                text-align: left;
            }

            .table tbody tr td:nth-child(10) { /* Actions cell - Last block */
                border-bottom: none;
            }
            
            /* Action Button Grouping: Stacked for best touch interaction */
            .actions-cell {
                padding: 10px 10px 15px 10px !important; 
            }
            .btn-group-vertical {
                flex-direction: column;
                gap: 8px;
                width: 100%;
            }
            .btn-group-vertical button, .btn-group-vertical a {
                width: 100%;
            }
        }

        @media (max-width: 576px) {
            /* Smallest screen adjustments */
            .main-content { padding: 10px; padding-top: calc(var(--header-height) + 10px); }
            .content-area { padding: 10px; }
            .top-header-bar { padding-left: 65px; }
            .table tbody tr td:nth-child(2) { font-size: 0.9rem; } /* Shrink font a bit more */
            .table td::before { font-size: 0.85rem; }
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
        <a class="nav-link" href="staff_dashboard.php">
            <i class="fas fa-chart-line fa-fw me-2"></i>Dashboard
        </a>
        <a class="nav-link" href="staff_apparatus.php">
            <i class="fas fa-vials fa-fw me-2"></i>Apparatus List
        </a>
        <a class="nav-link active" href="staff_pending.php">
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
            <i class="fas fa-hourglass-half fa-fw me-2 text-secondary"></i> Pending Requests & Returns
        </h2>

        <?php if (!empty($message)): ?>
            <div id="status-alert" class="alert <?= $is_success ? 'alert-success' : ((strpos($message, '❌') !== false) ? 'alert-danger' : 'alert-warning') ?> alert-dismissible fade show" role="alert">
                <?= htmlspecialchars($message) ?>
                <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
            </div>
        <?php endif; ?>

        <div class="table-responsive">
            <table class="table table-striped table-hover align-middle">
                <thead>
                    <tr>
                        <th>Form ID</th>
                        <th>Student Details</th> 
                        <th>Apparatus (First Item)</th>
                        <th><i class="fas fa-info-circle"></i> Status</th>
                        <th>Borrow Date</th>
                        <th>Expected Return</th>
                        <th>Actual Return</th>
                        <th>Student Remarks</th> <th>Staff Remarks</th>
                        <th>Actions</th>
                    </tr>
                </thead>
                <tbody>
                <?php if (!empty($pendingForms)): ?>
                    <?php foreach ($pendingForms as $form): 
                        $clean_status = strtolower($form["status"]);
                        $display_status = ucfirst(str_replace('_', ' ', $clean_status));
                        
                        // Fetch the full form details to get the student's remarks 
                        // Note: $full_form_data['staff_remarks'] holds the student's message if status is 'checking'
                        $full_form_data = $transaction->getBorrowFormById($form["id"]); 
                        
                        // FIX 1: Display student remarks by pulling from the staff_remarks column (since there is no student_remarks column).
                        $student_remarks = ($clean_status === 'checking') ? 
                                                 ($full_form_data['staff_remarks'] ?? '-') : 
                                                 'N/A';
                        
                        // Fetch unit-level items for the "Mark Damaged" dropdown
                        $items = $transaction->getTransactionItems($form["id"]);
                        
                        // Check if item is overdue (for button logic)
                        $today_dt = new DateTime();
                        $today_dt->setTime(0, 0, 0); // *** CRITICAL: Normalized $today to midnight ***

                        $expected_return = new DateTime($form["expected_return_date"]);
                        $expected_return->setTime(0, 0, 0); // Normalized Expected Return Date to midnight
                        
                        // CRITICAL: The item is past due if expected date is BEFORE today.
                        $is_currently_overdue = ($expected_return < $today_dt);
                        
                        // NEW LOGIC: Is the item past the 1-day grace period? (i.e., today is >= Expected Return Date + 2 days)
                        $grace_period_end_date = (clone $expected_return)->modify('+1 day'); 
                        $is_ban_eligible_now = ($today_dt > $grace_period_end_date); // True if today is strictly AFTER the grace period end (2 days past due)


                    ?>
                        <tr>
                            <td data-label="Form ID:"><?= htmlspecialchars($form["id"]) ?></td>
                            <td data-label="Student Details:">
                                <strong><?= htmlspecialchars($form["firstname"] ?? '') ?> <?= htmlspecialchars($form["lastname"] ?? '') ?></strong>
                                <br>
                                <small class="text-muted">(ID: <?= htmlspecialchars($form["borrower_id"]) ?>)</small>
                            </td>
                            <td data-label="Apparatus (First Item):"><?= htmlspecialchars($form["apparatus_list"] ?? '-') ?></td> 
                            <td data-label="Status:">
                                <span class="status-tag <?= $clean_status ?>">
                                    <?= $display_status ?>
                                </span>
                            </td>
                            <td data-label="Borrow Date:"><?= htmlspecialchars($form["borrow_date"] ?? '-') ?></td>
                            <td data-label="Expected Return:"><?= htmlspecialchars($form["expected_return_date"] ?? '-') ?></td>
                            <td data-label="Actual Return:"><?= htmlspecialchars($form["actual_return_date"] ?? '-') ?></td>
                            
                            <td data-label="Student Remarks:"><?= htmlspecialchars($student_remarks) ?></td>

                            <form method="POST" class="pending-form" data-form-id="<?= htmlspecialchars($form["id"]) ?>">
                                <td data-label="Staff Remarks:" class="remarks-cell">
                                    <?php if ($clean_status == "checking" || $clean_status == "waiting_for_approval"): ?>
                                                   <textarea name="staff_remarks" rows="2" placeholder="Enter staff remarks..."></textarea>
                                    <?php else: ?>
                                                   -
                                    <?php endif; ?>
                                    <input type="hidden" name="form_id" value="<?= htmlspecialchars($form["id"]) ?>">
                                    <input type="hidden" name="action_type" value=""> 
                                    
                                    <?php if ($clean_status == "checking"): ?>
                                        <div class="mt-2 text-start">
                                            <label for="damaged_unit_id_<?= $form['id'] ?>" class="fw-bold mb-1">Mark Damaged Unit:</label>
                                            <select name="damaged_unit_id" id="damaged_unit_id_<?= $form['id'] ?>" class="form-select-sm">
                                                <option value="">-- None / All Good --</option>
                                                <?php
                                                // FIX: Iterate through unit-level items. The Transaction method now returns 'unit_id' and 'name'.
                                                foreach ($items as $item):
                                                ?>
                                                     <option value="<?= htmlspecialchars($item["unit_id"]) ?>">
                                                         <?= htmlspecialchars($item["name"]) ?> (Unit ID: <?= htmlspecialchars($item["unit_id"]) ?>)
                                                     </option>
                                                <?php endforeach; ?>
                                            </select>
                                        </div>
                                    <?php endif; ?>
                                </td>

                                <td data-label="Actions:" class="actions-cell">
                                    <div class="btn-group-vertical">
                                    <?php if ($clean_status == "waiting_for_approval"): ?>
                                        <button type="submit" name="approve" class="btn approve">Approve</button>
                                        <button type="submit" name="reject" class="btn reject">Reject</button>

                                    <?php elseif ($clean_status == "checking"): ?>
                                        <?php if ($is_currently_overdue): ?>
                                            <button type="button" 
                                                class="btn secondary late-return-btn" 
                                                data-bs-toggle="modal" 
                                                data-bs-target="#lateReturnModal"
                                                data-form-id="<?= htmlspecialchars($form["id"]) ?>">
                                                Confirm LATE Return
                                            </button>
                                        <?php else: ?>
                                            <button type="submit" name="approve_return" class="btn approve">Mark Returned (Good)</button>
                                        <?php endif; ?>

                                        <button type="submit" name="mark_damaged" id="mark_damaged_btn_<?= $form['id'] ?>" class="btn warning mark-damaged-btn">Returned with Issues</button>

                                    <?php elseif ($clean_status == "borrowed" && $is_ban_eligible_now): ?>
                                        <button type="button" 
                                            class="btn reject overdue-btn"
                                            data-bs-toggle="modal" 
                                            data-bs-target="#overdueModal"
                                            data-form-id="<?= htmlspecialchars($form["id"]) ?>">
                                            Manually Mark OVERDUE
                                        </button>
                                            
                                    <?php else: ?>
                                        <button type="button" class="btn secondary" disabled>No Action Needed</button>
                                    <?php endif; ?>
                                    </div>
                                </td>
                            </form>
                        </tr>
                    <?php endforeach; ?>
                <?php else: ?>
                    <tr><td colspan="10">No pending or checking forms found.</td></tr> 
                <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<div class="modal fade" id="lateReturnModal" tabindex="-1" aria-labelledby="lateReturnModalLabel" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title" id="lateReturnModalLabel"><i class="fas fa-exclamation-triangle me-2"></i> WARNING: LATE RETURN</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body">
                This item is **LATE**. Confirming LATE RETURN will:
                <ul>
                    <li>Mark the item status as **RETURNED**.</li>
                    <li>**Clear** any existing student bans related to this transaction.</li>
                    <li>Set the internal **LATE RETURN flag** for penalty tracking.</li>
                    
                </ul>
                <p class="text-danger fw-bold mb-0">Please ensure the items are accounted for before proceeding.</p>
                <input type="hidden" id="modal_late_return_form_id">
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                <button type="button" class="btn btn-danger" id="confirmLateReturnBtn">Confirm LATE RETURN</button>
            </div>
        </div>
    </div>
</div>

<div class="modal fade" id="overdueModal" tabindex="-1" aria-labelledby="overdueModalLabel" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content">
            <div class="modal-header bg-danger text-white">
                <h5 class="modal-title" id="overdueModalLabel"><i class="fas fa-exclamation-circle me-2"></i> MANUAL OVERDUE WARNING</h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body">
                You are about to **MANUALLY MARK** this transaction as **OVERDUE**. This action:
                <ul>
                    <li>Applies a **ban** to the borrowing student.</li>
                    <li>**Restores units** to the inventory (assuming loss/missing).</li>
                    <li>Is typically used ONLY for **missing items** that were not returned past the due date.</li>
                </ul>
                <p class="text-danger fw-bold mb-0">Use this action with extreme caution.</p>
                <input type="hidden" id="modal_overdue_form_id">
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                <button type="button" class="btn btn-danger" id="confirmOverdueBtn">Mark as OVERDUE</button>
            </div>
        </div>
    </div>
</div>

<div class="modal fade" id="requiredUnitSelectModal" tabindex="-1" aria-labelledby="requiredUnitSelectModalLabel" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title" id="requiredUnitSelectModalLabel"><i class="fas fa-hand-paper me-2"></i> Action Required</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body text-center">
                <p class="fw-bold text-danger">You must select a specific apparatus unit from the dropdown to mark as 'Returned with Issues'.</p>
                <p class="text-muted">If all returned items are in good condition, please use the 'Mark Returned (Good)' button.</p>
                <input type="hidden" id="form_to_submit_after_error_fix">
            </div>
            <div class="modal-footer justify-content-center">
                <button type="button" class="btn btn-warning" data-bs-dismiss="modal">OK, I Understand</button>
            </div>
        </div>
    </div>
</div>


<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
<script>
    // --- JAVASCRIPT FOR STAFF NOTIFICATION LOGIC (FIXED) ---

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
        
        // Mobile Toggle Logic
        const menuToggle = document.getElementById('menuToggle');
        const sidebar = document.querySelector('.sidebar');
        const mainContent = document.querySelector('.main-content'); 

        if (menuToggle && sidebar) {
            menuToggle.addEventListener('click', () => {
                sidebar.classList.toggle('active');
                if (sidebar.classList.contains('active')) {
                     mainContent.addEventListener('click', closeSidebarOnce);
                } else {
                     mainContent.removeEventListener('click', closeSidebarOnce);
                }
            });
            
            function closeSidebarOnce() {
                 sidebar.classList.remove('active');
                 mainContent.removeEventListener('click', closeSidebarOnce);
            }
            
            const navLinks = sidebar.querySelectorAll('.nav-link');
            navLinks.forEach(link => {
                 link.addEventListener('click', () => {
                     if (window.innerWidth <= 992) {
                         sidebar.classList.remove('active');
                     }
                 });
            });
        }
        
        
        // Initial fetch on page load
        fetchStaffNotifications();
        
        // Poll the server every 30 seconds for new alerts
        setInterval(fetchStaffNotifications, 30000); 

        // --- Modal Logic for LATE RETURN ---
        const lateReturnModal = document.getElementById('lateReturnModal');
        if (lateReturnModal) {
            lateReturnModal.addEventListener('show.bs.modal', function (event) {
                const button = event.relatedTarget;
                const formId = button.getAttribute('data-form-id');
                const modalFormIdInput = lateReturnModal.querySelector('#modal_late_return_form_id');
                modalFormIdInput.value = formId;
            });
            
            // *** CRITICAL FIX: Ensure the correct form is submitted with the action flag ***
            $('#confirmLateReturnBtn').on('click', function() {
                const formId = $('#modal_late_return_form_id').val();
                const $formToSubmit = $(`form.pending-form[data-form-id="${formId}"]`);
                
                if ($formToSubmit.length) {
                    // 1. Add the action flag input to the form
                    if ($formToSubmit.find('input[name="confirm_late_return"]').length === 0) {
                        $formToSubmit.append('<input type="hidden" name="confirm_late_return" value="1">');
                    }
                    
                    // 2. Submit the form using the native DOM submit method for reliability
                    $formToSubmit[0].submit();
                    
                    // 3. Hide the modal immediately after submit is triggered
                    $('#lateReturnModal').modal('hide'); 
                } else {
                    console.error(`Form with ID ${formId} not found for late return submission.`);
                     $('#lateReturnModal').modal('hide'); 
                }
            });
        }
        
        // --- Modal Logic for MANUAL OVERDUE ---
        const overdueModal = document.getElementById('overdueModal');
        if (overdueModal) {
            overdueModal.addEventListener('show.bs.modal', function (event) {
                const button = event.relatedTarget;
                const formId = button.getAttribute('data-form-id');
                const modalFormIdInput = overdueModal.querySelector('#modal_overdue_form_id');
                modalFormIdInput.value = formId;
            });
            
            document.getElementById('confirmOverdueBtn').addEventListener('click', function() {
                const formId = document.getElementById('modal_overdue_form_id').value;
                const formToSubmit = document.querySelector(`form.pending-form[data-form-id="${formId}"]`);
                
                if (formToSubmit) {
                    const overdueActionInput = document.createElement('input');
                    overdueActionInput.type = 'hidden';
                    overdueActionInput.name = 'manually_mark_overdue';
                    overdueActionInput.value = '1';
                    formToSubmit.appendChild(overdueActionInput);
                    
                    formToSubmit.submit();
                }
            });
        }
    });
</script>
</body>
</html>