<?php
declare(strict_types=1);

$recipient = 'rene@bjornsondesigns.com';
$fromAddress = 'no-reply@bjornsondesigns.ca';
$siteName = 'Bjornson Designs';

function load_smtp_config(string $defaultFromAddress): ?array
{
    $config = [];
    $configPath = __DIR__ . '/../bjornson-mail-config.php';

    if (is_readable($configPath)) {
        $loadedConfig = require $configPath;
        if (is_array($loadedConfig)) {
            $config = $loadedConfig;
        }
    }

    $envMap = [
        'host' => 'BJORNSON_SMTP_HOST',
        'port' => 'BJORNSON_SMTP_PORT',
        'username' => 'BJORNSON_SMTP_USERNAME',
        'password' => 'BJORNSON_SMTP_PASSWORD',
        'secure' => 'BJORNSON_SMTP_SECURE',
        'from_address' => 'BJORNSON_SMTP_FROM',
        'from_name' => 'BJORNSON_SMTP_FROM_NAME',
    ];

    foreach ($envMap as $key => $envKey) {
        $value = getenv($envKey);
        if ($value !== false && $value !== '') {
            $config[$key] = $value;
        }
    }

    if (empty($config['host']) || empty($config['username']) || empty($config['password'])) {
        return null;
    }

    $secure = strtolower((string)($config['secure'] ?? 'ssl'));
    $port = isset($config['port']) ? (int)$config['port'] : ($secure === 'tls' ? 587 : 465);

    return [
        'host' => (string)$config['host'],
        'port' => $port,
        'username' => (string)$config['username'],
        'password' => (string)$config['password'],
        'secure' => in_array($secure, ['ssl', 'tls', 'none'], true) ? $secure : 'ssl',
        'from_address' => filter_var($config['from_address'] ?? $defaultFromAddress, FILTER_VALIDATE_EMAIL) ?: $defaultFromAddress,
        'from_name' => (string)($config['from_name'] ?? 'Bjornson Designs Website'),
    ];
}

function smtp_read_response($socket): array
{
    $response = '';

    while (($line = fgets($socket, 515)) !== false) {
        $response .= $line;
        if (strlen($line) >= 4 && $line[3] === ' ') {
            break;
        }
    }

    return [(int)substr($response, 0, 3), $response];
}

function smtp_expect($socket, array $acceptedCodes): string
{
    [$code, $response] = smtp_read_response($socket);

    if (!in_array($code, $acceptedCodes, true)) {
        throw new RuntimeException('SMTP response ' . $code . ': ' . trim($response));
    }

    return $response;
}

function smtp_command($socket, string $command, array $acceptedCodes): string
{
    fwrite($socket, $command . "\r\n");

    return smtp_expect($socket, $acceptedCodes);
}

function normalize_email_body(string $body): string
{
    $body = str_replace(["\r\n", "\r"], "\n", $body);
    $body = preg_replace('/^\./m', '..', $body) ?? $body;

    return str_replace("\n", "\r\n", $body);
}

