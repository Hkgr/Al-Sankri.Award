<?php

namespace App\Jobs;

use App\Candidates\ProgressNotifications;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

class DeliverProgressEmail implements ShouldQueue
{
    use Queueable;

    /**
     * Create a new job instance.
     */
    public int $tries = 20;

    public int $timeout = 60;

    public function __construct(public int $notificationId) {}

    /**
     * Execute the job.
     */
    public function handle(ProgressNotifications $notifications): void
    {
        $delay = $notifications->deliver($this->notificationId);
        if ($delay !== null) {
            $this->release($delay);
        }
    }
}
