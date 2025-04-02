<?php
// تنظیمات ثابت‌ها
define('API_TOKEN', '1424084686:FujHPYzWPtjgMiHkdT7BVw4e4klifUVyzWzjHnOB');
define('DB_HOST', 'localhost');
define('DB_NAME', 'parselec_channelbot');
define('DB_USER', 'parselec_botuser');
define('DB_PASS', 'K7m#nPq9L$x2');
define('LOG_FILE', 'bot_log.txt');

// تنظیم منطقه زمانی
date_default_timezone_set('Asia/Tehran');

// اتصال به دیتابیس
function getDB() {
    try {
        $pdo = new PDO("mysql:host=" . DB_HOST . ";dbname=" . DB_NAME . ";charset=utf8mb4", DB_USER, DB_PASS);
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
        $pdo->exec("SET NAMES 'utf8mb4'");
        $pdo->exec("SET CHARACTER SET utf8mb4");
        $pdo->exec("SET SESSION collation_connection = 'utf8mb4_unicode_ci'");
        logMessage("اتصال به دیتابیس با موفقیت انجام شد.");
        return $pdo;
    } catch (PDOException $e) {
        logMessage("خطا در اتصال به دیتابیس: " . $e->getMessage());
        die("خطا در اتصال به دیتابیس!");
    }
}

