<?php
error_reporting(E_ALL);
ini_set('display_errors', 1);
session_start();
require '../includes/db.php';
require '../customer/send_email.php';
require_once 'invoice_functions.php';

if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['action']) && $_POST['action'] == 'mark_step_complete') {
    header('Content-Type: application/json');
    $qtn_id = intval($_POST['qtn_id']);
    $step_name = mysqli_real_escape_string($conn, $_POST['step_name']);
    $staff_id = intval($_POST['staff_id']);
    
    $manual = isset($_POST['manual']) && $_POST['manual'] == 1;
    $notes = isset($_POST['notes']) ? mysqli_real_escape_string($conn, $_POST['notes']) : '';
    if ($manual) {
        $notes = "[Manual completion by staff] " . $notes;
    }
    
    $qtn_check = mysqli_query($conn, "SELECT customer_id, staff_id FROM quotations WHERE qtn_id = $qtn_id");
    $qtn_row = mysqli_fetch_assoc($qtn_check);
    if (!$qtn_row || $qtn_row['staff_id'] != $staff_id) {
        echo json_encode(['success' => false, 'message' => 'Invalid quotation']);
        exit;
    }
    $cust_id = $qtn_row['customer_id'];
    
    $check = mysqli_query($conn, "SELECT id FROM project_progress WHERE qtn_id = $qtn_id AND staff_id = $staff_id AND progress_step = '$step_name'");
    if (mysqli_num_rows($check) > 0) {
        $update = mysqli_query($conn, "UPDATE project_progress SET status = 'Completed', notes = CONCAT(IFNULL(notes, ''), IF(notes IS NOT NULL AND notes != '', CONCAT('\\n', '$notes'), '$notes')), updated_at = NOW() WHERE qtn_id = $qtn_id AND staff_id = $staff_id AND progress_step = '$step_name'");
        if ($update) {
            echo json_encode(['success' => true]);
        } else {
            echo json_encode(['success' => false, 'message' => 'Database update failed:' . mysqli_error($conn)]);
        }
    } else {
        $insert = mysqli_query($conn, "INSERT INTO project_progress (qtn_id, customer_id, staff_id, progress_step, status, notes, updated_at) VALUES ($qtn_id, $cust_id, $staff_id, '$step_name', 'Completed', '$notes', NOW())");
        if ($insert) {
            echo json_encode(['success' => true]);
        } else {
            echo json_encode(['success' => false, 'message' => 'Database insert failed: ' . mysqli_error($conn)]);
        }
    }
    exit;
}

if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['action']) && $_POST['action'] == 'add_step_note') {
    header('Content-Type: application/json');
    $qtn_id = intval($_POST['qtn_id']);
    $step_name = mysqli_real_escape_string($conn, $_POST['step_name']);
    $staff_id = intval($_POST['staff_id']);
    $new_note = isset($_POST['note']) ? trim(mysqli_real_escape_string($conn, $_POST['note'])) : '';
    
    if (empty($new_note)) {
        echo json_encode(['success' => false, 'message' => 'Note cannot be empty.']);
        exit;
    }
    
    $qtn_check = mysqli_query($conn, "SELECT customer_id FROM quotations WHERE qtn_id = $qtn_id AND staff_id = $staff_id");
    $qtn_row = mysqli_fetch_assoc($qtn_check);
    if (!$qtn_row) {
        echo json_encode(['success' => false, 'message' => 'Invalid quotation']);
        exit;
    }
    $cust_id = $qtn_row['customer_id'];
    
    $check = mysqli_query($conn, "SELECT id, notes FROM project_progress WHERE qtn_id = $qtn_id AND staff_id = $staff_id AND progress_step = '$step_name'");
    if (mysqli_num_rows($check) > 0) {
        $row = mysqli_fetch_assoc($check);
        $current_notes = $row['notes'];
        $timestamp = date('Y-m-d H:i:s');
        $new_notes_entry = "\n[" . $timestamp . "] " . $new_note;
        $updated_notes = $current_notes . $new_notes_entry;
        $update = mysqli_query($conn, "UPDATE project_progress SET notes = '$updated_notes', updated_at = NOW() WHERE qtn_id = $qtn_id AND staff_id = $staff_id AND progress_step = '$step_name'");
        if ($update) {
            echo json_encode(['success' => true]);
        } else {
            echo json_encode(['success' => false, 'message' => 'Database update failed: ' . mysqli_error($conn)]);
        }
    } else {
        $insert = mysqli_query($conn, "INSERT INTO project_progress (qtn_id, customer_id, staff_id, progress_step, status, notes, updated_at) VALUES ($qtn_id, $cust_id, $staff_id, '$step_name', 'Pending', '[" . date('Y-m-d H:i:s') . "] " . $new_note . "', NOW())");
        if ($insert) {
            echo json_encode(['success' => true]);
        } else {
            echo json_encode(['success' => false, 'message' => 'Database insert failed: ' . mysqli_error($conn)]);
        }
    }
    exit;
}

if (!isset($_SESSION['staff_id'])) {
    header("Location: login.php");
    exit;
}

$staff_db_id = intval($_SESSION['staff_id']);
$staff_name = $_SESSION['staff_name'] ?? "Staff Member";

$staff_info = [];
$staff_query = "SELECT staff_name, email, phone, profile_image, appointment_calendar FROM staff WHERE id = ?";
$staff_stmt = mysqli_prepare($conn, $staff_query);
mysqli_stmt_bind_param($staff_stmt, "i", $staff_db_id);
mysqli_stmt_execute($staff_stmt);
$staff_res = mysqli_stmt_get_result($staff_stmt);
$staff_info = mysqli_fetch_assoc($staff_res);
if (!$staff_info) {
    $staff_info = [
        'staff_name' => $staff_name,
        'email' => '',
        'phone' => '',
        'profile_image' => '../images/default-avatar.jpg',
        'appointment_calendar' => ''
    ];
}
if (empty($staff_info['profile_image']) || !file_exists("../".$staff_info['profile_image'])) {
    $staff_info['profile_image'] = '../images/default-avatar.jpg';
}

$success_message = '';
if (isset($_GET['msg']) && $_GET['msg'] == 'quotation_success') {
    $qtn_id = isset($_GET['qtn_id']) ? intval($_GET['qtn_id']) : 0;
    $success_message = "Quotation #{$qtn_id} has been created successfully!";
}
if (isset($_POST['change_staff_password'])) {
    $current_password = $_POST['current_password'];
    $new_password = $_POST['new_password'];
    $confirm_password = $_POST['confirm_password'];

    $pwd_check = mysqli_query($conn, "SELECT password FROM staff WHERE id = $staff_db_id");
    $pwd_row = mysqli_fetch_assoc($pwd_check);
    
    if (!password_verify($current_password, $pwd_row['password'])) {
        echo "<script>alert('Current password is incorrect!'); window.location.href='staff_dashboard.php?active_section=profile';</script>";
        exit;
    }
    if ($new_password !== $confirm_password) {
        echo "<script>alert('New passwords do not match!'); window.location.href='staff_dashboard.php?active_section=profile';</script>";
        exit;
    }
    if (strlen($new_password) < 6) {
        echo "<script>alert('Password must be at least 6 characters!'); window.location.href='staff_dashboard.php?active_section=profile';</script>";
        exit;
    }

    $hashed = password_hash($new_password, PASSWORD_DEFAULT);
    $update_pwd = mysqli_query($conn, "UPDATE staff SET password = '$hashed' WHERE id = $staff_db_id");
    
    if ($update_pwd) {
        $subject = "Password Changed Successfully - YS Aluminium";
        $body = "
            <html>
            <head><meta charset='UTF-8'></head>
            <body style='font-family: Arial, sans-serif; background-color: #f6f6f6; margin: 0; padding: 20px;'>
                <div style='max-width: 480px; margin: 0 auto; background: white; border-radius: 16px; box-shadow: 0 8px 20px rgba(0,0,0,0.05); padding: 35px 30px; text-align: left;'>
                    <h2 style='color: #1c1c1e; margin-top: 0; font-weight: 600;'>Hello " . htmlspecialchars($staff_info['staff_name']) . ",</h2>
                    <p style='color: #3a3a3c; line-height: 1.5; font-size: 15px;'>Your YS Aluminium staff account password was successfully changed.</p>
                    <p style='color: #3a3a3c; line-height: 1.5; font-size: 15px;'>If you did not perform this action, please contact the administrator immediately.</p>
                    <hr style='border: none; border-top: 1px solid #e4e4e7; margin: 30px 0 15px;'>
                    <p style='color: #555; font-size: 0.9rem;'>If you have any questions, please contact us:</p>
                    <p style='color: #555; font-size: 0.9rem;'><strong>Phone:</strong> +60 18-366 5756<br><strong>Email:</strong> yongshengalu@gmail.com</p>
                    <p style='color: #8e8e93; font-size: 13px; text-align: center; margin-bottom: 0;'>YS Aluminium Sdn Bhd</p>
                </div>
            </body>
            </html>
        ";
        $altBody = "Hello " . $staff_info['staff_name'] . ",\n\nYour staff account password was successfully changed.\n\nIf you did not perform this change, please contact the administrator immediately.\n\nYS Aluminium Sdn Bhd";
        sendYSAluminiumEmail($staff_info['email'], $staff_info['staff_name'], $subject, $body, $altBody);
        echo "<script>alert('Password changed successfully! A confirmation email has been sent.'); window.location.href='staff_dashboard.php?active_section=profile';</script>";
        exit;
    } else {
        echo "<script>alert('Database error. Please try again.'); window.location.href='staff_dashboard.php?active_section=profile';</script>";
        exit;
    }
}

if (isset($_POST['register_customer'])) {
    $email = mysqli_real_escape_string($conn, $_POST['email']);
    $check_email = mysqli_query($conn, "SELECT email FROM customers WHERE email = '$email'");
    if (mysqli_num_rows($check_email) > 0) {
        echo "<script>alert('Error: Email $email already exists!'); window.history.back();</script>";
        exit;
    }
    $name = mysqli_real_escape_string($conn, $_POST['cust_name']);
    $phone = mysqli_real_escape_string($conn, $_POST['phone']);
    $gender = mysqli_real_escape_string($conn, $_POST['gender']);
    $race = mysqli_real_escape_string($conn, $_POST['race']);
    $address = mysqli_real_escape_string($conn, $_POST['address']);
    $plain_password = $_POST['password'];
    $confirm_password = $_POST['confirm_password'] ?? '';
    if ($plain_password !== $confirm_password) {
        echo "<script>alert('Passwords do not match!'); window.history.back();</script>";
        exit;
    }
    $password = password_hash($plain_password, PASSWORD_DEFAULT);
    $profile_image = '../images/default-user.png';
    if (isset($_FILES['profile_image']) && $_FILES['profile_image']['error'] === UPLOAD_ERR_OK) {
        $upload_dir = '../uploads/customer_avatars/';
        if (!is_dir($upload_dir)) mkdir($upload_dir, 0777, true);
        $ext = pathinfo($_FILES['profile_image']['name'], PATHINFO_EXTENSION);
        $filename = 'cust_' . time() . '_' . rand(1000,9999) . '.' . $ext;
        $dest = $upload_dir . $filename;
        if (move_uploaded_file($_FILES['profile_image']['tmp_name'], $dest)) {
            $profile_image = 'uploads/customer_avatars/' . $filename;
        }
    }
    $insert_sql = "INSERT INTO customers (name, email, phone, gender, race, address, password, profile_image, status) VALUES (?, ?, ?, ?, ?, ?, ?, ?, 1)";
    $stmt = mysqli_prepare($conn, $insert_sql);
    mysqli_stmt_bind_param($stmt, "ssssssss", $name, $email, $phone, $gender, $race, $address, $password, $profile_image);
    if (mysqli_stmt_execute($stmt)) {
        $new_cust_id = mysqli_insert_id($conn);
        if (isset($_POST['selected_products']) && is_array($_POST['selected_products'])) {
            foreach ($_POST['selected_products'] as $pid) {
                $pid = intval($pid);
                mysqli_query($conn, "INSERT INTO customer_selected_products (customer_id, product_id) VALUES ($new_cust_id, $pid)");
            }
        }
        $subject = "Welcome to YS Aluminium";
        $body = "<!DOCTYPE html>...</html>";
        $altBody = "Hello $name,...";
        sendYSAluminiumEmail($email, $name, $subject, $body, $altBody);
        echo "<script>alert('Customer registered successfully!'); window.location.href='staff_dashboard.php?active_section=customer';</script>";
        exit;
    }
}

if (isset($_POST['update_customer'])) {
    $id = intval($_POST['customer_id']);
    $name = mysqli_real_escape_string($conn, $_POST['name']);
    $email = mysqli_real_escape_string($conn, $_POST['email']);
    $phone = mysqli_real_escape_string($conn, $_POST['phone']);
    $gender = mysqli_real_escape_string($conn, $_POST['gender']);
    $race = mysqli_real_escape_string($conn, $_POST['race']);
    $address = mysqli_real_escape_string($conn, $_POST['address']);
    $check_email_query = "SELECT customer_id FROM customers WHERE email = '$email' AND customer_id != $id";
    $check_res = mysqli_query($conn, $check_email_query);
    if (mysqli_num_rows($check_res) > 0) {
        echo "<script>alert('Error: Email already taken!'); window.history.back();</script>";
        exit;
    }
    $profile_image = null;
    if (isset($_FILES['profile_image']) && $_FILES['profile_image']['error'] === UPLOAD_ERR_OK) {
        $upload_dir = '../uploads/customer_avatars/';
        if (!is_dir($upload_dir)) mkdir($upload_dir, 0777, true);
        $ext = pathinfo($_FILES['profile_image']['name'], PATHINFO_EXTENSION);
        $filename = 'cust_' . time() . '_' . rand(1000,9999) . '.' . $ext;
        $dest = $upload_dir . $filename;
        if (move_uploaded_file($_FILES['profile_image']['tmp_name'], $dest)) {
            $profile_image = 'uploads/customer_avatars/' . $filename;
        }
    }
    if ($profile_image) {
        $update_sql = "UPDATE customers SET name=?, email=?, phone=?, gender=?, race=?, address=?, profile_image=? WHERE customer_id=?";
        $stmt = mysqli_prepare($conn, $update_sql);
        mysqli_stmt_bind_param($stmt, "sssssssi", $name, $email, $phone, $gender, $race, $address, $profile_image, $id);
    } else {
        $update_sql = "UPDATE customers SET name=?, email=?, phone=?, gender=?, race=?, address=? WHERE customer_id=?";
        $stmt = mysqli_prepare($conn, $update_sql);
        mysqli_stmt_bind_param($stmt, "ssssssi", $name, $email, $phone, $gender, $race, $address, $id);
    }
    if(mysqli_stmt_execute($stmt)){
        mysqli_query($conn, "DELETE FROM customer_selected_products WHERE customer_id = $id");
        if (isset($_POST['selected_products']) && is_array($_POST['selected_products'])) {
            foreach ($_POST['selected_products'] as $pid) {
                $pid = intval($pid);
                mysqli_query($conn, "INSERT INTO customer_selected_products (customer_id, product_id) VALUES ($id, $pid)");
            }
        }
        echo "<script>alert('Customer updated successfully!'); window.location.href='staff_dashboard.php?active_section=customer';</script>";
        exit;
    }
}

