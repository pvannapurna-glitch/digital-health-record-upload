<?php
http_response_code(403);
header('Content-Type: text/plain');
die("403 Forbidden: Direct access to Aadhaar documents storage directory is disallowed.");
