<?php
require_once 'config/config.php';
require_once 'includes/auth.php';

$auth = new Auth();
$auth->logout();
header('Location: ' . BASE_URL . '/login.php');
exit;
?>
