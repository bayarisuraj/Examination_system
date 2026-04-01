<?php
session_start();
require_once "../../config/db.php";
require_once "../../config/secrets.php";

// Force JSON output — catch any stray output before this
ob_clean();
header('Content-Type: application/json');

if (!isset($_SESSION['role']) || $_SESSION['role'] !== 'lecturer') {
    echo json_encode(['error' => 'Not authenticated — role: ' . ($_SESSION['role'] ?? 'none')]);
    exit;
}

$data   = json_decode(file_get_contents('php://input'), true);
$prompt = trim($data['prompt'] ?? '');

if (!$prompt) {
    echo json_encode(['error' => 'Empty prompt received']);
    exit;
}

if (!defined('CLAUDE_API_KEY') || !CLAUDE_API_KEY) {
    echo json_encode(['error' => 'API key not defined in secrets.php']);
    exit;
}

$payload = json_encode([
    'model'      => 'claude-sonnet-4-20250514',
    'max_tokens' => 1000,
    'messages'   => [['role' => 'user', 'content' => $prompt]]
]);

$ch = curl_init('https://api.anthropic.com/v1/messages');
curl_setopt_array($ch, [
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_POST           => true,
    CURLOPT_POSTFIELDS     => $payload,
    CURLOPT_HTTPHEADER     => [
        'Content-Type: application/json',
        'x-api-key: ' . CLAUDE_API_KEY,
        'anthropic-version: 2023-06-01'
    ],
    CURLOPT_TIMEOUT        => 30,
    CURLOPT_SSL_VERIFYPEER => true,
]);

$response = curl_exec($ch);
$curl_err  = curl_error($ch);
$http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
curl_close($ch);

if ($curl_err) {
    echo json_encode(['error' => 'cURL error: ' . $curl_err]);
    exit;
}

$result = json_decode($response, true);

if (!$result) {
    echo json_encode(['error' => 'Bad response from Anthropic (HTTP ' . $http_code . '): ' . substr($response, 0, 200)]);
    exit;
}

if (isset($result['error'])) {
    echo json_encode(['error' => 'Anthropic error: ' . $result['error']['message']]);
    exit;
}

echo json_encode(['text' => $result['content'][0]['text'] ?? '']);

