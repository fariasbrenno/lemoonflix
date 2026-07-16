<?php

namespace App\Listeners;

use App\Events\OrderCompleted;
use App\Services\MetaConversionsApiService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Queue\InteractsWithQueue;

class SendMetaPurchaseCapiOnOrderCompleted implements ShouldQueue
{
    use InteractsWithQueue;

    public int $tries = 3;

    public int $backoff = 30;

    public function __construct(private MetaConversionsApiService $metaConversionsApiService) {}

    public function handle(OrderCompleted $event): void
    {
        $this->metaConversionsApiService->sendPurchaseForCompletedOrder($event->order);
    }
}