if (isset($_GET['delete_quote'])) {
    $q_id = intval($_GET['delete_quote']);
    $status_check = mysqli_query($conn, "SELECT status FROM quotations WHERE qtn_id = $q_id");
    $status_row = mysqli_fetch_assoc($status_check);
    if ($status_row && $status_row['status'] != 'Accepted') {
        mysqli_query($conn, "DELETE FROM quotations WHERE qtn_id = $q_id");
    }
    $active = $_GET['active_section'] ?? 'quotation';
    $search = $_GET['search'] ?? '';
    header("Location: staff_dashboard.php?active_section=$active&search=" . urlencode($search));
    exit;
}

if (isset($_GET['toggle_status']) && isset($_GET['id'])) {
    $cust_id = intval($_GET['id']);
    if ($_GET['toggle_status'] == 'inactive') {
        mysqli_query($conn, "UPDATE customers SET status = 0 WHERE customer_id = $cust_id");
    } else {
        mysqli_query($conn, "UPDATE customers SET status = 1 WHERE customer_id = $cust_id");
    }
    $active = $_GET['active_section'] ?? 'customer';
    $search = $_GET['search'] ?? '';
    header("Location: staff_dashboard.php?active_section=$active&search=" . urlencode($search));
    exit;
}

if (isset($_POST['update_progress'])) {
    $cust_id = intval($_POST['customer_id']);
    $step = mysqli_real_escape_string($conn, $_POST['progress_step']);
    $status = mysqli_real_escape_string($conn, $_POST['status']);
    $notes = isset($_POST['notes']) ? mysqli_real_escape_string($conn, $_POST['notes']) : '';
    if (empty($step)) {
        echo "<script>alert('Please select a progress stage!'); window.location.href='staff_dashboard.php?active_section=progress';</script>";
        exit;
    }
    $check = mysqli_query($conn, "SELECT id FROM project_progress WHERE customer_id = $cust_id AND progress_step = '$step'");
    if (mysqli_num_rows($check) > 0) {
        $sql = "UPDATE project_progress SET status = ?, staff_id = ?, notes = ?, updated_at = NOW() WHERE customer_id = ? AND progress_step = ?";
        $stmt = mysqli_prepare($conn, $sql);
        mysqli_stmt_bind_param($stmt, "sisis", $status, $staff_db_id, $notes, $cust_id, $step);
    } else {
        $sql = "INSERT INTO project_progress (customer_id, staff_id, progress_step, status, notes, updated_at) VALUES (?, ?, ?, ?, ?, NOW())";
        $stmt = mysqli_prepare($conn, $sql);
        mysqli_stmt_bind_param($stmt, "iisss", $cust_id, $staff_db_id, $step, $status, $notes);
    }
    if (mysqli_stmt_execute($stmt)) {
        echo "<script>alert('Progress Updated! Stage: " . addslashes($step) . "'); window.location.href='staff_dashboard.php?active_section=progress';</script>";
        exit;
    } else {
        echo "<script>alert('Database error: " . mysqli_error($conn) . "'); window.location.href='staff_dashboard.php?active_section=progress';</script>";
        exit;
    }
}

if (isset($_POST['update_staff_profile'])) {
    $new_name = mysqli_real_escape_string($conn, $_POST['staff_name']);
    $new_phone = mysqli_real_escape_string($conn, $_POST['staff_phone']);
    $update_sql = "UPDATE staff SET staff_name = ?, phone = ? WHERE id = ?";
    $stmt = mysqli_prepare($conn, $update_sql);
    mysqli_stmt_bind_param($stmt, "ssi", $new_name, $new_phone, $staff_db_id);
    if (mysqli_stmt_execute($stmt)) {
        $_SESSION['staff_name'] = $new_name;
        $staff_info['staff_name'] = $new_name;
        $staff_info['phone'] = $new_phone;
        echo "<script>alert('Profile updated successfully!'); window.location.href='staff_dashboard.php?active_section=profile';</script>";
        exit;
    } else {
        echo "<script>alert('Database error. Please try again.'); window.location.href='staff_dashboard.php?active_section=profile';</script>";
        exit;
    }
}

function auto_create_invoice($conn, $qtn_id, $customer_id, $staff_id, $total_amount, $stage = null) {
    if ($stage !== null) {
        $check = mysqli_query($conn, "SELECT inv_id, file_path FROM invoices WHERE qtn_id = $qtn_id AND stage = '$stage'");
    } else {
        $check = mysqli_query($conn, "SELECT inv_id, file_path FROM invoices WHERE qtn_id = $qtn_id AND stage IS NULL");
    }
    $existing = mysqli_fetch_assoc($check);
    if ($existing) {
        if (empty($existing['file_path'])) {
            generate_pdf_for_invoice($conn, $existing['inv_id'], $qtn_id, $customer_id, $staff_id, $total_amount, $stage);
        }
        return true;
    }

    $pay_sql = "SELECT status FROM payment_records WHERE qtn_id = $qtn_id AND stage = '$stage' AND status = 'Verified'";
    $pay_res = mysqli_query($conn, $pay_sql);
    if (mysqli_num_rows($pay_res) == 0) {
        error_log("auto_create_invoice: Payment not verified for qtn_id=$qtn_id, stage=$stage");
        return false;
    }

    $stage_amount = 0;
    if ($stage == 'deposit') $stage_amount = $total_amount * 0.5;
    elseif ($stage == 'progress') $stage_amount = $total_amount * 0.3;
    elseif ($stage == 'final') $stage_amount = $total_amount * 0.2;
    else $stage_amount = $total_amount;

    $inv_number = 'INV-' . date('Ymd') . '-' . rand(100, 999);
    $issue_date = date('Y-m-d');
    $due_date = date('Y-m-d', strtotime('+30 days'));

    $sql = "INSERT INTO invoices (qtn_id, customer_id, staff_id, invoice_number, total_amount, final_amount, status, issue_date, due_date, stage, file_path) 
            VALUES (?, ?, ?, ?, ?, ?, 'Draft', ?, ?, ?, '')";
    $stmt = mysqli_prepare($conn, $sql);
    mysqli_stmt_bind_param($stmt, "iiissdsss", $qtn_id, $customer_id, $staff_id, $inv_number, $total_amount, $stage_amount, $issue_date, $due_date, $stage);
    if (!mysqli_stmt_execute($stmt)) {
        error_log("auto_create_invoice INSERT failed: " . mysqli_error($conn));
        return false;
    }
    $inv_id = mysqli_insert_id($conn);

    generate_pdf_for_invoice($conn, $inv_id, $qtn_id, $customer_id, $staff_id, $stage_amount, $stage);
    return true;
}
function generate_pdf_for_invoice($conn, $inv_id, $qtn_id, $customer_id, $staff_id, $amount, $stage) {
    $items_query = "SELECT description, quantity, unit_price, discount FROM quotation_items WHERE qtn_id = ?";
    $stmt = mysqli_prepare($conn, $items_query);
    mysqli_stmt_bind_param($stmt, "i", $qtn_id);
    mysqli_stmt_execute($stmt);
    $res = mysqli_stmt_get_result($stmt);
    $items = [];
    while ($row = mysqli_fetch_assoc($res)) {
        $items[] = $row;
    }
    
    $cust_query = "SELECT name, address, phone, email FROM customers WHERE customer_id = ?";
    $stmt = mysqli_prepare($conn, $cust_query);
    mysqli_stmt_bind_param($stmt, "i", $customer_id);
    mysqli_stmt_execute($stmt);
    $cust_res = mysqli_stmt_get_result($stmt);
    $customer = mysqli_fetch_assoc($cust_res);
    if (!$customer) return;
    
    $inv_query = "SELECT invoice_number, issue_date FROM invoices WHERE inv_id = ?";
    $stmt_inv = mysqli_prepare($conn, $inv_query);
    mysqli_stmt_bind_param($stmt_inv, "i", $inv_id);
    mysqli_stmt_execute($stmt_inv);
    $inv_res = mysqli_stmt_get_result($stmt_inv);
    $inv_row = mysqli_fetch_assoc($inv_res);
    $invoice_number = $inv_row['invoice_number'];
    $issue_date = $inv_row['issue_date'];
    
    $stage_percent = 1.0;
    $stage_name = '';
    switch ($stage) {
        case 'deposit':
            $stage_percent = 0.5;
            $stage_name = '50% Deposit';
            break;
        case 'progress':
            $stage_percent = 0.3;
            $stage_name = '30% Progress';
            break;
        case 'final':
            $stage_percent = 0.2;
            $stage_name = '20% Final';
            break;
        default:
            $stage_percent = 1.0;
            $stage_name = '';
    }
    
    $pdf_path = generateInvoicePDF($inv_id, $invoice_number, $customer, $items, $amount, $issue_date, '', $stage_percent, $stage_name);
    
    $upd_sql = "UPDATE invoices SET file_path = ? WHERE inv_id = ?";
    $upd_stmt = mysqli_prepare($conn, $upd_sql);
    mysqli_stmt_bind_param($upd_stmt, "si", $pdf_path, $inv_id);
    mysqli_stmt_execute($upd_stmt);
}

if (isset($_POST['update_payment_status'])) {
    $payment_id = intval($_POST['payment_id']);
    $new_status = mysqli_real_escape_string($conn, $_POST['payment_status']);
    $staff_notes = mysqli_real_escape_string($conn, $_POST['staff_notes']);
    $customer_id = intval($_POST['customer_id']);
    if (!in_array($new_status, ['Verified', 'Rejected'])) {
        echo "<script>alert('Invalid status.'); window.location.href='staff_dashboard.php?active_section=payment';</script>";
        exit;
    }
    if ($new_status == 'Rejected' && empty($staff_notes)) {
        echo "<script>alert('Please provide a rejection reason.'); window.location.href='staff_dashboard.php?active_section=payment';</script>";
        exit;
    }
    $pay_query = "SELECT qtn_id, stage FROM payment_records WHERE id = $payment_id";
    $pay_res = mysqli_query($conn, $pay_query);
    $pay_row = mysqli_fetch_assoc($pay_res);
    $qtn_id = $pay_row['qtn_id'] ?? 0;
    $stage = $pay_row['stage'] ?? 'deposit';
    $update_sql = "UPDATE payment_records SET status = ?, staff_notes = ? WHERE id = ?";
    $stmt = mysqli_prepare($conn, $update_sql);
    mysqli_stmt_bind_param($stmt, "ssi", $new_status, $staff_notes, $payment_id);
    $update_ok = mysqli_stmt_execute($stmt);
    if ($update_ok) {
        if ($new_status == 'Verified') {
            $progress_step_map = [
                'deposit'  => 'Deposit 50%',
                'progress' => '30% on going job',
                'final'    => '20% complete job'
            ];
            
            if (isset($progress_step_map[$stage])) {
                $progress_step = $progress_step_map[$stage];
                $check_step = mysqli_query($conn, "SELECT id FROM project_progress WHERE qtn_id = $qtn_id AND progress_step = '$progress_step'");
                if (mysqli_num_rows($check_step) > 0) {
                    mysqli_query($conn, "UPDATE project_progress SET status = 'Completed', updated_at = NOW() WHERE qtn_id = $qtn_id AND progress_step = '$progress_step'");
                } else {
                    mysqli_query($conn, "INSERT INTO project_progress (qtn_id, customer_id, staff_id, progress_step, status, notes) VALUES ($qtn_id, $customer_id, $staff_db_id, '$progress_step', 'Completed', 'Auto-completed after payment verification')");
                }
                $qtn_query = "SELECT total_amount FROM quotations WHERE qtn_id = $qtn_id AND status = 'Accepted'";
                $qtn_res = mysqli_query($conn, $qtn_query);
                if ($qtn_row = mysqli_fetch_assoc($qtn_res)) {
                    $total_amount = $qtn_row['total_amount'];
                    $stage_amount = 0;
                    if ($stage == 'deposit') $stage_amount = $total_amount * 0.5;
                    elseif ($stage == 'progress') $stage_amount = $total_amount * 0.3;
                    elseif ($stage == 'final') $stage_amount = $total_amount * 0.2;
                    auto_create_invoice($conn, $qtn_id, $customer_id, $staff_db_id, $stage_amount, $stage);
                }

                $update_inv = "UPDATE invoices SET status = 'Paid', paid_date = CURDATE() 
                            WHERE qtn_id = $qtn_id AND stage = '$stage' AND status != 'Paid'";
                mysqli_query($conn, $update_inv);
                
            }
        }
        $email_query = "SELECT email, name FROM customers WHERE customer_id = $customer_id";
        $email_res = mysqli_query($conn, $email_query);
        if ($email_row = mysqli_fetch_assoc($email_res)) {
            if ($new_status == 'Verified') {
                $subject = "Your payment has been verified - YS Aluminium";
                $altBody = "Hello {$email_row['name']},\n\nYour payment receipt has been verified.\n\nStaff Notes: $staff_notes\n\nThe deposit stage (50%) has been marked as completed.\n\nLogin to view details.\n\nYS Aluminium Team";
            } else {
                $subject = "Your payment has been rejected - YS Aluminium";
                $altBody = "Hello {$email_row['name']},\n\nYour payment receipt has been rejected.\n\nReason: $staff_notes\n\nPlease upload a valid receipt again.\n\nYS Aluminium Team";
            }
            sendYSAluminiumEmail($email_row['email'], $email_row['name'], $subject, "", $altBody);
        }
        echo "<script>alert('Payment status updated to $new_status!'); window.location.href='staff_dashboard.php?active_section=payment';</script>";
    } else {
        echo "<script>alert('Database error. Please try again.'); window.location.href='staff_dashboard.php?active_section=payment';</script>";
    }
    exit;
}

$search = isset($_GET['search']) ? mysqli_real_escape_string($conn, $_GET['search']) : '';
$active_section = isset($_GET['active_section']) ? $_GET['active_section'] : 'dashboard';

if (!empty($search)) {
    if (preg_match('/^CUST-(\d+)$/i', $search, $matches)) {
        $id_num = intval($matches[1]);
        $query = "SELECT * FROM customers WHERE customer_id = $id_num ORDER BY name ASC";
    } elseif (ctype_digit($search) && strlen($search) <= 4) {
        $id_num = intval($search);
        $query = "SELECT * FROM customers WHERE customer_id = $id_num ORDER BY name ASC";
    } else {
        $query = "SELECT * FROM customers 
                  WHERE name LIKE '%$search%' 
                     OR email LIKE '%$search%' 
                     OR phone LIKE '%$search%'
                     OR race LIKE '%$search%'
                  ORDER BY name ASC";
    }
} else {
    $query = "SELECT * FROM customers ORDER BY name ASC";
}
$result = mysqli_query($conn, $query);

$staff_cust_ids = [];
$cust_res = mysqli_query($conn, "SELECT DISTINCT customer_id FROM quotations WHERE staff_id = $staff_db_id");
while($row = mysqli_fetch_assoc($cust_res)) {
    $staff_cust_ids[] = $row['customer_id'];
}
if (empty($staff_cust_ids)) $staff_cust_ids = [0];
$cust_ids_str = implode(',', $staff_cust_ids);

$ongoing_count = 0;
$completed_count = 0;
$cust_all = $conn->query("SELECT customer_id FROM customers WHERE status = 1 AND customer_id IN ($cust_ids_str)");
while($c = $cust_all->fetch_assoc()) {
    $cid = $c['customer_id'];
    $check = $conn->query("SELECT id FROM project_progress WHERE customer_id = $cid AND progress_step = '20% complete job' AND status = 'Completed'");
    if($check && $check->num_rows > 0) {
        $completed_count++;
    } else {
        $ongoing_count++;
    }
}

