<?php
/**
 * UCP Proxy for AI Agents
 *
 * This file can be hosted on a server WITHOUT strict ModSecurity
 * to proxy requests to the actual WooCommerce store.
 *
 * DEPLOYMENT OPTIONS:
 * 1. Host on a different server/subdomain without ModSecurity
 * 2. Use a Cloudflare Worker (see cloudflare-worker.js below)
 * 3. Use AWS Lambda or similar serverless function
 *
 * Configure the TARGET_STORE below to point to the actual store.
 */

// CONFIGURATION - Change this to the actual store URL
define('TARGET_STORE', 'https://ecom-sandbox.net');

// CORS headers for AI agents
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, PUT, DELETE, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Accept, Origin, X-Requested-With');
header('Content-Type: application/json; charset=UTF-8');

// Handle preflight
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}

// Get the endpoint from query params
$endpoint = isset($_GET['ucp_endpoint']) ? $_GET['ucp_endpoint'] : '';

if (empty($endpoint)) {
    http_response_code(400);
    echo json_encode(['error' => true, 'message' => 'Missing ucp_endpoint parameter']);
    exit;
}

// Build the target URL
$target_url = TARGET_STORE . '/?avvance_api=1&ucp_endpoint=' . urlencode($endpoint);

// Add other query params (except our routing params)
foreach ($_GET as $key => $value) {
    if (!in_array($key, ['ucp_endpoint'])) {
        $target_url .= '&' . urlencode($key) . '=' . urlencode($value);
    }
}

// Get request body for POST/PUT
$body = file_get_contents('php://input');

// Browser-like headers that bypass ModSecurity
$headers = [
    'User-Agent: Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0.0.0 Safari/537.36',
    'Accept: application/json, text/plain, */*',
    'Accept-Language: en-US,en;q=0.9',
    'Accept-Encoding: gzip, deflate, br',
    'Connection: keep-alive',
    'Sec-Ch-Ua: "Not_A Brand";v="8", "Chromium";v="120", "Google Chrome";v="120"',
    'Sec-Ch-Ua-Mobile: ?0',
    'Sec-Ch-Ua-Platform: "Windows"',
    'Sec-Fetch-Dest: empty',
    'Sec-Fetch-Mode: cors',
    'Sec-Fetch-Site: cross-site',
    'Origin: https://chat.openai.com',
    'Referer: https://chat.openai.com/'
];

if (!empty($body)) {
    $headers[] = 'Content-Type: application/json';
    $headers[] = 'Content-Length: ' . strlen($body);
}

// Make the request with cURL
$ch = curl_init();
curl_setopt_array($ch, [
    CURLOPT_URL => $target_url,
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_FOLLOWLOCATION => true,
    CURLOPT_HTTPHEADER => $headers,
    CURLOPT_TIMEOUT => 30,
    CURLOPT_SSL_VERIFYPEER => true,
]);

// Set method and body
switch ($_SERVER['REQUEST_METHOD']) {
    case 'POST':
        curl_setopt($ch, CURLOPT_POST, true);
        if (!empty($body)) {
            curl_setopt($ch, CURLOPT_POSTFIELDS, $body);
        }
        break;
    case 'PUT':
        curl_setopt($ch, CURLOPT_CUSTOMREQUEST, 'PUT');
        if (!empty($body)) {
            curl_setopt($ch, CURLOPT_POSTFIELDS, $body);
        }
        break;
    case 'DELETE':
        curl_setopt($ch, CURLOPT_CUSTOMREQUEST, 'DELETE');
        break;
}

$response = curl_exec($ch);
$http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
$error = curl_error($ch);
curl_close($ch);

if ($error) {
    http_response_code(502);
    echo json_encode(['error' => true, 'message' => 'Proxy error: ' . $error]);
    exit;
}

http_response_code($http_code);
echo $response;
