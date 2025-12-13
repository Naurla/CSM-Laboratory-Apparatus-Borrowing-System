<?php
session_start();
require_once "../classes/Student.php"; 
require_once "../classes/Transaction.php"; 

// 1. Redirect if not logged in or not a student
if (!isset($_SESSION['user']) || $_SESSION['user']['role'] !== 'student') {
    header("Location: ../pages/login.php");
    exit;
}

$student = new Student();
$transaction = new Transaction();
$user_id = $_SESSION['user']['id'];
$errors = [];
$message = "";

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


// Check if form was submitted
if ($_SERVER["REQUEST_METHOD"] == "POST") {
    
    $new_firstname = trim($_POST["firstname"]);
    $new_lastname = trim($_POST["lastname"]);
    $new_course = trim($_POST["course"]);
    $new_email = trim($_POST["email"]);
    $new_contact_number = trim($_POST["contact_number"]); 
    
    // --- Validation (Server-side validation remains to enforce data integrity) ---
    if (empty($new_firstname)) $errors["firstname"] = "Your first name cannot be empty. Please provide your official first name.";
    if (empty($new_lastname)) $errors["lastname"] = "Your last name is required for identification. Please enter it.";
    if (empty($new_course)) $errors["course"] = "Your course/program is mandatory.";

    if (empty($new_contact_number)) {
        $errors["contact_number"] = "Contact number is required for laboratory communication.";
    } else {
        $clean_number = preg_replace('/[^0-9\+]/', '', $new_contact_number); 
        if (strlen($clean_number) < 8 || strlen( $clean_number) > 20) {
            $errors["contact_number"] = "The contact number seems invalid. Please ensure it includes the country code (e.g., +639xxxxxxxxx) and is between 8 and 20 characters long.";
        }
    }

    if (empty($new_email)) {
        $errors["email"] = "Email address is essential for account recovery and notifications.";
    } elseif (!filter_var($new_email, FILTER_VALIDATE_EMAIL)) {
        $errors["email"] = "The email format is invalid. Please double-check for typos (e.g., user@example.com).";
    }
    
    // --- Duplicate Email Check (Only if email changed) ---
    if (empty($errors) && $new_email !== $_SESSION['user']['email']) {
        if ($student->isEmailExist($new_email)) {
            $errors['email'] = "This Email address is already linked to another user account. Please use a unique email.";
        }
    }

    // 3. Process Update
    if (empty($errors)) {
        $full_contact = $clean_number; 
        
        $result = $student->updateStudentProfile(
            $user_id, $new_firstname, $new_lastname, $new_course, $full_contact, $new_email
        );
        
        if ($result) {
            $message = "✅ Success! Your profile details have been updated.";
            
            // 4. Update Session variables on success
            $_SESSION['user']['firstname'] = $new_firstname;
            $_SESSION['user']['lastname'] = $new_lastname;
            $_SESSION['user']['course'] = $new_course;
            $_SESSION['user']['email'] = $new_email;
            
            // Re-initialize local form variables with new data
            $current_data['firstname'] = $new_firstname;
            $current_data['lastname'] = $new_lastname;
            $current_data['course'] = $new_course;
            $current_data['email'] = $new_email;
            $current_data['contact_number'] = $new_contact_number; 
            
            // Set a flag to bypass the 'unsaved changes' warning after successful submission
            echo '<script>sessionStorage.setItem("formSubmitted", "true");</script>';

        } else {
            $message = "❌ System Error: We encountered an issue while saving to the database. Please try again or contact support.";
        }
    } else {
        $message = "⚠️ Validation Failed: Please review and correct the marked fields below before proceeding.";
        
        // Retain user input in case of error
        $current_data['firstname'] = $new_firstname;
        $current_data['lastname'] = $new_lastname;
        $current_data['course'] = $new_course;
        $current_data['email'] = $new_email;
        $current_data['contact_number'] = $new_contact_number;
    }
}
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
        /* --- COPYING STYLES FROM DASHBOARD --- */
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
            display: none; /* Hidden on desktop */
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
        /* Notification Bell (Restored) */
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
            background-color: var(--secondary-color); /* Use accent color */
            color: var(--text-dark);
            font-weight: bold;
        }
        /* Active Link Styling for Edit Profile */
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
            border-bottom: 2px solid var(--primary-color); /* Use primary color */
            font-weight: 700;
            font-size: 2.2rem;
        }
        
        /* --- Form Styles --- */
        .form-container-wrapper {
            width: 95%; 
            max-width: 800px; 
            margin: 0 auto; 
        }
        .form-group {
            display: flex;
            margin-bottom: 20px;
            align-items: flex-start;
        }
        .form-group label {
            flex: 0 0 160px; 
            padding-right: 20px;
            text-align: right;
            padding-top: 8px;
            font-weight: 600;
            font-size: 1rem;
        }
        .form-control, .contact-input { 
            flex: 1;
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
        
        /* --- Disabled/Read-Only Styling for Student ID --- */
        input[disabled] {
            background-color: #f5f5f5; 
            border-color: #e0e0e0; 
            opacity: 1; 
            cursor: default;
        }
        
        /* Centering the Form Actions */
        .form-actions {
            padding-left: 0; 
            margin-top: 30px;
            text-align: center; 
        }
        .error {
            color: var(--primary-color); /* Use primary color for errors */
            font-size: 0.95rem;
            margin-top: -15px; 
            margin-bottom: 20px; 
            padding-left: 180px; 
            font-weight: 600;
            display: block;
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
        .alert-success {
            background-color: #d4edda; 
            color: #155724; 
            border: 1px solid #c3e6cb;
        }
        .alert-danger { /* Used for validation and system errors */
            background-color: #f8d7da;
            color: #721c24;
            border: 1px solid #f5c6cb;
        }
        
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
        
        /* Modal button styling (Updated to use theme colors) */
        .modal-footer .btn-secondary {
            background-color: #6c757d;
            border-color: #6c757d;
            color: white;
            font-weight: 600;
        }
        .modal-footer .btn-custom-ok {
            background-color: var(--primary-color);
            border-color: var(--primary-color);
            color: white;
            font-weight: 700;
        }


        /* --- RESPONSIVE ADJUSTMENTS --- */
        @media (max-width: 992px) {
            /* Mobile Sidebar */
            .menu-toggle { display: block; }
            .sidebar { left: calc(var(--sidebar-width) * -1); transition: left 0.3s ease; box-shadow: none; --sidebar-width: 250px; }
            .sidebar.active { left: 0; box-shadow: 2px 0 5px rgba(0, 0, 0, 0.2); }
            .main-wrapper { margin-left: 0; }
            .top-header-bar { left: 0; padding-left: 70px; justify-content: flex-end; }
        }
        
        @media (max-width: 768px) {
            .content-area { padding: 20px 15px; }
            .page-header { font-size: 2rem; }
            /* Stack form elements */
            .form-group {
                flex-direction: column;
                margin-bottom: 10px;
            }
            .form-group label {
                flex: none;
                text-align: left;
                padding-right: 0;
                padding-top: 0;
                margin-bottom: 5px;
            }
            /* Adjust error messages to start from the left */
            .error {
                padding-left: 0;
                margin-top: 5px;
                margin-bottom: 15px;
            }
            .form-control, .contact-input {
                 padding: 8px 10px;
                 font-size: 0.95rem;
            }
            .form-actions button {
                 width: 100%;
            }
            .top-header-bar {
                 /* Re-adjust for better spacing */
                 padding: 0 15px;
                 justify-content: space-between;
                 padding-left: 70px;
            }
        }
        @media (max-width: 576px) {
            .top-header-bar {
                 padding: 0 15px;
                 justify-content: flex-end;
                 padding-left: 65px;
            }
            .top-header-bar .notification-bell-container {
                 margin-right: 10px;
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
                <i class="fas fa-undo-alt fa-fw me-2"></i> Initiate Return
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
                <i class="fas fa-user-edit fa-fw me-2 text-secondary"></i> Edit Profile Details
            </h2>
            
            <?php 
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

            <form id="profileForm" method="POST" action="">

                <div class="form-group">
                    <label>Student ID</label>
                    <input type="text" class="form-control" value="<?= htmlspecialchars($current_data['student_id']) ?>" disabled>
                </div>

                <div class="form-group">
                    <label for="firstname">First Name</label>
                    <input type="text" id="firstname" name="firstname" class="form-control <?= isset($errors['firstname']) ? 'is-invalid' : '' ?>" value="<?= htmlspecialchars($current_data['firstname']) ?>">
                </div>
                <?php if (isset($errors['firstname'])): ?><span class="error"><i class="fas fa-exclamation-circle"></i> <?= $errors['firstname'] ?></span><?php endif; ?>

                <div class="form-group">
                    <label for="lastname">Last Name</label>
                    <input type="text" id="lastname" name="lastname" class="form-control <?= isset($errors['lastname']) ? 'is-invalid' : '' ?>" value="<?= htmlspecialchars($current_data['lastname']) ?>">
                </div>
                <?php if (isset($errors['lastname'])): ?><span class="error"><i class="fas fa-exclamation-circle"></i> <?= $errors['lastname'] ?></span><?php endif; ?>

                <div class="form-group">
                    <label for="course">Course</label>
                    <input type="text" id="course" name="course" class="form-control <?= isset($errors['course']) ? 'is-invalid' : '' ?>" value="<?= htmlspecialchars($current_data['course']) ?>">
                </div>
                <?php if (isset($errors['course'])): ?><span class="error"><i class="fas fa-exclamation-circle"></i> <?= $errors['course'] ?></span><?php endif; ?>

                <div class="form-group">
                    <label for="email">Email Address</label>
                    <input type="email" id="email" name="email" class="form-control <?= isset($errors['email']) ? 'is-invalid' : '' ?>" value="<?= htmlspecialchars($current_data['email']) ?>">
                </div>
                <?php if (isset($errors['email'])): ?><span class="error"><i class="fas fa-exclamation-circle"></i> <?= $errors['email'] ?></span><?php endif; ?>

                <div class="form-group">
                    <label for="contact_number">Contact Number</label>
                    <input type="text" id="contact_number" name="contact_number" class="contact-input form-control <?= isset($errors['contact_number']) ? 'is-invalid' : '' ?>" value="<?= htmlspecialchars($current_data['contact_number']) ?>" placeholder="e.g., +639123456789">
                </div>
                <?php if (isset( $errors['contact_number'])): ?><span class="error"><i class="fas fa-exclamation-circle"></i> <?= $errors['contact_number'] ?></span><?php endif; ?>

                <div class="form-actions">
                    <button type="button" id="openConfirmModal" class="btn-theme-primary"> 
                        <i class="fas fa-save me-2"></i> Save Changes
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>

<div class="modal fade" id="confirmationModal" tabindex="-1" aria-labelledby="confirmationModalLabel" aria-hidden="true">
    <div class="modal-dialog modal-md modal-dialog-centered"> 
        <div class="modal-content">
            <div class="modal-body text-center">
                Are you sure you want to apply these profile changes? This will update your account details.
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary btn-sm btn-custom-cancel" data-bs-dismiss="modal">Cancel</button>
                <button type="button" class="btn btn-danger btn-sm btn-custom-ok" id="confirmSubmit">OK</button>
            </div>
        </div>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
<script>
    let formChanged = false; // Flag to track if the form content has been modified
    let formSubmitting = false; // Flag to track if the form is intentionally being submitted

    document.addEventListener('DOMContentLoaded', () => {
        const form = document.getElementById('profileForm');
        const openModalButton = document.getElementById('openConfirmModal');
        const confirmSubmitButton = document.getElementById('confirmSubmit');
        const confirmationModal = new bootstrap.Modal(document.getElementById('confirmationModal'));
        
        // --- Sidebar Activation Logic ---
        const links = document.querySelectorAll('.sidebar .nav-link');
        const currentPath = 'student_edit.php'; 
        
        links.forEach(link => {
             const linkPath = link.getAttribute('href').split('/').pop();
            
             if (linkPath === currentPath) {
                 link.classList.add('active');
             } else {
                 link.classList.remove('active');
             }
        });

        // --- 1. Track Form Changes ---
        const inputFields = form.querySelectorAll('input:not([type="hidden"]):not([disabled])');
        
        // Store initial values to compare later
        const initialValues = {};
        inputFields.forEach(input => {
             initialValues[input.id] = input.value;
        });
        
        // Check for changes on input/change events
        inputFields.forEach(input => {
            input.addEventListener('input', () => {
                let changed = false;
                inputFields.forEach(i => {
                    if (initialValues[i.id] !== i.value) {
                        changed = true;
                    }
                });
                formChanged = changed;
            });
        });

        // --- 2. Save Confirmation Modal Logic ---
        openModalButton.addEventListener('click', () => {
            // Re-check changes immediately before showing modal
            let changed = false;
            inputFields.forEach(i => {
                if (initialValues[i.id] !== i.value) {
                    changed = true;
                }
            });
            formChanged = changed;

            if (formChanged) {
                confirmationModal.show();
            } else {
                alert("No changes detected in your profile. Nothing to save.");
            }
        });

        // 3. Handle the "OK" click inside the modal
        confirmSubmitButton.addEventListener('click', () => {
            confirmationModal.hide(); 
            formSubmitting = true; // Set flag to bypass beforeunload check
            form.submit();
        });
        
        // --- 4. Unsaved Changes Warning (beforeunload) ---
        window.addEventListener('beforeunload', function (e) {
            // Check if the page is being left and the form has unsaved changes AND 
            // the user is NOT intentionally clicking the save button.
            if (formChanged && !formSubmitting) {
                e.preventDefault(); 
                return e.returnValue = 'You have unsaved changes. Are you sure you want to leave?';
            }
        });
        
        // --- Reset formSubmitting flag if successful (from PHP session flag) ---
        if (sessionStorage.getItem("formSubmitted") === "true") {
            formChanged = false;
            sessionStorage.removeItem("formSubmitted");
        }

        // New Mobile Toggle Logic
        const menuToggle = document.getElementById('menuToggle');
        const sidebar = document.querySelector('.sidebar');
        const mainWrapper = document.querySelector('.main-wrapper');

        if (menuToggle && sidebar) {
            menuToggle.addEventListener('click', () => {
                sidebar.classList.toggle('active');
                if (window.innerWidth <= 992) {
                     if (sidebar.classList.contains('active')) {
                        document.body.style.overflow = 'hidden'; 
                        mainWrapper.addEventListener('click', closeSidebarOnce);
                    } else {
                        document.body.style.overflow = 'auto'; 
                        mainWrapper.removeEventListener('click', closeSidebarOnce);
                    }
                }
            });
            
            function closeSidebarOnce() {
                 sidebar.classList.remove('active');
                 document.body.style.overflow = 'auto'; 
                 mainWrapper.removeEventListener('click', closeSidebarOnce);
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