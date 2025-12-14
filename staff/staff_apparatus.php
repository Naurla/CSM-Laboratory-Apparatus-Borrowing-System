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

// --- NEW LOGIC: Determine if the ADD form should be visible (by default, it is hidden)
// Show the ADD form if there were errors on a POST attempt, or if the user explicitly clicked the 'show add' button
$show_add_form = isset($_POST['add_apparatus']) && !empty($errors) || isset($_GET['show_add']);


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
            $show_add_form = false; // Hide form on success
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
    // If we're in error mode, the edit form MUST remain visible
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
        // Assuming the original 'Failed to restore Unit ID 4 due to a database error' came from here
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
        // Store original values as hidden fields for JavaScript comparison
        $original_total_stock = $current_apparatus['total_stock'];
        $original_damaged_stock = $current_apparatus['damaged_stock'];
        $original_lost_stock = $current_apparatus['lost_stock'];
        $currently_out = $current_apparatus['currently_out'];

    } else {
        $success_message = "❌ Apparatus with ID $edit_id not found.";
        $edit_id = null; // Clear edit mode
    }
} else {
    // Set dummy original values if not in edit mode to prevent errors
    $original_total_stock = 0;
    $original_damaged_stock = 0;
    $original_lost_stock = 0;
    $currently_out = 0;
}  


// --- Search, Filter, and Sort Logic (UPDATED) ---  
$filter_status = $_GET['status'] ?? 'all';
// NEW: Apparatus Type Multi-Select Filter
$filter_types = $_GET['apparatus_type'] ?? [];
if (!is_array($filter_types)) {
    $filter_types = [$filter_types]; // Ensure it's an array if only one is selected or it's a string
}

$search_query = $_GET['search'] ?? '';  

// NEW: Sort parameters
$sort_by = $_GET['sort_by'] ?? 'id'; // Default sort
$sort_order = $_GET['sort_order'] ?? 'asc'; // Default order

// We use the updated getAllApparatus() to get the full list
$apparatusList = $transaction->getAllApparatus();

// 1. Filter by Status
if ($filter_status !== 'all') {
    $apparatusList = array_filter($apparatusList, function($item) use ($filter_status) {
        return strtolower($item['status']) === strtolower($filter_status);
    });
}

// 2. Filter by Apparatus Type (Multi-Select)
if (!empty($filter_types) && !in_array('all', $filter_types)) {
    $apparatusList = array_filter($apparatusList, function($item) use ($filter_types) {
        return in_array($item['apparatus_type'], $filter_types);
    });
}

// 3. Filter by Search Query
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

// 4. Sorting Logic (Multi-Sortable)
$sort_fields = [
    'id' => 'id',
    'name' => 'name',
    'stock' => 'total_stock',
];

if (isset($sort_fields[$sort_by])) {
    $field = $sort_fields[$sort_by];
    $is_numeric = in_array($sort_by, ['id', 'stock']);
    $multiplier = ($sort_order === 'asc') ? 1 : -1;

    usort($apparatusList, function($a, $b) use ($field, $is_numeric, $multiplier) {
        $a_val = $a[$field];
        $b_val = $b[$field];

        if ($is_numeric) {
            return ($a_val <=> $b_val) * $multiplier;
        } else {
            return strcasecmp($a_val, $b_val) * $multiplier;
        }
    });
}


// --- START PAGINATION LOGIC ---
$items_per_page = 10; // Define how many items per page
$total_items = count($apparatusList); // Total items AFTER filtering/searching/sorting
$total_pages = ceil($total_items / $items_per_page); // Calculate total pages

// Get current page from URL, default to 1, or clamp between 1 and total_pages
$current_page = isset($_GET['page']) ? (int)$_GET['page'] : 1;
if ($current_page < 1) $current_page = 1;
if ($current_page > $total_pages) $current_page = max(1, $total_pages); // Ensure it's at least 1

// Calculate the offset for array_slice
$offset = ($current_page - 1) * $items_per_page;

// Slice the array to get only the items for the current page
$paginatedApparatusList = array_slice($apparatusList, $offset, $items_per_page);

// Function to construct the base URL with current filters/search (excluding 'page')
function getBaseUrl($currentPage = 1, $remove_param = null) {
    $params = $_GET;
    $params['page'] = $currentPage;
    // Keep 'edit_id' out of base URL construction if it exists, to keep page clean.
    unset($params['edit_id']);
    // 'message' is also typically excluded for clean navigation.
    unset($params['message']);
    
    // Allow removing a specific parameter (useful for clearing search or a single type filter)
    if ($remove_param && isset($params[$remove_param])) {
        unset($params[$remove_param]);
    }
    
    // NEW: Handle array parameters for apparatus_type
    $query = http_build_query($params);
    
    // Special handling for the type filter array if present
    if (isset($_GET['apparatus_type']) && is_array($_GET['apparatus_type']) && !empty($_GET['apparatus_type'])) {
        // Re-encode array parameters manually if needed to ensure correct format (http_build_query should handle this, but for explicit control)
        // Check if the parameter is already represented in the query string and replace
        $type_params = http_build_query(['apparatus_type' => $_GET['apparatus_type']]);
        if (str_contains($query, 'apparatus_type%5B%5D=')) {
            // Remove old type array serialization and append new one
            $query = preg_replace('/apparatus_type%5B%5D=[^&]*/', '', $query);
            // Clean up double ampersands
            $query = str_replace('&&', '&', $query);
            $query = rtrim($query, '&');
            if ($query) $query .= '&';
            $query .= $type_params;
        }
    }
    
    return 'staff_apparatus.php?' . $query;
}

// Function to construct the URL for sorting
function getSortUrl($column) {
    global $sort_by, $sort_order, $filter_status, $search_query, $filter_types;
    $new_order = 'asc';
    if ($sort_by === $column && $sort_order === 'asc') {
        $new_order = 'desc';
    }
    
    $params = [
        'status' => $filter_status,
        'search' => $search_query,
        'sort_by' => $column,
        'sort_order' => $new_order
    ];
    
    // Add type filters back as array
    if (!empty($filter_types)) {
        $params['apparatus_type'] = $filter_types;
    }
    
    // Filter out empty params
    $params = array_filter($params, fn($value) => $value !== null && $value !== '');

    return 'staff_apparatus.php?' . http_build_query($params);
}

// Get all unique apparatus types for the filter options
$allApparatusTypes = $transaction->getUniqueApparatusTypes(); // Assuming a method exists in Transaction class to fetch unique types

