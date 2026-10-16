<div class="form-grid">
    <div><label for="{{ $prefix }}-password">{{ $label }}</label><input id="{{ $prefix }}-password" name="password" type="password" autocomplete="new-password" minlength="12" maxlength="72" @required($required) aria-describedby="{{ $prefix }}-password-help"></div>
    <div><label for="{{ $prefix }}-password-confirmation">Confirm password</label><input id="{{ $prefix }}-password-confirmation" name="password_confirmation" type="password" autocomplete="new-password" minlength="12" maxlength="72" @required($required)></div>
</div>
<p class="field-help" id="{{ $prefix }}-password-help">Use at least 12 characters, including uppercase, lowercase and a number.</p>
