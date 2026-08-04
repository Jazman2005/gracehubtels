<?php
require_once __DIR__.'/core/auth.php';
auth_user();
$uid = $_SESSION['user_id'];
$user = get_user($uid);
$name = explode(' ', $user['full_name'])[0];
if (($user['user_type'] ?? '') !== 'vendor') {
    $page_title = 'API Documentation';
    include __DIR__ . '/includes/header.php';
    include __DIR__ . '/includes/sidebar.php';
    ?>
    <div class="max-w-xl mx-auto mt-16 text-center">
        <div class="w-20 h-20 rounded-full bg-amber-100 flex items-center justify-center mx-auto mb-6">
            <i class="fas fa-lock text-3xl text-amber-600"></i>
        </div>
        <h2 class="text-2xl font-bold text-slate-800 mb-3">Vendor Feature</h2>
        <p class="text-slate-500 mb-6">The API Documentation is only available for vendors. Please contact the admin to upgrade your account to a vendor account.</p>
        <a href="/user/support" class="inline-flex items-center gap-2 bg-brand-navy text-white px-6 py-3 rounded-xl font-semibold hover:bg-brand-navyDark transition-colors">
            <i class="fas fa-envelope"></i> Contact Support
        </a>
    </div>
    <?php
    include __DIR__ . '/includes/footer.php';
    exit;
}

$page_title = 'API Documentation'; 
include __DIR__ . '/includes/header.php'; 
include __DIR__ . '/includes/sidebar.php'; 
?>

<div class="mb-8">
    <div class="flex items-center gap-4">
        <a href="/user/developer-api" class="w-10 h-10 rounded-full bg-white border border-slate-200 flex items-center justify-center text-slate-600 hover:bg-slate-50 transition-colors">
            <i class="fas fa-arrow-left"></i>
        </a>
        <div>
            <h2 class="text-2xl font-bold text-slate-800">Full API Documentation</h2>
            <p class="text-slate-500 mt-1">Detailed guide for integrating all Gracehubtels services.</p>
        </div>
    </div>
</div>

<div class="bg-white rounded-2xl border border-slate-200 p-6 card-shadow mb-8">
    <h3 class="text-xl font-bold text-slate-800 mb-4">Authentication</h3>
    <p class="text-slate-600 mb-4">All API requests must be authenticated using your API Key via the <code>Authorization</code> header unless stated otherwise.</p>
    <div class="bg-slate-800 text-green-400 p-4 rounded-xl font-mono text-sm overflow-x-auto mb-6">
        Authorization: Bearer msj_live_...
    </div>
    <p class="text-slate-600 mb-4">All requests must be made over <strong>HTTPS</strong>. Content-Type for POST requests must be <code>application/json</code>.</p>
    <p class="text-slate-600 mb-4"><strong>Base URL:</strong> <code>https://gracehubtels.com/api/v1</code></p>
</div>

