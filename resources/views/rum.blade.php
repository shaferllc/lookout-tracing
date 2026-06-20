@if (\Lookout\Tracing\Laravel\RumScript::enabled())
    {!! \Lookout\Tracing\Laravel\RumScript::traceMetaHtml() !!}
    <script src="{{ route('lookout.rum.script') }}" defer></script>
    <script>
        document.addEventListener('DOMContentLoaded', function () {
            if (typeof LookoutRum === 'undefined') {
                return;
            }
            LookoutRum.init(@json(\Lookout\Tracing\Laravel\RumScript::initConfig()));
        });
    </script>
@endif
