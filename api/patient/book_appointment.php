<?php
require_once __DIR__ . '/../helpers.php';
require_once __DIR__ . '/../db.php';

cors_json();
$auth = require_auth();
$pdo = db();
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

function is_localhost(): bool {
  $ip = $_SERVER["REMOTE_ADDR"] ?? "";
  return in_array($ip, ["127.0.0.1", "::1"], true);
}
function norm_hhmm(string $t): string {
  $t = trim($t);
  if (preg_match('/^\d{2}:\d{2}(:\d{2})?$/', $t)) return substr($t, 0, 5);
  return "";
}
function to_minutes(string $hm): int {
  $hm = norm_hhmm($hm);
  if ($hm === "") return 0;
  $p = explode(":", $hm);
  return ((int)($p[0] ?? 0))*60 + ((int)($p[1] ?? 0));
}

$uid  = (int)($auth["uid"] ?? 0);
$role = strtoupper((string)($auth["role"] ?? ""));

if ($uid <= 0) json_response(401, ["ok"=>false, "error"=>"Unauthorized"]);
if ($role !== "PATIENT") json_response(403, ["ok"=>false, "error"=>"Patient only"]);
if (($_SERVER["REQUEST_METHOD"] ?? "") !== "POST") json_response(405, ["ok"=>false, "error"=>"POST only"]);

$data = read_json();
if (!is_array($data)) $data = [];

// accept both doctorId/doctor_id from Android
$doctorId = (int)($data["doctorId"] ?? $data["doctor_id"] ?? 0);
$specKey  = trim((string)($data["speciality_key"] ?? $data["specialty"] ?? "")); // store stable key in specialty
$ctype    = strtoupper(trim((string)($data["consult_type"] ?? "")));
$dateIso  = trim((string)($data["date"] ?? ""));           // yyyy-mm-dd
$timeHm   = norm_hhmm((string)($data["time"] ?? ""));      // HH:mm
$symptoms = array_key_exists("symptoms", $data) ? trim((string)$data["symptoms"]) : null;

// optional
$feeAmount = (int)($data["fee_amount"] ?? 0);

if ($doctorId <= 0) json_response(422, ["ok"=>false, "error"=>"Invalid doctorId"]);
if ($specKey === "") json_response(422, ["ok"=>false, "error"=>"Invalid speciality_key"]);
if (!in_array($ctype, ["AUDIO","VIDEO"], true)) json_response(422, ["ok"=>false, "error"=>"Invalid consult_type"]);
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $dateIso)) json_response(422, ["ok"=>false, "error"=>"Invalid date"]);
if ($timeHm === "") json_response(422, ["ok"=>false, "error"=>"Invalid time"]);

try {
  // doctor must be VERIFIED doctor
  $st = $pdo->prepare("SELECT id, role, is_active, admin_verification_status FROM users WHERE id=? LIMIT 1");
  $st->execute([$doctorId]);
  $doc = $st->fetch(PDO::FETCH_ASSOC);

  if (!$doc || strtoupper((string)($doc["role"] ?? "")) !== "DOCTOR") {
    json_response(404, ["ok"=>false, "error"=>"Doctor not found"]);
  }
  if ((int)($doc["is_active"] ?? 0) !== 1 || strtoupper((string)($doc["admin_verification_status"] ?? "")) !== "VERIFIED") {
    json_response(403, ["ok"=>false, "error"=>"Doctor not available"]);
  }

  // build scheduled_at
  $scheduledAt = DateTime::createFromFormat("Y-m-d H:i", $dateIso . " " . $timeHm);
  if (!$scheduledAt) json_response(422, ["ok"=>false, "error"=>"Invalid date/time"]);
  $scheduledAtStr = $scheduledAt->format("Y-m-d H:i:s");

  // availability check by weekday
  $dow = (int)$scheduledAt->format("N"); // 1..7

  $st = $pdo->prepare("
    SELECT enabled, start_time, end_time
    FROM doctor_availability
    WHERE user_id=? AND day_of_week=? LIMIT 1
  ");
  $st->execute([$doctorId, $dow]);
  $av = $st->fetch(PDO::FETCH_ASSOC);

  if (!$av || (int)($av["enabled"] ?? 0) !== 1) {
    json_response(422, ["ok"=>false, "error"=>"Doctor is not available on this day"]);
  }

  $slotMinutes = 30;

  $startMin = to_minutes((string)($av["start_time"] ?? "09:00"));
  $endMin   = to_minutes((string)($av["end_time"] ?? "17:00"));
  $tMin     = to_minutes($timeHm);

  if ($tMin < $startMin || $tMin + $slotMinutes > $endMin) {
    json_response(422, ["ok"=>false, "error"=>"Selected time is outside doctor's availability"]);
  }

  // past time check if today
  $now = new DateTime();
  if ($dateIso === $now->format("Y-m-d")) {
    $nowMin = to_minutes($now->format("H:i"));
    if ($tMin <= $nowMin) json_response(422, ["ok"=>false, "error"=>"Selected time has already passed"]);
  }

  $pdo->beginTransaction();

  // conflict check (lock)
  $cst = $pdo->prepare("
    SELECT id
    FROM appointments
    WHERE doctor_id=?
      AND scheduled_at=?
      AND UPPER(status) IN ('BOOKED','CONFIRMED')
    LIMIT 1
    FOR UPDATE
  ");
  $cst->execute([$doctorId, $scheduledAtStr]);
  if ($cst->fetchColumn()) {
    $pdo->rollBack();
    json_response(409, ["ok"=>false, "error"=>"Someone else booked this time slot. Please choose another time."]);
  }

  // insert appointment (matches your schema)
  $ins = $pdo->prepare("
    INSERT INTO appointments
      (patient_id, doctor_id, specialty, consult_type, symptoms, fee_amount, scheduled_at, duration_min, status)
    VALUES
      (?, ?, ?, ?, ?, ?, ?, ?, 'BOOKED')
  ");
  $ins->execute([
    $uid,
    $doctorId,
    $specKey,                 // store stable key
    $ctype,
    ($symptoms === "" ? null : $symptoms),
    $feeAmount,
    $scheduledAtStr,
    $slotMinutes
  ]);

  $apptId = (int)$pdo->lastInsertId();

  // notifications (if table exists)
  try {
    $n = $pdo->prepare("INSERT INTO notifications (user_id, title, body) VALUES (?, ?, ?)");
    $n->execute([$uid, "Appointment booked", "Your appointment is booked on {$dateIso} at {$timeHm} ({$ctype})."]);
    $n->execute([$doctorId, "New appointment", "You have a new appointment on {$dateIso} at {$timeHm} ({$ctype})."]);
  } catch (Throwable $e) {
    // ignore notification failures (don’t fail booking)
  }

  $pdo->commit();

  json_response(200, ["ok"=>true, "data"=>["bookingId"=>(string)$apptId]]);

} catch (Throwable $e) {
  if ($pdo->inTransaction()) $pdo->rollBack();

  json_response(500, [
    "ok"=>false,
    "error"=>"Failed to create appointment",
    "debug"=> is_localhost() ? $e->getMessage() : null
  ]);
}
