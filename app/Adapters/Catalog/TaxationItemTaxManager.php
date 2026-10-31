<?php

namespace App\Adapters\Catalog;

use App\Domains\Catalog\Contracts\ItemTaxManager;
use App\Domains\Catalog\Models\Item;
use App\Domains\Taxation\Models\TaxType;

class TaxationItemTaxManager implements ItemTaxManager
{
    public function attach(Item $item, array $taxes, int $companyId): void
    {
        if ($taxes === []) {
            return;
        }

        $item->update(['tax_per_item' => true]);

        foreach ($taxes as $tax) {
            $item->taxes()->create([
                ...$tax,
                'company_id' => $companyId,
            ]);
        }
    }

    public function zeroRated(int $companyId): array
    {
        $taxType = TaxType::query()->firstOrCreate([
            'company_id' => $companyId,
            'type' => TaxType::TYPE_GENERAL,
            'transaction_type' => TaxType::TRANSACTION_TYPE_SALES,
            'calculation_type' => 'percentage',
            'percent' => 0,
            'tax_category_code' => TaxType::TAX_CATEGORY_CODE_ZERO_RATED,
        ], [
            'name' => 'Zero rated',
            'compound_tax' => false,
        ]);

        return [[
            'tax_type_id' => $taxType->id,
            'name' => $taxType->name,
            'percent' => 0,
            'calculation_type' => 'percentage',
            'amount' => 0,
            'fixed_amount' => null,
            'compound_tax' => false,
            'collective_tax' => 0,
        ]];
    }

    public function replace(Item $item, array $taxes, int $companyId): void
    {
        $item->taxes()->delete();

        $this->attach($item, $taxes, $companyId);
    }
}
