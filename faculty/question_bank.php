<?php
require_once __DIR__ . '/../includes/functions.php';
requireRole('faculty');

$sectionId = (int)($_GET['section_id'] ?? 0);
$sec = $pdo->prepare("SELECT s.*, c.course_code, c.course_name FROM sections s JOIN courses c ON s.course_id=c.id WHERE s.id=? AND s.faculty_id=?");
$sec->execute([$sectionId, $_SESSION['user_id']]);
$section = $sec->fetch();
if (!$section) redirect(BASE_URL . '/faculty/sections.php');

$pageTitle = 'Question Bank – ' . $section['course_code'];

// Get or create default bank
$bankStmt = $pdo->prepare("SELECT id FROM question_banks WHERE section_id=? LIMIT 1");
$bankStmt->execute([$sectionId]);
$bank = $bankStmt->fetch();
if (!$bank) {
    $pdo->prepare("INSERT INTO question_banks (section_id, name, created_by) VALUES (?,?,?)")
        ->execute([$sectionId, 'Default Bank', $_SESSION['user_id']]);
    $bankId = $pdo->lastInsertId();
} else {
    $bankId = $bank['id'];
}

$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $postAction = $_POST['post_action'] ?? '';

    if ($postAction === 'add_question') {
        $qText  = trim($_POST['question_text'] ?? '');
        $qType  = $_POST['question_type'] ?? '';
        $points = max(0.5, (float)($_POST['points'] ?? 1));
        $expl   = trim($_POST['explanation'] ?? '');

        if (empty($qText) || !in_array($qType, ['multiple_choice','true_false','essay','matching','short_answer'])) {
            $error = 'Question text and valid type are required.';
        } else {
            $pdo->prepare("INSERT INTO questions (bank_id, question_text, question_type, points, explanation) VALUES (?,?,?,?,?)")
                ->execute([$bankId, $qText, $qType, $points, $expl]);
            $qid = $pdo->lastInsertId();

            switch ($qType) {
                case 'multiple_choice':
                    $opts    = $_POST['mc_options'] ?? [];
                    $correct = (int)($_POST['mc_correct'] ?? 0);
                    foreach ($opts as $i => $opt) {
                        if (trim($opt) !== '') {
                            $pdo->prepare("INSERT INTO question_options (question_id, option_text, is_correct, option_order) VALUES (?,?,?,?)")
                                ->execute([$qid, trim($opt), $i == $correct ? 1 : 0, $i]);
                        }
                    }
                    break;
                case 'true_false':
                    $correct = $_POST['tf_correct'] ?? 'true';
                    $pdo->prepare("INSERT INTO question_options (question_id, option_text, is_correct, option_order) VALUES (?,?,?,0)")->execute([$qid, 'True',  $correct === 'true'  ? 1 : 0]);
                    $pdo->prepare("INSERT INTO question_options (question_id, option_text, is_correct, option_order) VALUES (?,?,?,1)")->execute([$qid, 'False', $correct === 'false' ? 1 : 0]);
                    break;
                case 'essay':
                    $criteria = $_POST['rubric_criteria'] ?? [];
                    $crPts    = $_POST['rubric_points']   ?? [];
                    $crDesc   = $_POST['rubric_desc']     ?? [];
                    foreach ($criteria as $i => $crit) {
                        if (trim($crit) !== '') {
                            $pdo->prepare("INSERT INTO rubric_criteria (question_id, criterion_name, max_points, description, criterion_order) VALUES (?,?,?,?,?)")
                                ->execute([$qid, trim($crit), (float)($crPts[$i] ?? 1), trim($crDesc[$i] ?? ''), $i]);
                        }
                    }
                    break;
                case 'matching':
                    $lefts  = $_POST['match_left']  ?? [];
                    $rights = $_POST['match_right'] ?? [];
                    foreach ($lefts as $i => $left) {
                        $left  = trim($left);
                        $right = trim($rights[$i] ?? '');
                        if ($left !== '' && $right !== '') {
                            $pdo->prepare("INSERT INTO question_options (question_id, option_text, match_pair, is_correct, option_order) VALUES (?,?,?,1,?)")
                                ->execute([$qid, $left, $right, $i]);
                        }
                    }
                    break;
                case 'short_answer':
                    $answers = $_POST['sa_answers'] ?? [];
                    foreach ($answers as $i => $ans) {
                        if (trim($ans) !== '') {
                            $pdo->prepare("INSERT INTO question_options (question_id, option_text, is_correct, option_order) VALUES (?,?,1,?)")
                                ->execute([$qid, trim($ans), $i]);
                        }
                    }
                    break;
            }
            flashMessage('success', 'Question added to bank.');
            redirect(BASE_URL . '/faculty/question_bank.php?section_id=' . $sectionId);
        }
    }

    if ($postAction === 'delete_question') {
        $qid = (int)$_POST['question_id'];
        $chk = $pdo->prepare("SELECT id FROM questions WHERE id=? AND bank_id=?");
        $chk->execute([$qid, $bankId]);
        if ($chk->fetch()) {
            $pdo->prepare("DELETE FROM rubric_criteria WHERE question_id=?")->execute([$qid]);
            $pdo->prepare("DELETE FROM question_options WHERE question_id=?")->execute([$qid]);
            $pdo->prepare("DELETE FROM questions WHERE id=?")->execute([$qid]);
            flashMessage('success', 'Question deleted.');
        }
        redirect(BASE_URL . '/faculty/question_bank.php?section_id=' . $sectionId);
    }
}

