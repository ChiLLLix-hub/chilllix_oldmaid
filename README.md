# chilllix_oldmaid

An illustrated multiplayer **Old Maid** web card game built with PHP, MySQL, Vanilla JavaScript, and an optional Node.js Socket.IO realtime relay.

## Game Features
- **Illustrated Character Deck**: 25 cute animal pairs (50 cards) + 1 unmatchable **The Old Maid** card (`🧙‍♀️`).
- **Interactive Card Drawing**: Active players can view opponents' face-down cards and tap to pick one!
- **Auto-Pair with Suggestion**: Matching pairs in your hand are automatically detected and highlighted, with one-click discard buttons so you can choose when to drop them or keep them for bluffing.
- **Escape Ladder**: Players escape safely as they run out of cards ($1^{\text{st}}, 2^{\text{nd}}, 3^{\text{rd}}$ place). The last player holding the Old Maid is the loser!
- **Hand Re-shuffling**: Click "Shuffle Hand" to scramble your cards so opponents cannot track where the Old Maid was.
- **Dual Sync Architecture**: Automatic fallback to 2-second HTTP polling on shared hosting (cPanel/sPanel), or instant WebSocket push when connected to the Node.js relay.

## Deployment Setup

1. Copy `api/config.local.php.example` to `api/config.local.php` (or configure via environment variables).
2. Enter your MySQL database credentials:
   - `db_host`
   - `db_name`
   - `db_user`
   - `db_pass`
3. Run the installer by browsing to `setup/install.php` (or import `setup/sqldb.sql` in phpMyAdmin).
4. (Optional) Run the Node.js realtime relay:
   ```bash
   npm install
   npm start
   ```
   and update `<meta name="oldmaid-realtime-url" content="...">` in `index.html`.
