SET NAMES utf8mb4;
START TRANSACTION;

INSERT INTO clients (id, name, contact_person, phone, email, address) VALUES
    (1, '海岬選物', '客服甲', NULL, 'cape@example.test', '示範地址甲'),
    (2, '山線器材', '客服乙', NULL, 'ridge@example.test', '示範地址乙');
INSERT INTO warehouses (id, name, address) VALUES
    (1, '基隆港倉', '示範倉址一'), (2, '七堵轉運倉', '示範倉址二');

INSERT INTO sections (id, warehouse_id, client_id, name, capacity, rent_start, rent_end) VALUES
    (1, 1, 1, 'A-01', 400, '2026-01-01', '2027-12-31'),
    (2, 2, 1, 'B-02', 300, '2026-07-01', '2027-12-31'),
    (3, 1, 2, 'A-03', 250, '2026-04-01', '2027-12-31');
INSERT INTO goods (id, client_id, name, unit) VALUES
    (1, 1, '玻璃保鮮盒', '箱'), (2, 1, '棉布提袋', '箱'),
    (3, 2, '登山頭燈', '箱'), (4, 2, '摺疊推車', '台'), (5, 1, '矽膠杯墊', '箱');
INSERT INTO employees (id, name, job_title, hourly_rate, manager_id, overtime_hours, insurer) VALUES
    (1, '林示範', '人事主任', 320, NULL, 0, '示範保險'),
    (2, '江示範', '理貨員', 230, 1, 2, '示範保險'),
    (3, '徐示範', '倉務助理', 215, 1, 0, NULL);
-- Each lot starts with one receipt. The balance equals receipts less dispatches.
INSERT INTO inventory (id, goods_id, section_id, quantity, arrival_date) VALUES
    (1, 1, 1, 72, '2026-11-10'), (2, 2, 2, 36, '2026-11-12'),
    (3, 3, 3, 50, '2026-11-13'), (4, 4, 3, 0, '2026-11-14');
INSERT INTO movements (stock_id, type, quantity, moved_at, employee_id) VALUES
    (1, 'in', 90, '2026-11-10 09:20:00', 2),
    (2, 'in', 36, '2026-11-12 11:40:00', 2),
    (3, 'in', 50, '2026-11-13 14:10:00', 2),
    (4, 'in', 8, '2026-11-14 08:30:00', 2),
    (4, 'out', 8, '2026-11-15 15:30:00', 2),
    (1, 'out', 18, '2026-11-16 10:15:00', 2);

-- Local demonstration credentials only. These accounts share a bcrypt hash.
SET @demo_hash = '$2y$10$Eu48XIlVxa3Jq5i6i.jIfelA5aC00goAKZpYe2u4ab0YBsYkxrDpO';
INSERT INTO users (username, password_hash, role, client_id, employee_id) VALUES
    ('client', @demo_hash, 'client', 1, NULL), ('client2', @demo_hash, 'client', 2, NULL),
    ('employee', @demo_hash, 'employee', NULL, 2), ('hr', @demo_hash, 'hr', NULL, 1);

COMMIT;
