<?php
/**
 * S3 COMPATIBLE STORAGE HELPERS
 * Minimal AWS Signature V4 implementation using curl.
 *
 * FIX LOG
 * -------
 * 1. s3Request(): GET/HEAD/DELETE requests must NOT include a Content-Type
 *    header in the canonical request. Including it caused AWS to return
 *    SignatureDoesNotMatch (403) for every GET call. The content-type header
 *    is now only added for PUT/POST methods.
 *
 * 2. s3Exists(): Replaced the full-body GET workaround with a proper HEAD
 *    request now that the canonical-request bug above is fixed. HEAD is
 *    cheaper (no body transfer) and does not flood the error log with
 *    "[S3] GET failed" entries for missing files.
 *
 * 3. s3DeletePrefix(): New helper — deletes all S3 objects whose key starts
 *    with a given prefix (used by the package-delete flow in scorm-upload.php
 *    so that deleted packages are also removed from S3).
 */

if (!function_exists('isS3Configured')) {
    function isS3Configured(): bool
    {
        return defined('S3_BUCKET') && S3_BUCKET !== ''
            && defined('S3_KEY') && S3_KEY !== ''
            && defined('S3_SECRET') && S3_SECRET !== '';
    }
}

if (!function_exists('s3Hmac')) {
    function s3Hmac(string $key, string $data): string
    {
        return hash_hmac('sha256', $data, $key, true);
    }
}

if (!function_exists('s3Request')) {
    /**
     * Low-level AWS Signature V4 request.
     *
     * FIX: Content-Type is only included in the canonical request (and in the
     * actual HTTP headers) for PUT and POST methods. For GET, HEAD, and DELETE
     * the header is omitted entirely — including it in the canonical request
     * while AWS ignores it on the wire causes a SignatureDoesNotMatch error.
     */
    function s3Request(string $method, string $key, string $body = '', string $contentType = ''): array
    {
        $bucket    = S3_BUCKET;
        $region    = S3_REGION;
        $accessKey = S3_KEY;
        $secretKey = S3_SECRET;
        $endpoint  = defined('S3_ENDPOINT') && S3_ENDPOINT !== ''
            ? rtrim(S3_ENDPOINT, '/')
            : "https://{$bucket}.s3.{$region}.amazonaws.com";

        $now     = gmdate('Ymd\THis\Z');
        $date    = substr($now, 0, 8);
        $service = 's3';

        $canonicalUri = '/' . implode('/', array_map(
            function ($z) { return str_replace('%7E', '~', rawurlencode($z)); },
            explode('/', $key)
        ));
        $canonicalQuery = '';

        $host        = parse_url($endpoint, PHP_URL_HOST);
        $payloadHash = hash('sha256', $body);

        $needsContentType = ($method === 'PUT' || $method === 'POST');

        if ($needsContentType) {
            $contentTypeHeader = $contentType !== '' ? $contentType : 'application/octet-stream';
            $canonicalHeaders  = 'content-type:' . $contentTypeHeader . "\n"
                . 'host:' . $host . "\n"
                . 'x-amz-content-sha256:' . $payloadHash . "\n"
                . 'x-amz-date:' . $now . "\n";
            $signedHeaders = 'content-type;host;x-amz-content-sha256;x-amz-date';
        } else {
            // GET / HEAD / DELETE — no Content-Type in canonical request
            $canonicalHeaders = 'host:' . $host . "\n"
                . 'x-amz-content-sha256:' . $payloadHash . "\n"
                . 'x-amz-date:' . $now . "\n";
            $signedHeaders = 'host;x-amz-content-sha256;x-amz-date';
        }

        $canonicalRequest = $method . "\n"
            . $canonicalUri . "\n"
            . $canonicalQuery . "\n"
            . $canonicalHeaders . "\n"
            . $signedHeaders . "\n"
            . $payloadHash;

        $scope        = $date . '/' . $region . '/' . $service . '/aws4_request';
        $stringToSign = "AWS4-HMAC-SHA256\n" . $now . "\n" . $scope . "\n" . hash('sha256', $canonicalRequest);

        $kDate    = s3Hmac('AWS4' . $secretKey, $date);
        $kRegion  = s3Hmac($kDate, $region);
        $kService = s3Hmac($kRegion, $service);
        $kSigning = s3Hmac($kService, 'aws4_request');
        $signature = hash_hmac('sha256', $stringToSign, $kSigning);

        $authorization = 'AWS4-HMAC-SHA256 '
            . 'Credential=' . $accessKey . '/' . $scope . ', '
            . 'SignedHeaders=' . $signedHeaders . ', '
            . 'Signature=' . $signature;

        $url = $endpoint . $canonicalUri;

        $headers = [
            'x-amz-date: ' . $now,
            'x-amz-content-sha256: ' . $payloadHash,
            'Authorization: ' . $authorization,
        ];
        if ($needsContentType) {
            $headers[] = 'Content-Type: ' . $contentTypeHeader;
        }

        if (defined('S3_DEBUG') && S3_DEBUG) {
            error_log('[S3] DEBUG canonicalRequest=' . $canonicalRequest);
            error_log('[S3] DEBUG stringToSign=' . $stringToSign);
            error_log('[S3] DEBUG url=' . $url);
            error_log('[S3] DEBUG host=' . $host);
        }

        $ch = curl_init();
        curl_setopt_array($ch, [
            CURLOPT_URL           => $url,
            CURLOPT_CUSTOMREQUEST => $method,
            CURLOPT_HTTPHEADER    => $headers,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT       => 30,
            CURLOPT_SSL_VERIFYPEER => true,
        ]);
        if ($method === 'PUT' || $method === 'POST') {
            curl_setopt($ch, CURLOPT_POSTFIELDS, $body);
        }
        if ($method === 'HEAD') {
            curl_setopt($ch, CURLOPT_NOBODY, true);
        }
        $resp   = curl_exec($ch);
        $status = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $err    = curl_error($ch);
        curl_close($ch);

        if ($resp === false) {
            error_log('[S3] Request failed: ' . $err . ' method=' . $method . ' key=' . $key);
            return ['status' => 0, 'body' => false];
        }
        return ['status' => $status, 'body' => $resp];
    }
}

