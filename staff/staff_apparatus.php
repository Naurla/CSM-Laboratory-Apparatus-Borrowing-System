<?php
// staff_apparatus.php
session_start();
// Include the Transaction class and file system functions
require_once "../classes/Transaction.php";
require_once "../classes/Database.php"; // Added for dependency if Transaction uses it

// Function to safely delete an image file (helper)
function deleteApparatusImage($imageName) {
    if ($imageName && $imageName !== "default.jpg") {
        $upload_dir = "../uploads/apparatus_images/";
        $target_path = $upload_dir . $imageName;
        if (file_exists($target_path)) {
            // Suppress errors with @in case of permission issues
            @unlink($target_path); 
        }
    }
}


if (!isset($_SESSION['user']) || $_SESSION['user']['role'] !== 'staff') {
    header("Location: ../pages/login.php");
    exit;
}

$transaction = new Transaction();

$errors = [];
$message = "";

// Initialize values
$name = $type = $size = $material = $description = $total_stock = $damaged_stock = $lost_stock = ""; 
$edit_id = $_GET['edit_id'] ?? null; // ID of the apparatus being edited
$current_image = 'default.jpg'; // Initialize current image

// Handle Form Submissions (Add, Edit, Quick Update, Delete, RESTORE UNIT) ---

// 1. Handle form submission (Add apparatus)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['add_apparatus'])) {
    $name = trim($_POST['name']);
    $type = trim($_POST['type']);
    $size = trim($_POST['size']);
    $material = trim($_POST['material']);
    $description = trim($_POST['description']); // Capture Description
    $total_stock = (int)$_POST['total_stock']; // CAPTURE TOTAL STOCK
    $damaged_stock = (int)$_POST['damaged_stock']; // CAPTURE DAMAGED STOCK
    $lost_stock = (int)$_POST['lost_stock']; // CAPTURE LOST STOCK
    
    // Initial available stock calculation check
    $initial_available = $total_stock - $damaged_stock - $lost_stock; 
    
    // Validation
    if (empty($name)) $errors['name'] = "Apparatus name is required.";
    if (empty($type)) $errors['type'] = "Type is required.";
    if (empty($size)) $errors['size'] = "Size is required.";
    if (empty($material)) $errors['material'] = "Material is required.";
    if (empty($description)) $errors['description'] = "Description is required.";
    if ($total_stock <= 0) $errors['total_stock'] = "Total stock quantity must be a positive number.";
    if ($damaged_stock < 0 || $lost_stock < 0) $errors['stock_neg'] = "Damaged/Lost stock cannot be negative.";
    if ($initial_available < 0) $errors['stock_math'] = "Damaged/Lost stock cannot exceed Total Stock.";
    
    // Enforce image upload
    $image_name = "default.jpg"; 
    if (!isset($_FILES['apparatus_image']) || $_FILES['apparatus_image']['error'] === UPLOAD_ERR_NO_FILE) {
        $errors['image'] = "An apparatus image is required.";
    }

    if (empty($errors)) {
        
        //Handle image upload
        if (isset($_FILES['apparatus_image']) && $_FILES['apparatus_image']['error'] === UPLOAD_ERR_OK) {
            $upload_dir = "../uploads/apparatus_images/";
            $image_name = time() . "_" . basename($_FILES['apparatus_image']['name']);
            $target_path = $upload_dir . $image_name;

            if (!is_dir($upload_dir) && !mkdir($upload_dir, 0777, true)) { /* directory check */ }

            if (!move_uploaded_file($_FILES['apparatus_image']['tmp_name'], $target_path)) {
                $image_name = "default.jpg";
                $errors['image_upload'] = "Failed to upload image. Using default.";
            }
        }

        // PASS NEW STOCK VARIABLES (Condition/Status will be determined by stock levels inside the method)
        // This relies on the BCNF-compliant addApparatus method to create the units
        $result = $transaction->addApparatus($name, $type, $size, $material, $description, $total_stock, $damaged_stock, $lost_stock, $image_name);
        
        if ($result === true) {
            $message = "✅ Apparatus '{$name}' added successfully!";
            // Clear inputs
            $name = $type = $size = $material = $description = $total_stock = $damaged_stock = $lost_stock = ""; 
        } elseif ($result === false) {
            $message = "❌ Failed to add apparatus: An item with the exact Name, Type, Size, and Material already exists.";
            if ($image_name !== "default.jpg") { deleteApparatusImage($image_name); }
        } else {
            $message = "❌ Failed to add apparatus due to a database error.";
            if ($image_name !== "default.jpg") { deleteApparatusImage($image_name); }
        }
    }
}

// 2. Handle form submission (Update apparatus details and/or image)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['update_details'])) {
    $id = $_POST['id'];
    $name = trim($_POST['name']);
    $type = trim($_POST['type']);
    $size = trim($_POST['size']);
    $material = trim($_POST['material']);
    $description = trim($_POST['description']); 
    $total_stock = (int)$_POST['total_stock']; 
    $damaged_stock = (int)$_POST['damaged_stock']; // NEW
    $lost_stock = (int)$_POST['lost_stock']; // NEW
    
    $old_image = $_POST['old_image']; 
    $new_image_name = $old_image;

    // Fetch current available stock 
    $current_app = $transaction->getApparatusById($id);
    $current_items_out = $current_app['currently_out']; // items currently borrowed/reserved
    
    // Validation
    if (empty($name)) $errors['name'] = "Name is required.";
    if (empty($type)) $errors['type'] = "Type is required.";
    if (empty($size)) $errors['size'] = "Size is required.";
    if (empty($material)) $errors['material'] = "Material is required.";
    if (empty($description)) $errors['description'] = "Description is required.";
    if ($total_stock <= 0) $errors['total_stock'] = "Total stock quantity must be a positive number."; 
    if ($damaged_stock < 0 || $lost_stock < 0) $errors['stock_neg'] = "Damaged/Lost stock cannot be negative.";
    
    // Stock constraint check: Total stock minus Damaged/Lost stock must be >= items currently borrowed/reserved
    $new_physical_available = $total_stock - $damaged_stock - $lost_stock;
    if ($new_physical_available < $current_items_out) {
        $errors['stock_too_low'] = "Cannot reduce total stock or increase damaged/lost units to a point where fewer units are physically available than currently borrowed/reserved ($current_items_out).";
    }

    // Handle image upload ONLY IF a new file is selected
    if (isset($_FILES['new_apparatus_image']) && $_FILES['new_apparatus_image']['error'] === UPLOAD_ERR_OK) {
        $upload_dir = "../uploads/apparatus_images/";
        $new_image_name = time() . "_" . basename($_FILES['new_apparatus_image']['name']);
        $target_path = $upload_dir . $new_image_name;

        if (!is_dir($upload_dir) && !mkdir($upload_dir, 0777, true)) { /* directory check */ }

        if (move_uploaded_file($_FILES['new_apparatus_image']['tmp_name'], $target_path)) {
            // Delete the old image only if the new one was successfully saved AND the old one isn't the default
            deleteApparatusImage($old_image);
        } else {
            $new_image_name = $old_image; 
            $errors['image_update'] = "Failed to upload new image. Retaining old image.";
        }
    }

    if (empty($errors)) {
        // CALL THE BCNF-COMPLIANT UPDATED METHOD
        $result = $transaction->updateApparatusDetailsAndStock($id, $name, $type, $size, $material, $description, $total_stock, $damaged_stock, $lost_stock, $new_image_name);
        
        if ($result === 'stock_too_low') { // Handle specific stock error from Transaction class (redundant due to client check, but kept for safety)
             $message = "❌ Update failed: Cannot reduce total stock below the number of items currently borrowed/reserved.";
        } elseif ($result === true) {
            $message = "✅ Apparatus #$id ('{$name}') updated successfully!";
            // Redirect to clean URL after success
            header("Location: staff_apparatus.php?message=" . urlencode($message));
            exit;
        } else {
            $message = "❌ Failed to update apparatus #$id due to a database error.";
        }
    } else {
        $message = "❌ Update failed due to validation errors.";
    }
    // If update fails, ensure $edit_id is set to display the form again
    $edit_id = $id; 
}


