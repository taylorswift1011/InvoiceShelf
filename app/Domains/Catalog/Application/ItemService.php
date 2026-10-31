<?php

namespace App\Domains\Catalog\Application;

use App\Domains\Accounts\Models\CompanySetting;
use App\Domains\Catalog\Contracts\ItemTaxManager;
use App\Domains\Catalog\Models\Item;
use App\Domains\Metadata\Contracts\CustomFieldValueWriter;

class ItemService
{
    public function __construct(
        private readonly ItemTaxManager $itemTaxManager,
        private readonly CustomFieldValueWriter $customFieldValueWriter,
    ) {}

    /**
     * @param  array<string, mixed>  $attributes
     * @param  array<int, array<string, mixed>>  $taxes
     * @param  iterable<int, mixed>|null  $customFields
     */
    public function create(array $attributes, array $taxes, int $companyId, int $creatorId, ?iterable $customFields = null): Item
    {
        $taxes = $this->taxesFor($attributes, $taxes, $companyId);

        $attributes['company_id'] = $companyId;
        $attributes['creator_id'] = $creatorId;
        $attributes['currency_id'] = CompanySetting::getSetting('currency', $companyId);

        $item = Item::create($attributes);

        $this->itemTaxManager->attach($item, $taxes, $companyId);

        if ($customFields) {
            $this->customFieldValueWriter->attach($item, $customFields);
        }

        return Item::query()->with(['taxes', 'fields'])->findOrFail($item->getKey());
    }

    /**
     * @param  array<string, mixed>  $attributes
     * @param  array<int, array<string, mixed>>  $taxes
     * @param  iterable<int, mixed>|null  $customFields
     */
    public function update(Item $item, array $attributes, array $taxes, int $companyId, ?iterable $customFields = null): Item
    {
        $taxes = $this->taxesFor($attributes, $taxes, $companyId);

        $item->update($attributes);

        $this->itemTaxManager->replace($item, $taxes, $companyId);

        if ($customFields) {
            $this->customFieldValueWriter->update($item, $customFields);
        }

        return Item::query()->with(['taxes', 'fields'])->findOrFail($item->getKey());
    }

    /**
     * A zero-rated item carries the zero-rated tax and nothing else,
     * whatever taxes were sent with it.
     *
     * @param  array<string, mixed>  $attributes
     * @param  array<int, array<string, mixed>>  $taxes
     * @return array<int, array<string, mixed>>
     */
    private function taxesFor(array $attributes, array $taxes, int $companyId): array
    {
        return ! empty($attributes['zero_rated'])
            ? $this->itemTaxManager->zeroRated($companyId)
            : $taxes;
    }
}
