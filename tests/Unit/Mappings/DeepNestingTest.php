<?php

declare(strict_types=1);

use LaravelIngest\IngestConfig;
use LaravelIngest\NestedIngestConfig;
use LaravelIngest\Services\DataTransformationService;
use LaravelIngest\Tests\Fixtures\Models\Order;

it('flattens deeply nested data via beforeRow callback for single-level nest mapping', function () {
    $config = IngestConfig::for(Order::class)
        ->map('order_id', 'id')
        ->map('customer_email', 'email')
        ->beforeRow(function (array &$row) {
            if (!isset($row['shipments']) || !is_array($row['shipments'])) {
                $row['shipments_flat'] = [];

                return;
            }

            $flat = [];
            foreach ($row['shipments'] as $shipment) {
                foreach ($shipment['packages'] ?? [] as $package) {
                    $flat[] = [
                        'tracking' => $shipment['tracking_number'],
                        'pkg_ref' => $package['reference'],
                        'weight' => $package['weight_kg'],
                    ];
                }
            }

            $row['shipments_flat'] = $flat;
        })
        ->nest('shipments_flat', function (NestedIngestConfig $nested) {
            $nested->map('tracking', 'tracking_number')
                ->map('pkg_ref', 'package_reference')
                ->mapAndTransform('weight', 'weight_kg', fn($v) => (float) $v);
        });

    expect($config->nestedConfigs)->toHaveKey('shipments_flat');

    $service = new DataTransformationService();

    $input = [
        'order_id' => 'ORD-999',
        'customer_email' => 'alice@example.com',
        'shipments' => [
            [
                'tracking_number' => 'TRACK-001',
                'packages' => [
                    ['reference' => 'PKG-A1', 'weight_kg' => '1.5'],
                    ['reference' => 'PKG-A2', 'weight_kg' => '2.0'],
                ],
            ],
            [
                'tracking_number' => 'TRACK-002',
                'packages' => [
                    ['reference' => 'PKG-B1', 'weight_kg' => '0.8'],
                ],
            ],
        ],
    ];

    $callback = $config->beforeRowCallback->getClosure();
    $callback($input);

    $result = $service->processNestedData($input, $config->nestedConfigs);

    expect($result)->toHaveKey('shipments_flat')
        ->and($result['shipments_flat'])->toHaveCount(3)
        ->and($result['shipments_flat'][0])->toBe([
            'tracking_number' => 'TRACK-001',
            'package_reference' => 'PKG-A1',
            'weight_kg' => 1.5,
        ])
        ->and($result['shipments_flat'][1])->toBe([
            'tracking_number' => 'TRACK-001',
            'package_reference' => 'PKG-A2',
            'weight_kg' => 2.0,
        ])
        ->and($result['shipments_flat'][2])->toBe([
            'tracking_number' => 'TRACK-002',
            'package_reference' => 'PKG-B1',
            'weight_kg' => 0.8,
        ]);
});

it('handles empty deep nested structures gracefully', function () {
    $config = IngestConfig::for(Order::class)
        ->map('order_id', 'id')
        ->beforeRow(function (array &$row) {
            $row['shipments_flat'] = [];
            if (isset($row['shipments']) && is_array($row['shipments'])) {
                foreach ($row['shipments'] as $shipment) {
                    foreach ($shipment['packages'] ?? [] as $package) {
                        $row['shipments_flat'][] = [
                            'tracking' => $shipment['tracking_number'] ?? null,
                            'pkg_ref' => $package['reference'] ?? null,
                            'weight' => $package['weight_kg'] ?? null,
                        ];
                    }
                }
            }
        })
        ->nest('shipments_flat', function (NestedIngestConfig $nested) {
            $nested->map('tracking', 'tracking_number')
                ->map('pkg_ref', 'package_reference')
                ->mapAndTransform('weight', 'weight_kg', fn($v) => $v !== null ? (float) $v : null);
        });

    $service = new DataTransformationService();

    $input = [
        'order_id' => 'ORD-000',
        'customer_email' => 'bob@example.com',
        'shipments' => [],
    ];

    $callback = $config->beforeRowCallback->getClosure();
    $callback($input);

    $result = $service->processNestedData($input, $config->nestedConfigs);

    expect($result['shipments_flat'])->toBeEmpty();
});

it('validates flattened nested items using mapAndValidate', function () {
    $config = IngestConfig::for(Order::class)
        ->beforeRow(function (array &$row) {
            $row['shipments_flat'] = [];
            foreach ($row['shipments'] ?? [] as $shipment) {
                foreach ($shipment['packages'] ?? [] as $package) {
                    $row['shipments_flat'][] = [
                        'tracking' => $shipment['tracking_number'],
                        'pkg_ref' => $package['reference'],
                    ];
                }
            }
        })
        ->nest('shipments_flat', function (NestedIngestConfig $nested) {
            $nested->mapAndValidate(
                'tracking',
                'tracking_number',
                LaravelIngest\Validators\RegexValidator::class
            )
                ->map('pkg_ref', 'package_reference');
        });

    expect($config->nestedConfigs['shipments_flat']->getValidators())->toHaveKey('tracking');
});
