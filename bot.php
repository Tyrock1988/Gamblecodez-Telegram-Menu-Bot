<?php
/**
 * GambleCodez Casino Links Menu Bot — Single-file webhook (Ubuntu 22.04 ready)
 * Admin ID: 6668510825
 * Bot token: 8002470143:AAGTzcuIY7NCOOrrbjBLUdP8aoCg5ZH6tkA
 * JSONBin Bin: 68bb2e58ae596e708fe3dd2d
 *
 * Features:
 * - /start — Welcome & main menu (DM-aware)
 * - /menu — Sends menu to DM if in group; shows menu in private
 * - Main menu: 2 buttons per row (categories)
 * - Category pages: 8 entries per page (Prev/Next + Back)
 * - /help — User commands; admin-only tooltips shown if admin
 * - /batch — Admin bulk add/update with USA/Everyone note
 * - /winnavip — VIP form + rules block
 * - /bug — Bug capture, stores ticket in JSONBin
 * - /broadcast <text> — Admin promo to private subscribers
 * - /export, /snapshots, /revert <idx> — Admin maintenance
 * - Exact invalid-input fallback:
 *   sendMessage($chat_id, "Invalid input. Please use the buttons to navigate the menu.");
 */

/* ---------------- CONFIG ---------------- */
$TOKEN = '8002470143:AAGTzcuIY7NCOOrrbjBLUdP8aoCg5ZH6tkA';
$ADMIN_ID = 6668510825;
$BOT_USERNAME = 'GambleCodezMenu_bot'; // no @
$BOT_DISPLAY_NAME = 'GambleCodez Casino Links Menu Bot';

$JSONBIN_BIN_ID = '68bb2e58ae596e708fe3dd2d';
$JSONBIN_MASTER_KEY = '$2a$10$kVxDuopEGaSnUgS2PV/P4OzlHrrEeZdOjL7XVtsfTQfMVNuyPAjnO';
$JSONBIN_BASE = "https://api.jsonbin.io/v3/b/{$JSONBIN_BIN_ID}";

$TMP_DIR = sys_get_temp_dir() . DIRECTORY_SEPARATOR . "gamblecodez_bot";
if (!is_dir($TMP_DIR)) @mkdir($TMP_DIR, 0777, true);

const PAGE_SIZE = 8;   // entries per category page
const MAIN_COLS  = 2;  // category buttons per row

/* --------------- TG UTILS --------------- */
function tg_api($method, $payload, $is_json = true) {
    global $TOKEN;
    $url = "https://api.telegram.org/bot{$TOKEN}/{$method}";
    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_POST, true);
    if ($is_json) {
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($payload));
        curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json']);
    } else {
        curl_setopt($ch, CURLOPT_POSTFIELDS, $payload);
    }
    $res = curl_exec($ch);
    curl_close($ch);
    return $res ? json_decode($res, true) : null;
}
function tg_send_message($chat_id, $text, $reply_markup = null, $parse_mode = 'HTML', $disable_web_page_preview = true) {
    $payload = ['chat_id'=>$chat_id,'text'=>$text,'parse_mode'=>$parse_mode,'disable_web_page_preview'=>$disable_web_page_preview];
    if ($reply_markup !== null) $payload['reply_markup'] = $reply_markup;
    return tg_api('sendMessage', $payload);
}
function tg_edit_message($chat_id, $message_id, $text, $reply_markup = null, $parse_mode = 'HTML', $disable_web_page_preview = true) {
    $payload = ['chat_id'=>$chat_id,'message_id'=>$message_id,'text'=>$text,'parse_mode'=>$parse_mode,'disable_web_page_preview'=>$disable_web_page_preview];
    if ($reply_markup !== null) $payload['reply_markup'] = $reply_markup;
    return tg_api('editMessageText', $payload);
}
function tg_answer_callback($callback_id, $text = '') {
    $payload = ['callback_query_id'=>$callback_id];
    if ($text !== '') $payload['text'] = $text;
    tg_api('answerCallbackQuery', $payload);
}
function tg_send_file($chat_id, $filepath, $filename = null, $caption = '') {
    if (!file_exists($filepath)) return false;
    $filename = $filename ?? basename($filepath);
    $cfile = curl_file_create($filepath, mime_content_type($filepath), $filename);
    $post = ['chat_id'=>$chat_id, 'document'=>$cfile, 'caption'=>$caption];
    tg_api('sendDocument', $post, false);
    return true;
}

