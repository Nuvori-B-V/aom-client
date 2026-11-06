@auth
    @if (app(\Nuvori\AomClient\Aom::class)->isConfigured())
        <link rel="stylesheet" href="{{ route('aom.feedback.style') }}">
        <script src="{{ route('aom.feedback.script') }}" data-endpoint="{{ route('aom.feedback') }}" data-csrf="{{ csrf_token() }}" defer></script>
    @endif
@endauth
