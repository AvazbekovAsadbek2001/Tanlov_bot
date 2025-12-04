<?php
// ==================== DEBUG VA LOG SOZLAMLARI ====================
error_reporting(E_ALL);
ini_set('display_errors', 0);                 // Ekranga chiqarmaymiz (Telegram JSON buziladi)
ini_set('log_errors', 1);
ini_set('error_log', __DIR__ . '/debug.log');

$DEBUG_ADMIN = "7201090018"; // Sizning ID

function log_debug($msg, $data = null) {
    $time = date('Y-m-d H:i:s');
    $text = "[$time] $msg";
    if ($data !== null) {
        $text .= "\n" . json_encode($data, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
    }
    $text .= "\n\n";
    file_put_contents(__DIR__ . '/debug.log', $text, FILE_APPEND | LOCK_EX);
}

function notify_admin($text) {
    global $bot_token, $DEBUG_ADMIN;
    if (!$bot_token || !$DEBUG_ADMIN) return;
    $url = "https://api.telegram.org/bot$bot_token/sendMessage";
    file_get_contents($url . "?" . http_build_query([
        'chat_id' => $DEBUG_ADMIN,
        'text'    => "DEBUG\n\n" . $text,
        'parse_mode' => 'HTML'
    ]));
}

// Har bir update ni saqlaymiz
file_put_contents(__DIR__ . '/last_update.json', file_get_contents('php://input'));
log_debug("YANGI UPDATE KELDI", json_decode(file_get_contents('php://input'), true));

// ==================== ASOSIY SOZLAMLAR ====================
include("functions.php");

$bot_token        = "6538478280:AAGbD5Jc8rUG73sPpdKHVQpwQPM3Q-HtjBE";
$channel_main     = "@newchannel001111";
$required_channel = "@requiredchannel";
$ADMIN_ID         = "7201090018";

$likesFile    = __DIR__ . '/likes_data.json';
$competitions = __DIR__ . '/competition.json';
$stateFile    = __DIR__ . '/steps.json';

$input = file_get_contents('php://input');
if (empty($input)) {
    log_debug("BO'SH UPDATE KELDI");
    exit("No input");
}

$update = json_decode($input, true);
if ($update === null) {
    log_debug("JSON DECODE XATOLIK", json_last_error_msg());
    notify_admin("JSON decode xato:\n" . json_last_error_msg());
    exit();
}

log_debug("Update qabul qilindi");

// ==================== UMUMIY O'ZGARUVCHILAR ====================
$chat_id = $update['message']['chat']['id'] ?? null;
$user_id = $update['message']['from']['id'] ?? null;
$text    = $update['message']['text'] ?? '';
$username = $update['message']['from']['username'] ?? 'no_username';

log_debug("Foydalanuvchi", ['chat_id' => $chat_id, 'user_id' => $user_id, 'text' => $text]);

if (!$chat_id && !isset($update['callback_query'])) {
    log_debug("Chat ID topilmadi va callback ham emas");
    exit();
}

// ==================== CALLBACK QUERY ====================
if (isset($update['callback_query'])) {
    $cb = $update['callback_query'];
    $callbackId = $cb['id'] ?? null;
    $userId     = $cb['from']['id'] ?? null;
    $data       = $cb['data'] ?? null;

    log_debug("CALLBACK QUERY", ['id' => $callbackId, 'user' => $userId, 'data' => $data]);
    notify_admin("Like bosildi!\nUser: $userId\nData: $data");

    if ($data === 'like_temp' || $data === 'ignore_me') {
        answerCallbackQuery($callbackId, ""); // yoki hech nima yozmaslik ham bo'ladi
        log_debug("Eski tugma bosildi – e'tiborsiz", $data);
        exit;
    }

    if (!$data || !str_starts_with($data, 'like_')) {
        log_debug("Noto'g'ri callback data");
        exit();
    }

    // Faol tanlovni olish
    $competition = getActiveCompetition($userId); // yoki admin ID orqali aniqlash kerak bo'lsa o'zgartiring
    if (!$competition) {
        log_debug("Faol tanlov topilmadi (callbackda)");
        notify_admin("Like bosilganda faol tanlov topilmadi!");
        answerCallbackQuery($callbackId, "Tanlov topilmadi!", true);
        exit;
    }

    // Obuna tekshirish
    $not_member = [];

    if (!isUserMember($competition['main_channel'], $userId, $bot_token)) {
        $not_member[] = $competition['main_channel'];
    }

    foreach ($competition['required_channels'] ?? [] as $ch) {
        if (!isUserMember($ch, $userId, $bot_token)) $not_member[] = $ch;
    }

    if (!empty($not_member)) {
        $txt = "Quyidagi kanallarga obuna bo'ling:\n" . implode("\n", $not_member);
        answerCallbackQuery($callbackId, $txt, true);
        log_debug("Obuna tekshiruvi o'tmadi", $not_member);
        exit;
    }

    // Like qayta ishlash
    $parts = explode('_', $data, 4);
    if (count($parts) !== 4) {
        log_debug("Callback data noto'g'ri format", $data);
        exit;
    }
    [$cmd, $channelId, $messageId, $competition_id] = $parts;
    $key = "{$channelId}_{$messageId}_{$competition_id}";

    $likesData = file_exists($likesFile) ? json_decode(file_get_contents($likesFile), true) : [];
    if (!isset($likesData['channels'])) $likesData['channels'] = [];
    if (!isset($likesData['total_likes'])) $likesData['total_likes'] = 0;

    $alreadyLiked = isset($likesData['channels'][$key]['users'][$userId]);

    if ($alreadyLiked) {
        answerCallbackQuery($callbackId, "Siz allaqachon like bosgansiz!");
    } else {
        $likesData['channels'][$key]['users'][$userId] = true;
        $likesData['channels'][$key]['count'] = ($likesData['channels'][$key]['count'] ?? 0) + 1;
        $likesData['total_likes']++;

        $fp = fopen($likesFile, 'c+');
        if (flock($fp, LOCK_EX)) {
            ftruncate($fp, 0);
            fwrite($fp, json_encode($likesData, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));
            fflush($fp);
            flock($fp, LOCK_UN);
        }
        fclose($fp);

        $newCount = $likesData['channels'][$key]['count'];
        $newText = "Like $newCount";

        editMessageReplyMarkup($channelId, $messageId, [
            'inline_keyboard' => [[['text' => $newText, 'callback_data' => $data]]]
        ]);

        answerCallbackQuery($callbackId, "Rahmat! Like qabul qilindi");
        notify_admin("YANGI LIKE!\nFoydalanuvchi: @$username ($userId)\nXabar: $channelId → $messageId\nJami: $newCount");
    }

    log_debug("Callback muvaffaqiyatli bajarildi", ['key' => $key, 'liked' => !$alreadyLiked]);
    exit;
}

// ==================== ODATIY XABARLAR ====================
if (!$chat_id || !$user_id) {
    log_debug("Chat ID yoki User ID yo'q");
    exit;
}

checkstep($chat_id, $text);
log_debug("Step tekshirildi", ['step' => getStep($chat_id)]);

global $main_keyboard;

switch ($text) {
    case '/start':
        log_debug("/start buyrug'i");
        notify_admin("Foydalanuvchi botni ishga tushirdi: @$username ($user_id)");
        sendMessage($chat_id, "Assalomu alaykum! Tanlov botga xush kelibsiz!\n\nBot orqali institut, korxona yoki jamoada o‘tkaziladigan ijodiy tanlovlarni adolatli va shaffof tarzda tashkil qilishingiz mumkin.\n\nQuyidagi tugmalardan foydalaning:", $main_keyboard);
        exit;

    case "Tanlov qo'shish":
        log_debug("Tanlov yaratish boshlandi");
        if (insertStep($chat_id, "create_competition")) {
            sendMessage($chat_id, "Yangi tanlov nomini yuboring:\n\nMisol: <b>Korrupsiyaga qarshi eng yaxshi plakat</b>", 
                ['keyboard' => [['Bekor qilish']], 'resize_keyboard' => true]
            );
        }
        exit;

    // Boshqa case lar ham shu tarzda log qilish mumkin...
    // Qolgan case lar o'zgarmagan holda qoldirdim (joy tejash uchun)

    case "Bekor qilish":
        gohome($chat_id, "Amal bekor qilindi!");
        exit;
}

// ==================== POST YUBORISH ====================
if (isset($update['message']['photo']) || !empty($update['message']['text'])) {
    log_debug("Yangi post keldi", $update['message']);

    $competition = getActiveCompetition($chat_id);
    if (!$competition) {
        sendMessage($chat_id, "Sizda faol tanlov yo'q! Avval tanlov yarating va faollashtiring.");
        exit;
    }

    $postText  = $update['message']['caption'] ?? $update['message']['text'] ?? '';
    $postPhoto = isset($update['message']['photo']) ? end($update['message']['photo'])['file_id'] : null;

    // 1. Kanalning real ID sini olamiz (-1001...)
    $channelUsername = ltrim($competition['main_channel'], '@');
    $chatInfo = json_decode(file_get_contents("https://api.telegram.org/bot$bot_token/getChat?chat_id=@$channelUsername"), true);
    
    if (!$chatInfo['ok']) {
        sendMessage($chat_id, "Bot kanalga admin emas yoki kanal topilmadi!");
        log_debug("getChat xato", $chatInfo);
        exit;
    }
    $realChannelId = $chatInfo['result']['id']; // masalan: -1001234567890

    // 2. Avval postni yuboramiz (callback_data hali yo'q – bo'sh tugma)
    $tempKeyboard = [
        'inline_keyboard' => [[['text' => 'Like 0', 'callback_data' => 'ignore_me']]]
    ];

    $sent = sendPost($competition['main_channel'], $postText, $postPhoto, $tempKeyboard);
    $result = json_decode($sent, true);

    if (!$result['ok']) {
        sendMessage($chat_id, "Post yuborishda xato yuz berdi.");
        log_debug("sendPost xato", $result);
        exit;
    }

    $messageId = $result['result']['message_id'];
    $competition = getActiveCompetition($chat_id);

    // 3. DARHOL to'g'ri callback_data bilan almashtiramiz
    $correctCallback = "like_{$realChannelId}_{$messageId}_{$competition['id']}";

    $finalKeyboard = [
        'inline_keyboard' => [[
            ['text' => 'Like 0', 'callback_data' => $correctCallback]
        ]]
    ];

    // Tugmani yangilash
    $edit = editMessageReplyMarkup($realChannelId, $messageId, $finalKeyboard);

    sendMessage($chat_id, "Post muvaffaqiyatli yuborildi!\nLike tugmasi faol!");
    notify_admin("YANGI POST!\nKanal: {$competition['main_channel']}\nMessage ID: $messageId\nCallback: $correctCallback");

    exit;
}   

log_debug("Skript tugadi (hech narsa qayta ishlamadi)");
?>