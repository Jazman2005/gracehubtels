<?php
// user/logout.php
require_once __DIR__ . '/core/auth.php';
start_session();
logout_user();