if (!function_exists('s3PutFile')) {
    /**
     * Upload a local file to S3 using streaming (CURLOPT_INFILE).
     *
     * Unlike s3Put() which loads the entire file into a PHP string,
     * s3PutFile() streams the file directly from disk via curl, keeping
     * memory usage constant regardless of file size. This is essential for
     * large SCORM packages that contain video files (50-200 MB each).
     *
     * BUG FIX: The original s3Put() approach caused memory exhaustion when
     * uploading Rise 360 packages with embedded video. PHP's memory limit
     * was hit partway through the upload, causing all subsequent files
     * (including slide JS bundles) to be silently skipped.
     */
    function s3PutFile(string $localPath, string $key, string $contentType = ''): bool
    {
        if (!isS3Configured()) {
            return false;
        }
        $fileSize = filesize($localPath);
        if ($fileSize === false) {
            error_log('[S3] s3PutFile: filesize() failed for ' . $localPath);
            return false;
        }

        $bucket    = S3_BUCKET;
        $region    = S3_REGION;
        $accessKey = S3_KEY;
        $secretKey = S3_SECRET;
        $endpoint  = defined('S3_ENDPOINT') && S3_ENDPOINT !== ''
            ? rtrim(S3_ENDPOINT, '/')
            : "https://{$bucket}.s3.{$region}.amazonaws.com";

        $now  = gmdate('Ymd\THis\Z');
        $date = substr($now, 0, 8);

        $canonicalUri = '/' . implode('/', array_map(
            function ($z) { return str_replace('%7E', '~', rawurlencode($z)); },
            explode('/', $key)
        ));

        $host             = parse_url($endpoint, PHP_URL_HOST);
        $contentTypeHeader = $contentType !== '' ? $contentType : 'application/octet-stream';

        // For streaming uploads we use the special SHA256 value
        // 'UNSIGNED-PAYLOAD' so we don't need to hash the entire file body.
        $payloadHash = 'UNSIGNED-PAYLOAD';

        $canonicalHeaders = 'content-type:' . $contentTypeHeader . "\n"
            . 'host:' . $host . "\n"
            . 'x-amz-content-sha256:' . $payloadHash . "\n"
            . 'x-amz-date:' . $now . "\n";
        $signedHeaders = 'content-type;host;x-amz-content-sha256;x-amz-date';

        $canonicalRequest = "PUT\n"
            . $canonicalUri . "\n\n"
            . $canonicalHeaders . "\n"
            . $signedHeaders . "\n"
            . $payloadHash;

        $scope        = $date . '/' . $region . '/s3/aws4_request';
        $stringToSign = "AWS4-HMAC-SHA256\n" . $now . "\n" . $scope . "\n" . hash('sha256', $canonicalRequest);

        $kDate    = s3Hmac('AWS4' . $secretKey, $date);
        $kRegion  = s3Hmac($kDate, $region);
        $kService = s3Hmac($kRegion, 's3');
        $kSigning = s3Hmac($kService, 'aws4_request');
        $signature = hash_hmac('sha256', $stringToSign, $kSigning);

        $authorization = 'AWS4-HMAC-SHA256 '
            . 'Credential=' . $accessKey . '/' . $scope . ', '
            . 'SignedHeaders=' . $signedHeaders . ', '
            . 'Signature=' . $signature;

        $fh = fopen($localPath, 'rb');
        if ($fh === false) {
            error_log('[S3] s3PutFile: fopen() failed for ' . $localPath);
            return false;
        }

        $ch = curl_init();
        curl_setopt_array($ch, [
            CURLOPT_URL            => $endpoint . $canonicalUri,
            CURLOPT_CUSTOMREQUEST  => 'PUT',
            CURLOPT_UPLOAD         => true,
            CURLOPT_INFILE         => $fh,
            CURLOPT_INFILESIZE     => $fileSize,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 300,  // 5 min per file (videos can be large)
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_HTTPHEADER     => [
                'Content-Type: ' . $contentTypeHeader,
                'x-amz-date: ' . $now,
                'x-amz-content-sha256: ' . $payloadHash,
                'Authorization: ' . $authorization,
            ],
        ]);

        $resp   = curl_exec($ch);
        $status = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $err    = curl_error($ch);
        curl_close($ch);
        fclose($fh);

        if ($resp === false) {
            error_log('[S3] s3PutFile curl error: ' . $err . ' key=' . $key);
            return false;
        }
        if ($status < 200 || $status >= 300) {
            error_log('[S3] s3PutFile failed status=' . $status . ' key=' . $key . ' body=' . substr((string)$resp, 0, 200));
            return false;
        }
        error_log('[S3] s3PutFile uploaded ' . $fileSize . ' bytes to ' . $key);
        return true;
    }
}

