<?php
    include_once("functions.php");

    $bot_token        = "6538478280:AAGbD5Jc8rUG73sPpdKHVQpwQPM3Q-HtjBE";
    $channel_main     = "@newchannel001111";
    $required_channel = "@requiredchannel";

    $likesFile    = __DIR__ . '/likes_data.json';
    $competitions = __DIR__ . '/competition.json';
    $stateFile    = __DIR__ . '/steps.json';


    $input = file_get_contents('php://input');
        if (empty($input)) exit("No input");

    $update = json_decode($input, true);
    if ($update === null) {
        log_error("JSON decode error");
        exit();
    }

    $chat_id = $update['message']['chat']['id'] ?? null;
    $user_id = $update['message']['from']['id'] ?? null;
    $text    = $update['message']['text'] ?? '';

    if (!$chat_id) exit();

    checkstep($chat_id, $text);

    global $main_keyboard;

    switch ($text) {
        case '/start':
            sendMessage($chat_id, 
                "Assalomu alaykum! Tanlov botga xush kelibsiz!\n\n" .
                "Bot orqali institut, korxona yoki jamoada o‘tkaziladigan ijodiy tanlovlarni adolatli va shaffof tarzda tashkil qilishingiz mumkin.\n\n" .
                "Quyidagi tugmalardan foydalaning:",
                $main_keyboard
            );
            exit;

        case "Tanlov qo‘shish":
            if (insertStep($chat_id, "create_competition"))
                sendMessage($chat_id, "Yangi tanlov nomini yuboring:\n\nMisol: <b>Korrupsiyaga qarshi eng yaxshi plakat</b>", 
                    ['keyboard' => [['Bekor qilish']], 'resize_keyboard' => true]
                );
            else 
                sendMessage($chat_id, 'InsertStep xatoligi');
            exit;

        case "Tanlovlarim":
            $states = file_exists($competitions) ? json_decode(file_get_contents($competitions), true) : [];

            $competitions = array_filter($states, function ($item) use ($chat_id) {
                return $item['chat_id'] == $chat_id;
            });

            if (!empty($competitions)) {
                $competitions = array_values($competitions);

                $msg = "Sizning tanlovlaringiz:\n\n";

                foreach ($competitions as $comp) {
                    $msg .= "ID: {$comp['id']}\n";
                    $msg .= "Nomi: {$comp['title']}\n";
                    $msg .= "Holati: " . (($comp['active'] == true) ? "Faol" : "Faol emas") . "\n";
                    $msg .= "Yuborilagan kanal: ".  "https://t.me/". ltrim($comp["main_channel"], '@')."\n";
                    $msg .= "Majbutoy kanallar : \n";
                    foreach ($comp['required_channels'] as $item) {
                        $msg .= "https://t.me/". ltrim($item, '@'). "\n";
                    }
                }

                sendMessage($chat_id, $msg, $main_keyboard);
            } else {
                sendMessage($chat_id, "Hozircha faol tanlov yo‘q yoki ma'lumot topilmadi.\n\nYangi tanlov yarating!", $main_keyboard);
            }

            exit;

        case "Aktiv tanlov":
            $competitionsData = file_exists($competitions) ? 
                json_decode(file_get_contents($competitions), true) : [];

            // Faqat aktiv tanlovlar
            $active_competitions = array_filter($competitionsData, function ($item) use ($chat_id) {
                return $item["chat_id"] == $chat_id && $item["active"] == true;
            });

            if (empty($active_competitions)) {
                sendMessage($chat_id, "Sizda aktiv tanlovlar mavjud emas!", $main_keyboard);
                exit;
            }

            $msg = "";

            foreach ($active_competitions as $comp) {
                $msg .= "🆔 ID: {$comp['id']}\n";
                $msg .= "📌 Nomi: {$comp['title']}\n";
                $msg .= "⚡ Holati: " . ($comp['active'] ? "Faol" : "Faol emas") . "\n";
                $msg .= "📮 Yuborilgan kanal: https://t.me/". ltrim($comp["main_channel"], '@') ."\n\n";

                $msg .= "📚 Majburiy kanallar:\n";

                if (!empty($comp['required_channels'])) {
                    foreach ($comp['required_channels'] as $item) {
                        $msg .= "- https://t.me/". ltrim($item, '@') ."\n";
                    }
                } else {
                    $msg .= "Hech narsa qo'shilmagan\n";
                }

                $msg .= "\n-------------------------\n\n";
            }

            sendMessage($chat_id, $msg, $main_keyboard);
            exit;


        case "O'zgartirish":
            sendMessage($chat_id, "O'zgartirish funksiyasi hali tayyorlanmoqda...", $main_keyboard);
            exit;

        case "Bekor qilish":
            gohome($chat_id, "Amal bekor qilindi!");
            exit;
    }

    //--------------------------------------------------------- send post ---------------------------------------------------------
    
    if (isset($update['message'])) {
        $postText = "";
        $postPhoto = "";

        if (isset($update['message']['photo'])) {
            $postPhoto = end($update['message']['photo'])['file_id'];
            $postText = $update['message']['caption'] ?? ""; 
        } elseif (isset($update['message']['text'])) {
            $postText = $update['message']['text'];
        };

        $likesData = file_exists($likesFile) ? json_decode(file_get_contents($likesFile), true) : ['total_likes' => 0];

        $keyboard = [
            'inline_keyboard' => [
                [['text' => "Like {$likesData['total_likes']}", 'callback_data' => 'like']]
            ]
        ];

        $competition = getActiveCompetition($chat_id);
        
        sendPost($competition['main_channel'],  $postText, $postPhoto);
    }

    if (isset($update['callback_query']) && $update['callback_query']['data'] === 'like') 
    {
        $callback   = $update['callback_query'];
        $callbackId = $callback['id'];
        $user_id    = $callback['from']['id'];
        $message_id = $callback['message']['message_id'];
        $chat_id_cb = $callback['message']['chat']['id'];

        $likesData = file_exists($likesFile) 
            ? json_decode(file_get_contents($likesFile), true) 
            : ['total_likes' => 0, 'users' => []];

        if (in_array($user_id, $likesData['users'] ?? [])) 
        {
            file_get_contents("https://api.telegram.org/bot$bot_token/answerCallbackQuery?" . http_build_query([
                'callback_query_id' => $callbackId,
                'text'              => "Rahmat! Siz allaqachon like bosgansiz.",
                'show_alert'        => true
            ]));
            exit;
        }

        // Obuna tekshirish
        if (!isUserMember($competition["main_channel"], $user_id, $bot_token)) {
            file_get_contents("https://api.telegram.org/bot$bot_token/answerCallbackQuery?" . http_build_query([
                'callback_query_id' => $callbackId,
                'text'              => "Siz asosiy kanalga a'zo emassiz!",
                'show_alert'        => true
            ]));
            exit;
        }

        foreach ($competition['required_channels'] as $ch) {
            if (!isUserMember($ch, $user_id, $bot_token)) {
                file_get_contents("https://api.telegram.org/bot$bot_token/answerCallbackQuery?" . http_build_query([
                    'callback_query_id' => $callbackId,
                    'text'              => "Siz barcha majburiy kanallarga a'zo bo'lishingiz kerak!",
                    'show_alert'        => true
                ]));
                exit;
            }
        }

        if (in_array($user_id, $likesData['users'] ?? [])) 
        {
            file_get_contents("https://api.telegram.org/bot$bot_token/answerCallbackQuery?" . http_build_query([
                'callback_query_id' => $callbackId,
                'text'              => "Rahmat! Siz allaqachon like bosgansiz.",
                'show_alert'        => true
            ]));
            exit;
        }

        // Obuna tekshirish
        if (!isUserMember($competition["main_channel"], $user_id, $bot_token)) {
            file_get_contents("https://api.telegram.org/bot$bot_token/answerCallbackQuery?" . http_build_query([
                'callback_query_id' => $callbackId,
                'text'              => "Siz asosiy kanalga a'zo emassiz!",
                'show_alert'        => true
            ]));
            exit;
        }
        
        
        
        
            if (!in_array($user_id, $likesData['users'])) 
        {
            if (!isUserMember($competition["main_channel"], $user_id, $bot_token)) {
                file_get_contents("https://api.telegram.org/bot$bot_token/answerCallbackQuery?" . http_build_query([
                    'callback_query_id' => $callbackId,
                    'text' => "❌ Siz asosiy kanalga a'zo emassiz!",
                    'show_alert' => true
                ]));
                exit;
            }

            foreach ($competition['required_channels'] as $ch) {
                if (!isUserMember($ch, $user_id, $bot_token)) {
                    file_get_contents("https://api.telegram.org/bot$bot_token/answerCallbackQuery?" . http_build_query([
                        'callback_query_id' => $callbackId,
                        'text' => "❌ Siz barcha majburiy kanallarga a'zo bo'lishingiz kerak!",
                        'show_alert' => true
                    ]));
                    exit;
                }
            }

            $likesData['total_likes']++;
            $likesData['users'][] = $user_id;

            file_put_contents($likesFile, json_encode($likesData, JSON_PRETTY_PRINT));

            // Tugma textini yangilash
            $message_id = $callback['message']['message_id'];
            $chat_id_cb = $callback['message']['chat']['id'];

            $newKeyboard = [
                'inline_keyboard' => [
                    [['text' => "Like {$likesData['total_likes']}", 'callback_data' => 'like']]
                ]
            ];

            file_get_contents("https://api.telegram.org/bot$bot_token/editMessageReplyMarkup?" . http_build_query([
                'chat_id' => $chat_id_cb,
                'message_id' => $message_id,
                'reply_markup' => json_encode($newKeyboard)
            ]));
        } else {
            file_get_contents("https://api.telegram.org/bot$bot_token/answerCallbackQuery?" . http_build_query([
                'callback_query_id' => $callbackId,
                'text' => "Rahmat! Siz allaqachon like bosgansiz.",
                'show_alert' => true
            ]));
        }
    }    

    


?>

