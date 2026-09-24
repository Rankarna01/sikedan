<?php
require_once __DIR__ . '/../config/config.php';
requireRole('admin');

$pdo = getDBConnection();
$quizId = (int) ($_GET['quiz_id'] ?? 0);

$stmt = $pdo->prepare(
    "SELECT qz.*, ed.day_number, e.id AS event_id, e.title AS event_title
     FROM quizzes qz JOIN event_days ed ON ed.id = qz.event_day_id JOIN events e ON e.id = ed.event_id
     WHERE qz.id = ?"
);
$stmt->execute([$quizId]);
$quiz = $stmt->fetch();

if (!$quiz) {
    setFlash('danger', 'Quiz tidak ditemukan.');
    redirect('admin/events.php');
}

$pageTitle = 'Tambah Soal — ' . $quiz['title'];
require_once __DIR__ . '/../includes/header.php';
require_once __DIR__ . '/../includes/navbar.php';
?>
<div class="app-layout">
  <?php require_once __DIR__ . '/../includes/sidebar.php'; ?>
  <main class="app-content">
    <nav aria-label="breadcrumb" class="mb-2">
      <ol class="breadcrumb small">
        <li class="breadcrumb-item"><a href="event_dashboard.php?id=<?= $quiz['event_id'] ?>"><?= e($quiz['event_title']) ?></a></li>
        <li class="breadcrumb-item"><a href="quiz_questions.php?quiz_id=<?= $quizId ?>"><?= e($quiz['title']) ?></a></li>
        <li class="breadcrumb-item active">Tambah Soal</li>
      </ol>
    </nav>
    <div class="d-flex justify-content-between align-items-center mb-3 flex-wrap gap-2">
      <h4 class="fw-bold mb-0">Tambah Soal — <?= e($quiz['title']) ?></h4>
      <span class="text-muted small">Tambahkan sebanyak mungkin soal, lalu simpan sekali di akhir.</span>
    </div>

    <form method="post" action="quiz_questions.php?quiz_id=<?= $quizId ?>" id="bulkQuestionForm">
      <?= csrfField() ?>
      <input type="hidden" name="action" value="add_question">
      <input type="hidden" name="quiz_id" value="<?= $quizId ?>">

      <div id="questionsContainer"></div>

      <div class="d-flex gap-2 mb-4">
        <button type="button" id="addRowBtn" class="btn btn-outline-primary"><i class="bi bi-plus-lg me-1"></i>Tambah Soal Lagi</button>
        <span class="text-muted small align-self-center" id="questionCounter">0 soal ditambahkan</span>
      </div>

      <div class="d-flex gap-2 sticky-bottom bg-white py-3 border-top">
        <button type="submit" class="btn btn-primary"><i class="bi bi-check-lg me-1"></i>Simpan Semua Soal</button>
        <a href="quiz_questions.php?quiz_id=<?= $quizId ?>" class="btn btn-outline-secondary">Batal</a>
      </div>
    </form>
  </main>
</div>

<!-- Template satu baris soal (disalin via JS, tidak ikut terkirim karena berada di <template>) -->
<template id="questionRowTemplate">
  <div class="card mb-3 question-row">
    <div class="card-body">
      <div class="d-flex justify-content-between align-items-start mb-2">
        <span class="badge bg-secondary question-index-badge">Soal #</span>
        <button type="button" class="btn btn-sm btn-outline-danger remove-row-btn"><i class="bi bi-trash"></i></button>
      </div>
      <div class="mb-3">
        <label class="form-label">Pertanyaan <span class="text-danger">*</span></label>
        <textarea class="form-control question-text-input" rows="2" placeholder="Tulis pertanyaan di sini..."></textarea>
      </div>
      <div class="row g-3 mb-3">
        <div class="col-md-8">
          <label class="form-label">Tipe Soal</label>
          <select class="form-select question-type-select">
            <option value="multiple_choice">Pilihan Ganda</option>
            <option value="multiple_answer">Multiple Answer</option>
            <option value="true_false">Benar / Salah</option>
            <option value="essay">Essay</option>
          </select>
        </div>
        <div class="col-md-4">
          <label class="form-label">Skor</label>
          <input type="number" class="form-control question-score-input" min="0" value="10">
        </div>
      </div>

      <div class="mc-options-wrap">
        <label class="form-label">Pilihan Jawaban</label>
        <div class="options-container">
          <!-- 4 opsi default di-generate via JS -->
        </div>
        <div class="form-text mb-2">Centang kotak di kiri untuk menandai jawaban benar. Pilihan Ganda: centang satu saja. Multiple Answer: boleh lebih dari satu.</div>
      </div>

      <div class="tf-options-wrap d-none mb-2">
        <label class="form-label">Jawaban Benar</label>
        <select class="form-select correct-tf-select">
          <option value="true">Benar</option>
          <option value="false">Salah</option>
        </select>
      </div>

      <div class="essay-wrap d-none">
        <p class="small text-muted mb-0"><i class="bi bi-info-circle"></i> Soal essay akan dijawab bebas oleh peserta dan dinilai manual oleh admin.</p>
      </div>
    </div>
  </div>
