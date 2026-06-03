<?php
// db_connect.php
define('DB_HOST', '127.0.0.1');
define('DB_USER', 'root');
define('DB_PASS', '');
define('DB_NAME', 'DBMS_Project');

$conn = mysqli_connect(DB_HOST, DB_USER, DB_PASS, DB_NAME);
if (!$conn) {
    die('<p style="color:red;font-family:sans-serif;padding:20px;">
        Database connection failed: ' . mysqli_connect_error() . '<br>
        Make sure XAMPP is running and you have imported resort_db.sql</p>');
}
mysqli_set_charset($conn, 'utf8mb4');

function db_fetch_all($conn, $sql) {
    $result = mysqli_query($conn, $sql);
    if (!$result) return [];
    $rows = [];
    while ($row = mysqli_fetch_assoc($result)) $rows[] = $row;
    return $rows;
}

function db_fetch_one($conn, $sql) {
    $result = mysqli_query($conn, $sql);
    if (!$result) return null;
    return mysqli_fetch_assoc($result);
}

function clean($conn, $value) {
    return mysqli_real_escape_string($conn, trim($value ?? ''));
}

// Call a stored procedure that returns a result set
function db_call_proc($conn, $sql) {
    $rows = [];
    if (mysqli_multi_query($conn, $sql)) {
        $result = mysqli_store_result($conn);
        if ($result) {
            while ($row = mysqli_fetch_assoc($result)) $rows[] = $row;
            mysqli_free_result($result);
        }
        while (mysqli_more_results($conn)) {
            mysqli_next_result($conn);
            $r = mysqli_store_result($conn);
            if ($r) mysqli_free_result($r);
        }
    }
    return $rows;
}
?>
