// js/game.js

// --- AUDIO SYSTEM ---
const sfx = {
  draw: new Audio('sounds/draw.mp3'),
  play: new Audio('sounds/play.mp3'),
  pair: new Audio('sounds/uno.mp3'),
  win:  new Audio('sounds/win.mp3')
};

Object.values(sfx).forEach(audio => audio.volume = 0.6);

function playSound(type) {
  if (sfx[type]) {
    sfx[type].currentTime = 0;
    sfx[type].play().catch(() => {});
  }
}

// --- REALTIME SETUP ---
const realtimeServiceUrl = document
  .querySelector('meta[name="oldmaid-realtime-url"]')
  ?.content
  ?.trim();
const realtime = realtimeServiceUrl ? io(realtimeServiceUrl, {
  transports: ["websocket", "polling"]
}) : null;

// --- GLOBAL VARIABLES ---
let sessionToken = null;
let myPlayerId = null;
let roomCode = null;
let stateVersion = 0;
let isPolling = false;
let isMyTurnGlobal = false;
let isProcessing = false;
let isXrayOn = false;
let devPassword = null;

let isSpectator = false;
let isEscaped = false;
let lobbyPollingInterval = null;
let gamePollingInterval = null;
let knownReactions = {};
let reactionCooldown = false;
let toastTimeout = null;
let lastRecentDiscards = [];
let justMatchedPairId = null;
let justMatchedTimeout = null;
let lastGameData = null;

document.addEventListener('DOMContentLoaded', startLobbyPolling);

function showToast(message) {
  const toast = document.getElementById('toastMessage');
  toast.innerText = message;
  toast.classList.remove('hidden');
  if (toastTimeout) clearTimeout(toastTimeout);
  toastTimeout = setTimeout(() => toast.classList.add('hidden'), 3000);
}

// --- LOBBY POLLING ---
function startLobbyPolling() {
  if (lobbyPollingInterval) return;
  fetchLobbyRooms();
  lobbyPollingInterval = setInterval(fetchLobbyRooms, 4000);
}

function stopLobbyPolling() {
  if (lobbyPollingInterval) {
    clearInterval(lobbyPollingInterval);
    lobbyPollingInterval = null;
  }
}

async function fetchLobbyRooms() {
  try {
    const res = await fetch('api/api.php?action=get_lobby_rooms');
    const data = await res.json();
    renderLobbyBrowser(data.rooms || []);
  } catch (e) {}
}

function renderLobbyBrowser(rooms) {
  let html = '';
  for (let i = 0; i < 5; i++) {
    if (rooms[i]) {
      const r = rooms[i];
      const isFull = parseInt(r.total_connections) >= 15;
      const isPlaying = r.status === 'playing';
      let statusText = isPlaying 
        ? `<span style="color:#f59e0b">In Progress</span>` 
        : `<span style="color:#10b981">Waiting</span>`;
      let btnHtml = isFull 
        ? `<button class="slot-btn btn-full" disabled>Full</button>` 
        : (isPlaying 
            ? `<button class="slot-btn btn-spectate" onclick="joinGameBrowser('${r.room_code}')">Spectate</button>` 
            : `<button class="slot-btn btn-join" onclick="joinGameBrowser('${r.room_code}')">Join</button>`);
      html += `
        <div class="room-slot">
          <div class="room-info">
            <span class="room-title">Room ${r.room_code}</span>
            <span class="room-meta">Host: ${r.host_name || 'Unknown'} • ${r.player_count}/8 Players • ${statusText}</span>
          </div>
          ${btnHtml}
        </div>`;
    } else {
      html += `<div class="room-slot empty" onclick="createGame()">+ Create New Room</div>`;
    }
  }
  document.getElementById('roomBrowser').innerHTML = html;
}

// --- ROOM MANAGEMENT ---
async function createGame() {
  const name = document.getElementById('playerName').value.trim();
  if (!name) return showToast('Name cannot be empty!');
  const res = await fetch('api/api.php?action=create_room', {
    method: 'POST',
    body: JSON.stringify({ player_name: name })
  });
  const data = await res.json();
  if (data.error) return showToast(data.error);
  initSession(data);
}

