<?php
require_once __DIR__ . "/../db.php";
require_once __DIR__ . "/../helpers.php";

if ($_SERVER["REQUEST_METHOD"] !== "GET") {
  json_response(405, ["ok" => false, "error" => "GET only"]);
}

/**
 * ---------------------------
 * JWT helpers (safe, standalone)
 * ---------------------------
 */
if (!function_exists("jwt_decode_hs256")) {
  function jwt_decode_hs256(string $jwt): array {
    $cfg = null;
    $cfgPath = __DIR__ . "/../config.php";
    if (file_exists($cfgPath)) $cfg = require $cfgPath;

    $secret = "";
    if (is_array($cfg) && isset($cfg["jwt"]["secret"])) $secret = (string)$cfg["jwt"]["secret"];
    if ($secret === "") $secret = (string)($_ENV["JWT_SECRET"] ?? "");
    if ($secret === "") $secret = (string)(getenv("JWT_SECRET") ?: "");

    if (trim($secret) === "") {
      json_response(500, ["ok" => false, "error" => "JWT_SECRET missing on server. Check config.php jwt.secret"]);
    }

    $parts = explode(".", $jwt);
    if (count($parts) !== 3) json_response(401, ["ok" => false, "error" => "Invalid token"]);

    [$h, $p, $s] = $parts;

    $b64url_decode = function (string $data): string {
      $remainder = strlen($data) % 4;
      if ($remainder) $data .= str_repeat("=", 4 - $remainder);
      return base64_decode(strtr($data, "-_", "+/")) ?: "";
    };

    $sig = $b64url_decode($s);
    $expected = hash_hmac("sha256", "$h.$p", $secret, true);
    if (!hash_equals($expected, $sig)) json_response(401, ["ok" => false, "error" => "Invalid token signature"]);

    $payloadJson = $b64url_decode($p);
    $payload = json_decode($payloadJson, true);
    if (!is_array($payload)) json_response(401, ["ok" => false, "error" => "Invalid token payload"]);

    $now = time();
    if (isset($payload["exp"]) && (int)$payload["exp"] < $now) {
      json_response(401, ["ok" => false, "error" => "Token expired"]);
    }

    return $payload;
  }
}

function get_bearer_token_safe(): string {
  $auth = $_SERVER["HTTP_AUTHORIZATION"] ?? $_SERVER["REDIRECT_HTTP_AUTHORIZATION"] ?? "";
  if (!$auth && function_exists("apache_request_headers")) {
    $headers = apache_request_headers();
    if (isset($headers["Authorization"])) $auth = $headers["Authorization"];
  }
  if (!$auth) return "";
  if (stripos($auth, "Bearer ") === 0) return trim(substr($auth, 7));
  return "";
}

/**
 * timeAgo helper
 */
function time_ago(string $dt): string {
  $ts = strtotime($dt);
  if (!$ts) return "";
  $diff = time() - $ts;
  if ($diff < 60) return "just now";
  $mins = floor($diff / 60);
  if ($mins < 60) return $mins . " min ago";
  $hrs = floor($mins / 60);
  if ($hrs < 24) return $hrs . " hr ago";
  $days = floor($hrs / 24);
  return $days . " day" . ($days > 1 ? "s" : "") . " ago";
}

$token = get_bearer_token_safe();
if ($token === "") json_response(401, ["ok" => false, "error" => "Missing token"]);

$claims = jwt_decode_hs256($token);

$uid = (int)($claims["uid"] ?? 0);
$role = strtoupper((string)($claims["role"] ?? ""));

if ($uid <= 0) json_response(401, ["ok" => false, "error" => "Invalid token user"]);
if ($role !== "PHARMACIST") json_response(403, ["ok" => false, "error" => "Forbidden"]);

$pdo = db();
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

/**
 * Pharmacy name (from pharmacist_profiles)
 */
