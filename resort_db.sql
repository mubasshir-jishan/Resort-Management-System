-- ============================================================
--  RESORT MANAGEMENT SYSTEM v2 — resort_db.sql
--  ALL 17 TOPICS LIVE IN THE DATABASE — NOT JUST DISPLAYED
--  Run in phpMyAdmin > DBMS_Project > SQL tab
-- ============================================================

CREATE DATABASE IF NOT EXISTS DBMS_Project;
USE DBMS_Project;

-- ============================================================
-- TABLE 1: admin
-- ============================================================
CREATE TABLE IF NOT EXISTS admin (
    admin_id   INT AUTO_INCREMENT PRIMARY KEY,
    username   VARCHAR(50)  NOT NULL UNIQUE,
    password   VARCHAR(255) NOT NULL,
    created_at DATETIME     DEFAULT NOW()
);
INSERT IGNORE INTO admin (username, password) VALUES ('admin', MD5('admin123'));

-- ============================================================
-- TABLE 2: rooms
-- ============================================================
CREATE TABLE IF NOT EXISTS rooms (
    room_id     INT AUTO_INCREMENT PRIMARY KEY,
    room_number VARCHAR(10)   NOT NULL UNIQUE,
    room_type   VARCHAR(50)   NOT NULL,
    price       DECIMAL(10,2) NOT NULL,
    status      VARCHAR(20)   DEFAULT 'available',
    floor_no    INT           DEFAULT 1,
    description VARCHAR(255),
    CHECK (status IN ('available','occupied','maintenance'))
);
INSERT IGNORE INTO rooms (room_number, room_type, price, status, floor_no, description) VALUES
('101','Standard',    2500.00,'available',   1,'Garden view standard room'),
('102','Standard',    2500.00,'available',   1,'Garden view standard room'),
('201','Deluxe',      4500.00,'available',   2,'Pool view deluxe room'),
('202','Deluxe',      4500.00,'occupied',    2,'Pool view deluxe room'),
('301','Suite',       8000.00,'available',   3,'Ocean view luxury suite'),
('302','Suite',       8000.00,'maintenance', 3,'Ocean view luxury suite'),
('401','Presidential',15000.00,'available',  4,'Top floor presidential suite');

-- ============================================================
-- TABLE 3: guests
-- ============================================================
CREATE TABLE IF NOT EXISTS guests (
    guest_id   INT AUTO_INCREMENT PRIMARY KEY,
    full_name  VARCHAR(100) NOT NULL,
    phone      VARCHAR(15)  UNIQUE,
    email      VARCHAR(100) UNIQUE,
    address    VARCHAR(255),
    id_type    ENUM('NID','Passport','Birth Certificate') DEFAULT 'NID',
    id_number  VARCHAR(20)  UNIQUE,
    created_at DATETIME     DEFAULT NOW()
);
INSERT IGNORE INTO guests (full_name, phone, email, address, id_type, id_number) VALUES
('Rahim Uddin',   '01711000001','rahim@email.com',  'Dhaka',     'NID',              '1234567890'),
('Karim Hossain', '01711000002','karim@email.com',  'Chittagong','NID',              '9876543210'),
('Nasrin Akter',  '01711000003','nasrin@email.com', 'Sylhet',    'Passport',         'AB1234567'),
('Farhan Ahmed',  '01711000004','farhan@email.com', 'Rajshahi',  'NID',              '1122334455'),
('Sadia Islam',   '01711000005','sadia@email.com',  'Khulna',    'Birth Certificate','19990101123456789');

