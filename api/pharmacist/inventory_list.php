<?php
require_once __DIR__ . "/../db.php";
require_once __DIR__ . "/../helpers.php";

if ($_SERVER["REQUEST_METHOD"] !== "POST") {
  json_response(405, ["ok"=>false,"error"=>"POST only"]);
}

$auth = require_auth();
$uid = (int)$auth["uid"];

$pdo = db();
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

// role check
$st = $pdo->prepare("SELECT role FROM users WHERE id=? LIMIT 1");
$st->execute([$uid]);
$u = $st->fetch(PDO::FETCH_ASSOC);
if (!$u || strtoupper((string)$u["role"]) !== "PHARMACIST") {
  json_response(403, ["ok"=>false,"error"=>"Pharmacist only"]);
}

$q = $pdo->prepare("
  SELECT id, medicine_name, strength, quantity, reorder_level, updated_at
  FROM pharmacy_inventory
  WHERE pharmacist_user_id=?
  ORDER BY medicine_name ASC, strength ASC
");
$q->execute([$uid]);

json_response(200, ["ok"=>true, "data"=>["items"=>$q->fetchAll(PDO::FETCH_ASSOC)]]);