?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>AI Question Generator</title>
    <link rel="stylesheet" href="assets/css/style.css">
    <style>
        .gen-wrap {
            max-width: 820px;
            margin: 0 auto;
            padding: 1.5rem;
        }
        .page-header {
            margin-bottom: 1.5rem;
        }
        .page-header h1 {
            font-size: 1.4rem;
            font-weight: 600;
            color: #1a1a2e;
        }
        .page-header p {
            font-size: 0.875rem;
            color: #6b7280;
            margin-top: 4px;
        }
        .card {
            background: #fff;
            border: 1px solid #e5e7eb;
            border-radius: 12px;
            padding: 1.5rem;
            margin-bottom: 1.25rem;
        }
        .card h2 {
            font-size: 1rem;
            font-weight: 600;
            color: #1a1a2e;
            margin-bottom: 1.25rem;
        }
        .form-grid {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 1rem;
        }
        .form-group {
            display: flex;
            flex-direction: column;
            gap: 5px;
        }
        .form-group.full {
            grid-column: 1 / -1;
        }
        label {
            font-size: 0.8rem;
            font-weight: 500;
            color: #374151;
        }
        input, select, textarea {
            padding: 8px 12px;
            border: 1px solid #d1d5db;
            border-radius: 8px;
            font-size: 0.875rem;
            color: #1a1a2e;
            background: #f9fafb;
            font-family: inherit;
            transition: border-color 0.2s;
        }
        input:focus, select:focus, textarea:focus {
            outline: none;
            border-color: #4f46e5;
            background: #fff;
        }
        textarea { resize: vertical; min-height: 80px; }
        .btn-generate {
            width: 100%;
            margin-top: 0.75rem;
            padding: 11px;
            background: #4f46e5;
            color: #fff;
            font-size: 0.9rem;
            font-weight: 600;
            border: none;
            border-radius: 8px;
            cursor: pointer;
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 8px;
            transition: background 0.2s;
        }
        .btn-generate:hover { background: #4338ca; }
        .btn-generate:disabled { background: #9ca3af; cursor: not-allowed; }
        .spinner {
            width: 16px; height: 16px;
            border: 2px solid rgba(255,255,255,0.4);
            border-top-color: #fff;
            border-radius: 50%;
            animation: spin 0.7s linear infinite;
        }
        @keyframes spin { to { transform: rotate(360deg); } }
        .status-msg {
            text-align: center;
            font-size: 0.8rem;
            color: #6b7280;
            margin-top: 8px;
            min-height: 18px;
        }
        .status-msg.error { color: #dc2626; }
        .status-msg.success { color: #16a34a; }

        /* Result area */
        #resultSection { display: none; }
        .result-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            flex-wrap: wrap;
            gap: 8px;
            margin-bottom: 1.25rem;
        }
        .result-header h2 { margin-bottom: 0; }
        .action-btns { display: flex; gap: 8px; }
        .btn-sm {
            padding: 6px 14px;
            font-size: 0.8rem;
            border: 1px solid #d1d5db;
            border-radius: 6px;
            background: #fff;
            cursor: pointer;
            color: #374151;
            font-weight: 500;
            transition: background 0.15s;
        }
        .btn-sm:hover { background: #f3f4f6; }
        .btn-sm.primary {
            background: #4f46e5;
            color: #fff;
            border-color: #4f46e5;
        }
        .btn-sm.primary:hover { background: #4338ca; }
        .q-list { display: flex; flex-direction: column; gap: 12px; }
        .q-card {
            background: #f9fafb;
            border: 1px solid #e5e7eb;
            border-radius: 10px;
            padding: 1rem;
        }
        .q-meta {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 8px;
        }
        .q-num {
            font-size: 0.75rem;
            font-weight: 600;
            color: #9ca3af;
            text-transform: uppercase;
            letter-spacing: 0.05em;
        }
        .badge {
            font-size: 0.7rem;
            padding: 2px 9px;
            border-radius: 20px;
            font-weight: 500;
        }
        .badge.easy { background: #dcfce7; color: #166534; }
        .badge.medium { background: #fef9c3; color: #854d0e; }
        .badge.hard { background: #fee2e2; color: #991b1b; }
        .badge.truefalse { background: #e0e7ff; color: #3730a3; }
        .badge.short { background: #f3f4f6; color: #374151; }
        .q-text {
            font-size: 0.9rem;
            font-weight: 500;
            color: #1a1a2e;
            margin-bottom: 10px;
            line-height: 1.5;
        }
        .opts { display: flex; flex-direction: column; gap: 6px; }
        .opt {
            font-size: 0.82rem;
            padding: 6px 10px;
            border: 1px solid #e5e7eb;
            border-radius: 6px;
            background: #fff;
            color: #374151;
        }
        .opt.correct {
            border-color: #16a34a;
            background: #dcfce7;
            color: #14532d;
            font-weight: 500;
        }
        .answer-row {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-top: 10px;
        }
        .ans-badge {
            font-size: 0.78rem;
            padding: 3px 10px;
            border-radius: 20px;
            background: #dcfce7;
            color: #14532d;
            font-weight: 500;
        }
        .copy-q-btn {
            font-size: 0.75rem;
            padding: 3px 10px;
            border: 1px solid #d1d5db;
            border-radius: 20px;
            background: #fff;
            cursor: pointer;
            color: #6b7280;
        }
        .copy-q-btn:hover { background: #f3f4f6; }
        .save-section {
            margin-top: 1.25rem;
            padding-top: 1rem;
            border-top: 1px solid #e5e7eb;
            display: flex;
            align-items: center;
            gap: 12px;
            flex-wrap: wrap;
        }
        .save-section label { font-size: 0.85rem; white-space: nowrap; }
        .save-section select { flex: 1; min-width: 200px; }
        .btn-save {
            padding: 8px 18px;
            background: #16a34a;
            color: #fff;
            border: none;
            border-radius: 8px;
            font-size: 0.85rem;
            font-weight: 600;
            cursor: pointer;
            white-space: nowrap;
        }
        .btn-save:hover { background: #15803d; }

        @media (max-width: 600px) {
            .form-grid { grid-template-columns: 1fr; }
            .form-group.full { grid-column: 1; }
        }
    </style>
</head>
<body>
<?php include 'includes/sidebar.php'; ?>

<div class="main-content">
    <div class="gen-wrap">
        <div class="page-header">
            <h1>AI Question Generator</h1>
            <p>Generate exam questions instantly using AI — then save them to your question bank.</p>
        </div>

        <!-- Generator Form -->
        <div class="card">
            <h2>Generate questions</h2>
            <div class="form-grid">
                <div class="form-group full">
                    <label for="course">Course</label>
                    <select id="course">
                        <option value="">— select a course —</option>
                        <?php foreach ($courses as $c): ?>
                            <option value="<?= htmlspecialchars($c['course_name']) ?>">
                                <?= htmlspecialchars($c['course_code'] . ' — ' . $c['course_name']) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="form-group full">
                    <label for="topic">Topic / focus area</label>
                    <textarea id="topic" placeholder="e.g. SQL joins and normalization — focus on INNER JOIN, LEFT JOIN, and 3NF"></textarea>
                </div>
                <div class="form-group">
                    <label for="qtype">Question type</label>
                    <select id="qtype">
                        <option value="mcq">Multiple choice (MCQ)</option>
                        <option value="truefalse">True / False</option>
                        <option value="short">Short answer</option>
                        <option value="mixed">Mixed</option>
                    </select>
                </div>
                <div class="form-group">
                    <label for="qcount">Number of questions</label>
                    <select id="qcount">
                        <option value="5">5 questions</option>
                        <option value="10" selected>10 questions</option>
                        <option value="15">15 questions</option>
                        <option value="20">20 questions</option>
                    </select>
                </div>
                <div class="form-group">
                    <label for="diff">Difficulty</label>
                    <select id="diff">
                        <option value="easy">Easy</option>
                        <option value="medium" selected>Medium</option>
                        <option value="hard">Hard</option>
                        <option value="mixed">Mixed</option>
                    </select>
                </div>
                <div class="form-group">
                    <label for="lang">Language</label>
                    <select id="lang">
                        <option value="English">English</option>
                        <option value="French">French</option>
                    </select>
                </div>
            </div>
            <button class="btn-generate" id="genBtn" onclick="generateQuestions()">
                <svg width="16" height="16" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path d="M13 2L3 14h9l-1 8 10-12h-9l1-8z"/></svg>
                Generate questions
            </button>
            <div class="status-msg" id="statusMsg"></div>
        </div>

        <!-- Results -->
        <div class="card" id="resultSection">
            <div class="result-header">
                <h2 id="resultTitle">Generated questions</h2>
                <div class="action-btns">
                    <button class="btn-sm" onclick="copyAll()">Copy all</button>
                    <button class="btn-sm" onclick="downloadTxt()">Download .txt</button>
                    <button class="btn-sm primary" onclick="regenerate()">Regenerate</button>
                </div>
            </div>
            <div class="q-list" id="qList"></div>

            <!-- Save to question bank -->
            <div class="save-section">
                <label>Save to question bank for exam:</label>
                <select id="examSelect">
                    <option value="">— choose an exam —</option>
                    <?php
                    $stmt2 = $pdo->prepare("SELECT e.id, e.title, c.course_code FROM exams e JOIN courses c ON e.course_id = c.id WHERE c.lecturer_id = ?");
                    $stmt2->execute([$lecturer_id]);
                    $exams = $stmt2->fetchAll(PDO::FETCH_ASSOC);
                    foreach ($exams as $ex):
                    ?>
                        <option value="<?= $ex['id'] ?>">
                            <?= htmlspecialchars($ex['course_code'] . ' — ' . $ex['title']) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
                <button class="btn-save" onclick="saveToBank()">Save to question bank</button>
            </div>
        </div>
    </div>
</div>

<script>
let generatedQuestions = [];
let lastParams = {};

const typeMap = {
    mcq:       'multiple choice with 4 options (A, B, C, D) and one correct answer',
    truefalse: 'True/False with the correct answer stated',
    short:     'short answer with a model answer provided',
    mixed:     'a mix of multiple choice, true/false, and short answer'
};

/**
 * GENERATE QUESTIONS
 * Calls the PHP proxy to talk to Claude AI
 */
async function generateQuestions() {
    const course  = document.getElementById('course').value.trim();
    const topic   = document.getElementById('topic').value.trim();
    const qtype   = document.getElementById('qtype').value;
    const qcount  = document.getElementById('qcount').value;
    const diff    = document.getElementById('diff').value;
    const lang    = document.getElementById('lang').value;
    const btn     = document.getElementById('genBtn');
    const status  = document.getElementById('statusMsg');

    if (!course || !topic) {
        status.textContent = 'Please select a course and enter a topic.';
        status.className = 'status-msg error';
        return;
    }

    lastParams = { course, topic, qtype, qcount, diff, lang };

    btn.disabled = true;
    btn.innerHTML = '<span class="spinner"></span> Generating...';
    status.textContent = '';
    status.className = 'status-msg';
    document.getElementById('resultSection').style.display = 'none';

    const prompt = `You are an expert exam question writer for university-level courses.
Generate exactly ${qcount} ${typeMap[qtype]} questions on the topic: "${topic}" for the course: "${course}".
Difficulty: ${diff}. Language: ${lang}.

Return ONLY a valid JSON array. No markdown, no explanation.
Each object must have: "num", "type", "question", "options" (array), "answer", "difficulty".`;

    try {
        // Send prompt to your SECURE proxy file
        const res = await fetch('ajax/ai_proxy.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ prompt: prompt })
        });

        const data = await res.json();

        if (data.error) {
            throw new Error(data.error);
        }

        // data is already the JSON array from the proxy
        generatedQuestions = data;
        renderQuestions(generatedQuestions, course, topic);
        
        status.textContent = `✓ ${generatedQuestions.length} questions generated successfully.`;
        status.className = 'status-msg success';
    } catch (e) {
        status.textContent = 'Generation failed: ' + e.message;
        status.className = 'status-msg error';
        console.error(e);
    }

    btn.disabled = false;
    btn.innerHTML = 'Generate questions';
}

/**
 * RENDER QUESTIONS TO UI
 */
function renderQuestions(qs, course, topic) {
    document.getElementById('resultTitle').textContent = course + ' — ' + topic;
    const list = document.getElementById('qList');
    list.innerHTML = '';

    qs.forEach((q, i) => {
        const div = document.createElement('div');
        div.className = 'q-card';

        let optHtml = '';
        if (q.options && q.options.length) {
            optHtml = '<div class="opts">' +
                q.options.map(o =>
                    `<div class="opt${o === q.answer ? ' correct' : ''}">${escHtml(o)}</div>`
                ).join('') + '</div>';
        }

        div.innerHTML = `
            <div class="q-meta">
                <span class="q-num">Question ${q.num}</span>
                <div style="display:flex;gap:6px">
                    <span class="badge ${q.type}">${q.type.toUpperCase()}</span>
                    <span class="badge ${q.difficulty}">${q.difficulty}</span>
                </div>
            </div>
            <div class="q-text">${escHtml(q.question)}</div>
            ${optHtml}
            <div class="answer-row">
                <span class="ans-badge">Answer: ${escHtml(q.answer)}</span>
                <button class="copy-q-btn" onclick="copyQ(${i})">Copy</button>
            </div>
        `;
        list.appendChild(div);
    });

    document.getElementById('resultSection').style.display = 'block';
}

function escHtml(str) {
    if (!str) return '';
    return String(str).replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;');
}

function copyQ(i) {
    const q = generatedQuestions[i];
    let text = `Q${q.num}: ${q.question}\n`;
    if (q.options?.length) text += q.options.join('\n') + '\n';
    text += `Answer: ${q.answer}`;
    navigator.clipboard.writeText(text);
}

function copyAll() {
    const text = generatedQuestions.map(q => {
        let t = `Q${q.num}: ${q.question}\n`;
        if (q.options?.length) t += q.options.join('\n') + '\n';
        t += `Answer: ${q.answer}\n`;
        return t;
    }).join('\n');
    navigator.clipboard.writeText(text);
}

function downloadTxt() {
    const { course, topic } = lastParams;
    let text = `Course: ${course}\nTopic: ${topic}\n\n`;
    text += generatedQuestions.map(q => {
        let t = `Q${q.num}: ${q.question}\n`;
        if (q.options?.length) t += q.options.join('\n') + '\n';
        t += `Answer: ${q.answer}\n`;
        return t;
    }).join('\n');
    const a = document.createElement('a');
    a.href = 'data:text/plain;charset=utf-8,' + encodeURIComponent(text);
    a.download = `questions_${course.replace(/\s+/g,'_')}.txt`;
    a.click();
}

function regenerate() {
    generateQuestions();
}

/**
 * SAVE TO DATABASE
 * Sends the generated array to your database saving script
 */
async function saveToBank() {
    const examId = document.getElementById('examSelect').value;
    if (!examId) {
        alert('Please select an exam to save these questions to.');
        return;
    }
    if (!generatedQuestions.length) {
        alert('No questions to save. Generate some first.');
        return;
    }

    try {
        const res = await fetch('ajax/save_questions.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ exam_id: examId, questions: generatedQuestions })
        });
        const result = await res.json();
        if (result.success) {
            alert(`✓ ${result.saved} questions saved to the question bank!`);
        } else {
            alert('Save failed: ' + (result.message || 'Unknown error'));
        }
    } catch (e) {
        alert('Save failed. Check your connection.');
    }
}
</script>
</body>
</html>