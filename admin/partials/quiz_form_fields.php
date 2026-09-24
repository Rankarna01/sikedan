<div class="mb-3">
  <label class="form-label">Judul Quiz <span class="text-danger">*</span></label>
  <input type="text" name="title" class="form-control" required>
</div>
<div class="mb-3">
  <label class="form-label">Deskripsi</label>
  <textarea name="description" class="form-control" rows="2"></textarea>
</div>
<div class="row g-3">
  <div class="col-md-4">
    <label class="form-label">Durasi (menit)</label>
    <input type="number" name="duration_minutes" min="1" class="form-control" value="20">
  </div>
  <div class="col-md-4">
    <label class="form-label">Passing Grade</label>
    <input type="number" name="passing_grade" min="0" max="100" class="form-control" value="70">
  </div>
  <div class="col-md-4">
    <label class="form-label">Batas Percobaan</label>
    <input type="number" name="max_attempts" min="1" class="form-control" value="1">
  </div>
  <div class="col-md-6">
    <label class="form-label">Waktu Mulai (opsional)</label>
    <input type="datetime-local" name="start_time" class="form-control">
  </div>
  <div class="col-md-6">
    <label class="form-label">Waktu Selesai (opsional)</label>
    <input type="datetime-local" name="end_time" class="form-control">
  </div>
  <div class="col-md-6">
    <label class="form-label">Status</label>
    <select name="status" class="form-select">
      <option value="draft">Draft</option>
      <option value="active">Aktif</option>
      <option value="inactive">Nonaktif</option>
    </select>
  </div>
  <div class="col-md-6">
    <label class="form-label">Bobot Progress (% dari total hari ini)</label>
    <input type="number" name="weight_percent" min="0" max="100" step="0.5" class="form-control" value="0">
  </div>
  <div class="col-md-6 d-flex align-items-end gap-3">
    <div class="form-check">
      <input class="form-check-input" type="checkbox" name="random_question" id="randQ<?= $dayId ?>">
      <label class="form-check-label small" for="randQ<?= $dayId ?>">Acak Soal</label>
    </div>
    <div class="form-check">
      <input class="form-check-input" type="checkbox" name="random_option" id="randO<?= $dayId ?>">
      <label class="form-check-label small" for="randO<?= $dayId ?>">Acak Pilihan</label>
    </div>
  </div>
</div>
<div class="form-text mt-2">Catatan: sistem memberikan mitigasi kecurangan (timer, acak soal/pilihan, batas percobaan), bukan jaminan 100% anti-cheating.</div>
