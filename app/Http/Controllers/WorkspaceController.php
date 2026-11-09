<?php

namespace App\Http\Controllers;

use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\View\View;

class WorkspaceController extends Controller
{
    public function show(Request $request): View
    {
        $user = $request->user();
        $tenant = $user->tenant;
        $members = User::where('tenant_id', $user->tenant_id)
            ->orderBy('id')
            ->paginate(20);

        return view('workspace', [
            'user' => $user,
            'tenant' => $tenant,
            'members' => $members,
        ]);
    }
}