if (!function_exists('s3Put')) {
    function s3Put(string $key, string $body, string $contentType = ''): bool
    {
        if (!isS3Configured()) {
            return false;
        }
        $r = s3Request('PUT', $key, $body, $contentType);
        if ($r['status'] < 200 || $r['status'] >= 300) {
            error_log('[S3] PUT failed status=' . $r['status'] . ' key=' . $key . ' body=' . substr((string)$r['body'], 0, 200));
            return false;
        }
        error_log('[S3] Uploaded ' . strlen($body) . ' bytes to ' . $key);
        return true;
    }
}

if (!function_exists('s3Get')) {
    function s3Get(string $key): ?string
    {
        if (!isS3Configured()) {
            return null;
        }
        $r = s3Request('GET', $key);
        if ($r['status'] < 200 || $r['status'] >= 300) {
            error_log('[S3] GET failed status=' . $r['status'] . ' key=' . $key . ' body=' . substr((string)$r['body'], 0, 200));
            return null;
        }
        return (string)$r['body'];
    }
}

if (!function_exists('s3Exists')) {
    /**
     * Check whether an S3 object exists using a HEAD request.
     *
     * FIX: Now uses HEAD (cheap, no body) instead of a full GET.
     * The canonical-request bug that previously broke HEAD has been fixed
     * in s3Request() above (Content-Type is no longer included for HEAD).
     */
    function s3Exists(string $key): bool
    {
        if (!isS3Configured()) {
            return false;
        }
        $r = s3Request('HEAD', $key);
        // 200 = exists; 404 = not found; anything else = error (treat as not found)
        return $r['status'] === 200;
    }
}

