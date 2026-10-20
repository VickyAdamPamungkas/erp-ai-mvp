CREATE DATABASE IF NOT EXISTS erp_ai_mvp CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
USE erp_ai_mvp;

CREATE TABLE users (
  id INT AUTO_INCREMENT PRIMARY KEY,
  username VARCHAR(50) NOT NULL UNIQUE,
  password VARCHAR(255) NOT NULL,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

CREATE TABLE materials (
  id INT AUTO_INCREMENT PRIMARY KEY,
  name VARCHAR(100) NOT NULL,
  unit VARCHAR(20) NOT NULL,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

CREATE TABLE locations (
  id INT AUTO_INCREMENT PRIMARY KEY,
  name VARCHAR(100) NOT NULL,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

CREATE TABLE stock (
  id INT AUTO_INCREMENT PRIMARY KEY,
  material_id INT NOT NULL,
  location_id INT NOT NULL,
  quantity DECIMAL(12,2) NOT NULL DEFAULT 0,
  UNIQUE KEY uq_stock (material_id, location_id),
  FOREIGN KEY (material_id) REFERENCES materials(id),
  FOREIGN KEY (location_id) REFERENCES locations(id)
);

CREATE TABLE stock_transactions (
  id INT AUTO_INCREMENT PRIMARY KEY,
  transaction_type ENUM('IN','UPDATE','TRANSFER') NOT NULL,
  material_id INT NOT NULL,
  quantity DECIMAL(12,2) NOT NULL,
  source_location_id INT NULL,
  destination_location_id INT NULL,
  note VARCHAR(255) NULL,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (material_id) REFERENCES materials(id),
  FOREIGN KEY (source_location_id) REFERENCES locations(id),
  FOREIGN KEY (destination_location_id) REFERENCES locations(id)
);

CREATE TABLE ai_requests (
  id INT AUTO_INCREMENT PRIMARY KEY,
  user_input TEXT NOT NULL,
  intent VARCHAR(50) NULL,
  parameters_json JSON NULL,
  validation_status ENUM('PENDING','VALID','INVALID') DEFAULT 'PENDING',
  validation_message TEXT NULL,
  user_confirmed TINYINT(1) DEFAULT 0,
  execution_status ENUM('PENDING','EXECUTED','CANCELLED','FAILED') DEFAULT 'PENDING',
  error_type VARCHAR(50) NULL,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

INSERT INTO users (username, password) VALUES ('admin', SHA2('admin123', 256));

INSERT INTO materials (name, unit) VALUES
('Kayu Jati','kg'),
('Kayu Mahoni','kg'),
('Kayu Pinus','kg');

INSERT INTO locations (name) VALUES ('Gudang A'), ('Gudang B');

INSERT INTO stock (material_id, location_id, quantity) VALUES
(1,1,100),(1,2,30),
(2,1,75),(2,2,20),
(3,1,50),(3,2,10);
