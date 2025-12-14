<?php
require_once "Database.php";
require_once "Transaction.php"; 



class Student extends Database {

    public function isStudentIdExist($student_id) {
        $sql = "SELECT COUNT(*) AS total 
                FROM users 
                WHERE student_id = :student_id";
        $query = $this->connect()->prepare($sql);
        $query->bindParam(":student_id", $student_id);

        if ($query->execute()) {
            $result = $query->fetch(PDO::FETCH_ASSOC); 
            return $result["total"] > 0;
        }
        return false;
    }

    public function isEmailExist($email) {
        $sql = "SELECT COUNT(*) AS total 
                FROM users 
                WHERE email = :email";
        $query = $this->connect()->prepare($sql);
        $query->bindParam(":email", $email);

        if ($query->execute()) {
            $result = $query->fetch(PDO::FETCH_ASSOC);
            return $result["total"] > 0;
        }
        return false;
    }

    public function registerStudent($student_id, $firstname, $lastname, $course, $contact_number, $email, $password, $token) {
        $hashed_password = password_hash($password, PASSWORD_DEFAULT);

        $sql = "INSERT INTO users (student_id, firstname, lastname, course, contact_number, email, password, verification_token, is_verified, role)
                VALUES (:student_id, :firstname, :lastname, :course, :contact_number, :email, :password, :token, 0, 'student')";

        $query = $this->connect()->prepare($sql);
        $query->bindParam(":student_id", $student_id);
        $query->bindParam(":firstname", $firstname);
        $query->bindParam(":lastname", $lastname);
        $query->bindParam(":course", $course);
        $query->bindParam(":contact_number", $contact_number);
        $query->bindParam(":email", $email);
        $query->bindParam(":password", $hashed_password);
        $query->bindParam(":token", $token); 

        return $query->execute();
    }
    
    public function verifyStudentAccountByCode($email, $code) {
        $conn = $this->connect();
        $code = trim($code);
        
        $sql = "UPDATE users 
                SET is_verified = 1, verification_token = NULL 
                WHERE email = :email 
                AND verification_token = :code 
                AND is_verified = 0 
                LIMIT 1";

        try {
            $stmt = $conn->prepare($sql);
            $stmt->bindParam(':email', $email);
            $stmt->bindParam(':code', $code);
            $stmt->execute();

            return $stmt->rowCount() === 1;

        } catch (PDOException $e) {
            error_log("Database error during account verification: " . $e->getMessage());
            return false;
        }
    }


    public function getContactDetails($user_id) {
        $conn = $this->connect();
        $stmt = $conn->prepare("SELECT contact_number FROM users WHERE id = :id");
        $stmt->execute([':id' => $user_id]);
        return $stmt->fetch(PDO::FETCH_ASSOC);
    }

    public function updateStudentProfile($user_id, $firstname, $lastname, $course, $contact_number, $email) {
        $conn = $this->connect();
        $sql = "UPDATE users SET 
                    firstname = :firstname, 
                    lastname = :lastname, 
                    course = :course, 
                    contact_number = :contact_number, 
                    email = :email 
                WHERE id = :id";
                
        $stmt = $conn->prepare($sql);
        
        $stmt->bindParam(":firstname", $firstname);
        $stmt->bindParam(":lastname", $lastname);
        $stmt->bindParam(":course", $course);
        $stmt->bindParam(":contact_number", $contact_number);
        $stmt->bindParam(":email", $email);
        $stmt->bindParam(":id", $user_id);
        
        return $stmt->execute();
    }
    
    public function getUserById(int $userId): ?array {
    try {
        $conn = $this->connect();
        $sql = "SELECT id, email, firstname, lastname FROM users WHERE id = :id LIMIT 1";
        $stmt = $conn->prepare($sql);
        $stmt->bindParam(':id', $userId, PDO::PARAM_INT);
        $stmt->execute();
        
        return $stmt->fetch(PDO::FETCH_ASSOC);
        
    } catch (\PDOException $e) {
        error_log("Database error fetching user ID {$userId}: " . $e->getMessage());
        return null;
    }
}
}