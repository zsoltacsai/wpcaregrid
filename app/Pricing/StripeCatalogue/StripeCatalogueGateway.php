<?php

namespace App\Pricing\StripeCatalogue;

/**
 * The only Stripe operations the catalogue manager performs: list Products and Prices, create one
 * Product, create one Price. It cannot update, archive or delete anything and never touches customers,
 * subscriptions or invoices. Every created object carries a deterministic idempotency key.
 */
interface StripeCatalogueGateway
{
    public function mode(): StripeMode;

    /**
     * @return list<RemoteProduct> Every Product, active or not.
     *
     * @throws CatalogueGatewayException
     */
    public function products(): array;

    /**
     * @return list<RemotePrice> Every Price, active or not.
     *
     * @throws CatalogueGatewayException
     */
    public function prices(): array;

    /**
     * @param  array{name: string, metadata: array<string, string>}  $params
     *
     * @throws CatalogueGatewayException
     */
    public function createProduct(array $params, string $idempotencyKey): RemoteProduct;

    /**
     * @param  array{product: string, currency: string, unit_amount: int, interval: string, nickname: string, metadata: array<string, string>}  $params
     *
     * @throws CatalogueGatewayException
     */
    public function createPrice(array $params, string $idempotencyKey): RemotePrice;
}
