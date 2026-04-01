<?php
session_start();
date_default_timezone_set('Africa/Accra');
require_once "../config/db.php";

// ── Auth guard: lecturers only ────────────────────────────────────
if (!isset($_SESSION['role']) || $_SESSION['role'] !== 'lecturer') {
    header("Location: ../auth/login.php");
    exit();
}

$lecturer_id = (int)($_SESSION['user_id'] ?? 0);
if (!$lecturer_id) {
    session_destroy();
    header("Location: ../auth/login.php");
    exit();
}

$attempt_id = (int)($_GET['attempt_id'] ?? 0);
if (!$attempt_id) {
    header("Location: results.php");
    exit();
}

// ── Fetch attempt — must belong to this lecturer's exam ──────────
$stmt = $conn->prepare("
    SELECT
        a.id              AS attempt_id,
        a.score,
        a.correct_answers,
        a.wrong_answers,
        a.skipped_answers,
        a.total_questions,
        a.start_time,
        a.end_time,
        a.status,

        u.name            AS student_name,
        u.email           AS student_email,

        e.id              AS exam_id,
        e.title           AS exam_title,
        e.duration,
        e.exam_date,
        e.result_published,

        c.course_name

    FROM exam_attempts a
    INNER JOIN users   u ON u.id  = a.student_id
    INNER JOIN exams   e ON e.id  = a.exam_id
    INNER JOIN courses c ON c.id  = e.course_id

    WHERE a.id     = ?
      AND (e.lecturer_id = ? OR e.created_by = ?)
      AND a.status = 'completed'

    LIMIT 1
");
$stmt->bind_param("iii", $attempt_id, $lecturer_id, $lecturer_id);
$stmt->execute();
$attempt = $stmt->get_result()->fetch_assoc();
$stmt->close();

if (!$attempt) {
    header("Location: results.php");
    exit();
}

// ── Time spent ────────────────────────────────────────────────────
$time_spent_sec = 0;
if ($attempt['start_time'] && $attempt['end_time']) {
    $time_spent_sec = max(0, strtotime($attempt['end_time']) - strtotime($attempt['start_time']));
}
$time_spent_fmt = sprintf('%d:%02d', floor($time_spent_sec / 60), $time_spent_sec % 60);

// ── Fetch question-level answers ──────────────────────────────────
$stmt = $conn->prepare("
    SELECT
        q.id              AS question_id,
        q.question_text,
        q.option_a,
        q.option_b,
        q.option_c,
        q.option_d,
        q.correct_option,
        sa.selected_option
    FROM questions q
    JOIN exam_questions eq ON eq.question_id = q.id
                          AND eq.exam_id     = ?
    LEFT JOIN student_answers sa ON sa.question_id = q.id
                                AND sa.attempt_id  = ?
    ORDER BY q.id ASC
");
$stmt->bind_param("ii", $attempt['exam_id'], $attempt_id);
$stmt->execute();
$answers_raw = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$stmt->close();

// ── Map selected_option → uppercase letter ────────────────────────
$answers = [];
foreach ($answers_raw as $ans) {
    $selected    = ($ans['selected_option'] !== null && $ans['selected_option'] !== '')
                   ? strtoupper(trim($ans['selected_option']))
                   : null;
    $correct_ans = strtoupper(trim($ans['correct_option']));
    $is_correct  = ($selected !== null && $selected === $correct_ans);
    $is_skipped  = ($selected === null);

    $ans['selected']    = $selected;
    $ans['correct_ans'] = $correct_ans;
    $ans['is_correct']  = $is_correct;
    $ans['is_skipped']  = $is_skipped;
    $answers[]          = $ans;
}

$total_q = count($answers);
$correct  = (int)$attempt['correct_answers'];
$wrong    = (int)$attempt['wrong_answers'];
$skipped  = (int)$attempt['skipped_answers'];
$score    = (float)$attempt['score'];

// ── Grade helpers ─────────────────────────────────────────────────
function grade_label(float $s): string {
    if ($s >= 80) return 'A'; if ($s >= 70) return 'B';
    if ($s >= 60) return 'C'; if ($s >= 50) return 'D'; return 'F';
}
function grade_color(float $s): string {
    if ($s >= 80) return '#16a34a'; if ($s >= 70) return '#2563eb';
    if ($s >= 60) return '#d97706'; if ($s >= 50) return '#f59e0b'; return '#dc2626';
}
function grade_remark(float $s): string {
    if ($s >= 80) return 'Excellent'; if ($s >= 70) return 'Good';
    if ($s >= 60) return 'Average';   if ($s >= 50) return 'Pass'; return 'Fail';
}

$grade     = grade_label($score);
$grade_clr = grade_color($score);
$remark    = grade_remark($score);

include "../includes/header.php";
?>

<style>
@import url('https://fonts.googleapis.com/css2?family=Sora:wght@400;600;700&family=DM+Mono:wght@400;500&family=DM+Sans:wght@400;500;600&display=swap');

:root {
    --bg:      #f4f6fb;
    --surface: #ffffff;
    --surface2:#f8f9fc;
    --border:  #e5e9f2;
    --text:    #1a1f2e;
    --muted:   #6b7280;
    --accent:  #2563eb;
    --green:   #16a34a;
    --red:     #dc2626;
    --amber:   #d97706;
    --radius:  14px;
    --sans:    'DM Sans', sans-serif;
    --serif:   'Sora', sans-serif;
    --mono:    'DM Mono', monospace;
    --shadow:  0 2px 16px rgba(30,40,80,.07);
}

*, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }
body { background: var(--bg); color: var(--text); font-family: var(--sans); min-height: 100vh; }
.page-shell { max-width: 960px; margin: 0 auto; padding: 2.5rem 1.25rem 5rem; }

.back-link { display: inline-flex; align-items: center; gap: .35rem; font-size: .82rem; color: var(--muted); text-decoration: none; margin-bottom: 1.5rem; transition: color .18s; }
.back-link:hover { color: var(--accent); }

/* ── Page header ── */
.page-header { margin-bottom: 1.75rem; }
.page-header h1 { font-family: var(--serif); font-size: 1.5rem; font-weight: 700; margin-bottom: .25rem; line-height: 1.25; }
.page-header .meta { font-size: .82rem; color: var(--muted); display: flex; gap: .5rem; flex-wrap: wrap; align-items: center; }

/* ── Student info banner ── */
.student-banner {
    background: var(--surface); border: 1px solid var(--border);
    border-radius: var(--radius); padding: 1.2rem 1.5rem;
    margin-bottom: 1.25rem; box-shadow: var(--shadow);
    display: flex; align-items: center; gap: 1.1rem; flex-wrap: wrap;
}
.student-avatar {
    width: 48px; height: 48px; border-radius: 50%;
    background: linear-gradient(135deg, var(--accent), #7c3aed);
    display: flex; align-items: center; justify-content: center;
    color: #fff; font-family: var(--serif); font-size: 1.2rem; font-weight: 700;
    flex-shrink: 0;
}
.student-info h3 { font-family: var(--serif); font-size: 1rem; font-weight: 700; margin-bottom: .15rem; }
.student-info p  { font-size: .8rem; color: var(--muted); margin: 0; }

/* ── Score banner ── */
.result-banner {
    background: var(--surface); border: 1px solid var(--border);
    border-radius: var(--radius); padding: 1.4rem 1.6rem;
    margin-bottom: 1.5rem; box-shadow: var(--shadow);
    display: flex; align-items: center; justify-content: space-between; flex-wrap: wrap; gap: 1.25rem;
}
.rb-left h2 { font-family: var(--serif); font-size: 1.05rem; font-weight: 700; margin-bottom: .2rem; }
.rb-left .sub { font-size: .8rem; color: var(--muted); }
.rb-right { display: flex; align-items: center; gap: 1.25rem; flex-wrap: wrap; }
.score-big .val { font-family: var(--mono); font-size: 2.2rem; font-weight: 700; display: block; line-height: 1; }
.score-big .lbl { font-size: .72rem; color: var(--muted); text-transform: uppercase; letter-spacing: .07em; }
.grade-big { font-family: var(--mono); font-size: 2rem; font-weight: 700; padding: .3rem .9rem; border-radius: 10px; border: 2px solid; }
.remark-pill { padding: .3rem .85rem; border-radius: 20px; font-size: .8rem; font-weight: 700; background: var(--surface2); border: 1px solid var(--border); }
.pf-big { padding: .35rem 1rem; border-radius: 20px; font-size: .85rem; font-weight: 700; }
.pf-pass { background: #d1fae5; color: #065f46; } .pf-fail { background: #fee2e2; color: #991b1b; }

/* ── Stat cards ── */
.stat-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(130px, 1fr)); gap: .8rem; margin-bottom: 1.75rem; }
.stat-card { background: var(--surface); border: 1px solid var(--border); border-radius: var(--radius); padding: .9rem 1rem; display: flex; flex-direction: column; gap: .25rem; box-shadow: var(--shadow); }
.s-label { font-size: .7rem; text-transform: uppercase; letter-spacing: .08em; font-weight: 700; color: var(--muted); }
.s-val   { font-family: var(--mono); font-size: 1.35rem; font-weight: 600; }
.sc-correct .s-val { color: var(--green); }
.sc-wrong   .s-val { color: var(--red); }
.sc-skip    .s-val { color: var(--muted); }
.sc-time    .s-val { color: var(--accent); }

/* ── Donut ── */
.donut-wrap { background: var(--surface); border: 1px solid var(--border); border-radius: var(--radius); padding: 1.25rem; margin-bottom: 1.75rem; box-shadow: var(--shadow); display: flex; align-items: center; gap: 2rem; flex-wrap: wrap; }
.donut-wrap h3 { width: 100%; font-family: var(--serif); font-size: .95rem; font-weight: 700; margin-bottom: -.5rem; }
.donut-svg { flex-shrink: 0; }
.donut-legend { display: flex; flex-direction: column; gap: .6rem; }
.legend-item { display: flex; align-items: center; gap: .55rem; font-size: .83rem; color: var(--muted); }
.legend-dot { width: 10px; height: 10px; border-radius: 50%; flex-shrink: 0; }

/* ── Filter bar ── */
.filter-bar { display: flex; gap: .6rem; flex-wrap: wrap; margin-bottom: 1.1rem; align-items: center; }
.filter-btn { display: inline-flex; align-items: center; gap: .3rem; padding: .35rem .85rem; border-radius: 20px; border: 1px solid var(--border); background: transparent; color: var(--muted); font-size: .8rem; font-weight: 600; cursor: pointer; transition: all .18s; }
.filter-btn:hover { border-color: var(--accent); color: var(--accent); }
.filter-btn.active    { border-color: var(--accent); color: var(--accent); background: #eef4ff; }
.filter-btn.f-correct.active { border-color: var(--green); color: var(--green); background: #f0fdf4; }
.filter-btn.f-wrong.active   { border-color: var(--red);   color: var(--red);   background: #fef2f2; }
.filter-btn.f-skip.active    { border-color: var(--muted); color: var(--text);  background: var(--surface2); }
.q-count { font-size: .81rem; color: var(--muted); margin-left: auto; }

/* ── Question cards ── */
.q-card { background: var(--surface); border: 1px solid var(--border); border-radius: var(--radius); padding: 1.2rem 1.4rem; margin-bottom: .85rem; box-shadow: var(--shadow); }
.q-card.q-correct { border-left: 4px solid var(--green); }
.q-card.q-wrong   { border-left: 4px solid var(--red); }
.q-card.q-skipped { border-left: 4px solid var(--border); }

.q-header { display: flex; align-items: flex-start; justify-content: space-between; gap: .75rem; margin-bottom: .85rem; flex-wrap: wrap; }
.q-num { font-family: var(--mono); font-size: .75rem; color: var(--muted); white-space: nowrap; }
.q-result-badge { display: inline-flex; align-items: center; gap: .3rem; padding: .2rem .65rem; border-radius: 20px; font-size: .73rem; font-weight: 700; white-space: nowrap; }
.badge-correct { background: #f0fdf4; color: var(--green); border: 1px solid #86efac; }
.badge-wrong   { background: #fef2f2; color: var(--red);   border: 1px solid #fca5a5; }
.badge-skipped { background: var(--surface2); color: var(--muted); border: 1px solid var(--border); }

.q-text { font-size: .92rem; color: var(--text); line-height: 1.6; margin-bottom: 1rem; font-weight: 500; }

.options-grid { display: flex; flex-direction: column; gap: .5rem; }
.option-row { display: flex; align-items: flex-start; gap: .65rem; padding: .5rem .75rem; border-radius: 8px; border: 1px solid transparent; font-size: .85rem; color: var(--muted); line-height: 1.5; background: var(--surface2); }
.option-row.opt-correct        { background: #f0fdf4; border-color: #86efac; color: var(--green); }
.option-row.opt-selected-wrong { background: #fef2f2; border-color: #fca5a5; color: var(--red); }
.opt-key  { font-family: var(--mono); font-size: .78rem; font-weight: 700; min-width: 22px; padding-top: .05rem; }
.opt-text { flex: 1; }
.opt-icon { margin-left: auto; font-size: .85rem; padding-top: .05rem; }

.empty-q { text-align: center; padding: 3rem 1rem; color: var(--muted); }
.empty-q i { font-size: 2rem; display: block; margin-bottom: .6rem; opacity: .3; }

@media (max-width: 600px) {
    .page-shell { padding: 1.25rem .75rem 4rem; }
    .score-big .val { font-size: 1.6rem; }
    .grade-big { font-size: 1.5rem; }
    .result-banner { flex-direction: column; }
    .rb-right { width: 100%; justify-content: flex-start; }
}
</style>

<div class="page-shell">

    <a href="results.php" class="back-link">
        <i class="bi bi-arrow-left"></i> Back to Results
    </a>

    <!-- Page header -->
    <div class="page-header">
        <h1>
            <i class="bi bi-file-earmark-bar-graph" style="color:var(--accent);margin-right:.4rem"></i>
            <?= htmlspecialchars($attempt['exam_title']) ?>
        </h1>
        <div class="meta">
            <i class="bi bi-book"></i> <?= htmlspecialchars($attempt['course_name']) ?>
            &bull;
            <i class="bi bi-calendar3"></i> <?= date('d M Y', strtotime($attempt['exam_date'])) ?>
            &bull;
            <i class="bi bi-clock"></i> Submitted: <?= date('d M Y, H:i', strtotime($attempt['end_time'])) ?>
        </div>
    </div>

    <!-- Student info banner -->
    <div class="student-banner">
        <div class="student-avatar">
            <?= strtoupper(substr($attempt['student_name'], 0, 1)) ?>
        </div>
        <div class="student-info">
            <h3><?= htmlspecialchars($attempt['student_name']) ?></h3>
            <p><i class="bi bi-envelope"></i> <?= htmlspecialchars($attempt['student_email']) ?></p>
        </div>
        <div style="margin-left:auto;display:flex;gap:.6rem;flex-wrap:wrap;align-items:center">
            <span class="pf-big <?= $score >= 50 ? 'pf-pass' : 'pf-fail' ?>">
                <i class="bi bi-<?= $score >= 50 ? 'check-circle-fill' : 'x-circle-fill' ?>"></i>
                <?= $score >= 50 ? 'Passed' : 'Failed' ?>
            </span>
            <?php if ($attempt['result_published']): ?>
            <span style="font-size:.78rem;color:var(--green);font-weight:700;display:inline-flex;align-items:center;gap:.3rem">
                <i class="bi bi-check-circle-fill"></i> Published
            </span>
            <?php else: ?>
            <span style="font-size:.78rem;color:var(--amber);font-weight:700;display:inline-flex;align-items:center;gap:.3rem">
                <i class="bi bi-hourglass-split"></i> Not Published
            </span>
            <?php endif; ?>
        </div>
    </div>

    <!-- Score banner -->
    <div class="result-banner">
        <div class="rb-left">
            <h2>Exam Result</h2>
            <div class="sub"><?= htmlspecialchars($attempt['course_name']) ?> &bull; <?= htmlspecialchars($attempt['exam_title']) ?></div>
        </div>
        <div class="rb-right">
            <div class="score-big">
                <span class="val" style="color:<?= $grade_clr ?>"><?= number_format($score, 1) ?>%</span>
                <span class="lbl">Score</span>
            </div>
            <div class="grade-big" style="color:<?= $grade_clr ?>;border-color:<?= $grade_clr ?>44">
                <?= $grade ?>
            </div>
            <span class="remark-pill" style="color:<?= $grade_clr ?>;border-color:<?= $grade_clr ?>44">
                <?= $remark ?>
            </span>
        </div>
    </div>

    <!-- Stat cards -->
    <div class="stat-grid">
        <div class="stat-card sc-correct">
            <span class="s-label">Correct</span>
            <span class="s-val"><?= $correct ?></span>
        </div>
        <div class="stat-card sc-wrong">
            <span class="s-label">Wrong</span>
            <span class="s-val"><?= $wrong ?></span>
        </div>
        <div class="stat-card sc-skip">
            <span class="s-label">Skipped</span>
            <span class="s-val"><?= $skipped ?></span>
        </div>
        <div class="stat-card sc-time">
            <span class="s-label">Time Spent</span>
            <span class="s-val"><?= $time_spent_fmt ?></span>
        </div>
        <div class="stat-card">
            <span class="s-label">Total Q</span>
            <span class="s-val" style="color:var(--muted)"><?= $total_q ?></span>
        </div>
        <div class="stat-card">
            <span class="s-label">Duration</span>
            <span class="s-val" style="color:var(--muted)"><?= (int)$attempt['duration'] ?>m</span>
        </div>
    </div>

    <!-- Donut breakdown -->
    <?php if ($total_q > 0):
        $r_val = 40; $cx = 56; $cy = 56; $stroke = 14;
        $circ  = 2 * M_PI * $r_val;
        $pct_c = $total_q ? ($correct / $total_q) : 0;
        $pct_w = $total_q ? ($wrong   / $total_q) : 0;
        $pct_s = $total_q ? ($skipped / $total_q) : 0;
        $dash_c = round($pct_c * $circ, 2);
        $dash_w = round($pct_w * $circ, 2);
        $dash_s = round($pct_s * $circ, 2);
    ?>
    <div class="donut-wrap">
        <h3><i class="bi bi-pie-chart-fill" style="color:var(--accent);margin-right:.4rem"></i>Answer Breakdown</h3>
        <svg class="donut-svg" width="112" height="112" viewBox="0 0 112 112">
            <circle cx="<?= $cx ?>" cy="<?= $cy ?>" r="<?= $r_val ?>" fill="none" stroke="#e5e9f2" stroke-width="<?= $stroke ?>"/>
            <?php if ($pct_c > 0): ?>
            <circle cx="<?= $cx ?>" cy="<?= $cy ?>" r="<?= $r_val ?>" fill="none" stroke="#16a34a" stroke-width="<?= $stroke ?>"
                    stroke-dasharray="<?= $dash_c ?> <?= $circ ?>"
                    stroke-dashoffset="<?= round($circ * 0.25, 2) ?>"
                    transform="rotate(-90 <?= $cx ?> <?= $cy ?>)"/>
            <?php endif; ?>
            <?php if ($pct_w > 0): ?>
            <circle cx="<?= $cx ?>" cy="<?= $cy ?>" r="<?= $r_val ?>" fill="none" stroke="#dc2626" stroke-width="<?= $stroke ?>"
                    stroke-dasharray="<?= $dash_w ?> <?= $circ ?>"
                    stroke-dashoffset="<?= round($circ * 0.25 - $dash_c, 2) ?>"
                    transform="rotate(-90 <?= $cx ?> <?= $cy ?>)"/>
            <?php endif; ?>
            <?php if ($pct_s > 0): ?>
            <circle cx="<?= $cx ?>" cy="<?= $cy ?>" r="<?= $r_val ?>" fill="none" stroke="#9ca3af" stroke-width="<?= $stroke ?>"
                    stroke-dasharray="<?= $dash_s ?> <?= $circ ?>"
                    stroke-dashoffset="<?= round($circ * 0.25 - $dash_c - $dash_w, 2) ?>"
                    transform="rotate(-90 <?= $cx ?> <?= $cy ?>)"/>
            <?php endif; ?>
            <text x="<?= $cx ?>" y="<?= $cy + 5 ?>" text-anchor="middle" fill="<?= $grade_clr ?>"
                  font-family="'DM Mono',monospace" font-size="14" font-weight="700">
                <?= number_format($score, 0) ?>%
            </text>
        </svg>
        <div class="donut-legend">
            <div class="legend-item">
                <span class="legend-dot" style="background:#16a34a"></span>
                Correct — <?= $correct ?> (<?= $total_q ? round($pct_c * 100) : 0 ?>%)
            </div>
            <div class="legend-item">
                <span class="legend-dot" style="background:#dc2626"></span>
                Wrong — <?= $wrong ?> (<?= $total_q ? round($pct_w * 100) : 0 ?>%)
            </div>
            <div class="legend-item">
                <span class="legend-dot" style="background:#9ca3af"></span>
                Skipped — <?= $skipped ?> (<?= $total_q ? round($pct_s * 100) : 0 ?>%)
            </div>
            <div class="legend-item" style="margin-top:.3rem;font-family:var(--mono);font-size:.77rem;">
                <i class="bi bi-stopwatch" style="color:var(--accent)"></i>
                Time: <?= $time_spent_fmt ?> / <?= (int)$attempt['duration'] ?>min allowed
            </div>
        </div>
    </div>
    <?php endif; ?>

    <!-- Filter bar -->
    <div class="filter-bar">
        <button class="filter-btn active"    onclick="filterQ('all',this)">
            <i class="bi bi-list-ul"></i> All (<?= $total_q ?>)
        </button>
        <button class="filter-btn f-correct" onclick="filterQ('correct',this)">
            <i class="bi bi-check-lg"></i> Correct (<?= $correct ?>)
        </button>
        <button class="filter-btn f-wrong"   onclick="filterQ('wrong',this)">
            <i class="bi bi-x-lg"></i> Wrong (<?= $wrong ?>)
        </button>
        <button class="filter-btn f-skip"    onclick="filterQ('skipped',this)">
            <i class="bi bi-dash"></i> Skipped (<?= $skipped ?>)
        </button>
        <span class="q-count" id="qCount"><?= $total_q ?> question<?= $total_q !== 1 ? 's' : '' ?></span>
    </div>

    <!-- Question cards -->
    <div id="questionsContainer">
        <?php if (!empty($answers)):
            $qi = 0;
            foreach ($answers as $ans):
                $selected    = $ans['selected'];
                $correct_ans = $ans['correct_ans'];
                $is_correct  = $ans['is_correct'];
                $is_skipped  = $ans['is_skipped'];

                if ($is_skipped)     { $state = 'skipped'; $badge_cls = 'badge-skipped'; $badge_lbl = 'Skipped'; $badge_icon = 'bi-dash';    $card_cls = 'q-skipped'; }
                elseif ($is_correct) { $state = 'correct'; $badge_cls = 'badge-correct'; $badge_lbl = 'Correct'; $badge_icon = 'bi-check-lg'; $card_cls = 'q-correct'; }
                else                 { $state = 'wrong';   $badge_cls = 'badge-wrong';   $badge_lbl = 'Wrong';   $badge_icon = 'bi-x-lg';    $card_cls = 'q-wrong';   }

                $options = [
                    'A' => $ans['option_a'] ?? '',
                    'B' => $ans['option_b'] ?? '',
                    'C' => $ans['option_c'] ?? '',
                    'D' => $ans['option_d'] ?? '',
                ];
                $qi++;
        ?>
        <div class="q-card <?= $card_cls ?>" data-state="<?= $state ?>">
            <div class="q-header">
                <span class="q-num">Question <?= $qi ?> of <?= $total_q ?></span>
                <span class="q-result-badge <?= $badge_cls ?>">
                    <i class="bi <?= $badge_icon ?>"></i> <?= $badge_lbl ?>
                </span>
            </div>

            <div class="q-text"><?= nl2br(htmlspecialchars($ans['question_text'])) ?></div>

            <div class="options-grid">
                <?php foreach ($options as $key => $text):
                    if ($text === '' || $text === null) continue;
                    $is_the_correct  = ($key === $correct_ans);
                    $is_the_selected = ($selected !== null && $key === $selected);

                    if ($is_the_correct && $is_the_selected) {
                        $cls  = 'opt-correct';
                        $icon = '<i class="bi bi-check-lg opt-icon" style="color:var(--green)"></i>';
                    } elseif ($is_the_correct && !$is_the_selected) {
                        $cls  = 'opt-correct';
                        $icon = '<i class="bi bi-check-lg opt-icon" style="color:var(--green)"></i>';
                    } elseif ($is_the_selected && !$is_the_correct) {
                        $cls  = 'opt-selected-wrong';
                        $icon = '<i class="bi bi-x-lg opt-icon" style="color:var(--red)"></i>';
                    } else {
                        $cls  = ''; $icon = '';
                    }
                ?>
                <div class="option-row <?= $cls ?>">
                    <span class="opt-key"><?= $key ?>.</span>
                    <span class="opt-text"><?= htmlspecialchars($text) ?></span>
                    <?= $icon ?>
                </div>
                <?php endforeach; ?>
            </div>

            <?php if ($is_skipped): ?>
            <div style="margin-top:.85rem;font-size:.77rem;color:var(--muted);font-family:var(--mono);">
                Student did not answer. Correct answer:
                <strong style="color:var(--green)"><?= $correct_ans ?></strong>
            </div>
            <?php elseif (!$is_correct): ?>
            <div style="margin-top:.85rem;font-size:.77rem;color:var(--muted);font-family:var(--mono);">
                Student answered: <strong style="color:var(--red)"><?= $selected ?></strong>
                &nbsp;&bull;&nbsp;
                Correct answer: <strong style="color:var(--green)"><?= $correct_ans ?></strong>
            </div>
            <?php endif; ?>
        </div>
        <?php endforeach;
        else: ?>
        <div class="empty-q">
            <i class="bi bi-journal-x"></i>
            <p>No question detail available for this attempt.</p>
        </div>
        <?php endif; ?>
    </div>

</div><!-- /page-shell -->

<script>
function filterQ(state, btn) {
    document.querySelectorAll('.filter-btn').forEach(b => b.classList.remove('active'));
    btn.classList.add('active');
    let visible = 0;
    document.querySelectorAll('.q-card').forEach(card => {
        const show = state === 'all' || card.dataset.state === state;
        card.style.display = show ? '' : 'none';
        if (show) visible++;
    });
    document.getElementById('qCount').textContent =
        visible + ' question' + (visible !== 1 ? 's' : '');
}
</script>

<?php include "../includes/footer.php"; ?>