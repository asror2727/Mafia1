<?php
// ============================================================
//  Manba: @Acsend_builder_support
//  Insof bilan chopinglar
//  MAFIYA BOT — Barcha funksiyalar (Gift, Support, GIF, Balans)
//  Cron: <bot manzili>?tick=1&key=<kalit>
//  Uzcard callback: <bot manzili>?uzcard_cb=1
//  Menyuni yangilash: <bot manzili>?setcmd=1
// ============================================================
define('API_KEY', getenv('TELEGRAM_BOT_TOKEN') ?: '__RB_TOKEN__');
$OWNER = getenv('TELEGRAM_OWNER_ID') ?: '__RB_ADMIN__'; // Render Environment Variables orqali sozlanadi

chdir(__DIR__);
error_reporting(0);
ignore_user_abort(true);
date_default_timezone_set('Asia/Tashkent');
$D = __DIR__ . '/mafiya_data';
if (!is_dir($D)) @mkdir($D, 0777, true);

// PostgreSQL connection; Render provides DATABASE_URL.
function db() {
    static $pdo = null;
    static $schemaReady = false;
    if ($pdo instanceof PDO) return $pdo;
    $url = getenv('DATABASE_URL') ?: '';
    if ($url === '') throw new RuntimeException('DATABASE_URL environment variable is missing');
    $parts = parse_url($url);
    if (!$parts || empty($parts['host']) || empty($parts['path'])) throw new RuntimeException('DATABASE_URL format is invalid');
    $host = $parts['host'];
    $port = $parts['port'] ?? 5432;
    $dbname = ltrim($parts['path'], '/');
    $user = rawurldecode($parts['user'] ?? '');
    $pass = rawurldecode($parts['pass'] ?? '');
    $query = [];
    if (!empty($parts['query'])) parse_str($parts['query'], $query);
    $sslmode = $query['sslmode'] ?? 'require';
    $dsn = "pgsql:host=$host;port=$port;dbname=$dbname;sslmode=$sslmode";
    $pdo = new PDO($dsn, $user, $pass, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC, PDO::ATTR_EMULATE_PREPARES => false]);
    if (!$schemaReady) {
        $pdo->exec('CREATE TABLE IF NOT EXISTS mafia_kv (store_key TEXT PRIMARY KEY, payload JSONB NOT NULL, updated_at TIMESTAMPTZ NOT NULL DEFAULT NOW())');
        $schemaReady = true;
    }
    return $pdo;
}

