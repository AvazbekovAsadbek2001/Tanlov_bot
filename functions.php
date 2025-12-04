<?php
// ==================== ASOSIY API FUNSKIYALAR ====================

// Bot tokenni global qilib olish uchun
$bot_token = "6538478280:AAGbD5Jc8rUG73sPpdKHVQpwQPM3Q-HtjBE"; // yoki boshqa joydan oling

// 1. Oddiy xabar yuborish
function sendMessage($chat_id, $text, $keyboard = null, $parse_mode = 'HTML') {
    global $bot_token;
    $url = "https://api.telegram.org/bot$bot_token/sendMessage";
    
    $data = [
        'chat_id'    => $chat_id,
        'text'       => $text,
        'parse_mode' => $parse_mode,
    ];
    
    if ($keyboard) {
        $data['reply_markup'] = is_string($keyboard) ? $keyboard : json_encode($keyboard);
    }
    
    return file_get_contents($url . "?" . http_build_query($data));
}

// 2. Foto yuborish (post uchun)
function sendPhoto($chat_id, $photo_file_id, $caption = '', $keyboard = null) {
    global $bot_token;
    $url = "https://api.telegram.org/bot$bot_token/sendPhoto";
    
    $data = [
        'chat_id'   => $chat_id,
        'photo'     => $photo_file_id,
        'caption'   => $caption,
        'parse_mode'=> 'HTML',
    ];
    
    if ($keyboard) {
        $data['reply_markup'] = json_encode($keyboard);
    }
    
    return file_get_contents($url . "?" . http_build_query($data));
}

// 3. Kanalga post yuborish (tekst yoki foto)
function sendPost($channel, $text = '', $photo = null, $keyboard = null) {
    if ($photo) {
        return sendPhoto($channel, $photo, $text, $keyboard);
    } else {
        return sendMessage($channel, $text, $keyboard);
    }
}

// 4. Inline tugmani yangilash
function editMessageReplyMarkup($chat_id, $message_id, $keyboard) {
    global $bot_token;
    $url = "https://api.telegram.org/bot$bot_token/editMessageReplyMarkup";
    
    $data = [
        'chat_id'      => $chat_id,
        'message_id'   => $message_id,
        'reply_markup' => json_encode($keyboard)
    ];
    
    $result = file_get_contents($url . "?" . http_build_query($data));
    return json_decode($result, true);
}

// 5. Callback query ga javob berish (Like bosganda "Rahmat" chiqishi uchun)
function answerCallbackQuery($callback_id, $text = '', $show_alert = false) {
    global $bot_token;
    $url = "https://api.telegram.org/bot$bot_token/answerCallbackQuery";
    
    $data = [
        'callback_query_id' => $callback_id,
        'text'              => $text,
        'show_alert'        => $show_alert
    ];
    
    file_get_contents($url . "?" . http_build_query($data));
}

// 6. Foydalanuvchi kanal a'zosimi?
function isUserMember($channel, $user_id, $token = null) {
    global $bot_token;
    if (!$token) $token = $bot_token;
    
    $url = "https://api.telegram.org/bot$token/getChatMember";
    $result = file_get_contents($url . "?" . http_build_query([
        'chat_id' => $channel,
        'user_id' => $user_id
    ]));
    
    $data = json_decode($result, true);
    if (!$data['ok']) return false;
    
    $status = $data['result']['status'];
    return in_array($status, ['member', 'administrator', 'creator']);
}

// 7. Fayldan ma'lumot o'qish (xavfsiz)
function readJson($file) {
    if (!file_exists($file)) return [];
    $content = file_get_contents($file);
    return $content ? json_decode($content, true) : [];
}

