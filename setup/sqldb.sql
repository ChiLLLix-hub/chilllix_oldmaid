-- --------------------------------------------------------
-- Table structure for table `oldmaid_rooms`
-- --------------------------------------------------------
CREATE TABLE IF NOT EXISTS `oldmaid_rooms` (
  `id` INT AUTO_INCREMENT PRIMARY KEY,
  `room_code` VARCHAR(10) NOT NULL UNIQUE,
  `status` ENUM('lobby', 'playing', 'finished') DEFAULT 'lobby',
  `current_player_idx` INT DEFAULT 0,
  `play_direction` INT DEFAULT 1,
  `loser_name` VARCHAR(50) DEFAULT NULL,
  `state_version` INT DEFAULT 1,
  `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- --------------------------------------------------------
-- Table structure for table `oldmaid_players`
-- --------------------------------------------------------
CREATE TABLE IF NOT EXISTS `oldmaid_players` (
  `id` INT AUTO_INCREMENT PRIMARY KEY,
  `room_id` INT NOT NULL,
  `player_name` VARCHAR(50) NOT NULL,
  `session_token` VARCHAR(64) NOT NULL,
  `turn_order` INT NOT NULL,
  `is_spectator` TINYINT(1) DEFAULT 0,
  `is_escaped` TINYINT(1) DEFAULT 0,
  `escape_rank` INT DEFAULT NULL,
  `last_reaction` VARCHAR(30) DEFAULT NULL,
  `reaction_time` TIMESTAMP NULL DEFAULT NULL,
  `last_ping` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  FOREIGN KEY (`room_id`) REFERENCES `oldmaid_rooms`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- --------------------------------------------------------
-- Table structure for table `oldmaid_cards`
-- --------------------------------------------------------
CREATE TABLE IF NOT EXISTS `oldmaid_cards` (
  `id` INT AUTO_INCREMENT PRIMARY KEY,
  `room_id` INT NOT NULL,
  `player_id` INT DEFAULT NULL,
  `pair_id` VARCHAR(30) NOT NULL,
  `card_title` VARCHAR(50) NOT NULL,
  `card_symbol` VARCHAR(30) NOT NULL,
  `card_color` VARCHAR(20) NOT NULL,
  `location` ENUM('deck', 'hand', 'discard') DEFAULT 'deck',
  `card_order` INT DEFAULT 0,
  FOREIGN KEY (`room_id`) REFERENCES `oldmaid_rooms`(`id`) ON DELETE CASCADE,
  FOREIGN KEY (`player_id`) REFERENCES `oldmaid_players`(`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