$questions = $pdo->prepare("SELECT * FROM questions WHERE bank_id=? ORDER BY created_at DESC");
$questions->execute([$bankId]);
$questions = $questions->fetchAll();

foreach ($questions as &$q) {
    $optStmt = $pdo->prepare("SELECT * FROM question_options WHERE question_id=? ORDER BY option_order");
    $optStmt->execute([$q['id']]);
    $q['options'] = $optStmt->fetchAll();
    if ($q['question_type'] === 'essay') {
        $rubStmt = $pdo->prepare("SELECT * FROM rubric_criteria WHERE question_id=? ORDER BY criterion_order");
        $rubStmt->execute([$q['id']]);
        $q['rubric'] = $rubStmt->fetchAll();
    }
}
unset($q);

include '../includes/header.php';
?>

<div class="page-header">
    <div>
        <a href="<?= BASE_URL ?>/faculty/exams.php?section_id=<?= $sectionId ?>" class="btn btn-sm btn-secondary mb-2">
            <i class="fas fa-arrow-left"></i> Back to Exams
        </a>
        <h1><i class="fas fa-database"></i> Question Bank</h1>
        <p class="text-muted"><?= sanitize($section['course_code']) ?> – <?= sanitize($section['section_name'] ?? '') ?> &nbsp;|&nbsp; <?= count($questions) ?> question(s)</p>
    </div>
</div>