$today_date = date('Ymd');
$staff_email_only = $staff_info['email'];
$calendar_src_today = !empty($staff_email_only) ? "https://calendar.google.com/calendar/embed?mode=DAY&date={$today_date}&ctz=Asia%2FKuala_Lumpur&src=" . urlencode($staff_email_only) . "&hl=en" : '';

$recent_quotations = mysqli_query($conn, "SELECT q.qtn_number, q.total_amount, q.status, q.created_at, c.name as customer_name FROM quotations q LEFT JOIN customers c ON q.customer_id = c.customer_id WHERE q.staff_id = $staff_db_id ORDER BY q.created_at DESC LIMIT 5");
$monthly_labels = [];
$monthly_values = [];
for ($i = 5; $i >= 0; $i--) {
    $month = date('Y-m', strtotime("-$i months"));
    $month_label = date('M Y', strtotime("-$i months"));
    $monthly_labels[] = $month_label;
    $sql_revenue = "
        SELECT COALESCE(SUM(
            q.total_amount * CASE pr.stage
                WHEN 'deposit' THEN 0.5
                WHEN 'progress' THEN 0.3
                WHEN 'final' THEN 0.2
                ELSE 0
            END
        ), 0) as total
        FROM payment_records pr
        JOIN quotations q ON pr.qtn_id = q.qtn_id
        WHERE pr.status = 'Verified'
          AND DATE_FORMAT(pr.uploaded_at, '%Y-%m') = '$month'
          AND q.staff_id = $staff_db_id
    ";
    $revenue_res = mysqli_query($conn, $sql_revenue);
    $revenue_row = mysqli_fetch_assoc($revenue_res);
    $monthly_values[] = $revenue_row['total'];
}
$weekly_labels = [];
$weekly_values = [];
for ($i = 7; $i >= 0; $i--) {
    $week_start = date('Y-m-d', strtotime("-$i weeks"));
    $week_end = date('Y-m-d', strtotime("-$i weeks +6 days"));
    $week_label = "Wk " . date('d M', strtotime($week_start));
    $sql_week = "
        SELECT COALESCE(SUM(
            q.total_amount * CASE pr.stage
                WHEN 'deposit' THEN 0.5
                WHEN 'progress' THEN 0.3
                WHEN 'final' THEN 0.2
                ELSE 0
            END
        ), 0) as total
        FROM payment_records pr
        JOIN quotations q ON pr.qtn_id = q.qtn_id
        WHERE pr.status = 'Verified'
          AND DATE(pr.uploaded_at) BETWEEN '$week_start' AND '$week_end'
          AND q.staff_id = $staff_db_id
    ";
    $res_week = mysqli_query($conn, $sql_week);
    $row_week = mysqli_fetch_assoc($res_week);
    $weekly_values[] = $row_week['total'];
    $weekly_labels[] = $week_label;
}
$yearly_labels = [];
$yearly_values = [];
for ($i = 2; $i >= 0; $i--) {
    $year = date('Y', strtotime("-$i years"));
    $year_label = $year;
    $sql_year = "
        SELECT COALESCE(SUM(
            q.total_amount * CASE pr.stage
                WHEN 'deposit' THEN 0.5
                WHEN 'progress' THEN 0.3
                WHEN 'final' THEN 0.2
                ELSE 0
            END
        ), 0) as total
        FROM payment_records pr
        JOIN quotations q ON pr.qtn_id = q.qtn_id
        WHERE pr.status = 'Verified'
          AND YEAR(pr.uploaded_at) = $year
          AND q.staff_id = $staff_db_id
    ";
    $res_year = mysqli_query($conn, $sql_year);
    $row_year = mysqli_fetch_assoc($res_year);
    $yearly_values[] = $row_year['total'];
    $yearly_labels[] = $year_label;
}
$top_products = mysqli_query($conn, "SELECT product_id, door_brand, price_per_sqft, image FROM products WHERE status = 1 ORDER BY price_per_sqft DESC LIMIT 5");
$all_products = [];
$prod_res = $conn->query("SELECT product_id, door_brand FROM products WHERE status = 1 ORDER BY door_brand ASC");
if($prod_res) {
    while($p = $prod_res->fetch_assoc()) {
        $all_products[] = $p;
    }
}
$recent_customers = mysqli_query($conn, "SELECT customer_id, name, email, created_at FROM customers ORDER BY created_at DESC LIMIT 8");
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=yes">
    <title>Staff Portal | YS Aluminium</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.0/font/bootstrap-icons.css">
    <link href="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/css/select2.min.css" rel="stylesheet" />
    <link rel="stylesheet" href="../css/staff_dashboard.css">
    <link rel="stylesheet" href="../css/mobile.css">
</head>
<body>

<button class="menu-toggle" onclick="document.querySelector('.sidebar').classList.toggle('show')">
    <i class="bi bi-list"></i>
</button>

<div class="sidebar" onclick="if(window.innerWidth<=768) this.classList.remove('show')">
    <div class="sidebar-brand">
        <img src="../images/ys.jpg" alt="Logo" onerror="this.src='https://via.placeholder.com/50'"> 
        <span>YS ALUMINIUM</span>
    </div>
    <div class="nav-menu">
        <div class="nav-section-title">Main Menu</div>
        <ul class="sidebar-menu" style="list-style: none; padding: 0; margin: 0;">
            <li class="nav-link active" data-section="dashboard" onclick="showPage('dashboard', this)"><i class="bi bi-speedometer2"></i> Dashboard</li>
            <li class="nav-link" data-section="profile" onclick="showPage('profile', this)"><i class="bi bi-person-circle"></i> Profile</li>
            <li class="nav-link" data-section="customer" onclick="showPage('customer', this)"><i class="bi bi-people"></i> Customer Management</li>
            <li class="nav-link" data-section="appointment" onclick="showPage('appointment', this)"><i class="bi bi-calendar-check"></i> Appointment</li>
            <li class="nav-link" data-section="quotation" onclick="showPage('quotation', this)"><i class="bi bi-file-earmark-text"></i> Quotation</li>
            <li class="nav-link" data-section="payment" onclick="showPage('payment', this)"><i class="bi bi-credit-card"></i> Payment</li>
            <li class="nav-link" data-section="invoice" onclick="showPage('invoice', this)"><i class="bi bi-receipt"></i> Invoice / Purchase</li>
            <li class="nav-link" data-section="progress" onclick="showPage('progress', this)"><i class="bi bi-bar-chart-steps"></i> Progress</li>
            <li class="nav-link" data-section="chat" onclick="showPage('chat', this)">
                <i class="bi bi-chat-dots"></i> Customer Chat
                <span id="chatUnreadBadge" class="badge-unread" style="display: none; margin-left: auto;">0</span>
            </li>
            <a href="logout.php" class="logout-link"><i class="bi bi-box-arrow-left"></i> Logout</a>
        </ul>
    </div>
</div>