function send_smtp_mail(array $config, string $recipient, string $subject, string $body, array $headers): bool
{
    $host = $config['host'];
    $port = (int)$config['port'];
    $secure = $config['secure'];
    $transportHost = $secure === 'ssl' ? 'ssl://' . $host : $host;
    $socket = @stream_socket_client($transportHost . ':' . $port, $errorNumber, $errorMessage, 20, STREAM_CLIENT_CONNECT);

    if (!$socket) {
        throw new RuntimeException('SMTP connection failed: ' . $errorNumber . ' ' . $errorMessage);
    }

    stream_set_timeout($socket, 20);
    $serverName = clean_single_line($_SERVER['SERVER_NAME'] ?? 'bjornsondesigns.ca', 120);

    try {
        smtp_expect($socket, [220]);
        smtp_command($socket, 'EHLO ' . $serverName, [250]);

        if ($secure === 'tls') {
            smtp_command($socket, 'STARTTLS', [220]);
            if (!stream_socket_enable_crypto($socket, true, STREAM_CRYPTO_METHOD_TLS_CLIENT)) {
                throw new RuntimeException('SMTP STARTTLS negotiation failed.');
            }
            smtp_command($socket, 'EHLO ' . $serverName, [250]);
        }

        smtp_command($socket, 'AUTH LOGIN', [334]);
        smtp_command($socket, base64_encode($config['username']), [334]);
        smtp_command($socket, base64_encode($config['password']), [235]);
        smtp_command($socket, 'MAIL FROM:<' . $config['from_address'] . '>', [250]);
        smtp_command($socket, 'RCPT TO:<' . $recipient . '>', [250, 251]);
        smtp_command($socket, 'DATA', [354]);

        $messageHeaders = array_merge([
            'Date: ' . date(DATE_RFC2822),
            'To: Rene <' . $recipient . '>',
            'Subject: ' . $subject,
        ], $headers, [
            'Message-ID: <' . bin2hex(random_bytes(12)) . '@bjornsondesigns.ca>'
        ]);

        fwrite($socket, implode("\r\n", $messageHeaders) . "\r\n\r\n" . normalize_email_body($body) . "\r\n.\r\n");
        smtp_expect($socket, [250]);
        smtp_command($socket, 'QUIT', [221, 250]);
    } finally {
        fclose($socket);
    }

    return true;
}

function wants_json(): bool
{
    $accept = $_SERVER['HTTP_ACCEPT'] ?? '';
    $requestedWith = $_SERVER['HTTP_X_REQUESTED_WITH'] ?? '';

    return stripos($accept, 'application/json') !== false || strtolower($requestedWith) === 'xmlhttprequest';
}

function respond(int $statusCode, array $payload): void
{
    http_response_code($statusCode);

    if (wants_json()) {
        header('Content-Type: application/json; charset=UTF-8');
        echo json_encode($payload);
        exit;
    }

    $title = $payload['ok'] ? 'Request sent' : 'Request not sent';
    $message = htmlspecialchars($payload['message'] ?? '', ENT_QUOTES, 'UTF-8');
    echo '<!doctype html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1"><title>' . $title . ' | Bjornson Designs</title><link rel="stylesheet" href="styles.css"></head><body><main class="section-pad"><div class="section-shell"><p class="eyebrow dark">Bjornson Designs</p><h1>' . $title . '</h1><p>' . $message . '</p><a class="button primary" href="contact.html">Back to contact</a></div></main></body></html>';
    exit;
}

function post_value(string $key): string
{
    return isset($_POST[$key]) && is_string($_POST[$key]) ? $_POST[$key] : '';
}

function clean_single_line(string $value, int $limit = 180): string
{
    $value = strip_tags($value);
    $value = str_replace(["\r", "\n"], ' ', $value);
    $value = preg_replace('/[ \t]+/', ' ', $value) ?? '';
    $value = trim($value);

    return function_exists('mb_substr') ? mb_substr($value, 0, $limit) : substr($value, 0, $limit);
}

function clean_message(string $value, int $limit = 3000): string
{
    $value = strip_tags($value);
    $value = str_replace(["\r\n", "\r"], "\n", $value);
    $value = preg_replace("/\n{3,}/", "\n\n", $value) ?? '';
    $value = trim($value);

    return function_exists('mb_substr') ? mb_substr($value, 0, $limit) : substr($value, 0, $limit);
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    respond(405, [
        'ok' => false,
        'message' => 'Please submit the contact form to send a request.'
    ]);
}

if (clean_single_line(post_value('website')) !== '') {
    respond(200, [
        'ok' => true,
        'message' => 'Thank you. A member of the Bjornson team will follow up shortly.'
    ]);
}

