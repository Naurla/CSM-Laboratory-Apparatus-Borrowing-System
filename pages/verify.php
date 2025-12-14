<?php
// pages/verify.php (REWRITTEN for 6-digit code entry)
session_start();
require_once '../classes/Student.php'; 

$error = '';
$message = '';
$email = $_GET['email'] ?? ''; 
$code = '';

// Handle flash message from signup.php
if (isset($_SESSION['flash_message'])) {
    $message = $_SESSION['flash_message']['content'];
    $alert_type = $_SESSION['flash_message']['type'];
    unset($_SESSION['flash_message']);
}

if ($_SERVER["REQUEST_METHOD"] == "POST") {
    $email = filter_input(INPUT_POST, 'email', FILTER_SANITIZE_EMAIL);
    $code = filter_input(INPUT_POST, 'code', FILTER_SANITIZE_NUMBER_INT);
    
    if (empty($email) || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $error = "Invalid email address.";
    } elseif (empty($code) || strlen($code) !== 6) {
        $error = "Please enter the 6-digit code.";
    } else {
        $student_db = new Student();
        
        // Use the new verification method from Student.php
        if ($student_db->verifyStudentAccountByCode($email, $code)) {
            // Success: redirect to login
            $_SESSION['flash_message'] = ['type' => 'success', 'content' => "✅ Success! Your account has been verified. You may now log in."];
            header("Location: login.php");
            exit;
        } else {
            $error = "❌ Invalid verification code or email. Please check your input.";
        }
    }
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Verify Account - CSM Borrowing</title>
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0-beta3/css/all.min.css" rel="stylesheet">
    <style>
        :root {
            --primary-color: #A40404; 
            --primary-color-dark: #820303;
            --secondary-color: #f4b400; 
            --text-dark: #2c3e50;
            --bg-light: #f5f6fa;
            --danger-color: #dc3545;
            --success-color: #28a745;
        }

        body { 
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif; 
            background-color: var(--bg-light); 
            display: flex; 
            justify-content: center; 
            align-items: center; 
            min-height: 100vh; 
            margin: 0; 
            color: var(--text-dark);
        }
        
        .card { 
            background-color: #fff; 
            padding: 40px; 
            border-radius: 12px; 
            box-shadow: 0 8px 25px rgba(0, 0, 0, 0.15); 
            width: 100%; 
            max-width: 450px; 
            text-align: center; 
        }
        
        .app-title { 
            color: var(--primary-color); 
            font-size: 1.5em; 
            font-weight: 800; 
            line-height: 1.3; 
            margin-bottom: 30px; 
            padding-bottom: 10px;
            border-bottom: 3px solid var(--secondary-color);
        }
        
        h2 { 
            font-size: 1.6em; 
            margin-bottom: 5px; 
            font-weight: 600;
        }
        
        .form-group { 
            margin-bottom: 25px; 
            text-align: center; 
        }
        
        input[type="email"], input[type="text"] { 
            width: 100%; 
            padding: 12px 15px; 
            border: 1px solid #ced4da; 
            border-radius: 8px; 
            box-sizing: border-box; 
            font-size: 1.05em;
            transition: border-color 0.2s, box-shadow 0.2s;
        }

        input[type="email"]:focus, input[type="text"]:focus {
            border-color: var(--secondary-color);
            box-shadow: 0 0 0 3px rgba(244, 180, 0, 0.2);
            outline: none;
        }
        
       
        .code-input { 
            text-align: center; 
            font-size: 1.5em; 
            letter-spacing: 0.5em; 
            font-weight: bold;
            padding: 15px 10px;
        }
        
        .btn-submit { 
            width: 100%; 
            padding: 12px; 
            background-color: var(--primary-color); 
            color: white; 
            border: none; 
            border-radius: 50px; 
            cursor: pointer; 
            font-size: 1.1em; 
            font-weight: 700;
            transition: background-color 0.3s, transform 0.2s; 
            box-shadow: 0 4px 8px rgba(0, 0, 0, 0.1);
        }
        .btn-submit:hover { 
            background-color: var(--primary-color-dark); 
            transform: translateY(-1px);
        }
        
        .alert {
            padding: 12px;
            border-radius: 8px;
            margin-bottom: 20px;
            font-weight: 600;
        }
        .alert-error { 
            color: #721c24; 
            background-color: #f8d7da; 
            border: 1px solid #f5c6cb;
        }
        .alert-success { 
            color: #155724; 
            background-color: #d4edda; 
            border: 1px solid #c3e6cb;
        }
        .alert-warning { 
            color: #856404; 
            background-color: #fff3cd; 
            border: 1px solid #ffeeba;
        }
        
        .link-text {
            color: var(--primary-color); 
            text-decoration: none;
            font-weight: 600;
            transition: color 0.2s;
        }
        .link-text:hover {
            color: var(--primary-color-dark);
            text-decoration: underline;
        }
    </style>
</head>
<body>
    <div class="card">
        <div class="app-title">
            <i class="fas fa-flask me-2"></i> CSM LABORATORY<br>
            ACCOUNT VERIFICATION
        </div>

        <h2>Enter Verification Code</h2>
        <p style="margin-bottom: 25px; font-size: 1em; color: #6c757d;">
            A 6-digit code was sent to **<?= htmlspecialchars($email) ?>**.
        </p>

        <?php if ($error): ?>
            <div class="alert alert-error"><i class="fas fa-exclamation-circle me-1"></i> <?= $error ?></div>
        <?php endif; ?>
        <?php if ($message): ?>
            <div class="alert alert-<?= htmlspecialchars($alert_type) ?>"><i class="fas fa-info-circle me-1"></i> <?= $message ?></div>
        <?php endif; ?>

        <form action="verify.php" method="POST">
            <div class="form-group">
                <label for="email" style="display: none;">Email (for reference):</label>
                <input type="email" id="email_display" name="email" value="<?= htmlspecialchars($email) ?>" readonly style="background-color: var(--label-bg); border: 1px solid #ccc; color: #555;">
            </div>
            
            <div class="form-group">
                <label for="code" style="display: block; font-weight: 600; margin-bottom: 10px;">6-Digit Code:</label>
                <input type="text" id="code" name="code" class="code-input" maxlength="6" placeholder="______">
            </div>
            
            <button type="submit" class="btn-submit">Verify Account</button>
        </form>

        <p style="margin-top: 20px; font-size: 0.95em;">
            <a href="forgot_password.php" class="link-text">Request a new code</a> (or check for typos in the email you signed up with)
        </p>
    </div>
</body>
</html>