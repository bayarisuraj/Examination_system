<?php
session_start();
date_default_timezone_set('Africa/Accra');

if (!isset($_SESSION['role']) || $_SESSION['role'] !== 'lecturer') {
    header("Location: ../auth/login.php");
    exit();
}

require_once "../config/db.php";

$username    = htmlspecialchars($_SESSION['name'] ?? $_SESSION['username'] ?? 'Lecturer');
$lecturer_id = (int)($_SESSION['user_id'] ?? 0);

if (!$lecturer_id) {
    session_destroy();
    header("Location: ../auth/login.php");
    exit();
}

include "../includes/header.php";
?>

<style>
:root {
    --teal:        #3d8b8d;
    --teal-dark:   #2d6e70;
    --teal-light:  #56a8aa;
    --teal-pale:   #eaf5f5;
    --teal-border: #c0dfe0;
    --surface:     #ffffff;
    --surface2:    #f8fafb;
    --text:        #1a2e35;
    --muted:       #6b7c8d;
    --radius:      12px;

    /* AI Search extras */
    --accent:      #7c6af7;
    --accent2:     #f76a8a;
    --glow:        rgba(124,106,247,0.14);
}
*, *::before, *::after { box-sizing: border-box; }

/* ── Shell ── */
.ai-shell {
    max-width: 860px;
    margin: 0 auto;
    padding: 1.75rem 1.25rem 4rem;
}

/* ── Page header ── */
.page-header {
    display: flex;
    justify-content: space-between;
    align-items: center;
    flex-wrap: wrap;
    gap: .75rem;
    margin-bottom: 1.75rem;
}
.page-header h1 {
    font-size: 1.45rem;
    font-weight: 700;
    color: var(--text);
    margin: 0 0 .2rem;
    display: flex;
    align-items: center;
    gap: .5rem;
}
.page-header h1 i { color: var(--teal); }
.page-header .sub  { font-size: .82rem; color: var(--muted); }

.badge-ai {
    display: inline-flex;
    align-items: center;
    gap: 6px;
    background: rgba(124,106,247,0.1);
    border: 1px solid rgba(124,106,247,0.25);
    border-radius: 100px;
    padding: 4px 12px;
    font-size: .72rem;
    font-weight: 700;
    letter-spacing: .07em;
    color: #7c6af7;
    text-transform: uppercase;
}
.badge-dot {
    width: 6px; height: 6px;
    border-radius: 50%;
    background: var(--accent);
    animation: blink 2s infinite;
}
@keyframes blink {
    0%,100% { opacity:1; }
    50%      { opacity:.3; }
}

/* ── Panel ── */
.panel {
    background: var(--surface);
    border: 1px solid var(--teal-border);
    border-radius: var(--radius);
    overflow: hidden;
    margin-bottom: 1.25rem;
}
.panel-head {
    display: flex;
    justify-content: space-between;
    align-items: center;
    padding: .85rem 1.1rem;
    border-bottom: 1px solid var(--teal-border);
    background: var(--teal-pale);
}
.panel-head h3 {
    font-size: .92rem;
    font-weight: 700;
    color: var(--teal-dark);
    margin: 0;
    display: flex;
    align-items: center;
    gap: .4rem;
}

/* ── Search box ── */
.search-panel { padding: 1.25rem; }

.search-box {
    display: flex;
    align-items: flex-end;
    gap: 10px;
    background: var(--surface2);
    border: 1.5px solid var(--teal-border);
    border-radius: 14px;
    padding: 12px 12px 12px 16px;
    transition: border-color .25s, box-shadow .25s;
}
.search-box:focus-within {
    border-color: var(--teal);
    box-shadow: 0 0 0 3px rgba(61,139,141,.12), 0 4px 20px rgba(61,139,141,.1);
}

.search-icon-wrap {
    color: var(--muted);
    padding-bottom: 6px;
    flex-shrink: 0;
    transition: color .25s;
}
.search-box:focus-within .search-icon-wrap { color: var(--teal); }

#query {
    flex: 1;
    background: transparent;
    border: none; outline: none;
    color: var(--text);
    font-size: .95rem;
    font-family: inherit;
    line-height: 1.6;
    resize: none;
    min-height: 28px;
    max-height: 160px;
}
#query::placeholder { color: var(--muted); }