-- ============================================================
-- TABLE 4: bookings
-- ============================================================
CREATE TABLE IF NOT EXISTS bookings (
    booking_id     INT AUTO_INCREMENT PRIMARY KEY,
    guest_id       INT NOT NULL,
    room_id        INT NOT NULL,
    check_in       DATE          NOT NULL,
    check_out      DATE          NOT NULL,
    total_amount   DECIMAL(10,2),          -- filled by TRIGGER
    nights         INT,                    -- filled by TRIGGER
    payment_status VARCHAR(20)  DEFAULT 'pending',
    booking_status VARCHAR(20)  DEFAULT 'confirmed',
    booked_at      DATETIME     DEFAULT NOW(),
    notes          VARCHAR(255),
    FOREIGN KEY (guest_id) REFERENCES guests(guest_id),
    FOREIGN KEY (room_id)  REFERENCES rooms(room_id)
);
INSERT IGNORE INTO bookings (booking_id,guest_id,room_id,check_in,check_out,total_amount,nights,payment_status,booking_status) VALUES
(1,1,1,'2026-04-10','2026-04-13',7500.00, 3,'paid',   'confirmed'),
(2,2,4,'2026-04-12','2026-04-15',13500.00,3,'paid',   'confirmed'),
(3,3,5,'2026-04-14','2026-04-18',32000.00,4,'pending','confirmed'),
(4,4,2,'2026-04-15','2026-04-16',2500.00, 1,'pending','confirmed'),
(5,5,3,'2026-04-20','2026-04-25',22500.00,5,'pending','confirmed');

-- ============================================================
-- TABLE 5: audit_log  — written ONLY by triggers, never by PHP
-- ============================================================
CREATE TABLE IF NOT EXISTS audit_log (
    log_id      INT AUTO_INCREMENT PRIMARY KEY,
    table_name  VARCHAR(50),
    action_type VARCHAR(20),
    record_id   INT,
    old_value   VARCHAR(500),
    new_value   VARCHAR(500),
    action_by   VARCHAR(50)  DEFAULT 'system',
    action_time DATETIME     DEFAULT NOW()
);

-- ============================================================
-- TABLE 6: sql_workshop_history — logs every query run in Reports
-- ============================================================
CREATE TABLE IF NOT EXISTS sql_workshop_history (
    history_id  INT AUTO_INCREMENT PRIMARY KEY,
    topic       VARCHAR(100),
    query_label VARCHAR(200),
    ran_at      DATETIME DEFAULT NOW()
);

-- ============================================================
-- VIEWS (Topic 15) — used by real pages, not just displayed
-- ============================================================

-- View 1: booking_details — used by bookings.php and dashboard.php
CREATE OR REPLACE VIEW booking_details AS
SELECT
    b.booking_id,
    g.full_name                             AS guest_name,
    g.phone,
    g.email,
    r.room_number,
    UPPER(r.room_type)                      AS room_type,
    b.check_in,
    b.check_out,
    DATEDIFF(b.check_out, b.check_in)       AS nights,
    r.price                                 AS price_per_night,
    b.total_amount,
    ROUND(b.total_amount / NULLIF(DATEDIFF(b.check_out,b.check_in),0), 2) AS avg_per_night,
    b.payment_status,
    b.booking_status,
    b.booked_at,
    b.notes,
    DATE_FORMAT(b.check_in,  '%d %b %Y')    AS check_in_fmt,
    DATE_FORMAT(b.check_out, '%d %b %Y')    AS check_out_fmt,
    DATE_FORMAT(b.booked_at, '%d %b %Y %H:%i') AS booked_at_fmt,
    DATEDIFF(NOW(), b.check_in)             AS days_since_checkin,
    CASE b.payment_status
        WHEN 'paid'     THEN 'Payment Complete'
        WHEN 'pending'  THEN 'Awaiting Payment'
        WHEN 'refunded' THEN 'Refund Issued'
        ELSE 'Unknown'
    END AS payment_label,
    CASE
        WHEN b.total_amount > 20000 THEN 'High Value'
        WHEN b.total_amount > 5000  THEN 'Mid Value'
        ELSE 'Standard'
    END AS booking_tier
FROM bookings b
INNER JOIN guests g ON b.guest_id = g.guest_id
INNER JOIN rooms  r ON b.room_id  = r.room_id;

