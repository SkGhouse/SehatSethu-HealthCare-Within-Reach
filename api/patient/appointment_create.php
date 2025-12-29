<?php
require_once __DIR__ . '/../helpers.php';
require_once __DIR__ . '/../db.php';

cors_json();
$auth = require_auth();
$pdo = db();

$uid  = (int)($auth["uid"] ?? 0);
$role = strtoupper((string)($auth["role"] ?? ""));

if ($uid <= 0) json_response(401, ["ok"=>false, "error"=>"Unauthorized"]);
if ($role !== "PATIENT") json_response(403, ["ok"=>false, "error"=>"Patient only"]);

if ($_SERVER["REQUEST_METHOD"] !== "POST") {
  json_response(405, ["ok"=>false, "error"=>"POST only"]);
}

$data = read_json();
require_fields($data, ["doctor_id","speciality_key","consult_type","date","time"]);

$doctorId = (int)$data["doctor_id"];
$specKey  = trim((string)$data["speciality_key"]);
$ctype    = strtoupper(trim((string)$data["consult_type"]));
$dateIso  = trim((string)$data["date"]);   // yyyy-mm-dd
$timeHm   = trim((string)$data["time"]);   // HH:mm
$symptoms = isset($data["symptoms"]) ? trim((string)$data["symptoms"]) : null;

if ($doctorId <= 0) json_response(422, ["ok"=>false, "error"=>"Invalid doctor_id"]);
if ($specKey === "") json_response(422, ["ok"=>false, "error"=>"Invalid speciality_key"]);
if (!in_array($ctype, ["AUDIO","VIDEO"], true)) json_response(422, ["ok"=>false, "error"=>"Invalid consult_type"]);
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $dateIso)) json_response(422, ["ok"=>false, "error"=>"Invalid date"]);
if (!preg_match('/^\d{2}:\d{2}$/', $timeHm)) json_response(422, ["ok"=>false, "error"=>"Invalid time"]);

function to_minutes($hm) {
  [$h,$m] = explode(":", $hm);
  return ((int)$h)*60 + ((int)$m);
}

try {
  // doctor must be VERIFIED doctor
  $st = $pdo->prepare("SELECT id, role, is_active, admin_verification_status FROM users WHERE id=? LIMIT 1");
  $st->execute([$doctorId]);
  $doc = $st->fetch(PDO::FETCH_ASSOC);
  if (!$doc || strtoupper($doc["role"] ?? "") !== "DOCTOR") {
    json_response(404, ["ok"=>false, "error"=>"Doctor not found"]);
  }
  if ((int)($doc["is_active"] ?? 0) !== 1 || strtoupper((string)$doc["admin_verification_status"]) !== "VERIFIED") {
    json_response(403, ["ok"=>false, "error"=>"Doctor not available"]);
  }

  // Validate time is within doctor's availability for that weekday
  $dt = DateTime::createFromFormat("Y-m-d", $dateIso);
  if (!$dt) json_response(422, ["ok"=>false, "error"=>"Invalid date"]);
  $dow = (int)$dt->format("N"); // 1..7

  $st = $pdo->prepare("
    SELECT enabled, start_time, end_time
    FROM doctor_availability
    WHERE user_id=? AND day_of_week=? LIMIT 1
  ");
  $st->execute([$doctorId, $dow]);
  $av = $st->fetch(PDO::FETCH_ASSOC);

  if (!$av || (int)$av["enabled"] !== 1) {
    json_response(422, ["ok"=>false, "error"=>"Doctor is not available on this day"]);
  }

  $startMin = to_minutes((string)$av["start_time"]);
  $endMin   = to_minutes((string)$av["end_time"]);
  $tMin     = to_minutes($timeHm);

  // Slot duration must match appointments default
  $slotMinutes = 30;

  if ($tMin < $startMin || $tMin + $slotMinutes > $endMin) {
    json_response(422, ["ok"=>false, "error"=>"Selected time is outside doctor's availability"]);
  }

  // Past time check for today
  $now = new DateTime();
  if ($dateIso === $now->format("Y-m-d") && $tMin <= to_minutes($now->format("H:i"))) {
    json_response(422, ["ok"=>false, "error"=>"Selected time has already passed"]);
  }

  $pdo->beginTransaction();

  // Insert appointment (conflict-safe due to uq_doc_slot)
  try {
    $ins = $pdo->prepare("
      INSERT INTO appointments
        (patient_id, doctor_id, speciality_key, consult_type, appointment_date, appointment_time, duration_minutes, symptoms, status)
      VALUES
        (?, ?, ?, ?, ?, ?, 30, ?, 'BOOKED')
    ");
    $ins->execute([$uid, $doctorId, $specKey, $ctype, $dateIso, $timeHm, $symptoms]);
    $apptId = (int)$pdo->lastInsertId();
  } catch (PDOException $e) {
    // MySQL duplicate key = slot already taken
    if ((int)$e->errorInfo[1] === 1062) {
      $pdo->rollBack();
      json_response(409, ["ok"=>false, "error"=>"Someone else booked this time slot. Please choose another time."]);
    }
    throw $e;
  }

  // Notifications for patient + doctor (in-app)
  $pTitle = "Appointment booked";
  $pBody  = "Your appointment is booked on {$dateIso} at {$timeHm} ({$ctype}).";

  $dTitle = "New appointment";
  $dBody  = "You have a new appointment on {$dateIso} at {$timeHm} ({$ctype}).";

  $n = $pdo->prepare("INSERT INTO notifications (user_id, title, body) VALUES (?, ?, ?)");
  $n->execute([$uid, $pTitle, $pBody]);
  $n->execute([$doctorId, $dTitle, $dBody]);

  $pdo->commit();

  json_response(200, ["ok"=>true, "data"=>["bookingId"=>(string)$apptId]]);

} catch (Throwable $e) {
  if ($pdo->inTransaction()) $pdo->rollBack();
  json_response(500, ["ok"=>false, "error"=>"Failed to create appointment"]);
}
