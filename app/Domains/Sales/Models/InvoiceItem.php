<?php

namespace App\Domains\Sales\Models;

use App\Domains\Catalog\Models\Item;
use App\Domains\Metadata\Concerns\HasCustomFields;
use App\Domains\Taxation\Models\Tax;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\DB;

/**
 * One priced line of an invoice.
 *
 * The row is a snapshot rather than a reference: the name, description and
 * price are copied off the catalog entry when the line is written, so later
 * edits to the catalog never rewrite history on a document already issued. The
 * link back to the catalog entry survives for reporting only.
 *
 * Recurring invoice templates keep their lines in this same table, which is why
 * a line can belong to a schedule instead of to a document.
 */
class InvoiceItem extends Model
{
    use HasCustomFields;
    use HasFactory;

    protected $table = 'invoice_items';

    /**
     * Everything but the primary key may be mass assigned.
     *
     * @var array
     */
    protected $guarded = [
        'id',
    ];

    /**
     * Attribute casts.
     *
     * Money is whole minor units. Quantity is fractional so that partial units
     * (hours, kilos, a half day) can be billed, and the percentage discount is
     * fractional for the same reason.
     */
    protected function casts(): array
    {
        return [
            'price' => 'integer',
            'total' => 'integer',
            'discount' => 'float',
            'quantity' => 'float',
            'discount_val' => 'integer',
            'tax' => 'integer',
            'zero_rated' => 'boolean',
        ];
    }

    /*
    |--------------------------------------------------------------------------
    | Relationships
    |--------------------------------------------------------------------------
    */

    /**
     * Document this line was billed on.
     */
    public function invoice(): BelongsTo
    {
        return $this->belongsTo(Invoice::class);
    }

    /**
     * Catalog entry the line was copied from, kept for reporting.
     */
    public function item(): BelongsTo
    {
        return $this->belongsTo(Item::class);
    }

    /**
     * Taxes applied to this line, in per-item tax mode.
     */
    public function taxes(): HasMany
    {
        return $this->hasMany(Tax::class);
    }

    /**
     * Schedule this line belongs to, when it is part of a recurring template
     * rather than of an issued document.
     */
    public function recurringInvoice(): BelongsTo
    {
        return $this->belongsTo(RecurringInvoice::class);
    }

    /*
    |--------------------------------------------------------------------------
    | Query scopes
    |--------------------------------------------------------------------------
    */

    /**
     * Narrow to one company.
     */
    public function scopeWhereCompany(Builder $query, int $company_id): void
    {
        $query->where('company_id', $company_id);
    }

    /**
     * Restrict to lines billed on a document issued inside the inclusive range.
     */
    public function scopeInvoicesBetween(Builder $query, Carbon $start, Carbon $end): void
    {
        $range = [$start->format('Y-m-d'), $end->format('Y-m-d')];

        $query->whereHas('invoice', function ($invoice) use ($range) {
            $invoice->whereBetween('invoice_date', $range);
        });
    }

    /**
     * Apply the date range, which counts only when the caller supplied both
     * ends of it.
     */
    public function scopeApplyInvoiceFilters(Builder $query, array $filters): void
    {
        $from = $filters['from_date'] ?? null;
        $to = $filters['to_date'] ?? null;

        if ($from && $to) {
            $query->invoicesBetween(
                Carbon::createFromFormat('Y-m-d', $from),
                Carbon::createFromFormat('Y-m-d', $to)
            );
        }
    }

    /**
     * Roll the lines up by product name, totalling quantity sold and the
     * revenue it brought in, in the company's own currency.
     */
    public function scopeItemAttributes(Builder $query): void
    {
        $columns = [
            'sum(quantity) as total_quantity',
            'sum(base_total) as total_amount',
            'invoice_items.name',
        ];

        $query->select(DB::raw(implode(', ', $columns)))
            ->groupBy('invoice_items.name');
    }
}
