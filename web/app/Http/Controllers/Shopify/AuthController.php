<?php

namespace App\Http\Controllers\Shopify;

use App\Http\Controllers\Controller;
use App\Lib\AuthRedirection;
use App\Lib\EnsureBilling;
use App\Models\Session;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Log;
use Shopify\Auth\OAuth;
use Shopify\Utils;
use Shopify\Webhooks\Registry;
use Shopify\Webhooks\Topics;

/**
 * @author Muhammad Huzaifa <me@muhammadhuzaifa.pro>
 */
class AuthController extends Controller
{
    public function index(Request $request)
    {
        $shop = Utils::sanitizeShopDomain($request->query('shop'));

        // Delete any previously created OAuth sessions that were not completed (don't have an access token)
        Session::where('shop', $shop)->where('access_token', null)->delete();

        return AuthRedirection::redirect($request);
    }

    public function callback(Request $request)
    {
        $session = OAuth::callback(
            $request->cookie(),
            $request->query(),
            ['App\Lib\CookieHandler', 'saveShopifyCookie'],
        );

        $host = $request->query('host');
        $shop = Utils::sanitizeShopDomain($request->query('shop'));

        $response = Registry::register('/api/webhooks', Topics::APP_UNINSTALLED, $shop, $session->getAccessToken());
        if ($response->isSuccess()) {
            Log::debug("Registered APP_UNINSTALLED webhook for shop $shop");
        } else {
            Log::error(
                "Failed to register APP_UNINSTALLED webhook for shop $shop with response body: ".
                    print_r($response->getBody(), true)
            );
        }

        $redirectUrl = Utils::getEmbeddedAppUrl($host);
        if (Config::get('shopify.billing.required')) {
            [$hasPayment, $confirmationUrl] = EnsureBilling::check($session, Config::get('shopify.billing'));

            if (! $hasPayment) {
                $redirectUrl = $confirmationUrl;
            }
        }

        return redirect($redirectUrl);
    }
}