if (!function_exists('s3Delete')) {
    /**
     * Delete a single S3 object.
     */
    function s3Delete(string $key): bool
    {
        if (!isS3Configured()) {
            return false;
        }
        $r = s3Request('DELETE', $key);
        // S3 returns 204 No Content on success; 404 is also acceptable (already gone)
        if ($r['status'] === 204 || $r['status'] === 404) {
            return true;
        }
        error_log('[S3] DELETE failed status=' . $r['status'] . ' key=' . $key);
        return false;
    }
}

if (!function_exists('s3DeletePrefix')) {
    /**
     * Delete all S3 objects whose key starts with $prefix.
     *
     * Uses ListObjectsV2 + Delete Object loop. This is the correct way to
     * clean up an entire SCORM package directory from S3 when a package is
     * deleted from the admin panel.
     *
     * @param  string $prefix  e.g. "scorm-content/42/"
     * @return int             Number of objects deleted (0 if none or error)
     */
    function s3DeletePrefix(string $prefix): int
    {
        if (!isS3Configured()) {
            return 0;
        }

        $bucket    = S3_BUCKET;
        $region    = S3_REGION;
        $accessKey = S3_KEY;
        $secretKey = S3_SECRET;
        $endpoint  = defined('S3_ENDPOINT') && S3_ENDPOINT !== ''
            ? rtrim(S3_ENDPOINT, '/')
            : "https://{$bucket}.s3.{$region}.amazonaws.com";

        $host        = parse_url($endpoint, PHP_URL_HOST);
        $deleted     = 0;
        $continuationToken = null;

        do {
            // ── Build ListObjectsV2 request ──
            $now         = gmdate('Ymd\THis\Z');
            $date        = substr($now, 0, 8);
            $payloadHash = hash('sha256', '');

            $queryParams = 'list-type=2&max-keys=1000&prefix=' . rawurlencode($prefix);
            if ($continuationToken !== null) {
                $queryParams .= '&continuation-token=' . rawurlencode($continuationToken);
            }

            $canonicalHeaders = 'host:' . $host . "\n"
                . 'x-amz-content-sha256:' . $payloadHash . "\n"
                . 'x-amz-date:' . $now . "\n";
            $signedHeaders = 'host;x-amz-content-sha256;x-amz-date';

            $canonicalRequest = "GET\n/\n{$queryParams}\n{$canonicalHeaders}\n{$signedHeaders}\n{$payloadHash}";
            $scope            = $date . '/'. $region . '/s3/aws4_request';
            $stringToSign     = "AWS4-HMAC-SHA256\n{$now}\n{$scope}\n" . hash('sha256', $canonicalRequest);

            $kDate    = hash_hmac('sha256', $date,         'AWS4' . $secretKey, true);
            $kRegion  = hash_hmac('sha256', $region,       $kDate,              true);
            $kService = hash_hmac('sha256', 's3',          $kRegion,            true);
            $kSigning = hash_hmac('sha256', 'aws4_request', $kService,          true);
            $signature = hash_hmac('sha256', $stringToSign, $kSigning);

            $authorization = 'AWS4-HMAC-SHA256 '
                . 'Credential=' . $accessKey . '/' . $scope . ', '
                . 'SignedHeaders=' . $signedHeaders . ', '
                . 'Signature=' . $signature;

            $listUrl = $endpoint . '/?' . $queryParams;
            $ch = curl_init();
            curl_setopt_array($ch, [
                CURLOPT_URL           => $listUrl,
                CURLOPT_HTTPHEADER    => [
                    'x-amz-date: ' . $now,
                    'x-amz-content-sha256: ' . $payloadHash,
                    'Authorization: ' . $authorization,
                ],
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_TIMEOUT        => 30,
                CURLOPT_SSL_VERIFYPEER => true,
            ]);
            $listResp   = curl_exec($ch);
            $listStatus = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
            curl_close($ch);

            if ($listStatus !== 200 || $listResp === false) {
                error_log('[S3] s3DeletePrefix LIST failed status=' . $listStatus . ' prefix=' . $prefix);
                break;
            }

            $xml = simplexml_load_string($listResp);
            if ($xml === false) {
                error_log('[S3] s3DeletePrefix: could not parse LIST XML for prefix=' . $prefix);
                break;
            }

            foreach ($xml->Contents as $obj) {
                $objKey = (string)$obj->Key;
                if (s3Delete($objKey)) {
                    $deleted++;
                }
            }

            $isTruncated       = ((string)$xml->IsTruncated === 'true');
            $continuationToken = $isTruncated ? (string)$xml->NextContinuationToken : null;

        } while ($isTruncated);

        error_log('[S3] s3DeletePrefix done: deleted=' . $deleted . ' prefix=' . $prefix);
        return $deleted;
    }
}

