<?php
require_once __DIR__ . '/../helpers.php';
require_once __DIR__ . '/../db.php';

cors_json();
$auth = require_auth();
$pdo = db();

try {
  // only VERIFIED doctors
  $stmt = $pdo->query("
    SELECT DISTINCT dp.specialization
    FROM doctor_profiles dp
    JOIN users u ON u.id = dp.user_id
    WHERE u.role='DOCTOR' AND u.is_active=1 AND u.admin_verification_status='VERIFIED'
      AND dp.specialization IS NOT NULL AND dp.specialization <> ''
    ORDER BY dp.specialization ASC
  ");
  $specs = $stmt->fetchAll(PDO::FETCH_COLUMN);

  // If empty, return common demo list (still from server)
  if (!$specs || count($specs) === 0) {
    $specs = ["General","Heart","Brain","Bones","Eyes","Child","Skin","Lungs","Diabetes","Fever","Medicine","Emergency"];
  }

  $out = [];
  foreach ($specs as $s) $out[] = ["name"=>$s];

  json_response(200, ["ok"=>true, "data"=>["specialities"=>$out]]);
} catch (Throwable $e) {
  json_response(500, ["ok"=>false, "error"=>"Server error"]);
}
