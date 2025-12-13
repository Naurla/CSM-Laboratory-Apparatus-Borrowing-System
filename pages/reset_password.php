<?php
// File: pages/reset_password.php 
session_start();
// Define the token expiry time for UX display
define('TOKEN_EXPIRY_MINUTES', 10); 

require_once '../classes/Login.php'; 

// === START: FLASH MESSAGE HANDLING ADDITION ===
// Retrieves flash message set by forgot_password.php or resend_reset_code.php
$flash_message = null;
if (isset($_SESSION['flash_message'])) {
    $flash_message = $_SESSION['flash_message'];
    unset($_SESSION['flash_message']);
}
// === END: FLASH MESSAGE HANDLING ADDITION ===

$message = '';
$error = '';

$email_from_get = $_GET['email'] ?? ''; 
$code_from_get = $_GET['code'] ?? ''; 

$is_code_validated = false;
$user_id = 0;
$login_handler = new Login();

if ($_SERVER["REQUEST_METHOD"] == "POST") {
    $email_from_post = filter_input(INPUT_POST, 'email', FILTER_SANITIZE_EMAIL);
    if (!empty($email_from_post)) {
        $email_from_get = $email_from_post;
    }
}


if (empty($email_from_get)) {
    header("Location: forgot_password.php");
    exit;
}

// Case A: Code is submitted via form (POST)
if ($_SERVER["REQUEST_METHOD"] == "POST" && isset($_POST['action']) && $_POST['action'] === 'validate_code') {
    // Note: JS handles filtering, but PHP must sanitize and validate final submission
    $code_to_validate = filter_input(INPUT_POST, 'code', FILTER_SANITIZE_NUMBER_INT);
    $code_from_get = $code_to_validate; 
    
    if (empty($code_to_validate) || strlen($code_to_validate) !== 6) {
        $error = "Please enter the 6-digit code.";
    } elseif (empty($error)) {
        $user_id = $login_handler->validateResetToken($email_from_get, $code_to_validate);
        
        if ($user_id) {
            header("Location: reset_password.php?email=" . urlencode($email_from_get) . "&code=" . urlencode($code_to_validate));
            exit;
        } else {
            $error = "The reset code is invalid or has expired.";
        }
    }
} 
// Case B: Code is already in the URL (GET from successful validation)
elseif (!empty($code_from_get) && empty($error)) {
    $user_id = $login_handler->validateResetToken($email_from_get, $code_from_get);
    if ($user_id) {
        $is_code_validated = true; 
    } else {
        $error = "The reset code is invalid or has expired. Please request a new code.";
    }
}

// STAGE 2: Handle Password Submission (POST on validated code)
if ($_SERVER["REQUEST_METHOD"] == "POST" && isset($_POST['action']) && $_POST['action'] === 'reset_password' && !empty($code_from_get)) {
    $user_id = $login_handler->validateResetToken($email_from_get, $code_from_get);
    
    if (!$user_id) {
        $error = "The reset code is no longer valid. Please start over.";
        $is_code_validated = false;
    } else {
        $new_password = $_POST['password'];
        $confirm_password = $_POST['confirm_password'];

        if ($new_password !== $confirm_password) {
            $error = "Passwords do not match.";
        } elseif (strlen($new_password) < 8) {
            $error = "Password must be at least 8 characters long.";
        } else {
            $new_hash = password_hash($new_password, PASSWORD_DEFAULT);
            
            if ($login_handler->updateUserPassword($user_id, $new_hash)) {
                $login_handler->deleteResetToken($code_from_get);
                $message = "Your password has been successfully reset. You can now log in.";
                $is_code_validated = false; 
            } else {
                $error = "A database error occurred while updating your password.";
            }
        }
        if ($error) {
            $is_code_validated = true; 
        }
    }
}

