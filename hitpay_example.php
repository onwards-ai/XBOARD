<?php
// -----------------------------------------------------------------------------
// CONFIGURATION
// -----------------------------------------------------------------------------
define('API_KEY', 'live_ca3fabae32a2ff8985465598c0786d7398e087a94db8a14596a7682b650459e9');
define('WEBHOOK_SALT', '0JDhUNNOCO2XJAR6aF1eJjFldRDUxFYhqHn9iM3glh9nWgVFjup6IWrF6XxMdQ7n');
define('BASE_URL', 'https://payment.friendship4u.com/hitpay.php');

// -----------------------------------------------------------------------------
// ROUTING
// -----------------------------------------------------------------------------
$method = $_SERVER['REQUEST_METHOD'];
if ($method === 'POST' && isset($_GET['webhook'])) {
    // HitPay will POST here after payment completes
    handleWebhook();
    exit;
}

if (isset($_GET['status'])) {
    // Customer is redirected back after they complete (or cancel) the checkout
    handleRedirectResponse();
    exit;
}

if ($method === 'POST') {
    // Our form submission: create a new payment request
    $amount   = $_POST['amount']   ?? '0';
    $currency = $_POST['currency'] ?? 'SGD';
    $methods = $_POST['methods'] ?? [];
    if (empty($methods)) {
        die('❌ Please select at least one payment method.');
    }
    $pr       = createPaymentRequest($amount, $currency, $methods);
    if (!empty($pr['url'])) {
        header('Location: ' . $pr['url']);
        exit;
    }
    die('❌ Error creating payment request: ' . htmlspecialchars(json_encode($pr)));
}

// Default: show a simple form to trigger a payment
echo <<<HTML
<!doctype html>
<html>
<head><title>HitPay Test</title></head>
<body>
  <h1>Make a Payment</h1>
  <form method="post">
    <label>Amount:</label>
    <input name="amount" value="10.00" /><br/>
    <label>Currency:</label>
    <select name="currency">
      <option value="SGD">SGD</option>
    </select><br/>
    <label>Payment Methods:</label><br/>
    <input type="checkbox" name="methods[]" value="wechat_pay" checked> WeChat Pay<br/>
    <br/>
    <button type="submit">Pay Now</button>
  </form>
</body>
</html>
HTML;


// -----------------------------------------------------------------------------
// STEP 1: Create Payment Request
// -----------------------------------------------------------------------------
function createPaymentRequest($amount, $currency, array $methods): array
{
    $url = 'https://api.hit-pay.com/v1/payment-requests';  // use sandbox URL if testing
    // build form-encoded body with multiple payment_methods[]
    $post = "amount={$amount}&currency={$currency}";
    foreach ($methods as $m) {
        $post .= "&payment_methods[]={$m}";
    }
    // where HitPay will redirect the customer:
    $post .= '&redirect_url=' . urlencode(BASE_URL . '?status=completed');
    // where HitPay will POST webhook after payment:
    $post .= '&webhook='      . urlencode(BASE_URL . '?webhook=1');

    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => $post,
        CURLOPT_HTTPHEADER     => [
            'X-BUSINESS-API-KEY: '   . API_KEY,
            'Content-Type: application/x-www-form-urlencoded',
            'X-Requested-With: XMLHttpRequest'
        ],
    ]);
    $resp = curl_exec($ch);
    curl_close($ch);
    return json_decode($resp, true);
}
//  [oai_citation:0‡docs.hitpayapp.com](https://docs.hitpayapp.com/apis/guide/online-payments)


// -----------------------------------------------------------------------------
// STEP 3: Handle Webhook (validate HMAC + mark order paid)
// -----------------------------------------------------------------------------
function handleWebhook()
{
    // parse form-encoded POST data
    parse_str(file_get_contents('php://input'), $payload);

    $receivedHmac = $payload['hmac'] ?? '';
    unset($payload['hmac']);

    $calculated = generateSignature(WEBHOOK_SALT, $payload);
    if (hash_equals($calculated, $receivedHmac)) {
        http_response_code(200);
        // TODO: lookup your order by $payload['reference_number'] or payment_request_id
        //       then mark it as PAID in your database
        error_log("✅ Valid webhook: " . json_encode($payload));
    } else {
        http_response_code(400);
        error_log("❌ Invalid webhook signature");
    }
}
//  [oai_citation:1‡docs.hitpayapp.com](https://docs.hitpayapp.com/apis/guide/online-payments)

// HMAC-SHA256 signature generator, per HitPay docs
function generateSignature(string $secret, array $data): string
{
    ksort($data);
    $sig = '';
    foreach ($data as $key => $val) {
        $sig .= "{$key}{$val}";
    }
    return hash_hmac('sha256', $sig, $secret);
}
//  [oai_citation:2‡docs.hitpayapp.com](https://docs.hitpayapp.com/apis/guide/online-payments)


// -----------------------------------------------------------------------------
// Handle the redirect back from HitPay (not secure; real check is via webhook)
// -----------------------------------------------------------------------------
function handleRedirectResponse()
{
    $status = $_GET['status'];
    $prid   = $_GET['payment_request_id'] ?? '';
    if ($status === 'completed') {
        echo "<h1>Thank you! Payment completed.</h1>";
        echo "<p>Payment Request ID: " . htmlspecialchars($prid) . "</p>";
    } else {
        echo "<h1>Payment {$status}</h1>";
        echo "<p>Please try again or contact support.</p>";
    }
}
