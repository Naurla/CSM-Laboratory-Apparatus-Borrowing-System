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
    <title>CSM LABORATORY BORROWING APPARATUS SYSTEM</title>
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0-beta3/css/all.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet"> 
    <style>
        body {
            font-family: Arial, sans-serif;
            margin: 0;
            padding: 0;
            display: flex;
            justify-content: center;
            align-items: center;
            min-height: 100vh;
            
            /* === START BACKGROUND IMAGE FIX === */
            background: 
                linear-gradient(rgba(0, 0, 0, 0.4), rgba(0, 0, 0, 0.4)),
                url("../uploads/Western_Mindanao_State_University_College_of_Teacher_Education_(Normal_Road,_Baliwasan,_Zamboanga_City;_10-06-2023).jpg") 
                no-repeat center center fixed; 
            background-size: cover;
            /* === END BACKGROUND IMAGE FIX === */
        }
        .login-card {
            background: rgba(255, 255, 255, 0.98); 
            padding: 45px; 
            border-radius: 8px;
            box-shadow: 0 8px 30px rgba(0, 0, 0, 0.4); 
            width: 100%;
            max-width: 500px; 
            text-align: center;
            z-index: 10; 
        }
        .logo-container img {
            max-width: 130px; 
            height: auto;
            margin-bottom: 10px;
        }
        .digital-education {
            font-size: 16px; 
            color: #A40404; /* CHANGED FROM #b8312d */
            margin-bottom: 30px; 
            font-weight: bold;
            letter-spacing: 1px;
        }
        .input-group {
            margin-bottom: 20px; 
            text-align: left;
        }
        .input-group label {
            display: block;
            font-size: 16px; 
            color: #333; 
            margin-bottom: 5px; 
            line-height: 1.2;
            font-weight: bold;
        }
        
        .input-field-wrapper {
            position: relative;
            width: 100%; 
            margin-top: -2px; 
        }
        
        .input-field {
            width: 100%;
            padding: 10px 12px; 
            height: 44px; 
            border: 1px solid #aaa;
            border-radius: 4px;
            box-sizing: border-box; 
            font-size: 17px; 
        }
        
        .input-field-wrapper .input-field {
             padding-right: 50px; 
        }

        .input-field.error-border {
            border-color: #A40404 !important; /* CHANGED FROM #b8312d */
        }
        
        .toggle-password {
            position: absolute;
            top: 50%;
            right: 12px; 
            transform: translateY(-50%);
            cursor: pointer;
            color: #888;
            font-size: 18px; 
            z-index: 10;
        }
        .toggle-password:hover {
            color: #A40404; /* CHANGED FROM #b8312d */
        }

        .btn-continue {
            width: 100%;
            padding: 14px; 
            background-color: #A40404; /* CHANGED FROM #b8312d */
            border: none;
            border-radius: 4px;
            color: white;
            font-weight: bold;
            font-size: 17px; 
            cursor: pointer;
            transition: background-color 0.3s;
            margin-top: 10px;
        }
        .btn-continue:hover {
            background-color: #820303; /* CHANGED FROM #a82e2a (approx darker shade) */
        }

        .bottom-links-container {
            display: flex;
            justify-content: space-between; 
            align-items: center;
            margin-top: 20px;
            font-size: 16px; 
            width: 100%; 
        }
        .bottom-links-container a {
            color: #A40404; /* CHANGED FROM #b8312d */
            text-decoration: none;
        }
        .bottom-links-container a:hover {
            text-decoration: underline;
        }

        .general-error-message {
            background-color: #f8d7da;
            color: #721c24;
            padding: 10px;
            border-radius: 4px;
            margin-bottom: 20px;
            font-weight: bold;
        }

        .specific-error {
            color: #A40404; /* CHANGED FROM #b8312d */
            font-size: 14px;
            margin-top: 5px;
            font-weight: bold;
        }

        /* Flash Message Styles */
        .alert {
            padding: 10px;
            border-radius: 4px;
            font-weight: bold;
            text-align: left;
            margin-bottom: 20px;
        }
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
        /* ADD STYLE FOR BACK TO HOME LINK */
        .back-to-home-link {
            text-align: center;
            margin-top: 15px;
        }
        .back-to-home-link a {
            color: #A40404; /* CHANGED FROM #b8312d */
            text-decoration: none;
            font-size: 0.95em;
            font-weight: bold;
        }
        .back-to-home-link a:hover {
            text-decoration: underline;
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
            <div class="alert alert-<?= htmlspecialchars($flash_message['type']) ?> fade show" role="alert">
                <?= htmlspecialchars($flash_message['content']) ?>
            </div>
        <?php endif; ?>
        <?php if (!empty($general_error)): ?>
            <p class="general-error-message"><?= $general_error ?></p>
        <?php endif; ?>
        
        <div class="input-group">
            <label for="email">Email Address</label>
            <input type="email" id="email" name="email" class="input-field <?= !empty($error_email) ? 'error-border' : '' ?>" placeholder="Enter your email" required 
                         value="<?= $entered_email ?>"> 
            <?php if (!empty($error_email)): ?><p class="specific-error"><?= $error_email ?></p><?php endif; ?>
        </div>

        <div class="input-group">
            <label for="password">Password</label>
            <div class="input-field-wrapper">
                <input type="password" id="password" name="password" class="input-field <?= !empty($error_password) ? 'error-border' : '' ?>" placeholder="Enter your password" required>
                <i class="fas fa-eye toggle-password" id="togglePassword"></i>
            </div>
            <?php if (!empty($error_password)): ?><p class="specific-error"><?= $error_password ?></p><?php endif; ?>
        </div>

        <button type="submit" name="login" class="btn-continue">Continue</button>
    </form>

    <div class="bottom-links-container">
        <a href="forgot_password.php">Forgot Password?</a>
        <a href="signup.php">Create an Account</a>
    </div>

    <div class="back-to-home-link">
        <a href="index.php">
            <i class="fas fa-home"></i> Back to Home
        </a>
    </div>
    </div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
<script>
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