.send-btn {
    flex-shrink: 0;
    width: 42px; height: 42px;
    border-radius: 10px;
    border: none; cursor: pointer;
    background: var(--teal);
    color: #fff;
    display: flex; align-items: center; justify-content: center;
    font-size: 1rem;
    transition: background .2s, transform .2s, box-shadow .2s;
    box-shadow: 0 3px 12px rgba(61,139,141,.3);
}
.send-btn:hover  { background: var(--teal-dark); transform: translateY(-2px); box-shadow: 0 6px 18px rgba(61,139,141,.35); }
.send-btn:active { transform: scale(.95); }
.send-btn:disabled { opacity: .45; cursor: not-allowed; transform: none; box-shadow: none; }

.hint-text {
    font-size: .75rem;
    color: var(--muted);
    margin-top: .6rem;
    padding-left: 2px;
}
.hint-text kbd {
    background: var(--teal-pale);
    border: 1px solid var(--teal-border);
    border-radius: 4px;
    padding: 1px 5px;
    font-size: .7rem;
    color: var(--teal-dark);
}

/* ── Skeleton ── */
.skeleton-wrap { display: none; padding: 1.25rem; flex-direction: column; gap: 10px; }
.skeleton-wrap.active { display: flex; }
.skel {
    border-radius: 8px;
    background: linear-gradient(90deg, var(--teal-pale) 25%, #d8eded 50%, var(--teal-pale) 75%);
    background-size: 200% 100%;
    animation: shimmer 1.5s infinite;
}
.skel-title { height: 13px; width: 28%; }
.skel-line  { height: 11px; }
.skel-w90 { width: 90%; }
.skel-w75 { width: 75%; }
.skel-w85 { width: 85%; }
.skel-w60 { width: 60%; }
@keyframes shimmer {
    0%   { background-position: 200% 0; }
    100% { background-position: -200% 0; }
}

/* ── Answer card ── */
.answer-wrap { padding: 0 1.25rem 1.25rem; }

.answer-card {
    background: var(--surface2);
    border: 1px solid var(--teal-border);
    border-radius: 12px;
    padding: 1.1rem 1.25rem;
    position: relative;
    overflow: hidden;
    animation: fadeUp .35s ease both;
}
.answer-card::before {
    content: '';
    position: absolute; top: 0; left: 0; right: 0;
    height: 3px;
    background: linear-gradient(90deg, var(--teal), var(--teal-light));
}

@keyframes fadeUp {
    from { opacity: 0; transform: translateY(12px); }
    to   { opacity: 1; transform: translateY(0); }
}

.answer-label {
    font-size: .7rem;
    font-weight: 700;
    letter-spacing: .09em;
    text-transform: uppercase;
    color: var(--teal);
    margin-bottom: .75rem;
    display: flex;
    align-items: center;
    gap: 6px;
}
.answer-label::after {
    content: '';
    flex: 1; height: 1px;
    background: linear-gradient(90deg, var(--teal-border), transparent);
}

.answer-text {
    font-size: .92rem;
    line-height: 1.8;
    color: var(--text);
}

.query-echo {
    margin-top: .9rem;
    padding-top: .9rem;
    border-top: 1px solid var(--teal-border);
    font-size: .78rem;
    color: var(--muted);
    font-style: italic;
}
.query-echo strong { color: var(--teal-dark); font-style: normal; }

/* ── Follow-ups ── */
.followups-wrap { padding: 0 1.25rem 1.25rem; }

.fu-label {
    font-size: .7rem;
    font-weight: 700;
    letter-spacing: .09em;
    text-transform: uppercase;
    color: var(--muted);
    margin-bottom: .75rem;
    display: flex;
    align-items: center;
    gap: 6px;
}
.fu-label::after {
    content: '';
    flex: 1; height: 1px;
    background: linear-gradient(90deg, var(--teal-border), transparent);
}

.fu-grid {
    display: grid;
    grid-template-columns: repeat(auto-fill, minmax(260px, 1fr));
    gap: .75rem;
}

.fu-card {
    background: var(--surface);
    border: 1px solid var(--teal-border);
    border-radius: 10px;
    padding: .75rem 1rem;
    cursor: pointer;
    display: flex;
    align-items: center;
    gap: .75rem;
    transition: border-color .2s, transform .2s, box-shadow .2s;
    animation: fadeUp .35s ease both;
}
.fu-card:hover {
    border-color: var(--teal);
    transform: translateY(-2px);
    box-shadow: 0 6px 20px rgba(61,139,141,.12);
}
.fu-card:nth-child(1) { animation-delay:.04s; }
.fu-card:nth-child(2) { animation-delay:.08s; }
.fu-card:nth-child(3) { animation-delay:.12s; }
.fu-card:nth-child(4) { animation-delay:.16s; }

.fu-thumb {
    width: 38px; height: 38px;
    border-radius: 8px;
    object-fit: cover;
    flex-shrink: 0;
    background: var(--teal-pale);
}
.fu-thumb-placeholder {
    width: 38px; height: 38px;
    border-radius: 8px;
    background: var(--teal-pale);
    border: 1px solid var(--teal-border);
    display: flex; align-items: center; justify-content: center;
    color: var(--teal); font-size: .9rem;
    flex-shrink: 0;
}
.fu-text {
    font-size: .84rem;
    color: var(--text);
    line-height: 1.4;
    flex: 1;
}
.fu-arrow {
    color: var(--muted);
    font-size: .8rem;
    transition: transform .2s, color .2s;
    flex-shrink: 0;
}
.fu-card:hover .fu-arrow { transform: translateX(3px); color: var(--teal); }

/* ── Error ── */
.error-box {
    margin: 0 1.25rem 1.25rem;
    background: #fff5f5;
    border: 1px solid #f5c6c6;
    border-radius: 10px;
    padding: .9rem 1rem;
    color: #c0392b;
    font-size: .88rem;
    display: flex;
    align-items: center;
    gap: .65rem;
    animation: fadeUp .3s ease both;
}

/* ── Empty state ── */
.empty-state {
    padding: 2.5rem 1.25rem;
    text-align: center;
    color: var(--muted);
}
.empty-state i { font-size: 2.5rem; opacity: .3; display: block; margin-bottom: .75rem; }
.empty-state p { font-size: .88rem; }

@media (max-width: 600px) {
    .fu-grid { grid-template-columns: 1fr; }
}
</style>

<div class="ai-shell">

    <!-- Page header -->
    <div class="page-header">
        <div>
            <h1><i class="bi bi-search-heart"></i> AI Search</h1>
            <div class="sub"><?= date('l, d F Y') ?> &mdash; Google AI Mode</div>
        </div>
        <div class="badge-ai"><span class="badge-dot"></span> Powered by Google AI</div>
    </div>

    <!-- Search panel -->
    <div class="panel">
        <div class="panel-head">
            <h3><i class="bi bi-chat-square-text" style="color:var(--teal)"></i> Ask a Question</h3>
        </div>
        <div class="search-panel">
            <div class="search-box">
                <div class="search-icon-wrap">
                    <i class="bi bi-search"></i>
                </div>
                <textarea id="query" rows="1"
                    placeholder="e.g. Explain the concept of database normalization…"
                    maxlength="500"></textarea>
                <button class="send-btn" id="sendBtn" title="Search (Enter)">
                    <i class="bi bi-arrow-up"></i>
                </button>
            </div>
            <div class="hint-text">
                Press <kbd>Enter</kbd> to search &nbsp;·&nbsp; <kbd>Shift</kbd> + <kbd>Enter</kbd> for new line
            </div>
        </div>

        <!-- Skeleton loader -->
        <div class="skeleton-wrap" id="skeleton">
            <div class="skel skel-title"></div>
            <div class="skel skel-line skel-w90"></div>
            <div class="skel skel-line skel-w75"></div>
            <div class="skel skel-line skel-w85"></div>
            <div class="skel skel-line skel-w60"></div>
        </div>

        <!-- Answer output -->
        <div id="answerOut"></div>
    </div>

    <!-- Follow-ups panel (hidden until results load) -->
    <div class="panel" id="followupsPanel" style="display:none;">
        <div class="panel-head">
            <h3><i class="bi bi-lightbulb" style="color:var(--amber,#d29922)"></i> Suggested Follow-ups</h3>
        </div>
        <div class="followups-wrap">
            <div class="fu-grid" id="followupsGrid"></div>
        </div>
    </div>

    <!-- Empty state (shown on page load) -->
    <div id="emptyState" class="panel">
        <div class="empty-state">
            <i class="bi bi-stars"></i>
            <p>Ask any question above to get an AI-generated answer with intelligent follow-up suggestions.</p>
        </div>
    </div>

</div>

<script>
const textarea       = document.getElementById('query');
const sendBtn        = document.getElementById('sendBtn');
const skeleton       = document.getElementById('skeleton');
const answerOut      = document.getElementById('answerOut');
const followupsPanel = document.getElementById('followupsPanel');
const followupsGrid  = document.getElementById('followupsGrid');
const emptyState     = document.getElementById('emptyState');

// Auto-resize textarea
textarea.addEventListener('input', () => {
    textarea.style.height = 'auto';
    textarea.style.height = Math.min(textarea.scrollHeight, 160) + 'px';
});

// Enter = search, Shift+Enter = newline
textarea.addEventListener('keydown', e => {
    if (e.key === 'Enter' && !e.shiftKey) {
        e.preventDefault();
        handleSearch();
    }
});

sendBtn.addEventListener('click', handleSearch);

async function handleSearch() {
    const query = textarea.value.trim();
    if (!query) return;

    setLoading(true);
    emptyState.style.display   = 'none';
    followupsPanel.style.display = 'none';
    answerOut.innerHTML        = '';
    followupsGrid.innerHTML    = '';

    try {
        const res  = await fetch('ajax/serpapi_search.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ query })
        });

        const data = await res.json();

        if (!res.ok || !data.success) {
            showError(data.error || 'Something went wrong. Please try again.');
            return;
        }

        renderAnswer(data);
        renderFollowups(data.followups);

    } catch (err) {
        showError('Network error. Check your connection and try again.');
    } finally {
        setLoading(false);
    }
}

