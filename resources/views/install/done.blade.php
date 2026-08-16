@extends('install.layout')

@section('step', 5)

@section('content')
    <div class="success-icon">✓</div>
    <h1>Installation complete</h1>
    <p class="lead">
        ApproveDesk is configured and ready. The installer is now locked
        (<code>/install</code> returns 404). You can sign in with the administrator
        account you just created.
    </p>

    <form method="GET" action="{{ route('login') }}">
        <button type="submit">Go to login</button>
    </form>
@endsection
