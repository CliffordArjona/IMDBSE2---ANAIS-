<?php
// includes/auth.php
if (session_status() === PHP_SESSION_NONE) session_start();

function isLoggedIn(): bool {
    return isset($_SESSION['user_id']);
}

function requireLogin(): void {
    if (!isLoggedIn()) {
        header('Location: /anais/login.php');
        exit;
    }
}

function requireRole(array $roles): void {
    requireLogin();
    if (!in_array($_SESSION['role'], $roles, true)) {
        header('Location: /anais/dashboard.php?error=unauthorized');
        exit;
    }
}

function currentUser(): array {
    return [
        'id'     => $_SESSION['user_id']   ?? null,
        'name'   => $_SESSION['full_name'] ?? '',
        'role'   => $_SESSION['role']      ?? '',
        'branch' => $_SESSION['branch']    ?? '',
        'supplier_id' => $_SESSION['supplier_id'] ?? null,
    ];
}

function isOwner(): bool   { return ($_SESSION['role'] ?? '') === 'Owner'; }
function isOIC(): bool     { return ($_SESSION['role'] ?? '') === 'OIC'; }
function canEdit(): bool   { return in_array($_SESSION['role'] ?? '', ['Owner', 'OIC']); }

function isSupplier(): bool { return ($_SESSION['role'] ?? '') === 'Supplier'; }