<div class="main-content">
    <div class="page-header">
    <div></div>
        <div class="search-group" id="searchGroup">
            <form action="" method="GET" class="d-flex">
                <i class="bi bi-search"></i>
                <input type="text" name="search" id="tableSearch" class="form-control" placeholder="Search..." value="<?php echo htmlspecialchars($search); ?>">
                <input type="hidden" name="active_section" id="activeSectionInput" value="<?php echo htmlspecialchars($active_section); ?>">
            </form>
        </div>
        <div class="account-area" onclick="showPage('profile', document.querySelector('.nav-link[data-section=\"profile\"]'))">
            <?php
            $avatar = $staff_info['profile_image'];
            if (strpos($avatar, '/') === 0) $avatar = '../' . ltrim($avatar, '/');
            elseif (strpos($avatar, '../') !== 0 && strpos($avatar, 'http') !== 0) $avatar = '../' . $avatar;
            if (!file_exists($avatar)) $avatar = '../images/default-avatar.jpg';
            ?>
            <img src="<?php echo $avatar; ?>" class="staff-avatar" alt="Avatar" onerror="this.src='../images/default-avatar.jpg';">
            <span class="staff-name"><?php echo htmlspecialchars($staff_info['staff_name']); ?></span>
        </div>
    </div>

    <?php if (!empty($success_message)): ?>
    <div class="alert alert-success alert-dismissible fade show" role="alert">
        <i class="bi bi-check-circle-fill me-2"></i> <?php echo htmlspecialchars($success_message); ?>
        <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
    </div>
    <?php endif; ?>

    <div id="section-dashboard" class="content-section <?php echo ($active_section == 'dashboard') ? 'active' : ''; ?>" style="display: <?php echo ($active_section == 'dashboard') ? 'block' : 'none'; ?>;">
        <div class="row g-4 mb-4">
            <div class="col-md-3 d-flex">
                <div class="stats-card w-100 h-100 d-flex flex-column justify-content-between" style="border-left-color: #0d6efd; cursor: pointer;" onclick="window.location.href='?active_section=progress'">
                    <div>
                        <h6 class="text-muted text-uppercase fw-semibold mb-1">Ongoing Projects</h6>
                        <h2 class="fw-bold mb-0"><?= $ongoing_count ?></h2>
                        <small class="text-muted">Not yet settled</small>
                    </div>
                    <i class="bi bi-hourglass-split fs-1 text-primary opacity-50 align-self-end"></i>
                </div>
            </div>
            <div class="col-md-3 d-flex">
                <div class="stats-card w-100 h-100 d-flex flex-column justify-content-between" style="border-left-color: #198754; cursor: pointer;" onclick="window.location.href='?active_section=progress'">
                    <div>
                        <h6 class="text-muted text-uppercase fw-semibold mb-1">Completed Projects</h6>
                        <h2 class="fw-bold mb-0"><?= $completed_count ?></h2>
                        <small class="text-muted">Settled</small>
                    </div>
                    <i class="bi bi-check2-circle fs-1 text-success opacity-50 align-self-end"></i>
                </div>
            </div>
            <div class="col-md-6 d-flex">
                <div class="card-custom w-100 h-100 d-flex flex-column m-0">
                    <div class="card-header-custom py-2 d-flex justify-content-between align-items-center">
                        <h6 class="mb-0"><i class="bi bi-calendar-check me-2"></i>Schedule</h6>
                        <a href="?active_section=appointment" class="btn btn-sm btn-outline-secondary">
                            <i class="bi bi-box-arrow-up-right"></i> Open Calendar
                        </a>
                    </div>
                    <div class="card-body p-2 flex-grow-1 d-flex flex-column">
                        <?php if (!empty($staff_email_only)): ?>
                            <iframe src="<?= $calendar_src_today ?>" style="border: 0; width: 100%; height: 123px;" frameborder="0" scrolling="yes"></iframe>
                        <?php else: ?>
                            <div class="text-center text-muted py-4 flex-grow-1 d-flex flex-column justify-content-center">
                                <i class="bi bi-calendar-x fs-1"></i>
                                <p class="mt-2">Staff email not configured.<br>Unable to display calendar.</p>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        </div>
        <div class="row g-4 mb-4">
            <div class="col-12">
                <div class="card-custom-chart">
                    <div class="card-header-custom d-flex justify-content-between align-items-center flex-wrap">
                        <h5 class="mb-0"><i class="bi bi-graph-up me-2"></i>Revenue Trend</h5>
                        <div class="d-flex align-items-center gap-3">
                            <div class="fw-bold fs-6 text-dark" id="totalRevenueDisplay">Total: RM <?= number_format(array_sum($monthly_values), 2) ?></div>
                            <div class="btn-group btn-group-sm" role="group">
                                <button type="button" class="btn btn-outline-secondary revenue-range" data-range="weekly">Weekly</button>
                                <button type="button" class="btn btn-outline-secondary revenue-range active" data-range="monthly">Monthly</button>
                                <button type="button" class="btn btn-outline-secondary revenue-range" data-range="yearly">Yearly</button>
                            </div>
                        </div>
                    </div>
                    <div class="card-body p-3" style="height: 380px; position: relative; min-height: 380px;">
                        <canvas id="staffRevenueChart" style="width: 100%; height: 100%; display: block;"></canvas>
                    </div>
                </div>
            </div>
        </div>
        <div class="row g-4 mb-4">
            <div class="col-md-6">
                <div class="card-custom">
                    <div class="card-header-custom"><h5 class="mb-0"><i class="bi bi-trophy me-2"></i>Top Premium Products (by Price)</h5><a href="?active_section=quotation" class="btn btn-sm btn-outline-secondary">View All</a></div>
                    <div class="table-responsive">
                        <table class="table align-middle">
                            <thead class="table-light">
                                <tr><th>Rank</th><th>Image</th><th>Product Name</th><th>Price per sqft (RM)</th></tr>
                            </thead>
                            <tbody><?php $rank=1; while($p=mysqli_fetch_assoc($top_products)): ?>
                                <tr>
                                    <td><span class="badge bg-dark rounded-pill">#<?= $rank++ ?></span></td>
                                    <td><?php if(!empty($p['image'])): ?><img src="../images/<?= $p['image'] ?>" class="product-thumb"><?php else: ?><i class="bi bi-image fs-3 text-muted"></i><?php endif; ?></td>
                                    <td class="fw-bold"><?= htmlspecialchars($p['door_brand']) ?></td>
                                    <td class="fw-bold">RM <?= number_format($p['price_per_sqft'], 2) ?></td>
                                </tr>
                            <?php endwhile; if(mysqli_num_rows($top_products)==0): ?>
                                <tr><td colspan="4" class="text-center text-muted">No products available.</td></tr><?php endif; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
            <div class="col-md-6">
                <div class="card-custom">
                    <div class="card-header-custom"><h6 class="mb-0"><i class="bi bi-people-fill me-2"></i>Recent Customers</h6></div>
                    <div class="table-responsive">
                        <table class="table table-sm mb-0">
                            <thead>
                                <tr><th>ID</th><th>Name</th><th>Email</th><th>Joined</th></tr>
                            </thead>
                            <tbody><?php while($rc=mysqli_fetch_assoc($recent_customers)): ?>
                                <tr>
                                    <td><code>CUST-<?= str_pad($rc['customer_id'],4,'0',STR_PAD_LEFT) ?></code></td>
                                    <td><?= htmlspecialchars($rc['name']) ?></td>
                                    <td><?= htmlspecialchars($rc['email']) ?></td>
                                    <td><?= date('d/m/Y', strtotime($rc['created_at'])) ?></td>
                                </tr>
                            <?php endwhile; if(mysqli_num_rows($recent_customers)==0): ?><tr><td colspan="4" class="text-center text-muted">No customers yet.</td></tr><?php endif; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        </div>
        <div class="row g-4">
            <div class="col-12">
                <div class="card-custom">
                    <div class="card-header-custom"><h5 class="mb-0"><i class="bi bi-file-earmark-text me-2"></i>Recent Quotations</h5><a href="?active_section=quotation" class="btn btn-sm btn-outline-secondary">View All</a></div>
                    <div class="table-responsive">
                        <table class="table align-middle">
                            <thead>
                                <tr><th>Qtn Number</th><th>Customer</th><th>Amount</th><th>Status</th><th>Created</th></tr>
                            </thead>
                            <tbody><?php while($q=mysqli_fetch_assoc($recent_quotations)): $status_class = match($q['status']) { 'Accepted'=>'status-active', 'Rejected'=>'status-inactive', default=>'status-pending' }; ?>
                                <tr>
                                    <td><code><?= htmlspecialchars($q['qtn_number']) ?></code></td>
                                    <td><?= htmlspecialchars($q['customer_name'] ?? 'Unknown') ?></td>
                                    <td>RM <?= number_format($q['total_amount'], 2) ?></td>
                                    <td><span class="status-badge <?= $status_class ?>"><?= $q['status'] ?></span></td>
                                    <td><small><?= date('d M Y', strtotime($q['created_at'])) ?></small></td>
                                </tr>
                            <?php endwhile; if(mysqli_num_rows($recent_quotations)==0): ?><tr><td colspan="5" class="text-center text-muted">No quotations found.</td></tr><?php endif; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        </div>
    </div>
    <div id="profile-content" style="display: none;"></div>
    <div id="section-customer" class="content-section <?php echo ($active_section == 'customer') ? 'active' : ''; ?>" style="display: <?php echo ($active_section == 'customer') ? 'block' : 'none'; ?>;">
        <div class="card-custom">
            <div class="card-header-custom">
                <h5 class="mb-0"><i class="bi bi-people me-2"></i>Customer Management</h5>
                <button class="btn-dark-custom" data-bs-toggle="modal" data-bs-target="#registerCustomerModal">
                    <i class="bi bi-person-plus-fill me-2"></i>Register New Customer
                </button>
            </div>
            <div class="table-responsive">
                <table class="table align-middle text-center">
                    <thead class="table-light">
                        <tr>
                            <th>ID</th>
                            <th>Customer</th>
                            <th>Interested Products</th>
                            <th>Contact</th>
                            <th>Address</th>
                            <th>Status</th>
                            <th>Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (mysqli_num_rows($result) > 0): while ($row = mysqli_fetch_assoc($result)): 
                            $cust_id = $row['customer_id'];
                            $prod_query = "SELECT p.door_brand FROM customer_selected_products csp 
                                        JOIN products p ON csp.product_id = p.product_id 
                                        WHERE csp.customer_id = $cust_id";
                            $prod_res = mysqli_query($conn, $prod_query);
                            $product_list = [];
                            while($p = mysqli_fetch_assoc($prod_res)) {
                                $product_list[] = $p['door_brand'];
                            }
                            $product_str = !empty($product_list) ? implode(', ', $product_list) : '—';

                            $avatar_img = $row['profile_image'];
                            if (!empty($avatar_img) && file_exists("../" . $avatar_img)) {
                                $avatar_src = "../" . $avatar_img;
                            } else {
                                $avatar_src = "https://ui-avatars.com/api/?name=" . urlencode($row['name']) . "&background=20304a&color=fff&size=32&rounded=true&bold=true";
                            }
                        ?>
                        <tr>
                            <td><code><?php echo sprintf("CUST-%04d", $row['customer_id']); ?></code></td>

                            <td>
                                <div class="d-flex align-items-center gap-2">
                                    <img src="<?php echo $avatar_src; ?>" style="width: 32px; height: 32px; border-radius: 50%; object-fit: cover;">
                                    <div class="text-start">
                                        <div class="fw-bold"><?php echo htmlspecialchars($row['name']); ?></div>
                                        <small class="text-muted"><?php echo htmlspecialchars($row['email']); ?></small>
                                    </div>
                                </div>
                            </td>
                            <td><?php echo htmlspecialchars($product_str); ?></td>
                            <td>
                                <div class="small">
                                    <div><?php echo htmlspecialchars($row['phone']); ?></div>
                                    <div class="text-muted"><?php echo $row['race'].'/'.$row['gender']; ?></div>
                                </div>
                            </td>
                            <td><?php echo htmlspecialchars($row['address'] ?? '-'); ?></td>
                            <td>
                                <?php if($row['status'] == 1): ?>
                                    <a href="?toggle_status=inactive&id=<?php echo $row['customer_id']; ?>&active_section=customer" class="status-badge status-active">Active</a>
                                <?php else: ?>
                                    <a href="?toggle_status=active&id=<?php echo $row['customer_id']; ?>&active_section=customer" class="status-badge status-inactive">Inactive</a>
                                <?php endif; ?>
                            </td>
                            <td>
                                <button class="btn-action edit-btn" 
                                    data-id="<?php echo $row['customer_id']; ?>" 
                                    data-name="<?php echo htmlspecialchars($row['name']); ?>" 
                                    data-email="<?php echo $row['email']; ?>" 
                                    data-phone="<?php echo $row['phone']; ?>" 
                                    data-gender="<?php echo $row['gender']; ?>" 
                                    data-race="<?php echo $row['race']; ?>" 
                                    data-address="<?php echo htmlspecialchars($row['address']); ?>" 
                                    data-bs-toggle="modal" data-bs-target="#editCustomerModal">
                                    <i class="bi bi-pencil-square"></i>
                                </button>
                            </td>
                        </tr>
                        <?php endwhile; else: ?>
                            <tr><td colspan="7" class="text-center py-4 text-muted">No customers found.</td></tr>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>

    <div id="section-quotation" class="content-section <?php echo ($active_section == 'quotation') ? 'active' : ''; ?>" style="display: <?php echo ($active_section == 'quotation') ? 'block' : 'none'; ?>;">
    <div class="card-custom">
        <div class="card-header-custom">
            <h5 class="mb-0"><i class="bi bi-file-earmark-text me-2"></i>Quotation Management</h5>
            <a href="create_quotation.php" class="btn-dark-custom"><i class="bi bi-plus-circle me-2"></i>Create New Quotation</a>
        </div>
        <div class="table-responsive">
            <table class="table align-middle">
                <thead>
                    <tr>
                        <th>Qtn Number</th>
                        <th>Customer</th>
                        <th>Product(s)</th>
                        <th>50% Deposit</th>
                        <th>30% Progress</th>
                        <th>20% Final</th>
                        <th>Total Amount</th>
                        <th>Document</th>
                        <th>Created</th>
                        <th>Status</th>
                        <th>Reason</th>
                        <th>Actions</th>
                    </tr>
                </thead>
                <tbody>
                <?php 
                $quote_query = "SELECT q.*, c.name as customer_name, c.profile_image,
                (SELECT GROUP_CONCAT(DISTINCT 
                    CASE     
                        WHEN p.door_brand IS NOT NULL THEN p.door_brand 
                        ELSE SUBSTRING_INDEX(qi.description, '\n', 1)
                    END SEPARATOR ', ') 
                FROM quotation_items qi 
                LEFT JOIN products p ON qi.product_id = p.product_id 
                WHERE qi.qtn_id = q.qtn_id LIMIT 3) as product_names,
                (SELECT progress_step FROM project_progress WHERE customer_id = q.customer_id ORDER BY updated_at DESC LIMIT 1) as current_stage
                FROM quotations q 
                LEFT JOIN customers c ON q.customer_id = c.customer_id
                WHERE q.staff_id = $staff_db_id"; 
                if (!empty($search)) {
                    if (preg_match('/^QT-\d{8}-\d{3}$/i', $search)) {
                        $quote_query .= " AND q.qtn_number = '$search'";
                    } else {
                        $quote_query .= " AND (q.qtn_number LIKE '%$search%' OR c.name LIKE '%$search%')";
                    }
                }
                $quote_query .= " ORDER BY q.created_at DESC";
                $quote_result = mysqli_query($conn, $quote_query);
                
                if(isset($quote_result) && mysqli_num_rows($quote_result)>0): 
                    while($q_row=mysqli_fetch_assoc($quote_result)): 
                        $status = $q_row['status'] ?? 'Pending';
                        $badge_class = match($status){
                            'Accepted' => 'status-active',
                            'Rejected' => 'status-rejected',
                            'Updated' => 'status-updated',
                            default => 'status-pending'
                        };
                        $total = $q_row['total_amount'] ?? 0;
                        $deposit = $total * 0.5;
                        $progress = $total * 0.3;
                        $final = $total * 0.2;
                        
                        $avatar_img = $q_row['profile_image'] ?? '';
                        $customer_name = htmlspecialchars($q_row['customer_name']??'Unknown');
                        if (!empty($avatar_img) && file_exists("../" . $avatar_img)) {
                            $avatar_src = "../" . $avatar_img;
                        } else {
                            $avatar_src = "https://ui-avatars.com/api/?name=" . urlencode($customer_name) . "&background=20304a&color=fff&size=32&rounded=true&bold=true";
                        }
                ?>
                    <tr>
                        <td><code><?php echo htmlspecialchars($q_row['qtn_number']??'#Q-'.$q_row['qtn_id']); ?></code></td>
                        <td>
                            <div class="d-flex align-items-center gap-2">
                                <img src="<?php echo $avatar_src; ?>" style="width: 32px; height: 32px; border-radius: 50%; object-fit: cover;">
                                <span><?php echo $customer_name; ?></span>
                            </div>
                        </td>
                        <td><?php echo htmlspecialchars($q_row['product_names']??'-'); ?></td>
                        <td>RM <?php echo number_format($deposit,2); ?></td>
                        <td>RM <?php echo number_format($progress,2); ?></td>
                        <td>RM <?php echo number_format($final,2); ?></td>
                        <td>RM <?php echo number_format($total,2); ?></td>
                        <td><a href="../<?php echo $q_row['file_path']; ?>" target="_blank" class="btn-action"><i class="bi bi-file-pdf"></i> View</a></td>
                        <td><small><?php echo date('d/m/Y',strtotime($q_row['created_at'])); ?></small></td>
                        <td><span class="status-badge <?php echo $badge_class; ?>"><?php echo $status; ?></span></td>
                        <td><small><?php echo htmlspecialchars(substr($q_row['rejection_reason']??'',0,20))?:'—'; ?></small></td>
                        <td class="text-end">
                            <?php if ($status == 'Accepted'): ?>
                                <span class="text-muted">—</span>
                            <?php else: ?>
                                <a href="edit_quotation_items.php?id=<?php echo $q_row['qtn_id']; ?>" class="btn-action"><i class="bi bi-pencil-square"></i></a>
                                <a href="?delete_quote=<?php echo $q_row['qtn_id']; ?>&active_section=quotation" class="btn-action text-danger" onclick="return confirm('Delete?')"><i class="bi bi-trash"></i></a>
                            <?php endif; ?>
                        </td>
                    </tr>
                <?php endwhile; else: ?>
                    <tr><td colspan="12" class="text-center py-4 text-muted">No quotations found.</td></tr>
                <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>

    <div class="card-custom mt-4">
        <div class="card-header-custom"><h5 class="mb-0"><i class="bi bi-flag me-2"></i>Rejected / Updated History</h5></div>
            <div class="table-responsive">
                <table class="table align-middle">
                    <thead>
                        <tr><th>Qtn Number</th><th>Customer</th><th>Product(s)</th><th>Status</th><th>Reason</th><th>Rejected At</th><th>Updated</th><th>Actions</th></tr>
                    </thead>
                    <tbody>
                    <?php 
                    $rejected_query = "SELECT q.qtn_id, q.qtn_number, q.status, q.rejection_reason, q.updated_at, 
                        c.name, c.profile_image,
                        CASE WHEN q.status = 'Rejected' THEN q.updated_at ELSE NULL END as rejected_at,
                        (SELECT GROUP_CONCAT(DISTINCT 
                            CASE 
                                WHEN p.door_brand IS NOT NULL THEN p.door_brand 
                                ELSE SUBSTRING_INDEX(qi.description, '\n', 1)
                            END SEPARATOR ', ')
                        FROM quotation_items qi 
                        LEFT JOIN products p ON qi.product_id = p.product_id 
                        WHERE qi.qtn_id = q.qtn_id LIMIT 3) as product_names
                    FROM quotations q
                    LEFT JOIN customers c ON q.customer_id = c.customer_id
                    WHERE (q.status IN ('Rejected', 'Updated') 
                        OR (q.rejection_reason IS NOT NULL AND q.rejection_reason != ''))
                    AND q.staff_id = $staff_db_id
                    ORDER BY q.updated_at DESC";
                        $rejected_res = mysqli_query($conn, $rejected_query);
                    if ($rejected_res && mysqli_num_rows($rejected_res) > 0):
                        while ($r_row = mysqli_fetch_assoc($rejected_res)):
                            $status_class = ($r_row['status'] == 'Rejected') ? 'status-rejected' : 'status-updated';
                            $cust_name = htmlspecialchars($r_row['name'] ?? 'Unknown');
                            $avatar_img = $r_row['profile_image'] ?? '';
                            if (!empty($avatar_img) && file_exists("../" . $avatar_img)) {
                                $avatar_src = "../" . $avatar_img;
                            } else {
                                $avatar_src = "https://ui-avatars.com/api/?name=" . urlencode($cust_name) . "&background=20304a&color=fff&size=32&rounded=true&bold=true";
                            }
                    ?>
                        <tr>
                            <td><code><?php echo htmlspecialchars($r_row['qtn_number']); ?></code></td>
                            <td>
                                <div class="d-flex align-items-center gap-2">
                                    <img src="<?php echo $avatar_src; ?>" style="width: 32px; height: 32px; border-radius: 50%; object-fit: cover;">
                                    <span><?php echo $cust_name; ?></span>
                                </div>
                            </td>
                            <td><?php echo htmlspecialchars($r_row['product_names'] ?? '-'); ?></td>
                            <td><span class="status-badge <?php echo $status_class; ?>"><?php echo $r_row['status']; ?></span></td>
                            <td><small><?php echo htmlspecialchars(substr($r_row['rejection_reason'] ?? '', 0, 30)) ?: '—'; ?></small></td>
                            <td><small><?php echo $r_row['rejected_at'] ?? '—'; ?></small></td>
                            <td><small><?php echo date('d/m/Y', strtotime($r_row['updated_at'])); ?></small></td>
                            <td>
                                <a href="quotation_history.php?id=<?php echo $r_row['qtn_id']; ?>" class="btn-action" target="_blank"><i class="bi bi-clock-history"></i></a>
                            </td>
                        </tr>
                    <?php endwhile; else: ?>
                        <tr>
                            <td colspan="8" class="text-center py-4 text-muted">No rejected/updated quotations.</td>
                        </tr>
                    <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
    
    <div id="section-invoice" class="content-section <?php echo ($active_section == 'invoice') ? 'active' : ''; ?>" style="display: <?php echo ($active_section == 'invoice') ? 'block' : 'none'; ?>;">
         <div class="card-custom mb-3">
             <div class="card-header-custom">
                <h5 class="mb-0"><i class="bi bi-receipt me-2"></i>Invoices & Purchase Orders</h5>
             </div>
         </div>

            <?php
            $quotes_sql = "SELECT q.qtn_id, q.qtn_number, q.total_amount, q.customer_id, 
                            c.name as customer_name, c.email as customer_email, c.profile_image
                            FROM quotations q
                            LEFT JOIN customers c ON q.customer_id = c.customer_id
                            WHERE q.status = 'Accepted' AND q.staff_id = $staff_db_id"; 
            if (!empty($search)) {
                $quotes_sql .= " AND (c.name LIKE '%$search%' OR c.email LIKE '%$search%' OR q.qtn_number LIKE '%$search%')";
            }
            $quotes_sql .= " ORDER BY q.created_at DESC";
            $quotes_res = mysqli_query($conn, $quotes_sql);
   
            function ensure_invoice_for_stage($conn, $qtn_id, $customer_id, $staff_id, $stage_key, $total_amount) {
                $check_sql = "SELECT inv_id FROM invoices WHERE qtn_id = $qtn_id AND stage = '$stage_key'";
                $check_res = mysqli_query($conn, $check_sql);
                if (mysqli_num_rows($check_res) > 0) {
                    return true;
                }

                $pay_sql = "SELECT status FROM payment_records WHERE qtn_id = $qtn_id AND stage = '$stage_key' AND status = 'Verified'";
                $pay_res = mysqli_query($conn, $pay_sql);
                if (mysqli_num_rows($pay_res) == 0) {
                    return false;
                }

                $stage_amount = 0;
                if ($stage_key == 'deposit') $stage_amount = $total_amount * 0.5;
                elseif ($stage_key == 'progress') $stage_amount = $total_amount * 0.3;
                elseif ($stage_key == 'final') $stage_amount = $total_amount * 0.2;

                return auto_create_invoice($conn, $qtn_id, $customer_id, $staff_id, $stage_amount, $stage_key);
            }
            
            if (mysqli_num_rows($quotes_res) == 0) {
                echo '<div class="card-custom text-center py-5"><i class="bi bi-inbox fs-1 text-muted"></i><p class="mt-2 text-muted">No accepted quotations found.</p></div>';
            } else {
                while ($quote = mysqli_fetch_assoc($quotes_res)) {
                    $qtn_id = $quote['qtn_id'];
                    $qtn_number = htmlspecialchars($quote['qtn_number']);
                    $total_amount = floatval($quote['total_amount']);
                    $customer_name = htmlspecialchars($quote['customer_name'] ?? 'Unknown');
                    $customer_email = htmlspecialchars($quote['customer_email'] ?? '');
                    $customer_id = $quote['customer_id'];
                    $avatar_img = $quote['profile_image'] ?? '';
                    $avatar_src = (!empty($avatar_img) && file_exists("../" . $avatar_img)) ? "../" . $avatar_img : "https://ui-avatars.com/api/?name=" . urlencode($customer_name) . "&background=20304a&color=fff&size=50&rounded=true";
                    
                    $stages = [
                        'deposit'  => ['label' => '50% Deposit',  'amount' => $total_amount * 0.5, 'percent' => 50],
                        'progress' => ['label' => '30% Progress', 'amount' => $total_amount * 0.3, 'percent' => 30],
                        'final'    => ['label' => '20% Final',    'amount' => $total_amount * 0.2, 'percent' => 20]
                    ];

                    $pay_query = "SELECT stage, status FROM payment_records WHERE qtn_id = $qtn_id";
                    $pay_res = mysqli_query($conn, $pay_query);
                    $payment_statuses = [];
                    while ($pay_row = mysqli_fetch_assoc($pay_res)) {
                        $payment_statuses[$pay_row['stage']] = $pay_row['status'];
                    }

                    $inv_query = "SELECT * FROM invoices WHERE qtn_id = $qtn_id";
                    $inv_res = mysqli_query($conn, $inv_query);
                    $invoices_by_stage = [];
                    while ($inv_row = mysqli_fetch_assoc($inv_res)) {
                        $invoices_by_stage[$inv_row['stage']] = $inv_row;
                    }
                    
                    foreach ($stages as $stage_key => $stage_info) {
                        if (isset($payment_statuses[$stage_key]) && $payment_statuses[$stage_key] == 'Verified' && !isset($invoices_by_stage[$stage_key])) {
                            ensure_invoice_for_stage($conn, $qtn_id, $customer_id, $staff_db_id, $stage_key, $total_amount);
                        }
                    }
                    $inv_res = mysqli_query($conn, $inv_query);
                    $invoices_by_stage = [];
                    while ($inv_row = mysqli_fetch_assoc($inv_res)) {
                        $invoices_by_stage[$inv_row['stage']] = $inv_row;
                    }
                    
                    echo '<div class="card-custom mb-4">';
                    echo '<div class="card-header-custom d-flex justify-content-between align-items-center flex-wrap">';
                    echo '<div class="d-flex align-items-center gap-3">';
                    echo '<img src="' . $avatar_src . '" width="50" height="50" style="border-radius: 50%; object-fit: cover; border: 2px solid #e4e4e7;">';
                    echo '<div>';
                    echo '<h5 class="mb-0 fw-bold">' . $customer_name . '</h5>';
                    echo '<small class="text-muted">' . $customer_email . ' | CUST-' . str_pad($customer_id, 4, '0', STR_PAD_LEFT) . '</small>';
                    echo '</div></div>';
                    echo '<div class="text-end"><code>' . $qtn_number . '</code><br><small class="text-muted">Quotation</small></div>';
                    echo '</div>';
                    
                    echo '<div class="card-body p-4">';
                    echo '<div class="row g-4">';
                    
                    foreach ($stages as $stage_key => $stage_info) {
                        $inv = isset($invoices_by_stage[$stage_key]) ? $invoices_by_stage[$stage_key] : null;
                        $has_inv = ($inv !== null);
                        $payment_status = isset($payment_statuses[$stage_key]) ? $payment_statuses[$stage_key] : null;
                        $is_verified = ($payment_status === 'Verified');
                        
                        $status_badge = '';
                        if ($has_inv) {
                            $status = $inv['status'];
                            $status_badge = match($status) {
                                'Paid' => 'bg-success',
                                'Sent' => 'bg-primary',
                                'Overdue' => 'bg-danger',
                                'Cancelled' => 'bg-secondary',
                                default => 'bg-warning text-dark'
                            };
                        }
                        
                        echo '<div class="col-md-4">';
                        echo '<div class="card h-100 shadow-sm border-0 rounded-3 d-flex flex-column" style="min-height: 280px;">';
                        echo '<div class="card-body d-flex flex-column">';
                        echo '<div class="d-flex justify-content-between align-items-start mb-2">';
                        echo '<h6 class="card-title fw-bold text-primary">' . $stage_info['label'] . '</h6>';
                        if ($has_inv) echo '<span class="badge ' . $status_badge . '">' . ucfirst($inv['status']) . '</span>';
                        else echo '<span class="badge bg-secondary">Not generated</span>';
                        echo '</div>';
                        echo '<p class="card-text small text-muted">Amount: RM ' . number_format($stage_info['amount'], 2) . '</p>';
                        
                        if ($has_inv) {
                            $inv_number = htmlspecialchars($inv['invoice_number']);
                            $issue_date = $inv['issue_date'];
                            $due_date = $inv['due_date'] ?? '—';
                            $has_pdf = !empty($inv['file_path']);
                            
                            echo '<p class="card-text small mb-1"><i class="bi bi-receipt"></i> ' . $inv_number . '</p>';
                            echo '<p class="card-text small text-muted mb-1"><i class="bi bi-calendar3"></i> Issue: ' . $issue_date . '</p>';
                            echo '<p class="card-text small text-muted mb-2"><i class="bi bi-calendar-check"></i> Due: ' . $due_date . '</p>';
                            echo '<div class="mt-auto pt-2">';
                            if ($has_pdf) {
                                echo '<a href="../' . $inv['file_path'] . '" target="_blank" class="btn btn-sm btn-outline-primary w-100"><i class="bi bi-file-pdf"></i> View Invoice</a>';
                            } else {
                                echo '<span class="text-muted small"><i class="bi bi-file-earmark-x"></i> No PDF</span>';
                            }
                            echo '</div>';
                        } else {
                            echo '<div class="mt-auto pt-2">';
                            if ($is_verified) {
                                echo '<a href="create_invoice.php?qtn_id=' . $qtn_id . '&stage=' . $stage_key . '" class="btn btn-sm btn-outline-secondary w-100">Generate Invoice</a>';
                            } else {
                                echo '<div class="text-center text-muted small"><i class="bi bi-lock-fill"></i> Locked (payment not verified)</div>';
                            }
                            echo '</div>';
                        }
                        echo '</div></div></div>';
                    }
                    echo '</div></div></div>';
                }
            }
        ?>
    </div>

    <div id="section-progress" class="content-section <?php echo ($active_section == 'progress') ? 'active' : ''; ?>" style="display: <?php echo ($active_section == 'progress') ? 'block' : 'none'; ?>;">
        <?php
        $all_customers_sql = "SELECT customer_id, name, email, profile_image FROM customers WHERE status = 1";
        if (!empty($search)) {
            $all_customers_sql .= " AND (name LIKE '%$search%' OR email LIKE '%$search%')";
        }
        $all_customers_sql .= " ORDER BY name ASC";
        $all_customers_res = mysqli_query($conn, $all_customers_sql);
        
        $step_order = [
            'Deposit 50%'     => 0,
            'Order'           => 1,
            'Fabrication'     => 2,
            'Installation'    => 3,
            '30% on going job'=> 4,
            '20% complete job'=> 5
        ];
        $step_names = array_keys($step_order);
        $step_icons = ['bi-cash-stack', 'bi-receipt', 'bi-tools', 'bi-truck', 'bi-hourglass-split', 'bi-check2-circle'];
        $total_steps = count($step_names);
        
        if (mysqli_num_rows($all_customers_res) == 0) {
            echo '<div class="alert alert-info">No active customers found.</div>';
        } else {
            while ($customer = mysqli_fetch_assoc($all_customers_res)) {
                $cust_id = $customer['customer_id'];
                $cust_name = htmlspecialchars($customer['name']);
                $cust_email = htmlspecialchars($customer['email']);
                $avatar_img = $customer['profile_image'] ?? '';
                if (!empty($avatar_img) && file_exists("../" . $avatar_img)) {
                    $avatar_src = "../" . $avatar_img;
                } else {
                    $avatar_src = "https://ui-avatars.com/api/?name=" . urlencode($cust_name) . "&background=20304a&color=fff&size=60&rounded=true&bold=true";
                }
                
                $quotations_sql = "
                    SELECT qtn_id, qtn_number, 
                        (SELECT description FROM quotation_items WHERE qtn_id = q.qtn_id LIMIT 1) as product_desc
                    FROM quotations q
                    WHERE q.customer_id = $cust_id AND q.staff_id = $staff_db_id AND q.status = 'Accepted'
                    ORDER BY q.qtn_id DESC
                ";
                $quotations_res = mysqli_query($conn, $quotations_sql);
                $has_quotations = (mysqli_num_rows($quotations_res) > 0);
        ?>
                <div class="card-custom mb-4">
                    <div class="card-header-custom d-flex justify-content-between align-items-center flex-wrap">
                        <div class="d-flex align-items-center gap-3">
                            <img src="<?= $avatar_src ?>" width="50" height="50" style="border-radius: 50%; object-fit: cover; border: 2px solid #e4e4e7;">
                            <div>
                                <h5 class="mb-0 fw-bold"><?= $cust_name ?></h5>
                                <small class="text-muted">CUST-<?= str_pad($cust_id, 4, '0', STR_PAD_LEFT) ?> | <?= $cust_email ?></small>
                            </div>
                        </div>
                    </div>
                    <div class="card-body p-4">
                        <?php if (!$has_quotations): ?>
                            <div class="alert alert-light text-muted text-center py-3 mb-0">No active project assigned.</div>
                        <?php else: 
                            while ($qtn = mysqli_fetch_assoc($quotations_res)):
                                $qtn_id = $qtn['qtn_id'];
                                $qtn_number = htmlspecialchars($qtn['qtn_number']);
                                $product_name = htmlspecialchars(explode("\n", $qtn['product_desc'])[0] ?: 'Product');
                                
                                $sql_steps = "SELECT progress_step, status, notes, updated_at FROM project_progress WHERE qtn_id = $qtn_id ORDER BY updated_at ASC";
                                $steps_res = mysqli_query($conn, $sql_steps);
                                $step_status = array_fill(0, $total_steps, 'Pending');
                                $step_notes = array_fill(0, $total_steps, '');
                                $raw_records = [];
                                while ($row = mysqli_fetch_assoc($steps_res)) {
                                    $raw_records[] = $row;
                                }
                                foreach ($raw_records as $row) {
                                    $step_name = $row['progress_step'];
                                    if (isset($step_order[$step_name])) {
                                        $idx = $step_order[$step_name];
                                        if ($row['status'] == 'Completed') {
                                            $step_status[$idx] = 'Completed';
                                            $step_notes[$idx] = $row['notes'] ?? '';
                                        } elseif ($row['status'] == 'In Progress') {
                                            $step_status[$idx] = 'In Progress';
                                            $step_notes[$idx] = $row['notes'] ?? '';
                                        }
                                    }
                                }
                                $progress_records = array_reverse($raw_records);
                                $completed_steps = count(array_filter($step_status, fn($s) => $s == 'Completed'));
                                $next_step_index = -1;
                                for ($i = 0; $i < $total_steps; $i++) {
                                    if ($step_status[$i] != 'Completed') {
                                        $next_step_index = $i;
                                        break;
                                    }
                                }
                                $has_next = ($next_step_index != -1);
                        ?>
                                <div class="mb-5 pb-3 border-bottom" data-qtn-id="<?= $qtn_id ?>">
                                    <div class="d-flex justify-content-between align-items-center mb-3">
                                        <h6 class="mb-0"><code><?= $qtn_number ?></code> – <?= $product_name ?></h6>
                                        <span class="badge bg-secondary"><?= $completed_steps ?>/<?= $total_steps ?> steps</span>
                                    </div>
                                    <div style="position: relative; margin: 30px 0 20px;">
                                        <div style="position: absolute; top: 22px; left: 5%; width: 90%; height: 3px; background: #e5e7eb; z-index: 1;"></div>
                                        <?php $progress_percent = ($total_steps > 0) ? round(($completed_steps / $total_steps) * 100) : 0; ?>
                                        <div style="position: absolute; top: 22px; left: 5%; width: <?= $progress_percent ?>%; max-width: 90%; height: 3px; background: var(--success-green); z-index: 1; transition: width 0.3s ease;"></div>
                                        <div class="d-flex justify-content-between" style="position: relative; z-index: 2;">
                                            <?php foreach ($step_names as $idx => $step): 
                                                $circle_bg = 'white';
                                                $circle_border = '#e5e7eb';
                                                $icon_color = '#a1a1aa';
                                                $text_color = '#a1a1aa';
                                                $status_badge_text = '';
                                                $status_badge_class = '';
                                                $show_mark_btn = false;
                                                $show_textarea = false;
                                                $current_notes = $step_notes[$idx];
                                                
                                                if ($step_status[$idx] == 'Completed') {
                                                    $circle_bg = 'var(--success-green)';
                                                    $circle_border = 'var(--success-green)';
                                                    $icon_color = 'white';
                                                    $text_color = 'var(--text-dark)';
                                                    $status_badge_text = 'Done';
                                                    $status_badge_class = 'bg-success';
                                                } elseif ($has_next && $idx == $next_step_index) {
                                                    $circle_bg = '#3b82f6';
                                                    $circle_border = '#3b82f6';
                                                    $icon_color = 'white';
                                                    $text_color = '#3b82f6';
                                                    $status_badge_text = 'In Progress';
                                                    $status_badge_class = 'bg-primary';
                                                    $show_mark_btn = true;
                                                    $show_textarea = true;
                                                } elseif ($step_status[$idx] == 'In Progress') {
                                                    $circle_bg = '#3b82f6';
                                                    $circle_border = '#3b82f6';
                                                    $icon_color = 'white';
                                                    $text_color = '#3b82f6';
                                                    $status_badge_text = 'In Progress';
                                                    $status_badge_class = 'bg-primary';
                                                } else {
                                                    $status_badge_text = 'Pending';
                                                    $status_badge_class = 'bg-secondary';
                                                }
                                            ?>
                                                <div class="text-center" style="flex: 1;">
                                                    <div style="width: 45px; height: 45px; margin: 0 auto 8px; background: <?= $circle_bg ?>; border: 3px solid <?= $circle_border ?>; border-radius: 50%; display: flex; align-items: center; justify-content: center; color: <?= $icon_color ?>; font-size: 1.2rem;">
                                                        <i class="bi <?= $step_icons[$idx] ?>"></i>
                                                    </div>
                                                    <div style="font-size: 0.7rem; font-weight: 700; text-transform: uppercase; color: <?= $text_color ?>;"><?= $step ?></div>
                                                    <?php if (!empty($status_badge_text)): ?>
                                                        <span class="badge <?= $status_badge_class ?> mt-2"><?= $status_badge_text ?></span>
                                                    <?php endif; ?>
                                                    <?php if ($show_textarea): ?>
                                                        <textarea class="form-control mt-2 step-notes" rows="2" placeholder="Add notes (optional)..." data-qtn-id="<?= $qtn_id ?>" data-step="<?= $step ?>" data-staff-id="<?= $staff_db_id ?>" style="font-size:0.8rem;"><?= htmlspecialchars($current_notes) ?></textarea>
                                                    <?php endif; ?>
                                                    <?php if ($show_mark_btn): ?>
                                                        <button class="btn btn-sm btn-dark mt-2 mark-step-btn">Mark Complete</button>
                                                    <?php endif; ?>
                                                    <?php if ($step_status[$idx] == 'Completed' && !empty($step_notes[$idx])): ?>
                                                        <div class="small text-muted mt-1">Note: <?= nl2br(htmlspecialchars($step_notes[$idx])) ?></div>
                                                    <?php endif; ?>
                                                </div>
                                            <?php endforeach; ?>
                                        </div>
                                    </div>
                                    <?php if (!empty($progress_records)): ?>
                                    <div class="mt-3 pt-2 border-top">
                                        <h6 class="fw-bold mb-2 small"><i class="bi bi-clock-history me-1"></i>Tracking Details</h6>
                                        <div style="max-height: 200px; overflow-y: auto; font-size: 0.75rem;">
                                            <?php foreach ($progress_records as $record): 
                                                $display_step = $record['progress_step'];
                                                $status_badge = ($record['status'] == 'Completed') ? 'status-active' : (($record['status'] == 'In Progress') ? 'status-pending' : 'status-inactive');
                                                $dot_color = ($record['status'] == 'Completed') ? 'var(--success-green)' : '#d4d4d8';
                                            ?>
                                            <div style="display: flex; gap: 12px; margin-bottom: 12px;">
                                                <div style="margin-top: 4px;">
                                                    <div style="width: 8px; height: 8px; border-radius: 50%; background: <?= $dot_color ?>; border: 1px solid white; box-shadow: 0 0 0 1px <?= $dot_color ?>;"></div>
                                                </div>
                                                <div>
                                                    <div class="fw-bold"><?= htmlspecialchars($display_step) ?></div>
                                                    <div><span class="status-badge <?= $status_badge ?>" style="font-size: 0.6rem; padding: 1px 6px;"><?= $record['status'] ?></span></div>
                                                    <?php if (!empty($record['notes'])): ?>
                                                        <div class="text-muted mt-1"><i class="bi bi-chat-left-text"></i> <?= nl2br(htmlspecialchars($record['notes'])) ?></div>
                                                    <?php endif; ?>
                                                    <div class="text-muted mt-1"><?= date('d M Y, H:i', strtotime($record['updated_at'])) ?></div>
                                                </div>
                                            </div>
                                            <?php endforeach; ?>
                                        </div>
                                    </div>
                                    <?php endif; ?>
                                </div>
                        <?php 
                            endwhile;
                        endif; 
                        ?>
                    </div>
                </div>
        <?php
            }
        }
        ?>
    </div>

    <div id="section-appointment" class="content-section <?php echo ($active_section == 'appointment') ? 'active' : ''; ?>" style="display: <?php echo ($active_section == 'appointment') ? 'block' : 'none'; ?>;">
        <?php
        $staff_email = $staff_info['email'] ?? '';
        $team_calendar_src = '';
        if (!empty($staff_email)) {
            $team_calendar_src = "https://calendar.google.com/calendar/embed?mode=DAY&date={$today_date}&ctz=Asia%2FKuala_Lumpur&src=" . urlencode($staff_email);
        }
        ?>
        <div class="row">
            <div class="col-12 mb-4">
                <div class="d-flex justify-content-between align-items-center flex-wrap">
                    <div>
                        <h4 class="fw-bold mb-1"><i class="bi bi-calendar-check me-2"></i>My Appointment Scheduler</h4>
                        <p class="text-muted mb-0">Manage your appointments with customers.</p>
                    </div>
                    <?php if (!empty($staff_info['appointment_calendar'])): ?>
                        <a href="<?= htmlspecialchars($staff_info['appointment_calendar']) ?>" target="_blank" class="btn btn-dark btn-sm"><i class="bi bi-pencil-square"></i> Edit in Google Calendar</a>
                    <?php endif; ?>
                </div>
            </div>
        </div>
        <div class="card-custom">
            <div class="card-header-custom"><h5 class="mb-0 fw-bold"><i class="bi bi-google me-2"></i>My Appointment Scheduler</h5></div>
            <div class="card-body p-0">
                <?php if (!empty($staff_info['appointment_calendar'])): ?>
                    <iframe src="<?= htmlspecialchars($staff_info['appointment_calendar']) ?>?gv=true" style="border:0; width:100%; height:700px;" frameborder="0" scrolling="yes"></iframe>
                <?php else: ?>
                    <div class="alert alert-warning m-3">No appointment scheduler link configured. Please contact admin.</div>
                <?php endif; ?>
            </div>
        </div>
        <?php if (!empty($staff_email)): ?>
        <div class="row mt-4">
            <div class="col-12">
                <div class="card-custom">
                    <div class="card-header-custom"><h5 class="mb-0 fw-bold"><i class="bi bi-calendar3 me-2"></i>My Calendar View</h5></div>
                    <div class="card-body p-0">
                        <?php if (!empty($team_calendar_src)): ?>
                            <iframe src="<?= $team_calendar_src ?>" style="border:0; width:100%; height:600px;" frameborder="0" scrolling="no"></iframe>
                        <?php else: ?>
                            <div class="alert alert-info m-3">No calendar available for your account.</div>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        </div>
        <?php endif; ?>
    </div>

    <div id="section-payment" class="content-section <?php echo ($active_section == 'payment') ? 'active' : ''; ?>" style="display: <?php echo ($active_section == 'payment') ? 'block' : 'none'; ?>;">
        <div class="card-custom mb-3">
            <div class="card-header-custom">
                <h5 class="mb-0"><i class="bi bi-credit-card-2-front me-2"></i>Customer Payment Progress</h5>
            </div>
        </div>

        <?php
        $customers_sql = "SELECT customer_id, name, email, profile_image FROM customers 
                  WHERE status = 1 AND customer_id IN (SELECT DISTINCT customer_id FROM quotations WHERE staff_id = $staff_db_id)";
        if (!empty($search)) {
            $customers_sql .= " AND (name LIKE '%$search%' OR email LIKE '%$search%')";
        }
        $customers_sql .= " ORDER BY name ASC";
        $customers_res = mysqli_query($conn, $customers_sql);
        
        if (mysqli_num_rows($customers_res) == 0) {
            echo '<div class="card-custom text-center py-5"><i class="bi bi-inbox fs-1 text-muted"></i><p class="mt-2 text-muted">No active customers found.</p></div>';
        } else {
            while ($customer = mysqli_fetch_assoc($customers_res)) {
                $cust_id    = $customer['customer_id'];
                $cust_name  = htmlspecialchars($customer['name']);
                $cust_email = htmlspecialchars($customer['email']);
                $avatar_img = $customer['profile_image'] ?? '';
                $avatar_src = (!empty($avatar_img) && file_exists("../" . $avatar_img)) ? "../" . $avatar_img : "https://ui-avatars.com/api/?name=" . urlencode($cust_name) . "&background=20304a&color=fff&size=50&rounded=true&bold=true";

                $quotations_sql = "SELECT qtn_id, qtn_number, total_amount, created_at,
                                        (SELECT CASE WHEN qi.product_id IS NOT NULL THEN p.door_brand ELSE SUBSTRING_INDEX(qi.description, '\n', 1) END 
                                        FROM quotation_items qi LEFT JOIN products p ON qi.product_id = p.product_id WHERE qi.qtn_id = q.qtn_id LIMIT 1) as product_name
                                FROM quotations q
                                WHERE q.customer_id = $cust_id AND q.status = 'Accepted'
                                ORDER BY q.qtn_id DESC";
                $quotations_res = mysqli_query($conn, $quotations_sql);
                $has_quotations = (mysqli_num_rows($quotations_res) > 0);
                
                if (!$has_quotations) {
                    ?>
                    <div class="card-custom mb-4">
                        <div class="card-header-custom d-flex justify-content-between align-items-center flex-wrap">
                            <div class="d-flex align-items-center gap-3">
                                <img src="<?= $avatar_src ?>" width="50" height="50" style="border-radius: 50%; object-fit: cover; border: 2px solid #e4e4e7;">
                                <div>
                                    <h5 class="mb-0 fw-bold"><?= $cust_name ?></h5>
                                    <small class="text-muted">CUST-<?= str_pad($cust_id, 4, '0', STR_PAD_LEFT) ?> | <?= $cust_email ?></small>
                                </div>
                            </div>
                            <div class="text-end text-muted small">
                                —
                            </div>
                        </div>
                        <div class="card-body text-center text-muted py-4">
                            No accepted quotation yet.
                        </div>
                    </div>
                    <?php
                    continue;
                }

                while ($quote = mysqli_fetch_assoc($quotations_res)) {
                    $qtn_id       = $quote['qtn_id'];
                    $qtn_number   = htmlspecialchars($quote['qtn_number']);
                    $total_amount = floatval($quote['total_amount']);
                    $product_name = htmlspecialchars($quote['product_name'] ?? 'Product');
                    $quote_created = date('d M Y', strtotime($quote['created_at']));

                    $pay_query = "SELECT stage, id, file_path, status, staff_notes, uploaded_at FROM payment_records WHERE qtn_id = $qtn_id";
                    $pay_res = mysqli_query($conn, $pay_query);
                    $payments = [];
                    while ($pay_row = mysqli_fetch_assoc($pay_res)) {
                        $payments[$pay_row['stage']] = $pay_row;
                    }

                    $stages = [
                        'deposit'  => ['label' => '50% Deposit',  'percent' => 50, 'amount' => $total_amount * 0.5],
                        'progress' => ['label' => '30% Progress', 'percent' => 30, 'amount' => $total_amount * 0.3],
                        'final'    => ['label' => '20% Final',    'percent' => 20, 'amount' => $total_amount * 0.2]
                    ];

                    $deposit_verified  = isset($payments['deposit']) && $payments['deposit']['status'] == 'Verified';
                    $progress_verified = isset($payments['progress']) && $payments['progress']['status'] == 'Verified';
                    $current_unlocked_stage = null;
                    if (!$deposit_verified) $current_unlocked_stage = 'deposit';
                    elseif (!$progress_verified) $current_unlocked_stage = 'progress';
                    else $current_unlocked_stage = 'final';
                    
                    ?>
                    <div class="card-custom mb-4">
                        <div class="card-header-custom d-flex justify-content-between align-items-center flex-wrap">
                            <div class="d-flex align-items-center gap-3">
                                <img src="<?= $avatar_src ?>" width="50" height="50" style="border-radius: 50%; object-fit: cover; border: 2px solid #e4e4e7;">
                                <div>
                                    <h5 class="mb-0 fw-bold"><?= $cust_name ?></h5>
                                    <small class="text-muted">CUST-<?= str_pad($cust_id, 4, '0', STR_PAD_LEFT) ?> | <?= $cust_email ?></small>
                                </div>
                            </div>
                            <div class="text-end">
                                <code><?= $qtn_number ?></code> – <span class="fw-bold"><?= $product_name ?></span><br>
                                <small class="text-muted">Quotation created: <?= $quote_created ?></small>
                            </div>
                        </div>
                        <div class="card-body p-4">
                            <div class="d-flex justify-content-between align-items-center mb-3">
                                <h6 class="fw-bold mb-0">Payment Stages</h6>
                                <span class="badge bg-dark fs-6 p-2">Total: RM <?= number_format($total_amount, 2) ?></span>
                            </div>
                            <div class="row g-3">
                                <?php foreach ($stages as $stage_key => $stage_info):
                                    $record = $payments[$stage_key] ?? null;
                                    $status = $record ? $record['status'] : null;
                                    $is_unlocked = ($current_unlocked_stage === $stage_key);
                                    $is_locked = (!$is_unlocked);
                                    $stage_amount = number_format($stage_info['amount'], 2);

                                    $is_manual = $record && strpos($record['staff_notes'] ?? '', '[Manual completion by staff]') !== false;
                                    $status_display = $status ? ucfirst($status) : ($is_locked ? 'Locked' : 'Not uploaded');
                                    if ($status == 'Completed' && $is_manual) {
                                        $status_display = 'Manual ✔️';
                                    }
                                ?>
                                    <div class="col-md-4">
                                        <div class="border rounded p-3 h-100 d-flex flex-column">
                                            <div class="d-flex justify-content-between align-items-center mb-2">
                                                <strong><?= $stage_info['label'] ?></strong>
                                                <span class="badge <?= ($status == 'Verified') ? 'bg-success' : (($status == 'Pending') ? 'bg-warning text-dark' : (($status == 'Rejected') ? 'bg-danger' : 'bg-secondary')) ?>">
                                                    <?= $status_display ?>
                                                </span>
                                            </div>
                                            <div class="small mb-2">Amount: RM <?= $stage_amount ?></div>
                                            
                                            <?php if ($record && $record['uploaded_at']): ?>
                                                <div class="small text-muted mb-2">
                                                    <i class="bi bi-clock"></i> Uploaded: <?= date('d M Y H:i', strtotime($record['uploaded_at'])) ?>
                                                </div>
                                            <?php else: ?>
                                                <div class="small text-muted mb-2">—</div>
                                            <?php endif; ?>
                                            
                                            <div class="mt-auto d-flex justify-content-between align-items-center">
                                                <?php if ($record && $record['file_path']): ?>
                                                    <a href="../<?= $record['file_path'] ?>" target="_blank" class="btn-action btn-sm"><i class="bi bi-eye"></i> Receipt</a>
                                                <?php else: ?>
                                                    <span></span>
                                                <?php endif; ?>
                                                
                                                <div class="d-flex gap-1">
                                                    <?php if ($status == 'Pending'): ?>
                                                        <button class="btn-action btn-sm" data-bs-toggle="modal" data-bs-target="#verifyPaymentModal" 
                                                                data-id="<?= $record['id'] ?>"
                                                                data-customer="<?= $cust_name ?>"
                                                                data-qtn="<?= $qtn_number ?>"
                                                                data-customer-id="<?= $cust_id ?>"
                                                                data-action="verify">Verify</button>
                                                        <button class="btn-action btn-sm btn-reject" data-bs-toggle="modal" data-bs-target="#verifyPaymentModal" 
                                                                data-id="<?= $record['id'] ?>"
                                                                data-customer="<?= $cust_name ?>"
                                                                data-qtn="<?= $qtn_number ?>"
                                                                data-customer-id="<?= $cust_id ?>"
                                                                data-action="reject">Reject</button>
                                                    <?php elseif ($status == 'Rejected'): ?>
                                                        <span class="text-muted">Rejected</span>
                                                    <?php elseif ($status == 'Verified'): ?>
                                                        <a href="javascript:void(0);" onclick="showPage('invoice', null);" class="btn btn-sm btn-outline-info">Go to Invoice</a>
                                                    <?php elseif ($is_unlocked && !$record): ?>
                                                        <span class="text-muted">—</span>
                                                    <?php endif; ?>
                                                </div>
                                            </div>
                                            
                                            <?php if ($record && $record['staff_notes']): ?>
                                                <div class="small text-muted mt-2">Note: <?= htmlspecialchars($record['staff_notes']) ?></div>
                                            <?php endif; ?>
                                        </div>
                                    </div>
                                <?php endforeach; ?>
                            </div>
                        </div>
                    </div>
                    <?php
                }
            }
        }
        ?>
    </div>

    <div id="section-chat" class="content-section" style="display: none;">
        <div class="chat-main-container">
            <div class="chat-user-sidebar">
                <div class="chat-user-header">
                    <i class="bi bi-chat-dots-fill me-2"></i> Customers
                </div>
                <div class="chat-user-list" id="chatUserList">
                    <div class="text-center text-muted p-4">Loading...</div>
                </div>
            </div>
            <div class="chat-room">
                <div class="chat-room-header">
                    <h5 id="chatWithLabel" class="mb-0">Select a customer</h5>
                    <button class="btn-delete-chat" id="deleteChatBtn" style="display: none;" onclick="confirmDeleteChat()">
                        <i class="bi bi-trash3"></i> Delete Chat
                    </button>
                </div>
                <div class="chat-messages-area" id="chatMessagesArea">
                    <div class="chat-empty-placeholder">
                        <i class="bi bi-chat-left-text" style="font-size: 2rem;"></i>
                        <p class="mt-2">No conversation selected</p>
                    </div>
                </div>
                <div class="chat-input-area" id="chatInputArea" style="display: none;">
                    <input type="text" id="chatMessageInput" placeholder="Type your message..." autocomplete="off">
                    <button id="chatSendBtn">Send <i class="bi bi-send-fill"></i></button>
                </div>
            </div>
        </div>
    </div>
