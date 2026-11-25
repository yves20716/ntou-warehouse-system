-- Existing installations: run once against the selected warehouse database.
-- New installations already receive this table from 01-schema.sql.
CREATE TABLE IF NOT EXISTS stock_requests (
    id INT PRIMARY KEY AUTO_INCREMENT,
    type ENUM('in','out') NOT NULL,
    goods_id INT,
    stock_id INT,
    quantity INT NOT NULL CHECK (quantity > 0),
    status ENUM('pending','completed','rejected','cancelled') NOT NULL DEFAULT 'pending',
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    handled_at DATETIME,
    employee_id INT,
    movement_id INT UNIQUE,
    response_note VARCHAR(255),
    CHECK ((type = 'in' AND goods_id IS NOT NULL AND stock_id IS NULL)
        OR (type = 'out' AND goods_id IS NULL AND stock_id IS NOT NULL)),
    CHECK ((status = 'pending' AND handled_at IS NULL AND employee_id IS NULL AND movement_id IS NULL)
        OR (status = 'completed' AND handled_at IS NOT NULL AND employee_id IS NOT NULL AND movement_id IS NOT NULL)
        OR (status = 'rejected' AND handled_at IS NOT NULL AND employee_id IS NOT NULL AND movement_id IS NULL)
        OR (status = 'cancelled' AND handled_at IS NOT NULL AND employee_id IS NULL AND movement_id IS NULL)),
    CHECK (status <> 'rejected' OR (response_note IS NOT NULL AND CHAR_LENGTH(TRIM(response_note)) > 0)),
    INDEX request_queue (status, created_at, id),
    FOREIGN KEY (goods_id) REFERENCES goods(id),
    FOREIGN KEY (stock_id) REFERENCES inventory(id),
    FOREIGN KEY (employee_id) REFERENCES employees(id),
    FOREIGN KEY (movement_id) REFERENCES movements(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
