<?php
session_start();
require_once "../classes/Student.php"; 
require_once "../classes/Transaction.php"; 

// 1. Redirect if not logged in or not a student
if (!isset($_SESSION['user']) || $_SESSION['user']['role'] !== 'student') {
    header("Location: ../pages/logout.php"); // Redirect to logout to ensure proper session clearing
    exit;
}

$student = new Student();
$transaction = new Transaction();
$user_id = $_SESSION['user']['id'];
$errors = [];
$message = "";
$message_type = ""; 

// --- Ban Check for Sidebar Logic ---
$isBanned = $transaction->isStudentBanned($user_id); 
// ------------------------------------

// Initialize variables with current session data
$current_data = [
    'student_id' => $_SESSION['user']['student_id'],
    'firstname' => $_SESSION['user']['firstname'],
    'lastname' => $_SESSION['user']['lastname'],
    'course' => $_SESSION['user']['course'],
    'email' => $_SESSION['user']['email'],
];

// 1. FETCH CONTACT NUMBER FOR DISPLAY (Simplified)
$db_contact = $student->getContactDetails($user_id);
$contact_number = $db_contact['contact_number'] ?? '';

// Set initial values for form fields
$current_data['contact_number'] = $contact_number;

// Variables for password form submission
$pass_errors = [];
$pass_message = "";
$pass_message_type = "";


// --- Handle Form Submissions ---
if ($_SERVER["REQUEST_METHOD"] == "POST") {
    
    // Determine which form was submitted
    if (isset($_POST['action']) && $_POST['action'] === 'update_profile') {
        
        // --- 1. HANDLE PROFILE DETAILS UPDATE ---
        $new_firstname = trim($_POST["firstname"]);
        $new_lastname = trim($_POST["lastname"]);
        $new_course = trim($_POST["course"]);
        $new_email = trim($_POST["email"]);
        $new_contact_number = trim($_POST["contact_number"]); 
        
        // --- Validation (Retaining logic from previous steps) ---
        if (empty($new_firstname)) $errors["firstname"] = "Your first name cannot be empty. Please provide your official first name.";
        if (empty($new_lastname)) $errors["lastname"] = "Your last name is required for identification. Please enter it.";
        if (empty($new_course)) $errors["course"] = "Your course/program is mandatory.";
        
        // Contact Validation
        if (empty($new_contact_number)) {
            $errors["contact_number"] = "Contact number is required for laboratory communication.";
        } else {
            // Clean number allowing only digits and '+'
            $clean_number = preg_replace('/[^0-9\+]/', '', $new_contact_number); 
            // Basic length validation
            if (empty($clean_number) || strlen($clean_number) < 8 || strlen( $clean_number) > 20) {
                $errors["contact_number"] = "The contact number seems invalid. Please ensure it includes the country code (e.g., +639xxxxxxxxx) and is between 8 and 20 characters long.";
            }
        }
        
        // Email Validation & Duplication Check
        if (empty($new_email)) {
            $errors["email"] = "Email address is essential for account recovery and notifications.";
        } elseif (!filter_var($new_email, FILTER_VALIDATE_EMAIL)) {
            $errors["email"] = "The email format is invalid. Please double-check for typos (e.g., user@example.com).";
        } elseif ($new_email !== $_SESSION['user']['email'] && $student->isEmailExist($new_email)) {
            $errors['email'] = "This Email address is already linked to another user account. Please use a unique email.";
        }

        // 3. Process Update
        if (empty($errors)) {
            $full_contact = $clean_number; 
            
            $result = $student->updateStudentProfile(
                $user_id, $new_firstname, $new_lastname, $new_course, $full_contact, $new_email
            );
            
            if ($result) {
                $message = "✅ Success! Your profile details have been updated.";
                $message_type = 'success';
                
                // Update Session variables immediately
                $_SESSION['user']['firstname'] = $new_firstname;
                $_SESSION['user']['lastname'] = $new_lastname;
                $_SESSION['user']['course'] = $new_course;
                $_SESSION['user']['email'] = $new_email;
                
                // Trigger success modal on reload
                echo '<script>sessionStorage.setItem("profileUpdated", "true");</script>';

            } else {
                $message = "❌ System Error: We encountered an issue while saving to the database. Please try again or contact support.";
                $message_type = 'danger';
            }
        } else {
            $message = "⚠️ Validation Failed: Please review and correct the marked fields below before proceeding.";
            $message_type = 'danger';
        }
        
        // Retain user input for current form in case of error
        $current_data['firstname'] = $new_firstname;
        $current_data['lastname'] = $new_lastname;
        $current_data['course'] = $new_course;
        $current_data['email'] = $new_email;
        $current_data['contact_number'] = $new_contact_number;
        
    } elseif (isset($_POST['action']) && $_POST['action'] === 'change_password') {
        
        // --- 2. HANDLE CHANGE PASSWORD ---
        $current_password = $_POST["current_password"];
        $new_password = $_POST["new_password"];
        $confirm_password = $_POST["confirm_password"];
        
        if (empty($current_password)) $pass_errors["current_password"] = "Current password is required.";
        if (empty($new_password)) {
             $pass_errors["new_password"] = "New password is required.";
        } elseif (strlen($new_password) < 8) {
            $pass_errors["new_password"] = "New password must be at least 8 characters."; 
        }
        if (empty($confirm_password)) {
             $pass_errors["confirm_password"] = "Confirmation password is required.";
        } elseif ($new_password !== $confirm_password) {
            $pass_errors["confirm_password"] = "New passwords do not match.";
        }
        
        if (empty($pass_errors)) {
            $result = $student->changeStudentPassword($user_id, $current_password, $new_password);
            
            if ($result === true) {
                // Set flag to trigger success modal/logout on reload
                echo '<script>sessionStorage.setItem("passUpdated", "true");</script>';
                // Clear POST and force reload to show modal and then log out
                header("Location: student_edit.php#password_modal_trigger");
                exit;
            } elseif ($result === 'invalid_password') {
                $pass_errors['current_password'] = "The current password you entered is incorrect.";
                $pass_message = "❌ Password change failed. Please review errors.";
                $pass_message_type = 'danger';
            } else {
                $pass_message = "❌ System Error: Failed to update password. Please try again or contact support.";
                $pass_message_type = 'danger';
            }
        } else {
            $pass_message = "⚠️ Validation Failed: Please check the highlighted fields.";
            $pass_message_type = 'danger';
        }
    }
}