// === END PHP LOGIC ===
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Reset Password - CSM Laboratory</title>
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

        .card {
            background: rgba(255, 255, 255, 0.98);
            border-radius: 12px; /* Consistent rounded corners */
            box-shadow: 0 10px 40px rgba(0, 0, 0, 0.4); /* Stronger, modern shadow */
            padding: 50px; /* Consistent padding */
            width: 100%;
            max-width: 420px; /* Consistent card width with login.php */
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
            font-size: 1.1rem; 
            font-weight: 700;
            line-height: 1.3;
            margin-bottom: 10px;
            text-transform: uppercase;
            letter-spacing: 1.5px;
        }
        h2 {
            font-size: 1.75rem; 
            margin-bottom: 15px;
            color: var(--text-dark);
            font-weight: 600;
        }
        .instruction-text {
            margin-bottom: 25px;
            font-size: 0.95rem;
            color: #555;
            line-height: 1.5;
        }

        /* Alerts - Consistent color palette */
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
            border-color: #f5c6cb;
        }
        .alert-info {
            color: #0c5460;
            background-color: #d1ecf1;
            border-color: #bee5eb;
            text-align: center; /* Center info alert content */
        }
        .alert-success {
            color: #155724;
            background-color: #d4edda;
            border-color: #c3e6cb;
        }
        .alert-warning {
             color: #856404;
             background-color: #fff3cd;
             border-color: #ffeeba;
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
            font-size: 0.95rem;
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
            border-color: var(--secondary-color);
            outline: none;
            box-shadow: 0 0 0 3px rgba(244, 180, 0, 0.2);
        }
        
        /* Code Input Specifics (Stage 1) - Preserving the unique look */
        .input-field[name="code"] {
            text-align: center;
            font-size: 1.5rem;
            letter-spacing: 5px; 
            padding: 15px 10px; 
            height: 60px; /* Slightly taller for code input */
            font-weight: bold;
        }
        /* New Password Fields (Stage 2) */
        .input-field[type="password"] {
            padding-right: 45px; /* Space for toggle icon */
        }


        /* Primary Button - Matches login.php CTA */
        .btn-continue {
            background-color: var(--primary-color); 
            color: #ffffff;
            padding: 15px; /* Consistent button padding */
            border: none;
            border-radius: 50px; /* Pill shape */
            font-size: 1.1rem;
            font-weight: 700;
            cursor: pointer;
            width: 100%;
            transition: background-color 0.3s ease, transform 0.2s, box-shadow 0.3s;
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
        .link-text {
            color: var(--primary-color); 
            text-decoration: none;
            font-size: 0.95rem;
            font-weight: 600;
            transition: text-decoration 0.2s;
        }
        .link-text:hover {
            text-decoration: underline;
        }
        
        /* Password Toggle Icon */
        .password-toggle {
            position: absolute;
            right: 15px;
            top: 50%; /* Center vertically with input field */
            transform: translateY(-50%);
            cursor: pointer;
            color: #666;
            font-size: 1.1rem;
            z-index: 10;
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
        <div class="app-title">CSM LABORATORY BORROWING APPARATUS</div>
        
        <h2>Reset Your Password</h2>
        
        <?php if ($error): ?>
            <div class="alert alert-error"><i class="fas fa-exclamation-triangle"></i> <?php echo htmlspecialchars($error); ?></div>
        <?php endif; ?>

        <?php if ($flash_message): // Display flash message set by forgot_password.php or resend_reset_code.php ?>
            <div class="alert alert-<?php echo htmlspecialchars($flash_message['type']); ?>">
                <i class="fas fa-info-circle"></i> <?php echo $flash_message['content']; ?>
            </div>
        <?php endif; ?>
        
        <?php if ($message): // Success Message Stage (after successful reset) ?>
            <div class="alert alert-success"><i class="fas fa-check-circle"></i> <?php echo htmlspecialchars($message); ?></div>
            <p style="margin-top: 25px;"><a href="login.php" class="link-text">Go to Login Page</a></p>
        <?php endif; ?>

        <?php if (!$message): // Show forms only if no success message is present ?>
            
            <?php if ($is_code_validated): // STAGE 2: New Password Entry ?>
                <p class="instruction-text">
                    Set your new password for **<?= htmlspecialchars($email_from_get) ?>**.
                </p>
                
                <form action="reset_password.php?email=<?php echo urlencode($email_from_get); ?>&code=<?php echo urlencode($code_from_get); ?>" method="POST">
                    <input type="hidden" name="action" value="reset_password">
                    
                    <div class="input-group">
                        <label for="password">New Password (Min 8 characters):</label>
                        <div style="position: relative;">
                            <input type="password" id="password" name="password" class="input-field" required autocomplete="new-password" placeholder="Enter new password">
                            <i class="fas fa-eye password-toggle" onclick="togglePasswordVisibility('password', this)"></i>
                        </div>
                    </div>
                    
                    <div class="input-group">
                        <label for="confirm_password">Confirm New Password:</label>
                        <div style="position: relative;">
                            <input type="password" id="confirm_password" name="confirm_password" class="input-field" required autocomplete="new-password" placeholder="Confirm new password">
                            <i class="fas fa-eye password-toggle" onclick="togglePasswordVisibility('confirm_password', this)"></i>
                        </div>
                    </div>
                    
                    <div style="margin-top: 30px;">
                        <button type="submit" class="btn-continue"><i class="fas fa-key"></i> Set New Password</button>
                    </div>
                    
                    <p style="margin-top: 20px; margin-bottom: 0; font-size: 0.95em;">
                            <a href="login.php" class="link-text">Cancel and return to Login</a>
                    </p>
                </form>
                
            <?php else: // STAGE 1: Code Entry ?>
                <p class="instruction-text">
                    Enter the 6-digit code sent to **<?= htmlspecialchars($email_from_get) ?>**.
                </p>
                
                <div class="alert alert-info">
                    This code is valid for only **<?= TOKEN_EXPIRY_MINUTES ?> minutes**.
                </div>

                <form action="reset_password.php" method="POST">
                    <input type="hidden" name="action" value="validate_code">
                    <input type="hidden" name="email" value="<?php echo htmlspecialchars($email_from_get); ?>">
                    
                    <div class="input-group">
                        <input type="text" id="code" name="code" class="input-field" maxlength="6" 
                                required placeholder="Enter 6-digit Code" autofocus
                                inputmode="numeric" pattern="[0-9]*" value="<?php echo htmlspecialchars($code_from_get); ?>">
                    </div>
                    
                    <div style="margin-top: 30px;">
                        <button type="submit" class="btn-continue"><i class="fas fa-arrow-right"></i> Verify Code</button>
                    </div>
                </form>
                
                <p style="margin-top: 25px; font-size: 0.95em; margin-bottom: 0;">
                    Didn't receive the code? 
                    <a href="resend_reset_code.php?email=<?php echo urlencode($email_from_get); ?>" class="link-text">Request a new code</a>
                    </p>
            <?php endif; ?>
        <?php endif; ?>
        
        <?php if (!($message || $is_code_validated)): // Only show "Back to Login" if we are in Code Entry and no success message ?>
            <p style="margin-top: 20px; margin-bottom: 0;">
                <a href="login.php" class="link-text">Back to Login</a>
            </p>
        <?php endif; ?>
    </div>

    <script>
        // === JAVASCRIPT FOR STRICTLY NUMERIC INPUT (Only on the code field) ===
        document.addEventListener('DOMContentLoaded', () => {
            const codeInput = document.getElementById('code');
            if (codeInput) {
                // Blocks non-numeric key presses
                codeInput.addEventListener('keydown', (e) => {
                    // Allow: backspace (8), delete (46), tab (9), escape (27), enter (13)
                    if ([8, 9, 13, 27, 46].indexOf(e.keyCode) !== -1 ||
                        // Allow: Ctrl/Cmd+A, Ctrl/Cmd+C, Ctrl/Cmd+X, Ctrl/Cmd+V
                        (e.ctrlKey === true || e.metaKey === true) && [65, 67, 88, 86].indexOf(e.keyCode) !== -1 || 
                        // Allow: home (36), end (35), left (37), right (39)
                        (e.keyCode >= 35 && e.keyCode <= 40)) {
                        return;
                    }
                    // Block letters (A-Z) and symbols on the main keyboard
                    if ((e.shiftKey || (e.keyCode < 48 || e.keyCode > 57)) && 
                        // Block symbols on the numeric keypad
                        (e.keyCode < 96 || e.keyCode > 105)) {
                        e.preventDefault();
                    }
                });

                // Blocks pasted content that is non-numeric
                codeInput.addEventListener('paste', (e) => {
                    const paste = (e.clipboardData || window.clipboardData).getData('text');
                    const filteredPaste = paste.replace(/[^0-9]/g, '');
                    
                    // If the paste contains non-numeric chars, prevent default and insert filtered
                    if (paste !== filteredPaste) {
                        e.preventDefault();
                        const currentVal = codeInput.value;
                        const selectionStart = codeInput.selectionStart;
                        const selectionEnd = codeInput.selectionEnd;

                        const newVal = currentVal.substring(0, selectionStart) + 
                                            filteredPaste.substring(0, 6 - currentVal.length + (selectionEnd - selectionStart)) + 
                                            currentVal.substring(selectionEnd);
                        
                        codeInput.value = newVal.substring(0, 6);
                    }
                });
                
                // Autofocus the code input on load
                // Only autofocus if the code hasn't been validated yet
                <?php if (!$is_code_validated): ?>
                    codeInput.focus();
                <?php endif; ?>
            }
        });

        // === PASSWORD TOGGLE ===
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