/* --------------- HELPERS --------------- */
function is_private($chat) { return isset($chat['type']) && $chat['type'] === 'private'; }
function deep_link($payload = 'menu') { global $BOT_USERNAME; return "https://t.me/{$BOT_USERNAME}?start=" . urlencode($payload); }
function ci_compare($a,$b){ return strcasecmp($a,$b); }
function sort_category_entries(&$entries){
    usort($entries,function($x,$y){
        $xp = !empty($x['pin']) ? 1 : 0;
        $yp = !empty($y['pin']) ? 1 : 0;
        if ($xp !== $yp) return $yp - $xp;
        return strcasecmp($x['name'] ?? '', $y['name'] ?? '');
    });
}

/* --------------- JSONBIN --------------- */
function jsonbin_fetch_menu() {
    global $JSONBIN_BASE, $JSONBIN_MASTER_KEY;
    $ch = curl_init($JSONBIN_BASE . "/latest");
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_HTTPHEADER, ["X-Master-Key: {$JSONBIN_MASTER_KEY}"]);
    $res = curl_exec($ch); curl_close($ch);
    $decoded = json_decode($res, true);
    $record = $decoded['record'] ?? [];
    if (!isset($record['_snapshots'])) $record['_snapshots'] = [];
    if (!isset($record['_subs']))      $record['_subs'] = [];
    if (!isset($record['_bugs']))      $record['_bugs'] = [];
    return $record;
}
function jsonbin_save_menu($menu) {
    global $JSONBIN_BASE, $JSONBIN_MASTER_KEY;
    if (!isset($menu['_snapshots'])) $menu['_snapshots'] = [];
    $copy = $menu; unset($copy['_snapshots']);
    $menu['_snapshots'][] = ['ts'=>gmdate('Y-m-d_H:i:s'),'menu'=>$copy];
    $ch = curl_init($JSONBIN_BASE);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_CUSTOMREQUEST, "PUT");
    curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($menu, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
    curl_setopt($ch, CURLOPT_HTTPHEADER, ["Content-Type: application/json","X-Master-Key: {$JSONBIN_MASTER_KEY}"]);
    $res = curl_exec($ch); curl_close($ch);
    return json_decode($res, true);
}

/* --------------- RENDERERS --------------- */
function build_main_menu_keyboard($menu, $is_admin=false) {
    $cats = array_values(array_filter(array_keys($menu), function($k){ return !in_array($k, ['_snapshots','_subs','_bugs']); }));
    sort($cats, 'ci_compare');

    $rows = []; $row = [];
    foreach ($cats as $cat) {
        $row[] = ['text'=>$cat, 'callback_data'=>"cat|{$cat}|0"];
        if (count($row) === MAIN_COLS) { $rows[] = $row; $row = []; }
    }
    if ($row) $rows[] = $row;

    if ($is_admin) {
        $rows[] = [
            ['text'=>'ℹ️ Help', 'callback_data'=>'help|main'],
            ['text'=>'🗂 Export All', 'callback_data'=>'admin_export_all']
        ];
    } else {
        $rows[] = [['text'=>'ℹ️ Help', 'callback_data'=>'help|main']];
    }
    return ['inline_keyboard'=>$rows];
}
function build_category_page_keyboard($cat, $entries, $page=0) {
    $total = count($entries);
    $pages = max(1, (int)ceil($total / PAGE_SIZE));
    $page = max(0, min($page, $pages-1));
    $start = $page * PAGE_SIZE;
    $slice = array_slice($entries, $start, PAGE_SIZE);

    $rows = [];
    foreach ($slice as $e) {
        $label = $e['name'];
        $extra = [];
        if (!empty($e['tags']))  $extra[] = $e['tags'];
        if (!empty($e['promo'])) $extra[] = 'PROMO';
        if (!empty($e['pin']))   $extra[] = 'PIN';
        if ($extra) $label .= " (" . implode(", ", $extra) . ")";
        $rows[] = [ ['text'=>$label, 'url'=>$e['url']] ];
    }

    $nav = [];
    if ($page>0) $nav[] = ['text'=>'« Prev', 'callback_data'=>"cat|{$cat}|".($page-1)];
    $nav[] = ['text'=> ($pages>1 ? "Page ".($page+1)."/{$pages}" : "Back"), 'callback_data'=>'main|0'];
    if ($page<$pages-1) $nav[] = ['text'=>'Next »', 'callback_data'=>"cat|{$cat}|".($page+1)];
    $rows[] = $nav;

    return ['inline_keyboard'=>$rows];
}

