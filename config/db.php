<?php
/**
 * config/db.php
 *
 * Reads DB credentials from environment variables if set (live server),
 * otherwise falls back to XAMPP defaults (localhost).
 *
 * On InfinityFree:
 *   Set these in config/db_credentials.php (see instructions below)
 */

// Check for a local credentials override file (not committed to any public repo)

$host     = 'localhost';
$db_name  = 'procratrack';
$username = 'root';
$password = '';

require_once __DIR__ . '/db_credentials.php';

try {
    $pdo = new PDO("mysql:host=$host;dbname=$db_name;charset=utf8", $username, $password);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    throw new Exception('DB connection failed: ' . $e->getMessage());
}
?>