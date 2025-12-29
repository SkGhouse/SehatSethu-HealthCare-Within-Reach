<?php
require_once __DIR__ . "/../db.php";
require_once __DIR__ . "/../helpers.php";

if ($_SERVER["REQUEST_METHOD"] !== "POST") json_response(405, ["ok"=>false,"error"=>"POST only"]);

set_time_limit(20);
ini_set('default_socket_timeout', '20');

$data = read_json();
require_fields($data, ["full_name","email","password","role","signup_token"]);

$fullName = trim((string)$data["full_name"]);
$email = normalize_email((string)$data["email"]);
$password = (string)$data["password"];
$role  = strtoupper(trim((string)$data["role"]));
$signupToken = trim((string)$data["signup_token"]);

if ($fullName === "") json_response(422, ["ok"=>false,"error"=>"Full name required."]);
if (!filter_var($email, FILTER_VALIDATE_EMAIL)) json_response(422, ["ok"=>false,"error"=>"Invalid email."]);
if (!is_valid_role($role)) json_response(422, ["ok"=>false,"error"=>"Invalid role."]);
if (strlen($password) < 6) json_response(422, ["ok"=>false,"error"=>"Password must be at least 6 characters."]);
if ($signupToken === "") json_response(422, ["ok"=>false,"error"=>"Missing token."]);

$pdo = db();
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

// user must NOT exist
$stmt = $pdo->prepare("SELECT id FROM users WHERE email=? LIMIT 1");
$stmt->execute([$email]);
if ($stmt->fetch()) {
  json_response(409, ["ok"=>false,"code"=>"EMAIL_EXISTS","error"=>"Email already registered. Please sign in."]);
}

// pending must exist
$stmt = $pdo->prepare("SELECT * FROM signup_pending WHERE email=? AND role=? LIMIT 1");
$stmt->execute([$email, $role]);
$p = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$p) json_response(404, ["ok"=>false,"error"=>"No pending signup found. Please send OTP again."]);
if (empty($p["verified_at"])) json_response(403, ["ok"=>false,"code"=>"NOT_VERIFIED","error"=>"Email not verified."]);

$exp = new DateTime((string)$p["signup_token_expires_at"], new DateTimeZone("UTC"));
$nowUtc = new DateTime("now", new DateTimeZone("UTC"));
if ($nowUtc > $exp) {
  json_response(400, ["ok"=>false,"code"=>"TOKEN_EXPIRED","error"=>"Token expired. Please resend OTP."]);
}

if (!hash_equals((string)$p["signup_token_hash"], sha256($signupToken))) {
  json_response(400, ["ok"=>false,"code"=>"TOKEN_INVALID","error"=>"Invalid token. Please verify OTP again."]);
}

// ✅ IMPORTANT RULE:
// - DOCTOR/PHARMACIST: admin_verification_status must start as PENDING
// - PATIENT/ADMIN: VERIFIED
$adminStatus = ($role === "DOCTOR" || $role === "PHARMACIST") ? "PENDING" : "VERIFIED";

// ✅ profile_completed MUST be 0 right after signup for ALL roles (Admin can still be routed directly in app)
$profileCompleted = 0;

try {
  $pdo->beginTransaction();

  $pdo->prepare("
    INSERT INTO users (full_name, email, password_hash, role, is_verified, admin_verification_status, profile_completed)
    VALUES (?, ?, ?, ?, 1, ?, ?)
  ")->execute([
      $fullName,
      $email,
      password_hash($password, PASSWORD_BCRYPT),
      $role,
      $adminStatus,
      $profileCompleted
  ]);

  $userId = (int)$pdo->lastInsertId();

  // ✅ Create professional_verifications row (but NOT submitted yet)
  if ($role === "DOCTOR" || $role === "PHARMACIST") {
    $pdo->prepare("
      INSERT INTO professional_verifications (user_id, role, status, submitted_at)
      VALUES (?, ?, 'PENDING', NULL)
      ON DUPLICATE KEY UPDATE role=VALUES(role), status='PENDING', submitted_at=NULL, rejection_reason=NULL
    ")->execute([$userId, $role]);
  }

  $pdo->prepare("DELETE FROM signup_pending WHERE id=?")->execute([(int)$p["id"]]);

  $pdo->commit();
} catch (Throwable $e) {
  if ($pdo->inTransaction()) $pdo->rollBack();
  json_response(500, ["ok"=>false,"error"=>"Could not create account. Please try again."]);
}

json_response(200, [
  "ok"=>true,
  "message"=>"Account created successfully.",
  "admin_verification_status"=>$adminStatus,
  "profile_completed"=>$profileCompleted
]);
