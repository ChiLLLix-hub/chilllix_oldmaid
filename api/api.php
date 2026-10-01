<?php
// api/api.php
header('Content-Type: application/json');

try {
    require_once 'db.php';
} catch (PDOException $e) {
    http_response_code(500);
    echo json_encode(['error' => 'Database connection failed. Check your hosting database credentials.']);
    exit;
} catch (RuntimeException $e) {
    http_response_code(500);
    echo json_encode(['error' => $e->getMessage()]);
    exit;
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['error' => 'Server configuration error: ' . $e->getMessage()]);
    exit;
}

$config = require __DIR__ . '/config.php';

function triggerRealtimeUpdate($roomCode, $newVersion) {
    global $config;

    $relayUrl = trim((string)($config['realtime_relay_url'] ?? ''));
    $relaySecret = (string)($config['realtime_relay_secret'] ?? '');

    if ($relayUrl === '' || $relaySecret === '') {
        return;
    }

    $payload = json_encode([
        'roomCode' => $roomCode,
        'version' => (int) $newVersion,
    ]);

    $signature = hash_hmac('sha256', $payload, $relaySecret);

    $curl = curl_init(rtrim($relayUrl, '/') . '/events/state-updated');
    curl_setopt_array($curl, [
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => $payload,
        CURLOPT_HTTPHEADER => [
            'Content-Type: application/json',
            'X-Relay-Signature: ' . $signature,
        ],
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 5,
    ]);

    $response = curl_exec($curl);
    $statusCode = curl_getinfo($curl, CURLINFO_RESPONSE_CODE);

    if ($response === false || $statusCode < 200 || $statusCode >= 300) {
        error_log('Realtime relay notification failed: ' . curl_error($curl));
    }

    curl_close($curl);
}

$action = $_GET['action'] ?? '';
$input = json_decode(file_get_contents('php://input'), true) ?? [];

function bumpVersion($pdo, $roomId) {
    $stmt = $pdo->prepare("UPDATE oldmaid_rooms SET state_version = state_version + 1 WHERE id = ?");
    $stmt->execute([$roomId]);
    
    $stmt = $pdo->prepare("SELECT room_code, state_version FROM oldmaid_rooms WHERE id = ?");
    $stmt->execute([$roomId]);
    $room = $stmt->fetch();
    
    if ($room) {
        triggerRealtimeUpdate($room['room_code'], $room['state_version']);
    }
}

