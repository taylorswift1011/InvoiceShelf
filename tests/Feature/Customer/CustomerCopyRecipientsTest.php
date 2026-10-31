<?php

use App\Domains\Accounts\Models\CompanySetting;
use App\Domains\Accounts\Models\User;
use App\Domains\Contacts\Models\Customer;
use App\Domains\Money\Models\Currency;
use App\Domains\Receivables\Http\Requests\SendPaymentRequest;
use App\Domains\Reporting\Http\Requests\SendCustomerStatementRequest;
use App\Domains\Sales\Application\Composition\EstimateMailDefaults;
use App\Domains\Sales\Application\Composition\InvoiceMailDefaults;
use App\Domains\Sales\Application\InvoiceReminderService;
use App\Domains\Sales\Application\ReminderSettings;
use App\Domains\Sales\Contracts\InvoiceEmailSender;
use App\Domains\Sales\Mail\SendEstimateMail;
use App\Domains\Sales\Mail\SendInvoiceMail;
use App\Domains\Sales\Models\Estimate;
use App\Domains\Sales\Models\Invoice;
use App\Domains\Sales\Models\RecurringInvoice;
use Carbon\Carbon;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Validator;
use Laravel\Sanctum\Sanctum;

use function Pest\Laravel\artisan;
use function Pest\Laravel\getJson;
use function Pest\Laravel\postJson;
use function Pest\Laravel\putJson;

/**
 * A customer can keep extra addresses that are copied (CC) or blind-copied
 * (BCC) on every email sent to them: the send dialogs prefill them, the send
 * endpoints take several addresses per field, and automatic sends include them.
 */
beforeEach(function () {
    Artisan::call('db:seed', ['--class' => 'DatabaseSeeder', '--force' => true]);
    Artisan::call('db:seed', ['--class' => 'DemoSeeder', '--force' => true]);

    $this->user = User::query()->findOrFail(1);
    $this->companyId = (int) $this->user->companies()->first()->id;
    $this->withHeaders(['company' => $this->companyId]);
    Sanctum::actingAs($this->user, ['*']);

    Mail::fake();
});

afterEach(fn () => Carbon::setTestNow());

function copiedCustomer($test, array $attributes = []): Customer
{
    return Customer::factory()->create([
        'company_id' => $test->companyId,
        'email' => 'billing@acme.test',
        'cc_emails' => ['accounts@acme.test', 'owner@acme.test'],
        'bcc_emails' => ['archive@acme.test'],
        ...$attributes,
    ]);
}

test('a customer is saved with CC and BCC lists typed as text', function () {
    $payload = Customer::factory()->raw([
        'cc_emails' => 'accounts@acme.test; owner@acme.test, accounts@acme.test',
        'bcc_emails' => "archive@acme.test\n",
    ]);

    $id = postJson('api/v1/customers', $payload)->assertOk()->json('data.id');

    $customer = Customer::query()->findOrFail($id);
    expect($customer->cc_emails)->toBe(['accounts@acme.test', 'owner@acme.test'])
        ->and($customer->bcc_emails)->toBe(['archive@acme.test']);

    getJson("api/v1/customers/{$id}")
        ->assertOk()
        ->assertJsonPath('data.cc_emails', ['accounts@acme.test', 'owner@acme.test'])
        ->assertJsonPath('data.bcc_emails', ['archive@acme.test']);
});

test('a customer is saved with CC and BCC lists sent as arrays', function () {
    $payload = Customer::factory()->raw(['cc_emails' => ['a@acme.test', 'b@acme.test'], 'bcc_emails' => []]);

    $id = postJson('api/v1/customers', $payload)->assertOk()->json('data.id');

    expect(Customer::query()->findOrFail($id)->cc_emails)->toBe(['a@acme.test', 'b@acme.test'])
        ->and(Customer::query()->findOrFail($id)->bcc_emails)->toBeNull();
});

test('an invalid address in a list is refused and named', function () {
    $payload = Customer::factory()->raw(['cc_emails' => 'accounts@acme.test, not-an-email']);

    postJson('api/v1/customers', $payload)
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['cc_emails']);
});