/* --------------- STATE --------------- */
function set_user_state($user_id, $key, $val) {
    global $TMP_DIR;
    $path = $TMP_DIR . "/state_{$user_id}.json";
    $state = file_exists($path) ? json_decode(file_get_contents($path), true) : [];
    $state[$key] = $val;
    file_put_contents($path, json_encode($state));
}
function get_user_state($user_id, $key, $default=null) {
    global $TMP_DIR;
    $path = $TMP_DIR . "/state_{$user_id}.json";
    if (!file_exists($path)) return $default;
    $state = json_decode(file_get_contents($path), true);
    return $state[$key] ?? $default;
}
function clear_user_state($user_id, $key=null) {
    global $TMP_DIR;
    $path = $TMP_DIR . "/state_{$user_id}.json";
    if (!file_exists($path)) return;
    if ($key===null) { @unlink($path); return; }
    $state = json_decode(file_get_contents($path), true);
    unset($state[$key]);
    file_put_contents($path, json_encode($state));
}

/* --------------- ENTRY --------------- */
$raw = file_get_contents('php://input');
if (!$raw) exit;
$update = json_decode($raw, true);

$message = $update['message'] ?? null;
$callback = $update['callback_query'] ?? null;
$chat    = $message['chat'] ?? ($callback['message']['chat'] ?? null);
$chat_id = $chat['id'] ?? null;
$user    = $message['from'] ?? ($callback['from'] ?? null);
$user_id = $user['id'] ?? 0;
$text    = trim($message['text'] ?? '');
$cb_data = $callback['data'] ?? null;
$cb_id   = $callback['id'] ?? null;
$msg_id  = $callback['message']['message_id'] ?? null;

$menu = jsonbin_fetch_menu();

/* Auto-subscribe private chats for broadcasts */
if ($message && is_private($chat)) {
    $subs = $menu['_subs'] ?? [];
    if (!in_array($chat_id, $subs)) {
        $subs[] = $chat_id;
        $menu['_subs'] = $subs;
        jsonbin_save_menu($menu);
        $menu = jsonbin_fetch_menu();
    }
}

/* ---------- COMMANDS ---------- */
// /start [payload]
if ($message && strpos($text, '/start') === 0) {
    $payload = trim(substr($text, 6));
    $is_admin = ($user_id == $ADMIN_ID);

    if (!is_private($chat)) {
        $link = deep_link($payload !== '' ? $payload : 'menu');
        tg_send_message($chat_id,
            "👋 Start me in DM to open the menu:\n{$link}\n\nIf the link fails, DM <b>@{$BOT_USERNAME}</b> and send /menu.");
        exit;
    }

    $kb = build_main_menu_keyboard($menu, $is_admin);
    tg_send_message($chat_id, "✅ Welcome to <b>{$BOT_DISPLAY_NAME}</b> — ONLINE", json_encode($kb));
    exit;
}

