<?php
require_once __DIR__ . '/../config/config.php';
requireRole('admin');

$pdo = getDBConnection();
$formId = (int) ($_GET['form_id'] ?? 0);

$stmt = $pdo->prepare('SELECT * FROM research_forms WHERE id = ?');
$stmt->execute([$formId]);
$form = $stmt->fetch();

if (!$form) {
    setFlash('danger', 'Form penelitian tidak ditemukan.');
    redirect('admin/research.php');
}

$qStmt = $pdo->prepare('SELECT * FROM research_questions WHERE form_id = ? ORDER BY sort_order ASC');
$qStmt->execute([$formId]);
$questions = $qStmt->fetchAll();

$rStmt = $pdo->prepare(
    "SELECT rr.*, u.full_name FROM research_responses rr
     LEFT JOIN users u ON u.id = rr.user_id
     WHERE rr.form_id = ? ORDER BY rr.submitted_at DESC"
);
$rStmt->execute([$formId]);
$responses = $rStmt->fetchAll();

$aStmt = $pdo->prepare('SELECT * FROM research_answers WHERE response_id = ?');
foreach ($responses as &$r) {
    $aStmt->execute([$r['id']]);
    $answers = [];
    foreach ($aStmt->fetchAll() as $a) {
        $answers[$a['question_id']] = $a['answer_text'];
    }
    $r['answers'] = $answers;
}
unset($r);

// Export CSV
if (($_GET['export'] ?? '') === 'csv') {
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="hasil_penelitian_' . slugify($form['title']) . '.csv"');
    $out = fopen('php://output', 'w');
    fprintf($out, chr(0xEF) . chr(0xBB) . chr(0xBF)); // UTF-8 BOM for Excel
    $header = ['timestamp', 'respondent'];
    foreach ($questions as $q) $header[] = $q['question_text'];
    fputcsv($out, $header);
    foreach ($responses as $r) {
        $row = [$r['submitted_at'], $r['full_name'] ?? 'Anonim'];
        foreach ($questions as $q) $row[] = $r['answers'][$q['id']] ?? '';
        fputcsv($out, $row);
    }
    fclose($out);
    exit;
}

$pageTitle = 'Hasil — ' . $form['title'];
require_once __DIR__ . '/../includes/header.php';
require_once __DIR__ . '/../includes/navbar.php';
?>
<div class="app-layout">
  <?php require_once __DIR__ . '/../includes/sidebar.php'; ?>
  <main class="app-content">
    <nav aria-label="breadcrumb" class="mb-2"><ol class="breadcrumb small"><li class="breadcrumb-item"><a href="research.php">Penelitian</a></li><li class="breadcrumb-item active"><?= e($form['title']) ?></li></ol></nav>
    <div class="d-flex justify-content-between align-items-center mb-3 flex-wrap gap-2">
      <h4 class="fw-bold mb-0">Hasil: <?= e($form['title']) ?> (<?= count($responses) ?> responden)</h4>
      <a href="?form_id=<?= $formId ?>&export=csv" class="btn btn-success btn-sm"><i class="bi bi-download me-1"></i>Export CSV</a>
    </div>

    <?php if (empty($responses)): ?>
      <div class="card"><div class="card-body text-center text-muted py-5">Belum ada responden.</div></div>
    <?php else: ?>
      <div class="card">
        <div class="table-responsive">
          <table class="table table-hover align-middle mb-0">
            <thead class="table-light">
              <tr>
                <th>Waktu</th><th>Responden</th>
                <?php foreach ($questions as $q): ?><th><?= e(mb_strimwidth($q['question_text'], 0, 30, '...')) ?></th><?php endforeach; ?>
              </tr>
            </thead>
            <tbody>
              <?php foreach ($responses as $r): ?>
                <tr>
                  <td class="small"><?= date('d M Y H:i', strtotime($r['submitted_at'])) ?></td>
                  <td class="small"><?= e($r['full_name'] ?? 'Anonim') ?></td>
                  <?php foreach ($questions as $q): ?>
                    <td class="small"><?= e($r['answers'][$q['id']] ?? '-') ?></td>
                  <?php endforeach; ?>
                </tr>
              <?php endforeach; ?>
            </tbody>
          </table>
        </div>
      </div>
    <?php endif; ?>
  </main>
</div>
<?php require_once __DIR__ . '/../includes/footer.php'; ?>
