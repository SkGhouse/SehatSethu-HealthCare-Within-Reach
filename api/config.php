<?php

return [
  "db" => [
    "host" => "127.0.0.1",
    "name" => "sehatsethu",
    "user" => "root",
    "pass" => "", 
    "charset" => "utf8mb4"
  ],

  "app" => [
    "base_url" => "http://localhost/sehatsethu_api",
    "api_prefix" => "/api"
  ],

  "jwt" => [
    "secret" => "7734f8c42da176949239518384b261819532b4ddb0f9ad08276a4f3748e1b356",
    "issuer" => "sehatsethu",
    "audience" => "sehatsethu_mobile",
    "ttl_seconds" => 60 * 60 * 24 // 24h
  ],

  "mail" => [
    "from_email" => "sehatsethu@gmail.com",
    "from_name" => "SehatSethu",
    
    "smtp" => [
      "enabled" => true,
      "host" => "smtp.gmail.com",
      "port" => 587,
      "username" => "sehatsethu@gmail.com",
      "password" => "hmzhjdfhynkjdpjv",
      "secure" => "tls"
    ]
    
  ]
];
