<?php
session_start(); // Start session to store messages
require_once "../classes/Student.php"; 
require_once '../vendor/autoload.php'; 
require_once '../classes/Mailer.php'; 

$errors = [];
$global_message = "";
$global_message_type = ""; // 'error' or 'success'

// Initialize values from POST or empty string
$student_id = $_POST["student_id"] ?? '';
$firstname = $_POST["firstname"] ?? '';
$lastname = $_POST["lastname"] ?? '';
$course = $_POST["course"] ?? '';
$contact_number = $_POST["contact_number"] ?? ''; 
$full_contact_number = ''; 
$email = $_POST["email"] ?? '';
$password = $_POST["password"] ?? '';
$confirm_password = $_POST["confirm_password"] ?? '';


if ($_SERVER["REQUEST_METHOD"] == "POST") {
    // Capture input values safely (re-trimmed)
    $student_id = trim($student_id);
    $firstname = trim($firstname);
    $lastname = trim($lastname);
    $course = trim($course);
    $contact_number = trim($contact_number);
    $email = trim($email);
    
    // Instantiate the class
    $student = new Student();

    // --- VALIDATION LOGIC ---
    if (empty($student_id)) {
        $errors["student_id"] = "Student ID is required.";
    } elseif (!preg_match("/^[0-9]{4}-[0-9]{5}$/", $student_id)) {
        $errors["student_id"] = "Student ID must follow the pattern YYYY-##### (e.g., 2024-01203).";
    }

    if (empty($firstname)) $errors["firstname"] = "First name is required.";
    if (empty($lastname)) $errors["lastname"] = "Last name is required.";
    if (empty($course)) $errors["course"] = "Course is required.";

    // --- UPDATED CONTACT NUMBER VALIDATION ---
    if (empty($contact_number)) {
        $errors["contact_number"] = "Contact number is required.";
    } else {
        // Assume Philippines default code (+63) for validation, but the user must include the full number.
        $clean_number = $contact_number;
        if (substr($clean_number, 0, 1) !== '+') {
            // For example, if user types '9171234567', it becomes '+639171234567'
            $full_contact_number = '+63' . preg_replace('/[^0-9]/', '', $clean_number);
        } else {
            // If user types '+639171234567', we use it as is
            $full_contact_number = preg_replace('/[^0-9+]/', '', $clean_number);
        }

        // Validate the length of the *digits only* after stripping non-numeric characters (and the plus sign if present)
        $digits_only = preg_replace('/[^0-9]/', '', $full_contact_number);
        
        // Typical global minimum/maximum length check for a full international number (10-15 digits after country code)
        if (strlen($digits_only) < 10 || strlen($digits_only) > 15) {
            $errors["contact_number"] = "Enter a valid full mobile number (e.g. +63917... or 0917...).";
        }
    }
    // ------------------------------------------

    if (empty($email)) {
        $errors["email"] = "Email is required.";
    } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $errors["email"] = "Invalid email format.";
    }

    if (empty($password)) {
        $errors["password"] = "Password is required.";
    } elseif (strlen($password) < 8) {
        $errors["password"] = "Password must be at least 8 characters."; 
    }
    
    if ($password !== $confirm_password) $errors["confirm_password"] = "Passwords do not match.";
    
    
    if (empty($errors)) {
        
        // --- DUPLICATE CHECK LOGIC ---
        $id_exists = $student->isStudentIdExist($student_id);
        $email_exists = $student->isEmailExist($email);

        if ($id_exists || $email_exists) {
            $global_message = "Registration failed. Please correct the errors below.";
            $global_message_type = 'error';
            
            if ($id_exists) {
                $errors['student_id'] = "An account with this Student ID already exists.";
            }
            if ($email_exists) {
                $errors['email'] = "An account with this Email address already exists.";
            }

        } else {
            // --- SUCCESS PATH WITH EMAIL VERIFICATION ---
            
            // 1. Generate the 6-digit verification code
            $code = strval(rand(100000, 999999));
            
            // 2. Register the student, passing the NEW $code
            $result = $student->registerStudent(
                $student_id, $firstname, $lastname, $course, $full_contact_number, $email, $password, $code
            );

            if ($result) {
                // 3. Send the Verification Email
                $mailer = new Mailer();
                $email_sent = $mailer->sendVerificationEmail($email, $code);

                // Set a session flash message for the verification page redirect
                if ($email_sent) {
                    $_SESSION['flash_message'] = ['type' => 'success', 'content' => "Registration successful! A 6-digit verification code has been sent to **{$email}**."];
                } else {
                    $_SESSION['flash_message'] = ['type' => 'warning', 'content' => "Registration successful, but the verification email failed to send. Please contact support."];
                }
                
                // Redirect to the code verification page, passing the email for code submission
                header("Location: verify.php?email=" . urlencode($email)); 
                exit;
            } else {
                $global_message = "Registration failed due to a database error. Please check server logs.";
                $global_message_type = 'error';
            }
        }
    } 
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Create New Account - CSM Laboratory</title>
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0-beta3/css/all.min.css" rel="stylesheet">
    <style>
        /* --- CARD THEME MATCHING (Consistent Theme) --- */
        
        :root {
            --primary-color: #A40404; /* Dark Red / Maroon (WMSU-inspired) */
            --secondary-color: #f4b400; /* Gold/Yellow Accent */
            --text-dark: #2c3e50;
            --text-light: #ecf0f1;
            --background-light: #f8f9fa;
        }
        
        /* Global & Layout Styles */
        body {
            /* Consistent background image and overlay from index.php/login.php */
            background: 
                linear-gradient(rgba(0, 0, 0, 0.4), rgba(0, 0, 0, 0.4)), 
                url("../uploads/Western_Mindanao_State_University_College_of_Teacher_Education_(Normal_Road,_Baliwasan,_Zamboanga_City;_10-06-2023).jpg") 
                no-repeat center center fixed; 
            background-size: cover;
            
            display: flex;
            justify-content: center;
            align-items: center;
            min-height: 100vh;
            margin: 0;
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
            color: var(--text-dark);
        }
        
        @keyframes fadeIn {
            from { opacity: 0; transform: translateY(20px); }
            to { opacity: 1; transform: translateY(0); }
        }

        .card {
            background: rgba(255, 255, 255, 0.98);
            border-radius: 12px; /* Consistent rounded corners */
            box-shadow: 0 10px 40px rgba(0, 0, 0, 0.4); /* Stronger, modern shadow */
            padding: 40px;
            width: 100%;
            max-width: 500px; /* Slightly wider card for longer registration form */
            text-align: center;
            z-index: 10; 
            animation: fadeIn 0.8s ease-out; /* Subtle animation */
        }
        
        /* Header and Branding */
        .logo {
            max-width: 100px; /* Consistent logo size */
            margin: 0 auto 5px auto;
        }
        .app-title {
            color: var(--primary-color); 
            font-size: 1.1rem; /* Consistent font size */
            font-weight: 700;
            line-height: 1.3;
            margin-bottom: 10px;
            text-transform: uppercase;
            letter-spacing: 1.5px;
        }
        h2 {
            font-size: 1.75rem; 
            margin-bottom: 25px;
            color: var(--text-dark);
            font-weight: 600;
        }
        
        .section-title {
            color: var(--primary-color); 
            font-size: 1.15em; 
            font-weight: 700; 
            text-align: left;
            padding-bottom: 8px; 
            border-bottom: 2px solid var(--secondary-color); /* Use accent color for separator */
            margin: 30px 0 20px 0;
        }

        /* Alerts */
        .message-box {
            padding: 15px;
            border-radius: 6px;
            margin-bottom: 25px;
            text-align: left;
            font-size: 0.95em;
            border: 1px solid transparent;
            font-weight: 600;
        }
        .message-box.error {
            color: #721c24; /* Dark red text */
            background-color: #f8d7da; /* Light red background */
            border-color: #f5c6cb;
        }

        /* Form Elements */
        .input-group {
            margin-bottom: 20px;
            text-align: left;
            position: relative; 
        }
        .input-group label {
            display: block;
            color: var(--text-dark);
            font-weight: 600;
            margin-bottom: 8px;
            font-size: 0.95em;
        }
        .input-field {
            width: 100%;
            padding: 12px 15px; 
            height: 48px; /* Consistent height */
            border: 1px solid #ddd;
            border-radius: 6px; 
            font-size: 1rem;
            box-sizing: border-box;
            transition: border-color 0.2s, box-shadow 0.2s;
        }
        .input-field:focus {
            border-color: var(--secondary-color); /* Consistent focus color */
            outline: none;
            box-shadow: 0 0 0 3px rgba(244, 180, 0, 0.2);
        }
        .input-field.error {
            border-color: var(--primary-color) !important;
        }
        
        /* Password Toggle Icon */
        .toggle-password {
            position: absolute;
            right: 15px;
            /* Recalculate based on input field height (48px) to center it */
            top: 50%; 
            transform: translateY(-50%); 
            cursor: pointer;
            color: #666;
            font-size: 1.1rem;
            z-index: 10;
        }
        
        /* Error Text */
        .error-text {
            color: var(--primary-color); 
            font-size: 0.85em;
            margin-top: 5px; 
            display: block;
            font-weight: 600;
        }

        /* Button Style (Updated to pill shape and new class) */
        .btn-continue {
            background-color: var(--primary-color); 
            color: #ffffff;
            padding: 15px 15px; /* Consistent button padding */
            border: none;
            border-radius: 50px; /* Pill shape */
            font-size: 1.1rem;
            font-weight: 700;
            cursor: pointer;
            width: 100%;
            transition: background-color 0.3s ease, transform 0.2s, box-shadow 0.3s;
            margin-top: 30px; 
            box-shadow: 0 4px 10px rgba(0, 0, 0, 0.1);
        }
        .btn-continue:hover {
            background-color: #820303; 
            transform: translateY(-2px);
            box-shadow: 0 6px 15px rgba(0, 0, 0, 0.2);
        }
        .btn-continue:active {
            transform: translateY(0);
        }
        
        /* Links */
        .bottom-link-container {
            text-align: center;
            margin-top: 25px;
            font-size: 0.95em;
        }
        .link-text {
            color: var(--primary-color); 
            text-decoration: none;
            font-weight: 600;
            transition: text-decoration 0.2s;
        }
        .link-text:hover {
            text-decoration: underline;
        }
        
        /* Back to Home Link (Consistent with login.php) */
        .back-to-home-link {
            text-align: center;
            margin-top: 20px;
        }
        .back-to-home-link a {
            color: var(--text-dark);
            text-decoration: none;
            font-size: 0.9rem;
            font-weight: 500;
            transition: color 0.2s;
        }
        .back-to-home-link a:hover {
            color: var(--primary-color);
            text-decoration: underline;
        }

        @media (max-width: 550px) {
            .card {
                margin: 20px;
                padding: 30px;
            }
        }
    </style>
