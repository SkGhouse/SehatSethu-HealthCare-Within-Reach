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

$q = trim((string)($_GET["q"] ?? ""));
$limit = (int)($_GET["limit"] ?? 50);
if ($limit < 1) $limit = 50;
if ($limit > 100) $limit = 100;

$pdo = db();
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

$like = "%" . $q . "%";

/**
 * We pick ONE "best pharmacy" row per (medicine_name,strength) based on:
 *  - highest quantity
 *  - tie-breaker lowest pharmacist_user_id
 */
$sql = "
SELECT
  inv.medicine_name,
  inv.strength,
  inv.quantity,
  inv.reorder_level,
  inv.pharmacist_user_id,
  pp.pharmacy_name,
  u.full_name AS owner_name,
  u.phone,
  pp.village_town,
  pp.full_address,
  CASE
    WHEN inv.quantity <= 0 THEN 'OUT_OF_STOCK'
    WHEN inv.quantity <= inv.reorder_level THEN 'LOW_STOCK'
    ELSE 'IN_STOCK'
  END AS status
FROM pharmacy_inventory inv
JOIN users u
  ON u.id = inv.pharmacist_user_id
JOIN pharmacist_profiles pp
  ON pp.user_id = u.id
JOIN (
  SELECT
    inv2.medicine_name,
    COALESCE(inv2.strength,'') AS strength_key,
    MAX(inv2.quantity) AS max_qty,
    MIN(inv2.pharmacist_user_id) AS min_uid
  FROM pharmacy_inventory inv2
  JOIN users u2 ON u2.id = inv2.pharmacist_user_id
  WHERE u2.role='PHARMACIST'
    AND u2.is_active=1
    AND u2.admin_verification_status='VERIFIED'
    AND (
      inv2.medicine_name LIKE :like
      OR CONCAT(inv2.medicine_name, ' ', COALESCE(inv2.strength,'')) LIKE :like2
    )
  GROUP BY inv2.medicine_name, COALESCE(inv2.strength,'')
) pick
  ON pick.medicine_name = inv.medicine_name
 AND pick.strength_key = COALESCE(inv.strength,'')
 AND inv.quantity = pick.max_qty
 AND inv.pharmacist_user_id = pick.min_uid
WHERE u.role='PHARMACIST'
  AND u.is_active=1
  AND u.admin_verification_status='VERIFIED'
  AND (
    inv.medicine_name LIKE :like3
    OR CONCAT(inv.medicine_name, ' ', COALESCE(inv.strength,'')) LIKE :like4
  )
ORDER BY
  inv.quantity DESC,
  inv.medicine_name ASC,
  COALESCE(inv.strength,'') ASC
LIMIT $limit
";

$stmt = $pdo->prepare($sql);
$stmt->execute([
  ":like"  => $like,
  ":like2" => $like,
  ":like3" => $like,
  ":like4" => $like,
]);

$out = [];
while ($r = $stmt->fetch(PDO::FETCH_ASSOC)) {
  $name = (string)($r["medicine_name"] ?? "");
  $strength = (string)($r["strength"] ?? "");
  $display = trim($name . " " . $strength);

  $out[] = [
    "medicine_name" => $name,
    "strength" => $strength,
    "display_name" => $display,
    "status" => (string)$r["status"],
    "quantity" => (int)$r["quantity"],
    "reorder_level" => (int)$r["reorder_level"],
    "pharmacist_user_id" => (int)$r["pharmacist_user_id"],
    "pharmacy_name" => (string)($r["pharmacy_name"] ?? ""),
    "owner_name" => (string)($r["owner_name"] ?? ""),
    "phone" => (string)($r["phone"] ?? ""),
    "village_town" => (string)($r["village_town"] ?? ""),
    "full_address" => (string)($r["full_address"] ?? ""),
  ];
}

json_response(200, ["ok"=>true, "items"=>$out]);
