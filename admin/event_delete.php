<?php
require_once __DIR__ . '/../config/config.php';
requireRole('admin');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    redirect('admin/events.php');
}

verifyCsrf();

$id = (int) ($_POST['id'] ?? 0);
$pdo = getDBConnection();

$stmt = $pdo->prepare('SELECT title FROM events WHERE id = ?');
$stmt->execute([$id]);
$event = $stmt->fetch();

if ($event) {
    $del = $pdo->prepare('DELETE FROM events WHERE id = ?');
    $del->execute([$id]);
    logActivity((int) $_SESSION['user_id'], 'delete_event', "Menghapus kegiatan: {$event['title']}");
    setFlash('success', 'Kegiatan berhasil dihapus.');
} else {
    setFlash('danger', 'Kegiatan tidak ditemukan.');
}

redirect('admin/events.php');
