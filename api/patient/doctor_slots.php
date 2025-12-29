<?php
require_once __DIR__ . '/../helpers.php';
require_once __DIR__ . '/../db.php';

cors_json();
$auth = require_auth();
$pdo = db();
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

$q = read_json();
if (!is_array($q)) $q = [];

$doctorId  = (int)($q["doctorId"] ?? $q["doctor_id"] ?? 0);
$daysAhead = (int)($q["daysAhead"] ?? $q["days"] ?? 7);

if ($doctorId <= 0) json_response(422, ["ok"=>false, "error"=>"Missing doctorId"]);
if ($daysAhead < 1) $daysAhead = 1;
if ($daysAhead > 14) $daysAhead = 14;

function is_localhost(): bool {
  $ip = $_SERVER["REMOTE_ADDR"] ?? "";
  return in_array($ip, ["127.0.0.1", "::1"], true);
}
function norm_time($t) {
  if ($t === null) return "";
  $t = trim((string)$t);
  // accept HH:mm or HH:mm:ss
  if (preg_match('/^\d{2}:\d{2}(:\d{2})?$/', $t)) return substr($t, 0, 5);
  return "";
}
function to_minutes($t) {
  $t = norm_time($t);
  if ($t === "") return 0;
  $p = explode(":", $t);
  return ((int)($p[0] ?? 0))*60 + ((int)($p[1] ?? 0));
}
function fmt_hhmm($mins) {
  $h = intdiv($mins, 60); $m = $mins % 60;
  return sprintf("%02d:%02d", $h, $m);
}
function label_12h($hhmm) {
  $p = explode(":", $hhmm);
  $h = (int)($p[0] ?? 0); $m = (int)($p[1] ?? 0);
  $ampm = $h >= 12 ? "PM" : "AM";
  $h12 = $h % 12; if ($h12 === 0) $h12 = 12;
  return $h12 . ":" . sprintf("%02d", $m) . " " . $ampm;
}
function section_key($hhmm) {
  $h = (int)explode(":", $hhmm)[0];
  if ($h < 12) return "MORNING";
  if ($h < 17) return "AFTERNOON";
  return "EVENING";
}
function has_col(PDO $pdo, string $table, string $col): bool {
  $s = $pdo->prepare("
    SELECT 1
    FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME = ?
      AND COLUMN_NAME = ?
    LIMIT 1
  ");
  $s->execute([$table, $col]);
  return (bool)$s->fetchColumn();
}

try {
  // ✅ IMPORTANT: if this fails you will get 404 (so slots never show)
  $chk = $pdo->prepare("
    SELECT u.id, u.is_active, u.admin_verification_status
    FROM users u
    WHERE u.id=?
      AND u.role='DOCTOR'
    LIMIT 1
  ");
  $chk->execute([$doctorId]);
  $doc = $chk->fetch(PDO::FETCH_ASSOC);
  if (!$doc) json_response(404, ["ok"=>false, "error"=>"Doctor not found"]);

  // keep your policy: only verified + active doctors show slots
  if ((int)$doc["is_active"] !== 1 || strtoupper((string)$doc["admin_verification_status"]) !== "VERIFIED") {
    json_response(404, ["ok"=>false, "error"=>"Doctor not found"]);
  }

  // weekly availability
  $avStmt = $pdo->prepare("
    SELECT day_of_week, enabled, start_time, end_time
    FROM doctor_availability
    WHERE user_id=?
    ORDER BY day_of_week ASC
  ");
  $avStmt->execute([$doctorId]);
  $avRows = $avStmt->fetchAll(PDO::FETCH_ASSOC);

  $weekly = [];
  if (!$avRows || count($avRows) === 0) {
    // fallback defaults
    for ($d=1; $d<=7; $d++) {
      $weekly[$d] = ["enabled" => ($d <= 5), "start" => "09:00", "end" => "17:00"];
    }
  } else {
    foreach ($avRows as $r) {
      $dow = (int)($r["day_of_week"] ?? 0);
      if ($dow < 1 || $dow > 7) continue;

      $en = ((int)($r["enabled"] ?? 0) === 1);
      $st = norm_time($r["start_time"] ?? "09:00");
      $et = norm_time($r["end_time"] ?? "17:00");

      // if invalid range, disable
      if ($en && ($st === "" || $et === "" || to_minutes($et) <= to_minutes($st))) $en = false;

      $weekly[$dow] = [
        "enabled" => $en,
        "start" => $st !== "" ? $st : "09:00",
        "end"   => $et !== "" ? $et : "17:00"
      ];
    }
    for ($d=1; $d<=7; $d++) {
      if (!isset($weekly[$d])) $weekly[$d] = ["enabled"=>false, "start"=>"09:00", "end"=>"17:00"];
    }
  }

  // date window
  $startDate = new DateTime("today");
  $endDate   = (clone $startDate)->modify("+".($daysAhead-1)." day");
  $startIso  = $startDate->format("Y-m-d");
  $endIso    = $endDate->format("Y-m-d");

  // booked slots (schema tolerant)
  $booked = [];

  $tbl = "appointments";
  $dateCol = has_col($pdo,$tbl,"appointment_date") ? "appointment_date" : (has_col($pdo,$tbl,"date") ? "date" : null);
  $timeCol = has_col($pdo,$tbl,"appointment_time") ? "appointment_time" : (has_col($pdo,$tbl,"time") ? "time" : null);
  $docCol  = has_col($pdo,$tbl,"doctor_id") ? "doctor_id" : (has_col($pdo,$tbl,"doctorId") ? "doctorId" : null);
  $statusCol = has_col($pdo,$tbl,"status") ? "status" : null;

  // if status column exists, consider these as blocking
  $blocking = ["BOOKED","CONFIRMED","SCHEDULED","APPROVED","PENDING"];

  if ($dateCol && $timeCol && $docCol) {
    $sql = "SELECT $dateCol AS d, $timeCol AS t" . ($statusCol ? ", $statusCol AS s" : "") . "
            FROM $tbl
            WHERE $docCol = ?
              AND $dateCol BETWEEN ? AND ?";

    $st = $pdo->prepare($sql);
    $st->execute([$doctorId, $startIso, $endIso]);

    while ($row = $st->fetch(PDO::FETCH_ASSOC)) {
      $d = (string)($row["d"] ?? "");
      $t = norm_time($row["t"] ?? "");
      if ($d === "" || $t === "") continue;

      if ($statusCol) {
        $s = strtoupper(trim((string)($row["s"] ?? "")));
        if (!in_array($s, $blocking, true)) continue;
      }

      if (!isset($booked[$d])) $booked[$d] = [];
      $booked[$d][$t] = true;
    }
  }

  // build slots
  $slotMinutes = 30;
  $days = [];

  for ($i=0; $i<$daysAhead; $i++) {
    $dt     = (clone $startDate)->modify("+$i day");
    $iso    = $dt->format("Y-m-d");
    $dow    = (int)$dt->format("N");
    $dayNum = (int)$dt->format("j");

    $w = $weekly[$dow] ?? ["enabled"=>false, "start"=>"09:00", "end"=>"17:00"];
    $wEnabled = (bool)$w["enabled"];
    $st = (string)$w["start"];
    $et = (string)$w["end"];

    $sections = ["MORNING"=>[], "AFTERNOON"=>[], "EVENING"=>[]];
    $totalSlots = 0;
    $freeSlots = 0;

    if ($wEnabled && norm_time($st) !== "" && norm_time($et) !== "" && to_minutes($et) > to_minutes($st)) {
      $sMin = to_minutes($st);
      $eMin = to_minutes($et);

      for ($m=$sMin; $m + $slotMinutes <= $eMin; $m += $slotMinutes) {
        $hhmm = fmt_hhmm($m);
        $disabled = isset($booked[$iso]) && isset($booked[$iso][$hhmm]);
        $key = section_key($hhmm);

        $sections[$key][] = [
          "value" => $hhmm,
          "label" => label_12h($hhmm),
          "disabled" => $disabled
        ];

        $totalSlots++;
        if (!$disabled) $freeSlots++;
      }
    }

    $secArr = [];
    foreach (["MORNING","AFTERNOON","EVENING"] as $k) {
      $secArr[] = ["key"=>$k, "slots"=>$sections[$k]];
    }

    // enabled should mean: weekly enabled AND there is at least 1 slot generated
    $enabled = $wEnabled && ($totalSlots > 0);

    $dayObj = [
      "date" => $iso,
      "dayNum" => $dayNum,
      "enabled" => $enabled,
      "sections" => $secArr
    ];

    // ✅ LOCALHOST DEBUG (won't break your UI)
    if (is_localhost()) {
      $dayObj["_debug"] = [
        "dow" => $dow,
        "weekly_enabled" => $wEnabled,
        "start" => $st,
        "end" => $et,
        "slot_total" => $totalSlots,
        "slot_free" => $freeSlots,
        "booked_count_for_day" => isset($booked[$iso]) ? count($booked[$iso]) : 0
      ];
    }

    $days[] = $dayObj;
  }

  $resp = ["ok"=>true, "data"=>["days"=>$days]];

  // ✅ LOCALHOST META
  if (is_localhost()) {
    $resp["_meta"] = [
      "server_tz" => date_default_timezone_get(),
      "server_today" => (new DateTime("today"))->format("Y-m-d"),
      "range" => [$startIso, $endIso],
      "doctor_id" => $doctorId,
      "availability_rows" => is_array($avRows) ? count($avRows) : 0,
      "appointments_cols" => [
        "dateCol"=>$dateCol, "timeCol"=>$timeCol, "docCol"=>$docCol, "statusCol"=>$statusCol
      ]
    ];
  }

  json_response(200, $resp);

} catch (Throwable $e) {
  error_log("patient/doctor_slots.php ERROR: ".$e->getMessage());
  json_response(500, [
    "ok"=>false,
    "error"=>"Server error",
    "debug"=> is_localhost() ? $e->getMessage() : null
  ]);
}
