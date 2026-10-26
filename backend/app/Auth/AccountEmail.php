<?php

namespace App\Auth;

use Illuminate\Notifications\Messages\MailMessage;

/**
 * The short Arabic, right-to-left account emails (verification, password
 * reset, Google sign-up confirmation), built on one template.
 */
class AccountEmail
{
    public const AWARD_NAME = 'جائزة الدكتور عبد القادر سنكري';

    public static function message(string $subject, string $heading, string $intro, string $action, string $url, string $outro): MailMessage
    {
        return (new MailMessage)
            ->subject($subject.' | '.self::AWARD_NAME)
            ->view(['mail.account-action', 'mail.account-action-text'], [
                'heading' => $heading,
                'intro' => $intro,
                'actionText' => $action,
                'actionUrl' => $url,
                'outro' => $outro,
                'awardName' => self::AWARD_NAME,
            ]);
    }
}
