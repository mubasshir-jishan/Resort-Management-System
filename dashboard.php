<?php
require_once 'session_check.php';
require_once 'db_connect.php';

// ── Stats from real DB queries / views ──
$total_rooms     = db_fetch_one($conn, "SELECT COUNT(*) AS n FROM rooms")['n'];
$available_rooms = db_fetch_one($conn, "SELECT COUNT(*) AS n FROM available_rooms")['n']; // uses VIEW
$total_guests    = db_fetch_one($conn, "SELECT COUNT(*) AS n FROM guests")['n'];
$total_bookings  = db_fetch_one($conn, "SELECT COUNT(*) AS n FROM bookings")['n'];
$paid_revenue    = db_fetch_one($conn, "SELECT IFNULL(SUM(total_amount),0) AS n FROM bookings WHERE payment_status='paid'")['n'];
$pending_count   = db_fetch_one($conn, "SELECT COUNT(*) AS n FROM bookings WHERE payment_status='pending'")['n'];

// ── Recent bookings from VIEW ──
$recent = db_fetch_all($conn, "SELECT * FROM booking_details ORDER BY booked_at DESC LIMIT 8");

// ── Revenue by type from VIEW (uses GROUP BY + aggregate inside view) ──
$rev_by_type = db_fetch_all($conn, "SELECT * FROM revenue_by_room_type ORDER BY total_revenue DESC");

// ── Room occupancy from VIEW (uses CASE + GROUP BY inside view) ──
$occupancy = db_fetch_all($conn, "SELECT * FROM room_occupancy_report ORDER BY occupancy_pct DESC");

