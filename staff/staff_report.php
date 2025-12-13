<?php
session_start();
require_once "../classes/Transaction.php";

if (!isset($_SESSION['user']) || $_SESSION['user']['role'] !== 'staff') {
    header("Location: ../pages/login.php");
    exit;
}

$transaction = new Transaction();

$mode = $_GET['mode'] ?? 'hub';
$report_view_type = $_GET['report_view_type'] ?? 'all';

function isOverdue($expected_return_date) {
    if (!$expected_return_date) return false;
    $expected_date = new DateTime($expected_return_date);
    $today = new DateTime();
    return $expected_date->format('Y-m-d') < $today->format('Y-m-d');
}

function getStatusBadgeForItem(string $status, bool $is_late_return = false) {
    $clean_status = strtolower(str_replace(' ', '_', $status));
    $display_status = ucfirst(str_replace('_', ' ', $clean_status));
    
    $color_map = [
        'returned' => 'success', 'approved' => 'info', 'borrowed' => 'primary',
        'overdue' => 'danger', 'damaged' => 'danger', 'rejected' => 'secondary',
        'waiting_for_approval' => 'warning', 'lost' => 'dark', 'checking' => 'info'
    ];
    $color = $color_map[$clean_status] ?? 'secondary';
    
    if ($clean_status === 'returned' && $is_late_return) {
        $color = 'danger';
        $display_status = 'Returned (LATE)';
    } elseif ($clean_status === 'damaged') {
        $display_status = 'Damaged';
    } elseif ($clean_status === 'rejected') {
        $display_status = 'Rejected';
    }

    return '<span class="badge bg-' . $color . '">' . $display_status . '</span>';
}

function getDetailedItemRows(array $forms, $transaction) {
    $rows = [];
    foreach ($forms as $form) {
        $form_id = $form['id'];
        $detailed_items = $transaction->getFormItems($form_id);

        if (empty($detailed_items)) {
            $detailed_items = [
                ['name' => '-',
                'quantity' => 1,
                'item_status' => $form['status'], 
                'is_late_return' => $form['is_late_return'] ?? 0]
            ];
        }

        foreach ($detailed_items as $index => $item) {
            
            $item_status = strtolower($item['item_status'] ?? $form['status']);
            $is_late_return = $item['is_late_return'] ?? ($form['is_late_return'] ?? 0);
            
            $row = [
                'form_id' => $form['id'],
                'student_id' => $form['user_id'],
                'borrower_name' => htmlspecialchars($form['firstname'] . ' ' . $form['lastname']),
                'form_type' => htmlspecialchars(ucfirst($form['form_type'])),
                
                'status_badge' => getStatusBadgeForItem($item_status, (bool)$is_late_return),
                
                'borrow_date' => htmlspecialchars($form['borrow_date'] ?? 'N/A'),
                'expected_return' => htmlspecialchars($form['expected_return_date'] ?? 'N/A'),
                'actual_return' => htmlspecialchars($form['actual_return_date'] ?? '-'),
                
                'apparatus' => htmlspecialchars($item['name'] ?? '-') . ' (x' . ($item['quantity'] ?? 1) . ')',
                
                'is_first_item' => ($index === 0),
            ];
            $rows[] = $row;
        }
    }
    return $rows;
}


$allApparatus = $transaction->getAllApparatus();
$allForms = $transaction->getAllForms();

$apparatus_filter_id = $_GET['apparatus_id'] ?? '';
$start_date = $_GET['start_date'] ?? '';
$end_date = $_GET['end_date'] ?? '';
$status_filter = $_GET['status_filter'] ?? '';
$form_type_filter = $_GET['form_type_filter'] ?? '';
$type_filter = $_GET['type_filter'] ?? '';

$filteredForms = $allForms;
$filteredApparatus = $allApparatus;

if ($type_filter) {
    $type_filter_lower = strtolower($type_filter);
    $filteredApparatus = array_filter($filteredApparatus, fn($a) =>
        isset($a['apparatus_type']) && strtolower(trim($a['apparatus_type'])) === $type_filter_lower
    );
}

if ($start_date) {
    $start_dt = new DateTime($start_date);
    $filteredForms = array_filter($filteredForms, fn($f) => (new DateTime($f['created_at']))->format('Y-m-d') >= $start_dt->format('Y-m-d'));
}
if ($end_date) {
    $end_dt = new DateTime($end_date);
    $filteredForms = array_filter($filteredForms, fn($f) => (new DateTime($f['created_at']))->format('Y-m-d') <= $end_dt->format('Y-m-d'));
}
if ($apparatus_filter_id) {
    $apparatus_filter_id = (string)$apparatus_filter_id;
    $forms_with_apparatus = [];
    foreach ($filteredForms as $form) {
        $items = $transaction->getFormItems($form['id']);
        foreach ($items as $item) {
            if ((string)$item['apparatus_id'] === $apparatus_filter_id) {
                $forms_with_apparatus[] = $form;
                break;
            }
        }
    }
    $filteredForms = $forms_with_apparatus;
}
if ($form_type_filter) {
    $form_type_filter = strtolower($form_type_filter);
    $filteredForms = array_filter($filteredForms, fn($f) => strtolower(trim($f['form_type'])) === $form_type_filter);
}
if ($status_filter) {
    $status_filter = strtolower($status_filter);
    if ($status_filter === 'overdue') {
        $filteredForms = array_filter($filteredForms, fn($f) => ($f['status'] === 'borrowed' || $f['status'] === 'approved') && isOverdue($f['expected_return_date']));
    } elseif ($status_filter === 'late_returns') {
        $filteredForms = array_filter($filteredForms, fn($f) => $f['status'] === 'returned' && ($f['is_late_return'] ?? 0) == 1);
    } elseif ($status_filter === 'returned') {
        $filteredForms = array_filter($filteredForms, fn($f) => $f['status'] === 'returned' && ($f['is_late_return'] ?? 0) == 0);
    } elseif ($status_filter === 'borrowed_reserved') {
        $filteredForms = array_filter($filteredForms, fn($f) => $f['status'] !== 'waiting_for_approval' && $f['status'] !== 'rejected');
    } elseif ($status_filter !== 'all') {
        $filteredForms = array_filter($filteredForms, fn($f) => strtolower(str_replace('_', ' ', $f['status'])) === strtolower(str_replace('_', ' ', $status_filter)));
    }
}

$detailedItemRows = getDetailedItemRows($filteredForms, $transaction);

