 <?php
    $sentFile     = __DIR__ . '/sent_start.json';
    $likesFile    = __DIR__ . '/likes_data.json';
    $competitions = __DIR__ . '/competition.json';
    $stepFile  = __DIR__ . '/steps.json';

    $main_keyboard = [
        'keyboard' => [
            ["Tanlov qo‘shish", "Tanlovlarim"],
            ["Aktiv tanlov", "O'zgartirish"]
        ],
        'resize_keyboard' => true,
        'one_time_keyboard' => false
    ];

    function log_error($msg) {
        file_put_contents(__DIR__.'/error.log', date('Y-m-d H:i:s')." - $msg\n", FILE_APPEND);
    }

    function sendMessage($chat_id, $text, $keyboard = null) {
        global $bot_token;
        $data = [
            'chat_id' => $chat_id,
            'text' => $text,
            'parse_mode' => 'HTML',
        ];
        if ($keyboard) $data['reply_markup'] = json_encode($keyboard);

        file_get_contents("https://api.telegram.org/bot$bot_token/sendMessage?" . http_build_query($data));
    }

    function checkstep($chat_id, $text) {
        global $competitions;
        global $stepFile;
        global $main_keyboard;

        $states = file_exists($stepFile) ? 
            json_decode(file_get_contents($stepFile), true) : [];

        $title = $states[$chat_id]['title'];

        sendMessage($chat_id, $title);

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

                if (!checkChannelAdmin($text)) {
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

    function array_find_index($array, $callback) {
        foreach ($array as $i => $item) {
            if ($callback($item)) return $i;
        }
        return -1;
    }

    function gohome($chat_id, $msg = "") {
        global $main_keyboard;
        global $stepFile;

        $states = file_exists($stepFile) ? 
            json_decode(file_get_contents($stepFile), true) : [];

        unset($states[$chat_id]);

        file_put_contents($stepFile, json_encode($states, JSON_PRETTY_PRINT));

        sendMessage($chat_id, $msg, $main_keyboard);
    }

    function insertStep($chat_id, $title, $text = null) {
        global $stepFile;
        $states = file_exists($stepFile) ? json_decode(file_get_contents($stepFile), true) : [];
        $states[$chat_id] = [
            'title' => $title,
            'text'  => $text
        ];

        file_put_contents($stepFile, json_encode($states));
        
        return true;
    }

    function checkChannelAdmin($username)
    {
        global $bot_token;

        if (strpos($username, '@') !== 0) {
            $username = '@' . $username;
        }

        $url = "https://api.telegram.org/bot{$bot_token}/getChatMember?chat_id={$username}&user_id=" . getBotId();

        $response = json_decode(file_get_contents($url), true);

        // Agar umuman kirish bo'lmasa — kanal yo‘q yoki bot kira olmaydi
        if (!$response || !$response['ok']) {
            return false;
        }

        $status = $response['result']['status'];

        // Bot admin bo‘lishi kerak
        return in_array($status, ['administrator', 'creator']);
    }

    function getBotId()
    {
        global $bot_token;

        $me = json_decode(file_get_contents("https://api.telegram.org/bot{$bot_token}/getMe"), true);

        return $me['ok'] ? $me['result']['id'] : null;
    }

    function activateCompetition($chat_id, $id) {
        $file = "competitions.json";

        $data = file_exists($file) ? json_decode(file_get_contents($file), true) : [];

        foreach ($data as &$item) {
            if ($item['chat_id'] == $chat_id) {
                $item['active'] = false;
            }

            if ($item['chat_id'] == $chat_id && $item['id'] == $id) {
                $item['active'] = true;
            }
        }

        file_put_contents($file, json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));

        return true;
    }

    function sendPost($chat_id, $text, $photo = null, $keyboard = null) {
        global $bot_token;

        if ($photo) {
            $data = [
                'chat_id' => $chat_id,
                'photo' => $photo,
                'caption' => $text,
                'reply_markup' => json_encode($keyboard),
                'parse_mode' => 'HTML'
            ];
            file_get_contents("https://api.telegram.org/bot$bot_token/sendPhoto?" . http_build_query($data));
        } else {
            $data = [
                'chat_id' => $chat_id,
                'text' => $text,
                'reply_markup' => json_encode($keyboard),
                'parse_mode' => 'HTML'
            ];
            file_get_contents("https://api.telegram.org/bot$bot_token/sendMessage?" . http_build_query($data));
        }
    }