// تابع برای لاگ کردن
function logMessage($message) {
    $log = date('Y-m-d H:i:s') . " - " . $message . "\n";
    file_put_contents(LOG_FILE, $log, FILE_APPEND);
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

// تابع ارسال فوروارد پیام
function forwardMessage($chatId, $fromChatId, $messageId) {
    $url = "https://tapi.bale.ai/bot" . API_TOKEN . "/forwardMessage";
    $data = [
        'chat_id' => $chatId,
        'from_chat_id' => $fromChatId,
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
        $error = curl_error($ch);
        logMessage("خطا در cURL برای فوروارد: $error - ChatID: $chatId, FromChatID: $fromChatId, MessageID: $messageId");
        curl_close($ch);
        return false;
    }

    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    $result = json_decode($response, true);
    logMessage("پاسخ API برای فوروارد: ChatID: $chatId, Response: " . json_encode($result) . ", HTTP Code: $httpCode");

    if ($httpCode == 200 && isset($result['ok']) && $result['ok']) {
        logMessage("فوروارد موفق: ChatID: $chatId, FromChatID: $fromChatId, MessageID: $messageId");
        return true;
    } else {
        logMessage("فوروارد ناموفق: ChatID: $chatId, Error: " . json_encode($result));
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

// اتصال به دیتابیس
$pdo = getDB();

// زمان فعلی و بازه‌ی زمانی برای بررسی (1 دقیقه گذشته تا زمان فعلی)
$currentTime = date('Y-m-d H:i:s');
$timeThreshold = date('Y-m-d H:i:s', strtotime('-1 minute'));

// پاکسازی پست‌ها و فورواردهای قدیمی (بیشتر از 7 روز گذشته)
$cleanupThreshold = date('Y-m-d H:i:s', strtotime('-7 days'));
try {
    $stmt = $pdo->prepare("DELETE FROM scheduled_posts WHERE scheduled_time < ? AND is_sent = 0");
    $stmt->execute([$cleanupThreshold]);
    logMessage("پست‌های قدیمی‌تر از 7 روز پاکسازی شدند.");

    $stmt = $pdo->prepare("DELETE FROM scheduled_forwards WHERE scheduled_time < ? AND is_sent = 0");
    $stmt->execute([$cleanupThreshold]);
    logMessage("فورواردهای قدیمی‌تر از 7 روز پاکسازی شدند.");

    $stmt = $pdo->prepare("DELETE FROM scheduled_deletes WHERE scheduled_time < ?");
    $stmt->execute([$cleanupThreshold]);
    logMessage("حذف‌های قدیمی‌تر از 7 روز پاکسازی شدند.");
} catch (PDOException $e) {
    logMessage("خطا در پاکسازی داده‌های قدیمی: " . $e->getMessage());
}

// بررسی پست‌های زمان‌بندی‌شده
try {
    $stmt = $pdo->prepare("SELECT * FROM scheduled_posts WHERE scheduled_time <= ? AND scheduled_time >= ? AND is_sent = 0");
    $stmt->execute([$currentTime, $timeThreshold]);
    $posts = $stmt->fetchAll(PDO::FETCH_ASSOC);

    if (empty($posts)) {
        logMessage("هیچ پستی برای ارسال در بازه‌ی $timeThreshold تا $currentTime پیدا نشد.");
    } else {
        logMessage("تعداد پست‌های آماده برای ارسال: " . count($posts));
    }

    foreach ($posts as $post) {
        $stmtUser = $pdo->prepare("SELECT is_banned FROM users WHERE user_id = ?");
        $stmtUser->execute([$post['user_id']]);
        $user = $stmtUser->fetch(PDO::FETCH_ASSOC);

        if (!$user) {
            logMessage("کاربر با آیدی {$post['user_id']} یافت نشد - پست حذف می‌شود: " . json_encode($post));
            $stmtDelete = $pdo->prepare("DELETE FROM scheduled_posts WHERE id = ?");
            $stmtDelete->execute([$post['id']]);
            continue;
        }

        if ($user['is_banned'] == 1) {
            logMessage("کاربر بن‌شده است - پست حذف می‌شود: " . json_encode($post));
            $stmtDelete = $pdo->prepare("DELETE FROM scheduled_posts WHERE id = ?");
            $stmtDelete->execute([$post['id']]);
            continue;
        }

        $inlineKeyboard = $post['inline_keyboard'] ? json_decode($post['inline_keyboard'], true) : null;
        $response = sendContentToChannel(
            $post['channel_id'],
            $post['content_type'],
            $post['content'],
            $post['caption'],
            $inlineKeyboard ? ['inline_keyboard' => $inlineKeyboard] : null
        );

        if ($response) {
            $stmtUpdate = $pdo->prepare("UPDATE scheduled_posts SET is_sent = 1 WHERE id = ?");
            $stmtUpdate->execute([$post['id']]);
            sendNotification($post['user_id'], "پست شما به کانال {$post['channel_id']} در {$post['scheduled_time']} ارسال شد.");
            logMessage("پست با موفقیت ارسال شد: " . json_encode($post));
        } else {
            sendNotification($post['user_id'], "خطا در ارسال پست به {$post['channel_id']} در {$post['scheduled_time']} - لطفاً لاگ را چک کنید.");
            logMessage("خطا در ارسال پست: " . json_encode($post));
            continue; // اگه ارسال ناموفق بود، پست رو نگه دار برای تلاش بعدی
        }

        $stmtDelete = $pdo->prepare("DELETE FROM scheduled_posts WHERE id = ?");
        $stmtDelete->execute([$post['id']]);
    }
} catch (PDOException $e) {
    logMessage("خطا در بررسی پست‌های زمان‌بندی‌شده: " . $e->getMessage());
}

// بررسی فورواردهای زمان‌بندی‌شده
try {
    $stmt = $pdo->prepare("SELECT * FROM scheduled_forwards WHERE scheduled_time <= ? AND scheduled_time >= ? AND is_sent = 0");
    $stmt->execute([$currentTime, $timeThreshold]);
    $forwards = $stmt->fetchAll(PDO::FETCH_ASSOC);

    if (empty($forwards)) {
        logMessage("هیچ فورواردی برای ارسال در بازه‌ی $timeThreshold تا $currentTime پیدا نشد.");
    } else {
        logMessage("تعداد فورواردهای آماده برای ارسال: " . count($forwards));
    }

    foreach ($forwards as $forward) {
        $stmtUser = $pdo->prepare("SELECT is_banned FROM users WHERE user_id = ?");
        $stmtUser->execute([$forward['user_id']]);
        $user = $stmtUser->fetch(PDO::FETCH_ASSOC);

        if (!$user) {
            logMessage("کاربر با آیدی {$forward['user_id']} یافت نشد - فوروارد حذف می‌شود: " . json_encode($forward));
            $stmtDelete = $pdo->prepare("DELETE FROM scheduled_forwards WHERE id = ?");
            $stmtDelete->execute([$forward['id']]);
            continue;
        }

        if ($user['is_banned'] == 1) {
            logMessage("کاربر بن‌شده است - فوروارد حذف می‌شود: " . json_encode($forward));
            $stmtDelete = $pdo->prepare("DELETE FROM scheduled_forwards WHERE id = ?");
            $stmtDelete->execute([$forward['id']]);
            continue;
        }

        $response = forwardMessage($forward['channel_id'], $forward['from_chat_id'], $forward['message_id']);

        if ($response) {
            $stmtUpdate = $pdo->prepare("UPDATE scheduled_forwards SET is_sent = 1 WHERE id = ?");
            $stmtUpdate->execute([$forward['id']]);
            sendNotification($forward['user_id'], "فوروارد شما به کانال {$forward['channel_id']} در {$forward['scheduled_time']} ارسال شد.");
            logMessage("فوروارد با موفقیت ارسال شد: " . json_encode($forward));
        } else {
            sendNotification($forward['user_id'], "خطا در ارسال فوروارد به {$forward['channel_id']} در {$forward['scheduled_time']} - لطفاً لاگ را چک کنید.");
            logMessage("خطا در ارسال فوروارد: " . json_encode($forward));
            continue; // اگه ارسال ناموفق بود، فوروارد رو نگه دار برای تلاش بعدی
        }

        $stmtDelete = $pdo->prepare("DELETE FROM scheduled_forwards WHERE id = ?");
        $stmtDelete->execute([$forward['id']]);
    }
} catch (PDOException $e) {
    logMessage("خطا در بررسی فورواردهای زمان‌بندی‌شده: " . $e->getMessage());
}

// بررسی حذف‌های زمان‌بندی‌شده
try {
    $stmt = $pdo->prepare("SELECT * FROM scheduled_deletes WHERE scheduled_time <= ? AND scheduled_time >= ?");
    $stmt->execute([$currentTime, $timeThreshold]);
    $deletes = $stmt->fetchAll(PDO::FETCH_ASSOC);

    if (empty($deletes)) {
        logMessage("هیچ حذف زمان‌داری در بازه‌ی $timeThreshold تا $currentTime پیدا نشد.");
    } else {
        logMessage("تعداد حذف‌های آماده برای اجرا: " . count($deletes));
    }

    foreach ($deletes as $delete) {
        $stmtUser = $pdo->prepare("SELECT is_banned FROM users WHERE user_id = ?");
        $stmtUser->execute([$delete['user_id']]);
        $user = $stmtUser->fetch(PDO::FETCH_ASSOC);

        if (!$user) {
            logMessage("کاربر با آیدی {$delete['user_id']} یافت نشد - حذف زمان‌دار حذف می‌شود: " . json_encode($delete));
            $stmtDelete = $pdo->prepare("DELETE FROM scheduled_deletes WHERE id = ?");
            $stmtDelete->execute([$delete['id']]);
            continue;
        }

        if ($user['is_banned'] == 1) {
            logMessage("کاربر بن‌شده است - حذف زمان‌دار حذف می‌شود: " . json_encode($delete));
            $stmtDelete = $pdo->prepare("DELETE FROM scheduled_deletes WHERE id = ?");
            $stmtDelete->execute([$delete['id']]);
            continue;
        }

        $response = deleteMessageFromChannel($delete['channel_id'], $delete['message_id']);

        if ($response) {
            sendNotification($delete['user_id'], "پست با آیدی {$delete['message_id']} از کانال {$delete['channel_id']} در {$delete['scheduled_time']} حذف شد.");
            logMessage("حذف زمان‌دار با موفقیت اجرا شد: " . json_encode($delete));
        } else {
            sendNotification($delete['user_id'], "خطا در حذف پست با آیدی {$delete['message_id']} از {$delete['channel_id']} در {$delete['scheduled_time']} - لطفاً لاگ را چک کنید.");
            logMessage("خطا در اجرای حذف زمان‌دار: " . json_encode($delete));
        }

        $stmtDelete = $pdo->prepare("DELETE FROM scheduled_deletes WHERE id = ?");
        $stmtDelete->execute([$delete['id']]);
    }
} catch (PDOException $e) {
    logMessage("خطا در بررسی حذف‌های زمان‌بندی‌شده: " . $e->getMessage());
}

// نوتفیکیشن برای اتمام اشتراک
$oneDayLater = date('Y-m-d H:i:s', strtotime('+1 day'));
try {
    // فقط اشتراک‌هایی که اعلان براشون فرستاده نشده
    $stmt = $pdo->prepare("SELECT * FROM user_subscriptions WHERE end_date <= ? AND end_date > ? AND notification_sent = 0");
    $stmt->execute([$oneDayLater, $currentTime]);
    $expiringSubscriptions = $stmt->fetchAll(PDO::FETCH_ASSOC);

    if (empty($expiringSubscriptions)) {
        logMessage("هیچ اشتراکی در حال اتمام در بازه‌ی $currentTime تا $oneDayLater پیدا نشد.");
    } else {
        logMessage("تعداد اشتراک‌های در حال اتمام: " . count($expiringSubscriptions));
    }

    foreach ($expiringSubscriptions as $subscription) {
        $userId = $subscription['user_id'];
        $buttonName = $subscription['button_name'];
        $endDate = $subscription['end_date'];
        $subscriptionId = $subscription['id'];

        // ارسال اعلان
        sendNotification($userId, "⚠️ اشتراک شما برای دکمه '$buttonName' در تاریخ $endDate به پایان می‌رسد. لطفاً برای تمدید به بخش 'خرید اشتراک' مراجعه کنید.");
        logMessage("نوتفیکیشن اتمام اشتراک برای کاربر $userId ارسال شد - دکمه: $buttonName");

        // به‌روزرسانی ستون notification_sent
        $stmtUpdate = $pdo->prepare("UPDATE user_subscriptions SET notification_sent = 1 WHERE id = ?");
        $stmtUpdate->execute([$subscriptionId]);
        logMessage("وضعیت اعلان برای اشتراک $subscriptionId به‌روزرسانی شد.");
    }

    // حذف اشتراک‌های منقضی‌شده
    $stmt = $pdo->prepare("DELETE FROM user_subscriptions WHERE end_date <= ?");
    $stmt->execute([$currentTime]);
    logMessage("اشتراک‌های منقضی‌شده حذف شدند.");
} catch (PDOException $e) {
    logMessage("خطا در بررسی اشتراک‌های در حال اتمام: " . $e->getMessage());
}