// 8. Faylga yozish (lock bilan – ma'lumot yo‘qolmasin)
function writeJson($file, $data) {
    $json = json_encode($data, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
    $fp = fopen($file, 'c+');
    if (flock($fp, LOCK_EX)) {
        ftruncate($fp, 0);
        fwrite($fp, $json);
        fflush($fp);
        flock($fp, LOCK_UN);
    }
    fclose($fp);
}

// 9. Step (qadam) tizimi – foydalanuvchi nima kiritayotganini eslab qolish
function getStep($chat_id) {
    $file = __DIR__ . '/steps.json';
    $steps = readJson($file);
    return $steps[$chat_id] ?? null;
}

function insertStep($chat_id, $title, $text = null) {
    global $stepFile;
    $states = file_exists($stepFile) ? json_decode(file_get_contents($stepFile), true) : [];
    $states[$chat_id] = [
        'title' => $title,
        'text'  => $text
    ];
    file_put_contents($stepFile, json_encode($states, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
    return true;
}

function deleteStep($chat_id) {
    $file = __DIR__ . '/steps.json';
    $steps = readJson($file);
    unset($steps[$chat_id]);
    writeJson($file, $steps);
}

function checkstep($chat_id, $text) {
        global $competitions;
        global $stepFile;
        global $main_keyboard;

        $states = file_exists($stepFile) ? 
            json_decode(file_get_contents($stepFile), true) : [];

        $title = $states[$chat_id]['title'];

        if (!isset($title)) return true;

        switch ($title) {
            case "create_competition":
                $competitionsData = file_exists($competitions) ? 
                    json_decode(file_get_contents($competitions), true) : [];

                $newCompetition = [
                    'id' => uniqid(),
                    'chat_id' => $chat_id,
                    'title' => $text,
                    'active' => false,
                ];

                $competitionsData[] = $newCompetition;

                file_put_contents($competitions, json_encode($competitionsData, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));

                unset($states[$chat_id]);

                file_put_contents($stepFile, json_encode($states, JSON_PRETTY_PRINT));

                sendMessage($chat_id, "Tanlov muvaffaqiyatli qo‘shildi!\n\nID: {$newCompetition['id']}\nNomi: {$newCompetition['title']}", $main_keyboard);

                sendMessage($chat_id, "Habar jo'natilishi uchun kanal username ni kiriting. Masalan \n @channel_name");
                
                insertStep($chat_id, 'insert_chennel', $newCompetition['id']);

                exit;

            case "insert_chennel":
                $competitionsData = file_exists($competitions) ? 
                    json_decode(file_get_contents($competitions), true) : [];

                $id = $states[$chat_id]['text'];

                $index = array_find_index($competitionsData, function($item) use ($id) {
                    return $item['id'] === $id;
                });

                if ($index === -1) {
                    sendMessage($chat_id, "Tanlov topilmadi!", $main_keyboard);
                    exit;
                }

                if (!checkChannelAdmin($text)) {
                    sendMessage($chat_id, "Bot kanalga Admin emas yoki kanalda a'zoligi yo'q!\nIltimos tekshirib qaytatdan jo'nating!");
                    exit; 
                }

                $competitionsData[$index]['main_channel'] = $text;

                file_put_contents($competitions, json_encode($competitionsData, JSON_PRETTY_PRINT));

                sendMessage($chat_id, "A'zo bo'lish majburiy bo'lgan kanallarning username larini alohida-alohida yuboring. To'xtatish uchun /stop buyrug'idan foydalaning. Masalan \n  "); 
                sendMessage($chat_id, "@channel_name_1");
                sendMessage($chat_id, "@channel_name_2");
                sendMessage($chat_id," @channel_name_3");
                sendMessage($chat_id,"/stop");
                insertStep($chat_id, 'required_chennel', $id);
                exit;

            case "required_chennel":

                $competitionsData = file_exists($competitions) ? 
                    json_decode(file_get_contents($competitions), true) : [];

                $id = $states[$chat_id]['text'];

                $index = array_find_index($competitionsData, function($item) use ($id) {
                    return $item['id'] === $id;
                });

                if ($index === -1) {
                    sendMessage($chat_id, "Tanlov topilmadi!", $main_keyboard);
                    exit;
                }

                if ($text == "/stop") {
                    activateCompetition($chat_id, $competitionsData[$index]['id']);
                    gohome($chat_id, 'Tanlov yaratish yakunlandi!');
                    exit;
                }

                if (!checkChannelAdmin($text) || $text[0] != '@') {
                    sendMessage($chat_id, "Bot kanalga Admin emas yoki kanalda a'zoligi yo'q!\nIltimos tekshirib qaytatdan jo'nating!");
                    exit; 
                }

                // required_channels mavjud bo'lmasa, yaratib qo'yamiz
                if (!isset($competitionsData[$index]['required_channels'])) {
                    $competitionsData[$index]['required_channels'] = [];
                }

                // kanalni qo'shamiz
                $competitionsData[$index]['required_channels'][] = $text;

                // json saqlaymiz
                file_put_contents($competitions, json_encode($competitionsData, JSON_PRETTY_PRINT));

                sendMessage($chat_id, "Qo'shildi: $text. Yana yuborishingiz mumkin yoki /stop.");
                exit;
        }

    }

// 10. Bosh sahifaga qaytarish
function gohome($chat_id, $msg = "Bosh sahifaga qaytdingiz!") {
    global $main_keyboard;
    deleteStep($chat_id);
    sendMessage($chat_id, $msg, $main_keyboard);
}

// 11. Faol tanlovni olish
function getActiveCompetition($chat_id) {
    $file = __DIR__ . '/competition.json';
    $comps = readJson($file);
    
    foreach ($comps as $comp) {
        if ($comp['chat_id'] == $chat_id && $comp['active'] == true) {
            return $comp;
        }
    }
    return null;
}

// 12. Adminni tekshirish
function isAdmin($user_id) {
    return $user_id == "7201090018"; // yoki ro'yxatdan
}

// Asosiy klaviatura
$main_keyboard = [
    'keyboard' => [
        ["Tanlov qo'shish", "Tanlovlarim"],
        ["Aktiv tanlov", "O'zgartirish"]
    ],
    'resize_keyboard' => true
];