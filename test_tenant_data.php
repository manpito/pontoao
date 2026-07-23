<?php
require 'vendor/autoload.php';

$pdo = new PDO('mysql:host=127.0.0.1;dbname=saas_tenant_004_ftl', 'root', 'root');
$stmt = $pdo->query("SELECT MIN(data_hora) as min_date, MAX(data_hora) as max_date FROM marcacoes");
print_r($stmt->fetch(PDO::FETCH_ASSOC));
