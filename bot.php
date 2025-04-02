<?php
// تنظیمات ثابت‌ها
define('API_TOKEN', '1424084686:FujHPYzWPtjgMiHkdT7BVw4e4klifUVyzWzjHnOB');
define('BOT_ID', 1000485078);
define('DB_HOST', 'localhost');
define('DB_NAME', 'parselec_channelbot');
define('DB_USER', 'parselec_botuser');
define('DB_PASS', 'K7m#nPq9L$x2');
define('LOG_FILE', 'bot_log.txt');

// تنظیم منطقه زمانی
date_default_timezone_set('Asia/Tehran');

// دریافت ورودی از پیام‌رسان بله
$update = json_decode(file_get_contents('php://input'), true);
logMessage("ورودی دریافت شده: " . json_encode($update));
if (!$update) {
    logMessage("خطا: هیچ ورودی‌ای از بله دریافت نشد!");
    die("No input received from Bale!");
}

// تابع اتصال به دیتابیس
function getDB() {
    static $pdo = null;
    if ($pdo === null) {
        try {
            $pdo = new PDO("mysql:host=" . DB_HOST . ";dbname=" . DB_NAME . ";charset=utf8mb4", DB_USER, DB_PASS, [
                PDO::ATTR_PERSISTENT => false
            ]);
            $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
            $pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
            $pdo->exec("SET NAMES 'utf8mb4'");
            $pdo->exec("SET CHARACTER SET utf8mb4");
            $pdo->exec("SET SESSION collation_connection = 'utf8mb4_unicode_ci'");
            logMessage("اتصال به دیتابیس با موفقیت انجام شد.");
        } catch (PDOException $e) {
            logMessage("خطا در اتصال به دیتابیس: " . $e->getMessage());
            die("خطا در اتصال به دیتابیس!");
        }
    }
    return $pdo;
}

// تابع لاگ کردن
function logMessage($message) {
    $log = date('Y-m-d H:i:s') . " - " . $message . "\n";
    file_put_contents(LOG_FILE, $log, FILE_APPEND);
}

// تابع اعتبارسنجی زمان
function validateDateTime($dateTime, $format = 'Y-m-d H:i') {
    $dt = DateTime::createFromFormat($format, $dateTime);
    return $dt && $dt->format($format) === $dateTime;
}

// تابع اعتبارسنجی محتوا
function validateContent($contentType, $content, $caption = null) {
    if (is_string($content) && strpos($content, API_TOKEN) !== false) {
        logMessage("خطا: محتوای ارسالی شامل توکن API است - ContentType: $contentType");
        return false;
    }
    $isValid = isset($content) && $content !== null && $content !== '';
    logMessage("اعتبارسنجی $contentType: Content: " . ($content ?? 'خالی') . ", Valid: " . ($isValid ? 'بله' : 'خیر') . ", Caption: " . ($caption ?? 'بدون کپشن'));
    return $isValid;
}

// تابع حذف پیام از کانال
function deleteMessageFromChannel($chatId, $messageId) {
    $url = "https://tapi.bale.ai/bot" . API_TOKEN . "/deleteMessage";
    $data = [
        'chat_id' => $chatId,
        'message_id' => $messageId
    ];

    $ch = curl_init();
    curl_setopt($ch, CURLOPT_HTTPHEADER, ["Content-Type:application/x-www-form-urlencoded"]);
    curl_setopt($ch, CURLOPT_URL, $url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($data));
    curl_setopt($ch, CURLOPT_TIMEOUT, 30);

    $response = curl_exec($ch);
    if (curl_errno($ch)) {
        logMessage("خطا در cURL هنگام حذف پیام: " . curl_error($ch));
        curl_close($ch);
        return false;
    }

    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    $result = json_decode($response, true);
    logMessage("پاسخ API حذف پیام: ChatID: $chatId, MessageID: $messageId, Response: " . json_encode($result) . ", HTTP Code: $httpCode");

    return $httpCode == 200 && isset($result['ok']) && $result['ok'];
}

// تابع ارسال محتوا به کانال
function sendContentToChannel($channelId, $contentType, $content, $caption = null, $replyMarkup = null) {
    if (!validateContent($contentType, $content, $caption)) {
        logMessage("خطا: محتوا نامعتبر است - ChannelID: $channelId, ContentType: $contentType");
        return false;
    }

    $methodMap = [
        'text' => 'sendMessage',
        'photo' => 'sendPhoto',
        'video' => 'sendVideo',
        'document' => 'sendDocument',
        'audio' => 'sendAudio'
    ];
    $method = $methodMap[$contentType] ?? 'sendMessage';
    $url = "https://tapi.bale.ai/bot" . API_TOKEN . "/$method";

    $channelId = trim($channelId);
    if (empty($channelId)) {
        logMessage("خطا: آیدی کانال خالی است - ChannelID: $channelId, ContentType: $contentType");
        return false;
    }

    $data = ['chat_id' => $channelId];
    if ($contentType === 'text') {
        $data['text'] = $content;
    } else {
        $data[$contentType] = $content;
        if ($caption) $data['caption'] = $caption;
    }

    if ($replyMarkup) {
        $data['reply_markup'] = json_encode($replyMarkup);
        logMessage("ارسال به کانال: ChannelID: $channelId, ReplyMarkup: " . json_encode($replyMarkup));
    }

    $data['parse_mode'] = 'Markdown';

    $ch = curl_init();
    curl_setopt($ch, CURLOPT_HTTPHEADER, ["Content-Type:application/x-www-form-urlencoded"]);
    curl_setopt($ch, CURLOPT_URL, $url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($data));
    curl_setopt($ch, CURLOPT_TIMEOUT, 30);

    $response = curl_exec($ch);
    if (curl_errno($ch)) {
        $error = curl_error($ch);
        logMessage("خطا در cURL: $error - ChannelID: $channelId, Type: $contentType");
        curl_close($ch);
        return false;
    }

    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    $result = json_decode($response, true);
    logMessage("پاسخ API: ChannelID: $channelId, Type: $contentType, Response: " . json_encode($result) . ", HTTP Code: $httpCode");

    if ($httpCode == 200 && isset($result['ok']) && $result['ok']) {
        logMessage("ارسال موفق: ChannelID: $channelId, Type: $contentType");
        return true;
    } else {
        logMessage("ارسال ناموفق: ChannelID: $channelId, Type: $contentType, Error: " . json_encode($result));
        return false;
    }
}

// تابع ارسال پیام به کاربر
function sendMessage($chatId, $text, $replyMarkup = null) {
    $url = "https://tapi.bale.ai/bot" . API_TOKEN . "/sendMessage";
    $data = [
        'chat_id' => $chatId,
        'text' => $text,
        'parse_mode' => 'Markdown'
    ];
    if ($replyMarkup) {
        $data['reply_markup'] = json_encode($replyMarkup);
    }

    $ch = curl_init();
    curl_setopt($ch, CURLOPT_HTTPHEADER, ["Content-Type:application/x-www-form-urlencoded"]);
    curl_setopt($ch, CURLOPT_URL, $url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($data));

    $response = curl_exec($ch);
    if (curl_errno($ch)) {
        logMessage("خطا در ارسال پیام: ChatID: $chatId, Error: " . curl_error($ch));
    }

    curl_close($ch);
    return json_decode($response, true);
}

// تابع ارسال نظرسنجی
function sendPoll($chatId, $question, $options, $replyMarkup = null) {
    if (empty($question) || empty($options) || count($options) < 2) {
        logMessage("خطا: سوال یا گزینه‌ها نامعتبر است - ChatID: $chatId");
        return false;
    }

    $url = "https://tapi.bale.ai/bot" . API_TOKEN . "/sendPoll";
    $data = [
        'chat_id' => $chatId,
        'question' => $question,
        'options' => json_encode($options),
        'is_anonymous' => false,
        'parse_mode' => 'Markdown'
    ];
    if ($replyMarkup) {
        $data['reply_markup'] = json_encode($replyMarkup);
    }

    $ch = curl_init();
    curl_setopt($ch, CURLOPT_HTTPHEADER, ["Content-Type:application/x-www-form-urlencoded"]);
    curl_setopt($ch, CURLOPT_URL, $url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($data));
    curl_setopt($ch, CURLOPT_TIMEOUT, 30);

    $response = curl_exec($ch);
    if (curl_errno($ch)) {
        $error = curl_error($ch);
        logMessage("خطا در cURL برای نظرسنجی: $error - ChatID: $chatId");
        curl_close($ch);
        return false;
    }

    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    $result = json_decode($response, true);
    logMessage("پاسخ API برای نظرسنجی: ChatID: $chatId, Response: " . json_encode($result) . ", HTTP Code: $httpCode");

    if ($httpCode == 200 && isset($result['ok']) && $result['ok']) {
        logMessage("ارسال نظرسنجی موفق: ChatID: $chatId");
        return true;
    } else {
        logMessage("ارسال نظرسنجی ناموفق: ChatID: $chatId, Error: " . json_encode($result));
        return false;
    }
}

// تابع ارسال اعلان به کاربر
function sendNotification($userId, $message) {
    $response = sendMessage($userId, "🔔 *اعلان ربات:* \n\n$message");
    if ($response && isset($response['ok']) && $response['ok']) {
        logMessage("اعلان با موفقیت ارسال شد - UserID: $userId, Message: $message");
        return true;
    } else {
        logMessage("خطا در ارسال اعلان - UserID: $userId, Message: $message");
        return false;
    }
}

// تابع ارتباط با API هوش مصنوعی
function callAvalAI($message) {
    $apiKey = 'aa-raAu7TOykheRJy7ekBkmKCra2GOAs2s4anxxH2g8tN0VGfeU';
    $url = 'https://api.avalai.ir/v1/chat/completions';
    $data = [
        'model' => 'gemini-2.0-flash',
        'messages' => [['role' => 'user', 'content' => $message]],
    ];

    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data));
    curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json', 'Authorization: Bearer ' . $apiKey]);
    curl_setopt($ch, CURLOPT_TIMEOUT, 15);

    $response = curl_exec($ch);
    if (curl_errno($ch)) {
        logMessage("خطا در AvalAI: " . curl_error($ch));
        curl_close($ch);
        return "⚠️ خطا در ارتباط با هوش مصنوعی";
    }

    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    $result = json_decode($response, true);
    if ($httpCode == 200 && isset($result['choices']) && !empty($result['choices'])) {
        $rawResponse = $result['choices'][0]['message']['content'] ?? '⚠️ محتوای پاسخ خالی است!';
        return str_replace(['Gemini', 'Google', 'گوگل', 'جمینی'], 'هوش مصنوعی ربات دستیار کانال بله', $rawResponse);
    }
    return "⚠️ خطا: پاسخ نامعتبر از هوش مصنوعی (کد: $httpCode)";
}

// بررسی مدیر بودن کاربر
function isAdmin($userId) {
    $admins = [942443926];
    return in_array($userId, $admins);
}

// اتصال به دیتابیس
$pdo = getDB();

// تعریف متغیرهای اولیه
$chatId = $update['message']['chat']['id'] ?? null;
$userId = $update['message']['from']['id'] ?? null;
$text = trim($update['message']['text'] ?? '');

logMessage("دریافت پیام جدید - ChatID: $chatId, UserId: $userId, Text: $text");

// فیلتر پیام‌های ربات
if ($userId == BOT_ID) {
    logMessage("پیام از خود ربات دریافت شد (ChatID: $chatId) - نادیده گرفته شد");
    exit;
}

// تشخیص نوع محتوا
$contentType = null;
$content = null;
$caption = null;

