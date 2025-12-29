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
require_fields($data, ["appointment_id"]);

$apptKey = trim((string)$data["appointment_id"]);
if ($apptKey === "") json_response(422, ["ok"=>false, "error"=>"Invalid appointment_id"]);

$isNumericId = preg_match('/^\d+$/', $apptKey) === 1;

try {
  $sql = "
    SELECT a.id, a.public_code, a.specialty, a.consult_type, a.symptoms, a.fee_amount, a.scheduled_at, a.duration_min, a.status,
           u.full_name AS doctor_name,
           dp.specialization,
           dp.practice_place
    FROM appointments a
    JOIN users u ON u.id = a.doctor_id
    LEFT JOIN doctor_profiles dp ON dp.user_id = a.doctor_id
    WHERE a.patient_id = ?
      AND ".($isNumericId ? "a.id = ?" : "a.public_code = ?")."
    LIMIT 1
  ";

  $st = $pdo->prepare($sql);
  $st->execute([$uid, $apptKey]);
  $row = $st->fetch(PDO::FETCH_ASSOC);

  if (!$row) json_response(404, ["ok"=>false, "error"=>"Appointment not found"]);

  $scheduled = new DateTime((string)$row["scheduled_at"]);
  $now = new DateTime();

  $diffSeconds = $scheduled->getTimestamp() - $now->getTimestamp();
  $minutesLeft = (int)floor($diffSeconds / 60);

  // Labels
  $dateLabel = $scheduled->format("Y-m-d");
  $timeLabel = $scheduled->format("H:i");

  json_response(200, [
    "ok"=>true,
    "data"=>[
      "appointmentId"   => (string)($row["public_code"] ?? ""),
      "internalId"      => (string)($row["id"] ?? ""),
      "doctorName"      => (string)($row["doctor_name"] ?? "Doctor"),
      "specialization"  => (string)($row["specialization"] ?? ""),
      "worksAt"         => (string)($row["practice_place"] ?? ""),
      "specialtyKey"    => (string)($row["specialty"] ?? ""),
      "consultType"     => (string)($row["consult_type"] ?? ""),
      "symptoms"        => (string)($row["symptoms"] ?? ""),
      "fee"             => (int)($row["fee_amount"] ?? 0),
      "dateLabel"       => $dateLabel,
      "timeLabel"       => $timeLabel,
      "scheduledAt"     => (string)($row["scheduled_at"] ?? ""),
      "durationMinutes" => (int)($row["duration_min"] ?? 0),
      "status"          => (string)($row["status"] ?? ""),
      "minutesLeft"     => $minutesLeft
    ]
  ]);

} catch (Throwable $e) {
  json_response(500, ["ok"=>false, "error"=>"Failed to load appointment"]);
}