// 3. Handle status/condition quick update (Now only updates stock levels)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['quick_update_stock'])) {
    $id = $_POST['apparatus_id'];
    $total_stock = (int)$_POST['total_stock']; 
    $damaged_stock = (int)$_POST['damaged_stock']; 
    $lost_stock = (int)$_POST['lost_stock']; 

    // --- NEW: Fetch all existing metadata needed for the update function ---
    $current_app = $transaction->getApparatusById($id);
    if (!$current_app) {
        $message = "❌ Quick update failed: Apparatus ID $id not found.";
        goto end_quick_update; // Jump to the end of the quick update block
    }
    
    $current_items_out = $current_app['currently_out']; // Borrowed/Reserved items
    
    // Check constraint
    $new_available_physical = $total_stock - $damaged_stock - $lost_stock;

    if ($new_available_physical < $current_items_out) {
        $message = "❌ Quick update failed: Cannot set damaged/lost count that reduces the physical available stock below the currently borrowed units ($current_items_out).";
    } elseif (
        // === FIXED CALL: Use the existing comprehensive method ===
        $transaction->updateApparatusDetailsAndStock(
            $id,
            $current_app['name'], 
            $current_app['apparatus_type'], 
            $current_app['size'], 
            $current_app['material'], 
            $current_app['description'],
            $total_stock, 
            $damaged_stock, 
            $lost_stock, 
            $current_app['image']
        )
    ) {
        // MODIFIED MESSAGE TO INCLUDE NAME
        $updated_app = $transaction->getApparatusById($id);
        $app_name = htmlspecialchars($updated_app['name'] ?? 'Apparatus');
        $available = $updated_app['available_stock'];
        $message = "✅ Apparatus #$id ($app_name) stock updated! Available: {$available}, Damaged: {$damaged_stock}, Lost: {$lost_stock}.";
    } else {
        $message = "❌ Failed to quick update apparatus #$id stock counts due to a database error.";
    }
    
    end_quick_update:
}

// 4. Handle apparatus deletion
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['delete_apparatus'])) {
    // The modal form passes the data directly here
    $id = $_POST['apparatus_id'];
    $image_to_delete = $_POST['apparatus_image']; 
    
    // Fetch the name for the feedback message before attempting deletion
    $app_to_delete = $transaction->getApparatusById($id);
    $app_name = htmlspecialchars($app_to_delete['name'] ?? 'Apparatus');

    // deleteApparatus now checks apparatus_unit status via isApparatusDeletable
    $result = $transaction->deleteApparatus($id); 
    
    if ($result === true) {
        deleteApparatusImage($image_to_delete);
        // MODIFIED MESSAGE TO INCLUDE NAME
        $message = "✅ Apparatus #$id ('{$app_name}') deleted successfully. The next new apparatus will be assigned the next available sequential ID.";
    } elseif ($result === 'in_use') {
        // Message updated to reflect BCNF meaning: active units exist
        $message = "❌ Failed to delete apparatus #$id ('{$app_name}'): Units are currently checked out (borrowed/reserved). Resolve active loans first."; 
    } else {
        $message = "❌ Failed to delete apparatus #$id ('{$app_name}') from the database.";
    }
}

// 5. NEW: Handle Unit Restoration from Unit Management Modal (FIXED METHOD CALL)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['unit_to_restore'])) {
    $unit_id = (int)$_POST['unit_to_restore'];
    
    // Ensure staff ID is available
    $staff_id = $_SESSION["user"]["id"] ?? null;
    if (!$staff_id) {
        $message = "❌ Restoration failed: Staff ID not found.";
        goto end_unit_restore;
    }
    
    // *** CRITICAL FIX: Calling the generalized restoreUnit method ***
    $result = $transaction->restoreUnit($unit_id, $staff_id);
    
    if ($result === true) {
        // MODIFIED MESSAGE to reflect restored status and availability
        $message = "✅ Unit ID {$unit_id} successfully restored to good condition and made available.";
    } elseif ($result === 'not_restorable') { 
        // Changed message to reflect generalized check (not_damaged/not_lost)
        $message = "❌ Error: Unit ID {$unit_id} was not marked as damaged or lost, or does not exist.";
    } else {
        $message = "❌ Failed to restore Unit ID {$unit_id} due to a database error.";
    }

    end_unit_restore:
    // Redirect to show message
    header("Location: staff_apparatus.php?message=" . urlencode($message));
    exit;
}


// Logic for Edit Form Data Retrieval ---
$current_apparatus = null;
$success_message = $_GET['message'] ?? $message; // Use URL message if set

if ($edit_id) {
    // Fetch the specific apparatus data for editing
    $current_apparatus = $transaction->getApparatusById($edit_id); 
    if ($current_apparatus) {
        // Only override if the form wasn't just submitted with errors (which keeps POST data)
        if (!isset($_POST['update_details'])) {
            $name = $current_apparatus['name'];
            $type = $current_apparatus['apparatus_type'];
            $size = $current_apparatus['size'];
            $material = $current_apparatus['material'];
            $description = $current_apparatus['description'] ?? ''; 
            $total_stock = $current_apparatus['total_stock']; 
            $damaged_stock = $current_apparatus['damaged_stock']; // NEW
            $lost_stock = $current_apparatus['lost_stock']; // NEW
        }
        $current_image = $current_apparatus['image'];
    } else {
        $success_message = "❌ Apparatus with ID $edit_id not found.";
        $edit_id = null; // Clear edit mode
    }
}


// --- Search and Filter Logic--- 
$filter_status = $_GET['status'] ?? 'all';
$search_query = $_GET['search'] ?? ''; 

// We use the updated getAllApparatus() to get the full list
$apparatusList = $transaction->getAllApparatus();

if ($filter_status !== 'all') {
    $apparatusList = array_filter($apparatusList, function($item) use ($filter_status) {
        // This checks the aggregated status field in apparatus_type, which is correct
        return strtolower($item['status']) === strtolower($filter_status);
    });
}

