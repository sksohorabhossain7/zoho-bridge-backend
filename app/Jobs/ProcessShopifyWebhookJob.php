<?php

namespace App\Jobs;

use App\Models\ZohoToken;
use App\Services\SyncManager;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Exception;

class ProcessShopifyWebhookJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    protected string $shopDomain;
    protected string $topic;
    protected array $payload;

    /**
     * Create a new job instance.
     *
     * @param string $shopDomain
     * @param string $topic
     * @param array $payload
     */
    public function __construct(string $shopDomain, string $topic, array $payload)
    {
        $this->shopDomain = $shopDomain;
        $this->topic = $topic;
        $this->payload = $payload;
    }

    /**
     * Execute the job.
     *
     * @param SyncManager $syncManager
     * @return void
     * @throws Exception
     */
    public function handle(SyncManager $syncManager): void
    {
        Log::info("Job started: Processing webhook {$this->topic} for shop {$this->shopDomain}");

        $token = ZohoToken::where('shop', $this->shopDomain)->first();
        if (!$token) {
            Log::error("Job failed: Zoho token record not found for shop: {$this->shopDomain}");
            throw new Exception("Zoho token record not found for shop: {$this->shopDomain}");
        }

        if (empty($token->organizationID)) {
            Log::error("Job failed: Zoho Organization ID is not set for shop: {$this->shopDomain}");
            throw new Exception("Zoho Organization ID is not set for shop: {$this->shopDomain}");
        }

        if (in_array($this->topic, ['products/create', 'products/update', 'PRODUCTS_CREATE', 'PRODUCTS_UPDATE'])) {
            $syncManager->syncSingleShopifyProduct($token, $this->payload);
            Log::info("Job finished: Successfully synced Shopify product variant changes to Zoho.");
        } elseif (in_array($this->topic, ['customers/create', 'customers/update', 'CUSTOMERS_CREATE', 'CUSTOMERS_UPDATE'])) {
            $syncManager->syncSingleShopifyCustomer($token, $this->payload);
            Log::info("Job finished: Successfully synced Shopify customer changes to Zoho.");
        } else {
            Log::warning("Job skipped: Unhandled webhook topic {$this->topic}");
        }
    }
}
