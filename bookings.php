<?php
require_once 'session_check.php';
require_once 'db_connect.php';

$msg = '';

// ── CHECKOUT via stored procedure sp_checkout ──
if (isset($_GET['checkout'])) {
    $bid = (int)$_GET['checkout'];
    mysqli_query($conn, "CALL sp_checkout($bid, @msg)");
    $r = db_fetch_one($conn, "SELECT @msg AS msg");
    $ok = strpos($r['msg'], 'successful') !== false;
    $msg = '<div class="alert alert-'.($ok?'success':'danger').'">'
         . '🔧 <strong>Stored Procedure sp_checkout:</strong> ' . htmlspecialchars($r['msg']) . '</div>';
}

// ── APPLY DISCOUNT via sp_apply_discount (has TRANSACTION + ROLLBACK inside) ──
if ($_SERVER['REQUEST_METHOD']==='POST' && ($_POST['action']??'')==='discount') {
    $bid  = (int)$_POST['booking_id'];
    $disc = (float)$_POST['discount_pct'];
    mysqli_query($conn, "CALL sp_apply_discount($bid, $disc, @msg)");
    $r = db_fetch_one($conn, "SELECT @msg AS msg");
    $ok = strpos($r['msg'], 'applied') !== false;
    $msg = '<div class="alert alert-'.($ok?'success':'danger').'">'
         . '💰 <strong>Stored Procedure sp_apply_discount (with TRANSACTION):</strong> '
         . htmlspecialchars($r['msg']) . '</div>';
}

// ── DELETE ──
if (isset($_GET['delete'])) {
    $id = (int)$_GET['delete'];
    // Using a transaction here — if delete fails, nothing is lost
    mysqli_begin_transaction($conn);
    $del = mysqli_query($conn, "DELETE FROM bookings WHERE booking_id=$id");
    if ($del) {
        mysqli_commit($conn);
        $msg = '<div class="alert alert-success">✅ Booking deleted. Trigger freed the room + logged to audit_log.</div>';
    } else {
        mysqli_rollback($conn);
        $msg = '<div class="alert alert-danger">Delete failed: ' . mysqli_error($conn) . '</div>';
    }
}