// Check if any player has escaped or if only 1 player remains (game over)
function checkEscapeAndFinish($pdo, $roomId) {
    // 1. Mark players with 0 cards as escaped
    $stmt = $pdo->prepare("
        SELECT p.id, p.player_name, p.is_escaped,
               (SELECT COUNT(*) FROM oldmaid_cards c WHERE c.room_id = p.room_id AND c.player_id = p.id AND c.location = 'hand') as hand_count
        FROM oldmaid_players p 
        WHERE p.room_id = ? AND p.is_spectator = 0
    ");
    $stmt->execute([$roomId]);
    $players = $stmt->fetchAll();

    $stmtRank = $pdo->prepare("SELECT MAX(escape_rank) FROM oldmaid_players WHERE room_id = ?");
    $stmtRank->execute([$roomId]);
    $currentMaxRank = (int)$stmtRank->fetchColumn();

    foreach ($players as $p) {
        if ((int)$p['hand_count'] === 0 && (int)$p['is_escaped'] === 0) {
            $currentMaxRank++;
            $pdo->prepare("UPDATE oldmaid_players SET is_escaped = 1, escape_rank = ? WHERE id = ?")
                ->execute([$currentMaxRank, $p['id']]);
        }
    }

    // 2. Count active players who have NOT escaped
    $stmt = $pdo->prepare("SELECT id, player_name FROM oldmaid_players WHERE room_id = ? AND is_spectator = 0 AND is_escaped = 0");
    $stmt->execute([$roomId]);
    $remaining = $stmt->fetchAll();

    if (count($remaining) === 1) {
        // Last player is holding the Old Maid and LOSES!
        $loser = $remaining[0];
        $pdo->prepare("UPDATE oldmaid_rooms SET status = 'finished', loser_name = ? WHERE id = ?")
            ->execute([$loser['player_name'], $roomId]);
        return true;
    } elseif (count($remaining) === 0) {
        $pdo->prepare("UPDATE oldmaid_rooms SET status = 'finished', loser_name = 'Nobody' WHERE id = ?")
            ->execute([$roomId]);
        return true;
    }

    return false;
}

// Find next active player turn index
function getNextActiveTurnIndex($pdo, $roomId, $currentIdx, $direction = 1) {
    $stmt = $pdo->prepare("SELECT turn_order, is_escaped FROM oldmaid_players WHERE room_id = ? AND is_spectator = 0 ORDER BY turn_order ASC");
    $stmt->execute([$roomId]);
    $players = $stmt->fetchAll();
    $total = count($players);

    if ($total <= 1) return 0;

    $next = $currentIdx;
    for ($i = 0; $i < $total; $i++) {
        $next = ($next + $direction + ($total * 10)) % $total;
        $candidate = $players[$next] ?? null;
        if ($candidate && (int)$candidate['is_escaped'] === 0) {
            return (int)$candidate['turn_order'];
        }
    }
    return $next;
}

// Remove disconnected or leaving player
function removePlayer($pdo, $roomId, $playerId, $playerTurn) {
    $stmt = $pdo->prepare("SELECT * FROM oldmaid_rooms WHERE id = ?");
    $stmt->execute([$roomId]);
    $room = $stmt->fetch();
    if (!$room) return false;

    $stmt = $pdo->prepare("SELECT is_spectator FROM oldmaid_players WHERE id = ?");
    $stmt->execute([$playerId]);
    $isSpec = (int)$stmt->fetchColumn();

    $pdo->prepare("UPDATE oldmaid_cards SET location = 'deck', player_id = NULL WHERE player_id = ?")->execute([$playerId]);
    $pdo->prepare("DELETE FROM oldmaid_players WHERE id = ?")->execute([$playerId]);

    if ($isSpec === 1) {
        bumpVersion($pdo, $roomId);
        return 'removed';
    }

    $stmt = $pdo->prepare("SELECT id, player_name, turn_order FROM oldmaid_players WHERE room_id = ? AND is_spectator = 0 ORDER BY turn_order ASC");
    $stmt->execute([$roomId]);
    $remaining = $stmt->fetchAll();
    $newTotal = count($remaining);

    if ($newTotal === 0) {
        $pdo->prepare("DELETE FROM oldmaid_rooms WHERE id = ?")->execute([$roomId]);
        return 'destroyed';
    }

    if ($newTotal === 1 && $room['status'] === 'playing') {
        $pdo->prepare("UPDATE oldmaid_rooms SET status = 'finished', loser_name = 'Game Aborted' WHERE id = ?")
            ->execute([$roomId]);
        bumpVersion($pdo, $roomId);
        return 'finished';
    }

    if ($newTotal === 1 && $room['status'] === 'lobby' && $playerTurn === 0) {
        $pdo->prepare("DELETE FROM oldmaid_rooms WHERE id = ?")->execute([$roomId]);
        return 'destroyed';
    }

    if ($room['status'] === 'lobby') {
        shuffle($remaining);
    }

    $newIdx = 0;
    foreach ($remaining as $p) {
        $pdo->prepare("UPDATE oldmaid_players SET turn_order = ? WHERE id = ?")->execute([$newIdx, $p['id']]);
        $newIdx++;
    }

    if ($room['status'] === 'playing' && $newTotal > 1) {
        $currIdx = getNextActiveTurnIndex($pdo, $roomId, (int)$room['current_player_idx'], (int)$room['play_direction']);
        $pdo->prepare("UPDATE oldmaid_rooms SET current_player_idx = ? WHERE id = ?")->execute([$currIdx, $roomId]);
    }

    bumpVersion($pdo, $roomId);
    return 'removed';
}

// -----------------------------------------------------------------------------
// PUBLIC ACTIONS
// -----------------------------------------------------------------------------

if ($action === 'get_lobby_rooms') {
    $pdo->exec("DELETE FROM oldmaid_players WHERE TIMESTAMPDIFF(SECOND, last_ping, CURRENT_TIMESTAMP) > 60");
    $pdo->exec("DELETE FROM oldmaid_rooms WHERE id NOT IN (SELECT DISTINCT room_id FROM oldmaid_players)");

    $stmt = $pdo->query("
        SELECT r.room_code, r.status, 
               (SELECT COUNT(*) FROM oldmaid_players p WHERE p.room_id = r.id AND p.is_spectator = 0) as player_count,
               (SELECT COUNT(*) FROM oldmaid_players p WHERE p.room_id = r.id) as total_connections,
               (SELECT player_name FROM oldmaid_players p2 WHERE p2.room_id = r.id AND p2.turn_order = 0 LIMIT 1) as host_name
        FROM oldmaid_rooms r 
        WHERE r.status != 'finished' 
        ORDER BY r.id DESC LIMIT 5
    ");
    sendResponse(['rooms' => $stmt->fetchAll()]);
}

if ($action === 'create_room') {
    $playerName = trim($input['player_name'] ?? '');
    if (empty($playerName)) sendResponse(['error' => 'Player name cannot be empty!'], 400);

    $stmt = $pdo->query("SELECT COUNT(*) FROM oldmaid_rooms");
    if ((int)$stmt->fetchColumn() >= 5) {
        sendResponse(['error' => 'Server is full! Maximum of 5 active rooms reached.'], 403);
    }

    $roomCode = strtoupper(substr(bin2hex(random_bytes(4)), 0, 5));
    $token = bin2hex(random_bytes(16));

    $stmt = $pdo->prepare("INSERT INTO oldmaid_rooms (room_code) VALUES (?)");
    $stmt->execute([$roomCode]);
    $roomId = $pdo->lastInsertId();

    $stmt = $pdo->prepare("INSERT INTO oldmaid_players (room_id, player_name, session_token, turn_order) VALUES (?, ?, ?, 0)");
    $stmt->execute([$roomId, $playerName, $token]);
    $playerId = $pdo->lastInsertId();

    sendResponse([
        'room_code' => $roomCode,
        'token' => $token,
        'player_id' => $playerId,
        'is_spectator' => 0
    ]);
}

if ($action === 'join_room') {
    $playerName = trim($input['player_name'] ?? '');
    $roomCode = strtoupper(trim($input['room_code'] ?? ''));

    if (empty($playerName)) sendResponse(['error' => 'Player name cannot be empty!'], 400);

    $stmt = $pdo->prepare("SELECT * FROM oldmaid_rooms WHERE room_code = ?");
    $stmt->execute([$roomCode]);
    $room = $stmt->fetch();
    if (!$room) sendResponse(['error' => 'Room not found'], 404);

    $isSpectator = ($room['status'] === 'playing') ? 1 : 0;

    $stmt = $pdo->prepare("SELECT COUNT(*) FROM oldmaid_players WHERE room_id = ? AND LOWER(player_name) = LOWER(?)");
    $stmt->execute([$room['id'], $playerName]);
    if ((int)$stmt->fetchColumn() > 0) {
        sendResponse(['error' => 'Name taken! Please choose another.'], 400);
    }

    $stmt = $pdo->prepare("SELECT SUM(CASE WHEN is_spectator = 0 THEN 1 ELSE 0 END) as p_count, COUNT(*) as total_count FROM oldmaid_players WHERE room_id = ?");
    $stmt->execute([$room['id']]);
    $counts = $stmt->fetch();
    
    $pCount = (int)$counts['p_count'];
    $totalCount = (int)$counts['total_count'];

    if (!$isSpectator && $pCount >= 8) sendResponse(['error' => 'Room is full (max 8 players)!'], 403);
    if ($totalCount >= 15) sendResponse(['error' => 'Spectator limit reached!'], 403);

    $token = bin2hex(random_bytes(16));
    $turnOrder = $isSpectator ? -1 : $pCount; 

    $stmt = $pdo->prepare("INSERT INTO oldmaid_players (room_id, player_name, session_token, turn_order, is_spectator) VALUES (?, ?, ?, ?, ?)");
    $stmt->execute([$room['id'], $playerName, $token, $turnOrder, $isSpectator]);
    $playerId = $pdo->lastInsertId();

    bumpVersion($pdo, $room['id']);

    sendResponse([
        'room_code' => $roomCode,
        'token' => $token,
        'player_id' => $playerId,
        'is_spectator' => $isSpectator
    ]);
}

// -----------------------------------------------------------------------------
// AUTHENTICATED SESSION REQUIRED
// -----------------------------------------------------------------------------

$token = $_SERVER['HTTP_X_SESSION_TOKEN'] ?? ($input['token'] ?? null);
if (!$token) sendResponse(['error' => 'Unauthorized'], 401);

$stmt = $pdo->prepare("SELECT p.*, r.id as room_id, r.room_code, r.status, r.current_player_idx, r.play_direction, r.loser_name, r.state_version 
                       FROM oldmaid_players p JOIN oldmaid_rooms r ON p.room_id = r.id WHERE p.session_token = ?");
$stmt->execute([$token]);
$session = $stmt->fetch();
if (!$session) sendResponse(['error' => 'Invalid session'], 401);

$roomId = $session['room_id'];

if ($action === 'leave_room') {
    removePlayer($pdo, $roomId, $session['id'], $session['turn_order']);
    sendResponse(['success' => true]);
}

if ($action === 'start_game') {
    if ($session['status'] !== 'lobby') sendResponse(['error' => 'Game already running']);
    if ((int)$session['turn_order'] !== 0) sendResponse(['error' => 'Only host can start!'], 403);

    $stmt = $pdo->prepare("SELECT COUNT(*) FROM oldmaid_players WHERE room_id = ? AND is_spectator = 0");
    $stmt->execute([$roomId]);
    if ((int)$stmt->fetchColumn() < 2) sendResponse(['error' => 'Need at least 2 players to start!'], 400);

    // 25 Pairs + 1 Old Maid = 51 Cards
    $characterDefs = [
        ['id' => 'fox',       'title' => 'Clever Fox',     'icon' => '🦊', 'color' => 'amber'],
        ['id' => 'panda',     'title' => 'Boba Panda',     'icon' => '🐼', 'color' => 'emerald'],
        ['id' => 'lion',      'title' => 'Brave Lion',     'icon' => '🦁', 'color' => 'orange'],
        ['id' => 'koala',     'title' => 'Chill Koala',    'icon' => '🐨', 'color' => 'slate'],
        ['id' => 'penguin',   'title' => 'Chef Penguin',   'icon' => '🐧', 'color' => 'cyan'],
        ['id' => 'owl',       'title' => 'Scholar Owl',    'icon' => '🦉', 'color' => 'indigo'],
        ['id' => 'tiger',     'title' => 'Striped Tiger',  'icon' => '🐯', 'color' => 'amber'],
        ['id' => 'rabbit',    'title' => 'Magic Bunny',    'icon' => '🐰', 'color' => 'rose'],
        ['id' => 'monkey',    'title' => 'Ninja Monkey',   'icon' => '🐵', 'color' => 'yellow'],
        ['id' => 'bear',      'title' => 'Honey Bear',     'icon' => '🐻', 'color' => 'amber'],
        ['id' => 'frog',      'title' => 'Knight Frog',    'icon' => '🐸', 'color' => 'emerald'],
        ['id' => 'cat',       'title' => 'Lucky Neko',     'icon' => '🐱', 'color' => 'purple'],
        ['id' => 'dog',       'title' => 'Detective Pup',  'icon' => '🐶', 'color' => 'sky'],
        ['id' => 'deer',      'title' => 'Forest Deer',    'icon' => '🦌', 'color' => 'teal'],
        ['id' => 'hedgehog',  'title' => 'Spiky Buddy',    'icon' => '🦔', 'color' => 'zinc'],
        ['id' => 'sloth',     'title' => 'Lazy Sloth',     'icon' => '🦥', 'color' => 'stone'],
        ['id' => 'raccoon',   'title' => 'Bandit Raccoon', 'icon' => '🦝', 'color' => 'slate'],
        ['id' => 'duck',      'title' => 'Captain Duck',   'icon' => '🦆', 'color' => 'cyan'],
        ['id' => 'otter',     'title' => 'River Otter',    'icon' => '🦦', 'color' => 'blue'],
        ['id' => 'whale',     'title' => 'Cosmic Whale',   'icon' => '🐳', 'color' => 'blue'],
        ['id' => 'octopus',   'title' => 'Pirate Octo',    'icon' => '🐙', 'color' => 'rose'],
        ['id' => 'unicorn',   'title' => 'Star Unicorn',   'icon' => '🦄', 'color' => 'pink'],
        ['id' => 'dragon',    'title' => 'Flame Dragon',   'icon' => '🐲', 'color' => 'red'],
        ['id' => 'chameleon', 'title' => 'Sneaky Lizard',  'icon' => '🦎', 'color' => 'lime'],
        ['id' => 'parrot',    'title' => 'Sailor Parrot',  'icon' => '🦜', 'color' => 'emerald'],
    ];

    $deck = [];
    foreach ($characterDefs as $char) {
        $deck[] = [
            'pair_id' => $char['id'],
            'title'   => $char['title'],
            'icon'    => $char['icon'],
            'color'   => $char['color']
        ];
        $deck[] = [
            'pair_id' => $char['id'],
            'title'   => $char['title'],
            'icon'    => $char['icon'],
            'color'   => $char['color']
        ];
    }
    // 1 Unmatchable Old Maid Card
    $deck[] = [
        'pair_id' => 'old_maid',
        'title'   => 'The Old Maid',
        'icon'    => '🧙‍♀️',
        'color'   => 'oldmaid'
    ];

    shuffle($deck);

    // Fetch non-spectator players
    $stmt = $pdo->prepare("SELECT id FROM oldmaid_players WHERE room_id = ? AND is_spectator = 0 ORDER BY turn_order ASC");
    $stmt->execute([$roomId]);
    $players = $stmt->fetchAll(PDO::FETCH_COLUMN);
    $numPlayers = count($players);

    // Clear previous cards if any
    $pdo->prepare("DELETE FROM oldmaid_cards WHERE room_id = ?")->execute([$roomId]);

    // Insert cards and deal them completely to players in round-robin fashion
    $stmtInsert = $pdo->prepare("INSERT INTO oldmaid_cards (room_id, player_id, pair_id, card_title, card_symbol, card_color, location, card_order) VALUES (?, ?, ?, ?, ?, ?, 'hand', ?)");

    foreach ($deck as $idx => $card) {
        $assignedPlayerId = $players[$idx % $numPlayers];
        $stmtInsert->execute([
            $roomId,
            $assignedPlayerId,
            $card['pair_id'],
            $card['title'],
            $card['icon'],
            $card['color'],
            $idx
        ]);
    }

    // Reset player escape statuses
    $pdo->prepare("UPDATE oldmaid_players SET is_escaped = 0, escape_rank = NULL WHERE room_id = ?")->execute([$roomId]);

    $pdo->prepare("UPDATE oldmaid_rooms SET status = 'playing', current_player_idx = 0, play_direction = 1, loser_name = NULL WHERE id = ?")
        ->execute([$roomId]);

    bumpVersion($pdo, $roomId);
    sendResponse(['success' => true]);
}

if ($action === 'sync') {
    $clientVersion = (int)($_GET['v'] ?? 0);
    $pdo->prepare("UPDATE oldmaid_players SET last_ping = CURRENT_TIMESTAMP WHERE id = ?")->execute([$session['id']]);

    // Heartbeat cleanup for players disconnected > 45 seconds
    $stmt = $pdo->prepare("SELECT id, turn_order FROM oldmaid_players WHERE room_id = ? AND TIMESTAMPDIFF(SECOND, last_ping, CURRENT_TIMESTAMP) > 45");
    $stmt->execute([$roomId]);
    foreach ($stmt->fetchAll() as $dp) {
        if (removePlayer($pdo, $roomId, $dp['id'], $dp['turn_order']) === 'destroyed') {
            sendResponse(['room_closed' => true]);
        }
    }

    $currVer = $pdo->prepare("SELECT state_version FROM oldmaid_rooms WHERE id = ?");
    $currVer->execute([$roomId]);
    $currVer = $currVer->fetchColumn();

    if ($currVer === false) sendResponse(['room_closed' => true]);

    if ((int)$currVer > $clientVersion || $clientVersion === 0) {
        $stmt = $pdo->prepare("SELECT * FROM oldmaid_rooms WHERE id = ?");
        $stmt->execute([$roomId]);
        $room = $stmt->fetch();

        $stmt = $pdo->prepare("SELECT * FROM oldmaid_players WHERE room_id = ? ORDER BY turn_order ASC");
        $stmt->execute([$roomId]);
        $players = $stmt->fetchAll();

        foreach ($players as &$pl) {
            $stmt = $pdo->prepare("SELECT COUNT(*) FROM oldmaid_cards WHERE room_id = ? AND player_id = ? AND location = 'hand'");
            $stmt->execute([$roomId, $pl['id']]);
            $pl['card_count'] = (int)$stmt->fetchColumn();
        }

        $myHand = [];
        $suggestedPairs = [];
        if ((int)$session['is_spectator'] === 0) {
            $stmt = $pdo->prepare("SELECT id, pair_id, card_title, card_symbol, card_color, card_order FROM oldmaid_cards WHERE room_id = ? AND player_id = ? AND location = 'hand' ORDER BY card_order ASC, id ASC");
            $stmt->execute([$roomId, $session['id']]);
            $myHand = $stmt->fetchAll();

            // Detect pairs in hand (excluding old_maid)
            $grouped = [];
            foreach ($myHand as $c) {
                if ($c['pair_id'] !== 'old_maid') {
                    $grouped[$c['pair_id']][] = $c;
                }
            }
            foreach ($grouped as $pairId => $cardsInPair) {
                if (count($cardsInPair) >= 2) {
                    $suggestedPairs[] = [
                        'pair_id' => $pairId,
                        'title'   => $cardsInPair[0]['card_title'],
                        'icon'    => $cardsInPair[0]['card_symbol'],
                        'card_ids'=> [$cardsInPair[0]['id'], $cardsInPair[1]['id']]
                    ];
                }
            }
        }

        // Fetch recent discarded pairs
        $stmt = $pdo->prepare("SELECT id, pair_id, card_title, card_symbol, card_color, card_order FROM oldmaid_cards WHERE room_id = ? AND location = 'discard' ORDER BY card_order DESC LIMIT 10");
        $stmt->execute([$roomId]);
        $recentDiscards = $stmt->fetchAll();

        // Calculate who is the target opponent to draw from for the current active player
        $activeTurn = (int)$room['current_player_idx'];
        $direction = (int)$room['play_direction'];
        $targetTurn = getNextActiveTurnIndex($pdo, $roomId, $activeTurn, $direction);

        $stmt = $pdo->prepare("SELECT id, player_name FROM oldmaid_players WHERE room_id = ? AND turn_order = ? AND is_spectator = 0");
        $stmt->execute([$roomId, $targetTurn]);
        $targetOpponent = $stmt->fetch() ?: null;

        // DEV X-RAY
        $xrayHands = [];
        if (isset($_GET['dev_xray']) && isset($_GET['dev_pass']) && $_GET['dev_pass'] === 'spoon') {
            $stmt = $pdo->prepare("SELECT id, player_name FROM oldmaid_players WHERE room_id = ? AND is_spectator = 0 AND id != ?");
            $stmt->execute([$roomId, $session['id']]);
            $otherPlayers = $stmt->fetchAll();
            foreach ($otherPlayers as $op) {
                $stmtC = $pdo->prepare("SELECT id, pair_id, card_title, card_symbol, card_color FROM oldmaid_cards WHERE room_id = ? AND player_id = ? AND location = 'hand' ORDER BY id ASC");
                $stmtC->execute([$roomId, $op['id']]);
                $xrayHands[$op['id']] = $stmtC->fetchAll();
            }
        }

        sendResponse([
            'version' => $currVer,
            'room' => $room,
            'players' => $players,
            'my_hand' => $myHand,
            'suggested_pairs' => $suggestedPairs,
            'recent_discards' => $recentDiscards,
            'target_opponent' => $targetOpponent,
            'my_id' => $session['id'],
            'xray_hands' => $xrayHands
        ]);
    }
    sendResponse(['no_change' => true, 'version' => $clientVersion]);
}

// -----------------------------------------------------------------------------
// DISCARD PAIR (User chooses to discard a suggested pair or all pairs)
// -----------------------------------------------------------------------------
if ($action === 'discard_pair') {
    if ((int)$session['is_spectator'] === 1) sendResponse(['error' => 'Spectators cannot play!'], 403);

    $pairId = $input['pair_id'] ?? null;
    $discardAll = !empty($input['discard_all']);

    if (!$pairId && !$discardAll) {
        sendResponse(['error' => 'Invalid pair selection.'], 400);
    }

    $stmtMax = $pdo->prepare("SELECT COALESCE(MAX(card_order), 0) FROM oldmaid_cards WHERE room_id = ? AND location = 'discard'");
    $stmtMax->execute([$roomId]);
    $maxOrder = (int)$stmtMax->fetchColumn();

    $discardedPairsCount = 0;

    if ($discardAll) {
        // Find all pairs in hand
        $stmt = $pdo->prepare("SELECT id, pair_id FROM oldmaid_cards WHERE room_id = ? AND player_id = ? AND location = 'hand' AND pair_id != 'old_maid' ORDER BY id ASC");
        $stmt->execute([$roomId, $session['id']]);
        $hand = $stmt->fetchAll();

        $byPair = [];
        foreach ($hand as $c) {
            $byPair[$c['pair_id']][] = $c['id'];
        }

        foreach ($byPair as $pId => $cardIds) {
            while (count($cardIds) >= 2) {
                $c1 = array_shift($cardIds);
                $c2 = array_shift($cardIds);
                $maxOrder += 2;
                $pdo->prepare("UPDATE oldmaid_cards SET location = 'discard', player_id = NULL, card_order = ? WHERE id IN (?, ?)")
                    ->execute([$maxOrder, $c1, $c2]);
                $discardedPairsCount++;
            }
        }
    } else {
        // Discard specific pair
        if ($pairId === 'old_maid') {
            sendResponse(['error' => 'You cannot discard the Old Maid!'], 400);
        }

        $stmt = $pdo->prepare("SELECT id FROM oldmaid_cards WHERE room_id = ? AND player_id = ? AND location = 'hand' AND pair_id = ? LIMIT 2");
        $stmt->execute([$roomId, $session['id'], $pairId]);
        $matching = $stmt->fetchAll(PDO::FETCH_COLUMN);

        if (count($matching) < 2) {
            sendResponse(['error' => 'Pair not found in your hand!'], 400);
        }

        $maxOrder += 2;
        $pdo->prepare("UPDATE oldmaid_cards SET location = 'discard', player_id = NULL, card_order = ? WHERE id IN (?, ?)")
            ->execute([$maxOrder, $matching[0], $matching[1]]);
        $discardedPairsCount = 1;
    }

    // Check if player escaped or game ended
    checkEscapeAndFinish($pdo, $roomId);

    bumpVersion($pdo, $roomId);
    sendResponse(['success' => true, 'pairs_discarded' => $discardedPairsCount]);
}

// -----------------------------------------------------------------------------
// PICK A CARD FROM TARGET OPPONENT
// -----------------------------------------------------------------------------
if ($action === 'pick_card') {
    if ((int)$session['is_spectator'] === 1) sendResponse(['error' => 'Spectators cannot pick!'], 403);
    if ((int)$session['is_escaped'] === 1) sendResponse(['error' => 'You have already escaped!'], 400);

    // Verify it is caller's turn
    $stmt = $pdo->prepare("SELECT current_player_idx, play_direction, status FROM oldmaid_rooms WHERE id = ?");
    $stmt->execute([$roomId]);
    $room = $stmt->fetch();

    if ($room['status'] !== 'playing') sendResponse(['error' => 'Game is not in playing state.'], 400);

    if ((int)$room['current_player_idx'] !== (int)$session['turn_order']) {
        sendResponse(['error' => 'Not your turn!'], 400);
    }

    $direction = (int)$room['play_direction'];
    $targetTurn = getNextActiveTurnIndex($pdo, $roomId, (int)$room['current_player_idx'], $direction);

    $stmt = $pdo->prepare("SELECT id, player_name FROM oldmaid_players WHERE room_id = ? AND turn_order = ? AND is_spectator = 0 AND is_escaped = 0");
    $stmt->execute([$roomId, $targetTurn]);
    $targetOpponent = $stmt->fetch();

    if (!$targetOpponent) {
        sendResponse(['error' => 'No active opponent to pick from!'], 400);
    }

    // Target player's cards in hand
    $stmt = $pdo->prepare("SELECT id, pair_id, card_title, card_symbol, card_color FROM oldmaid_cards WHERE room_id = ? AND player_id = ? AND location = 'hand' ORDER BY card_order ASC, id ASC");
    $stmt->execute([$roomId, $targetOpponent['id']]);
    $targetCards = $stmt->fetchAll();

    if (empty($targetCards)) {
        sendResponse(['error' => 'Target player has no cards!'], 400);
    }

    $cardIndex = isset($input['card_index']) ? (int)$input['card_index'] : -1;
    if ($cardIndex < 0 || $cardIndex >= count($targetCards)) {
        // Random pick if index out of bounds
        $cardIndex = array_rand($targetCards);
    }

    $pickedCard = $targetCards[$cardIndex];

    // Transfer card to caller's hand
    $pdo->prepare("UPDATE oldmaid_cards SET player_id = ? WHERE id = ?")
        ->execute([$session['id'], $pickedCard['id']]);

    // Check if drawing this card creates a pair in caller's hand
    $stmtCheck = $pdo->prepare("SELECT COUNT(*) FROM oldmaid_cards WHERE room_id = ? AND player_id = ? AND location = 'hand' AND pair_id = ?");
    $stmtCheck->execute([$roomId, $session['id'], $pickedCard['pair_id']]);
    $countInHand = (int)$stmtCheck->fetchColumn();
    $hasPair = ($pickedCard['pair_id'] !== 'old_maid') && ($countInHand >= 2);

    // Advance turn to next active player
    $nextTurn = getNextActiveTurnIndex($pdo, $roomId, (int)$room['current_player_idx'], $direction);
    $pdo->prepare("UPDATE oldmaid_rooms SET current_player_idx = ? WHERE id = ?")
        ->execute([$nextTurn, $roomId]);

    // Check if target or caller escaped / game finished
    checkEscapeAndFinish($pdo, $roomId);

    bumpVersion($pdo, $roomId);

    sendResponse([
        'success'     => true,
        'picked_card' => $pickedCard,
        'has_pair'    => $hasPair,
        'from_player' => $targetOpponent['player_name']
    ]);
}

// -----------------------------------------------------------------------------
// SHUFFLE MY HAND (Mix up order so opponent can't guess card positions)
// -----------------------------------------------------------------------------
if ($action === 'shuffle_hand') {
    $stmt = $pdo->prepare("SELECT id FROM oldmaid_cards WHERE room_id = ? AND player_id = ? AND location = 'hand'");
    $stmt->execute([$roomId, $session['id']]);
    $cards = $stmt->fetchAll(PDO::FETCH_COLUMN);

    shuffle($cards);
    foreach ($cards as $idx => $cId) {
        $pdo->prepare("UPDATE oldmaid_cards SET card_order = ? WHERE id = ?")->execute([$idx, $cId]);
    }

    bumpVersion($pdo, $roomId);
    sendResponse(['success' => true]);
}

// -----------------------------------------------------------------------------
// EMOJI REACTIONS
// -----------------------------------------------------------------------------
if ($action === 'send_reaction') {
    $reaction = trim($input['reaction'] ?? '');
    if ($reaction) {
        $pdo->prepare("UPDATE oldmaid_players SET last_reaction = ?, reaction_time = CURRENT_TIMESTAMP WHERE id = ?")
            ->execute([$reaction, $session['id']]);
        bumpVersion($pdo, $roomId);
    }
    sendResponse(['success' => true]);
}

// -----------------------------------------------------------------------------
// DEV TOOLS
// -----------------------------------------------------------------------------
if ($action === 'dev_action') {
    if (!isset($input['dev_pass']) || $input['dev_pass'] !== 'spoon') {
        sendResponse(['error' => 'Unauthorized Developer Action!'], 403);
    }
    $devType = $input['dev_type'] ?? '';

    if ($devType === 'force_turn') {
        $stmt = $pdo->prepare("SELECT turn_order FROM oldmaid_players WHERE id = ?");
        $stmt->execute([$session['id']]);
        $pdo->prepare("UPDATE oldmaid_rooms SET current_player_idx = ? WHERE id = ?")
            ->execute([$stmt->fetchColumn(), $roomId]);
    } elseif ($devType === 'test_win') {
        // Discard all cards for caller except 0 cards to trigger escape
        $pdo->prepare("UPDATE oldmaid_cards SET location = 'discard', player_id = NULL WHERE player_id = ? AND pair_id != 'old_maid'")
            ->execute([$session['id']]);
        checkEscapeAndFinish($pdo, $roomId);
    } elseif ($devType === 'give_old_maid') {
        $pdo->prepare("UPDATE oldmaid_cards SET player_id = ? WHERE room_id = ? AND pair_id = 'old_maid'")
            ->execute([$session['id'], $roomId]);
    }
    bumpVersion($pdo, $roomId);
    sendResponse(['success' => true]);
}