async function joinGameCode() {
  const name = document.getElementById('playerName').value.trim();
  const code = document.getElementById('joinRoomCode').value.trim();
  if (!name || !code) return showToast('Enter name and code!');
  const res = await fetch('api/api.php?action=join_room', {
    method: 'POST',
    body: JSON.stringify({ player_name: name, room_code: code })
  });
  const data = await res.json();
  if (data.error) return showToast(data.error);
  initSession(data);
}

function joinGameBrowser(code) {
  document.getElementById('joinRoomCode').value = code;
  joinGameCode();
}

function initSession(data) {
  sessionToken = data.token;
  myPlayerId = data.player_id;
  roomCode = data.room_code;
  isSpectator = data.is_spectator === 1;
  isEscaped = false;

  stopLobbyPolling();
  document.getElementById('roomBadge').innerText = `Room: ${roomCode}`;
  document.getElementById('displayRoomCode').innerText = roomCode;
  showScreen('waitingScreen');
  startSyncLoop();
}

async function copyRoomCode() {
  if (!roomCode) return;
  try {
    await navigator.clipboard.writeText(roomCode);
    showToast("Copied to clipboard!");
  } catch (e) {}
}

async function shareRoomCode() {
  if (!roomCode) return;
  const sd = { title: 'Old Maid', text: `Join Old Maid Room: ${roomCode}`, url: window.location.href };
  if (navigator.share) navigator.share(sd).catch(() => {});
  else window.open(`https://api.whatsapp.com/send?text=${encodeURIComponent(sd.text)}`);
}

function notifyServerLeave() {
  if (sessionToken) {
    navigator.sendBeacon(
      'api/api.php?action=leave_room',
      new Blob([JSON.stringify({ token: sessionToken })], { type: 'application/json' })
    );
  }
}
window.addEventListener('beforeunload', () => notifyServerLeave());

function returnToLobby(showAlert = false, msg = "") {
  if (showAlert && msg) showToast(msg);
  notifyServerLeave();
  isPolling = false;
  sessionToken = null;
  myPlayerId = null;
  roomCode = null;
  stateVersion = 0;
  isMyTurnGlobal = false;
  isSpectator = false;
  isEscaped = false;
  knownReactions = {};

  document.getElementById('roomBadge').innerText = 'Not Connected';
  document.getElementById('myTopNameDisplay').innerText = '';

  stopGamePolling();

  if (realtime) {
    realtime.off("state_updated");
    realtime.off("connect");
    realtime.off("connect_error");
    realtime.off("disconnect");
  }

  showScreen('lobbyScreen');
  startLobbyPolling();
}

async function startGame() {
  const res = await fetch('api/api.php?action=start_game', {
    method: 'POST',
    headers: { 'X-Session-Token': sessionToken }
  });
  const data = await res.json();
  if (data.error) showToast(data.error);
}

// --- SYNC ENGINE ---
function startGamePolling() {
  if (gamePollingInterval) return;
  gamePollingInterval = setInterval(() => {
    if (sessionToken) fetchGameState();
  }, 2000);
}

function stopGamePolling() {
  if (!gamePollingInterval) return;
  clearInterval(gamePollingInterval);
  gamePollingInterval = null;
}

async function fetchGameState() {
  try {
    const xray = isXrayOn ? `&dev_xray=1&dev_pass=${devPassword}` : '';
    const res = await fetch(`api/api.php?action=sync&v=${stateVersion}${xray}`, {
      headers: { 'X-Session-Token': sessionToken }
    });
    const data = await res.json();

    if (data.room_closed) {
      sessionToken = null;
      returnToLobby(true, "Room closed.");
      return;
    }

    if (!data.no_change && data.version > stateVersion) {
      stateVersion = data.version;
      renderGameState(data);
    }
  } catch (e) {}
}