</head>
<body>

<div class="card">
    <img src="../wmsu_logo/wmsu.png" alt="WMSU Logo" class="logo">
    <div class="app-title">CSM LABORATORY BORROWING APPARATUS</div>
    
    <h2>Create a New Account</h2>

    <?php if (!empty($global_message)): ?>
        <div class="message-box <?= $global_message_type ?>">
            <i class="fas fa-exclamation-triangle"></i> <?= htmlspecialchars($global_message) ?>
        </div>
    <?php endif; ?>

    <form method="POST" action="">
        
        <div class="section-title"><i class="fas fa-lock"></i> Account Details</div>

        <div class="input-group">
            <label for="student_id">Student ID</label>
            <input type="text" id="student_id" name="student_id" class="input-field <?= isset($errors["student_id"]) ? 'error' : '' ?>" 
                    value="<?= htmlspecialchars($student_id) ?>" placeholder="e.g., 2024-01203">
            <?php if (isset($errors["student_id"])): ?><span class="error-text"><i class="fas fa-exclamation-circle"></i> <?= $errors["student_id"] ?></span><?php endif; ?>
        </div>
        
        <div class="input-group">
            <label for="email">Email address</label>
            <input type="email" id="email" name="email" class="input-field <?= isset($errors["email"]) ? 'error' : '' ?>" 
                    value="<?= htmlspecialchars($email) ?>" placeholder="e.g., email@wmsu.edu.ph" >
            <?php if (isset($errors["email"])): ?><span class="error-text"><i class="fas fa-exclamation-circle"></i> <?= $errors["email"] ?></span><?php endif; ?>
        </div>

        <div class="input-group">
            <label for="password">Password (Min 8 characters)</label>
            <div style="position: relative;">
                <input type="password" id="password" name="password" class="input-field <?= isset($errors["password"]) ? 'error' : '' ?>" >
                <i class="fas fa-eye toggle-password" onclick="togglePasswordVisibility('password', this)"></i>
            </div>
            <?php if (isset($errors["password"])): ?><span class="error-text"><i class="fas fa-exclamation-circle"></i> <?= $errors["password"] ?></span><?php endif; ?>
        </div>

        <div class="input-group">
            <label for="confirm_password">Confirm Password</label>
            <div style="position: relative;">
                <input type="password" id="confirm_password" name="confirm_password" class="input-field <?= isset($errors["confirm_password"]) ? 'error' : '' ?>" >
                <i class="fas fa-eye toggle-password" onclick="togglePasswordVisibility('confirm_password', this)"></i>
            </div>
            <?php if (isset($errors["confirm_password"])): ?><span class="error-text"><i class="fas fa-exclamation-circle"></i> <?= $errors["confirm_password"] ?></span><?php endif; ?>
        </div>

        <div class="section-title"><i class="fas fa-user"></i> Personal Details</div>

        <div class="input-group">
            <label for="firstname">First name</label>
            <input type="text" id="firstname" name="firstname" class="input-field <?= isset($errors["firstname"]) ? 'error' : '' ?>" 
                    value="<?= htmlspecialchars($firstname) ?>" >
            <?php if (isset($errors["firstname"])): ?><span class="error-text"><i class="fas fa-exclamation-circle"></i> <?= $errors["firstname"] ?></span><?php endif; ?>
        </div>

        <div class="input-group">
            <label for="lastname">Last name</label>
            <input type="text" id="lastname" name="lastname" class="input-field <?= isset($errors["lastname"]) ? 'error' : '' ?>" 
                    value="<?= htmlspecialchars($lastname) ?>" >
            <?php if (isset($errors["lastname"])): ?><span class="error-text"><i class="fas fa-exclamation-circle"></i> <?= $errors["lastname"] ?></span><?php endif; ?>
        </div>
        
        <div class="input-group">
            <label for="course">Course</label>
            <input type="text" id="course" name="course" class="input-field <?= isset($errors["course"]) ? 'error' : '' ?>" 
                    value="<?= htmlspecialchars($course) ?>" >
            <?php if (isset($errors["course"])): ?><span class="error-text"><i class="fas fa-exclamation-circle"></i> <?= $errors["course"] ?></span><?php endif; ?>
        </div>

        <div class="input-group">
            <label for="contact_number">Contact Number</label>
            <input type="text" id="contact_number" name="contact_number" 
                    value="<?= htmlspecialchars($contact_number) ?>" class="input-field <?= isset($errors["contact_number"]) ? 'error' : '' ?>" 
                    placeholder="e.g., +639171234567 or 09171234567">
            <?php if (isset($errors["contact_number"])): ?><span class="error-text"><i class="fas fa-exclamation-circle"></i> <?= $errors["contact_number"] ?></span><?php endif; ?>
        </div>
        
        <button type="submit" class="btn-continue">
            <i class="fas fa-check-circle"></i> Create my new account
        </button>
    </form>
    
    <div class="bottom-link-container">
        Already have an account? <a href="login.php" class="link-text">Login here</a>
    </div>

    <div class="back-to-home-link">
        <a href="index.php">
            <i class="fas fa-arrow-left"></i> Back to Home
        </a>
    </div>
    </div>

<script>
    // === PASSWORD TOGGLE ===
    // This function handles the toggle for multiple password fields
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
</script>

</body>
</html>