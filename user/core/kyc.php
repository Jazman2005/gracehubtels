<?php
// KYC feature removed
function verify_nin(int $uid, array $input): array {
    return ['success' => false, 'message' => 'KYC verification is no longer available.'];
}
function verify_bvn(int $uid, array $input): array {
    return ['success' => false, 'message' => 'KYC verification is no longer available.'];
}
