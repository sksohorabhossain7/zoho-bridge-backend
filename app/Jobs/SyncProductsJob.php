<?php

namespace App\Jobs;

use App\Models\ZohoToken;
use App\Models\ProductSettings;
use App\Services\SyncManager;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Exception;
use Illuminate\Support\Facades\Log;

class SyncProductsJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    protected $shopDomain;
    protected $zohoItemID;

    /**
     * Create a new job instance.
     *
     * @param string $shopDomain
     * @param string|null $zohoItemID
     */
    public function __construct(string $shopDomain, ?string $zohoItemID = null)
    {
        $this->shopDomain = $shopDomain;
        $this->zohoItemID = $zohoItemID;
    }

    /**
     * Execute the job.
     *
     * @param SyncManager $syncManager
     * @return void
     */
    public function handle(SyncManager $syncManager): void
    {
        Log::info("Job started: SyncProductsJob for shop {$this->shopDomain}" . ($this->zohoItemID ? " (Item ID: {$this->zohoItemID})" : ""));

        $token = ZohoToken::where('shop', $this->shopDomain)->first();
        if (!$token) {
            throw new Exception("Shop not found or not connected to Zoho: {$this->shopDomain}");
        }

        if (!empty($this->zohoItemID)) {
            $syncManager->syncSingleZohoItem($token, $this->zohoItemID);
        } else {
            $settings = ProductSettings::where('shop', $token->shop)->first();
            if ($settings && $settings->sync_direction === 'shopify-to-zoho') {
                $syncManager->syncShopifyProductsToZoho($token);
            } else {
                $syncManager->syncShopProducts($token);
            }
        }

        Log::info("Job finished: SyncProductsJob completed successfully");
    }
}
