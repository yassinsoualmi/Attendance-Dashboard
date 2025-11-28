<?php
// Added configuration for database connection and shared paths.
return [
    'host' => 'localhost',
    // Change the port if your MySQL runs on a custom port (e.g., 3308 in some WAMP installs).
    'port' => 3306,
    'dbname' => 'attendance_dashboard',
    'user' => 'root',
    'pass' => '',
    'charset' => 'utf8mb4',
    'error_log' => __DIR__ . '/logs/error.log',
];