<!-- Add Question Form -->
<div class="card mb-4">
    <div class="card-header"><h3><i class="fas fa-plus-circle"></i> Add New Question</h3></div>
    <div class="card-body">
        <?php if ($error): ?><div class="alert alert-danger"><?= sanitize($error) ?></div><?php endif; ?>
        <form method="POST" id="qform">
            <input type="hidden" name="post_action" value="add_question">
            <div class="form-row">
                <div class="form-group col-5">
                    <label>Question Type *</label>
                    <select name="question_type" id="qtype" class="form-control" onchange="switchType(this.value)" required>
                        <option value="">Select Type</option>
                        <option value="multiple_choice">Multiple Choice</option>
                        <option value="true_false">True or False</option>
                        <option value="essay">Essay</option>
                        <option value="matching">Matching</option>
                        <option value="short_answer">Short Answer</option>
                    </select>
                </div>
                <div class="form-group col-3">
                    <label>Points</label>
                    <input type="number" name="points" class="form-control" value="1" min="0.5" step="0.5">
                </div>
            </div>
            <div class="form-group">
                <label>Question Text *</label>
                <textarea name="question_text" class="form-control" rows="3" required placeholder="Enter your question here..."></textarea>
            </div>

            <!-- Multiple Choice -->
            <div id="type-multiple_choice" class="type-fields" style="display:none">
                <label class="mb-2"><strong>Options</strong> <small class="text-muted">(select the correct answer)</small></label>
                <div id="mc-options">
                    <?php for ($i = 0; $i < 4; $i++): ?>
                    <div class="mc-option-row">
                        <input type="radio" name="mc_correct" value="<?= $i ?>"> &nbsp;
                        <input type="text" name="mc_options[]" class="form-control d-inline w80" placeholder="Option <?= chr(65+$i) ?>">
                    </div>
                    <?php endfor; ?>
                </div>
                <button type="button" class="btn btn-sm btn-secondary mt-2" onclick="addMCOption()"><i class="fas fa-plus"></i> Add Option</button>
            </div>

            <!-- True or False -->
            <div id="type-true_false" class="type-fields" style="display:none">
                <label><strong>Correct Answer</strong></label>
                <div>
                    <label class="radio-label"><input type="radio" name="tf_correct" value="true" checked> True</label>
                    <label class="radio-label ml-3"><input type="radio" name="tf_correct" value="false"> False</label>
                </div>
            </div>

            <!-- Essay -->
            <div id="type-essay" class="type-fields" style="display:none">
                <label><strong>Rubric Criteria</strong> <small class="text-muted">(optional — for rubric-based grading)</small></label>
                <div id="rubric-rows">
                    <div class="rubric-row form-row">
                        <div class="form-group col-4"><input type="text" name="rubric_criteria[]" class="form-control" placeholder="Criterion (e.g. Content)"></div>
                        <div class="form-group col-2"><input type="number" name="rubric_points[]" class="form-control" placeholder="Max pts" min="0.5" step="0.5" value="1"></div>
                        <div class="form-group col-6"><input type="text" name="rubric_desc[]" class="form-control" placeholder="Description (optional)"></div>
                    </div>
                </div>
                <button type="button" class="btn btn-sm btn-secondary" onclick="addRubricRow()"><i class="fas fa-plus"></i> Add Criterion</button>
            </div>

            <!-- Matching -->
            <div id="type-matching" class="type-fields" style="display:none">
                <label><strong>Matching Pairs</strong></label>
                <div id="match-rows">
                    <?php for ($i = 0; $i < 4; $i++): ?>
                    <div class="form-row match-row">
                        <div class="form-group col-5"><input type="text" name="match_left[]" class="form-control" placeholder="Left item (Question)"></div>
                        <div class="form-group col-1 text-center" style="padding-top:28px"><i class="fas fa-arrows-alt-h"></i></div>
                        <div class="form-group col-5"><input type="text" name="match_right[]" class="form-control" placeholder="Right item (Answer)"></div>
                    </div>
                    <?php endfor; ?>
                </div>
                <button type="button" class="btn btn-sm btn-secondary" onclick="addMatchRow()"><i class="fas fa-plus"></i> Add Pair</button>
            </div>

            <!-- Short Answer -->
            <div id="type-short_answer" class="type-fields" style="display:none">
                <label><strong>Acceptable Answers</strong> <small class="text-muted">(add multiple accepted answers)</small></label>
                <div id="sa-rows">
                    <div class="form-row"><div class="form-group col-6"><input type="text" name="sa_answers[]" class="form-control" placeholder="Correct answer"></div></div>
                </div>
                <button type="button" class="btn btn-sm btn-secondary" onclick="addSARow()"><i class="fas fa-plus"></i> Add Answer</button>
            </div>

            <div class="form-group mt-3">
                <label>Explanation <small class="text-muted">(shown to student after grading)</small></label>
                <textarea name="explanation" class="form-control" rows="2" placeholder="Optional explanation of the correct answer..."></textarea>
            </div>
            <button type="submit" class="btn btn-primary mt-2"><i class="fas fa-save"></i> Save Question</button>
        </form>
    </div>
</div>