if (isset($update['message']['photo'])) {
    $contentType = 'photo';
    $photos = $update['message']['photo'];
    usort($photos, function($a, $b) { return $b['file_size'] <=> $a['file_size']; });
    $content = $photos[0]['file_id'];
    $caption = $update['message']['caption'] ?? null;
    logMessage("تشخیص محتوا: Photo با کپشن: " . ($caption ?? 'بدون کپشن'));
} elseif (isset($update['message']['video'])) {
    $contentType = 'video';
    $content = $update['message']['video']['file_id'];
    $caption = $update['message']['caption'] ?? null;
    logMessage("تشخیص محتوا: Video با کپشن: " . ($caption ?? 'بدون کپشن'));
} elseif (isset($update['message']['document'])) {
    $contentType = 'document';
    $content = $update['message']['document']['file_id'];
    $caption = $update['message']['caption'] ?? null;
    logMessage("تشخیص محتوا: Document با کپشن: " . ($caption ?? 'بدون کپشن'));
} elseif (isset($update['message']['audio'])) {
    $contentType = 'audio';
    $content = $update['message']['audio']['file_id'];
    $caption = $update['message']['caption'] ?? null;
    logMessage("تشخیص محتوا: Audio با کپشن: " . ($caption ?? 'بدون کپشن'));
} elseif (isset($update['message']['text']) && !empty(trim($update['message']['text']))) {
    $contentType = 'text';
    $content = $update['message']['text'];
    $caption = null;
    logMessage("تشخیص محتوا: Text - Content: $content");
} else {
    logMessage("هیچ محتوای معتبری تشخیص داده نشد!");
}

logMessage("نوع محتوا تشخیص داده شده: $contentType, Content: " . ($content ?? 'خالی'));

// چک کردن وجود کاربر
if ($userId !== null) { // فقط اگه userId وجود داشته باشه
    try {
        $stmt = $pdo->prepare("SELECT * FROM users WHERE user_id = ?");
        $stmt->execute([$userId]);
        $user = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$user) {
            $stmt = $pdo->prepare("INSERT INTO users (user_id, channels) VALUES (?, ?)");
            $stmt->execute([$userId, json_encode([])]);
            $user = ['user_id' => $userId, 'channels' => json_encode([]), 'action' => null, 'is_banned' => 0];
        }
    } catch (PDOException $e) {
        logMessage("خطا در دیتابیس هنگام چک کردن کاربر: " . $e->getMessage());
        sendMessage($chatId, "یه مشکلی پیش اومد، لطفاً چند دقیقه دیگه امتحان کن!");
    }
} else {
    logMessage("کاربر شناسایی نشد - ChatID: $chatId");
}

// چک بن
if (isset($user) && $user['is_banned'] == 1) {
    sendMessage($chatId, "🚫 شما از ربات محروم شده‌اید!");
    logMessage("درخواست توسط کاربر بن‌شده ارسال شده است");
    exit;
}

// تعریف متغیرهای کاربر
$channels = json_decode($user['channels'], true) ?? [];
$action = $user['action'];

// تعریف منوهای کیبورد
$mainMenu = [
    'keyboard' => [
        [['text' => 'ارسال پست زمان‌دار']],
        [['text' => 'ارسال فوروارد زمان‌دار']],
        [['text' => 'حذف پست زمان‌دار']],
        [['text' => 'لغو حذف پست زمان‌دار']],
        [['text' => 'لغو پست و فوروارد زمان‌دار']],
        [['text' => 'اتصال کانال']],
        [['text' => 'حذف کانال']],
        [['text' => 'ساخت دکمه شیشه‌ای']],
        [['text' => 'ارسال نظرسنجی']],
        [['text' => 'گزارش تخلف']],
        [['text' => 'پشتیبانی']],
        [['text' => 'راهنما']],
        [['text' => 'مدیریت']],
        [['text' => 'ساخت بنر تبادل لیستی']],
        [['text' => 'کمک از هوش مصنوعی']],
        [['text' => 'ابزارهای هوشمند']]
    ],
    'resize_keyboard' => true
];

// منوی ابزارهای هوشمند
$smartToolsMenu = [
    'keyboard' => [
        [['text' => 'تحلیل محتوا']],
        [['text' => 'بهترین زمان پست']],
        [['text' => 'تقویم محتوایی']],
        [['text' => 'بازگشت']]
    ],
    'resize_keyboard' => true
];

// دکمه بازگشت سراسری
if ($text === 'بازگشت') {
    try {
        $stmt = $pdo->prepare("UPDATE users SET action = NULL, poll_options = NULL, poll_question = NULL WHERE user_id = ?");
        $stmt->execute([$userId]);
        sendMessage($chatId, "به منوی اصلی بازگشتید.", $mainMenu);
        logMessage("دکمه بازگشت با موفقیت کار کرد - UserID: $userId");
        exit();
    } catch (PDOException $e) {
        logMessage("خطا در دیتابیس هنگام بازگشت: " . $e->getMessage());
        sendMessage($chatId, "⚠️ خطا در بازگشت به منوی اصلی! لطفاً دوباره تلاش کنید.");
    }
}

