<?php

namespace App\Http\Controllers\Account;

use App\Backups\BackupPolicy;
use App\Connector\DisconnectWebsite;
use App\Connector\PairingCodes;
use App\Http\Controllers\Controller;
use App\Models\Website;
use App\Pricing\ProductType;
use App\Rules\PublicWebsiteUrl;
use App\Websites\ConnectorStatus;
use App\Websites\WebsiteStatus;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
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
            'url' => ['required', 'string', 'max:255', 'url:http,https', new PublicWebsiteUrl, Rule::unique('websites')->where('customer_id', $customer->id)],
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

        $website->load(['activeConnector', 'inventory']);

        return view('account.websites.show', [
            'website' => $website,
            'connector' => $website->activeConnector,
            'inventory' => $website->inventory,
            'jobs' => $website->jobs()->with('backup')->latest('id')->limit(10)->get(),
            'canPair' => request()->user()->can('pair', $website),
            'latestBackup' => $website->backups()->latest('id')->first(),
            'latestVerified' => $website->backups()->verified()->whereNull('objects_deleted_at')->latest('verified_at')->first(),
            'backupPolicy' => app(BackupPolicy::class)->for($website),
            'canBackup' => request()->user()->can('backup', $website),
            'canAdminAccess' => request()->user()->can('adminAccess', $website) && $website->isConnected(),
            'pairingCode' => $this->flashedPairingCode(),
        ]);
    }

    /**
     * Issue a one-time pairing code. It is shown once, via the session
     * (never in a URL), and only its hash is stored.
     */
    public function pairingCode(Request $request, Website $website, PairingCodes $codes): RedirectResponse
    {
        $this->authorize('pair', $website);

        $code = $codes->issue($website, $request->user());

        return redirect()->route('account.websites.show', $website)
            ->with('pairing_code', ['code' => $code, 'expires_at' => now()->addSeconds(config('connector.pairing_code_ttl'))->toIso8601String()]);
    }

    /** Revoke the website's connector. It cannot reconnect without a new pairing code. */
    public function disconnect(Request $request, Website $website, DisconnectWebsite $disconnect): RedirectResponse
    {
        $this->authorize('disconnect', $website);

        $disconnect($website, 'disconnected_by_customer', 'customer:'.$request->user()->id);

        return redirect()->route('account.websites.show', $website)->with('status', __('connector.account.disconnected'));
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

    /**
     * The code generated on the previous request (flashed, shown once).
     * Sessions are JSON-serialised, so the expiry travels as a string.
     *
     * @return array{code: string, expires_at: Carbon}|null
     */
    private function flashedPairingCode(): ?array
    {
        $flashed = session('pairing_code');

        if (! is_array($flashed) || ! is_string($flashed['code'] ?? null) || ! is_string($flashed['expires_at'] ?? null)) {
            return null;
        }

        return ['code' => $flashed['code'], 'expires_at' => Carbon::parse($flashed['expires_at'])];
    }
}
