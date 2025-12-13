<?php
// pages/forgot_password.php
session_start();

// === Dependencies ===
// Assuming 'vendor' is in the project root (../)
require_once '../vendor/autoload.php'; 
// The path below assumes your 'Login.php' is in classes/ relative to the parent directory.
require_once '../classes/Login.php'; 
require_once '../classes/Mailer.php'; 
// ====================

$error = '';
// $security_info is kept for logic consistency but will no longer be displayed directly.
$security_info = ''; 
$email = $_POST['email'] ?? ''; // Variable to store the entered email

if ($_SERVER["REQUEST_METHOD"] == "POST") {
    // Sanitize and validate email input
    $email = filter_input(INPUT_POST, 'email', FILTER_SANITIZE_EMAIL);

    if (empty($email) || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $error = "Please enter a valid email address.";
    } else {
        $login_handler = new Login();
        
        // Result is the 6-digit code on success
        // This must be handled securely in the Login class (new code, updated timestamp).
        $code = $login_handler->forgotPasswordAndGetLink($email);

        // Check if a code was successfully generated (meaning the email exists)
        if (is_string($code) && strlen($code) === 6) {
            
            $mailer = new Mailer(); 
            $email_sent = $mailer->sendResetCodeEmail($email, $code);

            if ($email_sent) {
                // === START CHANGE: Set flash message for success display on next page ===
                $_SESSION['flash_message'] = [
                    'type' => 'info',
                    'content' => "✅ A 6-digit verification code has been successfully sent to your email. Please check your inbox and spam folder."
                ];
                // === END CHANGE ===
                
                // SUCCESS: Redirect the user immediately to the reset page
                header("Location: reset_password.php?email=" . urlencode($email));
                exit;
            } else {
                // Should only happen if the mail server or Mailer class fails
                $error = "Failed to send the reset code email. Please try again. Mailer error: " . $mailer->getError(); 
            }
        } else {
            // SECURITY MESSAGE: This message is shown even if the email doesn't exist 
            // to prevent potential hackers from enumerating valid emails.
            $security_info = "If an account with that email exists, a password reset code has been sent. Please check your inbox and spam folder.";
            
            // === START CHANGE: Set generic flash message and redirect ===
            $_SESSION['flash_message'] = [
                'type' => 'security', // Using 'security' type for custom styling if needed
                'content' => $security_info
            ];
            header("Location: reset_password.php?email=" . urlencode($email));
            exit;
            // === END CHANGE ===
        }
    }
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Forgot Password - CSM Borrowing</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0-beta3/css/all.min.css">
    
    <style>
        /* --- THEME MATCHING (Consistent Theme) --- */
        
        :root {
            --primary-color: #A40404; /* Dark Red / Maroon (WMSU-inspired) */
            --secondary-color: #f4b400; /* Gold/Yellow Accent */
            --text-dark: #2c3e50;
            --text-light: #ecf0f1;
        }

        /* Global Styles */
        body {
            /* Consistent background image and overlay */
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

        /* Card Container - Consistent with login.php */
        .card {
            background: rgba(255, 255, 255, 0.98);
            padding: 50px;
            border-radius: 12px; 
            box-shadow: 0 10px 40px rgba(0, 0, 0, 0.4); 
            width: 100%;
            max-width: 420px; /* Consistent card width */
            text-align: center;
            z-index: 10;
            animation: fadeIn 0.8s ease-out;
        }

        /* Logo and Title */
        .logo {
            width: 100px; /* Consistent logo size */
            margin: 0 auto 5px auto;
        }
        .app-title {
            color: var(--primary-color); 
            font-size: 1.1rem;
            font-weight: 700;
            line-height: 1.3;
            margin-bottom: 10px;
            text-transform: uppercase;
            letter-spacing: 1.5px;
        }
        .main-heading {
            font-size: 1.75rem;
            margin-bottom: 15px;
            color: var(--text-dark);
            font-weight: 600;
        }
        .instruction-text {
            margin-bottom: 30px;
            font-size: 0.95rem; 
            color: #666; 
            line-height: 1.5;
        }

        /* Form Elements - Consistent with login.php */
        .input-group {
            margin-bottom: 25px;
            text-align: left;
        }
        label {
            display: block;
            font-weight: 600; 
            margin-bottom: 8px; 
            color: var(--text-dark);
            font-size: 0.95em;
        }
        input[type="email"] {
            width: 100%;
            padding: 12px 15px; 
            height: 48px; /* Consistent height */
            border: 1px solid #ddd; 
            border-radius: 6px; 
            box-sizing: border-box;
            font-size: 1rem;
            transition: border-color 0.2s, box-shadow 0.2s;
        }
        input[type="email"]:focus {
            border-color: var(--secondary-color);
            outline: none;
            box-shadow: 0 0 0 3px rgba(244, 180, 0, 0.2);
        }
        input::placeholder {
            color: #aaa;
        }

        /* Button - Consistent with login.php CTA */
        .btn-continue {
            width: 100%;
            padding: 15px;
            background-color: var(--primary-color); 
            color: white;
            border: none;
            border-radius: 50px; /* Pill shape */
            cursor: pointer;
            font-size: 1.1rem;
            font-weight: 700;
            text-transform: capitalize; 
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

        /* Alerts - Replicating styles from reset_password.php */
        .alert {
            padding: 15px;
            border-radius: 6px;
            margin-bottom: 25px;
            text-align: left;
            font-size: 0.95em;
            border: 1px solid transparent;
            font-weight: 600;
        }
        .alert-error {
            color: #721c24;
            background-color: #f8d7da;
            border: 1px solid #f5c6cb;
        }
        .alert-security { /* Used for the generic "check your email" message */
            color: #004085;
            background-color: #cce5ff;
            border: 1px solid #b8daff;
        }

        /* Back Link - Consistent with login.php */
        .back-link {
            display: inline-block; 
            margin-top: 25px;
            color: var(--text-dark); /* Use dark text for "Back to Login" */
            text-decoration: none;
            font-size: 0.9em; 
            font-weight: 500;
            transition: color 0.2s;
        }
        .back-link:hover {
            color: var(--primary-color);
            text-decoration: underline;
        }
        
        @media (max-width: 480px) {
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
        
        <div class="app-title">
            CSM LABORATORY BORROWING APPARATUS
        </div>

        <h2 class="main-heading">Forgot Your Password?</h2>
        <p class="instruction-text">
            Enter the email address associated with your account, and we will send a 6-digit verification code to reset your password.
        </p>

        <?php if ($error): ?>
            <div class="alert alert-error"><i class="fas fa-exclamation-triangle"></i> <?php echo htmlspecialchars($error); ?></div>
        <?php endif; ?>

        <form action="forgot_password.php" method="POST">
            <div class="input-group">
                <label for="email">Email Address</label>
                <input type="email" id="email" name="email" required 
                        placeholder="e.g. student@gmail.com"
                        value="<?php echo htmlspecialchars($email); ?>">
            </div>
            
            <button type="submit" class="btn-continue">
                <i class="fas fa-arrow-right"></i> Request Password Reset Code
            </button>
        </form>
        
        <a href="login.php" class="back-link">
            <i class="fas fa-arrow-left"></i> Back to Login
        </a>
    </div>
</body>
</html>