// ── ADD BOOKING ──
if ($_SERVER['REQUEST_METHOD']==='POST' && ($_POST['action']??'')==='add') {
    $gid      = (int)$_POST['guest_id'];
    $rid      = (int)$_POST['room_id'];
    $checkin  = clean($conn, $_POST['check_in']);
    $checkout = clean($conn, $_POST['check_out']);
    $notes    = clean($conn, $_POST['notes']);

    if (!$gid || !$rid || !$checkin || !$checkout) {
        $msg = '<div class="alert alert-danger">All fields required.</div>';
    } elseif ($checkout <= $checkin) {
        $msg = '<div class="alert alert-danger">Check-out must be after check-in.</div>';
    } else {
        $avail = db_fetch_one($conn, "SELECT status FROM rooms WHERE room_id=$rid");
        if (!$avail || $avail['status'] !== 'available') {
            $msg = '<div class="alert alert-danger">Room is not available.</div>';
        } else {
            // Overlap check using subquery
            $dup = db_fetch_one($conn,
                "SELECT COUNT(*) AS n FROM bookings
                 WHERE room_id=$rid AND booking_status='confirmed'
                 AND check_in < '$checkout' AND check_out > '$checkin'");
            if ($dup['n'] > 0) {
                $msg = '<div class="alert alert-danger">Room already booked for those dates.</div>';
            } else {
                // INSERT — trigger fires: calculates total_amount + nights, marks room occupied, writes audit_log
                $sql = "INSERT INTO bookings (guest_id,room_id,check_in,check_out,notes)
                        VALUES ($gid,$rid,'$checkin','$checkout','$notes')";
                if (mysqli_query($conn, $sql)) {
                    $msg = '<div class="alert alert-success">✅ Booking created!
                        <strong>Triggers fired:</strong>
                        (1) trg_calc_total_amount calculated the bill,
                        (2) trg_room_status_on_booking marked room occupied,
                        (3) trg_after_booking_insert logged to audit_log.</div>';
                } else {
                    $msg = '<div class="alert alert-danger">Error: '.mysqli_error($conn).'</div>';
                }
            }
        }
    }
}

// ── UPDATE booking ──
if ($_SERVER['REQUEST_METHOD']==='POST' && ($_POST['action']??'')==='edit') {
    $id      = (int)$_POST['booking_id'];
    $pstatus = clean($conn, $_POST['payment_status']);
    $bstatus = clean($conn, $_POST['booking_status']);
    $notes   = clean($conn, $_POST['notes']);
    if (mysqli_query($conn, "UPDATE bookings SET payment_status='$pstatus',booking_status='$bstatus',notes='$notes' WHERE booking_id=$id")) {
        $msg = '<div class="alert alert-success">✅ Booking updated. <strong>Trigger fired:</strong> trg_after_booking_update logged OLD vs NEW values to audit_log.</div>';
    } else {
        $msg = '<div class="alert alert-danger">'.mysqli_error($conn).'</div>';
    }
}

// ── Filters ──
$fs = clean($conn, $_GET['status'] ?? '');
$fp = clean($conn, $_GET['payment'] ?? '');
$fw = "WHERE 1=1";
if ($fs) $fw .= " AND booking_status='$fs'";
if ($fp) $fw .= " AND payment_status='$fp'";

// Uses booking_details VIEW (has INNER JOIN, DATEDIFF, CASE, DATE_FORMAT inside)
$bookings = db_fetch_all($conn, "SELECT * FROM booking_details $fw ORDER BY booking_id DESC");

$guests_list = db_fetch_all($conn, "SELECT guest_id, full_name FROM guests ORDER BY full_name");
// Uses available_rooms VIEW (WHERE status='available', ORDER BY price)
$rooms_list  = db_fetch_all($conn, "SELECT * FROM available_rooms");

$edit_booking = null;
if (isset($_GET['edit'])) {
    $edit_booking = db_fetch_one($conn, "SELECT * FROM bookings WHERE booking_id=".(int)$_GET['edit']);
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<title>Bookings — Resort Management</title>
<link rel="stylesheet" href="style.css">
</head>
<body>
<div class="layout">
    <?php include 'sidebar.php'; ?>
    <div class="main">
        <div class="topbar">
            <h1>Bookings</h1>
            <div class="admin-info"><a href="logout.php" style="color:#e53e3e;">Logout</a></div>
        </div>
        <div class="content">
            <?= $msg ?>

            <!-- Add Booking -->
            <div class="card">
                <div class="card-header">
                    <h3>New Booking <span class="db-badge">INSERT → 3 Triggers fire automatically</span></h3>
                </div>
                <div class="card-body">
                    <form method="POST">
                        <input type="hidden" name="action" value="add">
                        <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(170px,1fr));gap:14px;">
                            <div class="form-group">
                                <label>Guest *</label>
                                <select name="guest_id" required>
                                    <option value="">Select Guest</option>
                                    <?php foreach ($guests_list as $g): ?>
                                    <option value="<?= $g['guest_id'] ?>"><?= htmlspecialchars($g['full_name']) ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div class="form-group">
                                <label>Room (available only, from VIEW) *</label>
                                <select name="room_id" required>
                                    <option value="">Select Room</option>
                                    <?php foreach ($rooms_list as $r): ?>
                                    <option value="<?= $r['room_id'] ?>">
                                        <?= htmlspecialchars($r['room_number']) ?> — <?= $r['room_type'] ?> (৳<?= number_format($r['price'],0) ?>)
                                    </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div class="form-group">
                                <label>Check-in *</label>
                                <input type="date" name="check_in" required>
                            </div>
                            <div class="form-group">
                                <label>Check-out *</label>
                                <input type="date" name="check_out" required>
                            </div>
                            <div class="form-group">
                                <label>Notes</label>
                                <input type="text" name="notes" placeholder="Optional">
                            </div>
                        </div>
                        <div class="info-box">
                            💡 Total amount &amp; nights calculated by <strong>TRIGGER trg_calc_total_amount</strong>. Room marked occupied by <strong>trg_room_status_on_booking</strong>. Action logged by <strong>trg_after_booking_insert</strong>.
                        </div>
                        <button type="submit" class="btn btn-success">Create Booking</button>
                    </form>
                </div>
            </div>

            <?php if ($edit_booking): ?>
            <!-- Edit Booking -->
            <div class="card">
                <div class="card-header"><h3>Edit Booking #<?= $edit_booking['booking_id'] ?> <span class="db-badge">UPDATE → trg_after_booking_update fires</span></h3></div>
                <div class="card-body">
                    <form method="POST">
                        <input type="hidden" name="action" value="edit">
                        <input type="hidden" name="booking_id" value="<?= $edit_booking['booking_id'] ?>">
                        <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(170px,1fr));gap:14px;">
                            <div class="form-group">
                                <label>Payment Status</label>
                                <select name="payment_status">
                                    <?php foreach(['pending','paid','refunded'] as $s): ?>
                                    <option <?= $edit_booking['payment_status']===$s?'selected':'' ?>><?= $s ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div class="form-group">
                                <label>Booking Status</label>
                                <select name="booking_status">
                                    <?php foreach(['confirmed','checked_out','cancelled'] as $s): ?>
                                    <option <?= $edit_booking['booking_status']===$s?'selected':'' ?>><?= $s ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div class="form-group">
                                <label>Notes</label>
                                <input type="text" name="notes" value="<?= htmlspecialchars($edit_booking['notes']??'') ?>">
                            </div>
                        </div>
                        <button type="submit" class="btn btn-warning">Update</button>
                        <a href="bookings.php" class="btn btn-secondary">Cancel</a>
                    </form>
                </div>
            </div>
            <?php endif; ?>

            <!-- Bookings List -->
            <div class="card">
                <div class="card-header">
                    <h3>All Bookings (<?= count($bookings) ?>) <span class="db-badge">booking_details VIEW — INNER JOIN + DATEDIFF + CASE</span></h3>
                </div>
                <div class="card-body">
                    <form method="GET" class="filter-bar">
                        <select name="status">
                            <option value="">All Status</option>
                            <?php foreach(['confirmed','checked_out','cancelled'] as $s): ?>
                            <option <?= $fs===$s?'selected':'' ?>><?= $s ?></option>
                            <?php endforeach; ?>
                        </select>
                        <select name="payment">
                            <option value="">All Payment</option>
                            <?php foreach(['paid','pending','refunded'] as $s): ?>
                            <option <?= $fp===$s?'selected':'' ?>><?= $s ?></option>
                            <?php endforeach; ?>
                        </select>
                        <button class="btn btn-primary btn-sm">Filter</button>
                        <a href="bookings.php" class="btn btn-secondary btn-sm">Reset</a>
                    </form>
                    <div class="table-wrap">
                    <table>
                        <thead>
                            <tr><th>#</th><th>Guest</th><th>Room</th><th>Check-in</th><th>Check-out</th><th>Nights</th><th>Rate</th><th>Total</th><th>Tier</th><th>Payment</th><th>Status</th><th>Actions</th></tr>
                        </thead>
                        <tbody>
                        <?php if(empty($bookings)): ?>
                            <tr><td colspan="12" style="text-align:center;color:#718096;">No bookings found.</td></tr>
                        <?php else: ?>
                        <?php $i=1; foreach($bookings as $b): ?>
                        <tr>
                            <td><?= $i++ ?></td>
                            <td><?= htmlspecialchars($b['guest_name']) ?><br><small style="color:#718096;"><?= $b['phone'] ?></small></td>
                            <td><?= htmlspecialchars($b['room_number']) ?><br><small><?= $b['room_type'] ?></small></td>
                            <td><?= $b['check_in_fmt'] ?></td>
                            <td><?= $b['check_out_fmt'] ?></td>
                            <td><?= $b['nights'] ?></td>
                            <td>৳<?= number_format($b['price_per_night'],0) ?></td>
                            <td><strong>৳<?= number_format($b['total_amount'],0) ?></strong></td>
                            <td><span class="badge <?= $b['booking_tier']==='High Value'?'badge-danger':($b['booking_tier']==='Mid Value'?'badge-warning':'badge-info') ?>"><?= $b['booking_tier'] ?></span></td>
                            <td><?php $pc=['paid'=>'badge-success','pending'=>'badge-warning','refunded'=>'badge-info']; ?>
                                <span class="badge <?= $pc[$b['payment_status']]??'badge-gray' ?>"><?= ucfirst($b['payment_status']) ?></span></td>
                            <td><?php $sc=['confirmed'=>'badge-info','checked_out'=>'badge-success','cancelled'=>'badge-danger']; ?>
                                <span class="badge <?= $sc[$b['booking_status']]??'badge-gray' ?>"><?= ucfirst(str_replace('_',' ',$b['booking_status'])) ?></span></td>
                            <td style="white-space:nowrap;">
                                <a href="bookings.php?edit=<?= $b['booking_id'] ?>" class="btn btn-warning btn-sm">Edit</a>
                                <?php if($b['booking_status']==='confirmed'): ?>
                                <a href="bookings.php?checkout=<?= $b['booking_id'] ?>"
                                   class="btn btn-primary btn-sm"
                                   onclick="return confirm('Checkout via stored procedure?')">Checkout</a>
                                <?php endif; ?>
                                <!-- Discount form -->
                                <button class="btn btn-secondary btn-sm" onclick="showDiscount(<?= $b['booking_id'] ?>)">Discount</button>
                                <a href="bookings.php?delete=<?= $b['booking_id'] ?>"
                                   class="btn btn-danger btn-sm"
                                   onclick="return confirm('Delete? Trigger will log + free room.')">Delete</a>
                            </td>
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