$pharmacyName = null;
$stmt = $pdo->prepare("SELECT pharmacy_name FROM pharmacist_profiles WHERE user_id=? LIMIT 1");
$stmt->execute([$uid]);
$row = $stmt->fetch(PDO::FETCH_ASSOC);
if ($row && !empty($row["pharmacy_name"])) $pharmacyName = $row["pharmacy_name"];

/**
 * Notifications count (unread)
 * If your project doesn’t have this table yet, create it using SQL provided below.
 */
$notifications = 0;
try {
  $nq = $pdo->prepare("SELECT COUNT(*) AS c FROM notifications WHERE user_id=? AND is_read=0");
  $nq->execute([$uid]);
  $notifications = (int)($nq->fetch(PDO::FETCH_ASSOC)["c"] ?? 0);
} catch (Throwable $e) {
  // table may not exist yet, keep 0
  $notifications = 0;
}

/**
 * Inventory counts + alerts
 */
$totalStock = 0;
$alerts = 0;

try {
  $q1 = $pdo->prepare("SELECT COUNT(*) AS c FROM pharmacy_inventory WHERE pharmacist_user_id=?");
  $q1->execute([$uid]);
  $totalStock = (int)($q1->fetch(PDO::FETCH_ASSOC)["c"] ?? 0);

  $q2 = $pdo->prepare("
    SELECT COUNT(*) AS c
    FROM pharmacy_inventory
    WHERE pharmacist_user_id=?
      AND (quantity <= reorder_level OR quantity <= 0)
  ");
  $q2->execute([$uid]);
  $alerts = (int)($q2->fetch(PDO::FETCH_ASSOC)["c"] ?? 0);
} catch (Throwable $e) {
  $totalStock = 0;
  $alerts = 0;
}

/**
 * Low stock list (top 3)
 */
$lowStock = [];
try {
  $q3 = $pdo->prepare("
    SELECT medicine_name, strength, quantity, reorder_level
    FROM pharmacy_inventory
    WHERE pharmacist_user_id=?
      AND (quantity <= reorder_level OR quantity <= 0)
    ORDER BY quantity ASC, updated_at DESC
    LIMIT 3
  ");
  $q3->execute([$uid]);
  while ($r = $q3->fetch(PDO::FETCH_ASSOC)) {
    $lowStock[] = [
      "name" => (string)($r["medicine_name"] ?? ""),
      "strength" => (string)($r["strength"] ?? ""),
      "qty" => (int)($r["quantity"] ?? 0),
      "reorder_level" => (int)($r["reorder_level"] ?? 0),
    ];
  }
} catch (Throwable $e) {
  $lowStock = [];
}

/**
 * Recent requests (top 2)
 */
$recentRequests = [];
try {
  $q4 = $pdo->prepare("
    SELECT mr.id, mr.medicine_query, mr.created_at,
           u.full_name AS patient_name
    FROM medicine_requests mr
    LEFT JOIN users u ON u.id = mr.patient_user_id
    WHERE mr.pharmacist_user_id=?
    ORDER BY mr.created_at DESC
    LIMIT 2
  ");
  $q4->execute([$uid]);
  while ($r = $q4->fetch(PDO::FETCH_ASSOC)) {
    $recentRequests[] = [
      "id" => (string)($r["id"] ?? ""),
      "patientName" => (string)($r["patient_name"] ?? "Patient"),
      "medicine" => (string)($r["medicine_query"] ?? ""),
      "timeAgo" => time_ago((string)($r["created_at"] ?? "")),
    ];
  }
} catch (Throwable $e) {
  $recentRequests = [];
}

json_response(200, [
  "ok" => true,
  "data" => [
    "pharmacyName" => $pharmacyName ?? "Store",
    "notifications" => $notifications,
    "totalStock" => $totalStock,
    "alerts" => $alerts,
    "lowStock" => $lowStock,
    "recentRequests" => $recentRequests
  ]
]);
