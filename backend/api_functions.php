<?php
// Functionality to be shared between api.php and cron scripts

function sendPush($to, $title, $body) {
    if (empty($to)) return;
    $tokens = is_array($to) ? $to : [$to];
    $tokens = array_filter($tokens); // Remove null/empty tokens
    if (empty($tokens)) return;

    $messages = [];
    foreach ($tokens as $token) {
        if (!$token) continue;
        $messages[] = [
            'to' => $token,
            'title' => $title,
            'body' => $body,
            'sound' => 'default',
        ];
    }

    if (empty($messages)) return;

    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, "https://exp.host/--/api/v2/push/send");
    curl_setopt($ch, CURLOPT_POST, TRUE);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, TRUE);
    curl_setopt($ch, CURLOPT_HTTPHEADER, [
        'Content-Type: application/json',
        'accept-encoding: gzip, deflate'
    ]);
    curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($messages));
    $response = curl_exec($ch);
    $error = curl_error($ch);
    curl_close($ch);

    if ($error || !$response) {
        error_log("Push error: " . $error . " - Response: " . $response);
    }
}

function sendExpoNotification($title, $body, $pdo) {
    // 1. Log to database for history/web panel
    try {
        $stmt_log = $pdo->prepare("INSERT INTO notifications (title, message, `type`) VALUES (?, ?, 'admin_alert')");
        $stmt_log->execute([$title, $body]);
    } catch (Exception $e) { /* Ignore log error */ }

    // 2. Get all admin and superadmin tokens
    $stmt = $pdo->query("SELECT push_token FROM employees WHERE (role = 'admin' OR role = 'superadmin') AND push_token IS NOT NULL AND push_token != ''");
    $tokens = $stmt->fetchAll(PDO::FETCH_COLUMN);
    if (!empty($tokens)) {
        sendPush($tokens, $title, $body);
    }

    // 3. Telegram Support (Optional)
    // To enable, add TELEGRAM_BOT_TOKEN and TELEGRAM_CHAT_ID to your environment/config
    $tgToken = defined('TELEGRAM_BOT_TOKEN') ? TELEGRAM_BOT_TOKEN : null;
    $tgChatId = defined('TELEGRAM_CHAT_ID') ? TELEGRAM_CHAT_ID : null;
    if ($tgToken && $tgChatId) {
        $url = "https://api.telegram.org/bot$tgToken/sendMessage";
        $data = [
            'chat_id' => $tgChatId,
            'text' => "🔔 *$title*\n\n$body",
            'parse_mode' => 'Markdown'
        ];
        $options = [
            'http' => [
                'header'  => "Content-type: application/x-www-form-urlencoded\r\n",
                'method'  => 'POST',
                'content' => http_build_query($data)
            ]
        ];
        $context  = stream_context_create($options);
        @file_get_contents($url, false, $context);
    }
}

/**
 * Rasm manzillarini to'liq URL'ga aylantiradi
 */
function getFullUrl($path) {
    if (!$path) return null;
    // Agar rasm allaqachon base64 bo'lsa yoki to'liq URL bo'lsa, o'zini qaytaramiz
    if (strpos($path, 'data:image') === 0 || strpos($path, 'http') === 0) {
        return $path;
    }
    // Aks holda to'liq domen nomini qo'shamiz
    return 'https://karshievdev.uz/' . $path; 
}
?>
