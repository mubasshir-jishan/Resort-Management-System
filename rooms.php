<?php
require_once 'session_check.php';
require_once 'db_connect.php';
$msg = '';

if (isset($_GET['delete'])) {
    $id = (int)$_GET['delete'];
    $check = db_fetch_one($conn, "SELECT COUNT(*) AS n FROM bookings WHERE room_id=$id");
    if ($check['n'] > 0) {
        $msg = '<div class="alert alert-danger">Cannot delete: room has booking history. (FOREIGN KEY enforced by DB.)</div>';
    } else {
        mysqli_query($conn, "DELETE FROM rooms WHERE room_id=$id");
        $msg = '<div class="alert alert-success">Room deleted.</div>';
    }
}

if ($_SERVER['REQUEST_METHOD']==='POST' && ($_POST['action']??'')==='add') {
    $rnum  = clean($conn, $_POST['room_number']);
    $rtype = clean($conn, $_POST['room_type']);
    $price = (float)$_POST['price'];
    $floor = (int)$_POST['floor_no'];
    $desc  = clean($conn, $_POST['description']);
    if (!$rnum || !$rtype || $price <= 0) {
        $msg = '<div class="alert alert-danger">Room number, type and price are required.</div>';
    } else {
        $dup = db_fetch_one($conn, "SELECT COUNT(*) AS n FROM rooms WHERE room_number='$rnum'");
        if ($dup['n'] > 0) {
            $msg = '<div class="alert alert-danger">Room number already exists. (UNIQUE constraint.)</div>';
        } else {
            if (mysqli_query($conn, "INSERT INTO rooms (room_number,room_type,price,floor_no,description) VALUES ('$rnum','$rtype',$price,$floor,'$desc')")) {
                $msg = '<div class="alert alert-success">✅ Room added.</div>';
            } else {
                $msg = '<div class="alert alert-danger">'.mysqli_error($conn).'</div>';
            }
        }
    }
}

if ($_SERVER['REQUEST_METHOD']==='POST' && ($_POST['action']??'')==='edit') {
    $id     = (int)$_POST['room_id'];
    $rtype  = clean($conn, $_POST['room_type']);
    $price  = (float)$_POST['price'];
    $status = clean($conn, $_POST['status']);
    $floor  = (int)$_POST['floor_no'];
    $desc   = clean($conn, $_POST['description']);
    if (mysqli_query($conn, "UPDATE rooms SET room_type='$rtype',price=$price,status='$status',floor_no=$floor,description='$desc' WHERE room_id=$id")) {
        $msg = '<div class="alert alert-success">✅ Room updated. <strong>Trigger trg_room_price_change_log</strong> logged the change if price or status changed.</div>';
    } else {
        $msg = '<div class="alert alert-danger">'.mysqli_error($conn).'</div>';
    }
}

$search = clean($conn, $_GET['search']??'');
$ft     = clean($conn, $_GET['type']??'');
$fs     = clean($conn, $_GET['status']??'');
$where  = "WHERE 1=1";
if ($search) $where .= " AND (room_number LIKE '%$search%' OR description LIKE '%$search%')";
if ($ft)     $where .= " AND room_type='$ft'";
if ($fs)     $where .= " AND status='$fs'";

// String functions used live: UPPER, ROUND
$rooms = db_fetch_all($conn,
    "SELECT *, UPPER(room_type) AS room_type_up, ROUND(price,2) AS price_r FROM rooms $where ORDER BY room_number");

// Occupancy from VIEW
$occupancy = db_fetch_all($conn, "SELECT * FROM room_occupancy_report ORDER BY room_type");

