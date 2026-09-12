@props(['messages' => [], 'id' => null])

@if ($messages)
    <ul {{ $attributes->merge(['class' => 'mt-2 space-y-1 text-sm text-red-700 dark:text-red-300']) }} @if ($id) id="{{ $id }}" @endif>
        @foreach ((array) $messages as $message)
            <li>{{ $message }}</li>
        @endforeach
    </ul>
@endif
