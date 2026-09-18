<?php
declare(strict_types=1);
require_once __DIR__ . '/../includes/admin.php';

$id = (int)($_GET['edit'] ?? $_GET['id'] ?? 0);
if ($id > 0) {
    redirect('edit.php?id=' . $id);
}
redirect('index.php');