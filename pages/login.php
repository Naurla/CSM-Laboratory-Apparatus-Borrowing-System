<?php
session_start();

// --- Flash Message Handling ---
$flash_message = null;
if (isset($_SESSION['flash_message'])) {
    $flash_message = $_SESSION['flash_message'];
    unset($_SESSION['flash_message']); // Display message once and then destroy it
}
// ------------------------------

// Check if user is already logged in
if (isset($_SESSION['user'])) {
    if ($_SESSION['user']['role'] == 'student') {
        header("Location: ../student/student_dashboard.php");
        exit;
    } else {
        header("Location: ../staff/staff_dashboard.php");
        exit;
    }
}

require_once "../classes/Login.php"; 
$login = new Login();

// Variables to hold specific errors and input value
$error_email = ""; 
$error_password = "";
$general_error = ""; 
$entered_email = ""; // To retain the email input

if ($_SERVER["REQUEST_METHOD"] == "POST") {
    $login->email = filter_input(INPUT_POST, 'email', FILTER_SANITIZE_EMAIL);
    $login->password = filter_input(INPUT_POST, 'password', FILTER_SANITIZE_STRING);

    // Retain the email value regardless of login success/failure
    $entered_email = htmlspecialchars($login->email);

    if ($login->login()) {
        $_SESSION["user"] = $login->getUser();

        if ($_SESSION["user"]["role"] == "student") {
            header("Location: ../student/student_dashboard.php");
        } else {
            header("Location: ../staff/staff_dashboard.php");
        }
        exit;
    } else {
        // Capture the specific error reason
        $reason = $login->getErrorReason();

        if ($reason === 'user_not_found') {
            $error_email = "Account not found. Please check your email address.";
            $general_error = "❌ Login failed.";
        } elseif ($reason === 'incorrect_password') {
            $error_password = "Incorrect password. Please try again.";
            $general_error = "❌ Login failed.";
        } elseif ($reason === 'unverified_account') { // <<< ADDED HANDLER
            $general_error = "❌ Account not verified. Please check your email for the verification link.";
        } else {
            $general_error = "An unknown error occurred. Please try again.";
        }
    }
} else {
    $entered_email = "";
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>CSM LABORATORY BORROWING APPARATUS SYSTEM - Login</title>
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0-beta3/css/all.min.css" rel="stylesheet">
    <style>
        /* CSS VARIABLES from index.php */
        :root {
            --primary-color: #A40404; /* Dark Red / Maroon (WMSU-inspired) */
            --secondary-color: #f4b400; /* Gold/Yellow Accent */
            --text-dark: #2c3e50;
            --text-light: #ecf0f1;
            --background-light: #f8f9fa;
        }

        body {
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif; /* Consistent font */
            color: var(--text-dark);
            margin: 0;
            padding: 0;
            display: flex;
            justify-content: center;
            align-items: center;
            min-height: 100vh;
            
            /* Consistent background image and overlay from index.php */
            background: 
                linear-gradient(rgba(0, 0, 0, 0.4), rgba(0, 0, 0, 0.4)),
                url("../uploads/Western_Mindanao_State_University_College_of_Teacher_Education_(Normal_Road,_Baliwasan,_Zamboanga_City;_10-06-2023).jpg") 
                no-repeat center center fixed; 
            background-size: cover;
        }
        
        .login-card {
            background: rgba(255, 255, 255, 0.95); /* Slightly transparent white card */
            padding: 50px; /* Increased padding */
            border-radius: 12px; /* Consistent rounded corners */
            box-shadow: 0 10px 40px rgba(0, 0, 0, 0.4); /* Stronger, modern shadow */
            width: 100%;
            max-width: 420px; /* Slightly narrower card for login */
            text-align: center;
            z-index: 10; 
            
            /* Add an initial subtle fade-in effect */
            animation: fadeIn 0.8s ease-out;
        }

        @keyframes fadeIn {
            from { opacity: 0; transform: translateY(20px); }
            to { opacity: 1; transform: translateY(0); }
        }

        .logo-container img {
            max-width: 100px; /* Adjusted logo size */
            height: auto;
            margin-bottom: 5px;
        }
        
        .digital-education {
            font-size: 1.1rem; 
            color: var(--primary-color);
            margin-bottom: 30px; 
            font-weight: 700;
            letter-spacing: 1.5px;
            text-transform: uppercase;
        }
        
        /* Form Inputs */
        .input-group {
            margin-bottom: 25px; /* Increased spacing */
            text-align: left;
        }
        .input-group label {
            display: block;
            font-size: 0.95rem; 
            color: var(--text-dark); 
            margin-bottom: 8px; 
            font-weight: 600;
        }
        
        .input-field-wrapper {
            position: relative;
        }
        
        .input-field {
            width: 100%;
            padding: 12px 15px; /* Better padding */
            height: 48px; /* Standardized height */
            border: 1px solid #ddd;
            border-radius: 6px; /* Slightly more rounded inputs */
            box-sizing: border-box; 
            font-size: 1rem; 
            transition: border-color 0.3s, box-shadow 0.3s;
        }

        .input-field:focus {
            border-color: var(--secondary-color);
            box-shadow: 0 0 0 3px rgba(244, 180, 0, 0.2);
            outline: none;
        }
        
        .input-field-wrapper .input-field {
            padding-right: 50px; 
        }

        .input-field.error-border {
            border-color: var(--primary-color) !important;
        }
        
        .toggle-password {
            position: absolute;
            top: 50%;
            right: 15px; /* Adjusted position */
            transform: translateY(-50%);
            cursor: pointer;
            color: #888;
            font-size: 1.1rem; 
            z-index: 10;
            transition: color 0.2s;
        }
        .toggle-password:hover {
            color: var(--primary-color);
        }

        /* Primary Button - Matches index.php CTA */
        .btn-continue {
            width: 100%;
            padding: 15px; 
            background-color: var(--primary-color);
            border: none;
            border-radius: 50px; /* Pill shape */
            color: white;
            font-weight: 700;
            font-size: 1.1rem; 
            cursor: pointer;
            transition: background-color 0.3s, transform 0.2s, box-shadow 0.3s;
            margin-top: 10px;
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

        /* Error/Message Styling */
        .general-error-message, .alert {
            padding: 15px;
            border-radius: 6px;
            margin-bottom: 25px;
            font-weight: 600;
            text-align: left;
        }
        
        .general-error-message {
            background-color: #f8d7da; /* Light Red */
            color: #721c24; /* Dark Red Text */
            border: 1px solid #f5c6cb;
        }

        .specific-error {
            color: var(--primary-color);
            font-size: 0.85rem;
            margin-top: 5px;
            font-weight: 600;
        }

        /* Flash Message Styles */
        .alert-success {
            background-color: #d4edda; 
            color: #155724; 
            border: 1px solid #c3e6cb;
        }
        .alert-warning {
            background-color: #fff3cd; 
            color: #856404; 
            border: 1px solid #ffeeba;
        }
        .alert-error {
            background-color: #f8d7da;
            color: #721c24;
            border: 1px solid #f5c6cb;
        }
        
        /* Bottom Links */
        .bottom-links-container {
            display: flex;
            justify-content: space-between; 
            align-items: center;
            margin-top: 25px;
            font-size: 0.95rem; 
            width: 100%; 
        }
        .bottom-links-container a {
            color: var(--primary-color);
            text-decoration: none;
            font-weight: 600;
            transition: color 0.2s, text-decoration 0.2s;
        }
        .bottom-links-container a:hover {
            text-decoration: underline;
        }

        .back-to-home-link {
            text-align: center;
            margin-top: 20px;
        }
        .back-to-home-link a {
            color: var(--text-dark); /* Changed to dark text for contrast with red links */
            text-decoration: none;
            font-size: 0.9rem;
            font-weight: 500;
            transition: color 0.2s;
        }
        .back-to-home-link a:hover {
            color: var(--primary-color);
            text-decoration: underline;
        }

        @media (max-width: 500px) {
            .login-card {
                margin: 20px;
                padding: 30px;
            }
            .bottom-links-container {
                flex-direction: column;
                gap: 10px;
            }
        }
    </style>
</head>
<body>

<div class="login-card">
    <div class="logo-container">
        <img src="../wmsu_logo/wmsu.png" alt="Western Mindanao State University Logo"> 
    </div>
    
    <div class="digital-education">
        CSM LABORATORY BORROWING APPARATUS 
    </div>

    <form method="POST" action="">
        <?php if ($flash_message): ?>
            <div class="alert alert-<?= htmlspecialchars($flash_message['type']) ?>" role="alert">
                <?= htmlspecialchars($flash_message['content']) ?>
            </div>
        <?php endif; ?>
        <?php if (!empty($general_error)): ?>
            <p class="general-error-message"><?= $general_error ?></p>
        <?php endif; ?>
        
        <div class="input-group">
            <label for="email">Email Address</label>
            <input type="email" id="email" name="email" class="input-field <?= !empty($error_email) ? 'error-border' : '' ?>" placeholder="e.g., your.email@gmail.com" required 
                         value="<?= $entered_email ?>"> 
            <?php if (!empty($error_email)): ?><p class="specific-error"><i class="fas fa-exclamation-circle"></i> <?= $error_email ?></p><?php endif; ?>
        </div>

        <div class="input-group">
            <label for="password">Password</label>
            <div class="input-field-wrapper">
                <input type="password" id="password" name="password" class="input-field <?= !empty($error_password) ? 'error-border' : '' ?>" placeholder="Enter your password" required>
                <i class="fas fa-eye toggle-password" id="togglePassword"></i>
            </div>
            <?php if (!empty($error_password)): ?><p class="specific-error"><i class="fas fa-exclamation-circle"></i> <?= $error_password ?></p><?php endif; ?>
        </div>

        <button type="submit" name="login" class="btn-continue">
            <i class="fas fa-sign-in-alt"></i> Login to Dashboard
        </button>
    </form>

    <div class="bottom-links-container">
        <a href="forgot_password.php">Forgot Password?</a>
        <a href="signup.php"><i class="fas fa-user-plus"></i> Create an Account</a>
    </div>

    <div class="back-to-home-link">
        <a href="index.php">
            <i class="fas fa-arrow-left"></i> Back to Home
        </a>
    </div>
</div>

<script>
    // Toggle password script remains the same
    document.getElementById('togglePassword').addEventListener('click', function() {
        const passwordInput = document.getElementById('password');
        const icon = this;

        // Toggle visibility
        const type = passwordInput.getAttribute('type') === 'password' ? 'text' : 'password';
        passwordInput.setAttribute('type', type);

        // Toggle icon
        icon.classList.toggle('fa-eye-slash');
        icon.classList.toggle('fa-eye');
    });
</script>

</body>
</html>