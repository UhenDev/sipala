<?php
require_once __DIR__ . '/includes/admin.php';
if(($_SESSION['role']??'')!=='admin'){flash('error','Halaman ini hanya dapat diakses admin.');redirect('dashboard.php');}
$rows=$pdo->query('SELECT l.*,u.nama FROM log_aktivitas l LEFT JOIN users u ON u.id=l.user_id ORDER BY l.id DESC LIMIT 200')->fetchAll();
page_start('Log Aktivitas','Riwayat aktivitas pengguna di SIPALA','aktivitas');
?><div class="head"><div><h2>Log Aktivitas</h2><p>Catatan login, transaksi, persetujuan dan perubahan penting.</p></div></div><div class="panel"><div class="table-wrap"><table><thead><tr><th>Waktu</th><th>Pengguna</th><th>Aktivitas</th><th>Deskripsi</th><th>IP</th></tr></thead><tbody><?php foreach($rows as $r):?><tr><td><?=e(date('d-m-Y H:i:s',strtotime($r['created_at'])))?></td><td><?=e($r['nama']??'Pengguna dihapus')?></td><td><b><?=e($r['aktivitas'])?></b></td><td><?=e($r['deskripsi'])?></td><td><?=e($r['ip_address']??'-')?></td></tr><?php endforeach;?></tbody></table></div></div><?php page_end(); ?>