$totalForms = count($allForms);
$pendingForms = count(array_filter($allForms, fn($f) => $f['status'] === 'waiting_for_approval'));
$reservedForms = count(array_filter($allForms, fn($f) => $f['status'] === 'approved'));
$borrowedForms = count(array_filter($allForms, fn($f) => $f['status'] === 'borrowed'));
$returnedForms = count(array_filter($allForms, fn($f) => $f['status'] === 'returned'));
$damagedForms = count(array_filter($allForms, fn($f) => $f['status'] === 'damaged'));

$overdueFormsList = array_filter($allForms, fn($f) =>
    ($f['status'] === 'borrowed' || $f['status'] === 'approved') && isOverdue($f['expected_return_date'])
);
$overdueFormsCount = count($overdueFormsList);

$totalApparatusCount = 0;
$availableApparatusCount = 0;
$damagedApparatusCount = 0;
$lostApparatusCount = 0;
foreach ($allApparatus as $app) {
    $totalApparatusCount += (int)($app['total_stock'] ?? 0);
    $availableApparatusCount += (int)($app['available_stock'] ?? 0);
    $damagedApparatusCount += (int)($app['damaged_stock'] ?? 0);
    $lostApparatusCount += (int)($app['lost_stock'] ?? 0);
}

$uniqueApparatusTypes = array_unique(array_column($allApparatus, 'apparatus_type'));

