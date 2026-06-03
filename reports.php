<?php
require_once 'session_check.php';
require_once 'db_connect.php';

$tab = $_GET['tab'] ?? 'availability';

// ══════════════════════════════════════════
// TAB 1: Room Availability — calls sp_room_availability
// ══════════════════════════════════════════
$avail_result = [];
$avail_type   = clean($conn, $_GET['avail_type'] ?? 'ALL');
if ($tab === 'availability') {
    $avail_result = db_call_proc($conn, "CALL sp_room_availability('$avail_type')");
}

// ══════════════════════════════════════════
// TAB 2: Monthly Revenue — calls sp_monthly_revenue
// ══════════════════════════════════════════
$monthly = [];
$rev_year = (int)($_GET['rev_year'] ?? date('Y'));
if ($tab === 'revenue') {
    $monthly = db_call_proc($conn, "CALL sp_monthly_revenue($rev_year)");
}

// ══════════════════════════════════════════
// TAB 3: Search & Filter — WHERE, BETWEEN, LIKE, IN, ORDER BY all live
// ══════════════════════════════════════════
$search_results = [];
if ($tab === 'search') {
    $min_price = (float)($_GET['min_price'] ?? 0);
    $max_price = (float)($_GET['max_price'] ?? 99999);
    $room_type = clean($conn, $_GET['room_type'] ?? '');
    $s_status  = clean($conn, $_GET['s_status'] ?? '');
    $keyword   = clean($conn, $_GET['keyword'] ?? '');
    $sort      = in_array($_GET['sort']??'', ['price','room_number','floor_no']) ? $_GET['sort'] : 'price';
    $dir       = ($_GET['dir']??'ASC') === 'DESC' ? 'DESC' : 'ASC';

    $where = "WHERE price BETWEEN $min_price AND $max_price";
    if ($room_type) $where .= " AND room_type IN ('$room_type')";
    if ($s_status)  $where .= " AND status = '$s_status'";
    if ($keyword)   $where .= " AND (room_number LIKE '%$keyword%' OR description LIKE '%$keyword%' OR room_type LIKE '%$keyword%')";

    $search_results = db_fetch_all($conn,
        "SELECT room_number, UPPER(room_type) AS room_type,
                price, status, floor_no,
                IFNULL(description,'No description') AS description,
                ROUND(price/30, 2) AS price_per_day,
                ROUND(price * 365 * 0.6, 0) AS est_annual_revenue
         FROM rooms $where ORDER BY $sort $dir");
}

// ══════════════════════════════════════════
// TAB 4: String Functions — live on real guest data
// ══════════════════════════════════════════
$string_results = [];
if ($tab === 'strings') {
    $string_results = db_fetch_all($conn,
        "SELECT
            full_name,
            UPPER(full_name)                                        AS upper_name,
            LOWER(full_name)                                        AS lower_name,
            CONCAT(UPPER(LEFT(full_name,1)), LOWER(SUBSTR(full_name,2))) AS initcap_manual,
            LENGTH(full_name)                                       AS name_length,
            SUBSTR(full_name, 1, 5)                                 AS first_5_chars,
            INSTR(full_name, 'a')                                   AS pos_of_a,
            LPAD(IFNULL(phone,'N/A'), 15, '*')                      AS lpad_phone,
            RPAD(full_name, 20, '.')                                AS rpad_name,
            REPLACE(full_name, ' ', '_')                            AS underscore_name,
            CONCAT(full_name, ' | ', IFNULL(phone,'N/A'))           AS full_contact,
            TRIM('  extra spaces  ')                                AS trim_demo
         FROM guests ORDER BY full_name");
}

// ══════════════════════════════════════════
// TAB 5: Date & Number functions — live on real booking data
// ══════════════════════════════════════════
$date_results = $number_results = [];
if ($tab === 'functions') {
    $date_results = db_fetch_all($conn,
        "SELECT
            b.booking_id,
            b.check_in,
            b.check_out,
            DATEDIFF(b.check_out, b.check_in)              AS nights,
            DATE_ADD(b.check_in, INTERVAL 1 MONTH)         AS add_one_month,
            LAST_DAY(b.check_in)                           AS last_day_of_month,
            DATE_FORMAT(b.check_in, '%W, %d %M %Y')        AS full_date_name,
            DATE_FORMAT(NOW(), '%d-%b-%Y %H:%i:%s')         AS current_time,
            CASE
                WHEN DATEDIFF(b.check_out, b.check_in) >= 5 THEN 'Long Stay'
                WHEN DATEDIFF(b.check_out, b.check_in) >= 3 THEN 'Medium Stay'
                ELSE 'Short Stay'
            END AS stay_type
         FROM bookings b ORDER BY b.booking_id");

    $number_results = db_fetch_all($conn,
        "SELECT
            room_number,
            price,
            ROUND(price / 30, 2)          AS price_per_day,
            ROUND(price, -3)              AS rounded_thousand,
            TRUNCATE(price, 0)            AS truncated,
            MOD(room_id, 2)               AS odd_or_even,
            ROUND(price * 365 * 0.6, 0)   AS annual_est_revenue
         FROM rooms ORDER BY price DESC");
}

// ══════════════════════════════════════════
// TAB 6: JOIN explorer — user picks join type, sees real result
// ══════════════════════════════════════════
$join_results = [];
$join_type = clean($conn, $_GET['join_type'] ?? 'inner');
if ($tab === 'joins') {
    $joins = [
        'inner' => "SELECT g.full_name AS guest, r.room_number, r.room_type, b.check_in, b.check_out, b.total_amount
                    FROM bookings b INNER JOIN guests g ON b.guest_id=g.guest_id INNER JOIN rooms r ON b.room_id=r.room_id ORDER BY b.booking_id",
        'left'  => "SELECT r.room_number, UPPER(r.room_type) AS room_type, r.price,
                           IFNULL(CAST(b.booking_id AS CHAR),'Never booked') AS booking,
                           IFNULL(b.total_amount, 0) AS amount
                    FROM rooms r LEFT JOIN bookings b ON r.room_id=b.room_id ORDER BY r.room_number",
        'right' => "SELECT g.full_name, g.phone,
                           IFNULL(CAST(b.booking_id AS CHAR),'No bookings yet') AS booking,
                           IFNULL(CAST(b.total_amount AS CHAR),'—') AS amount
                    FROM bookings b RIGHT JOIN guests g ON b.guest_id=g.guest_id ORDER BY g.guest_id",
        'self'  => "SELECT a.room_number AS room_a, b.room_number AS room_b, a.room_type, a.price
                    FROM rooms a INNER JOIN rooms b ON a.room_type=b.room_type AND a.room_id < b.room_id ORDER BY a.room_type LIMIT 10",
        'equi'  => "SELECT g.full_name, r.room_number, b.booking_id, b.total_amount
                    FROM bookings b, guests g, rooms r
                    WHERE b.guest_id=g.guest_id AND b.room_id=r.room_id ORDER BY b.booking_id",
        'full'  => "SELECT g.full_name AS guest, IFNULL(CAST(b.booking_id AS CHAR),'—') AS booking, IFNULL(r.room_number,'—') AS room
                    FROM guests g LEFT JOIN bookings b ON g.guest_id=b.guest_id LEFT JOIN rooms r ON b.room_id=r.room_id
                    UNION
                    SELECT IFNULL(g2.full_name,'—'), CAST(b2.booking_id AS CHAR), IFNULL(r2.room_number,'—')
                    FROM bookings b2 RIGHT JOIN guests g2 ON b2.guest_id=g2.guest_id LEFT JOIN rooms r2 ON b2.room_id=r2.room_id
                    ORDER BY guest",
    ];
    $join_results = db_fetch_all($conn, $joins[$join_type] ?? $joins['inner']);
}

// ══════════════════════════════════════════
// TAB 7: Subqueries — real interactive subquery results
// ══════════════════════════════════════════
$sub_results = [];
$sub_type = clean($conn, $_GET['sub_type'] ?? 'single');
if ($tab === 'subqueries') {
    $subs = [
        'single' => "SELECT full_name, phone, email FROM guests
                     WHERE guest_id = (SELECT guest_id FROM bookings ORDER BY total_amount DESC LIMIT 1)",
        'in'     => "SELECT full_name, IFNULL(email,'N/A') AS email FROM guests
                     WHERE guest_id IN (SELECT guest_id FROM bookings WHERE total_amount > 10000)",
        'max'    => "SELECT booking_id, total_amount, payment_status, booking_status FROM bookings
                     WHERE total_amount = (SELECT MAX(total_amount) FROM bookings)",
        'corr'   => "SELECT room_type, room_number, price FROM rooms
                     WHERE price = (SELECT MAX(r2.price) FROM rooms r2 WHERE r2.room_type=rooms.room_type)
                     ORDER BY price DESC",
        'exists' => "SELECT r.room_number, r.room_type, r.price FROM rooms r
                     WHERE EXISTS (SELECT 1 FROM bookings b WHERE b.room_id=r.room_id AND b.payment_status='paid')",
        'having' => "SELECT r.room_type, COUNT(*) AS bookings, SUM(b.total_amount) AS revenue
                     FROM bookings b INNER JOIN rooms r ON b.room_id=r.room_id
                     GROUP BY r.room_type
                     HAVING SUM(b.total_amount) >= (SELECT AVG(total_amount) FROM bookings)
                     ORDER BY revenue DESC",
        'notin'  => "SELECT full_name, phone FROM guests
                     WHERE guest_id NOT IN (SELECT DISTINCT guest_id FROM bookings WHERE payment_status='paid')",
    ];
    $sub_results = db_fetch_all($conn, $subs[$sub_type] ?? $subs['single']);
}

// ══════════════════════════════════════════
// TAB 8: GROUP BY + HAVING — user picks grouping
// ══════════════════════════════════════════
$group_results = [];
$group_by = clean($conn, $_GET['group_by'] ?? 'room_type');
$having_min = (int)($_GET['having_min'] ?? 0);
if ($tab === 'groupby') {
    if ($group_by === 'room_type') {
        $group_results = db_fetch_all($conn,
            "SELECT r.room_type AS group_label,
                    COUNT(b.booking_id) AS total_bookings,
                    IFNULL(SUM(b.total_amount),0) AS total_revenue,
                    IFNULL(AVG(b.total_amount),0) AS avg_revenue,
                    IFNULL(MAX(b.total_amount),0) AS max_booking,
                    IFNULL(MIN(b.total_amount),0) AS min_booking
             FROM rooms r LEFT JOIN bookings b ON r.room_id=b.room_id
             GROUP BY r.room_type
             HAVING IFNULL(SUM(b.total_amount),0) >= $having_min
             ORDER BY total_revenue DESC");
    } elseif ($group_by === 'payment') {
        $group_results = db_fetch_all($conn,
            "SELECT payment_status AS group_label,
                    COUNT(*) AS total_bookings,
                    SUM(total_amount) AS total_revenue,
                    AVG(total_amount) AS avg_revenue,
                    MAX(total_amount) AS max_booking,
                    MIN(total_amount) AS min_booking
             FROM bookings GROUP BY payment_status
             HAVING SUM(total_amount) >= $having_min ORDER BY total_revenue DESC");
    } elseif ($group_by === 'month') {
        $group_results = db_fetch_all($conn,
            "SELECT DATE_FORMAT(check_in,'%b %Y') AS group_label,
                    COUNT(*) AS total_bookings,
                    SUM(total_amount) AS total_revenue,
                    AVG(total_amount) AS avg_revenue,
                    MAX(total_amount) AS max_booking,
                    MIN(total_amount) AS min_booking
             FROM bookings GROUP BY DATE_FORMAT(check_in,'%Y-%m')
             HAVING SUM(total_amount) >= $having_min ORDER BY MIN(check_in)");
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<title>Reports — Resort Management</title>
<link rel="stylesheet" href="style.css">
<style>
.tab-nav { display:flex; gap:4px; flex-wrap:wrap; margin-bottom:24px; }
.tab-nav a { padding:8px 16px; border-radius:8px; text-decoration:none; font-size:13px; font-weight:600;
             background:#e2e8f0; color:#4a5568; transition:all 0.15s; }
.tab-nav a.active { background:#1a365d; color:#fff; }
.tab-nav a:hover:not(.active) { background:#cbd5e0; }
.query-box { background:#1a202c; color:#68d391; border-radius:8px; padding:14px 18px;
             font-family:'Courier New',monospace; font-size:12px; margin-bottom:16px; white-space:pre-wrap; }
</style>
</head>
<body>
<div class="layout">
    <?php include 'sidebar.php'; ?>
    <div class="main">
        <div class="topbar">
            <h1>Reports — Live SQL Queries</h1>
            <div class="admin-info"><a href="logout.php" style="color:#e53e3e;">Logout</a></div>
        </div>
        <div class="content">

            <!-- Tab Nav -->
            <div class="tab-nav">
                <?php
                $tabs = [
                    'availability' => '📦 Room Availability (Procedure)',
                    'revenue'      => '💰 Monthly Revenue (Cursor+Loop)',
                    'search'       => '🔍 Search & Filter (WHERE+BETWEEN+LIKE)',
                    'strings'      => '🔤 String Functions (Live)',
                    'functions'    => '📅 Date & Number Functions (Live)',
                    'joins'        => '🔗 JOIN Explorer (All Types)',
                    'subqueries'   => '📐 Subqueries (All Types)',
                    'groupby'      => '📊 GROUP BY + HAVING',
                ];
                foreach ($tabs as $t => $label):
                ?>
                <a href="?tab=<?= $t ?>" class="<?= $tab===$t?'active':'' ?>"><?= $label ?></a>
                <?php endforeach; ?>
            </div>

            <?php if ($tab === 'availability'): ?>
            <!-- STORED PROCEDURE: sp_room_availability -->
            <div class="card">
                <div class="card-header"><h3>Room Availability <span class="db-badge">CALL sp_room_availability()</span></h3></div>
                <div class="card-body">
                    <form method="GET" class="filter-bar">
                        <input type="hidden" name="tab" value="availability">
                        <select name="avail_type">
                            <option <?= $avail_type==='ALL'?'selected':'' ?> value="ALL">All Types</option>
                            <?php foreach(['Standard','Deluxe','Suite','Presidential'] as $t): ?>
                            <option <?= $avail_type===$t?'selected':'' ?> value="<?= $t ?>"><?= $t ?></option>
                            <?php endforeach; ?>
                        </select>
                        <button class="btn btn-primary btn-sm">Run Procedure</button>
                    </form>
                    <div class="query-box">CALL sp_room_availability('<?= htmlspecialchars($avail_type) ?>');</div>
                    <div class="table-wrap"><table>
                        <thead><tr><th>Room Type</th><th>Total</th><th>Available</th><th>Occupied</th><th>Maintenance</th><th>Avg Price</th><th>Min Price</th><th>Max Price</th></tr></thead>
                        <tbody>
                        <?php foreach($avail_result as $r): ?>
                        <tr>
                            <td><?= htmlspecialchars($r['room_type']) ?></td>
                            <td><?= $r['total_rooms'] ?></td>
                            <td><span class="badge badge-success"><?= $r['available'] ?></span></td>
                            <td><span class="badge badge-danger"><?= $r['occupied'] ?></span></td>
                            <td><span class="badge badge-warning"><?= $r['under_maintenance'] ?></span></td>
                            <td>৳<?= number_format($r['avg_price'],2) ?></td>
                            <td>৳<?= number_format($r['min_price'],0) ?></td>
                            <td>৳<?= number_format($r['max_price'],0) ?></td>
                        </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table></div>
                </div>
            </div>

            <?php elseif ($tab === 'revenue'): ?>
            <!-- STORED PROCEDURE with CURSOR + LOOP: sp_monthly_revenue -->
            <div class="card">
                <div class="card-header"><h3>Monthly Revenue <span class="db-badge">CALL sp_monthly_revenue() — uses CURSOR + LOOP internally</span></h3></div>
                <div class="card-body">
                    <form method="GET" class="filter-bar">
                        <input type="hidden" name="tab" value="revenue">
                        <label>Year:</label>
                        <input type="number" name="rev_year" value="<?= $rev_year ?>" min="2020" max="2030" style="width:90px;">
                        <button class="btn btn-primary btn-sm">Run</button>
                    </form>
                    <div class="query-box">CALL sp_monthly_revenue(<?= $rev_year ?>);
-- Inside the procedure:
-- DECLARE cur CURSOR FOR SELECT MONTH(check_in), SUM(total_amount) ...
-- OPEN cur → LOOP → FETCH cur INTO v_month, v_total → INSERT tmp_revenue → CLOSE cur</div>
                    <?php if(empty($monthly)): ?>
                    <p style="color:#718096;">No bookings found for <?= $rev_year ?>. Try another year.</p>
                    <?php else: ?>
                    <div class="table-wrap"><table>
                        <thead><tr><th>#</th><th>Month</th><th>Revenue</th><th>Bookings</th><th>Visual</th></tr></thead>
                        <tbody>
                        <?php $max_rev = max(array_column($monthly,'revenue')) ?: 1; ?>
                        <?php foreach($monthly as $m): ?>
                        <tr>
                            <td><?= $m['month_no'] ?></td>
                            <td><?= $m['month_name'] ?></td>
                            <td>৳<?= number_format($m['revenue'],0) ?></td>
                            <td><?= $m['bookings'] ?></td>
                            <td>
                                <div style="background:#e2e8f0;border-radius:4px;height:14px;width:200px;display:inline-block;">
                                    <div style="background:#2c7a7b;height:14px;border-radius:4px;width:<?= round($m['revenue']/$max_rev*100) ?>%;"></div>
                                </div>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table></div>
                    <?php endif; ?>
                </div>
            </div>

            <?php elseif ($tab === 'search'): ?>
            <!-- WHERE + BETWEEN + IN + LIKE + ORDER BY — user controls all parameters -->
            <div class="card">
                <div class="card-header"><h3>Search Rooms <span class="db-badge">WHERE + BETWEEN + IN + LIKE + ORDER BY — all live</span></h3></div>
                <div class="card-body">
                    <form method="GET" class="filter-bar" style="flex-wrap:wrap;">
                        <input type="hidden" name="tab" value="search">
                        <div class="form-group" style="margin:0;">
                            <label style="font-size:12px;">Min Price</label>
                            <input type="number" name="min_price" value="<?= $_GET['min_price']??0 ?>" style="width:90px;">
                        </div>
                        <div class="form-group" style="margin:0;">
                            <label style="font-size:12px;">Max Price</label>
                            <input type="number" name="max_price" value="<?= $_GET['max_price']??99999 ?>" style="width:90px;">
                        </div>
                        <select name="room_type">
                            <option value="">All Types</option>
                            <?php foreach(['Standard','Deluxe','Suite','Presidential'] as $t): ?>
                            <option <?= ($_GET['room_type']??'')===$t?'selected':'' ?>><?= $t ?></option>
                            <?php endforeach; ?>
                        </select>
                        <select name="s_status">
                            <option value="">All Status</option>
                            <?php foreach(['available','occupied','maintenance'] as $s): ?>
                            <option <?= ($_GET['s_status']??'')===$s?'selected':'' ?>><?= $s ?></option>
                            <?php endforeach; ?>
                        </select>
                        <input type="text" name="keyword" placeholder="Keyword (LIKE)" value="<?= htmlspecialchars($_GET['keyword']??'') ?>">
                        <select name="sort">
                            <?php foreach(['price'=>'Sort: Price','room_number'=>'Sort: Room No','floor_no'=>'Sort: Floor'] as $k=>$v): ?>
                            <option value="<?= $k ?>" <?= ($_GET['sort']??'')===$k?'selected':'' ?>><?= $v ?></option>
                            <?php endforeach; ?>
                        </select>
                        <select name="dir">
                            <option <?= ($_GET['dir']??'ASC')==='ASC'?'selected':'' ?> value="ASC">ASC ↑</option>
                            <option <?= ($_GET['dir']??'')==='DESC'?'selected':'' ?> value="DESC">DESC ↓</option>
                        </select>
                        <button class="btn btn-primary btn-sm">Search</button>
                        <a href="?tab=search" class="btn btn-secondary btn-sm">Reset</a>
                    </form>
                    <div class="table-wrap"><table>
                        <thead><tr><th>Room</th><th>Type</th><th>Price</th><th>Price/Day</th><th>Est Annual</th><th>Floor</th><th>Status</th><th>Description</th></tr></thead>
                        <tbody>
                        <?php if(empty($search_results)): ?>
                        <tr><td colspan="8" style="text-align:center;color:#718096;">No rooms match your filters.</td></tr>
                        <?php else: foreach($search_results as $r): ?>
                        <tr>
                            <td><?= htmlspecialchars($r['room_number']) ?></td>
                            <td><?= htmlspecialchars($r['room_type']) ?></td>
                            <td>৳<?= number_format($r['price'],0) ?></td>
                            <td>৳<?= number_format($r['price_per_day'],2) ?></td>
                            <td>৳<?= number_format($r['est_annual_revenue'],0) ?></td>
                            <td><?= $r['floor_no'] ?></td>
                            <td><?php $bc=['available'=>'badge-success','occupied'=>'badge-danger','maintenance'=>'badge-warning']; ?>
                                <span class="badge <?= $bc[$r['status']]??'badge-gray' ?>"><?= ucfirst($r['status']) ?></span></td>
                            <td><?= htmlspecialchars($r['description']) ?></td>
                        </tr>
                        <?php endforeach; endif; ?>
                        </tbody>
                    </table></div>
                </div>
            </div>

            <?php elseif ($tab === 'strings'): ?>
            <!-- STRING FUNCTIONS — live on real guest names -->
            <div class="card">
                <div class="card-header"><h3>String Functions on Real Guest Data <span class="db-badge">UPPER, LOWER, CONCAT, SUBSTR, INSTR, LPAD, RPAD, REPLACE, TRIM — all live</span></h3></div>
                <div class="card-body">
                    <div class="query-box">SELECT
  UPPER(full_name), LOWER(full_name),
  CONCAT(UPPER(LEFT(full_name,1)), LOWER(SUBSTR(full_name,2))) AS initcap_manual,
  LENGTH(full_name), SUBSTR(full_name,1,5), INSTR(full_name,'a'),
  LPAD(phone,15,'*'), RPAD(full_name,20,'.'),
  REPLACE(full_name,' ','_'), TRIM('  extra spaces  ')
FROM guests ORDER BY full_name;</div>
                    <div class="table-wrap"><table>
                        <thead><tr><th>Name</th><th>UPPER</th><th>LOWER</th><th>INITCAP (manual)</th><th>LENGTH</th><th>SUBSTR(1,5)</th><th>INSTR('a')</th><th>LPAD(*,15)</th><th>REPLACE( ,_)</th><th>Full Contact</th></tr></thead>
                        <tbody>
                        <?php foreach($string_results as $r): ?>
                        <tr>
                            <td><?= htmlspecialchars($r['full_name']) ?></td>
                            <td><?= htmlspecialchars($r['upper_name']) ?></td>
                            <td><?= htmlspecialchars($r['lower_name']) ?></td>
                            <td><?= htmlspecialchars($r['initcap_manual']) ?></td>
                            <td><?= $r['name_length'] ?></td>
                            <td><?= htmlspecialchars($r['first_5_chars']) ?></td>
                            <td><?= $r['pos_of_a'] ?></td>
                            <td style="font-family:monospace;font-size:12px;"><?= htmlspecialchars($r['lpad_phone']) ?></td>
                            <td><?= htmlspecialchars($r['underscore_name']) ?></td>
                            <td><?= htmlspecialchars($r['full_contact']) ?></td>
                        </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table></div>
                </div>
            </div>

            <?php elseif ($tab === 'functions'): ?>
            <!-- DATE + NUMBER FUNCTIONS — live -->
            <div class="card">
                <div class="card-header"><h3>Date Functions on Real Bookings <span class="db-badge">DATEDIFF, DATE_ADD, LAST_DAY, DATE_FORMAT, NOW(), CASE</span></h3></div>
                <div class="card-body">
                    <div class="table-wrap"><table>
                        <thead><tr><th>Booking</th><th>Check-in</th><th>Check-out</th><th>DATEDIFF (nights)</th><th>+1 Month</th><th>LAST_DAY</th><th>Full Date Name</th><th>NOW()</th><th>Stay Type (CASE)</th></tr></thead>
                        <tbody>
                        <?php foreach($date_results as $r): ?>
                        <tr>
                            <td><?= $r['booking_id'] ?></td>
                            <td><?= $r['check_in'] ?></td>
                            <td><?= $r['check_out'] ?></td>
                            <td><?= $r['nights'] ?></td>
                            <td><?= $r['add_one_month'] ?></td>
                            <td><?= $r['last_day_of_month'] ?></td>
                            <td style="font-size:12px;"><?= htmlspecialchars($r['full_date_name']) ?></td>
                            <td style="font-size:11px;"><?= $r['current_time'] ?></td>
                            <td><span class="badge badge-info"><?= $r['stay_type'] ?></span></td>
                        </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table></div>
                </div>
            </div>
            <div class="card">
                <div class="card-header"><h3>Number Functions on Real Room Prices <span class="db-badge">ROUND, TRUNCATE, MOD — live</span></h3></div>
                <div class="card-body">
                    <div class="table-wrap"><table>
                        <thead><tr><th>Room</th><th>Price</th><th>ROUND(/30,2)</th><th>ROUND(-3)</th><th>TRUNCATE(0)</th><th>MOD(id,2)</th><th>Est. Annual</th></tr></thead>
                        <tbody>
                        <?php foreach($number_results as $r): ?>
                        <tr>
                            <td><?= htmlspecialchars($r['room_number']) ?></td>
                            <td>৳<?= number_format($r['price'],2) ?></td>
                            <td>৳<?= number_format($r['price_per_day'],2) ?>/day</td>
                            <td>৳<?= number_format($r['rounded_thousand'],0) ?></td>
                            <td>৳<?= number_format($r['truncated'],0) ?></td>
                            <td><?= $r['odd_or_even'] == 0 ? 'Even' : 'Odd' ?></td>
                            <td>৳<?= number_format($r['annual_est_revenue'],0) ?></td>
                        </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table></div>
                </div>
            </div>

            <?php elseif ($tab === 'joins'): ?>
            <!-- JOIN EXPLORER — user picks join type -->
            <div class="card">
                <div class="card-header"><h3>JOIN Explorer <span class="db-badge">Pick a join — real query runs</span></h3></div>
                <div class="card-body">
                    <form method="GET" class="filter-bar">
                        <input type="hidden" name="tab" value="joins">
                        <?php
                        $jtypes=['inner'=>'INNER JOIN','left'=>'LEFT JOIN','right'=>'RIGHT JOIN','self'=>'SELF JOIN','equi'=>'EQUI JOIN (old)','full'=>'FULL OUTER (UNION)'];
                        foreach($jtypes as $k=>$v): ?>
                        <a href="?tab=joins&join_type=<?= $k ?>" class="btn <?= $join_type===$k?'btn-primary':'btn-secondary' ?> btn-sm"><?= $v ?></a>
                        <?php endforeach; ?>
                    </form>
                    <?php
                    $descs=[
                        'inner'=>'Returns only rows where the match exists in BOTH tables.',
                        'left' =>'Returns ALL rooms, with booking info if exists, NULL if room was never booked.',
                        'right'=>'Returns ALL guests, with booking info if exists, NULL if guest has no booking.',
                        'self' =>'Joins the rooms table to itself — finds pairs of rooms with the same type.',
                        'equi' =>'Old-style join using WHERE clause instead of JOIN keyword.',
                        'full' =>'FULL OUTER JOIN: all guests + all bookings. MySQL has no FULL JOIN so LEFT UNION RIGHT is used.',
                    ];
                    ?>
                    <div class="info-box"><?= $descs[$join_type] ?></div>
                    <?php if(!empty($join_results)): ?>
                    <div class="table-wrap"><table>
                        <thead><tr><?php foreach(array_keys($join_results[0]) as $col): ?><th><?= htmlspecialchars($col) ?></th><?php endforeach; ?></tr></thead>
                        <tbody>
                        <?php foreach($join_results as $r): ?>
                        <tr><?php foreach($r as $v): ?><td><?= htmlspecialchars($v??'NULL') ?></td><?php endforeach; ?></tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table></div>
                    <?php endif; ?>
                </div>
            </div>

            <?php elseif ($tab === 'subqueries'): ?>
            <!-- SUBQUERY EXPLORER -->
            <div class="card">
                <div class="card-header"><h3>Subquery Explorer <span class="db-badge">All 6 subquery types — real results</span></h3></div>
                <div class="card-body">
                    <form method="GET" class="filter-bar" style="flex-wrap:wrap;">
                        <input type="hidden" name="tab" value="subqueries">
                        <?php
                        $stypes=['single'=>'Single-Value (=)','in'=>'Multi-Row (IN)','max'=>'MAX Subquery','corr'=>'Correlated','exists'=>'EXISTS','having'=>'HAVING+Subquery','notin'=>'NOT IN'];
                        foreach($stypes as $k=>$v): ?>
                        <a href="?tab=subqueries&sub_type=<?= $k ?>" class="btn <?= $sub_type===$k?'btn-primary':'btn-secondary' ?> btn-sm"><?= $v ?></a>
                        <?php endforeach; ?>
                    </form>
                    <?php
                    $sdesc=[
                        'single'=>'Finds the guest who made the single highest-value booking. The subquery returns exactly one value.',
                        'in'    =>'Finds all guests who have at least one booking over ৳10,000. Subquery returns a list.',
                        'max'   =>'Finds the booking(s) with the maximum total amount using a single-value MAX subquery.',
                        'corr'  =>'For each room type, finds the most expensive room. Subquery runs once per row (correlated).',
                        'exists'=>'Finds rooms that have at least one paid booking. EXISTS returns true/false per row.',
                        'having'=>'Groups by room type, then uses a subquery in HAVING to keep only above-average revenue types.',
                        'notin' =>'Finds guests who have NEVER made a paid booking. Uses NOT IN with a subquery.',
                    ];
                    ?>
                    <div class="info-box"><?= $sdesc[$sub_type] ?></div>
                    <?php if(!empty($sub_results)): ?>
                    <div class="table-wrap"><table>
                        <thead><tr><?php foreach(array_keys($sub_results[0]) as $col): ?><th><?= htmlspecialchars($col) ?></th><?php endforeach; ?></tr></thead>
                        <tbody>
                        <?php foreach($sub_results as $r): ?>
                        <tr><?php foreach($r as $v): ?><td><?= htmlspecialchars($v??'NULL') ?></td><?php endforeach; ?></tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table></div>
                    <?php else: ?>
                    <p style="color:#718096;">No results for this query.</p>
                    <?php endif; ?>
                </div>
            </div>

            <?php elseif ($tab === 'groupby'): ?>
            <!-- GROUP BY + HAVING — user controls grouping and HAVING threshold -->
            <div class="card">
                <div class="card-header"><h3>GROUP BY + HAVING <span class="db-badge">User-controlled grouping + HAVING threshold</span></h3></div>
                <div class="card-body">
                    <form method="GET" class="filter-bar">
                        <input type="hidden" name="tab" value="groupby">
                        <select name="group_by">
                            <option <?= $group_by==='room_type'?'selected':'' ?> value="room_type">Group by Room Type</option>
                            <option <?= $group_by==='payment'?'selected':'' ?> value="payment">Group by Payment Status</option>
                            <option <?= $group_by==='month'?'selected':'' ?> value="month">Group by Month</option>
                        </select>
                        <label style="font-size:13px;white-space:nowrap;">HAVING revenue ≥ ৳</label>
                        <input type="number" name="having_min" value="<?= $having_min ?>" style="width:100px;" placeholder="0">
                        <button class="btn btn-primary btn-sm">Run</button>
                    </form>
                    <div class="query-box">SELECT group_column,
  COUNT(*) AS total_bookings,
  SUM(total_amount) AS total_revenue,
  AVG(total_amount) AS avg_revenue,
  MAX(total_amount), MIN(total_amount)
FROM bookings [JOIN rooms]
GROUP BY <?= htmlspecialchars($group_by) ?>

HAVING SUM(total_amount) >= <?= $having_min ?>

ORDER BY total_revenue DESC;</div>
                    <?php if(empty($group_results)): ?>
                    <p style="color:#718096;">No groups match HAVING threshold of ৳<?= $having_min ?>.</p>
                    <?php else: ?>
                    <div class="table-wrap"><table>
                        <thead><tr><th>Group</th><th>COUNT(*)</th><th>SUM (Revenue)</th><th>AVG</th><th>MAX</th><th>MIN</th></tr></thead>
                        <tbody>
                        <?php foreach($group_results as $r): ?>
                        <tr>
                            <td><strong><?= htmlspecialchars($r['group_label']) ?></strong></td>
                            <td><?= $r['total_bookings'] ?></td>
                            <td>৳<?= number_format($r['total_revenue'],0) ?></td>
                            <td>৳<?= number_format($r['avg_revenue'],0) ?></td>
                            <td>৳<?= number_format($r['max_booking'],0) ?></td>
                            <td>৳<?= number_format($r['min_booking'],0) ?></td>
                        </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table></div>
                    <?php endif; ?>
                </div>
            </div>

            <?php endif; ?>

        </div>
    </div>
</div>
</body>
</html>
