<?php
// classes/Mailer.php

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;
use PHPMailer\PHPMailer\SMTP;

class Mailer {
    private $mail;
    private $last_error = '';

    public function __construct() {
        $this->mail = new PHPMailer(true);
        try {
        
            $this->mail->isSMTP();
            $this->mail->Host = 'smtp.gmail.com'; // CORRECT Host for Gmail
            $this->mail->SMTPAuth = true;
            $this->mail->Username = 'jngiglesia@gmail.com'; // Your Gmail address
            $this->mail->Password = 'aezq hfjs fbpl fnew'; // Your 16-digit App Password
            $this->mail->SMTPSecure = PHPMailer::ENCRYPTION_SMTPS; // CORRECT: Use SMTPS
            $this->mail->Port = 465; // CORRECT Port for SMTPS
           

            $this->mail->setFrom('jngiglesia@gmail.com', 'CSM Borrowing System');
            $this->mail->isHTML(true);
        } catch (Exception $e) {
            $this->last_error = "Mailer configuration error: " . $e->getMessage();
            error_log($this->last_error);
        }
    }
    
    /**
     * NEW: Public getter for the last PHPMailer error message.
     * @return string
     */
    public function getError() {
        return $this->mail->ErrorInfo;
    }

    protected function loadTemplate($templateName, array $variables = []) {
        $basePath = __DIR__ . '/../templates/emails/'; 
        $contentPath = $basePath . $templateName . '.html';
        $layoutPath = $basePath . 'base_layout.html';

        if (!file_exists($contentPath) || !file_exists($layoutPath)) {
            error_log("Missing email template file: " . $contentPath);
            return "Error: Template not found."; 
        }

        $content = file_get_contents($contentPath);
        
        $status_class = $variables['STATUS_CLASS'] ?? ''; 

        $content = preg_replace_callback('/{{\s*IF\s+([A-Z_]+)\s*}}(.*?){{\s*ENDIF\s*}}/s', function($matches) use ($variables) {
            $key = strtoupper($matches[1]);
            $content_block = $matches[2];
            return !empty($variables[$key]) ? $content_block : '';
        }, $content);

      
        $pattern = '/{{\s*IF\s+STATUS_CLASS\s*==\s*\'([^\']+)\'\s*}}(.*?){{\s*ELSEIF\s+STATUS_CLASS\s*==\s*\'([^\']+)\'\s*}}(.*?){{\s*ELSEIF\s+STATUS_CLASS\s*==\s*\'([^\']+)\'\s*}}(.*?){{\s*ENDIF\s*}}/s';

        $content = preg_replace_callback($pattern, function($matches) use ($status_class) {
            
            if ($status_class == $matches[1]) {
                return $matches[2]; 
            }
            
            if (isset($matches[3]) && $status_class == $matches[3]) {
                return $matches[4]; 
            }

            if (isset($matches[5]) && $status_class == $matches[5]) {
                return $matches[6];
            }
            
            return ''; 
        }, $content);
        
        $content = preg_replace_callback('/{{\s*IF\s+REMARKS\s*}}(.*?){{\s*ENDIF\s*}}/s', function($matches) use ($variables) {
            $remarks_content = $variables['REMARKS'] ?? '';
            $content_block = $matches[1];
            return !empty($remarks_content) ? $content_block : '';
        }, $content);
        

        foreach ($variables as $key => $value) {
            $upperKey = strtoupper($key);
            $value = is_string($value) ? $value : (string)$value;

            if (strpos($content, "{{ {$upperKey} | UPPERCASE }}") !== false) {
                $content = str_replace('{{ ' . $upperKey . ' | UPPERCASE }}', strtoupper($value), $content);
            }
            
            $content = str_replace('{{ ' . $upperKey . ' }}', $value, $content);
            
            $content = str_replace('**{{ ' . $upperKey . ' }}**', '**' . $value . '**', $content);
        }
        
        $bodyHtml = file_get_contents($layoutPath);
        $bodyHtml = str_replace('{{ BODY_CONTENT }}', $content, $bodyHtml);
        
        foreach ($variables as $key => $value) {
            $upperKey = strtoupper($key);
            $value = is_string($value) ? $value : (string)$value;
            $bodyHtml = str_replace('{{ ' . $upperKey . ' }}', $value, $bodyHtml);
        }
        
        return $bodyHtml;
    }