</div>

<div class="modal fade" id="registerCustomerModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered modal-dialog-scrollable">
        <div class="modal-content">
            <div class="modal-header">
                <h5><i class="bi bi-person-plus me-2"></i>Register New Customer</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <form action="" method="POST" enctype="multipart/form-data">
                    <div class="mb-3">
                        <label class="form-label small fw-bold">Full Name <span class="text-danger">*</span></label>
                        <input type="text" name="cust_name" class="form-control" required>
                    </div>
                    <div class="mb-3">
                        <label class="form-label small fw-bold">Email <span class="text-danger">*</span></label>
                        <input type="email" name="email" class="form-control" required>
                    </div>
                    <div class="mb-3">
                        <label class="form-label small fw-bold">Phone Number <span class="text-danger">*</span></label>
                        <input type="text" name="phone" class="form-control" required>
                    </div>
                    <div class="row">
                        <div class="col-6 mb-3">
                            <label class="form-label small fw-bold">Gender</label>
                            <select name="gender" class="form-select">
                                <option value="Male">Male</option>
                                <option value="Female">Female</option>
                            </select>
                        </div>
                        <div class="col-6 mb-3">
                            <label class="form-label small fw-bold">Race</label>
                            <select name="race" class="form-select">
                                <option value="Malay">Malay</option>
                                <option value="Chinese">Chinese</option>
                                <option value="Indian">Indian</option>
                                <option value="Other">Other</option>
                            </select>
                        </div>
                    </div>
                    <div class="mb-3">
                        <label class="form-label small fw-bold">Address <span class="text-danger">*</span></label>
                        <textarea name="address" class="form-control" rows="2" required></textarea>
                    </div>
                    <div class="mb-3">
                        <label class="form-label fw-bold">Interested Products</label>
                        <div class="row" style="max-height: 200px; overflow-y: auto; border:1px solid #e4e4e7; border-radius:8px; padding:10px;">
                            <?php foreach($all_products as $p): ?>
                                <div class="col-md-4 col-6">
                                    <div class="form-check">
                                        <input type="checkbox" name="selected_products[]" value="<?= $p['product_id'] ?>" class="form-check-input" id="prod_<?= $p['product_id'] ?>">
                                        <label class="form-check-label small" for="prod_<?= $p['product_id'] ?>"><?= htmlspecialchars($p['door_brand']) ?></label>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        </div>
                        <small class="text-muted">Select all products the customer is interested in.</small>
                    </div>
                    <div class="mb-3">
                        <label class="form-label small fw-bold">Password <span class="text-danger">*</span></label>
                        <div class="input-group">
                            <input type="password" name="password" id="customer_password" class="form-control" required minlength="6">
                            <button class="btn btn-outline-secondary toggle-password" type="button" data-target="customer_password">
                                <i class="bi bi-eye-slash"></i>
                            </button>
                        </div>
                    </div>
                    <div class="mb-3">
                        <label class="form-label small fw-bold">Confirm Password <span class="text-danger">*</span></label>
                        <div class="input-group">
                            <input type="password" name="confirm_password" id="customer_confirm_password" class="form-control" required minlength="6">
                            <button class="btn btn-outline-secondary toggle-password" type="button" data-target="customer_confirm_password">
                                <i class="bi bi-eye-slash"></i>
                            </button>
                        </div>
                    </div>
                    <div class="mb-3">
                        <label class="form-label small fw-bold">Profile Image (Optional)</label>
                        <input type="file" name="profile_image" class="form-control" accept="image/*">
                        <div class="form-text">Leave empty to use default avatar.</div>
                    </div>
                    <button type="submit" name="register_customer" class="btn btn-dark w-100" onclick="return validateCustomerPasswords()">Create Account</button>
                </form>
            </div>
        </div>
    </div>
