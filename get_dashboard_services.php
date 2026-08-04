<?php
require_once __DIR__.'/user/core/db.php';
$services = json_decode(setting('dashboard_services', '[]'), true) ?: [];
print_r($services);