// منوی اصلی و شروع
if ($text === '/start') {
    try {
        $stmt = $pdo->prepare("UPDATE users SET action = NULL WHERE user_id = ?");
        $stmt->execute([$userId]);
    } catch (PDOException $e) {
        logMessage("خطا در دیتابیس هنگام ریست action در /start: " . $e->getMessage());
    }
    sendMessage($chatId, "🤖 به ربات مدیر کانال خوش آمدید!\n\n"
        . "🌟 *قابلیت‌ها*:\n"
        . "🔹 ارسال پست زمان‌دار (عکس، ویدیو، فایل، متن، موزیک و...)\n"
        . "🔹 ارسال فوروارد زمان‌دار (هر نوع پیام)\n"
        . "🔹 لغو پست‌ها و فورواردهای زمان‌دار\n"
        . "🔹 اتصال و حذف کانال‌ها\n"
        . "🔹 ساخت دکمه شیشه‌ای\n"
        . "🔹 ارسال نظرسنجی\n"
        . "🔹 پشتیبانی و راهنما\n"
        . "🔹 مدیریت (برای ادمین)\n"
        . "🔹 ساخت بنر تبادل لیستی\n"
        . "🔹 کمک از هوش مصنوعی\n"
        . "🔹 ابزارهای هوشمند\n\n"
        . "لطفاً یک گزینه را انتخاب کنید:", $mainMenu);
    sendNotification($userId, "شما با موفقیت ربات را راه‌اندازی کردید! برای شروع، یک کانال متصل کنید.");
} elseif ($text === 'لغو پست و فوروارد زمان‌دار') {
    try {
        $stmt = $pdo->prepare("SELECT * FROM scheduled_posts WHERE user_id = ?");
        $stmt->execute([$userId]);
        $scheduledPosts = $stmt->fetchAll();

        $stmt = $pdo->prepare("SELECT * FROM scheduled_forwards WHERE user_id = ?");
        $stmt->execute([$userId]);
        $scheduledForwards = $stmt->fetchAll();

        $allScheduled = array_merge(
            array_map(function($post) {
                return ['type' => 'post', 'id' => $post['id'], 'scheduled_time' => $post['scheduled_time'], 'channel_id' => $post['channel_id']];
            }, $scheduledPosts),
            array_map(function($forward) {
                return ['type' => 'forward', 'id' => $forward['id'], 'scheduled_time' => $forward['scheduled_time'], 'channel_id' => $forward['channel_id']];
            }, $scheduledForwards)
        );

        if (empty($allScheduled)) {
            sendMessage($chatId, "⚠️ هیچ پست یا فوروارد زمان‌داری برای شما ثبت نشده است.", $mainMenu);
        } else {
            $stmt = $pdo->prepare("UPDATE users SET action = ? WHERE user_id = ?");
            $stmt->execute(['cancel_scheduled_items', $userId]);
            
            $keyboard = [];
            foreach ($allScheduled as $item) {
                $typeLabel = $item['type'] === 'post' ? 'پست' : 'فوروارد';
                $keyboard[] = [['text' => "[$item[id]] $typeLabel - {$item['scheduled_time']} - {$item['channel_id']}"]];
            }
            $keyboard[] = [['text' => 'بازگشت']];
            
            sendMessage($chatId, "📅 *پست‌ها و فورواردهای زمان‌دار شما:*\n\nانتخاب کنید تا لغو شود:", ['keyboard' => $keyboard, 'resize_keyboard' => true]);
        }
    } catch (PDOException $e) {
        logMessage("خطا در دیتابیس هنگام نمایش پست‌ها و فورواردهای زمان‌دار: " . $e->getMessage());
        sendMessage($chatId, "⚠️ خطا در دیتابیس! لطفاً دوباره تلاش کنید.");
        sendNotification($userId, "خطایی در نمایش پست‌ها و فورواردهای زمان‌دار رخ داد. لطفاً دوباره تلاش کنید.");
    }
} elseif ($action === 'cancel_scheduled_items') {
    if (preg_match('/^\[(\d+)\]\s*(پست|فوروارد)/', $text, $match)) {
        $itemId = $match[1];
        $itemType = $match[2] === 'پست' ? 'post' : 'forward';
        try {
            if ($itemType === 'post') {
                $stmt = $pdo->prepare("SELECT * FROM scheduled_posts WHERE id = ? AND user_id = ?");
                $stmt->execute([$itemId, $userId]);
                $item = $stmt->fetch();
                if ($item) {
                    $stmt = $pdo->prepare("DELETE FROM scheduled_posts WHERE id = ? AND user_id = ?");
                    $stmt->execute([$itemId, $userId]);
                    sendMessage($chatId, "✅ پست با شناسه [$itemId] در زمان {$item['scheduled_time']} برای کانال {$item['channel_id']} لغو شد.", $mainMenu);
                    sendNotification($userId, "پست زمان‌دار شما با شناسه [$itemId] لغو شد.");
                }
            } else {
                $stmt = $pdo->prepare("SELECT * FROM scheduled_forwards WHERE id = ? AND user_id = ?");
                $stmt->execute([$itemId, $userId]);
                $item = $stmt->fetch();
                if ($item) {
                    $stmt = $pdo->prepare("DELETE FROM scheduled_forwards WHERE id = ? AND user_id = ?");
                    $stmt->execute([$itemId, $userId]);
                    sendMessage($chatId, "✅ فوروارد با شناسه [$itemId] در زمان {$item['scheduled_time']} برای کانال {$item['channel_id']} لغو شد.", $mainMenu);
                    sendNotification($userId, "فوروارد زمان‌دار شما با شناسه [$itemId] لغو شد.");
                }
            }
            if (!$item) {
                sendMessage($chatId, "⚠️ مورد مورد نظر یافت نشد یا متعلق به شما نیست.", $mainMenu);
            }
            $stmt = $pdo->prepare("UPDATE users SET action = NULL WHERE user_id = ?");
            $stmt->execute([$userId]);
        } catch (PDOException $e) {
            logMessage("خطا در دیتابیس هنگام لغو $itemType زمان‌دار: " . $e->getMessage());
            sendMessage($chatId, "⚠️ خطا در دیتابیس! لطفاً دوباره تلاش کنید.");
            sendNotification($userId, "خطایی در لغو $itemType زمان‌دار رخ داد.");
        }
    } else {
        sendMessage($chatId, "به منوی اصلی بازگشتید.", $mainMenu);
        $stmt = $pdo->prepare("UPDATE users SET action = NULL WHERE user_id = ?");
        $stmt->execute([$userId]);
    }
} elseif ($text === 'ارسال فوروارد زمان‌دار') {
    if (empty($channels)) {
        sendMessage($chatId, "⚠️ شما هیچ کانالی متصل نکرده‌اید.", $mainMenu);
        sendNotification($userId, "برای ارسال فوروارد زمان‌دار، لطفاً یک کانال متصل کنید.");
    } else {
        try {
            $stmt = $pdo->prepare("UPDATE users SET action = ? WHERE user_id = ?");
            $stmt->execute(['select_channel_forward', $userId]);
            $keyboard = array_map(function($channel) { return [['text' => $channel]]; }, $channels);
            $keyboard[] = [['text' => 'ارسال به همه کانال‌ها'], ['text' => 'بازگشت']];
            sendMessage($chatId, "لطفاً کانال مورد نظر را انتخاب کنید:", ['keyboard' => $keyboard, 'resize_keyboard' => true]);
        } catch (PDOException $e) {
            logMessage("خطا در دیتابیس هنگام تنظیم action برای ارسال فوروارد زمان‌دار: " . $e->getMessage());
            sendMessage($chatId, "⚠️ خطا در دیتابیس! لطفاً دوباره تلاش کنید.");
        }
    }
} elseif ($action === 'select_channel_forward') {
    if (in_array($text, $channels) || $text === 'ارسال به همه کانال‌ها') {
        try {
            $stmt = $pdo->prepare("UPDATE users SET action = ?, selected_channel = ? WHERE user_id = ?");
            $stmt->execute(['schedule_forward', $text, $userId]);
            sendMessage($chatId, "لطفاً تاریخ و زمان فوروارد را به فرمت `YYYY-MM-DD HH:MM` وارد کنید (مثال: 2025-03-22 15:00):", [
                'keyboard' => [[['text' => 'بازگشت']]],
                'resize_keyboard' => true
            ]);
        } catch (PDOException $e) {
            logMessage("خطا در دیتابیس هنگام تنظیم زمان فوروارد: " . $e->getMessage());
            sendMessage($chatId, "⚠️ خطا در دیتابیس! لطفاً دوباره تلاش کنید.");
        }
    }
} elseif ($action === 'schedule_forward') {
    if (validateDateTime($text, 'Y-m-d H:i')) {
        $dt = DateTime::createFromFormat('Y-m-d H:i', $text);
        $current_time = new DateTime();
        if ($dt <= $current_time) {
            sendMessage($chatId, "⚠️ زمان واردشده گذشته است!", [
                'keyboard' => [[['text' => 'بازگشت']]],
                'resize_keyboard' => true
            ]);
        } else {
            try {
                $stmt = $pdo->prepare("UPDATE users SET action = ?, scheduled_time = ? WHERE user_id = ?");
                $stmt->execute(['forward_content', $text, $userId]);
                sendMessage($chatId, "لطفاً پیامی که می‌خواهید فوروارد شود را فوروارد کنید:", [
                    'keyboard' => [[['text' => 'بازگشت']]],
                    'resize_keyboard' => true
                ]);
            } catch (PDOException $e) {
                logMessage("خطا در دیتابیس هنگام تنظیم زمان‌بندی فوروارد: " . $e->getMessage());
                sendMessage($chatId, "⚠️ خطا در دیتابیس! لطفاً دوباره تلاش کنید.");
            }
        }
    } else {
        sendMessage($chatId, "⚠️ فرمت نادرست است! مثال: 2025-03-22 15:00", [
            'keyboard' => [[['text' => 'بازگشت']]],
            'resize_keyboard' => true
        ]);
    }
} elseif ($action === 'forward_content') {
    if (isset($update['message']['forward_from']) || isset($update['message']['forward_from_chat'])) {
        $fromChatId = $update['message']['forward_from_chat']['id'] ?? $update['message']['forward_from']['id'];
        $messageId = $update['message']['message_id'];
        try {
            $stmt = $pdo->prepare("SELECT selected_channel, scheduled_time FROM users WHERE user_id = ?");
            $stmt->execute([$userId]);
            $userData = $stmt->fetch(PDO::FETCH_ASSOC);
            $selected_channel = $userData['selected_channel'];
            $scheduled_time = $userData['scheduled_time'];
            $channels_to_forward = ($selected_channel === 'ارسال به همه کانال‌ها') ? $channels : [$selected_channel];

            foreach ($channels_to_forward as $channel) {
                try {
                    $stmt = $pdo->prepare("INSERT INTO scheduled_forwards (user_id, channel_id, from_chat_id, message_id, scheduled_time, is_sent) VALUES (?, ?, ?, ?, ?, ?)");
$stmt->execute([$userId, $channel, $fromChatId, $messageId, $scheduled_time, 0]);
                    logMessage("فوروارد زمان‌دار ذخیره شد: UserID: $userId, Channel: $channel, FromChatID: $fromChatId, MessageID: $messageId, Time: $scheduled_time");
                } catch (PDOException $e) {
                    logMessage("خطا در دیتابیس هنگام ذخیره فوروارد زمان‌دار: " . $e->getMessage());
                    sendMessage($chatId, "⚠️ خطا در ذخیره فوروارد زمان‌دار! لطفاً دوباره تلاش کنید.");
                    sendNotification($userId, "خطایی در زمان‌بندی فوروارد برای $channel رخ داد.");
                    return;
                }
            }
            $channel_text = ($selected_channel === 'ارسال به همه کانال‌ها') ? 'همه کانال‌ها' : $selected_channel;
            sendMessage($chatId, "✅ فوروارد شما برای ارسال در $scheduled_time به $channel_text برنامه‌ریزی شد.", $mainMenu);
            sendNotification($userId, "فوروارد شما برای $channel_text در $scheduled_time زمان‌بندی شد.");
            $stmt = $pdo->prepare("UPDATE users SET action = NULL WHERE user_id = ?");
            $stmt->execute([$userId]);
        } catch (PDOException $e) {
            logMessage("خطا در دیتابیس هنگام پردازش forward_content: " . $e->getMessage());
            sendMessage($chatId, "⚠️ خطا در دیتابیس هنگام پردازش فوروارد!");
            sendNotification($userId, "خطایی در پردازش فوروارد رخ داد.");
        }
    } else {
        sendMessage($chatId, "⚠️ لطفاً یک پیام را فوروارد کنید!", [
            'keyboard' => [[['text' => 'بازگشت']]],
            'resize_keyboard' => true
        ]);
    }
} elseif ($text === 'ابزارهای هوشمند') {
    if (empty($channels)) {
        sendMessage($chatId, "⚠️ شما هیچ کانالی متصل نکرده‌اید.", $mainMenu);
        sendNotification($userId, "برای استفاده از ابزارهای هوشمند، لطفاً حداقل یک کانال متصل کنید.");
    } else {
        try {
            $stmt = $pdo->prepare("UPDATE users SET action = ? WHERE user_id = ?");
            $stmt->execute(['smart_tools', $userId]);
            sendMessage($chatId, "لطفاً یکی از ابزارهای هوشمند را انتخاب کنید:", $smartToolsMenu);
        } catch (PDOException $e) {
            logMessage("خطا در دیتابیس هنگام تنظیم action برای ابزارهای هوشمند: " . $e->getMessage());
            sendMessage($chatId, "⚠️ خطا در دیتابیس! لطفاً دوباره تلاش کنید.");
        }
    }
} elseif ($action === 'smart_tools') {
    if ($text === 'تحلیل محتوا') {
        try {
            $stmt = $pdo->prepare("UPDATE users SET action = ? WHERE user_id = ?");
            $stmt->execute(['content_analysis', $userId]);
            sendMessage($chatId, "لطفاً محتوای پست خود را ارسال کنید (متن، عکس، ویدیو و...) تا تحلیل کنم:", [
                'keyboard' => [[['text' => 'بازگشت']]],
                'resize_keyboard' => true
            ]);
        } catch (PDOException $e) {
            logMessage("خطا در دیتابیس هنگام تنظیم تحلیل محتوا: " . $e->getMessage());
            sendMessage($chatId, "⚠️ خطا در دیتابیس! لطفاً دوباره تلاش کنید.");
        }
    } elseif ($text === 'بهترین زمان پست') {
        $message = "⏰ *بهترین زمان برای ارسال پست:*\n\n";
        $optimalTimesPrompt = "برای یه کانال بله، بر اساس رفتار عمومی کاربرا، ۳ زمان بهینه برای پست‌گذاری پیشنهاد بده (فرمت: HH:MM)";
        $optimalTimesAI = callAvalAI($optimalTimesPrompt);
        $optimalTimes = preg_match_all('/(\d{2}:\d{2})/', $optimalTimesAI, $matches) ? $matches[0] : ['12:00', '20:00', '22:00'];
        foreach ($channels as $channel) {
            $stmt = $pdo->prepare("SELECT scheduled_time FROM scheduled_posts WHERE channel_id = ? AND scheduled_time >= ? ORDER BY scheduled_time DESC LIMIT 5");
            $stmt->execute([$channel, date('Y-m-d H:i', strtotime('-7 days'))]);
            $posts = $stmt->fetchAll(PDO::FETCH_ASSOC);
            if ($posts) {
                $hours = array_map(function($post) {
                    return date('H:i', strtotime($post['scheduled_time']));
                }, $posts);
                $mostFrequentHour = array_search(max(array_count_values($hours)), array_count_values($hours));
                $message .= "کانال $channel: ساعت $mostFrequentHour (بر اساس فعالیت اخیر)\n";
            } else {
                $randomOptimal = $optimalTimes[array_rand($optimalTimes)];
                $message .= "کانال $channel: ساعت $randomOptimal (پیشنهاد هوش مصنوعی)\n";
            }
        }
        sendMessage($chatId, $message, $smartToolsMenu);
    } elseif ($text === 'تقویم محتوایی') {
        try {
            $stmt = $pdo->prepare("UPDATE users SET action = ? WHERE user_id = ?");
            $stmt->execute(['content_calendar', $userId]);
            sendMessage($chatId, "لطفاً موضوع یا دسته‌بندی کانال خود را وارد کنید (مثال: آموزشی، طنز، خبری):", [
                'keyboard' => [[['text' => 'بازگشت']]],
                'resize_keyboard' => true
            ]);
        } catch (PDOException $e) {
            logMessage("خطا در دیتابیس هنگام تنظیم تقویم محتوایی: " . $e->getMessage());
            sendMessage($chatId, "⚠️ خطا در دیتابیس! لطفاً دوباره تلاش کنید.");
        }
    }
} elseif ($action === 'content_analysis') {
    if ($contentType) {
        $analysisPrompt = "تحلیل کن که این محتوا چقدر برای یه کانال بله جذابه و چه پیشنهادهایی برای بهبودش داری:\n";
        if ($contentType === 'text') {
            $analysisPrompt .= "متن: $content";
        } elseif ($contentType === 'photo' || $contentType === 'video') {
            $analysisPrompt .= "نوع محتوا: $contentType\nکپشن: " . ($caption ?? 'بدون کپشن');
        } else {
            $analysisPrompt .= "نوع محتوا: $contentType\nمحتوا: فایل یا موزیک\nکپشن: " . ($caption ?? 'بدون کپشن');
        }

        $analysis = callAvalAI($analysisPrompt);
        sendMessage($chatId, $analysis, $smartToolsMenu);
        try {
            $stmt = $pdo->prepare("UPDATE users SET action = ? WHERE user_id = ?");
            $stmt->execute(['smart_tools', $userId]);
        } catch (PDOException $e) {
            logMessage("خطا در دیتابیس هنگام بازگشت به ابزارهای هوشمند: " . $e->getMessage());
            sendMessage($chatId, "⚠️ خطا در دیتابیس! لطفاً دوباره تلاش کنید.");
        }
    } else {
        sendMessage($chatId, "⚠️ لطفاً یه محتوا (متن، عکس، ویدیو و...) ارسال کنید!", [
            'keyboard' => [[['text' => 'بازگشت']]],
            'resize_keyboard' => true
        ]);
    }
} elseif ($action === 'content_calendar') {
    if (!empty($text)) {
        $calendarPrompt = "برای یه کانال بله با موضوع '$text'، یه تقویم محتوایی برای ۷ روز آینده پیشنهاد بده. هر روز یه نوع محتوا (مثل پست آموزشی، طنز، نظرسنجی و...) پیشنهاد بده.";
        $calendar = callAvalAI($calendarPrompt);
        if (strpos($calendar, '⚠️') === false) {
            sendMessage($chatId, "📅 *تقویم محتوایی پیشنهادی برای ۷ روز آینده:*\n\n$calendar", $smartToolsMenu);
        } else {
            sendMessage($chatId, "⚠️ خطا در تولید تقویم محتوایی. لطفاً دوباره امتحان کنید.", $smartToolsMenu);
        }
        try {
            $stmt = $pdo->prepare("UPDATE users SET action = ? WHERE user_id = ?");
            $stmt->execute(['smart_tools', $userId]);
        } catch (PDOException $e) {
            logMessage("خطا در دیتابیس هنگام بازگشت به ابزارهای هوشمند: " . $e->getMessage());
            sendMessage($chatId, "⚠️ خطا در دیتابیس! لطفاً دوباره تلاش کنید.");
        }
    } else {
        sendMessage($chatId, "⚠️ لطفاً موضوع یا دسته‌بندی کانال را وارد کنید!", [
            'keyboard' => [[['text' => 'بازگشت']]],
            'resize_keyboard' => true
        ]);
    }
} elseif ($text === 'کمک از هوش مصنوعی') {
    try {
        $stmt = $pdo->prepare("UPDATE users SET action = ? WHERE user_id = ?");
        $stmt->execute(['ai_help', $userId]);
        sendMessage($chatId, "سؤالت رو بپرس:", [
            'keyboard' => [[['text' => 'بازگشت']]],
            'resize_keyboard' => true
        ]);
    } catch (PDOException $e) {
        logMessage("خطا در دیتابیس هنگام تنظیم کمک از هوش مصنوعی: " . $e->getMessage());
        sendMessage($chatId, "⚠️ خطا در دیتابیس! لطفاً دوباره تلاش کنید.");
    }
} elseif ($action === 'ai_help') {
    if (!empty($text)) {
        logMessage("درخواست هوش مصنوعی - UserID: $userId, Text: $text");
        sleep(1);
        $response = callAvalAI($text);
        logMessage("پاسخ هوش مصنوعی: " . $response);
        sendMessage($chatId, $response, [
            'keyboard' => [[['text' => 'بازگشت']]],
            'resize_keyboard' => true
        ]);
    } else {
        sendMessage($chatId, "⚠️ لطفاً سؤال خود را وارد کنید!", [
            'keyboard' => [[['text' => 'بازگشت']]],
            'resize_keyboard' => true
        ]);
    }
} elseif ($text === 'اتصال کانال') {
    if (count($channels) >= 3) {
        sendMessage($chatId, "⚠️ شما حداکثر تعداد کانال‌ها (۳ عدد) را متصل کرده‌اید.", $mainMenu);
    } else {
        try {
            $stmt = $pdo->prepare("UPDATE users SET action = ? WHERE user_id = ?");
            $stmt->execute(['connect_channel', $userId]);
            sendMessage($chatId, "لطفاً آیدی کانال خود را ارسال کنید (مثال: @ChannelName):", [
                'keyboard' => [[['text' => 'بازگشت']]],
                'resize_keyboard' => true
            ]);
        } catch (PDOException $e) {
            logMessage("خطا در دیتابیس هنگام تنظیم action برای اتصال کانال: " . $e->getMessage());
            sendMessage($chatId, "⚠️ خطا در دیتابیس! لطفاً دوباره تلاش کنید.");
        }
    }
} elseif ($action === 'connect_channel') {
    if (preg_match('/^@[a-zA-Z0-9_]+$/', $text)) {
        if (!in_array($text, $channels)) {
            $channels[] = $text;
            try {
                $stmt = $pdo->prepare("UPDATE users SET channels = ?, action = NULL WHERE user_id = ?");
                $stmt->execute([json_encode($channels), $userId]);
                sendMessage($chatId, "✅ کانال *$text* متصل شد.", $mainMenu);
                sendNotification($userId, "کانال $text با موفقیت به ربات متصل شد.");
            } catch (PDOException $e) {
                logMessage("خطا در دیتابیس هنگام اتصال کانال: " . $e->getMessage());
                sendMessage($chatId, "⚠️ خطا در دیتابیس! لطفاً دوباره تلاش کنید.");
                sendNotification($userId, "خطایی در اتصال کانال رخ داد.");
            }
        } else {
            sendMessage($chatId, "⚠️ این کانال قبلاً متصل شده است!", $mainMenu);
        }
    } else {
        sendMessage($chatId, "⚠️ آیدی کانال معتبر نیست.", [
            'keyboard' => [[['text' => 'بازگشت']]],
            'resize_keyboard' => true
        ]);
    }
} elseif ($text === 'حذف کانال') {
    if (empty($channels)) {
        sendMessage($chatId, "⚠️ شما هیچ کانالی متصل نکرده‌اید.", $mainMenu);
    } else {
        try {
            $stmt = $pdo->prepare("UPDATE users SET action = ? WHERE user_id = ?");
            $stmt->execute(['delete_channel', $userId]);
            $keyboard = array_map(function($channel) { return [['text' => $channel]]; }, $channels);
            $keyboard[] = [['text' => 'بازگشت']];
            sendMessage($chatId, "لطفاً کانال مورد نظر را انتخاب کنید:", ['keyboard' => $keyboard, 'resize_keyboard' => true]);
        } catch (PDOException $e) {
            logMessage("خطا در دیتابیس هنگام تنظیم action برای حذف کانال: " . $e->getMessage());
            sendMessage($chatId, "⚠️ خطا در دیتابیس! لطفاً دوباره تلاش کنید.");
        }
    }
} elseif ($action === 'delete_channel') {
    if (in_array($text, $channels)) {
        $index = array_search($text, $channels);
        unset($channels[$index]);
        $channels = array_values($channels);
        try {
            $stmt = $pdo->prepare("UPDATE users SET channels = ?, action = NULL WHERE user_id = ?");
            $stmt->execute([json_encode($channels), $userId]);
            sendMessage($chatId, "✅ کانال *$text* حذف شد.", $mainMenu);
            sendNotification($userId, "کانال $text از ربات حذف شد.");
        } catch (PDOException $e) {
            logMessage("خطا در دیتابیس هنگام حذف کانال: " . $e->getMessage());
            sendMessage($chatId, "⚠️ خطا در دیتابیس! لطفاً دوباره تلاش کنید.");
            sendNotification($userId, "خطایی در حذف کانال رخ داد.");
        }
    }
} elseif ($text === 'ارسال پست زمان‌دار') {
    if (empty($channels)) {
        sendMessage($chatId, "⚠️ شما هیچ کانالی متصل نکرده‌اید.", $mainMenu);
        sendNotification($userId, "برای ارسال پست زمان‌دار، لطفاً یک کانال متصل کنید.");
    } else {
        try {
            $stmt = $pdo->prepare("UPDATE users SET action = ? WHERE user_id = ?");
            $stmt->execute(['select_channel', $userId]);
            $keyboard = array_map(function($channel) { return [['text' => $channel]]; }, $channels);
            $keyboard[] = [['text' => 'ارسال به همه کانال‌ها'], ['text' => 'بازگشت']];
            sendMessage($chatId, "لطفاً کانال مورد نظر را انتخاب کنید:", ['keyboard' => $keyboard, 'resize_keyboard' => true]);
        } catch (PDOException $e) {
            logMessage("خطا در دیتابیس هنگام تنظیم action برای ارسال پست زمان‌دار: " . $e->getMessage());
            sendMessage($chatId, "⚠️ خطا در دیتابیس! لطفاً دوباره تلاش کنید.");
        }
    }
} elseif ($action === 'select_channel') {
    if (in_array($text, $channels) || $text === 'ارسال به همه کانال‌ها') {
        try {
            $stmt = $pdo->prepare("UPDATE users SET action = ?, selected_channel = ? WHERE user_id = ?");
            $stmt->execute(['schedule_post', $text, $userId]);
            sendMessage($chatId, "لطفاً تاریخ و زمان ارسال را به فرمت `YYYY-MM-DD HH:MM` وارد کنید (مثال: 2025-03-22 15:00):", [
                'keyboard' => [[['text' => 'بازگشت']]],
                'resize_keyboard' => true
            ]);
        } catch (PDOException $e) {
            logMessage("خطا در دیتابیس هنگام تنظیم زمان ارسال: " . $e->getMessage());
            sendMessage($chatId, "⚠️ خطا در دیتابیس! لطفاً دوباره تلاش کنید.");
        }
    }
} elseif ($action === 'schedule_post') {
    if (validateDateTime($text, 'Y-m-d H:i')) {
        $dt = DateTime::createFromFormat('Y-m-d H:i', $text);
        $current_time = new DateTime();
        if ($dt <= $current_time) {
            sendMessage($chatId, "⚠️ زمان واردشده گذشته است!", [
                'keyboard' => [[['text' => 'بازگشت']]],
                'resize_keyboard' => true
            ]);
        } else {
            try {
                $stmt = $pdo->prepare("UPDATE users SET action = ?, scheduled_time = ? WHERE user_id = ?");
                $stmt->execute(['post_content', $text, $userId]);
                sendMessage($chatId, "لطفاً محتوای پست را ارسال کنید (عکس، ویدیو، فایل، متن یا موزیک):", [
                    'keyboard' => [[['text' => 'بازگشت']]],
                    'resize_keyboard' => true
                ]);
            } catch (PDOException $e) {
                logMessage("خطا در دیتابیس هنگام تنظیم زمان‌بندی: " . $e->getMessage());
                sendMessage($chatId, "⚠️ خطا در دیتابیس! لطفاً دوباره تلاش کنید.");
            }
        }
    } else {
        sendMessage($chatId, "⚠️ فرمت نادرست است! مثال: 2025-03-22 15:00", [
            'keyboard' => [[['text' => 'بازگشت']]],
            'resize_keyboard' => true
        ]);
    }
} elseif ($action === 'post_content') {
    if ($contentType && in_array($contentType, ['text', 'photo', 'video', 'document', 'audio'])) {
        if (!validateContent($contentType, $content, $caption)) {
            sendMessage($chatId, "⚠️ محتوا نامعتبر است! لطفاً دوباره امتحان کنید.", [
                'keyboard' => [[['text' => 'بازگشت']]],
                'resize_keyboard' => true
            ]);
            return;
        }

        try {
            $stmt = $pdo->prepare("SELECT selected_channel, scheduled_time FROM users WHERE user_id = ?");
            $stmt->execute([$userId]);
            $userData = $stmt->fetch(PDO::FETCH_ASSOC);
            $selected_channel = $userData['selected_channel'];
            $scheduled_time = $userData['scheduled_time'];
            $channels_to_post = ($selected_channel === 'ارسال به همه کانال‌ها') ? $channels : [$selected_channel];

            foreach ($channels_to_post as $channel) {
                try {
                    $stmt = $pdo->prepare("INSERT INTO scheduled_posts (user_id, channel_id, content_type, content, caption, scheduled_time, is_sent) VALUES (?, ?, ?, ?, ?, ?, ?)");
$stmt->execute([$userId, $channel, $contentType, $content, $caption, $scheduled_time, 0]);
                    logMessage("پست زمان‌دار ذخیره شد: UserID: $userId, Channel: $channel, Type: $contentType, Time: $scheduled_time");
                } catch (PDOException $e) {
                    logMessage("خطا در دیتابیس هنگام ذخیره پست زمان‌دار: " . $e->getMessage());
                    sendMessage($chatId, "⚠️ خطا در ذخیره پست زمان‌دار! لطفاً دوباره تلاش کنید.");
                    sendNotification($userId, "خطایی در زمان‌بندی پست برای $channel رخ داد.");
                    return;
                }
            }
            $channel_text = ($selected_channel === 'ارسال به همه کانال‌ها') ? 'همه کانال‌ها' : $selected_channel;
            sendMessage($chatId, "✅ پست شما برای ارسال در $scheduled_time به $channel_text برنامه‌ریزی شد.", $mainMenu);
            sendNotification($userId, "پست شما برای $channel_text در $scheduled_time زمان‌بندی شد.");
            $stmt = $pdo->prepare("UPDATE users SET action = NULL WHERE user_id = ?");
            $stmt->execute([$userId]);
        } catch (PDOException $e) {
            logMessage("خطا در دیتابیس هنگام پردازش post_content: " . $e->getMessage());
            sendMessage($chatId, "⚠️ خطا در دیتابیس هنگام پردازش محتوا!");
            sendNotification($userId, "خطایی در پردازش محتوای پست رخ داد.");
        }
    } else {
        sendMessage($chatId, "⚠️ لطفاً یک نوع محتوا معتبر (عکس، ویدیو، فایل، متن یا موزیک) ارسال کنید!", [
            'keyboard' => [[['text' => 'بازگشت']]],
            'resize_keyboard' => true
        ]);
    }
} elseif ($text === 'ساخت دکمه شیشه‌ای') {
    if (empty($channels)) {
        sendMessage($chatId, "⚠️ شما هیچ کانالی متصل نکرده‌اید.", $mainMenu);
        sendNotification($userId, "برای ساخت دکمه شیشه‌ای، لطفاً یک کانال متصل کنید.");
    } else {
        try {
            $stmt = $pdo->prepare("UPDATE users SET action = ?, glass_buttons = ? WHERE user_id = ?");
            $stmt->execute(['glass_button_content', json_encode([]), $userId]);
            sendMessage($chatId, "لطفاً محتوای پست خود را ارسال کنید (عکس، ویدیو، فایل، متن یا موزیک):", [
                'keyboard' => [[['text' => 'بازگشت']]],
                'resize_keyboard' => true
            ]);
        } catch (PDOException $e) {
            logMessage("خطا در دیتابیس هنگام تنظیم action برای دکمه شیشه‌ای: " . $e->getMessage());
            sendMessage($chatId, "⚠️ خطا در دیتابیس! لطفاً دوباره تلاش کنید.");
        }
    }
} elseif ($action === 'glass_button_content') {
    if ($contentType) {
        try {
            $stmt = $pdo->prepare("UPDATE users SET action = ?, post_content_type = ?, post_content = ?, post_caption = ? WHERE user_id = ?");
            $stmt->execute(['glass_button_title', $contentType, $content, $caption, $userId]);
            sendMessage($chatId, "لطفاً عنوان دکمه شیشه‌ای اول را وارد کنید:\nبرای اتمام، 'پایان' را بزنید.", [
                'keyboard' => [[['text' => 'پایان']], [['text' => 'بازگشت']]],
                'resize_keyboard' => true
            ]);
        } catch (PDOException $e) {
            logMessage("خطا در دیتابیس هنگام تنظیم دکمه شیشه‌ای: " . $e->getMessage());
            sendMessage($chatId, "⚠️ خطا در دیتابیس! لطفاً دوباره تلاش کنید.");
        }
    } else {
        sendMessage($chatId, "⚠️ لطفاً یک نوع محتوا (عکس، ویدیو، فایل، متن یا موزیک) ارسال کنید!", [
            'keyboard' => [[['text' => 'بازگشت']]],
            'resize_keyboard' => true
        ]);
    }
} elseif ($action === 'glass_button_title') {
    try {
        $stmt = $pdo->prepare("SELECT glass_buttons FROM users WHERE user_id = ?");
        $stmt->execute([$userId]);
        $glass_buttons = json_decode($stmt->fetchColumn(), true) ?? [];

        if ($text === 'پایان') {
            if (empty($glass_buttons)) {
                sendMessage($chatId, "⚠️ حداقل یک دکمه اضافه کنید!", [
                    'keyboard' => [[['text' => 'بازگشت']]],
                    'resize_keyboard' => true
                ]);
            } else {
                $stmt = $pdo->prepare("UPDATE users SET action = ? WHERE user_id = ?");
                $stmt->execute(['glass_button_select_channel', $userId]);
                $keyboard = array_map(function($channel) { return [['text' => $channel]]; }, $channels);
                $keyboard[] = [['text' => 'ارسال به همه کانال‌ها'], ['text' => 'پیش‌نمایش'], ['text' => 'بازگشت']];
                sendMessage($chatId, "دکمه‌ها با موفقیت ساخته شدند! لطفاً کانال مورد نظر را انتخاب کنید:", ['keyboard' => $keyboard, 'resize_keyboard' => true]);
            }
        } elseif (count($glass_buttons) < 10) {
            $stmt = $pdo->prepare("UPDATE users SET action = ?, current_glass_button_title = ? WHERE user_id = ?");
            $stmt->execute(['glass_button_link', $text, $userId]);
            sendMessage($chatId, "لطفاً لینک دکمه را وارد کنید:", [
                'keyboard' => [[['text' => 'بازگشت']]],
                'resize_keyboard' => true
            ]);
        } else {
            sendMessage($chatId, "⚠️ حداکثر 10 دکمه مجاز است!", [
                'keyboard' => [[['text' => 'بازگشت']]],
                'resize_keyboard' => true
            ]);
        }
    } catch (PDOException $e) {
        logMessage("خطا در دیتابیس هنگام پردازش دکمه شیشه‌ای: " . $e->getMessage());
        sendMessage($chatId, "⚠️ خطا در دیتابیس! لطفاً دوباره تلاش کنید.");
    }
} elseif ($action === 'glass_button_link') {
    if (filter_var($text, FILTER_VALIDATE_URL)) {
        try {
            $stmt = $pdo->prepare("SELECT glass_buttons, current_glass_button_title FROM users WHERE user_id = ?");
            $stmt->execute([$userId]);
            $data = $stmt->fetch(PDO::FETCH_ASSOC);
            $glass_buttons = json_decode($data['glass_buttons'], true) ?? [];
            $glass_buttons[] = [
                'title' => $data['current_glass_button_title'],
                'link' => $text
            ];
            $stmt = $pdo->prepare("UPDATE users SET action = ?, glass_buttons = ? WHERE user_id = ?");
            $stmt->execute(['glass_button_title', json_encode($glass_buttons), $userId]);
            sendMessage($chatId, "عنوان دکمه بعدی را وارد کنید یا 'پایان' را بزنید:", [
                'keyboard' => [[['text' => 'پایان']], [['text' => 'بازگشت']]],
                'resize_keyboard' => true
            ]);
        } catch (PDOException $e) {
            logMessage("خطا در دیتابیس هنگام افزودن لینک دکمه شیشه‌ای: " . $e->getMessage());
            sendMessage($chatId, "⚠️ خطا در دیتابیس! لطفاً دوباره تلاش کنید.");
        }
    } else {
        sendMessage($chatId, "⚠️ لینک نامعتبر است!", [
            'keyboard' => [[['text' => 'بازگشت']]],
            'resize_keyboard' => true
        ]);
    }
} elseif ($action === 'glass_button_select_channel') {
    if ($text === 'پیش‌نمایش') {
        try {
            $stmt = $pdo->prepare("SELECT glass_buttons, post_content_type, post_content, post_caption FROM users WHERE user_id = ?");
            $stmt->execute([$userId]);
            $data = $stmt->fetch(PDO::FETCH_ASSOC);
            $glass_buttons = json_decode($data['glass_buttons'], true) ?? [];
            $inlineKeyboard = ['inline_keyboard' => array_map(function($button) {
                return [['text' => $button['title'], 'url' => $button['link']]];
            }, $glass_buttons)];

            $testResponse = sendContentToChannel($chatId, $data['post_content_type'], $data['post_content'], $data['post_caption'], $inlineKeyboard);
            if ($testResponse) {
                logMessage("پیش‌نمایش پست با دکمه شیشه‌ای موفق: UserID: $userId");
                $keyboard = array_map(function($channel) { return [['text' => $channel]]; }, $channels);
                $keyboard[] = [['text' => 'ارسال به همه کانال‌ها'], ['text' => 'پیش‌نمایش'], ['text' => 'بازگشت']];
                sendMessage($chatId, "این پیش‌نمایش پست شماست. برای ارسال، کانال مورد نظر را انتخاب کنید:", ['keyboard' => $keyboard, 'resize_keyboard' => true]);
            } else {
                logMessage("پیش‌نمایش پست با دکمه شیشه‌ای ناموفق: UserID: $userId");
                sendMessage($chatId, "⚠️ خطا در ساخت پیش‌نمایش!", [
                    'keyboard' => [[['text' => 'بازگشت']]],
                    'resize_keyboard' => true
                ]);
            }
        } catch (PDOException $e) {
            logMessage("خطا در دیتابیس هنگام پیش‌نمایش دکمه شیشه‌ای: " . $e->getMessage());
            sendMessage($chatId, "⚠️ خطا در دیتابیس! لطفاً دوباره تلاش کنید.");
        }
    } elseif (in_array($text, $channels) || $text === 'ارسال به همه کانال‌ها') {
        try {
            $stmt = $pdo->prepare("SELECT glass_buttons, post_content_type, post_content, post_caption FROM users WHERE user_id = ?");
            $stmt->execute([$userId]);
            $data = $stmt->fetch(PDO::FETCH_ASSOC);
            $glass_buttons = json_decode($data['glass_buttons'], true) ?? [];
            $inlineKeyboard = ['inline_keyboard' => array_map(function($button) {
                return [['text' => $button['title'], 'url' => $button['link']]];
            }, $glass_buttons)];

            $channels_to_post = ($text === 'ارسال به همه کانال‌ها') ? $channels : [$text];
            foreach ($channels_to_post as $channel) {
                $response = sendContentToChannel($channel, $data['post_content_type'], $data['post_content'], $data['post_caption'], $inlineKeyboard);
                if ($response) {
                    logMessage("ارسال با دکمه شیشه‌ای موفق: UserID: $userId, Channel: $channel");
                    sendMessage($chatId, "✅ پست با دکمه‌های شیشه‌ای به $channel ارسال شد.");
                    sendNotification($userId, "پست شما با دکمه‌های شیشه‌ای به $channel ارسال شد.");
                } else {
                    logMessage("ارسال با دکمه شیشه‌ای ناموفق: UserID: $userId, Channel: $channel");
                    sendMessage($chatId, "⚠️ خطا در ارسال به $channel - لطفاً لاگ را چک کنید.");
                    sendNotification($userId, "خطایی در ارسال پست به $channel رخ داد.");
                }
            }
            $stmt = $pdo->prepare("UPDATE users SET action = NULL WHERE user_id = ?");
            $stmt->execute([$userId]);
            sendMessage($chatId, "به منوی اصلی بازگشتید.", $mainMenu);
        } catch (PDOException $e) {
            logMessage("خطا در دیتابیس هنگام ارسال دکمه شیشه‌ای: " . $e->getMessage());
            sendMessage($chatId, "⚠️ خطا در دیتابیس! لطفاً دوباره تلاش کنید.");
        }
    }
} elseif ($text === 'ارسال نظرسنجی') {
    if (empty($channels)) {
        sendMessage($chatId, "⚠️ شما هیچ کانالی متصل نکرده‌اید.", $mainMenu);
        sendNotification($userId, "برای ارسال نظرسنجی، لطفاً یک کانال متصل کنید.");
    } else {
        try {
            $stmt = $pdo->prepare("UPDATE users SET action = ?, poll_options = ? WHERE user_id = ?");
            $stmt->execute(['poll_select_channel', json_encode([]), $userId]);
            $keyboard = array_map(function($channel) { return [['text' => $channel]]; }, $channels);
            $keyboard[] = [['text' => 'ارسال به همه کانال‌ها'], ['text' => 'بازگشت']];
            sendMessage($chatId, "لطفاً کانال مورد نظر را انتخاب کنید:", ['keyboard' => $keyboard, 'resize_keyboard' => true]);
        } catch (PDOException $e) {
            logMessage("خطا در دیتابیس هنگام تنظیم نظرسنجی: " . $e->getMessage());
            sendMessage($chatId, "⚠️ خطا در دیتابیس! لطفاً دوباره تلاش کنید.");
        }
    }
} elseif ($action === 'poll_select_channel') {
    if (in_array($text, $channels) || $text === 'ارسال به همه کانال‌ها') {
        try {
            $stmt = $pdo->prepare("UPDATE users SET action = ?, selected_channel = ? WHERE user_id = ?");
            $stmt->execute(['poll_question', $text, $userId]);
            sendMessage($chatId, "لطفاً سوال نظرسنجی را وارد کنید:", [
                'keyboard' => [[['text' => 'بازگشت']]],
                'resize_keyboard' => true
            ]);
        } catch (PDOException $e) {
            logMessage("خطا در دیتابیس هنگام تنظیم سوال نظرسنجی: " . $e->getMessage());
            sendMessage($chatId, "⚠️ خطا در دیتابیس! لطفاً دوباره تلاش کنید.");
        }
    }
} elseif ($action === 'poll_question') {
    if (!empty($text)) {
        try {
            $stmt = $pdo->prepare("UPDATE users SET action = ?, poll_question = ? WHERE user_id = ?");
            $stmt->execute(['poll_options', $text, $userId]);
            sendMessage($chatId, "لطفاً اولین گزینه نظرسنجی را وارد کنید (حداکثر 10 گزینه). برای اتمام، 'پایان' را بزنید:", [
                'keyboard' => [[['text' => 'پایان']], [['text' => 'بازگشت']]],
                'resize_keyboard' => true
            ]);
        } catch (PDOException $e) {
            logMessage("خطا در دیتابیس هنگام تنظیم گزینه‌های نظرسنجی: " . $e->getMessage());
            sendMessage($chatId, "⚠️ خطا در دیتابیس! لطفاً دوباره تلاش کنید.");
        }
    } else {
        sendMessage($chatId, "⚠️ لطفاً سوال را وارد کنید!", [
            'keyboard' => [[['text' => 'بازگشت']]],
            'resize_keyboard' => true
        ]);
    }
} elseif ($action === 'poll_options') {
    try {
        $stmt = $pdo->prepare("SELECT poll_options, selected_channel, poll_question FROM users WHERE user_id = ?");
        $stmt->execute([$userId]);
        $data = $stmt->fetch();
        $pollOptions = json_decode($data['poll_options'], true) ?? [];
        $selectedChannel = $data['selected_channel'];
        $pollQuestion = $data['poll_question'];

        if ($text === 'پایان') {
            if (count($pollOptions) < 2) {
                sendMessage($chatId, "⚠️ حداقل 2 گزینه لازم است!", [
                    'keyboard' => [[['text' => 'بازگشت']]],
                    'resize_keyboard' => true
                ]);
            } else {
                $channelsToPost = ($selectedChannel === 'ارسال به همه کانال‌ها') ? $channels : [$selectedChannel];
                foreach ($channelsToPost as $channel) {
                    $response = sendPoll($channel, $pollQuestion, $pollOptions);
                    if ($response) {
                        sendMessage($chatId, "✅ نظرسنجی به $channel ارسال شد.", $mainMenu);
                        sendNotification($userId, "نظرسنجی شما به $channel ارسال شد.");
                    } else {
                        sendMessage($chatId, "⚠️ خطا در ارسال نظرسنجی به $channel!", $mainMenu);
                        sendNotification($userId, "خطایی در ارسال نظرسنجی به $channel رخ داد.");
                    }
                }
                $stmt = $pdo->prepare("UPDATE users SET action = NULL, poll_options = NULL, poll_question = NULL WHERE user_id = ?");
                $stmt->execute([$userId]);
            }
        } elseif (count($pollOptions) < 10) {
            $pollOptions[] = $text;
            $stmt = $pdo->prepare("UPDATE users SET poll_options = ? WHERE user_id = ?");
            $stmt->execute([json_encode($pollOptions), $userId]);
            sendMessage($chatId, "گزینه بعدی را وارد کنید یا 'پایان' را بزنید:", [
                'keyboard' => [[['text' => 'پایان']], [['text' => 'بازگشت']]],
                'resize_keyboard' => true
            ]);
        } else {
            sendMessage($chatId, "⚠️ حداکثر 10 گزینه مجاز است!", [
                'keyboard' => [[['text' => 'پایان']], [['text' => 'بازگشت']]],
                'resize_keyboard' => true
            ]);
        }
    } catch (PDOException $e) {
        logMessage("خطا در دیتابیس هنگام پردازش نظرسنجی: " . $e->getMessage());
        sendMessage($chatId, "⚠️ خطا در دیتابیس! لطفاً دوباره تلاش کنید.");
    }
} elseif ($text === 'پشتیبانی') {
    try {
        $stmt = $pdo->prepare("UPDATE users SET action = ? WHERE user_id = ?");
        $stmt->execute(['support', $userId]);
        sendMessage($chatId, "لطفاً پیام خود را ارسال کنید. برای اتمام، 'اتمام مکالمه' را بزنید.", [
            'keyboard' => [[['text' => 'اتمام مکالمه']], [['text' => 'بازگشت']]],
            'resize_keyboard' => true
        ]);
    } catch (PDOException $e) {
        logMessage("خطا در دیتابیس هنگام تنظیم پشتیبانی: " . $e->getMessage());
        sendMessage($chatId, "⚠️ خطا در دیتابیس! لطفاً دوباره تلاش کنید.");
    }
} elseif ($action === 'support') {
    if ($text === 'اتمام مکالمه') {
        try {
            $stmt = $pdo->prepare("UPDATE users SET action = NULL WHERE user_id = ?");
            $stmt->execute([$userId]);
            sendMessage($chatId, "مکالمه شما با پشتیبانی به پایان رسید.", $mainMenu);
        } catch (PDOException $e) {
            logMessage("خطا در دیتابیس هنگام اتمام مکالمه پشتیبانی: " . $e->getMessage());
            sendMessage($chatId, "⚠️ خطا در دیتابیس! لطفاً دوباره تلاش کنید.");
        }
    } else {
        $adminChatId = 942443926;
        $userDetails = "پیام جدید از کاربر:\nID: $userId\nUsername: " . ($update['message']['from']['username'] ?? 'N/A') . "\nMessage: $text";
        sendMessage($adminChatId, $userDetails);
        sendMessage($chatId, "پیام شما به پشتیبانی ارسال شد.", [
            'keyboard' => [[['text' => 'اتمام مکالمه']], [['text' => 'بازگشت']]],
            'resize_keyboard' => true
        ]);
    }
} elseif ($text === 'راهنما') {
    $helpMessage = "📌 *راهنمای کامل ربات مدیر کانال*\n\n"
        . "🔹 *ارسال پست زمان‌دار*: پست (متن، عکس، ویدیو و...) رو برای زمان خاص تنظیم کن.\n"
        . "🔹 *ارسال فوروارد زمان‌دار*: هر نوع پیام رو برای زمان خاص فوروارد کن.\n"
        . "🔹 *لغو پست‌ها و فورواردهای زمان‌دار*: پست‌ها و فورواردهای زمان‌دار رو ببین و لغو کن.\n"
        . "🔹 *اتصال کانال*: تا ۳ کانال رو با آیدی متصل کن.\n"
        . "🔹 *حذف کانال*: کانال رو از لیست حذف کن.\n"
        . "🔹 *ساخت دکمه شیشه‌ای*: دکمه‌های لینک‌دار برای پست‌هات بساز.\n"
        . "🔹 *ارسال نظرسنجی*: نظرسنجی به کانال‌هات بفرست.\n"
        . "🔹 *پشتیبانی*: سؤال یا مشکل داری؟ پیام بفرست.\n"
        . "🔹 *مدیریت*: آمار ببین یا پیام گروهی بفرست (فقط ادمین).\n"
        . "🔹 *ساخت بنر تبادل لیستی*: لیست تبادل کانال بساز.\n"
        . "🔹 *کمک از هوش مصنوعی*: سؤالت رو بپرس و جواب بگیر.\n"
        . "🔹 *ابزارهای هوشمند*: تحلیل محتوا، بهترین زمان پست و تقویم محتوایی.\n\n"
        . "هر سؤالی داشتی، پشتیبانی رو بزن!";
    sendMessage($chatId, $helpMessage, $mainMenu);
} elseif ($text === 'مدیریت' && isAdmin($userId)) {
    sendMessage($chatId, "منوی مدیریت:", [
        'keyboard' => [
            [['text' => 'آمار کاربران']],
            [['text' => 'آمار کانال‌ها']],
            [['text' => 'ارسال پیام به کاربران']],
            [['text' => 'ارسال پیام به کانال‌ها']],
            [['text' => 'مدیریت کاربران']],
            [['text' => 'بازگشت']]
        ],
        'resize_keyboard' => true
    ]);
} elseif ($text === 'مدیریت' && !isAdmin($userId)) {
    sendMessage($chatId, "⚠️ شما مدیر نیستید!", $mainMenu);
} elseif ($text === 'آمار کاربران' && isAdmin($userId)) {
    try {
        $stmt = $pdo->query("SELECT COUNT(*) FROM users");
        $count = $stmt->fetchColumn();
        sendMessage($chatId, "تعداد کاربران: " . $count, [
            'keyboard' => [[['text' => 'بازگشت']]],
            'resize_keyboard' => true
        ]);
    } catch (PDOException $e) {
        logMessage("خطا در دیتابیس هنگام گرفتن آمار کاربران: " . $e->getMessage());
        sendMessage($chatId, "⚠️ خطا در دیتابیس! لطفاً دوباره تلاش کنید.");
    }
} elseif ($text === 'آمار کانال‌ها' && isAdmin($userId)) {
    try {
        $stmt = $pdo->query("SELECT channels FROM users");
        $allChannels = [];
        foreach ($stmt->fetchAll(PDO::FETCH_COLUMN) as $channelJson) {
            $channelArray = json_decode($channelJson, true);
            if (is_array($channelArray)) {
                $allChannels = array_merge($allChannels, $channelArray);
            }
        }
        sendMessage($chatId, "تعداد کانال‌ها: " . count(array_unique($allChannels)), [
            'keyboard' => [[['text' => 'بازگشت']]],
            'resize_keyboard' => true
        ]);
    } catch (PDOException $e) {
        logMessage("خطا در دیتابیس هنگام گرفتن آمار کانال‌ها: " . $e->getMessage());
        sendMessage($chatId, "⚠️ خطا در دیتابیس! لطفاً دوباره تلاش کنید.");
    }
} elseif ($text === 'ارسال پیام به کاربران' && isAdmin($userId)) {
    try {
        $stmt = $pdo->prepare("UPDATE users SET action = ? WHERE user_id = ?");
        $stmt->execute(['send_to_users', $userId]);
        sendMessage($chatId, "لطفاً پیام خود را برای ارسال به همه کاربران وارد کنید:", [
            'keyboard' => [[['text' => 'بازگشت']]],
            'resize_keyboard' => true
        ]);
    } catch (PDOException $e) {
        logMessage("خطا در دیتابیس هنگام تنظیم ارسال پیام به کاربران: " . $e->getMessage());
        sendMessage($chatId, "⚠️ خطا در دیتابیس! لطفاً دوباره تلاش کنید.");
    }
} elseif ($action === 'send_to_users' && isAdmin($userId)) {
    try {
        $stmt = $pdo->query("SELECT user_id FROM users");
        foreach ($stmt->fetchAll(PDO::FETCH_COLUMN) as $user) {
            sendContentToChannel($user, $contentType, $content, $caption);
        }
        sendMessage($chatId, "✅ پیام به همه کاربران ارسال شد.", $mainMenu);
        $stmt = $pdo->prepare("UPDATE users SET action = NULL WHERE user_id = ?");
        $stmt->execute([$userId]);
    } catch (PDOException $e) {
        logMessage("خطا در دیتابیس هنگام ارسال پیام به کاربران: " . $e->getMessage());
        sendMessage($chatId, "⚠️ خطا در دیتابیس! لطفاً دوباره تلاش کنید.");
    }
} elseif ($text === 'ارسال پیام به کانال‌ها' && isAdmin($userId)) {
    try {
        $stmt = $pdo->prepare("UPDATE users SET action = ? WHERE user_id = ?");
        $stmt->execute(['send_to_channels', $userId]);
        sendMessage($chatId, "لطفاً پیام خود را برای ارسال به همه کانال‌ها وارد کنید:", [
            'keyboard' => [[['text' => 'بازگشت']]],
            'resize_keyboard' => true
        ]);
    } catch (PDOException $e) {
        logMessage("خطا در دیتابیس هنگام تنظیم ارسال پیام به کانال‌ها: " . $e->getMessage());
        sendMessage($chatId, "⚠️ خطا در دیتابیس! لطفاً دوباره تلاش کنید.");
    }
} elseif ($action === 'send_to_channels' && isAdmin($userId)) {
    try {
        $stmt = $pdo->query("SELECT channels FROM users");
        $allChannels = [];
        foreach ($stmt->fetchAll(PDO::FETCH_COLUMN) as $channelJson) {
            $channelArray = json_decode($channelJson, true);
            if (is_array($channelArray)) {
                $allChannels = array_merge($allChannels, $channelArray);
            }
        }
        $allChannels = array_unique($allChannels);
        foreach ($allChannels as $channel) {
            sendContentToChannel($channel, $contentType, $content, $caption);
        }
        sendMessage($chatId, "✅ پیام به همه کانال‌ها ارسال شد.", $mainMenu);
        $stmt = $pdo->prepare("UPDATE users SET action = NULL WHERE user_id = ?");
        $stmt->execute([$userId]);
    } catch (PDOException $e) {
        logMessage("خطا در دیتابیس هنگام ارسال پیام به کانال‌ها: " . $e->getMessage());
        sendMessage($chatId, "⚠️ خطا در دیتابیس! لطفاً دوباره تلاش کنید.");
    }
} elseif ($text === 'ساخت بنر تبادل لیستی') {
    try {
        $stmt = $pdo->prepare("UPDATE users SET action = ?, exchange_list = ? WHERE user_id = ?");
        $stmt->execute(['exchange_list_title', json_encode([]), $userId]);
        sendMessage($chatId, "لطفاً عنوان اولین کانال را وارد کنید (حداکثر ۳۰):\nبرای پایان، 'پایان' را بزنید.", [
            'keyboard' => [[['text' => 'پایان']], [['text' => 'بازگشت']]],
            'resize_keyboard' => true
        ]);
    } catch (PDOException $e) {
        logMessage("خطا در دیتابیس هنگام تنظیم بنر تبادل لیستی: " . $e->getMessage());
        sendMessage($chatId, "⚠️ خطا در دیتابیس! لطفاً دوباره تلاش کنید.");
    }
} elseif ($action === 'exchange_list_title') {
    try {
        $stmt = $pdo->prepare("SELECT exchange_list FROM users WHERE user_id = ?");
        $stmt->execute([$userId]);
        $exchange_list = json_decode($stmt->fetchColumn(), true) ?? [];

        if ($text === 'پایان') {
            if (empty($exchange_list)) {
                sendMessage($chatId, "⚠️ حداقل یک کانال وارد کنید!", [
                    'keyboard' => [[['text' => 'بازگشت']]],
                    'resize_keyboard' => true
                ]);
            } else {
                $banner = "🌸 *لیست تبادل کانال‌ها* 🌸\n\n";
                foreach ($exchange_list as $channel) {
                    $banner .= "🎁 *عنوان*: [" . $channel['title'] . "](" . $channel['link'] . ")\n";
                }
                $banner .= "\n🌟 * @dastibot ربات دستیار بله*";
                sendMessage($chatId, $banner);
                sendMessage($chatId, "⚠️ برای حفظ بنر، فوروارد کنید!", $mainMenu);
                $stmt = $pdo->prepare("UPDATE users SET action = NULL WHERE user_id = ?");
                $stmt->execute([$userId]);
            }
        } elseif (count($exchange_list) < 30) {
            $stmt = $pdo->prepare("UPDATE users SET action = ?, current_channel_title = ? WHERE user_id = ?");
            $stmt->execute(['exchange_list_link', $text, $userId]);
            sendMessage($chatId, "لطفاً لینک کانال را ارسال کنید:", [
                'keyboard' => [[['text' => 'بازگشت']]],
                'resize_keyboard' => true
            ]);
        } else {
            sendMessage($chatId, "⚠️ حداکثر ۳۰ کانال مجاز است!", [
                'keyboard' => [[['text' => 'بازگشت']]],
                'resize_keyboard' => true
            ]);
        }
    } catch (PDOException $e) {
        logMessage("خطا در دیتابیس هنگام پردازش بنر تبادل لیستی: " . $e->getMessage());
        sendMessage($chatId, "⚠️ خطا در دیتابیس! لطفاً دوباره تلاش کنید.");
    }
} elseif ($action === 'exchange_list_link') {
    if (filter_var($text, FILTER_VALIDATE_URL)) {
        try {
            $stmt = $pdo->prepare("SELECT exchange_list, current_channel_title FROM users WHERE user_id = ?");
            $stmt->execute([$userId]);
            $data = $stmt->fetch(PDO::FETCH_ASSOC);
            $exchange_list = json_decode($data['exchange_list'], true) ?? [];
            $exchange_list[] = [
                'title' => $data['current_channel_title'],
                'link' => $text
            ];
            $stmt = $pdo->prepare("UPDATE users SET action = ?, exchange_list = ? WHERE user_id = ?");
            $stmt->execute(['exchange_list_title', json_encode($exchange_list), $userId]);
            sendMessage($chatId, "عنوان کانال بعدی را وارد کنید یا 'پایان' را بزنید:", [
                'keyboard' => [[['text' => 'پایان']], [['text' => 'بازگشت']]],
                'resize_keyboard' => true
            ]);
        } catch (PDOException $e) {
            logMessage("خطا در دیتابیس هنگام افزودن لینک بنر تبادل: " . $e->getMessage());
            sendMessage($chatId, "⚠️ خطا در دیتابیس! لطفاً دوباره تلاش کنید.");
        }
    } else {
        sendMessage($chatId, "⚠️ لینک نامعتبر است!", [
            'keyboard' => [[['text' => 'بازگشت']]],
            'resize_keyboard' => true
        ]);
    }
} elseif ($text === 'مدیریت کاربران' && isAdmin($userId)) {
    sendMessage($chatId, "منوی مدیریت کاربران:", [
        'keyboard' => [
            [['text' => 'بن کردن کاربر']],
            [['text' => 'آن‌بن کردن کاربر']],
            [['text' => 'لیست کاربران بن‌شده']],
            [['text' => 'بازگشت']]
        ],
        'resize_keyboard' => true
    ]);
} elseif ($text === 'بن کردن کاربر' && isAdmin($userId)) {
    try {
        $stmt = $pdo->prepare("UPDATE users SET action = ? WHERE user_id = ?");
        $stmt->execute(['ban_user', $userId]);
        sendMessage($chatId, "لطفاً آیدی کاربر (User ID) را وارد کنید:", [
            'keyboard' => [[['text' => 'بازگشت']]],
            'resize_keyboard' => true
        ]);
    } catch (PDOException $e) {
        logMessage("خطا در دیتابیس هنگام تنظیم بن کردن کاربر: " . $e->getMessage());
        sendMessage($chatId, "⚠️ خطا در دیتابیس! لطفاً دوباره تلاش کنید.");
    }
} elseif ($action === 'ban_user' && isAdmin($userId)) {
    if (is_numeric($text)) {
        $targetUserId = (int)$text;
        try {
            $stmt = $pdo->prepare("SELECT * FROM users WHERE user_id = ?");
            $stmt->execute([$targetUserId]);
            $targetUser = $stmt->fetch(PDO::FETCH_ASSOC);
            if ($targetUser) {
                if ($targetUser['is_banned'] == 1) {
                    sendMessage($chatId, "⚠️ این کاربر قبلاً بن شده است!", [
                        'keyboard' => [[['text' => 'بازگشت']]],
                        'resize_keyboard' => true
                    ]);
                } else {
                    $stmt = $pdo->prepare("UPDATE users SET is_banned = 1 WHERE user_id = ?");
                    $stmt->execute([$targetUserId]);
                    sendMessage($chatId, "✅ کاربر با آیدی $targetUserId بن شد.", [
                        'keyboard' => [[['text' => 'بازگشت']]],
                        'resize_keyboard' => true
                    ]);
                    sendNotification($targetUserId, "شما توسط مدیر ربات بن شدید و دیگر نمی‌توانید از ربات استفاده کنید.");
                }
            } else {
                sendMessage($chatId, "⚠️ کاربر با این آیدی یافت نشد!", [
                    'keyboard' => [[['text' => 'بازگشت']]],
                    'resize_keyboard' => true
                ]);
            }
            $stmt = $pdo->prepare("UPDATE users SET action = NULL WHERE user_id = ?");
            $stmt->execute([$userId]);
        } catch (PDOException $e) {
            logMessage("خطا در دیتابیس هنگام بن کردن کاربر: " . $e->getMessage());
            sendMessage($chatId, "⚠️ خطا در دیتابیس! لطفاً دوباره تلاش کنید.");
        }
    } else {
        sendMessage($chatId, "⚠️ لطفاً یک آیدی عددی معتبر وارد کنید!", [
            'keyboard' => [[['text' => 'بازگشت']]],
            'resize_keyboard' => true
        ]);
    }
} elseif ($text === 'آن‌بن کردن کاربر' && isAdmin($userId)) {
    try {
        $stmt = $pdo->prepare("UPDATE users SET action = ? WHERE user_id = ?");
        $stmt->execute(['unban_user', $userId]);
        sendMessage($chatId, "لطفاً آیدی کاربر (User ID) را برای آن‌بن وارد کنید:", [
            'keyboard' => [[['text' => 'بازگشت']]],
            'resize_keyboard' => true
        ]);
    } catch (PDOException $e) {
        logMessage("خطا در دیتابیس هنگام تنظیم آن‌بن کردن کاربر: " . $e->getMessage());
        sendMessage($chatId, "⚠️ خطا در دیتابیس! لطفاً دوباره تلاش کنید.");
    }
} elseif ($action === 'unban_user' && isAdmin($userId)) {
    if (is_numeric($text)) {
        $targetUserId = (int)$text;
        try {
            $stmt = $pdo->prepare("SELECT * FROM users WHERE user_id = ?");
            $stmt->execute([$targetUserId]);
            $targetUser = $stmt->fetch(PDO::FETCH_ASSOC);
            if ($targetUser) {
                if ($targetUser['is_banned'] == 0) {
                    sendMessage($chatId, "⚠️ این کاربر بن نیست!", [
                        'keyboard' => [[['text' => 'بازگشت']]],
                        'resize_keyboard' => true
                    ]);
                } else {
                    $stmt = $pdo->prepare("UPDATE users SET is_banned = 0 WHERE user_id = ?");
                    $stmt->execute([$targetUserId]);
                    sendMessage($chatId, "✅ کاربر با آیدی $targetUserId آن‌بن شد.", [
                        'keyboard' => [[['text' => 'بازگشت']]],
                        'resize_keyboard' => true
                    ]);
                    sendNotification($targetUserId, "شما توسط مدیر ربات آن‌بن شدید و می‌توانید دوباره از ربات استفاده کنید.");
                }
            } else {
                sendMessage($chatId, "⚠️ کاربر با این آیدی یافت نشد!", [
                    'keyboard' => [[['text' => 'بازگشت']]],
                    'resize_keyboard' => true
                ]);
            }
            $stmt = $pdo->prepare("UPDATE users SET action = NULL WHERE user_id = ?");
            $stmt->execute([$userId]);
        } catch (PDOException $e) {
            logMessage("خطا در دیتابیس هنگام آن‌بن کردن کاربر: " . $e->getMessage());
            sendMessage($chatId, "⚠️ خطا در دیتابیس! لطفاً دوباره تلاش کنید.");
        }
    } else {
        sendMessage($chatId, "⚠️ لطفاً یک آیدی عددی معتبر وارد کنید!", [
            'keyboard' => [[['text' => 'بازگشت']]],
            'resize_keyboard' => true
        ]);
    }
} elseif ($text === 'لیست کاربران بن‌شده' && isAdmin($userId)) {
    try {
        $stmt = $pdo->prepare("SELECT user_id FROM users WHERE is_banned = 1");
        $stmt->execute();
        $bannedUsers = $stmt->fetchAll(PDO::FETCH_COLUMN);
        if (empty($bannedUsers)) {
            sendMessage($chatId, "هیچ کاربری بن نشده است.", [
                'keyboard' => [[['text' => 'بازگشت']]],
                'resize_keyboard' => true
            ]);
        } else {
            $message = "📋 *لیست کاربران بن‌شده:*\n\n";
            foreach ($bannedUsers as $bannedUser) {
                $message .= "🆔 $bannedUser\n";
            }
            sendMessage($chatId, $message, [
                'keyboard' => [[['text' => 'بازگشت']]],
                'resize_keyboard' => true
            ]);
        }
    } catch (PDOException $e) {
        logMessage("خطا در دیتابیس هنگام نمایش لیست کاربران بن‌شده: " . $e->getMessage());
        sendMessage($chatId, "⚠️ خطا در دیتابیس! لطفاً دوباره تلاش کنید.");
    }
}
elseif ($text === 'گزارش تخلف') {
    try {
        $stmt = $pdo->prepare("UPDATE users SET action = ? WHERE user_id = ?");
        $stmt->execute(['report_violation', $userId]);
        sendMessage($chatId, "لطفاً تخلف رو توضیح بده (مثلاً آیدی کانال یا کاربر متخلف رو بگو):", [
            'keyboard' => [[['text' => 'بازگشت']]],
            'resize_keyboard' => true
        ]);
    } catch (PDOException $e) {
        logMessage("خطا در دیتابیس هنگام تنظیم گزارش تخلف: " . $e->getMessage());
        sendMessage($chatId, "⚠️ خطا در دیتابیس! لطفاً دوباره تلاش کن.");
    }
} elseif ($action === 'report_violation') {
    if (!empty($text)) {
        try {
            $reportCode = 'REP' . time() . rand(100, 999); // کد رهگیری منحصر به فرد
            $stmt = $pdo->prepare("INSERT INTO violation_reports (user_id, report_text, report_code, report_time) VALUES (?, ?, ?, ?)");
            $stmt->execute([$userId, $text, $reportCode, date('Y-m-d H:i:s')]);
            
            $adminChatId = 942443926; // آیدی ادمین
            $reportDetails = "📢 گزارش تخلف جدید:\nکاربر: $userId\nمتن گزارش: $text\nکد رهگیری: $reportCode";
            sendMessage($adminChatId, $reportDetails);
            
            sendMessage($chatId, "✅ گزارشت ثبت شد!\nکد رهگیری: *$reportCode*\nاین کد رو نگه دار تا بعداً پیگیری کنی.", $mainMenu);
            $stmt = $pdo->prepare("UPDATE users SET action = NULL WHERE user_id = ?");
            $stmt->execute([$userId]);
        } catch (PDOException $e) {
            logMessage("خطا در دیتابیس هنگام ثبت گزارش تخلف: " . $e->getMessage());
            sendMessage($chatId, "⚠️ خطا در دیتابیس! لطفاً دوباره تلاش کن.");
        }
    } else {
        sendMessage($chatId, "⚠️ لطفاً توضیح تخلف رو وارد کن!", [
            'keyboard' => [[['text' => 'بازگشت']]],
            'resize_keyboard' => true
        ]);
    }
}
elseif ($text === 'حذف پست زمان‌دار') {
    if (empty($channels)) {
        sendMessage($chatId, "⚠️ شما هیچ کانالی متصل نکرده‌اید.", $mainMenu);
        sendNotification($userId, "برای حذف پست زمان‌دار، لطفاً یه کانال متصل کن.");
    } else {
        try {
            $stmt = $pdo->prepare("UPDATE users SET action = ? WHERE user_id = ?");
            $stmt->execute(['delete_post_select_channel', $userId]);
            $keyboard = array_map(function($channel) { return [['text' => $channel]]; }, $channels);
            $keyboard[] = [['text' => 'بازگشت']];
            sendMessage($chatId, "لطفاً کانالی که می‌خوای پستش حذف بشه رو انتخاب کن:", ['keyboard' => $keyboard, 'resize_keyboard' => true]);
        } catch (PDOException $e) {
            logMessage("خطا در دیتابیس هنگام تنظیم حذف پست زمان‌دار: " . $e->getMessage());
            sendMessage($chatId, "⚠️ خطا در دیتابیس! لطفاً دوباره تلاش کن.");
        }
    }
} elseif ($action === 'delete_post_select_channel') {
    if (in_array($text, $channels)) {
        try {
            $stmt = $pdo->prepare("UPDATE users SET action = ?, selected_channel = ? WHERE user_id = ?");
            $stmt->execute(['delete_post_content', $text, $userId]);
            sendMessage($chatId, "لطفاً پستی که می‌خوای حذف بشه رو فوروارد کن یا آیدی پیامش (Message ID) رو بفرست:", [
                'keyboard' => [[['text' => 'بازگشت']]],
                'resize_keyboard' => true
            ]);
        } catch (PDOException $e) {
            logMessage("خطا در دیتابیس هنگام انتخاب کانال برای حذف: " . $e->getMessage());
            sendMessage($chatId, "⚠️ خطا در دیتابیس! لطفاً دوباره تلاش کن.");
        }
    }
} elseif ($action === 'delete_post_content') {
    $messageId = null;
    if (isset($update['message']['forward_from']) || isset($update['message']['forward_from_chat'])) {
        $messageId = $update['message']['forward_from_message_id'] ?? $update['message']['message_id'];
    } elseif (is_numeric($text)) {
        $messageId = (int)$text;
    }

    if ($messageId) {
        try {
            $stmt = $pdo->prepare("SELECT selected_channel FROM users WHERE user_id = ?");
            $stmt->execute([$userId]);
            $selectedChannel = $stmt->fetchColumn();

            $stmt = $pdo->prepare("UPDATE users SET action = ?, message_id = ? WHERE user_id = ?");
            $stmt->execute(['delete_post_time', $messageId, $userId]);
            sendMessage($chatId, "لطفاً تاریخ و زمان حذف رو به فرمت `YYYY-MM-DD HH:MM` وارد کن (مثال: 2025-03-22 15:00):", [
                'keyboard' => [[['text' => 'بازگشت']]],
                'resize_keyboard' => true
            ]);
        } catch (PDOException $e) {
            logMessage("خطا در دیتابیس هنگام تنظیم پیام برای حذف: " . $e->getMessage());
            sendMessage($chatId, "⚠️ خطا در دیتابیس! لطفاً دوباره تلاش کن.");
        }
    } else {
        sendMessage($chatId, "⚠️ لطفاً یه پست فوروارد کن یا آیدی پیام (عددی) رو وارد کن!", [
            'keyboard' => [[['text' => 'بازگشت']]],
            'resize_keyboard' => true
        ]);
    }
} elseif ($action === 'delete_post_time') {
    if (validateDateTime($text, 'Y-m-d H:i')) {
        $dt = DateTime::createFromFormat('Y-m-d H:i', $text);
        $current_time = new DateTime();
        if ($dt <= $current_time) {
            sendMessage($chatId, "⚠️ زمان واردشده گذشته است!", [
                'keyboard' => [[['text' => 'بازگشت']]],
                'resize_keyboard' => true
            ]);
        } else {
            try {
                $stmt = $pdo->prepare("SELECT selected_channel, message_id FROM users WHERE user_id = ?");
                $stmt->execute([$userId]);
                $data = $stmt->fetch(PDO::FETCH_ASSOC);
                $selectedChannel = $data['selected_channel'];
                $messageId = $data['message_id'];

                $stmt = $pdo->prepare("INSERT INTO scheduled_deletes (user_id, channel_id, message_id, scheduled_time) VALUES (?, ?, ?, ?)");
                $stmt->execute([$userId, $selectedChannel, $messageId, $text]);

                sendMessage($chatId, "✅ حذف پست با آیدی $messageId برای $selectedChannel در زمان $text برنامه‌ریزی شد.", $mainMenu);
                sendNotification($userId, "حذف پست با آیدی $messageId در $selectedChannel برای $text زمان‌بندی شد.");

                $stmt = $pdo->prepare("UPDATE users SET action = NULL, message_id = NULL WHERE user_id = ?");
                $stmt->execute([$userId]);
            } catch (PDOException $e) {
                logMessage("خطا در دیتابیس هنگام زمان‌بندی حذف پست: " . $e->getMessage());
                sendMessage($chatId, "⚠️ خطا در دیتابیس! لطفاً دوباره تلاش کن.");
            }
        }
    } else {
        sendMessage($chatId, "⚠️ فرمت نادرست است! مثال: 2025-03-22 15:00", [
            'keyboard' => [[['text' => 'بازگشت']]],
            'resize_keyboard' => true
        ]);
    }
} elseif ($text === 'لغو حذف پست زمان‌دار') {
    try {
        $stmt = $pdo->prepare("SELECT * FROM scheduled_deletes WHERE user_id = ?");
        $stmt->execute([$userId]);
        $scheduledDeletes = $stmt->fetchAll();

        if (empty($scheduledDeletes)) {
            sendMessage($chatId, "⚠️ هیچ حذف زمان‌داری برای شما ثبت نشده.", $mainMenu);
        } else {
            $stmt = $pdo->prepare("UPDATE users SET action = ? WHERE user_id = ?");
            $stmt->execute(['cancel_scheduled_delete', $userId]);

            $keyboard = [];
            foreach ($scheduledDeletes as $delete) {
                $keyboard[] = [['text' => "[$delete[id]] حذف پیام $delete[message_id] - $delete[scheduled_time] - $delete[channel_id]"]];
            }
            $keyboard[] = [['text' => 'بازگشت']];

            sendMessage($chatId, "📅 *حذف‌های زمان‌دار شما:*\n\nانتخاب کن تا لغو بشه:", ['keyboard' => $keyboard, 'resize_keyboard' => true]);
        }
    } catch (PDOException $e) {
        logMessage("خطا در دیتابیس هنگام نمایش حذف‌های زمان‌دار: " . $e->getMessage());
        sendMessage($chatId, "⚠️ خطا در دیتابیس! لطفاً دوباره تلاش کن.");
    }
} elseif ($action === 'cancel_scheduled_delete') {
    if (preg_match('/^\[(\d+)\]/', $text, $match)) {
        $deleteId = $match[1];
        try {
            $stmt = $pdo->prepare("SELECT * FROM scheduled_deletes WHERE id = ? AND user_id = ?");
            $stmt->execute([$deleteId, $userId]);
            $delete = $stmt->fetch();

            if ($delete) {
                $stmt = $pdo->prepare("DELETE FROM scheduled_deletes WHERE id = ? AND user_id = ?");
                $stmt->execute([$deleteId, $userId]);
                sendMessage($chatId, "✅ حذف زمان‌دار پیام $delete[message_id] در $delete[scheduled_time] برای $delete[channel_id] لغو شد.", $mainMenu);
                sendNotification($userId, "حذف زمان‌دار پیام $delete[message_id] لغو شد.");
            } else {
                sendMessage($chatId, "⚠️ مورد مورد نظر پیدا نشد یا مال شما نیست.", $mainMenu);
            }

            $stmt = $pdo->prepare("UPDATE users SET action = NULL WHERE user_id = ?");
            $stmt->execute([$userId]);
        } catch (PDOException $e) {
            logMessage("خطا در دیتابیس هنگام لغو حذف زمان‌دار: " . $e->getMessage());
            sendMessage($chatId, "⚠️ خطا در دیتابیس! لطفاً دوباره تلاش کن.");
        }
    } else {
        sendMessage($chatId, "به منوی اصلی بازگشتید.", $mainMenu);
        $stmt = $pdo->prepare("UPDATE users SET action = NULL WHERE user_id = ?");
        $stmt->execute([$userId]);
    }
}
logMessage("پایان پردازش درخواست - ChatID: $chatId, UserID: $userId");
exit();
?>