// Keep the original J()/S() interface so the game logic remains unchanged,
// while storing each original JSON collection as a PostgreSQL JSONB record.
function J($n) {
    $q = db()->prepare('SELECT payload::text AS payload FROM mafia_kv WHERE store_key = :k');
    $q->execute([':k' => $n]);
    $raw = $q->fetchColumn();
    if ($raw === false) return [];
    $d = json_decode((string)$raw, true);
    return is_array($d) ? $d : [];
}
function S($n, $d) {
    $json = json_encode($d, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
    $q = db()->prepare('INSERT INTO mafia_kv (store_key, payload, updated_at) VALUES (:k, CAST(:v AS JSONB), NOW()) ON CONFLICT (store_key) DO UPDATE SET payload = EXCLUDED.payload, updated_at = NOW()');
    $q->execute([':k' => $n, ':v' => $json]);
}
function h($s) { return htmlspecialchars((string)$s, ENT_QUOTES); }
function tg($m, $p = []) { foreach ($p as $k => $v) { if (is_array($v)) $p[$k] = json_encode($v, JSON_UNESCAPED_UNICODE); } $ch = curl_init("https://api.telegram.org/bot" . API_KEY . "/$m"); curl_setopt_array($ch, [CURLOPT_POST => true, CURLOPT_POSTFIELDS => $p, CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 20]); $r = curl_exec($ch); curl_close($ch); return json_decode((string)$r, true); }
function say($chat, $text, $kb = null) { $p = ['chat_id' => $chat, 'text' => $text, 'parse_mode' => 'HTML', 'disable_web_page_preview' => true]; if ($kb !== null) $p['reply_markup'] = $kb; return tg('sendMessage', $p); }
function edit($chat, $mid, $text, $kb = null) { $p = ['chat_id' => $chat, 'message_id' => $mid, 'text' => $text, 'parse_mode' => 'HTML', 'disable_web_page_preview' => true]; if ($kb !== null) $p['reply_markup'] = $kb; return tg('editMessageText', $p); }
function answer($id, $text = '', $alert = false) { return tg('answerCallbackQuery', ['callback_query_id' => $id, 'text' => $text, 'show_alert' => $alert ? 'true' : 'false']); }
function kb($rows) { $o = []; foreach ($rows as $r) { $row = []; foreach ($r as $b) { $row[] = is_array($b) ? $b : ['text' => $b]; } $o[] = $row; } return ['keyboard' => $o, 'resize_keyboard' => true]; }
function ikb($rows) { return ['inline_keyboard' => $rows]; }
function btn($t, $d) { return ['text' => $t, 'callback_data' => $d]; }
function ubtn($t, $u) { return ['text' => $t, 'url' => $u]; }
function mention($id, $name) { return '<a href="tg://user?id=' . $id . '">' . h($name) . '</a>'; }
function nm($p) { return mention($p['id'], $p['name']); }
function fmtn($n) { return number_format((float)$n, 0, '.', ' '); }

function sendMedia($chat, $media, $caption = '') {
    if (!$media || $media === '') { say($chat, $caption); return; }
    $r = tg('sendAnimation', ['chat_id' => $chat, 'animation' => $media, 'caption' => $caption, 'parse_mode' => 'HTML']);
    if (empty($r['ok'])) {
        $r = tg('sendVideo', ['chat_id' => $chat, 'video' => $media, 'caption' => $caption, 'parse_mode' => 'HTML']);
        if (empty($r['ok'])) {
            $r = tg('sendPhoto', ['chat_id' => $chat, 'photo' => $media, 'caption' => $caption, 'parse_mode' => 'HTML']);
            if (empty($r['ok'])) say($chat, $caption);
        }
    }
    return $r;
}

// ---------- sozlamalar ----------
function cfg() {
    $def = [
        'reg_time' => 120, 'night_time' => 60, 'day_time' => 90, 'vote_time' => 45, 'min_players' => 4, 'max_players' => 20,
        'reveal_role' => 1, 'strict_chat' => 1,
        'win_coins' => 100, 'lose_coins' => 30, 'survive_bonus' => 20, 'daily_bonus' => 50, 'ref_bonus' => 100,
        'rt_win' => 25, 'rt_lose' => 15,
        'p_doc' => 300, 'p_shield' => 500, 'p_rebirth' => 700, 'p_qotil_himoya' => 800, 'p_miltiq' => 1000,
        'p_dori_himoya' => 500, 'p_maska' => 600, 'p_geroy' => 1200, 'p_sirpanish' => 400,
        'p_geroy_himoya' => 500, 'p_qora_materiya' => 5000,
        'pd_doc' => 2, 'pd_shield' => 3, 'pd_rebirth' => 4, 'pd_qotil_himoya' => 4, 'pd_miltiq' => 6,
        'pd_dori_himoya' => 3, 'pd_maska' => 4, 'pd_geroy' => 7, 'pd_sirpanish' => 2,
        'pd_geroy_himoya' => 3, 'pd_qora_materiya' => 25,
        'roles_off' => [], 'admins' => [], 'channels' => [], 'welcome' => '', 'last_tick' => 0, 'cron_key' => '', 'bot_username' => '',
        'diamond_packages' => [], 'card_number' => '', 'card_holder' => '', 'pay_note' => "To'lovni amalga oshirib, chekni yuboring.",
        'role_prices' => [], 'role_prices_t' => [], 'ref_need' => 3, 'ref_diamonds' => 10,
        'uzcard_enabled' => 0, 'uzcard_merchant_id' => '', 'uzcard_api_key' => '',
        'uzcard_api_url' => 'https://api.uzcard.uz/merchant/v1/payment', 'uzcard_callback_secret' => '',
        'admin_roles' => [],
        'balance_rate_tanga' => 100, 'balance_rate_olmos' => 1000,
        'balance_packages' => [],
        'oltin_buy_t' => 500, 'oltin_sell_t' => 400, 'oltin_buy_o' => 5, 'oltin_sell_o' => 4,
        'tr_enabled' => 1, 'tr_min_tanga' => 10, 'tr_min_olmos' => 1, 'tr_min_oltin' => 1, 'tr_min_balans' => 1000,
        'tr_fee_pct' => 5, 'tr_daily_limit' => 500000,
        'night_media' => 'https://media.giphy.com/media/l0HlvtIPzPdt2usH2/giphy.mp4',
        'day_media' => 'https://media.giphy.com/media/3o7TKu8oN88lS1T8p2/giphy.mp4',
        'gift_enabled' => 1, 'gift_admin' => '@admin',
        'gift_packages' => [
            ['name' => '🌹 Atirgul', 'stars' => 15, 'diamonds' => 50, 'balance' => 15000],
            ['name' => '🧸 Ayiqcha', 'stars' => 25, 'diamonds' => 100, 'balance' => 25000],
            ['name' => '🎁 Sovg\'a', 'stars' => 50, 'diamonds' => 250, 'balance' => 50000],
            ['name' => '🚀 Raketa', 'stars' => 100, 'diamonds' => 600, 'balance' => 100000],
        ],
        'support_buttons' => [
            ['id' => 'about', 'text' => 'ℹ️ Bot haqida', 'type' => 'text', 'value' => 'Bu Mafiya o\'yini boti. Bu yerda siz do\'stlaringiz bilan qiziqarli o\'yin o\'ynashingiz mumkin.'],
            ['id' => 'admin', 'text' => '👨‍💻 Admin bilan bog\'lanish', 'type' => 'url', 'value' => 'https://t.me/your_admin_username']
        ],
    ];
    return array_merge($def, J('settings'));
}
function setcfg($k, $v) { $c = J('settings'); $c[$k] = $v; S('settings', $c); }
function cron_key() { $env = getenv('CRON_SECRET') ?: ''; if ($env !== '') return $env; $c = cfg(); if ($c['cron_key'] === '') { $k = bin2hex(random_bytes(16)); setcfg('cron_key', $k); return $k; } return $c['cron_key']; }
function bot_username() { $c = cfg(); if ($c['bot_username'] !== '') return $c['bot_username']; $r = tg('getMe'); $u = isset($r['result']['username']) ? $r['result']['username'] : ''; if ($u !== '') setcfg('bot_username', $u); return $u; }
function isAdmin($id) { global $OWNER; $c = cfg(); return ((string)$id === (string)$OWNER) || in_array((string)$id, array_map('strval', $c['admins']), true) || isset($c['admin_roles'][(string)$id]); }
function admin_ids() { global $OWNER; $c = cfg(); $a = array_map('strval', $c['admins']); $a = array_merge($a, array_map('strval', array_keys($c['admin_roles']))); $a[] = (string)$OWNER; return array_values(array_unique($a)); }
function admin_level($uid) { global $OWNER; if ((string)$uid === (string)$OWNER) return 'owner'; $c = cfg(); if (in_array((string)$uid, array_map('strval', $c['admins']), true)) return 'super'; if (isset($c['admin_roles'][(string)$uid])) return $c['admin_roles'][(string)$uid]; return null; }
function level_name($l) { return ['owner'=>'👑 Owner','super'=>'🛡 Super','pay'=>"💳 To'lov",'mod'=>'👮 Moderator'][$l] ?? '—'; }

// ---------- rollar ----------
function ROLES() {
    return [
        'civil' => ['n' => '👨‍🌾 Tinch aholi', 'team' => 'town', 'd' => "Kunduzi ovoz berib mafiyani toping."],
        'sheriff' => ['n' => '🕵️ Komissar', 'team' => 'town', 'd' => "Har tun bitta odamni tekshirasiz yoki otasiz (2 o'q)."],
        'sergeant' => ['n' => '👮 Serjant', 'team' => 'town', 'd' => "Komissar o'lsa uning o'rniga Komissar bo'lasiz."],
        'doctor' => ['n' => '👨🏻‍⚕️ Shifokor', 'team' => 'town', 'd' => "Har tun bitta odamni davolaysiz."],
        'escort' => ['n' => '💃 Fohisha', 'team' => 'town', 'd' => "Har tun bitta odamni band qilasiz."],
        'lucky' => ['n' => '🤞 Omadli', 'team' => 'town', 'd' => "Hujumga uchrasangiz 50% omon qolasiz."],
        'bodyguard' => ['n' => '🛡 Qo\'riqchi', 'team' => 'town', 'd' => "Bitta odamni qo'riqlaysiz."],
        'tracker' => ['n' => '👁 Kuzatuvchi', 'team' => 'town', 'd' => "Kimga kim tashrif buyurganini bilasiz."],
        'mayor' => ['n' => '💰 Rais', 'team' => 'town', 'd' => "Ovozingiz 2 ta hisoblanadi. Tunda uyquda."],
        'kamikaze' => ['n' => '💣 Kamikadze', 'team' => 'town', 'd' => "Chiqarilsangiz bir kishini olib ketasiz."],
        'konchi' => ['n' => '⛏ Konchi', 'team' => 'town', 'd' => "Qazinasiz: 10% sirpanish, 20% xazina. 🪤 Sirpanish himoyasi qutqaradi."],
        'daydi' => ['n' => '🚶 Daydi', 'team' => 'town', 'd' => "Har tun bitta odamning rolini bilasiz. 🎭 Maska yashiradi."],
        'mafia' => ['n' => '🤵🏻 Mafiya', 'team' => 'mafia', 'd' => "Har tun bitta odamni o'ldirasiz."],
        'don' => ['n' => '🎩 Don', 'team' => 'mafia', 'd' => "Mafiya boshlig'i. Komissarni qidiradi."],
        'lawyer' => ['n' => '👨🏼‍💼 Advokat', 'team' => 'mafia', 'd' => "Mafiyani tekshiruvdan himoya qilasiz."],
        'spy' => ['n' => '🕶 Josus', 'team' => 'mafia', 'd' => "Kimning rolini bilmoqchisiz."],
        'yollanma' => ['n' => '🥷 Yollanma qotil', 'team' => 'mafia', 'd' => "Alohida o'ldiradi. ⛑️ Qotildan himoya to'xtatadi."],
        'malika' => ['n' => '👸 Malika', 'team' => 'mafia', 'd' => "Har tun bitta odamni dorilaysiz. Faqat 💊 Doridan himoya qutqaradi."],
        'maniac' => ['n' => '🔪 Qotil', 'team' => 'neutral', 'd' => "Yolg'iz. Har tun o'ldirasiz."],
        'jester' => ['n' => '🤹🏻 Aferist', 'team' => 'neutral', 'd' => "Chiqarilsangiz g'alaba."],
    ];
}
function role_name($r) { $a = ROLES(); return isset($a[$r]) ? $a[$r]['n'] : $r; }
function role_team($r) { $a = ROLES(); return isset($a[$r]) ? $a[$r]['team'] : 'town'; }
function toggle_roles() { $r = array_keys(ROLES()); return array_values(array_diff($r, ['civil', 'mafia'])); }
function night_roles() { return ['mafia', 'don', 'lawyer', 'spy', 'sheriff', 'doctor', 'escort', 'maniac', 'bodyguard', 'tracker', 'yollanma', 'malika', 'konchi', 'daydi']; }
function distribute($n) {
    $c = cfg(); $off = $c['roles_off'];
    $on = function ($r) use ($off) { return !in_array($r, $off, true); };
    $m = max(1, (int)floor($n / 3));
    $team = ['mafia'];
    $extras = ['don', 'lawyer', 'yollanma', 'malika', 'spy'];
    foreach ($extras as $e) { if (count($team) >= $m) break; $team[] = $on($e) ? $e : 'mafia'; }
    while (count($team) < $m) $team[] = 'mafia';
    $roles = $team;
    $special = [['sheriff', 4], ['konchi', 5], ['doctor', 5], ['daydi', 6], ['escort', 6], ['maniac', 7], ['lucky', 8], ['sergeant', 9], ['bodyguard', 10], ['tracker', 11], ['mayor', 12], ['kamikaze', 13], ['jester', 14]];
    foreach ($special as $sp) { if ($n < $sp[1] || !$on($sp[0]) || count($roles) >= $n) continue; if ($sp[0] === 'sergeant' && !in_array('sheriff', $roles, true)) continue; $roles[] = $sp[0]; }
    while (count($roles) < $n) $roles[] = 'civil';
    shuffle($roles);
    return $roles;
}

// ---------- foydalanuvchilar ----------
function newuser($from) {
    return ['id' => $from['id'], 'name' => trim(($from['first_name'] ?? '') . ' ' . ($from['last_name'] ?? '')), 'un' => $from['username'] ?? '',
        'coins' => 100, 'rating' => 1000, 'games' => 0, 'wins' => 0, 'streak' => 0, 'best_streak' => 0, 'roles' => [], 'ach' => [],
        'items' => ['doc'=>0,'shield'=>0,'rebirth'=>0,'qotil_himoya'=>0,'miltiq'=>0,'dori_himoya'=>0,'maska'=>0,'geroy'=>0,'sirpanish'=>0,'geroy_himoya'=>0,'qora_materiya'=>0],
        'banned' => 0, 'ref' => 0, 'refpaid' => 0, 'daily_d' => '', 'daily_s' => 0,
        'diamonds' => 0, 'owned_roles' => [], 'active_role' => '',
        'ref_active' => 0, 'ref_rewarded' => 0,
        'balance' => 0, 'oltin' => 0,
        'week' => '', 'wwins' => 0, 'created' => time(), 'step' => '', 'tmp' => []];
}
function uget($id) { $a = J('users'); return isset($a[$id]) ? $a[$id] : null; }
function uset($id, $f) { $a = J('users'); if (!isset($a[$id])) return; $a[$id] = array_merge($a[$id], $f); S('users', $a); }
function uensure($from, $ref = 0) {
    $a = J('users'); $id = $from['id'];
    if (!isset($a[$id])) { $u = newuser($from); if ($ref > 0 && $ref != $id && isset($a[$ref])) $u['ref'] = $ref; $a[$id] = $u; }
    else { $a[$id]['name'] = trim(($from['first_name'] ?? '') . ' ' . ($from['last_name'] ?? '')); $a[$id]['un'] = $from['username'] ?? ''; }
    S('users', $a); return $a[$id];
}
function use_item($uid, $k) { $a = J('users'); if (!isset($a[$uid]) || ($a[$uid]['items'][$k] ?? 0) < 1) return false; $a[$uid]['items'][$k]--; S('users', $a); return true; }
function has_item($uid, $k) { $a = J('users'); return isset($a[$uid]) && ($a[$uid]['items'][$k] ?? 0) > 0; }
function addcoins($uid, $n) { $a = J('users'); if (!isset($a[$uid])) return; $a[$uid]['coins'] = max(0, $a[$uid]['coins'] + $n); S('users', $a); }
function adddiamonds($uid, $n) { $a = J('users'); if (!isset($a[$uid])) return; $a[$uid]['diamonds'] = max(0, (int)($a[$uid]['diamonds'] ?? 0) + (int)$n); S('users', $a); }
function usediamonds($uid, $n) { $a = J('users'); if (!isset($a[$uid])) return false; if ((int)($a[$uid]['diamonds'] ?? 0) < (int)$n) return false; $a[$uid]['diamonds'] -= (int)$n; S('users', $a); return true; }
function addbalance($uid, $n) { $a = J('users'); if (!isset($a[$uid])) return; $a[$uid]['balance'] = max(0, (int)($a[$uid]['balance'] ?? 0) + (int)$n); S('users', $a); }
function useBalance($uid, $n) { $a = J('users'); if (!isset($a[$uid])) return false; if ((int)($a[$uid]['balance'] ?? 0) < (int)$n) return false; $a[$uid]['balance'] -= (int)$n; S('users', $a); return true; }
function addoltin($uid, $n) { $a = J('users'); if (!isset($a[$uid])) return; $a[$uid]['oltin'] = max(0, (int)($a[$uid]['oltin'] ?? 0) + (int)$n); S('users', $a); }
function useOltin($uid, $n) { $a = J('users'); if (!isset($a[$uid])) return false; if ((int)($a[$uid]['oltin'] ?? 0) < (int)$n) return false; $a[$uid]['oltin'] -= (int)$n; S('users', $a); return true; }
function setstep($uid, $step, $tmp = []) { uset($uid, ['step' => $step, 'tmp' => $tmp]); }

// ---------- to'lov ----------
function pay_get($id) { foreach (J('payments') as $r) { if ((string)$r['id'] === (string)$id) return $r; } return null; }
function pay_add($req) { $a = J('payments'); $req['id'] = 'P' . date('ymd') . rand(100, 999); $req['status'] = 'pending'; $req['created'] = time(); $a[] = $req; S('payments', $a); return $req; }
function pay_update($id, $f) { $a = J('payments'); foreach ($a as &$r) if ((string)$r['id'] === (string)$id) { $r = array_merge($r, $f); break; } unset($r); S('payments', $a); }
function pay_pending_count() { $n = 0; foreach (J('payments') as $r) if ($r['status'] === 'pending') $n++; return $n; }
function oltin_hist_add($uid, $type, $cur, $qty, $total) { $h = J('oltin_hist'); $h[] = ['uid' => $uid, 'type' => $type, 'cur' => $cur, 'qty' => (int)$qty, 'total' => (int)$total, 't' => time()]; if (count($h) > 1000) $h = array_slice($h, -1000); S('oltin_hist', $h); }

// ---------- yutuqlar ----------
function ACH() {
    return [
        'w1' => ["🥇 Birinchi g'alaba", "1 ta o'yinda g'alaba", 50, 'wins', 1],
        'w10' => ["🏅 Tajribali", "10 ta g'alaba", 150, 'wins', 10],
        'w50' => ["🏆 Afsonaviy", "50 ta g'alaba", 600, 'wins', 50],
        'g10' => ["🎮 Faol o'yinchi", "10 ta o'yin", 80, 'games', 10],
        'g100' => ["🎲 Mafiya qurti", "100 ta o'yin", 500, 'games', 100],
        's5' => ["🔥 G'alaba seriyasi", "Ketma-ket 5 ta g'alaba", 300, 'best_streak', 5],
        'mw' => ["🤵🏻 Mafiya sardori", "Mafiyada 5 g'alaba", 250, 'mafia_wins', 5],
        'sw' => ["🕵️ Chinakam komissar", "Komissar 3 g'alaba", 250, 'sheriff_wins', 3],
        'dw' => ["👨🏻‍⚕️ Hayot qutqaruvchi", "Shifokor 3 g'alaba", 250, 'doctor_wins', 3],
        'r1200' => ["⭐ Yulduz", "Reyting 1200", 200, 'rating', 1200],
        'c2000' => ["💰 Boy", "2000 tanga", 200, 'coins', 2000],
    ];
}
function ach_stat($u, $k) {
    $r = isset($u['roles']) ? $u['roles'] : [];
    $w = function ($role) use ($r) { return isset($r[$role]) ? (int)$r[$role][1] : 0; };
    if ($k === 'mafia_wins') return $w('mafia') + $w('don') + $w('lawyer') + $w('yollanma') + $w('malika');
    if ($k === 'sheriff_wins') return $w('sheriff');
    if ($k === 'doctor_wins') return $w('doctor');
    return isset($u[$k]) ? (int)$u[$k] : 0;
}
function ach_check(&$u) { $new = []; foreach (ACH() as $id => $a) { if (in_array($id, $u['ach'], true)) continue; if (ach_stat($u, $a[3]) >= $a[4]) { $u['ach'][] = $id; $u['coins'] += $a[2]; $new[] = $a; } } return $new; }

// ======================= O'YIN =======================
function gget($chat) { $a = J('games'); return isset($a[$chat]) ? $a[$chat] : null; }
function gput($g) { $a = J('games'); $a[$g['chat']] = $g; S('games', $a); }
function gdel($chat) { $a = J('games'); unset($a[$chat]); S('games', $a); }
function find_game_of($uid) { foreach (J('games') as $g) { if (isset($g['players'][$uid])) return $g; } return null; }
function alive_ids($g) { $r = []; foreach ($g['players'] as $id => $p) { if (!empty($p['alive'])) $r[] = $id; } return $r; }
function is_mafia_role($r) { return role_team($r) === 'mafia'; }
function bot_link() { return 'https://t.me/' . bot_username(); }
function plist($g, $only_alive = true) { $t = ''; $i = 0; foreach ($g['players'] as $id => $p) { if ($only_alive && empty($p['alive'])) continue; $i++; $t .= "$i. " . nm($p) . (empty($p['alive']) && !$only_alive && $p['role'] !== '' ? ' 💀' : '') . "\n"; } return $t; }

function status_text($g) {
    $now = time();
    $left = max(0, ($g['phase_end'] ?? $now) - $now);
    $alive = alive_ids($g);
    
    $phase = "Ro'yxat";
    if ($g['status'] === 'night') $phase = "🌙 Tun";
    elseif ($g['status'] === 'day') $phase = "☀️ Kun";
    elseif ($g['status'] === 'vote') $phase = "🗳 Ovoz berish";
    
    $t = "🎮 <b>O'yin holati</b> — " . ($g['day'] > 0 ? $g['day'] . "-kun" : "Ro'yxat") . " ($phase)\n\n";
    $t .= "👥 <b>Tirik o'yinchilar (" . count($alive) . "):</b>\n";
    $i = 0;
    foreach ($g['players'] as $id => $p) {
        if (!empty($p['alive'])) { $i++; $t .= "$i. " . nm($p) . "\n"; }
    }
    $dead = [];
    foreach ($g['players'] as $id => $p) { if (empty($p['alive'])) $dead[] = $p; }
    if ($dead) {
        $t .= "\n💀 <b>O'lganlar:</b>\n";
        foreach ($dead as $p) { $t .= "• " . h($p['name']) . " — " . role_name($p['role'] ?? '') . "\n"; }
    }
    $t .= "\n";
    if ($g['status'] === 'reg') $t .= "⏳ Ro'yxat tugashiga: <b>$left</b> soniya";
    elseif ($g['status'] === 'night') $t .= "🌙 Tun tugashiga: <b>$left</b> soniya";
    elseif ($g['status'] === 'day') $t .= "☀️ Kun tugashiga: <b>$left</b> soniya";
    elseif ($g['status'] === 'vote') $t .= "🗳 Ovoz tugashiga: <b>$left</b> soniya";
    return $t;
}
function update_status($chat) {
    $g = gget($chat);
    if (!$g || empty($g['status_msg_id'])) return;
    edit($chat, $g['status_msg_id'], status_text($g));
}

function reg_text($g) {
    $c = cfg(); $left = max(0, $g['reg_end'] - time());
    $t = "🎲 <b>Mafiya — ro'yxat!</b>\n\n👥 (" . count($g['players']) . "/{$c['max_players']}):\n";
    $t .= $g['players'] ? plist($g, false) : "— bo'sh —\n";
    $t .= "\n⏳ $left soniya (min {$c['min_players']})\n/extend · /begin";
    return $t;
}
function reg_kb($g) { return ikb([[ubtn("🎮 Qo'shilish", bot_link() . '?start=join_' . $g['chat'])]]); }
function reg_refresh($g) { if (!empty($g['reg_msg'])) edit($g['chat'], $g['reg_msg'], reg_text($g), reg_kb($g)); }

function begin_game($chat) {
    $g = gget($chat); if (!$g || $g['status'] !== 'reg') return;
    $c = cfg(); $n = count($g['players']);
    if ($n < $c['min_players']) { say($chat, "😔 Kamida {$c['min_players']} kishi kerak."); if (!empty($g['reg_msg'])) tg('unpinChatMessage', ['chat_id' => $chat, 'message_id' => $g['reg_msg']]); gdel($chat); return; }
    $roles = distribute($n); $cnt = [];
    $users = J('users'); $forced = [];
    foreach ($g['players'] as $id => $p) {
        if (!isset($users[$id])) continue;
        $ar = (string)($users[$id]['active_role'] ?? ''); if ($ar === '') continue;
        $idx = array_search($ar, $roles, true); if ($idx === false) continue;
        $forced[$id] = $ar; unset($roles[$idx]); $roles = array_values($roles); $users[$id]['active_role'] = '';
    }
    S('users', $users);
    $i = 0;
    foreach ($g['players'] as $id => $p) {
        $r = isset($forced[$id]) ? $forced[$id] : $roles[$i++];
        $g['players'][$id]['role'] = $r; $g['players'][$id]['alive'] = 1;
        $g['players'][$id]['shots'] = ($r === 'sheriff') ? 2 : 0;
        $g['players'][$id]['heal_self'] = 0; $g['players'][$id]['last_block'] = 0;
        $cnt[$r] = (isset($cnt[$r]) ? $cnt[$r] : 0) + 1;
    }
    $g['status'] = 'night'; $g['day'] = 0; $g['started'] = time(); $g['qm_used'] = 0;
    
    $sr = say($chat, status_text($g));
    $g['status_msg_id'] = $sr['result']['message_id'] ?? 0;
    $g['last_status_update'] = time();
    
    gput($g);
    $mt = []; foreach ($g['players'] as $id => $p) { if (is_mafia_role($p['role'])) $mt[] = $p; }
    $roles_all = ROLES();
    foreach ($g['players'] as $id => $p) {
        $t = "🎭 <b>Rolingiz: " . role_name($p['role']) . "</b>\n\n" . $roles_all[$p['role']]['d'];
        if (is_mafia_role($p['role'])) { $t .= "\n\n🤵🏻 <b>Mafiya:</b>\n"; foreach ($mt as $x) $t .= "• " . nm($x) . " — " . role_name($x['role']) . "\n"; }
        say($id, $t);
    }
    $comp = ''; foreach ($cnt as $r => $k) $comp .= role_name($r) . " × $k\n";
    say($chat, "🎬 <b>O'yin boshlandi!</b>\n\n📋 <b>Rollar:</b>\n$comp", ikb([[ubtn("🤖 Botni ochish", bot_link())]]));
    start_night($chat);
}

function tkb($chat, $act, $ids, $g) {
    $rows = []; $row = [];
    foreach ($ids as $id) { $row[] = btn($g['players'][$id]['name'], "na:$chat:$act:$id"); if (count($row) == 2) { $rows[] = $row; $row = []; } }
    if ($row) $rows[] = $row;
    if (!$rows) $rows[] = [btn("—", "na:$chat:none:0")];
    return ikb($rows);
}

function start_night($chat) {
    $g = gget($chat); if (!$g) return;
    $c = cfg();
    if (!empty($g['swap']) && $g['day'] >= $g['swap']['expire_day']) {
        $a = $g['swap']['a']; $b = $g['swap']['b'];
        if (isset($g['players'][$a]) && isset($g['players'][$b])) {
            $tmp = $g['players'][$a]['role']; $g['players'][$a]['role'] = $g['players'][$b]['role']; $g['players'][$b]['role'] = $tmp;
            say($a, "🌀 Void Swap tugadi. Rolingiz: " . role_name($g['players'][$a]['role'])); say($b, "🌀 Void Swap tugadi. Rolingiz: " . role_name($g['players'][$b]['role']));
        }
        unset($g['swap']);
    }
    $g['day']++; $g['status'] = 'night'; $g['phase_end'] = time() + $c['night_time']; $g['night'] = ['act' => [], 'ds' => [], 'mil' => []]; $g['warned'] = 0;
    gput($g);

    // ATMOSFERA XABARLARI
    $ann = "🌙 <b>{$g['day']}-tun boshlandi.</b>\nShahar uyquga ketdi...\n\n";
    $seen = []; 
    foreach ($g['players'] as $id => $p) {
        if (!empty($p['alive'])) {
            $r = $p['role'];
            if (!in_array($r, $seen)) {
                $seen[] = $r;
                if ($r === 'sheriff') $ann .= "🕵🏼 Komissar jinoyatchilarni qidirishda davom etmoqda!\n";
                elseif ($r === 'doctor') $ann .= "👨🏻‍⚕️ Shifokor tungi navbatchilikka tushdi.\n";
                elseif ($r === 'don') $ann .= "😏 Don o'ljasini tanladi. 🔫\n";
                elseif ($r === 'mafia') $ann .= "🤵🏻 Mafiya o'ljasini poylamoqda...\n";
                elseif ($r === 'maniac') $ann .= "🔪 Qotil qorong'ulikda yashirindi...\n";
                elseif ($r === 'escort') $ann .= "💃 Fohisha kimnidir o'z tuzog'iga tortdi...\n";
                elseif ($r === 'bodyguard') $ann .= "🛡 Qo'riqchi kimnidir himoyaga oldi.\n";
                elseif ($r === 'tracker') $ann .= "👁 Kuzatuvchi ko'z uzmasdan kuzatmoqda...\n";
                elseif ($r === 'lawyer') $ann .= "👨🏼‍💼 Advokat kimnidir himoya qilishga tayyorlanmoqda.\n";
                elseif ($r === 'yollanma') $ann .= "🥷 Yollanma qotil miltig'ini o'qladi...\n";
                elseif ($r === 'malika') $ann .= "👸 Malika zaharlangan qadahni tayyorladi...\n";
                elseif ($r === 'konchi') $ann .= "⛏ Konchi qorong'uda qazishni boshladi...\n";
                elseif ($r === 'daydi') $ann .= "🚶 Daydi ko'chada tentirab yuribdi...\n";
                elseif ($r === 'mayor') $ann .= "💰 Rais kimnidir hursand qilish uchun ketdi!\n";
                elseif ($r === 'spy') $ann .= "🕶 Josus ma'lumot yig'moqda...\n";
                elseif ($r === 'jester') $ann .= "🤹🏻 Aferist o'ljasini tanladi.\n";
            }
        }
    }
    $ann .= "\n⏳ Tun tugashiga <b>{$c['night_time']}</b> soniya qoldi.";

    $night_gif = $c['night_media'] ?? '';
    sendMedia($chat, $night_gif, $ann);
    
    update_status($chat);
    
    foreach (alive_ids($g) as $id) night_menu($g, $id);
    $g2 = gget($chat);
    if ($g2 && night_complete($g2)) resolve_night($chat);
}

function night_menu($g, $uid) {
    $chat = $g['chat']; $p = $g['players'][$uid]; $r = $p['role'];
    $u = uget($uid); $has = function ($k) use ($u) { return !empty($u) && ($u['items'][$k] ?? 0) > 0; };
    $al = alive_ids($g); $others = array_values(array_diff($al, [$uid]));
    $nonm = array_values(array_filter($al, function ($i) use ($g) { return !is_mafia_role($g['players'][$i]['role']); }));
    $mafs = array_values(array_filter($al, function ($i) use ($g) { return is_mafia_role($g['players'][$i]['role']); }));
    if ($r === 'mafia' || $r === 'don') { say($uid, "🤵🏻 <b>Kimni o'ldiramiz?</b>", tkb($chat, 'mk', $nonm, $g)); if ($r === 'don') say($uid, "🎩 <b>Komissar?</b>", tkb($chat, 'ds', $others, $g)); }
    elseif ($r === 'lawyer') say($uid, "👨🏼‍💼 <b>Himoya?</b>", tkb($chat, 'lw', $mafs, $g));
    elseif ($r === 'spy') say($uid, "🕶 <b>Rol?</b>", tkb($chat, 'sp', $others, $g));
    elseif ($r === 'sheriff') { $rows = []; foreach ($others as $i) { $nx = $g['players'][$i]['name']; $row = [btn("🔍 $nx", "na:$chat:sc:$i")]; if ($p['shots'] > 0) $row[] = btn("🔫 $nx", "na:$chat:ss:$i"); $rows[] = $row; } say($uid, "🕵️ <b>Tekshirish/Otish?</b> (o'q: {$p['shots']})", ikb($rows)); }
    elseif ($r === 'doctor') { $t = $p['heal_self'] ? $others : $al; say($uid, "👨🏻‍⚕️ <b>Davolash?</b>", tkb($chat, 'dc', $t, $g)); }
    elseif ($r === 'escort') { $t = array_values(array_diff($others, [$p['last_block']])); say($uid, "💃 <b>Band qilish?</b>", tkb($chat, 'es', $t, $g)); }
    elseif ($r === 'maniac') say($uid, "🔪 <b>O'ldirish?</b>", tkb($chat, 'mn', $others, $g));
    elseif ($r === 'bodyguard') say($uid, "🛡 <b>Qo'riqlash?</b>", tkb($chat, 'bg', $others, $g));
    elseif ($r === 'tracker') say($uid, "👁 <b>Kuzatish?</b>", tkb($chat, 'tr', $others, $g));
    elseif ($r === 'yollanma') say($uid, "🥷 <b>O'ldirish?</b>", tkb($chat, 'yl', $others, $g));
    elseif ($r === 'malika') say($uid, "👸 <b>Dorilash?</b>", tkb($chat, 'ml', $others, $g));
    elseif ($r === 'daydi') say($uid, "🚶 <b>Rol bilish?</b>", tkb($chat, 'dd', $others, $g));
    elseif ($r === 'konchi') say($uid, "⛏ <b>Qazish joyi?</b>", tkb($chat, 'kc', $others, $g));
    else say($uid, "😴 Tun.");
    if ($has('miltiq')) say($uid, "🔫 <b>Miltiq</b> bor.", ikb([[btn("🔫 Otish", "na:$chat:milmenu:0")]]));
    if ($has('qora_materiya')) say($uid, "🌑 <b>Qora Materiya</b>", ikb([[btn("🌑 Ochish", "qm:$chat:menu:0")]]));
}

function night_complete($g) { foreach ($g['players'] as $id => $p) { if (empty($p['alive']) || !in_array($p['role'], night_roles(), true)) continue; if (!isset($g['night']['act'][$id])) return false; } return true; }
function cb_night($uid, $q, $p) {
    $chat = (int)$p[1]; $act = $p[2]; $t = (int)$p[3];
    if ($act === 'none') { answer($q['id']); return; }
    $g = gget($chat);
    if (!$g || $g['status'] !== 'night') { answer($q['id'], "Tun tugagan.", true); return; }
    if (!isset($g['players'][$uid]) || empty($g['players'][$uid]['alive'])) { answer($q['id'], "Siz emassiz.", true); return; }
    $me = $g['players'][$uid]; $role = $me['role'];
    if ($act === 'milmenu') {
        if (!has_item($uid, 'miltiq')) { answer($q['id'], "Miltiq yo'q.", true); return; }
        if (isset($g['night']['mil'][$uid])) { answer($q['id'], "Otgansiz.", true); return; }
        $others = array_values(array_diff(alive_ids($g), [$uid])); $rows = []; $row = [];
        foreach ($others as $i) { $row[] = btn($g['players'][$i]['name'], "na:$chat:milshoot:$i"); if (count($row) == 2) { $rows[] = $row; $row = []; } }
        if ($row) $rows[] = $row;
        answer($q['id']); say($uid, "🔫 <b>Kimga?</b>", ikb($rows)); return;
    }
    if ($act === 'milshoot') {
        if (!has_item($uid, 'miltiq')) { answer($q['id'], "Miltiq yo'q.", true); return; }
        if (!isset($g['players'][$t]) || empty($g['players'][$t]['alive']) || $t == $uid) { answer($q['id'], "Xato.", true); return; }
        if (!use_item($uid, 'miltiq')) { answer($q['id'], "Xato.", true); return; }
        $g['night']['mil'][$uid] = $t; gput($g); answer($q['id'], "🔫!");
        say($uid, "🔫 " . nm($g['players'][$t]) . " ga otgansiz."); return;
    }
    $allowed = ['mk' => ['mafia', 'don'], 'ds' => ['don'], 'lw' => ['lawyer'], 'sp' => ['spy'], 'sc' => ['sheriff'], 'ss' => ['sheriff'], 'dc' => ['doctor'], 'es' => ['escort'], 'mn' => ['maniac'], 'bg' => ['bodyguard'], 'tr' => ['tracker'], 'yl' => ['yollanma'], 'ml' => ['malika'], 'dd' => ['daydi'], 'kc' => ['konchi']];
    if (!isset($allowed[$act]) || !in_array($role, $allowed[$act], true)) { answer($q['id']); return; }
    if (!isset($g['players'][$t]) || empty($g['players'][$t]['alive'])) { answer($q['id'], "Tirik emas.", true); return; }
    if (($act === 'mk' || $act === 'yl' || $act === 'ml') && is_mafia_role($g['players'][$t]['role'])) { answer($q['id'], "O'z jamoangiz.", true); return; }
    if ($act === 'lw' && !is_mafia_role($g['players'][$t]['role'])) { answer($q['id'], "Faqat mafiya.", true); return; }
    if (in_array($act, ['ds', 'sp', 'sc', 'ss', 'es', 'mn', 'bg', 'tr', 'dd', 'kc'], true) && $t == $uid) { answer($q['id'], "O'zingiz.", true); return; }
    if ($act === 'dc' && $t == $uid && $me['heal_self']) { answer($q['id'], "Bir marta.", true); return; }
    if ($act === 'es' && $t == $me['last_block']) { answer($q['id'], "Ketma-ket.", true); return; }
    if ($act === 'ss' && $me['shots'] < 1) { answer($q['id'], "O'q yo'q.", true); return; }
    if ($act === 'ds') $g['night']['ds'][$uid] = $t; else $g['night']['act'][$uid] = ['a' => $act, 't' => $t];
    gput($g); answer($q['id'], "✅");
    $msg = isset($q['message']['message_id']) ? $q['message']['message_id'] : 0;
    if ($msg) edit($uid, $msg, "✅ " . h($g['players'][$t]['name']), $q['message']['reply_markup']);
    if (night_complete($g)) resolve_night($chat);
}

function promote_sergeant(&$P) {
    foreach ($P as $p) if ($p['role'] === 'sheriff' && !empty($p['alive'])) return;
    foreach ($P as $id => $p) if ($p['role'] === 'sergeant' && !empty($p['alive'])) { $P[$id]['role'] = 'sheriff'; $P[$id]['shots'] = 2; say($id, "🕵️ Endi <b>Komissarsiz!</b>"); break; }
}

function resolve_night($chat) {
    $g = gget($chat); if (!$g || $g['status'] !== 'night') return;
    $c = cfg();
    $P = $g['players']; $N = $g['night']; $acts = $N['act'] ?? []; $mil = $N['mil'] ?? [];
    $blocked = [];
    foreach ($P as $u => $pp) {
        if ($pp['role'] === 'escort' && !empty($pp['alive'])) {
            if (isset($acts[$u]) && $acts[$u]['a'] === 'es') {
                $target = $acts[$u]['t'];
                if (use_item($target, 'dori_himoya')) { say($target, "💊 Doridan himoya."); $P[$u]['last_block'] = 0; }
                else { $blocked[$target] = 1; $P[$u]['last_block'] = $target; }
            } else $P[$u]['last_block'] = 0;
        }
    }
    $votes = []; $voters = []; $kills = []; $healed = []; $prot = []; $guard = [];
    $checks = []; $spies = []; $tracks = []; $visits = []; $nb = [];
    $malika_targets = []; $yollanma_targets = []; $konchi_results = []; $daydi_targets = [];
    foreach ($acts as $u => $x) {
        if (empty($P[$u]['alive'])) continue;
        if (isset($blocked[$u])) { $nb[] = $u; continue; }
        $t = $x['t']; $a = $x['a'];
        if ($a === 'mk') { $votes[$t] = ($votes[$t] ?? 0) + ($P[$u]['role'] === 'don' ? 1.5 : 1); $voters[$t][] = $u; }
        elseif ($a === 'mn') { $kills[$t][] = 'maniac'; $visits[$t][] = $u; }
        elseif ($a === 'yl') { $yollanma_targets[$u] = $t; $visits[$t][] = $u; }
        elseif ($a === 'ml') { $malika_targets[$u] = $t; $visits[$t][] = $u; }
        elseif ($a === 'ss') { $kills[$t][] = 'sheriff'; $P[$u]['shots']--; $visits[$t][] = $u; }
        elseif ($a === 'dc') { $healed[$t] = $u; if ($t == $u) $P[$u]['heal_self'] = 1; $visits[$t][] = $u; }
        elseif ($a === 'sc') { $checks[] = [$u, $t]; $visits[$t][] = $u; }
        elseif ($a === 'sp') { $spies[] = [$u, $t]; $visits[$t][] = $u; }
        elseif ($a === 'lw') { $prot[$t] = 1; $visits[$t][] = $u; }
        elseif ($a === 'bg') { $guard[$t] = $u; $visits[$t][] = $u; }
        elseif ($a === 'tr') { $tracks[] = [$u, $t]; }
        elseif ($a === 'dd') { $daydi_targets[$u] = $t; }
        elseif ($a === 'kc') { $konchi_results[$u] = $t; }
        elseif ($a === 'es') { $visits[$t][] = $u; }
    }
    $mil_kills = [];
    foreach ($mil as $shooter => $target) { if (!empty($P[$shooter]['alive']) && !empty($P[$target]['alive'])) { $mil_kills[$target][] = $shooter; $visits[$target][] = $shooter; } }
    if ($votes) { arsort($votes); $mt = key($votes); $kills[$mt][] = 'mafia'; $visits[$mt][] = $voters[$mt][0]; }
    
    $dead = []; $saved = [];
    foreach ($kills as $v => $by) {
        if (empty($P[$v]['alive'])) continue;
        if (isset($healed[$v])) { $saved[$v] = 'doc'; continue; }
        if (isset($guard[$v]) && !empty($P[$guard[$v]]['alive']) && $guard[$v] != $v) { $gu = $guard[$v]; $P[$gu]['alive'] = 0; $dead[$gu] = ['bodyguard']; $saved[$v] = 'guard'; continue; }
        $kill_by_maniac = in_array('maniac', $by, true);
        if ($kill_by_maniac && ($u2 = uget($v)) && (($u2['items']['qotil_himoya'] ?? 0) > 0)) { $saved[$v] = 'qotil'; continue; }
        if (use_item($v, 'shield')) { $saved[$v] = 'shield'; continue; }
        if ($P[$v]['role'] === 'lucky' && mt_rand(0, 1) === 1) { $saved[$v] = 'luck'; continue; }
        $P[$v]['alive'] = 0; $dead[$v] = $by;
    }
    foreach ($yollanma_targets as $shooter => $target) {
        if (empty($P[$shooter]['alive']) || empty($P[$target]['alive']) || isset($blocked[$shooter])) continue;
        $u2 = uget($target);
        if ($u2 && ($u2['items']['qotil_himoya'] ?? 0) > 0) { $saved[$target] = 'qotil'; say($target, "⛑️ Qotildan himoya yollanma o'qini to'xtatdi!"); continue; }
        $P[$target]['alive'] = 0; $dead[$target][] = 'yollanma';
        say($shooter, "🥷 " . nm($P[$target]) . " o'ldirildi.");
    }
    foreach ($malika_targets as $shooter => $target) {
        if (empty($P[$shooter]['alive']) || empty($P[$target]['alive']) || isset($blocked[$shooter])) continue;
        $u2 = uget($target);
        if ($u2 && ($u2['items']['dori_himoya'] ?? 0) > 0) { $saved[$target] = 'dori'; say($target, "💊 Doridan himoya Malika zaharini to'xtatdi!"); continue; }
        $P[$target]['alive'] = 0; $dead[$target][] = 'malika';
        say($shooter, "👸 " . nm($P[$target]) . " dorilandi.");
        say($target, "☠️ Malika sizni doriladi.");
    }
    foreach ($mil_kills as $v => $shooters) {
        if (empty($P[$v]['alive'])) continue;
        if (use_item($v, 'geroy_himoya')) { $saved[$v] = 'geroy_himoya'; continue; }
        $P[$v]['alive'] = 0; $dead[$v] = ['miltiq'];
    }
    foreach ($konchi_results as $u => $t) {
        if (empty($P[$u]['alive']) || isset($blocked[$u])) continue;
        $u2 = uget($u);
        if (mt_rand(1, 100) <= 10) {
            if ($u2 && ($u2['items']['sirpanish'] ?? 0) > 0) { $us = J('users'); $us[$u]['items']['sirpanish']--; S('users', $us); say($u, "🪤 Sirpanish himoyasi!"); }
            else { $P[$u]['alive'] = 0; $dead[$u] = ['sirpanish']; say($u, "⛏ Sirpanib o'ldingiz."); }
            continue;
        }
        if (mt_rand(1, 100) <= 20) { $amt = mt_rand(100, 500); addcoins($u, $amt); say($u, "⛏ <b>Xazina!</b> +" . fmtn($amt)); }
        else say($u, "⛏ Bo'sh.");
    }
    foreach ($daydi_targets as $u => $t) {
        if (empty($P[$u]['alive']) || isset($blocked[$u])) continue;
        $u2 = uget($t);
        if ($u2 && ($u2['items']['maska'] ?? 0) > 0) { say($u, "🚶 " . nm($P[$t]) . " — ❓ maska"); continue; }
        say($u, "🚶 " . nm($P[$t]) . " — " . role_name($P[$t]['role']));
    }
    foreach ($nb as $u) say($u, "💃 Band qilindingiz.");
    foreach ($checks as $ck) {
        list($s, $t) = $ck; if (empty($P[$s]['alive'])) continue;
        $u2 = uget($t);
        if ($u2 && ($u2['items']['maska'] ?? 0) > 0) { say($s, "🔍 " . nm($P[$t]) . " — 👨‍🌾 <b>Tinch</b>"); continue; }
        $mf = in_array($P[$t]['role'], ['mafia', 'lawyer', 'spy', 'don', 'yollanma', 'malika'], true);
        if ($mf && (isset($prot[$t]) || use_item($t, 'doc'))) $mf = false;
        say($s, "🔍 " . nm($P[$t]) . " — " . ($mf ? "🤵🏻 <b>MAFIYA</b>" : "👨‍🌾 <b>Tinch</b>"));
    }
    foreach ($spies as $sp) {
        list($s, $t) = $sp; if (empty($P[$s]['alive'])) continue;
        $u2 = uget($t);
        if ($u2 && ($u2['items']['maska'] ?? 0) > 0) say($s, "🕶 " . nm($P[$t]) . " — 👨‍🌾 Tinch");
        else say($s, "🕶 " . nm($P[$t]) . " — " . role_name($P[$t]['role']));
    }
    if (!empty($N['ds'])) foreach ($N['ds'] as $d => $t) {
        if (empty($P[$d]['alive']) || isset($blocked[$d])) continue;
        say($d, "🎩 " . nm($P[$t]) . " — " . ($P[$t]['role'] === 'sheriff' ? "✅ KOMISSAR" : "❌ emas"));
    }
    foreach ($tracks as $tr) {
        list($s, $t) = $tr; if (empty($P[$s]['alive']) || isset($blocked[$s])) continue;
        $vs = isset($visits[$t]) ? array_values(array_unique(array_diff($visits[$t], [$s]))) : [];
        if (!$vs) say($s, "👁 " . nm($P[$t]) . " — hech kim.");
        else { $names = []; foreach ($vs as $x) $names[] = nm($P[$x]); say($s, "👁 " . nm($P[$t]) . " — " . implode(', ', $names)); }
    }
    foreach ($saved as $v => $how) {
        if ($how === 'doc') { say($v, "🩹 Shifokor qutqardi!"); if (isset($healed[$v])) say($healed[$v], "✅ Siz " . nm($P[$v]) . " ni qutqardingiz!"); }
        elseif ($how === 'guard') say($v, "🛡 Qo'riqchi fido!");
        elseif ($how === 'shield') say($v, "🛡 Himoya!");
        elseif ($how === 'luck') say($v, "🤞 Omad!");
        elseif ($how === 'qotil') say($v, "⛑️ Qotildan himoya!");
        elseif ($how === 'dori') say($v, "💊 Doridan himoya!");
        elseif ($how === 'geroy_himoya') say($v, "🔰 Geroydan himoya!");
    }
    promote_sergeant($P);
    $g['players'] = $P; $g['night'] = [];
    
    // KUN BOSHLANISHI GIF
    $day_gif = $c['day_media'] ?? '';
    sendMedia($chat, $day_gif, "☀️ <b>{$g['day']}-kun boshlandi.</b>");
    
    // BATAFSIL O'LIM XABARI
    $death_text = "";
    if ($saved) $death_text .= "🕊 <b>Kimningdir himoyasi ishladi!</b>\n";
    if ($dead) {
        $death_text .= "🧟‍♂️ <b>Tunda o'ldirilganlar:</b>\n\n";
        foreach ($dead as $v => $by) {
            $role_icon = role_name($P[$v]['role']);
            $player = nm($P[$v]);
            $killer = "💀 Noma'lum";
            if (in_array('mafia', $by)) $killer = "🤵🏻 Mafiya";
            elseif (in_array('don', $by)) $killer = "🎩 Don";
            elseif (in_array('maniac', $by)) $killer = "🔪 Qotil";
            elseif (in_array('yollanma', $by)) $killer = "🥷 Yollanma qotil";
            elseif (in_array('malika', $by)) $killer = "👸 Malika";
            elseif (in_array('sheriff', $by)) $killer = "🕵️ Komissar (Robin Gud)";
            elseif (in_array('miltiq', $by)) $killer = "🔫 Miltiqdor";
            elseif (in_array('sirpanish', $by)) $killer = "⛏ Taqdir (Sirpanish)";

            $death_text .= "Tunda $role_icon $player...\nvaxshiylarcha o'ldirildi.\nAytishlaricha, unikiga <b>$killer</b> kelgan.\n\n";
        }
    } else { $death_text .= "🕊 Tunda hech kim o'lmadi.\n"; }
    
    gput($g); say($chat, $death_text);
    update_status($chat);
    
    $w = check_win($g); if ($w !== null) { end_game($chat, $w); return; }
    start_day($chat);
}

function start_day($chat) {
    $g = gget($chat); if (!$g) return;
    $c = cfg();
    $g['status'] = 'day'; $g['phase_end'] = time() + $c['day_time']; $g['warned'] = 0; gput($g);
    say($chat, "🗣 <b>Muhokama!</b>\n⏳ {$c['day_time']} soniya\n\n👥 " . plist($g));
    foreach (alive_ids($g) as $id) if (has_item($id, 'geroy')) say($id, "🥷 <b>Geroy</b>", ikb([[btn("🥷 Otish", "gd:$chat:menu:0")]]));
    update_status($chat);
}

function cb_geroy($uid, $q, $p) {
    $chat = (int)$p[1]; $act = $p[2]; $t = isset($p[3]) ? (int)$p[3] : 0;
    $g = gget($chat);
    if (!$g || $g['status'] !== 'day') { answer($q['id'], "Kunduz emas.", true); return; }
    if (!has_item($uid, 'geroy')) { answer($q['id'], "Geroy yo'q.", true); return; }
    if ($act === 'menu') {
        $others = array_values(array_diff(alive_ids($g), [$uid])); $rows = []; $row = [];
        foreach ($others as $i) { $row[] = btn($g['players'][$i]['name'], "gd:$chat:shoot:$i"); if (count($row) == 2) { $rows[] = $row; $row = []; } }
        if ($row) $rows[] = $row;
        answer($q['id']); say($uid, "🥷 <b>Kimga?</b>", ikb($rows)); return;
    }
    if ($act === 'shoot') {
        if (!isset($g['players'][$t]) || empty($g['players'][$t]['alive']) || $t == $uid) { answer($q['id'], "Xato.", true); return; }
        if (!use_item($uid, 'geroy')) { answer($q['id'], "Xato.", true); return; }
        if (use_item($t, 'geroy_himoya')) { say($uid, "🔰 " . nm($g['players'][$t]) . " himoyalangan!"); say($t, "🔰 Geroydan himoya!"); answer($q['id'], "Himoyalangan!"); return; }
        $g['players'][$t]['alive'] = 0; gput($g);
        say($chat, "🥷 " . nm($g['players'][$uid]) . " → " . nm($g['players'][$t]));
        say($t, "🥷 Geroy otib o'ldirdi.");
        answer($q['id'], "✅");
        update_status($chat);
        $w = check_win($g); if ($w !== null) end_game($chat, $w);
        return;
    }
    answer($q['id']);
}

function cb_qm($uid, $q, $p) {
    $chat = (int)$p[1]; $sub = $p[2]; $t = isset($p[3]) ? (int)$p[3] : 0;
    $g = gget($chat);
    if (!$g || $g['status'] !== 'night') { answer($q['id'], "Tun emas.", true); return; }
    if (!isset($g['players'][$uid]) || empty($g['players'][$uid]['alive'])) { answer($q['id'], "Emassiz.", true); return; }
    if (!has_item($uid, 'qora_materiya')) { answer($q['id'], "Yo'q.", true); return; }
    if (!empty($g['qm_used'])) { answer($q['id'], "Ishlatilgan.", true); return; }
    if ($sub === 'menu') { answer($q['id']); say($uid, "🌑 <b>Qora Materiya</b>", ikb([[btn("🌀 Void Swap", "qm:$chat:swapmenu:0")],[btn("💥 Tosh", "qm:$chat:tosh:0")],[btn("✨ Tiriltirish", "qm:$chat:revivemenu:0")],[btn("💎 Xazina", "qm:$chat:xazina:0")]])); return; }
    if ($sub === 'swapmenu') {
        $others = array_values(array_diff(alive_ids($g), [$uid])); $rows = []; $row = [];
        foreach ($others as $i) { $row[] = btn($g['players'][$i]['name'], "qm:$chat:swap:$i"); if (count($row) == 2) { $rows[] = $row; $row = []; } }
        if ($row) $rows[] = $row;
        answer($q['id']); say($uid, "🌀 <b>3 kunlik swap</b>", ikb($rows)); return;
    }
    if ($sub === 'revivemenu') {
        $dead = []; foreach ($g['players'] as $id => $pp) if (empty($pp['alive'])) $dead[] = $id;
        if (!$dead) { answer($q['id'], "O'lgan yo'q.", true); return; }
        $rows = []; foreach ($dead as $i) $rows[] = [btn("✨ " . $g['players'][$i]['name'], "qm:$chat:revive:$i")];
        answer($q['id']); say($uid, "✨", ikb($rows)); return;
    }
    if ($sub === 'swap') {
        if (!isset($g['players'][$t]) || empty($g['players'][$t]['alive'])) { answer($q['id'], "Xato.", true); return; }
        if (!empty($g['swap'])) { answer($q['id'], "Faol.", true); return; }
        $me = $g['players'][$uid]; $other = $g['players'][$t];
        $g['players'][$uid]['role'] = $other['role']; $g['players'][$t]['role'] = $me['role'];
        $g['qm_used'] = 1; use_item($uid, 'qora_materiya');
        $g['swap'] = ['a' => $uid, 'b' => $t, 'expire_day' => $g['day'] + 3]; gput($g);
        answer($q['id'], "🌀!");
        say($uid, "🌀 Yangi rolingiz: " . role_name($g['players'][$uid]['role']));
        say($t, "🌀 Yangi rolingiz: " . role_name($g['players'][$t]['role']));
        say($chat, "🌑 Rollar 3 kunga almashdi!"); return;
    }
    if ($sub === 'tosh') {
        $users = J('users'); $cnt = 0;
        foreach ($g['players'] as $id => $pp) { if (!isset($users[$id])) continue; foreach (['shield','doc','geroy_himoya','sirpanish','qotil_himoya','dori_himoya'] as $k) { if (($users[$id]['items'][$k] ?? 0) > 0) { $users[$id]['items'][$k] = 0; $cnt++; } } }
        S('users', $users); $g['qm_used'] = 1; use_item($uid, 'qora_materiya'); gput($g);
        answer($q['id'], "💥!"); say($chat, "💥 Himoyalar yo'q qilindi ($cnt ta)!"); return;
    }
    if ($sub === 'revive') {
        if (!isset($g['players'][$t]) || !empty($g['players'][$t]['alive'])) { answer($q['id'], "Tirik.", true); return; }
        $g['players'][$t]['alive'] = 1; $g['qm_used'] = 1; use_item($uid, 'qora_materiya'); gput($g);
        answer($q['id'], "✨!"); say($chat, "✨ " . nm($g['players'][$t]) . " qaytdi!");
        say($t, "✨ Siz tiriltirildingiz. Rolingiz: " . role_name($g['players'][$t]['role'])); return;
    }
    if ($sub === 'xazina') {
        $g['qm_used'] = 1; use_item($uid, 'qora_materiya'); gput($g);
        if (mt_rand(0, 1) === 1) { addcoins($uid, 1000); say($uid, "💎 Xazina! +1000 tanga"); }
        else { adddiamonds($uid, 20); say($uid, "💎 Xazina! +20 olmos"); }
        answer($q['id'], "💎!"); say($chat, "💎 Kimdir xazina topdi!"); return;
    }
    answer($q['id']);
}

function vote_kb($g) { $rows = []; $row = []; foreach (alive_ids($g) as $id) { $row[] = btn("👤 " . $g['players'][$id]['name'], "vt:{$g['chat']}:$id"); if (count($row) == 2) { $rows[] = $row; $row = []; } } if ($row) $rows[] = $row; $rows[] = [btn("🚫 Hech kim", "vt:{$g['chat']}:0")]; return ikb($rows); }
function tally($g) { $cnt = []; $skip = 0; foreach ($g['votes'] as $v => $t) { if (empty($g['players'][$v]['alive'])) continue; $w = ($g['players'][$v]['role'] === 'mayor') ? 2 : 1; if ($t == 0) $skip += $w; else $cnt[$t] = ($cnt[$t] ?? 0) + $w; } return [$cnt, $skip]; }
function vote_text($g) {
    $left = max(0, $g['phase_end'] - time()); list($cnt, $skip) = tally($g);
    $t = "🗳 <b>Ovoz!</b> ⏳ $left\n\n";
    foreach (alive_ids($g) as $id) { $k = $cnt[$id] ?? 0; if ($k > 0) $t .= "• " . nm($g['players'][$id]) . " — <b>$k</b>\n"; }
    if ($skip > 0) $t .= "• 🚫 — <b>$skip</b>\n";
    return $t . "\n" . count($g['votes']) . "/" . count(alive_ids($g));
}
function start_vote($chat) {
    $g = gget($chat); if (!$g) return;
    $c = cfg(); $g['status'] = 'vote'; $g['votes'] = []; $g['phase_end'] = time() + $c['vote_time']; $g['warned'] = 0;
    $r = say($chat, vote_text($g), vote_kb($g));
    $g['vote_msg'] = $r['result']['message_id'] ?? 0; gput($g);
    update_status($chat);
}
function cb_vote($uid, $q, $p) {
    $chat = (int)$p[1]; $t = (int)$p[2];
    $g = gget($chat);
    if (!$g || $g['status'] !== 'vote') { answer($q['id'], "Tugagan.", true); return; }
    if (!isset($g['players'][$uid]) || empty($g['players'][$uid]['alive'])) { answer($q['id'], "Faqat tirik.", true); return; }
    if ($t != 0 && (!isset($g['players'][$t]) || empty($g['players'][$t]['alive']))) { answer($q['id'], "Tirik emas.", true); return; }
    if ($t == $uid) { answer($q['id'], "O'zingiz.", true); return; }
    $g['votes'][$uid] = $t; gput($g); answer($q['id'], "✅");
    if (!empty($g['vote_msg'])) edit($chat, $g['vote_msg'], vote_text($g), vote_kb($g));
    if (count($g['votes']) >= count(alive_ids($g))) resolve_vote($chat);
}
function resolve_vote($chat) {
    $g = gget($chat); if (!$g || $g['status'] !== 'vote') return;
    $c = cfg(); list($cnt, $skip) = tally($g); $P = $g['players'];
    $txt = "🗳 <b>Natija.</b>\n\n"; $lynch = null;
    if ($cnt) { arsort($cnt); $keys = array_keys($cnt); $top = $cnt[$keys[0]]; if ($top > ($cnt[$keys[1]] ?? 0) && $top > $skip) $lynch = $keys[0]; }
    $jester_win = false;
    if ($lynch === null) $txt .= "🤷 Bo'lindi.";
    else {
        $v = $lynch;
        if (use_item($v, 'rebirth')) $txt .= "✨ " . nm($P[$v]) . " ovozdan himoya!";
        else {
            $P[$v]['alive'] = 0;
            $txt .= "⚖️ " . nm($P[$v]) . " chiqarildi" . ($c['reveal_role'] ? " — " . role_name($P[$v]['role']) : '');
            say($v, "⚖️ Chiqarildingiz.");
            if ($P[$v]['role'] === 'jester') $jester_win = true;
            if ($P[$v]['role'] === 'kamikaze') { $cands = []; foreach ($g['votes'] as $vv => $tt) if ($tt == $v && !empty($P[$vv]['alive']) && $vv != $v) $cands[] = $vv; if ($cands) { $k = $cands[array_rand($cands)]; $P[$k]['alive'] = 0; $txt .= "\n💣 " . nm($P[$k]) . " ni olib ketdi!"; say($k, "💣"); } }
        }
    }
    promote_sergeant($P); $g['players'] = $P; $g['votes'] = []; gput($g); say($chat, $txt);
    update_status($chat);
    if ($jester_win) { end_game($chat, 'jester'); return; }
    $w = check_win($g); if ($w !== null) { end_game($chat, $w); return; }
    start_night($chat);
}

function check_win($g) {
    $m = 0; $o = 0; $man = 0;
    foreach ($g['players'] as $p) { if (empty($p['alive'])) continue; if (is_mafia_role($p['role'])) $m++; else { $o++; if ($p['role'] === 'maniac') $man++; } }
    $alive = $m + $o;
    if ($alive == 0) return 'draw';
    if ($m == 0 && $man == 0) return 'town';
    if ($m == 0) return ($alive <= 2) ? 'maniac' : null;
    if ($man == 0) return ($m >= $o) ? 'mafia' : null;
    if ($alive <= 2) return 'draw';
    return ($m > $o) ? 'mafia' : null;
}
function end_game($chat, $w) {
    $g = gget($chat); if (!$g) return;
    $c = cfg(); $P = $g['players'];
    $heads = ['mafia' => "🤵🏻 <b>MAFIYA!</b>", 'town' => "🏙 <b>TINCH AHOLI!</b>", 'maniac' => "🔪 <b>QOTIL!</b>", 'jester' => "🤡 <b>MASXARABOZ!</b>", 'draw' => "🤝 <b>DURANG!</b>"];
    $users = J('users'); $week = date('o-W');
    $winners = ''; $all = ''; $dms = [];
    foreach ($P as $id => $p) {
        $r = $p['role']; $team = role_team($r);
        $won = ($w === 'mafia' && $team === 'mafia') || ($w === 'town' && $team === 'town') || ($w === 'maniac' && $r === 'maniac') || ($w === 'jester' && $r === 'jester');
        $all .= (!empty($p['alive']) ? '🟢 ' : '💀 ') . nm($p) . " — " . role_name($r) . ($won ? ' 🏆' : '') . "\n";
        if ($won) $winners .= "• " . nm($p) . " — " . role_name($r) . "\n";
        if (!isset($users[$id])) continue;
        $u = $users[$id];
        $u['games']++;
        if ($won) { $u['wins']++; $u['streak']++; $u['best_streak'] = max($u['best_streak'], $u['streak']); } elseif ($w !== 'draw') $u['streak'] = 0;
        $rs = $u['roles'][$r] ?? [0, 0]; $u['roles'][$r] = [(int)$rs[0] + 1, (int)$rs[1] + ($won ? 1 : 0)];
        $coin = $won ? $c['win_coins'] : $c['lose_coins']; if (!empty($p['alive'])) $coin += $c['survive_bonus'];
        $u['coins'] += $coin;
        $rt = $won ? $c['rt_win'] : ($w === 'draw' ? 0 : -$c['rt_lose']); $u['rating'] = max(0, $u['rating'] + $rt);
        if ($u['week'] !== $week) { $u['week'] = $week; $u['wwins'] = 0; } if ($won) $u['wwins']++;
        $new = ach_check($u);
        if (!empty($u['ref']) && empty($u['refpaid'])) {
            $u['refpaid'] = 1; $rid = $u['ref'];
            if (isset($users[$rid])) {
                $users[$rid]['coins'] += $c['ref_bonus'];
                $users[$rid]['ref_active'] = (int)($users[$rid]['ref_active'] ?? 0) + 1;
                say($rid, "🎁 Do'stingiz o'ynadi: +" . fmtn($c['ref_bonus']) . " tanga!");
                $need = max(1, (int)($c['ref_need'] ?? 3)); $dia = max(0, (int)($c['ref_diamonds'] ?? 10));
                $before = (int)($users[$rid]['ref_rewarded'] ?? 0); $milestone = (int)floor($users[$rid]['ref_active'] / $need);
                if ($dia > 0 && $milestone > $before) { $gain = ($milestone - $before) * $dia; $users[$rid]['diamonds'] = (int)($users[$rid]['diamonds'] ?? 0) + $gain; $users[$rid]['ref_rewarded'] = $milestone; say($rid, "💎 Referal: +" . fmtn($gain) . " olmos!"); }
            }
        }
        $users[$id] = $u;
        $t = ($won ? "🏆 <b>G'alaba!</b>" : ($w === 'draw' ? "🤝 Durang." : "😔 Yutqazdingiz.")) . "\n\n🎭 " . role_name($r) . "\n💰 +$coin\n⭐ {$u['rating']}";
        foreach ($new as $a) $t .= "\n🎖 " . $a[0] . " (+" . $a[2] . ")";
        $dms[$id] = $t;
    }
    S('users', $users);
    foreach ($dms as $id => $t) say($id, $t);
    $st = J('stats'); $st['games'] = ($st['games'] ?? 0) + 1; S('stats', $st);
    $gr = J('groups'); if (isset($gr[$chat])) { $gr[$chat]['games'] = ($gr[$chat]['games'] ?? 0) + 1; S('groups', $gr); }
    $dur = max(1, (int)round((time() - ($g['started'] ?? time())) / 60));
    say($chat, ($heads[$w] ?? $heads['draw']) . "\n\n" . ($winners !== '' ? "🏆 <b>G'oliblar:</b>\n$winners\n" : '') . "📋 <b>Hamma:</b>\n$all\n⏱ $dur daqiqa.\n\n/game");
    
    if (!empty($g['status_msg_id'])) tg('deleteMessage', ['chat_id' => $chat, 'message_id' => $g['status_msg_id']]);
    gdel($chat);
}

function warn_game($g, $msg) { $g['warned'] = 1; gput($g); say($g['chat'], $msg); }
function check_games() {
    $now = time();
    foreach (J('games') as $chat => $g) {
        $st = $g['status'];
        
        if (!empty($g['status_msg_id']) && $now - ($g['last_status_update'] ?? 0) >= 5) {
            $g['last_status_update'] = $now; gput($g); update_status($chat);
        }

        if ($now - ($g['created'] ?? $now) > 10800) { say($chat, "⏹ Vaqt."); gdel($chat); continue; }
        if ($st === 'reg') { if ($now >= $g['reg_end']) begin_game($chat); elseif (empty($g['warned']) && $g['reg_end'] - $now <= 15) warn_game($g, "⏰ 15s!"); }
        elseif ($st === 'night') { if ($now >= $g['phase_end']) resolve_night($chat); elseif (empty($g['warned']) && $g['phase_end'] - $now <= 15) warn_game($g, "⏰ Tun 15s!"); }
        elseif ($st === 'day') { if ($now >= $g['phase_end']) start_vote($chat); }
        elseif ($st === 'vote') { if ($now >= $g['phase_end']) resolve_vote($chat); elseif (empty($g['warned']) && $g['phase_end'] - $now <= 15) warn_game($g, "⏰ Ovoz 15s!"); }
    }
}

// ======================= MENYU =======================
const B_DIAMONDS = "💎 Olmos";
const B_PROFILE = "👤 Profil";
const B_TOP = "🏆 Reyting";
const B_SHOP = "🛒 Do'kon";
const B_ACH = "🎖 Yutuqlar";
const B_DAILY = "🎁 Kunlik bonus";
const B_REF = "👥 Referal";
const B_RULES = "📖 Qo'llanma";
const B_ADMIN = "🛠 Boshqaruv";
const B_BALANCE = "💰 Balans";
const B_OLTIN = "🥇 Oltin";
const B_TRANSFER = "💸 O'tkazma";
const B_SUPPORT = "🆘 Qo'llab-quvvatlash";

function main_kb($uid) { 
    $rows = [
        [B_PROFILE, B_TOP], 
        [B_SHOP, B_ACH], 
        [B_DAILY, B_DIAMONDS], 
        [B_BALANCE, B_OLTIN], 
        [B_TRANSFER, B_REF], 
        [B_SUPPORT, B_RULES]
    ]; 
    if (isAdmin($uid)) $rows[] = [B_ADMIN]; 
    return kb($rows); 
}
function sub_missing($uid) { $c = cfg(); $miss = []; foreach ($c['channels'] as $ch) { $r = tg('getChatMember', ['chat_id' => $ch, 'user_id' => $uid]); if (empty($r['ok'])) continue; if (!in_array($r['result']['status'], ['creator', 'administrator', 'member', 'restricted'], true)) $miss[] = $ch; } return $miss; }
function sub_gate($uid) { if (isAdmin($uid)) return true; $miss = sub_missing($uid); if (!$miss) return true; $rows = []; foreach ($miss as $ch) $rows[] = [ubtn("📣 $ch", 'https://t.me/' . ltrim($ch, '@'))]; $rows[] = [btn("✅", "chk")]; say($uid, "📣 Obuna bo'ling:", ikb($rows)); return false; }

function join_game($uid, $chat) {
    $g = gget($chat); $c = cfg();
    if (!$g || $g['status'] !== 'reg') { say($uid, "😔 Yopilgan.", main_kb($uid)); return; }
    if (isset($g['players'][$uid])) { say($uid, "✅ Siz o'yindasiz.", main_kb($uid)); return; }
    $other = find_game_of($uid); if ($other && $other['chat'] != $chat) { say($uid, "⚠️ Boshqa o'yin."); return; }
    if (count($g['players']) >= $c['max_players']) { say($uid, "😔 To'lgan."); return; }
    $u = uget($uid);
    $g['players'][$uid] = ['id' => $uid, 'name' => ($u['name'] !== '' ? $u['name'] : 'O' . $uid), 'role' => '', 'alive' => 1, 'shots' => 0, 'heal_self' => 0, 'last_block' => 0];
    gput($g); reg_refresh($g);
    say($uid, "✅ «" . h($g['title']) . "» ga qo'shildingiz!", main_kb($uid));
    if (count($g['players']) >= $c['max_players']) begin_game($chat);
}
function start_flow($uid, $arg) {
    if (strpos($arg, 'join_') === 0) { join_game($uid, (int)substr($arg, 5)); return; }
    $c = cfg();
    say($uid, "🎭 <b>Mafiya!</b>\n\n" . ($c['welcome'] !== '' ? h($c['welcome']) . "\n\n" : '') . "Guruhga qo'shing va /game yozing.", main_kb($uid));
}

function show_profile($uid) {
    $u = uget($uid);
    $wr = $u['games'] > 0 ? round($u['wins'] * 100 / $u['games']) : 0;
    $best = ''; $bn = 0; foreach ($u['roles'] as $r => $s) if ($s[0] > $bn) { $bn = $s[0]; $best = $r; }
    $it = $u['items'];
    $t = "👤 <b>" . h($u['name']) . "</b>\n\n⭐ <b>{$u['rating']}</b>  💰 <b>" . fmtn($u['coins']) . "</b>\n💎 <b>" . fmtn($u['diamonds'] ?? 0) . "</b>  🥇 <b>" . fmtn($u['oltin'] ?? 0) . "</b>\n💵 Balans: <b>" . fmtn($u['balance'] ?? 0) . "</b> so'm\n🎮 {$u['games']} · 🏆 {$u['wins']} ($wr%) · 🔥 {$u['streak']}\n";
    if ($best !== '') $t .= "🎭 " . role_name($best) . " ($bn)\n";
    $t .= "\n🎒 <b>Buyumlar:</b>\n📁{$it['doc']} 🛡{$it['shield']} ⚖️{$it['rebirth']}\n⛑️{$it['qotil_himoya']} 🔫{$it['miltiq']} 💊{$it['dori_himoya']}\n🎭{$it['maska']} 🥷{$it['geroy']} 🪤{$it['sirpanish']}\n🔰{$it['geroy_himoya']} 🌑{$it['qora_materiya']}\n🎖 " . count($u['ach']) . "/" . count(ACH());
    say($uid, $t, main_kb($uid));
}
function top_text($kind) {
    $us = J('users'); $rows = []; $week = date('o-W');
    foreach ($us as $id => $u) {
        if ($kind === 'rating' && $u['games'] > 0) $rows[] = [$u['name'], $u['rating'], "⭐"];
        elseif ($kind === 'week' && $u['week'] === $week && $u['wwins'] > 0) $rows[] = [$u['name'], $u['wwins'], "🏆"];
        elseif ($kind === 'coins' && $u['coins'] > 0) $rows[] = [$u['name'], $u['coins'], "💰"];
    }
    usort($rows, function ($a, $b) { return $b[1] - $a[1]; });
    $titles = ['rating' => "⭐ Reyting", 'week' => "🏆 Hafta", 'coins' => "💰 Boylar"];
    $t = "<b>" . $titles[$kind] . " TOP 10</b>\n\n"; $i = 0;
    foreach (array_slice($rows, 0, 10) as $r) { $i++; $m = $i <= 3 ? ['🥇', '🥈', '🥉'][$i - 1] : "$i."; $t .= "$m " . h($r[0]) . " — <b>" . fmtn($r[1]) . "</b> " . $r[2] . "\n"; }
    if (!$rows) $t .= "Bo'sh.";
    return $t;
}
function top_kb() { return ikb([[btn("⭐", "rt:rating"), btn("🏆", "rt:week"), btn("💰", "rt:coins")]]); }

function shop_items() {
    return [
        'doc' => ["📁 Hujjatlar", "Komissar tekshiruvida tinch", 'p_doc'],
        'shield' => ["🛡 Himoya", "Bir marta hayotingizni saqlaydi", 'p_shield'],
        'rebirth' => ["⚖️ Ovozdan himoya", "Osishdan bir marta", 'p_rebirth'],
        'qotil_himoya' => ["⛑️ Qotildan himoya", "🔪 va 🥷 qotildan saqlaydi", 'p_qotil_himoya'],
        'miltiq' => ["🔫 Miltiq", "Himoyalarni teshib o'ldiradi", 'p_miltiq'],
        'dori_himoya' => ["💊 Doridan himoya", "Malika dorisidan saqlaydi", 'p_dori_himoya'],
        'maska' => ["🎭 Maska", "Daydi taniy olmaydi", 'p_maska'],
        'geroy' => ["🥷 Geroy", "Kunduz otish (1 o'q)", 'p_geroy'],
        'sirpanish' => ["🪤 Sirpanish himoyasi", "Konchi sirpanib o'lishdan", 'p_sirpanish'],
        'geroy_himoya' => ["🔰 Geroydan himoya", "Geroy o'qidan saqlaydi", 'p_geroy_himoya'],
        'qora_materiya' => ["🌑 Qora Materiya", "4 qobiliyat", 'p_qora_materiya'],
    ];
}
function shop_text($uid) {
    $u = uget($uid); $c = cfg();
    $t = "🛒 <b>Do'kon</b>\n💰 " . fmtn($u['coins']) . " tanga · 💎 " . fmtn($u['diamonds'] ?? 0) . "\n\n";
    foreach (shop_items() as $k => $it) {
        $own = $u['items'][$k] ?? 0; $tp = $c['p_' . $k] ?? 0; $dp = $c['pd_' . $k] ?? 0;
        $t .= $it[0] . " <b>($own)</b>\n🪙 " . fmtn($tp) . ($dp > 0 ? " · 💎 " . fmtn($dp) : '') . "\n<i>" . $it[1] . "</i>\n\n";
    }
    return $t;
}
function shop_kb() {
    $c = cfg(); $rows = [];
    foreach (shop_items() as $k => $it) {
        $tp = $c['p_' . $k] ?? 0; $dp = $c['pd_' . $k] ?? 0;
        if ($dp > 0) $rows[] = [btn($it[0] . " · 🪙" . fmtn($tp), "buy:$k:t"), btn("💎" . fmtn($dp), "buy:$k:d")];
        else $rows[] = [btn($it[0] . " · 🪙" . fmtn($tp), "buy:$k:t")];
    }
    return ikb($rows);
}
function show_ach($uid) {
    $u = uget($uid); $t = "🎖 <b>Yutuqlar</b>\n\n";
    foreach (ACH() as $id => $a) { $done = in_array($id, $u['ach'], true); $t .= ($done ? "✅ " : "🔒 ") . $a[0] . "\n<i>" . $a[1] . "</i> +" . $a[2] . " tanga" . ($done ? '' : " (" . min(ach_stat($u, $a[3]), $a[4]) . "/" . $a[4] . ")") . "\n\n"; }
    say($uid, $t, main_kb($uid));
}
function daily_bonus($uid) {
    $u = uget($uid); $c = cfg(); $today = date('Ymd'); $yest = date('Ymd', strtotime('-1 day'));
    if ($u['daily_d'] === $today) { say($uid, "🎁 Bugun olgansiz.", main_kb($uid)); return; }
    $streak = ($u['daily_d'] === $yest) ? $u['daily_s'] + 1 : 1;
    $b = $c['daily_bonus'] + min($streak - 1, 6) * 10;
    $us = J('users'); $us[$uid]['coins'] += $b; $us[$uid]['daily_d'] = $today; $us[$uid]['daily_s'] = $streak; S('users', $us);
    say($uid, "🎁 <b>+$b tanga!</b> 🔥 $streak", main_kb($uid));
}
function show_ref($uid) {
    $c = cfg(); $n = 0; foreach (J('users') as $u) if ($u['ref'] == $uid) $n++;
    $u = uget($uid); $act = (int)($u['ref_active'] ?? 0); $need = max(1, (int)($c['ref_need'] ?? 3)); $dia = (int)($c['ref_diamonds'] ?? 10);
    $next = $need - ($act % $need); if ($next == $need) $next = $need;
    say($uid, "👥 <b>Referal</b>\n\nBirinchi o'yin uchun:\n• {$c['ref_bonus']} tanga\n• har $need taklif = $dia 💎\n\n🔗 <code>" . bot_link() . "?start=ref$uid</code>\n\n👥 Jami: $n\n✅ Faol: $act\n🎯 Keyingi: $next\n💎 Jami: " . ((int)($u['ref_rewarded'] ?? 0) * $dia), main_kb($uid));
}
function show_rules($uid) {
    $t = "📖 <b>Qo'llanma</b>\n\n1️⃣ /game — guruhda boshlash\n2️⃣ «Qo'shilish» (min " . cfg()['min_players'] . ")\n3️⃣ Rol shaxsiy xabarda\n4️⃣ 🌙 tun → ☀️ kun → 🗳 ovoz\n\n💎 Olmos · 💰 Balans · 🥇 Oltin · 💸 O'tkazma\n\n<b>Buyruqlar:</b> /game · /begin · /extend · /leave · /stop · /give";
    say($uid, $t, main_kb($uid));
    $out = ['town' => '', 'mafia' => '', 'neutral' => ''];
    foreach (ROLES() as $r => $x) $out[$x['team']] .= $x['n'] . " — " . $x['d'] . "\n\n";
    say($uid, "🏙 <b>Tinch</b>\n" . $out['town'] . "\n🤵🏻 <b>Mafiya</b>\n" . $out['mafia'] . "\n🎭 <b>Yolg'iz</b>\n" . $out['neutral']);
}

// ============ 🆘 QO'LLAB-QUVVATLASH ============
function show_support($uid) {
    $c = cfg();
    $btns = $c['support_buttons'] ?? [];
    $rows = [];
    foreach ($btns as $b) {
        if ($b['type'] === 'url') $rows[] = [ubtn($b['text'], $b['value'])];
        else $rows[] = [btn($b['text'], 'sup:show:' . $b['id'])];
    }
    $rows[] = [btn("◀️ Orqaga", "sup:back")];
    say($uid, "🆘 <b>Qo'llab-quvvatlash bo'limi</b>\n\nQuyidagi ma'lumotlardan birini tanlashingiz mumkin:", ikb($rows));
}
function cb_support($uid, $q, $p) {
    $act = $p[1] ?? 'menu';
    $c = cfg();
    if ($act === 'menu') { answer($q['id']); show_support($uid); return; }
    if ($act === 'back') { answer($q['id']); say($uid, "Bosh menyudasiz:", main_kb($uid)); return; }
    if ($act === 'show' && isset($p[2])) {
        $id = $p[2];
        $btns = $c['support_buttons'] ?? [];
        foreach ($btns as $b) {
            if ($b['id'] === $id && $b['type'] === 'text') {
                answer($q['id']);
                say($uid, $b['value'], ikb([[btn("◀️ Orqaga", "sup:menu")]]));
                return;
            }
        }
    }
    answer($q['id']);
}

// ============ 🎁 GIFT ============
function gift_menu($uid, $type, $idx = 0) {
    $u = uget($uid); if (!$u) return;
    $c = cfg();
    $pkgs = $c['gift_packages'] ?? [];
    if (!$pkgs) { say($uid, "😔 Hozircha sovg'alar yo'q."); return; }
    
    $t = "🎁 <b>Sovg'a orqali to'lash</b>\n\n";
    if ($type === 'diamond') $t .= "Siz 💎 <b>" . ($c['diamond_packages'][$idx]['diamonds'] ?? 0) . " olmos</b> sotib olmoqchisiz.\n\n";
    elseif ($type === 'balance') $t .= "Siz 💵 <b>" . fmtn($c['balance_packages'][$idx]['amount'] ?? 0) . " so'm</b> balans sotib olmoqchisiz.\n\n";
    
    $t .= "Quyidagi sovg'alardan birini tanlang va <b>" . h($c['gift_admin']) . "</b> ga yuboring:\n\n";
    $rows = [];
    foreach ($pkgs as $i => $p) {
        $val = $type === 'diamond' ? "💎 " . $p['diamonds'] : "💵 " . fmtn($p['balance']);
        $rows[] = [btn($p['name'] . " (" . $p['stars'] . " yulduz) — $val", "gift:sel:$type:$idx:$i")];
    }
    $rows[] = [btn("◀️ Orqaga", $type === 'diamond' ? "dia:pk:$idx" : "bal:pay:$idx")];
    say($uid, $t, ikb($rows));
}
function cb_gift($uid, $q, $p) {
    $act = $p[1] ?? '';
    $c = cfg();
    if ($act === 'sel' && isset($p[2], $p[3], $p[4])) {
        $type = $p[2]; $idx = (int)$p[3]; $gIdx = (int)$p[4];
        $pkgs = $c['gift_packages'] ?? [];
        if (!isset($pkgs[$gIdx])) { answer($q['id'], "Xato.", true); return; }
        $gift = $pkgs[$gIdx];
        
        if ($type === 'diamond') {
            $dp = $c['diamond_packages'][$idx] ?? [];
            $amount = $gift['diamonds']; 
            $desc = "💎 " . $amount . " olmos";
        } else {
            $bp = $c['balance_packages'][$idx] ?? [];
            $amount = $gift['balance'];
            $desc = "💵 " . fmtn($amount) . " so'm";
        }
        
        $t = "🎁 <b>" . $gift['name'] . "</b>\n\n";
        $t .= "Siz tanlagan sovg'a: <b>" . $gift['stars'] . " yulduz</b>\n";
        $t .= "evaziga olasiz: <b>$desc</b>\n\n";
        $t .= "1️⃣ Quyidagi havola orqali <b>" . h($c['gift_admin']) . "</b> ga o'ting.\n";
        $t .= "2️⃣ Ushbu qimmatdagi sovg'ani sotib olib, yuboring.\n";
        $t .= "3️⃣ So'ngra shu yerga sovg'a yuborilganligi haqidagi <b>skrinshotni</b> tashlang.\n\n";
        $t .= "Admin tasdiqlagach, hisobingizga qo'shiladi.";
        
        setstep($uid, 'gift_proof', ['type' => $type, 'idx' => $idx, 'gift_idx' => $gIdx]);
        answer($q['id']);
        say($uid, $t, ikb([[btn("📸 Skrinshot yuborish", "gift:proof:$type:$idx:$gIdx")], [btn("◀️ Bekor qilish", "gift:menu:$type:$idx")]]));
        return;
    }
    if ($act === 'proof' && isset($p[2], $p[3], $p[4])) {
        answer($q['id'], "Pastdagi xabar orqali skrinshotni yuboring!");
        setstep($uid, 'gift_proof', ['type' => $p[2], 'idx' => (int)$p[3], 'gift_idx' => (int)$p[4]]);
        return;
    }
    if ($act === 'menu' && isset($p[2], $p[3])) { answer($q['id']); gift_menu($uid, $p[2], (int)$p[3]); return; }
    answer($q['id']);
}

// ============ 💸 O'TKAZMA ============
function tr_hist_add($from, $to, $cur, $amt, $fee) {
    $h = J('tr_hist');
    $h[] = ['from' => $from, 'to' => $to, 'cur' => $cur, 'amt' => (int)$amt, 'fee' => (int)$fee, 't' => time()];
    if (count($h) > 2000) $h = array_slice($h, -2000);
    S('tr_hist', $h);
}
function tr_daily_used($uid) {
    $today = date('Ymd'); $sum = 0;
    foreach (J('tr_hist') as $r) if ((string)$r['from'] === (string)$uid && date('Ymd', $r['t']) === $today) $sum += (int)$r['amt'];
    return $sum;
}
function cur_name($cur) { return ['tanga' => '🪙 Tanga', 'olmos' => '💎 Olmos', 'oltin' => '🥇 Oltin', 'balans' => '💵 Balans'][$cur] ?? $cur; }
function cur_balance($uid, $cur) {
    $u = uget($uid); if (!$u) return 0;
    if ($cur === 'tanga') return (int)$u['coins'];
    if ($cur === 'olmos') return (int)($u['diamonds'] ?? 0);
    if ($cur === 'oltin') return (int)($u['oltin'] ?? 0);
    if ($cur === 'balans') return (int)($u['balance'] ?? 0);
    return 0;
}
function cur_add($uid, $cur, $n) {
    if ($cur === 'tanga') addcoins($uid, $n);
    elseif ($cur === 'olmos') adddiamonds($uid, $n);
    elseif ($cur === 'oltin') addoltin($uid, $n);
    elseif ($cur === 'balans') addbalance($uid, $n);
}
function cur_take($uid, $cur, $n) {
    if ($cur === 'tanga') { $u = uget($uid); if (($u['coins'] ?? 0) < $n) return false; addcoins($uid, -$n); return true; }
    if ($cur === 'olmos') return usediamonds($uid, $n);
    if ($cur === 'oltin') return useOltin($uid, $n);
    if ($cur === 'balans') return useBalance($uid, $n);
    return false;
}
function transfer_menu($uid) {
    $u = uget($uid); if (!$u) return;
    $c = cfg();
    if (empty($c['tr_enabled'])) { say($uid, "💸 O'tkazmalar vaqtincha o'chirilgan.", main_kb($uid)); return; }
    $t = "💸 <b>Pul o'tkazish</b>\n\n";
    $t .= "💰 Tanga: <b>" . fmtn($u['coins']) . "</b>\n";
    $t .= "💎 Olmos: <b>" . fmtn($u['diamonds'] ?? 0) . "</b>\n";
    $t .= "🥇 Oltin: <b>" . fmtn($u['oltin'] ?? 0) . "</b>\n";
    $t .= "💵 Balans: <b>" . fmtn($u['balance'] ?? 0) . "</b> so'm\n\n";
    $t .= "📊 Komissiya: <b>" . $c['tr_fee_pct'] . "%</b>\n";
    $t .= "📈 Kunlik limit: <b>" . fmtn($c['tr_daily_limit']) . "</b>\n\n";
    $t .= "Guruhda: <code>/give 100 tanga</code> (reply yoki @user)\n\nNimani o'tkazasiz?";
    say($uid, $t, ikb([
        [btn("🪙 Tanga", "tr:cur:tanga")],
        [btn("💎 Olmos", "tr:cur:olmos")],
        [btn("🥇 Oltin", "tr:cur:oltin")],
        [btn("💵 Balans", "tr:cur:balans")],
        [btn("📜 Tarixim", "tr:hist")],
    ]));
}
function cb_transfer($uid, $q, $p) {
    $u = uget($uid); if (!$u) { answer($q['id']); return; }
    $act = $p[1] ?? 'menu'; $c = cfg();
    $msg = $q['message']['message_id'] ?? 0; $cid = $q['message']['chat']['id'] ?? $uid;
    if (empty($c['tr_enabled'])) { answer($q['id'], "O'chirilgan", true); return; }
    if ($act === 'menu') { answer($q['id']); transfer_menu($uid); return; }
    if ($act === 'cur' && isset($p[2])) {
        $cur = $p[2];
        if (!in_array($cur, ['tanga', 'olmos', 'oltin', 'balans'], true)) { answer($q['id']); return; }
        $bal = cur_balance($uid, $cur);
        $min = (int)$c['tr_min_' . $cur];
        if ($bal < $min) { answer($q['id'], "Sizda " . fmtn($bal) . ". Min: " . fmtn($min), true); return; }
        setstep($uid, 'tr_to', ['cur' => $cur]);
        answer($q['id']);
        $t = cur_name($cur) . " <b>o'tkazish</b>\n\n";
        $t .= "💼 Sizda: <b>" . fmtn($bal) . "</b>\n";
        $t .= "📉 Min: <b>" . fmtn($min) . "</b>\n";
        $t .= "📊 Komissiya: <b>" . $c['tr_fee_pct'] . "%</b>\n\n";
        $t .= "Kimga o'tkazasiz? <b>ID</b> yoki <b>@username</b> yozing:\n\n/cancel";
        say($uid, $t);
        return;
    }
    if ($act === 'hist') {
        answer($q['id']);
        $rows = []; foreach (J('tr_hist') as $r) if ((string)$r['from'] === (string)$uid || (string)$r['to'] === (string)$uid) $rows[] = $r;
        usort($rows, function($a, $b){ return $b['t'] - $a['t']; }); $rows = array_slice($rows, 0, 20);
        if (!$rows) { if ($msg) edit($cid, $msg, "Tarix bo'sh.", ikb([[btn("◀️", "tr:menu")]])); return; }
        $t = "📜 <b>O'tkazma tarixi</b>\n\n";
        foreach ($rows as $r) {
            $dir = (string)$r['from'] === (string)$uid ? "📤" : "📥";
            $t .= "• " . date('d.m H:i', $r['t']) . " $dir <b>" . fmtn($r['amt']) . "</b> " . cur_name($r['cur']) . "\n";
        }
        if ($msg) edit($cid, $msg, $t, ikb([[btn("◀️", "tr:menu")]]));
        return;
    }
    answer($q['id']);
}
function tr_do($from, $to, $cur, $amt) {
    $c = cfg();
    if (empty($c['tr_enabled'])) return [false, "O'tkazmalar o'chirilgan"];
    if ($from == $to) return [false, "O'zingizga o'tkazolmaysiz"];
    $u1 = uget($from); $u2 = uget($to);
    if (!$u1) return [false, "Siz topilmadingiz"];
    if (!$u2) return [false, "Qabul qiluvchi topilmadi (botga /start yozmagan)"];
    if (!empty($u2['banned'])) return [false, "Qabul qiluvchi bloklangan"];
    $min = (int)($c['tr_min_' . $cur] ?? 1);
    if ($amt < $min) return [false, "Minimum: " . fmtn($min) . " " . $cur];
    if (cur_balance($from, $cur) < $amt) return [false, "Mablag' yetarli emas"];
    if ((int)$c['tr_daily_limit'] > 0 && tr_daily_used($from) + $amt > (int)$c['tr_daily_limit']) return [false, "Kunlik limit tugadi"];
    $fee = (int)round($amt * (int)$c['tr_fee_pct'] / 100);
    $net = $amt - $fee;
    if ($net <= 0) return [false, "Komissiya juda katta"];
    if (!cur_take($from, $cur, $amt)) return [false, "Yechishda xato"];
    cur_add($to, $cur, $net);
    tr_hist_add($from, $to, $cur, $amt, $fee);
    return [true, ['fee' => $fee, 'net' => $net, 'to_name' => $u2['name']]];
}

// ============ 💰 BALANS ============
function balance_menu($uid) {
    $u = uget($uid); if (!$u) return;
    $c = cfg();
    $t = "💰 <b>Balans</b>\n\n💵 <b>" . fmtn($u['balance'] ?? 0) . "</b> so'm\n\n🪙 1 tanga = " . fmtn($c['balance_rate_tanga']) . " so'm\n💎 1 olmos = " . fmtn($c['balance_rate_olmos']) . " so'm";
    say($uid, $t, ikb([[btn("🪙 Tanga olish", "bal:tanga")],[btn("💎 Olmos olish", "bal:olmos")],[btn("➕ To'ldirish", "bal:topup")],[btn("🧾 Tarix", "bal:hist")]]));
}
function cb_balance($uid, $q, $p) {
    $u = uget($uid); if (!$u) { answer($q['id']); return; }
    $act = $p[1] ?? 'menu'; $c = cfg();
    $msg = $q['message']['message_id'] ?? 0; $cid = $q['message']['chat']['id'] ?? $uid;
    if ($act === 'menu') { answer($q['id']); $t = "💰 <b>Balans</b>\n\n💵 <b>" . fmtn($u['balance'] ?? 0) . "</b> so'm\n\n🪙 1 tanga = " . fmtn($c['balance_rate_tanga']) . " so'm\n💎 1 olmos = " . fmtn($c['balance_rate_olmos']) . " so'm"; $r = ikb([[btn("🪙 Tanga olish", "bal:tanga")],[btn("💎 Olmos olish", "bal:olmos")],[btn("➕ To'ldirish", "bal:topup")],[btn("🧾 Tarix", "bal:hist")]]); if ($msg) edit($cid, $msg, $t, $r); else say($cid, $t, $r); return; }
    if ($act === 'tanga' || $act === 'olmos') {
        answer($q['id']); $rate = $act === 'tanga' ? (int)$c['balance_rate_tanga'] : (int)$c['balance_rate_olmos'];
        if ($rate <= 0) { if ($msg) edit($cid, $msg, "😔 Kurs yo'q."); return; }
        $cur = $act === 'tanga' ? "tanga" : "olmos"; $sym = $act === 'tanga' ? "🪙" : "💎";
        setstep($uid, 'bal_buy', ['cur' => $act]);
        say($uid, "$sym <b>$cur sotib olish</b>\n\n💵 Balans: <b>" . fmtn($u['balance'] ?? 0) . "</b>\n📊 1 $cur = " . fmtn($rate) . " so'm\n\nNechta? <code>miqdor</code> yozing (masalan: <code>10</code>)\n\n/cancel");
        return;
    }
    if ($act === 'topup') {
        answer($q['id']); $pkgs = $c['balance_packages'] ?? [];
        if (!$pkgs) { if ($msg) edit($cid, $msg, "😔 Paketlar yo'q."); return; }
        $rows = []; foreach ($pkgs as $i => $pp) $rows[] = [btn("💵 " . fmtn($pp['amount']) . " so'm", "bal:pay:$i")];
        $rows[] = [btn("◀️", "bal:menu")];
        if ($msg) edit($cid, $msg, "💰 <b>To'ldirish</b>", ikb($rows)); return;
    }
    if ($act === 'pay' && isset($p[2])) {
        answer($q['id']); $pkgs = $c['balance_packages'] ?? []; $idx = (int)$p[2]; if (!isset($pkgs[$idx])) return;
        $pp = $pkgs[$idx];
        $rows = [];
        if (!empty($c['uzcard_enabled']) && $c['uzcard_merchant_id'] !== '' && $c['uzcard_api_key'] !== '') $rows[] = [btn("🟦 Uzcard", "bal:uz:$idx")];
        $rows[] = [btn("📇 Karta", "bal:manual:$idx")]; 
        if (!empty($c['gift_enabled'])) $rows[] = [btn("🎁 Gift orqali", "gift:menu:balance:$idx")];
        $rows[] = [btn("◀️", "bal:topup")];
        if ($msg) edit($cid, $msg, "💰 <b>" . fmtn($pp['amount']) . " so'm</b>", ikb($rows)); return;
    }
    if ($act === 'manual' && isset($p[2])) {
        answer($q['id']); $pkgs = $c['balance_packages'] ?? []; $idx = (int)$p[2];
        if (!isset($pkgs[$idx]) || empty($c['card_number'])) { if ($msg) edit($cid, $msg, "😔 Karta yo'q."); return; }
        $pp = $pkgs[$idx];
        $t = "📇 <b>Karta</b>\n\n💵 " . fmtn($pp['amount']) . " so'm\n📇 <code>" . h($c['card_number']) . "</code>\n";
        if ($c['card_holder'] !== '') $t .= "👤 " . h($c['card_holder']) . "\n";
        if ($msg) edit($cid, $msg, $t, ikb([[btn("✅ To'lov qildim", "bal:paid:$idx")],[btn("◀️", "bal:pay:$idx")]])); return;
    }
    if ($act === 'uz' && isset($p[2])) {
        answer($q['id']); $pkgs = $c['balance_packages'] ?? []; $idx = (int)$p[2]; if (!isset($pkgs[$idx])) return;
        $pp = $pkgs[$idx];
        $all = J('payments'); $all = array_values(array_filter($all, function($x) use ($uid) { return !($x['uid'] == $uid && $x['status'] === 'pending' && ($x['kind'] ?? '') === 'balance'); })); S('payments', $all);
        $req = pay_add(['uid' => $uid, 'name' => $u['name'], 'un' => $u['un'], 'kind' => 'balance', 'balance' => $pp['amount'], 'price' => $pp['amount'], 'diamonds' => 0, 'pkg_idx' => $idx, 'proof_type' => 'uzcard_auto', 'proof_data' => '', 'user_msg_id' => 0]);
        $host = $_SERVER['HTTP_HOST'] ?? ''; $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
        $base = $scheme . '://' . $host . ($_SERVER['SCRIPT_NAME'] ?? '/');
        $payload = ['merchant_id' => $c['uzcard_merchant_id'], 'amount' => (int)round($pp['amount'] * 100), 'order_id' => $req['id'], 'callback_url' => $base . '?uzcard_cb=1', 'description' => "Balans: " . $pp['amount']];
        $ch = curl_init(rtrim($c['uzcard_api_url'], '/') . '/create');
        curl_setopt_array($ch, [CURLOPT_POST => true, CURLOPT_POSTFIELDS => json_encode($payload), CURLOPT_HTTPHEADER => ['Content-Type: application/json', 'Authorization: Bearer ' . $c['uzcard_api_key']], CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 15]);
        $resp = curl_exec($ch); curl_close($ch); $rd = json_decode((string)$resp, true);
        $pu = $rd['payment_url'] ?? ($rd['data']['payment_url'] ?? '');
        if ($pu === '') {
            $all = J('payments'); $all = array_values(array_filter($all, function($x) use ($req) { return $x['id'] !== $req['id']; })); S('payments', $all);
            $t = "⚠️ Uzcard ishlamaydi.\n📇 <code>" . h($c['card_number']) . "</code>\n💵 " . fmtn($pp['amount']);
            if ($msg) edit($cid, $msg, $t, ikb([[btn("✅ To'lov qildim", "bal:paid:$idx")],[btn("◀️", "bal:topup")]])); return;
        }
        pay_update($req['id'], ['payment_url' => $pu]);
        if ($msg) edit($cid, $msg, "🟦 <b>Uzcard</b>\n\n💵 " . fmtn($pp['amount']) . "\n🧾 <code>" . $req['id'] . "</code>", ikb([[ubtn("💳 To'lash", $pu)],[btn("◀️", "bal:topup")]])); return;
    }
    if ($act === 'paid' && isset($p[2])) {
        answer($q['id']); $pkgs = $c['balance_packages'] ?? []; $idx = (int)$p[2]; if (!isset($pkgs[$idx])) return;
        foreach (J('payments') as $r) if ($r['uid'] == $uid && $r['status'] === 'pending') { say($uid, "⏳ Kuting."); return; }
        setstep($uid, 'bal_proof', ['pkg' => $idx]); say($uid, "📸 <b>Chekni yuboring</b>\n\n/cancel"); return;
    }
    if ($act === 'hist') {
        answer($q['id']); $rows = []; foreach (J('payments') as $r) if ($r['uid'] == $uid && ($r['kind'] ?? 'diamond') === 'balance') $rows[] = $r;
        usort($rows, function($a, $b){ return $b['created'] - $a['created']; }); $rows = array_slice($rows, 0, 10);
        if (!$rows) { if ($msg) edit($cid, $msg, "Bo'sh.", ikb([[btn("➕", "bal:topup")],[btn("◀️", "bal:menu")]])); return; }
        $st = ['pending'=>"⏳",'confirmed'=>"✅",'rejected'=>"❌"];
        $t = "🧾 <b>Balans tarixi</b>\n\n";
        foreach ($rows as $r) $t .= "• <code>" . $r['id'] . "</code> — 💵 " . fmtn($r['balance'] ?? $r['price']) . " · " . ($st[$r['status']] ?? $r['status']) . "\n";
        if ($msg) edit($cid, $msg, $t, ikb([[btn("◀️", "bal:menu")]])); return;
    }
    answer($q['id']);
}

// ============ 🥇 OLTIN ============
function oltin_menu($uid) {
    $u = uget($uid); if (!$u) return;
    $c = cfg();
    $bt = (int)$c['oltin_buy_t']; $st = (int)$c['oltin_sell_t']; $bo = (int)$c['oltin_buy_o']; $so = (int)$c['oltin_sell_o'];
    $t = "🥇 <b>Oltin savdosi</b>\n\n🥇 Sizda: <b>" . fmtn($u['oltin'] ?? 0) . "</b>\n💰 " . fmtn($u['coins']) . " tanga · 💎 " . fmtn($u['diamonds'] ?? 0) . "\n\n📊 <b>Narxlar:</b>\n";
    if ($bt > 0) $t .= "🪙 Sotib olish: 1 oltin = " . fmtn($bt) . " tanga\n";
    if ($st > 0) $t .= "🪙 Sotish: 1 oltin = " . fmtn($st) . " tanga\n";
    if ($bo > 0) $t .= "💎 Sotib olish: 1 oltin = " . fmtn($bo) . " olmos\n";
    if ($so > 0) $t .= "💎 Sotish: 1 oltin = " . fmtn($so) . " olmos\n";
    $t .= "\n<i>Arzon bo'lsa — sotib oling, qimmatlashsa — soting!</i>";
    say($uid, $t, ikb([[btn("🪙 Sotib olish", "olt:buy")],[btn("💰 Sotish", "olt:sell")],[btn("🧾 Tarix", "olt:hist")]]));
}
function cb_oltin($uid, $q, $p) {
    $u = uget($uid); if (!$u) { answer($q['id']); return; }
    $act = $p[1] ?? 'menu'; $c = cfg();
    $msg = $q['message']['message_id'] ?? 0; $cid = $q['message']['chat']['id'] ?? $uid;
    if ($act === 'menu') {
        answer($q['id']);
        $bt = (int)$c['oltin_buy_t']; $st = (int)$c['oltin_sell_t']; $bo = (int)$c['oltin_buy_o']; $so = (int)$c['oltin_sell_o'];
        $t = "🥇 <b>Oltin</b>\n\n🥇 Sizda: <b>" . fmtn($u['oltin'] ?? 0) . "</b>\n\n📊 Narxlar:\n";
        if ($bt > 0) $t .= "🪙 Sotib olish: " . fmtn($bt) . " tanga\n";
        if ($st > 0) $t .= "🪙 Sotish: " . fmtn($st) . " tanga\n";
        if ($bo > 0) $t .= "💎 Sotib olish: " . fmtn($bo) . " olmos\n";
        if ($so > 0) $t .= "💎 Sotish: " . fmtn($so) . " olmos\n";
        $r = ikb([[btn("🪙 Sotib olish", "olt:buy")],[btn("💰 Sotish", "olt:sell")],[btn("🧾 Tarix", "olt:hist")]]);
        if ($msg) edit($cid, $msg, $t, $r); else say($cid, $t, $r); return;
    }
    if ($act === 'buy') {
        answer($q['id']); $bt = (int)$c['oltin_buy_t']; $bo = (int)$c['oltin_buy_o'];
        $rows = [];
        if ($bt > 0) $rows[] = [btn("🪙 Tanga (" . fmtn($bt) . "/1)", "olt:bq:t")];
        if ($bo > 0) $rows[] = [btn("💎 Olmos (" . fmtn($bo) . "/1)", "olt:bq:o")];
        if (!$rows) { if ($msg) edit($cid, $msg, "😔 Narx yo'q."); return; }
        $rows[] = [btn("◀️", "olt:menu")];
        if ($msg) edit($cid, $msg, "🥇 <b>Sotib olish</b>", ikb($rows)); return;
    }
    if ($act === 'bq' && isset($p[2])) {
        $cur = $p[2]; $price = $cur === 't' ? (int)$c['oltin_buy_t'] : (int)$c['oltin_buy_o'];
        if ($price <= 0) { answer($q['id'], "Narx yo'q.", true); return; }
        setstep($uid, 'olt_buy', ['cur' => $cur]);
        $sym = $cur === 't' ? "🪙 tanga" : "💎 olmos";
        answer($q['id']);
        say($uid, "🥇 <b>Sotib olish</b>\n\n💰 1 oltin = " . fmtn($price) . " $sym\n\nNechta? <code>miqdor</code> yozing\n\n/cancel");
        return;
    }
    if ($act === 'sell') {
        answer($q['id']);
        if (($u['oltin'] ?? 0) < 1) { if ($msg) edit($cid, $msg, "😔 Sizda oltin yo'q."); return; }
        $st = (int)$c['oltin_sell_t']; $so = (int)$c['oltin_sell_o'];
        $rows = [];
        if ($st > 0) $rows[] = [btn("🪙 Tangaga (" . fmtn($st) . "/1)", "olt:sq:t")];
        if ($so > 0) $rows[] = [btn("💎 Olmosga (" . fmtn($so) . "/1)", "olt:sq:o")];
        if (!$rows) { if ($msg) edit($cid, $msg, "😔 Narx yo'q."); return; }
        $rows[] = [btn("◀️", "olt:menu")];
        if ($msg) edit($cid, $msg, "💰 <b>Sotish</b>\n\nSizda: <b>" . fmtn($u['oltin']) . "</b> oltin", ikb($rows)); return;
    }
    if ($act === 'sq' && isset($p[2])) {
        $cur = $p[2]; $price = $cur === 't' ? (int)$c['oltin_sell_t'] : (int)$c['oltin_sell_o'];
        if ($price <= 0) { answer($q['id'], "Narx yo'q.", true); return; }
        setstep($uid, 'olt_sell', ['cur' => $cur]);
        $sym = $cur === 't' ? "🪙 tanga" : "💎 olmos";
        answer($q['id']);
        say($uid, "💰 <b>Oltin sotish</b>\n\n💵 1 oltin = " . fmtn($price) . " $sym\n\nNechta? (sizda: " . fmtn($u['oltin']) . ")\n\n/cancel");
        return;
    }
    if ($act === 'hist') {
        answer($q['id']); $hist = J('oltin_hist');
        $rows = []; foreach ($hist as $r) if ((string)$r['uid'] === (string)$uid) $rows[] = $r;
        usort($rows, function($a, $b){ return $b['t'] - $a['t']; }); $rows = array_slice($rows, 0, 15);
        if (!$rows) { if ($msg) edit($cid, $msg, "Bo'sh.", ikb([[btn("◀️", "olt:menu")]])); return; }
        $t = "🧾 <b>Oltin tarixi</b>\n\n";
        foreach ($rows as $r) { $dir = $r['type'] === 'buy' ? "📥" : "📤"; $sym = $r['cur'] === 't' ? "🪙" : "💎"; $t .= "• " . date('d.m H:i', $r['t']) . " $dir <b>" . $r['qty'] . "</b> oltin · $sym " . fmtn($r['total']) . "\n"; }
        if ($msg) edit($cid, $msg, $t, ikb([[btn("◀️", "olt:menu")]])); return;
    }
    answer($q['id']);
}

// ============ 💎 OLMOS ============
function diamond_menu($uid) {
    $u = uget($uid); if (!$u) return;
    say($uid, "💎 <b>Olmos</b>\n\n💎 Balans: <b>" . fmtn($u['diamonds'] ?? 0) . "</b>", ikb([[btn("💳 Sotib olish", "dia:buy")],[btn("🎭 Rollar", "dia:roles")],[btn("🎒 Mening rollarim", "dia:my")],[btn("🧾 To'lovlarim", "dia:hist")]]));
}
function cb_diamond($uid, $q, $p) {
    $u = uget($uid); if (!$u) { answer($q['id']); return; }
    $act = $p[1] ?? 'menu'; $c = cfg();
    $msg = $q['message']['message_id'] ?? 0; $cid = $q['message']['chat']['id'] ?? $uid;
    if ($act === 'menu') { answer($q['id']); $t = "💎 <b>Olmos</b>\n\n💎 Balans: <b>" . fmtn($u['diamonds'] ?? 0) . "</b>"; $r = ikb([[btn("💳 Sotib olish", "dia:buy")],[btn("🎭 Rollar", "dia:roles")],[btn("🎒 Mening rollarim", "dia:my")],[btn("🧾 To'lovlarim", "dia:hist")]]); if ($msg) edit($cid, $msg, $t, $r); else say($cid, $t, $r); return; }
    if ($act === 'buy') { answer($q['id']); $pkgs = $c['diamond_packages'] ?? []; if (!$pkgs) { if ($msg) edit($cid, $msg, "😔 Paketlar yo'q."); return; } $rows = []; foreach ($pkgs as $i => $pp) $rows[] = [btn("💎 " . $pp['diamonds'] . " — " . fmtn($pp['price']) . " so'm", "dia:pk:$i")]; $rows[] = [btn("◀️", "dia:menu")]; if ($msg) edit($cid, $msg, "💎 <b>Paketlar</b>", ikb($rows)); return; }
    if ($act === 'pk' && isset($p[2])) {
        answer($q['id']); $pkgs = $c['diamond_packages'] ?? []; $idx = (int)$p[2]; if (!isset($pkgs[$idx])) return;
        $pp = $pkgs[$idx]; $rows = [];
        if (!empty($c['uzcard_enabled']) && $c['uzcard_merchant_id'] !== '' && $c['uzcard_api_key'] !== '') $rows[] = [btn("🟦 Uzcard", "dia:uz:$idx")];
        $rows[] = [btn("📇 Karta", "dia:manual:$idx")]; 
        if (!empty($c['gift_enabled'])) $rows[] = [btn("🎁 Gift orqali", "gift:menu:diamond:$idx")];
        $rows[] = [btn("◀️", "dia:buy")];
        if ($msg) edit($cid, $msg, "💳 <b>To'lov usuli</b>\n\n💎 " . $pp['diamonds'] . " · 💰 " . fmtn($pp['price']) . " so'm", ikb($rows)); return;
    }
    if ($act === 'manual' && isset($p[2])) {
        answer($q['id']); $pkgs = $c['diamond_packages'] ?? []; $idx = (int)$p[2]; if (!isset($pkgs[$idx])) return;
        if (empty($c['card_number'])) { if ($msg) edit($cid, $msg, "😔 Karta yo'q."); return; }
        $pp = $pkgs[$idx];
        $t = "📇 <b>Karta</b>\n\n💎 " . $pp['diamonds'] . " · 💰 " . fmtn($pp['price']) . " so'm\n📇 <code>" . h($c['card_number']) . "</code>\n";
        if ($c['card_holder'] !== '') $t .= "👤 " . h($c['card_holder']) . "\n";
        if ($msg) edit($cid, $msg, $t, ikb([[btn("✅ To'lov qildim", "dia:paid:$idx")],[btn("◀️", "dia:pk:$idx")]])); return;
    }
    if ($act === 'uz' && isset($p[2])) {
        answer($q['id']); $pkgs = $c['diamond_packages'] ?? []; $idx = (int)$p[2]; if (!isset($pkgs[$idx])) return;
        $pp = $pkgs[$idx];
        $all = J('payments'); $all = array_values(array_filter($all, function($x) use ($uid) { return !($x['uid'] == $uid && $x['status'] === 'pending' && ($x['kind'] ?? 'diamond') === 'diamond'); })); S('payments', $all);
        $req = pay_add(['uid' => $uid, 'name' => $u['name'], 'un' => $u['un'], 'kind' => 'diamond', 'pkg_idx' => $idx, 'diamonds' => $pp['diamonds'], 'price' => $pp['price'], 'balance' => 0, 'proof_type' => 'uzcard_auto', 'proof_data' => '', 'user_msg_id' => 0]);
        $host = $_SERVER['HTTP_HOST'] ?? ''; $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
        $base = $scheme . '://' . $host . ($_SERVER['SCRIPT_NAME'] ?? '/');
        $payload = ['merchant_id' => $c['uzcard_merchant_id'], 'amount' => (int)round($pp['price'] * 100), 'order_id' => $req['id'], 'callback_url' => $base . '?uzcard_cb=1', 'description' => "Mafiya olmos: " . $pp['diamonds']];
        $ch = curl_init(rtrim($c['uzcard_api_url'], '/') . '/create');
        curl_setopt_array($ch, [CURLOPT_POST => true, CURLOPT_POSTFIELDS => json_encode($payload), CURLOPT_HTTPHEADER => ['Content-Type: application/json', 'Authorization: Bearer ' . $c['uzcard_api_key']], CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 15]);
        $resp = curl_exec($ch); curl_close($ch); $rd = json_decode((string)$resp, true);
        $pu = $rd['payment_url'] ?? ($rd['data']['payment_url'] ?? '');
        if ($pu === '') {
            $all = J('payments'); $all = array_values(array_filter($all, function($x) use ($req) { return $x['id'] !== $req['id']; })); S('payments', $all);
            $t = "⚠️ Uzcard ishlamaydi.\n📇 <code>" . h($c['card_number']) . "</code>\n💎 " . $pp['diamonds'] . " · 💰 " . fmtn($pp['price']);
            if ($msg) edit($cid, $msg, $t, ikb([[btn("✅ To'lov qildim", "dia:paid:$idx")],[btn("◀️", "dia:buy")]])); return;
        }
        pay_update($req['id'], ['payment_url' => $pu]);
        if ($msg) edit($cid, $msg, "🟦 <b>Uzcard</b>\n\n💎 " . $pp['diamonds'] . "\n💰 " . fmtn($pp['price']) . "\n🧾 <code>" . $req['id'] . "</code>", ikb([[ubtn("💳 To'lash", $pu)],[btn("◀️", "dia:buy")]])); return;
    }
    if ($act === 'paid' && isset($p[2])) {
        answer($q['id']); $pkgs = $c['diamond_packages'] ?? []; $idx = (int)$p[2]; if (!isset($pkgs[$idx])) return;
        foreach (J('payments') as $r) if ($r['uid'] == $uid && $r['status'] === 'pending') { say($uid, "⏳ Kuting."); return; }
        setstep($uid, 'pay_proof', ['pkg' => $idx]); say($uid, "📸 <b>Chekni yuboring</b>\n\n/cancel"); return;
    }
    if ($act === 'roles') {
        answer($q['id']); $pD = $c['role_prices'] ?? []; $pT = $c['role_prices_t'] ?? [];
        $t = "🎭 <b>Rollar</b>\n\n💎 " . fmtn($u['diamonds'] ?? 0) . " · 💰 " . fmtn($u['coins']) . "\n\n";
        $rows = []; $any = false;
        foreach (ROLES() as $rk => $rv) {
            if ($rk === 'civil') continue;
            $pd = isset($pD[$rk]) ? (int)$pD[$rk] : 0; $pt = isset($pT[$rk]) ? (int)$pT[$rk] : 0;
            if ($pd <= 0 && $pt <= 0) continue;
            $any = true; $owned = in_array($rk, $u['owned_roles'] ?? [], true);
            if ($owned) $rows[] = [btn("✅ " . role_name($rk) . " (egasiz)", "dia:noop")];
            elseif ($pd > 0 && $pt > 0) $rows[] = [btn("💎 " . role_name($rk) . " · $pd", "dia:br:$rk:d"), btn("🪙 " . fmtn($pt), "dia:br:$rk:t")];
            elseif ($pd > 0) $rows[] = [btn("💎 " . role_name($rk) . " — $pd olmos", "dia:br:$rk:d")];
            else $rows[] = [btn("🪙 " . role_name($rk) . " — " . fmtn($pt) . " tanga", "dia:br:$rk:t")];
        }
        if (!$any) $rows[] = [btn("— sotuvda emas —", "dia:noop")];
        $rows[] = [btn("◀️", "dia:menu")];
        if ($msg) edit($cid, $msg, $t, ikb($rows)); else say($cid, $t, ikb($rows)); return;
    }
    if ($act === 'br' && isset($p[2])) {
        $rk = $p[2]; $cur = $p[3] ?? 'd'; $pD = $c['role_prices'] ?? []; $pT = $c['role_prices_t'] ?? [];
        if (!isset(ROLES()[$rk]) || $rk === 'civil') { answer($q['id'], "Xato.", true); return; }
        if (in_array($rk, $u['owned_roles'] ?? [], true)) { answer($q['id'], "Sizda bor.", true); return; }
        if ($cur === 't') {
            $price = isset($pT[$rk]) ? (int)$pT[$rk] : 0;
            if ($price <= 0) { answer($q['id'], "Tanga emas.", true); return; }
            if ($u['coins'] < $price) { answer($q['id'], "Tanga yetarli emas.", true); return; }
            $a = J('users'); $a[$uid]['coins'] -= $price; $a[$uid]['owned_roles'][] = $rk; S('users', $a);
            answer($q['id'], "✅ -" . fmtn($price) . " tanga");
            say($uid, "✅ <b>" . role_name($rk) . "</b> sotib olindi!\n💰 -" . fmtn($price) . " tanga");
        } else {
            $price = isset($pD[$rk]) ? (int)$pD[$rk] : 0;
            if ($price <= 0) { answer($q['id'], "Olmos emas.", true); return; }
            if (!usediamonds($uid, $price)) { answer($q['id'], "Olmos yetarli emas.", true); return; }
            $a = J('users'); $a[$uid]['owned_roles'][] = $rk; S('users', $a);
            answer($q['id'], "✅ -$price 💎");
            say($uid, "✅ <b>" . role_name($rk) . "</b> sotib olindi!\n💎 -$price");
        }
        $u2 = uget($uid); $pD2 = $c['role_prices'] ?? []; $pT2 = $c['role_prices_t'] ?? [];
        $t2 = "🎭 <b>Rollar</b>\n\n💎 " . fmtn($u2['diamonds'] ?? 0) . " · 💰 " . fmtn($u2['coins']) . "\n\n";
        $rows2 = []; $any2 = false;
        foreach (ROLES() as $rk2 => $rv2) {
            if ($rk2 === 'civil') continue;
            $pd = isset($pD2[$rk2]) ? (int)$pD2[$rk2] : 0; $pt = isset($pT2[$rk2]) ? (int)$pT2[$rk2] : 0;
            if ($pd <= 0 && $pt <= 0) continue;
            $any2 = true; $owned2 = in_array($rk2, $u2['owned_roles'] ?? [], true);
            if ($owned2) $rows2[] = [btn("✅ " . role_name($rk2) . " (egasiz)", "dia:noop")];
            elseif ($pd > 0 && $pt > 0) $rows2[] = [btn("💎 " . role_name($rk2) . " · $pd", "dia:br:$rk2:d"), btn("🪙 " . fmtn($pt), "dia:br:$rk2:t")];
            elseif ($pd > 0) $rows2[] = [btn("💎 " . role_name($rk2) . " — $pd olmos", "dia:br:$rk2:d")];
            else $rows2[] = [btn("🪙 " . role_name($rk2) . " — " . fmtn($pt) . " tanga", "dia:br:$rk2:t")];
        }
        if (!$any2) $rows2[] = [btn("— sotuvda emas —", "dia:noop")];
        $rows2[] = [btn("◀️", "dia:menu")];
        if ($msg) edit($cid, $msg, $t2, ikb($rows2)); return;
    }
    if ($act === 'my') {
        answer($q['id']); $owned = $u['owned_roles'] ?? [];
        if (!$owned) { if ($msg) edit($cid, $msg, "Sizda rollar yo'q.", ikb([[btn("🎭", "dia:roles")],[btn("◀️", "dia:menu")]])); return; }
        $ar = (string)($u['active_role'] ?? '');
        $t = "🎒 <b>Mening rollarim</b>\n\nFaol: <b>" . ($ar !== '' ? role_name($ar) : "yo'q") . "</b>\n\n";
        $rows = []; foreach ($owned as $rk) { $ac = $ar === $rk; $rows[] = [btn(($ac ? "✅ " : "") . role_name($rk), "dia:act:$rk")]; }
        if ($ar !== '') $rows[] = [btn("🚫 Bekor", "dia:act:")];
        $rows[] = [btn("◀️", "dia:menu")];
        if ($msg) edit($cid, $msg, $t, ikb($rows)); return;
    }
    if ($act === 'act') {
        $rk = $p[2] ?? ''; $a = J('users');
        if ($rk === '') { $a[$uid]['active_role'] = ''; S('users', $a); answer($q['id'], "Bekor"); }
        elseif (in_array($rk, $u['owned_roles'] ?? [], true)) { $a[$uid]['active_role'] = $rk; S('users', $a); answer($q['id'], "✅"); }
        else { answer($q['id'], "Yo'q", true); return; }
        $u3 = uget($uid); $owned3 = $u3['owned_roles'] ?? []; $ar3 = (string)($u3['active_role'] ?? '');
        $t3 = "🎒 <b>Mening rollarim</b>\n\nFaol: <b>" . ($ar3 !== '' ? role_name($ar3) : "yo'q") . "</b>\n\n";
        $rows3 = []; foreach ($owned3 as $rk3) { $ac3 = $ar3 === $rk3; $rows3[] = [btn(($ac3 ? "✅ " : "") . role_name($rk3), "dia:act:$rk3")]; }
        if ($ar3 !== '') $rows3[] = [btn("🚫 Bekor", "dia:act:")];
        $rows3[] = [btn("◀️", "dia:menu")];
        if ($msg) edit($cid, $msg, $t3, ikb($rows3)); return;
    }
    if ($act === 'hist') {
        answer($q['id']); $rows = []; foreach (J('payments') as $r) if ($r['uid'] == $uid && ($r['kind'] ?? 'diamond') === 'diamond') $rows[] = $r;
        usort($rows, function($a, $b){ return $b['created'] - $a['created']; }); $rows = array_slice($rows, 0, 10);
        if (!$rows) { if ($msg) edit($cid, $msg, "Bo'sh.", ikb([[btn("💳", "dia:buy")],[btn("◀️", "dia:menu")]])); return; }
        $st = ['pending'=>"⏳",'confirmed'=>"✅",'rejected'=>"❌"];
        $t = "🧾 <b>To'lovlarim</b>\n\n";
        foreach ($rows as $r) $t .= "• <code>" . $r['id'] . "</code> — 💎" . $r['diamonds'] . " · " . fmtn($r['price']) . " · " . ($st[$r['status']] ?? $r['status']) . "\n";
        if ($msg) edit($cid, $msg, $t, ikb([[btn("◀️", "dia:menu")]])); return;
    }
    if ($act === 'noop') { answer($q['id']); return; }
    answer($q['id']);
}

// ---------- shaxsiy chat ----------
function handle_private($m) {
    $from = $m['from']; $uid = $from['id']; $text = trim((string)($m['text'] ?? ''));
    $ref = 0; $arg = '';
    if (preg_match('/^\/start(?:\s+(\S+))?/', $text, $mm)) { $arg = $mm[1] ?? ''; if (strpos($arg, 'ref') === 0) $ref = (int)substr($arg, 3); }
    $u = uensure($from, $ref);
    if (!empty($u['banned']) && !isAdmin($uid)) { say($uid, "🚫 Cheklangansiz."); return; }
    $step = (string)$u['step']; $tmp = is_array($u['tmp']) ? $u['tmp'] : [];
    if (strpos($text, '/start') === 0) { setstep($uid, '', []); if (!sub_gate($uid)) { uset($uid, ['tmp' => ['pend' => $arg]]); return; } start_flow($uid, $arg); return; }
    if ($text === B_PROFILE) { show_profile($uid); return; }
    if ($text === B_TOP) { say($uid, top_text('rating'), top_kb()); return; }
    if ($text === B_SHOP) { say($uid, shop_text($uid), shop_kb()); return; }
    if ($text === B_ACH) { show_ach($uid); return; }
    if ($text === B_DAILY) { daily_bonus($uid); return; }
    if ($text === B_DIAMONDS) { diamond_menu($uid); return; }
    if ($text === B_BALANCE) { balance_menu($uid); return; }
    if ($text === B_OLTIN) { oltin_menu($uid); return; }
    if ($text === B_TRANSFER) { transfer_menu($uid); return; }
    if ($text === B_SUPPORT) { show_support($uid); return; }
    if ($text === B_REF) { show_ref($uid); return; }
    if ($text === B_RULES) { show_rules($uid); return; }
    if (($text === B_ADMIN || $text === '/admin') && admin_level($uid) !== null) { admin_home($uid); return; }
    if ($text === '/cancel') { setstep($uid, '', []); say($uid, "Bekor qilindi.", main_kb($uid)); return; }
    
    // To'lov cheki
    if ($step === 'pay_proof' || $step === 'bal_proof' || $step === 'gift_proof') {
        $isBal = $step === 'bal_proof';
        $isGift = $step === 'gift_proof';
        
        $idx = (int)($tmp['pkg'] ?? $tmp['idx'] ?? -1);
        $c = cfg();
        
        $pt = 'text'; $pd = '';
        if (isset($m['photo'])) { $pt = 'photo'; $pd = end($m['photo'])['file_id']; }
        elseif (isset($m['document'])) { $pt = 'document'; $pd = $m['document']['file_id']; }
        elseif (isset($m['text']) && $m['text'] !== '') { $pt = 'text'; $pd = mb_substr($m['text'], 0, 500); }
        else { say($uid, "Faqat matn/rasm."); return; }

        if ($isGift) {
            $gIdx = (int)($tmp['gift_idx'] ?? -1);
            $gift = $c['gift_packages'][$gIdx] ?? null;
            if (!$gift) { say($uid, "Sovg'a topilmadi."); setstep($uid, '', []); return; }
            
            $kind = $tmp['type'] === 'diamond' ? 'diamond' : 'balance';
            $amount = $kind === 'diamond' ? $gift['diamonds'] : $gift['balance'];
            $price = $gift['stars'];
            
            $req = pay_add(['uid' => $uid, 'name' => $u['name'], 'un' => $u['un'], 'kind' => $kind, 'balance' => $amount, 'diamonds' => $amount, 'price' => $price, 'gift_name' => $gift['name'], 'gift_stars' => $price, 'proof_type' => $pt, 'proof_data' => $pd, 'user_msg_id' => $m['message_id']]);
            
            setstep($uid, '', []);
            say($uid, "✅ <b>Gift to'lov so'rovi qabul qilindi!</b>\n🧾 <code>" . $req['id'] . "</code>\nAdmin tasdiqlashini kuting.");
            
            $nt = "🎁 <b>GIFT TO'LOV</b>\n🆔 <code>{$req['id']}</code>\n👤 " . h($u['name']) . "\n🎁 Sovg'a: " . $gift['name'] . " (" . $price . " yulduz)\n👉 Olinadi: " . ($kind === 'diamond' ? "💎 " . $amount . " olmos" : "💵 " . fmtn($amount) . " so'm");
            foreach (admin_ids() as $aid) say($aid, $nt . "\n🆔 <code>$uid</code>", ikb([[btn("✅", "ad:pac:{$req['id']}"), btn("❌", "ad:par:{$req['id']}")]]));
            return;
        }

        $pkgs = $isBal ? ($c['balance_packages'] ?? []) : ($c['diamond_packages'] ?? []);
        if (!isset($pkgs[$idx])) { say($uid, "Paket yo'q."); setstep($uid, '', []); return; }
        foreach (J('payments') as $r) if ($r['uid'] == $uid && $r['status'] === 'pending') { say($uid, "⏳ Kuting."); setstep($uid, '', []); return; }
        $pp = $pkgs[$idx];
        
        if ($isBal) {
            $req = pay_add(['uid' => $uid, 'name' => $u['name'], 'un' => $u['un'], 'kind' => 'balance', 'balance' => $pp['amount'], 'price' => $pp['amount'], 'diamonds' => 0, 'pkg_idx' => $idx, 'proof_type' => $pt, 'proof_data' => $pd, 'user_msg_id' => $m['message_id']]);
            $nt = "💰 <b>BALANS TO'LDIRISH</b>\n🆔 <code>{$req['id']}</code>\n👤 " . h($u['name']) . "\n💵 +" . fmtn($pp['amount']) . " so'm";
        } else {
            $req = pay_add(['uid' => $uid, 'name' => $u['name'], 'un' => $u['un'], 'kind' => 'diamond', 'pkg_idx' => $idx, 'diamonds' => $pp['diamonds'], 'price' => $pp['price'], 'balance' => 0, 'proof_type' => $pt, 'proof_data' => $pd, 'user_msg_id' => $m['message_id']]);
            $nt = "💳 <b>OLMOS TO'LOV</b>\n🆔 <code>{$req['id']}</code>\n👤 " . h($u['name']) . "\n💎 " . $pp['diamonds'] . " · " . fmtn($pp['price']);
        }
        setstep($uid, '', []);
        say($uid, "✅ <b>Qabul!</b>\n🧾 <code>{$req['id']}</code>\n\nAdmin tasdiqlashini kuting.");
        foreach (admin_ids() as $aid) say($aid, $nt . "\n🆔 <code>$uid</code>", ikb([[btn("✅", "ad:pac:{$req['id']}"), btn("❌", "ad:par:{$req['id']}")]]));
        return;
    }
    // Balans xarid
    if ($step === 'bal_buy') {
        $cur = $tmp['cur'] ?? 'tanga'; $c = cfg();
        $rate = $cur === 'tanga' ? (int)$c['balance_rate_tanga'] : (int)$c['balance_rate_olmos'];
        if (!preg_match('/^\d+$/', $text) || (int)$text < 1) { say($uid, "❌ Raqam."); return; }
        $qty = (int)$text; $total = $qty * $rate;
        if (($u['balance'] ?? 0) < $total) { say($uid, "❌ Balans yetarli emas.\nKerak: " . fmtn($total) . "\nSizda: " . fmtn($u['balance'] ?? 0)); return; }
        if (!useBalance($uid, $total)) { say($uid, "❌ Xato."); return; }
        if ($cur === 'tanga') addcoins($uid, $qty); else adddiamonds($uid, $qty);
        setstep($uid, '', []); $sym = $cur === 'tanga' ? "🪙" : "💎";
        say($uid, "✅ <b>$qty $cur!</b>\n-$sym " . fmtn($total) . " so'm", main_kb($uid));
        return;
    }
    // Oltin xarid
    if ($step === 'olt_buy') {
        $cur = $tmp['cur'] ?? 't'; $c = cfg();
        $price = $cur === 't' ? (int)$c['oltin_buy_t'] : (int)$c['oltin_buy_o'];
        if (!preg_match('/^\d+$/', $text) || (int)$text < 1) { say($uid, "❌ Raqam."); return; }
        $qty = (int)$text; $total = $qty * $price;
        if ($cur === 't') { if ($u['coins'] < $total) { say($uid, "❌ Tanga yetarli emas."); return; } addcoins($uid, -$total); }
        else { if ((int)($u['diamonds'] ?? 0) < $total) { say($uid, "❌ Olmos yetarli emas."); return; } adddiamonds($uid, -$total); }
        addoltin($uid, $qty); oltin_hist_add($uid, 'buy', $cur, $qty, $total);
        setstep($uid, '', []); $sym = $cur === 't' ? "🪙" : "💎";
        say($uid, "✅ <b>$qty oltin sotib olindi!</b>\n💸 " . fmtn($total) . " $sym\n🥇 Balans: " . fmtn((uget($uid)['oltin'] ?? 0)), main_kb($uid));
        return;
    }
    // Oltin sotish
    if ($step === 'olt_sell') {
        $cur = $tmp['cur'] ?? 't'; $c = cfg();
        $price = $cur === 't' ? (int)$c['oltin_sell_t'] : (int)$c['oltin_sell_o'];
        if (!preg_match('/^\d+$/', $text) || (int)$text < 1) { say($uid, "❌ Raqam."); return; }
        $qty = (int)$text;
        if (($u['oltin'] ?? 0) < $qty) { say($uid, "❌ Sizda " . fmtn($u['oltin'] ?? 0) . " oltin."); return; }
        $total = $qty * $price;
        if (!useOltin($uid, $qty)) { say($uid, "❌ Xato."); return; }
        if ($cur === 't') addcoins($uid, $total); else adddiamonds($uid, $total);
        oltin_hist_add($uid, 'sell', $cur, $qty, $total);
        setstep($uid, '', []); $sym = $cur === 't' ? "🪙" : "💎";
        say($uid, "✅ <b>$qty oltin sotildi!</b>\n💰 +" . fmtn($total) . " $sym\n🥇 Balans: " . fmtn((uget($uid)['oltin'] ?? 0)), main_kb($uid));
        return;
    }
    // 💸 O'tkazma: qabul qiluvchini tanlash
    if ($step === 'tr_to') {
        $cur = $tmp['cur'] ?? 'tanga';
        $targetId = 0;
        $t = trim($text);
        if (preg_match('/^\d+$/', $t)) {
            if (!uget($t)) { say($uid, "❌ Bu ID topilmadi. Qayta yozing:"); return; }
            $targetId = (int)$t;
        } elseif (preg_match('/^@([A-Za-z0-9_]{4,})$/', $t, $mm)) {
            $un = strtolower($mm[1]);
            foreach (J('users') as $id => $us) if (strtolower((string)($us['un'] ?? '')) === $un) { $targetId = (int)$id; break; }
            if (!$targetId) { say($uid, "❌ Bu username topilmadi. Qayta yozing:"); return; }
        } else { say($uid, "❌ ID yoki @username yozing:"); return; }
        if ($targetId == $uid) { say($uid, "❌ O'zingizga o'tkazolmaysiz."); return; }
        setstep($uid, 'tr_amt', ['cur' => $cur, 'to' => $targetId]);
        $bal = cur_balance($uid, $cur);
        say($uid, "💸 <b>Miqdorni kiriting</b>\n\n" . cur_name($cur) . "\n💼 Sizda: <b>" . fmtn($bal) . "</b>\n👤 Qabul: <b>" . h(uget($targetId)['name']) . "</b>\n\nRaqam yozing:\n\n/cancel");
        return;
    }
    // 💸 O'tkazma: miqdorni kiritish
    if ($step === 'tr_amt') {
        $cur = $tmp['cur'] ?? 'tanga'; $to = (int)($tmp['to'] ?? 0);
        if (!preg_match('/^\d+$/', $text) || (int)$text < 1) { say($uid, "❌ Musbat raqam yozing."); return; }
        $amt = (int)$text; $c = cfg();
        $fee = (int)round($amt * (int)$c['tr_fee_pct'] / 100);
        $net = $amt - $fee;
        setstep($uid, 'tr_conf', ['cur' => $cur, 'to' => $to, 'amt' => $amt, 'fee' => $fee, 'net' => $net]);
        $t = "💸 <b>Tasdiqlash</b>\n\n";
        $t .= "👤 Kimga: <b>" . h(uget($to)['name']) . "</b>\n";
        $t .= "💵 Miqdor: <b>" . fmtn($amt) . "</b> " . $cur . "\n";
        $t .= "📊 Komissiya: <b>" . fmtn($fee) . "</b> (" . $c['tr_fee_pct'] . "%)\n";
        $t .= "✅ Qabul qiluvchi oladi: <b>" . fmtn($net) . "</b>\n\n";
        $t .= "<code>TASDIQ</code> deb yozing yoki /cancel";
        say($uid, $t);
        return;
    }
    // 💸 O'tkazma: tasdiqlash
    if ($step === 'tr_conf') {
        if (strtoupper(trim($text)) !== 'TASDIQ') { say($uid, "❌ Bekor qilindi.", main_kb($uid)); setstep($uid, '', []); return; }
        $cur = $tmp['cur'] ?? 'tanga'; $to = (int)($tmp['to'] ?? 0); $amt = (int)($tmp['amt'] ?? 0);
        setstep($uid, '', []);
        list($ok, $res) = tr_do($uid, $to, $cur, $amt);
        if (!$ok) { say($uid, "❌ " . $res, main_kb($uid)); return; }
        say($uid, "✅ <b>O'tkazma bajarildi!</b>\n\n👤 " . h($res['to_name']) . "\n💸 " . fmtn($amt) . " " . $cur . "\n📊 Komissiya: " . fmtn($res['fee']) . "\n✅ Yuborildi: " . fmtn($res['net']), main_kb($uid));
        say($to, "📥 <b>Pul keldi!</b>\n\n👤 " . h(uget($uid)['name']) . " dan\n💵 +" . fmtn($res['net']) . " " . $cur . "\n📊 (komissiya: " . fmtn($res['fee']) . ")");
        return;
    }
    if (strpos($step, 'adm_') === 0 && admin_level($uid) !== null) { admin_step($uid, $step, $tmp, $m); return; }
    $g = find_game_of($uid);
    if ($g && $g['status'] !== 'reg' && $text !== '') {
        $me = $g['players'][$uid];
        if (!empty($me['alive']) && is_mafia_role($me['role'])) { foreach ($g['players'] as $id => $p) if ($id != $uid && !empty($p['alive']) && is_mafia_role($p['role'])) say($id, "🤵🏻 <b>" . h($me['name']) . "</b>: " . h($text)); return; }
    }
    say($uid, "Menyudan foydalaning 👇", main_kb($uid));
}

// ---------- guruh ----------
function group_reg($m) { $chat = $m['chat']['id']; $gr = J('groups'); if (!isset($gr[$chat])) $gr[$chat] = ['title' => $m['chat']['title'] ?? (string)$chat, 'games' => 0, 'banned' => 0, 'added' => time()]; else $gr[$chat]['title'] = $m['chat']['title'] ?? $gr[$chat]['title']; S('groups', $gr); return $gr[$chat]; }
function is_group_admin($chat, $uid) { $r = tg('getChatMember', ['chat_id' => $chat, 'user_id' => $uid]); return !empty($r['ok']) && in_array($r['result']['status'], ['creator', 'administrator'], true); }
function handle_group($m) {
    $chat = $m['chat']['id']; $from = $m['from']; $uid = $from['id']; $text = trim((string)($m['text'] ?? ''));
    $cmd = '';
    if (preg_match('/^\/(\w+)(?:@(\w+))?/', $text, $mm)) { $cmd = strtolower($mm[1]); if (!empty($mm[2]) && strcasecmp($mm[2], bot_username()) !== 0) return; }
    $c = cfg(); $g = gget($chat);
    if ($g && $cmd === '' && $c['strict_chat'] && $g['status'] !== 'reg') { $inplay = isset($g['players'][$uid]) && !empty($g['players'][$uid]['alive']); if ($g['status'] === 'night' || !$inplay) tg('deleteMessage', ['chat_id' => $chat, 'message_id' => $m['message_id']]); return; }
    if ($cmd === '') return;
    $gr = group_reg($m);
    
    if ($cmd === 'start') { say($chat, "🎭 <b>Mafiya o'yini</b>\n\nO'yinni boshlash uchun /game yozing.\nRollar ro'yxati: /roles"); return; }
    if ($cmd === 'roles') {
        $t = "🎭 <b>O'yin rollari:</b>\n\n";
        $t .= "🏙 <b>Tinch aholi:</b> 👨‍🌾, 🕵️, 👮, 👨🏻‍⚕️, 💃, 🤞, 🛡, 👁, 💰, 💣, ⛏, 🚶\n";
        $t .= "🤵🏻 <b>Mafiya:</b> 🤵🏻, 🎩, 👨🏼‍💼, 🕶, 🥷, 👸\n";
        $t .= "🎭 <b>Yolg'iz:</b> 🔪, 🤹🏻\n\n";
        $t .= "Batafsil ma'lumot uchun botga shaxsiy yozib <b>📖 Qo'llanma</b> tugmasini bosing.";
        say($chat, $t); return;
    }
    if ($cmd === 'lang') { say($chat, "🌐 Bot faqat O'zbek tilida ishlaydi."); return; }
    if ($cmd === 'sozlamalar' || $cmd === 'settings') { if (admin_level($uid) !== null) admin_home($uid); else say($chat, "⛔️ Sozlamalar faqat adminlar uchun."); return; }

    if ($cmd === 'game') {
        if (!empty($gr['banned'])) { say($chat, "⛔️ Cheklangan."); return; }
        $u = uensure($from); if (!empty($u['banned'])) return;
        if ($g) { say($chat, "⚠️ Bu guruhda o'yin allaqachon boshlangan! Yangi o'yin boshlash uchun avval /stop qiling."); return; }
        $g = ['chat' => $chat, 'title' => $gr['title'], 'status' => 'reg', 'starter' => $uid, 'created' => time(), 'reg_end' => time() + $c['reg_time'], 'players' => [], 'day' => 0, 'warned' => 0, 'ext' => 0, 'reg_msg' => 0, 'votes' => [], 'night' => []];
        $r = say($chat, reg_text($g), reg_kb($g));
        $g['reg_msg'] = $r['result']['message_id'] ?? 0;
        if (!$g['reg_msg']) return;
        gput($g); return;
    }
    
    if ($cmd === 'give') {
        $c = cfg();
        if (empty($c['tr_enabled'])) { say($chat, "💸 O'tkazmalar o'chirilgan."); return; }
        $u1 = uget($uid); if (!$u1) { say($chat, "Avval botga /start yozing."); return; }
        if (isset($m['reply_to_message']['from']['id'])) {
            $to = (int)$m['reply_to_message']['from']['id'];
            if ($to == $uid) { say($chat, "❌ O'zingizga emas."); return; }
            if (!uget($to)) { say($chat, "❌ Qabul qiluvchi botga /start yozmagan."); return; }
            $args = preg_split('/\s+/', trim($text));
            $amt = isset($args[1]) ? (int)$args[1] : 0;
            $curMap = ['tanga'=>'tanga','t'=>'tanga','coin'=>'tanga','olmos'=>'olmos','o'=>'olmos','d'=>'olmos','ol'=>'olmos','oltin'=>'oltin','g'=>'oltin','gold'=>'oltin','balans'=>'balans','b'=>'balans','pul'=>'balans','sum'=>'balans'];
            $curRaw = isset($args[2]) ? mb_strtolower($args[2]) : '';
            $cur = $curMap[$curRaw] ?? 'tanga';
            if ($amt < 1) { say($chat, "💸 Format: <code>/give 100 tanga</code> (reply qilib)\nYoki: <code>/give 100 oltin</code> · <code>/give 100000 balans</code>"); return; }
            list($ok, $res) = tr_do($uid, $to, $cur, $amt);
            if (!$ok) { say($chat, "❌ " . $res); return; }
            say($chat, "✅ " . nm($u1) . " → " . nm(uget($to)) . "\n💸 " . fmtn($amt) . " " . cur_name($cur) . "\n📊 Komissiya: " . fmtn($res['fee']) . "\n✅ Yetib bordi: " . fmtn($res['net']));
            say($to, "📥 <b>Pul keldi!</b>\n👤 " . h($u1['name']) . " dan\n💵 +" . fmtn($res['net']) . " " . cur_name($cur));
            return;
        }
        $args = preg_split('/\s+/', trim($text));
        if (isset($args[1]) && strpos($args[1], '@') === 0) {
            $un = strtolower(substr($args[1], 1));
            $to = 0; foreach (J('users') as $id => $us) if (strtolower((string)($us['un'] ?? '')) === $un) { $to = (int)$id; break; }
            if (!$to) { say($chat, "❌ @" . h($un) . " topilmadi."); return; }
            if ($to == $uid) { say($chat, "❌ O'zingizga emas."); return; }
            $amt = isset($args[2]) ? (int)$args[2] : 0;
            $curMap = ['tanga'=>'tanga','olmos'=>'olmos','oltin'=>'oltin','balans'=>'balans','pul'=>'balans'];
            $curRaw = isset($args[3]) ? mb_strtolower($args[3]) : 'tanga';
            $cur = $curMap[$curRaw] ?? 'tanga';
            if ($amt < 1) { say($chat, "💸 Format: <code>/give @user 100 tanga</code>"); return; }
            list($ok, $res) = tr_do($uid, $to, $cur, $amt);
            if (!$ok) { say($chat, "❌ " . $res); return; }
            say($chat, "✅ " . nm($u1) . " → " . nm(uget($to)) . "\n💸 " . fmtn($amt) . " " . cur_name($cur) . "\n📊 Komissiya: " . fmtn($res['fee']));
            say($to, "📥 <b>Pul keldi!</b>\n👤 " . h($u1['name']) . " dan\n💵 +" . fmtn($res['net']) . " " . cur_name($cur));
            return;
        }
        say($chat, "💸 <b>Pul o'tkazish</b>\n\n<b>Reply usuli:</b>\nKimga o'tkazmoqchi bo'lsangiz — uning xabariga reply qilib:\n<code>/give 100 tanga</code>\n<code>/give 5 olmos</code>\n<code>/give 10 oltin</code>\n<code>/give 50000 balans</code>\n\n<b>Username usuli:</b>\n<code>/give @user 100 tanga</code>\n\nYoki shaxsiy chatda <b>💸 O'tkazma</b> tugmasi.");
        return;
    }
    
    if (!$g) return;
    if ($cmd === 'begin') { if ($g['status'] !== 'reg') return; if ($uid != $g['starter'] && admin_level($uid) === null && !is_group_admin($chat, $uid)) { say($chat, "Ruxsat yo'q."); return; } begin_game($chat); return; }
    if ($cmd === 'extend') { if ($g['status'] !== 'reg') return; if ($g['ext'] >= 3) { say($chat, "3 marta."); return; } $g['reg_end'] += 30; $g['ext']++; $g['warned'] = 0; gput($g); reg_refresh($g); say($chat, "⏳ +30"); return; }
    if ($cmd === 'leave') { if ($g['status'] !== 'reg' || !isset($g['players'][$uid])) return; unset($g['players'][$uid]); gput($g); reg_refresh($g); say($chat, "👋 " . h($from['first_name'])); return; }
    if ($cmd === 'stop') { if ($uid != $g['starter'] && admin_level($uid) === null && !is_group_admin($chat, $uid)) { say($chat, "Ruxsat yo'q."); return; } gdel($chat); say($chat, "⏹ O'yin to'xtatildi."); return; }
}
function handle_member($mc) {
    $chat = $mc['chat']['id']; $type = $mc['chat']['type'] ?? '';
    if ($type !== 'group' && $type !== 'supergroup') return;
    $st = $mc['new_chat_member']['status']; $gr = J('groups');
    if (in_array($st, ['member', 'administrator'], true)) {
        if (!isset($gr[$chat])) $gr[$chat] = ['title' => $mc['chat']['title'], 'games' => 0, 'banned' => 0, 'added' => time()];
        $gr[$chat]['left'] = 0; S('groups', $gr);
        if (!empty($gr[$chat]['banned'])) { tg('leaveChat', ['chat_id' => $chat]); return; }
        say($chat, "🎭 <b>Mafiya</b>\n\n/game\n\n⚠️ Botni <b>admin</b> qiling (xabarlarni o'chirish).");
    } elseif (isset($gr[$chat])) { $gr[$chat]['left'] = 1; S('groups', $gr); }
}

// ---------- callback ----------
function handle_cb($q) {
    $uid = $q['from']['id']; $data = (string)($q['data'] ?? ''); $p = explode(':', $data);
    $u = uget($uid);
    if ($u && !empty($u['banned']) && !isAdmin($uid)) { answer($q['id'], "Cheklangansiz.", true); return; }
    $k = $p[0];
    if ($k === 'na') { cb_night($uid, $q, $p); return; }
    if ($k === 'vt') { cb_vote($uid, $q, $p); return; }
    if ($k === 'dia') { cb_diamond($uid, $q, $p); return; }
    if ($k === 'bal') { cb_balance($uid, $q, $p); return; }
    if ($k === 'olt') { cb_oltin($uid, $q, $p); return; }
    if ($k === 'tr') { cb_transfer($uid, $q, $p); return; }
    if ($k === 'sup') { cb_support($uid, $q, $p); return; }
    if ($k === 'gift') { cb_gift($uid, $q, $p); return; }
    if ($k === 'qm') { cb_qm($uid, $q, $p); return; }
    if ($k === 'gd') { cb_geroy($uid, $q, $p); return; }
    $msg = $q['message']['message_id'] ?? 0; $cid = $q['message']['chat']['id'] ?? $uid;
    if ($k === 'rt' && $u) { answer($q['id']); edit($cid, $msg, top_text($p[1]), top_kb()); return; }
    if ($k === 'buy' && $u) {
        $items = shop_items(); $c = cfg(); $key = $p[1] ?? ''; $cur = $p[2] ?? 't';
        if (!isset($items[$key])) { answer($q['id']); return; }
        if ($cur === 'd') {
            $price = (int)($c['pd_' . $key] ?? 0);
            if ($price <= 0) { answer($q['id'], "Olmos emas.", true); return; }
            if ((int)($u['diamonds'] ?? 0) < $price) { answer($q['id'], "💎 Yetarli emas.", true); return; }
            $us = J('users'); $us[$uid]['diamonds'] -= $price; $us[$uid]['items'][$key]++; S('users', $us);
            answer($q['id'], "✅ -$price 💎");
        } else {
            $price = (int)($c['p_' . $key] ?? 0);
            if ($u['coins'] < $price) { answer($q['id'], "Tanga yetarli emas.", true); return; }
            $us = J('users'); $us[$uid]['coins'] -= $price; $us[$uid]['items'][$key]++; S('users', $us);
            answer($q['id'], "✅ -" . fmtn($price) . " tanga");
        }
        edit($cid, $msg, shop_text($uid), shop_kb()); return;
    }
    if ($k === 'chk') { if (!sub_gate($uid)) { answer($q['id'], "Obuna emas.", true); return; } answer($q['id'], "✅"); $arg = ($u && isset($u['tmp']['pend'])) ? $u['tmp']['pend'] : ''; setstep($uid, '', []); start_flow($uid, $arg); return; }
    if ($k === 'ad' && admin_level($uid) !== null) { admin_cb($uid, $q, $p); return; }
    answer($q['id']);
}

// ======================= ADMIN =======================
function NUMS() {
    return [
        'reg_time' => ["Ro'yxat", 15, 600], 'night_time' => ["Tun", 15, 300], 'day_time' => ["Kun", 15, 600], 'vote_time' => ["Ovoz", 15, 300],
        'min_players' => ["Min", 3, 30], 'max_players' => ["Maks", 4, 50],
        'win_coins' => ["G'alaba tanga", 0, 1000000], 'lose_coins' => ["Ishtirok", 0, 1000000], 'survive_bonus' => ["Omon", 0, 1000000],
        'daily_bonus' => ["Kunlik", 0, 1000000], 'ref_bonus' => ["Referal", 0, 1000000],
        'p_doc' => ["📁", 0, 10000000], 'p_shield' => ["🛡", 0, 10000000], 'p_rebirth' => ["⚖️", 0, 10000000],
        'p_qotil_himoya' => ["⛑️", 0, 10000000], 'p_miltiq' => ["🔫", 0, 10000000], 'p_dori_himoya' => ["💊", 0, 10000000],
        'p_maska' => ["🎭", 0, 10000000], 'p_geroy' => ["🥷", 0, 10000000], 'p_sirpanish' => ["🪤", 0, 10000000],
        'p_geroy_himoya' => ["🔰", 0, 10000000], 'p_qora_materiya' => ["🌑", 0, 10000000],
        'pd_doc' => ["💎📁", 0, 100000], 'pd_shield' => ["💎🛡", 0, 100000], 'pd_rebirth' => ["💎⚖️", 0, 100000],
        'pd_qotil_himoya' => ["💎⛑️", 0, 100000], 'pd_miltiq' => ["💎🔫", 0, 100000], 'pd_dori_himoya' => ["💎💊", 0, 100000],
        'pd_maska' => ["💎🎭", 0, 100000], 'pd_geroy' => ["💎🥷", 0, 100000], 'pd_sirpanish' => ["💎🪤", 0, 100000],
        'pd_geroy_himoya' => ["💎🔰", 0, 100000], 'pd_qora_materiya' => ["💎🌑", 0, 100000],
        'rt_win' => ["+", 0, 1000], 'rt_lose' => ["-", 0, 1000],
    ];
}
function shop_keys() { return ['p_doc','p_shield','p_rebirth','p_qotil_himoya','p_miltiq','p_dori_himoya','p_maska','p_geroy','p_sirpanish','p_geroy_himoya','p_qora_materiya']; }
function shopd_keys() { return ['pd_doc','pd_shield','pd_rebirth','pd_qotil_himoya','pd_miltiq','pd_dori_himoya','pd_maska','pd_geroy','pd_sirpanish','pd_geroy_himoya','pd_qora_materiya']; }
function eco_keys() { return ['win_coins', 'lose_coins', 'survive_bonus', 'daily_bonus', 'ref_bonus', 'rt_win', 'rt_lose']; }
function admin_kb() {
    $pc = pay_pending_count();
    return ikb([
        [btn("📊 Statistika", "ad:stat"), btn("🏆 TOP guruhlar", "ad:topgr")],
        [btn("🎮 Faol o'yinlar", "ad:ag"), btn("⚙️ O'yin", "ad:cfg")],
        [btn("🎭 Rollar", "ad:roles"), btn("💰 Iqtisod", "ad:eco")],
        [btn("🛒 Do'kon (tanga)", "ad:shopp"), btn("💎 Do'kon (olmos)", "ad:shopd")],
        [btn("👥 Userlar", "ad:uf"), btn("💎 To'lov", "ad:dia")],
        [btn("🎁 Gift sozlamalari", "ad:giftset"), btn("🆘 Qo'llab-quvvatlash", "ad:sup")],
        [btn("💰 Balans", "ad:balset"), btn("🥇 Oltin narxlari", "ad:oltset")],
        [btn("💸 O'tkazma", "ad:trset")],
        [btn("💳 To'lovlar" . ($pc > 0 ? " ($pc)" : ""), "ad:pay"), btn("🏘 Guruhlar", "ad:gr")],
        [btn("📢 Xabar", "ad:bc"), btn("📣 Obuna", "ad:ch")],
        [btn("👮 Adminlar", "ad:adm"), btn("📝 Salom", "ad:txt")],
        [btn("⏱ Cron", "ad:cr"), btn("🧹 Tozalash", "ad:rs")],
    ]);
}
function admin_home($uid, $chat = null, $mid = null) { $t = "🛠 <b>Boshqaruv</b>\n\nDaraja: <b>" . level_name(admin_level($uid)) . "</b>"; if ($mid) edit($chat, $mid, $t, admin_kb()); else say($uid, $t, admin_kb()); }
function back_row($to = 'ad:home') { return [btn("◀️", $to)]; }
function num_rows($keys) { $N = NUMS(); $c = cfg(); $rows = []; foreach ($keys as $k) $rows[] = [btn(($N[$k][0] ?? $k) . ": " . fmtn($c[$k] ?? 0), "ad:set:$k")]; return $rows; }
function user_card_text($id) {
    $u = uget($id); if (!$u) return "Topilmadi.";
    $wr = $u['games'] > 0 ? round($u['wins'] * 100 / $u['games']) : 0;
    return "👤 <b>" . h($u['name']) . "</b>" . ($u['un'] !== '' ? " (@" . h($u['un']) . ")" : '') . "\n🆔 <code>$id</code>\n⭐ {$u['rating']}\n💰 " . fmtn($u['coins']) . "\n💎 " . fmtn($u['diamonds'] ?? 0) . "\n💵 " . fmtn($u['balance'] ?? 0) . " so'm\n🥇 " . fmtn($u['oltin'] ?? 0) . "\n🎮 {$u['games']} · 🏆 {$u['wins']} ($wr%)\n" . (!empty($u['banned']) ? "🚫" : "✅");
}
function user_card_kb($id) { $u = uget($id); return ikb([[!empty($u['banned']) ? btn("♻️ Blokdan", "ad:ub:$id") : btn("🚫 Bloklash", "ad:ub:$id")],[btn("💰 Tanga +/-", "ad:uc:$id")],[btn("💎 Olmos +/-", "ad:ud:$id")],[btn("💵 Balans +/-", "ad:ubl:$id")],[btn("🥇 Oltin +/-", "ad:uol:$id")],[btn("🔄 Reset", "ad:ur:$id")], back_row()]); }
function admin_cb($uid, $q, $p) {
    $chat = $q['message']['chat']['id']; $mid = $q['message']['message_id']; $act = $p[1] ?? 'home'; $c = cfg();
    $lvl = admin_level($uid);
    if ($lvl === 'pay' && !in_array($act, ['home','stat','pay','pv','pac','par','noop'], true)) { answer($q['id'], "Huquq yo'q.", true); return; }
    if ($lvl === 'mod' && !in_array($act, ['home','stat','uf','ub','uc','ud','ubl','uol','ur','ag','gv','gx','noop'], true)) { answer($q['id'], "Huquq yo'q.", true); return; }
    answer($q['id']);
    if ($act === 'home') { setstep($uid, '', []); admin_home($uid, $chat, $mid); return; }
    if ($act === 'stat') {
        $us = J('users'); $gr = J('groups'); $st = J('stats');
        $coins = 0; $bn = 0; $diam = 0; $totalRoles = 0; $totBal = 0; $totOltin = 0;
        foreach ($us as $u) { $coins += $u['coins']; $diam += (int)($u['diamonds'] ?? 0); $totBal += (int)($u['balance'] ?? 0); $totOltin += (int)($u['oltin'] ?? 0); if (!empty($u['banned'])) $bn++; if (is_array($u['owned_roles'] ?? null)) $totalRoles += count($u['owned_roles']); }
        $ng = 0; foreach ($gr as $x) if (empty($x['left'])) $ng++;
        $pays = J('payments'); $conf = 0; $pend = 0; $rej = 0; $totDS = 0; $totSum = 0; $totBalTop = 0;
        foreach ($pays as $r) { if ($r['status'] === 'confirmed') { $conf++; if (($r['kind'] ?? 'diamond') === 'balance') $totBalTop += (int)($r['balance'] ?? $r['price']); else { $totDS += (int)$r['diamonds']; $totSum += (int)$r['price']; } } elseif ($r['status'] === 'pending') $pend++; else $rej++; }
        $itemsSold = [];
        foreach ($us as $u) foreach (($u['items'] ?? []) as $k => $v) if ((int)$v > 0) { if (!isset($itemsSold[$k])) $itemsSold[$k] = 0; $itemsSold[$k] += (int)$v; }
        $t = "📊 <b>Statistika</b>\n\n👥 User: <b>" . count($us) . "</b> (blok: $bn)\n🏘 Guruh: <b>$ng</b>\n🎮 Faol: <b>" . count(J('games')) . "</b>\n🎲 O'yin: <b>" . ($st['games'] ?? 0) . "</b>\n\n💰 " . fmtn($coins) . " tanga\n💎 " . fmtn($diam) . " olmos\n💵 " . fmtn($totBal) . " so'm\n🥇 " . fmtn($totOltin) . " oltin\n🎭 Sotilgan rollar: <b>$totalRoles</b>\n\n💳 <b>To'lovlar:</b>\n✅ $conf · ⏳ $pend · ❌ $rej\n💎 Sotilgan: " . fmtn($totDS) . " · 💰 " . fmtn($totSum) . " so'm\n💵 Balans to'ldirish: " . fmtn($totBalTop) . "\n\n🛒 <b>Buyumlar:</b>\n";
        if ($itemsSold) { $items = shop_items(); foreach ($itemsSold as $k => $v) $t .= "   " . ($items[$k][0] ?? $k) . ": <b>$v</b>\n"; } else $t .= "— yo'q —\n";
        edit($chat, $mid, $t, ikb([back_row()])); return;
    }
    if ($act === 'topgr') {
        $gr = J('groups'); $rows = [];
        foreach ($gr as $id => $x) { if (!empty($x['left'])) continue; $rows[] = ['id' => $id, 'title' => $x['title'] ?? ('G' . $id), 'games' => (int)($x['games'] ?? 0), 'banned' => !empty($x['banned'])]; }
        usort($rows, function($a, $b){ return $b['games'] - $a['games']; });
        $totalGames = 0; foreach ($rows as $r) $totalGames += $r['games'];
        $t = "🏆 <b>TOP guruhlar</b>\n\n📊 " . count($rows) . " guruh · $totalGames o'yin\n\n"; $i = 0;
        foreach (array_slice($rows, 0, 15) as $r) { $i++; $m = $i <= 3 ? ['🥇', '🥈', '🥉'][$i-1] : "$i."; $t .= "$m " . h($r['title']) . " — <b>" . $r['games'] . "</b>" . ($r['banned'] ? " 🚫" : '') . "\n"; }
        if (!$rows) $t .= "Yo'q.";
        $kb = []; $rr = []; $j = 0;
        foreach (array_slice($rows, 0, 15) as $r) { $rr[] = btn("📊 " . $r['title'], "ad:g:" . $r['id']); if (++$j % 2 === 0) { $kb[] = $rr; $rr = []; } }
        if ($rr) $kb[] = $rr; $kb[] = back_row();
        edit($chat, $mid, $t, ikb($kb)); return;
    }
    if ($act === 'cfg') { 
        $rows = num_rows(['reg_time', 'night_time', 'day_time', 'vote_time', 'min_players', 'max_players']); 
        $rows[] = [btn("Rol ochilishi: " . ($c['reveal_role'] ? "✅" : "❌"), "ad:tg:reveal_role")]; 
        $rows[] = [btn("Qattiq tartib: " . ($c['strict_chat'] ? "✅" : "❌"), "ad:tg:strict_chat")]; 
        $rows[] = [btn("🌙 Tun uchun GIF", "ad:setmedia:night")];
        $rows[] = [btn("☀️ Kun uchun GIF", "ad:setmedia:day")];
        $rows[] = back_row(); 
        edit($chat, $mid, "⚙️ <b>O'yin</b>", ikb($rows)); return; 
    }
    if ($act === 'setmedia' && isset($p[2])) {
        setstep($uid, 'adm_media', ['type' => $p[2]]);
        $type = $p[2] === 'night' ? "Tun" : "Kun";
        say($uid, "🖼 <b>$type uchun rasm/GIF yuboring</b>\n\nRasm, GIF yoki Video yuboring (yoki URL manzilini yozing).\n\n/cancel");
        return;
    }
    if ($act === 'tg' && isset($p[2]) && in_array($p[2], ['reveal_role', 'strict_chat'], true)) { setcfg($p[2], $c[$p[2]] ? 0 : 1); admin_cb($uid, $q, ['ad', 'cfg']); return; }
    if ($act === 'roles') { $rows = []; foreach (toggle_roles() as $r) $rows[] = [btn((in_array($r, $c['roles_off'], true) ? "❌ " : "✅ ") . role_name($r), "ad:rt:$r")]; $rows[] = back_row(); edit($chat, $mid, "🎭 <b>Rollar</b>", ikb($rows)); return; }
    if ($act === 'rt' && isset($p[2]) && in_array($p[2], toggle_roles(), true)) { $off = $c['roles_off']; if (in_array($p[2], $off, true)) $off = array_values(array_diff($off, [$p[2]])); else $off[] = $p[2]; setcfg('roles_off', $off); admin_cb($uid, $q, ['ad', 'roles']); return; }
    if ($act === 'eco') { $rows = num_rows(eco_keys()); $rows[] = back_row(); edit($chat, $mid, "💰 <b>Iqtisod</b>", ikb($rows)); return; }
    if ($act === 'shopp') { $rows = num_rows(shop_keys()); $rows[] = back_row(); edit($chat, $mid, "🛒 <b>Do'kon — tanga</b>", ikb($rows)); return; }
    if ($act === 'shopd') { $rows = num_rows(shopd_keys()); $rows[] = back_row(); edit($chat, $mid, "💎 <b>Do'kon — olmos</b>\n0 = sotilmaydi", ikb($rows)); return; }
    if ($act === 'set' && isset($p[2]) && array_key_exists($p[2], NUMS())) { $N = NUMS(); setstep($uid, 'adm_num', ['key' => $p[2]]); say($uid, "✏️ <b>" . $N[$p[2]][0] . "</b>\nHozir: <b>" . $c[$p[2]] . "</b>\nYangi raqam (" . $N[$p[2]][1] . "–" . $N[$p[2]][2] . "):\n\n/cancel"); return; }
    if ($act === 'uf') { setstep($uid, 'adm_uf', []); say($uid, "🆔 User ID:\n\n/cancel"); return; }
    if ($act === 'ub' && isset($p[2])) { $u = uget($p[2]); if ($u) uset($p[2], ['banned' => empty($u['banned']) ? 1 : 0]); edit($chat, $mid, user_card_text($p[2]), user_card_kb($p[2])); return; }
    if ($act === 'uc' && isset($p[2])) { setstep($uid, 'adm_coins', ['id' => $p[2]]); say($uid, "💰 Tanga (500 yoki -100):\n\n/cancel"); return; }
    if ($act === 'ud' && isset($p[2])) { setstep($uid, 'adm_diam', ['id' => $p[2]]); say($uid, "💎 Olmos (50 yoki -10):\n\n/cancel"); return; }
    if ($act === 'ubl' && isset($p[2])) { setstep($uid, 'adm_bal', ['id' => $p[2]]); say($uid, "💵 Balans so'm (50000 yoki -10000):\n\n/cancel"); return; }
    if ($act === 'uol' && isset($p[2])) { setstep($uid, 'adm_olt', ['id' => $p[2]]); say($uid, "🥇 Oltin (10 yoki -5):\n\n/cancel"); return; }
    if ($act === 'ur' && isset($p[2])) { uset($p[2], ['games' => 0, 'wins' => 0, 'streak' => 0, 'best_streak' => 0, 'roles' => [], 'ach' => [], 'rating' => 1000, 'wwins' => 0]); edit($chat, $mid, user_card_text($p[2]), user_card_kb($p[2])); return; }
    if ($act === 'gr') { $rows = []; $i = 0; foreach (J('groups') as $id => $x) { if (!empty($x['left'])) continue; if (++$i > 20) break; $rows[] = [btn((!empty($x['banned']) ? "🚫 " : "🏘 ") . $x['title'] . " · " . ($x['games'] ?? 0), "ad:g:$id")]; } $rows[] = back_row(); edit($chat, $mid, "🏘 <b>Guruhlar</b>", ikb($rows)); return; }
    if ($act === 'g' && isset($p[2])) { $gr = J('groups'); $x = $gr[$p[2]] ?? null; if (!$x) { edit($chat, $mid, "Yo'q.", ikb([back_row('ad:gr')])); return; } edit($chat, $mid, "🏘 <b>" . h($x['title']) . "</b>\n🆔 <code>{$p[2]}</code>\n🎮 " . ($x['games'] ?? 0), ikb([[btn(!empty($x['banned']) ? "♻️" : "🚫", "ad:gb:{$p[2]}")], [btn("🚪", "ad:gl:{$p[2]}")], back_row('ad:gr')])); return; }
    if ($act === 'gb' && isset($p[2])) { $gr = J('groups'); if (isset($gr[$p[2]])) { $gr[$p[2]]['banned'] = empty($gr[$p[2]]['banned']) ? 1 : 0; S('groups', $gr); if ($gr[$p[2]]['banned']) { gdel($p[2]); tg('leaveChat', ['chat_id' => $p[2]]); } } admin_cb($uid, $q, ['ad', 'g', $p[2]]); return; }
    if ($act === 'gl' && isset($p[2])) { gdel($p[2]); tg('leaveChat', ['chat_id' => $p[2]]); $gr = J('groups'); if (isset($gr[$p[2]])) { $gr[$p[2]]['left'] = 1; S('groups', $gr); } admin_cb($uid, $q, ['ad', 'gr']); return; }
    if ($act === 'ag') { $rows = []; foreach (J('games') as $id => $g) $rows[] = [btn("🎮 " . $g['title'] . " · " . $g['status'] . " · " . count($g['players']), "ad:gv:$id")]; if (!$rows) $rows[] = [btn("—", "ad:ag")]; $rows[] = back_row(); edit($chat, $mid, "🎮 <b>Faol</b>", ikb($rows)); return; }
    if ($act === 'gv' && isset($p[2])) { $g = gget($p[2]); if (!$g) { edit($chat, $mid, "Yo'q.", ikb([back_row('ad:ag')])); return; } $t = "🎮 <b>" . h($g['title']) . "</b>\n<b>{$g['status']}</b> · kun {$g['day']}\n\n"; foreach ($g['players'] as $x) $t .= (!empty($x['alive']) ? "🟢 " : "💀 ") . h($x['name']) . ($x['role'] !== '' ? " — " . role_name($x['role']) : '') . "\n"; edit($chat, $mid, $t, ikb([[btn("⏹", "ad:gx:{$p[2]}")], back_row('ad:ag')])); return; }
    if ($act === 'gx' && isset($p[2])) { if (gget($p[2])) { gdel($p[2]); say($p[2], "⏹"); } admin_cb($uid, $q, ['ad', 'ag']); return; }
    if ($act === 'bc') { edit($chat, $mid, "📢 <b>Xabar</b>", ikb([[btn("👥 Userlar", "ad:bt:usr")], [btn("🏘 Guruhlar", "ad:bt:grp")], [btn("🌐 Hammaga", "ad:bt:all")], back_row()])); return; }
    if ($act === 'bt' && isset($p[2])) { setstep($uid, 'adm_bc', ['to' => $p[2]]); say($uid, "✉️ Yuboring:\n\n/cancel"); return; }
    if ($act === 'ch') { $rows = []; foreach ($c['channels'] as $i => $ch) $rows[] = [btn("🗑 $ch", "ad:cd:$i")]; $rows[] = [btn("➕", "ad:ca")]; $rows[] = back_row(); edit($chat, $mid, "📣 <b>Obuna</b>", ikb($rows)); return; }
    if ($act === 'ca') { setstep($uid, 'adm_ch', []); say($uid, "📣 @kanal:\n\n/cancel"); return; }
    if ($act === 'cd' && isset($p[2])) { $l = $c['channels']; unset($l[(int)$p[2]]); setcfg('channels', array_values($l)); admin_cb($uid, $q, ['ad', 'ch']); return; }
    if ($act === 'adm') { $rows = []; global $OWNER; $rows[] = [btn("👑 " . $OWNER . " (owner)", "ad:ar:$OWNER")]; foreach ($c['admins'] as $a) $rows[] = [btn("🛡 $a (super)", "ad:ar:$a")]; foreach ($c['admin_roles'] as $id => $lv) $rows[] = [btn(level_name($lv) . " $id", "ad:ar:$id")]; $rows[] = [btn("➕", "ad:aa")]; $rows[] = back_row(); edit($chat, $mid, "👮 <b>Adminlar</b>", ikb($rows)); return; }
    if ($act === 'aa') { setstep($uid, 'adm_adm', []); say($uid, "🆔 Yangi admin:\n\n/cancel"); return; }
    if ($act === 'ar' && isset($p[2])) { global $OWNER; if ((string)$p[2] === (string)$OWNER) { answer($q['id'], "Owner.", true); return; } $rows = [[btn("💳 To'lov", "ad:aset:" . $p[2] . ":pay")],[btn("👮 Moderator", "ad:aset:" . $p[2] . ":mod")],[btn("🛡 Super", "ad:aset:" . $p[2] . ":super")],[btn("🚫 Olib tashlash", "ad:aset:" . $p[2] . ":none")], back_row('ad:adm')]; edit($chat, $mid, "👮 <code>" . h($p[2]) . "</code> — daraja:", ikb($rows)); return; }
    if ($act === 'aset' && isset($p[2], $p[3])) { $tid = (string)$p[2]; $lvl = (string)$p[3]; global $OWNER; if ($tid === (string)$OWNER) { answer($q['id'], "Owner.", true); return; } $roles = $c['admin_roles']; $admins = array_map('strval', $c['admins']); if ($lvl === 'none') { unset($roles[$tid]); $admins = array_values(array_filter($admins, function($x) use ($tid) { return $x !== $tid; })); } elseif ($lvl === 'super') { unset($roles[$tid]); if (!in_array($tid, $admins, true)) $admins[] = $tid; } else { $admins = array_values(array_filter($admins, function($x) use ($tid) { return $x !== $tid; })); $roles[$tid] = $lvl; } setcfg('admins', $admins); setcfg('admin_roles', $roles); answer($q['id'], "✅"); admin_cb($uid, $q, ['ad','adm']); return; }
    
    // QO'LLAB QUVVATLASH
    if ($act === 'sup') { 
        $rows = [];
        foreach ($c['support_buttons'] ?? [] as $i => $b) {
            $type = $b['type'] === 'url' ? "🔗" : "📝";
            $rows[] = [btn($type . " " . $b['text'] . " 🗑", "ad:sup_del:$i")];
        }
        $rows[] = [btn("➕ Yangi qo'shish", "ad:sup_add")];
        $rows[] = back_row();
        edit($chat, $mid, "🆘 <b>Qo'llab-quvvatlash menyusi</b>\n\nFoydalanuvchilar ko'radigan tugmalar ro'yxati. O'chirish uchun bosing.", ikb($rows));
        return; 
    }
    if ($act === 'sup_del' && isset($p[2])) {
        $btns = $c['support_buttons'] ?? [];
        if (isset($btns[(int)$p[2]])) { unset($btns[(int)$p[2]]); setcfg('support_buttons', array_values($btns)); }
        admin_cb($uid, $q, ['ad', 'sup']); return;
    }
    if ($act === 'sup_add') { setstep($uid, 'adm_sup_text', []); say($uid, "1️⃣ Yangi tugma uchun <b>matn</b> kiriting (masalan: ℹ️ Bot haqida):\n\n/cancel"); return; }
    if ($act === 'sup_type' && isset($p[2])) {
        $type = $p[2];
        $tmp = uget($uid)['tmp'];
        setstep($uid, 'adm_sup_value', ['text' => $tmp['text'], 'type' => $type]);
        if ($type === 'text') say($uid, "3️⃣ Ushbu tugma bosilganda chiqadigan <b>matnni</b> yuboring:\n\n/cancel");
        else say($uid, "3️⃣ Ushbu tugma uchun <b>URL manzil</b> yuboring (https:// bilan boshlansin, masalan: https://t.me/admin):\n\n/cancel");
        return;
    }

    // GIFT SOZLAMALARI
    if ($act === 'giftset') { 
        $rows = [
            [btn("Holat: " . (!empty($c['gift_enabled']) ? "✅" : "❌"), "ad:gift_en")],
            [btn("Admin: " . h($c['gift_admin']), "ad:gift_adm")],
        ];
        foreach ($c['gift_packages'] ?? [] as $i => $g) $rows[] = [btn($g['name'] . " (" . $g['stars'] . " yulduz) 🗑", "ad:giftdel:$i")];
        $rows[] = [btn("➕ Qo'shish", "ad:giftadd")];
        $rows[] = back_row();
        edit($chat, $mid, "🎁 <b>Gift sozlamalari</b>\n\nGiftlar orqali to'lov qilish bo'limi.", ikb($rows)); return; 
    }
    if ($act === 'gift_en') { setcfg('gift_enabled', empty($c['gift_enabled']) ? 1 : 0); admin_cb($uid, $q, ['ad','giftset']); return; }
    if ($act === 'gift_adm') { setstep($uid, 'adm_giftad', []); say($uid, "👤 Gift qabul qiluvchi admin @username ini kiriting (masalan: @admin):\n\n/cancel"); return; }
    if ($act === 'giftdel' && isset($p[2])) { $pkgs = $c['gift_packages'] ?? []; if (isset($pkgs[(int)$p[2]])) { unset($pkgs[(int)$p[2]]); setcfg('gift_packages', array_values($pkgs)); } admin_cb($uid, $q, ['ad','giftset']); return; }
    if ($act === 'giftadd') { setstep($uid, 'adm_giftadd', []); say($uid, "🎁 <b>Yangi gift qo'shish</b>\n\nFormat: <code>Nomi Yulduz_soni Olmos_soni Balans_so'm</code>\nMasalan: <code>Atirgul 15 50 15000</code>\n\n/cancel"); return; }

    if ($act === 'dia') { $rows = [[btn("📇 Karta: " . ($c['card_number'] !== '' ? h($c['card_number']) : "❌"), "ad:dcard")],[btn("👤 Egasi", "ad:dholder")],[btn("🟦 Uzcard: " . (!empty($c['uzcard_enabled']) ? "✅" : "❌"), "ad:uzcard")],[btn("📝 Izoh", "ad:dnote")],[btn("📦 Olmos paketlar (" . count($c['diamond_packages'] ?? []) . ")", "ad:dpkg")],[btn("🎭 Rol narxlari", "ad:drp")],[btn("👥 Referal: " . $c['ref_need'] . "→" . $c['ref_diamonds'] . "💎", "ad:dref")], back_row()]; edit($chat, $mid, "💎 <b>To'lov</b>", ikb($rows)); return; }
    if ($act === 'dcard') { setstep($uid, 'adm_dcard', []); say($uid, "📇 Karta:\n\n/cancel"); return; }
    if ($act === 'dholder') { setstep($uid, 'adm_dholder', []); say($uid, "👤 F.I.SH.:\n\n/cancel"); return; }
    if ($act === 'dnote') { setstep($uid, 'adm_dnote', []); say($uid, "📝 Izoh:\n\n/cancel"); return; }
    if ($act === 'dpkg') { $rows = []; foreach ($c['diamond_packages'] ?? [] as $i => $pp) $rows[] = [btn("💎 " . $pp['diamonds'] . " — " . fmtn($pp['price']) . " 🗑", "ad:dpdel:$i")]; $rows[] = [btn("➕", "ad:dpadd")]; $rows[] = back_row('ad:dia'); edit($chat, $mid, "📦", ikb($rows)); return; }
    if ($act === 'dpdel' && isset($p[2])) { $pkgs = $c['diamond_packages'] ?? []; if (isset($pkgs[(int)$p[2]])) { unset($pkgs[(int)$p[2]]); setcfg('diamond_packages', array_values($pkgs)); } admin_cb($uid, $q, ['ad','dpkg']); return; }
    if ($act === 'dpadd') { setstep($uid, 'adm_dpadd', []); say($uid, "📦 <code>olmos narx</code>\nmasalan: <code>100 50000</code>\n\n/cancel"); return; }
    if ($act === 'drp') { $rows = []; $pD = $c['role_prices'] ?? []; $pT = $c['role_prices_t'] ?? []; foreach (ROLES() as $rk => $rv) { if ($rk === 'civil') continue; $pd = isset($pD[$rk]) ? (int)$pD[$rk] : 0; $pt = isset($pT[$rk]) ? (int)$pT[$rk] : 0; $lbl = role_name($rk) . " — "; if ($pd > 0 && $pt > 0) $lbl .= "💎$pd·🪙" . fmtn($pt); elseif ($pd > 0) $lbl .= "💎$pd"; elseif ($pt > 0) $lbl .= "🪙" . fmtn($pt); else $lbl .= "❌"; $rows[] = [btn($lbl, "ad:drpset:$rk")]; } $rows[] = back_row('ad:dia'); edit($chat, $mid, "🎭 <b>Rol narxlari</b>", ikb($rows)); return; }
    if ($act === 'drpset' && isset($p[2])) { $rk = $p[2]; $pD = $c['role_prices'] ?? []; $pT = $c['role_prices_t'] ?? []; $pd = isset($pD[$rk]) ? (int)$pD[$rk] : 0; $pt = isset($pT[$rk]) ? (int)$pT[$rk] : 0; $info = "🎭 <b>" . role_name($rk) . "</b>\n\n💎 " . ($pd > 0 ? $pd : "❌") . "\n🪙 " . ($pt > 0 ? fmtn($pt) : "❌"); $rows = [[btn("💎 Olmos", "ad:drpd:$rk")],[btn("🪙 Tanga", "ad:drpt:$rk")],[btn("🚫 Olib tashlash", "ad:drprm:$rk")], back_row('ad:drp')]; edit($chat, $mid, $info, ikb($rows)); return; }
    if ($act === 'drpd' && isset($p[2])) { setstep($uid, 'adm_drp', ['role' => $p[2]]); say($uid, "💎 " . role_name($p[2]) . " olmos (0 = yo'q):\n\n/cancel"); return; }
    if ($act === 'drpt' && isset($p[2])) { setstep($uid, 'adm_drpt', ['role' => $p[2]]); say($uid, "🪙 " . role_name($p[2]) . " tanga (0 = yo'q):\n\n/cancel"); return; }
    if ($act === 'drprm' && isset($p[2])) { $rk = $p[2]; $pD = $c['role_prices'] ?? []; $pT = $c['role_prices_t'] ?? []; unset($pD[$rk]); unset($pT[$rk]); setcfg('role_prices', $pD); setcfg('role_prices_t', $pT); answer($q['id'], "✅"); admin_cb($uid, $q, ['ad', 'drp']); return; }
    if ($act === 'dref') { setstep($uid, 'adm_dref', []); say($uid, "👥 <code>taklif olmos</code>\nmasalan: <code>3 10</code>\n\n/cancel"); return; }
    if ($act === 'uzcard') { $rows = [[btn("Holat: " . (!empty($c['uzcard_enabled']) ? "✅" : "❌"), "ad:uz_en")],[btn("Merchant ID: " . ($c['uzcard_merchant_id'] !== '' ? h($c['uzcard_merchant_id']) : "❌"), "ad:uz_mid")],[btn("API key: " . ($c['uzcard_api_key'] !== '' ? "✅" : "❌"), "ad:uz_key")],[btn("API URL", "ad:uz_url")],[btn("Secret: " . ($c['uzcard_callback_secret'] !== '' ? "✅" : "❌"), "ad:uz_sec")], back_row('ad:dia')]; edit($chat, $mid, "🟦 <b>Uzcard</b>", ikb($rows)); return; }
    if ($act === 'uz_en') { setcfg('uzcard_enabled', empty($c['uzcard_enabled']) ? 1 : 0); admin_cb($uid, $q, ['ad','uzcard']); return; }
    if ($act === 'uz_mid') { setstep($uid, 'adm_uz_mid', []); say($uid, "Merchant ID:\n\n/cancel"); return; }
    if ($act === 'uz_key') { setstep($uid, 'adm_uz_key', []); say($uid, "API key:\n\n/cancel"); return; }
    if ($act === 'uz_url') { setstep($uid, 'adm_uz_url', []); say($uid, "API URL:\n\n/cancel"); return; }
    if ($act === 'uz_sec') { setstep($uid, 'adm_uz_sec', []); say($uid, "Secret:\n\n/cancel"); return; }
    if ($act === 'balset') { $rows = [[btn("🪙 1 tanga = " . fmtn($c['balance_rate_tanga']) . " so'm", "ad:balt")],[btn("💎 1 olmos = " . fmtn($c['balance_rate_olmos']) . " so'm", "ad:balo")],[btn("📦 Paketlar (" . count($c['balance_packages'] ?? []) . ")", "ad:balpk")], back_row()]; edit($chat, $mid, "💰 <b>Balans</b>", ikb($rows)); return; }
    if ($act === 'balt') { setstep($uid, 'adm_balt', []); say($uid, "1 tanga necha so'm?\nHozir: " . fmtn($c['balance_rate_tanga']) . "\n\n/cancel"); return; }
    if ($act === 'balo') { setstep($uid, 'adm_balo', []); say($uid, "1 olmos necha so'm?\nHozir: " . fmtn($c['balance_rate_olmos']) . "\n\n/cancel"); return; }
    if ($act === 'balpk') { $rows = []; foreach ($c['balance_packages'] ?? [] as $i => $pp) $rows[] = [btn("💵 " . fmtn($pp['amount']) . " so'm 🗑", "ad:balpdel:$i")]; $rows[] = [btn("➕", "ad:balpadd")]; $rows[] = back_row('ad:balset'); edit($chat, $mid, "📦 <b>Balans paketlari</b>", ikb($rows)); return; }
    if ($act === 'balpdel' && isset($p[2])) { $pkgs = $c['balance_packages'] ?? []; if (isset($pkgs[(int)$p[2]])) { unset($pkgs[(int)$p[2]]); setcfg('balance_packages', array_values($pkgs)); } admin_cb($uid, $q, ['ad','balpk']); return; }
    if ($act === 'balpadd') { setstep($uid, 'adm_balpadd', []); say($uid, "Necha so'mlik paket? (masalan: 50000):\n\n/cancel"); return; }
    if ($act === 'oltset') { $rows = [[btn("🪙 Sotib olish (tanga): " . fmtn($c['oltin_buy_t']), "ad:olbt")],[btn("🪙 Sotish (tanga): " . fmtn($c['oltin_sell_t']), "ad:olst")],[btn("💎 Sotib olish (olmos): " . fmtn($c['oltin_buy_o']), "ad:olbo")],[btn("💎 Sotish (olmos): " . fmtn($c['oltin_sell_o']), "ad:olso")], back_row()]; edit($chat, $mid, "🥇 <b>Oltin narxlari</b>", ikb($rows)); return; }
    if ($act === 'olbt') { setstep($uid, 'adm_olbt', []); say($uid, "🪙 Sotib olish narxi (tanga):\nHozir: " . fmtn($c['oltin_buy_t']) . "\n\n/cancel"); return; }
    if ($act === 'olst') { setstep($uid, 'adm_olst', []); say($uid, "🪙 Sotish narxi (tanga):\nHozir: " . fmtn($c['oltin_sell_t']) . "\n\n/cancel"); return; }
    if ($act === 'olbo') { setstep($uid, 'adm_olbo', []); say($uid, "💎 Sotib olish narxi (olmos):\nHozir: " . fmtn($c['oltin_buy_o']) . "\n\n/cancel"); return; }
    if ($act === 'olso') { setstep($uid, 'adm_olso', []); say($uid, "💎 Sotish narxi (olmos):\nHozir: " . fmtn($c['oltin_sell_o']) . "\n\n/cancel"); return; }
    if ($act === 'trset') {
        $rows = [
            [btn((!empty($c['tr_enabled']) ? "✅ Yoqilgan" : "❌ O'chirilgan") . " — bosib o'zgartirish", "ad:tr_en")],
            [btn("📊 Komissiya: " . $c['tr_fee_pct'] . "%", "ad:tr_fee")],
            [btn("📈 Kunlik limit: " . fmtn($c['tr_daily_limit']), "ad:tr_lim")],
            [btn("🪙 Min tanga: " . fmtn($c['tr_min_tanga']), "ad:tr_mint")],
            [btn("💎 Min olmos: " . fmtn($c['tr_min_olmos']), "ad:tr_mino")],
            [btn("🥇 Min oltin: " . fmtn($c['tr_min_oltin']), "ad:tr_ming")],
            [btn("💵 Min balans: " . fmtn($c['tr_min_balans']), "ad:tr_minb")],
            back_row(),
        ];
        edit($chat, $mid, "💸 <b>O'tkazma sozlamalari</b>\n\nGuruhda: <code>/give 100 tanga</code> (reply)", ikb($rows)); return;
    }
    if ($act === 'tr_en') { setcfg('tr_enabled', empty($c['tr_enabled']) ? 1 : 0); admin_cb($uid, $q, ['ad', 'trset']); return; }
    if ($act === 'tr_fee') { setstep($uid, 'adm_trfee', []); say($uid, "📊 Komissiya % (0–50):\nHozir: " . $c['tr_fee_pct'] . "%\n\n/cancel"); return; }
    if ($act === 'tr_lim') { setstep($uid, 'adm_trlim', []); say($uid, "📈 Kunlik limit:\nHozir: " . fmtn($c['tr_daily_limit']) . "\n0 = cheksiz\n\n/cancel"); return; }
    if ($act === 'tr_mint') { setstep($uid, 'adm_trmint', []); say($uid, "🪙 Min tanga:\nHozir: " . fmtn($c['tr_min_tanga']) . "\n\n/cancel"); return; }
    if ($act === 'tr_mino') { setstep($uid, 'adm_trmino', []); say($uid, "💎 Min olmos:\nHozir: " . fmtn($c['tr_min_olmos']) . "\n\n/cancel"); return; }
    if ($act === 'tr_ming') { setstep($uid, 'adm_trming', []); say($uid, "🥇 Min oltin:\nHozir: " . fmtn($c['tr_min_oltin']) . "\n\n/cancel"); return; }
    if ($act === 'tr_minb') { setstep($uid, 'adm_trminb', []); say($uid, "💵 Min balans:\nHozir: " . fmtn($c['tr_min_balans']) . "\n\n/cancel"); return; }
    if ($act === 'pay') { $rows = []; $pays = J('payments'); usort($pays, function($a, $b){ return $b['created'] - $a['created']; }); $i = 0; foreach ($pays as $r) { if (++$i > 30) break; $ic = $r['status'] === 'pending' ? "⏳" : ($r['status'] === 'confirmed' ? "✅" : "❌"); $kind = ($r['kind'] ?? 'diamond') === 'balance' ? "💵" : (isset($r['gift_name']) ? "🎁" : "💎"); $rows[] = [btn("$ic$kind " . h($r['name']) . " — " . fmtn(($r['kind'] ?? '') === 'balance' ? ($r['balance'] ?? $r['price']) : ($r['gift_name'] ?? $r['diamonds'])), "ad:pv:" . $r['id'])]; } if (!$rows) $rows[] = [btn("—", "ad:noop")]; $rows[] = back_row(); edit($chat, $mid, "💳 <b>To'lovlar</b>\n⏳ " . pay_pending_count(), ikb($rows)); return; }
    if ($act === 'pv' && isset($p[2])) { $r = pay_get($p[2]); if (!$r) { edit($chat, $mid, "Yo'q.", ikb([back_row('ad:pay')])); return; } $st = ['pending'=>"⏳",'confirmed'=>"✅",'rejected'=>"❌"]; $kind = ($r['kind'] ?? 'diamond') === 'balance' ? "💵 Balans" : (isset($r['gift_name']) ? "🎁 Gift" : "💎 Olmos"); $t = "💳 <b>" . $r['id'] . "</b>\n\n👤 " . h($r['name']) . "\n🆔 <code>" . $r['uid'] . "</code>\n$kind\n"; if (($r['kind'] ?? 'diamond') === 'balance') $t .= "💵 +" . fmtn($r['balance'] ?? $r['price']) . " so'm"; else $t .= "💎 " . $r['diamonds'] . " · " . fmtn($r['price']) . " so'm"; if (isset($r['gift_name'])) $t .= "\n🎁 " . $r['gift_name'] . " (" . $r['gift_stars'] . " yulduz)"; $t .= "\n" . ($st[$r['status']] ?? $r['status']); if (($r['proof_type'] ?? '') === 'photo') tg('sendPhoto', ['chat_id' => $chat, 'photo' => $r['proof_data'], 'caption' => "Chek: " . $r['id']]); elseif (($r['proof_type'] ?? '') === 'document') tg('sendDocument', ['chat_id' => $chat, 'document' => $r['proof_data'], 'caption' => "Chek"]); elseif (($r['proof_type'] ?? '') === 'uzcard_auto') $t .= "\n🟦 Uzcard"; else $t .= "\n📝 " . h($r['proof_data'] ?? ''); $btns = []; if ($r['status'] === 'pending') { $btns[] = [btn("✅ Tasdiq", "ad:pac:" . $r['id'])]; $btns[] = [btn("❌ Rad", "ad:par:" . $r['id'])]; } $btns[] = back_row('ad:pay'); edit($chat, $mid, $t, ikb($btns)); return; }
    if ($act === 'pac' && isset($p[2])) { $r = pay_get($p[2]); if (!$r) { answer($q['id'], "Yo'q.", true); return; } if ($r['status'] !== 'pending') { answer($q['id'], "Ko'rilgan.", true); return; } pay_update($r['id'], ['status' => 'confirmed', 'confirmed_by' => $uid, 'confirmed_at' => time()]); if (($r['kind'] ?? 'diamond') === 'balance') { addbalance($r['uid'], $r['balance'] ?? $r['price']); say($r['uid'], "✅ <b>Balans to'ldirildi!</b>\n💵 <b>+" . fmtn($r['balance'] ?? $r['price']) . " so'm</b>\n🧾 <code>" . $r['id'] . "</code>"); } else { adddiamonds($r['uid'], $r['diamonds']); say($r['uid'], "✅ <b>To'lov tasdiqlandi!</b>\n💎 <b>+" . fmtn($r['diamonds']) . " olmos</b>\n🧾 <code>" . $r['id'] . "</code>"); } admin_cb($uid, $q, ['ad','pv',$r['id']]); return; }
    if ($act === 'par' && isset($p[2])) { $r = pay_get($p[2]); if (!$r) { answer($q['id'], "Yo'q.", true); return; } if ($r['status'] !== 'pending') { answer($q['id'], "Ko'rilgan.", true); return; } setstep($uid, 'adm_preject', ['payid' => $r['id']]); say($uid, "❌ Rad sababi:\n\n/cancel"); return; }
    if ($act === 'noop') return;
    if ($act === 'txt') { setstep($uid, 'adm_txt', []); say($uid, "📝 Salom matni (- = o'chirish):\n\n/cancel"); return; }
    if ($act === 'cr') { $host = $_SERVER['HTTP_HOST'] ?? 'domen'; $path = $_SERVER['SCRIPT_NAME'] ?? '/'; $url = "https://$host$path?tick=1&key=" . cron_key(); $last = $c['last_tick'] > 0 ? (time() - $c['last_tick']) . " soniya oldin" : "ishlamagan"; edit($chat, $mid, "⏱ <b>Cron</b>\n\n<code>" . h($url) . "</code>\n\nOxirgi: <b>$last</b>", ikb([back_row()])); return; }
    if ($act === 'rs') { edit($chat, $mid, "🧹 Statistikani nolga tushirish?\n\nDavom?", ikb([[btn("✅", "ad:rs2")], back_row()])); return; }
    if ($act === 'rs2') { $us = J('users'); foreach ($us as $id => $u) { $us[$id]['games'] = 0; $us[$id]['wins'] = 0; $us[$id]['streak'] = 0; $us[$id]['best_streak'] = 0; $us[$id]['roles'] = []; $us[$id]['ach'] = []; $us[$id]['rating'] = 1000; $us[$id]['wwins'] = 0; } S('users', $us); edit($chat, $mid, "✅", ikb([back_row()])); return; }
}

function admin_step($uid, $step, $tmp, $m) {
    $text = trim((string)($m['text'] ?? '')); $c = cfg();
    
    if ($step === 'adm_sup_text') {
        setstep($uid, 'adm_sup_type', ['text' => $text]);
        say($uid, "2️⃣ Tugma turini tanlang:", ikb([
            [btn("📝 Matn yuboradi", "ad:sup_type:text")],
            [btn("🔗 Havola (URL)", "ad:sup_type:url")]
        ]));
        return;
    }
    if ($step === 'adm_sup_value') {
        $btns = $c['support_buttons'] ?? [];
        $id = 'sup_' . time() . rand(10, 99);
        $btns[] = ['id' => $id, 'text' => $tmp['text'], 'type' => $tmp['type'], 'value' => $text];
        setcfg('support_buttons', $btns);
        setstep($uid, '', []);
        say($uid, "✅ Yangi tugma qo'shildi!", main_kb($uid));
        admin_home($uid);
        return;
    }

    if ($step === 'adm_giftad') {
        if (!preg_match('/^@[A-Za-z0-9_]{4,}$/', $text)) { say($uid, "❌ @username formatida yozing."); return; }
        setcfg('gift_admin', $text); setstep($uid, '', []);
        say($uid, "✅ Gift qabul qiluvchi: " . $text, main_kb($uid)); admin_home($uid); return;
    }
    if ($step === 'adm_giftadd') {
        $parts = preg_split('/\s+/', $text);
        if (count($parts) < 4) { say($uid, "❌ Format: <code>Nomi Yulduz_soni Olmos_soni Balans_so'm</code>"); return; }
        $name = $parts[0]; $stars = (int)$parts[1]; $diamonds = (int)$parts[2]; $balance = (int)$parts[3];
        if ($stars < 1 || $diamonds < 1 || $balance < 1) { say($uid, "❌ Faqat musbat sonlar."); return; }
        $pkgs = $c['gift_packages'] ?? [];
        $pkgs[] = ['name' => $name, 'stars' => $stars, 'diamonds' => $diamonds, 'balance' => $balance];
        setcfg('gift_packages', $pkgs); setstep($uid, '', []);
        say($uid, "✅ Gift qo'shildi.", main_kb($uid)); admin_home($uid); return;
    }

    if ($step === 'adm_media') {
        $type = $tmp['type'] ?? 'night';
        $file_id = '';
        if (isset($m['animation'])) { $file_id = $m['animation']['file_id']; }
        elseif (isset($m['photo'])) { $file_id = end($m['photo'])['file_id']; }
        elseif (isset($m['video'])) { $file_id = $m['video']['file_id']; }
        elseif (isset($m['text']) && filter_var($text, FILTER_VALIDATE_URL)) { $file_id = $text; }
        
        if (!$file_id) { say($uid, "❌ Iltimos rasm, GIF, video yuboring yoki to'g'ri URL manzil yozing."); return; }
        
        setcfg($type . '_media', $file_id);
        setstep($uid, '', []);
        say($uid, "✅ " . ($type === 'night' ? "Tun" : "Kun") . " uchun rasm muvaffaqiyatli saqlandi!", main_kb($uid));
        admin_home($uid);
        return;
    }
    if ($step === 'adm_num') { $N = NUMS(); $k = $tmp['key']; if (!isset($N[$k]) || !preg_match('/^\d+$/', $text) || (int)$text < $N[$k][1] || (int)$text > $N[$k][2]) { say($uid, "❌ " . $N[$k][1] . "–" . $N[$k][2]); return; } setcfg($k, (int)$text); setstep($uid, '', []); say($uid, "✅", main_kb($uid)); admin_home($uid); return; }
    if ($step === 'adm_uf') { if (!preg_match('/^\d+$/', $text) || !uget($text)) { say($uid, "❌ Topilmadi."); return; } setstep($uid, '', []); say($uid, user_card_text($text), user_card_kb($text)); return; }
    if ($step === 'adm_coins') { if (!preg_match('/^-?\d+$/', $text)) { say($uid, "❌ Raqam."); return; } addcoins($tmp['id'], (int)$text); setstep($uid, '', []); say($uid, "✅\n" . user_card_text($tmp['id']), user_card_kb($tmp['id'])); return; }
    if ($step === 'adm_diam') { if (!preg_match('/^-?\d+$/', $text)) { say($uid, "❌"); return; } adddiamonds($tmp['id'], (int)$text); setstep($uid, '', []); say($uid, "✅\n" . user_card_text($tmp['id']), user_card_kb($tmp['id'])); return; }
    if ($step === 'adm_bal') { if (!preg_match('/^-?\d+$/', $text)) { say($uid, "❌"); return; } addbalance($tmp['id'], (int)$text); setstep($uid, '', []); say($uid, "✅\n" . user_card_text($tmp['id']), user_card_kb($tmp['id'])); return; }
    if ($step === 'adm_olt') { if (!preg_match('/^-?\d+$/', $text)) { say($uid, "❌"); return; } addoltin($tmp['id'], (int)$text); setstep($uid, '', []); say($uid, "✅\n" . user_card_text($tmp['id']), user_card_kb($tmp['id'])); return; }
    if ($step === 'adm_ch') { if (!preg_match('/^@[A-Za-z0-9_]{4,}$/', $text)) { say($uid, "❌ @kanal"); return; } $l = $c['channels']; if (!in_array($text, $l, true)) $l[] = $text; setcfg('channels', $l); setstep($uid, '', []); say($uid, "✅", main_kb($uid)); return; }
    if ($step === 'adm_adm') { if (!preg_match('/^\d{5,15}$/', $text)) { say($uid, "❌ ID."); return; } $l = array_map('strval', $c['admins']); if (!in_array($text, $l, true)) $l[] = $text; setcfg('admins', $l); setstep($uid, '', []); say($uid, "✅\n/admin → 👮 Adminlar → ID → daraja", main_kb($uid)); return; }
    if ($step === 'adm_txt') { setcfg('welcome', $text === '-' ? '' : mb_substr($text, 0, 500)); setstep($uid, '', []); say($uid, "✅", main_kb($uid)); return; }
    if ($step === 'adm_bc') { $to = $tmp['to']; $ids = []; if ($to === 'usr' || $to === 'all') foreach (J('users') as $id => $u) if (empty($u['banned'])) $ids[$id] = 1; if ($to === 'grp' || $to === 'all') foreach (J('groups') as $id => $x) if (empty($x['left']) && empty($x['banned'])) $ids[$id] = 1; setstep($uid, '', []); say($uid, "📤 " . count($ids) . "..."); $ok = 0; foreach ($ids as $id => $_) { $r = tg('copyMessage', ['chat_id' => $id, 'from_chat_id' => $m['chat']['id'], 'message_id' => $m['message_id']]); if (!empty($r['ok'])) $ok++; usleep(40000); } say($uid, "📢 $ok / " . count($ids), main_kb($uid)); return; }
    if ($step === 'adm_dcard') { setcfg('card_number', mb_substr($text, 0, 50)); setstep($uid, '', []); say($uid, "✅", main_kb($uid)); return; }
    if ($step === 'adm_dholder') { setcfg('card_holder', mb_substr($text, 0, 100)); setstep($uid, '', []); say($uid, "✅", main_kb($uid)); return; }
    if ($step === 'adm_dnote') { setcfg('pay_note', mb_substr($text, 0, 500)); setstep($uid, '', []); say($uid, "✅", main_kb($uid)); return; }
    if ($step === 'adm_dpadd') { if (!preg_match('/^(\d+)\s+(\d+)$/', $text, $mm)) { say($uid, "❌ olmos narx"); return; } $pkgs = $c['diamond_packages'] ?? []; $pkgs[] = ['diamonds' => (int)$mm[1], 'price' => (int)$mm[2]]; setcfg('diamond_packages', $pkgs); setstep($uid, '', []); say($uid, "✅", main_kb($uid)); return; }
    if ($step === 'adm_drp') { if (!preg_match('/^\d+$/', $text)) { say($uid, "❌"); return; } $prices = $c['role_prices'] ?? []; $rk = $tmp['role']; $v = (int)$text; if ($v <= 0) unset($prices[$rk]); else $prices[$rk] = $v; setcfg('role_prices', $prices); setstep($uid, '', []); say($uid, "✅ " . role_name($rk) . ": " . ($v > 0 ? "$v 💎" : "yo'q"), main_kb($uid)); return; }
    if ($step === 'adm_drpt') { if (!preg_match('/^\d+$/', $text)) { say($uid, "❌"); return; } $prices = $c['role_prices_t'] ?? []; $rk = $tmp['role']; $v = (int)$text; if ($v <= 0) unset($prices[$rk]); else $prices[$rk] = $v; setcfg('role_prices_t', $prices); setstep($uid, '', []); say($uid, "✅ " . role_name($rk) . ": " . ($v > 0 ? fmtn($v) . " tanga" : "yo'q"), main_kb($uid)); return; }
    if ($step === 'adm_dref') { if (!preg_match('/^(\d+)\s+(\d+)$/', $text, $mm)) { say($uid, "❌ taklif olmos"); return; } setcfg('ref_need', max(1, (int)$mm[1])); setcfg('ref_diamonds', (int)$mm[2]); setstep($uid, '', []); say($uid, "✅", main_kb($uid)); return; }
    if ($step === 'adm_preject') { $r = pay_get($tmp['payid']); if ($r && $r['status'] === 'pending') { pay_update($r['id'], ['status' => 'rejected', 'reject_reason' => mb_substr($text, 0, 500), 'rejected_by' => $uid, 'rejected_at' => time()]); say($r['uid'], "❌ <b>Rad etildi.</b>\n🧾 <code>" . $r['id'] . "</code>\n📝 " . h($text)); } setstep($uid, '', []); say($uid, "✅", main_kb($uid)); return; }
    if ($step === 'adm_uz_mid') { setcfg('uzcard_merchant_id', mb_substr($text, 0, 100)); setstep($uid, '', []); say($uid, "✅", main_kb($uid)); return; }
    if ($step === 'adm_uz_key') { setcfg('uzcard_api_key', mb_substr($text, 0, 500)); setstep($uid, '', []); say($uid, "✅", main_kb($uid)); return; }
    if ($step === 'adm_uz_url') { if (!preg_match('#^https?://#', $text)) { say($uid, "❌ http(s)://"); return; } setcfg('uzcard_api_url', mb_substr($text, 0, 300)); setstep($uid, '', []); say($uid, "✅", main_kb($uid)); return; }
    if ($step === 'adm_uz_sec') { setcfg('uzcard_callback_secret', mb_substr($text, 0, 200)); setstep($uid, '', []); say($uid, "✅", main_kb($uid)); return; }
    if ($step === 'adm_balt') { if (!preg_match('/^\d+$/', $text) || (int)$text < 1) { say($uid, "❌"); return; } setcfg('balance_rate_tanga', (int)$text); setstep($uid, '', []); say($uid, "✅ 1 tanga = " . fmtn((int)$text) . " so'm", main_kb($uid)); return; }
    if ($step === 'adm_balo') { if (!preg_match('/^\d+$/', $text) || (int)$text < 1) { say($uid, "❌"); return; } setcfg('balance_rate_olmos', (int)$text); setstep($uid, '', []); say($uid, "✅ 1 olmos = " . fmtn((int)$text) . " so'm", main_kb($uid)); return; }
    if ($step === 'adm_balpadd') { if (!preg_match('/^\d+$/', $text) || (int)$text < 1000) { say($uid, "❌ min 1000"); return; } $pkgs = $c['balance_packages'] ?? []; $pkgs[] = ['amount' => (int)$text]; setcfg('balance_packages', $pkgs); setstep($uid, '', []); say($uid, "✅ " . fmtn((int)$text) . " so'm", main_kb($uid)); return; }
    if ($step === 'adm_olbt') { if (!preg_match('/^\d+$/', $text) || (int)$text < 1) { say($uid, "❌"); return; } setcfg('oltin_buy_t', (int)$text); setstep($uid, '', []); say($uid, "✅ Sotib olish: " . fmtn((int)$text) . " tanga/1", main_kb($uid)); return; }
    if ($step === 'adm_olst') { if (!preg_match('/^\d+$/', $text) || (int)$text < 1) { say($uid, "❌"); return; } setcfg('oltin_sell_t', (int)$text); setstep($uid, '', []); say($uid, "✅ Sotish: " . fmtn((int)$text) . " tanga/1", main_kb($uid)); return; }
    if ($step === 'adm_olbo') { if (!preg_match('/^\d+$/', $text) || (int)$text < 1) { say($uid, "❌"); return; } setcfg('oltin_buy_o', (int)$text); setstep($uid, '', []); say($uid, "✅ Sotib olish: " . fmtn((int)$text) . " olmos/1", main_kb($uid)); return; }
    if ($step === 'adm_olso') { if (!preg_match('/^\d+$/', $text) || (int)$text < 1) { say($uid, "❌"); return; } setcfg('oltin_sell_o', (int)$text); setstep($uid, '', []); say($uid, "✅ Sotish: " . fmtn((int)$text) . " olmos/1", main_kb($uid)); return; }
    if ($step === 'adm_trfee') { if (!preg_match('/^\d+$/', $text) || (int)$text > 50) { say($uid, "❌ 0–50"); return; } setcfg('tr_fee_pct', (int)$text); setstep($uid, '', []); say($uid, "✅ Komissiya: " . (int)$text . "%", main_kb($uid)); return; }
    if ($step === 'adm_trlim') { if (!preg_match('/^\d+$/', $text)) { say($uid, "❌"); return; } setcfg('tr_daily_limit', (int)$text); setstep($uid, '', []); say($uid, "✅ Limit: " . fmtn((int)$text), main_kb($uid)); return; }
    if ($step === 'adm_trmint') { if (!preg_match('/^\d+$/', $text)) { say($uid, "❌"); return; } setcfg('tr_min_tanga', (int)$text); setstep($uid, '', []); say($uid, "✅", main_kb($uid)); return; }
    if ($step === 'adm_trmino') { if (!preg_match('/^\d+$/', $text)) { say($uid, "❌"); return; } setcfg('tr_min_olmos', (int)$text); setstep($uid, '', []); say($uid, "✅", main_kb($uid)); return; }
    if ($step === 'adm_trming') { if (!preg_match('/^\d+$/', $text)) { say($uid, "❌"); return; } setcfg('tr_min_oltin', (int)$text); setstep($uid, '', []); say($uid, "✅", main_kb($uid)); return; }
    if ($step === 'adm_trminb') { if (!preg_match('/^\d+$/', $text)) { say($uid, "❌"); return; } setcfg('tr_min_balans', (int)$text); setstep($uid, '', []); say($uid, "✅", main_kb($uid)); return; }
}

// ======================= KIRISH =======================
function process_update($u) {
    if (isset($u['callback_query'])) { handle_cb($u['callback_query']); return; }
    if (isset($u['my_chat_member'])) { handle_member($u['my_chat_member']); return; }
    $m = $u['message'] ?? null;
    if (!$m || empty($m['from']) || !empty($m['from']['is_bot'])) return;
    $type = $m['chat']['type'] ?? '';
    if ($type === 'private') handle_private($m);
    elseif ($type === 'group' || $type === 'supergroup') handle_group($m);
}

if (($_SERVER['REQUEST_URI'] ?? '') === '/healthz' || parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) === '/healthz') {
    header('Content-Type: application/json');
    try { db()->query('SELECT 1'); echo json_encode(['ok' => true, 'service' => 'genzo-mafia']); }
    catch (Throwable $e) { http_response_code(503); echo json_encode(['ok' => false, 'error' => 'database_unavailable']); }
    exit();
}

