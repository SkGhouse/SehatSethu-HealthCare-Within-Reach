<?php
require_once __DIR__ . "/../db.php";
require_once __DIR__ . "/../helpers.php";

if ($_SERVER["REQUEST_METHOD"] !== "GET") {
  json_response(405, ["ok"=>false,"error"=>"GET only"]);
}

$auth = require_auth();
$role = strtoupper((string)($auth["role"] ?? ""));
if ($role !== "PATIENT") {
  json_response(403, ["ok"=>false,"error"=>"Patient only"]);
}

$pharmacistId = (int)($_GET["pharmacist_user_id"] ?? 0);
if ($pharmacistId <= 0) {
  json_response(422, ["ok"=>false,"error"=>"Invalid pharmacist_user_id"]);
}

$pdo = db();
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

// pharmacist check + profile
$stmt = $pdo->prepare("
  SELECT
    u.id,
    u.full_name,
    u.phone,
    u.role,
    u.is_active,
    u.admin_verification_status,
    pp.pharmacy_name,
    pp.village_town,
    pp.full_address
  FROM users u
  JOIN pharmacist_profiles pp ON pp.user_id = u.id
  WHERE u.id = ?
  LIMIT 1
");
$stmt->execute([$pharmacistId]);
$u = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$u) json_response(404, ["ok"=>false,"error"=>"Pharmacy not found"]);

if (strtoupper((string)$u["role"]) !== "PHARMACIST"
    || (int)$u["is_active"] !== 1
    || strtoupper((string)$u["admin_verification_status"]) !== "VERIFIED") {
  json_response(403, ["ok"=>false,"error"=>"Pharmacy not available"]);
}

// inventory list
$inv = $pdo->prepare("
  SELECT medicine_name, strength, quantity, reorder_level
  FROM pharmacy_inventory
  WHERE pharmacist_user_id=?
  ORDER BY medicine_name ASC, COALESCE(strength,'') ASC
");
$inv->execute([$pharmacistId]);

$items = [];
$totalStock = 0;
$available = 0;
$low = 0;

while ($r = $inv->fetch(PDO::FETCH_ASSOC)) {
  $qty = (int)$r["quantity"];
  $reorder = (int)$r["reorder_level"];
  $totalStock += max(0, $qty);
  if ($qty > 0) $available += $qty;
  if ($qty > 0 && $qty <= $reorder) $low++;

  $status = "IN_STOCK";
  if ($qty <= 0) $status = "OUT_OF_STOCK";
  else if ($qty <= $reorder) $status = "LOW_STOCK";

  $name = (string)$r["medicine_name"];
  $strength = (string)($r["strength"] ?? "");
  $display = trim($name . " " . $strength);

  $items[] = [
    "medicine_name" => $name,
    "strength" => $strength,
    "display_name" => $display,
    "quantity" => $qty,
    "reorder_level" => $reorder,
    "status" => $status,
  ];
}

$data = [
  "pharmacist_user_id" => (int)$u["id"],
  "pharmacy_name" => (string)$u["pharmacy_name"],
  "owner_name" => (string)$u["full_name"],
  "phone" => (string)($u["phone"] ?? ""),
  "address" => trim(((string)$u["full_address"] ?: "") . ((string)$u["village_town"] ? (", " . $u["village_town"]) : "")),
  "rating" => 0.0,
  "reviews_count" => 0,
  "hours" => "",
  "open_now" => false,
  "distance_km" => 0.0,
  "stats" => [
    "total_stock" => $totalStock,
    "available_stock" => $available,
    "low_stock_items" => $low,
  ],
  "medicines" => $items,
];

json_response(200, ["ok"=>true, "data"=>$data]);
