{{-- A site page's default template: its header and footer around the page's own part. The areas are the SIMAI Framework's
     declared regions (layout.regions with layout.region nodes and their landmarks), drawn as the Framework's renderer
     draws them (Larena\Layout\StructuredContent\Renderer), so the Framework's own sheet lays them out. --}}
<div class="sf-composition-regions" data-sf-composition-id="regions"><div class="sf-composition-regions-grid">
@isset($areas['header'])<header class="sf-composition-region" data-sf-composition-id="header" data-sf-region="header" data-sf-region-placement="block">{{ $areas['header'] }}</header>@endisset
<main class="sf-composition-region" data-sf-composition-id="main" data-sf-region="main" data-sf-region-placement="block">{{ $main }}</main>
@isset($areas['footer'])<footer class="sf-composition-region" data-sf-composition-id="footer" data-sf-region="footer" data-sf-region-placement="block">{{ $areas['footer'] }}</footer>@endisset
</div></div>