</div>

<div class="modal fade" id="editCustomerModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content">
            <div class="modal-header">
                <h5><i class="bi bi-pencil-square me-2"></i>Edit Customer</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <form action="" method="POST" enctype="multipart/form-data">
                    <input type="hidden" name="customer_id" id="edit_id">
                    <div class="mb-3">
                        <label class="form-label small fw-bold">Full Name <span class="text-danger">*</span></label>
                        <input type="text" name="name" id="edit_name" class="form-control" required>
                    </div>
                    <div class="mb-3">
                        <label class="form-label small fw-bold">Email <span class="text-danger">*</span></label>
                        <input type="email" name="email" id="edit_email" class="form-control" required>
                    </div>
                    <div class="mb-3">
                        <label class="form-label small fw-bold">Phone Number <span class="text-danger">*</span></label>
                        <input type="text" name="phone" id="edit_phone" class="form-control" required>
                    </div>
                    <div class="row">
                        <div class="col-6 mb-3">
                            <label class="form-label small fw-bold">Gender</label>
                            <select name="gender" id="edit_gender" class="form-select">
                                <option value="Male">Male</option>
                                <option value="Female">Female</option>
                            </select>
                        </div>
                        <div class="col-6 mb-3">
                            <label class="form-label small fw-bold">Race</label>
                            <select name="race" id="edit_race" class="form-select">
                                <option value="Malay">Malay</option>
                                <option value="Chinese">Chinese</option>
                                <option value="Indian">Indian</option>
                                <option value="Other">Other</option>
                            </select>
                        </div>
                    </div>
                    <div class="col-12 mb-3">
                        <label>Address <span class="text-danger">*</span></label>
                        <textarea name="address" id="edit_address" class="form-control" rows="2" required></textarea>
                    </div>
                    <div class="col-12 mb-3">
                        <div class="col-12 mb-3">
                            <label class="form-label fw-bold">Interested Products</label>
                            <div class="row" style="max-height: 300px; overflow-y: auto; border: 1px solid #e4e4e7; border-radius: 8px; padding: 10px;">
                                <?php foreach($all_products as $p): ?>
                                    <div class="col-md-4 col-6">
                                        <div class="form-check">
                                            <input type="checkbox" name="selected_products[]" value="<?= $p['product_id'] ?>" class="form-check-input" id="edit_prod_<?= $p['product_id'] ?>">
                                            <label class="form-check-label small" for="edit_prod_<?= $p['product_id'] ?>"><?= htmlspecialchars($p['door_brand']) ?></label>
                                        </div>
                                    </div>
                                <?php endforeach; ?>
                            </div>
                        </div>
                        <small class="text-muted">Select all products the customer is interested in.</small>
                    </div>
                    <div class="mb-3">
                        <label class="form-label small fw-bold">Profile Image (Optional)</label>
                        <input type="file" name="profile_image" class="form-control" accept="image/*">
                        <div class="form-text mb-3">Leave empty to keep existing image.</div>
                    </div>
                    <button type="submit" name="update_customer" class="btn btn-primary w-100">Update Information</button>
                </form>
            </div>
        </div>
    </div>
