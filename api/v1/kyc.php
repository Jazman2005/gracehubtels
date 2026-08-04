<?php
http_response_code(410);
echo json_encode(['status' => 'error', 'message' => 'KYC verification is no longer available.']);