async function startSyncLoop() {
  if (isPolling) return;
  isPolling = true;

  await fetchGameState();

  if (!realtime) {
    startGamePolling();
    return;
  }

  realtime.off("state_updated");
  realtime.off("connect");
  realtime.off("connect_error");
  realtime.off("disconnect");

  const joinRealtimeRoom = () => {
    stopGamePolling();
    realtime.emit("join_room", roomCode);
  };

  realtime.on("state_updated", (data) => {
    if (data.version > stateVersion) {
      fetchGameState();
    }
  });
  realtime.on("connect", joinRealtimeRoom);
  realtime.on("connect_error", startGamePolling);
  realtime.on("disconnect", startGamePolling);

  if (realtime.connected) {
    joinRealtimeRoom();
  } else {
    startGamePolling();
    realtime.connect();
  }
}

// --- GAME STATE RENDERING ---
function renderGameState(data) {
  const { room, players, my_hand, suggested_pairs, recent_discards, target_opponent: targetOpponent, xray_hands } = data;
  lastRecentDiscards = recent_discards || [];
  lastGameData = data;

  // Top header player name
  const me = players.find(p => p.id == myPlayerId);
  if (me) {
    document.getElementById('myTopNameDisplay').innerText = `👤 ${me.player_name}`;
    isEscaped = me.is_escaped == 1;
  }

  // 1. LOBBY STATE
  if (room.status === 'lobby') {
    showScreen('waitingScreen');
    document.getElementById('waitingPlayersList').innerHTML = players.map(p => {
      let tags = [];
      if (p.turn_order == 0) tags.push('<span style="color:#f59e0b;font-size:0.8rem">(Host)</span>');
      if (p.is_spectator == 1) tags.push('<span style="color:#3b82f6;font-size:0.8rem">(Spectating)</span>');
      return `<div style="padding: 6px 0;">👤 <strong>${p.player_name}</strong> ${tags.join(' ')}</div>`;
    }).join('');

    const startBtn = document.getElementById('startGameBtn');
    if (me && me.turn_order == 0) {
      startBtn.classList.remove('hidden');
      document.getElementById('waitingForHostMsg').classList.add('hidden');
      const activeCount = players.filter(p => p.is_spectator == 0).length;
      startBtn.disabled = activeCount < 2;
      startBtn.innerText = activeCount < 2 ? "Need 2 Players..." : "Start Game";
    } else {
      startBtn.classList.add('hidden');
      document.getElementById('waitingForHostMsg').classList.remove('hidden');
    }
    return;
  }

  // 2. FINISHED STATE
  if (room.status === 'finished') {
    isPolling = false;
    showScreen('winnerScreen');
    document.getElementById('loserName').innerText = room.loser_name || 'Nobody';
    playSound('win');

    // Build leaderboard
    const rankedEscaped = players
      .filter(p => p.is_escaped == 1 && p.escape_rank)
      .sort((a, b) => a.escape_rank - b.escape_rank);

    let lbHtml = '';
    const medals = ['🥇', '🥈', '🥉'];
    rankedEscaped.forEach((p, idx) => {
      const medal = medals[idx] || `#${idx + 1}`;
      lbHtml += `
        <div class="result-row escaped">
          <span>${medal} ${p.player_name}</span>
          <span style="color:#10b981;font-weight:bold;">Safe (Escaped)</span>
        </div>`;
    });

    const loserPlayer = players.find(p => p.player_name === room.loser_name);
    if (loserPlayer) {
      lbHtml += `
        <div class="result-row loser">
          <span>💀 ${loserPlayer.player_name}</span>
          <span style="color:#ef4444;font-weight:bold;">Old Maid</span>
        </div>`;
    }

    document.getElementById('resultsLeaderboard').innerHTML = lbHtml;
    return;
  }

  // 3. ARENA STATE
  showScreen('arenaScreen');

  const activePlayer = players.find(p => p.turn_order == room.current_player_idx);
  isMyTurnGlobal = activePlayer && activePlayer.id == myPlayerId;

  // Turn Indicator
  const turnText = document.getElementById('turnIndicator');
  if (isSpectator) {
    turnText.innerText = `Spectating: ${activePlayer ? activePlayer.player_name : '...'}'s Turn`;
    turnText.style.color = "#94a3b8";
    document.getElementById('spectatorBanner').classList.remove('hidden');
    document.getElementById('escapedBanner').classList.add('hidden');
  } else if (isEscaped) {
    turnText.innerText = `${activePlayer ? activePlayer.player_name : '...'}'s Turn`;
    turnText.style.color = "#10b981";
    document.getElementById('spectatorBanner').classList.add('hidden');
    document.getElementById('escapedBanner').classList.remove('hidden');
  } else {
    document.getElementById('spectatorBanner').classList.add('hidden');
    document.getElementById('escapedBanner').classList.add('hidden');
    if (isMyTurnGlobal) {
      const oppName = targetOpponent ? targetOpponent.player_name : 'Opponent';
      turnText.innerText = `YOUR TURN! Pick a card from ${oppName}!`;
      turnText.style.color = "#10b981";
    } else {
      turnText.innerText = `${activePlayer ? activePlayer.player_name : '...'}'s Turn`;
      turnText.style.color = "#f59e0b";
    }
  }

  // Reactions
  players.forEach(p => {
    if (p.last_reaction && p.reaction_time && p.reaction_time !== knownReactions[p.id]) {
      knownReactions[p.id] = p.reaction_time;
      if (p.id != myPlayerId) showReactionBubble(p.id, p.last_reaction);
    }
  });

  // Opponents Row
  document.getElementById('opponentsRow').innerHTML = players
    .filter(p => p.id != myPlayerId && p.is_spectator == 0)
    .map(p => {
      const isActive = p.turn_order == room.current_player_idx;
      const isTarget = isMyTurnGlobal && targetOpponent && targetOpponent.id == p.id;
      let statusPill = '';

      if (p.is_escaped == 1) {
        statusPill = `<span class="card-pill escaped-pill">Safe #${p.escape_rank}</span>`;
      } else {
        statusPill = `<span class="card-pill">${p.card_count} cards</span>`;
      }

      let xrayHtml = '';
      if (isXrayOn && xray_hands && xray_hands[p.id]) {
        xrayHtml = '<div style="display:flex;gap:3px;margin-top:4px;">' + xray_hands[p.id].map(c => 
          `<span title="${c.card_title}" style="font-size:1.2rem;">${c.card_symbol}</span>`
        ).join('') + '</div>';
      }

      return `
        <div style="display: flex; flex-direction: column; align-items: center;" id="opponent-${p.id}">
          <div class="opponent-badge ${isActive ? 'active-turn' : ''} ${isTarget ? 'target-victim' : ''}">
            <span>👤 ${p.player_name}</span>
            ${statusPill}
          </div>
          ${xrayHtml}
        </div>`;
    }).join('');

  // Target Opponent Pick Zone
  const pickContainer = document.getElementById('targetPickContainer');
  const targetFan = document.getElementById('targetCardsFan');
  if (isMyTurnGlobal && !isEscaped && !isSpectator && targetOpponent) {
    const oppObj = players.find(p => p.id == targetOpponent.id);
    const count = oppObj ? oppObj.card_count : 0;

    if (count > 0) {
      pickContainer.classList.remove('hidden');
      document.getElementById('targetPickTitle').innerText = `👉 Pick a card from ${targetOpponent.player_name}:`;
      let fanHtml = '';
      for (let i = 0; i < count; i++) {
        fanHtml += `<div class="card-back" onclick="executePickCard(${i}, event)"></div>`;
      }
      targetFan.innerHTML = fanHtml;
    } else {
      pickContainer.classList.add('hidden');
    }
  } else {
    pickContainer.classList.add('hidden');
  }

  // Table Stats
  const escapedCount = players.filter(p => p.is_escaped == 1).length;
  document.getElementById('escapedCountDisplay').innerText = escapedCount;
  const discardedCount = (recent_discards || []).length;
  document.getElementById('pairsCountDisplay').innerText = Math.floor(discardedCount / 2);

  // Pair Suggestion Tray
  const tray = document.getElementById('pairSuggestionTray');
  const chipsContainer = document.getElementById('pairSuggestionChips');
  if (!isEscaped && !isSpectator && suggested_pairs && suggested_pairs.length > 0) {
    tray.classList.remove('hidden');
    chipsContainer.innerHTML = suggested_pairs.map(pair => `
      <div class="pair-chip" onclick="discardPair('${pair.pair_id}')">
        <span>${pair.icon}</span>
        <span>${pair.title} (Discard)</span>
      </div>
    `).join('');
  } else {
    tray.classList.add('hidden');
  }

  // My Hand Dock
  const handContainer = document.getElementById('playerHand');
  handContainer.innerHTML = '';
  document.getElementById('myCardCountDisplay').innerText = `Your Hand: ${my_hand.length} cards`;

  // Determine which cards belong to suggested pairs to give them a glowing outline
  const pairCardIds = new Set();
  (suggested_pairs || []).forEach(p => p.card_ids.forEach(id => pairCardIds.add(id)));

  // Arrange hand so that paired cards sit side by side, keeping overall order stable otherwise
  const orderedHand = [];
  const placed = new Set();
  my_hand.forEach(c => {
    if (placed.has(c.id)) return;
    orderedHand.push(c);
    placed.add(c.id);
    if (pairCardIds.has(c.id)) {
      const partner = my_hand.find(x => x.id !== c.id && x.pair_id === c.pair_id && !placed.has(x.id));
      if (partner) {
        orderedHand.push(partner);
        placed.add(partner.id);
      }
    }
  });

  orderedHand.forEach(c => {
    const isPair = pairCardIds.has(c.id);
    const el = renderCard(c, true, isPair);
    if (isPair && c.pair_id === justMatchedPairId) {
      el.classList.add('just-matched');
    }
    el.dataset.cardId = c.id;
    handContainer.appendChild(el);
  });
}

