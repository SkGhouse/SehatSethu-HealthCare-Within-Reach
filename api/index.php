<?php
require __DIR__ . "/helpers.php";

if ($_SERVER["REQUEST_METHOD"] === "OPTIONS") {
  json_response(200, ["ok" => true]);
}

$path = $_SERVER["REQUEST_URI"];
$base = "/sehatsethu_api/api/";
$pos = strpos($path, $base);
$route = $pos !== false ? substr($path, $pos + strlen($base)) : "";
$route = strtok($route, "?"); // remove query string
$route = trim($route, "/");   // normalize

switch ($route) {

    case "auth/login":
case "auth/login.php":
  require __DIR__ . "/auth/login.php"; break;

 
  case "auth/register_send_otp":
case "auth/register_send_otp.php":
  require __DIR__ . "/auth/register_send_otp.php"; break;

case "auth/verify_email_otp":
case "auth/verify_email_otp.php":
  require __DIR__ . "/auth/verify_email_otp.php"; break;

case "auth/resend_email_otp":
case "auth/resend_email_otp.php":
  require __DIR__ . "/auth/resend_email_otp.php"; break;

case "auth/forgot_send_otp":
case "auth/forgot_send_otp.php":
  require __DIR__ . "/auth/forgot_send_otp.php"; break;

case "auth/verify_reset_otp":
case "auth/verify_reset_otp.php":
  require __DIR__ . "/auth/verify_reset_otp.php"; break;

case "auth/reset_password_otp":
case "auth/reset_password_otp.php":
  require __DIR__ . "/auth/reset_password_otp.php"; break;

  default:
    json_response(404, ["ok" => false, "error" => "Route not found", "route" => $route]);
}
