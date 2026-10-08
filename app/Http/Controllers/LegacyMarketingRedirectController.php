<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;

final class LegacyMarketingRedirectController extends Controller
{
    private const SAFE_QUERY_KEYS = [
        'product', 'plan', 'interval', 'currency', 'utm_source', 'utm_medium', 'utm_campaign',
        'utm_term', 'utm_content', 'gclid', 'dclid', 'fbclid', 'msclkid',
    ];

    public function home(Request $request)
    {
        return $this->redirect($request, '/en/');
    }

    public function pricing(Request $request)
    {
        return $this->redirect($request, '/en/pricing');
    }

    private function redirect(Request $request, string $path)
    {
        $query = [];
        foreach ($request->query() as $key => $value) {
            if (in_array((string) $key, self::SAFE_QUERY_KEYS, true) && is_scalar($value)) {
                $query[$key] = mb_substr((string) $value, 0, 200);
            }
        }

        $configured = parse_url((string) config('app.url')) ?: [];
        $origin = (string) ($configured['scheme'] ?? 'https').'://'.(string) ($configured['host'] ?? 'localhost');
        if (isset($configured['port'])) {
            $origin .= ':'.(int) $configured['port'];
        }

        return response()->redirectTo($origin.$path.($query === [] ? '' : '?'.http_build_query($query)), 301);
    }
}