if (!function_exists('s3Head')) {
    /**
     * Return the Content-Length of an S3 object without downloading it.
     * Returns -1 on error or if the object does not exist.
     */
    function s3Head(string $key): int
    {
        if (!isS3Configured()) {
            return -1;
        }

        $bucket    = S3_BUCKET;
        $region    = S3_REGION;
        $accessKey = S3_KEY;
        $secretKey = S3_SECRET;
        $endpoint  = defined('S3_ENDPOINT') && S3_ENDPOINT !== ''
            ? rtrim(S3_ENDPOINT, '/')
            : "https://{$bucket}.s3.{$region}.amazonaws.com";

        $now  = gmdate('Ymd\THis\Z');
        $date = substr($now, 0, 8);

        $canonicalUri = '/' . implode('/', array_map(
            function ($z) { return str_replace('%7E', '~', rawurlencode($z)); },
            explode('/', $key)
        ));

        $host        = parse_url($endpoint, PHP_URL_HOST);
        $payloadHash = hash('sha256', '');

        $canonicalHeaders = 'host:' . $host . "\n"
            . 'x-amz-content-sha256:' . $payloadHash . "\n"
            . 'x-amz-date:' . $now . "\n";
        $signedHeaders = 'host;x-amz-content-sha256;x-amz-date';

        $canonicalRequest = "HEAD\n{$canonicalUri}\n\n{$canonicalHeaders}\n{$signedHeaders}\n{$payloadHash}";
        $scope            = $date . '/' . $region . '/s3/aws4_request';
        $stringToSign     = "AWS4-HMAC-SHA256\n{$now}\n{$scope}\n" . hash('sha256', $canonicalRequest);

        $kDate    = s3Hmac('AWS4' . $secretKey, $date);
        $kRegion  = s3Hmac($kDate, $region);
        $kService = s3Hmac($kRegion, 's3');
        $kSigning = s3Hmac($kService, 'aws4_request');
        $signature = hash_hmac('sha256', $stringToSign, $kSigning);

        $authorization = 'AWS4-HMAC-SHA256 '
            . 'Credential=' . $accessKey . '/' . $scope . ', '
            . 'SignedHeaders=' . $signedHeaders . ', '
            . 'Signature=' . $signature;

        $ch = curl_init();
        curl_setopt_array($ch, [
            CURLOPT_URL            => $endpoint . $canonicalUri,
            CURLOPT_CUSTOMREQUEST  => 'HEAD',
            CURLOPT_NOBODY         => true,
            CURLOPT_HEADER         => true,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 10,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_HTTPHEADER     => [
                'x-amz-date: ' . $now,
                'x-amz-content-sha256: ' . $payloadHash,
                'Authorization: ' . $authorization,
            ],
        ]);
        $resp   = curl_exec($ch);
        $status = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $size   = (int)curl_getinfo($ch, CURLINFO_CONTENT_LENGTH_DOWNLOAD);
        curl_close($ch);

        if ($status !== 200) {
            return -1;
        }
        // curl CURLINFO_CONTENT_LENGTH_DOWNLOAD returns -1 when header is absent
        if ($size > 0) {
            return $size;
        }
        // Fallback: parse Content-Length from raw headers
        if (preg_match('/Content-Length:\s*(\d+)/i', (string)$resp, $m)) {
            return (int)$m[1];
        }
        return -1;
    }
}

