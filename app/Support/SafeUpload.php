<?php

namespace App\Support;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Str;

/**
 * EZ-REL-20261015-3-hotfix: one safe path for every user upload.
 *  - who:   logged-in session (Auth::check()) and a same-origin request
 *  - what:  type decided by the bytes (finfo), allow-list only, no HTML/SVG/scripts
 *  - where: public/upload/<area>/ under a server-generated name, extension from the detected type
 */
final class SafeUpload
{
    public const MAX_BYTES = 100 * 1024 * 1024;

    /** Detected MIME => [extension, kind]. Anything else is refused. */
    public const TYPES = [
        'image/jpeg' => ['jpg', 'image'], 'image/png' => ['png', 'image'], 'image/gif' => ['gif', 'image'],
        'image/webp' => ['webp', 'image'], 'image/avif' => ['avif', 'image'], 'image/bmp' => ['bmp', 'image'],
        'image/x-ms-bmp' => ['bmp', 'image'], 'image/vnd.microsoft.icon' => ['ico', 'image'], 'image/x-icon' => ['ico', 'image'],
        'video/mp4' => ['mp4', 'video'], 'video/webm' => ['webm', 'video'], 'video/ogg' => ['ogv', 'video'],
        'video/quicktime' => ['mov', 'video'], 'video/x-m4v' => ['m4v', 'video'], 'video/x-matroska' => ['mkv', 'video'],
        'audio/mpeg' => ['mp3', 'audio'], 'audio/mp4' => ['m4a', 'audio'], 'audio/x-m4a' => ['m4a', 'audio'],
        'audio/aac' => ['aac', 'audio'], 'audio/ogg' => ['ogg', 'audio'], 'audio/wav' => ['wav', 'audio'],
        'audio/x-wav' => ['wav', 'audio'], 'audio/vnd.wave' => ['wav', 'audio'], 'audio/flac' => ['flac', 'audio'],
        'audio/x-flac' => ['flac', 'audio'], 'audio/webm' => ['weba', 'audio'],
        'application/pdf' => ['pdf', 'pdf'],
    ];

    /** 401 for guests, 403 for cross-site writes, null when the request may upload. */
    public static function guard(Request $r): ?JsonResponse
    {
        if (!self::sameOrigin($r)) {
            return response()->json(['success' => false, 'error' => 'cross_site', 'message' => 'Cross-site upload refused.'], 403);
        }
        if (!Auth::check()) {
            return response()->json(['success' => false, 'error' => 'login_required', 'message' => 'Sign in to upload.'], 401);
        }
        return null;
    }

    /**
     * Same-origin only (CSRF is disabled app-wide, so this is the CSRF defence):
     * Sec-Fetch-Site must be same-origin/none; else the Origin (or Referer) host must be this host;
     * with neither header, the custom X-Requested-With header is required (it forces a CORS preflight).
     */
    public static function sameOrigin(Request $r): bool
    {
        $site = strtolower((string) $r->header('sec-fetch-site', ''));
        if ($site !== '') {
            return in_array($site, ['same-origin', 'none'], true);
        }
        $src = (string) ($r->header('origin') ?: $r->header('referer', ''));
        if ($src !== '' && $src !== 'null') {
            $host = parse_url($src, PHP_URL_HOST);
            return is_string($host) && strcasecmp($host, $r->getHost()) === 0;
        }
        return strcasecmp((string) $r->header('x-requested-with', ''), 'XMLHttpRequest') === 0;
    }

    /** Detect the type of a file on disk. Returns [mime, ext, kind] or an error string. */
    public static function inspect(string $path)
    {
        $size = @filesize($path);
        if ($size === false || $size <= 0) {
            return 'Empty file.';
        }
        if ($size > self::MAX_BYTES) {
            return 'File exceeds the 100 MB limit.';
        }
        $mime = strtolower((string) (new \finfo(FILEINFO_MIME_TYPE))->file($path));
        $h = @fopen($path, 'rb');
        $head = $h ? (string) fread($h, 65536) : '';
        $tail = '';
        if ($h && $size > 65536) {
            fseek($h, -65536, SEEK_END);
            $tail = (string) fread($h, 65536);
        }
        if ($h) {
            fclose($h);
        }
        // Script markers anywhere in the head/tail refuse the file, whatever finfo says (polyglots).
        if (preg_match('/<\?(php|=)|<script[\s>]|<!doctype\s+html|<html[\s>]|<svg[\s>]/i', $head . "\n" . $tail)) {
            return 'Scripts, HTML and SVG are not accepted.';
        }
        if (!isset(self::TYPES[$mime])) {
            return "File type not allowed ({$mime}). Images, video, audio and PDF only.";
        }
        [$ext, $kind] = self::TYPES[$mime];
        return [$mime, $ext, $kind];
    }

    /**
     * Validate and store an uploaded file (or raw bytes) under public/upload/<area>/.
     * Returns ['url','name','mime_type','kind','size','extension'] or an error string.
     */
    public static function store($fileOrBytes, string $area)
    {
        $area = trim(preg_replace('#[^a-zA-Z0-9_/\-]#', '_', $area), '/');
        if ($area === '' || str_contains($area, '..')) {
            return 'Bad upload area.';
        }
        $tmp = null;
        if ($fileOrBytes instanceof UploadedFile) {
            if (!$fileOrBytes->isValid()) {
                return 'File upload failed: ' . $fileOrBytes->getErrorMessage();
            }
            $src = $fileOrBytes->getPathname();
        } else {
            if (strlen((string) $fileOrBytes) > self::MAX_BYTES) {
                return 'File exceeds the 100 MB limit.';
            }
            $tmp = tempnam(sys_get_temp_dir(), 'ezup');
            file_put_contents($tmp, (string) $fileOrBytes);
            $src = $tmp;
        }
        $info = self::inspect($src);
        if (!is_array($info)) {
            if ($tmp) @unlink($tmp);
            return $info;
        }
        [$mime, $ext, $kind] = $info;
        $dir = public_path('upload/' . $area);
        if (!is_dir($dir)) {
            @mkdir($dir, 0755, true);
        }
        $name = 'u' . (int) Auth::id() . '_' . time() . '_' . Str::lower(Str::random(16)) . '.' . $ext;
        if ($tmp) {
            rename($tmp, $dir . '/' . $name);
        } else {
            $fileOrBytes->move($dir, $name);
        }
        @chmod($dir . '/' . $name, 0644);
        return [
            'url'       => '/upload/' . $area . '/' . $name,
            'name'      => $name,
            'extension' => $ext,
            'mime_type' => $mime,
            'kind'      => $kind,
            'size'      => (int) @filesize($dir . '/' . $name),
        ];
    }
}
