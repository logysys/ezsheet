<?php

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

/** EZ-LOCK-20261017 P2: tells the OWNER that a signed-in visitor asks for the password of a private slug. */
class SlugPasswordRequestMail extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(
        public string $requesterName,
        public string $requesterEmail,
        public string $pageUrl,
        public string $note,
        public string $settingsUrl,
    ) {
    }

    public function envelope(): Envelope
    {
        return new Envelope(subject: 'Password request for your private page / 私人頁面密碼請求', replyTo: [$this->requesterEmail]);
    }

    public function content(): Content
    {
        $n = e($this->requesterName);
        $em = e($this->requesterEmail);
        $p = e($this->pageUrl);
        $note = $this->note !== '' ? '<blockquote style="margin:0 0 16px;padding:8px 12px;border-left:3px solid #e5e7eb;color:#374151">' . nl2br(e($this->note)) . '</blockquote>' : '';
        $s = e($this->settingsUrl);
        $html = '<!doctype html><html><body style="margin:0;background:#f6f7f9;font-family:-apple-system,Segoe UI,Arial,sans-serif;color:#111">'
            . '<div style="max-width:520px;margin:24px auto;background:#fff;border-radius:12px;padding:28px;border:1px solid #e5e7eb">'
            . '<h1 style="font-size:18px;margin:0 0 12px">' . $n . ' (' . $em . ') asks for the password</h1>'
            . '<p style="font-size:14px;margin:0 0 12px">Page: <a href="' . $p . '">' . $p . '</a></p>'
            . $note
            . '<p style="font-size:14px;line-height:1.5;margin:0 0 12px">If you want to share it, reply to this email with the password. ez.wiki never sends the password for you.</p>'
            . '<p style="font-size:14px;line-height:1.5;margin:0 0 16px">' . $n . ' 想取得此私人頁面的密碼。如願意分享，請直接回覆此郵件告知密碼（ez.wiki 不會代為傳送密碼）。</p>'
            . '<p style="font-size:13px;margin:0"><a href="' . $s . '">Manage access / 管理存取</a></p>'
            . '</div></body></html>';
        return new Content(htmlString: $html);
    }
}
