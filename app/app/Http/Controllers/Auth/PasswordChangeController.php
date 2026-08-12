<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rules\Password;
use Illuminate\View\View;

class PasswordChangeController extends Controller
{
    public function edit(): View
    {
        return view('auth.change-password');
    }

    public function update(Request $request): RedirectResponse
    {
        $request->validate([
            'password' => ['required', 'confirmed', Password::min(12)->mixedCase()->numbers()->symbols()],
        ]);

        $request->user()->forceFill([
            'password' => Hash::make($request->input('password')),
            'must_change_password' => false,
            'password_changed_at' => now(),
            'failed_attempts' => 0,
        ])->save();

        $request->session()->regenerate();

        return redirect()->route('admin.index')->with('status', 'Senha atualizada. Sua sessão está protegida.');
    }
}
