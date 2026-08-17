<?php
header('Content-Type: application/json');
require_once __DIR__ . '/kv.php';

$kvConfigured = !empty(get_kv_config()['url']) && !empty(get_kv_config()['token']);
$configFile = __DIR__ . '/../config.json';

if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    $localData = file_exists($configFile) ? json_decode(file_get_contents($configFile), true) : [];
    
    $gemini = $kvConfigured ? kv_get('gemini_api_key') : (getenv('GEMINI_API_KEY') ?: (isset($localData['gemini_api_key']) ? $localData['gemini_api_key'] : ""));
    $bridge = $kvConfigured ? kv_get('bridge_api_key') : (getenv('BRIDGE_API_KEY') ?: (isset($localData['bridge_api_key']) ? $localData['bridge_api_key'] : ""));
    
    echo json_encode([
        "gemini_api_key" => $gemini ?: "",
        "bridge_api_key" => $bridge ?: ""
    ]);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $input = json_decode(file_get_contents('php://input'), true);
    $localData = file_exists($configFile) ? json_decode(file_get_contents($configFile), true) : [];
    
    $gemini = $kvConfigured ? kv_get('gemini_api_key') : (isset($localData['gemini_api_key']) ? $localData['gemini_api_key'] : "");
    $bridge = $kvConfigured ? kv_get('bridge_api_key') : (isset($localData['bridge_api_key']) ? $localData['bridge_api_key'] : "");

    if (isset($input['gemini_api_key'])) {
        $gemini = $input['gemini_api_key'];
        if ($kvConfigured) {
            kv_set('gemini_api_key', $gemini);
        } else {
            $localData['gemini_api_key'] = $gemini;
        }
    }
    
    if (isset($input['generate_bridge_key']) && $input['generate_bridge_key'] === true) {
        if (empty($bridge)) {
            $bridge = 'pod_' . bin2hex(random_bytes(16));
            if ($kvConfigured) {
                kv_set('bridge_api_key', $bridge);
            } else {
                $localData['bridge_api_key'] = $bridge;
            }
        }
    }

    if (!$kvConfigured && is_writable(dirname($configFile))) {
        @file_put_contents($configFile, json_encode($localData, JSON_PRETTY_PRINT));
    }
    
    echo json_encode([
        "gemini_api_key" => $gemini,
        "bridge_api_key" => $bridge
    ]);
    exit;
}