if (isset($_GET['uzcard_cb'])) {
    header('Content-Type: application/json'); $c = cfg(); $raw = file_get_contents('php://input');
    if (!empty($c['uzcard_callback_secret'])) { $sig = $_SERVER['HTTP_X_SIGNATURE'] ?? ($_SERVER['HTTP_X_UZCARD_SIGN'] ?? ''); $expected = hash_hmac('sha256', $raw, $c['uzcard_callback_secret']); if (!hash_equals($expected, $sig)) { http_response_code(403); echo json_encode(['ok' => false]); exit(); } }
    $data = json_decode($raw, true);
    if (!is_array($data) || empty($data['order_id'])) { http_response_code(400); echo json_encode(['ok' => false]); exit(); }
    $r = pay_get((string)$data['order_id']);
    if (!$r) { http_response_code(404); echo json_encode(['ok' => false]); exit(); }
    if ($r['status'] === 'confirmed') { echo json_encode(['ok' => true, 'already' => true]); exit(); }
    $okStatus = isset($data['status']) && in_array(strtolower((string)$data['status']), ['success','paid','completed','ok','1'], true);
    if ($okStatus) {
        pay_update($r['id'], ['status' => 'confirmed', 'confirmed_at' => time(), 'auto' => 1, 'confirmed_by' => 'uzcard']);
        if (($r['kind'] ?? 'diamond') === 'balance') {
            addbalance($r['uid'], $r['balance'] ?? $r['price']);
            say($r['uid'], "✅ <b>Uzcard to'lov!</b>\n💵 <b>+" . fmtn($r['balance'] ?? $r['price']) . " so'm</b>\n🧾 <code>" . $r['id'] . "</code>");
        } else {
            adddiamonds($r['uid'], $r['diamonds']);
            say($r['uid'], "✅ <b>Uzcard to'lov!</b>\n💎 <b>+" . fmtn($r['diamonds']) . " olmos</b>\n🧾 <code>" . $r['id'] . "</code>");
        }
        foreach (admin_ids() as $aid) say($aid, "💎 <b>Avto to'lov</b>\n🧾 <code>" . $r['id'] . "</code>\n👤 " . h($r['name']));
    } else {
        pay_update($r['id'], ['status' => 'rejected', 'reject_reason' => 'uzcard: ' . ($data['status'] ?? 'unknown')]);
        say($r['uid'], "❌ <b>To'lov amalga oshmadi.</b>\n🧾 <code>" . $r['id'] . "</code>");
    }
    echo json_encode(['ok' => true]); exit();
}