// --- END PAGINATION LOGIC ---
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
        --msu-red: #A40404;
        --msu-red-dark: #820303;
        --msu-blue: #007bff;
        --sidebar-width: 280px;
        --header-height: 60px;
        --student-logout-red: #dc3545;
        --base-font-size: 15px;
        --main-text: #333;
        --card-background: #fcfcfc;
        --label-bg: #e9ecef;
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
    
    /* --- NEW CSS ADDITION: Hides burger button when any modal is open --- */
    body.modal-open .menu-toggle {
        display: none !important;
    }
    /* --- END NEW CSS ADDITION --- */
    
    /* --- BURGER BUTTON FIX: Always visible on desktop for sidebar collapse, CENTERED ICON --- */
    .menu-toggle {
        /* FIX FOR REQUEST: Hide the button completely on DESKTOP */
        display: none;  
        
        /* Original styles (retained for mobile only via media query) */
        position: fixed;
        top: 15px;
        left: calc(var(--sidebar-width) + 20px);  
        z-index: 1060;  
        background: var(--msu-red);
        color: white;
        border: none;
        padding: 8px 12px;
        border-radius: 6px;
        font-size: 1.2rem;
        box-shadow: 0 2px 5px rgba(0,0,0,0.2);
        transition: left 0.3s ease;  
        display: flex;
        justify-content: center;
        align-items: center;
        width: 44px;  
        height: 44px;  
        padding: 0;  
    }
    
    /* NEW CLASS: When sidebar is closed (Desktop collapse mode) - RETAIN FOR TRANSITION LOGIC*/
    .sidebar.closed {
        left: calc(var(--sidebar-width) * -1); /* Move sidebar off-screen */
    }
    .sidebar.closed ~ .menu-toggle {
        left: 20px; /* Shift button back to the left edge of the screen */
    }
    /* FIX: When sidebar is permanently open on desktop, main content and header must shift */
    .top-header-bar {
        left: var(--sidebar-width); /* Default desktop position */
        background-color: #fcfcfc; /* Lighter header background for contrast */
    }
    .main-content {
        margin-left: var(--sidebar-width); /* Default desktop position */
    }
    /* The .closed classes below are now essentially unused on desktop since it's permanently open */
    .sidebar.closed ~ .top-header-bar {
        left: 0;  
    }
    .sidebar.closed ~ .main-content {
        margin-left: 0;  
        width: 100%;
    }
    /* --- END BURGER BUTTON FIX --- */


    /* NEW: Backdrop for mobile sidebar */
    .sidebar-backdrop {
        position: fixed;
        top: 0;
        left: 0;
        right: 0;
        bottom: 0;
        background-color: rgba(0, 0, 0, 0.5);
        z-index: 1000;
        display: none;
        opacity: 0;
        transition: opacity 0.3s ease;
    }
    /* When sidebar is active (mobile overlay mode), show backdrop */
    .sidebar.active ~ .sidebar-backdrop {
        display: block;
        opacity: 1;
    }


    /* --- Top Header Bar Styles (COPIED FROM DASHBOARD) --- */
    .top-header-bar {
        position: fixed;
        top: 0;
        left: var(--sidebar-width); /* Start from the right edge of the fixed sidebar */
        right: 0;
        height: var(--header-height);
        background-color: #fcfcfc; /* Lighter background */
        border-bottom: 1px solid #ddd;
        box-shadow: 0 2px 5px rgba(0,0,0,0.05);
        display: flex;
        align-items: center;
        justify-content: flex-end;
        padding: 0 30px;
        /* FIX: Set a high Z-index so it sits above all content and the sidebar (when closed) */
        z-index: 1050;
        transition: left 0.3s ease; /* For smooth desktop collapse */
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
        color: var(--main-text); /* Use main text color for readability */
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
        left: 0; /* Always visible on desktop now */
        display: flex;
        flex-direction: column;
        box-shadow: 2px 0 5px rgba(0, 0, 0, 0.2);
        z-index: 1010;
        transition: left 0.3s ease; /* For smooth desktop collapse/expand */
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
        /* REMOVED: Gold accent border for consistency */
        /* border-left: 5px solid #ffc107;  
        padding-left: 20px; */
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
        transition: margin-left 0.3s ease, width 0.3s ease; /* For smooth desktop collapse */
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
        color: var(--msu-red); /* Changed to MSU red for form headings */
        font-weight: 700; /* Made bolder */
        font-size: 1.4rem;
        border-bottom: 1px solid var(--msu-red); /* Match theme red */
        padding-bottom: 10px;
        margin-bottom: 20px;
    }

    /* Form Styling - Card separation */
    .add-form {
        padding: 30px;
        border: none; /* Removed original border */
        border-radius: 12px;
        box-shadow: 0 4px 12px rgba(0,0,0,0.08); /* Stronger shadow for card effect */
        margin-bottom: 30px;
        background-color: #ffffff;
    }
    .add-form label {
        font-weight: 600;
        margin-top: 10px;
        font-size: 0.95rem;
    }
    
    /* Form Inputs */
    .form-control, .form-select, textarea {
        font-size: 1rem;
    }
    .edit-image-preview {
        max-width: 150px;
        max-height: 150px; /* Constrain height too */
        display: block;
        margin-top: 10px;
        border: 2px solid #ddd;
        border-radius: 5px;
        object-fit: contain;
        padding: 5px;
    }


    .table-responsive {
        border-radius: 8px;
        overflow-x: auto; /* Keeps scroll inside this container */
        box-shadow: 0 2px 10px rgba(0,0,0,0.05);
        margin-top: 20px;
    }
    
    /* Ensure the table doesn't collapse but has a defined width for scrolling */
    .table {
        /* Adjusted minimum width to account for removed columns. Removed 'Unit Cond.' column */
        min-width: 1100px;  
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
    /* Style for sortable columns */
    .table thead th.sortable {
        cursor: pointer;
    }
    .table thead th .sort-icon {
        margin-left: 5px;
        opacity: 0.6;
    }
    .table thead th.sorted .sort-icon {
        opacity: 1;
    }
    .table tbody td {
        vertical-align: middle;
        font-size: 0.95rem;
        padding: 8px 4px;
        text-align: center;
    }
    /* Left align Name and Description in table */
    .table tbody tr td:nth-child(3),  
    .table tbody tr td:nth-child(11) { /* Changed from 12 to 11 */
        text-align: left !important;
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
        display: inline-flex;
        padding: 5px 8px; /* Tighter padding */
        border-radius: 16px;
        font-weight: 700;
        text-transform: capitalize;
        font-size: 0.85rem;
        line-height: 1.2;
        white-space: nowrap;
        box-shadow: 0 1px 2px rgba(0,0,0,0.1);
        justify-content: center;
        align-items: center;
        min-width: 80px;
    }
    /* Status Tag Colors (Used for Type Status) */
    /* Removed .status-tag.good, .damaged, .lost from here as 'Unit Cond.' column is removed */

    .status-tag.available { background-color: #28a745; color: white; } /* Type Status */
    .status-tag.borrowed { background-color: #0d6efd; color: white; } /* Type Status */
    .status-tag.reserved { background-color: #17a2b8; color: white; } /* Type Status */
    .status-tag.unavailable { background-color: #343a40; color: white; } /* Type Status */
    

    .action-buttons-group {
        display: flex;
        gap: 10px; /* Increased gap */
        justify-content: space-evenly; /* Spread out buttons slightly */
        align-items: center;
        flex-wrap: nowrap;
    }
    
    .action-buttons-group .btn {
        padding: 7px 11px;
        font-size: 0.9rem;
        white-space: nowrap;
        box-shadow: 0 1px 3px rgba(0,0,0,0.1);
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
    
    /* Default button styling (Theming Consistency - MSU Red) */
    .btn-primary {  
        background-color: var(--msu-red) !important;  
        border-color: var(--msu-red) !important;  
        color: white !important;  
        transition: background-color 0.2s;
    }
    .btn-primary:hover {  
        background-color: var(--msu-red-dark) !important;  
        border-color: var(--msu-red-dark) !important;  
    }
    /* Edit button should stay warning color for consistency */
    .btn-warning { background-color: #ffc107; border-color: #ffc107; color: #212529 !important; }

    .btn-info { background-color: #17a2b8; border-color: #17a2b8; color: white !important; }
    .btn-danger { background-color: #dc3545; border-color: #dc3545; }
    
    /* Description cell needs space but must wrap */
    /* Index 11 is the Description column now (was 12) */
    .table tbody td:nth-child(11) {  
        max-width: 200px;
        white-space: normal;
        word-wrap: break-word;
    }
    
    /* Modal Custom Style for Warning (Unchanged) */
    #unitManageModal .modal-header { background-color: var(--msu-blue); color: white; }
    #restoreConfirmModal .modal-header { background-color: #ffc107; color: #333; }

    /* NEW: Edit Stock Modal Header */
    #editStockConfirmModal .modal-header { background-color: #17a2b8; color: white; }

    /* --- Pagination UI Improvement (Matches Image) --- */
    /* FIX: Change flex-column to flex-wrap and justify-content */
    .pagination-container {
        padding: 10px 0;
        border-top: 1px solid #e9ecef;
        display: flex;
        justify-content: space-between; /* Spread items across the container */
        align-items: center;
        flex-wrap: wrap; /* Allows stacking on small screens */
    }

    .pagination {
        --bs-pagination-font-size: 0.95rem; /* Slightly larger text */
    }

    /* DEFAULT STATE (Next, Numbers, etc.): White bg, Red text */
    .pagination .page-item .page-link {
        color: var(--msu-red); /* Red text */
        background-color: #fff;
        border: 1px solid #dee2e6;
        font-weight: 500;
        display: flex;
        align-items: center;
        justify-content: center;
        padding: 0.5rem 0.75rem;
    }

    /* HOVER STATE */
    .pagination .page-item .page-link:hover {
        color: var(--msu-red-dark);
        background-color: #e9ecef;
        border-color: #dee2e6;
    }

    /* ACTIVE STATE (The '1' in the image): Red bg, White text */
    .pagination .page-item.active .page-link {
        background-color: var(--msu-red) !important;
        border-color: var(--msu-red) !important;
        color: white !important;
        z-index: 1;
    }

    /* DISABLED STATE (The 'Previous' button in the image): Gray bg */
    .pagination .page-item.disabled .page-link {
        color: #6c757d;
        pointer-events: none;
        background-color: #e9ecef;
        border-color: #dee2e6;
    }

    .pagination-info {
        font-size: 0.9rem;
        color: #6c757d;
        font-weight: 500;
        margin-top: 10px; /* Keep margin for vertical spacing on smaller screens */
    }
    
    /* NEW: Multi-select dropdown styling fix for BS5 */
    .dropdown-menu-checkbox .dropdown-item {
        cursor: pointer;
        padding: 0.25rem 1rem;
    }
    .dropdown-menu-checkbox .dropdown-item.active {
        background-color: #f8f9fa; /* Light background for selected item */
        color: #212529; /* Dark text */
        font-weight: 600;
    }
    .dropdown-menu-checkbox .form-check-input {
        margin-right: 0.5rem;
    }

    /* --- RESPONSIVE ADJUSTMENTS --- */
    @media (max-width: 1200px) {
        /* Optimize column layout for larger tablets/smaller laptops (2 columns) */
        .add-form .col-md-4 {
            flex: 0 0 50%;
            max-width: 50%;
        }
        .add-form .col-12 {
            flex: 0 0 100%;
            max-width: 100%;
        }
        /* Tighten table spacing */
        .table thead th {
             font-size: 0.9rem;
             padding: 8px 4px;
        }
        .table tbody td {
             font-size: 0.9rem;
             padding: 6px 3px;
        }
        .table {
            min-width: 900px; /* Adjusted min width */
        }
        /* Keep edit/delete side-by-side or expand */
        .action-buttons-group:last-child {
             flex-wrap: nowrap;
        }
        .action-buttons-group:last-child .btn-icon-only {
             flex-grow: 1;
             width: 48%; /* Adjust for gap */
        }
    }
    
    @media (max-width: 992px) {
        /* Mobile Sidebar Toggle: Always show the button and position it */
        .menu-toggle {
            display: flex; /* Ensure it stays flex/block */
            left: 20px;
        }
        /* Set a smaller default width for mobile sidebar */
        .sidebar {
            left: calc(var(--sidebar-width) * -1);
            transition: left 0.3s ease;
            box-shadow: none;
            --sidebar-width: 250px;
        }
        .sidebar.active {
            left: 0;
            box-shadow: 2px 0 5px rgba(0, 0, 0, 0.2);
        }
        /* On desktop, the .closed class handles the non-mobile collapse state */
        .sidebar.closed {
            left: calc(var(--sidebar-width) * -1);
        }


        /* Main Content and Header Adjustments */
        .main-content {
            margin-left: 0;
            padding-left: 15px;
            padding-right: 15px;
            padding-top: calc(var(--header-height) + 15px); /* Adjusted top padding */
        }
        .top-header-bar {
            left: 0;
            padding-left: 70px; /* Space for the menu toggle button */
            padding-right: 15px;
        }
        .content-area { padding: 25px; } /* Adjust padding for smaller screens */
        h2 { font-size: 1.8rem; }
        h3 { font-size: 1.3rem; }
        
        /* Form responsiveness: full stack on tablet portrait */
        .add-form { padding: 15px; }
        .add-form .col-md-4 {
            flex: 0 0 100%;
            max-width: 100%;
        }
        
        /* Table responsiveness for 992px+ */
        .table { min-width: 900px; /* Ensure horizontal scroll for tables */ }
        .table thead th { font-size: 0.85rem; }
        
        /* Force ALL actions to stack vertically here for clarity */
        /* NOTE: Since Stock/Unit Actions are removed, this mainly impacts Edit/Delete */
        .action-buttons-group {
            flex-direction: column;
            align-items: stretch;
            flex-wrap: nowrap;
        }
        .action-buttons-group .btn {
            width: 100%;
            margin-bottom: 5px;
        }
        .action-buttons-group:last-child {
            flex-direction: row; /* Put edit/delete side-by-side */
            margin-top: 5px;
            gap: 5px;
        }
        .action-buttons-group:last-child .btn-icon-only {
            flex-grow: 1;
            width: auto;
        }

        /* Pagination on smaller screens should stack for space */
        .pagination-container {
            flex-direction: column;
            align-items: center;
        }
        .pagination-info {
            order: 2; /* Move info below pagination nav */
        }
        .pagination {
            order: 1;
            margin-bottom: 10px;
        }
    }
    
    @media (max-width: 768px) {
        /* General content area padding adjustment */
        .main-content { padding-left: 10px; padding-right: 10px; }
        .content-area { padding: 15px; }

        /* Full mobile table stacking */
        .table thead { display: none; }
        .table tbody, .table tr, .table td { display: block; width: 100%; }
        .table { min-width: auto; }
        
        /* === MOBILE CARD AESTHETICS IMPROVEMENT (CLEAN HIERARCHY) === */
        .table tr {
            margin-bottom: 15px;
            border: 1px solid #ccc;
            border-left: 5px solid var(--msu-red); /* Highlight left border */
            border-radius: 8px;
            background-color: var(--card-background);
            box-shadow: 0 2px 4px rgba(0, 0, 0, 0.05);
            padding: 0;
            overflow: hidden;
        }
        
        /* Reset table cell positioning for the card layout */
        .table td {
            text-align: right !important;
            padding-left: 50% !important;
            position: relative;
            border: none;
            border-bottom: 1px solid #eee; /* Lighter border separation */
            padding: 10px 10px !important;
        }
        .table td:last-child { border-bottom: none; }
        
        /* --- 1. Header Group (ID, Image, Name) --- */
        .table tbody td:nth-child(1) { /* ID: Top-left pill */
            text-align: left !important;
            padding-left: 10px !important;
            font-size: 0.9rem;
            font-weight: 600;
            color: #6c757d;
            background-color: #f8f8f8;
            border-bottom: 1px solid #ddd;
        }
        .table tbody td:nth-child(1)::before {
            display: none; /* Hide ID label */
        }
        
        /* Combine Image and Name into one logical section (Cell 2 is the main container for mobile) */
        .table tbody tr td:nth-child(2) {
            display: flex;
            align-items: center;
            justify-content: flex-start;
            text-align: left !important;
            padding: 15px 10px 15px 10px !important;
            border-bottom: 2px solid #ddd;
            position: relative;
            padding-left: 10px !important;
            width: 100%;
            height: auto;
        }
        .table tbody tr td:nth-child(2)::before {
            display: none; /* Hide Image label */
        }
        .table img {
            width: 70px;
            height: 70px;
            margin-right: 15px;
            flex-shrink: 0;
        }
        
        .table tbody tr td:nth-child(3) { /* Name cell - desktop only, hide it entirely in mobile view */
             display: none;
        }
        
        /* --- Use data-name-label in cell 2 for the name content in mobile --- */
        .table tbody tr td:nth-child(2) .d-md-none { /* Target the span we added for the name */
             display: block !important;
             font-size: 1.1rem;
             font-weight: 700;
             color: var(--msu-red-dark);
        }
        .table tbody tr td:nth-child(2) .d-md-none::before {
             content: attr(data-name-label); /* Show the implicit label in mobile name block */
             display: block;
             font-weight: 500;
             font-size: 0.85rem;
             color: #6c757d;
        }
        
        /* --- 2. Standard Labels (Clean Look) --- */
        .table td::before {
            content: attr(data-label);
            /* CRITICAL FIX: Removing background from the label area */
            background-color: transparent;
            border-right: none;
            /* END FIX */
            color: var(--main-text);
            font-weight: 600;
            font-size: 0.9rem;
            
            position: absolute;
            height: 100%;
            display: flex;
            align-items: center;
            left: 0;
            width: 50%;
            padding: 10px; /* Add padding back to label */
        }
        /* Ensure standard cells have a light background for data alignment */
        /* Columns adjusted after removal of Unit Cond. */
        .table tbody td:nth-child(4),
        .table tbody td:nth-child(5),
        .table tbody td:nth-child(6),
        .table tbody td:nth-child(7),
        .table tbody td:nth-child(8),
        .table tbody td:nth-child(9),
        .table tbody td:nth-child(10),
        .table tbody td:nth-child(12) {
            display: block;
            width: 100%;
            border-bottom: 1px solid #eee;
            background-color: var(--card-background);
        }


        /* --- 3. Highlight Critical Stock (FIXED) --- */
        
        /* Apply background to the ENTIRE Damaged TD (index 9 now) */
        .table tbody td:nth-child(9) {
            background-color: #fff3cd !important; /* Warning light background */
        }
        /* Ensure the Damaged label text is dark over the light background */
        .table tbody td:nth-child(9)::before {
            background-color: transparent !important; /* Clear the pseudo-element background */
            color: #856404; /* Dark text color for readability */
        }
        
        /* Apply background to the ENTIRE Lost TD (index 10 now) */
        .table tbody td:nth-child(10) {
            background-color: #f8d7da !important; /* Danger light background */
        }
        /* Ensure the Lost label text is dark over the light background */
        .table tbody td:nth-child(10)::before {
            background-color: transparent !important; /* Clear the pseudo-element background */
            color: #721c24; /* Dark text color for readability */
        }
        
        /* Revert other cells that might have inherited the background */
        .table tbody td:nth-child(4),
        .table tbody td:nth-child(5),
        .table tbody td:nth-child(6),
        .table tbody td:nth-child(7),
        .table tbody td:nth-child(8),
        .table tbody td:nth-child(11), /* Description (index 11 now) is handled next */
        .table tbody td:nth-child(12) { /* Type Status (index 12 now) */
            background-color: var(--card-background); /* Ensure standard cells are light */
        }
        
        /* --- 4. Description Display (FINAL FIX: EXPANSION and SPACING) --- */
        /* NOTE: Index changed from 12 to 11 */
        .table tbody td:nth-child(11) {
            text-align: left !important;
            /* CRITICAL: Set padding to 10px on all sides, overriding the 50% left padding */
            padding: 10px !important;
            padding-bottom: 15px !important;
            
            font-size: 0.9rem;
            border-bottom: 1px solid #eee;
            display: block;
            width: 100%; /* <<<<< FIX: Full width on mobile */
            background-color: var(--card-background);
            position: static; /* Important for flow */
        }
        
        /* The header/label for the description needs to be displayed as a block *above* the content */
        .table tbody td:nth-child(11)::before {
            content: "Description:";
            display: block;
            position: static; /* Forces it to flow normally, taking up space */
            width: 100%;
            height: auto;
            color: var(--main-text);
            background: #f8f8f8; /* Keep light background for description header */
            font-weight: 600;
            padding: 10px;
            border-bottom: 1px solid #eee;
            margin-bottom: 5px;
            border-right: none;
            /* Ensure the description header padding is correct */
            margin-left: -10px; /* Offset the <td> padding */
            margin-right: -10px; /* Offset the <td> padding */
            padding-left: 10px; /* Restore internal padding */
            padding-right: 10px;
        }

        /* The actual description text (the span) must flow naturally below the header/label */
        .table tbody td:nth-child(11) span {
            display: block; /* <<<<< FIX: Force block display */
            width: 100%;
            white-space: normal;
            word-break: break-word;
            padding: 0; /* Remove inner span padding */
        }

        /* --- 5. Action Buttons (Edit/Delete) --- */
        /* NOTE: Index changed from 15 to 13 (after removing Unit Cond.) */
        .table td:nth-child(13) {
            border-bottom: none;
            padding: 10px 10px 15px 10px !important;
            text-align: center !important;
            padding-left: 10px !important;
            display: block;
            width: 100%;
            background-color: #f8f8f8 !important; /* Highlighted background for actions */
            position: static; /* Ensure it flows correctly */
        }
        .table td:nth-child(13)::before {
            display: none;
        }
        /* Edit/Delete group (13th cell) */
        .table td:nth-child(13) .action-buttons-group {
            flex-direction: row;
            margin-top: 10px;
            gap: 10px;
        }
        .table td:nth-child(13) .action-buttons-group .btn-icon-only {
            flex-grow: 1;
            width: auto;
            height: 44px;
        }
    }

    /* Smallest Mobile adjustments */
    @media (max-width: 576px) {
        .top-header-bar {
            padding: 0 15px;
            justify-content: flex-end;
            padding-left: 65px;
        }
        .table tbody tr td:nth-child(3) {
            font-size: 1rem;
        }
        /* Reset to standard flow for the two icons */
        .table td:nth-child(13) .action-buttons-group {
            flex-direction: row;
        }
    }
</style>

</head>
<body>

<button class="menu-toggle" id="menuToggle" aria-label="Toggle navigation menu">
    <i class="fas fa-bars"></i>
</button>

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

<div class="sidebar-backdrop" id="sidebarBackdrop"></div>

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
                
                <a class="dropdown-item text-center small text-muted mark-all-link" id="mark-all-link"
                    onclick="window.markAllStaffAsRead()" style="display:none;">
                    <i class="fas fa-check-double me-1"></i> Mark All as Read
                </a>

                <div class="dynamic-notif-placeholder">
                    <a class="dropdown-item text-center small text-muted dynamic-notif-item">Loading notifications...</a>
                </div>
                
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

        <div class="d-flex justify-content-end mb-4">
            <?php if ($edit_id): ?>
                <a href="staff_apparatus.php" class="btn btn-outline-secondary me-2">
                    <i class="fas fa-times me-1"></i> Cancel Edit
                </a>
            <?php else: ?>
                <button  
                    class="btn btn-primary"  
                    type="button"  
                    data-bs-toggle="collapse"  
                    data-bs-target="#addApparatusCollapse"  
                    aria-expanded="<?= $show_add_form ? 'true' : 'false' ?>"  
                    aria-controls="addApparatusCollapse"
                    id="toggleAddFormButton">
                    <i class="fas fa-plus me-1"></i> Add New Apparatus
                </button>
            <?php endif; ?>
        </div>
        
        <?php if ($edit_id && $current_apparatus): ?>
            <div class="add-form">
                <h3><i class="fas fa-edit me-2"></i> Edit Apparatus #<?= $edit_id ?></h3>
                <form method="POST" enctype="multipart/form-data" class="row g-3" id="editApparatusForm">
                    <input type="hidden" name="id" value="<?= $edit_id ?>">
                    <input type="hidden" name="old_image" value="<?= htmlspecialchars($current_image) ?>">
                    
                    <input type="hidden" id="original_total_stock" value="<?= $original_total_stock ?>">
                    <input type="hidden" id="original_damaged_stock" value="<?= $original_damaged_stock ?>">
                    <input type="hidden" id="original_lost_stock" value="<?= $original_lost_stock ?>">
                    <input type="hidden" id="currently_out" value="<?= $currently_out ?>">
                    
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
                        <small class="text-muted">Currently Borrowed/Reserved: <?= htmlspecialchars($currently_out) ?> units.</small>
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
                        <textarea name="description" class="form-control" rows="8"><?= htmlspecialchars($description ?? '') ?></textarea>
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
                        <button type="button"  
                                id="saveChangesButton"
                                class="btn btn-primary"
                                onclick="showEditConfirmModal()">
                            <i class="fas fa-sync me-1"></i> Save Changes
                        </button>
                    </div>
                </form>
            </div>
        <?php endif; ?>
        
        <div class="collapse <?= $show_add_form ? 'show' : '' ?>" id="addApparatusCollapse">
            <div class="add-form">
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
                        <label class="form-label">Description (Usage and Details) <span class="text-danger">*</span>:</label>  
                        <textarea name="description" class="form-control" rows="8"><?= htmlspecialchars($description ?? '') ?></textarea>
                        <?php if (isset($errors['description'])): ?><span class="text-danger error"><?= htmlspecialchars($errors['description']) ?></span><?php endif; ?>
                    </div>

                    <div class="col-md-4">
                        <label class="form-label">Upload Image: <span class="text-danger">*</span></label>
                        <input type="file" name="apparatus_image" id="apparatus_image_input" accept="image/*" class="form-control" onchange="previewImage('apparatus_image_input', 'image-preview')">
                        <?php if (isset($errors['image'])): ?><span class="text-danger error"><?= htmlspecialchars($errors['image']) ?></span><?php endif; ?>
                        <?php if (isset($errors['image_upload'])): ?><span class="text-warning error"><?= htmlspecialchars($errors['image_upload']) ?></span><?php endif; ?>
                        
                        <img id="image-preview" src="../uploads/apparatus_images/default.jpg" alt="Image Preview" style="display: none;" class="edit-image-preview">
                    </div>
                    
                    <div class="col-12 text-end">
                        <button type="submit" name="add_apparatus" class="btn btn-primary">
                            <i class="fas fa-save me-1"></i> Add Apparatus
                        </button>
                    </div>
                </form>
            </div>
        </div>
        
        <h3><i class="fas fa-list me-2"></i> Apparatus Inventory</h3>
        
        <div class="d-flex flex-wrap justify-content-between align-items-center mb-3">
            
            <div class="d-flex flex-wrap align-items-center mb-2">
                <label class="form-label me-2 mb-0 fw-bold text-secondary">Status Filter:</label>
                <form method="GET" class="filter-form d-flex align-items-center mb-0 me-3">
                    <input type="hidden" name="search" value="<?= htmlspecialchars($search_query) ?>">
                    <input type="hidden" name="sort_by" value="<?= htmlspecialchars($sort_by) ?>">
                    <input type="hidden" name="sort_order" value="<?= htmlspecialchars($sort_order) ?>">
                    <?php if (!empty($filter_types)): 
                        foreach($filter_types as $type_val): ?>
                            <input type="hidden" name="apparatus_type[]" value="<?= htmlspecialchars($type_val) ?>">
                        <?php endforeach;
                    endif; ?>
                    <select name="status" onchange="this.form.submit()" class="form-select form-select-sm w-auto">
                        <option value="all" <?= $filter_status === 'all' ? 'selected' : '' ?>>Show All Status</option>
                        <option value="available" <?= $filter_status === 'available' ? 'selected' : '' ?>>Available</option>
                        <option value="borrowed" <?= $filter_status === 'borrowed' ? 'selected' : '' ?>>Borrowed</option>
                        <option value="reserved" <?= $filter_status === 'reserved' ? 'selected' : '' ?>>Reserved</option>
                        <option value="unavailable" <?= $filter_status === 'unavailable' ? 'selected' : '' ?>>Unavailable</option>
                    </select>
                </form>

                <div class="dropdown">
                    <button class="btn btn-outline-secondary btn-sm dropdown-toggle" type="button" data-bs-toggle="dropdown" aria-expanded="false" id="typeFilterDropdown">
                        <i class="fas fa-filter me-1"></i> Type (<?= empty($filter_types) || in_array('all', $filter_types) ? 'All' : count($filter_types) ?>)
                    </button>
                    <ul class="dropdown-menu dropdown-menu-checkbox" aria-labelledby="typeFilterDropdown" id="apparatusTypeFilterMenu">
                        <a class="dropdown-item" href="staff_apparatus.php?<?= http_build_query(array_filter(['status' => $filter_status, 'search' => $search_query, 'sort_by' => $sort_by, 'sort_order' => $sort_order], fn($value) => $value !== null && $value !== '')) ?>">
                            <i class="fas fa-times me-2"></i> Clear All Filters
                        </a>
                        <li><hr class="dropdown-divider"></li>
                        <?php foreach ($allApparatusTypes as $appType):  
                            $isSelected = in_array($appType, $filter_types) && !empty($filter_types);
                            $checkClass = $isSelected ? 'fa-check-square' : 'fa-square';
                            $itemClass = $isSelected ? 'active' : '';
                        ?>
                            <li onclick="toggleApparatusType('<?= htmlspecialchars($appType) ?>')">
                                <a class="dropdown-item <?= $itemClass ?>" href="#">
                                    <i class="far <?= $checkClass ?> me-2"></i> <?= htmlspecialchars($appType) ?>
                                </a>
                            </li>
                        <?php endforeach; ?>
                    </ul>
                </div>
                <form id="apparatusTypeForm" method="GET" class="d-none">
                    <input type="hidden" name="status" value="<?= htmlspecialchars($filter_status) ?>">
                    <input type="hidden" name="search" value="<?= htmlspecialchars($search_query) ?>">
                    <input type="hidden" name="sort_by" value="<?= htmlspecialchars($sort_by) ?>">
                    <input type="hidden" name="sort_order" value="<?= htmlspecialchars($sort_order) ?>">
                    <div id="apparatusTypeHiddenInputs">
                        <?php if (!empty($filter_types)):
                            foreach($filter_types as $type_val): ?>
                                <input type="hidden" name="apparatus_type[]" value="<?= htmlspecialchars($type_val) ?>">
                            <?php endforeach;
                        endif; ?>
                    </div>
                </form>

            </div>
            
            <form method="GET" class="d-flex align-items-center mb-2">
                <input type="hidden" name="status" value="<?= htmlspecialchars($filter_status) ?>">
                <input type="hidden" name="sort_by" value="<?= htmlspecialchars($sort_by) ?>">
                <input type="hidden" name="sort_order" value="<?= htmlspecialchars($sort_order) ?>">
                <?php if (!empty($filter_types)): 
                    foreach($filter_types as $type_val): ?>
                        <input type="hidden" name="apparatus_type[]" value="<?= htmlspecialchars($type_val) ?>">
                    <?php endforeach;
                endif; ?>
                <div class="input-group input-group-sm">
                    <input type="search" name="search" class="form-control" placeholder="Search by Name, Type, Material..." value="<?= htmlspecialchars($search_query) ?>">
                    <button class="btn btn-outline-secondary" type="submit">
                        <i class="fas fa-search"></i>
                    </button>
                    <?php if (!empty($search_query) || $filter_status !== 'all' || (!empty($filter_types) && !in_array('all', $filter_types))): ?>
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
                        <th style="width: 4%;" class="sortable <?= $sort_by === 'id' ? 'sorted' : '' ?>"  
                            onclick="window.location.href='<?= getSortUrl('id') ?>'">
                            ID
                            <i class="fas fa-sort sort-icon <?= $sort_by === 'id' ? ($sort_order === 'asc' ? 'fa-sort-up' : 'fa-sort-down') : 'text-muted' ?>"></i>
                        </th>
                        <th style="width: 7%;">Image</th>
                        <th style="width: 12%;" class="sortable <?= $sort_by === 'name' ? 'sorted' : '' ?>"  
                            onclick="window.location.href='<?= getSortUrl('name') ?>'">
                            Name
                            <i class="fas fa-sort sort-icon <?= $sort_by === 'name' ? ($sort_order === 'asc' ? 'fa-sort-up' : 'fa-sort-down') : 'text-muted' ?>"></i>
                        </th>
                        <th style="width: 9%;">Type</th>
                        <th style="width: 6%;">Size</th>
                        <th style="width: 8%;">Material</th>
                        <th style="width: 7%;" class="sortable <?= $sort_by === 'stock' ? 'sorted' : '' ?>"  
                            onclick="window.location.href='<?= getSortUrl('stock') ?>'">
                            Total Stock
                            <i class="fas fa-sort sort-icon <?= $sort_by === 'stock' ? ($sort_order === 'asc' ? 'fa-sort-up' : 'fa-sort-down') : 'text-muted' ?>"></i>
                        </th>  
                        <th style="width: 7%;">Available Stock</th>  
                        <th style="width: 7%;">Damaged Units</th>  
                        <th style="width: 6%;">Lost Units</th>  
                        <th style="width: 6%;">Out (Borr/Res)</th>  
                        <th style="width: 15%;">Description</th>  
                        <th style="width: 8%;">Type Status</th>
                        <th style="width: 8%;">Actions</th>  
                    </tr>
                </thead>
                <tbody>
                    <?php if (!empty($paginatedApparatusList)): ?>
                        <?php foreach ($paginatedApparatusList as $app): ?>
                            <tr>
                                <td data-label="ID:"><?= $app['id'] ?></td>
                                <td data-label="Image:">
                                    <img src="../uploads/apparatus_images/<?= htmlspecialchars($app['image'] ?? 'default.jpg') ?>"  
                                        alt="<?= htmlspecialchars($app['name']) ?>">
                                    <span class="d-md-none text-start" data-name-label="Name:"><?= htmlspecialchars($app['name']) ?></span>
                                </td>
                                <td data-label="Name:" class="text-start d-none d-md-table-cell"><?= htmlspecialchars($app['name']) ?></td>
                                <td data-label="Type:"><?= htmlspecialchars($app['apparatus_type']) ?></td>
                                <td data-label="Size:"><?= htmlspecialchars($app['size']) ?></td>
                                <td data-label="Material:"><?= htmlspecialchars($app['material']) ?></td>
                                
                                <td data-label="Total Stock:"><?= htmlspecialchars($app['total_stock'] ?? '0') ?></td>  
                                <td data-label="Available Stock:" class="fw-bold <?= ($app['available_stock'] > 0 ? 'text-success' : 'text-danger') ?>"><?= htmlspecialchars($app['available_stock'] ?? '0') ?></td>  
                                
                                <td data-label="Damaged Units:" class="fw-bold text-warning"><?= htmlspecialchars(max(0, $app['damaged_stock'] ?? '0')) ?></td>  
                                <td data-label="Lost Units:" class="fw-bold text-danger"><?= htmlspecialchars(max(0, $app['lost_stock'] ?? '0')) ?></td>  
                                <td data-label="Out (Borr/Res):"><?= htmlspecialchars($app['currently_out'] ?? '0') ?></td>  
                                
                                <td data-label="Description:" class="text-start">
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
                                
                                <td data-label="Type Status:">
                                    <span class="status-tag <?= htmlspecialchars($app['status']) ?>">
                                        <?= htmlspecialchars(ucfirst($app['status'])) ?>
                                    </span>
                                </td>
                                
                                <td data-label="Actions:">
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
                        <tr><td colspan="13" class="text-muted py-3">No apparatus found for the selected filter or search query.</td></tr>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>

        <div class="pagination-container mt-3 mb-3">
            
            <?php if (!empty($apparatusList)): ?>
            <div class="pagination-info my-2">
                Displaying <?= $offset + 1 ?> to <?= min($offset + $items_per_page, $total_items) ?> of <?= $total_items ?> items.
            </div>
            <?php endif; ?>

            <?php if ($total_pages > 1): ?>
            <nav aria-label="Apparatus Pagination">
                <ul class="pagination pagination-sm justify-content-center mb-0 my-2">
                    
                    <li class="page-item <?= ($current_page <= 1) ? 'disabled' : '' ?>">
                        <a class="page-link" href="<?= getBaseUrl($current_page - 1) ?>" aria-label="Previous">
                            <i class="fas fa-chevron-left me-1" style="font-size: 0.8rem;"></i> Previous
                        </a>
                    </li>

                    <?php
                    $start_page = max(1, $current_page - 2);
                    $end_page = min($total_pages, $current_page + 2);

                    if ($start_page > 1) {
                        echo '<li class="page-item"><a class="page-link" href="' . getBaseUrl(1) . '">1</a></li>';
                        if ($start_page > 2) {
                            echo '<li class="page-item disabled"><span class="page-link">...</span></li>';
                        }
                    }

                    for ($i = $start_page; $i <= $end_page; $i++):
                    ?>
                        <li class="page-item <?= ($i === $current_page) ? 'active' : '' ?>">
                            <a class="page-link" href="<?= getBaseUrl($i) ?>"><?= $i ?></a>
                        </li>
                    <?php endfor; ?>
                    
                    <?php
                    if ($end_page < $total_pages) {
                        if ($end_page < $total_pages - 1) {
                            echo '<li class="page-item disabled"><span class="page-link">...</span></li>';
                        }
                        echo '<li class="page-item"><a class="page-link" href="' . getBaseUrl($total_pages) . '">' . $total_pages . '</a></li>';
                    }
                    ?>

                    <li class="page-item <?= ($current_page >= $total_pages) ? 'disabled' : '' ?>">
                        <a class="page-link" href="<?= getBaseUrl($current_page + 1) ?>" aria-label="Next">
                            Next <i class="fas fa-chevron-right ms-1" style="font-size: 0.8rem;"></i>
                        </a>
                    </li>
                </ul>
            </nav>
            <?php endif; ?>
            
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

<div class="modal fade" id="editStockConfirmModal" tabindex="-1" aria-labelledby="editStockConfirmModalLabel" aria-hidden="true">
  <div class="modal-dialog">
    <div class="modal-content">
      <div class="modal-header bg-info text-white">
        <h5 class="modal-title" id="editStockConfirmModalLabel"><i class="fas fa-exclamation-triangle me-2"></i> Confirm Stock Changes</h5>
        <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
      </div>
      <div class="modal-body">
          <p>You are about to change the stock counts for <span id="editConfirmName" class="fw-bold"></span>.</p>
          
          <div id="edit-change-summary">
              </div>
          
          <div class="alert alert-warning py-2 small mt-3" role="alert">
              <i class="fas fa-info-circle me-1"></i> Confirm these changes to proceed. This action will adjust the status of individual units in the database.
          </div>
          
          <form method="POST" id="confirmEditStockForm">
              <input type="hidden" name="id" id="confirm_id">
              <input type="hidden" name="name" id="confirm_name">
              <input type="hidden" name="type" id="confirm_type">
              <input type="hidden" name="size" id="confirm_size">
              <input type="hidden" name="material" id="confirm_material">
              <input type="hidden" name="description" id="confirm_description">
              <input type="hidden" name="total_stock" id="confirm_total_stock">
              <input type="hidden" name="damaged_stock" id="confirm_damaged_stock">
              <input type="hidden" name="lost_stock" id="confirm_lost_stock">
              <input type="hidden" name="old_image" id="confirm_old_image">
              <input type="hidden" name="update_details" value="1">
          </form>
      </div>
      <div class="modal-footer">
        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
        <button type="button" class="btn btn-info" id="confirmEditStockBtn">Yes, Proceed with Stock Update</button>
      </div>
    </div>
  </div>
</div>


<script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>  
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
<script>
    // Global variable to hold the ID of the unit currently being restored
    let currentUnitIdToRestore = null;
    
    // --- START: JAVASCRIPT FOR MULTI-SELECT TYPE FILTER ---
    window.toggleApparatusType = function(typeValue) {
        const form = document.getElementById('apparatusTypeForm');
        const hiddenInputsContainer = document.getElementById('apparatusTypeHiddenInputs');
        const existingInput = hiddenInputsContainer.querySelector(`input[name="apparatus_type[]"][value="${typeValue}"]`);
        
        // Remove existing inputs to rebuild the list
        const existingInputs = Array.from(hiddenInputsContainer.querySelectorAll('input[name="apparatus_type[]"]'));
        existingInputs.forEach(input => input.remove());
        
        let selectedTypes = [];
        // Extract current selected types from the GET parameters if any
        const urlParams = new URLSearchParams(window.location.search);
        urlParams.getAll('apparatus_type[]').forEach(type => {
            if (type !== typeValue) {
                selectedTypes.push(type);
            }
        });
        
        // If the clicked type was NOT found, it means it was added
        if (!existingInput) {
            selectedTypes.push(typeValue);
        }
        
        // Rebuild the hidden inputs
        selectedTypes.forEach(type => {
            const input = document.createElement('input');
            input.type = 'hidden';
            input.name = 'apparatus_type[]';
            input.value = type;
            hiddenInputsContainer.appendChild(input);
        });
        
        // Submit the form to apply the filter
        form.submit();
    };

    // Update the visual state of the dropdown on load (to persist checks)
    document.addEventListener('DOMContentLoaded', () => {
        const typeFilterMenu = document.getElementById('apparatusTypeFilterMenu');
        if (typeFilterMenu) {
            const urlParams = new URLSearchParams(window.location.search);
            const selectedTypes = urlParams.getAll('apparatus_type[]');
            
            // Loop through all list items in the dropdown (skipping the "Clear All" link and divider)
            typeFilterMenu.querySelectorAll('li[onclick]').forEach(li => {
                const typeValue = li.getAttribute('onclick').match(/'([^']+)'/)[1];
                const link = li.querySelector('a');
                const icon = li.querySelector('i');
                
                if (selectedTypes.includes(typeValue)) {
                    link.classList.add('active');
                    icon.classList.remove('fa-square');
                    icon.classList.add('fa-check-square');
                } else {
                    link.classList.remove('active');
                    icon.classList.remove('fa-check-square');
                    icon.classList.add('fa-square');
                }
            });
            
            // Update the display text of the button
            const typeFilterDropdown = document.getElementById('typeFilterDropdown');
            if (typeFilterDropdown) {
                let count = selectedTypes.length;
                if (count === 0 || selectedTypes.includes('all')) {
                    typeFilterDropdown.innerHTML = '<i class="fas fa-filter me-1"></i> Type (All)';
                } else {
                    typeFilterDropdown.innerHTML = `<i class="fas fa-filter me-1"></i> Type (${count})`;
                }
            }
        }
    });

    // --- END: JAVASCRIPT FOR MULTI-SELECT TYPE FILTER ---
    
    // --- START: JAVASCRIPT FOR EDIT FORM CONFIRMATION MODAL ---

    window.showEditConfirmModal = function() {
        // 0. Perform client-side validation first
        if (!document.getElementById('editApparatusForm').checkValidity()) {
            // If the form is invalid, trigger the browser's default validation messages
            document.getElementById('saveChangesButton').click(); // Re-trigger validation for error display
            return;
        }

        // 1. Gather current values from the Edit form
        const form = document.getElementById('editApparatusForm');
        const apparatusId = parseInt(form.elements['id'].value);
        const apparatusName = form.elements['name'].value;
        const newTotal = parseInt(form.elements['total_stock'].value);
        const newDamaged = parseInt(form.elements['damaged_stock'].value);
        const newLost = parseInt(form.elements['lost_stock'].value);

        // 2. Gather original values from hidden fields
        const originalTotal = parseInt(document.getElementById('original_total_stock').value);
        const originalDamaged = parseInt(document.getElementById('original_damaged_stock').value);
        const originalLost = parseInt(document.getElementById('original_lost_stock').value);
        const currentlyOut = parseInt(document.getElementById('currently_out').value);

        // --- VALIDATION CHECKS (Should pass if form.checkValidity() worked, but good to reconfirm) ---
        if (newTotal <= 0 || newDamaged < 0 || newLost < 0) {
            alert("Validation Error: Stock counts must be valid non-negative numbers, and Total Stock must be > 0.");
            return;
        }
        
        // Calculate new available physical stock
        const newAvailablePhysical = newTotal - newDamaged - newLost;

        if (newAvailablePhysical < 0) {
            alert("Validation Error: Damaged/Lost stock exceeds Total Stock.");
            return;
        }
        if (newAvailablePhysical < currentlyOut) {
            alert(`Validation Error: Cannot set stock counts that result in fewer physically available units (${newAvailablePhysical}) than currently borrowed/reserved (${currentlyOut}).`);
            return;
        }
        // --- END VALIDATION ---
        
        // 3. Determine which stock values actually changed
        let changes = [];

        if (newTotal !== originalTotal) {
            changes.push(`Total Stock: changed from <span class="text-danger">${originalTotal}</span> to <span class="text-success">${newTotal}</span>.`);
        }
        if (newDamaged !== originalDamaged) {
            changes.push(`Damaged Stock: changed from <span class="text-warning">${originalDamaged}</span> to <span class="text-warning">${newDamaged}</span>.`);
        }
        if (newLost !== originalLost) {
            changes.push(`Lost Stock**: changed from <span class="text-danger">${originalLost}</span> to <span class="text-danger">${newLost}</span>.`);
        }

        // 4. Update the Modal Content
        const $modalBody = $('#edit-change-summary');
        
        $('#editConfirmName').text(apparatusName);
        
        if (changes.length > 0) {
            $modalBody.html('<p class="fw-bold">The following stock counts will be updated:</p><ul><li>' + changes.join('</li><li>') + '</li></ul>');
        } else {
            // Check if ANY field changed (non-stock related)
            const otherFieldsChanged = Array.from(form.elements).some(element => {
                if (element.name && !['total_stock', 'damaged_stock', 'lost_stock', 'id', 'old_image', 'new_apparatus_image'].includes(element.name) && element.value !== element.defaultValue) {
                    return true;
                }
                return false;
            });

            if (otherFieldsChanged) {
                $modalBody.html('<p class="fw-bold text-success">No stock counts were modified, but other details (Name, Type, Description, etc.) will be updated.</p>');
            } else {
                // No changes at all - prevent modal show and alert user
                alert("No changes were made to the apparatus details or stock counts.");
                return;
            }
        }
        
        // 5. Populate the hidden form fields in the modal for submission
        $('#confirmEditStockForm #confirm_id').val(form.elements['id'].value);
        $('#confirmEditStockForm #confirm_name').val(form.elements['name'].value);
        $('#confirmEditStockForm #confirm_type').val(form.elements['type'].value);
        $('#confirmEditStockForm #confirm_size').val(form.elements['size'].value);
        $('#confirmEditStockForm #confirm_material').val(form.elements['material'].value);
        $('#confirmEditStockForm #confirm_description').val(form.elements['description'].value);
        $('#confirmEditStockForm #confirm_total_stock').val(newTotal);
        $('#confirmEditStockForm #confirm_damaged_stock').val(newDamaged);
        $('#confirmEditStockForm #confirm_lost_stock').val(newLost);
        $('#confirmEditStockForm #confirm_old_image').val(form.elements['old_image'].value);

        // 6. Set up the confirmation button handler
        $('#confirmEditStockBtn').off('click').on('click', function() {
            // Handle Image file transfer (files cannot be passed in hidden fields, must be re-attached)
            const newImageInput = form.elements['new_apparatus_image'];
            if (newImageInput && newImageInput.files.length > 0) {
                // Since we are submitting the hidden modal form, we must copy the file data
                const confirmForm = document.getElementById('confirmEditStockForm');
                const fileClone = newImageInput.cloneNode(true);
                fileClone.id = 'confirm_new_apparatus_image'; // Give it a unique ID
                fileClone.name = 'new_apparatus_image'; // Use the correct POST name
                
                // Temporarily append to the confirmation form
                confirmForm.appendChild(fileClone);
            }
            
            // Submit the modal's hidden form
            document.getElementById('confirmEditStockForm').submit();
        });

        // 7. Show the modal
        const confirmModal = new bootstrap.Modal(document.getElementById('editStockConfirmModal'));
        confirmModal.show();
    };

    // --- END: JAVASCRIPT FOR EDIT FORM CONFIRMATION MODAL ---


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


    // =========================================================================
    // --- NOTIFICATION HANDLER IMPLEMENTATION (COPIED FROM STAFF DASHBOARD) ---
    // =========================================================================

    // 1. Function to handle marking ALL staff notifications as read
    window.markAllStaffAsRead = function() {
        // Use the generalized API endpoint for staff batch read
        $.post('../api/mark_notification_as_read.php', { mark_all: true, role: 'staff' }, function(response) {
            if (response.success) {
                // Update text and force UI refresh via re-fetch
                $('#mark-all-link').text('Successfully marked all as read!').removeClass('text-muted').addClass('text-success');
                setTimeout(fetchStaffNotifications, 500);
            } else {
                console.error("Failed to mark all staff notifications as read.");
                alert("Failed to clear all notifications.");
            }
        }).fail(function() {
            console.error("API call failed.");
        });
    };
    
    // 2. Function to handle single notification click (Mark as read + navigate)
    window.handleNotificationClick = function(event, element, notificationId) {
        event.preventDefault();  
        const linkHref = element.getAttribute('href');

        // Explicitly close the Bootstrap Dropdown
        const $dropdownToggle = $('#alertsDropdown');
        const dropdownInstance = bootstrap.Dropdown.getInstance($dropdownToggle[0]);
        if (dropdownInstance) { dropdownInstance.hide(); }
        
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
        const apiPath = '../api/get_notifications.php';  

        $.getJSON(apiPath, function(response) {  
            
            const unreadCount = response.count;  
            const notifications = response.alerts || [];  
            
            const $badge = $('#notification-bell-badge');
            const $markAllLink = $('#mark-all-link'); // Get the static HTML element
            const $dropdown = $('#notification-dropdown');
            
            // Clear previous dynamic items
            $dropdown.find('.dynamic-notif-item').remove();  
            
            // 1. Update the Badge Count
            $badge.text(unreadCount);
            $badge.toggle(unreadCount > 0);  
            
            // 2. Control visibility of the static Mark All link
            if (unreadCount > 0) {
                 // Show the link and update the count text
                 $markAllLink.show().text(`Mark All ${unreadCount} as Read`).removeClass('text-success').addClass('text-muted');
            } else {
                 // Hide the link
                 $markAllLink.hide();
            }

            // 3. Populate the Dynamic Placeholder
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
                    const targetLink = notif.link ? notif.link : 'staff_pending.php';  


                    $placeholder.append(`
                             <a class="dropdown-item d-flex align-items-center dynamic-notif-item"  
                                 href="${targetLink}"
                                 data-id="${notif.id}"
                                 onclick="handleNotificationClick(event, this, ${notif.id})">
                                 <div class="me-3"><i class="${iconClass} fa-fw"></i></div>
                                 <div>
                                     <div class="small text-gray-500">${notif.created_at.split(' ')[0]}</div>
                                     <span class="d-block ${itemClass}">${notif.message}</span>
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
            
        }).fail(function(jqXHR, textStatus, errorThrown) {
            console.error("Error fetching staff notifications:", textStatus, errorThrown);
            // Ensure the badge is hidden and link is hidden on failure
            $('#notification-bell-badge').text('0').hide();
            $('#mark-all-link').hide();
        });
    }
    // =========================================================================
    // --- END NOTIFICATION HANDLER IMPLEMENTATION ---
    // =========================================================================


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
        
        // --- NEW DESKTOP COLLAPSE LOGIC ---
        const menuToggle = document.getElementById('menuToggle');
        const sidebar = document.querySelector('.sidebar');
        const sidebarBackdrop = document.getElementById('sidebarBackdrop');  
        
        function setInitialState() {
            if (window.innerWidth > 992) {
                sidebar.classList.remove('closed');
                sidebar.classList.remove('active');
                if (sidebarBackdrop) sidebarBackdrop.style.display = 'none';
                if (menuToggle) menuToggle.style.display = 'none';  
            } else {
                sidebar.classList.remove('closed');
                sidebar.classList.remove('active');
                if (sidebarBackdrop) sidebarBackdrop.style.display = 'none';
                if (menuToggle) menuToggle.style.display = 'flex';  
            }
        }
        
        function toggleSidebar() {
            if (window.innerWidth <= 992) {
                sidebar.classList.toggle('active');
                if (sidebarBackdrop) {
                    const isActive = sidebar.classList.contains('active');
                    sidebarBackdrop.style.display = isActive ? 'block' : 'none';
                    sidebarBackdrop.style.opacity = isActive ? '1' : '0';
                }
            }  
        }

        if (menuToggle && sidebar) {
            menuToggle.addEventListener('click', toggleSidebar);
            
            if (sidebarBackdrop) {
                sidebarBackdrop.addEventListener('click', () => {
                    sidebar.classList.remove('active');
                    sidebarBackdrop.style.display = 'none';
                });
            }
            
            const navLinks = sidebar.querySelectorAll('.nav-link');
            navLinks.forEach(link => {
                link.addEventListener('click', () => {
                    if (window.innerWidth <= 992) {
                        setTimeout(() => {
                           sidebar.classList.remove('active');
                           sidebarBackdrop.style.display = 'none';
                        }, 100);
                    }
                });
            });

            window.addEventListener('resize', setInitialState);
            setInitialState();
        }
        // --- END NEW DESKTOP COLLAPSE LOGIC ---
        
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