</div>

<div class="modal fade" id="changePasswordModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content">
            <div class="modal-header">
                <h5><i class="bi bi-key me-2"></i>Change Password</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <form action="" method="POST">
                    <div class="mb-3">
                        <label class="form-label small fw-bold">Current Password</label>
                        <input type="password" name="current_password" class="form-control" required>
                    </div>
                    <div class="mb-3">
                        <label class="form-label small fw-bold">New Password</label>
                        <input type="password" name="new_password" class="form-control" required minlength="6">
                    </div>
                    <div class="mb-3">
                        <label class="form-label small fw-bold">Confirm New Password</label>
                        <input type="password" name="confirm_password" class="form-control" required minlength="6">
                    </div>
                    <button type="submit" name="change_staff_password" class="btn btn-dark w-100">Update Password</button>
                </form>
            </div>
        </div>
    </div>
</div>

<div class="modal fade" id="verifyPaymentModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title" id="verifyModalTitle"><i class="bi bi-credit-card me-2"></i>Update Payment Status</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <form action="" method="POST">
                <div class="modal-body">
                    <input type="hidden" name="payment_id" id="modal_payment_id">
                    <input type="hidden" name="payment_status" id="modal_payment_status">
                    <input type="hidden" name="customer_id" id="modal_customer_id">

                    <div class="mb-3">
                        <p><strong>Customer:</strong> <span id="modal_customer_name"></span></p>
                        <p><strong>Quotation:</strong> <span id="modal_qtn_number"></span></p>
                    </div>

                    <div class="mb-3" id="notes_container">
                        <label class="form-label fw-bold" id="notes_label">Staff Notes <span class="text-danger">*</span></label>
                        <textarea name="staff_notes" id="modal_staff_notes" class="form-control" rows="3" required></textarea>
                        <small class="text-muted" id="notes_hint">Explain the reason if rejecting, or add a confirmation note if verifying.</small>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" name="update_payment_status" class="btn btn-primary" id="modal_submit_btn">Confirm</button>
                </div>
            </form>
        </div>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
