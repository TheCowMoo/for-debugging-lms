<?php
/**
 * Download placeholder certificate or compliance records.
 */

require_once __DIR__ . '/../bootstrap.php';

requireLogin();

$requestedFile = '';
if ($_SERVER['REQUEST_METHOD'] === 'GET') {
	$requestedFile = $_GET['file'] ?? '';
} else {
	$requestedFile = $_POST['file'] ?? '';
}

$file = basename($requestedFile ?: '');
if (empty($file)) {
	http_response_code(400);
	echo "No file specified.";
	exit;
}

$certDir = __DIR__ . '/files';
$filePath = $certDir . DIRECTORY_SEPARATOR . $file;

if (!file_exists($filePath)) {
	http_response_code(404);
	echo "Requested certificate not found.";
	exit;
}

// If POST action=send => email attachment
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'send') {
	$to = trim($_POST['to'] ?? ($_SESSION['email'] ?? ''));
	if (empty($to)) {
		http_response_code(400);
		echo "No recipient email provided.";
		exit;
	}

	$siteName = getSiteName();
	$subject = "Your $siteName Certificate";
	$messageBody = "Attached is the requested certificate from your learning portal.";

	if (!is_readable($filePath)) {
		http_response_code(500);
		error_log('[CERT] File not readable: ' . $filePath);
		echo "Server error reading file.";
		exit;
	}

	$fileData = file_get_contents($filePath);
	if ($fileData === false) {
		http_response_code(500);
		error_log('[CERT] Failed to read file: ' . $filePath);
		echo "Server error reading file.";
		exit;
	}

	$attachment = chunk_split(base64_encode($fileData));
	$filename = basename($filePath);

	$boundary = md5(time());
	$fromEmail = getenv('MAIL_FROM_EMAIL') ?: 'noreply@' . (getenv('APP_DOMAIN') ?: 'localhost');
	$fromName = getenv('MAIL_FROM_NAME') ?: 'Learning Portal';
	$headers = "From: $fromName <$fromEmail>" . "\r\n";
	$headers .= "MIME-Version: 1.0" . "\r\n";
	$headers .= "Content-Type: multipart/mixed; boundary=\"" . $boundary . "\"" . "\r\n";

	$body = "--$boundary\r\n";
	$body .= "Content-Type: text/plain; charset=ISO-8859-1\r\n";
	$body .= "Content-Transfer-Encoding: 7bit\r\n\r\n";
	$body .= $messageBody . "\r\n\r\n";

	$body .= "--$boundary\r\n";
	$body .= "Content-Type: application/pdf; name=\"" . $filename . "\"\r\n";
	$body .= "Content-Transfer-Encoding: base64\r\n";
	$body .= "Content-Disposition: attachment; filename=\"" . $filename . "\"\r\n\r\n";
	$body .= $attachment . "\r\n";
	$body .= "--$boundary--";

	$sent = false;
	// Attempt to send mail; if mail() not configured, return 202
	if (function_exists('mail')) {
		try {
			$sent = mail($to, $subject, $body, $headers);
		} catch (Exception $e) {
			error_log('[CERT] mail() failed: ' . $e->getMessage());
			$sent = false;
		}
	}

	if ($sent) {
		echo "Certificate emailed to " . htmlspecialchars($to) . ".";
	} else {
		http_response_code(202);
		echo "Email queued/unsupported on this host. File is available for download.";
	}
	exit;
}

// Default: serve file for download
$safeName = str_replace([' ', '%20'], ['-', '-'], $file);
$fsize = filesize($filePath);
header('Content-Description: File Transfer');
header('Content-Type: application/pdf');
header('Content-Disposition: attachment; filename="' . $safeName . '"');
header('Content-Transfer-Encoding: binary');
header('Content-Length: ' . $fsize);
readfile($filePath);
exit;
