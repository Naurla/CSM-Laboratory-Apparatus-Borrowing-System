<?php
require_once "Database.php";
require_once "Mailer.php"; // <--- CRITICAL FIX: ADDED MAILER INCLUDE

class Transaction extends Database
{

    // --- BCNF CORE STOCK/UNIT MANAGEMENT HELPERS (UPDATED FOR STOCK QUEUEING) ---

    // FIX 1: Allow $conn to be passed to use the transactional connection
    protected function countAvailableUnits($type_id, $conn = null) 
    {
        $used_conn = $conn ?? $this->connect();
        $stmt = $used_conn->prepare("
            SELECT COUNT(unit_id) 
            FROM apparatus_unit 
            WHERE type_id = :type_id AND current_status = 'available'
        ");
        $stmt->execute([':type_id' => $type_id]);
        return $stmt->fetchColumn();
    }

    protected function getUnitsForBorrow($type_id, $quantity_needed, $conn)
    {
        $stmt = $conn->prepare("
            SELECT unit_id 
            FROM apparatus_unit 
            WHERE type_id = :type_id AND current_status = 'available'
            LIMIT :quantity
            FOR UPDATE
        ");
        $stmt->bindParam(':type_id', $type_id); 
        $stmt->bindParam(':quantity', $quantity_needed); 
        $stmt->execute();
        return $stmt->fetchAll(PDO::FETCH_COLUMN); 
    }

    protected function updateUnitStatus(array $unit_ids, $new_status, $conn) 
    {
        if (empty($unit_ids)) return true;
        
        $placeholders = implode(',', array_fill(0, count($unit_ids), '?'));
        $sql = "UPDATE apparatus_unit SET current_status = ? WHERE unit_id IN ({$placeholders})";
        
        $stmt = $conn->prepare($sql);
        $params = array_merge([$new_status], $unit_ids);
        
        return $stmt->execute($params);
    }
    
    protected function getFormUnitIds($form_id, $conn) 
    {
        $stmt = $conn->prepare("
            SELECT unit_id 
            FROM borrow_items 
            WHERE form_id = :form_id AND unit_id IS NOT NULL
        ");
        $stmt->execute([':form_id' => $form_id]);
        return $stmt->fetchAll(PDO::FETCH_COLUMN); 
    }
    
    protected function getPendingQuantity($type_id, $conn) {
        $stmt = $conn->prepare("
            SELECT SUM(bi.quantity) 
            FROM borrow_items bi
            JOIN borrow_forms bf ON bi.form_id = bf.id
            WHERE bi.type_id = :type_id AND bf.status = 'waiting_for_approval'
        ");
        $stmt->execute([':type_id' => $type_id]);
        return (int)$stmt->fetchColumn() ?? 0;
    }

    protected function getCurrentlyOutCount($type_id, $conn = null) {
        $used_conn = $conn ?? $this->connect(); 
        $stmt = $used_conn->prepare("
            SELECT COUNT(unit_id) FROM apparatus_unit 
            WHERE type_id = :type_id AND current_status IN ('borrowed', 'checking') 
        ");
        $stmt->execute([':type_id' => $type_id]);
        return $stmt->fetchColumn(); 
    }
    
    protected function refreshAvailableStockColumn($type_id, $conn)
    {
        $stmt = $conn->prepare("SELECT total_stock, damaged_stock, lost_stock FROM apparatus_type WHERE id = :id");
        $stmt->execute([':id' => $type_id]);
        $data = $stmt->fetch(PDO::FETCH_ASSOC);

        $currently_out = $this->getCurrentlyOutCount($type_id, $conn); 
        $pending_quantity = $this->getPendingQuantity($type_id, $conn);

        $available_physical_stock = $data['total_stock'] - $data['damaged_stock'] - $data['lost_stock'];
        $new_available_stock = $available_physical_stock - $currently_out - $pending_quantity; 
        $new_available_stock = max(0, $new_available_stock); 

        $status = ($new_available_stock > 0) ? 'available' : 'unavailable';
        
        $update_stmt = $conn->prepare("
            UPDATE apparatus_type 
            SET available_stock = :available_stock, status = :status 
            WHERE id = :id
        ");
        return $update_stmt->execute([
            ':available_stock' => $new_available_stock,
            ':status' => $status,
            ':id' => $type_id
        ]);
    }


    protected function addLog($form_id, $staff_id, $action, $remarks, $conn)
    {
        $stmt = $conn->prepare("
            INSERT INTO logs (form_id, user_id, action, message)
            VALUES (:form_id, :user_id, :action, :remarks)
        ");
        
        $log_user_id = $staff_id ?? $_SESSION["user"]["id"]; 
        
        return $stmt->execute([
            ':form_id' => $form_id,
            ':user_id' => $log_user_id, 
            ':action' => $action,
            ':remarks' => $remarks
        ]);
    }

    // --- MODIFIED STOCK METHODS ---

    public function checkApparatusStock($apparatus_id, $quantity_needed)
    {
        return $this->countAvailableUnits($apparatus_id) >= $quantity_needed;
    }

    protected function checkIfDuplicateExists($name, $type, $size, $material)
    {
        $conn = $this->connect();
        $stmt = $conn->prepare("
            SELECT COUNT(*) 
            FROM apparatus_type 
            WHERE name = :name 
            AND apparatus_type = :type 
            AND size = :size 
            AND material = :material
        ");
        $stmt->execute([
            ':name' => $name,
            ':type' => $type,
            ':size' => $size,
            ':material' => $material
        ]);
        
        return $stmt->fetchColumn() > 0;
    }

    // --- STUDENT SUBMISSION (STOCK QUEUEING LOGIC) ---

    // File: ../classes/Transaction.php

// File: classes/Transaction.php

// File: classes/Transaction.php

// File: classes/Transaction.php

// File: classes/Transaction.php (Full replacement for createTransaction)

public function createTransaction($user_id, $type, $apparatus_list, $borrow_date, $expected_return_date, $agreed_terms)
{
    $conn = $this->connect();
    $conn->setAttribute(PDO::ATTR_AUTOCOMMIT, 0); // Start Transaction Mode
    $conn->beginTransaction();  

    try {
        $refreshed_ids = [];    
        $transaction_items = [];    
        $requested_type_ids = array_column($apparatus_list, 'id');

        // === STEP 0: PRE-CHECK ACTIVE DUPLICATE REQUESTS ===
        // ... (omitted for brevity)

        // 1. Initial Stock Check and locking
        foreach ($apparatus_list as $app) {
            $type_id = $app['id'];
            $quantity = $app['quantity'];

            $stmt_type = $conn->prepare("SELECT total_stock, damaged_stock, lost_stock FROM apparatus_type WHERE id = :id FOR UPDATE");
            $stmt_type->execute([':id' => $type_id]);
            $data = $stmt_type->fetch(PDO::FETCH_ASSOC);

            $available_physical_stock = $data['total_stock'] - $data['damaged_stock'] - $data['lost_stock'];
            $currently_out = $this->getCurrentlyOutCount($type_id, $conn);
            $pending_quantity = $this->getPendingQuantity($type_id, $conn);
            
            $available_for_new_request = $available_physical_stock - $currently_out - $pending_quantity;

            if ($available_for_new_request < $quantity) {
                $conn->rollBack();  
                $conn->setAttribute(PDO::ATTR_AUTOCOMMIT, 1);
                return 'stock_error';
            }

            $transaction_items[] = ['type_id' => $type_id, 'quantity' => $quantity];
            if (!in_array($type_id, $refreshed_ids)) $refreshed_ids[] = $type_id;
        }
        
        // 2. Insert borrow form
        $formType = ($type === 'borrow') ? 'Borrow' : 'Reservation';
        $status = 'waiting_for_approval';
        
        $stmt = $conn->prepare("
            INSERT INTO borrow_forms (user_id, form_type, status, request_date, borrow_date, expected_return_date)
            VALUES (:user_id, :form_type, :status, CURDATE(), :borrow_date, :expected_return_date)
        ");
        $stmt->bindParam(":user_id", $user_id);
        $stmt->bindParam(":form_type", $formType);
        $stmt->bindParam(":status", $status);
        $stmt->bindParam(":borrow_date", $borrow_date);
        $stmt->bindParam(":expected_return_date", $expected_return_date);
        $stmt->execute();

        $form_id = $conn->lastInsertId();

        // 3. Insert borrow items
        $stmt2 = $conn->prepare("
            INSERT INTO borrow_items (form_id, type_id, quantity, item_status, unit_id) 
            VALUES (:form_id, :type_id, :quantity, 'pending', NULL)
        ");
        
        foreach ($transaction_items as $item) {
            for ($i = 0; $i < $item['quantity']; $i++) {
                $stmt2->execute([
                    ':form_id' => $form_id,
                    ':type_id' => $item['type_id'],
                    ':quantity' => 1, 
                ]);
            }
        }

        // 4. Update the available_stock column
        foreach ($refreshed_ids as $type_id) {
            $this->refreshAvailableStockColumn($type_id, $conn);
        }
        
        // 5. Notifications and User details fetch (inside transaction)
        $student_details = $this->getUserDetails($user_id, $conn);
        $item_list_for_email = $this->getFormItemsForEmail($form_id); 
        
        // 1. Notification for Student (System)
        $this->createNotification(
            $user_id, 
            'form_sent', 
            "Your {$formType} request (#{$form_id}) has been sent and is awaiting approval.", 
            "student_view_items.php?form_id={$form_id}", 
            $conn
        );
        
        // 2. Notification for Staff (System)
        $staff_id_to_notify = 1; 
        
        $this->createNotification(
            $staff_id_to_notify, 
            'form_pending', 
            "New {$formType} request (#{$form_id}) from {$student_details['firstname']} is pending approval.", 
            "../staff/staff_pending.php", 
            $conn
        );
        
        $conn->commit();
        $conn->setAttribute(PDO::ATTR_AUTOCOMMIT, 1);
        
        // =================================================================
        // >> RE-INTEGRATED CRITICAL FIX: EMAIL Submission Confirmation (Waiting for Approval) <<
        // >> Using the correct dates ($borrow_date, $expected_return_date)
        // =================================================================
        $mailer = new Mailer(); 

        $mail_success = $mailer->sendTransactionStatusEmail(
            $student_details['email'],
            $student_details['firstname'],
            $form_id,
            'waiting_for_approval', 
            'Your request has been placed and is currently awaiting approval from the staff.',
            $borrow_date, // Passed as Borrow Date
            $expected_return_date, // Passed as Due Date
            '', // No approval date yet
            $item_list_for_email  
        );

        if (!$mail_success) {
            error_log("Submission Email FAILED for Form #{$form_id}. Check Mailer logs.");
        }
        // =================================================================
        
        return $form_id; 
        
    } catch (Exception $e) {
        $conn->rollBack();
        $conn->setAttribute(PDO::ATTR_AUTOCOMMIT, 1);
        error_log("Transaction Creation Failed: " . $e->getMessage());
        return 'db_error'; 
    }
}
    
    public function getTransactionItems($form_id)
    {
        return $this->getFormItems($form_id); 
    }
    

    // File: classes/Transaction.php

public function rejectForm($form_id, $staff_id, $remarks = null) {
    $conn = $this->connect();
    $conn->beginTransaction();
    $final_remarks = empty($remarks) ? null : $remarks;

    try {
        // 1. Get the type_ids involved for stock refresh (to restore the stock from the queue)
        $stmt_get_types = $conn->prepare("SELECT DISTINCT type_id FROM borrow_items WHERE form_id = ?");
        $stmt_get_types->execute([$form_id]);
        $type_ids = $stmt_get_types->fetchAll(PDO::FETCH_COLUMN); 
        
        // 2. Update form and items status
        $stmt_form = $conn->prepare("
                UPDATE borrow_forms 
                SET status='rejected', staff_id=:staff_id, staff_remarks=:remarks 
                WHERE id=:form_id
        ");
        $stmt_form->bindParam(':staff_id', $staff_id);
        $stmt_form->bindParam(':remarks', $final_remarks, $final_remarks === null ? 0 : 2);
        $stmt_form->bindParam(':form_id', $form_id);
        $stmt_form->execute();
        
        $conn->prepare("UPDATE borrow_items SET item_status='rejected' WHERE form_id=:form_id")
                ->execute([':form_id' => $form_id]);
        
        // 3. Log and Commit
        foreach ($type_ids as $type_id) {
                     $this->refreshAvailableStockColumn($type_id, $conn); // Stock refresh restores the available count
        }
        $this->addLog($form_id, $staff_id, 'rejected', $final_remarks, $conn);
        
        // =================================================================
        // >> NEW NOTIFICATION LOGIC (NEED 2 - REJECT) <<
        // =================================================================
        // 1. Get student ID to notify
        $stmt_get_user = $conn->prepare("SELECT user_id, form_type FROM borrow_forms WHERE id = ?");
        $stmt_get_user->execute([$form_id]);
        $form_data = $stmt_get_user->fetch(PDO::FETCH_ASSOC);
        $student_id = $form_data['user_id'];
        $form_type = $form_data['form_type'];

        // 2. Notification for Student (System)
        $this->createNotification(
            $student_id, 
            'form_rejected', 
            "Your {$form_type} request (#{$form_id}) has been **rejected** by staff.", 
            "/student/student_view_items.php?form_id={$form_id}", 
            $conn
        );
        // =================================================================
        
        $conn->commit();
        return true;
    } catch (Exception $e) {
        $conn->rollBack();
        error_log("Rejection failed: " . $e->getMessage());
        return false;
    }
}
 
/**
 * Approve a borrow or reservation request (UNIT ASSIGNMENT LOGIC).
 */
public function approveForm($form_id, $staff_id, $remarks = null) {
    $conn = $this->connect();
    // Start Transaction Mode
    $conn->setAttribute(PDO::ATTR_AUTOCOMMIT, 0); 
    $conn->beginTransaction(); 

    try {
        // 0. Fetch the apparatus types involved in this form for explicit locking
        $stmt_types = $conn->prepare("SELECT DISTINCT type_id FROM borrow_items WHERE form_id = :id");
        $stmt_types->execute([':id' => $form_id]);
        $type_ids_to_lock = $stmt_types->fetchAll(PDO::FETCH_COLUMN);

        // CRITICAL STEP: Lock the involved apparatus_type rows *now* to serialize concurrent access.
        foreach ($type_ids_to_lock as $type_id) {
            $conn->prepare("SELECT id FROM apparatus_type WHERE id = ? FOR UPDATE")
                ->execute([$type_id]);
        }

        // Lock the borrow form data itself
        $stmt = $conn->prepare("SELECT user_id, form_type, status, request_date, expected_return_date FROM borrow_forms WHERE id = :id FOR UPDATE");
        $stmt->bindParam(":id", $form_id);
        $stmt->execute();
        $form = $stmt->fetch(PDO::FETCH_ASSOC);
        
        $student_id = $form['user_id']; 
        $form_type = $form['form_type']; 
        $request_date = $form['request_date'];
        $expected_return_date = $form['expected_return_date'];
        $approval_date_str = date('Y-m-d');

        if (!$form || $form['status'] !== 'waiting_for_approval') { 
            $conn->rollBack(); 
            $conn->setAttribute(PDO::ATTR_AUTOCOMMIT, 1);
            return false; 
        }

        $status = ($form_type === 'borrow') ? 'borrowed' : 'approved';
        $type_ids_to_refresh = [];

        // 1. Get and group pending item rows (unit_id IS NULL)
        $stmt_items = $conn->prepare("SELECT id, type_id, quantity FROM borrow_items WHERE form_id = :form_id AND item_status = 'pending' AND unit_id IS NULL FOR UPDATE");
        $stmt_items->execute([':form_id' => $form_id]);
        $pending_item_rows = $stmt_items->fetchAll(PDO::FETCH_ASSOC);

        $items_by_type = [];
        foreach ($pending_item_rows as $row) {
            $items_by_type[$row['type_id']][] = $row['id'];
            if (!in_array($row['type_id'], $type_ids_to_refresh)) $type_ids_to_refresh[] = $row['type_id'];
        }

        // 2. Assign specific units and update their status 
        $update_item_stmt = $conn->prepare("UPDATE borrow_items SET unit_id = :unit_id, item_status = 'borrowed' WHERE id = :item_id AND unit_id IS NULL");
        
        foreach ($items_by_type as $type_id => $item_ids) {
            $quantity_needed = count($item_ids);
            
            $available_units_count = $this->countAvailableUnits($type_id, $conn); 
            if ($available_units_count < $quantity_needed) {
                $conn->rollBack();
                $conn->setAttribute(PDO::ATTR_AUTOCOMMIT, 1);
                return 'stock_mismatch_on_approval'; 
            }

            $stmt_find_units = $conn->prepare("
                SELECT unit_id 
                FROM apparatus_unit 
                WHERE type_id = :type_id AND current_status = 'available'
                ORDER BY unit_id ASC
                LIMIT :quantity
                FOR UPDATE
            ");
            
            $stmt_find_units->bindValue(':type_id', $type_id);
            // *** FIX: Explicitly bind quantity as integer for LIMIT clause ***
            $stmt_find_units->bindValue(':quantity', $quantity_needed, PDO::PARAM_INT); 
            // ***************************************************************

            $stmt_find_units->execute();
            $units = $stmt_find_units->fetchAll(PDO::FETCH_COLUMN);

            if (count($units) !== $quantity_needed) {
                $conn->rollBack();
                $conn->setAttribute(PDO::ATTR_AUTOCOMMIT, 1);
                return 'stock_mismatch_on_approval';
            }

            foreach ($units as $index => $unit_id) {
                $item_id = $item_ids[$index]; 
                $this->updateUnitStatus([$unit_id], 'borrowed', $conn); 
                
                $update_item_stmt->execute([
                    ':unit_id' => $unit_id,
                    ':item_id' => $item_id
                ]);
            }
        }

        // 3. Update form status - ADD approval_date
        $updateForm = $conn->prepare("
                UPDATE borrow_forms 
                SET status = :status, staff_id = :staff_id, staff_remarks = :remarks, approval_date = :approval_date 
                WHERE id = :id
        ");
        $updateForm->execute([
            ":status" => $status, 
            ":staff_id" => $staff_id, 
            ":remarks" => $remarks, 
            ":id" => $form_id,
            ":approval_date" => $approval_date_str
        ]);

        // 4. Final Stock Refresh
        foreach ($type_ids_to_refresh as $type_id) {
            $this->refreshAvailableStockColumn($type_id, $conn);
        }
        
        $this->addLog($form_id, $staff_id, 'approved', $remarks, $conn);
        
        // 5. Get student details (Needed for notifications/email)
        $student_details = $this->getUserDetails($student_id, $conn);
        
        // 6. Get list of items for the email (NEW)
        $item_list_for_email = $this->getFormItemsForEmail($form_id); 
        
        // =================================================================
        // >> CRITICAL FIX: COMMIT THE TRANSACTION BEFORE EXTERNAL CALLS <<
        // =================================================================
        $conn->commit();
        // Return to normal mode
        $conn->setAttribute(PDO::ATTR_AUTOCOMMIT, 1); 

        // =================================================================
        // >> NOTIFICATION LOGIC (Internal System Notification) <<
        // =================================================================
        // Using null for $conn argument to use a fresh connection, as the transaction is closed.
        $this->createNotification(
            $student_id, 
            'form_approved', 
            "Your {$form_type} request (#{$form_id}) has been **approved** by staff.", 
            "/student/student_view_items.php?form_id={$form_id}", 
            null
        );
        // =================================================================

        // =================================================================
        // >> EMAIL: Send approval email (External Communication) <<
        // =================================================================
        $mailer = new Mailer(); 

        $mail_success = $mailer->sendTransactionStatusEmail(
            $student_details['email'],
            $student_details['firstname'],
            $form_id,
            'approved',
            $remarks,
            $request_date,
            $expected_return_date,
            $approval_date_str,
            $item_list_for_email 
        );
        
        if (!$mail_success) {
            // Log the email failure, but still return true for the main transaction success
            error_log("Approval Email FAILED for Form #{$form_id}. Check Mailer logs.");
        }
        // =================================================================

        return true;

    } catch (Exception $e) {
        // Ensure rollback and autocommit reset on failure
        $conn->rollBack();
        $conn->setAttribute(PDO::ATTR_AUTOCOMMIT, 1);
        
        // ENHANCED ERROR LOGGING for debugging the database error
        $error_message = "Approval failed for Form #{$form_id}: " . $e->getMessage();
        if ($e instanceof PDOException) {
            $error_info = $conn->errorInfo();
            $error_message .= " [PDO Error: SQLSTATE {$e->getCode()}, Driver Error: {$error_info[1]}, Detail: {$error_info[2]}]";
        }
        error_log($error_message);
        
        return false;
    }
}
    
    // Mark a borrow form as returned by the borrower (DEPRECATED: Use confirmReturn)
    public function markReturned($form_id, $staff_id, $remarks = null) {
        return $this->confirmReturn($form_id, $staff_id, $remarks);
    }
    
    // Approve a student's return request (DEPRECATED: Use confirmReturn)
    public function approveReturn($form_id, $staff_id, $remarks = null) {
        return $this->confirmReturn($form_id, $staff_id, $remarks);
    }
    
    /**
     * Mark a form as overdue, set units to 'unavailable', but DONT deduct from total stock.
     */
    public function markAsOverdue($form_id, $staff_id, $remarks = "") {
    $conn = $this->connect();
    $conn->beginTransaction();

    try {
        $stmt_status = $conn->prepare("SELECT status, user_id, expected_return_date FROM borrow_forms WHERE id = :id FOR UPDATE");
        $stmt_status->execute([':id' => $form_id]);
        $form_data = $stmt_status->fetch(PDO::FETCH_ASSOC);

        if (!$form_data) { $conn->rollBack(); return false; }

        $student_id = $form_data['user_id'];
        $old_status = $form_data['status'];
        $expected_return_date = new DateTime($form_data['expected_return_date']);
        $today = new DateTime();
        $today->setTime(0, 0, 0); 

        $days_overdue = 0;
        if ($today > $expected_return_date) {
            $interval = $today->diff($expected_return_date);
            $days_overdue = $interval->days;
        }

        // 1. Identify units involved, and permanently adjust stock
        $type_ids = [];
        $units_declared_lost_count = 0;

        if ($old_status === 'borrowed' || $old_status === 'approved' || $old_status === 'checking') { 
            $unit_ids = $this->getFormUnitIds($form_id, $conn);
            
            foreach($unit_ids as $unit_id) {
                
                $stmt_get_type = $conn->prepare("SELECT type_id FROM apparatus_unit WHERE unit_id = ? FOR UPDATE");
                $stmt_get_type->execute([$unit_id]);
                $type_id = $stmt_get_type->fetchColumn();

                if (!in_array($type_id, $type_ids)) $type_ids[] = $type_id;

                // --- CRITICAL PERMANENT STOCK ADJUSTMENT (FIX) ---
                
                // 1. Mark unit condition as 'lost' and status as 'unavailable'
                $conn->prepare("UPDATE apparatus_unit SET current_condition = 'lost', current_status = 'unavailable' WHERE unit_id = ?")
                    ->execute([$unit_id]);
                
                // 2. Increment apparatus_type.lost_stock count (Lock must be acquired before this, or rely on unit lock)
                $conn->prepare("
                    UPDATE apparatus_type
                    SET lost_stock = lost_stock + 1
                    WHERE id = :type_id
                ")->execute([':type_id' => $type_id]);
                
                $units_declared_lost_count++;
                // --- END CRITICAL FIX ---
            }
        }
        
        // Ensure type_ids are still populated if no units were found (for refresh)
        if (empty($type_ids)) {
            $stmt_get_types = $conn->prepare("SELECT DISTINCT type_id FROM borrow_items WHERE form_id = ?");
            $stmt_get_types->execute([$form_id]);
            $type_ids = $stmt_get_types->fetchAll(PDO::FETCH_COLUMN); 
        }
        
        // 3. Update the form status 
        $stmt = $conn->prepare("
                UPDATE borrow_forms
                SET status = 'overdue', staff_id = :staff_id, staff_remarks = :remarks
                WHERE id = :form_id
        ");
        $stmt->execute([
            ':staff_id' => $staff_id, 
            ':remarks' => $remarks, 
            ':form_id' => $form_id
        ]);
        
        // 4. Update item status
        $conn->prepare("UPDATE borrow_items SET item_status='overdue' WHERE form_id=:form_id")->execute([':form_id' => $form_id]);

        // 5. APPLY BAN ONLY IF DAYS OVERDUE IS 2 OR MORE (1-day grace period)
        $log_message = "Staff marked as overdue. {$units_declared_lost_count} unit(s) declared lost. "; 
        $ban_duration_days = 1;

        if ($days_overdue >= 2) {
            $ban_until = new DateTime("+{$ban_duration_days} day");
            $ban_date_str = $ban_until->format('Y-m-d H:i:s');
            
            $stmt_ban = $conn->prepare("UPDATE users SET ban_until_date = :ban_date WHERE id = :student_id");
            $stmt_ban->execute([
                ':ban_date' => $ban_date_str,
                ':student_id' => $student_id
            ]);
            $log_message .= "{$ban_duration_days}-day ban applied until " . $ban_until->format('Y-m-d H:i:s') . ".";
        } else {
            $log_message .= "Grace period observed (Days Overdue: {$days_overdue}). No ban applied.";
        }

        // 6. Final Refresh stock display (Crucial to update available_stock based on new lost_stock count)
        foreach ($type_ids as $type_id) {
            $this->refreshAvailableStockColumn($type_id, $conn);
        }

        $this->addLog($form_id, $staff_id, 'marked_overdue', $log_message, $conn);
        $conn->commit();
        
        return true;
    } catch (Exception $e) {
        $conn->rollBack();
        error_log("Error marking as overdue: " . $e->getMessage());
        return false;
    }
}
    
public function confirmReturn($form_id, $staff_id, $remarks = "") {
    $conn = $this->connect();
    $conn->beginTransaction();

    try {
        $stmt_status = $conn->prepare("SELECT status, user_id FROM borrow_forms WHERE id = ? FOR UPDATE");
        $stmt_status->execute([$form_id]);
        $form_data = $stmt_status->fetch();
        
        if (!$form_data) { $conn->rollBack(); return false; }
        
        $old_status = $form_data['status'];
        $student_id = $form_data['user_id'];
        
        $type_ids = [];
        $unit_ids = $this->getFormUnitIds($form_id, $conn);
        $unit_placeholders = implode(',', array_fill(0, count($unit_ids), '?'));
        
        // Gather type IDs for stock refresh
        foreach($unit_ids as $unit_id) {
            $stmt_get_type = $conn->prepare("SELECT type_id FROM apparatus_unit WHERE unit_id = ?");
            $stmt_get_type->execute([$unit_id]);
            $type_id = $stmt_get_type->fetchColumn();
            if (!in_array($type_id, $type_ids)) $type_ids[] = $type_id;
        }

        
        // 1. STOCK REVERSAL AND UNIT RESTORATION LOGIC
        $is_late = FALSE;

        if ($old_status === 'overdue') {   
            
            // 1a. CRITICAL FIX: The item was returned late, but is confirmed 'good'. 
            // Reset condition from 'lost' to 'good' AND ensure status is 'available'.
            $stmt_unit_condition_reset = $conn->prepare("
                UPDATE apparatus_unit  
                SET current_condition = 'good', current_status = 'available' // <-- FIXED
                WHERE unit_id IN ({$unit_placeholders})
            ");
            
            // Execute the condition and status reset.
            if (!$stmt_unit_condition_reset->execute($unit_ids)) {
                $conn->rollBack();  
                error_log("CRITICAL FAILURE: Unit condition reset failed for form {$form_id} on return.");
                return false;   
            }
            
            // Treat the return as late return since it was marked overdue
            $is_late = TRUE;   
            
            // 1b. CRITICAL FIX: REVERSE LOST STOCK COUNTER (Needed if markAsOverdue included stock decrement)
            $stmt_units_by_type = $conn->prepare("
                SELECT type_id, COUNT(unit_id) as unit_count  
                FROM apparatus_unit  
                WHERE unit_id IN ({$unit_placeholders})
                GROUP BY type_id
            ");
            $stmt_units_by_type->execute($unit_ids);
            $reversal_counts = $stmt_units_by_type->fetchAll(PDO::FETCH_ASSOC);

            foreach ($reversal_counts as $reversal_data) {
                $type_id = $reversal_data['type_id'];
                $unit_count = $reversal_data['unit_count'];
                
                $conn->prepare("SELECT id FROM apparatus_type WHERE id = ? FOR UPDATE")->execute([$type_id]);

                // Decrement the lost_stock count since the unit was found and returned
                $stmt_update_stock = $conn->prepare("
                    UPDATE apparatus_type
                    SET  
                        lost_stock = GREATEST(0, lost_stock - :unit_count)
                    WHERE id = :type_id
                ");
                $stmt_update_stock->execute([
                    ':unit_count' => $unit_count,
                    ':type_id' => $type_id
                ]);
            }
        
        }
        
        // 1c. Standard Status Update (applies to normal and checking returns)
        if ($old_status === 'borrowed' || $old_status === 'approved' || $old_status === 'checking') {
            // Only runs if the item was NOT overdue/lost, or if a student initiates return from borrowed/approved status
            if (!$this->updateUnitStatus($unit_ids, 'available', $conn)) {
                $conn->rollBack(); return false;
            }
        } else if ($old_status !== 'returned' && !$is_late) { // Catch-all for non-returned statuses that weren't handled by 'overdue' or 'borrowed' status resets
             if (!$this->updateUnitStatus($unit_ids, 'available', $conn)) {
                $conn->rollBack(); return false;
            }
        } else if ($old_status === 'returned') {
            // If status is already 'returned', just maintain the existing late flag
            $is_late = (bool)$form_data['is_late_return'];
        }
        
        // 2. Update form status
        if (empty($type_ids)) {
             $stmt_get_types = $conn->prepare("SELECT DISTINCT type_id FROM borrow_items WHERE form_id = ?");
             $stmt_get_types->execute([$form_id]);
             $type_ids = $stmt_get_types->fetchAll(PDO::FETCH_COLUMN);  
        }
        
        $stmt = $conn->prepare("
                UPDATE borrow_forms
                SET status = 'returned', actual_return_date = CURDATE(), staff_id = :staff_id, staff_remarks = :remarks, is_late_return = :is_late
                WHERE id = :form_id
        ");
        $stmt->execute([
            ':staff_id' => $staff_id,  
            ':remarks' => $remarks,  
            ':form_id' => $form_id,  
            ':is_late' => $is_late
        ]);

        // 3. Update item status in borrow_items
        $conn->prepare("UPDATE borrow_items SET item_status='returned' WHERE form_id=:form_id")
                ->execute([':form_id' => $form_id]);

        // 4. Clear any existing ban for this student (return successful)
        $conn->prepare("UPDATE users SET ban_until_date = NULL WHERE id = :student_id")
                ->execute([':student_id' => $student_id]);
        
        // 5. Refresh stock display
        foreach ($type_ids as $type_id) {
            $this->refreshAvailableStockColumn($type_id, $conn);
        }
        
        $this->addLog($form_id, $staff_id, 'confirmed_return', 'Staff verified return', $conn);
        
        // Notification Logic
        $this->createNotification(
            $student_id,  
            'return_confirmed_good',  
            "Your return for request (#{$form_id}) was confirmed in **good** condition. Transaction complete.",  
            "/student/student_view_items.php?form_id={$form_id}",  
            $conn
        );
        
        $conn->commit();
        return true;
    } catch (Exception $e) {
        $conn->rollBack();
        error_log("Error in confirmReturn: " . $e->getMessage());
        return false;
    }
}
    
public function confirmLateReturn($form_id, $staff_id, $remarks = "") {
    $conn = $this->connect();
    $conn->beginTransaction();

    try {
        $stmt_status = $conn->prepare("SELECT user_id FROM borrow_forms WHERE id = ? FOR UPDATE");
        $stmt_status->execute([$form_id]);
        $form_data = $stmt_status->fetch();

        if (!$form_data) { $conn->rollBack(); return false; }
        
        $student_id = $form_data['user_id'];
        $type_ids_to_refresh = [];
        $units_to_restore_from_loss = []; // Units that were marked lost and need full reversal
        $units_to_just_avail = []; // Units that were only borrowed/checking
        
        $unit_ids = $this->getFormUnitIds($form_id, $conn);
        if (empty($unit_ids)) {
             // If no units are attached (e.g., form was approved but never checked out), just update form status and exit.
             // Fetch type IDs for refresh if units list is empty
             $stmt_get_types = $conn->prepare("SELECT DISTINCT type_id FROM borrow_items WHERE form_id = ?");
             $stmt_get_types->execute([$form_id]);
             $type_ids_to_refresh = $stmt_get_types->fetchAll(PDO::FETCH_COLUMN);
             goto update_form_only;
        }

        $unit_placeholders = implode(',', array_fill(0, count($unit_ids), '?'));

        // 1. DETERMINE UNIT ACTIONS AND COLLECT TYPE IDs
        // Lock unit rows and fetch necessary data
        $stmt_unit_data = $conn->prepare("SELECT unit_id, type_id, current_condition FROM apparatus_unit WHERE unit_id IN ({$unit_placeholders}) FOR UPDATE");
        $stmt_unit_data->execute($unit_ids);
        $unit_details = $stmt_unit_data->fetchAll(PDO::FETCH_ASSOC);

        foreach ($unit_details as $unit_detail) {
            $unit_id = $unit_detail['unit_id'];
            $type_id = $unit_detail['type_id'];

            if (!in_array($type_id, $type_ids_to_refresh)) {
                $type_ids_to_refresh[] = $type_id;
            }

            // CRITICAL CHECK: If the unit was marked 'lost' (due to overdue action), it needs reversal.
            if ($unit_detail['current_condition'] === 'lost') {
                $units_to_restore_from_loss[] = $unit_id; 
            } else {
                // All other units ('good', 'damaged', etc. which were in borrowed/checking status) just need status 'available'.
                $units_to_just_avail[] = $unit_id; 
            }
        }
        
        $is_late = TRUE; // Since we are in confirmLateReturn
        
        // 2. REVERSE LOST STOCK AND RESTORE CONDITION (for units that were LOST)
        if (!empty($units_to_restore_from_loss)) {   
            $restore_placeholders = implode(',', array_fill(0, count($units_to_restore_from_loss), '?'));
            
            // 2a. Restore unit condition to 'good' AND status to 'available' for recovered units.
            $stmt_unit_condition_reset = $conn->prepare("
                UPDATE apparatus_unit  
                SET current_condition = 'good', current_status = 'available' 
                WHERE unit_id IN ({$restore_placeholders})
            ");
            if (!$stmt_unit_condition_reset->execute($units_to_restore_from_loss)) {
                $conn->rollBack();  
                error_log("CRITICAL FAILURE: Unit condition reset failed for form {$form_id} on late return.");
                return false;   
            }
            
            // 2b. Reverse permanent stock changes (DECREMENT LOST_STOCK COUNT in apparatus_type).
            $stmt_units_by_type = $conn->prepare("
                SELECT type_id, COUNT(unit_id) as unit_count  
                FROM apparatus_unit  
                WHERE unit_id IN ({$restore_placeholders})
                GROUP BY type_id
            ");
            $stmt_units_by_type->execute($units_to_restore_from_loss);
            $reversal_counts = $stmt_units_by_type->fetchAll(PDO::FETCH_ASSOC);

            foreach ($reversal_counts as $reversal_data) {
                $type_id = $reversal_data['type_id'];
                $unit_count = $reversal_data['unit_count'];
                
                // Ensure apparatus_type is locked before decrementing the shared counter
                $conn->prepare("SELECT id FROM apparatus_type WHERE id = ? FOR UPDATE")->execute([$type_id]);

                $stmt_update_stock = $conn->prepare("
                    UPDATE apparatus_type
                    SET lost_stock = GREATEST(0, lost_stock - :unit_count)
                    WHERE id = :type_id
                ");
                $stmt_update_stock->execute([
                    ':unit_count' => $unit_count,
                    ':type_id' => $type_id
                ]);
            }
        }
        
        // 3. Simple Status Update (for units that were not lost, e.g., damaged/good items in 'checking')
        if (!empty($units_to_just_avail)) {
            if (!$this->updateUnitStatus($units_to_just_avail, 'available', $conn)) {
                $conn->rollBack(); return false;
            }
        }

        // Jump target if no units were attached to the form
        update_form_only:
        
        // 4. Update the form status: Mark as returned and set LATE flag
        $stmt = $conn->prepare("
                UPDATE borrow_forms
                SET status = 'returned', actual_return_date = CURDATE(), is_late_return = :is_late, staff_id = :staff_id, staff_remarks = :remarks
                WHERE id = :form_id
        ");
        $stmt->execute([
            ':is_late' => $is_late,
            ':staff_id' => $staff_id,  
            ':remarks' => $remarks,  
            ':form_id' => $form_id
        ]);
        
        // 5. Update item status to 'returned' in borrow_items
        $conn->prepare("UPDATE borrow_items SET item_status='returned' WHERE form_id=:form_id")
                ->execute([':form_id' => $form_id]);

        // 6. Clear any existing ban for this student  
        $conn->prepare("UPDATE users SET ban_until_date = NULL WHERE id = :student_id")
                ->execute([':student_id' => $student_id]);
        
        // 7. Refresh stock display
        foreach ($type_ids_to_refresh as $type_id) {
            $this->refreshAvailableStockColumn($type_id, $conn);
        }

        $this->addLog($form_id, $staff_id, 'confirmed_late_return', 'Staff confirmed late return (Unit condition and lost stock reversed).', $conn);
        
        // ... (Notification Logic) ...
        
        $conn->commit();
        return true;  
    } catch (Exception $e) {
        $conn->rollBack();
        error_log("Error confirming late return: " . $e->getMessage());
        return false;
    }
}

public function markAsDamaged($form_id, $staff_id, $remarks = "", $unit_id = null) {
    $conn = $this->connect();
    $conn->beginTransaction();

    try {
        $stmt_status = $conn->prepare("SELECT status, user_id FROM borrow_forms WHERE id = ? FOR UPDATE");
        $stmt_status->execute([$form_id]);
        $form_data = $stmt_status->fetch(PDO::FETCH_ASSOC);
        
        if (!$form_data) { $conn->rollBack(); return false; }
        
        $old_status = $form_data['status'];
        $student_id = $form_data['user_id'];
        
        $all_unit_ids = $this->getFormUnitIds($form_id, $conn);
        $type_ids_to_refresh = [];
        
        // 1. Update the borrow form status to 'damaged'
        $stmt = $conn->prepare("
                UPDATE borrow_forms
                SET status = 'damaged', actual_return_date = CURDATE(), staff_id = :staff_id, staff_remarks = :remarks
                WHERE id = :form_id
        ");
        $stmt->execute([':staff_id' => $staff_id, ':remarks' => $remarks, ':form_id' => $form_id]);

        
        foreach ($all_unit_ids as $current_unit_id) {
            
            // Get Type ID and CURRENT condition (Needed for stock refresh and reversal)
            $stmt_get_unit_data = $conn->prepare("SELECT type_id, current_condition FROM apparatus_unit WHERE unit_id = ? FOR UPDATE");
            $stmt_get_unit_data->execute([$current_unit_id]);
            $unit_data = $stmt_get_unit_data->fetch(PDO::FETCH_ASSOC);
            $type_id = $unit_data['type_id'];
            $current_condition = $unit_data['current_condition'];

            if (!in_array($type_id, $type_ids_to_refresh)) $type_ids_to_refresh[] = $type_id;

            if ($current_unit_id == $unit_id) {
                // --- A. DAMAGED ITEM LOGIC ---

                // Update unit status and condition
                $conn->prepare("
                        UPDATE apparatus_unit
                        SET current_condition = 'damaged', current_status = 'unavailable'
                        WHERE unit_id = :unit_id
                ")->execute([':unit_id' => $unit_id]);
                
                // Lock apparatus_type row
                $conn->prepare("SELECT id FROM apparatus_type WHERE id = ? FOR UPDATE")->execute([$type_id]);

                // Increment damaged_stock
                $conn->prepare("
                        UPDATE apparatus_type
                        SET damaged_stock = damaged_stock + 1
                        WHERE id = :type_id
                ")->execute([':type_id' => $type_id]);
                
                // CRITICAL FIX: If the damaged item was PREVIOUSLY marked LOST, reverse the lost_stock counter
                if ($current_condition === 'lost' && $old_status === 'overdue') {
                    $conn->prepare("
                        UPDATE apparatus_type
                        SET lost_stock = GREATEST(0, lost_stock - 1)
                        WHERE id = :type_id
                    ")->execute([':type_id' => $type_id]);
                }
                
                // Update item status in borrow_items
                $conn->prepare("UPDATE borrow_items SET item_status='damaged' WHERE form_id = :form_id AND unit_id = :unit_id")
                    ->execute([':form_id' => $form_id, ':unit_id' => $unit_id]);

            } else {
                // --- B. OTHER ITEMS (RETURNED IN GOOD CONDITION) LOGIC ---
                
                // Set to good condition and available status
                $conn->prepare("
                    UPDATE apparatus_unit 
                    SET current_condition = 'good', current_status = 'available' 
                    WHERE unit_id = ?
                ")->execute([$current_unit_id]);
                
                // CRITICAL FIX: If this GOOD item was PREVIOUSLY marked LOST, reverse the lost_stock counter
                if ($current_condition === 'lost') {
                    // Lock apparatus_type row
                    $conn->prepare("SELECT id FROM apparatus_type WHERE id = ? FOR UPDATE")->execute([$type_id]);
                    
                    $conn->prepare("
                        UPDATE apparatus_type
                        SET lost_stock = GREATEST(0, lost_stock - 1)
                        WHERE id = :type_id
                    ")->execute([':type_id' => $type_id]);
                }
                
                $conn->prepare("UPDATE borrow_items SET item_status='returned' WHERE form_id = :form_id AND unit_id = :unit_id")
                    ->execute([':form_id' => $form_id, ':unit_id' => $current_unit_id]);
            }
        }
        
        // 3. Clear any existing ban for this student 
        $conn->prepare("UPDATE users SET ban_until_date = NULL WHERE id = :student_id")
                ->execute([':student_id' => $student_id]);
        
        // 4. Refresh stock display
        foreach ($type_ids_to_refresh as $type_id) {
            $this->refreshAvailableStockColumn($type_id, $conn);
        }
        
        $this->addLog($form_id, $staff_id, 'returned_with_issue', $remarks, $conn);
        
        // ... (Notification Logic) ...
        
        $conn->commit();
        return true;
    } catch (Exception $e) {
        $conn->rollBack();
        error_log("Mark Damaged failed: " . $e->getMessage());
        return false;
    }
}
    
    // --- NEW UNIT MANAGEMENT METHODS ---
    
    /**
     * Retrieves all individual units for a given apparatus type ID.
     */
    public function getUnitsByType($type_id) 
    {
        $conn = $this->connect();
        $stmt = $conn->prepare("
            SELECT unit_id, serial_number, current_condition, current_status, created_at 
            FROM apparatus_unit 
            WHERE type_id = ? 
            ORDER BY unit_id ASC
        ");
        $stmt->execute([$type_id]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }
    
    /**
     * Restores a unit (from Damaged or Lost) back to good condition and updates stock counts.
     */
public function restoreUnit($unit_id, $staff_id)  
{
    $conn = $this->connect();
    $conn->beginTransaction();

    try {
        // 1. Get the unit data and lock the unit row
        $stmt_get_unit = $conn->prepare("
            SELECT type_id, current_condition 
            FROM apparatus_unit 
            WHERE unit_id = ? FOR UPDATE
        ");
        $stmt_get_unit->execute([$unit_id]);
        $unit_data = $stmt_get_unit->fetch(PDO::FETCH_ASSOC);

        // Check if the unit needs restoring (only Damaged or Lost)
        if (!$unit_data || ($unit_data['current_condition'] !== 'damaged' && $unit_data['current_condition'] !== 'lost')) {
            $conn->rollBack();
            return 'not_restorable'; 
        }
        
        $type_id = $unit_data['type_id'];
        $condition_to_reverse = $unit_data['current_condition'];
        
        // CRITICAL: Lock apparatus_type row
        $conn->prepare("SELECT id FROM apparatus_type WHERE id = ? FOR UPDATE")->execute([$type_id]);

        // 2. Update apparatus_unit: Restore to good/available
        $stmt_unit_update = $conn->prepare("
            UPDATE apparatus_unit  
            SET current_condition = 'good', current_status = 'available' 
            WHERE unit_id = ?
        ");
        $stmt_unit_update->execute([$unit_id]);
        
        // 3. Update apparatus_type: Decrement the respective stock count by 1
        $column_to_decrement = ($condition_to_reverse === 'damaged') ? 'damaged_stock' : 'lost_stock';

        $stmt_type_decrement = $conn->prepare("
            UPDATE apparatus_type  
            SET {$column_to_decrement} = GREATEST(0, {$column_to_decrement} - 1)
            WHERE id = ?
        ");
        $stmt_type_decrement->execute([$type_id]);

        // 4. Recalculate available_stock column
        $this->refreshAvailableStockColumn($type_id, $conn);
        
        // 5. Log the action (Optional: Add log entry here)
        $this->addLog(null, $staff_id, 'unit_restored', "Unit ID {$unit_id} restored from {$condition_to_reverse} to good/available.", $conn);

        $conn->commit();
        return true;

    } catch (Exception $e) {
        $conn->rollBack();
        error_log("Unit Restoration failed: " . $e->getMessage());
        return false;
    }
}
    
    // --- MODIFIED APPARATUS CRUD METHODS (BCNF) ---

    // File: classes/Transaction.php

public function addApparatus($name, $type, $size, $material, $description, $total_stock, $damaged_stock, $lost_stock, $image = 'default.jpg') {
    $conn = $this->connect();
    $conn->beginTransaction();

    if ($this->checkIfDuplicateExists($name, $type, $size, $material)) {
        if ($conn->inTransaction()) { $conn->rollBack(); }
        return false;
    }
    
    try {
        $initial_available_stock = $total_stock - $damaged_stock - $lost_stock; 
        $initial_available_stock = max(0, $initial_available_stock);

        $available_units = max(0, $total_stock - $damaged_stock - $lost_stock);

        $initial_condition = ($damaged_stock > 0 || $lost_stock > 0) ? 'mixed' : 'good';
        $initial_status = ($initial_available_stock > 0) ? 'available' : 'unavailable';
        
        // --- STEP 1: Insert Type Data into apparatus_type ---
        $stmt = $conn->prepare(" 
                INSERT INTO apparatus_type (name, apparatus_type, size, material, description, total_stock, available_stock, damaged_stock, lost_stock, item_condition, status, image)
                VALUES (:name, :type, :size, :material, :description, :total_stock, :available_stock, :damaged_stock, :lost_stock, :condition, :status, :image)
        ");

        $stmt->execute([
            ":name" => $name, ":type" => $type, ":size" => $size, ":material" => $material, 
            ":description" => $description, ":total_stock" => $total_stock, 
            ":available_stock" => $initial_available_stock, 
            ":damaged_stock" => $damaged_stock, ":lost_stock" => $lost_stock, 
            ":condition" => $initial_condition, ":status" => $initial_status, 
            ":image" => $image
        ]);
        
        $type_id = $conn->lastInsertId();

        // --- STEP 2: Insert Unit Data into apparatus_unit ---
        
        // Insert Available Units
        $stmt_unit_available = $conn->prepare("INSERT INTO apparatus_unit (type_id, current_condition, current_status) VALUES (:type_id, 'good', 'available')");
        for ($i = 0; $i < $available_units; $i++) {
            $stmt_unit_available->execute([':type_id' => $type_id]);
        }

        // Insert Damaged/Lost Units (as unavailable)
        $stmt_unit_unavailable = $conn->prepare("INSERT INTO apparatus_unit (type_id, current_condition, current_status) VALUES (:type_id, :condition, 'unavailable')");
        for ($i = 0; $i < $damaged_stock; $i++) {
            $stmt_unit_unavailable->execute([':type_id' => $type_id, ':condition' => 'damaged']);
        }
        for ($i = 0; $i < $lost_stock; $i++) {
            $stmt_unit_unavailable->execute([':type_id' => $type_id, ':condition' => 'lost']);
        }
        
        $conn->commit();
        return true;

    } catch (Exception $e) {
        if ($conn->inTransaction()) { 
            $conn->rollBack();
        }
        error_log("Add Apparatus Error: " . $e->getMessage());
        return false;
    }
}

    public function updateApparatus($id, $name, $type, $size, $material, $description, $total_stock, $condition, $status, $image) {
        return $this->updateApparatusDetailsAndStock($id, $name, $type, $size, $material, $description, $total_stock, 0, 0, $image);
    } 

    public function getAllApparatus() {
        $conn = $this->connect();
        $stmt = $conn->prepare("
            SELECT 
                at.*, 
                (SELECT COUNT(unit_id) FROM apparatus_unit WHERE type_id = at.id AND current_status IN ('borrowed', 'checking')) AS currently_out 
            FROM apparatus_type at
            ORDER BY at.id DESC
        ");
        $stmt->execute();
        return $stmt->fetchAll();
    }

    public function deleteApparatus($id) {
        $conn = $this->connect();
        $conn->beginTransaction();

        try {
            // 1. Check for active loans (unchanged)
            $check_active_loans = $conn->prepare("
                SELECT 1 
                FROM apparatus_unit au
                WHERE au.type_id = :id 
                  AND au.current_status IN ('borrowed', 'checking') 
                LIMIT 1
            ");
            $check_active_loans->execute([':id' => $id]);

            if ($check_active_loans->rowCount() > 0) {
                $conn->rollBack();
                return 'in_use'; 
            }
            
            // 2. Execute the DELETE command 
            $sql_delete = "DELETE FROM apparatus_type WHERE id = :id";
            $stmt_delete = $conn->prepare($sql_delete);
            $stmt_delete->bindParam(":id", $id);
            
            if (!$stmt_delete->execute()) {
                $conn->rollBack();
                return false;
            }

            // 3. COMMIT the transaction 
            $conn->commit();
            
            // 4. Reset AUTO_INCREMENT (Non-critical operation, now outside the transaction)
            $sql_max_id = "SELECT MAX(id) FROM apparatus_type";
            $max_id = $conn->query($sql_max_id)->fetchColumn();
            $next_auto_increment = ($max_id === false || $max_id === null) ? 1 : $max_id + 1;
            
            $conn->exec("ALTER TABLE apparatus_type AUTO_INCREMENT = {$next_auto_increment}");

            return true;
            
        } catch (Exception $e) {
            if ($conn->inTransaction()) {
                $conn->rollBack();
            }
            error_log("Delete Apparatus Error (ID {$id}): " . $e->getMessage());
            return false;
        }
    }

    // Transaction.php
    public function getAvailableApparatus() {
        $conn = $this->connect();
        $sql = "
            SELECT 
                at.id, 
                at.name, 
                at.apparatus_type, 
                at.size, 
                at.material, 
                at.description, 
                at.image, 
                (at.total_stock - at.damaged_stock - at.lost_stock) AS physical_stock,
                (SELECT COUNT(unit_id) FROM apparatus_unit WHERE type_id = at.id AND current_status IN ('borrowed', 'checking')) AS currently_out 
            FROM apparatus_type at 
            ORDER BY at.name ASC
        ";
        $stmt = $conn->prepare($sql);
        $stmt->execute();
        
        $results = $stmt->fetchAll(PDO::FETCH_ASSOC);
        $final_results = [];
        foreach ($results as $row) {
            $pending = $this->getPendingQuantity($row['id'], $conn);
            $row['available_stock'] = $row['physical_stock'] - $row['currently_out'] - $pending;
            
            if ($row['available_stock'] > 0) {
                unset($row['physical_stock']);
                unset($row['currently_out']);
                $final_results[] = $row; 
            }
        }
        
        return $final_results;
    }
    
    // --- ADDED/MODIFIED METHODS FOR SEARCH & FILTER (BCNF) ---

    public function getUniqueApparatusTypes() {
        $conn = $this->connect();
        $sql = "SELECT DISTINCT apparatus_type FROM apparatus_type ORDER BY apparatus_type ASC";
        $stmt = $conn->prepare($sql);
        $stmt->execute();
        
        return $stmt->fetchAll(PDO::FETCH_COLUMN);
    }

    public function getAllApparatusIncludingZeroStock($search_term = '', $filter_type = '') {
        $conn = $this->connect();
        
        $sql = "SELECT id, name, apparatus_type, size, material, description, image, available_stock 
                  FROM apparatus_type";
        
        $params = [];
        $conditions = [];

        if (!empty($search_term)) {
            $conditions[] = "(name LIKE :search_term)"; 
            $params[':search_term'] = '%' . $search_term . '%';
        }

        if (!empty($filter_type)) {
            $conditions[] = "apparatus_type = :filter_type";
            $params[':filter_type'] = $filter_type;
        }

        if (!empty($conditions)) {
            $sql .= " WHERE " . implode(' AND ', $conditions);
        }

        $sql .= " ORDER BY available_stock DESC, name ASC"; 
        
        $stmt = $conn->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetchAll();
    }
    
    public function getApparatusById($id) {
        $conn = $this->connect();
        $stmt = $conn->prepare("
            SELECT 
                at.*, 
                (SELECT COUNT(unit_id) FROM apparatus_unit WHERE type_id = at.id AND current_status IN ('borrowed', 'checking')) AS currently_out 
            FROM apparatus_type at 
            WHERE id = :id
        "); 
        $stmt->execute([':id' => $id]);
        return $stmt->fetch();
    }

    public function getFormItems($form_id)
    {
        $sql = "SELECT 
                        bi.id, 
                        bi.type_id AS apparatus_id, 
                        bi.quantity, 
                        bi.item_status,
                        at.name, 
                        at.apparatus_type,
                        at.size, 
                        at.material,
                        bi.unit_id,
                        au.current_status AS unit_current_status
                    FROM borrow_items bi
                    JOIN apparatus_type at ON bi.type_id = at.id
                    LEFT JOIN apparatus_unit au ON bi.unit_id = au.unit_id 
                    WHERE bi.form_id = :form_id
                    ORDER BY at.name, bi.unit_id";

        $stmt = $this->connect()->prepare($sql);
        $stmt->bindParam(':form_id', $form_id);
        $stmt->execute();

        return $stmt->fetchAll();
    }
    
    public function getBorrowFormItems($form_id) {
        $conn = $this->connect();
        $stmt = $conn->prepare("
            SELECT 
                at.id,
                at.name, 
                at.apparatus_type, 
                at.size, 
                at.material, 
                at.image, 
                COUNT(bi.id) AS quantity, 
                MAX(bi.item_status) AS item_status,
                GROUP_CONCAT(bi.unit_id ORDER BY bi.unit_id) AS unit_ids_list
            FROM borrow_items bi
            JOIN apparatus_type at ON bi.type_id = at.id 
            WHERE bi.form_id = :form_id
            GROUP BY at.id, at.name, at.apparatus_type, at.size, at.material, at.image
            ORDER BY at.name
        ");
        $stmt->execute([':form_id' => $form_id]);
        return $stmt->fetchAll();
    }
    
    public function getStudentFormsByStatus($student_id, $status = null) {
        $conn = $this->connect();
        
        $select_list = "GROUP_CONCAT(CONCAT(a.name, ' (x', form_items.count, ')') SEPARATOR ', ') AS apparatus_list";
        
        $base_sql = "
            SELECT bf.*, {$select_list}
            FROM borrow_forms bf
            JOIN users u ON bf.user_id = u.id
            JOIN (
                SELECT 
                    bi.form_id, 
                    bi.type_id, 
                    COUNT(bi.id) as count 
                FROM borrow_items bi
                GROUP BY bi.form_id, bi.type_id
            ) AS form_items ON bf.id = form_items.form_id
            JOIN apparatus_type a ON form_items.type_id = a.id
        ";

        if ($status && strtolower($status) !== 'all') {
            $statusArray = array_map('trim', explode(',', $status));
            $placeholders = implode(',', array_fill(0, count($statusArray), '?'));
            $query = $conn->prepare("
                {$base_sql}
                WHERE bf.user_id = ?
                AND bf.status IN ({$placeholders})
                GROUP BY bf.id
            ");
            $query->execute(array_merge([$student_id], $statusArray));
        } else {
            $query = $conn->prepare("
                {$base_sql}
                WHERE bf.user_id = ?
                GROUP BY bf.id
            ");
            $query->execute([$student_id]);
        }

        return $query->fetchAll(); 
    }

    // --- NEW METHOD TO GET OVERDUE FORMS (Category 3) ---
    private function getOverdueBorrowedForms() {
        $conn = $this->connect();
        
        $select_list = "GROUP_CONCAT(CONCAT(a.name, ' (x', form_items.count, ')') SEPARATOR ', ') AS apparatus_list";
        
        $query = $conn->query("
            SELECT 
                bf.id, bf.user_id AS borrower_id, 
                u.firstname, u.lastname, bf.form_type AS type, 
                bf.status, bf.request_date, bf.borrow_date, bf.expected_return_date, bf.actual_return_date, bf.staff_remarks,
                {$select_list}
            FROM borrow_forms bf
            JOIN users u ON bf.user_id = u.id
            JOIN (
                SELECT 
                    bi.form_id, 
                    bi.type_id, 
                    COUNT(bi.id) as count 
                FROM borrow_items bi
                GROUP BY bi.form_id, bi.type_id
            ) AS form_items ON bf.id = form_items.form_id
            JOIN apparatus_type a ON form_items.type_id = a.id
            WHERE bf.status = 'borrowed' 
            AND bf.expected_return_date < CURDATE()
            GROUP BY bf.id
            ORDER BY bf.id DESC
        ");

        return $query->fetchAll(PDO::FETCH_ASSOC);
    }
    // -----------------------------------------------------

    /**
     * Retrieves forms requiring staff action:
     * 1. Waiting for Approval
     * 2. Checking (Student initiated return)
     * 3. Borrowed & Overdue (Student has NOT initiated return - MISSING/LATE)
     */
    public function getPendingForms() {
        $conn = $this->connect();
        
        // 1. Get Approval/Checking Forms (Category 1 & 2)
        $select_list = "GROUP_CONCAT(CONCAT(a.name, ' (x', form_items.count, ')') SEPARATOR ', ') AS apparatus_list";
        
        $query_active = $conn->query("
            SELECT 
                bf.id, bf.user_id AS borrower_id, 
                u.firstname, u.lastname, bf.form_type AS type, 
                bf.status, bf.request_date, bf.borrow_date, bf.expected_return_date, bf.actual_return_date, bf.staff_remarks,
                {$select_list}
            FROM borrow_forms bf
            JOIN users u ON bf.user_id = u.id
            JOIN (
                SELECT 
                    bi.form_id, 
                    bi.type_id, 
                    COUNT(bi.id) as count 
                FROM borrow_items bi
                GROUP BY bi.form_id, bi.type_id
            ) AS form_items ON bf.id = form_items.form_id
            JOIN apparatus_type a ON form_items.type_id = a.id
            WHERE bf.status IN ('waiting_for_approval', 'checking')
            GROUP BY bf.id
        ");
        $active_forms = $query_active->fetchAll(PDO::FETCH_ASSOC);
        
        // 2. Get Missing/Overdue Forms (Category 3)
        $overdue_forms = $this->getOverdueBorrowedForms();

        // 3. Combine and sort results
        $combined_forms = array_merge($active_forms, $overdue_forms);
        
        // Sort by Form ID (or date, but ID is simple) descending
        usort($combined_forms, function($a, $b) {
            return $b['id'] <=> $a['id'];
        });

        return $combined_forms; 
    }
    
    public function getFormApparatus($form_id)
    {
        $conn = $this->connect();
        $stmt = $conn->prepare("
            SELECT at.id, at.name, at.image, COUNT(bi.id) AS quantity 
            FROM borrow_items bi
            JOIN apparatus_type at ON bi.type_id = at.id 
            WHERE bi.form_id = :form_id
            GROUP BY at.id, at.name, at.image
        ");
        $stmt->bindParam(':form_id', $form_id);
        $stmt->execute();
        return $stmt->fetchAll();
    }

    public function isApparatusDeletable($apparatus_id)
    {
        $conn = $this->connect();
        $sql = "
            SELECT 1 FROM apparatus_unit 
            WHERE type_id = :id 
            AND current_status IN ('borrowed') 
            LIMIT 1
        ";
        $stmt = $conn->prepare($sql);
        $stmt->execute([':id' => $apparatus_id]);
        return $stmt->rowCount() === 0; 
    }

    // File: classes/Transaction.php

// File: classes/Transaction.php

/**
 * Updates the apparatus details and synchronizes individual units in apparatus_unit
 * to match the new total, damaged, and lost stock counts.
 */
public function updateApparatusDetailsAndStock($id, $name, $type, $size, $material, $description, $new_total_stock, $new_damaged_stock, $new_lost_stock, $image)
{
    $conn = $this->connect();
    $conn->beginTransaction();

    try {
        // 1. Fetch current stock and unit counts, and lock the row
        $stmt_current = $conn->prepare("
            SELECT total_stock, damaged_stock, lost_stock, status 
            FROM apparatus_type 
            WHERE id = :id FOR UPDATE
        ");
        $stmt_current->execute([':id' => $id]);
        $current_data = $stmt_current->fetch(PDO::FETCH_ASSOC);

        if (!$current_data) {
            $conn->rollBack();
            return false;
        }

        $current_total_stock = $current_data['total_stock'];
        $current_damaged_stock = $current_data['damaged_stock'];
        $current_lost_stock = $current_data['lost_stock'];
        
        $current_physical_available = $current_total_stock - $current_damaged_stock - $current_lost_stock;
        
        $currently_out = $this->getCurrentlyOutCount($id, $conn);
        $pending_quantity = $this->getPendingQuantity($id, $conn);


        // --- VALIDATION (Should be done by controller, but repeating for safety) ---
        $new_available_physical = $new_total_stock - $new_damaged_stock - $new_lost_stock;
        
        if ($new_available_physical < 0) {
            $conn->rollBack();
            return 'stock_math_error';
        }
        if ($new_available_physical < $currently_out) {
            $conn->rollBack();
            return 'stock_too_low';
        }

        // --- 2. UNIT RECONCILIATION LOGIC ---

        // A. Handle Total Stock Change (Creation/Deletion of units)
        $total_diff = $new_total_stock - $current_total_stock;
        
        if ($total_diff > 0) {
            // Add New Units (always start as good/available)
            $stmt_insert = $conn->prepare("INSERT INTO apparatus_unit (type_id, current_condition, current_status) VALUES (:type_id, 'good', 'available')");
            for ($i = 0; $i < $total_diff; $i++) {
                $stmt_insert->execute([':type_id' => $id]);
            }
        } elseif ($total_diff < 0) {
            // Delete Units (units MUST NOT be in 'borrowed', 'checking', 'damaged', or 'lost' state)
            $units_to_delete_count = abs($total_diff);
            
            // Find units in 'available' status to delete
            $stmt_delete = $conn->prepare("
                SELECT unit_id FROM apparatus_unit 
                WHERE type_id = :type_id AND current_status = 'available' AND current_condition = 'good'
                ORDER BY unit_id DESC LIMIT :limit
            ");
            $stmt_delete->bindValue(':type_id', $id, PDO::PARAM_INT);
            $stmt_delete->bindValue(':limit', $units_to_delete_count, PDO::PARAM_INT);
            $stmt_delete->execute();
            $delete_unit_ids = $stmt_delete->fetchAll(PDO::FETCH_COLUMN);

            if (count($delete_unit_ids) !== $units_to_delete_count) {
                // This means there aren't enough available units to fulfill the deletion. This should not happen 
                // if the total stock validation passed, but is a fail-safe.
                $conn->rollBack();
                return 'delete_unit_fail'; 
            }
            
            $placeholders = implode(',', array_fill(0, count($delete_unit_ids), '?'));
            $conn->prepare("DELETE FROM apparatus_unit WHERE unit_id IN ({$placeholders})")->execute($delete_unit_ids);
        }

        // B. Handle Damaged Stock Change
        $damaged_diff = $new_damaged_stock - $current_damaged_stock;
        
        if ($damaged_diff > 0) {
            // Mark existing 'good/available' units as 'damaged/unavailable'
            $stmt_mark_damaged = $conn->prepare("
                SELECT unit_id FROM apparatus_unit 
                WHERE type_id = :type_id AND current_condition = 'good' AND current_status = 'available' 
                ORDER BY unit_id ASC LIMIT :limit
            ");
            $stmt_mark_damaged->bindValue(':type_id', $id, PDO::PARAM_INT);
            $stmt_mark_damaged->bindValue(':limit', $damaged_diff, PDO::PARAM_INT);
            $stmt_mark_damaged->execute();
            $damaged_unit_ids = $stmt_mark_damaged->fetchAll(PDO::FETCH_COLUMN);

            if (count($damaged_unit_ids) !== $damaged_diff) {
                $conn->rollBack();
                return 'mark_damaged_fail'; // Cannot mark damaged due to insufficient good stock
            }
            
            $placeholders = implode(',', array_fill(0, count($damaged_unit_ids), '?'));
            $conn->prepare("UPDATE apparatus_unit SET current_condition = 'damaged', current_status = 'unavailable' WHERE unit_id IN ({$placeholders})")->execute($damaged_unit_ids);
        
        } elseif ($damaged_diff < 0) {
            // Mark existing 'damaged/unavailable' units as 'good/available' (Restoration)
            $units_to_restore_count = abs($damaged_diff);
            $stmt_restore_damaged = $conn->prepare("
                SELECT unit_id FROM apparatus_unit 
                WHERE type_id = :type_id AND current_condition = 'damaged' AND current_status = 'unavailable'
                ORDER BY unit_id DESC LIMIT :limit
            ");
            $stmt_restore_damaged->bindValue(':type_id', $id, PDO::PARAM_INT);
            $stmt_restore_damaged->bindValue(':limit', $units_to_restore_count, PDO::PARAM_INT);
            $stmt_restore_damaged->execute();
            $restore_unit_ids = $stmt_restore_damaged->fetchAll(PDO::FETCH_COLUMN);

            if (count($restore_unit_ids) !== $units_to_restore_count) {
                $conn->rollBack();
                return 'restore_damaged_fail';
            }
            
            $placeholders = implode(',', array_fill(0, count($restore_unit_ids), '?'));
            $conn->prepare("UPDATE apparatus_unit SET current_condition = 'good', current_status = 'available' WHERE unit_id IN ({$placeholders})")->execute($restore_unit_ids);
        }

        // C. Handle Lost Stock Change (Same logic as Damaged, but targeting 'lost' condition)
        $lost_diff = $new_lost_stock - $current_lost_stock;
        
        if ($lost_diff > 0) {
            // Mark existing 'good/available' units as 'lost/unavailable'
            $stmt_mark_lost = $conn->prepare("
                SELECT unit_id FROM apparatus_unit 
                WHERE type_id = :type_id AND current_condition = 'good' AND current_status = 'available'
                ORDER BY unit_id ASC LIMIT :limit
            ");
            $stmt_mark_lost->bindValue(':type_id', $id, PDO::PARAM_INT);
            $stmt_mark_lost->bindValue(':limit', $lost_diff, PDO::PARAM_INT);
            $stmt_mark_lost->execute();
            $lost_unit_ids = $stmt_mark_lost->fetchAll(PDO::FETCH_COLUMN);

            if (count($lost_unit_ids) !== $lost_diff) {
                $conn->rollBack();
                return 'mark_lost_fail'; // Cannot mark lost due to insufficient good stock
            }
            
            $placeholders = implode(',', array_fill(0, count($lost_unit_ids), '?'));
            $conn->prepare("UPDATE apparatus_unit SET current_condition = 'lost', current_status = 'unavailable' WHERE unit_id IN ({$placeholders})")->execute($lost_unit_ids);
        
        } elseif ($lost_diff < 0) {
            // Mark existing 'lost/unavailable' units as 'good/available' (Recovery)
            $units_to_recover_count = abs($lost_diff);
            $stmt_recover_lost = $conn->prepare("
                SELECT unit_id FROM apparatus_unit 
                WHERE type_id = :type_id AND current_condition = 'lost' AND current_status = 'unavailable'
                ORDER BY unit_id DESC LIMIT :limit
            ");
            $stmt_recover_lost->bindValue(':type_id', $id, PDO::PARAM_INT);
            $stmt_recover_lost->bindValue(':limit', $units_to_recover_count, PDO::PARAM_INT);
            $stmt_recover_lost->execute();
            $recover_unit_ids = $stmt_recover_lost->fetchAll(PDO::FETCH_COLUMN);

            if (count($recover_unit_ids) !== $units_to_recover_count) {
                $conn->rollBack();
                return 'recover_lost_fail';
            }
            
            $placeholders = implode(',', array_fill(0, count($recover_unit_ids), '?'));
            $conn->prepare("UPDATE apparatus_unit SET current_condition = 'good', current_status = 'available' WHERE unit_id IN ({$placeholders})")->execute($recover_unit_ids);
        }

        // --- 3. FINAL APPARATUS_TYPE UPDATE (Non-Stock fields & final summary columns) ---

        $item_condition = ($new_damaged_stock > 0 || $new_lost_stock > 0) ? 'mixed' : 'good';
        $status = ($new_available_physical > $currently_out + $pending_quantity) ? 'available' : 'unavailable';
        
        $sql = "
            UPDATE apparatus_type 
            SET name = :name, apparatus_type = :type, size = :size, material = :material, 
                description = :description, total_stock = :total_stock, 
                damaged_stock = :damaged_stock, lost_stock = :lost_stock,
                available_stock = :available_stock, item_condition = :condition, status = :status,
                image = :image
            WHERE id = :id
        ";

        $stmt = $conn->prepare($sql);

        $stmt->execute([
            ":name" => $name, ":type" => $type, ":size" => $size, ":material" => $material, 
            ":description" => $description, ":total_stock" => $new_total_stock, 
            ":damaged_stock" => $new_damaged_stock, ":lost_stock" => $new_lost_stock, 
            ":available_stock" => max(0, $new_available_physical - $currently_out - $pending_quantity), 
            ":condition" => $item_condition, ":status" => $status, 
            ":image" => $image, ":id" => $id
        ]);
        
        $conn->commit();
        return true;
        
    } catch (Exception $e) {
        $conn->rollBack();
        error_log("Update Apparatus Details Failed (ID: {$id}): " . $e->getMessage());
        return "Database Error: " . $e->getMessage();
    }
}
/**
 * Retrieves borrow forms filtered by status, search term, date range, 
 * specific apparatus, and apparatus type.
 */
/**
 * Retrieves ALL form rows filtered by the complex criteria.
 * This method finds the unique form IDs matching the criteria. The view then 
 * loops through these IDs and calls getFormItems() for detailed item display.
 */
public function getAllFormsFiltered(
    $status_filter = 'all', 
    $search_term = '', 
    $start_date = '', 
    $end_date = '', 
    $apparatus_id = '', 
    $apparatus_type = ''
) {
    $conn = $this->connect();
    $where_clauses = [];
    $params = [];
    $joins = "";
    
    // LEFT JOINs are used so that forms without any items (e.g., rejected early) 
    // or forms without item details (e.g., item details tables haven't been populated yet) are still included.
    $joins .= "
        LEFT JOIN borrow_items bi ON bf.id = bi.form_id
        LEFT JOIN apparatus_type at ON bi.type_id = at.id
    ";

    $base_sql = "
        SELECT 
            DISTINCT bf.*, 
            u.firstname, 
            u.lastname
        FROM borrow_forms bf
        JOIN users u ON bf.user_id = u.id
    ";
    
    $base_sql .= $joins;
    
    // --- 1. STATUS FILTER ---
    if ($status_filter !== 'all') {
        if ($status_filter === 'overdue') {
            // Forms that are currently borrowed/approved but past due date
            $where_clauses[] = "(bf.status IN ('approved', 'borrowed') AND bf.expected_return_date < CURDATE())";
        } elseif ($status_filter === 'damaged') {
             // Filter forms where AT LEAST ONE item is damaged
             $where_clauses[] = "bi.item_status = 'damaged'";
        } else {
             // Direct status match for form status
             $where_clauses[] = "bf.status = :filter_status";
             $params[':filter_status'] = $status_filter;
        }
    }
    
    // --- 2. APPARATUS ID FILTER (Filters by apparatus_type.id using borrow_items) ---
    if (!empty($apparatus_id)) {
        $where_clauses[] = "bi.type_id = :apparatus_id";
        $params[':apparatus_id'] = $apparatus_id;
    }
    
    // --- 3. APPARATUS TYPE FILTER ---
    if (!empty($apparatus_type)) {
        $where_clauses[] = "at.apparatus_type = :apparatus_type";
        $params[':apparatus_type'] = $apparatus_type;
    }

    // --- 4. DATE RANGE FILTERS (FIXED: Uses COALESCE to handle NULL borrow_date) ---
    if (!empty($start_date)) {
        // Use borrow_date if available, otherwise fall back to request_date
        $where_clauses[] = "COALESCE(bf.borrow_date, bf.request_date) >= :start_date";
        $params[':start_date'] = $start_date;
    }
    
    if (!empty($end_date)) {
        // Use borrow_date if available, otherwise fall back to request_date
        $where_clauses[] = "COALESCE(bf.borrow_date, bf.request_date) <= :end_date";
        $params[':end_date'] = $end_date;
    }
    
    // --- 5. SEARCH TERM FILTER (Student Name/ID or Apparatus Name) ---
    if (!empty($search_term)) {
        $search_param = '%' . $search_term . '%';
        
        $search_clause = "
            (u.firstname LIKE :search_term_name 
             OR u.lastname LIKE :search_term_name 
             OR bf.user_id = :search_id
             OR at.name LIKE :search_term_name
            )
        ";
        
        $where_clauses[] = $search_clause;
        $params[':search_term_name'] = $search_param;
        // Use the search term as an ID if it's numeric for a more precise match
        $params[':search_id'] = is_numeric($search_term) ? (int)$search_term : 0; 
    }
    
    // Combine WHERE clauses
    if (!empty($where_clauses)) {
        $base_sql .= " WHERE " . implode(' AND ', $where_clauses);
    }
    
    // Group by Form ID to ensure only unique forms are returned, even if multiple items match
    $base_sql .= " GROUP BY bf.id, u.firstname, u.lastname";
    
    // Order by descending ID
    $base_sql .= " ORDER BY bf.id DESC";

    try {
        $query = $conn->prepare($base_sql);
        $query->execute($params);
        $forms = $query->fetchAll(PDO::FETCH_ASSOC);
        
        return $forms;
    } catch (\PDOException $e) {
        error_log("Database Error in getAllFormsFiltered: " . $e->getMessage());
        return [];
    }
}


    public function getAllForms() {
        return $this->getAllFormsFiltered('all', ''); 
    }

    public function isStudentBanned($student_id) {
        $conn = $this->connect();
        $stmt = $conn->prepare("SELECT ban_until_date FROM users WHERE id = :id");
        $stmt->execute([':id' => $student_id]);
        $ban_date_string = $stmt->fetchColumn();
        
        if (!$ban_date_string) {
            return false;
        }

        $current_datetime = new DateTime();
        $ban_datetime = new DateTime($ban_date_string);
        
        return $ban_datetime > $current_datetime;
    }

    public function getStudentActiveTransactions($student_id)
{
    $conn = $this->connect();
    $query = $conn->prepare("
        SELECT * FROM borrow_forms 
        WHERE user_id = :student_id 
        AND status IN ('waiting_for_approval', 'approved', 'borrowed', 'checking', 'overdue')
        ORDER BY created_at DESC
    ");
    $query->bindParam(":student_id", $student_id);
    $query->execute();
    return $query->fetchAll();
}
    
    public function getActiveTransactionCount($student_id) {
        $conn = $this->connect();
        $sql = "SELECT COUNT(*) FROM borrow_forms 
                  WHERE user_id = ? 
                  AND status IN ('waiting_for_approval', 'approved', 'borrowed', 'checking')";
        $stmt = $conn->prepare($sql);
        $stmt->execute([$student_id]);
        return $stmt->fetchColumn();
    }
    
    public function hasOverdueLoansPendingReturn($student_id) {
        $conn = $this->connect();
        $sql = "
            SELECT 1 FROM borrow_forms
            WHERE user_id = :student_id
            AND status IN ('approved', 'borrowed') 
            AND expected_return_date < CURDATE()  
            LIMIT 1
        ";
        $stmt = $conn->prepare($sql);
        $stmt->execute([':student_id' => $student_id]); 
        
        return $stmt->rowCount() > 0;
    }
    public function getStudentTransactions($student_id)
    {
        $conn = $this->connect();
        $query = $conn->prepare("
            SELECT 
                bf.*, 
                u.firstname, 
                u.lastname
            FROM borrow_forms bf
            JOIN users u ON bf.user_id = u.id 
            WHERE bf.user_id = :student_id 
            ORDER BY bf.created_at DESC
        ");
        $query->bindParam(":student_id", $student_id);
        $query->execute();
        return $query->fetchAll();
    }

    // File: classes/Transaction.php

public function getBorrowFormById($form_id) {
    $conn = $this->connect();
    $query = $conn->prepare("
        SELECT 
            bf.id, 
            bf.user_id,          
            bf.form_type, 
            bf.status, 
            bf.request_date,            
            bf.borrow_date, 
            bf.expected_return_date,    
            bf.actual_return_date, 
            bf.staff_remarks,
            bf.is_late_return, 
            bf.staff_remarks AS student_remarks 
        FROM borrow_forms bf
        WHERE bf.id = ?
    ");
    $query->execute([$form_id]);
    return $query->fetch(PDO::FETCH_ASSOC);
}
    
// File: classes/Transaction.php

// File: classes/Transaction.php

public function markAsChecking($form_id, $student_id, $remarks = null) 
{
    $conn = $this->connect();
    $conn->beginTransaction();

    $check_stmt = $conn->prepare("
        SELECT status 
        FROM borrow_forms 
        WHERE id = :form_id 
          AND user_id = :student_id 
          AND status IN ('borrowed', 'approved', 'overdue') 
    ");
    $check_stmt->execute([':form_id' => $form_id, ':student_id' => $student_id]);
    
    $old_status = $check_stmt->fetchColumn(); 

    if ($old_status === false) {
        $conn->rollBack();
        return false; 
    }

    try {
        $stmt_form = $conn->prepare("
            UPDATE borrow_forms 
            SET status='checking', staff_remarks=:remarks, actual_return_date=CURDATE() 
            WHERE id=:form_id
        ");
        $stmt_form->bindParam(':remarks', $remarks);
        $stmt_form->bindParam(':form_id', $form_id);
        $stmt_form->execute();

        $conn->prepare("UPDATE borrow_items SET item_status='checking' WHERE form_id=:form_id")
            ->execute([':form_id' => $form_id]);

        $unit_ids = $this->getFormUnitIds($form_id, $conn);
        
        // Update unit status to 'checking' (omitted for brevity, assume correct)
        if (!$this->updateUnitStatus($unit_ids, 'checking', $conn)) {
            $conn->rollBack(); return false;
        }
        
        $this->addLog($form_id, $student_id, 'initiated_return', 'Student requested return verification. Remarks: ' . $remarks, $conn);
        
        // Fetch required details for email *before* commit
        // Must use fresh connection/local variable to ensure data safety if commit fails
        $form_data_for_mail = $this->getBorrowFormById($form_id); 
        $item_list_for_email = $this->getFormItemsForEmail($form_id);
        $student_details_for_mail = $this->getUserDetails($student_id, $conn);

        // Notify Staff (omitted for brevity, assume correct)
        // ... staff notification loop using $this->createNotification($staff_id_to_notify, ...)

        $conn->commit();
        
        // =================================================================
        // >> CRITICAL FIX: EMAIL Return Initiation Confirmation (Checking) <<
        // =================================================================
        $mailer = new Mailer(); 

        $mail_success = $mailer->sendTransactionStatusEmail(
            $student_details_for_mail['email'],
            $student_details_for_mail['firstname'],
            $form_id,
            'checking', // The correct status
            $remarks, // Student's remarks
            $form_data_for_mail['borrow_date'] ?? $form_data_for_mail['request_date'], // Passed as Request Date
            $form_data_for_mail['expected_return_date'], // Passed as Due Date
            $form_data_for_mail['actual_return_date'] ?? date('Y-m-d'), // Passed as Approval Date (Return Date)
            $item_list_for_email  
        );

        if (!$mail_success) {
            error_log("Checking Status Email FAILED for Form #{$form_id}. Check Mailer logs.");
        }
        // =================================================================

        return true;

    } catch (Exception $e) {
        $conn->rollBack();
        error_log("Error marking as checking: " . $e->getMessage());
        return false;
    }
}

    public function getBanUntilDate($student_id) {
        $conn = $this->connect();
        $stmt = $conn->prepare("SELECT ban_until_date FROM users WHERE id = :id");
        $stmt->execute([':id' => $student_id]);
        return $stmt->fetchColumn();
    }

    // --- Inside classes/Transaction.php ---

// 1. Fetch overdue loans
// 1. Fetch overdue loans (FIXED)
public function getOverdueLoansForNotification() {
    $today = date('Y-m-d');
    $conn = $this->connect(); // Ensure we have the connection object

    // SQL now selects the required aliases: user_email, user_name (concatenated), and necessary form data.
    $sql = "SELECT 
                bf.id, 
                bf.expected_return_date, 
                u.email AS user_email,          
                CONCAT(u.firstname, ' ', u.lastname) AS user_name 
            FROM borrow_forms bf
            JOIN users u ON bf.user_id = u.id
            WHERE 
                bf.status = 'borrowed' AND
                bf.expected_return_date < :today AND
                (bf.last_overdue_notice_date IS NULL OR bf.last_overdue_notice_date < :today)";
                
    $query = $conn->prepare($sql);
    $query->bindParam(":today", $today);
    
    if ($query->execute()) {
        $overdue_forms = $query->fetchAll(PDO::FETCH_ASSOC);

        // CRITICAL FIX: Loop through forms and attach the required 'items' array
        foreach ($overdue_forms as &$form) {
            // Re-use the existing method that fetches items for the email template
            $form['items'] = $this->getFormItemsForEmail($form['id']);
        }
        unset($form); // Break the reference for safety

        return $overdue_forms;
    }
    return [];
}

// 2. Log the notice date
public function logOverdueNotice($form_id, $date) {
    $sql = "UPDATE borrow_forms 
            SET last_overdue_notice_date = :date 
            WHERE id = :id";
            
    $query = $this->connect()->prepare($sql);
    $query->bindParam(":date", $date);
    $query->bindParam(":id", $form_id);
    
    return $query->execute();
}
protected function createNotification($userId, $type, $message, $link, $conn)
{
    // Use the provided connection if available, otherwise get a new one
    $used_conn = $conn ?? $this->connect();
    
    // *** FIX: Check if the connection is null before calling prepare() ***
    if ($used_conn === null) {
        error_log("FATAL: Failed to establish database connection for notification system (User ID: {$userId}).");
        return false;
    }
    // *******************************************************************
    
    $stmt = $used_conn->prepare("
        INSERT INTO notifications (user_id, type, message, link, is_read, created_at)
        VALUES (:user_id, :type, :message, :link, 0, NOW())
    ");
    
    return $stmt->execute([
        ':user_id' => $userId,
        ':type' => $type,
        ':message' => $message,
        ':link' => $link
    ]);
}
public function getUserDetails($user_id, $conn) // <-- CHANGED TO PUBLIC
{
    $used_conn = $conn ?? $this->connect();
    $stmt = $used_conn->prepare("SELECT firstname, lastname, email FROM users WHERE id = ?");
    $stmt->execute([$user_id]);
    return $stmt->fetch(PDO::FETCH_ASSOC);
}
public function markNotificationsAsRead(array $notificationIds, $userId)
{
    if (empty($notificationIds)) {
        return true;
    }

    $conn = $this->connect();
    $placeholders = implode(',', array_fill(0, count($notificationIds), '?'));
    
    // Use IN clause to update multiple notifications, and ensure they belong to the user
    $sql = "
        UPDATE notifications 
        SET is_read = 1 
        WHERE id IN ({$placeholders}) AND user_id = ?
    ";
    
    // Parameters: [id1, id2, id3, ..., userId]
    $params = array_merge($notificationIds, [$userId]);

    try {
        $stmt = $conn->prepare($sql);
        return $stmt->execute($params);
    } catch (Exception $e) {
        error_log("Failed to mark notifications as read: " . $e->getMessage());
        return false;
    }
}
// --- ADDED TO Transaction.php ---

/**
 * Retrieves the count of unread notifications for a student, forcing a non-cached read.
 */
public function getUnreadNotificationCount($student_id)
{
    $conn = $this->connect();
    // Use SQL_NO_CACHE to ensure the server reads the latest committed data.
    $sql = "
        SELECT SQL_NO_CACHE COUNT(id) 
        FROM notifications 
        WHERE user_id = :user_id AND is_read = 0
    ";
    $stmt = $conn->prepare($sql);
    $stmt->execute([':user_id' => $student_id]);
    return (int)$stmt->fetchColumn();
}

public function clearNotificationsByFormId($form_id) {
    try {
        $conn = $this->connect(); 
        // 1. Target pending approval and return checking links for STAFF NOTIFICATIONS:
        // E.g., link LIKE '%staff_pending.php?view=X' or similar pattern.
        $link_pattern = "%staff_pending.php%{$form_id}%";
        $stmt = $conn->prepare("
            UPDATE notifications 
            SET is_read = 1 
            WHERE link LIKE :link_pattern AND is_read = 0
        ");
        $stmt->bindParam(':link_pattern', $link_pattern);
        $stmt->execute();
        
        // 2. Also clear student notifications by link, just in case (optional, but safer)
        $student_link_pattern = "%student_view_items.php%{$form_id}%";
        $stmt_student = $conn->prepare("
             UPDATE notifications 
             SET is_read = 1 
             WHERE link LIKE :student_link_pattern AND is_read = 0
        ");
        $stmt_student->bindParam(':student_link_pattern', $student_link_pattern);
        $stmt_student->execute();
        
        return true;
    } catch (PDOException $e) {
        error_log("DB Error clearing notification for form {$form_id}: " . $e->getMessage());
        return false;
    }
}

public function getFormItemsForEmail($form_id)
{
    $conn = $this->connect();
    // This query is similar to getBorrowFormItems but focuses on the apparatus details for display
    $stmt = $conn->prepare("
        SELECT 
            at.name, 
            at.apparatus_type, 
            at.size, 
            at.material, 
            COUNT(bi.id) AS quantity 
        FROM borrow_items bi
        JOIN apparatus_type at ON bi.type_id = at.id
        WHERE bi.form_id = :form_id
        GROUP BY at.id, at.name, at.apparatus_type, at.size, at.material
        ORDER BY at.name
    ");
    $stmt->execute([':form_id' => $form_id]);
    
    // Return the raw array of items for templating flexibility
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

}