<?php

namespace App\Providers;

use Illuminate\Support\ServiceProvider;
use Illuminate\Http\Client\Events\ResponseReceived;
use Illuminate\Support\Facades\Event;
use App\Models\ZohoToken;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        Event::listen(ResponseReceived::class, function (ResponseReceived $event) {
            if (str_contains($event->request->url(), '/books/v3/')) {
                $headers = $event->request->headers();
                $shopDomain = $headers['X-Shop-Domain'][0] ?? null;

                if ($shopDomain) {
                    $limit = $event->response->header('X-RateLimit-Limit');
                    $remaining = $event->response->header('X-RateLimit-Remaining');

                    if ($limit !== null && $remaining !== null) {
                        ZohoToken::where('shop', $shopDomain)->update([
                            'api_calls_limit' => (int) $limit,
                            'api_calls_remaining' => (int) $remaining,
                        ]);
                    }
                }
            }
        });
    }
}
