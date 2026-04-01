<?php
/**
 * SerpApi Google AI Mode Proxy
 * Location: lecturer/ajax/serpapi_search.php
 * Follows OES ajax pattern alongside ai_generate.php and save_questions.php
 */

header('Content-Type: application/json');

// ── Guard: AJAX only ──────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'error' => 'Method not allowed.']);
    exit;
}

// ── Session / auth check (same pattern as ai_generate.php) ──
session_start();
if (!isset($_SESSION['lecturer_id'])) {
    http_response_code(401);
    echo json_encode(['success' => false, 'error' => 'Unauthorized. Please log in.']);
    exit;
}

// ── Load secrets (your existing file) ────────────────────
require_once '../../config/secrets.php';
// secrets.php must define: define('SERPAPI_KEY', 'your_key_here');

if (!defined('SERPAPI_KEY') || empty(SERPAPI_KEY)) {
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => 'SerpApi key not configured.']);
    exit;
}

// ── Parse input ───────────────────────────────────────────
$input = json_decode(file_get_contents('php://input'), true);
$query = isset($input['query']) ? trim($input['query']) : '';

if (empty($query)) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'Query cannot be empty.']);
    exit;
}

if (strlen($query) > 500) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'Query too long. Max 500 characters.']);
    exit;
}

// ── SerpApi request ───────────────────────────────────────
$params = http_build_query([
    'engine'  => 'google_ai_mode',
    'q'       => $query,
    'hl'      => 'en',
    'gl'      => 'us',
    'api_key' => SERPAPI_KEY,
]);

$url = 'https://serpapi.com/search.json?' . $params;

$ch = curl_init();
curl_setopt_array($ch, [
    CURLOPT_URL            => $url,
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_TIMEOUT        => 20,
    CURLOPT_SSL_VERIFYPEER => true,
    CURLOPT_HTTPHEADER     => ['Accept: application/json'],
]);

$response  = curl_exec($ch);
$httpCode  = curl_getinfo($ch, CURLINFO_HTTP_CODE);
$curlError = curl_error($ch);
curl_close($ch);

if ($curlError) {
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => 'Network error: ' . $curlError]);
    exit;
}

if ($httpCode !== 200) {
    $decoded = json_decode($response, true);
    $message = isset($decoded['error']) ? $decoded['error'] : 'SerpApi returned HTTP ' . $httpCode;
    http_response_code(502);
    echo json_encode(['success' => false, 'error' => $message]);
    exit;
}

$data = json_decode($response, true);

if (json_last_error() !== JSON_ERROR_NONE) {
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => 'Failed to parse API response.']);
    exit;
}

// ── Extract AI answer ─────────────────────────────────────
$aiAnswer = '';

if (isset($data['ai_overview']['text_blocks'])) {
    foreach ($data['ai_overview']['text_blocks'] as $block) {
        if (isset($block['snippet'])) {
            $aiAnswer .= $block['snippet'] . ' ';
        }
    }
}

// Fallbacks
if (empty(trim($aiAnswer))) {
    if (isset($data['answer_box']['answer']))          $aiAnswer = $data['answer_box']['answer'];
    elseif (isset($data['answer_box']['snippet']))     $aiAnswer = $data['answer_box']['snippet'];
    elseif (isset($data['knowledge_graph']['description'])) $aiAnswer = $data['knowledge_graph']['description'];
}

// ── Extract follow-up questions ───────────────────────────
$followUps = [];
if (isset($data['ask_ai_mode']) && is_array($data['ask_ai_mode'])) {
    foreach ($data['ask_ai_mode'] as $item) {
        $followUps[] = [
            'position' => $item['position'] ?? null,
            'question' => $item['question'] ?? '',
            'image'    => $item['image']    ?? '',
            'link'     => $item['link']     ?? '',
        ];
    }
}

// ── Return structured response ────────────────────────────
echo json_encode([
    'success'   => true,
    'query'     => $query,
    'answer'    => trim($aiAnswer),
    'followups' => $followUps,
]);