<?php
// Shared assignment form fields — included by faculty/assignments.php
// $editAssign is set when editing, null when creating
$f = $editAssign ?? [];
?>
<div class="form-row">
    <div class="form-group col-8">
        <label>Assignment Title *</label>
        <input type="text" name="title" class="form-control" value="<?= sanitize($f['title'] ?? '') ?>" required placeholder="e.g. Lab Report 1">
    </div>
    <div class="form-group col-4">
        <label>Due Date</label>
        <input type="datetime-local" name="due_date" class="form-control"
            value="<?= isset($f['due_date']) && $f['due_date'] ? date('Y-m-d\TH:i', strtotime($f['due_date'])) : '' ?>">
    </div>
</div>

<div class="form-group">
    <label>Description <small class="text-muted">(shown in assignment list)</small></label>
    <input type="text" name="description" class="form-control" value="<?= sanitize($f['description'] ?? '') ?>" placeholder="Brief description...">
</div>

<div class="form-group">
    <label>Instructions <small class="text-muted">(detailed, shown on submission page)</small></label>
    <textarea name="instructions" class="form-control" rows="4" placeholder="Detailed instructions for students..."><?= sanitize($f['instructions'] ?? '') ?></textarea>
</div>

<div class="form-row">
    <div class="form-group col-3">
        <label>Max Score</label>
        <input type="number" name="max_score" class="form-control" value="<?= $f['max_score'] ?? 100 ?>" min="1" step="0.5">
    </div>
    <div class="form-group col-3">
        <label>Passing Score %</label>
        <input type="number" name="passing_score" class="form-control" value="<?= $f['passing_score'] ?? 60 ?>" min="0" max="100" step="0.5">
    </div>
    <div class="form-group col-3">
        <label>Max Resubmissions</label>
        <input type="number" name="max_submissions" class="form-control" value="<?= $f['max_submissions'] ?? 1 ?>" min="1" max="99">
        <small class="text-muted">1 = no resubmit</small>
    </div>
    <div class="form-group col-3">
        <label>Max File Size (MB)</label>
        <input type="number" name="max_file_size_mb" class="form-control" value="<?= isset($f['max_file_size']) ? (int)($f['max_file_size'] / 1024 / 1024) : 50 ?>" min="1" max="500">
    </div>
</div>

<div class="form-group">
    <label>Allowed File Types <small class="text-muted">(comma-separated extensions)</small></label>
    <input type="text" name="allowed_file_types" class="form-control"
        value="<?= sanitize($f['allowed_file_types'] ?? 'pdf,doc,docx,txt,zip,py,java,cpp,c,js') ?>"
        placeholder="pdf,doc,docx,txt,zip,py,java,cpp,c,js,mp4">
    <small class="text-muted">Common: <a href="#" onclick="this.closest('form').querySelector('[name=allowed_file_types]').value='pdf,doc,docx';return false">Documents</a> |
        <a href="#" onclick="this.closest('form').querySelector('[name=allowed_file_types]').value='py,java,cpp,c,js,txt,zip';return false">Code</a> |
        <a href="#" onclick="this.closest('form').querySelector('[name=allowed_file_types]').value='mp4,mov,avi,mkv';return false">Video</a> |
        <a href="#" onclick="this.closest('form').querySelector('[name=allowed_file_types]').value='pdf,doc,docx,txt,zip,py,java,cpp,c,js,mp4,mov';return false">All</a>
    </small>
</div>

<div class="form-group">
    <label class="checkbox-label">
        <input type="checkbox" name="allow_late" id="allow-late-cb" <?= !empty($f['allow_late']) ? 'checked' : '' ?> onchange="document.getElementById('late-options').style.display=this.checked?'flex':'none'">
        Allow Late Submissions
    </label>
</div>
<div id="late-options" class="form-row" style="display:<?= !empty($f['allow_late']) ? 'flex' : 'none' ?>">
    <div class="form-group col-4">
        <label>Penalty % per Day Late</label>
        <input type="number" name="late_penalty" class="form-control" value="<?= $f['late_penalty'] ?? 0 ?>" min="0" max="100" step="0.5">
        <small class="text-muted">0 = no penalty</small>
    </div>
    <div class="form-group col-4">
        <label>Max Days Late Allowed</label>
        <input type="number" name="max_late_days" class="form-control" value="<?= $f['max_late_days'] ?? 0 ?>" min="0">
        <small class="text-muted">0 = unlimited</small>
    </div>
</div>
