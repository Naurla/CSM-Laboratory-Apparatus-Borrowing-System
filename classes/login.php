<?php
require_once "Database.php";

class Login extends Database {
    public $email;
    public $password;
    private $user;
    private $error_reason; 


    public function updateUserPassword($userId, $newHashedPassword) {
        $conn = $this->connect();
        $stmt = $conn->prepare("UPDATE users SET password = :password WHERE id = :id");
        return $stmt->execute([
            ":password" => $newHashedPassword,
            ":id" => $userId
        ]);
    }

    public function login() {
        $conn = $this->connect();
        $stmt = $conn->prepare("SELECT * FROM users WHERE email = :email LIMIT 1");
        $stmt->execute([":email" => $this->email]);
        $user = $stmt->fetch();
        
        if (!$user) {
            $this->error_reason = 'user_not_found';
            return false;
        }

        
        if ($user['is_verified'] == 0) {
            $this->error_reason = 'unverified_account'; 
            return false; 
        }
      

        $stored_password = $user['password'];
        $success = false;

        
        if (password_verify($this->password, $stored_password)) {
            $success = true;
        } 
   
        else if (strpos($stored_password, '$') !== 0 && $this->password === $stored_password) {
            
            $success = true;

           
            $new_hash = password_hash($this->password, PASSWORD_DEFAULT);
            $this->updateUserPassword($user['id'], $new_hash);
            
            error_log("Password migrated for user ID: " . $user['id']);

        } else {
           
            $this->error_reason = 'incorrect_password'; 
            $success = false;
        }

        

        if ($success) {
            
            $this->user = [
                "id" => $user['id'],
                "firstname" => $user['firstname'],
                "lastname" => $user['lastname'],
                "email" => $user['email'],
                "role" => $user['role'],
                "student_id" => $user['student_id'] ?? null,
                "course" => $user['course'] ?? null
            ];
            return true;
        }

        return false;
    }

    public function getUser() {
        return $this->user;
    }
    
    public function getErrorReason() {
        return $this->error_reason;
    }

   
    public function forgotPasswordAndGetLink($email) {
        $conn = $this->connect();
        
      
        $stmt = $conn->prepare("SELECT id FROM users WHERE email = :email LIMIT 1");
        $stmt->execute([":email" => $email]);
        $user = $stmt->fetch();
        
        if (!$user) {
            return false; 
        }

        $userId = $user['id'];
        
        
        $code = strval(rand(100000, 999999)); 
        
       
        $conn->prepare("DELETE FROM password_resets WHERE user_id = :user_id")->execute([':user_id' => $userId]);

      
        $stmt_insert = $conn->prepare("
            INSERT INTO password_resets (user_id, token, expires_at) 
            VALUES (:user_id, :token, DATE_ADD(NOW(), INTERVAL 10 MINUTE))
        ");
        
        $success = $stmt_insert->execute([
            ":user_id" => $userId, 
            ":token" => $code 
        ]);

        if ($success) {
           
            return $code; 
        }

        return false; 
    }

    
    public function validateResetToken($email, $code) {
        $conn = $this->connect();
        
        
        $sql = "SELECT r.user_id FROM password_resets r
                JOIN users u ON r.user_id = u.id
                WHERE u.email = :email 
                AND r.token = :code 
                AND r.expires_at > NOW()";
                
        $stmt = $conn->prepare($sql);
        $stmt->execute([":email" => $email, ":code" => $code]); 
        $result = $stmt->fetch();

        return $result ? $result['user_id'] : false;
    }

    
    public function deleteResetToken($token) {
        $conn = $this->connect();
        $stmt = $conn->prepare("DELETE FROM password_resets WHERE token = :token");
        return $stmt->execute([":token" => $token]);
    }
}