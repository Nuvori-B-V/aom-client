@auth
    @if (app(\Nuvori\AomClient\Aom::class)->isConfigured())
        <script src="{{ route('aom.feedback.script') }}" data-endpoint="{{ route('aom.feedback') }}" data-csrf="{{ csrf_token() }}" defer></script>
    @endif
@endauth