-- View 2: revenue_by_room_type — used by dashboard.php revenue table
CREATE OR REPLACE VIEW revenue_by_room_type AS
SELECT
    r.room_type,
    COUNT(b.booking_id)              AS total_bookings,
    IFNULL(SUM(b.total_amount), 0)   AS total_revenue,
    IFNULL(AVG(b.total_amount), 0)   AS avg_revenue,
    IFNULL(MAX(b.total_amount), 0)   AS max_booking,
    IFNULL(MIN(b.total_amount), 0)   AS min_booking,
    SUM(CASE WHEN b.payment_status='paid'    THEN b.total_amount ELSE 0 END) AS paid_revenue,
    SUM(CASE WHEN b.payment_status='pending' THEN b.total_amount ELSE 0 END) AS pending_revenue
FROM bookings b
INNER JOIN rooms r ON b.room_id = r.room_id
GROUP BY r.room_type;

-- View 3: available_rooms — used in booking form dropdown
CREATE OR REPLACE VIEW available_rooms AS
SELECT
    room_id,
    room_number,
    UPPER(room_type)                      AS room_type,
    price,
    floor_no,
    IFNULL(description,'No description')  AS description
FROM rooms
WHERE status = 'available'
ORDER BY price ASC;

-- View 4: guest_booking_summary — used on guests.php detail panel
CREATE OR REPLACE VIEW guest_booking_summary AS
SELECT
    g.guest_id,
    g.full_name,
    g.phone,
    g.email,
    g.id_type,
    g.id_number,
    COUNT(b.booking_id)            AS total_bookings,
    IFNULL(SUM(b.total_amount),0)  AS total_spent,
    IFNULL(MAX(b.total_amount),0)  AS biggest_booking,
    IFNULL(MAX(b.check_in),  'Never') AS last_checkin,
    CASE
        WHEN COUNT(b.booking_id) = 0 THEN 'New Guest'
        WHEN COUNT(b.booking_id) < 3 THEN 'Regular'
        ELSE 'VIP'
    END AS guest_tier
FROM guests g
LEFT JOIN bookings b ON g.guest_id = b.guest_id
GROUP BY g.guest_id, g.full_name, g.phone, g.email, g.id_type, g.id_number;

-- View 5: room_occupancy_report — used on rooms.php stats panel
CREATE OR REPLACE VIEW room_occupancy_report AS
SELECT
    r.room_type,
    COUNT(r.room_id)                                              AS total_rooms,
    SUM(CASE WHEN r.status='available'   THEN 1 ELSE 0 END)     AS available,
    SUM(CASE WHEN r.status='occupied'    THEN 1 ELSE 0 END)     AS occupied,
    SUM(CASE WHEN r.status='maintenance' THEN 1 ELSE 0 END)     AS maintenance,
    ROUND(SUM(CASE WHEN r.status='occupied' THEN 1 ELSE 0 END)
        / COUNT(r.room_id) * 100, 1)                            AS occupancy_pct,
    ROUND(AVG(r.price), 2)                                      AS avg_price,
    COUNT(b.booking_id)                                         AS total_bookings_ever
FROM rooms r
LEFT JOIN bookings b ON r.room_id = b.room_id
GROUP BY r.room_type;

-- ============================================================
-- TRIGGERS (Topic 16) — all fire automatically on real actions
-- ============================================================

DROP TRIGGER IF EXISTS trg_calc_total_amount;
DROP TRIGGER IF EXISTS trg_after_booking_insert;
DROP TRIGGER IF EXISTS trg_room_status_on_booking;
DROP TRIGGER IF EXISTS trg_after_booking_update;
DROP TRIGGER IF EXISTS trg_after_booking_delete;
DROP TRIGGER IF EXISTS trg_validate_guest;
DROP TRIGGER IF EXISTS trg_after_guest_update;
DROP TRIGGER IF EXISTS trg_room_price_change_log;

DELIMITER $$

