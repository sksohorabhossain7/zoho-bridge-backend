<?php

namespace App\Jobs;

use App\Models\ZohoToken;
use App\Models\CustomerSettings;
use App\Models\SyncedCustomer;
use App\Services\ZohoService;
use App\Services\ShopifyService;
use App\Services\SyncManager;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Exception;
use Illuminate\Support\Facades\Log;

class ImportZohoCustomersJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    protected $shopDomain;

    /**
     * Create a new job instance.
     *
     * @param string $shopDomain
     */
    public function __construct(string $shopDomain)
    {
        $this->shopDomain = $shopDomain;
    }

    /**
     * Execute the job.
     */
    public function handle(ZohoService $zohoService, ShopifyService $shopifyService, SyncManager $syncManager): void
    {
        Log::info("Job started: ImportZohoCustomersJob for shop {$this->shopDomain}");

        $token = ZohoToken::where('shop', $this->shopDomain)->first();
        if (!$token) {
            $syncManager->createLog($this->shopDomain, 'customer_import', 'error', 'Import failed: connected shop not found', '');
            throw new Exception("Shop not found or not connected to Zoho: {$this->shopDomain}");
        }

        $settings = CustomerSettings::where('shop', $this->shopDomain)->first();
        if (!$settings || $settings->sync_direction !== 'zoho-to-shopify') {
            $syncManager->createLog($this->shopDomain, 'customer_import', 'error', 'Import aborted: Sync direction is not Zoho to Shopify', '');
            return;
        }

        $syncManager->createLog($this->shopDomain, 'customer_import', 'success', 'Customer import from Zoho to Shopify started in the background.', '');

        try {
            $accessToken = $shopifyService->getAccessToken($this->shopDomain);
            
            $page = 1;
            $perPage = 50;
            $importedCount = 0;
            $failedCount = 0;

            do {
                [$contacts, $pageContext] = $zohoService->fetchContactsPaginated($this->shopDomain, $token->organizationID, $page, $perPage);
                
                if (empty($contacts)) {
                    break;
                }

                foreach ($contacts as $contactShort) {
                    $zohoContactId = $contactShort['contact_id'];
                    $contactName = $contactShort['contact_name'] ?? '';

                    try {
                        // Retrieve full details of the contact to get billing/shipping address and contact persons list
                        $zohoCustomer = $zohoService->fetchContactById($this->shopDomain, $token->organizationID, $zohoContactId);
                        if (!$zohoCustomer) {
                            Log::warning("Skipping Zoho contact ID {$zohoContactId}: Could not fetch full details");
                            continue;
                        }

                        // Determine primary contact person's name, email, and phone
                        $primaryContactPerson = null;
                        if (!empty($zohoCustomer['contact_persons'])) {
                            foreach ($zohoCustomer['contact_persons'] as $cp) {
                                if (!empty($cp['is_primary_contact'])) {
                                    $primaryContactPerson = $cp;
                                    break;
                                }
                            }
                            if (!$primaryContactPerson) {
                                $primaryContactPerson = $zohoCustomer['contact_persons'][0];
                            }
                        }

                        $firstName = $primaryContactPerson['first_name'] ?? $zohoCustomer['first_name'] ?? '';
                        $lastName = $primaryContactPerson['last_name'] ?? $zohoCustomer['last_name'] ?? '';
                        $email = $primaryContactPerson['email'] ?? $zohoCustomer['email'] ?? '';
                        $phone = $primaryContactPerson['phone'] ?? $zohoCustomer['phone'] ?? '';

                        // In Shopify, email or phone is required to create a customer
                        if (empty($email) && empty($phone)) {
                            Log::warning("Skipping Zoho contact ID {$zohoContactId}: Email or Phone is required to import to Shopify");
                            $failedCount++;
                            $syncManager->createLog($this->shopDomain, 'customer_import', 'error', "Skipped contact '{$contactName}': missing both email and phone number.", "Zoho Contact ID: {$zohoContactId}");
                            continue;
                        }

                        // Prepare Customer Input payload
                        $customerInput = [
                            'firstName' => $firstName,
                            'lastName' => $lastName,
                        ];

                        if (!empty($email)) {
                            $customerInput['email'] = $email;
                        }

                        if (!empty($phone)) {
                            $customerInput['phone'] = $phone;
                        }

                        // Map Address Data (Billing & Shipping)
                        $addresses = [];
                        
                        if (!empty($zohoCustomer['billing_address'])) {
                            $billing = $zohoCustomer['billing_address'];
                            if (!empty($billing['address']) || !empty($billing['city']) || !empty($billing['country'])) {
                                $addresses[] = [
                                    'address1' => $billing['address'] ?? '',
                                    'address2' => $billing['street2'] ?? '',
                                    'city' => $billing['city'] ?? '',
                                    'province' => $billing['state'] ?? '',
                                    'country' => $billing['country'] ?? '',
                                    'zip' => $billing['zip'] ?? '',
                                    'firstName' => $firstName,
                                    'lastName' => $lastName,
                                    'phone' => !empty($billing['phone']) ? $billing['phone'] : $phone,
                                ];
                            }
                        }

                        if (!empty($zohoCustomer['shipping_address'])) {
                            $shipping = $zohoCustomer['shipping_address'];
                            if (!empty($shipping['address']) || !empty($shipping['city']) || !empty($shipping['country'])) {
                                $addresses[] = [
                                    'address1' => $shipping['address'] ?? '',
                                    'address2' => $shipping['street2'] ?? '',
                                    'city' => $shipping['city'] ?? '',
                                    'province' => $shipping['state'] ?? '',
                                    'country' => $shipping['country'] ?? '',
                                    'zip' => $shipping['zip'] ?? '',
                                    'firstName' => $firstName,
                                    'lastName' => $lastName,
                                    'phone' => !empty($shipping['phone']) ? $shipping['phone'] : $phone,
                                ];
                            }
                        }

                        if (!empty($addresses)) {
                            $customerInput['addresses'] = $addresses;
                        }

                        // Check DB Mapping
                        $mapping = SyncedCustomer::where('shop', $this->shopDomain)
                            ->where('zoho_contact_id', $zohoContactId)
                            ->first();

                        $shopifyCustomerId = null;

                        if ($mapping) {
                            $shopifyCustomerId = $mapping->shopify_customer_id;
                        } else {
                            // Search Shopify by email to find existing customer accounts to link
                            if (!empty($email)) {
                                $shopifyCustomerId = $shopifyService->findCustomerByEmail($this->shopDomain, $accessToken, $email);
                            }
                        }

                        // Create or Update in Shopify
                        $shopifyCustomerId = $shopifyService->syncCustomer($this->shopDomain, $accessToken, $customerInput, $shopifyCustomerId);

                        // Save Mapping
                        SyncedCustomer::updateOrCreate(
                            ['shop' => $this->shopDomain, 'zoho_contact_id' => $zohoContactId],
                            [
                                'shopify_customer_id' => $shopifyCustomerId,
                                'email' => $email,
                                'phone' => $phone,
                            ]
                        );

                        $importedCount++;
                    } catch (Exception $innerEx) {
                        $failedCount++;
                        Log::error("Failed to import Zoho customer ID {$zohoContactId}: " . $innerEx->getMessage());
                        $syncManager->createLog($this->shopDomain, 'customer_import', 'error', "Failed to import Zoho customer '{$contactName}': " . $innerEx->getMessage(), "Zoho ID: {$zohoContactId}");
                    }
                }

                $hasNextPage = $pageContext['has_more_page'] ?? false;
                $page++;

            } while ($hasNextPage);

            $syncManager->createLog(
                $this->shopDomain, 
                'customer_import', 
                'success', 
                "Customer import completed successfully. Imported: {$importedCount}, Failed: {$failedCount}.", 
                ""
            );

        } catch (Exception $e) {
            Log::error("Customer import job encountered a critical error: " . $e->getMessage());
            $syncManager->createLog($this->shopDomain, 'customer_import', 'error', "Customer import failed: " . $e->getMessage(), $e->getTraceAsString());
        }

        Log::info("Job finished: ImportZohoCustomersJob completed successfully");
    }
}