// ── High-value bookings — subquery in action on a real page ──
$high_value = db_fetch_all($conn,
    "SELECT * FROM booking_details
     WHERE total_amount > (SELECT AVG(total_amount) FROM bookings)
     ORDER BY total_amount DESC LIMIT 5");

// ── VIP guests — subquery + HAVING used on real page ──
$vip_guests = db_fetch_all($conn,
    "SELECT g.full_name, g.phone, COUNT(b.booking_id) AS trips,
            SUM(b.total_amount) AS total_spent
     FROM guests g
     INNER JOIN bookings b ON g.guest_id = b.guest_id
     GROUP BY g.guest_id, g.full_name, g.phone
     HAVING COUNT(b.booking_id) >= 1
     ORDER BY total_spent DESC LIMIT 5");
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<title>Dashboard — Resort Management</title>
<link rel="stylesheet" href="style.css">
</head>
<body>
<div class="layout">
    <?php include 'sidebar.php'; ?>
    <div class="main">
        <div class="topbar">
            <h1>Dashboard</h1>
            <div class="admin-info">
                Logged in as <strong><?= htmlspecialchars($_SESSION['admin_username']) ?></strong>
                &nbsp;|&nbsp;<a href="logout.php" style="color:#e53e3e;">Logout</a>
            </div>
        </div>
        <div class="content">

            <!-- Stat Cards -->
            <div class="stats-grid">
                <div class="stat-card">
                    <div class="stat-number"><?= $total_rooms ?></div>
                    <div class="stat-label">Total Rooms</div>
                </div>
                <div class="stat-card green">
                    <div class="stat-number"><?= $available_rooms ?></div>
                    <div class="stat-label">Available Now <small style="font-size:11px;">(via VIEW)</small></div>
                </div>
                <div class="stat-card">
                    <div class="stat-number"><?= $total_guests ?></div>
                    <div class="stat-label">Total Guests</div>
                </div>
                <div class="stat-card">
                    <div class="stat-number"><?= $total_bookings ?></div>
                    <div class="stat-label">Total Bookings</div>
                </div>
                <div class="stat-card green">
                    <div class="stat-number">৳<?= number_format($paid_revenue, 0) ?></div>
                    <div class="stat-label">Revenue Collected</div>
                </div>
                <div class="stat-card orange">
                    <div class="stat-number"><?= $pending_count ?></div>
                    <div class="stat-label">Pending Payments</div>
                </div>
            </div>

            <!-- Recent Bookings from booking_details VIEW -->
            <div class="card">
                <div class="card-header">
                    <h3>Recent Bookings <span class="db-badge">booking_details VIEW</span></h3>
                    <a href="bookings.php" class="btn btn-primary btn-sm">View All</a>
                </div>
                <div class="card-body">
                    <div class="table-wrap">
                    <table>
                        <thead><tr><th>#</th><th>Guest</th><th>Room</th><th>Check-in</th><th>Check-out</th><th>Nights</th><th>Amount</th><th>Tier</th><th>Payment</th><th>Status</th></tr></thead>
                        <tbody>
                        <?php foreach ($recent as $r): ?>
                        <tr>
                            <td><?= $r['booking_id'] ?></td>
                            <td><?= htmlspecialchars($r['guest_name']) ?></td>
                            <td><?= htmlspecialchars($r['room_number']) ?> <small><?= $r['room_type'] ?></small></td>
                            <td><?= $r['check_in_fmt'] ?></td>
                            <td><?= $r['check_out_fmt'] ?></td>
                            <td><?= $r['nights'] ?></td>
                            <td>৳<?= number_format($r['total_amount'],0) ?></td>
                            <td><span class="badge <?= $r['booking_tier']==='High Value'?'badge-danger':($r['booking_tier']==='Mid Value'?'badge-warning':'badge-info') ?>"><?= $r['booking_tier'] ?></span></td>
                            <td><?php $pc=['paid'=>'badge-success','pending'=>'badge-warning','refunded'=>'badge-info']; ?>
                                <span class="badge <?= $pc[$r['payment_status']]??'badge-gray' ?>"><?= $r['payment_label'] ?></span></td>
                            <td><?php $sc=['confirmed'=>'badge-info','checked_out'=>'badge-success','cancelled'=>'badge-danger']; ?>
                                <span class="badge <?= $sc[$r['booking_status']]??'badge-gray' ?>"><?= ucfirst(str_replace('_',' ',$r['booking_status'])) ?></span></td>
                        </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                    </div>
                </div>
            </div>

            <div style="display:grid;grid-template-columns:1fr 1fr;gap:24px;">

                <!-- Revenue by Room Type — from VIEW with GROUP BY inside -->
                <div class="card">
                    <div class="card-header">
                        <h3>Revenue by Room Type <span class="db-badge">revenue_by_room_type VIEW</span></h3>
                    </div>
                    <div class="card-body">
                        <table>
                            <thead><tr><th>Type</th><th>Bookings</th><th>Total</th><th>Avg</th><th>Paid</th><th>Pending</th></tr></thead>
                            <tbody>
                            <?php foreach ($rev_by_type as $r): ?>
                            <tr>
                                <td><strong><?= htmlspecialchars($r['room_type']) ?></strong></td>
                                <td><?= $r['total_bookings'] ?></td>
                                <td>৳<?= number_format($r['total_revenue'],0) ?></td>
                                <td>৳<?= number_format($r['avg_revenue'],0) ?></td>
                                <td style="color:#22543d;">৳<?= number_format($r['paid_revenue'],0) ?></td>
                                <td style="color:#744210;">৳<?= number_format($r['pending_revenue'],0) ?></td>
                            </tr>
                            <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                </div>

                <!-- Room Occupancy — from VIEW with CASE + occupancy % -->
                <div class="card">
                    <div class="card-header">
                        <h3>Room Occupancy <span class="db-badge">room_occupancy_report VIEW</span></h3>
                    </div>
                    <div class="card-body">
                        <table>
                            <thead><tr><th>Type</th><th>Total</th><th>Avail</th><th>Occupied</th><th>Maint.</th><th>Occ%</th><th>Avg ৳</th></tr></thead>
                            <tbody>
                            <?php foreach ($occupancy as $r): ?>
                            <tr>
                                <td><?= htmlspecialchars($r['room_type']) ?></td>
                                <td><?= $r['total_rooms'] ?></td>
                                <td style="color:#22543d;"><strong><?= $r['available'] ?></strong></td>
                                <td style="color:#742a2a;"><strong><?= $r['occupied'] ?></strong></td>
                                <td style="color:#744210;"><?= $r['maintenance'] ?></td>
                                <td>
                                    <div style="background:#e2e8f0;border-radius:4px;height:14px;width:80px;display:inline-block;vertical-align:middle;">
                                        <div style="background:#e53e3e;height:14px;border-radius:4px;width:<?= min($r['occupancy_pct'],100) ?>%;"></div>
                                    </div>
                                    <?= $r['occupancy_pct'] ?>%
                                </td>
                                <td>৳<?= number_format($r['avg_price'],0) ?></td>
                            </tr>
                            <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>

            <div style="display:grid;grid-template-columns:1fr 1fr;gap:24px;">

                <!-- Above-average bookings — real subquery on a real page -->
                <div class="card">
                    <div class="card-header">
                        <h3>High-Value Bookings <span class="db-badge">Subquery: total > AVG</span></h3>
                    </div>
                    <div class="card-body">
                        <table>
                            <thead><tr><th>Guest</th><th>Room</th><th>Amount</th><th>Tier</th></tr></thead>
                            <tbody>
                            <?php foreach ($high_value as $r): ?>
                            <tr>
                                <td><?= htmlspecialchars($r['guest_name']) ?></td>
                                <td><?= htmlspecialchars($r['room_number']) ?></td>
                                <td>৳<?= number_format($r['total_amount'],0) ?></td>
                                <td><span class="badge badge-danger"><?= $r['booking_tier'] ?></span></td>
                            </tr>
                            <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                </div>

                <!-- VIP guests — real GROUP BY + HAVING on a real page -->
                <div class="card">
                    <div class="card-header">
                        <h3>Top Guests <span class="db-badge">GROUP BY + HAVING</span></h3>
                    </div>
                    <div class="card-body">
                        <table>
                            <thead><tr><th>Guest</th><th>Trips</th><th>Total Spent</th></tr></thead>
                            <tbody>
                            <?php foreach ($vip_guests as $r): ?>
                            <tr>
                                <td><?= htmlspecialchars($r['full_name']) ?><br><small style="color:#718096;"><?= $r['phone'] ?></small></td>
                                <td><?= $r['trips'] ?></td>
                                <td>৳<?= number_format($r['total_spent'],0) ?></td>
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