-- Trigger 1: BEFORE INSERT on bookings → calculate total_amount + nights
CREATE TRIGGER trg_calc_total_amount
BEFORE INSERT ON bookings
FOR EACH ROW
BEGIN
    DECLARE v_price DECIMAL(10,2);
    SELECT price INTO v_price FROM rooms WHERE room_id = NEW.room_id;
    SET NEW.nights       = DATEDIFF(NEW.check_out, NEW.check_in);
    SET NEW.total_amount = v_price * DATEDIFF(NEW.check_out, NEW.check_in);
END$$

-- Trigger 2: AFTER INSERT on bookings → write full audit log
CREATE TRIGGER trg_after_booking_insert
AFTER INSERT ON bookings
FOR EACH ROW
BEGIN
    INSERT INTO audit_log (table_name, action_type, record_id, old_value, new_value)
    VALUES (
        'bookings', 'INSERT', NEW.booking_id,
        NULL,
        CONCAT('guest_id=',NEW.guest_id,
               ' | room_id=',NEW.room_id,
               ' | check_in=',NEW.check_in,
               ' | check_out=',NEW.check_out,
               ' | total=',NEW.total_amount,
               ' | nights=',NEW.nights)
    );
END$$

-- Trigger 3: AFTER INSERT on bookings → mark room occupied
CREATE TRIGGER trg_room_status_on_booking
AFTER INSERT ON bookings
FOR EACH ROW
BEGIN
    UPDATE rooms SET status = 'occupied' WHERE room_id = NEW.room_id;
END$$

-- Trigger 4: AFTER UPDATE on bookings → log what changed (OLD vs NEW)
CREATE TRIGGER trg_after_booking_update
AFTER UPDATE ON bookings
FOR EACH ROW
BEGIN
    INSERT INTO audit_log (table_name, action_type, record_id, old_value, new_value)
    VALUES (
        'bookings', 'UPDATE', NEW.booking_id,
        CONCAT('status=',OLD.booking_status,' | payment=',OLD.payment_status),
        CONCAT('status=',NEW.booking_status,' | payment=',NEW.payment_status)
    );
END$$

-- Trigger 5: AFTER DELETE on bookings → log deletion + free room
CREATE TRIGGER trg_after_booking_delete
AFTER DELETE ON bookings
FOR EACH ROW
BEGIN
    INSERT INTO audit_log (table_name, action_type, record_id, old_value, new_value)
    VALUES (
        'bookings', 'DELETE', OLD.booking_id,
        CONCAT('guest_id=',OLD.guest_id,' | room_id=',OLD.room_id,' | total=',OLD.total_amount),
        NULL
    );
    UPDATE rooms SET status = 'available' WHERE room_id = OLD.room_id;
END$$

-- Trigger 6: BEFORE INSERT on guests → validate + clean name
CREATE TRIGGER trg_validate_guest
BEFORE INSERT ON guests
FOR EACH ROW
BEGIN
    IF TRIM(NEW.full_name) = '' THEN
        SIGNAL SQLSTATE '45000'
        SET MESSAGE_TEXT = 'Guest name cannot be empty.';
    END IF;
    SET NEW.full_name = TRIM(NEW.full_name);
    -- Capitalize first letter (MySQL has no INITCAP)
    SET NEW.full_name = CONCAT(
        UPPER(LEFT(TRIM(NEW.full_name), 1)),
        LOWER(SUBSTR(TRIM(NEW.full_name), 2))
    );
END$$

-- Trigger 7: AFTER UPDATE on guests → log profile changes
CREATE TRIGGER trg_after_guest_update
AFTER UPDATE ON guests
FOR EACH ROW
BEGIN
    INSERT INTO audit_log (table_name, action_type, record_id, old_value, new_value)
    VALUES (
        'guests', 'UPDATE', NEW.guest_id,
        CONCAT('name=',OLD.full_name,' | phone=',IFNULL(OLD.phone,'NULL')),
        CONCAT('name=',NEW.full_name,' | phone=',IFNULL(NEW.phone,'NULL'))
    );
END$$

