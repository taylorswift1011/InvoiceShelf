<?php

use App\Domains\Accounts\Models\CompanySetting;
use App\Domains\Accounts\Models\User;
use App\Domains\Catalog\Models\Item;
use App\Domains\Contacts\Models\Customer;
use App\Domains\Money\Models\Currency;
use App\Domains\Sales\Application\Composition\SalesDocumentComposer;
use App\Domains\Sales\Models\Estimate;
use App\Domains\Sales\Models\Invoice;
use App\Domains\Taxation\Models\TaxType;
use App\Support\DocumentTaxes;
use Illuminate\Support\Facades\Artisan;
use Laravel\Sanctum\Sanctum;

use function Pest\Laravel\getJson;
use function Pest\Laravel\postJson;
use function Pest\Laravel\putJson;

/**
 * When a company taxes whole documents, a line taken from a zero-rated
 * catalogue item is left out of the document's taxes (GST): the tax is charged
 * on the other lines, less their share of the document discount.
 */
beforeEach(function () {
    Artisan::call('db:seed', ['--class' => 'DatabaseSeeder', '--force' => true]);
    Artisan::call('db:seed', ['--class' => 'DemoSeeder', '--force' => true]);

    $this->user = User::query()->findOrFail(1);
    $this->companyId = (int) $this->user->companies()->first()->id;
    $this->withHeaders(['company' => $this->companyId]);
    Sanctum::actingAs($this->user, ['*']);

    $usd = Currency::query()->where('code', 'USD')->value('id');
    CompanySetting::setSettings([
        'currency' => $usd,
        'tax_per_item' => 'NO',
        'discount_per_item' => 'NO',
        'tax_included' => 'NO',
        'time_zone' => 'UTC',
    ], $this->companyId);

    $this->customer = Customer::factory()->create(['company_id' => $this->companyId, 'currency_id' => $usd]);
    $this->gst = TaxType::factory()->create(['company_id' => $this->companyId, 'name' => 'GST', 'percent' => 15, 'type' => TaxType::TYPE_GENERAL]);

    $this->hosting = Item::factory()->create(['company_id' => $this->companyId, 'name' => 'Hosting', 'price' => 20000, 'zero_rated' => true]);
    $this->hourly = Item::factory()->create(['company_id' => $this->companyId, 'name' => 'Hourly', 'price' => 8000, 'zero_rated' => false]);

    $this->composer = app(SalesDocumentComposer::class);
});

function zeroRatedInvoice(array $extra = []): array
{
    return test()->composer->invoice([
        'customer_id' => test()->customer->id,
        'lines' => [
            ['item_id' => test()->hourly->id, 'quantity' => 1],
            ['item_id' => test()->hosting->id, 'quantity' => 1],
        ],
        'tax_type_ids' => [test()->gst->id],
        ...$extra,
    ], test()->companyId, test()->user);
}

test('the taxable base leaves zero-rated lines out, with their share of the discount', function () {
    expect(DocumentTaxes::taxableBase(10000, 10000, 1000))->toBe(9000)   // nothing zero-rated: as always
        ->and(DocumentTaxes::taxableBase(28000, 8000, 0))->toBe(8000)    // only the taxable line
        ->and(DocumentTaxes::taxableBase(28000, 8000, 2800))->toBe(7200) // 10% discount shared pro rata
        ->and(DocumentTaxes::taxableBase(10000, 0, 500))->toBe(0)        // everything zero-rated
        ->and(DocumentTaxes::taxableBase(0, 0, 0))->toBe(0);
});

test('GST on a document is charged on the lines that are not zero-rated', function () {
    $payload = zeroRatedInvoice();

    expect($payload['sub_total'])->toBe(28000)
        ->and($payload['taxes'][0]['amount'])->toBe(1200) // 15% of 8000, hosting left out
        ->and($payload['total'])->toBe(29200);

    $id = postJson('api/v1/invoices', $payload)->assertOk()->json('data.id');
    $invoice = Invoice::query()->with('items')->findOrFail($id);

    expect($invoice->tax)->toBe(1200)
        ->and($invoice->total)->toBe(29200)
        ->and($invoice->items->firstWhere('item_id', $this->hosting->id)->zero_rated)->toBeTrue()
        ->and($invoice->items->firstWhere('item_id', $this->hourly->id)->zero_rated)->toBeFalse();
});

test('a document discount is shared between taxed and zero-rated lines', function () {
    $payload = zeroRatedInvoice(['discount' => '10', 'discount_type' => 'percentage']);

    // 10% off 28000 = 2800; the taxed line carries 8000/28000 of it (800): GST on 7200
    expect($payload['discount_val'])->toBe(2800)
        ->and($payload['taxes'][0]['amount'])->toBe(1080);
});