if (!empty($search_query)) {
    $search_query_lower = strtolower($search_query);
    $apparatusList = array_filter($apparatusList, function($item) use ($search_query_lower) {
        // Fields are descriptive and remain in apparatus_type
        return (
            str_contains(strtolower($item['name']), $search_query_lower) ||
            str_contains(strtolower($item['apparatus_type']), $search_query_lower) ||
            str_contains(strtolower($item['material']), $search_query_lower)
        );
    });
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Staff - Apparatus Management</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0-beta3/css/all.min.css" rel="stylesheet">
<style>
    /* CSS FIXES: Explicitly constraining image and description width to stabilize table UI */
    :root {
        --msu-red: #A40404; /* FIXED to consistent staff/student red */
        --msu-red-dark: #820303; /* FIXED to consistent dark red */
        --msu-blue: #007bff;
        --sidebar-width: 280px; 
        --header-height: 60px; 
        --student-logout-red: #dc3545; 
        --base-font-size: 15px;
        --main-text: #333; 
    }

    body { 
        font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif; 
        background: #f5f6fa;
        min-height: 100vh;
        display: flex; 
        padding: 0;
        margin: 0;
        font-size: var(--base-font-size);
        /* CRITICAL: Prevents the page body from generating a horizontal scrollbar */
        overflow-x: hidden; 
    }
    
    /* --- Top Header Bar Styles (COPIED FROM DASHBOARD) --- */
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
        justify-content: flex-end; 
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
        z-index: 1010; /* Ensure sidebar is above the header */
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
    
    .sidebar-nav .nav-link {
        color: white;
        padding: 18px 25px;
        font-size: 1.05rem; 
        font-weight: 600;
        transition: background-color 0.2s;
    }

    .sidebar-nav .nav-link:hover {
        background-color: var(--msu-red-dark);
    }
    .sidebar-nav .nav-link.active {
        background-color: var(--msu-red-dark);
    }
    
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
        background-color: #C62828 !important; /* FIXED to match consistent base logout color */
        color: white !important;
        padding: 18px 25px; 
        border-radius: 0; 
        text-decoration: none;
        font-weight: 600; 
        font-size: 1.05rem; 
        transition: background 0.3s;
    }
    .logout-link .nav-link:hover {
        background-color: var(--msu-red-dark) !important; /* FIXED to use consistent dark hover color */
    }
    /* --- END FINAL LOGOUT FIX --- */

    .main-content {
        margin-left: var(--sidebar-width); 
        flex-grow: 1;
        padding: 30px;
        width: calc(100% - var(--sidebar-width)); 
        /* CRITICAL FIX: Add padding-top to push content below fixed header */
        padding-top: calc(var(--header-height) + 30px); 
    }
    .content-area {
        background: #fff; 
        border-radius: 12px; 
        padding: 30px; 
        box-shadow: 0 5px 15px rgba(0,0,0,0.1);
    }

    h2 { 
        color: #333; 
        border-bottom: 2px solid var(--msu-red);
        padding-bottom: 15px; 
        margin-bottom: 30px; 
        font-weight: 600;
        font-size: 2rem; 
    }
    h3 {
        color: #333; 
        font-weight: 600;
        font-size: 1.4rem; 
        border-bottom: 1px solid #eee;
        padding-bottom: 10px;
    }

    .add-form {
        padding: 30px;
        border: 1px solid #ddd;
        border-radius: 10px;
        box-shadow: 0 2px 8px rgba(0,0,0,0.05);
        margin-bottom: 30px;
    }
    .add-form label {
        font-weight: 600;
        margin-top: 10px;
        font-size: 1rem;
    }
    
    /* Form Inputs */
    .form-control, .form-select, textarea {
        font-size: 1rem;
    }
    .edit-image-preview {
        max-width: 150px;
        height: auto;
        display: block;
        margin-top: 10px;
        border: 1px solid #ccc;
        border-radius: 5px;
        object-fit: contain;
    }


    .table-responsive {
        border-radius: 8px;
        overflow-x: auto; /* Keeps scroll inside this container */
        box-shadow: 0 2px 10px rgba(0,0,0,0.05);
        margin-top: 20px; 
    }
    
    /* Ensure the table doesn't collapse but has a defined width for scrolling */
    .table {
        min-width: 1300px; /* Define a generous minimum width to activate internal scroll */
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
    
    /* UI FIX: Constrain image size in table */
    .table img {
        width: 60px; 
        height: 60px; 
        object-fit: cover;
        border-radius: 4px;
        border: 1px solid #ddd;
        vertical-align: middle; 
    }
    
    .status-tag {
        display: inline-block;
        padding: 5px 12px; 
        border-radius: 16px; 
        font-weight: 700;
        text-transform: capitalize;
        font-size: 0.85rem; 
        line-height: 1.2;
        white-space: nowrap;
    }
    /* Status Tag Colors (Used for Condition and Type Status) */
    .status-tag.good { background-color: #28a745; color: white; } /* Item Condition */
    .status-tag.damaged { background-color: #ffc107; color: #333; } /* Item Condition */
    .status-tag.lost { background-color: #dc3545; color: white; } /* Item Condition */

    .status-tag.available { background-color: #28a745; color: white; } /* Type Status */
    .status-tag.borrowed { background-color: #0d6efd; color: white; } /* Type Status */
    .status-tag.reserved { background-color: #17a2b8; color: white; } /* Type Status */
    .status-tag.unavailable { background-color: #343a40; color: white; } /* Type Status */
    

    .action-buttons-group {
        display: flex;
        gap: 5px; 
        justify-content: center;
        align-items: center;
        flex-wrap: nowrap; 
    }
    
    .action-buttons-group .btn {
        padding: 7px 11px;
        font-size: 0.9rem;
        white-space: nowrap;
        box-shadow: 0 1px 3px rgba(0,0,0,0.1); 
    }

    .btn-stock-action {
        padding: 7px 9px; 
    }

    .btn-icon-only {
        width: 38px; 
        height: 38px;
        padding: 0;
        display: flex;
        justify-content: center;
        align-items: center;
        border-radius: 6px; 
    }
    
    /* Description cell needs space but must wrap */
    .table tbody td:nth-child(12) {
        max-width: 180px;
        white-space: normal;
        word-wrap: break-word;
    }
    
    /* Default button styling (unchanged) */
    .btn-primary { background-color: #007bff; border-color: #007bff; }
    .btn-info { background-color: #17a2b8; border-color: #17a2b8; color: white !important; }
    .btn-warning { background-color: #ffc107; border-color: #ffc107; color: #212529 !important; }
    .btn-danger { background-color: #dc3545; border-color: #dc3545; }
    
    /* Modal Custom Style for Warning (Unchanged) */
    #unitManageModal .modal-header { background-color: var(--msu-blue); color: white; }
    #restoreConfirmModal .modal-header { background-color: #ffc107; color: #333; }
</style>

</head>
<body>

<div class="sidebar">
    <div class="sidebar-header">
        <img src="../wmsu_logo/wmsu.png" alt="WMSU Logo"> 
        <div class="title">
            CSM LABORATORY <br>APPARATUS BORROWING
        </div>
    </div>
    
    <div class="sidebar-nav .nav flex-column">
        <a class="nav-link" href="staff_dashboard.php">
            <i class="fas fa-chart-line fa-fw me-2"></i>Dashboard
        </a>
        <a class="nav-link active" href="staff_apparatus.php">
            <i class="fas fa-vials fa-fw me-2"></i>Apparatus List
        </a>
        <a class="nav-link" href="staff_pending.php">
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
                    <a class="dropdown-item text-center small text-muted dynamic-notif-item">Loading notifications...</a>
                </div>
                
                <a class="dropdown-item text-center small text-muted mark-all-link" onclick="markAllStaffAsRead()">
                    <i class="fas fa-check-double me-1"></i> Mark All as Read
                </a>
                
                <a class="dropdown-item text-center small text-muted" href="staff_pending.php">View All Pending Requests</a>
            </div>
        </li>
    </ul>
</header>
<div class="main-content">
    <div class="content-area">
        <h2 class="mb-4">
            <i class="fas fa-vials fa-fw me-2"></i> Manage Apparatus Inventory
        </h2>

        <?php if ($success_message): ?>
            <div id="status-alert" class="alert <?= strpos($success_message, '✅') !== false ? 'alert-success' : 'alert-danger' ?> alert-dismissible fade show" role="alert">
                <?= htmlspecialchars($success_message) ?>
                <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
            </div>
        <?php endif; ?>

        <div class="add-form">
            <?php if ($edit_id && $current_apparatus): ?>
                <h3><i class="fas fa-edit me-2"></i> Edit Apparatus #<?= $edit_id ?></h3>
                <form method="POST" enctype="multipart/form-data" class="row g-3">
                    <input type="hidden" name="id" value="<?= $edit_id ?>">
                    <input type="hidden" name="old_image" value="<?= htmlspecialchars($current_image) ?>">
                    
                    <div class="col-md-4">
                        <label class="form-label">Apparatus Name:</label>
                        <input type="text" name="name" class="form-control" value="<?= htmlspecialchars($name) ?>">
                        <?php if (isset($errors['name'])): ?><span class="text-danger error"><?= htmlspecialchars($errors['name']) ?></span><?php endif; ?>
                    </div>
                    <div class="col-md-4">
                        <label class="form-label">Type:</label>
                        <select name="type" class="form-select">
                            <option value="Glassware" <?= ($type === 'Glassware') ? 'selected' : '' ?>>Glassware</option>
                            <option value="Measuring Instrument" <?= ($type === 'Measuring Instrument') ? 'selected' : '' ?>>Measuring Instrument</option>
                            <option value="Heating Equipment" <?= ($type === 'Heating Equipment') ? 'selected' : '' ?>>Heating Equipment</option>
                            <option value="Optical Instrument" <?= ($type === 'Optical Instrument') ? 'selected' : '' ?>>Optical Instrument</option>
                            <option value="Support Apparatus" <?= ($type === 'Support Apparatus') ? 'selected' : '' ?>>Support Apparatus</option>
                            <option value="Safety Equipment" <?= ($type === 'Safety Equipment') ? 'selected' : '' ?>>Safety Equipment</option>
                            <option value="Storage Equipment" <?= ($type === 'Storage Equipment') ? 'selected' : '' ?>>Storage Equipment</option>
                            <option value="Cleaning Equipment" <?= ($type === 'Cleaning Equipment') ? 'selected' : '' ?>>Cleaning Equipment</option>
                            <option value="Chemical Apparatus" <?= ($type === 'Chemical Apparatus') ? 'selected' : '' ?>>Chemical Apparatus</option>
                            <option value="Electrical Apparatus" <?= ($type === 'Electrical Apparatus') ? 'selected' : '' ?>>Electrical Apparatus</option>
                        </select>
                            <?php if (isset($errors['type'])): ?><span class="text-danger error"><?= htmlspecialchars($errors['type']) ?></span><?php endif; ?>
                    </div>
                    <div class="col-md-4">
                        <label class="form-label">Total Stock Quantity <span class="text-danger">*</span>:</label>
                        <input type="number" name="total_stock" class="form-control" min="1" value="<?= htmlspecialchars($total_stock ?? 1) ?>">
                        <small class="text-muted">Currently Borrowed/Reserved: <?= htmlspecialchars($current_apparatus['currently_out'] ?? 0) ?> units.</small>
                        <?php if (isset($errors['total_stock'])): ?><span class="text-danger error"><?= htmlspecialchars($errors['total_stock']) ?></span><?php endif; ?>
                    </div>

                    <div class="col-md-4">
                        <label class="form-label">Size:</label>
                        <input type="text" name="size" class="form-control" placeholder="e.g. 10ml, 15cm, small" value="<?= htmlspecialchars($size ?? '') ?>">
                            <?php if (isset($errors['size'])): ?><span class="text-danger error"><?= htmlspecialchars($errors['size']) ?></span><?php endif; ?>
                    </div>

                    <div class="col-md-4">
                        <label class="form-label">Material:</label>
                        <select name="material" class="form-select">
                            <option value="Borosilicate Glass" <?= ($material === 'Borosilicate Glass') ? 'selected' : '' ?>>Borosilicate Glass</option>
                            <option value="Porcelain" <?= ($material === 'Porcelain') ? 'selected' : '' ?>>Porcelain (Ceramic)</option>
                            <option value="Iron/Steel" <?= ($material === 'Iron/Steel') ? 'selected' : '' ?>>Iron/Steel</option>
                            <option value="Aluminum" <?= ($material === 'Aluminum') ? 'selected' : '' ?>>Aluminum</option>
                            <option value="Copper" <?= ($material === 'Copper') ? 'selected' : '' ?>>Copper</option>
                            <option value="Plastic" <?= ($material === 'Plastic') ? 'selected' : '' ?>>Plastic (General)</option>
                            <option value="Polypropylene (PP)" <?= ($material === 'Polypropylene (PP)') ? 'selected' : '' ?>>Polypropylene (PP)</option>
                            <option value="PTFE (Teflon)" <?= ($material === 'PTFE (Teflon)') ? 'selected' : '' ?>>PTFE (Teflon)</option>
                            <option value="Rubber/Silicone" <?= ($material === 'Rubber/Silicone') ? 'selected' : '' ?>>Rubber/Silicone</option>
                            <option value="Wood" <?= ($material === 'Wood') ? 'selected' : '' ?>>Wood</option>
                        </select>
                        <?php if (isset($errors['material'])): ?><span class="text-danger error"><?= htmlspecialchars($errors['material']) ?></span><?php endif; ?>
                    </div>
                    
                    <div class="col-md-4">
                        <label class="form-label">Damaged Stock Count:</label>
                        <input type="number" name="damaged_stock" class="form-control" min="0" value="<?= htmlspecialchars($damaged_stock ?? 0) ?>">
                        <?php if (isset($errors['damaged_stock']) || isset($errors['stock_neg'])): ?><span class="text-danger error"><?= htmlspecialchars($errors['damaged_stock'] ?? $errors['stock_neg']) ?></span><?php endif; ?>
                    </div>
                    
                    <div class="col-md-4">
                        <label class="form-label">Lost Stock Count:</label>
                        <input type="number" name="lost_stock" class="form-control" min="0" value="<?= htmlspecialchars($lost_stock ?? 0) ?>">
                        <?php if (isset($errors['lost_stock']) || isset($errors['stock_neg'])): ?><span class="text-danger error"><?= htmlspecialchars($errors['lost_stock'] ?? $errors['stock_neg']) ?></span><?php endif; ?>
                    </div>
                    
                    <?php if (isset($errors['stock_too_low'])): ?><div class="col-12"><span class="text-danger error fw-bold"><?= htmlspecialchars($errors['stock_too_low']) ?></span></div><?php endif; ?>

                    <div class="col-12">
                        <label class="form-label">Description (Usage and Details):</label>
                        <textarea name="description" class="form-control" rows="3"><?= htmlspecialchars($description ?? '') ?></textarea>
                        <?php if (isset($errors['description'])): ?><span class="text-danger error"><?= htmlspecialchars($errors['description']) ?></span><?php endif; ?>
                    </div>

                    
                    <div class="col-md-4">
                        <label class="form-label">Current Image:</label>
                        <img id="edit_current_img" src="../uploads/apparatus_images/<?= htmlspecialchars($current_image) ?>" alt="Current Image" class="edit-image-preview">
                        
                        <label class="form-label">Change Image (Optional):</label>
                        <input type="file" name="new_apparatus_image" id="edit_apparatus_image_input" accept="image/*" class="form-control" onchange="previewImage('edit_apparatus_image_input', 'current_edit_preview')">
                        <?php if (isset($errors['image_update'])): ?><span class="text-warning error"><?= htmlspecialchars($errors['image_update']) ?></span><?php endif; ?>
                        
                        <img id="current_edit_preview" src="#" alt="New Image Preview" style="display: none;" class="edit-image-preview">
                    </div>
                    
                    <div class="col-12 text-end">
                        <a href="staff_apparatus.php" class="btn btn-secondary me-2">Cancel Edit</a>
                        <button type="submit" name="update_details" class="btn btn-warning">
                            <i class="fas fa-sync me-1"></i> Save Changes
                        </button>
                    </div>
                </form>
            <?php else: ?>
                <h3><i class="fas fa-plus-circle me-2"></i> Add New Apparatus</h3>
                <form method="POST" enctype="multipart/form-data" class="row g-3">
                    
                    <div class="col-md-4">
                        <label class="form-label">Apparatus Name:</label>
                        <input type="text" name="name" class="form-control" value="<?= htmlspecialchars($name) ?>">
                        <?php if (isset($errors['name'])): ?><span class="text-danger error"><?= htmlspecialchars($errors['name']) ?></span><?php endif; ?>
                    </div>

                    <div class="col-md-4">
                        <label class="form-label">Type:</label>
                        <select name="type" class="form-select">
                            <option value="">-- Select Type --</option>
                            <option value="Glassware" <?= ($type === 'Glassware') ? 'selected' : '' ?>>Glassware</option>
                            <option value="Measuring Instrument" <?= ($type === 'Measuring Instrument') ? 'selected' : '' ?>>Measuring Instrument</option>
                            <option value="Heating Equipment" <?= ($type === 'Heating Equipment') ? 'selected' : '' ?>>Heating Equipment</option>
                            <option value="Optical Instrument" <?= ($type === 'Optical Instrument') ? 'selected' : '' ?>>Optical Instrument</option>
                            <option value="Support Apparatus" <?= ($type === 'Support Apparatus') ? 'selected' : '' ?>>Support Apparatus</option>
                            <option value="Safety Equipment" <?= ($type === 'Safety Equipment') ? 'selected' : '' ?>>Safety Equipment</option>
                            <option value="Storage Equipment" <?= ($type === 'Storage Equipment') ? 'selected' : '' ?>>Storage Equipment</option>
                            <option value="Cleaning Equipment" <?= ($type === 'Cleaning Equipment') ? 'selected' : '' ?>>Cleaning Equipment</option>
                            <option value="Chemical Apparatus" <?= ($type === 'Chemical Apparatus') ? 'selected' : '' ?>>Chemical Apparatus</option>
                            <option value="Electrical Apparatus" <?= ($type === 'Electrical Apparatus') ? 'selected' : '' ?>>Electrical Apparatus</option>
                        </select>
                        <?php if (isset($errors['type'])): ?><span class="text-danger error"><?= htmlspecialchars($errors['type']) ?></span><?php endif; ?>
                    </div>

                    <div class="col-md-4">
                        <label class="form-label">Total Stock Quantity <span class="text-danger">*</span>:</label>
                        <input type="number" name="total_stock" class="form-control" min="1" value="<?= htmlspecialchars($total_stock ?? 1) ?>">
                        <small class="text-muted">Total number of units of this item.</small>
                        <?php if (isset($errors['total_stock'])): ?><span class="text-danger error"><?= htmlspecialchars($errors['total_stock']) ?></span><?php endif; ?>
                    </div>

                    <div class="col-md-4">
                        <label class="form-label">Size:</label>
                        <input type="text" name="size" class="form-control" placeholder="e.g. 10ml, 15cm, small" value="<?= htmlspecialchars($size ?? '') ?>">
                        <small class="text-muted">Enter realistic units (ml, cm, L, etc.)</small>
                        <?php if (isset($errors['size'])): ?><span class="text-danger error"><?= htmlspecialchars($errors['size']) ?></span><?php endif; ?>
                    </div>

                    <div class="col-md-4">
                        <label class="form-label">Material:</label>
                        <select name="material" class="form-select">
                            <option value="">-- Select Material --</option>
                            <option value="Borosilicate Glass" <?= ($material === 'Borosilicate Glass') ? 'selected' : '' ?>>Borosilicate Glass</option>
                            <option value="Porcelain" <?= ($material === 'Porcelain') ? 'selected' : '' ?>>Porcelain (Ceramic)</option>
                            <option value="Iron/Steel" <?= ($material === 'Iron/Steel') ? 'selected' : '' ?>>Iron/Steel</option>
                            <option value="Aluminum" <?= ($material === 'Aluminum') ? 'selected' : '' ?>>Aluminum</option>
                            <option value="Copper" <?= ($material === 'Copper') ? 'selected' : '' ?>>Copper</option>
                            <option value="Plastic" <?= ($material === 'Plastic') ? 'selected' : '' ?>>Plastic (General)</option>
                            <option value="Polypropylene (PP)" <?= ($material === 'Polypropylene (PP)') ? 'selected' : '' ?>>Polypropylene (PP)</option>
                            <option value="PTFE (Teflon)" <?= ($material === 'PTFE (Teflon)') ? 'selected' : '' ?>>PTFE (Teflon)</option>
                            <option value="Rubber/Silicone" <?= ($material === 'Rubber/Silicone') ? 'selected' : '' ?>>Rubber/Silicone</option>
                            <option value="Wood" <?= ($material === 'Wood') ? 'selected' : '' ?>>Wood</option>
                        </select>
                        <?php if (isset($errors['material'])): ?><span class="text-danger error"><?= htmlspecialchars($errors['material']) ?></span><?php endif; ?>
                    </div>

                    <div class="col-md-4">
                        <label class="form-label">Damaged Stock (Initial):</label>
                        <input type="number" name="damaged_stock" class="form-control" min="0" value="<?= htmlspecialchars($damaged_stock ?? 0) ?>">
                        <?php if (isset($errors['damaged_stock']) || isset($errors['stock_neg'])): ?><span class="text-danger error"><?= htmlspecialchars($errors['damaged_stock'] ?? $errors['stock_neg']) ?></span><?php endif; ?>
                    </div>
                    
                    <div class="col-md-4">
                        <label class="form-label">Lost Stock (Initial):</label>
                        <input type="number" name="lost_stock" class="form-control" min="0" value="<?= htmlspecialchars($lost_stock ?? 0) ?>">
                        <?php if (isset($errors['lost_stock']) || isset($errors['stock_neg'])): ?><span class="text-danger error"><?= htmlspecialchars($errors['lost_stock'] ?? $errors['stock_neg']) ?></span><?php endif; ?>
                    </div>
                    
                    <?php if (isset($errors['stock_math'])): ?><div class="col-12"><span class="text-danger error fw-bold"><?= htmlspecialchars($errors['stock_math']) ?></span></div><?php endif; ?>

                    <div class="col-12">
                        <label class="form-label">Description (Usage and Details) <span class="text-danger">*</span>:</label> <textarea name="description" class="form-control" rows="3"><?= htmlspecialchars($description ?? '') ?></textarea>
                        <?php if (isset($errors['description'])): ?><span class="text-danger error"><?= htmlspecialchars($errors['description']) ?></span><?php endif; ?>
                    </div>

                    <div class="col-md-4">
                        <label class="form-label">Upload Image: <span class="text-danger">*</span></label>
                        <input type="file" name="apparatus_image" id="apparatus_image_input" accept="image/*" class="form-control" onchange="previewImage('apparatus_image_input', 'image-preview')">
                        <?php if (isset($errors['image'])): ?><span class="text-danger error"><?= htmlspecialchars($errors['image']) ?></span><?php endif; ?>
                        <?php if (isset($errors['image_upload'])): ?><span class="text-warning error"><?= htmlspecialchars($errors['image_upload']) ?></span><?php endif; ?>
                        
                        <img id="image-preview" src="../uploads/apparatus_images/default.jpg" alt="Image Preview" style="display: none;">
                    </div>
                    
                    <div class="col-12 text-end">
                        <button type="submit" name="add_apparatus" class="btn btn-primary">
                            <i class="fas fa-save me-1"></i> Add Apparatus
                        </button>
                    </div>
                </form>
            <?php endif; ?>
        </div>
        <h3><i class="fas fa-list me-2"></i> Apparatus Inventory</h3>
        
        <div class="d-flex justify-content-between align-items-center mb-3">
            
            <form method="GET" class="filter-form d-flex align-items-center mb-0">
                <label class="form-label me-2 mb-0 fw-bold text-secondary">Filter:</label>
                <select name="status" onchange="this.form.submit()" class="form-select form-select-sm w-auto me-3">
                    <option value="all" <?= $filter_status === 'all' ? 'selected' : '' ?>>Show All</option>
                    <option value="available" <?= $filter_status === 'available' ? 'selected' : '' ?>>Available</option>
                    <option value="borrowed" <?= $filter_status === 'borrowed' ? 'selected' : '' ?>>Borrowed</option>
                    <option value="reserved" <?= $filter_status === 'reserved' ? 'selected' : '' ?>>Reserved</option>
                    <option value="unavailable" <?= $filter_status === 'unavailable' ? 'selected' : '' ?>>Unavailable</option>
                </select>
            </form>
            
            <form method="GET" class="d-flex align-items-center mb-0">
                <input type="hidden" name="status" value="<?= htmlspecialchars($filter_status) ?>">
                <div class="input-group input-group-sm">
                    <input type="search" name="search" class="form-control" placeholder="Search by Name, Type, Material..." value="<?= htmlspecialchars($search_query) ?>">
                    <button class="btn btn-outline-secondary" type="submit">
                        <i class="fas fa-search"></i>
                    </button>
                    <?php if (!empty($search_query) || $filter_status !== 'all'): ?>
                    <a href="staff_apparatus.php" class="btn btn-outline-danger" title="Clear Filters">
                        <i class="fas fa-times"></i>
                    </a>
                    <?php endif; ?>
                </div>
            </form>

        </div>
        <div class="table-responsive">
            <table class="table table-striped table-hover align-middle">
                <thead>
                    <tr>
                        <th style="width: 4%;">ID</th>
                        <th style="width: 7%;">Image</th>
                        <th style="width: 12%;">Name</th>
                        <th style="width: 9%;">Type</th>
                        <th style="width: 6%;">Size</th>
                        <th style="width: 8%;">Material</th>
                        <th style="width: 6%;">Total</th> 
                        <th style="width: 6%;">Available Stock</th> 
                        <th style="width: 6%;">Damaged Units</th> 
                        <th style="width: 5%;">Lost Units</th> 
                        <th style="width: 5%;">Out</th> 
                        <th style="width: 15%;">Description</th> 
                        <th style="width: 7%;">Cond.</th> 
                        <th style="width: 7%;">Status</th>
                        <th style="width: 15%;" class="unit-actions-cell">Stock / Unit Actions</th> 
                        <th style="width: 7%;">Edit</th> 
                    </tr>
                </thead>
                <tbody>
                    <?php if (!empty($apparatusList)): ?>
                        <?php foreach ($apparatusList as $app): ?>
                            <tr>
                                <td><?= $app['id'] ?></td>
                                <td>
                                    <img src="../uploads/apparatus_images/<?= htmlspecialchars($app['image'] ?? 'default.jpg') ?>" 
                                            alt="<?= htmlspecialchars($app['name']) ?>">
                                </td>
                                <td class="text-start"><?= htmlspecialchars($app['name']) ?></td>
                                <td><?= htmlspecialchars($app['apparatus_type']) ?></td>
                                <td><?= htmlspecialchars($app['size']) ?></td>
                                <td><?= htmlspecialchars($app['material']) ?></td>
                                
                                <td><?= htmlspecialchars($app['total_stock'] ?? '0') ?></td> 
                                <td class="fw-bold <?= ($app['available_stock'] > 0 ? 'text-success' : 'text-danger') ?>"><?= htmlspecialchars($app['available_stock'] ?? '0') ?></td> 
                                
                                <td class="fw-bold text-warning"><?= htmlspecialchars(max(0, $app['damaged_stock'] ?? '0')) ?></td> 
                                <td class="fw-bold text-danger"><?= htmlspecialchars(max(0, $app['lost_stock'] ?? '0')) ?></td> 
                                <td><?= htmlspecialchars($app['currently_out'] ?? '0') ?></td> 
                                
                                <td class="text-start">
                                    <?php 
                                        $description = htmlspecialchars($app['description'] ?? '');
                                        $display_limit = 60;
                                        $display_text = (strlen($description) > $display_limit) ? substr($description, 0, $display_limit) . '...' : $description;

                                        echo '<span 
                                                tabindex="0" 
                                                role="button"
                                                data-bs-toggle="popover" 
                                                data-bs-trigger="hover focus" 
                                                data-bs-placement="bottom" 
                                                data-bs-custom-class="description-popover" 
                                                data-bs-title="Full Description" 
                                                data-bs-content="' . $description . '">' . $display_text . 
                                                '</span>';
                                    ?>
                                </td>
                                
                                <td>
                                    <span class="status-tag <?= htmlspecialchars($app['item_condition']) ?>">
                                        <?= htmlspecialchars(ucfirst($app['item_condition'])) ?>
                                    </span>
                                </td>
                                <td>
                                    <span class="status-tag <?= htmlspecialchars($app['status']) ?>">
                                        <?= htmlspecialchars(ucfirst($app['status'])) ?>
                                    </span>
                                </td>
                                
                                <td class="text-center unit-actions-cell">
                                    <div class="action-buttons-group">
                                        <button type="button" 
                                            class="btn btn-sm btn-primary btn-stock-action" 
                                            data-bs-toggle="modal" 
                                            data-bs-target="#quickUpdateModal"
                                            data-id="<?= $app['id'] ?>"
                                            data-name="<?= htmlspecialchars($app['name']) ?>"
                                            data-total="<?= htmlspecialchars($app['total_stock'] ?? 0) ?>"
                                            data-damaged="<?= htmlspecialchars($app['damaged_stock'] ?? 0) ?>"
                                            data-lost="<?= htmlspecialchars($app['lost_stock'] ?? 0) ?>"
                                            data-out="<?= htmlspecialchars($app['currently_out'] ?? 0) ?>">
                                            <i class="fas fa-boxes me-1"></i> Quick Stock
                                        </button>
                                        
                                        <button type="button" 
                                            class="btn btn-sm btn-info btn-stock-action" 
                                            data-bs-toggle="modal" 
                                            data-bs-target="#unitManageModal"
                                            data-type-id="<?= $app['id'] ?>"
                                            data-name="<?= htmlspecialchars($app['name']) ?>">
                                            <i class="fas fa-wrench me-1"></i> Manage Units
                                        </button>
                                    </div>
                                </td>

                                <td>
                                    <div class="action-buttons-group">
                                        <a href="staff_apparatus.php?edit_id=<?= $app['id'] ?>" 
                                            class="btn btn-sm btn-warning btn-icon-only" 
                                            title="Edit Details">
                                            <i class="fas fa-edit"></i>
                                        </a>
                                        <button type="button" 
                                            class="btn btn-sm btn-danger btn-icon-only"
                                            data-bs-toggle="modal" 
                                            data-bs-target="#deleteApparatusModal"
                                            data-id="<?= $app['id'] ?>"
                                            data-name="<?= htmlspecialchars($app['name']) ?>"
                                            data-image="<?= htmlspecialchars($app['image'] ?? 'default.jpg') ?>"
                                            title="Delete Apparatus">
                                            <i class="fas fa-trash-alt"></i>
                                        </button>
                                    </div>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    <?php else: ?>
                        <tr><td colspan="16" class="text-muted py-3">No apparatus found for the selected filter or search query.</td></tr>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<div class="modal fade" id="quickUpdateModal" tabindex="-1" aria-labelledby="quickUpdateModalLabel" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title" id="quickUpdateModalLabel">
                    <i class="fas fa-boxes fa-fw me-1"></i> Quick Stock Adjustment
                </h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <form id="modalQuickUpdateForm" method="POST">
                <div class="modal-body">
                    <h5 class="mb-3 text-center fw-bold text-dark">
                        <span id="modalAppName"></span> (ID: <span id="modalAppId"></span>)
                    </h5>
                    
                    <p class="text-danger small fw-bold text-center">
                        <i class="fas fa-exclamation-triangle"></i> Currently Borrowed/Reserved: <span id="modalItemsOut">0</span> units.
                    </p>

                    <div class="stock-input-group row g-3 text-center">
                        <div class="col-4">
                            <label for="modalTotalStock" class="form-label">Total Stock</label>
                            <input type="number" name="total_stock" id="modalTotalStock" class="form-control" min="1" required>
                        </div>
                        <div class="col-4">
                            <label for="modalDamagedStock" class="form-label text-warning">Damaged Stock</label>
                            <input type="number" name="damaged_stock" id="modalDamagedStock" class="form-control" min="0">
                        </div>
                        <div class="col-4">
                            <label for="modalLostStock" class="form-label text-danger">Lost Stock</label>
                            <input type="number" name="lost_stock" id="modalLostStock" class="form-control" min="0">
                        </div>
                    </div>
                    
                    <div class="alert alert-info mt-3 py-2 text-center" role="alert">
                        New Available Stock: <strong id="modalNewAvailable">0</strong> units
                    </div>
                    
                    <p class="text-danger small mt-2 fw-bold" id="stockWarning" style="display: none;">
                        <i class="fas fa-hand-paper"></i> Error: Available units cannot be less than Currently Borrowed units (<span id="warningItemsOut"></span>).
                    </p>

                    <input type="hidden" name="apparatus_id" id="modalUpdateApparatusId">
                    <input type="hidden" name="quick_update_stock" value="1">
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-primary" id="confirmQuickUpdate">Confirm Update</button>
                </div>
            </form>
        </div>
    </div>
</div>

<div class="modal fade" id="unitManageModal" tabindex="-1" aria-labelledby="unitManageModalLabel" aria-hidden="true">
    <div class="modal-dialog modal-dialog-scrollable modal-lg">
        <div class="modal-content">
            <div class="modal-header bg-info text-white">
                <h5 class="modal-title" id="unitManageModalLabel">
                    <i class="fas fa-boxes me-2"></i> Manage Individual Units: <span id="modalUnitAppName"></span>
                </h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body">
                <p class="text-muted small">Use the actions below to adjust the condition of damaged/lost units (e.g., repair or recovery). Units marked **Damaged** or **Lost** are considered **Unavailable**.</p>
                <div class="table-responsive">
                    <table class="table table-sm table-bordered align-middle">
                        <thead>
                            <tr>
                                <th style="width: 10%">Unit ID</th>
                                <th style="width: 25%">Serial Number</th>
                                <th style="width: 20%">Condition</th>
                                <th style="width: 20%">Status</th>
                                <th style="width: 25%">Actions</th>
                            </tr>
                        </thead>
                        <tbody id="unitListBody">
                            <tr><td colspan="5" class="text-center text-muted">Loading unit data...</td></tr>
                        </tbody>
                    </table>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Close</button>
            </div>
        </div>
    </div>
</div>

<div class="modal fade" id="restoreConfirmModal" tabindex="-1" aria-labelledby="restoreConfirmModalLabel" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title" id="restoreConfirmModalLabel">
                    <i class="fas fa-wrench me-2"></i> Confirm Unit Restoration
                </h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body text-center">
                <p>Are you sure you want to restore Unit ID <span id="modalRestoreUnitId" class="fw-bold"></span> to GOOD/AVAILABLE status?</p>
                <p class="text-success fw-bold">This will decrement the Damaged/Lost count and increase the Available Stock.</p>
            </div>
            <div class="modal-footer justify-content-center">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                <button type="button" class="btn btn-success" id="confirmRestoreBtn">OK</button>
            </div>
        </div>
    </div>
</div>

<div class="modal fade" id="deleteApparatusModal" tabindex="-1" aria-labelledby="deleteApparatusModalLabel" aria-hidden="true">
  <div class="modal-dialog">
    <div class="modal-content">
      <div class="modal-header bg-danger text-white">
        <h5 class="modal-title" id="deleteApparatusModalLabel">Confirm Permanent Deletion</h5>
        <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
      </div>
      <form method="POST" id="deleteApparatusForm">
          <div class="modal-body">
              <p>You are about to permanently delete <span id="apparatusName" class="fw-bold"></span> (ID: <span id="apparatusId" class="fw-bold"></span>) from the inventory.</p>
              <p class="fw-bold text-danger">WARNING: This action is irreversible. The apparatus record and its image file will be permanently removed.</p>
              
              <input type="hidden" name="apparatus_id" id="modalApparatusId">
              <input type="hidden" name="apparatus_image" id="modalApparatusImage">
              <input type="hidden" name="delete_apparatus" value="1">
          </div>
          <div class="modal-footer">
            <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
            <button type="submit" class="btn btn-danger">Yes, Permanently Delete</button>
          </div>
      </form>
    </div>
  </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
<script src="https://code.jquery.com/jquery-3.6.0.min.js"></script> 
<script>
    // Global variable to hold the ID of the unit currently being restored
    let currentUnitIdToRestore = null;
    
    // --- START: JAVASCRIPT FOR SYSTEM NOTIFICATION (Bell Alert) - ADDED ---

    // 1. New global function to handle marking ALL staff notifications as read
    window.markAllStaffAsRead = function() {
        // We use the generalized API endpoint for staff batch read
        $.post('../api/mark_notification_as_read.php', { mark_all: true, role: 'staff' }, function(response) {
            if (response.success) {
                // Reload the page to clear the badge and update the list
                window.location.reload(); 
            } else {
                console.error("Failed to mark all staff notifications as read.");
                alert("Failed to clear all notifications.");
            }
        }).fail(function() {
            console.error("API call failed.");
        });
    };
    
    // 2. New global function to handle single notification click (Mark as read + navigate)
    window.handleNotificationClick = function(event, element, notificationId) {
        event.preventDefault(); 
        const linkHref = element.getAttribute('href');

        // Use the generalized API endpoint to mark the single alert as read
        $.post('../api/mark_notification_as_read.php', { notification_id: notificationId, role: 'staff' }, function(response) {
            if (response.success) {
                // Navigate after marking as read
                window.location.href = linkHref;
            } else {
                console.error("Failed to mark notification as read. Navigating anyway.");
                window.location.href = linkHref;
            }
        }).fail(function() {
            console.error("API call failed. Navigating anyway.");
            window.location.href = linkHref;
        });
    };

    // 3. Function to fetch the count and populate the dropdown
    function fetchStaffNotifications() {
        // NOTE: Uses the generalized API endpoint
        const apiPath = '../api/get_notifications.php'; 

        $.getJSON(apiPath, function(response) { 
            
            const unreadCount = response.count; 
            const notifications = response.alerts || []; 
            
            const $badge = $('#notification-bell-badge');
            const $dropdown = $('#notification-dropdown');
            
            // Find static elements
            const $markAllLink = $dropdown.find('.mark-all-link').detach();
            const $viewAllLink = $dropdown.find('a[href="staff_pending.php"]').detach();

            // Clear previous dynamic items
            $dropdown.find('.dynamic-notif-item').remove(); 
            
            // 1. Update the Badge Count
            $badge.text(unreadCount);
            $badge.toggle(unreadCount > 0); 

            // 2. Populate the Dropdown Menu
            const $placeholder = $dropdown.find('.dynamic-notif-placeholder').empty();
            
            if (notifications.length > 0) {

                notifications.slice(0, 5).forEach(notif => {
                    
                    let iconClass = 'fas fa-info-circle text-info'; 
                    if (notif.type.includes('form_pending')) {
                         iconClass = 'fas fa-hourglass-half text-warning';
                    } else if (notif.type.includes('checking')) {
                         iconClass = 'fas fa-redo text-primary';
                    }
                    
                    const is_read = notif.is_read == 1;
                    const itemClass = is_read ? 'text-muted' : 'fw-bold'; // Highlight unread

                    $placeholder.append(`
                        <a class="dropdown-item d-flex align-items-center dynamic-notif-item ${itemClass}" 
                            href="${notif.link}"
                            data-id="${notif.id}"
                            onclick="handleNotificationClick(event, this, ${notif.id})">
                            <div class="me-3"><i class="${iconClass} fa-fw"></i></div>
                            <div>
                                <div class="small text-gray-500">${notif.created_at.split(' ')[0]}</div>
                                <span class="d-block">${notif.message}</span>
                            </div>
                        </a>
                    `);
                });
            } else {
                // Display a "No Alerts" message
                $placeholder.html(`
                    <a class="dropdown-item text-center small text-muted dynamic-notif-item">No New Notifications</a>
                `);
            }
            
            // Re-append the Mark All link and View All link in order
            if (unreadCount > 0) {
                   $dropdown.append($markAllLink);
            }
            $dropdown.append($viewAllLink);
            

        }).fail(function(jqXHR, textStatus, errorThrown) {
            console.error("Error fetching staff notifications:", textStatus, errorThrown);
            // Ensure the badge is hidden on failure
            $('#notification-bell-badge').text('0').hide();
        });
    }
    // --- END: JAVASCRIPT FOR SYSTEM NOTIFICATION ---


    // --- Core Logic to Trigger Submission (Used by the new modal) ---
    function executeRestoreUnitSubmission() {
        if (currentUnitIdToRestore) {
            // This function creates a hidden form and submits it to staff_apparatus.php
            const form = document.createElement('form');
            form.method = 'POST';
            form.action = 'staff_apparatus.php'; 
            
            const idInput = document.createElement('input');
            idInput.type = 'hidden';
            idInput.name = 'unit_to_restore';
            idInput.value = currentUnitIdToRestore;
            form.appendChild(idInput);
            
            document.body.appendChild(form);
            form.submit();
        }
    }
    
    function previewImage(inputId, imgId) {
        const fileInput = document.getElementById(inputId);
        const previewImg = document.getElementById(imgId);

        if (fileInput.files && fileInput.files[0]) {
            const reader = new FileReader();
            reader.onload = function(e) {
                previewImg.src = e.target.result;
                previewImg.style.display = 'block';
            };
            reader.readAsDataURL(fileInput.files[0]);
        } else {
            previewImg.style.display = 'none';
            previewImg.src = '#';
        }
    }
    window.previewImage = previewImage; // Make accessible globally


    // --- NEW FIX: This function MUST only open the Bootstrap modal ---
    window.handleRestoreUnit = function(unitId) {
        currentUnitIdToRestore = unitId;
        // 1. Set the unit ID in the modal text
        document.getElementById('modalRestoreUnitId').textContent = unitId;
        
        // 2. Display the Bootstrap Modal
        const restoreConfirmModal = new bootstrap.Modal(document.getElementById('restoreConfirmModal'));
        restoreConfirmModal.show();
    }

    // --- JS Initialization ---
    document.addEventListener('DOMContentLoaded', () => {
        // Sidebar active link script
        const path = window.location.pathname.split('/').pop();
        const links = document.querySelectorAll('.sidebar .nav-link');
        
        links.forEach(link => {
            const linkPath = link.getAttribute('href').split('/').pop();
            if (linkPath === path) {
                link.classList.add('active');
            } else {
                link.classList.remove('active');
            }
        });
        
        // Delete Modal Handler (Existing)
        const deleteModal = document.getElementById('deleteApparatusModal');
        if (deleteModal) {
            deleteModal.addEventListener('show.bs.modal', function (event) {
                const button = event.relatedTarget;
                const id = button.getAttribute('data-id');
                const name = button.getAttribute('data-name');
                const image = button.getAttribute('data-image');
                
                deleteModal.querySelector('#apparatusName').textContent = name;
                deleteModal.querySelector('#apparatusId').textContent = id;
                deleteModal.querySelector('#modalApparatusId').value = id;
                deleteModal.querySelector('#modalApparatusImage').value = image;
            });
        }

        // Popover Initialization (Existing)
        const popoverTriggerList = document.querySelectorAll('[data-bs-toggle="popover"]');
        [...popoverTriggerList].map(popoverTriggerEl => new bootstrap.Popover(popoverTriggerEl));


        // --- QUICK UPDATE MODAL LOGIC FOR QUANTITIES (EXISTING) ---
        const quickUpdateModal = document.getElementById('quickUpdateModal');
        const modalTotalStock = document.getElementById('modalTotalStock');
        const modalDamagedStock = document.getElementById('modalDamagedStock');
        const modalLostStock = document.getElementById('modalLostStock');
        const modalNewAvailable = document.getElementById('modalNewAvailable');
        const modalItemsOut = document.getElementById('modalItemsOut');
        const stockWarning = document.getElementById('stockWarning');
        const warningItemsOut = document.getElementById('warningItemsOut');
        const confirmQuickUpdate = document.getElementById('confirmQuickUpdate');
        
        let currentlyOut = 0;

        function calculateAvailable() {
            const total = parseInt(modalTotalStock.value) || 0;
            const damaged = parseInt(modalDamagedStock.value) || 0;
            const lost = parseInt(modalLostStock.value) || 0;
            
            const physicalAvailable = total - damaged - lost;
            const actualPhysicalGoodStock = physicalAvailable;

            // This calculates the total number of units that can be borrowed/are available.
            // It must be at least 0. If stock levels are reduced while items are out, 
            // the new 'available' stock will reflect the reduction but won't go below zero.
            modalNewAvailable.textContent = Math.max(0, actualPhysicalGoodStock - currentlyOut); 
            
            if (physicalAvailable < currentlyOut) {
                stockWarning.style.display = 'block';
                modalNewAvailable.classList.remove('text-success');
                modalNewAvailable.classList.add('text-danger');
                confirmQuickUpdate.disabled = true;
            } else {
                stockWarning.style.display = 'none';
                modalNewAvailable.classList.remove('text-danger');
                modalNewAvailable.classList.add('text-success');
                confirmQuickUpdate.disabled = false;
            }
        }
        
        if (modalTotalStock) modalTotalStock.addEventListener('input', calculateAvailable);
        if (modalDamagedStock) modalDamagedStock.addEventListener('input', calculateAvailable);
        if (modalLostStock) modalLostStock.addEventListener('input', calculateAvailable);


        if (quickUpdateModal) {
            quickUpdateModal.addEventListener('show.bs.modal', function (event) {
                const button = event.relatedTarget;
                const id = button.getAttribute('data-id');
                const name = button.getAttribute('data-name');
                const total = parseInt(button.getAttribute('data-total'));
                const damaged = parseInt(button.getAttribute('data-damaged'));
                const lost = parseInt(button.getAttribute('data-lost'));
                currentlyOut = parseInt(button.getAttribute('data-out'));
                
                modalItemsOut.textContent = currentlyOut;
                warningItemsOut.textContent = currentlyOut;

                quickUpdateModal.querySelector('#modalAppName').textContent = name;
                quickUpdateModal.querySelector('#modalAppId').textContent = id;
                modalTotalStock.value = total;
                modalDamagedStock.value = damaged;
                modalLostStock.value = lost;
                
                quickUpdateModal.querySelector('#modalUpdateApparatusId').value = id;
                
                calculateAvailable(); 
            });
        }
        
        // --- UNIT MANAGEMENT MODAL LOGIC (AJAX rendering) ---
        const unitManageModalElement = document.getElementById('unitManageModal'); // Renamed to Element
        const unitListBody = document.getElementById('unitListBody');

        if (unitManageModalElement) {
            unitManageModalElement.addEventListener('show.bs.modal', function (event) {
                const button = event.relatedTarget;
                const typeId = button.getAttribute('data-type-id');
                const name = button.getAttribute('data-name');
                
                unitManageModalElement.querySelector('#modalUnitAppName').textContent = name;
                unitListBody.innerHTML = '<tr><td colspan="5" class="text-center text-muted"><i class="fas fa-spinner fa-spin me-2"></i>Loading unit data...</td></tr>';
                
                // --- AJAX Fetch Call to staff_get_units.php ---
                fetch(`staff_get_units.php?type_id=${typeId}`)
                    .then(response => response.json())
                    .then(data => {
                        unitListBody.innerHTML = '';
                        if (data.units && data.units.length > 0) {
                            data.units.forEach(unit => {
                                // Determine if the unit is restorable (Damaged or Lost, but NOT borrowed)
                                const isDamaged = unit.current_condition === 'damaged';
                                const isLost = unit.current_condition === 'lost';
                                const isRestorable = isDamaged || isLost; // FIX: Check for both damaged or lost
                                
                                let buttonHtml;
                                if (isRestorable) {
                                    // Calls the handler function which opens the Bootstrap modal
                                    buttonHtml = `<button type="button" 
                                                            class="btn btn-sm btn-success restore-unit-btn" 
                                                            data-unit-id="${unit.unit_id}">
                                                           <i class="fas fa-wrench me-1"></i> Restore
                                                          </button>`;
                                } else if (unit.current_condition === 'good' && unit.current_status === 'available') {
                                    buttonHtml = `<button class="btn btn-sm btn-secondary" disabled>No Action</button>`;
                                } else {
                                    // For 'borrowed', 'checking', etc.
                                    buttonHtml = `<button class="btn btn-sm btn-dark" disabled>${unit.current_status.charAt(0).toUpperCase() + unit.current_status.slice(1)}</button>`;
                                }
                                
                                const row = `
                                    <tr>
                                        <td>${unit.unit_id}</td>
                                        <td>${unit.serial_number || 'N/A'}</td>
                                        <td>
                                            <span class="status-tag ${unit.current_condition}">
                                                ${unit.current_condition.charAt(0).toUpperCase() + unit.current_condition.slice(1)}
                                            </span>
                                        </td>
                                        <td>${unit.current_status}</td>
                                        <td>${buttonHtml}</td>
                                    </tr>`;
                                unitListBody.insertAdjacentHTML('beforeend', row);
                            });
                            
                            // Attach click listener for restore button (attaches to the new confirmation modal handler)
                            document.querySelectorAll('.restore-unit-btn').forEach(btn => {
                                btn.addEventListener('click', function() {
                                    window.handleRestoreUnit(btn.getAttribute('data-unit-id')); // Call global handler
                                });
                            });
                            
                        } else {
                            unitListBody.innerHTML = '<tr><td colspan="5" class="text-center text-muted">No units found for this apparatus type.</td></tr>';
                        }
                    })
                    .catch(error => {
                        console.error('Error fetching units:', error);
                        unitListBody.innerHTML = '<tr><td colspan="5" class="text-center text-danger">Failed to load unit data. (Check staff_get_units.php)</td></tr>';
                    });
            });
        }
        
        // --- RESTORE CONFIRMATION HANDLER (Final Submit from Modal) ---
        // This is attached to the green 'OK' button inside the Bootstrap modal
        document.getElementById('confirmRestoreBtn').addEventListener('click', function() {
            executeRestoreUnitSubmission(); 
        });

        // --- AUTOHIDE LOGIC ---
        const statusAlert = document.getElementById('status-alert');
        if (statusAlert) {
            if (statusAlert.classList.contains('alert-success') || statusAlert.classList.contains('alert-danger')) {
                setTimeout(() => {
                    const bsAlert = bootstrap.Alert.getInstance(statusAlert) || new bootstrap.Alert(statusAlert);
                    bsAlert.close();
                }, 4000); // 4000 milliseconds = 4 seconds
            }
        }
        
        // NEW: Initial fetch on page load and polling for notifications
        fetchStaffNotifications();
        setInterval(fetchStaffNotifications, 30000); // Poll every 30 seconds

    });
</script>
</body>
</html>