if (!function_exists('s3GetRange')) {
    /**
     * Fetch a byte range from an S3 object.
     *
     * Sends a signed GET request with a Range header and returns the partial
     * body. Used by serve.php to support HTTP Range requests for video seeking.
     *
     * @param  string $key    S3 object key
     * @param  int    $start  First byte (inclusive)
     * @param  int    $end    Last byte (inclusive), or -1 for "to end of file"
     * @return string|null    Partial body on success, null on error
     */
    function s3GetRange(string $key, int $start, int $end = -1): ?string
    {
        if (!isS3Configured()) {
            return null;
        }

        $bucket    = S3_BUCKET;
        $region    = S3_REGION;
        $accessKey = S3_KEY;
        $secretKey = S3_SECRET;
        $endpoint  = defined('S3_ENDPOINT') && S3_ENDPOINT !== ''
            ? rtrim(S3_ENDPOINT, '/')
            : "https://{$bucket}.s3.{$region}.amazonaws.com";

        $now  = gmdate('Ymd\THis\Z');
        $date = substr($now, 0, 8);

        $canonicalUri = '/' . implode('/', array_map(
            function ($z) { return str_replace('%7E', '~', rawurlencode($z)); },
            explode('/', $key)
        ));

        $host        = parse_url($endpoint, PHP_URL_HOST);
        $payloadHash = hash('sha256', '');

        $rangeHeader = $end >= 0
            ? 'bytes=' . $start . '-' . $end
            : 'bytes=' . $start . '-';

        // Range must be included in the canonical request and signed headers
        $canonicalHeaders = 'host:' . $host . "\n"
            . 'range:' . $rangeHeader . "\n"
            . 'x-amz-content-sha256:' . $payloadHash . "\n"
            . 'x-amz-date:' . $now . "\n";
        $signedHeaders = 'host;range;x-amz-content-sha256;x-amz-date';

        $canonicalRequest = "GET\n{$canonicalUri}\n\n{$canonicalHeaders}\n{$signedHeaders}\n{$payloadHash}";
        $scope            = $date . '/' . $region . '/s3/aws4_request';
        $stringToSign     = "AWS4-HMAC-SHA256\n{$now}\n{$scope}\n" . hash('sha256', $canonicalRequest);

        $kDate    = s3Hmac('AWS4' . $secretKey, $date);
        $kRegion  = s3Hmac($kDate, $region);
        $kService = s3Hmac($kRegion, 's3');
        $kSigning = s3Hmac($kService, 'aws4_request');
        $signature = hash_hmac('sha256', $stringToSign, $kSigning);

        $authorization = 'AWS4-HMAC-SHA256 '
            . 'Credential=' . $accessKey . '/' . $scope . ', '
            . 'SignedHeaders=' . $signedHeaders . ', '
            . 'Signature=' . $signature;

        $ch = curl_init();
        curl_setopt_array($ch, [
            CURLOPT_URL            => $endpoint . $canonicalUri,
            CURLOPT_CUSTOMREQUEST  => 'GET',
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 60,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_HTTPHEADER     => [
                'Range: ' . $rangeHeader,
                'x-amz-date: ' . $now,
                'x-amz-content-sha256: ' . $payloadHash,
                'Authorization: ' . $authorization,
            ],
        ]);
        $resp   = curl_exec($ch);
        $status = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $err    = curl_error($ch);
        curl_close($ch);

        if ($resp === false) {
            error_log('[S3] s3GetRange curl error: ' . $err . ' key=' . $key . ' range=' . $rangeHeader);
            return null;
        }
        // 206 = partial content (range satisfied); 200 = full content (range ignored by server)
        if ($status !== 206 && $status !== 200) {
            error_log('[S3] s3GetRange failed status=' . $status . ' key=' . $key . ' range=' . $rangeHeader);
            return null;
        }
        return (string)$resp;
    }
}
