{{-- A site page with a side column beside the page's own part: the side column is the Framework's end region, beside
     the main one from a medium width on and under it on a phone (the Framework's sheet decides). --}}
<div class="sf-composition-regions" data-sf-composition-id="regions"><div class="sf-composition-regions-grid">
@isset($areas['header'])<header class="sf-composition-region" data-sf-composition-id="header" data-sf-region="header" data-sf-region-placement="block">{{ $areas['header'] }}</header>@endisset
<main class="sf-composition-region" data-sf-composition-id="main" data-sf-region="main" data-sf-region-placement="center">{{ $main }}</main>
@isset($areas['sidebar'])<aside class="sf-composition-region" data-sf-composition-id="sidebar" data-sf-region="sidebar" data-sf-region-placement="end">{{ $areas['sidebar'] }}</aside>@endisset
@isset($areas['footer'])<footer class="sf-composition-region" data-sf-composition-id="footer" data-sf-region="footer" data-sf-region-placement="block">{{ $areas['footer'] }}</footer>@endisset
</div></div>
