<?php

declare(strict_types=1);

namespace Larena\Layout\Runtime;

use InvalidArgumentException;
use Larena\Layout\Contracts\ContentModel;
use Larena\Layout\Contracts\SectionDefinition;
use Larena\Layout\Contracts\SectionInstance;

final class InMemoryDataRuntime
{
    /**
     * @param array<string, array<string, mixed>> $fixtures
     */
    public function resolve(
        SectionInstance $instance,
        SectionDefinition $definition,
        array $fixtures,
    ): ContentModel {
        $fixtureKey = $instance->dataSources[$definition->dataSourceKey] ?? $definition->dataSourceKey;
        if (!array_key_exists($fixtureKey, $fixtures)) {
            throw new InvalidArgumentException('layout_data_runtime_unknown_source:' . $fixtureKey);
        }

        $values = $fixtures[$fixtureKey];
        if ($values === []) {
            throw new InvalidArgumentException('layout_data_runtime_empty_content_model:' . $fixtureKey);
        }

        return new ContentModel(
            $definition->dataSourceKey,
            $values,
            [
                'data-runtime:in-memory',
                'section:' . $instance->instanceId,
                'source:' . $fixtureKey,
            ],
        );
    }
}