</template>

<script>
(function () {
  var container = document.getElementById('questionsContainer');
  var template = document.getElementById('questionRowTemplate');
  var counter = 0;

  function createOptionRow(rowIndex, optIndex) {
    var div = document.createElement('div');
    div.className = 'input-group mb-2';
    div.innerHTML =
      '<span class="input-group-text"><input type="checkbox" class="form-check-input mt-0 option-correct-cb" title="Tandai sebagai jawaban benar"></span>' +
      '<input type="text" class="form-control option-text-input" placeholder="Pilihan ' + String.fromCharCode(65 + optIndex) + '">';
    return div;
  }

  function addQuestionRow() {
    counter++;
    var clone = template.content.cloneNode(true);
    var rowEl = clone.querySelector('.question-row');
    rowEl.dataset.rowIndex = counter;
    clone.querySelector('.question-index-badge').textContent = 'Soal #' + counter;

    var optionsContainer = clone.querySelector('.options-container');
    for (var i = 0; i < 4; i++) {
      optionsContainer.appendChild(createOptionRow(counter, i));
    }

    var typeSelect = clone.querySelector('.question-type-select');
    typeSelect.addEventListener('change', function () {
      var type = this.value;
      var card = this.closest('.question-row');
      card.querySelector('.mc-options-wrap').classList.toggle('d-none', !(type === 'multiple_choice' || type === 'multiple_answer'));
      card.querySelector('.tf-options-wrap').classList.toggle('d-none', type !== 'true_false');
      card.querySelector('.essay-wrap').classList.toggle('d-none', type !== 'essay');
      card.querySelectorAll('.option-correct-cb').forEach(function (cb) {
        cb.type = (type === 'multiple_choice') ? 'radio' : 'checkbox';
        cb.name = 'correct_group_' + card.dataset.rowIndex; // group radios per row
      });
    });

    clone.querySelector('.remove-row-btn').addEventListener('click', function () {
      this.closest('.question-row').remove();
      updateCounter();
    });

    container.appendChild(clone);
    updateCounter();
  }

  function updateCounter() {
    var count = container.querySelectorAll('.question-row').length;
    document.getElementById('questionCounter').textContent = count + ' soal ditambahkan';
  }

  document.getElementById('addRowBtn').addEventListener('click', addQuestionRow);

  // Mulai dengan 3 baris soal kosong agar admin langsung bisa mengetik
  addQuestionRow(); addQuestionRow(); addQuestionRow();

  // Rakit semua data menjadi field array tersembunyi sebelum submit,
  // karena elemen di dalam <template> tidak otomatis ikut ter-submit.
  document.getElementById('bulkQuestionForm').addEventListener('submit', function (e) {
    var rows = container.querySelectorAll('.question-row');
    var hiddenWrap = document.createElement('div');
    hiddenWrap.style.display = 'none';
    var validCount = 0;

    rows.forEach(function (row, idx) {
      var text = row.querySelector('.question-text-input').value.trim();
      if (text === '') return; // lewati baris kosong
      validCount++;

      var type = row.querySelector('.question-type-select').value;
      var score = row.querySelector('.question-score-input').value || 10;

      function addHidden(name, value) {
        var input = document.createElement('input');
        input.type = 'hidden';
        input.name = name;
        input.value = value;
        hiddenWrap.appendChild(input);
      }

      addHidden('questions[' + idx + '][text]', text);
      addHidden('questions[' + idx + '][type]', type);
      addHidden('questions[' + idx + '][score]', score);

      if (type === 'multiple_choice' || type === 'multiple_answer') {
        row.querySelectorAll('.option-text-input').forEach(function (optInput, optIdx) {
          addHidden('questions[' + idx + '][options][' + optIdx + ']', optInput.value.trim());
        });
        row.querySelectorAll('.option-correct-cb').forEach(function (cb, optIdx) {
          if (cb.checked) addHidden('questions[' + idx + '][correct][]', optIdx);
        });
      } else if (type === 'true_false') {
        addHidden('questions[' + idx + '][correct_tf]', row.querySelector('.correct-tf-select').value);
      }
    });

    if (validCount === 0) {
      e.preventDefault();
      alert('Isi minimal satu pertanyaan sebelum menyimpan.');
      return;
    }

    this.appendChild(hiddenWrap);
  });
})();
</script>
<?php require_once __DIR__ . '/../includes/footer.php'; ?>
