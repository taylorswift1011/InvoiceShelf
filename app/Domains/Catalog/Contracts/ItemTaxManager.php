<?php

namespace App\Domains\Catalog\Contracts;

use App\Domains\Catalog\Models\Item;

interface ItemTaxManager
{
    /** @param array<int, array<string, mixed>> $taxes */
    public function attach(Item $item, array $taxes, int $companyId): void;

    /**
     * The tax rows that make an item zero-rated: the company's zero-rated
     * sales tax type, created on first use.
     *
     * @return array<int, array<string, mixed>>
     */
    public function zeroRated(int $companyId): array;

    /** @param array<int, array<string, mixed>> $taxes */
    public function replace(Item $item, array $taxes, int $companyId): void;
}
