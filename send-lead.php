<?php
declare(strict_types=1);

$recipient = 'rene@bjornsondesigns.com';
$fromAddress = $recipient;
$siteName = 'Bjornson Designs';

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
    $sent = @mail($recipient, $subject, $body, $headerText);

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