test('an update that leaves the lists out keeps them, and an empty list clears them', function () {
    $customer = copiedCustomer($this);
    $base = ['name' => $customer->name, 'email' => $customer->email, 'enable_portal' => false];

    putJson("api/v1/customers/{$customer->id}", $base)->assertOk();
    expect($customer->fresh()->cc_emails)->toBe(['accounts@acme.test', 'owner@acme.test']);

    putJson("api/v1/customers/{$customer->id}", [...$base, 'cc_emails' => '', 'bcc_emails' => []])->assertOk();
    expect($customer->fresh()->cc_emails)->toBeNull()
        ->and($customer->fresh()->bcc_emails)->toBeNull();
});

test('the invoice and estimate send dialogs are prefilled with the customer lists', function () {
    $customer = copiedCustomer($this);
    $invoice = Invoice::factory()->create(['company_id' => $this->companyId, 'customer_id' => $customer->id]);
    $estimate = Estimate::factory()->create(['company_id' => $this->companyId, 'customer_id' => $customer->id]);

    $invoiceDefaults = app(InvoiceMailDefaults::class)->for($invoice, 'en');
    $estimateDefaults = app(EstimateMailDefaults::class)->for($estimate, 'en');

    expect($invoiceDefaults['to'])->toBe('billing@acme.test')
        ->and($invoiceDefaults['cc'])->toBe('accounts@acme.test, owner@acme.test')
        ->and($invoiceDefaults['bcc'])->toBe('archive@acme.test')
        ->and($estimateDefaults['cc'])->toBe('accounts@acme.test, owner@acme.test')
        ->and($estimateDefaults['bcc'])->toBe('archive@acme.test');
});

test('an invoice is sent to every CC and BCC address typed in the dialog', function () {
    $invoice = Invoice::factory()->create(['company_id' => $this->companyId, 'invoice_date' => '2026-01-01', 'due_date' => '2026-02-01']);

    postJson("api/v1/invoices/{$invoice->id}/send", [
        'from' => 'john@example.com',
        'to' => 'doe@example.com',
        'cc' => 'accounts@acme.test, owner@acme.test',
        'bcc' => 'archive@acme.test; audit@acme.test',
        'subject' => 'Your invoice',
        'body' => 'Please find it attached',
    ])->assertOk();

    Mail::assertSent(SendInvoiceMail::class, fn (SendInvoiceMail $mail) => $mail->hasTo('doe@example.com')
        && $mail->hasCc('accounts@acme.test') && $mail->hasCc('owner@acme.test')
        && $mail->hasBcc('archive@acme.test') && $mail->hasBcc('audit@acme.test'));
});

test('an estimate is sent to every CC and BCC address typed in the dialog', function () {
    $estimate = Estimate::factory()->create(['company_id' => $this->companyId]);

    postJson("api/v1/estimates/{$estimate->id}/send", [
        'from' => 'john@example.com',
        'to' => 'doe@example.com',
        'cc' => 'accounts@acme.test, owner@acme.test',
        'bcc' => 'archive@acme.test',
        'subject' => 'Your estimate',
        'body' => 'Please find it attached',
    ])->assertOk();

    Mail::assertSent(SendEstimateMail::class, fn (SendEstimateMail $mail) => $mail->hasCc('accounts@acme.test')
        && $mail->hasCc('owner@acme.test') && $mail->hasBcc('archive@acme.test'));
});

test('a send with an invalid CC address is refused before anything goes out', function () {
    $invoice = Invoice::factory()->create(['company_id' => $this->companyId]);

    postJson("api/v1/invoices/{$invoice->id}/send", [
        'from' => 'john@example.com',
        'to' => 'doe@example.com',
        'cc' => 'accounts@acme.test, nope',
        'subject' => 'Your invoice',
        'body' => 'Body',
    ])->assertUnprocessable()->assertJsonValidationErrors(['cc']);

    Mail::assertNothingSent();
});

