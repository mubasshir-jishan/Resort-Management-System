<?php
require_once 'session_check.php';
require_once 'db_connect.php';
$msg = '';

// ── DELETE ──
if (isset($_GET['delete'])) {
    $id = (int)$_GET['delete'];
    $check = db_fetch_one($conn, "SELECT COUNT(*) AS n FROM bookings WHERE guest_id=$id");
    if ($check['n'] > 0) {
        $msg = '<div class="alert alert-danger">Cannot delete: guest has booking history. (FOREIGN KEY constraint enforced by DB.)</div>';
    } else {
        mysqli_query($conn, "DELETE FROM guests WHERE guest_id=$id");
        $msg = '<div class="alert alert-success">Guest deleted.</div>';
    }
}

// ── ADD — trigger trg_validate_guest fires ──
if ($_SERVER['REQUEST_METHOD']==='POST' && ($_POST['action']??'')==='add') {
    $name   = clean($conn, $_POST['full_name']);
    $phone  = clean($conn, $_POST['phone']);
    $email  = clean($conn, $_POST['email']);
    $addr   = clean($conn, $_POST['address']);
    $idtype = clean($conn, $_POST['id_type']);
    $idnum  = clean($conn, $_POST['id_number']);

    if ($phone !== '' && !preg_match('/^\d{7,15}$/', $phone)) {
        $msg = '<div class="alert alert-danger">Phone must be 7–15 digits only.</div>';
    } elseif ($idnum !== '' && $idtype === 'NID' && !preg_match('/^\d{10,17}$/', $idnum)) {
        $msg = '<div class="alert alert-danger">NID must be 10–17 digits.</div>';
    } elseif ($idnum !== '' && $idtype === 'Birth Certificate' && !preg_match('/^\d{17}$/', $idnum)) {
        $msg = '<div class="alert alert-danger">Birth Certificate must be exactly 17 digits.</div>';
    } elseif ($idnum !== '' && $idtype === 'Passport' && !preg_match('/^[A-Za-z0-9]{6,9}$/', $idnum)) {
        $msg = '<div class="alert alert-danger">Passport must be 6–9 alphanumeric chars.</div>';
    } elseif (trim($name) === '') {
        $msg = '<div class="alert alert-danger">Full name is required.</div>';
    } else {
        $sql = "INSERT INTO guests (full_name,phone,email,address,id_type,id_number)
                VALUES ('$name','$phone','$email','$addr','$idtype','$idnum')";
        if (mysqli_query($conn, $sql)) {
            // Re-read to show trigger's auto-capitalized name
            $new = db_fetch_one($conn, "SELECT full_name FROM guests WHERE guest_id=LAST_INSERT_ID()");
            $msg = '<div class="alert alert-success">✅ Guest added!
                <strong>Trigger trg_validate_guest fired:</strong> Name auto-capitalized to "'.htmlspecialchars($new['full_name']).'".</div>';
        } else {
            $e = mysqli_error($conn);
            $msg = '<div class="alert alert-danger">'.($e ?: 'Error').'</div>';
        }
    }
}

// ── UPDATE — trigger trg_after_guest_update fires ──
if ($_SERVER['REQUEST_METHOD']==='POST' && ($_POST['action']??'')==='edit') {
    $id    = (int)$_POST['guest_id'];
    $name  = clean($conn, $_POST['full_name']);
    $phone = clean($conn, $_POST['phone']);
    $email = clean($conn, $_POST['email']);
    $addr  = clean($conn, $_POST['address']);
    if ($phone !== '' && !preg_match('/^\d{7,15}$/', $phone)) {
        $msg = '<div class="alert alert-danger">Phone must be digits only (7–15).</div>';
    } else {
        if (mysqli_query($conn, "UPDATE guests SET full_name='$name',phone='$phone',email='$email',address='$addr' WHERE guest_id=$id")) {
            $msg = '<div class="alert alert-success">✅ Guest updated. <strong>Trigger trg_after_guest_update</strong> logged OLD vs NEW values to audit_log.</div>';
        } else {
            $msg = '<div class="alert alert-danger">'.mysqli_error($conn).'</div>';
        }
    }
}

// ── Guest statement via stored procedure ──
$statement = [];
$statement_guest = null;
if (isset($_GET['statement'])) {
    $gid = (int)$_GET['statement'];
    $statement = db_call_proc($conn, "CALL sp_guest_statement($gid)");
    $statement_guest = db_fetch_one($conn, "SELECT * FROM guest_booking_summary WHERE guest_id=$gid");
}

