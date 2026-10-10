<?php

namespace App\Http\Controllers\Owner;

use App\Backups\BackupReason;
use App\Backups\BackupRefused;
use App\Backups\BackupService;
use App\Http\Controllers\Controller;
use App\Models\Website;
use App\Operator\BackupState;
use App\Operator\ConnectorState;
use App\Operator\Refusal;
use App\Operator\WebsiteDirectory;
use App\Pricing\ProductType;
use App\Security\ScanType;
use App\Security\SecurityRefused;
use App\Security\SecurityScanner;
use App\Security\SecurityScanService;
use App\Security\SecurityState;
use App\SiteHealth\SiteHealth;
use App\Websites\Actor;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/**
 * Every website, with Unified Site Health (App\SiteHealth\SiteHealth, the
 * same calculation customers and operators see). The owner may start a
 * backup or a security scan, through the same services and checks as the
 * operator console; restores, updates, admin access and remediation stay
 * with operators.
 */
class WebsiteController extends Controller
{
    public function index(Request $request, WebsiteDirectory $directory, SiteHealth $health, SecurityScanner $security): View
    {
        // The operator directory's search, filters and sorting; WordPress health is not a filter here.
        $filters = ['status' => null] + WebsiteDirectory::filters($request->query());
        $websites = $directory->paginate($filters);

        return view('owner.websites.index', [
            'websites' => $websites,
            'filters' => $filters,
            'health' => collect($websites->items())->mapWithKeys(fn (Website $website) => [$website->id => $health->for($website)]),
            'security' => $security->summaries($websites->items()),
            'options' => [
                'type' => ProductType::cases(),
                'backup' => BackupState::cases(),
                'security' => SecurityState::cases(),
                'connector' => ConnectorState::cases(),
            ],
        ]);
    }

    public function show(Website $website, SiteHealth $health, SecurityScanner $security): View
    {
        $website->load(['customer.syntheticFixture', 'syntheticFixture', 'subscription', 'activeConnector', 'inventory']);
        $backups = $website->backups()->latest('id')->limit(5)->get();

        return view('owner.websites.show', [
            'website' => $website,
            'report' => $health->for($website),
            'connector' => ConnectorState::of($website),
            'security' => $security->summaries([$website])[$website->id],
            'backups' => $backups,
            'latestBackup' => BackupState::fromStatus($backups->first()?->status),
            'events' => $website->events()->with('actorUser')->latest('id')->limit(15)->get(),
        ]);
    }

    public function backup(Request $request, Website $website, BackupService $backups): RedirectResponse
    {
        try {
            $backups->request($website, BackupReason::Manual, Actor::user($request->user())->label());
        } catch (BackupRefused $e) {
            return back()->with('owner_error', Refusal::flash($e->errorCode));
        }

        return back()->with('status', __('owner.websites.backup_queued'));
    }

    public function scan(Request $request, Website $website, SecurityScanService $scans): RedirectResponse
    {
        try {
            $scans->request($website->load(['activeConnector', 'subscription', 'customer', 'inventory']), ScanType::Full, Actor::user($request->user())->label());
        } catch (SecurityRefused $e) {
            return back()->with('owner_error', Refusal::flash($e->errorCode));
        }

        return back()->with('status', __('owner.websites.scan_queued'));
    }
}