$name = clean_single_line(post_value('name'));
$phone = clean_single_line(post_value('phone'));
$email = clean_single_line(post_value('email'));
$project = clean_single_line(post_value('project'));
$message = clean_message(post_value('message'));
$source = clean_single_line(post_value('source'), 120);

$errors = [];

if ($name === '') {
    $errors[] = 'name';
}

if ($phone === '') {
    $errors[] = 'phone';
}

if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
    $errors[] = 'email';
}

if ($project === '') {
    $errors[] = 'project type';
}

if ($message === '') {
    $errors[] = 'message';
}

if ($errors !== []) {
    respond(422, [
        'ok' => false,
        'message' => 'Please check the required fields and try again.'
    ]);
}

$submittedAt = gmdate('Y-m-d H:i:s') . ' UTC';
$ipAddress = clean_single_line($_SERVER['REMOTE_ADDR'] ?? 'Unknown', 80);
$userAgent = clean_single_line($_SERVER['HTTP_USER_AGENT'] ?? 'Unknown', 220);
$referer = clean_single_line($_SERVER['HTTP_REFERER'] ?? 'Direct visit', 260);
$safeProject = clean_single_line($project, 80);
$subject = 'Bjornson Designs website callback request - ' . $safeProject;
$smtpConfig = load_smtp_config($fromAddress);

if ($smtpConfig !== null) {
    $fromAddress = $smtpConfig['from_address'];
}

$body = implode("\n", [
    'New website callback request for Bjornson Designs',
    '',
    'Name: ' . $name,
    'Phone: ' . $phone,
    'Email: ' . $email,
    'Project type: ' . $project,
    'Source form: ' . ($source !== '' ? $source : 'Website contact form'),
    '',
    'Message:',
    $message,
    '',
    'Submitted: ' . $submittedAt,
    'IP address: ' . $ipAddress,
    'Page: ' . $referer,
    'User agent: ' . $userAgent
]);

$headers = [
    'MIME-Version: 1.0',
    'Content-Type: text/plain; charset=UTF-8',
    'From: ' . $siteName . ' Website <' . $fromAddress . '>',
    'Reply-To: ' . $email,
    'X-Mailer: PHP/' . phpversion()
];

$testMode = getenv('BJORNSON_FORM_TEST_MODE') === '1';

if ($testMode) {
    $testLog = getenv('BJORNSON_FORM_TEST_LOG') ?: sys_get_temp_dir() . '/bjornson-lead-form-test.log';
    $sent = file_put_contents($testLog, json_encode([
        'to' => $recipient,
        'subject' => $subject,
        'headers' => $headers,
        'body' => $body
    ], JSON_PRETTY_PRINT) . PHP_EOL, FILE_APPEND | LOCK_EX) !== false;
} else {
    $headerText = implode("\r\n", $headers);

    if ($smtpConfig !== null) {
        try {
            $sent = send_smtp_mail($smtpConfig, $recipient, $subject, $body, $headers);
        } catch (Throwable $error) {
            error_log('Bjornson SMTP send failed: ' . $error->getMessage());
            $sent = false;
        }
    } elseif (function_exists('mb_send_mail')) {
        if (function_exists('mb_language')) {
            mb_language('uni');
        }
        if (function_exists('mb_internal_encoding')) {
            mb_internal_encoding('UTF-8');
        }
        $sent = @mb_send_mail($recipient, $subject, $body, $headerText);
    } else {
        $sent = false;
    }

    if (!$sent) {
        $sent = @mail($recipient, $subject, $body, $headerText);
    }

    if (!$sent && stripos(PHP_OS, 'WIN') !== 0) {
        $sent = @mail($recipient, $subject, $body, $headerText, '-f' . $fromAddress);
    }
}

if (!$sent) {
    respond(500, [
        'ok' => false,
        'message' => 'We could not send the request. Please call Bjornson Designs directly.'
    ]);
}

respond(200, [
    'ok' => true,
    'message' => 'Thank you. A member of the Bjornson team will follow up shortly.'
]);
