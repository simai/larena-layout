<?php

declare(strict_types=1);

namespace Larena\Layout\Runtime;

use InvalidArgumentException;
use JsonException;

final readonly class FrameworkRecipeCompiler
{
    public static function fromFrameworkDistribution(string $nodeBinary, string $frameworkRoot): self
    {
        $entry = rtrim($frameworkRoot, '/\\\\').'/distr/core/js/composition/index.mjs';
        if (! is_file($entry) || is_link($entry)) {
            throw new InvalidArgumentException('layout_framework_recipe_distribution_invalid');
        }

        return new self($nodeBinary, $entry, 'sha256:'.hash_file('sha256', $entry));
    }

    public function __construct(
        private string $nodeBinary,
        private string $frameworkEntry,
        private string $expectedFrameworkEntryDigest,
        private ?string $runner = null,
    ) {}

    /** @param array<string,mixed> $request @return array<string,mixed> */
    public function compile(array $request): array
    {
        $entry = realpath($this->frameworkEntry);
        $runner = realpath($this->runner ?? dirname(__DIR__, 2).'/resources/runtime/framework-recipe-adapter.mjs');
        if (! is_string($entry) || ! is_file($entry) || ! is_string($runner) || ! is_file($runner)) {
            throw new InvalidArgumentException('layout_framework_recipe_runtime_unavailable');
        }
        if ($this->expectedFrameworkEntryDigest !== 'sha256:'.hash_file('sha256', $entry)) {
            throw new InvalidArgumentException('layout_framework_recipe_digest_mismatch');
        }
        $pipes = [];
        $process = proc_open([$this->nodeBinary, $runner, $entry], [
            0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w'],
        ], $pipes);
        if (! is_resource($process)) {
            throw new InvalidArgumentException('layout_framework_recipe_process_unavailable');
        }
        fwrite($pipes[0], json_encode($request, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
        fclose($pipes[0]);
        $output = stream_get_contents($pipes[1]);
        $error = stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        if (proc_close($process) !== 0) {
            throw new InvalidArgumentException('layout_framework_recipe_failed:'.trim($error !== '' ? $error : $output));
        }
        try {
            $result = json_decode($output, true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException $exception) {
            throw new InvalidArgumentException('layout_framework_recipe_result_invalid', previous: $exception);
        }
        if (! is_array($result) || ! is_array($result['document'] ?? null) || ! is_array($result['dependencyReceipt'] ?? null) || ($result['diagnostics'] ?? null) !== []) {
            throw new InvalidArgumentException('layout_framework_recipe_rejected');
        }
        return $result;
    }
}
