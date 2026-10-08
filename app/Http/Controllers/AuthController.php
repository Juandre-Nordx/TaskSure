<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Str;
use Illuminate\Validation\Rules\Password as PasswordRule;

class AuthController extends Controller
{
    public function login(Request $r)
    {
        $data = $r->validate(['email' => 'required|email', 'password' => 'required|string']);
        if (Auth::attempt($data + ['active' => true], $r->boolean('remember'))) {
            $r->session()->regenerate();

            return redirect()->intended('/');
        }

        return back()->withErrors(['email' => 'These credentials are invalid or the account is inactive.'])->onlyInput('email');
    }

    public function logout(Request $r)
    {
        Auth::logout();
        $r->session()->invalidate();
        $r->session()->regenerateToken();

        return redirect('/login');
    }

    public function forgot(Request $r)
    {
        $r->validate(['email' => 'required|email']);
        if (in_array(config('mail.default'), ['log', 'array'])) {
            return back()->withErrors(['email' => 'Password reset email is not configured. Contact your administrator.']);
        }Password::sendResetLink($r->only('email'));

        return back()->with('success', 'If this account exists, a password reset link has been requested.');
    }

    public function reset(Request $r)
    {
        $r->validate(['token' => 'required', 'email' => 'required|email', 'password' => ['required', 'confirmed', PasswordRule::min(12)->mixedCase()->numbers()]]);
        $status = Password::reset($r->only('email', 'password', 'password_confirmation', 'token'), function ($u, $p) {
            $u->forceFill(['password' => Hash::make($p), 'remember_token' => Str::random(60)])->save();
            DB::table('sessions')->where('user_id', $u->id)->delete();
        });

        return $status === Password::PASSWORD_RESET ? redirect('/login')->with('success', 'Password reset.') : back()->withErrors(['email' => __($status)]);
    }
}
