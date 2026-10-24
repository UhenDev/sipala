<?php
require_once __DIR__ . '/../includes/admin.php';
require_petugas();
$id=(int)($_GET['id']??$_POST['id']??0);
if($id<=0) redirect('index.php');
if($_SERVER['REQUEST_METHOD']==='POST'){
    try{
        verify_csrf(); $pdo->beginTransaction();
        $q=$pdo->prepare('SELECT p.*,u.nama AS nama_peminjam FROM peminjaman p JOIN users u ON u.id=p.user_id WHERE p.id=? FOR UPDATE');$q->execute([$id]);$p=$q->fetch();
        if(!$p || !in_array($p['status'],['DIPINJAM','MENUNGGU_PENGEMBALIAN'],true)) throw new RuntimeException('Peminjaman tidak aktif atau sudah diproses.');
        $d=$pdo->prepare('SELECT d.id,d.peralatan_id,d.jumlah,pr.nama_barang FROM detail_peminjaman d JOIN peralatan pr ON pr.id=d.peralatan_id WHERE d.peminjaman_id=? ORDER BY d.id');$d->execute([$id]);$items=$d->fetchAll();
        $conditions=$_POST['kondisi']??[];$notes=$_POST['catatan_item']??[];
        $totalReturned=0;
        foreach($items as $it){
            $condition=(string)($conditions[$it['id']]??'Baik'); if(!in_array($condition,['Baik','Rusak Ringan','Rusak Berat'],true)) throw new RuntimeException('Kondisi alat tidak valid.');
            $note=trim((string)($notes[$it['id']]??''));
            $u=$pdo->prepare('UPDATE detail_peminjaman SET kondisi_akhir=?,catatan_kondisi=? WHERE id=?');$u->execute([$condition,$note!==''?$note:null,$it['id']]);
            if($condition!=='Rusak Berat'){$st=$pdo->prepare('UPDATE peralatan SET jumlah_tersedia=LEAST(jumlah,jumlah_tersedia+?) WHERE id=?');$st->execute([(int)$it['jumlah'],(int)$it['peralatan_id']]);}
            $totalReturned+=(int)$it['jumlah'];
        }
        $due=new DateTime($p['tanggal_rencana_kembali']);$today=new DateTime(date('Y-m-d'));$late=max(0,(int)$today->diff($due)->format('%r%a')*-1);
        $set=$pdo->prepare("SELECT nilai FROM pengaturan WHERE nama='tarif_denda_harian' LIMIT 1");$set->execute();$rate=(float)($set->fetchColumn()?:0);$denda=$late*$rate;
        $ins=$pdo->prepare('INSERT INTO pengembalian (peminjaman_id,tanggal_pengembalian,terlambat_hari,denda,kondisi_barang,catatan,diproses_oleh) VALUES (?,CURDATE(),?,?,?,?,?)');
        $overall='Baik'; foreach($conditions as $c){if($c==='Rusak Berat'){$overall='Rusak Berat';break;}if($c==='Rusak Ringan')$overall='Rusak Ringan';}
        $catatan=trim((string)($_POST['catatan']??'')); $ins->execute([$id,$late,$denda,$overall,$catatan!==''?$catatan:null,(int)$_SESSION['user_id']]);
        $up=$pdo->prepare('UPDATE peminjaman SET status="SELESAI",tanggal_realisasi_kembali=CURDATE() WHERE id=?');$up->execute([$id]);
        log_activity('PENGEMBALIAN_ALAT','Memproses pengembalian '.$p['kode_peminjaman'].' dari '.$p['nama_peminjam'].'. Denda Rp '.number_format($denda,0,',','.'));
        $pdo->commit(); flash('success','Pengembalian berhasil diproses. Denda: Rp '.number_format($denda,0,',','.').'.'); redirect('index.php');
    }catch(Throwable $e){if($pdo->inTransaction())$pdo->rollBack();flash('error',$e->getMessage());}
}
$q=$pdo->prepare('SELECT p.*,u.nama AS nama_peminjam,u.nis,u.kelas FROM peminjaman p JOIN users u ON u.id=p.user_id WHERE p.id=?');$q->execute([$id]);$p=$q->fetch();if(!$p)redirect('index.php');
$q=$pdo->prepare('SELECT d.id,d.jumlah,pr.kode_barang,pr.nama_barang FROM detail_peminjaman d JOIN peralatan pr ON pr.id=d.peralatan_id WHERE d.peminjaman_id=? ORDER BY d.id');$q->execute([$id]);$items=$q->fetchAll();
$set=$pdo->query("SELECT nilai FROM pengaturan WHERE nama='tarif_denda_harian' LIMIT 1")->fetchColumn();$rate=(float)($set?:0);$due=new DateTime($p['tanggal_rencana_kembali']);$today=new DateTime(date('Y-m-d'));$late=max(0,(int)$today->diff($due)->format('%r%a')*-1);$estimated=$late*$rate;
page_start('Proses Pengembalian','Periksa kondisi setiap alat sebelum stok dikembalikan','pengembalian');
?>
<div class="head"><div><h2>Pengembalian <?=e($p['kode_peminjaman'])?></h2><p><?=e($p['nama_peminjam'])?> · <?=e($p['nis']??'-')?> · <?=e($p['kelas']??'-')?></p></div><a class="btn" href="index.php">Kembali</a></div>
<div class="panel" style="padding:18px 22px"><strong>Batas kembali:</strong> <?=e(date('d-m-Y',strtotime($p['tanggal_rencana_kembali'])))?> · <strong>Terlambat:</strong> <?=e((string)$late)?> hari · <strong>Estimasi denda:</strong> Rp <?=e(number_format($estimated,0,',','.'))?> <span style="color:#64748b">(tarif Rp <?=e(number_format($rate,0,',','.'))?>/hari)</span></div>
<form method="post"><input type="hidden" name="id" value="<?=$id?>"><?=csrf_field()?><div class="panel"><div class="panel-title">Kondisi Setiap Peralatan</div><div class="table-wrap"><table><thead><tr><th>Alat</th><th>Jumlah</th><th>Kondisi Akhir</th><th>Catatan Item</th></tr></thead><tbody><?php foreach($items as $it):?><tr><td><strong><?=e($it['nama_barang'])?></strong><div style="font-size:12px;color:#64748b"><?=e($it['kode_barang'])?></div></td><td><?=$it['jumlah']?> unit</td><td><select name="kondisi[<?=$it['id']?>]"><option>Baik</option><option>Rusak Ringan</option><option>Rusak Berat</option></select></td><td><input name="catatan_item[<?=$it['id']?>]" placeholder="Catatan kondisi (opsional)" style="width:100%;padding:9px;border:1px solid #cbd5e1;border-radius:7px"></td></tr><?php endforeach;?></tbody></table></div></div><div class="form-panel"><div class="field full"><label>Catatan Pengembalian</label><textarea name="catatan" rows="3" placeholder="Catatan umum pengembalian..."></textarea></div><div class="form-actions"><a class="btn" href="index.php">Batal</a><button class="btn btn-primary" type="submit" onclick="return confirm('Proses pengembalian ini?')">✅ Konfirmasi Pengembalian</button></div></div></form>
<?php page_end(); ?>
