<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

class WebhookController extends Controller
{
    public function index(Request $request)
    {
        Log::debug(json_encode($request->all()));

        return response()->json([
            "message" => "Webhook received successfully",
            "data" => $request->all()
        ], 200);
    }
}
