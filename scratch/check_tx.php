<?php
require_once 'c:\xampp\htdocs\gracehubtels\user\core\db.php';
$stmt = db()->prepare("SELECT meta FROM transactions WHERE transaction_ref = 'MSJFB7A539F4EF01785518091'");
$stmt->execute();
$res = $stmt->fetch();
var_dump(json_decode($res['meta'], true));
