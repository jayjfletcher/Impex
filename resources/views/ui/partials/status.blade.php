@if (session('status'))
    <x-atrium::alert variant="success" class="mb-4" dismissible>{{ session('status') }}</x-atrium::alert>
@endif

@if ($errors->any())
    <x-atrium::alert variant="danger" class="mb-4">{{ $errors->first() }}</x-atrium::alert>
@endif
