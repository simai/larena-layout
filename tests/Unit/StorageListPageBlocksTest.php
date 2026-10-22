<?php

declare(strict_types=1);

require_once __DIR__ . '/../bootstrap.php';

use Larena\Layout\Contracts\PageDescriptorAuthorizationPolicy;
use Larena\Layout\Exceptions\LayoutRejected;
use Larena\Layout\Persistence\PdoPageDescriptorStore;
use Larena\Layout\Runtime\PageAssemblyDescriptorNormalizer;
use Larena\Layout\Runtime\PageDescriptorComponentRegistry;
use Larena\Layout\Runtime\StorageListPageBlocks;

// The list block of a site page, put on, read back, changed and taken away through the page
// store: one builder for the console command and the admin's «Список записей».

final class StorageListPageBlocksTestPolicy implements PageDescriptorAuthorizationPolicy
{
    public function assertAllowed(string $actor, string $operation, string $scopeRef): void
    {
        if ($actor !== 'actor:editor') {
            throw new LayoutRejected('layout_scope_denied');
        }
    }
}

$path = tempnam(sys_get_temp_dir(), 'larena-layout-list-blocks-');
assert(is_string($path));
// The site's sections are `layout.section`, as the Root registers them.
$store = new PdoPageDescriptorStore(new PDO('sqlite:' . $path), new StorageListPageBlocksTestPolicy(),
    new PageAssemblyDescriptorNormalizer(new PageDescriptorComponentRegistry(['layout.section'], ['larena.list'])));
$store->install();
$scope = 'scope:site.main';
$page = StorageListPageBlocks::pageId('news');
assert($page === 'site.news');

// A page without an assembly gets one with the block in its main section.
assert(StorageListPageBlocks::read(null) === null);
$query = ['sort' => [['field' => 'order_index', 'direction' => 'desc']], 'limit' => 5];
$saved = $store->create(StorageListPageBlocks::put(null, $scope, $page, 'site_node@v1', $query, 'Latest'), 'actor:editor');
$read = StorageListPageBlocks::read($saved->descriptor);
assert($read !== null && $read['title'] === 'Latest' && $read['target'] === 'site_node@v1', json_encode($read));
assert($read['query']['limit'] === 5 && $read['query']['sort'] === [['direction' => 'desc', 'field' => 'order_index']]);
$block = $saved->descriptor['regions'][0]['sections'][0]['blocks'][0];
assert($block['id'] === 'block.list' && $block['component'] === 'larena.list' && $block['bindings'][0]['id'] === 'binding.list');

// Putting it again replaces it where it stands and keeps its other props; a cleared title goes.
$withProps = $saved->descriptor;
$withProps['regions'][0]['sections'][0]['blocks'][0]['props']['summary_field'] = 'target';
$saved = $store->update($withProps, $saved->revision, 'actor:editor');
$saved = $store->update(StorageListPageBlocks::put($saved->descriptor, $scope, $page, 'site_node@v1', ['limit' => 3], null), $saved->revision, 'actor:editor');
$blocks = $saved->descriptor['regions'][0]['sections'][0]['blocks'];
assert(count($blocks) === 1 && $blocks[0]['props'] === ['summary_field' => 'target'], json_encode($blocks));
assert(StorageListPageBlocks::read($saved->descriptor)['title'] === null);

// Another list joins the same section after it; the first one stays.
$saved = $store->update(StorageListPageBlocks::put($saved->descriptor, $scope, $page, 'workbench.news', [], 'News', 'block.news'), $saved->revision, 'actor:editor');
$blocks = $saved->descriptor['regions'][0]['sections'][0]['blocks'];
assert(array_column($blocks, 'id') === ['block.list', 'block.news'] && $blocks[1]['sort'] > $blocks[0]['sort']);
assert($blocks[1]['bindings'][0]['id'] === 'binding.news');
assert(StorageListPageBlocks::read($saved->descriptor, 'block.news')['target'] === 'workbench.news');

// Removing one leaves the rest of the page; removing the last leaves an empty section, still a page.
$saved = $store->update(StorageListPageBlocks::remove($saved->descriptor), $saved->revision, 'actor:editor');
assert(StorageListPageBlocks::read($saved->descriptor) === null && StorageListPageBlocks::read($saved->descriptor, 'block.news') !== null);
$saved = $store->update(StorageListPageBlocks::remove($saved->descriptor, 'block.news'), $saved->revision, 'actor:editor');
assert($saved->descriptor['regions'][0]['sections'][0]['blocks'] === [] && $saved->revision === 6);

// A page assembly that has other regions keeps them; the list gets the main region beside them.
$other = StorageListPageBlocks::remove(StorageListPageBlocks::put(null, $scope, 'site.about', 't@v1', [], null), 'block.list');
$other['regions'][0]['id'] = 'region.aside';
$other['regions'][0]['sections'][0]['id'] = 'section.aside';
$put = StorageListPageBlocks::put($other, $scope, 'site.about', 'site_node@v1', [], null);
assert(array_column($put['regions'], 'id') === ['region.aside', 'region.main'] && $put['regions'][1]['sort'] === 200);
$store->create($put, 'actor:editor');

@unlink($path);
echo "StorageListPageBlocksTest passed.\n";
