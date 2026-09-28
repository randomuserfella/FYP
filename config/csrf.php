<?php
// ── CSRF Protection Helper ────────────────────────────────────────────────────
// Include this file in any page that has a POST form.
// Usage:
//   In the form:         <?= csrf_field() 
//   At POST handler top: csrf_verify();

function csrf_token(): string {
    if (empty($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf_token'];
}

function csrf_field(): string {
    return '<input type="hidden" name="csrf_token" value="' . htmlspecialchars(csrf_token()) . '">';
}

function csrf_verify(): void {
    $token = $_POST['csrf_token'] ?? '';
    if (!hash_equals(csrf_token(), $token)) {
        http_response_code(403);
        die('Security check failed. Please go back and try again.');
    }
}
?>