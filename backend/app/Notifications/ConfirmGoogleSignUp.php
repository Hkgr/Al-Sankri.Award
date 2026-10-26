<?php

namespace App\Notifications;

use App\Auth\AccountEmail;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * Sent when someone starts a Google sign-up with an address Google is not
 * authoritative for. The link only completes the sign-up in the browser
 * that started it, so the mailbox owner and the Google account holder must
 * be the same person; otherwise nothing is created.
 */
class ConfirmGoogleSignUp extends Notification
{
    public function __construct(private readonly string $url) {}

    /**
     * Get the notification's delivery channels.
     *
     * @return array<int, string>
     */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    /**
     * Get the mail representation of the notification.
     */
    public function toMail(object $notifiable): MailMessage
    {
        return AccountEmail::message(
            subject: 'تأكيد التسجيل عبر Google',
            heading: 'أكّد بريدك لإكمال التسجيل عبر Google',
            intro: 'بدأ أحدهم إنشاء حساب في موقع '.AccountEmail::AWARD_NAME.' بحساب Google يستخدم هذا البريد. إن كنت أنت، فافتح الرابط في المتصفح نفسه الذي بدأت منه التسجيل.',
            action: 'تأكيد البريد وإكمال التسجيل',
            url: $this->url,
            outro: 'صلاحية الرابط 60 دقيقة. إذا لم تكن أنت، فتجاهل هذه الرسالة: لن يُنشأ أي حساب.',
        );
    }
}