// --- CARD ACTIONS ---
async function executePickCard(index, event) {
  if (isProcessing) return;
  isProcessing = true;

  const sourceEl = event ? event.currentTarget : null;
  const sourceRect = sourceEl ? sourceEl.getBoundingClientRect() : null;

  document.getElementById('processingOverlay').classList.remove('hidden');

  const res = await fetch('api/api.php?action=pick_card', {
    method: 'POST',
    headers: { 'X-Session-Token': sessionToken },
    body: JSON.stringify({ card_index: index })
  });
  const data = await res.json();

  isProcessing = false;
  document.getElementById('processingOverlay').classList.add('hidden');

  if (data.error) {
    showToast(data.error);
    return;
  }

  playSound('draw');

  if (data.picked_card) {
    const c = data.picked_card;
    await animateCardPickup(c, sourceRect);

    if (data.has_pair) {
      playSound('pair');
      showActionBanner(`You picked ${c.card_symbol} ${c.card_title} and made a PAIR! Check suggested pairs.`);
      triggerPairGlow(c.pair_id);
    } else if (c.pair_id === 'old_maid') {
      showActionBanner(`⚠️ OH NO! You picked THE OLD MAID! 🧙‍♀️`);
    } else {
      showActionBanner(`You drew ${c.card_symbol} ${c.card_title} from ${data.from_player}`);
    }
  }
}