if (isset($_GET['setcmd'])) {
    $cmds = [
        ['command' => 'game', 'description' => "Mafiya o'yinini boshlash"],
        ['command' => 'begin', 'description' => "O'yinni hoziroq boshlash"],
        ['command' => 'extend', 'description' => "Ro'yxat vaqtini uzaytirish"],
        ['command' => 'leave', 'description' => "O'yindan chiqish"],
        ['command' => 'stop', 'description' => "O'yinni to'xtatish"],
        ['command' => 'roles', 'description' => "O'yin rollarini ko'rish"],
        ['command' => 'give', 'description' => "Pul o'tkazish (reply/@user)"],
        ['command' => 'admin', 'description' => "Boshqaruv paneli"],
    ];
    $r = tg('setMyCommands', ['commands' => json_encode($cmds)]);
    echo "<pre>"; print_r($r); echo "</pre>";
    echo "Menyu yangilandi! Endi guruhga qaytib / yozib ko'ring.";
    exit();
}

if (isset($_GET['tick'])) {
    if (!hash_equals(cron_key(), (string)($_GET['key'] ?? ''))) { http_response_code(403); echo 'forbidden'; exit(); }
    $tl = fopen("$D/.tick", 'c'); if (!flock($tl, LOCK_EX | LOCK_NB)) { echo 'busy'; exit(); }
    @set_time_limit(75); $end = time() + 55; $first = true;
    do { $lk = fopen("$D/.lock", 'c'); flock($lk, LOCK_EX); if ($first) { setcfg('last_tick', time()); $first = false; } check_games(); $more = (bool)J('games'); flock($lk, LOCK_UN); fclose($lk); if (!$more) break; sleep(2); } while (time() < $end);
    echo 'ok'; exit();
}

$lk = fopen("$D/.lock", 'c'); flock($lk, LOCK_EX);
$secret = getenv('TELEGRAM_WEBHOOK_SECRET') ?: '';
if ($secret !== '' && ($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
    $provided = $_SERVER['HTTP_X_TELEGRAM_BOT_API_SECRET_TOKEN'] ?? '';
    if (!hash_equals($secret, $provided)) { http_response_code(403); exit('forbidden'); }
}
$upd = json_decode((string)file_get_contents('php://input'), true);
if (is_array($upd)) { process_update($upd); check_games(); }
exit();