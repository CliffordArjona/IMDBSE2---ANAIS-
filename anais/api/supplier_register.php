<?php
require_once __DIR__ . '/../config/db.php';

header('Content-Type: application/json');

function respond(bool $success, string $message, array $extra = []): void {
    echo json_encode(array_merge([
        'success' => $success,
        'ok' => $success,
        'message' => $message
    ], $extra));
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    respond(false, 'Invalid request method.');
}

$supplier_name = trim($_POST['supplier_name'] ?? '');
$contact_person = trim($_POST['contact_person'] ?? '');
$contact_number = trim($_POST['contact_number'] ?? '');
$email = trim($_POST['email'] ?? '');
$address = trim($_POST['address'] ?? '');
$username = trim($_POST['username'] ?? '');
$password = $_POST['password'] ?? '';
$confirm_password = $_POST['confirm_password'] ?? '';

if ($supplier_name === '' || $contact_person === '' || $contact_number === '' || $address === '' || $username === '' || $password === '') {
    respond(false, 'Please complete all required fields.');
}

if (!preg_match('/^[A-Za-z0-9_\.\-]{4,50}$/', $username)) {
    respond(false, 'Username must be 4–50 characters and may only contain letters, numbers, underscore, dash, or dot.');
}

if (strlen($password) < 8) {
    respond(false, 'Password must be at least 8 characters long.');
}

if ($password !== $confirm_password) {
    respond(false, 'Password and confirm password do not match.');
}

if ($email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL)) {
    respond(false, 'Please enter a valid email address.');
}

try {
    $db = getDB();

    // Check username first.
    $checkUser = $db->prepare("SELECT user_id FROM users WHERE username = ? LIMIT 1");
    $checkUser->execute([$username]);
    if ($checkUser->fetch()) {
        respond(false, 'Username is already taken. Please choose another username.');
    }

    // Check supplier/business name.
    $checkSupplier = $db->prepare("SELECT supplier_id FROM suppliers WHERE supplier_name = ? LIMIT 1");
    $checkSupplier->execute([$supplier_name]);
    if ($checkSupplier->fetch()) {
        respond(false, 'Supplier / business name already exists. Please contact the Owner if this supplier is already registered.');
    }

    $db->beginTransaction();

    // 1. Save supplier record.
    $supplierStmt = $db->prepare("INSERT INTO suppliers
        (supplier_name, contact_person, contact_number, email, address, status, remarks, created_by, created_at, updated_at)
        VALUES (?, ?, ?, ?, ?, 'Active', ?, NULL, NOW(), NOW())");
    $supplierStmt->execute([
        $supplier_name,
        $contact_person,
        $contact_number,
        $email,
        $address,
        'Registered through supplier registration form.'
    ]);

    $supplier_id = (int)$db->lastInsertId();

    if ($supplier_id <= 0) {
        throw new RuntimeException('Supplier record was not saved.');
    }

    // 2. Save supplier user account and link supplier_id.
    $hash = password_hash($password, PASSWORD_DEFAULT);

    $userStmt = $db->prepare("INSERT INTO users
        (full_name, username, password, role, branch, status, supplier_id, created_by, created_at, updated_at)
        VALUES (?, ?, ?, 'Supplier', 'Both', 'Active', ?, NULL, NOW(), NOW())");
    $userStmt->execute([
        $contact_person,
        $username,
        $hash,
        $supplier_id
    ]);

    $user_id = (int)$db->lastInsertId();

    if ($user_id <= 0) {
        throw new RuntimeException('Supplier account was not saved.');
    }

    $db->commit();

    respond(true, 'Supplier account created and saved to database. Redirecting to login...', [
        'supplier_id' => $supplier_id,
        'user_id' => $user_id
    ]);
} catch (Throwable $e) {
    if (isset($db) && $db instanceof PDO && $db->inTransaction()) {
        $db->rollBack();
    }

    $msg = $e->getMessage();

    if ($e instanceof PDOException && ($e->getCode() === '23000' || stripos($msg, 'Duplicate entry') !== false)) {
        if (stripos($msg, 'username') !== false) {
            respond(false, 'Username is already taken. Please choose another username.');
        }

        if (stripos($msg, 'supplier_name') !== false || stripos($msg, 'supplier') !== false) {
            respond(false, 'Supplier / business name already exists.');
        }

        respond(false, 'Duplicate record found. Please check the supplier name and username.');
    }

    respond(false, 'Registration failed: ' . $msg);
}
