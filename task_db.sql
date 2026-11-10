-- ============================================================================
-- COMP016 Lab Activity 5: task_db schema
--
-- Import with HeidiSQL (File > Run SQL file) or the mysql client:
--   mysql -u root < task_db.sql
--
-- With Docker this file runs automatically the first time the database
-- container starts (see docker-compose.yml). To re-run it, reset the volume:
--   docker compose down -v && docker compose up --build
-- ============================================================================

CREATE DATABASE IF NOT EXISTS `task_db`
    CHARACTER SET utf8mb4
    COLLATE utf8mb4_unicode_ci;

USE task_db;

CREATE TABLE IF NOT EXISTS tasks (
    id               INT AUTO_INCREMENT PRIMARY KEY,
    task_name        VARCHAR(255) NOT NULL,
    task_description TEXT NOT NULL,
    status           ENUM('pending', 'completed') DEFAULT 'pending',
    created_at       TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    -- Kept from Lab 4 so the priority feature survives the refactor.
    priority         ENUM('low', 'medium', 'high') NOT NULL DEFAULT 'medium'
);

-- Sample rows, inserted only while the table is empty so re-importing this
-- file never creates duplicates.
INSERT INTO tasks (task_name, task_description, status, priority)
SELECT * FROM (
    SELECT 'Buy Groceries'  AS task_name, 'Milk, Eggs, Bread'                   AS task_description, 'pending'   AS status, 'medium' AS priority
    UNION ALL
    SELECT 'Finish Lab 5',  'Refactor the Lab 4 to-do list to use MySQL and PDO', 'pending',   'high'
    UNION ALL
    SELECT 'Set up Docker', 'Run PHP and MySQL in containers with docker compose', 'completed', 'low'
) AS seed
WHERE NOT EXISTS (SELECT 1 FROM tasks);

-- ============================================================================
-- Reference: the CRUD statements used by index.php
-- (the app runs them as PDO prepared statements, with ? placeholders)
-- ============================================================================

-- C - CREATE
--   INSERT INTO tasks (task_name, task_description, priority) VALUES (?, ?, ?);

-- R - READ
--   SELECT * FROM tasks ORDER BY created_at DESC, id DESC;

-- U - UPDATE
--   UPDATE tasks SET task_name = ?, task_description = ?, priority = ? WHERE id = ?;
--   UPDATE tasks SET status = IF(status = 'pending', 'completed', 'pending') WHERE id = ?;

-- D - DELETE
--   DELETE FROM tasks WHERE id = ?;
--   DELETE FROM tasks WHERE status = 'completed';
