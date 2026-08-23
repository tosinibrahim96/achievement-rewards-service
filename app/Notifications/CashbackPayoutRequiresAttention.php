<?php

declare(strict_types=1);

namespace App\Notifications;

use App\Data\Cashback\CashbackPayoutSupportRequest;
use Illuminate\Contracts\Queue\ShouldQueueAfterCommit;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Notifications\AnonymousNotifiable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

final class CashbackPayoutRequiresAttention extends Notification implements ShouldQueueAfterCommit
{
    use Queueable;

    public function __construct(
        private readonly CashbackPayoutSupportRequest $request,
    ) {}

    /** @return list<string> */
    public function via(AnonymousNotifiable $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(AnonymousNotifiable $notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject('Cashback payout requires attention')
            ->line("Cashback reward #{$this->request->cashbackRewardId} requires support review.")
            ->line("Payout attempt: #{$this->request->payoutAttemptId}")
            ->line("Issue: {$this->request->issue->value}")
            ->line("Reason: {$this->request->issue->reason()}")
            ->line("Next action: {$this->request->issue->nextAction()}");
    }
}
