<?php
require_once __DIR__.'/core/auth.php';
auth_admin();
header('Location: service-pricing.php');
exit;