// Room search — uses LIKE + WHERE + BETWEEN in a real filter
$edit_room = null;
if (isset($_GET['edit'])) {
    $edit_room = db_fetch_one($conn, "SELECT * FROM rooms WHERE room_id=".(int)$_GET['edit']);
}
?>
<!DOCTYPE html>
<html lang="en">
<head><meta charset="UTF-8"><title>Rooms — Resort Management</title><link rel="stylesheet" href="style.css"></head>
<body>
<div class="layout">
    <?php include 'sidebar.php'; ?>
    <div class="main">
        <div class="topbar"><h1>Rooms</h1><div class="admin-info"><a href="logout.php" style="color:#e53e3e;">Logout</a></div></div>
        <div class="content">
            <?= $msg ?>

            <!-- Occupancy summary from VIEW -->
            <div class="card">
                <div class="card-header"><h3>Occupancy Summary <span class="db-badge">room_occupancy_report VIEW — GROUP BY + CASE + AVG</span></h3></div>
                <div class="card-body">
                    <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(200px,1fr));gap:14px;">
                    <?php foreach($occupancy as $o): ?>
                    <div class="stat-card" style="border-left-color:<?= $o['occupancy_pct']>50?'#e53e3e':'#38a169' ?>;">
                        <div class="stat-number" style="font-size:18px;"><?= $o['room_type'] ?></div>
                        <div style="font-size:13px;color:#718096;margin-top:4px;">
                            <?= $o['total_rooms'] ?> rooms · ৳<?= number_format($o['avg_price'],0) ?>/night
                        </div>
                        <div style="margin-top:8px;">
                            <span class="badge badge-success"><?= $o['available'] ?> avail</span>
                            <span class="badge badge-danger"><?= $o['occupied'] ?> occupied</span>
                            <span class="badge badge-warning"><?= $o['maintenance'] ?> maint</span>
                        </div>
                        <div style="margin-top:8px;background:#e2e8f0;border-radius:4px;height:8px;">
                            <div style="background:#e53e3e;height:8px;border-radius:4px;width:<?= min($o['occupancy_pct'],100) ?>%;"></div>
                        </div>
                        <div style="font-size:12px;color:#718096;margin-top:2px;"><?= $o['occupancy_pct'] ?>% occupied</div>
                    </div>
                    <?php endforeach; ?>
                    </div>
                </div>
            </div>

            <!-- Add Room -->
            <div class="card">
                <div class="card-header"><h3>Add New Room</h3></div>
                <div class="card-body">
                    <form method="POST">
                        <input type="hidden" name="action" value="add">
                        <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(160px,1fr));gap:14px;">
                            <div class="form-group"><label>Room Number *</label><input type="text" name="room_number" required placeholder="103"></div>
                            <div class="form-group"><label>Room Type *</label>
                                <select name="room_type" required>
                                    <?php foreach(['Standard','Deluxe','Suite','Presidential'] as $t): ?>
                                    <option><?= $t ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div class="form-group"><label>Price (৳/night) *</label><input type="number" name="price" step="0.01" required placeholder="2500"></div>
                            <div class="form-group"><label>Floor</label><input type="number" name="floor_no" value="1" min="1"></div>
                            <div class="form-group"><label>Description</label><input type="text" name="description" placeholder="Optional"></div>
                        </div>
                        <button type="submit" class="btn btn-success">Add Room</button>
                    </form>
                </div>
            </div>

            <?php if($edit_room): ?>
            <div class="card">
                <div class="card-header"><h3>Edit Room <?= htmlspecialchars($edit_room['room_number']) ?> <span class="db-badge">UPDATE → trg_room_price_change_log fires</span></h3></div>
                <div class="card-body">
                    <form method="POST">
                        <input type="hidden" name="action" value="edit">
                        <input type="hidden" name="room_id" value="<?= $edit_room['room_id'] ?>">
                        <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(160px,1fr));gap:14px;">
                            <div class="form-group"><label>Room Type</label>
                                <select name="room_type">
                                    <?php foreach(['Standard','Deluxe','Suite','Presidential'] as $t): ?>
                                    <option <?= $edit_room['room_type']===$t?'selected':'' ?>><?= $t ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div class="form-group"><label>Price</label><input type="number" name="price" step="0.01" value="<?= $edit_room['price'] ?>"></div>
                            <div class="form-group"><label>Status</label>
                                <select name="status">
                                    <?php foreach(['available','occupied','maintenance'] as $s): ?>
                                    <option <?= $edit_room['status']===$s?'selected':'' ?>><?= $s ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div class="form-group"><label>Floor</label><input type="number" name="floor_no" value="<?= $edit_room['floor_no'] ?>"></div>
                            <div class="form-group"><label>Description</label><input type="text" name="description" value="<?= htmlspecialchars($edit_room['description']??'') ?>"></div>
                        </div>
                        <button type="submit" class="btn btn-warning">Update Room</button>
                        <a href="rooms.php" class="btn btn-secondary">Cancel</a>
                    </form>
                </div>
            </div>
            <?php endif; ?>

            <!-- Room list with UPPER() and ROUND() applied live -->
            <div class="card">
                <div class="card-header"><h3>All Rooms (<?= count($rooms) ?>) <span class="db-badge">UPPER(room_type) + ROUND(price,2) in live query</span></h3></div>
                <div class="card-body">
                    <form method="GET" class="filter-bar">
                        <input type="text" name="search" placeholder="Search room..." value="<?= htmlspecialchars($search) ?>">
                        <select name="type">
                            <option value="">All Types</option>
                            <?php foreach(['Standard','Deluxe','Suite','Presidential'] as $t): ?>
                            <option <?= $ft===$t?'selected':'' ?>><?= $t ?></option>
                            <?php endforeach; ?>
                        </select>
                        <select name="status">
                            <option value="">All Status</option>
                            <?php foreach(['available','occupied','maintenance'] as $s): ?>
                            <option <?= $fs===$s?'selected':'' ?>><?= $s ?></option>
                            <?php endforeach; ?>
                        </select>
                        <button class="btn btn-primary btn-sm">Filter</button>
                        <a href="rooms.php" class="btn btn-secondary btn-sm">Reset</a>
                    </form>
                    <div class="table-wrap">
                    <table>
                        <thead><tr><th>#</th><th>Room No.</th><th>Type (UPPER)</th><th>Price/Night (ROUND)</th><th>Floor</th><th>Status</th><th>Description</th><th>Actions</th></tr></thead>
                        <tbody>
                        <?php $i=1; foreach($rooms as $r): ?>
                        <tr>
                            <td><?= $i++ ?></td>
                            <td><strong><?= htmlspecialchars($r['room_number']) ?></strong></td>
                            <td><?= htmlspecialchars($r['room_type_up']) ?></td>
                            <td>৳<?= number_format($r['price_r'],2) ?></td>
                            <td><?= $r['floor_no'] ?></td>
                            <td><?php $bc=['available'=>'badge-success','occupied'=>'badge-danger','maintenance'=>'badge-warning']; ?>
                                <span class="badge <?= $bc[$r['status']]??'badge-gray' ?>"><?= ucfirst($r['status']) ?></span></td>
                            <td><?= htmlspecialchars($r['description']??'-') ?></td>
                            <td>
                                <a href="rooms.php?edit=<?= $r['room_id'] ?>" class="btn btn-warning btn-sm">Edit</a>
                                <a href="rooms.php?delete=<?= $r['room_id'] ?>" class="btn btn-danger btn-sm"
                                   onclick="return confirm('Delete room?')">Delete</a>
                            </td>
                        </tr>
                        <?php endforeach; ?>
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
