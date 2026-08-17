<?php
header('Content-Type: application/json');
require_once __DIR__ . '/kv.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(["error" => "Method not allowed"]);
    exit;
}

// 1. Verify Bridge API Key
set_time_limit(120); // Allow up to 2 minutes for proxy model fallbacks
$kvConfigured = !empty(get_kv_config()['url']) && !empty(get_kv_config()['token']);
$configFile = __DIR__ . '/../config.json';
$config = file_exists($configFile) ? json_decode(file_get_contents($configFile), true) : [];

$bridgeKey = $kvConfigured ? kv_get('bridge_api_key') : (getenv('BRIDGE_API_KEY') ?: (isset($config['bridge_api_key']) ? $config['bridge_api_key'] : ''));
$geminiKey = $kvConfigured ? kv_get('gemini_api_key') : (getenv('GEMINI_API_KEY') ?: (isset($config['gemini_api_key']) ? $config['gemini_api_key'] : ''));

$headers = array_change_key_case(getallheaders(), CASE_UPPER);
$authHeader = isset($headers['AUTHORIZATION']) ? $headers['AUTHORIZATION'] : (isset($_SERVER['HTTP_AUTHORIZATION']) ? $_SERVER['HTTP_AUTHORIZATION'] : '');

if ($bridgeKey) {
    if ($authHeader !== 'Bearer ' . $bridgeKey) {
        http_response_code(401);
        echo json_encode([
            "error" => "Unauthorized. Invalid Bridge API Key.",
            "debug_authHeader" => $authHeader,
            "debug_bridgeKey" => $bridgeKey,
            "debug_SERVER_keys" => array_keys($_SERVER)
        ]);
        exit;
    }
} else {
    http_response_code(401);
    echo json_encode(["error" => "Server misconfiguration: Bridge API Key is not set up."]);
    exit;
}

// 2. Read Payload
$input = file_get_contents('php://input');
$data = json_decode($input, true);
$imageUrl = isset($data['image_url']) ? $data['image_url'] : null;
$imageBase64 = isset($data['image_base64']) ? $data['image_base64'] : null;

if (!$imageUrl && !$imageBase64) {
    http_response_code(400);
    echo json_encode(["error" => "Missing image_url or image_base64 parameter in JSON body"]);
    exit;
}

// 3. Download or parse Image
if ($imageUrl) {
    $imageBytes = @file_get_contents($imageUrl);
    if (!$imageBytes) {
        http_response_code(400);
        echo json_encode(["error" => "Failed to download image from provided URL"]);
        exit;
    }
    $base64Image = base64_encode($imageBytes);
} else {
    // Strip data URI scheme if present
    if (preg_match('/^data:image\/(\w+);base64,/', $imageBase64)) {
        $imageBase64 = substr($imageBase64, strpos($imageBase64, ',') + 1);
    }
    $base64Image = $imageBase64;
    $imageBytes = base64_decode($base64Image);
}

// Determine MIME type (roughly)
$mimeType = 'image/jpeg'; // Default fallback
if (class_exists('finfo')) {
    $finfo = new finfo(FILEINFO_MIME_TYPE);
    $detected = @$finfo->buffer($imageBytes);
    if ($detected && strpos($detected, 'image/') === 0) {
        $mimeType = $detected;
    }
} elseif (function_exists('getimagesizefromstring')) {
    $size = @getimagesizefromstring($imageBytes);
    if ($size && isset($size['mime'])) {
        $mimeType = $size['mime'];
    }
}

// 4. Send to Gemini LLM
if (!$geminiKey) {
    http_response_code(500);
    echo json_encode(["error" => "Server misconfiguration: GEMINI_API_KEY is not set."]);
    exit;
}

$prompt = <<<EOT
You are an automated Proof of Delivery (POD) document auditor. Your sole job is to classify the provided POD image into one of three strict categories based on the presence of a receiver's ink stamp and handwritten remarks.

