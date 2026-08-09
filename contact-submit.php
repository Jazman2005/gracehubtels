<?php
// Backend for the public contact form (contact.php). Previously the form only
// faked a success response client-side (main.js) and never sent the message
// anywhere — this is the actual handler it now posts to.
require_once __DIR__ . '/user/core/db.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    json_response(['success' => false, 'message' => 'Method not allowed'], 405);
}

if (!rate_limit('contact_form', 3, 300)) {
    json_response(['success' => false, 'message' => 'Too many messages sent. Please try again later.'], 429);
}

if (detect_tamper($_POST)) {
    json_response(['success' => false, 'message' => 'Invalid input']);
}

$fullname = sanitize(trim($_POST['fullname'] ?? ''), 100);
$email = trim($_POST['email'] ?? '');
$subject_key = trim($_POST['subject'] ?? '');
$message = sanitize(trim($_POST['message'] ?? ''), 1000);

$subjects = [
    'integration' => 'Merchant Account & API Integration',
    'settlement'  => 'Transaction Failures / Chargebacks',
    'wallet'      => 'Digital Wallet & Transfers',
    'corporate'   => 'Corporate Inquiries & Partnerships',
    'other'       => 'General Feedback / Other',
];

if (!$fullname || !$message || !isset($subjects[$subject_key])) {
    json_response(['success' => false, 'message' => 'Please fill in all fields.']);
}
if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
    json_response(['success' => false, 'message' => 'Please enter a valid email address.']);
}

log_write('contact', 'INFO', 'New contact form submission', [
    'name' => $fullname, 'email' => $email, 'subject' => $subject_key,
]);

notify_admin(
    'New Contact Form Message: ' . $subjects[$subject_key],
    "<div style='text-align:center;padding:10px 0 16px;'>
<span style='font-size:28px;line-height:1;font-weight:800;color:#4F46E5;'>@</span>
<h3 style='color:#4F46E5;font-size:18px;margin:8px 0 4px;'>New Contact Form Message</h3>
<p style='color:#475569;font-size:13px;margin:0;'>A visitor submitted the public contact form.</p>
</div>
<table style='width:100%;border-collapse:collapse;'>
<tr><td style='padding:8px 12px;background:#f8fafc;border-radius:8px;'>
<table width='100%'>
<tr><td style='padding:4px 0;color:#64748b;font-size:13px;'>Name</td><td style='padding:4px 0;text-align:right;font-weight:600;color:#0f172a;font-size:13px;'>" . htmlspecialchars($fullname, ENT_QUOTES) . "</td></tr>
<tr><td style='padding:4px 0;color:#64748b;font-size:13px;'>Email</td><td style='padding:4px 0;text-align:right;font-weight:600;color:#0f172a;font-size:13px;'>" . htmlspecialchars($email, ENT_QUOTES) . "</td></tr>
<tr><td style='padding:4px 0;color:#64748b;font-size:13px;'>Topic</td><td style='padding:4px 0;text-align:right;font-weight:600;color:#0f172a;font-size:13px;'>" . htmlspecialchars($subjects[$subject_key], ENT_QUOTES) . "</td></tr>
</table>
</td></tr>
</table>
<div style='margin-top:16px;padding:12px 16px;background:#f8fafc;border-radius:8px;'>
<p style='margin:0;color:#64748b;font-size:12px;'>Message</p>
<p style='margin:6px 0 0;color:#0f172a;font-size:13px;white-space:pre-wrap;'>" . nl2br(htmlspecialchars($message, ENT_QUOTES)) . "</p>
</div>"
);

json_response(['success' => true, 'message' => 'Your message has been sent.']);