// Slides a card from the opponent's fan (or a fallback point) into the player's hand dock.
function animateCardPickup(card, sourceRect) {
  return new Promise(resolve => {
    const handEl = document.getElementById('playerHand');
    if (!handEl) return resolve();
    const handRect = handEl.getBoundingClientRect();

    const start = sourceRect || {
      left: handRect.left + handRect.width / 2 - 35,
      top: handRect.top - 160,
      width: 70,
      height: 105
    };

    const flying = renderCard(card, false);
    flying.classList.add('card-flying');
    flying.style.left = `${start.left}px`;
    flying.style.top = `${start.top}px`;
    flying.style.width = `${start.width || 70}px`;
    flying.style.height = `${start.height || 105}px`;
    document.body.appendChild(flying);

    // Force reflow so the transition picks up the starting position before we move it.
    void flying.offsetWidth;

    const targetLeft = handRect.left + handRect.width / 2 - 40;
    const targetTop = handRect.top + handRect.height / 2 - 60;

    requestAnimationFrame(() => {
      flying.style.left = `${targetLeft}px`;
      flying.style.top = `${targetTop}px`;
      flying.style.width = '80px';
      flying.style.height = '120px';
      flying.style.opacity = '0.95';
    });

    let settled = false;
    const finish = () => {
      if (settled) return;
      settled = true;
      flying.remove();
      resolve();
    };
    flying.addEventListener('transitionend', finish, { once: true });
    setTimeout(finish, 700); // Safety net in case transitionend doesn't fire.
  });
}

