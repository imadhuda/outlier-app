<?php
require_once __DIR__ . '/lib/auth.php';
app_boot();
$u = Auth::user();
header('Location: ' . ($u ? ($u['role'] === 'admin' ? 'admin.php' : 'app.php') : 'login.php'));
exit;
