<?php

header('X-A: 1');
setcookie('a', 'b');
http_response_code(404);
http_response_code();
headers_sent();
