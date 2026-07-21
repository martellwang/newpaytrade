<?php
require_once __DIR__ . '/auth.php';
portal_logout();
header('Location: login.php');
exit;
