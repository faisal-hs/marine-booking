<?php

namespace App\Http\Controllers\Web\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Web\Admin\User\StoreUserRequest;
use App\Http\Requests\Web\Admin\User\UpdateUserRequest;
use App\Models\User;
use App\Services\Admin\UserManagementService;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;
use Spatie\Permission\Models\Role;

class UserController extends Controller
{
    use AuthorizesRequests;

    public function __construct(protected UserManagementService $service) {}

    public function index(Request $request): View
    {
        $this->authorize('viewAny', User::class);

        $users = $this->service->paginate($request->only(['user_type', 'status', 'search']));

        return view('admin.users.index', ['users' => $users, 'filters' => $request->only(['user_type', 'status', 'search'])]);
    }

    public function show(User $user): View
    {
        $this->authorize('view', $user);

        return view('admin.users.show', ['user' => $user]);
    }

    public function create(): View
    {
        $this->authorize('create', User::class);

        return view('admin.users.create', ['roles' => Role::pluck('name')]);
    }

    public function store(StoreUserRequest $request): RedirectResponse
    {
        $user = $this->service->create($request->payload());

        return redirect()->route('admin.users.show', $user)->with('status', 'User created successfully.');
    }

    public function edit(User $user): View
    {
        $this->authorize('update', $user);

        return view('admin.users.edit', ['user' => $user, 'roles' => Role::pluck('name')]);
    }

    public function update(UpdateUserRequest $request, User $user): RedirectResponse
    {
        $this->service->update($user, $request->payload());

        return redirect()->route('admin.users.show', $user)->with('status', 'User updated successfully.');
    }

    public function destroy(User $user): RedirectResponse
    {
        $this->authorize('delete', $user);

        $this->service->delete($user);

        return redirect()->route('admin.users.index')->with('status', 'User deleted successfully.');
    }
}