test('payment receipts and statements take address lists too', function () {
    $rules = fn (string $request) => array_intersect_key((new $request)->rules(), ['cc' => 1, 'bcc' => 1]);

    foreach ([SendPaymentRequest::class, SendCustomerStatementRequest::class] as $request) {
        expect(Validator::make(['cc' => 'a@acme.test, b@acme.test', 'bcc' => 'c@acme.test'], $rules($request))->passes())->toBeTrue()
            ->and(Validator::make(['cc' => 'a@acme.test, nope'], $rules($request))->passes())->toBeFalse();
    }
});

test('payment reminders are copied to the customer lists', function () {
    $usd = (int) Currency::query()->where('code', 'USD')->value('id');
    CompanySetting::setSettings(['currency' => $usd, 'time_zone' => 'UTC'], $this->companyId);
    ReminderSettings::save($this->companyId, ['enabled' => true]);
    $customer = copiedCustomer($this, ['currency_id' => $usd]);
    Invoice::factory()->create([
        'company_id' => $this->companyId,
        'customer_id' => $customer->id,
        'currency_id' => $usd,
        'type' => Invoice::TYPE_INVOICE,
        'status' => Invoice::STATUS_SENT,
        'sent' => true,
        'paid_status' => Invoice::STATUS_UNPAID,
        'due_date' => '2026-06-10',
        'total' => 10000,
        'due_amount' => 10000,
        'base_total' => 10000,
        'base_due_amount' => 10000,
        'exchange_rate' => 1,
    ]);

    Carbon::setTestNow('2026-06-11 10:00:00');
    expect(app(InvoiceReminderService::class)->sendDue(CarbonImmutable::now()))->toBe(1);

    Mail::assertSent(SendInvoiceMail::class, fn (SendInvoiceMail $mail) => $mail->hasTo('billing@acme.test')
        && $mail->hasCc('accounts@acme.test') && $mail->hasCc('owner@acme.test')
        && $mail->hasBcc('archive@acme.test'));
});

test('recurring invoices sent automatically are copied to the customer lists', function () {
    $sent = new class implements InvoiceEmailSender
    {
        /** @var array<int, array<string, mixed>> */
        public array $calls = [];

        public function send(array $data, bool $creditNote): void
        {
            $this->calls[] = $data;
        }
    };
    app()->instance(InvoiceEmailSender::class, $sent);

    Carbon::setTestNow('2026-06-15 12:00:00');
    CompanySetting::setSettings(['time_zone' => 'UTC'], $this->companyId);
    $customer = copiedCustomer($this);
    RecurringInvoice::factory()->create([
        'company_id' => $this->companyId,
        'customer_id' => $customer->id,
        'currency_id' => $customer->currency_id,
        'creator_id' => $this->user->id,
        'status' => RecurringInvoice::ACTIVE,
        'frequency' => '0 0 * * *',
        'limit_by' => RecurringInvoice::NONE,
        'starts_at' => '2026-06-01 00:00:00',
        'next_invoice_at' => '2026-06-15 00:00:00',
        'send_automatically' => true,
    ]);

    artisan('recurring-invoices:generate')->assertSuccessful();

    expect($sent->calls)->toHaveCount(1)
        ->and($sent->calls[0]['to'])->toBe('billing@acme.test')
        ->and($sent->calls[0]['cc'])->toBe('accounts@acme.test, owner@acme.test')
        ->and($sent->calls[0]['bcc'])->toBe('archive@acme.test');
});

test('documents carry the customer lists, so the send dialogs can prefill them', function () {
    $customer = copiedCustomer($this);
    $invoice = Invoice::factory()->create(['company_id' => $this->companyId, 'customer_id' => $customer->id]);

    getJson("api/v1/invoices/{$invoice->id}")
        ->assertOk()
        ->assertJsonPath('data.customer.cc_emails', ['accounts@acme.test', 'owner@acme.test'])
        ->assertJsonPath('data.customer.bcc_emails', ['archive@acme.test']);
});