<script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/js/select2.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.0/dist/chart.umd.min.js"></script>
<script>
    const staffData = {
        id: <?php echo $staff_db_id; ?>,
        name: "<?php echo addslashes($staff_info['staff_name']); ?>",
        email: "<?php echo addslashes($staff_info['email']); ?>",
        phone: "<?php echo addslashes($staff_info['phone']); ?>",
        photo: "<?php echo htmlspecialchars($staff_info['profile_image']); ?>", 
        appointment_calendar: "<?php echo addslashes($staff_info['appointment_calendar']); ?>"
    };
    const chartDataSets = {
        weekly: {
            labels: <?php echo json_encode($weekly_labels); ?>,
            values: <?php echo json_encode($weekly_values); ?>
        },
        monthly: {
            labels: <?php echo json_encode($monthly_labels); ?>,
            values: <?php echo json_encode($monthly_values); ?>
        },
        yearly: {
            labels: <?php echo json_encode($yearly_labels); ?>,
            values: <?php echo json_encode($yearly_values); ?>
        }
    };

    function showPage(pageId, element) {
        document.getElementById('searchGroup').style.display = (pageId === 'dashboard' || pageId === 'profile' || pageId === 'appointment') ? 'none' : 'block';
        document.getElementById('activeSectionInput').value = pageId;
        const url = new URL(window.location.href);
        url.searchParams.set('active_section', pageId);
        window.history.pushState({}, '', url);
        document.querySelectorAll('.content-section').forEach(s => { s.classList.remove('active'); s.style.display = 'none'; });
        document.getElementById('profile-content').style.display = 'none';
        if (pageId === 'profile') {
            document.getElementById('profile-content').style.display = 'block';
            const container = document.getElementById('profile-content');
            const defaultAvatar = `https://ui-avatars.com/api/?name=${encodeURIComponent(staffData.name)}&background=f0f0f0&color=666666&size=150&bold=true`;
            const avatarSrc = (staffData.photo && staffData.photo !== 'null' && staffData.photo.trim() !== '') ? staffData.photo : defaultAvatar;
            container.innerHTML = `
            <div class="card-custom">
                <div class="profile-cover">
                </div>
                <div style="display: flex; align-items: flex-end; gap: 25px; padding: 0 30px 20px 30px;">
                    <div class="profile-avatar"><img id="profileAvatar" src="../${avatarSrc}" alt="Avatar" onerror="this.onerror=null; this.src='${defaultAvatar}';">
                    <div class="camera-icon" onclick="document.getElementById('avatarInput').click()"><i class="bi bi-camera"></i>
                    </div>
                    <input type="file" id="avatarInput" style="display:none" accept="image/*" onchange="handleAvatarChange(this)">
                </div>
                <div class="profile-info" style="padding-left:0;">
                    <h2 style="margin:0;font-weight:800;">${staffData.name}</h2>
                    <p class="text-muted">
                        <i class="bi bi-shield-check" style="color: var(--success-green);">
                        </i> Verified Member
                    </p>
                </div>
            </div>
            <div class="card-header-custom" style="border-top:1px solid var(--border-color);margin-top:0;">
                <h5 class="mb-0">
                <i class="bi bi-person-lines-fill me-2">
                </i> Account Details</h5>
            </div>
            <div class="p-4">
                <form id="staffProfileForm" method="POST" enctype="multipart/form-data" style="display:grid;grid-template-columns:1fr 1fr;gap:25px;">
                <div class="form-group">
                <label class="form-label small fw-bold">
                    User ID
                </label>
                <input type="text" class="form-control" value="STAFF-${String(staffData.id).padStart(4,'0')}" disabled style="background:#f9f9f9;">
            </div>
            <div class="form-group">
                <label class="form-label small fw-bold">
                    Full Name
                </label>
                <input type="text" name="staff_name" class="form-control" value="${staffData.name}" required>
            </div>
            <div class="form-group">
                <label class="form-label small fw-bold">Email Address</label>
                <input type="email" class="form-control" value="${staffData.email}" disabled style="background:#f9f9f9;">
            </div>
            <div class="form-group">
                <label class="form-label small fw-bold">Phone Number</label>
                <input type="text" name="staff_phone" class="form-control" value="${staffData.phone}">
            </div>
            <div style="grid-column:span 2; display:flex; justify-content:space-between; align-items:center;">
                <button type="button" class="btn btn-outline-secondary" data-bs-toggle="modal" data-bs-target="#changePasswordModal">
                    <i class="bi bi-key me-2"></i>Change Password
                </button>
                <button type="submit" name="update_staff_profile" class="btn-dark-custom">
                    Save Information
                </button>
            </div>
        </form>
    </div>
</div>`;
            container.style.display = 'block';
        } else if (pageId === 'appointment') {
            const target = document.getElementById('section-appointment');
            if (target) {
                target.style.display = 'block';
                target.classList.add('active');
            }
        } else {
            const target = document.getElementById('section-' + pageId);
            if (target) { target.style.display = 'block'; target.classList.add('active'); }
            if (pageId === 'chat') {
                initChatModule();
            }
            if (pageId === 'payment') setTimeout(() => { $('.select2-customer').select2({ placeholder: '-- Select Customer --', allowClear: true, width: '100%' }); }, 50);
        }
        document.querySelectorAll('.nav-menu li').forEach(li => li.classList.remove('active'));
        if (element) element.classList.add('active');
        if (window.innerWidth <= 768) document.querySelector('.sidebar').classList.remove('show');
    }

    let currentChatCustomer = null;
    let chatRefreshInterval = null;

    function initChatModule() {
        const sendBtn = document.getElementById('chatSendBtn');
        const msgInput = document.getElementById('chatMessageInput');
        
        sendBtn.removeEventListener('click', sendChatMessage);
        msgInput.removeEventListener('keypress', chatKeyPressHandler);
        
        sendBtn.addEventListener('click', sendChatMessage);
        msgInput.addEventListener('keypress', chatKeyPressHandler);
        
        loadChatCustomerList();
    }

    function chatKeyPressHandler(e) {
        if (e.key === 'Enter') sendChatMessage();
    }

    function loadChatCustomerList() {
        fetch('chat_api.php?action=conversations')
            .then(res => res.json())
            .then(data => {
                const container = document.getElementById('chatUserList');
                if (!container) return;
                if (!data || data.length === 0) {
                    container.innerHTML = '<div class="text-center text-muted p-4">No customers yet</div>';
                    return;
                }
                let html = '';
                data.forEach(conv => {
                    const isActive = (currentChatCustomer === conv.session_id);
                    const unreadHtml = (conv.unread_staff > 0) ? `<span class="chat-unread-badge">${conv.unread_staff}</span>` : '';
                    html += `
                        <div class="chat-user-item ${isActive ? 'active' : ''}" data-session="${conv.session_id}" onclick="selectChatCustomer('${conv.session_id.replace(/'/g, "\\'")}')">
                            <span class="chat-user-name"><i class="bi bi-person-circle me-2"></i>${escapeHtml(conv.name)}</span>
                            ${unreadHtml}
                        </div>
                    `;
                });
                container.innerHTML = html;
                const totalUnread = data.reduce((sum, c) => sum + (c.unread_staff || 0), 0);
                const badge = document.getElementById('chatUnreadBadge');
                if (badge) {
                    if (totalUnread > 0) {
                        badge.textContent = totalUnread > 99 ? '99+' : totalUnread;
                        badge.style.display = 'inline-block';
                    } else {
                        badge.style.display = 'none';
                    }
                }
            })
            .catch(err => console.error('loadChatCustomerList error:', err));
    }

    function selectChatCustomer(sessionId) {
        if (currentChatCustomer === sessionId) return;
        currentChatCustomer = sessionId;
        document.querySelectorAll('.chat-user-item').forEach(item => {
            if (item.getAttribute('data-session') === sessionId) {
                item.classList.add('active');
            } else {
                item.classList.remove('active');
            }
        });
        const convName = document.querySelector(`.chat-user-item[data-session="${sessionId}"] .chat-user-name`)?.innerText || sessionId;
        document.getElementById('chatWithLabel').innerHTML = `<i class="bi bi-person-circle me-1"></i> ${convName}`;
        document.getElementById('deleteChatBtn').style.display = 'inline-block';
        document.getElementById('chatInputArea').style.display = 'flex';
        loadChatMessages(sessionId);
        markChatAsRead(sessionId);
        if (chatRefreshInterval) clearInterval(chatRefreshInterval);
        chatRefreshInterval = setInterval(() => {
            if (currentChatCustomer && document.getElementById('section-chat').style.display !== 'none') {
                loadChatMessages(currentChatCustomer);
            }
        }, 5000);
    }

    function loadChatMessages(sessionId) {
        fetch(`chat_api.php?action=messages&session_id=${encodeURIComponent(sessionId)}`)
            .then(res => res.json())
            .then(data => {
                const container = document.getElementById('chatMessagesArea');
                if (data.error) {
                    container.innerHTML = '<div class="chat-empty-placeholder text-danger">Failed to load messages</div>';
                    return;
                }
                if (!data.messages || data.messages.length === 0) {
                    container.innerHTML = '<div class="chat-empty-placeholder"><i class="bi bi-chat-dots"></i><p class="mt-2">No messages yet. Start the conversation!</p></div>';
                    return;
                }
                let html = '';
                data.messages.forEach(msg => {
                    const isStaff = (msg.sender === 'staff');
                    const bubbleClass = isStaff ? 'chat-msg-staff' : 'chat-msg-customer';
                    html += `<div class="chat-msg-bubble ${bubbleClass}">${escapeHtml(msg.message)}</div>`;
                });
                container.innerHTML = html;
                container.scrollTop = container.scrollHeight;
                loadChatCustomerList();
            })
            .catch(err => {
                console.error('loadChatMessages error:', err);
                document.getElementById('chatMessagesArea').innerHTML = '<div class="chat-empty-placeholder text-danger">Network error</div>';
            });
    }

    let isSending = false;
    function sendChatMessage() {
        if (isSending) return;
        const input = document.getElementById('chatMessageInput');
        const text = input.value.trim();
        if (!text || !currentChatCustomer) return;
        isSending = true;
        fetch('chat_api.php?action=send', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ session_id: currentChatCustomer, message: text })
        })
        .then(res => res.json())
        .then(data => {
            if (data.success) {
                input.value = '';
                loadChatMessages(currentChatCustomer);
                loadChatCustomerList();
            } else {
                alert('Failed to send message: ' + (data.message || 'Unknown error'));
            }
        })
        .catch(err => console.error('sendChatMessage error:', err))
        .finally(() => { isSending = false; });
    }

    function markChatAsRead(sessionId) {
        fetch('chat_api.php?action=mark_read', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ session_id: sessionId })
        }).catch(e => console.warn('mark_read failed', e));
    }

    function confirmDeleteChat() {
        if (!currentChatCustomer) return;
        if (confirm(`Are you sure you want to delete all messages with this customer?`)) {
            fetch('chat_api.php?action=delete_conversation', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ session_id: currentChatCustomer })
            })
            .then(res => res.json())
            .then(data => {
                if (data.success) {
                    document.getElementById('chatMessagesArea').innerHTML = '<div class="chat-empty-placeholder"><i class="bi bi-chat-left-text"></i><p>Conversation deleted</p></div>';
                    document.getElementById('chatWithLabel').innerHTML = 'Select a customer';
                    document.getElementById('deleteChatBtn').style.display = 'none';
                    document.getElementById('chatInputArea').style.display = 'none';
                    currentChatCustomer = null;
                    if (chatRefreshInterval) clearInterval(chatRefreshInterval);
                    loadChatCustomerList();
                } else {
                    alert('Failed to delete conversation');
                }
            })
            .catch(err => {
                console.error(err);
                alert('Network error');
            });
        }
    }

    function escapeHtml(str) {
        if (!str) return '';
        return str.replace(/[&<>]/g, m => {
            if (m === '&') return '&amp;';
            if (m === '<') return '&lt;';
            if (m === '>') return '&gt;';
            return m;
        });
    }

    document.querySelectorAll('.edit-btn').forEach(btn => {
        btn.addEventListener('click', function() {
            document.getElementById('edit_id').value = this.dataset.id;
            document.getElementById('edit_name').value = this.dataset.name;
            document.getElementById('edit_email').value = this.dataset.email;
            document.getElementById('edit_phone').value = this.dataset.phone;
            document.getElementById('edit_gender').value = this.dataset.gender;
            document.getElementById('edit_race').value = this.dataset.race;
            document.getElementById('edit_address').value = this.dataset.address;
        });
    });

    window.onload = () => {
        const active = document.getElementById('activeSectionInput').value;
        showPage(active || 'dashboard', document.querySelector(`.nav-menu li[data-section="${active}"]`));
        $('.select2-customer').select2({ placeholder: '-- Select Customer --', allowClear: true, width: '100%' });
    };

    function handleAvatarChange(input) {
        if (input.files[0]) {
            const reader = new FileReader();
            reader.onload = e => document.getElementById('profileAvatar').src = e.target.result;
            reader.readAsDataURL(input.files[0]);
            const fd = new FormData(); fd.append('avatar', input.files[0]);
            fetch('upload_staff_avatar.php', { method: 'POST', body: fd }).then(r => r.json()).then(d => { if(d.success) staffData.photo = d.new_path; });
        }
    }

    const verifyModal = document.getElementById('verifyPaymentModal');
    if (verifyModal) {
        verifyModal.addEventListener('show.bs.modal', function (event) {
            const button = event.relatedTarget;
            document.getElementById('modal_payment_id').value = button.getAttribute('data-id');
            document.getElementById('modal_payment_status').value = (button.getAttribute('data-action') === 'verify') ? 'Verified' : 'Rejected';
            document.getElementById('modal_customer_name').textContent = button.getAttribute('data-customer');
            document.getElementById('modal_qtn_number').textContent = button.getAttribute('data-qtn');
            document.getElementById('modal_customer_id').value = button.getAttribute('data-customer-id');
            document.getElementById('modal_staff_notes').value = '';
            const title = document.getElementById('verifyModalTitle');
            const submitBtn = document.getElementById('modal_submit_btn');
            const notesLabel = document.getElementById('notes_label');
            const notesField = document.getElementById('modal_staff_notes');
            if (button.getAttribute('data-action') === 'verify') {
                title.textContent = 'Verify Payment';
                submitBtn.textContent = 'Verify';
                submitBtn.classList.remove('btn-danger'); submitBtn.classList.add('btn-success');
                notesLabel.innerHTML = 'Staff Notes (Optional)';
                notesField.required = false;
            } else {
                title.textContent = 'Reject Payment';
                submitBtn.textContent = 'Reject';
                submitBtn.classList.remove('btn-success'); submitBtn.classList.add('btn-danger');
                notesLabel.innerHTML = 'Rejection Reason <span class="text-danger">*</span>';
                notesField.required = true;
            }
        });
    }

    document.addEventListener('DOMContentLoaded', function() {
        const progressSection = document.getElementById('section-progress');
        if (!progressSection) return;

        progressSection.addEventListener('click', function(e) {
            const btn = e.target.closest('.mark-step-btn');
            if (!btn) return;
            const container = btn.parentElement;
            const notesTextarea = container.querySelector('.step-notes');
            if (!notesTextarea) {
                alert('Error: Could not find notes input.');
                return;
            }
            const qtnId = notesTextarea.dataset.qtnId;
            const stepName = notesTextarea.dataset.step;
            const staffId = notesTextarea.dataset.staffId;
            const notes = notesTextarea.value.trim();
            if (!qtnId || !stepName) {
                alert('Missing data attributes.');
                return;
            }

            const paymentSteps = ['Deposit 50%', '30% on going job', '20% complete job'];
            let confirmMessage = `Mark "${stepName}" as Completed?`;
            if (paymentSteps.includes(stepName)) {
                confirmMessage = `WARNING: You are manually marking the payment step "${stepName}" as completed.\n\nThis will NOT automatically record any payment. Please ensure the customer has actually paid.\n\nAre you sure you want to continue?`;
            }

            if (confirm(confirmMessage)) {
                fetch('', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                    body: `action=mark_step_complete&qtn_id=${qtnId}&step_name=${encodeURIComponent(stepName)}&staff_id=${staffId}&notes=${encodeURIComponent(notes)}&manual=1`
                })
                .then(response => response.json())
                .then(data => {
                    if (data.success) {
                        location.reload();
                    } else {
                        alert('Update failed: ' + data.message);
                    }
                })
                .catch(err => {
                    console.error(err);
                    alert('Network error, please try again.');
                });
            }
        });

        progressSection.addEventListener('keypress', function(e) {
            const textarea = e.target.closest('.step-notes');
            if (!textarea) return;
            if (e.key === 'Enter' && !e.shiftKey) {
                e.preventDefault();
                const notes = textarea.value.trim();
                if (!notes) return;
                const qtnId = textarea.dataset.qtnId;
                const stepName = textarea.dataset.step;
                const staffId = textarea.dataset.staffId;
                if (!qtnId || !stepName) return;

                fetch('', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                    body: `action=add_step_note&qtn_id=${qtnId}&step_name=${encodeURIComponent(stepName)}&staff_id=${staffId}&note=${encodeURIComponent(notes)}`
                })
                .then(response => response.json())
                .then(data => {
                    if (data.success) {
                        textarea.value = '';
                        location.reload();
                    } else {
                        alert('Failed to add note: ' + data.message);
                    }
                })
                .catch(err => {
                    console.error(err);
                    alert('Network error, please try again.');
                });
            }
        });
    });
    function editCustomer(id, name, email, phone, address, gender, race, selectedProductsJson) { 
        document.querySelector('#edit_customer_id').value = id; 
        document.querySelector('#edit_customer_name').value = name; 
        document.querySelector('#edit_customer_email').value = email; 
        document.querySelector('#edit_customer_phone').value = phone; 
        document.querySelector('#edit_customer_address').value = address; 
        document.querySelector('#edit_customer_gender').value = gender; 
        document.querySelector('#edit_customer_race').value = race; 
        

        document.querySelectorAll('#editCustomerModal input[name="selected_products[]"]').forEach(cb => cb.checked = false);
        
        if(selectedProductsJson) {
            let pids = JSON.parse(selectedProductsJson);
            document.querySelectorAll('#editCustomerModal input[name="selected_products[]"]').forEach(cb => {
                if(pids.includes(parseInt(cb.value))) {
                    cb.checked = true;
                }
            });
        }
    }
    function uploadForQuotationStage(qtnId, stage) {
        const fileInput = document.createElement('input');
        fileInput.type = 'file';
        fileInput.accept = 'image/*,application/pdf';
        fileInput.style.display = 'none';
        document.body.appendChild(fileInput);
        
        fileInput.onchange = async function() {
            if (!fileInput.files.length) {
                fileInput.remove();
                return;
            }
            const file = fileInput.files[0];
            const formData = new FormData();
            formData.append('qtn_id', qtnId);
            formData.append('stage', stage);
            formData.append('payment_receipt', file);
            
            try {
                const response = await fetch('upload_payment.php', { method: 'POST', body: formData });
                const text = await response.text();
                if (text.includes('success') || text.includes('true')) {
                    alert('Payment uploaded successfully!');
                    location.reload();
                } else {
                    alert('Upload failed: ' + text);
                }
            } catch(err) {
                alert('Upload error: ' + err.message);
            } finally {
                fileInput.remove();
            }
        };
        fileInput.click();
    }

    document.querySelectorAll('.toggle-password').forEach(btn => {
        btn.addEventListener('click', function() {
            const targetId = this.getAttribute('data-target');
            const input = document.getElementById(targetId);
            if (input) {
                const icon = this.querySelector('i');
                if (input.type === 'password') {
                    input.type = 'text';
                    icon.classList.remove('bi-eye-slash');
                    icon.classList.add('bi-eye');
                } else {
                    input.type = 'password';
                    icon.classList.remove('bi-eye');
                    icon.classList.add('bi-eye-slash');
                }
            }
        });
    });

    function validateCustomerPasswords() {
        let pwd = document.getElementById('customer_password')?.value;
        let confirm = document.getElementById('customer_confirm_password')?.value;
        if (pwd !== confirm) {
            alert('Passwords do not match!');
            return false;
        }
        if (pwd && pwd.length < 6) {
            alert('Password must be at least 6 characters!');
            return false;
        }
        return true;
    }
</script>
</body>
</html>