-- Trigger 8: AFTER UPDATE on rooms → log price changes
CREATE TRIGGER trg_room_price_change_log
AFTER UPDATE ON rooms
FOR EACH ROW
BEGIN
    IF OLD.price != NEW.price THEN
        INSERT INTO audit_log (table_name, action_type, record_id, old_value, new_value)
        VALUES (
            'rooms', 'UPDATE', NEW.room_id,
            CONCAT('price=',OLD.price,' | status=',OLD.status),
            CONCAT('price=',NEW.price,' | status=',NEW.status)
        );
    ELSEIF OLD.status != NEW.status THEN
        INSERT INTO audit_log (table_name, action_type, record_id, old_value, new_value)
        VALUES (
            'rooms', 'UPDATE', NEW.room_id,
            CONCAT('status=',OLD.status),
            CONCAT('status=',NEW.status)
        );
    END IF;
END$$

DELIMITER ;

-- ============================================================
-- STORED PROCEDURES (Topic 17) — called by real pages
-- ============================================================

DROP PROCEDURE IF EXISTS sp_checkout;
DROP PROCEDURE IF EXISTS sp_room_availability;
DROP PROCEDURE IF EXISTS sp_monthly_revenue;
DROP PROCEDURE IF EXISTS sp_guest_statement;
DROP PROCEDURE IF EXISTS sp_apply_discount;

DELIMITER $$

-- Procedure 1: Checkout — called by bookings.php Checkout button
CREATE PROCEDURE sp_checkout(IN p_booking_id INT, OUT p_message VARCHAR(200))
BEGIN
    DECLARE v_room_id INT;
    DECLARE v_status  VARCHAR(20);

    SELECT room_id, booking_status
    INTO v_room_id, v_status
    FROM bookings WHERE booking_id = p_booking_id;

    IF v_status = 'checked_out' THEN
        SET p_message = 'Already checked out.';
    ELSEIF v_status = 'cancelled' THEN
        SET p_message = 'Cannot checkout a cancelled booking.';
    ELSE
        UPDATE bookings
        SET booking_status = 'checked_out', payment_status = 'paid'
        WHERE booking_id = p_booking_id;

        UPDATE rooms SET status = 'available' WHERE room_id = v_room_id;

        SET p_message = CONCAT('Checkout successful. Room freed. Booking #', p_booking_id, ' closed.');
    END IF;
END$$

-- Procedure 2: Room availability — called by Reports > Availability tab
CREATE PROCEDURE sp_room_availability(IN p_type VARCHAR(50))
BEGIN
    SELECT
        room_type,
        COUNT(*)                                                  AS total_rooms,
        SUM(CASE WHEN status='available'   THEN 1 ELSE 0 END)   AS available,
        SUM(CASE WHEN status='occupied'    THEN 1 ELSE 0 END)   AS occupied,
        SUM(CASE WHEN status='maintenance' THEN 1 ELSE 0 END)   AS under_maintenance,
        ROUND(AVG(price),2)                                      AS avg_price,
        MIN(price)                                               AS min_price,
        MAX(price)                                               AS max_price
    FROM rooms
    WHERE room_type = p_type OR p_type = 'ALL'
    GROUP BY room_type
    ORDER BY avg_price;
END$$

