<?php

namespace App\Http\Controllers\Owner;

use App\Http\Controllers\Controller;
use App\Models\AdminAuditEvent;
use App\Models\User;
use App\Operator\Permission;
use App\Owner\OperatorAccounts;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * Operators: invite, permissions, disable and enable (App\Owner\OperatorAccounts).
 * Passwords are never shown or set here; sensitive permissions need an
 * explicit confirmation.
 */
class OperatorController extends Controller
{
    public function index(): View
    {
        return view('owner.operators.index', [
            'operators' => User::where('role', User::ROLE_OPERATOR)->orderBy('name')->get(),
            'permissions' => Permission::cases(),
            'sensitive' => OperatorAccounts::SENSITIVE,
        ]);
    }

    public function store(Request $request, OperatorAccounts $accounts): RedirectResponse
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:100'],
            'email' => ['required', 'string', 'email', 'max:254'],
            ...$this->permissionRules(),
        ]);
        $permissions = $this->requestedPermissions($data);
        $this->confirmSensitive($permissions, [], $data);

        $operator = $accounts->invite($data['name'], $data['email'], $permissions, $request->user());

        return redirect()->route('owner.operators.show', $operator)->with('status', __('owner.operators.invited', ['email' => $operator->email]));
    }

    public function show(User $operatorUser): View
    {
        return view('owner.operators.show', [
            'operator' => $operatorUser,
            'permissions' => Permission::cases(),
            'sensitive' => OperatorAccounts::SENSITIVE,
            'audit' => AdminAuditEvent::with('actor')->where('subject_type', 'operator')->where('subject_key', (string) $operatorUser->id)->latest('id')->limit(15)->get(),
        ]);
    }

    public function permissions(Request $request, User $operatorUser, OperatorAccounts $accounts): RedirectResponse
    {
        $data = $request->validate($this->permissionRules());
        $permissions = $this->requestedPermissions($data);
        $this->confirmSensitive($permissions, $operatorUser->operatorPermissions(), $data);

        $change = $accounts->setPermissions($operatorUser, $permissions, $request->user());

        return back()->with('status', $change['granted'] === [] && $change['revoked'] === []
            ? __('owner.operators.permissions_unchanged')
            : __('owner.operators.permissions_saved', ['granted' => count($change['granted']), 'revoked' => count($change['revoked'])]));
    }

    public function disable(Request $request, User $operatorUser, OperatorAccounts $accounts): RedirectResponse
    {
        $accounts->disable($operatorUser, $request->user());

        return back()->with('status', __('owner.operators.disabled'));
    }

    public function enable(Request $request, User $operatorUser, OperatorAccounts $accounts): RedirectResponse
    {
        $accounts->enable($operatorUser, $request->user());

        return back()->with('status', __('owner.operators.enabled'));
    }

    public function resendInvitation(Request $request, User $operatorUser, OperatorAccounts $accounts): RedirectResponse
    {
        $accounts->resendInvitation($operatorUser, $request->user());

        return back()->with('status', __('owner.operators.invitation_sent'));
    }

    /** @return array<string, mixed> */
    private function permissionRules(): array
    {
        return [
            'permissions' => ['nullable', 'array'],
            'permissions.*' => ['string', Rule::in(Permission::values())],
            'confirm_sensitive' => ['nullable', 'boolean'],
        ];
    }

    /** @return list<Permission> */
    private function requestedPermissions(array $data): array
    {
        return array_values(array_map(fn (string $value) => Permission::from($value), array_unique($data['permissions'] ?? [])));
    }

    /**
     * Newly granting a sensitive permission needs the confirmation box.
     *
     * @param  list<Permission>  $requested
     * @param  list<Permission>  $current
     *
     * @throws ValidationException
     */
    private function confirmSensitive(array $requested, array $current, array $data): void
    {
        $new = array_filter($requested, fn (Permission $p) => in_array($p, OperatorAccounts::SENSITIVE, true) && ! in_array($p, $current, true));

        if ($new !== [] && ! filter_var($data['confirm_sensitive'] ?? false, FILTER_VALIDATE_BOOLEAN)) {
            throw ValidationException::withMessages(['confirm_sensitive' => __('owner.operators.errors.confirm_sensitive')]);
        }
    }
}
