<?php
/**
 * Generate a personalized certificate image from a template.
 */

require_once __DIR__ . '/../bootstrap.php';
requireLogin();

$template = trim($_GET['template'] ?? $_POST['template'] ?? '');
$name = trim($_GET['name'] ?? $_POST['name'] ?? '');

if (empty($template)) {
    http_response_code(400);
    echo "No certificate template specified.";
    exit;
}

$template = basename($template);
$templatePath = __DIR__ . '/../content/' . $template;
if (!file_exists($templatePath)) {
    http_response_code(404);
    echo "Certificate template not found.";
    exit;
}

if ($name === '') {
    $name = 'Learner';
    if (!isTestUser()) {
        try {
            $pdo = getDbConnection();
            $stmt = $pdo->prepare("SELECT first_name, last_name FROM users WHERE id = ?");
            $stmt->execute([$_SESSION['user_id']]);
            $user = $stmt->fetch(PDO::FETCH_ASSOC);
            if ($user) {
                $first = trim($user['first_name'] ?? '');
                $last = trim($user['last_name'] ?? '');
                $name = trim($first . ' ' . $last) ?: 'Learner';
            }
        } catch (PDOException $e) {
            error_log('[CERT] User lookup failed: ' . $e->getMessage());
        }
    } else {
        $name = 'Test Learner';
    }
}

$extension = strtolower(pathinfo($templatePath, PATHINFO_EXTENSION));
if (in_array($extension, ['jpg', 'jpeg'], true)) {
    $image = @imagecreatefromjpeg($templatePath);
} elseif ($extension === 'png') {
    $image = @imagecreatefrompng($templatePath);
} else {
    http_response_code(415);
    echo "Unsupported certificate format.";
    exit;
}

if (!$image) {
    http_response_code(500);
    echo "Failed to load certificate template.";
    exit;
}

$width = imagesx($image);
$height = imagesy($image);

function findSystemFont(): ?string
{
    $candidates = [
        __DIR__ . '/../fonts/arial.ttf',
        __DIR__ . '/../fonts/DejaVuSans.ttf',
        __DIR__ . '/../fonts/PlusJakartaSans-Bold.ttf',
        '/usr/share/fonts/truetype/dejavu/DejaVuSans.ttf',
        '/usr/share/fonts/truetype/liberation/LiberationSans-Regular.ttf',
        getenv('WINDIR') ? getenv('WINDIR') . '\\Fonts\\arial.ttf' : null,
        getenv('WINDIR') ? getenv('WINDIR') . '\\Fonts\\Tahoma.ttf' : null,
    ];

    foreach ($candidates as $candidate) {
        if ($candidate && file_exists($candidate)) {
            return $candidate;
        }
    }

    return null;
}

$fontPath = findSystemFont();

if ($fontPath && function_exists('imagettftext')) {
    $fontSize = (int) max(16, min(30, ($width / 18) * 0.5));
    $angle = 0;
    $bbox = imagettfbbox($fontSize, $angle, $fontPath, $name);
    $textWidth = abs($bbox[4] - $bbox[0]);
    $textHeight = abs($bbox[7] - $bbox[1]);
    $x = (int) (($width - $textWidth) / 2);
    $y = (int) ($height * 0.45);

    $backgroundColor = imagecolorallocatealpha($image, 255, 255, 255, 75);
    $padding = 18;
    $rectTop = max(0, $y - $textHeight - $padding);
    $rectBottom = min($height, $y + $padding);
    imagefilledrectangle(
        $image,
        max(0, $x - $padding),
        $rectTop,
        min($width, $x + $textWidth + $padding),
        $rectBottom,
        $backgroundColor
    );

    $shadowColor = imagecolorallocate($image, 0, 0, 0);
    $textColor = imagecolorallocate($image, 14, 28, 75);
    imagettftext($image, $fontSize, $angle, $x + 2, $y + 2, $shadowColor, $fontPath, $name);
    imagettftext($image, $fontSize, $angle, $x, $y, $textColor, $fontPath, $name);
} else {
    $font = 3;
    $fontWidth = imagefontwidth($font);
    $fontHeight = imagefontheight($font);
    $textWidth = $fontWidth * strlen($name);
    $x = (int) (($width - $textWidth) / 2);
    $y = (int) ($height * 0.45) - 150;

    $bgColor = imagecolorallocatealpha($image, 255, 255, 255, 90);
    imagefilledrectangle($image, $x - 10, $y - 8, $x + $textWidth + 10, $y + $fontHeight + 8, $bgColor);

    $textColor = imagecolorallocate($image, 0, 0, 0);
    imagestring($image, $font, $x, $y, $name, $textColor);
}

$outputName = preg_replace('/[^A-Za-z0-9_-]+/', '-', $name);
$outputName = trim($outputName, '-');
if ($outputName === '') {
    $outputName = 'certificate';
}

header('Content-Type: image/jpeg');
header('Content-Disposition: attachment; filename="' . $outputName . '-certificate.jpg"');
imagejpeg($image, null, 90);
imagedestroy($image);
exit;
