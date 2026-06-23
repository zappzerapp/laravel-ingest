<?php

declare(strict_types=1);

namespace LaravelIngest\Contracts;

use Generator;

/**
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