function renderAnswer(data) {
    const text = data.answer
        ? esc(data.answer)
        : '<span style="color:var(--muted);font-style:italic">No direct AI answer returned. Try rephrasing your question.</span>';

    answerOut.innerHTML = `
        <div class="answer-wrap">
            <div class="answer-card">
                <div class="answer-label"><i class="bi bi-stars"></i> AI Answer</div>
                <div class="answer-text">${text}</div>
                <div class="query-echo">Searched for: <strong>${esc(data.query)}</strong></div>
            </div>
        </div>
    `;
}

function renderFollowups(followups) {
    if (!followups || followups.length === 0) return;

    followups.forEach(fu => {
        const thumb = fu.image
            ? `<img class="fu-thumb" src="${esc(fu.image)}" alt="" loading="lazy"
                   onerror="this.style.display='none';this.nextElementSibling.style.display='flex'">`
            : '';
        const placeholder = `<div class="fu-thumb-placeholder" ${fu.image ? 'style="display:none"' : ''}>
                                <i class="bi bi-question-lg"></i>
                             </div>`;

        const card = document.createElement('div');
        card.className = 'fu-card';
        card.innerHTML = `
            ${thumb}${placeholder}
            <span class="fu-text">${esc(fu.question)}</span>
            <i class="bi bi-arrow-right fu-arrow"></i>
        `;
        card.addEventListener('click', () => searchQuery(fu.question));
        followupsGrid.appendChild(card);
    });

    followupsPanel.style.display = 'block';
}

function searchQuery(q) {
    textarea.value = q;
    textarea.style.height = 'auto';
    textarea.style.height = Math.min(textarea.scrollHeight, 160) + 'px';
    window.scrollTo({ top: 0, behavior: 'smooth' });
    handleSearch();
}

function showError(msg) {
    answerOut.innerHTML = `
        <div class="error-box">
            <i class="bi bi-exclamation-circle"></i>
            ${esc(msg)}
        </div>
    `;
}

function setLoading(state) {
    sendBtn.disabled = state;
    skeleton.classList.toggle('active', state);
}

function esc(str) {
    const d = document.createElement('div');
    d.textContent = str || '';
    return d.innerHTML;
}
</script>

<?php include "../includes/footer.php"; ?>