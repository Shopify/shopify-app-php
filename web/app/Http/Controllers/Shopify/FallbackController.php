<?php

namespace App\Http\Controllers\Shopify;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Shopify\Context;
use Shopify\Utils;

/**
 * @author Muhammad Huzaifa <me@muhammadhuzaifa.pro>
 */
class FallbackController extends Controller
{
    public function __invoke(Request $request)
    {
        if (Context::$IS_EMBEDDED_APP && $request->query('embedded', false) === '1') {
            if (env('APP_ENV') === 'production') {
                return file_get_contents(public_path('index.html'));
            } else {
                return file_get_contents(base_path('frontend/index.html'));
            }
        }

        return redirect(Utils::getEmbeddedAppUrl($request->query('host', null)).'/'.$request->path());
    }
}