    public function sendVerificationEmail($recipientEmail, $code) {
        try {
            $verification_code = $code; 
            
            $this->mail->clearAddresses();
            $this->mail->addAddress($recipientEmail);

            $subject = 'Verify Your Account for the CSM Borrowing System';

            $variables = [
                'SUBJECT' => $subject,
                'VERIFICATION_CODE' => $verification_code, 
                'RECIPIENT_NAME' => 'User', 
            ];

            $bodyHtml = $this->loadTemplate('body_verification', $variables); 

            $this->mail->Subject = $subject;
            $this->mail->Body = $bodyHtml;
            $this->mail->AltBody = "Your verification code is: " . $verification_code;

            return $this->mail->send();
        } catch (Exception $e) {
            $this->last_error = $this->mail->ErrorInfo;
            error_log("Verification Email failed for {$recipientEmail}. Mailer Error: {$this->last_error}");
            return false;
        }
    }

 
    public function sendResetCodeEmail($recipientEmail, $code) {
        try {
            $this->mail->clearAddresses();
            $this->mail->addAddress($recipientEmail);

            $subject = 'CSM Borrowing System Password Reset Code';
            
            $variables = [
                'SUBJECT' => $subject,
                'VERIFICATION_CODE' => $code, 
                'RECIPIENT_NAME' => 'User',
            ];
            
            $bodyHtml = $this->loadTemplate('body_verification', $variables); 
            
            $bodyHtml = str_replace('Account Verification', 'Password Reset Code', $bodyHtml);
            $bodyHtml = str_replace('to activate your borrowing privileges:', 'to reset your password. This code is valid for a limited time:', $bodyHtml);
    

            $this->mail->Subject = $subject;
            $this->mail->Body = $bodyHtml;
            $this->mail->AltBody = "Your password reset code is: " . $code; 
            
            

            return $this->mail->send();

        } catch (Exception $e) {
            $this->last_error = $this->mail->ErrorInfo;
            error_log("Reset Code Email failed for {$recipientEmail}. Mailer Error: {$this->last_error}");
            return false;
        }
    }

   
    public function sendRawEmail($recipientEmail, $subject, $bodyHtml) {
        try {
            $this->mail->clearAddresses();
            $this->mail->addAddress($recipientEmail);

            $this->mail->Subject = $subject;
            $this->mail->Body = $bodyHtml;
            $this->mail->AltBody = strip_tags($bodyHtml);

            return $this->mail->send();
        } catch (Exception $e) {
            $this->last_error = $this->mail->ErrorInfo;
            error_log("Raw Email failed for {$recipientEmail}. Error: {$this->last_error}");
            return false;
        }
    }
    

public function sendTransactionStatusEmail($recipientEmail, $recipientName, $formId, $status, $remarks = null, $requestDate = '', $dueDate = '', $approvalDate = '', array $itemsList = []) {
    try {
        $this->mail->clearAddresses();
        $this->mail->addAddress($recipientEmail, $recipientName);

        $statusText = ucwords(str_replace('_', ' ', $status));
        $subject = "Update: Request #{$formId} is {$statusText}";
        $cleanRemarks = $remarks ? nl2br(htmlspecialchars($remarks)) : 'No specific remarks were provided by staff.';
        
        
        $dynamic_message = '';
        $status_css_class = 'status-waiting'; 
        $status_bg = '#fff3cd'; 
        $status_color = '#856404'; 
        $template_file = 'body_status';

        switch ($status) {
            case 'approved':
                $dynamic_message = 'The staff has **APPROVED** your request! You may proceed to collect the apparatus on the scheduled borrow date.';
                $status_css_class = 'status-approved';
                $status_bg = '#d4edda'; 
                $status_color = '#155724'; 
                break;
            case 'rejected':
                $dynamic_message = 'Your request has been **REJECTED**. Please review the remarks below for the reason and submit a new request.';
                $status_css_class = 'status-rejected';
                $status_bg = '#f8d7da'; 
                $status_color = '#721c24'; 
                break;
            case 'waiting_for_approval': 
            case 'returned': 
            case 'damaged': 
            case 'overdue': 
            default:
                $dynamic_message = 'Your submission has been successfully received and is **awaiting staff review**. You will receive another email once your request is approved or rejected.';
                $status_css_class = 'status-waiting'; 
                $status_bg = '#fff3cd';
                $status_color = '#856404'; 
                if ($status == 'rejected' || $status == 'overdue') {
                    $status_bg = '#f8d7da'; $status_color = '#721c24';
                } elseif ($status == 'returned' || $status == 'damaged') {
                    $status_bg = '#d4edda'; $status_color = '#155724';
                    $dynamic_message = "Your transaction has been finalized as **{$statusText}**.";
                }
                break;
        }
        
        
        $finalStatusDisplay = strtoupper($statusText); 
        $statusBoxHtml = "
            <div style=\"background-color: {$status_bg}; color: {$status_color}; padding: 15px; border: 1px solid {$status_color}; border-radius: 4px; font-weight: bold; text-align: center; font-size: 1.1em;\">
                TRANSACTION STATUS: **{$finalStatusDisplay}**
            </div>
        ";
        
        
        $itemTableHtml = '';
        if (!empty($itemsList)) {
            $itemRows = '';
            foreach ($itemsList as $item) {
                $apparatusName = htmlspecialchars($item['name'] ?? 'N/A');
                $apparatusType = htmlspecialchars($item['apparatus_type'] ?? '');
                $quantity = htmlspecialchars($item['quantity'] ?? '1');
                
                $nameDisplay = $apparatusName;
                if (!empty($apparatusType)) {
                    $nameDisplay .= " ({$apparatusType})";
                }

                $itemRows .= '
                    <tr>
                        <td style="padding: 8px 15px; border-bottom: 1px solid #eee;">' . $nameDisplay . '</td>
                        <td style="padding: 8px 15px; border-bottom: 1px solid #eee; text-align: right;">' . $quantity . '</td>
                    </tr>
                ';
            }
            $itemTableHtml = '
                <h3 style="margin-top: 25px; margin-bottom: 10px; color: #333;">Requested Items</h3>
                <table width="100%" cellspacing="0" cellpadding="0" style="border-collapse: collapse; border: 1px solid #ddd; background-color: #f9f9f9;">
                    <thead>
                        <tr>
                            <th style="padding: 10px 15px; text-align: left; border-bottom: 2px solid #ddd; background-color: #e9ecef;">Apparatus Name / Type</th>
                            <th style="padding: 10px 15px; text-align: right; border-bottom: 2px solid #ddd; background-color: #e9ecef; width: 80px;">Quantity</th>
                        </tr>
                    </thead>
                    <tbody>
                        ' . $itemRows . '
                    </tbody>
                </table>
            ';
        }

        
        
        $reqDateFormatted = 'N/A';
        
        if (!empty($requestDate) && ($ts = strtotime($requestDate)) !== false) {
            $reqDateFormatted = date('F j, Y', $ts);
        }

        $dueDateFormatted = 'N/A';
        if (!empty($dueDate) && ($ts = strtotime($dueDate)) !== false) {
            $dueDateFormatted = date('F j, Y', $ts);
        }
        
        $approvalDateFormatted = '';
        if (!empty($approvalDate) && ($ts = strtotime($approvalDate)) !== false) {
            $approvalDateFormatted = date('F j, Y', $ts);
        }
        
        
        $variables = [
            'SUBJECT' => $subject,
            'RECIPIENT_NAME' => $recipientName,
            'FORM_ID' => $formId,
            'STATUS_TEXT' => $statusText, 
            'DYNAMIC_MESSAGE' => $dynamic_message, 
            'STATUS_CLASS' => $status_css_class,
            'REMARKS' => $cleanRemarks,
            
          
            'REQUEST_DATE' => $reqDateFormatted,
            'DUE_DATE' => $dueDateFormatted,
            'APPROVAL_DATE' => $approvalDateFormatted,
            
            'STATUS_BOX_HTML' => $statusBoxHtml, 
            'ITEM_TABLE_HTML' => $itemTableHtml, 
        ];

        $bodyHtml = $this->loadTemplate($template_file, $variables);
        
        $this->mail->Subject = $subject;
        $this->mail->Body = $bodyHtml;
        $this->mail->AltBody = "Your request #{$formId} status has been updated to {$statusText}.";

        return $this->mail->send();
    } catch (Exception $e) {
        $this->last_error = $this->mail->ErrorInfo;
        error_log("Status Email failed for {$recipientEmail} (Form #{$formId}). Mailer Error: {$this->last_error}");
        return false;
    }
}
    
