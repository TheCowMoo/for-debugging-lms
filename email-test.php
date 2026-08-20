<?php
/**
 * GHL CONVERSATIONS API TEST
 * Uses .env variables for configuration.
 */

require_once __DIR__ . '/bootstrap.php';
requireLogin();
requireAdmin();

$locationId = getenv('GHL_LOCATION_ID') ?: 'QY5KzvIJwkJbpoAizxnK';
$apiKey = getenv('GHL_API_KEY') ?: 'pit-24ca63d0-922f-41e6-97e0-296644f1a9c2';
$fromEmail = getenv('MAIL_FROM_EMAIL') ?: 'noreply@' . (getenv('APP_DOMAIN') ?: 'localhost');
$testEmail = 'nathanaelsta.catalina0@gmail.com';

echo "<h2>GHL API Debugger: Conversations Mode</h2>";

// --- STEP 1: UPSERT CONTACT ---
$contactUrl = "https://services.leadconnectorhq.com/contacts/upsert";
$contactData = [
    "locationId" => $locationId,
    "email" => $testEmail,
    "firstName" => "Nathanael",
    "lastName" => "Test"
];

$ch = curl_init($contactUrl);
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_POST, true);
curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($contactData));
curl_setopt($ch, CURLOPT_HTTPHEADER, [
    "Authorization: Bearer $apiKey",
    "Content-Type: application/json",
    "Version: 2021-07-28"
]);

$contactResponse = curl_exec($ch);
$contactDataDecoded = json_decode($contactResponse, true);
$contactId = $contactDataDecoded['contact']['id'] ?? null;
curl_close($ch);

if (!$contactId) {
    echo "<p style='color:red;'>❌ Failed to create/find contact.</p>";
    echo "<pre>Response: " . htmlspecialchars($contactResponse) . "</pre>";
    exit;
}

echo "<p style='color:green;'>✅ Contact ID found: <strong>$contactId</strong></p>";

// --- STEP 2: SEND EMAIL MESSAGE ---
$msgUrl = "https://services.leadconnectorhq.com/conversations/messages";
$msgPayload = [
    "type" => "Email",
    "contactId" => $contactId,
    "emailFrom" => $fromEmail,
    "subject" => "Learning Portal: API Verification Test",
    "html" => "
        <div style='font-family: Arial, sans-serif; border: 1px solid #eee; padding: 20px; border-radius: 10px;'>
            <h2 style='color: #2563eb;'>Authentication Successful</h2>
            <p>Hello Nathanael,</p>
            <p>If you are reading this, your PHP script successfully bypassed HestiaCP's local mail blocks by using the GHL Conversations API.</p>
            <hr>
            <p style='font-size: 12px; color: #666;'>Sent via LeadConnector API V2</p>
        </div>
    "
];

$ch = curl_init($msgUrl);
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_POST, true);
curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($msgPayload));
curl_setopt($ch, CURLOPT_HTTPHEADER, [
    "Authorization: Bearer $apiKey",
    "Content-Type: application/json",
    "Version: 2021-07-28"
]);

$response = curl_exec($ch);
$httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
curl_close($ch);

// --- STEP 3: OUTPUT RESULT ---
echo "<h3>Message Delivery Result</h3>";
echo "HTTP Status Code: <strong>$httpCode</strong><br>";
echo "Response Payload: <pre>" . htmlspecialchars($response) . "</pre>";

if ($httpCode >= 200 && $httpCode < 300) {
    echo "<p style='background: #dcfce7; color: #166534; padding: 15px; border-radius: 8px;'>
            <strong>SUCCESS!</strong> The email has been queued. Check <strong>$testEmail</strong>.
          </p>";
} else {
    echo "<p style='background: #fef2f2; color: #b91c1c; padding: 15px; border-radius: 8px;'>
            <strong>FAILED.</strong> The message was not accepted. See the error response above.
          </p>";
}