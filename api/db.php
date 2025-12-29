<?php
function db() : PDO {
  static $pdo = null;
  if ($pdo) return $pdo;

  $cfg = require __DIR__ . "/config.php";
  $db = $cfg["db"];
  $dsn = "mysql:host={$db["host"]};dbname={$db["name"]};charset={$db["charset"]}";

  $pdo = new PDO($dsn, $db["user"], $db["pass"], [
    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    PDO::ATTR_EMULATE_PREPARES => false,
  ]);

  return $pdo;
}
