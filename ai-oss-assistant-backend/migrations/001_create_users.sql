CREATE TABLE IF NOT EXISTS users (
  id INT AUTO_INCREMENT PRIMARY KEY,
  github_installation_id VARCHAR(64) NOT NULL,
  email VARCHAR(255) NOT NULL,
  spend_cap_usd DECIMAL(10,2) DEFAULT 5.00,
  digest_frequency ENUM('daily','every_3_days','weekly') DEFAULT 'daily',
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB;

INSERT IGNORE INTO users (id, github_installation_id, email) VALUES (1, 'inst_system_bot', 'bot@ai-oss-assistant.com');