// /menu
if ($message && $text === '/menu') {
    $is_admin = ($user_id == $ADMIN_ID);
    if (!is_private($chat)) {
        $link = deep_link('menu');
        tg_send_message($chat_id, "📬 I sent the menu to your DM:\n{$link}\n\nIf nothing arrived, DM <b>@{$BOT_USERNAME}</b> and send /menu.");
        exit;
    }
    $kb = build_main_menu_keyboard($menu, $is_admin);
    tg_send_message($chat_id, "📋 <b>{$BOT_DISPLAY_NAME} — Main Menu</b>", json_encode($kb));
    exit;
}

// /help
if ($message && $text === '/help') {
    $is_admin = ($user_id == $ADMIN_ID);
    $user_help =
"Commands:
/start — Welcome & main menu
/menu — Sends menu to DM if in group, replies in private
/help — Shows command info
/batch — Admin instructions with USA daily / Everyone toggle info
/winnavip — Submission form placeholder for VIP info

Multi-page US buttons (8 per page)
Buttons everywhere else follow same pattern";

    $admin_extra =
"\n\nAdmin tools:
/batch lines: CATEGORY|Name|URL|Tags|Promo|Pin
/broadcast <text> — send promo to private subscribers
/export — download full JSON
/snapshots — list snapshots
/revert <index> — restore snapshot";

    tg_send_message($chat_id, $user_help . ($is_admin ? $admin_extra : ""));
    exit;
}

// /winnavip
if ($message && stripos($text, '/winnavip') === 0) {
    $form = 'https://t.me/my_form_bot/form?startapp=9fc09558-1676-4d74-9e14-04cb9d72b744';
    $copy =
"🎯 <b>Winna VIP Submission</b>

Submit this form within <b>24 hours</b> of hitting: <b>Bronze 1, Silver 1, Gold 1, Plat 1, Diamond 1, Nebula 1</b>.

<b>Rules</b>
• Bonus tip only for <b>Level 1</b> of each tier (max Nebula 1)
• DM <b>@GambleCodez</b> after submitting

📝 <a href=\"$form\">Open VIP Form</a>";
    tg_send_message($chat_id, $copy);
    exit;
}

// /bug -> await next message
if ($message && $text === '/bug') {
    set_user_state($user_id, 'await_bug', 1);
    tg_send_message($chat_id, "🐛 <b>Bug report</b>\nReply with a short description (and screenshots/IDs if relevant).");
    exit;
}

// /broadcast <text> (admin)
if ($message && preg_match('/^\/broadcast\s+(.+)/s', $text, $m)) {
    if ($user_id != $ADMIN_ID) { tg_send_message($chat_id, "Unauthorized."); exit; }
    $payload = trim($m[1]);
    $subs = $menu['_subs'] ?? [];
    $sent = 0; $fail = 0;
    foreach ($subs as $sid) {
        $r = tg_send_message($sid, "📣 <b>Promo</b>\n\n{$payload}");
        if (!empty($r['ok'])) $sent++; else $fail++;
    }
    tg_send_message($chat_id, "Broadcast done. Sent: {$sent}" . ($fail? " | Failed: {$fail}" : ""));
    exit;
}

