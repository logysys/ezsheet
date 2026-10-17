<?php

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

/** EZ-LOCK-20261017 P2: one-time code + link to open a private page. Never contains page content or title. */
class SlugUnlockMail extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(public string $url, public string $code, public int $minutes = 10)
    {
    }

    public function envelope(): Envelope
    {
        return new Envelope(subject: 'Your ez.wiki access code / 存取碼');
    }

    public function content(): Content
    {
        $u = e($this->url);
        $c = e($this->code);
        $m = (int) $this->minutes;
        $html = '<!doctype html><html><body style="margin:0;background:#f6f7f9;font-family:-apple-system,Segoe UI,Arial,sans-serif;color:#111">'
            . '<div style="max-width:480px;margin:24px auto;background:#fff;border-radius:12px;padding:28px;border:1px solid #e5e7eb">'
            . '<h1 style="font-size:20px;margin:0 0 12px">Open the private page</h1>'
            . '<p style="font-size:14px;line-height:1.5;margin:0 0 20px">Click the button, or enter the code on the page. Both work once and expire in ' . $m . ' minutes. '
            . 'The page owner will see that this email address opened the page.</p>'
            . '<p style="margin:0 0 24px"><a href="' . $u . '" style="display:inline-block;background:#0E7C7B;color:#fff;text-decoration:none;font-weight:700;padding:12px 22px;border-radius:999px">Open page</a></p>'
            . '<p style="font-size:28px;font-weight:800;letter-spacing:6px;margin:0 0 20px">' . $c . '</p>'
            . '<p style="font-size:13px;line-height:1.5;margin:0 0 16px">點擊按鈕或在頁面輸入驗證碼即可開啟私人頁面（' . $m . ' 分鐘內有效，限用一次）。頁面擁有者會看到此電子郵件地址曾開啟該頁面。</p>'
            . '<p style="font-size:12px;color:#6b7280;margin:0">If you did not ask for this, ignore this email.</p>'
            . '</div></body></html>';
        return new Content(htmlString: $html);
    }
}
