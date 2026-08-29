@extends('install.layout')

@section('step', 1)

@section('content')
    <h1>Welcome</h1>
    <p class="lead">
        This wizard checks your server, configures the database, runs migrations,
        and creates your first administrator account. It takes about two minutes.
    </p>

    <ul class="reqs">
        @foreach ($requirements as $requirement)
            <li>
                <span class="dot {{ $requirement['ok'] ? 'ok' : ($requirement['required'] ? 'bad' : 'warn') }}"></span>
                <span>{{ $requirement['label'] }}</span>
                <span class="note">{{ $requirement['note'] }}</span>
            </li>
        @endforeach
    </ul>

    @if ($meets)
        <a class="continue" href="{{ route('install.database') }}">Continue to database setup</a>
    @else
        <button disabled>Fix the red items above, then reload</button>
    @endif
@endsection
