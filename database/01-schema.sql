SET NAMES utf8mb4;

CREATE TABLE clients (
    id INT PRIMARY KEY AUTO_INCREMENT,
    name VARCHAR(100) NOT NULL UNIQUE,
    contact_person VARCHAR(100), phone VARCHAR(40), email VARCHAR(180), address VARCHAR(255)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE warehouses (
    id INT PRIMARY KEY AUTO_INCREMENT,
    name VARCHAR(100) NOT NULL UNIQUE,
    address VARCHAR(255) NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE sections (
    id INT PRIMARY KEY AUTO_INCREMENT,
    warehouse_id INT NOT NULL,
    client_id INT NOT NULL,
    name VARCHAR(40) NOT NULL,
    capacity INT NOT NULL CHECK (capacity > 0),
    rent_start DATE,
    rent_end DATE,
    CHECK (rent_start IS NULL OR rent_end IS NULL OR rent_end >= rent_start),
    UNIQUE (warehouse_id, name),
    FOREIGN KEY (warehouse_id) REFERENCES warehouses(id),
    FOREIGN KEY (client_id) REFERENCES clients(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE goods (
    id INT PRIMARY KEY AUTO_INCREMENT,
    client_id INT NOT NULL,
    name VARCHAR(100) NOT NULL,
    unit VARCHAR(16) NOT NULL,
    UNIQUE (client_id, name),
    FOREIGN KEY (client_id) REFERENCES clients(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE employees (
    id INT PRIMARY KEY AUTO_INCREMENT,
    name VARCHAR(100) NOT NULL,
    job_title VARCHAR(80) NOT NULL,
    hourly_rate DECIMAL(8,2) NOT NULL CHECK (hourly_rate >= 0),
    manager_id INT,
    birth_date DATE,
    overtime_hours DECIMAL(6,2) NOT NULL DEFAULT 0 CHECK (overtime_hours >= 0),
    insurer VARCHAR(100),
    FOREIGN KEY (manager_id) REFERENCES employees(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE inventory (
    id INT PRIMARY KEY AUTO_INCREMENT,
    goods_id INT NOT NULL,
    section_id INT NOT NULL,
    quantity INT NOT NULL CHECK (quantity >= 0),
    arrival_date DATE NOT NULL,
    FOREIGN KEY (goods_id) REFERENCES goods(id),
    FOREIGN KEY (section_id) REFERENCES sections(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE movements (
    id INT PRIMARY KEY AUTO_INCREMENT,
    stock_id INT NOT NULL,
    type ENUM('in','out') NOT NULL,
    quantity INT NOT NULL CHECK (quantity > 0),
    moved_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    employee_id INT NOT NULL,
    FOREIGN KEY (stock_id) REFERENCES inventory(id),
    FOREIGN KEY (employee_id) REFERENCES employees(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE users (
    id INT PRIMARY KEY AUTO_INCREMENT,
    username VARCHAR(80) NOT NULL UNIQUE,
    password_hash VARCHAR(255) NOT NULL,
    role ENUM('client','employee','hr') NOT NULL,
    client_id INT,
    employee_id INT,
    CHECK ((role = 'client' AND client_id IS NOT NULL AND employee_id IS NULL)
        OR (role IN ('employee','hr') AND employee_id IS NOT NULL AND client_id IS NULL)),
    FOREIGN KEY (client_id) REFERENCES clients(id),
    FOREIGN KEY (employee_id) REFERENCES employees(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
