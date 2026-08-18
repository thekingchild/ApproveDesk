@if ($errors->any())
    <x-ui.alert variant="danger" :title="__('Please correct the following fields')" {{ $attributes }}>
        <ul class="list-disc space-y-1 ps-5">
            @foreach ($errors->all() as $error)
                <li>{{ $error }}</li>
            @endforeach
        </ul>
    </x-ui.alert>
@endif
