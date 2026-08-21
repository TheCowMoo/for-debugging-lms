<?php
/**
 * PURSUIT PATHWAYS LMS — Production deployment guard.
 *
 * Run BEFORE packaging/deploying the web tree, e.g.:
 *
 *   php tests/check-production.php
 *
 * Exits non-zero if any forbidden web-accessible diagnostic/test file exists,
 * or if any web-reachable PHP file disables TLS certificate verification.
 * Add this to your deployment pipeline so diagnostics cannot sneak back into
 * production.
 *
 * @package  PP_LMS
 */

$root = dirname(__DIR__);

// Explicit allowlist of forbidden web diagnostics (deliberately NOT filename
// conventions — every file must be listed to ship).
$forbiddenFiles = [
    '_diag.php',
    'login-debug.php',
    'upload-diag.php',
    'api-test.php',
    'email-test.php',
    's3-test.php',
    'temp-db-users.php',
    'scorm-content/debug.php',
];

$errors = [];

foreach ($forbiddenFiles as $rel) {
    if (file_exists($root . '/' . $rel)) {
        $errors[] = "Forbidden web diagnostic present: {$rel}";
    }
}

// Scan web-reachable PHP for disabled TLS certificate verification.
$skipDirs = ['migrations', 'tests', 'content', '.git', 'vendor'];
$it = new RecursiveIteratorIterator(
    new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS)
);
foreach ($it as $file) {
    if (!$file->isFile() || substr($file->getFilename(), -4) !== '.php') {
        continue;
    }
    $rel = ltrim(str_replace('\\', '/', substr($file->getPathname(), strlen($root))), '/');
    foreach ($skipDirs as $d) {
        if (strpos($rel, $d . '/') === 0) {
            continue 2;
        }
    }
    $content = @file_get_contents($file->getPathname());
    if ($content !== false && preg_match('/CURLOPT_SSL_VERIFYPEER\s*=>\s*false/', $content)) {
        $errors[] = "TLS certificate verification disabled in web PHP: {$rel}";
    }
}

if ($errors) {
    fwrite(STDERR, "PRODUCTION CHECK FAILED\n");
    foreach ($errors as $e) {
        fwrite(STDERR, "  - {$e}\n");
    }
    exit(1);
}

echo "Production check passed: no forbidden diagnostics; TLS verification enabled everywhere.\n";
exit(0);
