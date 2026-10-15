<?php

namespace App\Http\Controllers\Account;

use App\Http\Controllers\Controller;
use App\Models\Customer;
use App\Models\PrivacyExportArtifact;
use App\Privacy\PrivacyExportService;
use App\Privacy\PrivacyRequestService;
use App\Privacy\PrivacyRequestType;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Str;
use RuntimeException;

final class PrivacyController extends Controller
{
    public function index(Request $request, PrivacyExportService $exports): View
    {
        /** @var Customer $customer */
        $customer = $request->attributes->get('customer');
        $cases = $customer->privacyRequests()->with('exportArtifact')->latest('requested_at')->paginate(15);
        $downloadUrls = $cases->getCollection()->mapWithKeys(fn ($case) => [
            $case->uuid => $case->exportArtifact ? $exports->downloadUrl($case->exportArtifact) : null,
        ]);

        return view('account.privacy', compact('cases', 'downloadUrls'));
    }

    public function requestExport(Request $request, PrivacyExportService $exports): RedirectResponse
    {
        $data = $request->validate(['current_password' => ['required', 'string', 'current_password']]);
        $customer = $request->attributes->get('customer');
        $exports->request($customer, $request->user(), $data['current_password']);

        return redirect()->route('account.privacy.index')->with('status', __('account.privacy.export_requested'));
    }

    public function requestRectification(Request $request, PrivacyRequestService $privacyRequests): RedirectResponse
    {
        $data = $request->validate(['current_password' => ['required', 'string', 'current_password']]);
        $customer = $request->attributes->get('customer');
        $case = $privacyRequests->createCustomerRequest(
            $customer,
            PrivacyRequestType::Rectification,
            'privacy-rectification-'.Str::uuid(),
            $request->user(),
            $data['current_password'],
        );

        return redirect()->route('account.profile', ['privacy_request' => $case->uuid])->with('status', __('account.privacy.rectification_started'));
    }

    public function renewLink(Request $request, PrivacyExportArtifact $artifact, PrivacyExportService $exports): RedirectResponse
    {
        $data = $request->validate(['current_password' => ['required', 'string', 'current_password']]);
        $customer = $request->attributes->get('customer');
        abort_unless($artifact->privacyRequest()->where('customer_id', $customer->id)->exists(), 404);
        $exports->renewDownloadLink($artifact, $request->user(), $data['current_password']);

        return redirect()->route('account.privacy.index')->with('status', __('account.privacy.link_renewed'));
    }

    public function download(Request $request, PrivacyExportArtifact $artifact, PrivacyExportService $exports): Response
    {
        $customer = $request->attributes->get('customer');
        abort_unless($artifact->privacyRequest()->where('customer_id', $customer->id)->exists(), 404);
        try {
            $bytes = $exports->download($artifact, $customer, $request->user());
        } catch (AuthorizationException) {
            abort(404);
        } catch (RuntimeException) {
            abort(410);
        }

        return response($bytes, 200, [
            'Content-Type' => 'application/zip',
            'Content-Disposition' => 'attachment; filename="wpcaregrid-privacy-export-'.$artifact->uuid.'.zip"',
            'Cache-Control' => 'private, no-store, max-age=0',
            'Pragma' => 'no-cache',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }
}
