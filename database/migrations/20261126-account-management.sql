-- Run with a schema-owner account. This migration preserves business rows and is safe to rerun.
SET NAMES utf8mb4;
DELIMITER //
DROP PROCEDURE IF EXISTS migrate_accounts_20261126//
CREATE PROCEDURE migrate_accounts_20261126()
BEGIN
    DECLARE identity_check VARCHAR(64);
    IF NOT EXISTS (SELECT 1 FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'employees' AND COLUMN_NAME = 'monthly_salary') THEN
        ALTER TABLE employees ADD COLUMN monthly_salary DECIMAL(10,2) NULL CHECK (monthly_salary >= 0);
    END IF;
    IF NOT EXISTS (SELECT 1 FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'employees' AND COLUMN_NAME = 'employment_status') THEN
        ALTER TABLE employees ADD COLUMN employment_status ENUM('active','inactive') NOT NULL DEFAULT 'active';
    END IF;
    ALTER TABLE employees ALTER COLUMN hourly_rate SET DEFAULT 0;
    IF NOT EXISTS (SELECT 1 FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'users' AND COLUMN_NAME = 'status') THEN
        ALTER TABLE users ADD COLUMN status ENUM('pending','active','disabled') NOT NULL DEFAULT 'active';
    END IF;
    IF NOT EXISTS (SELECT 1 FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'users' AND COLUMN_NAME = 'auth_version') THEN
        ALTER TABLE users ADD COLUMN auth_version INT UNSIGNED NOT NULL DEFAULT 1;
    END IF;
    IF NOT EXISTS (SELECT 1 FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'users' AND COLUMN_NAME = 'password_set_at') THEN
        ALTER TABLE users ADD COLUMN password_set_at DATETIME NULL DEFAULT CURRENT_TIMESTAMP;
    END IF;
    IF NOT EXISTS (SELECT 1 FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'users' AND COLUMN_NAME = 'reset_requested_at') THEN
        ALTER TABLE users ADD COLUMN reset_requested_at DATETIME NULL;
    END IF;
    SELECT tc.CONSTRAINT_NAME INTO identity_check
    FROM information_schema.TABLE_CONSTRAINTS tc
    JOIN information_schema.CHECK_CONSTRAINTS cc
      ON cc.CONSTRAINT_SCHEMA = tc.CONSTRAINT_SCHEMA AND cc.CONSTRAINT_NAME = tc.CONSTRAINT_NAME
    WHERE tc.TABLE_SCHEMA = DATABASE() AND tc.TABLE_NAME = 'users'
      AND tc.CONSTRAINT_TYPE = 'CHECK' AND cc.CHECK_CLAUSE LIKE '%role%'
    LIMIT 1;
    IF identity_check IS NOT NULL THEN
        SET @drop_identity = CONCAT('ALTER TABLE users DROP CHECK `', REPLACE(identity_check, '`', '``'), '`');
        PREPARE account_migration_stmt FROM @drop_identity;
        EXECUTE account_migration_stmt;
        DEALLOCATE PREPARE account_migration_stmt;
    END IF;
    ALTER TABLE users MODIFY COLUMN role ENUM('client','employee','hr','admin') NOT NULL;
    UPDATE users SET role = 'admin', auth_version = auth_version + 1 WHERE role = 'hr';
    ALTER TABLE users MODIFY COLUMN role ENUM('client','employee','admin') NOT NULL;
    ALTER TABLE users ADD CONSTRAINT chk_user_identity CHECK
        ((role = 'client' AND client_id IS NOT NULL AND employee_id IS NULL)
        OR (role IN ('employee','admin') AND employee_id IS NOT NULL AND client_id IS NULL));
END//
CALL migrate_accounts_20261126()//
DROP PROCEDURE migrate_accounts_20261126//
DELIMITER ;

CREATE TABLE IF NOT EXISTS account_tokens (
    id INT PRIMARY KEY AUTO_INCREMENT,
    user_id INT NOT NULL,
    token_hash CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL UNIQUE,
    purpose ENUM('activate','reset') NOT NULL,
    auth_version INT UNSIGNED NOT NULL,
    expires_at DATETIME NOT NULL,
    used_at DATETIME,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX token_user (user_id, used_at),
    FOREIGN KEY (user_id) REFERENCES users(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS admin_audit (
    id INT PRIMARY KEY AUTO_INCREMENT,
    actor_user_id INT,
    target_user_id INT,
    target_employee_id INT,
    event VARCHAR(50) NOT NULL,
    details VARCHAR(500) NOT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX audit_time (created_at, id),
    FOREIGN KEY (actor_user_id) REFERENCES users(id),
    FOREIGN KEY (target_user_id) REFERENCES users(id),
    FOREIGN KEY (target_employee_id) REFERENCES employees(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
