<?php
$dsn = "mysql:host=127.0.0.1;dbname=msjdkjef_api;charset=utf8mb4";
$pdo = new PDO($dsn, "msjdkjef_api", "@Jazman200516");
$r = $pdo->query("SELECT * FROM api_registry WHERE name LIKE '%autopilot%'")->fetchAll(PDO::FETCH_ASSOC);
print_r($r);