// ── Fetch guests using guest_booking_summary VIEW ──
$search = clean($conn, $_GET['search'] ?? '');
$where  = $search ? "WHERE full_name LIKE '%$search%' OR phone LIKE '%$search%' OR email LIKE '%$search%'" : "";
$guests = db_fetch_all($conn, "SELECT * FROM guest_booking_summary $where ORDER BY guest_id ASC");

$edit_guest = null;
if (isset($_GET['edit'])) {
    $edit_guest = db_fetch_one($conn, "SELECT * FROM guests WHERE guest_id=".(int)$_GET['edit']);
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<title>Guests — Resort Management</title>
<link rel="stylesheet" href="style.css">
</head>
<body>
<div class="layout">
    <?php include 'sidebar.php'; ?>
    <div class="main">
        <div class="topbar">
            <h1>Guests</h1>
            <div class="admin-info"><a href="logout.php" style="color:#e53e3e;">Logout</a></div>
        </div>
        <div class="content">
            <?= $msg ?>

            <?php if (!empty($statement) && $statement_guest): ?>
            <!-- Guest Statement via stored procedure -->
            <div class="card" style="border-left:4px solid #2c7a7b;">
                <div class="card-header">
                    <h3>Statement: <?= htmlspecialchars($statement_guest['full_name']) ?> <span class="db-badge">CALL sp_guest_statement(<?= (int)$_GET['statement'] ?>)</span></h3>
                    <a href="guests.php" class="btn btn-secondary btn-sm">Close</a>
                </div>
                <div class="card-body">
                    <div style="display:grid;grid-template-columns:repeat(4,1fr);gap:12px;margin-bottom:16px;">
                        <div class="stat-card" style="padding:12px;"><div class="stat-number" style="font-size:20px;"><?= $statement_guest['total_bookings'] ?></div><div class="stat-label">Total Trips</div></div>
                        <div class="stat-card green" style="padding:12px;"><div class="stat-number" style="font-size:20px;">৳<?= number_format($statement_guest['total_spent'],0) ?></div><div class="stat-label">Total Spent</div></div>
                        <div class="stat-card" style="padding:12px;"><div class="stat-number" style="font-size:20px;">৳<?= number_format($statement_guest['biggest_booking'],0) ?></div><div class="stat-label">Biggest Stay</div></div>
                        <div class="stat-card orange" style="padding:12px;"><div class="stat-number" style="font-size:16px;"><?= $statement_guest['guest_tier'] ?></div><div class="stat-label">Tier (CASE)</div></div>
                    </div>
                    <div class="table-wrap">
                    <table>
                        <thead><tr><th>Booking</th><th>Room</th><th>Type</th><th>Check-in</th><th>Check-out</th><th>Nights</th><th>Rate/Night</th><th>Total</th><th>Payment</th><th>Status</th><th>Booked On</th></tr></thead>
                        <tbody>
                        <?php foreach($statement as $s): ?>
                        <tr>
                            <td><?= $s['booking_id'] ?></td>
                            <td><?= htmlspecialchars($s['room_number']) ?></td>
                            <td><?= $s['room_type'] ?></td>
                            <td><?= $s['check_in'] ?></td>
                            <td><?= $s['check_out'] ?></td>
                            <td><?= $s['nights'] ?></td>
                            <td>৳<?= number_format($s['price_per_night'],0) ?></td>
                            <td><strong>৳<?= number_format($s['total_amount'],0) ?></strong></td>
                            <td><?php $pc=['paid'=>'badge-success','pending'=>'badge-warning','refunded'=>'badge-info']; ?>
                                <span class="badge <?= $pc[$s['payment_status']]??'badge-gray' ?>"><?= ucfirst($s['payment_status']) ?></span></td>
                            <td><span class="badge badge-info"><?= htmlspecialchars($s['status_label']) ?></span></td>
                            <td><?= $s['booked_on'] ?></td>
                        </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                    </div>
                </div>
            </div>
            <?php endif; ?>

            <!-- Add Guest -->
            <div class="card">
                <div class="card-header"><h3>Add New Guest <span class="db-badge">INSERT → trg_validate_guest fires</span></h3></div>
                <div class="card-body">
                    <form method="POST">
                        <input type="hidden" name="action" value="add">
                        <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(160px,1fr));gap:14px;">
                            <div class="form-group"><label>Full Name *</label>
                                <input type="text" name="full_name" required placeholder="e.g. Rahim Uddin"></div>
                            <div class="form-group"><label>Phone</label>
                                <input type="tel" name="phone" placeholder="01711000001" maxlength="15"></div>
                            <div class="form-group"><label>Email</label>
                                <input type="email" name="email" placeholder="guest@email.com"></div>
                            <div class="form-group"><label>Address</label>
                                <input type="text" name="address" placeholder="City"></div>
                            <div class="form-group"><label>ID Type</label>
                                <select name="id_type">
                                    <?php foreach(['NID','Passport','Birth Certificate'] as $t): ?>
                                    <option><?= $t ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div class="form-group"><label>ID Number</label>
                                <input type="text" name="id_number" placeholder="ID number"></div>
                        </div>
                        <div class="info-box">💡 <strong>Trigger trg_validate_guest</strong> will auto-capitalize the name and reject empty names at the database level.</div>
                        <button type="submit" class="btn btn-success">Add Guest</button>
                    </form>
                </div>
            </div>

            <?php if ($edit_guest): ?>
            <div class="card">
                <div class="card-header"><h3>Edit Guest <span class="db-badge">UPDATE → trg_after_guest_update fires</span></h3></div>
                <div class="card-body">
                    <form method="POST">
                        <input type="hidden" name="action" value="edit">
                        <input type="hidden" name="guest_id" value="<?= $edit_guest['guest_id'] ?>">
                        <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(160px,1fr));gap:14px;">
                            <div class="form-group"><label>Full Name</label>
                                <input type="text" name="full_name" value="<?= htmlspecialchars($edit_guest['full_name']) ?>" required></div>
                            <div class="form-group"><label>Phone</label>
                                <input type="tel" name="phone" value="<?= htmlspecialchars($edit_guest['phone']??'') ?>"></div>
                            <div class="form-group"><label>Email</label>
                                <input type="email" name="email" value="<?= htmlspecialchars($edit_guest['email']??'') ?>"></div>
                            <div class="form-group"><label>Address</label>
                                <input type="text" name="address" value="<?= htmlspecialchars($edit_guest['address']??'') ?>"></div>
                        </div>
                        <button type="submit" class="btn btn-warning">Update</button>
                        <a href="guests.php" class="btn btn-secondary">Cancel</a>
                    </form>
                </div>
            </div>
            <?php endif; ?>

            <!-- Guest List from guest_booking_summary VIEW -->
            <div class="card">
                <div class="card-header"><h3>All Guests (<?= count($guests) ?>) <span class="db-badge">guest_booking_summary VIEW — LEFT JOIN + GROUP BY + CASE</span></h3></div>
                <div class="card-body">
                    <form method="GET" class="filter-bar">
                        <input type="text" name="search" placeholder="Search name/phone/email..." value="<?= htmlspecialchars($search) ?>">
                        <button class="btn btn-primary btn-sm">Search</button>
                        <a href="guests.php" class="btn btn-secondary btn-sm">Reset</a>
                    </form>
                    <div class="table-wrap">
                    <table>
                        <thead><tr><th>#</th><th>Name</th><th>Phone</th><th>Email</th><th>ID Type</th><th>ID Number</th><th>Trips</th><th>Total Spent</th><th>Tier</th><th>Actions</th></tr></thead>
                        <tbody>
                        <?php $i=1; foreach($guests as $g): ?>
                        <tr>
                            <td><?= $i++ ?></td>
                            <td><strong><?= htmlspecialchars($g['full_name']) ?></strong></td>
                            <td><?= htmlspecialchars($g['phone']??'-') ?></td>
                            <td><?= htmlspecialchars($g['email']??'-') ?></td>
                            <td><?= htmlspecialchars($g['id_type']) ?></td>
                            <td><?= htmlspecialchars($g['id_number']??'-') ?></td>
                            <td><?= $g['total_bookings'] ?></td>
                            <td>৳<?= number_format($g['total_spent'],0) ?></td>
                            <td><span class="badge <?= $g['guest_tier']==='VIP'?'badge-danger':($g['guest_tier']==='Regular'?'badge-warning':'badge-info') ?>"><?= $g['guest_tier'] ?></span></td>
                            <td style="white-space:nowrap;">
                                <a href="guests.php?statement=<?= $g['guest_id'] ?>" class="btn btn-primary btn-sm">Statement</a>
                                <a href="guests.php?edit=<?= $g['guest_id'] ?>" class="btn btn-warning btn-sm">Edit</a>
                                <a href="guests.php?delete=<?= $g['guest_id'] ?>" class="btn btn-danger btn-sm"
                                   onclick="return confirm('Delete guest?')">Delete</a>
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