test('without zero-rated lines the document tax is what it always was', function () {
    $payload = $this->composer->invoice([
        'customer_id' => $this->customer->id,
        'lines' => [['item_id' => $this->hourly->id, 'quantity' => 2]],
        'tax_type_ids' => [$this->gst->id],
        'discount' => '10',
    ], $this->companyId, $this->user);

    // 16000 less a 10.00 discount = 15000, 15% = 2250
    expect($payload['taxes'][0]['amount'])->toBe(2250);
});

test('the zero-rated mark comes from the catalogue, not from what the client sends', function () {
    $payload = zeroRatedInvoice();
    $payload['items'][0]['zero_rated'] = true;  // hourly claimed zero-rated
    $payload['items'][1]['zero_rated'] = false; // hosting claimed taxable
    $payload['items'][] = [...$payload['items'][0], 'item_id' => null, 'name' => 'Typed line', 'zero_rated' => true];

    $id = postJson('api/v1/invoices', $payload)->assertOk()->json('data.id');
    $items = Invoice::query()->with('items')->findOrFail($id)->items;

    expect($items->firstWhere('item_id', $this->hourly->id)->zero_rated)->toBeFalse()
        ->and($items->firstWhere('item_id', $this->hosting->id)->zero_rated)->toBeTrue()
        ->and($items->firstWhere('name', 'Typed line')->zero_rated)->toBeFalse();
});

test('lines report whether they are zero-rated, for the form and the PDF', function () {
    $id = postJson('api/v1/invoices', zeroRatedInvoice())->assertOk()->json('data.id');

    $lines = collect(getJson("api/v1/invoices/{$id}")->assertOk()->json('data.items'));

    expect($lines->firstWhere('name', 'Hosting')['zero_rated'])->toBeTrue()
        ->and($lines->firstWhere('name', 'Hourly')['zero_rated'])->toBeFalse();
});

test('an update re-reads the mark, and estimates work the same way', function () {
    $id = postJson('api/v1/invoices', zeroRatedInvoice())->assertOk()->json('data.id');
    $this->hosting->update(['zero_rated' => false]);

    putJson("api/v1/invoices/{$id}", zeroRatedInvoice(['invoice_number' => Invoice::query()->findOrFail($id)->invoice_number]))->assertOk();
    expect(Invoice::query()->with('items')->findOrFail($id)->items->every(fn ($item) => ! $item->zero_rated))->toBeTrue();

    $this->hosting->update(['zero_rated' => true]);
    $estimate = $this->composer->estimate([
        'customer_id' => $this->customer->id,
        'lines' => [['item_id' => $this->hourly->id], ['item_id' => $this->hosting->id]],
        'tax_type_ids' => [$this->gst->id],
    ], $this->companyId, $this->user);

    expect($estimate['taxes'][0]['amount'])->toBe(1200);

    $estimateId = postJson('api/v1/estimates', $estimate)->assertSuccessful()->json('data.id');
    expect(Estimate::query()->with('items')->findOrFail($estimateId)->items->firstWhere('item_id', $this->hosting->id)->zero_rated)->toBeTrue();
});

test('the invoice PDF marks a zero-rated line', function () {
    $id = postJson('api/v1/invoices', zeroRatedInvoice())->assertOk()->json('data.id');
    $invoice = Invoice::query()->with(['items', 'customer', 'company'])->findOrFail($id);

    $html = view('app.pdf.invoice.partials.table', [
        'invoice' => $invoice,
        'customFields' => collect(),
        'taxes' => collect(),
    ])->render();

    expect(substr_count($html, 'Zero rated'))->toBe(1);
})->skip(fn () => ! view()->exists('app.pdf.invoice.partials.table'), 'invoice table partial not present');

test('a sales tax can be added to new documents by default; a purchase tax cannot', function () {
    $sales = postJson('api/v1/tax-types', [
        'name' => 'GST default',
        'calculation_type' => 'percentage',
        'percent' => 15,
        'transaction_type' => 'sales',
        'is_default' => true,
    ])->assertSuccessful()->json('data');

    expect($sales['is_default'])->toBeTrue()
        ->and(TaxType::query()->findOrFail($sales['id'])->is_default)->toBeTrue();

    postJson('api/v1/tax-types', [
        'name' => 'Input GST default',
        'calculation_type' => 'percentage',
        'percent' => 15,
        'transaction_type' => 'purchases',
        'is_default' => true,
    ])->assertUnprocessable()->assertJsonValidationErrors(['is_default']);

    expect(getJson('api/v1/tax-types?limit=all&transaction_type=sales')->assertOk()->json('data.*.is_default'))->toContain(true);
});