<div class="space-y-8">

    <!-- Auth: Login -->
    <div class="bg-white rounded-2xl border border-slate-200 p-6 card-shadow">
        <div class="flex items-center gap-3 mb-4">
            <span class="bg-green-100 text-green-700 font-bold px-3 py-1 rounded text-sm">POST</span>
            <h3 class="text-lg font-bold text-slate-800">/api/v1/login.php</h3>
        </div>
        <p class="text-slate-600 mb-4">No auth required. Log in and receive your API token.</p>
        <h4 class="font-bold text-slate-700 mb-2">Request Body (JSON)</h4>
        <pre class="bg-slate-50 border border-slate-200 p-4 rounded-xl text-sm overflow-x-auto text-slate-800 mb-4">{
  "phone": "08012345678",
  "password": "your_password"
}</pre>
        <h4 class="font-bold text-slate-700 mb-2">Response</h4>
        <pre class="bg-slate-50 border border-slate-200 p-4 rounded-xl text-sm overflow-x-auto text-slate-800">{
  "status": "success",
  "data": {
    "token": "msj_live_...",
    "user": {
      "id": 1,
      "name": "John Doe",
      "email": "john@example.com",
      "phone": "08012345678",
      "wallet_balance": 5000.00,
      "cashback_balance": 100.00,
      "user_type": "regular"
    }
  }
}</pre>
    </div>

    <!-- Auth: Register -->
    <div class="bg-white rounded-2xl border border-slate-200 p-6 card-shadow">
        <div class="flex items-center gap-3 mb-4">
            <span class="bg-green-100 text-green-700 font-bold px-3 py-1 rounded text-sm">POST</span>
            <h3 class="text-lg font-bold text-slate-800">/api/v1/register.php</h3>
        </div>
        <p class="text-slate-600 mb-4">No auth required. Create a new account.</p>
        <h4 class="font-bold text-slate-700 mb-2">Request Body (JSON)</h4>
        <pre class="bg-slate-50 border border-slate-200 p-4 rounded-xl text-sm overflow-x-auto text-slate-800 mb-4">{
  "full_name": "John Doe",
  "phone": "08012345678",
  "password": "your_password",
  "email": "john@example.com"
}</pre>
        <h4 class="font-bold text-slate-700 mb-2">Response</h4>
        <pre class="bg-slate-50 border border-slate-200 p-4 rounded-xl text-sm overflow-x-auto text-slate-800">{
  "status": "success",
  "message": "Account created successfully.",
  "data": {
    "token": "msj_live_...",
    "user": {
      "id": 1,
      "name": "John Doe",
      "phone": "08012345678",
      "email": "john@example.com"
    }
  }
}</pre>
    </div>

    <!-- Get Balance -->
    <div class="bg-white rounded-2xl border border-slate-200 p-6 card-shadow">
        <div class="flex items-center gap-3 mb-4">
            <span class="bg-blue-100 text-blue-700 font-bold px-3 py-1 rounded text-sm">GET</span>
            <h3 class="text-lg font-bold text-slate-800">/api/v1/balance.php</h3>
        </div>
        <p class="text-slate-600 mb-4">Retrieve your current wallet and cashback balance.</p>
        <h4 class="font-bold text-slate-700 mb-2">Response</h4>
        <pre class="bg-slate-50 border border-slate-200 p-4 rounded-xl text-sm overflow-x-auto text-slate-800">{
  "status": "success",
  "data": {
    "wallet_balance": 5000.00,
    "cashback_balance": 100.00,
    "currency": "NGN"
  }
}</pre>
    </div>

    <!-- Get Profile -->
    <div class="bg-white rounded-2xl border border-slate-200 p-6 card-shadow">
        <div class="flex items-center gap-3 mb-4">
            <span class="bg-blue-100 text-blue-700 font-bold px-3 py-1 rounded text-sm">GET</span>
            <h3 class="text-lg font-bold text-slate-800">/api/v1/profile.php</h3>
        </div>
        <p class="text-slate-600 mb-4">Retrieve your profile information.</p>
        <h4 class="font-bold text-slate-700 mb-2">Response</h4>
        <pre class="bg-slate-50 border border-slate-200 p-4 rounded-xl text-sm overflow-x-auto text-slate-800">{
  "status": "success",
  "data": {
    "user_id": 1,
    "name": "John Doe",
    "email": "john@example.com",
    "phone": "08012345678",
    "user_type": "regular",
    "webhook_url": "https://..."
  }
}</pre>
    </div>

    <!-- Service Catalog -->
    <div class="bg-white rounded-2xl border border-slate-200 p-6 card-shadow">
        <div class="flex items-center gap-3 mb-4">
            <span class="bg-blue-100 text-blue-700 font-bold px-3 py-1 rounded text-sm">GET</span>
            <h3 class="text-lg font-bold text-slate-800">/api/v1/catalog.php</h3>
        </div>
        <p class="text-slate-600 mb-4">Retrieve the list of available services (Data plans, Cable TV plans, Electricity Discos, Exam PIN pricing) based on your account level.</p>
        <h4 class="font-bold text-slate-700 mb-2">Response</h4>
        <pre class="bg-slate-50 border border-slate-200 p-4 rounded-xl text-sm overflow-x-auto text-slate-800">{
  "status": "success",
  "data": {
    "data_plans": [
      {
        "id": 1,
        "network": "MTN",
        "plan_id": "12",
        "plan_name": "MTN 1GB",
        "display_name": "1GB",
        "data_type": "SME",
        "data_size": "1GB",
        "validity": "30 Days",
        "selling_price": "250.00",
        "network_id": 1
      }
    ],
    "cable_plans": [...],
    "discos": [...],
    "exam_prices": {"waec": 1000.00, "neco": 1200.00, "nabteb": 1500.00}
  }
}</pre>
    </div>

    <!-- Dashboard -->
    <div class="bg-white rounded-2xl border border-slate-200 p-6 card-shadow">
        <div class="flex items-center gap-3 mb-4">
            <span class="bg-blue-100 text-blue-700 font-bold px-3 py-1 rounded text-sm">GET</span>
            <h3 class="text-lg font-bold text-slate-800">/api/v1/dashboard.php</h3>
        </div>
        <p class="text-slate-600 mb-4">Full dashboard data including user info, virtual accounts, transactions, and monthly spend.</p>
        <h4 class="font-bold text-slate-700 mb-2">Response</h4>
        <pre class="bg-slate-50 border border-slate-200 p-4 rounded-xl text-sm overflow-x-auto text-slate-800">{
  "status": "success",
  "data": {
    "user": { "id": 1, "full_name": "John", "wallet_balance": 5000 },
    "virtual_accounts": [...],
    "transactions": [...],
    "monthly_spend": 15000
  }
}</pre>
    </div>

    <!-- Transactions -->
    <div class="bg-white rounded-2xl border border-slate-200 p-6 card-shadow">
        <div class="flex items-center gap-3 mb-4">
            <span class="bg-blue-100 text-blue-700 font-bold px-3 py-1 rounded text-sm">GET</span>
            <h3 class="text-lg font-bold text-slate-800">/api/v1/transactions.php</h3>
        </div>
        <p class="text-slate-600 mb-4">Paginated transaction history.</p>
        <h4 class="font-bold text-slate-700 mb-2">Query Parameters</h4>
        <pre class="bg-slate-50 border border-slate-200 p-4 rounded-xl text-sm overflow-x-auto text-slate-800 mb-4">?page=1&per_page=20&filter=airtime</pre>
        <h4 class="font-bold text-slate-700 mb-2">Response</h4>
        <pre class="bg-slate-50 border border-slate-200 p-4 rounded-xl text-sm overflow-x-auto text-slate-800">{
  "status": "success",
  "data": {
    "transactions": [...],
    "total": 50,
    "page": 1,
    "per_page": 20
  }
}</pre>
    </div>

    <!-- Buy Airtime -->
    <div class="bg-white rounded-2xl border border-slate-200 p-6 card-shadow">
        <div class="flex items-center gap-3 mb-4">
            <span class="bg-green-100 text-green-700 font-bold px-3 py-1 rounded text-sm">POST</span>
            <h3 class="text-lg font-bold text-slate-800">/api/v1/airtime.php</h3>
        </div>
        <p class="text-slate-600 mb-4">Purchase airtime for any network.</p>
        <h4 class="font-bold text-slate-700 mb-2">Request Body (JSON)</h4>
        <pre class="bg-slate-50 border border-slate-200 p-4 rounded-xl text-sm overflow-x-auto text-slate-800 mb-4">{
  "network": "MTN",
  "phone": "08012345678",
  "amount": 100
}</pre>
        <h4 class="font-bold text-slate-700 mb-2">Response</h4>
        <pre class="bg-slate-50 border border-slate-200 p-4 rounded-xl text-sm overflow-x-auto text-slate-800">{
  "status": "success",
  "message": "Airtime purchase successful",
  "data": {
    "reference": "MSJ...",
    "cashback_earned": 2.0
  }
}</pre>
    </div>

    <!-- Buy Data -->
    <div class="bg-white rounded-2xl border border-slate-200 p-6 card-shadow">
        <div class="flex items-center gap-3 mb-4">
            <span class="bg-green-100 text-green-700 font-bold px-3 py-1 rounded text-sm">POST</span>
            <h3 class="text-lg font-bold text-slate-800">/api/v1/data.php</h3>
        </div>
        <p class="text-slate-600 mb-4">Purchase data bundle for any network.</p>
        <h4 class="font-bold text-slate-700 mb-2">Request Body (JSON)</h4>
        <pre class="bg-slate-50 border border-slate-200 p-4 rounded-xl text-sm overflow-x-auto text-slate-800 mb-4">{
  "network": "MTN",
  "phone": "08012345678",
  "plan_id": 12
}</pre>
        <h4 class="font-bold text-slate-700 mb-2">Response</h4>
        <pre class="bg-slate-50 border border-slate-200 p-4 rounded-xl text-sm overflow-x-auto text-slate-800">{
  "status": "success",
  "message": "Data purchase successful",
  "data": {
    "reference": "MSJ...",
    "cashback_earned": 5.0
  }
}</pre>
    </div>

    <!-- Validate Meter -->
    <div class="bg-white rounded-2xl border border-slate-200 p-6 card-shadow">
        <div class="flex items-center gap-3 mb-4">
            <span class="bg-green-100 text-green-700 font-bold px-3 py-1 rounded text-sm">POST</span>
            <h3 class="text-lg font-bold text-slate-800">/api/v1/validate_meter.php</h3>
        </div>
        <p class="text-slate-600 mb-4">Validate electricity meter number before purchase.</p>
        <h4 class="font-bold text-slate-700 mb-2">Request Body (JSON)</h4>
        <pre class="bg-slate-50 border border-slate-200 p-4 rounded-xl text-sm overflow-x-auto text-slate-800 mb-4">{
  "disco_id": "ibedc",
  "meter_number": "111111111111",
  "meter_type": "prepaid"
}</pre>
        <h4 class="font-bold text-slate-700 mb-2">Response</h4>
        <pre class="bg-slate-50 border border-slate-200 p-4 rounded-xl text-sm overflow-x-auto text-slate-800">{
  "status": "success",
  "data": {
    "name": "JOHN DOE",
    "address": "123 MAIN ST"
  }
}</pre>
    </div>

    <!-- Buy Electricity -->
    <div class="bg-white rounded-2xl border border-slate-200 p-6 card-shadow">
        <div class="flex items-center gap-3 mb-4">
            <span class="bg-green-100 text-green-700 font-bold px-3 py-1 rounded text-sm">POST</span>
            <h3 class="text-lg font-bold text-slate-800">/api/v1/electricity.php</h3>
        </div>
        <p class="text-slate-600 mb-4">Purchase electricity token.</p>
        <h4 class="font-bold text-slate-700 mb-2">Request Body (JSON)</h4>
        <pre class="bg-slate-50 border border-slate-200 p-4 rounded-xl text-sm overflow-x-auto text-slate-800 mb-4">{
  "disco_id": "ibedc",
  "meter_number": "111111111111",
  "meter_type": "prepaid",
  "amount": 1000
}</pre>
        <h4 class="font-bold text-slate-700 mb-2">Response</h4>
        <pre class="bg-slate-50 border border-slate-200 p-4 rounded-xl text-sm overflow-x-auto text-slate-800">{
  "status": "success",
  "message": "Electricity purchase successful",
  "data": {
    "reference": "MSJ...",
    "token": "1234 5678 9012 3456 7890",
    "cashback_earned": 0
  }
}</pre>
    </div>
    
    <!-- Cable TV -->
    <div class="bg-white rounded-2xl border border-slate-200 p-6 card-shadow">
        <div class="flex items-center gap-3 mb-4">
            <span class="bg-green-100 text-green-700 font-bold px-3 py-1 rounded text-sm">POST</span>
            <h3 class="text-lg font-bold text-slate-800">/api/v1/cabletv.php</h3>
        </div>
        <p class="text-slate-600 mb-4">Subscribe to Cable TV (DSTV, GOTV, Startimes).</p>
        <h4 class="font-bold text-slate-700 mb-2">Request Body (JSON)</h4>
        <pre class="bg-slate-50 border border-slate-200 p-4 rounded-xl text-sm overflow-x-auto text-slate-800 mb-4">{
  "plan_id": 5,
  "smartcard": "1234567890"
}</pre>
        <h4 class="font-bold text-slate-700 mb-2">Response</h4>
        <pre class="bg-slate-50 border border-slate-200 p-4 rounded-xl text-sm overflow-x-auto text-slate-800">{
  "status": "success",
  "message": "Cable TV subscription successful",
  "data": {
    "reference": "MSJ...",
    "cashback_earned": 0
  }
}</pre>
    </div>

    <!-- Validate Cable TV -->
    <div class="bg-white rounded-2xl border border-slate-200 p-6 card-shadow">
        <div class="flex items-center gap-3 mb-4">
            <span class="bg-green-100 text-green-700 font-bold px-3 py-1 rounded text-sm">POST</span>
            <h3 class="text-lg font-bold text-slate-800">/api/v1/validate_cable.php</h3>
        </div>
        <p class="text-slate-600 mb-4">Validate Cable TV Smartcard number before purchasing.</p>
        <h4 class="font-bold text-slate-700 mb-2">Request Body (JSON)</h4>
        <pre class="bg-slate-50 border border-slate-200 p-4 rounded-xl text-sm overflow-x-auto text-slate-800 mb-4">{
  "cable_id": 1,
  "smartcard": "1234567890"
}</pre>
        <h4 class="font-bold text-slate-700 mb-2">Response</h4>
        <pre class="bg-slate-50 border border-slate-200 p-4 rounded-xl text-sm overflow-x-auto text-slate-800">{
  "status": "success",
  "data": {
    "name": "JOHN DOE"
  }
}</pre>
    </div>

    <!-- Exam Pins -->
    <div class="bg-white rounded-2xl border border-slate-200 p-6 card-shadow">
        <div class="flex items-center gap-3 mb-4">
            <span class="bg-green-100 text-green-700 font-bold px-3 py-1 rounded text-sm">POST</span>
            <h3 class="text-lg font-bold text-slate-800">/api/v1/exam_pin.php</h3>
        </div>
        <p class="text-slate-600 mb-4">Generate WAEC/NECO/Nabteb Exam PINs.</p>
        <h4 class="font-bold text-slate-700 mb-2">Request Body (JSON)</h4>
        <pre class="bg-slate-50 border border-slate-200 p-4 rounded-xl text-sm overflow-x-auto text-slate-800 mb-4">{
  "exam_name": "waec",
  "quantity": 1
}</pre>
        <p class="text-slate-500 text-sm mb-4">exam_name: waec, neco, or nabteb</p>
        <h4 class="font-bold text-slate-700 mb-2">Response</h4>
        <pre class="bg-slate-50 border border-slate-200 p-4 rounded-xl text-sm overflow-x-auto text-slate-800">{
  "status": "success",
  "message": "Exam PIN purchased",
  "data": {
    "reference": "MSJ...",
    "pins": ["PIN1234567890"]
  }
}</pre>
    </div>

    <!-- Airtime to Cash -->
    <div class="bg-white rounded-2xl border border-slate-200 p-6 card-shadow">
        <div class="flex items-center gap-3 mb-4">
            <span class="bg-green-100 text-green-700 font-bold px-3 py-1 rounded text-sm">POST</span>
            <h3 class="text-lg font-bold text-slate-800">/api/v1/a2c.php</h3>
        </div>
        <p class="text-slate-600 mb-4">Convert airtime to cash.</p>
        <h4 class="font-bold text-slate-700 mb-2">Request Body (JSON)</h4>
        <pre class="bg-slate-50 border border-slate-200 p-4 rounded-xl text-sm overflow-x-auto text-slate-800 mb-4">{
  "network": "MTN",
  "sender_phone": "08012345678",
  "airtime_amount": 500
}</pre>
        <h4 class="font-bold text-slate-700 mb-2">Response</h4>
        <pre class="bg-slate-50 border border-slate-200 p-4 rounded-xl text-sm overflow-x-auto text-slate-800">{
  "status": "success",
  "message": "...",
  "data": {
    "reference": "MSJ...",
    "cash_expected": 425,
    "transfer_to": "08098765432",
    "a2c_status": "pending"
  }
}</pre>
    </div>

    <!-- Bulk SMS -->
    <div class="bg-white rounded-2xl border border-slate-200 p-6 card-shadow">
        <div class="flex items-center gap-3 mb-4">
            <span class="bg-green-100 text-green-700 font-bold px-3 py-1 rounded text-sm">POST</span>
            <h3 class="text-lg font-bold text-slate-800">/api/v1/bulk_sms.php</h3>
        </div>
        <p class="text-slate-600 mb-4">Send bulk SMS messages.</p>
        <h4 class="font-bold text-slate-700 mb-2">Request Body (JSON)</h4>
        <pre class="bg-slate-50 border border-slate-200 p-4 rounded-xl text-sm overflow-x-auto text-slate-800 mb-4">{
  "sender_id": "Gracehubtels",
  "message": "Hello world",
  "recipients": ["08012345678", "08098765432"]
}</pre>
        <h4 class="font-bold text-slate-700 mb-2">Response</h4>
        <pre class="bg-slate-50 border border-slate-200 p-4 rounded-xl text-sm overflow-x-auto text-slate-800">{
  "status": "success",
  "message": "SMS sent successfully",
  "data": {
    "reference": "MSJ..."
  }
}</pre>
    </div>

    <!-- Recharge Cards -->
    <div class="bg-white rounded-2xl border border-slate-200 p-6 card-shadow">
        <div class="flex items-center gap-3 mb-4">
            <span class="bg-green-100 text-green-700 font-bold px-3 py-1 rounded text-sm">POST</span>
            <h3 class="text-lg font-bold text-slate-800">/api/v1/recharge_card.php</h3>
        </div>
        <p class="text-slate-600 mb-4">Purchase recharge PIN cards (MTN, GLO, AIRTEL, 9MOBILE). Requires transaction PIN.</p>
        <h4 class="font-bold text-slate-700 mb-2">Request Body (JSON)</h4>
        <pre class="bg-slate-50 border border-slate-200 p-4 rounded-xl text-sm overflow-x-auto text-slate-800 mb-4">{
  "network_id": 1,
  "denomination": 100,
  "quantity": 1,
  "pin": "1234"
}</pre>
        <p class="text-slate-500 text-sm mb-4">network_id: 1=MTN, 2=GLO, 3=AIRTEL, 4=9MOBILE | denomination: 100, 200, 500, 1000 | quantity: 1–5</p>
        <h4 class="font-bold text-slate-700 mb-2">Response</h4>
        <pre class="bg-slate-50 border border-slate-200 p-4 rounded-xl text-sm overflow-x-auto text-slate-800">{
  "status": "success",
  "message": "1 card(s) ready",
  "data": {
    "reference": "MSJ...",
    "pins": ["123456789012"]
  }
}</pre>
    </div>

    <!-- Data Cards -->
    <div class="bg-white rounded-2xl border border-slate-200 p-6 card-shadow">
        <div class="flex items-center gap-3 mb-4">
            <span class="bg-green-100 text-green-700 font-bold px-3 py-1 rounded text-sm">POST</span>
            <h3 class="text-lg font-bold text-slate-800">/api/v1/data_card.php</h3>
        </div>
        <p class="text-slate-600 mb-4">Purchase data PIN cards. Requires transaction PIN.</p>
        <h4 class="font-bold text-slate-700 mb-2">Request Body (JSON)</h4>
        <pre class="bg-slate-50 border border-slate-200 p-4 rounded-xl text-sm overflow-x-auto text-slate-800 mb-4">{
  "plan_id": 12,
  "quantity": 1,
  "pin": "1234"
}</pre>
        <p class="text-slate-500 text-sm mb-4">plan_id: use the catalog endpoint to get available data plan IDs</p>
        <h4 class="font-bold text-slate-700 mb-2">Response</h4>
        <pre class="bg-slate-50 border border-slate-200 p-4 rounded-xl text-sm overflow-x-auto text-slate-800">{
  "status": "success",
  "message": "1 data card(s) ready",
  "data": {
    "reference": "MSJ...",
    "pins": ["PIN..."]
  }
}</pre>
    </div>

    <!-- Bank Transfer -->
    <div class="bg-white rounded-2xl border border-slate-200 p-6 card-shadow">
        <div class="flex items-center gap-3 mb-4">
            <span class="bg-green-100 text-green-700 font-bold px-3 py-1 rounded text-sm">POST</span>
            <h3 class="text-lg font-bold text-slate-800">/api/v1/bank_transfer.php</h3>
        </div>
        <p class="text-slate-600 mb-4">Withdraw funds to a bank account. Requires transaction PIN. Pending admin approval.</p>
        <h4 class="font-bold text-slate-700 mb-2">Request Body (JSON)</h4>
        <pre class="bg-slate-50 border border-slate-200 p-4 rounded-xl text-sm overflow-x-auto text-slate-800 mb-4">{
  "bank_code": "058",
  "account_number": "0123456789",
  "amount": 5000,
  "payee_name": "John Doe",
  "pin": "1234"
}</pre>
        <h4 class="font-bold text-slate-700 mb-2">Response</h4>
        <pre class="bg-slate-50 border border-slate-200 p-4 rounded-xl text-sm overflow-x-auto text-slate-800">{
  "status": "success",
  "message": "Withdrawal of ₦5000 submitted for approval.",
  "data": {
    "reference": "MSJ...",
    "pending": true
  }
}</pre>
    </div>

    <!-- Internal Transfer -->
    <div class="bg-white rounded-2xl border border-slate-200 p-6 card-shadow">
        <div class="flex items-center gap-3 mb-4">
            <span class="bg-green-100 text-green-700 font-bold px-3 py-1 rounded text-sm">POST</span>
            <h3 class="text-lg font-bold text-slate-800">/api/v1/internal_transfer.php</h3>
        </div>
        <p class="text-slate-600 mb-4">Send money to another Gracehubtels user by username, email, or phone number.</p>
        <h4 class="font-bold text-slate-700 mb-2">Request Body (JSON)</h4>
        <pre class="bg-slate-50 border border-slate-200 p-4 rounded-xl text-sm overflow-x-auto text-slate-800 mb-4">{
  "recipient": "username_or_phone_or_email",
  "amount": 1000,
  "pin": "1234"
}</pre>
        <h4 class="font-bold text-slate-700 mb-2">Response</h4>
        <pre class="bg-slate-50 border border-slate-200 p-4 rounded-xl text-sm overflow-x-auto text-slate-800">{
  "status": "success",
  "message": "Successfully transferred ₦1000.00 to John Doe"
}</pre>
    </div>

    <!-- Virtual Accounts -->
    <div class="bg-white rounded-2xl border border-slate-200 p-6 card-shadow">
        <div class="flex items-center gap-3 mb-4">
            <span class="bg-blue-100 text-blue-700 font-bold px-3 py-1 rounded text-sm">GET</span>
            <span class="bg-green-100 text-green-700 font-bold px-3 py-1 rounded text-sm">POST</span>
            <h3 class="text-lg font-bold text-slate-800">/api/v1/virtual_accounts.php</h3>
        </div>
        <p class="text-slate-600 mb-4"><strong>GET</strong> — List your virtual accounts. <strong>POST</strong> — Create/refresh virtual accounts.</p>
        <h4 class="font-bold text-slate-700 mb-2">Response (GET)</h4>
        <pre class="bg-slate-50 border border-slate-200 p-4 rounded-xl text-sm overflow-x-auto text-slate-800">{
  "status": "success",
  "data": {
    "virtual_accounts": [
      {
        "id": 1,
        "bank_name": "PalmPay",
        "account_number": "1234567890",
        "account_name": "John Doe"
      }
    ]
  }
}</pre>
        <h4 class="font-bold text-slate-700 mb-2">Response (POST)</h4>
        <pre class="bg-slate-50 border border-slate-200 p-4 rounded-xl text-sm overflow-x-auto text-slate-800">{
  "status": "success",
  "message": "Virtual accounts created/refreshed",
  "data": {
    "virtual_accounts": [...]
  }
}</pre>
    </div>

    <!-- Virtual Cards -->
    <div class="bg-white rounded-2xl border border-slate-200 p-6 card-shadow">
        <div class="flex items-center gap-3 mb-4">
            <span class="bg-blue-100 text-blue-700 font-bold px-3 py-1 rounded text-sm">GET</span>
            <span class="bg-green-100 text-green-700 font-bold px-3 py-1 rounded text-sm">POST</span>
            <h3 class="text-lg font-bold text-slate-800">/api/v1/virtual_cards.php</h3>
        </div>
        <p class="text-slate-600 mb-4"><strong>GET</strong> — List your virtual cards. <strong>POST</strong> — Create, fund, freeze, unfreeze, or terminate cards.</p>
        <h4 class="font-bold text-slate-700 mb-2">POST — Create Card</h4>
        <pre class="bg-slate-50 border border-slate-200 p-4 rounded-xl text-sm overflow-x-auto text-slate-800 mb-4">{
  "action": "create",
  "pin": "1234"
}</pre>
        <h4 class="font-bold text-slate-700 mb-2">POST — Fund Card</h4>
        <pre class="bg-slate-50 border border-slate-200 p-4 rounded-xl text-sm overflow-x-auto text-slate-800 mb-4">{
  "action": "fund",
  "card_id": "cw_card_id",
  "amount": 10,
  "pin": "1234"
}</pre>
        <h4 class="font-bold text-slate-700 mb-2">POST — Freeze/Unfreeze/Terminate Card</h4>
        <pre class="bg-slate-50 border border-slate-200 p-4 rounded-xl text-sm overflow-x-auto text-slate-800 mb-4">{
  "action": "freeze",
  "card_id": "cw_card_id",
  "pin": "1234"
}</pre>
    </div>

    <!-- Beneficiaries -->
    <div class="bg-white rounded-2xl border border-slate-200 p-6 card-shadow">
        <div class="flex items-center gap-3 mb-4">
            <span class="bg-blue-100 text-blue-700 font-bold px-3 py-1 rounded text-sm">GET</span>
            <span class="bg-green-100 text-green-700 font-bold px-3 py-1 rounded text-sm">POST</span>
            <h3 class="text-lg font-bold text-slate-800">/api/v1/beneficiaries.php</h3>
        </div>
        <p class="text-slate-600 mb-4"><strong>GET</strong> — List your saved beneficiaries. <strong>POST</strong> — Add, delete, or toggle favourite.</p>
        <h4 class="font-bold text-slate-700 mb-2">GET — List</h4>
        <pre class="bg-slate-50 border border-slate-200 p-4 rounded-xl text-sm overflow-x-auto text-slate-800 mb-4">?service=airtime</pre>
        <h4 class="font-bold text-slate-700 mb-2">POST — Add Beneficiary</h4>
        <pre class="bg-slate-50 border border-slate-200 p-4 rounded-xl text-sm overflow-x-auto text-slate-800 mb-4">{
  "action": "create",
  "service": "airtime",
  "label": "My MTN",
  "value": "08012345678",
  "network": "MTN"
}</pre>
        <h4 class="font-bold text-slate-700 mb-2">POST — Delete</h4>
        <pre class="bg-slate-50 border border-slate-200 p-4 rounded-xl text-sm overflow-x-auto text-slate-800 mb-4">{
  "action": "delete",
  "id": 1
}</pre>
    </div>

</div>

<?php include __DIR__ . '/includes/footer.php'; ?>
