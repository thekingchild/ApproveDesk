@extends('install.layout')

@section('step', 2)

@section('content')
    <h1>Database</h1>
    <p class="lead">
        Create the database and user in your hosting panel first (cPanel → MySQL Databases
        or Plesk → Databases), then enter the credentials here. We test the connection
        before anything is saved.
    </p>

    <form method="POST" action="{{ route('install.database.store') }}">
        @csrf

        <label for="connection">Connection type</label>
        <select name="connection" id="connection" data-connection-picker>
            <option value="mysql" @selected(old('connection', 'mysql') === 'mysql')>MySQL / MariaDB (recommended)</option>
            <option value="sqlite" @selected(old('connection') === 'sqlite')>SQLite (small installs)</option>
        </select>

        <div data-conn="mysql" @hidden(old('connection', 'mysql') !== 'mysql')>
            <div class="row">
                <div>
                    <label for="host">Host</label>
                    <input id="host" name="host" value="{{ old('host', 'localhost') }}">
                    @error('host')<div class="field-error">{{ $message }}</div>@enderror
                </div>
                <div>
                    <label for="port">Port</label>
                    <input id="port" name="port" value="{{ old('port', '3306') }}">
                </div>
            </div>
            <label for="database">Database name</label>
            <input id="database" name="database" value="{{ old('database') }}">
            @error('database')<div class="field-error">{{ $message }}</div>@enderror
            <label for="username">Username</label>
            <input id="username" name="username" value="{{ old('username') }}">
            @error('username')<div class="field-error">{{ $message }}</div>@enderror
            <label for="password">Password</label>
            <input id="password" type="password" name="password" value="{{ old('password') }}">
        </div>

        <div data-conn="sqlite" @hidden(old('connection', 'mysql') === 'mysql')>
            <label for="path">SQLite file name (inside /database)</label>
            <input id="path" name="path" value="{{ old('path', 'database.sqlite') }}" placeholder="database.sqlite">
            @error('path')<div class="field-error">{{ $message }}</div>@enderror
        </div>

        <label for="url">Application URL (optional)</label>
        <input id="url" name="url" value="{{ old('url') }}" placeholder="https://approvals.example.com">

        <button type="submit">Test connection &amp; save configuration</button>
    </form>
<script nonce="{{ \App\Support\CspNonce::current() }}">
    document.querySelector('[data-connection-picker]')?.addEventListener('change', (event) => {
        document.querySelectorAll('[data-conn]').forEach((el) => { el.hidden = el.dataset.conn !== event.target.value })
    })
</script>
@endsection