?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Staff Reports Hub - WMSU CSM</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0-beta3/css/all.min.css" rel="stylesheet">
    <script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
    <style>
        :root{--msu-red:#A40404;--msu-red-dark:#820303;--msu-blue:#007bff;--sidebar-width:280px;--header-height:60px;--student-logout-red:#C62828;--base-font-size:15px;--main-text:#333;--label-bg:#e9ecef;--card-background:#fcfcfc}
        body{font-family:'Segoe UI',Tahoma,Geneva,Verdana,sans-serif;background:#f5f6fa;min-height:100vh;display:flex;padding:0;margin:0;font-size:var(--base-font-size);overflow-x:hidden}
        .menu-toggle{position:fixed;top:15px;left:calc(var(--sidebar-width) + 20px);z-index:1060;background:var(--msu-red);color:white;border:none;border-radius:6px;font-size:1.2rem;box-shadow:0 2px 5px rgba(0,0,0,0.2);transition:left .3s ease;display:flex;justify-content:center;align-items:center;width:44px;height:44px}
        .sidebar.closed{left:calc(var(--sidebar-width) * -1)}
        .sidebar.closed~.menu-toggle{left:20px}
        .sidebar.closed~.top-header-bar{left:0}
        .sidebar.closed~.main-content{margin-left:0;width:100%}
        .sidebar-backdrop{position:fixed;top:0;left:0;right:0;bottom:0;background-color:rgba(0,0,0,0.5);z-index:1000;display:none;opacity:0;transition:opacity .3s ease}
        .sidebar.active~.sidebar-backdrop{display:block;opacity:1}
        .top-header-bar{position:fixed;top:0;left:var(--sidebar-width);right:0;height:var(--header-height);background-color:#fff;border-bottom:1px solid #ddd;box-shadow:0 2px 5px rgba(0,0,0,0.05);display:flex;align-items:center;justify-content:flex-end;padding:0 30px;z-index:1000;transition:left .3s ease}
        .notification-bell-container{position:relative;list-style:none;padding:0;margin:0}
        .notification-bell-container .nav-link{padding:.5rem .5rem;color:var(--main-text)}
        .notification-bell-container .badge-counter{position:absolute;top:5px;right:0;font-size:.8em;padding:.35em .5em;background-color:#ffc107;color:var(--main-text);font-weight:bold}
        .dropdown-menu{min-width:320px;/* Increased width for readability */ padding:0}
        .dropdown-item{padding:10px 15px;white-space:normal;transition:background-color .1s}
        .dropdown-item:hover{background-color:#f5f5f5}
        .mark-all-link{cursor:pointer;color:var(--main-text);font-weight:600;padding:8px 15px;display:block;text-align:center;border-top:1px solid #eee;border-bottom:1px solid #eee}
        .sidebar{width:var(--sidebar-width);min-width:var(--sidebar-width);height:100vh;background-color:var(--msu-red);color:white;padding:0;box-shadow:2px 0 5px rgba(0,0,0,0.2);position:fixed;top:0;left:0;display:flex;flex-direction:column;z-index:1010;transition:left .3s ease}
        .sidebar-header{text-align:center;padding:25px 15px;font-size:1.3rem;font-weight:700;line-height:1.2;color:#fff;border-bottom:1px solid rgba(255,255,255,0.4);margin-bottom:25px}
        .sidebar-header img{max-width:100px;height:auto;margin-bottom:15px}
        .sidebar-header .title{font-size:1.4rem;line-height:1.1}
        .sidebar-nav{flex-grow:1}
        .sidebar-nav .nav-link{color:white;padding:18px 25px;font-size:1.05rem;font-weight:600;transition:background-color .2s;border-left:none!important}
        .sidebar-nav .nav-link:hover{background-color:var(--msu-red-dark)}
        .sidebar-nav .nav-link.active{background-color:var(--msu-red-dark)}
        .logout-link{margin-top:auto;border-top:1px solid rgba(255,255,255,0.1);width:100%;background-color:var(--msu-red)}
        .logout-link .nav-link{display:flex;align-items:center;justify-content:flex-start;background-color:var(--student-logout-red)!important;color:white!important;padding:18px 25px;border-radius:0;text-decoration:none;font-weight:600;font-size:1.05rem;transition:background .3s}
        .logout-link .nav-link:hover{background-color:var(--msu-red-dark)!important}
        .main-content{margin-left:var(--sidebar-width);flex-grow:1;padding:30px;padding-top:calc(var(--header-height) + 30px);width:calc(100% - var(--sidebar-width));transition:margin-left .3s ease,width .3s ease}
        .content-area{background:#fff;border-radius:12px;padding:30px 40px;box-shadow:0 5px 15px rgba(0,0,0,0.1)}
        .page-header{color:#333;border-bottom:2px solid var(--msu-red);padding-bottom:15px;margin-bottom:30px;font-weight:600;font-size:2rem}
        .report-section{border:1px solid #e0e0e0;border-radius:8px;padding:25px;margin-bottom:35px;background:#fff;box-shadow:0 5px 15px rgba(0,0,0,0.05)}
        .report-section h3{color:var(--msu-red);padding-bottom:10px;border-bottom:1px dashed #ddd;margin-bottom:25px;font-weight:600;font-size:1.5rem}
        .stat-card{display:flex;align-items:center;background:#fff;border:1px solid #e0e0e0;border-radius:8px;padding:15px 20px;box-shadow:0 2px 4px rgba(0,0,0,0.05);transition:all .2s;height:100%}
        .stat-icon{display:flex;align-items:center;justify-content:center;width:50px;height:50px;border-radius:50%;font-size:1.4rem;color:white;margin-right:15px;flex-shrink:0}
        .stat-value{font-size:1.6rem;font-weight:700;line-height:1.1;margin-bottom:3px}
        .stat-label{font-size:.9rem;color:#6c757d;font-weight:500;white-space:normal;overflow:hidden;text-overflow:ellipsis}
        .bg-light-gray{background-color:#f9f9f9!important}
        .border-danger{border-left:5px solid var(--student-logout-red)!important}
        .bg-dark-monochrome{background-color:#343a40}
        .print-stat-table-container{display:none}
        .table-responsive{border-radius:8px;overflow-x:auto;box-shadow:0 2px 10px rgba(0,0,0,0.05);margin-top:25px}
        .table{min-width:1200px;border-collapse:separate}
        .table thead th{background-color:var(--msu-red);color:white;font-weight:700;vertical-align:middle;font-size:1rem;padding:10px 5px;white-space:normal;text-align:center}
        .table tbody td{vertical-align:top;padding:8px 4px;font-size:.95rem;text-align:center;border-bottom:1px solid #e9ecef}
        .table tbody tr.first-item-of-group td{border-top:2px solid #ccc}
        .table tbody tr:first-child.first-item-of-group td{border-top:0}
        .detailed-items-cell{white-space:normal!important;word-break:break-word;overflow:visible;text-align:left!important;padding-left:10px!important}
        .detailed-inventory-table{min-width:1000px}
        .table tbody td .badge{display:inline-block;padding:4px 8px;border-radius:4px;font-weight:700;text-transform:uppercase;font-size:.8rem;line-height:1;white-space:nowrap;border:1px solid transparent}
        .detailed-inventory-table tbody td .badge{background-color:transparent!important;color:var(--main-text)!important;font-weight:500!important;padding:0!important;border-radius:0!important;border:none!important}
        .print-header{display:none}
        .wmsu-logo-print{display:none}
        @media print{
            body{margin:0!important;padding:0!important;background:white!important;color:#000}
            .sidebar,.page-header,.filter-form,.print-summary-footer,.top-header-bar{display:none!important}
            @page{size:A4 portrait;margin:.7cm}
            .print-header{display:flex!important;flex-direction:column;align-items:center;text-align:center;padding-bottom:15px;margin-bottom:25px;border-bottom:3px solid #000}
            .wmsu-logo-print{display:block!important;width:70px;height:auto;margin-bottom:5px}
            .print-header .logo{font-size:.9rem;font-weight:600;margin-bottom:2px;color:#555}
            .print-header h1{font-size:1.5rem;font-weight:700;margin:0;color:#000}
            .report-section{border:none!important;box-shadow:none!important;padding:0;margin-bottom:25px}
            .report-section h3{color:#333!important;border-bottom:1px solid #ccc!important;padding-bottom:5px;margin-bottom:15px;font-size:1.4rem;font-weight:600;page-break-after:avoid;text-align:left}
            .report-section .row{display:none!important}
            .print-stat-table-container{display:block!important;margin-bottom:30px}
            .print-stat-table{width:100%;border-collapse:collapse!important;font-size:.9rem}
            .print-stat-table th,.print-stat-table td{border:1px solid #000!important;padding:8px 10px!important;vertical-align:middle;color:#000;font-size:.9rem;line-height:1.2}
            .print-stat-table th{background-color:#eee!important;font-weight:700;width:70%;text-align:left!important}
            .print-stat-table td{text-align:center;font-weight:700;width:30%;color:#000!important}
            .print-stat-table tr:nth-child(even) td{background-color:#f9f9f9!important}
            body[data-print-view="detailed"] @page{size:A4 landscape}
            .table thead th,.table tbody td{border:1px solid #000!important;padding:6px!important;color:#000!important;vertical-align:top!important;font-size:.85rem!important}
            .table thead th{background-color:#eee!important;font-weight:700!important;white-space:normal}
            .table tbody tr:nth-child(odd){background-color:#f9f9f9!important}
            .table tbody tr:first-child.first-item-of-group td{border-top:1px solid #000!important}
            .table tbody td .badge{color:#000!important;background-color:transparent!important;border:1px solid #000;-webkit-print-color-adjust:exact;print-color-adjust:exact;box-shadow:none!important}
            body[data-print-view="apparatus_list"] @page{size:A4 portrait}
            .detailed-inventory-table{width:100%!important;border-collapse:collapse!important;min-width:unset!important;table-layout:fixed!important;display:table!important}
            .detailed-inventory-table thead,.detailed-inventory-table tbody{display:table-row-group!important}
            .detailed-inventory-table tr{display:table-row!important;border:none!important;box-shadow:none!important;margin-bottom:0!important}
            .detailed-inventory-table thead th,.detailed-inventory-table tbody td{border:1px solid #000!important;padding:8px 6px!important;font-size:.9rem!important;text-align:center!important;vertical-align:middle!important;display:table-cell!important}
            .detailed-inventory-table thead th{background-color:#eee!important;font-weight:700!important}
            .detailed-inventory-table tbody tr td:first-child{text-align:left!important;font-weight:700}
            .detailed-inventory-table tbody tr:nth-child(odd){background-color:#f9f9f9!important}
            .detailed-inventory-table tbody tr:nth-child(even){background-color:#ffffff!important}
            .detailed-inventory-table th:nth-child(1),.detailed-inventory-table td:nth-child(1){width:35%!important}
            .detailed-inventory-table th:nth-child(2),.detailed-inventory-table td:nth-child(2){width:15%!important}
            .detailed-inventory-table th:nth-child(3),.detailed-inventory-table td:nth-child(3){width:10%!important}
            .detailed-inventory-table th:nth-child(4),.detailed-inventory-table td:nth-child(4){width:15%!important}
            .detailed-inventory-table th:nth-child(5),.detailed-inventory-table td:nth-child(5){width:12.5%!important}
            .detailed-inventory-table th:nth-child(6),.detailed-inventory-table td:nth-child(6){width:12.5%!important}
            .print-target{display:none}
            body[data-print-view="detailed"] .print-detailed,body[data-print-view="apparatus_list"] .print-detailed-inventory,body[data-print-view="summary"] .print-summary,body[data-print-view="inventory"] .print-inventory{display:block!important}
            body[data-print-view="all"] .print-target{display:block!important}
            .table *{-webkit-print-color-adjust:exact;print-color-adjust:exact}
            /* Start of Fix: Remove mobile labels in detailed inventory table print view */
            body[data-print-view="apparatus_list"] .detailed-inventory-table td::before,
            body[data-print-view="all"] .detailed-inventory-table td::before {
                content: none !important; /* This is the key change for print view */
            }
            body[data-print-view="apparatus_list"] .detailed-inventory-table tbody tr td:nth-child(1)::before,
            body[data-print-view="all"] .detailed-inventory-table tbody tr td:nth-child(1)::before {
                content: none !important;
            }
            body[data-print-view="apparatus_list"] .detailed-inventory-table tbody tr td,
            body[data-print-view="all"] .detailed-inventory-table tbody tr td {
                text-align: center !important; /* Restore default center alignment */
                padding-left: 6px !important; /* Restore default padding */
            }
            body[data-print-view="apparatus_list"] .detailed-inventory-table tbody tr td:first-child,
            body[data-print-view="all"] .detailed-inventory-table tbody tr td:first-child {
                text-align: left !important; /* Keep first column left-aligned */
            }
            /* End of Fix */
        }
        @media (min-width:993px){.menu-toggle{display:none}}
        @media (max-width:1200px){#report-filter-form .col-md-3{width:50%!important}#report-filter-form .col-md-6{width:100%!important}.stat-card{padding:10px 15px}.stat-icon{width:40px;height:40px;font-size:1.2rem}.stat-value{font-size:1.4rem}.table{min-width:1000px}.table thead th{font-size:.9rem}}
        @media (max-width:992px){.menu-toggle{display:flex;left:20px}.sidebar{left:calc(var(--sidebar-width) * -1);transition:left .3s ease;box-shadow:none;--sidebar-width:250px}.sidebar.active{left:0;box-shadow:2px 0 5px rgba(0,0,0,0.2)}.main-content{margin-left:0;padding-left:15px;padding-right:15px;padding-top:calc(var(--header-height) + 15px)}.top-header-bar{left:0;padding-left:70px;padding-right:15px}.content-area{padding:20px 15px}.page-header{font-size:1.8rem}#report-filter-form .col-md-3,#report-filter-form .col-md-6{width:100%!important;margin-top:10px}#report-filter-form>div:first-child{margin-top:0!important}.d-flex.justify-content-end.align-items-end{flex-direction:column;align-items:stretch!important}.d-flex.justify-content-end.align-items-end .btn{width:100%;margin-left:0!important;margin-right:0!important;margin-bottom:5px}.report-section .row>div{width:50%!important}.report-section .row>div:nth-child(odd){padding-right:.375rem!important}.report-section .row>div:nth-child(even){padding-left:.375rem!important}.report-section .row{margin-left:-.375rem!important;margin-right:-.375rem!important}}
        @media (max-width:768px){.main-content{padding:10px;padding-top:calc(var(--header-height) + 10px)}.content-area{padding:10px}.report-section .row>div{width:100%!important;margin-bottom:15px;padding:0 .75rem!important}.report-section h3{font-size:1.3rem}.table-responsive{overflow-x:hidden}.table{min-width:auto}.table thead{display:none}.table tbody,.table tr,.table td{display:block;width:100%}.table tr{margin-bottom:15px;border:1px solid #ccc;border-left:1px solid #ccc;border-radius:8px;background-color:var(--card-background);box-shadow:0 2px 4px rgba(0,0,0,0.05);padding:0;overflow:hidden}.table tbody tr.first-item-of-group td{border-top:none}.table tbody tr.first-item-of-group{margin-top:15px}.table tbody tr:first-child.first-item-of-group{margin-top:0}.table td{text-align:right!important;padding-left:50%!important;position:relative;border:none;border-bottom:1px solid #eee;padding:10px 10px!important}.table td:last-child{border-bottom:none}.table:not(.detailed-inventory-table) td::before{content:attr(data-label);position:absolute;left:0;width:50%;height:100%;padding:10px;white-space:nowrap;text-align:left;font-weight:600;color:var(--main-text);font-size:.9rem;background-color:transparent;border-right:none;display:flex;align-items:center}.table tbody tr td:nth-child(1){text-align:left!important;padding:10px!important;font-weight:700;color:#6c757d;background-color:#f8f8f8;border-bottom:1px solid #ddd}.table tbody tr td:nth-child(1)::before{content:"Form ";position:static;display:inline;color:#6c757d;font-size:.9rem;padding:0;width:auto;height:auto}.table tbody tr td:nth-child(3){font-size:1rem;font-weight:700;color:var(--main-text)}.table tbody tr td:nth-child(3)::before{content:"Borrower Name";background-color:#f8f8f8;color:#000;font-weight:700}.table tbody tr td .badge{border-radius:0!important;background-color:transparent!important;color:#333!important;font-weight:600!important;padding:0!important;border:none!important}.table tbody tr td:nth-child(9){text-align:left!important;padding-left:10px!important;border-bottom:none}.table tbody tr td:nth-child(9)::before{content:"Items Borrowed";position:static;width:100%;height:auto;background:#f8f8f8;border-right:none;border-bottom:1px solid #eee;display:block;padding:10px;margin-bottom:5px}.detailed-items-cell span{display:block!important;padding:5px 0}.detailed-inventory-table{min-width:auto}.detailed-inventory-table thead{display:none}.detailed-inventory-table tbody,.detailed-inventory-table tr,.detailed-inventory-table td{display:block;width:100%;padding:0}.detailed-inventory-table tr{margin-bottom:15px;border:1px solid #ccc;border-radius:8px;box-shadow:0 2px 4px rgba(0,0,0,0.05);overflow:hidden}.detailed-inventory-table tbody tr td:nth-child(1){text-align:left!important;font-size:1.1rem;color:var(--msu-red);background-color:#f8f8f8}.detailed-inventory-table tbody tr td:nth-child(1)::before{content:"Apparatus Name:";position:static;display:block;width:100%;color:#6c757d;font-size:.9rem}.detailed-inventory-table tbody tr td:nth-child(2)::before{content:"Type"}.detailed-inventory-table tbody tr td:nth-child(3)::before{content:"Total Stock"}.detailed-inventory-table tbody tr td:nth-child(4)::before{content:"Available Stock"}.detailed-inventory-table tbody tr td:nth-child(5)::before{content:"Damaged Stock"}.detailed-inventory-table tbody tr td:nth-child(6)::before{content:"Lost Stock"}}
        @media (max-width:576px){.top-header-bar{padding-left:65px}}
    </style>
</head>
<body data-print-view="<?= htmlspecialchars($report_view_type) ?>">
<button class="menu-toggle" id="menuToggle" aria-label="Toggle navigation menu"><i class="fas fa-bars"></i></button>
<div class="sidebar">
    <div class="sidebar-header">
        <img src="../wmsu_logo/wmsu.png" alt="WMSU Logo" class="img-fluid">
        <div class="title">CSM LABORATORY <br>APPARATUS BORROWING</div>
    </div>
    <div class="sidebar-nav nav flex-column">
        <a class="nav-link" href="staff_dashboard.php"><i class="fas fa-chart-line fa-fw me-2"></i>Dashboard</a>
        <a class="nav-link" href="staff_apparatus.php"><i class="fas fa-vials fa-fw me-2"></i>Apparatus List</a>
        <a class="nav-link" href="staff_pending.php"><i class="fas fa-hourglass-half fa-fw me-2"></i>Pending Approvals</a>
        <a class="nav-link" href="staff_transaction.php"><i class="fas fa-list-alt fa-fw me-2"></i>All Transactions</a>
        <a class="nav-link active" href="staff_report.php"><i class="fas fa-print fa-fw me-2"></i>Generate Reports</a>
    </div>
    <div class="logout-link">
        <a href="../pages/logout.php" class="nav-link"><i class="fas fa-sign-out-alt fa-fw me-2"></i> Logout</a>
    </div>
</div>
<div class="sidebar-backdrop" id="sidebarBackdrop"></div>
<header class="top-header-bar">
    <ul class="navbar-nav mb-2 mb-lg-0">
        <li class="nav-item dropdown notification-bell-container">
            <a class="nav-link dropdown-toggle" href="#" id="alertsDropdown" role="button" data-bs-toggle="dropdown" aria-expanded="false">
                <i class="fas fa-bell fa-lg"></i>
                <span class="badge rounded-pill badge-counter" id="notification-bell-badge" style="display:none;"></span>
            </a>
            <div class="dropdown-menu dropdown-menu-end shadow animated--grow-in" aria-labelledby="alertsDropdown" id="notification-dropdown">
                
                <h6 class="dropdown-header text-center">New Requests</h6>
                
                <a class="dropdown-item text-center small text-muted" href="staff_pending.php">View All Pending Requests</a>
            </div>
        </li>
    </ul>
    </header>
<div class="main-content">
    <div class="content-area">
        <h2 class="page-header">
            <i class="fas fa-print fa-fw me-2 text-secondary"></i> Printable Reports Hub
        </h2>
        
        <div class="print-header">
            <img src="../wmsu_logo/wmsu.png" alt="WMSU Logo" class="img-fluid wmsu-logo-print">
            <div class="logo">WESTERN MINDANAO STATE UNIVERSITY</div>
            <div class="logo">CSM LABORATORY APPARATUS BORROWING SYSTEM</div>
            <h1>
            <?php
                if ($report_view_type === 'summary') echo 'Transaction Status Summary Report';
                elseif ($report_view_type === 'inventory') echo 'Apparatus Inventory Stock Report (Summary)';
                elseif ($report_view_type === 'detailed') echo 'Detailed Transaction History Report';
                elseif ($report_view_type === 'apparatus_list') echo 'Detailed Apparatus Inventory List';
                else echo 'All Reports Hub View';
            ?>
            </h1>
            <p>Generated by Staff: <?= date('F j, Y, g:i a') ?></p>
        </div>
        <div class="d-flex justify-content-between align-items-center mb-4 print-summary-footer">
            <p class="text-muted mb-0">Report Date: <?= date('F j, Y, g:i a') ?></p>
            <button class="btn btn-lg btn-danger btn-print" id="main-print-button">
                <i class="fas fa-print me-2"></i> Print Selected Report
            </button>
        </div>

        <div class="report-section filter-form mb-4">
            <h3><i class="fas fa-filter me-2"></i> Filter Report Data</h3>
            <form method="GET" action="staff_report.php" class="row g-3" id="report-filter-form">
                
                <div class="col-md-3">
                    <label for="report_view_type_select" class="form-label fw-bold">**Select Report View Type**</label>
                    <select name="report_view_type" id="report_view_type_select" class="form-select">
                        <option value="all" <?= ($report_view_type === 'all') ? 'selected' : '' ?>>View/Print: All Sections (Hub View)</option>
                        <option value="summary" <?= ($report_view_type === 'summary') ? 'selected' : '' ?>>View/Print: Transaction Summary Only</option>
                        <option value="inventory" <?= ($report_view_type === 'inventory') ? 'selected' : '' ?>>View/Print: Apparatus Stock Status</option>
                        <option value="apparatus_list" <?= ($report_view_type === 'apparatus_list') ? 'selected' : '' ?>>View/Print: Detailed Apparatus List</option>
                        <option value="detailed" <?= ($report_view_type === 'detailed') ? 'selected' : '' ?>>View/Print: Detailed History</option>
                    </select>
                </div>

                <div class="col-md-3">
                    <label for="apparatus_id" class="form-label">Specific Apparatus (History Filter)</label>
                    <select name="apparatus_id" id="apparatus_id" class="form-select">
                        <option value="">-- All Apparatus --</option>
                        <?php foreach ($allApparatus as $app): ?>
                            <option
                                value="<?= htmlspecialchars($app['id']) ?>"
                                <?= ((string)$apparatus_filter_id === (string)$app['id']) ? 'selected' : '' ?>
                            >
                                <?= htmlspecialchars($app['name']) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                
                <div class="col-md-3">
                    <label for="type_filter" class="form-label">Filter Apparatus Type (List Filter)</label>
                    <select name="type_filter" id="type_filter" class="form-select">
                        <option value="">-- All Types --</option>
                        <?php foreach ($uniqueApparatusTypes as $type): ?>
                            <option
                                value="<?= htmlspecialchars($type) ?>"
                                <?= (strtolower($type_filter) === strtolower($type)) ? 'selected' : '' ?>
                            >
                                <?= htmlspecialchars(ucfirst($type)) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                
                <div class="col-md-3">
                    <label for="form_type_filter" class="form-label">Filter Form Type (History Filter)</label>
                    <select name="form_type_filter" id="form_type_filter" class="form-select">
                        <option value="">-- All Form Types --</option>
                        <option value="borrow" <?= (strtolower($form_type_filter) === 'borrow') ? 'selected' : '' ?>>Direct Borrow</option>
                        <option value="reserved" <?= (strtolower($form_type_filter) === 'reserved') ? 'selected' : '' ?>>Reservation Request</option>
                    </select>
                </div>

                <div class="col-md-3">
                    <label for="status_filter" class="form-label">Filter Status (History Filter)</label>
                    <select name="status_filter" id="status_filter" class="form-select">
                        <option value="">-- All Statuses --</option>
                        <option value="waiting_for_approval" <?= ($status_filter === 'waiting_for_approval') ? 'selected' : '' ?>>Pending Approval</option>
                        <option value="approved" <?= ($status_filter === 'approved') ? 'selected' : '' ?>>Reserved (Approved)</option>
                        <option value="borrowed" <?= ($status_filter === 'borrowed') ? 'selected' : '' ?>>Currently Borrowed</option>
                        <option value="borrowed_reserved" <?= ($status_filter === 'borrowed_reserved') ? 'selected' : '' ?>>All Active/Completed Forms</option>
                        <option value="overdue" <?= ($status_filter === 'overdue') ? 'selected' : '' ?>>** Overdue **</option>
                        <option value="returned" <?= ($status_filter === 'returned') ? 'selected' : '' ?>>Returned (On Time)</option>
                        <option value="late_returns" <?= ($status_filter === 'late_returns') ? 'selected' : '' ?>>Returned (LATE)</option>
                        <option value="damaged" <?= ($status_filter === 'damaged') ? 'selected' : '' ?>>Damaged/Lost</option>
                        <option value="rejected" <?= ($status_filter === 'rejected') ? 'selected' : '' ?>>Rejected</option>
                    </select>
                </div>
                
                <div class="col-md-3">
                    <label for="start_date" class="form-label">Start Date (Form Created)</label>
                    <input type="date" name="start_date" id="start_date" class="form-control"
                                             value="<?= htmlspecialchars($start_date) ?>">
                </div>
                <div class="col-md-3">
                    <label for="end_date" class="form-label">End Date (Form Created)</label>
                    <input type="date" name="end_date" id="end_date" class="form-control"
                                             value="<?= htmlspecialchars($end_date) ?>">
                </div>

                <div class="col-md-3 d-flex align-items-end justify-content-end">
                    <button type="submit" class="btn btn-primary me-2">Apply Filters</button>
                    <a href="staff_report.php" class="btn btn-secondary">Clear</a>
                </div>
            </form>
            <p class="text-muted small mt-2 mb-0">Note: Filters apply to either the **Detailed Transaction History** or **Detailed Apparatus List** based on your selected view type.</p>
        </div>
        
        <div class="report-section print-summary print-target" id="report-summary">
            <h3><i class="fas fa-clipboard-list me-2"></i> Transaction Status Summary</h3>
            
            <div class="row g-3">
                <div class="col-xl-3 col-md-4 col-sm-6 col-12">
                    <div class="stat-card bg-light-gray">
                        <div class="stat-icon bg-secondary"><i class="fas fa-file-alt"></i></div>
                        <div class="stat-body">
                            <div class="stat-value text-dark"><?= $totalForms ?></div>
                            <div class="stat-label">Total Forms</div>
                        </div>
                    </div>
                </div>
                <div class="col-xl-3 col-md-4 col-sm-6 col-12">
                    <div class="stat-card bg-light-gray">
                        <div class="stat-icon bg-warning"><i class="fas fa-hourglass-half"></i></div>
                        <div class="stat-body">
                            <div class="stat-value text-warning"><?= $pendingForms ?></div>
                            <div class="stat-label">Pending Approval</div>
                        </div>
                    </div>
                </div>
                <div class="col-xl-3 col-md-4 col-sm-6 col-12">
                    <div class="stat-card bg-light-gray">
                        <div class="stat-icon bg-info"><i class="fas fa-book-reader"></i></div>
                        <div class="stat-body">
                            <div class="stat-value text-info"><?= $reservedForms ?></div>
                            <div class="stat-label">Reserved (Approved)</div>
                        </div>
                    </div>
                </div>
                <div class="col-xl-3 col-md-4 col-sm-6 col-12">
                    <div class="stat-card bg-light-gray">
                        <div class="stat-icon bg-primary"><i class="fas fa-hand-holding"></i></div>
                        <div class="stat-body">
                            <div class="stat-value text-primary"><?= $borrowedForms ?></div>
                            <div class="stat-label">Currently Borrowed</div>
                        </div>
                    </div>
                </div>
                <div class="col-xl-3 col-md-4 col-sm-6 col-12">
                    <div class="stat-card bg-light-gray border-danger">
                        <div class="stat-icon bg-danger"><i class="fas fa-exclamation-triangle"></i></div>
                        <div class="stat-body">
                            <div class="stat-value text-danger"><?= $overdueFormsCount ?></div>
                            <div class="stat-label">Overdue (Active)</div>
                        </div>
                    </div>
                </div>
                <div class="col-xl-3 col-md-4 col-sm-6 col-12">
                    <div class="stat-card bg-light-gray">
                        <div class="stat-icon bg-success"><i class="fas fa-check-circle"></i></div>
                        <div class="stat-body">
                            <div class="stat-value text-success"><?= $returnedForms ?></div>
                            <div class="stat-label">Successfully Returned</div>
                        </div>
                    </div>
                </div>
                <div class="col-xl-3 col-md-4 col-sm-6 col-12">
                    <div class="stat-card bg-light-gray">
                        <div class="stat-icon bg-dark-monochrome"><i class="fas fa-times-circle"></i></div>
                        <div class="stat-body">
                            <div class="stat-value text-dark"><?= $damagedForms ?></div>
                            <div class="stat-label">Damaged/Lost Forms</div>
                        </div>
                    </div>
                </div>
            </div>
            
            <div class="print-stat-table-container">
                <table class="print-stat-table">
                    <thead>
                        <tr><th>Status Description</th><th>Count</th></tr>
                    </thead>
                    <tbody>
                        <tr><th>Total Forms</th><td class="text-dark"><?= $totalForms ?></td></tr>
                        <tr><th>Pending Approval</th><td class="text-warning"><?= $pendingForms ?></td></tr>
                        <tr><th>Reserved (Approved)</th><td class="text-info"><?= $reservedForms ?></td></tr>
                        <tr><th>Currently Borrowed</th><td class="text-primary"><?= $borrowedForms ?></td></tr>
                        <tr><th>Overdue (Active)</th><td class="text-danger"><?= $overdueFormsCount ?></td></tr>
                        <tr><th>Successfully Returned</th><td class="text-success"><?= $returnedForms ?></td></tr>
                        <tr><th>Damaged/Lost Forms</th><td class="text-danger"><?= $damagedForms ?></td></tr>
                    </tbody>
                </table>
            </div>
            
        </div>
        
        <div class="report-section print-inventory print-target" id="report-inventory">
            <h3><i class="fas fa-flask me-2"></i> Apparatus Inventory Stock Status (Summary)</h3>
            
            <div class="row g-3">
                <div class="col-md-4 col-12">
                    <div class="stat-card bg-light-gray">
                        <div class="stat-icon bg-secondary"><i class="fas fa-boxes"></i></div>
                        <div class="stat-body">
                            <div class="stat-value text-dark"><?= $totalApparatusCount ?></div>
                            <div class="stat-label">Total Inventory Units</div>
                        </div>
                    </div>
                </div>
                <div class="col-md-4 col-12">
                    <div class="stat-card bg-light-gray">
                        <div class="stat-icon bg-success"><i class="fas fa-box-open"></i></div>
                        <div class="stat-body">
                            <div class="stat-value text-success"><?= $availableApparatusCount ?></div>
                            <div class="stat-label">Units Available for Borrowing</div>
                        </div>
                    </div>
                </div>
                <div class="col-md-4 col-12">
                    <div class="stat-card bg-light-gray">
                        <div class="stat-icon bg-danger"><i class="fas fa-trash-alt"></i></div>
                        <div class="stat-body">
                            <div class="stat-value text-danger"><?= $damagedApparatusCount + $lostApparatusCount ?></div>
                            <div class="stat-label">Units Unavailable (Damaged/Lost)</div>
                        </div>
                    </div>
                </div>
            </div>
            <p class="text-muted small mt-3">*Note: Units marked Unavailable are not available for borrowing until their stock count is adjusted.</p>
            
            <div class="print-stat-table-container">
                <table class="print-stat-table">
                    <thead>
                        <tr><th>Inventory Metric</th><th>Units</th></tr>
                    </thead>
                    <tbody>
                        <tr><th>Total Inventory Units</th><td class="text-dark"><?= $totalApparatusCount ?></td></tr>
                        <tr><th>Units Available for Borrowing</th><td class="text-success"><?= $availableApparatusCount ?></td></tr>
                        <tr><th>Units Unavailable (Damaged/Lost)</th><td class="text-danger"><?= $damagedApparatusCount + $lostApparatusCount ?></td></tr>
                    </tbody>
                </table>
                <p class="text-muted small mt-3">*Note: Units marked Unavailable are not available for borrowing until their stock count is adjusted.</p>
            </div>
        </div>

        <div class="report-section print-detailed-inventory print-target" id="report-apparatus-list">
            <h3><i class="fas fa-list-ul me-2"></i> Detailed Apparatus List (Filtered: <?= count($filteredApparatus) ?> items)</h3>
            <div class="table-responsive">
                <table class="table table-striped table-sm align-middle detailed-inventory-table">
                    <thead>
                        <tr>
                            <th>Apparatus Name</th>
                            <th>Type</th>
                            <th>Total Stock</th>
                            <th>Available Stock</th>
                            <th>Damaged Stock</th>
                            <th>Lost Stock</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php
                        if (!empty($filteredApparatus)):
                            foreach ($filteredApparatus as $app): ?>
                                <tr>
                                    <td data-label="Apparatus Name" class="text-start"><strong><?= htmlspecialchars($app['name']) ?></strong></td>
                                    <td data-label="Type"><?= htmlspecialchars(ucfirst($app['apparatus_type'] ?? 'N/A')) ?></td>
                                    <td data-label="Total Stock"><?= (int)($app['total_stock'] ?? 0) ?></td>
                                    <td data-label="Available Stock">
                                        <?= (int)($app['available_stock'] ?? 0) ?>
                                    </td>
                                    <td data-label="Damaged Stock">
                                        <?= (int)($app['damaged_stock'] ?? 0) ?>
                                    </td>
                                    <td data-label="Lost Stock">
                                        <?= (int)($app['lost_stock'] ?? 0) ?>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        <?php else: ?>
                            <tr><td colspan="6" class="text-muted text-center">No apparatus match the current filter criteria.</td></tr>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
        <div class="report-section print-detailed print-target" id="report-detailed-table">
            <h3><i class="fas fa-history me-2"></i> Detailed Transaction History (Filtered: <?= count($detailedItemRows) ?> Items)</h3>
            <div class="table-responsive">
                <table class="table table-striped table-sm align-middle">
                    <thead>
                        <tr>
                            <th>Form ID</th>
                            <th>Student ID</th>
                            <th>Borrower Name</th>
                            <th>Type</th>
                            <th>Status</th>
                            <th>Borrow Date</th>
                            <th>Expected Return</th>
                            <th>Actual Return</th>
                            <th>Items Borrowed</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php
                        if (!empty($detailedItemRows)):
                            foreach ($detailedItemRows as $row): ?>
                                <tr class="<?= $row['is_first_item'] ? 'first-item-of-group' : '' ?>">
                                    <td data-label="Form ID:"><?= $row['form_id'] ?></td>
                                    <td data-label="Student ID:"><?= $row['student_id'] ?></td>
                                    <td data-label="Borrower Name:" class="text-start">
                                        <strong style="color: var(--main-text);"><?= $row['borrower_name'] ?></strong>
                                    </td>
                                    <td data-label="Type:"><?= $row['form_type'] ?></td>
                                    <td data-label="Status:"><?= $row['status_badge'] ?></td>
                                    <td data-label="Borrow Date:"><?= $row['borrow_date'] ?></td>
                                    <td data-label="Expected Return:"><?= $row['expected_return'] ?></td>
                                    <td data-label="Actual Return:"><?= $row['actual_return'] ?></td>
                                    <td data-label="Items Borrowed:" class="detailed-items-cell">
                                        <span><?= $row['apparatus'] ?></span>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        <?php else: ?>
                            <tr><td colspan="9" class="text-muted text-center">No transactions match the current filter criteria.</td></tr>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
        
    </div>
</div>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
<script>
    
    // --- JAVASCRIPT FOR STAFF NOTIFICATION LOGIC ---
    
    // 1. Function to handle clicking a notification link
    window.handleNotificationClick = function(event, element, notificationId) {
        event.preventDefault(); 
        const linkHref = element.getAttribute('href');

        $.post('../api/mark_notification_as_read.php', { notification_id: notificationId, role: 'staff' }, function(response) {
            if (response.success) {
                window.location.href = linkHref;
            } else {
                console.error("Failed to mark notification as read.");
                window.location.href = linkHref; 
            }
        }).fail(function() {
            console.error("API call failed.");
            window.location.href = linkHref;
        });
    };

    // 2. Function to mark ALL staff notifications as read
    window.markAllStaffAsRead = function() {
        $.post('../api/mark_notification_as_read.php', { mark_all: true, role: 'staff' }, function(response) {
            if (response.success) {
                // Instead of full reload, just re-fetch to update the UI cleanly
                fetchStaffNotifications(); 
            } else {
                alert("Failed to clear all notifications.");
                console.error("Failed to mark all staff notifications as read.");
            }
        }).fail(function() {
            console.error("API call failed.");
        });
    };
    
    // 3. Function to fetch the count and populate the dropdown
    function fetchStaffNotifications() {
        const apiPath = '../api/get_notifications.php'; 

        $.getJSON(apiPath, function(response) { 
            
            const unreadCount = response.count; 
            const notifications = response.alerts || []; 
            
            const $badge = $('#notification-bell-badge');
            const $dropdown = $('#notification-dropdown');
            const $header = $dropdown.find('.dropdown-header');
            
            // Find and detach the static View All link
            const $viewAllLink = $dropdown.find('a[href="staff_pending.php"]').detach();
            
            // Clear previous dynamic content
            // We clear everything between the header and the View All link
            $dropdown.children().not($header).not($viewAllLink).remove();
            
            // Update badge display
            $badge.text(unreadCount);
            $badge.toggle(unreadCount > 0); 
            
            
            let contentToInsert = [];
            
            if (notifications.length > 0) {
                
                // A. Mark All button (Must be inserted first)
                if (unreadCount > 0) {
                     contentToInsert.push(`
                            <a class="dropdown-item text-center small text-muted dynamic-notif-item mark-all-btn-wrapper" href="#" onclick="event.preventDefault(); window.markAllStaffAsRead();">
                                <i class="fas fa-check-double me-1"></i> Mark All ${unreadCount} as Read
                            </a>
                        `);
                }
                
                // B. Individual Notifications
                notifications.slice(0, 5).forEach(notif => {
                    
                    let iconClass = 'fas fa-info-circle text-info'; 
                    if (notif.type.includes('form_pending')) {
                            iconClass = 'fas fa-hourglass-half text-warning';
                    } else if (notif.type.includes('checking')) {
                            iconClass = 'fas fa-redo text-primary';
                    }
                    
                    const itemClass = notif.is_read == 0 ? 'fw-bold' : 'text-muted';

                    contentToInsert.push(`
                        <a class="dropdown-item d-flex align-items-center dynamic-notif-item" 
                            href="${notif.link}"
                            data-id="${notif.id}"
                            onclick="handleNotificationClick(event, this, ${notif.id})">
                            <div class="me-3"><i class="${iconClass} fa-fw"></i></div>
                            <div>
                                <div class="small text-gray-500">${notif.created_at.split(' ')[0]}</div>
                                <span class="${itemClass}">${notif.message}</span>
                            </div>
                        </a>
                    `);
                });
                
            } else {
                // Display a "No Alerts" message
                contentToInsert.push(`
                    <a class="dropdown-item text-center small text-muted dynamic-notif-item">No New Notifications</a>
                `);
            }
            
            // 4. Insert all dynamic content (Mark All + notifications) after the header
            $header.after(contentToInsert.join(''));
            
            // 5. Re-append the 'View All' link to the end of the dropdown
            $dropdown.append($viewAllLink);
            

        }).fail(function(jqXHR, textStatus, errorThrown) {
            console.error("Error fetching staff notifications:", textStatus, errorThrown);
            $('#notification-bell-badge').text('0').hide();
        });
    }
    // --- END JAVASCRIPT FOR STAFF NOTIFICATION LOGIC ---


    document.addEventListener('DOMContentLoaded', () => {
        // --- Sidebar Activation ---
        const path = window.location.pathname.split('/').pop() || 'staff_dashboard.php';
        const links = document.querySelectorAll('.sidebar .nav-link');
        
        links.forEach(link => {
            const linkPath = link.getAttribute('href').split('/').pop();
            
            if (linkPath === path) {
                link.classList.add('active');
            } else {
                 link.classList.remove('active');
            }
        });
        
        // --- Mobile Toggle Logic (Simplified for brevity) ---
        const menuToggle = document.getElementById('menuToggle');
        const sidebar = document.querySelector('.sidebar');
        const mainContent = document.querySelector('.main-content'); 

        if (menuToggle && sidebar) {
            menuToggle.addEventListener('click', () => {
                sidebar.classList.toggle('active');
                if (window.innerWidth <= 992) {
                    const isActive = sidebar.classList.contains('active');
                    if (isActive) {
                        mainContent.style.pointerEvents = 'none';
                        mainContent.style.opacity = '0.5';
                    } else {
                        mainContent.style.pointerEvents = 'auto';
                        mainContent.style.opacity = '1';
                    }
                }
            });
            
            mainContent.addEventListener('click', () => {
                if (sidebar.classList.contains('active') && window.innerWidth <= 992) {
                    sidebar.classList.remove('active');
                    mainContent.style.pointerEvents = 'auto';
                    mainContent.style.opacity = '1';
                }
            });
            
            sidebar.addEventListener('click', (e) => {
                e.stopPropagation();
            });
        }
        
        // --- Filter Submission Logic ---
        const statusFilter = document.getElementById('statusFilter');
        const form = document.getElementById('transactionFilterForm');

        if (statusFilter && form) {
            statusFilter.addEventListener('change', function() {
                form.submit();
            });
        }
        
        // --- Report View Handler ---
        document.getElementById('report_view_type_select').addEventListener('change', updateHubView);
        document.getElementById('main-print-button').addEventListener('click', handlePrint);
        
        // --- Notification Initialization ---
        fetchStaffNotifications();
        setInterval(fetchStaffNotifications, 30000); 
    });

    // --- REPORT UTILITY FUNCTIONS ---
    function handlePrint() {
        const viewType = document.getElementById('report_view_type_select').value;
        document.body.setAttribute('data-print-view', viewType);
        window.print();
        setTimeout(() => {
            document.body.removeAttribute('data-print-view');
        }, 100);
    }
    
    function updateHubView() {
        const viewType = document.getElementById('report_view_type_select').value;
        const sections = ['summary', 'inventory', 'apparatus-list', 'detailed-table'];
        const printButton = document.getElementById('main-print-button');
        
        // Hide all sections first
        sections.forEach(id => {
            const el = document.getElementById(`report-${id}`);
            if (el) el.style.display = 'none';
        });

        // Show the selected section(s) and update the print button text
        if (viewType === 'all') {
            sections.forEach(id => {
                const el = document.getElementById(`report-${id}`);
                if (el) el.style.display = 'block';
            });
            printButton.textContent = 'Print All Sections (Hub View)';
        } else if (viewType === 'summary' || viewType === 'inventory' || viewType === 'detailed' || viewType === 'apparatus_list') {
            const targetId = viewType === 'detailed' ? 'detailed-table' : (viewType === 'apparatus_list' ? 'apparatus-list' : viewType);
            const el = document.getElementById(`report-${targetId}`);
            if (el) el.style.display = 'block';

            // Update button text
            let text = 'Print Report';
            if (viewType === 'summary') text = 'Print Transaction Summary';
            else if (viewType === 'inventory') text = 'Print Inventory Stock Status';
            else if (viewType === 'apparatus_list') text = 'Print Detailed Apparatus List';
            else if (viewType === 'detailed') text = 'Print Filtered Detailed History';
            
            printButton.textContent = text;
        }
    }
</script>
</body>
</html>