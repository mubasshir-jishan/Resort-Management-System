<?php
require_once 'session_check.php';
require_once 'db_connect.php';

$total = db_fetch_one($conn, "SELECT COUNT(*) AS n FROM audit_log")['n'];
$filter_table  = clean($conn, $_GET['table'] ?? '');
$filter_action = clean($conn, $_GET['action'] ?? '');
$where = "WHERE 1=1";
if ($filter_table)  $where .= " AND table_name='$filter_table'";
if ($filter_action) $where .= " AND action_type='$filter_action'";

$logs = db_fetch_all($conn,
    "SELECT *, DATE_FORMAT(action_time,'%d-%b-%Y %H:%i:%s') AS fmt_time
     FROM audit_log $where ORDER BY log_id DESC LIMIT 150");
?>
<!DOCTYPE html>
<html lang="en">
<head><meta charset="UTF-8"><title>Audit Log — Resort Management</title><link rel="stylesheet" href="style.css"></head>
<body>
<div class="layout">
    <?php include 'sidebar.php'; ?>
    <div class="main">
        <div class="topbar"><h1>Audit Log</h1><div class="admin-info"><a href="logout.php" style="color:#e53e3e;">Logout</a></div></div>
        <div class="content">

            <div class="card">
                <div class="card-header"><h3>Trigger Log (last 150) <span class="db-badge">Written by: trg_after_booking_insert · trg_after_booking_update · trg_after_booking_delete · trg_after_guest_update · trg_room_price_change_log</span></h3></div>
                <div class="card-body">
                    <form method="GET" class="filter-bar">
                        <select name="table">
                            <option value="">All Tables</option>
                            <?php foreach(['bookings','guests','rooms'] as $t): ?>
                            <option <?= $filter_table===$t?'selected':'' ?>><?= $t ?></option>
                            <?php endforeach; ?>
                        </select>
                        <select name="action">
                            <option value="">All Actions</option>
                            <?php foreach(['INSERT','UPDATE','DELETE','DISCOUNT'] as $a): ?>
                            <option <?= $filter_action===$a?'selected':'' ?>><?= $a ?></option>
                            <?php endforeach; ?>
                        </select>
                        <button class="btn btn-primary btn-sm">Filter</button>
                        <a href="audit_log.php" class="btn btn-secondary btn-sm">Reset</a>
                    </form>
                    <div class="table-wrap">
                    <table>
                        <thead><tr><th>ID</th><th>Table</th><th>Action</th><th>Record ID</th><th>OLD Value (before)</th><th>NEW Value (after)</th><th>Time</th></tr></thead>
                        <tbody>
                        <?php if(empty($logs)): ?>
                            <tr><td colspan="7" style="text-align:center;color:#718096;">No log entries yet. Create, update, or delete a booking to see triggers in action.</td></tr>
                        <?php else: foreach($logs as $l): ?>
                        <tr>
                            <td><?= $l['log_id'] ?></td>
                            <td><span class="badge badge-info"><?= htmlspecialchars($l['table_name']) ?></span></td>
                            <td><?php
                                $ac=['INSERT'=>'badge-success','UPDATE'=>'badge-warning','DELETE'=>'badge-danger','DISCOUNT'=>'badge-info'];
                                echo '<span class="badge '.($ac[$l['action_type']]??'badge-gray').'">'.htmlspecialchars($l['action_type']).'</span>';
                            ?></td>
                            <td><?= $l['record_id'] ?></td>
                            <td style="font-family:monospace;font-size:11px;color:#718096;"><?= htmlspecialchars($l['old_value']??'—') ?></td>
                            <td style="font-family:monospace;font-size:11px;color:#276749;"><?= htmlspecialchars($l['new_value']??'—') ?></td>
                            <td style="white-space:nowrap;font-size:12px;"><?= $l['fmt_time'] ?></td>
                        </tr>
                        <?php endforeach; endif; ?>
                        </tbody>
                    </table>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>
</body>
</html>