// /export (admin)
if ($message && $text === '/export') {
    if ($user_id != $ADMIN_ID) { tg_send_message($chat_id, "Unauthorized."); exit; }
    $tmp = tempnam(sys_get_temp_dir(), 'gc_menu_') . '.json';
    file_put_contents($tmp, json_encode($menu, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
    tg_send_file($chat_id, $tmp, basename($tmp), 'GambleCodez Menu export');
    @unlink($tmp);
    exit;
}

// /snapshots (admin)
if ($message && $text === '/snapshots') {
    if ($user_id != $ADMIN_ID) { tg_send_message($chat_id, "Unauthorized."); exit; }
    $snaps = $menu['_snapshots'] ?? [];
    if (empty($snaps)) { tg_send_message($chat_id, "No snapshots found."); exit; }
    $out = [];
    foreach ($snaps as $i=>$s) { $out[] = "{$i}: ".($s['ts'] ?? 'unknown'); }
    tg_send_message($chat_id, "Snapshots:\n" . implode("\n", $out));
    exit;
}

// /revert <index> (admin)
if ($message && preg_match('/^\/revert\s+(\d+)$/', $text, $m)) {
    if ($user_id != $ADMIN_ID) { tg_send_message($chat_id, "Unauthorized."); exit; }
    $idx = intval($m[1]);
    $snaps = $menu['_snapshots'] ?? [];
    if (!isset($snaps[$idx])) { tg_send_message($chat_id, "Snapshot index {$idx} not found."); exit; }
    $restored = $snaps[$idx]['menu'] ?? [];
    if (!isset($restored['_snapshots'])) $restored['_snapshots'] = [];
    $restored['_snapshots'][] = ['ts'=>gmdate('Y-m-d_H:i:s'), 'note'=>"reverted_from_index_{$idx}"];
    jsonbin_save_menu($restored);
    tg_send_message($chat_id, "Reverted to snapshot {$idx}. Menu updated.");
    exit;
}

// /batch (inline multiline) (admin)
if ($message && $user_id == $ADMIN_ID && stripos($text, '/batch') === 0) {
    $payload = trim(substr($text, 6));
    if ($payload === '') {
        tg_send_message($chat_id,
"Send batch lines in the same message after /batch.

Format per line:
CATEGORY|Name|URL|Tags|Promo|Pin

Tips:
• Use <b>US</b> for USA Dailies, or <b>Everyone</b> for global
• 'promo' in Promo column marks PROMO; 'pin' pins the entry

Example:
/batch
US|Winna|https://winna.com/?r=GAMBLECODEZ|KYC, VPN|promo|pin");
        exit;
    }

    $lines = preg_split("/\r\n|\n|\r/", $payload);
    $preview = $menu; $added=0; $updated=0; $errors=[];
    foreach ($lines as $i=>$raw) {
        $line = trim($raw); if ($line==='') continue;
        $parts = explode('|', $line);
        if (count($parts)<3) { $errors[]="Line ".($i+1)." invalid: '{$raw}'"; continue; }

        $cat = trim($parts[0]); $name=trim($parts[1]); $url=trim($parts[2]);
        $tags=trim($parts[3]??''); $promo=trim($parts[4]??''); $pinraw=strtolower(trim($parts[5]??''));
        $entry = ['name'=>$name,'url'=>$url];
        if ($tags!=='') $entry['tags']=$tags;
        if ($promo!=='') $entry['promo'] = (strtolower($promo)==='promo')? true : $promo;
        $entry['pin'] = in_array($pinraw,['pin','pinned'], true);

        if (!isset($preview[$cat])) $preview[$cat]=[];
        $found=null;
        foreach ($preview[$cat] as $idx=>$ex){ if (strcasecmp($ex['name']??'',$name)===0){ $found=$idx; break; } }
        if ($found!==null){ $preview[$cat][$found]=array_merge($preview[$cat][$found],$entry); $updated++; }
        else { $preview[$cat][]=$entry; $added++; }
        sort_category_entries($preview[$cat]);
    }
    $pf = $GLOBALS['TMP_DIR']."/batch_preview_{$user_id}.json";
    file_put_contents($pf, json_encode($preview, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));

    $summary = "Batch preview:\nAdded: {$added}\nUpdated: {$updated}\nErrors: ".count($errors);
    if ($errors) $summary .= "\n".implode("\n", array_slice($errors,0,8));
    $summary .= "\n\nReply YES to apply, NO to cancel.";
    tg_send_message($chat_id, $summary);
    exit;
}
if ($message && $user_id == $ADMIN_ID && in_array(strtoupper($text), ['YES','NO'])) {
    $pf = $GLOBALS['TMP_DIR']."/batch_preview_{$user_id}.json";
    if (!file_exists($pf)) exit;
    if (strtoupper($text)==='YES') {
        $preview = json_decode(file_get_contents($pf), true);
        foreach ($preview as $cat=>&$entries) {
            if (in_array($cat, ['_subs','_bugs','_snapshots'])) continue;
            sort_category_entries($entries);
        }
        jsonbin_save_menu($preview);
        @unlink($pf);
        tg_send_message($chat_id, "✅ Batch applied. Menu updated and snapshot saved.");
    } else {
        @unlink($pf);
        tg_send_message($chat_id, "❌ Batch canceled.");
    }
    exit;
}

/* ---------- CALLBACKS ---------- */
if ($callback && $cb_data) {
    if (strpos($cb_data,'main|')===0) {
        tg_answer_callback($cb_id);
        $is_admin = ($user_id == $ADMIN_ID);
        $kb = build_main_menu_keyboard($menu, $is_admin);
        tg_send_message($chat_id, "📋 <b>{$BOT_DISPLAY_NAME} — Main Menu</b>", json_encode($kb));
        exit;
    }

    if (strpos($cb_data,'cat|')===0) {
        tg_answer_callback($cb_id);
        $parts = explode('|',$cb_data);
        $cat = $parts[1] ?? ''; $page = intval($parts[2] ?? 0);
        if (!isset($menu[$cat]) || !is_array($menu[$cat])) {
            tg_send_message($chat_id, "No category: {$cat}");
            exit;
        }
        $entries = $menu[$cat];
        sort_category_entries($entries);
        $kb = build_category_page_keyboard($cat, $entries, $page);
        tg_send_message($chat_id, "<b>{$cat} Affiliates</b>:", json_encode($kb));
        exit;
    }

    if (strpos($cb_data,'help|')===0) {
        tg_answer_callback($cb_id);
        $is_admin = ($user_id == $ADMIN_ID);
        $user_help =
"Commands:
/start — Welcome & main menu
/menu — Sends menu to DM if in group, replies in private
/help — Shows command info
/batch — Admin instructions with USA daily / Everyone toggle info
/winnavip — Submission form placeholder for VIP info

Multi-page US buttons (8 per page)
Buttons everywhere else follow same pattern";
        $admin_extra =
"\n\nAdmin tips:
/batch lines: CATEGORY|Name|URL|Tags|Promo|Pin
/broadcast <text> — private DM subscribers
/export, /snapshots, /revert <index>";
        tg_send_message($chat_id, $user_help . ($is_admin ? $admin_extra : ""));
        exit;
    }

    if ($cb_data === 'admin_export_all') {
        tg_answer_callback($cb_id);
        if ($user_id != $ADMIN_ID) { tg_send_message($chat_id, "Unauthorized."); exit; }
        $tmp = tempnam(sys_get_temp_dir(), 'gc_all_') . '.json';
        file_put_contents($tmp, json_encode($menu, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
        tg_send_file($chat_id, $tmp, basename($tmp), 'Export: Full Menu (incl. service keys)');
        @unlink($tmp);
        exit;
    }
}

/* ---------- BUG CAPTURE ---------- */
if ($message && get_user_state($user_id, 'await_bug', 0) === 1 && $text !== '' && $text[0] !== '/') {
    $ticket = [
        'id' => substr(hash('sha256', $user_id . '|' . microtime(true)), 0, 10),
        'from_id' => $user_id,
        'chat_id' => $chat_id,
        'ts' => gmdate('Y-m-d H:i:s'),
        'text' => $text
    ];
    $bugs = $menu['_bugs'] ?? [];
    $bugs[] = $ticket;
    $menu['_bugs'] = $bugs;
    jsonbin_save_menu($menu);
    clear_user_state($user_id, 'await_bug');
    tg_send_message($chat_id, "✅ Bug recorded. Ticket ID: <code>{$ticket['id']}</code>\nThanks for the report!");
    exit;
}

/* ---------- ADMIN QUICK ADD (compat) ---------- */
if ($message && $user_id == $ADMIN_ID && preg_match('/^([^|]+)\|([^|]+)\|([^|]+)(?:\|(.*))?$/', $text, $m)) {
    $cat = trim($m[1]); $name=trim($m[2]); $url=trim($m[3]); $rest=trim($m[4]??'');
    $tags=''; $promo=''; $pin=false;
    if ($rest!=='') {
        $parts = explode('|', 
