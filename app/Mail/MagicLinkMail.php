<?php

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

/** Item 21: sign-in link + 6-digit backup code. */
class MagicLinkMail extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(public string $url, public string $code, public int $minutes = 15)
    {
    }

    public function envelope(): Envelope
    {
        return new Envelope(subject: 'Your ez.wiki sign-in link');
    }

    public function content(): Content
    {
        $u = e($this->url);
        $c = e($this->code);
        $m = (int) $this->minutes;
        $html = '<!doctype html><html><body style="margin:0;background:#f6f7f9;font-family:-apple-system,Segoe UI,Arial,sans-serif;color:#111">'
            . '<div style="max-width:480px;margin:24px auto;background:#fff;border-radius:12px;padding:28px;border:1px solid #e5e7eb">'
            . '<h1 style="font-size:20px;margin:0 0 12px">Sign in to ez.wiki</h1>'
            . '<p style="font-size:14px;line-height:1.5;margin:0 0 20px">Click the button to sign in. The link works once and expires in ' . $m . ' minutes.</p>'
            . '<p style="margin:0 0 24px"><a href="' . $u . '" style="display:inline-block;background:#0E7C7B;color:#fff;text-decoration:none;font-weight:700;padding:12px 22px;border-radius:999px">Sign in</a></p>'
            . '<p style="font-size:13px;margin:0 0 6px">Or enter this code in the sign-in box:</p>'
            . '<p style="font-size:28px;font-weight:800;letter-spacing:6px;margin:0 0 20px">' . $c . '</p>'
            . '<p style="font-size:12px;color:#6b7280;margin:0">If you did not ask to sign in, ignore this email. Nobody can sign in without this link or code.</p>'
            . '<p style="font-size:11px;color:#9ca3af;margin:16px 0 0;word-break:break-all">' . $u . '</p>'
            . '</div></body></html>';
        return new Content(htmlString: $html);
    }
}