CRITERIA:
1. "NOT OK POD": The document has NO visible ink stamp anywhere on the page.
2. "OK POD": The document HAS a visible ink stamp AND there are NO legible handwritten remarks in the "RECEIVER'S REMARKS" section (ignore signatures or random scribbles that do not form readable words).
3. "HOLD POD": The document HAS a visible ink stamp AND there ARE legible handwritten remarks in the "RECEIVER'S REMARKS" section.

CONSTRAINTS & DEFINITIONS:
- INK STAMPS: Look very closely for rubber ink stamps. They are usually blue, purple, or red ink, often rectangular or circular, and contain text like a company name (e.g., "For [Company Name]", "Proprietor"). They are usually found in or near the "Sign & Stamp" box and may overlap with signatures. Even if faint, it counts as a stamp.
- REMARKS: Ignore pre-printed text, printed numbers, and signatures. We only care about handwritten notes specifically in the "RECEIVER'S REMARKS" box.
- If you classify it as a "HOLD POD", you MUST include the exact transcribed remark in parentheses. Example: HOLD POD (Box damaged)
- If you classify it as a "NOT OK POD", you MUST include the reason in parentheses. Example: NOT OK POD (No ink stamp present)
- If the handwriting in the remarks box is completely illegible or just a signature, treat it as having NO remarks (Output: OK POD).
- DO NOT provide any explanation, reasoning, or markdown formatting. 
- You must output EXACTLY ONE LINE of text.

Your output must be exactly one of the following formats:
OK POD
NOT OK POD (reason text here)
HOLD POD (transcribed remark text here)
ERROR: [Brief reason if the image is completely unreadable or not a document]
EOT;

$payload = [
    "contents" => [
        [
            "parts" => [
                [
                    "text" => $prompt
                ],
                [
                    "inline_data" => [
                        "mime_type" => $mimeType,
                        "data" => $base64Image
                    ]
                ]
            ]
        ]
    ]
];

$models = ["gemini-3.5-flash-lite", "gemini-2.5-flash", "gemini-1.5-flash"];
$response = false;
$httpCode = 0;
$curlError = '';
$usedModel = '';

foreach ($models as $model) {
    $url = "https://generativelanguage.googleapis.com/v1beta/models/{$model}:generateContent?key=" . $geminiKey;

    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false); // Fix for local Windows PHP
    curl_setopt($ch, CURLOPT_TIMEOUT, 15); // Don't hang for more than 15s per model
    curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json']);
    curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($payload));

    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curlError = curl_error($ch);
    curl_close($ch);

    if ($response !== false && $httpCode >= 200 && $httpCode < 400) {
        $usedModel = $model;
        break; // Success! Stop trying other models.
    }
}

if ($response === false) {
    http_response_code(500);
    echo json_encode(["error" => "cURL failed on all proxy models", "last_error" => $curlError]);
    exit;
}

if ($httpCode >= 400) {
    http_response_code(500);
    echo json_encode(["error" => "Gemini API error (All proxy models failed. Last HTTP $httpCode)", "details" => json_decode($response)]);
    exit;
}

$resultData = json_decode($response, true);
$statusText = "UNKNOWN";
$debugInfo = null;

if ($resultData === null) {
    $debugInfo = ["raw_response" => $response, "json_error" => json_last_error_msg()];
} elseif (isset($resultData['candidates'][0]['content']['parts'][0]['text'])) {
    $statusText = trim($resultData['candidates'][0]['content']['parts'][0]['text']);
} else {
    $debugInfo = $resultData; // capturing what gemini actually returned
}

$output = [
    "status" => $statusText,
    "image_url" => $imageUrl
];

if ($debugInfo !== null) {
    $output["debug_gemini_response"] = $debugInfo;
}

if ($kvConfigured) {
    $logEntry = [
        "timestamp" => date('c'),
        "image_url" => $imageUrl ? $imageUrl : "base64_upload",
        "status" => $statusText
    ];
    kv_log($logEntry);
}

echo json_encode($output);
