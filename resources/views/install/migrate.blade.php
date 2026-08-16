@extends('install.layout')

@section('step', 3)

@section('content')
    <h1>Database tables</h1>
    <p class="lead">
        Ready to create all tables (departments, submissions, workflow engine, audit log)
        and seed the default roles, permissions, and settings. This runs
        <code>migrate --force</code> + base seeders.
    </p>

    <form method="POST" action="{{ route('install.migrate.run') }}">
        @csrf
        <button type="submit">Run migrations</button>
    </form>
@endsection
