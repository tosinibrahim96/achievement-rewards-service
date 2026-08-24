<?php

declare(strict_types=1);

namespace App\Notifications;

use App\Enums\Currency;
use App\Models\User;
use Illuminate\Contracts\Queue\ShouldQueueAfterCommit;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

final class CashbackRewardNeedsPayoutAccount extends Notification implements ShouldQueueAfterCommit
{
    use Queueable;

    public function __construct(
        public readonly string $badgeName,
        public readonly int $amountMinor,
        public readonly Currency $currency,
    ) {}

    /** @return list<string> */
    public function via(User $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(User $notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject('Add a payout account for your cashback reward')
            ->line("You earned a {$this->formattedAmount()} cashback reward for the {$this->badgeName} badge.")
            ->line('Add a payout account to receive this reward.');
    }

    private function formattedAmount(): string
    {
        return sprintf(
            '%s %d.%02d',
            $this->currency->value,
            intdiv($this->amountMinor, 100),
            $this->amountMinor % 100,
        );
    }
}
