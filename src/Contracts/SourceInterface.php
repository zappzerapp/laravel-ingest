<?php

declare(strict_types=1);

namespace LaravelIngest\Contracts;

use Generator;

/**
 * Interface for custom data sources that can be used with IngestConfig::fromSource().
 *
 * Source classes implementing this interface allow importing from any external system
 * (APIs, databases, message queues, etc.) by providing a unified streaming interface.
 *
 * @example
 * class ShopifyProductSource implements SourceInterface
 * {
 *     public function __construct(
 *         private string $shopDomain,
 *         private string $apiKey
 *     ) {}
 *
 *     public function read(): Generator
 *     {
 *         $client = new ShopifyClient($this->shopDomain, $this->apiKey);
 *         foreach ($client->getProducts() as $product) {
 *             yield [
 *                 'id' => $product['id'],
 *                 'title' => $product['title'],
 *                 'price' => $product['variants'][0]['price'] ?? null,
 *             ];
 *         }
 *     }
 *
 *     public function getSchema(): array
 *     {
 *         return [
 *             'id' => ['type' => 'integer', 'required' => true],
 *             'title' => ['type' => 'string', 'required' => true],
 *             'price' => ['type' => 'numeric', 'required' => false],
 *         ];
 *     }
 *
 *     public function getSourceMetadata(): array
 *     {
 *         return [
 *             'shop_domain' => $this->shopDomain,
 *             'total_count' => $this->getTotalCount(),
 *         ];
 *     }
 * }
 *
 * // Usage in importer:
 * ->fromSource(new ShopifyProductSource('my-shop.myshopify.com', $apiKey))
 */
interface SourceInterface
{
    /**
     * @return Generator<int, array<string, mixed>>
     */
    public function read(): Generator;

    /**
     * @return array<string, array{type: string, required?: bool, nullable?: bool}>
     */
    public function getSchema(): array;

    /**
     * @return array<string, mixed>
     */
    public function getSourceMetadata(): array;
}
