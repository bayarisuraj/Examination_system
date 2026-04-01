<?php
session_start();
require_once "../../config/secrets.php";

header('Content-Type: application/json');

if (!isset($_SESSION['role']) || $_SESSION['role'] !== 'lecturer') {
    echo json_encode(['error' => 'Not authenticated']);
    exit;
}

$raw = file_get_contents('php://input');
$data = json_decode($raw, true);
$prompt = trim((string)($data['prompt'] ?? ''));

if ($prompt === '') {
    echo json_encode(['error' => 'Prompt is required']);
    exit;
}

// Support both constant names to avoid breaking older files.
$apiKey = '';
if (defined('ANTHROPIC_API_KEY') && constant('ANTHROPIC_API_KEY')) {
    $apiKey = (string)constant('ANTHROPIC_API_KEY');
} elseif (defined('CLAUDE_API_KEY') && constant('CLAUDE_API_KEY')) {
    $apiKey = (string)constant('CLAUDE_API_KEY');
}

if ($apiKey === '' || $apiKey === 'your_anthropic_api_key_here') {
    echo json_encode(['error' => 'Anthropic API key is missing in config/secrets.php']);
    exit;
}

$payload = json_encode([
    'model' => 'claude-3-5-sonnet-latest',
    'max_tokens' => 1800,
    'messages' => [
        ['role' => 'user', 'content' => $prompt]
    ],
], JSON_UNESCAPED_SLASHES);

$ch = curl_init('https://api.anthropic.com/v1/messages');
curl_setopt_array($ch, [
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_POST => true,
    CURLOPT_POSTFIELDS => $payload,
    CURLOPT_HTTPHEADER => [
        'Content-Type: application/json',
        'x-api-key: ' . $apiKey,
        'anthropic-version: 2023-06-01',
    ],
    CURLOPT_TIMEOUT => 45,
    CURLOPT_SSL_VERIFYPEER => true,
]);

$response = curl_exec($ch);
$curlErr = curl_error($ch);
$httpCode = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
curl_close($ch);

if ($curlErr) {
    echo json_encode(['error' => 'Network error: ' . $curlErr]);
    exit;
}

$result = json_decode((string)$response, true);
if (!is_array($result)) {
    echo json_encode(['error' => 'Invalid AI response (HTTP ' . $httpCode . ')']);
    exit;
}

if (isset($result['error'])) {
    $msg = is_array($result['error']) ? ($result['error']['message'] ?? 'Unknown API error') : (string)$result['error'];
    echo json_encode(['error' => $msg]);
    exit;
}

$text = '';
if (isset($result['content'][0]['text'])) {
    $text = (string)$result['content'][0]['text'];
}

echo json_encode(['text' => $text]);
exit;

