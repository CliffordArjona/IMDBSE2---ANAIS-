<?php
// includes/error_handler.php
// Global friendly PDO error handler for ANAIS.
// This prevents uncaught PDO errors from showing raw Fatal error pages.

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

function anaisFriendlyPDOMessage(Throwable $e): string {
    $message = $e->getMessage();

    if ($e instanceof PDOException) {
        // Duplicate entry / unique constraint.
        if ($e->getCode() === '23000' || stripos($message, 'Duplicate entry') !== false) {
            if (stripos($message, 'sku') !== false) {
                return 'SKU already exists. Please use a different SKU.';
            }

            if (stripos($message, 'username') !== false) {
                return 'Username already exists. Please choose another username.';
            }

            if (stripos($message, 'po_number') !== false) {
                return 'Purchase Order number already exists. Please try again.';
            }

            return 'Duplicate record found. Please check your input and try again.';
        }

        // Missing database column.
        if (stripos($message, 'Unknown column') !== false) {
            return 'Database column is missing. Please run the latest SQL update file for ANAIS.';
        }

        // Missing table.
        if (stripos($message, "doesn't exist") !== false || stripos($message, 'Base table or view not found') !== false) {
            return 'Database table is missing. Please import or update the ANAIS database.';
        }

        // Data too long.
        if (stripos($message, 'Data too long') !== false) {
            return 'One of the values is too long. Please shorten your input and try again.';
        }

        // Invalid enum value / truncated data.
        if (stripos($message, 'Data truncated') !== false) {
            return 'Invalid value selected. Please choose a valid option.';
        }

        // Foreign key issue.
        if (stripos($message, 'foreign key constraint') !== false) {
            return 'This record is linked to other data and cannot be changed or deleted directly.';
        }

        // Cannot be null.
        if (stripos($message, 'cannot be null') !== false) {
            return 'A required field is missing. Please complete all required fields.';
        }

        // Generic PDO/database error.
        return 'Database error occurred. Please check your input and try again.';
    }

    return 'An error occurred. Please try again.';
}

function anaisFlashError(string $message): void {
    $_SESSION['anais_flash_alert'] = [
        'type' => 'danger',
        'message' => $message,
    ];
}

function anaisDisplayFallbackErrorPage(string $message): void {
    http_response_code(500);
    $safe = htmlspecialchars($message, ENT_QUOTES, 'UTF-8');
    echo '<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<title>ANAIS Error</title>
<style>
body{font-family:Arial,sans-serif;background:#f3f4f6;margin:0;padding:40px;color:#111827}
.box{max-width:560px;margin:80px auto;background:#fff;border-radius:14px;box-shadow:0 18px 50px rgba(0,0,0,.14);padding:24px}
.alert{background:#fef2f2;border:1px solid #fecaca;color:#dc2626;border-radius:10px;padding:14px 16px;margin-bottom:18px}
.btn{display:inline-block;background:#2563eb;color:#fff;text-decoration:none;padding:10px 14px;border-radius:8px}
</style>
</head>
<body>
<div class="box">
  <div class="alert">⚠️ '.$safe.'</div>
  <a class="btn" href="javascript:history.back()">Go Back</a>
</div>
</body>
</html>';
}

set_exception_handler(function (Throwable $e) {
    $friendly = anaisFriendlyPDOMessage($e);

    // For POST requests, redirect back and show a normal ANAIS alert/notification.
    if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
        anaisFlashError($friendly);

        $back = $_SERVER['HTTP_REFERER'] ?? '';
        if ($back) {
            header('Location: ' . $back);
            exit;
        }
    }

    // If redirect is not possible, show a clean fallback alert page.
    anaisDisplayFallbackErrorPage($friendly);
    exit;
});

set_error_handler(function ($severity, $message, $file, $line) {
    // Convert serious PHP errors to exceptions so the handler can display friendly alerts.
    if (!(error_reporting() & $severity)) {
        return false;
    }

    throw new ErrorException($message, 0, $severity, $file, $line);
});