<!-- Discount modal -->
<div id="discount-modal" style="display:none;position:fixed;top:0;left:0;right:0;bottom:0;background:rgba(0,0,0,0.5);z-index:200;align-items:center;justify-content:center;">
    <div style="background:#fff;border-radius:12px;padding:32px;width:380px;box-shadow:0 20px 60px rgba(0,0,0,0.3);">
        <h3 style="margin-bottom:16px;color:#1a365d;">Apply Discount</h3>
        <p style="font-size:13px;color:#718096;margin-bottom:16px;">Uses <strong>sp_apply_discount</strong> stored procedure with <strong>TRANSACTION</strong>. If anything fails, it auto-rolls back.</p>
        <form method="POST">
            <input type="hidden" name="action" value="discount">
            <input type="hidden" name="booking_id" id="disc-bid">
            <div class="form-group">
                <label>Discount % (1–50)</label>
                <input type="number" name="discount_pct" min="1" max="50" value="10" required>
            </div>
            <div style="display:flex;gap:10px;">
                <button type="submit" class="btn btn-success">Apply</button>
                <button type="button" onclick="hideDiscount()" class="btn btn-secondary">Cancel</button>
            </div>
        </form>
    </div>
</div>
<script>
function showDiscount(id) {
    document.getElementById('disc-bid').value = id;
    document.getElementById('discount-modal').style.display = 'flex';
}
function hideDiscount() {
    document.getElementById('discount-modal').style.display = 'none';
}
</script>
</body>
</html>
