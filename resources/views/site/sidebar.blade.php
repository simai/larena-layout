{{-- A site page with a side column beside the page's own part. --}}
@isset($areas['header'])<header class="larena-site-header">{{ $areas['header'] }}</header>@endisset
<div class="larena-site-columns">
{{ $main }}
@isset($areas['sidebar'])<aside class="larena-site-sidebar">{{ $areas['sidebar'] }}</aside>@endisset
</div>
@isset($areas['footer'])<footer class="larena-site-footer">{{ $areas['footer'] }}</footer>@endisset