<!-- Questions List -->
<div class="card">
    <div class="card-header"><h3><i class="fas fa-list"></i> All Questions (<?= count($questions) ?>)</h3></div>
    <div class="card-body p-0">
        <?php if (empty($questions)): ?>
        <p class="text-center text-muted py-4">No questions yet. Add your first question above.</p>
        <?php else: ?>
        <?php foreach ($questions as $i => $q): ?>
        <div class="qbank-item">
            <div class="qbank-meta">
                <span class="qtype-badge qtype-<?= $q['question_type'] ?>"><?= str_replace('_',' ', ucfirst($q['question_type'])) ?></span>
                <span class="qpoints"><i class="fas fa-star"></i> <?= $q['points'] ?> pt<?= $q['points'] != 1 ? 's' : '' ?></span>
                <span class="text-muted">#<?= $i + 1 ?></span>
            </div>
            <p class="qbank-text"><?= sanitize($q['question_text']) ?></p>
            <?php if ($q['question_type'] === 'multiple_choice'): ?>
            <ul class="qbank-options">
                <?php foreach ($q['options'] as $opt): ?>
                <li class="<?= $opt['is_correct'] ? 'correct-opt' : '' ?>">
                    <?= $opt['is_correct'] ? '<i class="fas fa-check-circle"></i> ' : '<i class="far fa-circle"></i> ' ?>
                    <?= sanitize($opt['option_text']) ?>
                </li>
                <?php endforeach; ?>
            </ul>
            <?php elseif ($q['question_type'] === 'true_false'): ?>
            <p class="text-muted">
                Answer: <strong><?= collect_correct_tf($q['options']) ?></strong>
            </p>
            <?php elseif ($q['question_type'] === 'matching'): ?>
            <table class="match-preview">
                <?php foreach ($q['options'] as $opt): ?>
                <tr><td><?= sanitize($opt['option_text']) ?></td><td><i class="fas fa-long-arrow-alt-right"></i></td><td><?= sanitize($opt['match_pair']) ?></td></tr>
                <?php endforeach; ?>
            </table>
            <?php elseif ($q['question_type'] === 'short_answer'): ?>
            <p class="text-muted">Accepted: <?= implode(', ', array_map(fn($o) => sanitize($o['option_text']), $q['options'])) ?></p>
            <?php elseif ($q['question_type'] === 'essay' && !empty($q['rubric'])): ?>
            <div class="rubric-preview">
                <strong>Rubric:</strong>
                <?php foreach ($q['rubric'] as $rc): ?>
                <span class="rubric-tag"><?= sanitize($rc['criterion_name']) ?> (<?= $rc['max_points'] ?>pts)</span>
                <?php endforeach; ?>
            </div>
            <?php endif; ?>
            <?php if ($q['explanation']): ?>
            <p class="qbank-explanation"><i class="fas fa-lightbulb"></i> <?= sanitize($q['explanation']) ?></p>
            <?php endif; ?>
            <div class="qbank-actions">
                <form method="POST" style="display:inline" onsubmit="return confirm('Delete this question?')">
                    <input type="hidden" name="post_action" value="delete_question">
                    <input type="hidden" name="question_id" value="<?= $q['id'] ?>">
                    <button type="submit" class="btn btn-sm btn-danger"><i class="fas fa-trash"></i> Delete</button>
                </form>
            </div>
        </div>
        <?php endforeach; ?>
        <?php endif; ?>
    </div>
</div>

<?php
function collect_correct_tf($options) {
    foreach ($options as $o) if ($o['is_correct']) return $o['option_text'];
    return 'N/A';
}
?>

<script>
function switchType(type) {
    document.querySelectorAll('.type-fields').forEach(el => el.style.display = 'none');
    if (type) document.getElementById('type-' + type).style.display = 'block';
}
let mcCount = 4;
function addMCOption() {
    const row = document.createElement('div');
    row.className = 'mc-option-row';
    row.innerHTML = `<input type="radio" name="mc_correct" value="${mcCount}"> &nbsp;<input type="text" name="mc_options[]" class="form-control d-inline w80" placeholder="Option ${String.fromCharCode(65+mcCount)}">`;
    document.getElementById('mc-options').appendChild(row);
    mcCount++;
}
function addRubricRow() {
    const row = document.createElement('div');
    row.className = 'rubric-row form-row';
    row.innerHTML = `<div class="form-group col-4"><input type="text" name="rubric_criteria[]" class="form-control" placeholder="Criterion"></div><div class="form-group col-2"><input type="number" name="rubric_points[]" class="form-control" placeholder="Max pts" min="0.5" step="0.5" value="1"></div><div class="form-group col-6"><input type="text" name="rubric_desc[]" class="form-control" placeholder="Description"></div>`;
    document.getElementById('rubric-rows').appendChild(row);
}
function addMatchRow() {
    const row = document.createElement('div');
    row.className = 'form-row match-row';
    row.innerHTML = `<div class="form-group col-5"><input type="text" name="match_left[]" class="form-control" placeholder="Left item"></div><div class="form-group col-1 text-center" style="padding-top:28px"><i class="fas fa-arrows-alt-h"></i></div><div class="form-group col-5"><input type="text" name="match_right[]" class="form-control" placeholder="Right item"></div>`;
    document.getElementById('match-rows').appendChild(row);
}
function addSARow() {
    const row = document.createElement('div');
    row.className = 'form-row';
    row.innerHTML = `<div class="form-group col-6"><input type="text" name="sa_answers[]" class="form-control" placeholder="Acceptable answer"></div>`;
    document.getElementById('sa-rows').appendChild(row);
}
</script>

<?php include '../includes/footer.php'; ?>