// Check for successful submission flags to trigger modals on page load
$trigger_profile_success = isset($_SESSION['profileUpdated']) ? true : false;
unset($_SESSION['profileUpdated']);

$trigger_pass_success = false;

// If we hit a successful password update, we redirect and handle it in JS below.
// This block processes errors only.
if (isset($_SESSION['password_status'])) {
    $pass_message = $_SESSION['password_status']['message'];
    $pass_message_type = $_SESSION['password_status']['type'];
    $pass_errors = $_SESSION['password_status']['errors'];
    
    if($pass_message_type === 'success') {
        $trigger_pass_success = true;
    }
    unset($_SESSION['password_status']);
}

// FIX: Changed from 'disabled' to 'readonly'
// If there are errors in the profile form, the state must be enabled (i.e., not readonly)
$initial_readonly_state = !empty($errors) ? '' : 'readonly';
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Edit Profile - Student</title>
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
            --sidebar-width: 280px; 
            --bg-light: #f5f6fa;
            --header-height: 60px;
            --danger-color: #dc3545;
        }
        body { 
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif; 
            background: var(--bg-light); 
            min-height: 100vh;
            display: flex; 
            padding: 0;
            margin: 0;
            color: var(--text-dark);
        }

        /* NEW CSS for Mobile Toggle */
        .menu-toggle {
            display: none; 
            position: fixed;
            top: 15px;
            left: 20px;
            z-index: 1060; 
            background: var(--primary-color);
            color: white;
            border: none;
            padding: 8px 12px;
            border-radius: 6px;
            font-size: 1.2rem;
            box-shadow: 0 2px 5px rgba(0,0,0,0.2);
        }

        /* --- Sidebar Styles (Unifying Look) --- */
        .sidebar { width: var(--sidebar-width); min-width: var(--sidebar-width); height: 100vh; background-color: var(--primary-color); color: white; padding: 0; position: fixed; top: 0; left: 0; display: flex; flex-direction: column; box-shadow: 2px 0 5px rgba(0, 0, 0, 0.2); z-index: 1050; transition: left 0.3s ease; }
        .sidebar-header { text-align: center; padding: 20px 15px; font-size: 1.2rem; font-weight: 700; line-height: 1.15; color: #fff; border-bottom: 1px solid rgba(255, 255, 255, 0.4); margin-bottom: 20px; }
        .sidebar-header img { width: 90px; height: 90px; object-fit: contain; margin: 0 auto 15px auto; display: block; } 
        .sidebar-header .title { font-size: 1.3rem; line-height: 1.1; }
        .sidebar-nav { flex-grow: 1; }
        .sidebar .nav-link { 
            color: white; 
            padding: 15px 20px; 
            font-weight: 600; 
            transition: background-color 0.3s; 
            border-left: none !important; 
            font-size: 1.1rem; 
            display: flex; 
            align-items: center;
        }
        .sidebar .nav-link:hover { background-color: var(--primary-color-dark); }
        .sidebar .nav-link.active { background-color: var(--primary-color-dark); } 
        .sidebar .nav-link.banned { background-color: #5a2624; opacity: 0.8; cursor: pointer; pointer-events: auto; }
        .logout-link { margin-top: auto; border-top: 1px solid rgba(255, 255, 255, 0.1); }
        .logout-link .nav-link { background-color: #C62828 !important; color: white !important; } 
        .logout-link .nav-link:hover { background-color: var(--primary-color-dark) !important; }
        
        /* --- Top Header Bar Styles --- */
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
            padding: 0 20px;
            z-index: 1000;
            transition: left 0.3s ease;
        }
        .notification-bell-container {
            position: relative;
            margin-right: 25px;
            list-style: none;
            padding: 0;
        }
        .notification-bell-container .badge-counter {
            position: absolute;
            top: 5px;
            right: 0px;
            font-size: 0.7em;
            padding: 0.35em 0.5em;
            background-color: var(--secondary-color);
            color: var(--text-dark);
            font-weight: bold;
        }
        .edit-profile-link {
            color: var(--primary-color);
            font-weight: 700;
            text-decoration: none;
            transition: color 0.2s;
            border-bottom: 3px solid var(--primary-color);
            padding-bottom: 3px;
        }
        .edit-profile-link:hover {
            color: var(--primary-color-dark);
            text-decoration: none;
        }
        /* --- END Top Header Bar Styles --- */

        /* --- Main Content Styles --- */
        .main-wrapper {
            margin-left: var(--sidebar-width); 
            padding: 0; 
            padding-top: var(--header-height); 
            flex-grow: 1;
            transition: margin-left 0.3s ease;
        }
        .content-area {
            background: #fff; 
            border-radius: 0; 
            padding: 30px 40px;
            box-shadow: none; 
            max-width: none; 
            width: 100%; 
            margin: 0; 
            min-height: calc(100vh - var(--header-height)); 
        }
        .page-header {
            color: var(--text-dark); 
            margin-bottom: 30px;
            padding-bottom: 15px;
            border-bottom: 2px solid var(--primary-color); 
            font-weight: 700;
            font-size: 2.2rem;
        }
        
        /* --- Form Styles --- */
        .form-container-wrapper {
            width: 95%; 
            max-width: 850px; 
            margin: 0 auto; 
        }
        .form-section-header {
            font-size: 1.5rem;
            font-weight: 700;
            color: var(--primary-color);
            border-bottom: 3px solid var(--secondary-color); 
            padding-bottom: 10px;
            margin-top: 40px;
            margin-bottom: 25px;
            text-align: left;
        }

        .form-group {
            display: flex;
            margin-bottom: 20px;
            align-items: flex-start;
            position: relative; 
        }
        .form-group label {
            flex: 0 0 160px; 
            padding-right: 20px;
            text-align: right;
            padding-top: 8px;
            font-weight: 600;
            font-size: 1rem;
        }
        .input-wrapper {
            flex: 1;
            position: relative;
        }
        .form-control, .contact-input { 
            width: 100%;
            padding: 10px 12px; 
            border-radius: 6px; 
            box-sizing: border-box; 
            font-size: 1rem;
            transition: border-color 0.2s, box-shadow 0.2s;
        }
        .form-control:focus, .contact-input:focus {
            border-color: var(--secondary-color);
            box-shadow: 0 0 0 3px rgba(244, 180, 0, 0.2);
            outline: none;
        }
        .form-control.is-invalid, .contact-input.is-invalid {
             border-color: var(--danger-color);
        }
        
        /* Readonly/Disabled Input Styles */
        input:read-only {
            background-color: #f5f5f5; /* Light grey background */
            color: #555;
            cursor: default;
            border: 1px solid #ddd;
        }
        input:disabled { /* Keep disabled style for Student ID field */
             background-color: #eee;
             color: #777;
             cursor: not-allowed;
        }


        /* Password toggle icon placement (Modal and Main) */
        .password-toggle {
            position: absolute;
            right: 12px;
            top: 50%;
            transform: translateY(-50%);
            cursor: pointer;
            color: #888;
            z-index: 10; 
        }
        
        /* Centering the Form Actions */
        .form-actions {
            padding-left: 0; 
            margin-top: 30px;
            text-align: center; 
            display: flex;
            justify-content: center;
            gap: 15px; /* Space between Save and Cancel */
        }
        /* Adjusted error alignment to align with input field */
        .error {
            color: var(--primary-color); 
            font-size: 0.95rem;
            margin-top: 5px; 
            margin-bottom: 20px; 
            padding-left: 180px; 
            font-weight: 600;
            display: block;
            text-align: left;
        }
        
        /* Error for fields inside the password form */
        .error-inline {
             color: var(--primary-color); 
             font-size: 0.9rem;
             font-weight: 600;
             display: block;
             margin-top: 5px;
             text-align: left;
        }

        /* Alert styling - Consistent with login/signup pages */
        .alert-custom {
            margin-bottom: 20px;
            padding: 15px;
            font-weight: 600;
            border-radius: 8px;
            font-size: 1.05rem;
            text-align: left;
        }
        .alert-success { background-color: #d4edda; color: #155724; border: 1px solid #c3e6cb; }
        .alert-danger { background-color: #f8d7da; color: #721c24; border: 1px solid #f5c6cb; }
        
        /* Button styling - New Primary Style (Pill shape) */
        .btn-theme-primary {
            background-color: var(--primary-color);
            color: white;
            border: none;
            border-radius: 50px; /* Pill shape */
            padding: 12px 30px;
            font-weight: 700;
            font-size: 1.1rem;
            transition: background-color 0.3s, transform 0.2s, box-shadow 0.3s;
            box-shadow: 0 4px 10px rgba(0, 0, 0, 0.1);
        }
        .btn-theme-primary:hover {
            background-color: var(--primary-color-dark);
            transform: translateY(-2px);
            box-shadow: 0 6px 15px rgba(0, 0, 0, 0.2);
        }
        
        .btn-cancel {
            background-color: #6c757d;
            color: white;
            border: none;
            border-radius: 50px;
            padding: 12px 30px;
            font-weight: 700;
            font-size: 1.1rem;
            transition: background-color 0.3s, transform 0.2s;
            box-shadow: 0 4px 10px rgba(0, 0, 0, 0.1);
        }
        .btn-cancel:hover {
            background-color: #5a6268;
            transform: translateY(-2px);
        }

        /* Modal Header Styles for Status Modals */
        .modal-header.alert-success { background-color: #28a745; color: white; }
        .modal-header.alert-danger { background-color: var(--danger-color); color: white; }


        /* --- RESPONSIVE ADJUSTMENTS --- */
        @media (max-width: 992px) {
            .menu-toggle { display: block; }
            .sidebar { left: calc(var(--sidebar-width) * -1); transition: left 0.3s ease; box-shadow: none; --sidebar-width: 250px; }
            .sidebar.active { left: 0; box-shadow: 2px 0 5px rgba(0, 0, 0, 0.2); }
            .main-wrapper { margin-left: 0; }
            .top-header-bar { left: 0; padding-left: 70px; justify-content: flex-end; }
        }
        
        @media (max-width: 768px) {
            .content-area { padding: 20px 15px; }
            .page-header { font-size: 2rem; }
            
            .form-group { flex-direction: column; margin-bottom: 10px; }
            .form-group label {
                flex: none;
                text-align: left;
                padding-right: 0;
                padding-top: 0;
                margin-bottom: 5px;
            }
            
            .error { padding-left: 0; margin-top: 5px; margin-bottom: 15px; }
            .form-control, .contact-input { padding: 8px 10px; font-size: 0.95rem; }
            
            /* Stack action buttons vertically on mobile */
            .form-actions { flex-direction: column; gap: 10px; }
            .form-actions button { width: 100%; }
            .top-header-bar { padding: 0 15px; justify-content: space-between; padding-left: 70px; }
        }
        @media (max-width: 576px) {
            .top-header-bar { padding: 0 15px; justify-content: flex-end; padding-left: 65px; }
            .top-header-bar .notification-bell-container { margin-right: 10px; }
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
            CSM LABORATORY <br>APPARATUS <br>BORROWING
        </div>
    </div>
    
    <ul class="nav flex-column mb-4 sidebar-nav">
        <li class="nav-item">
            <a href="student_dashboard.php" class="nav-link">
                <i class="fas fa-clock fa-fw me-2"></i> Current Activity
            </a>
        </li>
        <li class="nav-item">
            <a href="student_borrow.php" class="nav-link <?= $isBanned ? 'banned' : '' ?>">
                <i class="fas fa-plus-circle fa-fw me-2"></i> Borrow/Reserve <?= $isBanned ? ' (BANNED)' : '' ?>
            </a>
        </li>
        <li class="nav-item">
            <a href="student_return.php" class="nav-link">
                <i class="fas fa-redo fa-fw me-2"></i> Initiate Return
            </a>
        </li>
        <li class="nav-item">
            <a href="student_transaction.php" class="nav-link">
                <i class="fas fa-history fa-fw me-2"></i> Transaction History
            </a>
        </li>
    </ul>
    
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
                <span class="badge rounded-pill badge-counter" id="notification-bell-badge" style="display:none;">0</span>
            </a>
        </li>
    </ul>
    <a href="student_edit.php" class="edit-profile-link">
        <i class="fas fa-user-edit me-1"></i> Edit Profile
    </a>
</header>

<div class="main-wrapper">
    <div class="content-area">
        <div class="form-container-wrapper">
            <h2 class="page-header">
                <i class="fas fa-user-cog fa-fw me-2 text-secondary"></i> Account Management
            </h2>
            
            <?php 
            // Display PROFILE message if set
            $alert_class = '';
            if (strpos($message, '✅') !== false) {
                $alert_class = 'alert-success';
            } elseif (!empty($message)) {
                $alert_class = 'alert-danger';
            }
            if (!empty($message)): 
            ?>
                <div class="alert-custom <?= $alert_class ?>">
                    <?= htmlspecialchars($message) ?>
                </div>
            <?php endif; ?>
            
            <div class="form-section-header">
                <i class="fas fa-info-circle me-2"></i> Personal & Contact Details
            </div>
            
            <form id="profileForm" method="POST" action="">
                <input type="hidden" name="action" value="update_profile">

                <div class="form-group">
                    <label>Student ID</label>
                    <div class="input-wrapper">
                        <input type="text" class="form-control" value="<?= htmlspecialchars($current_data['student_id']) ?>" disabled>
                    </div>
                </div>

                <div class="form-group">
                    <label for="firstname">First Name</label>
                    <div class="input-wrapper">
                        <input type="text" id="firstname" name="firstname" class="form-control <?= isset($errors['firstname']) ? 'is-invalid' : '' ?>" value="<?= htmlspecialchars($current_data['firstname']) ?>" <?= $initial_readonly_state ?>>
                    </div>
                </div>
                <?php if (isset($errors['firstname'])): ?><span class="error"><i class="fas fa-exclamation-circle"></i> <?= $errors['firstname'] ?></span><?php endif; ?>

                <div class="form-group">
                    <label for="lastname">Last Name</label>
                    <div class="input-wrapper">
                        <input type="text" id="lastname" name="lastname" class="form-control <?= isset($errors['lastname']) ? 'is-invalid' : '' ?>" value="<?= htmlspecialchars($current_data['lastname']) ?>" <?= $initial_readonly_state ?>>
                    </div>
                </div>
                <?php if (isset($errors['lastname'])): ?><span class="error"><i class="fas fa-exclamation-circle"></i> <?= $errors['lastname'] ?></span><?php endif; ?>

                <div class="form-group">
                    <label for="course">Course</label>
                    <div class="input-wrapper">
                        <input type="text" id="course" name="course" class="form-control <?= isset($errors['course']) ? 'is-invalid' : '' ?>" value="<?= htmlspecialchars($current_data['course']) ?>" <?= $initial_readonly_state ?>>
                    </div>
                </div>
                <?php if (isset($errors['course'])): ?><span class="error"><i class="fas fa-exclamation-circle"></i> <?= $errors['course'] ?></span><?php endif; ?>

                <div class="form-group">
                    <label for="email">Email Address</label>
                    <div class="input-wrapper">
                        <input type="email" id="email" name="email" class="form-control <?= isset($errors['email']) ? 'is-invalid' : '' ?>" value="<?= htmlspecialchars($current_data['email']) ?>" <?= $initial_readonly_state ?>>
                    </div>
                </div>
                <?php if (isset($errors['email'])): ?><span class="error"><i class="fas fa-exclamation-circle"></i> <?= $errors['email'] ?></span><?php endif; ?>

                <div class="form-group">
                    <label for="contact_number">Contact Number</label>
                    <div class="input-wrapper">
                        <input type="text" id="contact_number" name="contact_number" class="contact-input form-control <?= isset($errors['contact_number']) ? 'is-invalid' : '' ?>" value="<?= htmlspecialchars($current_data['contact_number']) ?>" placeholder="e.g., +639123456789" <?= $initial_readonly_state ?>>
                    </div>
                </div>
                <?php if (isset( $errors['contact_number'])): ?><span class="error"><i class="fas fa-exclamation-circle"></i> <?= $errors['contact_number'] ?></span><?php endif; ?>

                <div class="form-actions">
                    <button type="button" id="editProfileButton" class="btn-theme-primary" style="<?= !empty($errors) ? 'display: none;' : '' ?>"> 
                        <i class="fas fa-edit me-2"></i> Edit Account Information
                    </button>
                    <button type="submit" id="saveProfileButton" class="btn-theme-primary" style="display: none;"> 
                        <i class="fas fa-save me-2"></i> Save Changes
                    </button>
                    <button type="button" id="cancelEditButton" class="btn-cancel" style="<?= !empty($errors) ? 'display: inline-block;' : 'display: none;' ?>">
                        <i class="fas fa-times me-2"></i> Cancel
                    </button>
                </div>
            </form>
            
            <div class="form-section-header">
                <i class="fas fa-lock me-2"></i> Password & Security
            </div>
            
            <form id="passwordForm" method="POST" action="">
                <input type="hidden" name="action" value="change_password">
                
                <?php 
                // Display password-specific message if errors were present on page load
                if (!empty($pass_message)): 
                ?>
                    <div class="alert-custom <?= $pass_message_type === 'success' ? 'alert-success' : 'alert-danger' ?>">
                        <?= htmlspecialchars($pass_message) ?>
                    </div>
                <?php endif; ?>

                <div class="form-group">
                    <label for="current_password">Current Password</label>
                    <div class="input-wrapper">
                        <input type="password" id="current_password" name="current_password" 
                            class="form-control <?= isset($pass_errors['current_password']) ? 'is-invalid' : '' ?>" placeholder="Enter current password">
                        <i class="fas fa-eye password-toggle" onclick="togglePasswordVisibility('current_password', this)"></i>
                    </div>
                </div>
                <?php if (isset($pass_errors['current_password'])): ?><span class="error-inline"><i class="fas fa-exclamation-circle"></i> <?= $pass_errors['current_password'] ?></span><?php endif; ?>

                <div class="form-group">
                    <label for="new_password">New Password (Min 8 Chars)</label>
                    <div class="input-wrapper">
                        <input type="password" id="new_password" name="new_password" 
                            class="form-control <?= isset($pass_errors['new_password']) ? 'is-invalid' : '' ?>" placeholder="Enter new password">
                        <i class="fas fa-eye password-toggle" onclick="togglePasswordVisibility('new_password', this)"></i>
                    </div>
                </div>
                <?php if (isset($pass_errors['new_password'])): ?><span class="error-inline"><i class="fas fa-exclamation-circle"></i> <?= $pass_errors['new_password'] ?></span><?php endif; ?>

                <div class="form-group">
                    <label for="confirm_password">Confirm New Password</label>
                    <div class="input-wrapper">
                        <input type="password" id="confirm_password" name="confirm_password" 
                            class="form-control <?= isset($pass_errors['confirm_password']) ? 'is-invalid' : '' ?>" placeholder="Confirm new password">
                        <i class="fas fa-eye password-toggle" onclick="togglePasswordVisibility('confirm_password', this)"></i>
                    </div>
                </div>
                <?php if (isset($pass_errors['confirm_password'])): ?><span class="error-inline"><i class="fas fa-exclamation-circle"></i> <?= $pass_errors['confirm_password'] ?></span><?php endif; ?>

                <div class="form-actions" style="justify-content: center;">
                    <button type="submit" id="submitPasswordButton" class="btn-theme-primary"> 
                        <i class="fas fa-key me-2"></i> Change Password
                    </button>
                </div>
            </form>
            
        </div>
    </div>
</div>

<div class="modal fade" id="statusModal" tabindex="-1" aria-labelledby="statusModalLabel" aria-hidden="true">
    <div class="modal-dialog modal-md modal-dialog-centered">
        <div class="modal-content">
            <div class="modal-header" id="statusModalHeader">
                <h5 class="modal-title fw-bold" id="statusModalLabel"></h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body text-center" id="statusModalBody">
            </div>
            <div class="modal-footer justify-content-center">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Close</button>
            </div>
        </div>
    </div>
</div>


<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
<script>
    let profileFormChanged = false;
    let formSubmitting = false; 

    function togglePasswordVisibility(id, iconElement) {
        const input = document.getElementById(id);
        if (input.type === "password") {
            input.type = "text";
            iconElement.classList.remove('fa-eye');
            iconElement.classList.add('fa-eye-slash');
        } else {
            input.type = "password";
            iconElement.classList.remove('fa-eye-slash');
            iconElement.classList.add('fa-eye');
        }
    }
    
    function checkFormChanges(form, initialValues) {
        let changed = false;
        // Loop through inputs that were initially editable (which are the ones we track by ID)
        // We ensure we only check fields that are NOT the disabled Student ID field.
        form.querySelectorAll('#firstname, #lastname, #course, #email, #contact_number').forEach(input => {
            if (initialValues[input.id] !== input.value) {
                changed = true;
            }
        });
        return changed;
    }

    function displayStatus(message, type, redirectOnSuccess = false) {
        const statusModalElement = document.getElementById('statusModal');
        const statusModal = new bootstrap.Modal(statusModalElement);
        const header = document.getElementById('statusModalHeader');
        const label = document.getElementById('statusModalLabel');
        const body = document.getElementById('statusModalBody');

        header.className = 'modal-header';
        body.innerHTML = `<p>${message.replace(/✅|❌|⚠️/g, '<strong>')}</p>`;

        if (type === 'success') {
            header.classList.add('alert-success');
            header.classList.remove('alert-danger');
            label.innerHTML = '<i class="fas fa-check-circle me-2"></i> Success!';
        } else {
            header.classList.add('alert-danger');
            header.classList.remove('alert-success');
            label.innerHTML = '<i class="fas fa-exclamation-triangle me-2"></i> Notification'; // Changed to Notification for generic errors
        }
        
        statusModal.show();
        
        if (redirectOnSuccess && type === 'success') {
             // Handle the logout redirect after password change
             statusModalElement.addEventListener('hidden.bs.modal', function () {
                window.location.href = '../pages/logout.php';
             }, { once: true });
        }
    }


    document.addEventListener('DOMContentLoaded', () => {
        const profileForm = document.getElementById('profileForm');
        const editButton = document.getElementById('editProfileButton');
        const saveButton = document.getElementById('saveProfileButton');
        const cancelButton = document.getElementById('cancelEditButton');
        
        // Select only the editable inputs
        const profileInputs = profileForm.querySelectorAll('#firstname, #lastname, #course, #email, #contact_number'); 
        
        // --- Store Initial Profile Values for Cancellation/Change Detection ---
        const profileInitialValues = {};
        profileInputs.forEach(input => { 
             profileInitialValues[input.id] = input.value; 
        });

        // Function to set fields back to read-only, revert values, and hide action buttons
        function disableProfileFieldsAndRevert() {
            profileInputs.forEach(input => {
                input.value = profileInitialValues[input.id]; // Revert value
                // FIX: Set 'readonly' attribute
                input.setAttribute('readonly', 'readonly'); 
                input.classList.remove('is-invalid'); // Remove error styles
            });
            // Clear inline errors below fields
            profileForm.querySelectorAll('.error').forEach(span => span.textContent = '');
            
            editButton.style.display = 'inline-block';
            saveButton.style.display = 'none';
            cancelButton.style.display = 'none';
            profileFormChanged = false; // Reset flag
        }

        // --- Event Listeners ---
        
        // If there are errors on load, the fields are automatically NOT readonly, so we need to hide the EDIT button and show save/cancel
        const initialReadonly = '<?= $initial_readonly_state ?>';
        if (initialReadonly === '') {
             // Fields are enabled due to validation failure, adjust buttons
             editButton.style.display = 'none';
             saveButton.style.display = 'inline-block';
             cancelButton.style.display = 'inline-block';
        }


        // 1. Profile Change Detection
        profileInputs.forEach(input => {
            input.addEventListener('input', () => {
                profileFormChanged = checkFormChanges(profileForm, profileInitialValues);
            });
        });
        
        // 2. EDIT Button Logic (Removes Readonly/Enables fields)
        if (editButton) {
            editButton.addEventListener('click', () => {
                profileInputs.forEach(input => {
                    // FIX: Remove 'readonly' attribute
                    input.removeAttribute('readonly'); 
                });
                editButton.style.display = 'none';
                saveButton.style.display = 'inline-block';
                cancelButton.style.display = 'inline-block';
            });
        }
        
        // 3. CANCEL Button Logic (Reverts changes and disables fields)
        if (cancelButton) {
            cancelButton.addEventListener('click', disableProfileFieldsAndRevert);
        }

        // 4. SAVE Button Logic (Requires form changes)
        if (saveButton) {
            saveButton.addEventListener('click', (e) => {
                profileFormChanged = checkFormChanges(profileForm, profileInitialValues);
                if (profileFormChanged) {
                    // Set flag to bypass beforeunload check
                    formSubmitting = true;
                    // Allow submission to proceed normally
                } else {
                    e.preventDefault(); // Stop form submission
                    
                    // FIX: Replace alert() with displayStatus() using the modal
                    displayStatus("⚠️ Action Cancelled: No changes were detected in your profile. Nothing to save.", 'danger', false);
                }
            });
        }
        
        // 5. Submission Result Handlers (Modals)
        
        // --- A. Profile Submission Success/Error ---
        const profileMessage = '<?= addslashes($message) ?>';
        const profileMessageType = '<?= $message_type ?>';

        if (profileMessage && profileMessageType) {
             if (profileMessageType === 'success') {
                // Show modal for success
                displayStatus(profileMessage, profileMessageType, false); 
                // After successful update, revert to read-only state and update stored values
                disableProfileFieldsAndRevert();
                profileInputs.forEach(input => {
                     profileInitialValues[input.id] = input.value;
                });
             } else if (profileMessageType === 'danger') {
                 // Show modal for validation failure
                 displayStatus(profileMessage, profileMessageType, false); 
             }
        }

        // --- B. Password Submission Success/Logout Trigger ---
        if (sessionStorage.getItem("passUpdated") === "true") {
            sessionStorage.removeItem("passUpdated");
            // Show success modal and trigger logout upon closing
            displayStatus("✅ Success! Your password has been updated. You will be logged out upon closing this window.", 'success', true);
        }
        
        // --- Unsaved Changes Warning (beforeunload) ---
        window.addEventListener('beforeunload', function (e) {
            // Check if fields are currently NOT readonly and if changes were made
            const isEditing = profileInputs[0].hasAttribute('readonly') === false;
            if (isEditing && profileFormChanged && !formSubmitting) {
                e.preventDefault(); 
                return e.returnValue = 'You have unsaved changes. Are you sure you want to leave?';
            }
        });

        // Mobile Toggle Logic (Remains unchanged)
        const menuToggle = document.getElementById('menuToggle');
        const sidebar = document.querySelector('.sidebar');
        const mainWrapper = document.querySelector('.main-wrapper');
        const topHeaderBar = document.querySelector('.top-header-bar');

        if (menuToggle && sidebar) {
            menuToggle.addEventListener('click', () => {
                sidebar.classList.toggle('active');
                if (window.innerWidth <= 992) {
                     if (sidebar.classList.contains('active')) {
                         document.body.style.overflow = 'hidden'; 
                         mainWrapper.addEventListener('click', closeSidebarOnce);
                         topHeaderBar.addEventListener('click', closeSidebarOnce);
                     } else {
                         document.body.style.overflow = 'auto'; 
                         mainWrapper.removeEventListener('click', closeSidebarOnce);
                         topHeaderBar.removeEventListener('click', closeSidebarOnce);
                     }
                }
            });
            
            function closeSidebarOnce() {
                 sidebar.classList.remove('active');
                 document.body.style.overflow = 'auto'; 
                 mainWrapper.removeEventListener('click', closeSidebarOnce);
                 topHeaderBar.removeEventListener('click', closeSidebarOnce);
            }
            
            const navLinks = sidebar.querySelectorAll('.nav-link');
            navLinks.forEach(link => {
                 link.addEventListener('click', () => {
                     if (window.innerWidth <= 992) {
                         setTimeout(() => {
                             sidebar.classList.remove('active');
                             document.body.style.overflow = 'auto';
                         }, 100);
                     }
                 });
            });
        }
    });
</script>

</body>
</html>