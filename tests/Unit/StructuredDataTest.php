<?php

declare(strict_types=1);

use App\Services\StructuredData;

it('reads one node, a list of nodes or a graph, without their own context', function (string $json, array $types): void {
    $nodes = StructuredData::nodes($json);

    expect(array_column($nodes, '@type'))->toBe($types)
        ->and(array_filter($nodes, fn (array $node): bool => array_key_exists('@context', $node)))->toBeEmpty();
})->with([
    'one node' => ['{"@context": "https://schema.org", "@type": "SoftwareApplication", "name": "Wire Up"}', ['SoftwareApplication']],
    'a list' => ['[{"@type": "SoftwareApplication"}, {"@context": "https://schema.org", "@type": "SoftwareSourceCode"}]', ['SoftwareApplication', 'SoftwareSourceCode']],
    'a graph' => ['{"@context": "https://schema.org", "@graph": [{"@type": "Course"}, {"@type": "Organization"}]}', ['Course', 'Organization']],
    'several types' => ['{"@type": ["SoftwareApplication", "WebApplication"]}', [['SoftwareApplication', 'WebApplication']]],
]);

it('treats an empty box as no extra data', function (): void {
    expect(StructuredData::nodes("  \n "))->toBe([]);
});

it('refuses anything that is not JSON-LD', function (string $json): void {
    expect(StructuredData::nodes($json))->toBeNull();
})->with([
    'not JSON' => ['{"@type": "Thing",}'],
    'a plain value' => ['"SoftwareApplication"'],
    'an empty object' => ['{}'],
    'an empty graph' => ['{"@graph": []}'],
    'a node without a type' => ['{"name": "Wire Up"}'],
    'an empty type' => ['{"@type": ""}'],
    'a type list with a blank' => ['{"@type": ["SoftwareApplication", ""]}'],
    'a list with a plain value' => ['[{"@type": "Thing"}, 3]'],
    'a list of lists' => ['[["@type"]]'],
]);
