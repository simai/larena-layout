{{-- A site page's default template: its header and footer around the page's own part. --}}
@isset($areas['header'])<header class="larena-site-header">{{ $areas['header'] }}</header>@endisset
{{ $main }}
@isset($areas['footer'])<footer class="larena-site-footer">{{ $areas['footer'] }}</footer>@endisset