-- Procedure 3: Monthly revenue with CURSOR + LOOP — called by Reports > Revenue tab
CREATE PROCEDURE sp_monthly_revenue(IN p_year INT)
BEGIN
    DECLARE done    INT DEFAULT 0;
    DECLARE v_month INT;
    DECLARE v_total DECIMAL(10,2);
    DECLARE v_count INT;

    DECLARE cur CURSOR FOR
        SELECT MONTH(check_in), SUM(total_amount), COUNT(*)
        FROM bookings
        WHERE YEAR(check_in) = p_year AND booking_status != 'cancelled'
        GROUP BY MONTH(check_in)
        ORDER BY MONTH(check_in);

    DECLARE CONTINUE HANDLER FOR NOT FOUND SET done = 1;

    DROP TEMPORARY TABLE IF EXISTS tmp_revenue;
    CREATE TEMPORARY TABLE tmp_revenue (
        month_no   INT,
        month_name VARCHAR(20),
        revenue    DECIMAL(10,2),
        bookings   INT
    );

    OPEN cur;
    read_loop: LOOP
        FETCH cur INTO v_month, v_total, v_count;
        IF done THEN LEAVE read_loop; END IF;
        INSERT INTO tmp_revenue VALUES (
            v_month,
            CASE v_month
                WHEN 1 THEN 'January'   WHEN 2 THEN 'February'
                WHEN 3 THEN 'March'     WHEN 4 THEN 'April'
                WHEN 5 THEN 'May'       WHEN 6 THEN 'June'
                WHEN 7 THEN 'July'      WHEN 8 THEN 'August'
                WHEN 9 THEN 'September' WHEN 10 THEN 'October'
                WHEN 11 THEN 'November' WHEN 12 THEN 'December'
            END,
            v_total,
            v_count
        );
    END LOOP;
    CLOSE cur;

    SELECT month_no, month_name, revenue, bookings FROM tmp_revenue ORDER BY month_no;
END$$

-- Procedure 4: Guest statement — called by guests.php "Statement" button
CREATE PROCEDURE sp_guest_statement(IN p_guest_id INT)
BEGIN
    -- Full booking history for one guest with all computed fields
    SELECT
        b.booking_id,
        r.room_number,
        UPPER(r.room_type)                   AS room_type,
        b.check_in,
        b.check_out,
        DATEDIFF(b.check_out, b.check_in)    AS nights,
        r.price                              AS price_per_night,
        b.total_amount,
        b.payment_status,
        b.booking_status,
        DATE_FORMAT(b.booked_at,'%d %b %Y')  AS booked_on,
        CASE b.booking_status
            WHEN 'confirmed'   THEN 'Active Booking'
            WHEN 'checked_out' THEN 'Completed Stay'
            WHEN 'cancelled'   THEN 'Cancelled'
        END AS status_label
    FROM bookings b
    INNER JOIN rooms r ON b.room_id = r.room_id
    WHERE b.guest_id = p_guest_id
    ORDER BY b.check_in DESC;
END$$

-- Procedure 5: Apply discount — called by bookings.php discount action
-- Uses TRANSACTION internally: if discount calc fails, rolls back
CREATE PROCEDURE sp_apply_discount(IN p_booking_id INT, IN p_discount_pct DECIMAL(5,2), OUT p_message VARCHAR(200))
BEGIN
    DECLARE v_old_amount DECIMAL(10,2);
    DECLARE v_new_amount DECIMAL(10,2);
    DECLARE EXIT HANDLER FOR SQLEXCEPTION
    BEGIN
        ROLLBACK;
        SET p_message = 'Error applying discount. Transaction rolled back.';
    END;

    START TRANSACTION;

    SELECT total_amount INTO v_old_amount FROM bookings WHERE booking_id = p_booking_id;

    IF v_old_amount IS NULL THEN
        ROLLBACK;
        SET p_message = 'Booking not found.';
    ELSEIF p_discount_pct <= 0 OR p_discount_pct > 50 THEN
        ROLLBACK;
        SET p_message = 'Discount must be between 1% and 50%.';
    ELSE
        SET v_new_amount = ROUND(v_old_amount * (1 - p_discount_pct / 100), 2);

        UPDATE bookings SET total_amount = v_new_amount WHERE booking_id = p_booking_id;

        INSERT INTO audit_log (table_name, action_type, record_id, old_value, new_value)
        VALUES ('bookings','DISCOUNT', p_booking_id,
                CONCAT('amount=',v_old_amount),
                CONCAT('amount=',v_new_amount,' (',p_discount_pct,'% off)'));

        COMMIT;
        SET p_message = CONCAT('Discount applied. Amount changed from ৳',v_old_amount,' to ৳',v_new_amount);
    END IF;
END$$

DELIMITER ;

-- ============================================================
-- END OF resort_db.sql
-- ============================================================
