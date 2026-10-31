<?php

namespace App\Domains\Sales\Http\Resources;

use App\Domains\Metadata\Http\Resources\CustomFieldValueResource;
use App\Domains\Taxation\Http\Resources\TaxResource;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * One line of an invoice as the admin API publishes it.
 *
 * The line is a snapshot: the catalogue item it came from is only referenced by
 * id, every figure is stored on the row itself, and each amount is paired with
 * its `base_` twin (the same money converted at the document's exchange rate).
 * Line taxes and custom field values ride along whenever the row has any.
 */
class InvoiceItemResource extends JsonResource
{
    /**
     * @param  Request  $request
     */
    public function toArray($request): array
    {
        $item = $this->resource;

        return [
            'id' => $item->id,
            'name' => $item->name,
            'description' => $item->description,
            'discount_type' => $item->discount_type,
            'price' => $item->price,
            'zero_rated' => (bool) $item->zero_rated,
            'quantity' => $item->quantity,
            'unit_name' => $item->unit_name,
            'discount' => $item->discount,
            'discount_val' => $item->discount_val,
            'tax' => $item->tax,
            'total' => $item->total,
            'invoice_id' => $item->invoice_id,
            'item_id' => $item->item_id,
            'company_id' => $item->company_id,
            'base_price' => $item->base_price,
            'exchange_rate' => $item->exchange_rate,
            'base_discount_val' => $item->base_discount_val,
            'base_tax' => $item->base_tax,
            'base_total' => $item->base_total,
            'recurring_invoice_id' => $item->recurring_invoice_id,
            'taxes' => $this->when(
                $item->taxes()->exists(),
                fn () => TaxResource::collection($item->taxes)
            ),
            'fields' => $this->when(
                $item->fields()->exists(),
                fn () => CustomFieldValueResource::collection($item->fields)
            ),
        ];
    }
}