// Marks the matched pair so the next render highlights it with a pulsing glow, then clears it.
function triggerPairGlow(pairId) {
  justMatchedPairId = pairId;
  if (justMatchedTimeout) clearTimeout(justMatchedTimeout);
  justMatchedTimeout = setTimeout(() => {
    justMatchedPairId = null;
    if (lastGameData) renderGameState(lastGameData);
  }, 2800);
}

async function discardPair(pairId) {
  if (isProcessing) return;
  isProcessing = true;
  document.getElementById('processingOverlay').classList.remove('hidden');

  const res = await fetch('api/api.php?action=discard_pair', {
    method: 'POST',
    headers: { 'X-Session-Token': sessionToken },
    body: JSON.stringify({ pair_id: pairId })
  });
  const data = await res.json();

  isProcessing = false;
  document.getElementById('processingOverlay').classList.add('hidden');

  if (data.error) return showToast(data.error);

  playSound('play');
  showToast("Pair discarded to table! 🎉");
}

async function discardAllPairs() {
  if (isProcessing) return;
  isProcessing = true;
  document.getElementById('processingOverlay').classList.remove('hidden');

  const res = await fetch('api/api.php?action=discard_pair', {
    method: 'POST',
    headers: { 'X-Session-Token': sessionToken },
    body: JSON.stringify({ discard_all: true })
  });
  const data = await res.json();

  isProcessing = false;
  document.getElementById('processingOverlay').classList.add('hidden');

  if (data.error) return showToast(data.error);

  playSound('play');
  showToast(`Discarded ${data.pairs_discarded || 'all'} pairs! 🎉`);
}

async function shuffleMyHand() {
  const res = await fetch('api/api.php?action=shuffle_hand', {
    headers: { 'X-Session-Token': sessionToken }
  });
  const data = await res.json();
  if (data.success) {
    showToast("Cards shuffled! Opponents can't guess where Old Maid is.");
  }
}

// --- CARD RENDERING ---
function renderCard(card, isInteractive, isInPair = false) {
  const wrap = document.createElement('div');
  wrap.className = `card-illustrated ${card.card_color} ${isInteractive ? 'interactive' : ''} ${isInPair ? 'in-pair' : ''}`;

  wrap.innerHTML = `
    <div class="card-top">
      <span>${card.card_symbol}</span>
    </div>
    <div class="card-center">
      <div class="card-center-icon">${card.card_symbol}</div>
      <div class="card-center-name">${card.card_title}</div>
    </div>
    <div class="card-bottom">
      <span>${card.card_symbol}</span>
    </div>
  `;
  return wrap;
}

// --- GALLERY MODAL ---
function toggleDiscardGallery() {
  const modal = document.getElementById('discardGalleryModal');
  const isHidden = modal.classList.toggle('hidden');
  if (!isHidden) {
    const grid = document.getElementById('discardGalleryGrid');
    if (lastRecentDiscards.length === 0) {
      grid.innerHTML = '<div style="color:#94a3b8;padding:20px;">No pairs discarded yet.</div>';
    } else {
      grid.innerHTML = '';
      lastRecentDiscards.forEach(c => grid.appendChild(renderCard(c, false)));
    }
  }
}

