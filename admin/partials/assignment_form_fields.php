<div class="mb-3">
  <label class="form-label">Judul Tugas <span class="text-danger">*</span></label>
  <input type="text" name="title" class="form-control" required>
</div>
<div class="mb-3">
  <label class="form-label">Instruksi Tugas</label>
  <textarea name="instructions" class="form-control" rows="4" placeholder="Contoh: Buat sebuah script video pembelajaran menggunakan bantuan AI."></textarea>
</div>
<div class="row g-3">
  <div class="col-md-6">
    <label class="form-label">Tanggal Mulai (opsional)</label>
    <input type="datetime-local" name="start_date" class="form-control">
  </div>
  <div class="col-md-6">
    <label class="form-label">Deadline</label>
    <input type="datetime-local" name="deadline" class="form-control">
  </div>
  <div class="col-md-6">
    <label class="form-label">Status</label>
    <select name="status" class="form-select">
      <option value="draft">Draft</option>
      <option value="active">Aktif</option>
      <option value="closed">Ditutup</option>
    </select>
  </div>
  <div class="col-md-6">
    <label class="form-label">Bobot Progress (% dari total hari ini)</label>
    <input type="number" name="weight_percent" min="0" max="100" step="0.5" class="form-control" value="0">
  </div>
  <div class="col-md-6 d-flex align-items-end">
    <div class="form-check">
      <input class="form-check-input" type="checkbox" name="allow_late" id="allowLateCheck">
      <label class="form-check-label" for="allowLateCheck">Izinkan pengumpulan terlambat</label>
    </div>
  </div>
</div>
<div class="form-text mt-2">Format file yang diizinkan untuk peserta: PDF, DOC, DOCX, PPT, PPTX, JPG, PNG, ZIP (maks. 5MB).</div>
