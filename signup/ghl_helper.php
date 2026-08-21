<?php
/**
 * GHL HELPER - LeadConnector API Integration
 */

function sendGHLPortalEmail($toEmail, $firstName, $subject, $htmlBody, $lastName = '', $textBody = '', $fromName = '') {
    $locationId = getenv('GHL_LOCATION_ID') ?: 'QY5KzvIJwkJbpoAizxnK';
    $apiKey = getenv('GHL_API_KEY') ?: 'pit-24ca63d0-922f-41e6-97e0-296644f1a9c2';
    $fromEmail = getenv('MAIL_FROM_EMAIL') ?: ('noreply@' . (getenv('APP_DOMAIN') ?: 'localhost'));

    // 1. UPSERT CONTACT (Create or Update)
    $ch = curl_init("https://services.leadconnectorhq.com/contacts/upsert");
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => json_encode([
            "locationId" => $locationId,
            "email" => $toEmail,
            "firstName" => $firstName,
            "lastName" => $lastName
        ]),
        CURLOPT_HTTPHEADER => [
            "Authorization: Bearer $apiKey",
            "Content-Type: application/json",
            "Version: 2021-07-28"
        ],
    ]);

    $response = curl_exec($ch);
    $contactRes = json_decode($response, true);
    $contactId = $contactRes['contact']['id'] ?? null;
    curl_close($ch);

    if (!$contactId) return false;

    // 2. SEND EMAIL VIA CONVERSATIONS
    $messagePayload = [
        "type" => "Email",
        "contactId" => $contactId,
        "emailTo" => $toEmail,
        "emailFrom" => $fromEmail,
        "emailFromName" => ($fromName !== '') ? $fromName : (getenv('MAIL_FROM_NAME') ?: ''),
        "subject" => $subject,
        "html" => $htmlBody,
    ];
    // Include a plain-text part when provided (multipart emails are less
    // likely to be flagged as spam and improve accessibility).
    if ($textBody !== '') {
        $messagePayload["text"] = $textBody;
    }

    $ch = curl_init("https://services.leadconnectorhq.com/conversations/messages");
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => json_encode($messagePayload),
        CURLOPT_HTTPHEADER => [
            "Authorization: Bearer $apiKey",
            "Content-Type: application/json",
            "Version: 2021-07-28"
        ],
    ]);

    curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    return ($httpCode >= 200 && $httpCode < 300);
}

/**
 * Build a complete, branded verification email (HTML + plain text) so it
 * passes content-based spam filters and looks legitimate to recipients.
 */
function buildVerificationEmail($firstName, $verifyLink, $siteName) {
    $safeName = htmlspecialchars((string)$firstName, ENT_QUOTES, 'UTF-8');
    $safeSite = htmlspecialchars((string)$siteName, ENT_QUOTES, 'UTF-8');
    $safeLink = htmlspecialchars((string)$verifyLink, ENT_QUOTES, 'UTF-8');

    $html = "
        <div style=\"font-family:Arial,Helvetica,sans-serif;max-width:560px;margin:0 auto;color:#1A2E2A;\">
            <div style=\"text-align:center;padding:24px 0;\">
                <h1 style=\"margin:0;font-size:20px;color:#1A2E2A;\">{$safeSite}</h1>
            </div>
            <div style=\"background:#ffffff;border:1px solid #F4F9F7;border-radius:12px;padding:32px;\">
                <h2 style=\"margin:0 0 12px;font-size:18px;color:#1A2E2A;\">Hi {$safeName}, welcome to {$safeSite}!</h2>
                <p style=\"margin:0 0 16px;color:#1A2E2A;line-height:1.6;\">Thanks for creating an account. Please confirm your email address to activate your account and get started.</p>
                <div style=\"text-align:center;margin:28px 0;\">
                    <a href=\"{$safeLink}\" style=\"background:#006F53;color:#ffffff;padding:14px 32px;border-radius:8px;font-weight:bold;text-decoration:none;display:inline-block;\">Confirm My Email</a>
                </div>
                <p style=\"margin:0 0 16px;color:#475569;font-size:13px;line-height:1.6;\">If the button doesn't work, copy and paste this link into your browser:</p>
                <p style=\"margin:0 0 16px;color:#475569;font-size:13px;word-break:break-all;\">{$safeLink}</p>
                <p style=\"margin:0;color:#5f6f6a;font-size:13px;\">This link expires in 24 hours.</p>
            </div>
            <p style=\"text-align:center;color:#8b8b8b;font-size:12px;margin-top:16px;line-height:1.6;\">
                You're receiving this email because you created an account on {$safeSite}.<br>
                If you didn't create this account, you can safely ignore this email.
            </p>
        </div>";

    $text = "Hi {$firstName}, welcome to {$siteName}!\n\n"
        . "Thanks for creating an account. Please confirm your email address to activate your account and get started.\n\n"
        . "Confirm your email by opening this link:\n{$verifyLink}\n\n"
        . "This link expires in 24 hours.\n\n"
        . "You're receiving this email because you created an account on {$siteName}. If you didn't create this account, you can safely ignore this email.";

    return ['html' => $html, 'text' => $text];
}
