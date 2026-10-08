<?php

namespace App\Http\Controllers\Owner;

use App\Http\Controllers\Controller;
use App\Invoicing\SyntheticSzamlazzTestGuard;
use App\Invoicing\SyntheticSzamlazzTestWorkflow;
use App\Models\SzamlazzSyntheticTestRun;
use App\Owner\IntegrationCredentials;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\View\View;

final class SyntheticSzamlazzTestController extends Controller
{
    public function index(Request $request, SyntheticSzamlazzTestGuard $guard, IntegrationCredentials $credentials): View
    {
        return view('owner.billing.synthetic-szamlazz-test', [
            'run' => SzamlazzSyntheticTestRun::query()->with(['preparer', 'submitter'])->first(),
            'stageGuardPassed' => $guard->permits($request->getHost()),
            'ownerManagedCredential' => (bool) ($credentials->configured()['szamlazz_agent']['owner_managed'] ?? false),
            'normalIssuanceGates' => [
                'provider_enabled' => (bool) config('szamlazz.provider_enabled'),
                'dry_run' => (bool) config('szamlazz.dry_run'),
                'issuance_enabled' => (bool) config('szamlazz.issuance_enabled'),
            ],
        ]);
    }

    public function store(Request $request, SyntheticSzamlazzTestWorkflow $workflow): RedirectResponse
    {
        $workflow->prepare($request->only('account_test_mode_confirmed', 'test_seller_confirmed', 'confirmation_phrase', 'tax_code'), $request->user('owner'), $request->getHost());

        return to_route('owner.billing.synthetic-szamlazz-test.index')->with('status', __('owner.synthetic_invoice_test.prepared'));
    }

    public function submit(SzamlazzSyntheticTestRun $run, Request $request, SyntheticSzamlazzTestWorkflow $workflow): RedirectResponse
    {
        $workflow->submit($run, $request->user('owner'), $request->getHost(), $request->only('confirm_submission', 'confirmation_phrase'));

        return back()->with('status', __('owner.synthetic_invoice_test.submission_finished'));
    }

    public function reconcile(SzamlazzSyntheticTestRun $run, Request $request, SyntheticSzamlazzTestWorkflow $workflow): RedirectResponse
    {
        $workflow->reconcile($run, $request->user('owner'), $request->getHost());

        return back()->with('status', __('owner.synthetic_invoice_test.reconciliation_finished'));
    }

    public function downloadPdf(SzamlazzSyntheticTestRun $run): mixed
    {
        $expectedDisk = (string) config('szamlazz.synthetic_test.private_disk', 'local');
        $expectedDirectory = trim((string) config('szamlazz.synthetic_test.private_directory', 'private/szamlazz-synthetic-tests'), '/').'/';
        abort_unless($run->status === 'issued' && $run->pdf_disk === $expectedDisk && $run->pdf_path
            && str_starts_with($run->pdf_path, $expectedDirectory)
            && str_starts_with($run->pdf_path, 'private/szamlazz-synthetic-tests/'), 404);
        $stream = Storage::disk($run->pdf_disk)->readStream($run->pdf_path);
        abort_unless(is_resource($stream), 404);

        return response()->streamDownload(function () use ($stream): void {
            fpassthru($stream);
            fclose($stream);
        }, 'synthetic-test-invoice.pdf', ['Content-Type' => 'application/pdf', 'X-Content-Type-Options' => 'nosniff']);
    }
}
