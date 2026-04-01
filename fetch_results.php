<?php
session_start();
require_once "../config/db.php";

if (!isset($_SESSION['role']) || $_SESSION['role'] !== 'lecturer') {
    http_response_code(403);
    exit("Unauthorized");
}

$lecturer_id   = (int)($_SESSION['user_id'] ?? 0);
$filter_course = (int)($_GET['course'] ?? 0);
$search_name   = trim($_GET['search'] ?? '');

// ── Query — includes result_published + course column ─────────────
$sql = "
    SELECT
        a.id                AS attempt_id,
        a.score,
        a.end_time          AS date_submitted,
        a.correct_answers,
        a.wrong_answers,
        a.skipped_answers,
        a.total_questions,
        u.name              AS student_name,
        u.email             AS student_email,
        e.id                AS exam_id,
        e.title             AS exam_title,
        e.result_published,
        c.course_name
    FROM exam_attempts a
    JOIN users u    ON u.id  = a.student_id
    JOIN exams e    ON e.id  = a.exam_id
    JOIN courses c  ON c.id  = e.course_id
    WHERE e.created_by = ?
      AND a.status = 'completed'
";

$types  = "i";
$params = [$lecturer_id];

if ($filter_course > 0) {
    $sql     .= " AND c.id = ?";
    $types   .= "i";
    $params[] = $filter_course;
}

if ($search_name !== '') {
    $like     = "%$search_name%";
    $sql     .= " AND u.name LIKE ?";
    $types   .= "s";
    $params[] = $like;
}

$sql .= " ORDER BY a.end_time DESC";

$stmt = $conn->prepare($sql);
$stmt->bind_param($types, ...$params);
$stmt->execute();
$results = $stmt->get_result();
$stmt->close();

if ($results && $results->num_rows > 0):
    $i = 1;
    while ($row = $results->fetch_assoc()):
        $score = (float)$row['score'];
        $color = match(true) {
            $score >= 80 => '#3fb950',
            $score >= 70 => '#58a6ff',
            $score >= 60 => '#d29922',
            $score >= 50 => '#f0883e',
            default      => '#f85149',
        };
        $grade = match(true) {
            $score >= 80 => 'A',
            $score >= 70 => 'B',
            $score >= 60 => 'C',
            $score >= 50 => 'D',
            default      => 'F',
        };
        $submitted = !empty($row['date_submitted'])
            ? date('d M Y, H:i', strtotime($row['date_submitted']))
            : '—';

        $published     = (int)$row['result_published'];
        $pub_badge     = $published
            ? '<span style="background:#d1fae5;color:#065f46;padding:3px 10px;border-radius:20px;font-size:.72rem;font-weight:700;">✓ Published</span>'
            : '<span style="background:#f3f4f6;color:#6b7280;padding:3px 10px;border-radius:20px;font-size:.72rem;font-weight:700;">Hidden</span>';
?>
        <!-- FIX: data-index for row numbering, data-label on each td for summary + mobile -->
       <tr data-index="<?= $i ?>">
    <td class="row-num ps-3"><?= $i ?></td>

    <td data-label="Exam">
        <strong><?= htmlspecialchars($row['exam_title']) ?></strong>
    </td>

    <td data-label="Course">
        <span class="text-muted small"><?= htmlspecialchars($row['course_name']) ?></span>
    </td>

    <td data-label="Student">
        <strong><?= htmlspecialchars($row['student_name']) ?></strong><br>
        <span class="text-muted small"><?= htmlspecialchars($row['student_email']) ?></span>
    </td>

    <td data-label="Score"><?= number_format($score, 1) ?>
        <div style="display:flex;align-items:center;gap:.5rem;margin-top:3px">
            <div style="flex:1;height:5px;background:#e9ecef;border-radius:3px;overflow:hidden;min-width:60px">
                <div style="width:<?= min($score, 100) ?>%;height:100%;background:<?= $color ?>;border-radius:3px"></div>
            </div>
            <span style="font-weight:700;color:<?= $color ?>;font-size:.85rem;min-width:42px;text-align:right">
                <?= number_format($score, 1) ?>%
            </span>
        </div>
    </td>

    <td data-label="Grade">
        <span style="color:<?= $color ?>;border:1px solid <?= $color ?>33;padding:3px 10px;border-radius:20px;font-weight:700;font-size:.8rem;background:<?= $color ?>11">
            <?= $grade ?>
        </span>
    </td>

    <td data-label="Published"><?= $pub_badge ?></td>

    <td data-label="Submitted" class="text-muted small"><?= $submitted ?></td>

    <!-- ✅ ADD THIS -->
    <td data-label="Action">
        <a href="view_result_details.php?attempt_id=<?= (int)$row['attempt_id'] ?>"
           class="btn btn-sm btn-primary">
            <i class="bi bi-eye"></i> View
        </a>
    </td>
</tr>
<?php
        $i++;
    endwhile;
else:
?>
    <tr>
        <td colspan="8" class="text-center text-muted py-4">
            <i class="bi bi-inbox" style="font-size:2rem;display:block;margin-bottom:.5rem;opacity:.3"></i>
            No results found.
        </td>
    </tr>
<?php endif; ?>