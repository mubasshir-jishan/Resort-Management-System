<?php // sidebar.php ?>
<div class="sidebar">
    <div class="sidebar-header">
        <h3>🏨 Resort<br>Management</h3>
        <span>Admin Panel v2</span>
    </div>
    <nav>
        <?php $page = basename($_SERVER['PHP_SELF']); ?>
        <a href="dashboard.php" class="<?= $page==='dashboard.php'?'active':'' ?>">📊 Dashboard</a>
        <a href="rooms.php"     class="<?= $page==='rooms.php'    ?'active':'' ?>">🛏 Rooms</a>
        <a href="guests.php"    class="<?= $page==='guests.php'   ?'active':'' ?>">👤 Guests</a>
        <a href="bookings.php"  class="<?= $page==='bookings.php' ?'active':'' ?>">📋 Bookings</a>
        <a href="reports.php"   class="<?= $page==='reports.php'  ?'active':'' ?>">📈 Reports</a>
        <a href="audit_log.php" class="<?= $page==='audit_log.php'?'active':'' ?>">🔍 Audit Log</a>
    </nav>
    <div class="sidebar-footer">v2.0 &mdash; All logic in DB</div>
</div>
