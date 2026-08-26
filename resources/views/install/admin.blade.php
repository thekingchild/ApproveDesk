@extends('install.layout')

@section('step', 4)

@section('content')
    <h1>System owner account</h1>
    <p class="lead">
        This protected System owner receives full access, including the settings where
        branding and users are managed. Use a strong, unique password.
    </p>

    <form method="POST" action="{{ route('install.admin.store') }}">
        @csrf

        <label for="name">Full name</label>
        <input id="name" name="name" value="{{ old('name') }}" required autofocus>
        @error('name')<div class="field-error">{{ $message }}</div>@enderror

        <label for="email">Email address</label>
        <input id="email" name="email" type="email" value="{{ old('email') }}" required>
        @error('email')<div class="field-error">{{ $message }}</div>@enderror

        <div class="row">
            <div>
                <label for="password">Password (min. 10 chars)</label>
                <input id="password" name="password" type="password" required>
                @error('password')<div class="field-error">{{ $message }}</div>@enderror
            </div>
            <div>
                <label for="password_confirmation">Confirm password</label>
                <input id="password_confirmation" name="password_confirmation" type="password" required>
            </div>
        </div>

        <button type="submit">Create account &amp; finish installation</button>
    </form>
@endsection