    public function sendReturnConfirmationEmail(
        $recipientEmail, 
        $recipientName, 
        $formId, 
        $condition, 
        $remarks = null,
        $totalFine = 0.0,
        array $returnedItems = [],
        $viewTransactionUrl = ''
    ) {
        try {
            $this->mail->clearAddresses();
            $this->mail->addAddress($recipientEmail, $recipientName);

            $statusText = '';
            
           
            $statusBoxHtml = '';
            switch ($condition) {
                case 'damaged':
                    $statusText = 'RETURNED WITH ISSUE';
                    $statusBoxHtml = '<div style="background-color: #f8d7da; color: #721c24; padding: 15px; border: 1px solid #721c24; border-radius: 4px; font-weight: bold; text-align: center; font-size: 1.1em;">⚠️ Return Processed: DAMAGE/ISSUE NOTED</div>';
                    break;
                case 'late':
                    $statusText = 'RETURN CONFIRMED - LATE';
                    $statusBoxHtml = '<div style="background-color: #ffeeba; color: #856404; padding: 15px; border: 1px solid #856404; border-radius: 4px; font-weight: bold; text-align: center; font-size: 1.1em;">Return Processed: LATE</div>';
                    break;
                case 'good':
                default:
                    $statusText = 'RETURN CONFIRMED';
                    $statusBoxHtml = '<div style="background-color: #d4edda; color: #155724; padding: 15px; border: 1px solid #155724; border-radius: 4px; font-weight: bold; text-align: center; font-size: 1.1em;">Return Complete! All items in GOOD condition.</div>';
                    break;
            }

        
            $itemRowsHtml = '';
            foreach ($returnedItems as $item) {
                $itemCondition = htmlspecialchars($item['condition_on_return'] ?? 'Good');
                $itemConditionDisplay = 'Good';

                if (in_array($itemCondition, ['Damaged', 'Lost'])) {
                    $itemConditionDisplay = '<strong style="color: #b8312d;">' . strtoupper($itemCondition) . '</strong>';
                }

                $itemRowsHtml .= '
                    <tr>
                        <td>' . htmlspecialchars($item['apparatus_name'] ?? 'N/A') . '</td>
                        <td>' . htmlspecialchars($item['quantity'] ?? 1) . '</td>
                        <td>' . $itemConditionDisplay . '</td>
                    </tr>
                ';
            }

           
            $remarksSectionHtml = '';
            if ($condition == 'damaged' || $condition == 'late' || $totalFine > 0) {
                
                $formattedFine = ($totalFine > 0) ? '₱' . number_format($totalFine, 2) : '';
                $cleanRemarks = $remarks ? nl2br(htmlspecialchars($remarks)) : 'No specific remarks were recorded by staff.';

                $remarksSectionHtml .= '<div class="remarks-box">';
                $remarksSectionHtml .= '<h3 style="color: #8d4500; margin-top: 0;">Reasoning & Next Steps</h3>';
                $remarksSectionHtml .= '<p>The following official report was recorded by the staff regarding the condition of your return:</p>';
                $remarksSectionHtml .= '<strong style="display: block; margin-bottom: 10px;">Staff Remarks:</strong>';
                $remarksSectionHtml .= '<div style="border: 1px solid #ddd; padding: 10px; background-color: white; border-radius: 4px;">' . $cleanRemarks . '</div>';
                
                if ($totalFine > 0) {
                    $remarksSectionHtml .= '
                        <p style="margin-top: 15px; padding: 10px; background-color: #f8d7da; border-left: 3px solid #721c24;">
                            <strong style="color: #721c24;">ACTION REQUIRED: A financial liability of ' . $formattedFine . ' has been assessed due to the issue(s) noted above. Please contact the College Office immediately to settle this amount.</strong>
                        </p>
                    ';
                }
                $remarksSectionHtml .= '</div>';
            }
            
            
            $variables = [
                'RECIPIENT_NAME' => $recipientName,
                'FORM_ID' => $formId,
                'VIEW_TRANSACTION_URL' => $viewTransactionUrl,
                'STATUS_BOX_HTML' => $statusBoxHtml,
                'ITEM_ROWS_HTML' => $itemRowsHtml,
                'REMARKS_SECTION_HTML' => $remarksSectionHtml,
                'CONDITION' => $condition, 
            ];
            
            $template_file = 'body_return'; 
            $bodyHtml = $this->loadTemplate($template_file, $variables);
            
            $subject = "Confirmation: Apparatus Return (#{$formId}) - {$statusText}";
            $this->mail->Subject = $subject;
            $this->mail->Body = $bodyHtml;
            $this->mail->AltBody = "Return Confirmation for #{$formId}: {$statusText}.";

            return $this->mail->send();
        } catch (Exception $e) {
            $this->last_error = $this->mail->ErrorInfo;
            error_log("Return Confirmation Email failed for {$recipientEmail} (Form #{$formId}). Mailer Error: {$this->last_error}");
            return false;
        }
    }
    
   
    public function sendOverdueNotice($recipientEmail, $recipientName, $formId, $returnDate, $itemsList) {
        try {
            $this->mail->clearAddresses();
            $this->mail->addAddress($recipientEmail, $recipientName);

            $subject = "URGENT: Apparatus Overdue Notice (#{$formId})";
            $template_file = 'overdue_notice'; 
            $status_css_class = 'status-rejected'; 

            $variables = [
                'SUBJECT' => $subject,
                'RECIPIENT_NAME' => $recipientName,
                'FORM_ID' => $formId,
                'RETURN_DATE' => $returnDate,
                'ITEMS_LIST' => $itemsList,
                'STATUS_CLASS' => $status_css_class,
                'GRACE_PERIOD' => (new DateTime($returnDate))->modify('+1 day')->format('Y-m-d'),
            ];
            
            $bodyHtml = $this->loadTemplate($template_file, $variables);
            
            $this->mail->Subject = $subject;
            $this->mail->Body = $bodyHtml;
            $this->mail->AltBody = "URGENT: Your loan (#{$formId}) is overdue. Expected return date was {$returnDate}.";

            return $this->mail->send();
        } catch (Exception $e) {
            $this->last_error = $this->mail->ErrorInfo;
            error_log("Overdue Email failed for {$recipientEmail} (Form #{$formId}). Mailer Error: {$this->last_error}");
            return false;
        }
    }
}