// --- REACTION SYSTEM ---
async function sendReaction(emoji) {
  if (reactionCooldown) return;
  reactionCooldown = true;
  showReactionBubble(myPlayerId, emoji);
  fetch('api/api.php?action=send_reaction', {
    method: 'POST',
    headers: { 'X-Session-Token': sessionToken },
    body: JSON.stringify({ reaction: emoji })
  });
  setTimeout(() => reactionCooldown = false, 3000);
}

function showReactionBubble(pId, emoji) {
  const pNode = pId == myPlayerId 
    ? document.getElementById('reactionTray') 
    : document.getElementById(`opponent-${pId}`);
  if (!pNode) return;
  const bubble = document.createElement('div');
  bubble.className = 'reaction-bubble';
  bubble.innerText = emoji;
  const rect = pNode.getBoundingClientRect();
  bubble.style.left = (rect.left + rect.width / 2 - 20) + 'px';
  bubble.style.top = (rect.top - 10) + 'px';
  document.body.appendChild(bubble);
  setTimeout(() => bubble.remove(), 2000);
}

function showActionBanner(msg) {
  const b = document.getElementById('actionBanner');
  document.getElementById('actionBannerText').innerText = msg;
  b.classList.add('show');
  setTimeout(() => b.classList.remove('show'), 3500);
}

function showScreen(id) {
  ['lobbyScreen', 'waitingScreen', 'arenaScreen', 'winnerScreen'].forEach(s => {
    document.getElementById(s).classList.toggle('hidden', s !== id);
  });
}

// --- DEV TOOLS ---
function toggleXray() {
  isXrayOn = !isXrayOn;
  document.getElementById('xrayBtn').innerText = isXrayOn ? '👁️ Toggle X-Ray: ON' : '👁️ Toggle X-Ray: OFF';
  showToast(`X-Ray ${isXrayOn ? 'Enabled' : 'Disabled'}`);
  stateVersion = 0;
}

async function devAction(type, extra = {}) {
  const res = await fetch('api/api.php?action=dev_action', {
    method: 'POST',
    headers: { 'X-Session-Token': sessionToken },
    body: JSON.stringify({ dev_type: type, dev_pass: devPassword, ...extra })
  });
  const data = await res.json();
  if (data.error) return showToast(data.error);
  showToast(`Dev action ${type} executed.`);
}

function openDevToolsPrompt() {
  let p = document.getElementById('devPanel');
  if (!p) {
    let pw = prompt("Dev Password:");
    if (pw === "spoon") {
      devPassword = pw;
      p = document.createElement('div');
      p.id = 'devPanel';
      p.innerHTML = `
        <h4>Dev Tools <span onclick="document.getElementById('devPanel').classList.add('hidden')" style="float:right;cursor:pointer;color:#ef4444;">✖</span></h4>
        <button class="dev-btn" id="xrayBtn" onclick="toggleXray()">👁️ Toggle X-Ray: OFF</button>
        <button class="dev-btn" onclick="devAction('force_turn')">⚡ Force My Turn</button>
        <button class="dev-btn" onclick="devAction('test_win')">🏆 Test Escape (Win)</button>
        <button class="dev-btn" onclick="devAction('give_old_maid')">🧙‍♀️ Give Me Old Maid</button>
      `;
      document.body.appendChild(p);
      showToast("Dev mode unlocked.");
    } else if (pw !== null) {
      showToast("Incorrect.");
    }
  } else {
    p.classList.remove('hidden');
  }
}

window.addEventListener('keydown', (e) => {
  if (e.key === '`') openDevToolsPrompt();
});
let lc = 0, lt = null;
const lEl = document.querySelector('.logo');
if (lEl) {
  lEl.addEventListener('pointerdown', () => {
    lc++;
    clearTimeout(lt);
    lt = setTimeout(() => lc = 0, 2000);
    if (lc >= 5) {
      lc = 0;
      openDevToolsPrompt();
    }
  });
}
