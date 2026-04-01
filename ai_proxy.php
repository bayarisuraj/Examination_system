<?php
session_start();

/**
 * 1. FIX: Verify the secrets file exists before requiring it
 */
$secretsPath = "../../config/secrets.php";

if (!file_exists($secretsPath)) {
    header('Content-Type: application/json');
    echo json_encode(['error' => "Configuration file missing at: " . $secretsPath]);
    exit;
}

require_once $secretsPath;

/**
 * 2. FIX: Verify the API key is actually set and not the placeholder
 */
if (!defined('ANTHROPIC_API_KEY') || empty(ANTHROPIC_API_KEY) || ANTHROPIC_API_KEY === 'your_anthropic_api_key_here') {
    header('Content-Type: application/json');
    echo json_encode(['error' => "Anthropic API key is missing in config/secrets.php. Please check your key starts with 'sk-ant-'."]);
    exit;
}

// Clean output buffer
if (ob_get_length()) ob_clean();
header('Content-Type: application/json');

// Security Check
if (!isset($_SESSION['role']) || $_SESSION['role'] !== 'lecturer') {
    echo json_encode(['error' => 'Not authenticated as lecturer']);
    exit;
}

$data = json_decode(file_get_contents('php://input'), true);
$prompt = trim($data['prompt'] ?? '');

if (!$prompt) {
    echo json_encode(['error' => 'Empty prompt received']);
    exit;
}

/**
 * 3. FIX: Ensure model name is correct 
 * Use 'claude-3-5-sonnet-20241022' for the latest stable version
 */
$payload = json_encode([
    'model'      => 'claude-3-5-sonnet-20241022', 
    'max_tokens' => 2000,
    'messages'   => [['role' => 'user', 'content' => $prompt]]
]);

$ch = curl_init('https://api.anthropic.com/v1/messages');
curl_setopt_array($ch, [
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_POST           => true,
    CURLOPT_POSTFIELDS     => $payload,
    CURLOPT_HTTPHEADER     => [
        'Content-Type: application/json',
        'x-api-key: ' . ANTHROPIC_API_KEY, // Using the verified constant
        'anthropic-version: 2023-06-01'
    ],
    CURLOPT_TIMEOUT        => 60, // Increased timeout for longer generations
    CURLOPT_SSL_VERIFYPEER => true,
]);

$response = curl_exec($ch);
$curlErr  = curl_error($ch);
$httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
curl_close($ch);

if ($curlErr) {
    echo json_encode(['error' => 'Network error: ' . $curlErr]);
    exit;
}

// Handle API Errors (like 401 Unauthorized or 400 Bad Request)
if ($httpCode !== 200) {
    $errData = json_decode($response, true);
    $errMsg = $errData['error']['message'] ?? 'API Error code: ' . $httpCode;
    echo json_encode(['error' => 'Anthropic API Error: ' . $errMsg]);
    exit;
}

$result = json_decode($response, true);
$text = $result['content'][0]['text'] ?? '';

// Regex to strip markdown and conversational filler
$cleanJson = preg_replace('/^```json\s*|\s*```$/m', '', trim($text));

// Verify if the result is valid JSON before echoing
if (!json_decode($cleanJson)) {
    echo json_encode(['error' => 'The AI returned invalid JSON. Please try again.']);
} else {
    echo $cleanJson;
}