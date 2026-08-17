<?php

function get_kv_config() {
    return [
        'url' => getenv('KV_REST_API_URL') ?: getenv('UPSTASH_REDIS_REST_URL'),
        'token' => getenv('KV_REST_API_TOKEN') ?: getenv('UPSTASH_REDIS_REST_TOKEN')
    ];
}

function kv_request($payload) {
    $config = get_kv_config();
    if (!$config['url'] || !$config['token']) {
        return null;
    }

    $ch = curl_init($config['url']);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($payload));
    curl_setopt($ch, CURLOPT_HTTPHEADER, [
        'Authorization: Bearer ' . $config['token'],
        'Content-Type: application/json'
    ]);
    
    // Disable SSL verify for local dev fallback
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);

    $response = curl_exec($ch);
    curl_close($ch);

    return $response ? json_decode($response, true) : null;
}

function kv_get($key) {
    $res = kv_request(["GET", $key]);
    // KV returns {"result": "value"} or {"result": null}
    return ($res && isset($res['result'])) ? $res['result'] : null;
}

function kv_set($key, $value) {
    kv_request(["SET", $key, $value]);
}

function kv_log($log_data) {
    // We store logs as JSON strings in a Redis List
    kv_request(["RPUSH", "pod_api_logs", json_encode($log_data)]);
}
