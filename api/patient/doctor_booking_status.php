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
if ($_SERVER["REQUEST_METHOD"] !== "POST") json_response(405, ["ok"=>false, "error"=>"POST only"]);

$data = read_json();
require_fields($data, ["doctor_id"]);

$doctorId = (int)$data["doctor_id"];
if ($doctorId <= 0) json_response(422, ["ok"=>false, "error"=>"Invalid doctor_id"]);

try {
  $now = (new DateTime())->format("Y-m-d H:i:s");

  $st = $pdo->prepare("
    SELECT id, public_code, consult_type, specialty, scheduled_at, status
    FROM appointments
    WHERE patient_id = ?
      AND doctor_id = ?
      AND scheduled_at >= ?
      AND status IN ('BOOKED','CONFIRMED')
    ORDER BY scheduled_at ASC
    LIMIT 1
  ");
  $st->execute([$uid, $doctorId, $now]);
  $a = $st->fetch(PDO::FETCH_ASSOC);

  if (!$a) {
    json_response(200, ["ok"=>true, "data"=>["hasActiveBooking"=>false]]);
  }

  json_response(200, ["ok"=>true, "data"=>[
    "hasActiveBooking" => true,
    "appointmentId" => (string)$a["public_code"],
    "scheduledAt" => (string)$a["scheduled_at"],
    "consultType" => (string)$a["consult_type"],
    "specialtyKey" => (string)$a["specialty"],
    "status" => (string)$a["status"],
  ]]);

} catch (Throwable $e) {
  json_response(500, ["ok"=>false, "error"=>"Failed to check booking status"]);
}
