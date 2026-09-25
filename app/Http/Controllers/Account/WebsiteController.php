<?php

namespace App\Http\Controllers\Account;

use App\Http\Controllers\Controller;
use App\Models\Website;
use App\Pricing\ProductType;
use App\Websites\ConnectorStatus;
use App\Websites\WebsiteStatus;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class WebsiteController extends Controller
{
    public function index(Request $request): View
    {
        $customer = $request->attributes->get('customer');

        return view('account.websites.index', [
            'websites' => $customer->websites()->latest('id')->get(),
            'hasAccess' => $customer->hasActiveSubscription(),
            'canAddWebsite' => $request->user()->can('create', Website::class),
        ]);
    }

    public function create(Request $request): View
    {
        $this->authorize('create', Website::class);

        $subscription = $request->attributes->get('customer')->subscriptionAvailableForWebsite();

        return view('account.websites.create', ['defaultType' => $subscription->product_type]);
    }

    public function store(Request $request): RedirectResponse
    {
        $this->authorize('create', Website::class);

        $customer = $request->attributes->get('customer');
        $request->merge(['url' => $this->normalizeUrl((string) $request->input('url'))]);

        $validated = $request->validate([
            'url' => ['required', 'string', 'max:255', 'url:http,https', Rule::unique('websites')->where('customer_id', $customer->id)],
            'type' => ['required', Rule::enum(ProductType::class)],
        ], ['url.unique' => __('account.websites.duplicate')]);

        $website = $customer->websites()->create([
            'url' => $validated['url'],
            'type' => $validated['type'],
            'subscription_id' => $customer->subscriptionAvailableForWebsite()->id,
            'status' => WebsiteStatus::Pending,
            'connector_status' => ConnectorStatus::NotInstalled,
        ]);

        return redirect()->route('account.websites.show', $website)->with('status', __('account.onboarding.created'));
    }

    public function show(Website $website): View
    {
        $this->authorize('view', $website);

        return view('account.websites.show', ['website' => $website]);
    }

    /** "Company.example/" → "https://company.example" (scheme added, host lower-cased, trailing slash removed). */
    private function normalizeUrl(string $url): string
    {
        $url = trim($url);

        if ($url !== '' && ! preg_match('#^https?://#i', $url)) {
            $url = 'https://'.$url;
        }

        $parts = parse_url($url);

        if (! $parts || empty($parts['host'])) {
            return $url;
        }

        $path = rtrim($parts['path'] ?? '', '/');

        return strtolower($parts['scheme']).'://'.strtolower($parts['host']).(isset($parts['port']) ? ':'.$parts['port'] : '').$path;
    }
}
