<?php

use App\Domains\Accounts\Models\User;
use App\Domains\Catalog\Http\Controllers\ItemsController;
use App\Domains\Catalog\Http\Requests\ItemsRequest;
use App\Domains\Catalog\Models\Item;
use App\Domains\Taxation\Models\Tax;
use App\Domains\Taxation\Models\TaxType;
use Illuminate\Support\Facades\Artisan;
use Laravel\Sanctum\Sanctum;

use function Pest\Laravel\getJson;
use function Pest\Laravel\postJson;
use function Pest\Laravel\putJson;

beforeEach(function () {
    Artisan::call('db:seed', ['--class' => 'DatabaseSeeder', '--force' => true]);
    Artisan::call('db:seed', ['--class' => 'DemoSeeder', '--force' => true]);

    $user = User::find(1);
    $this->withHeaders([
        'company' => $user->companies()->first()->id,
    ]);
    Sanctum::actingAs(
        $user,
        ['*']
    );
});

test('get items', function () {
    $response = getJson('api/v1/items?page=1');

    $response->assertOk();
});

test('item metadata only exposes sales tax types', function () {
    $companyId = User::find(1)->companies()->first()->id;
    $salesTaxType = TaxType::factory()->create([
        'company_id' => $companyId,
        'transaction_type' => TaxType::TRANSACTION_TYPE_SALES,
    ]);
    $purchaseTaxType = TaxType::factory()->create([
        'company_id' => $companyId,
        'transaction_type' => TaxType::TRANSACTION_TYPE_PURCHASES,
    ]);

    $taxTypeIds = collect(
        getJson('api/v1/items?page=1')
            ->assertOk()
            ->json('meta.tax_types')
    )->pluck('id');

    expect($taxTypeIds)
        ->toContain($salesTaxType->id)
        ->not->toContain($purchaseTaxType->id);
});

test('create item', function () {
    $item = Item::factory()->raw([
        'taxes' => [
            Tax::factory()->raw(),
            Tax::factory()->raw(),
        ],
    ]);

    $response = postJson('api/v1/items', $item);

    $this->assertDatabaseHas('items', [
        'name' => $item['name'],
        'description' => $item['description'],
        'price' => $item['price'],
        'company_id' => $item['company_id'],
    ]);

    $this->assertDatabaseHas('taxes', [
        'item_id' => $response->getData()->data->id,
    ]);

    $response->assertOk();
});

test('store validates using a form request', function () {
    $this->assertActionUsesFormRequest(
        ItemsController::class,
        'store',
        ItemsRequest::class
    );
});

test('get item', function () {
    $item = Item::factory()->create();

    $response = getJson("api/v1/items/{$item->id}");

    $response->assertOk();

    $this->assertDatabaseHas('items', [
        'name' => $item['name'],
        'description' => $item['description'],
        'price' => $item['price'],
        'company_id' => $item['company_id'],
    ]);
});

test('update item', function () {
    $item = Item::factory()->create();

    $update_item = Item::factory()->raw([
        'taxes' => [
            Tax::factory()->raw(),
        ],
    ]);

    $response = putJson('api/v1/items/'.$item->id, $update_item);

    $response->assertOk();

    $this->assertDatabaseHas('items', [
        'name' => $update_item['name'],
        'description' => $update_item['description'],
        'price' => $update_item['price'],
        'company_id' => $update_item['company_id'],
    ]);

    $this->assertDatabaseHas('taxes', [
        'item_id' => $item->id,
    ]);
});

test('update validates using a form request', function () {
    $this->assertActionUsesFormRequest(
        ItemsController::class,
        'update',
        ItemsRequest::class
    );
});

test('delete multiple items', function () {
    $items = Item::factory()->count(5)->create();

    $data = [
        'ids' => $items->pluck('id'),
    ];

    postJson('/api/v1/items/delete', $data)->assertOk();

    foreach ($items as $item) {
        $this->assertModelMissing($item);
    }
});

test('search items', function () {
    $filters = [
        'page' => 1,
        'limit' => 15,
        'search' => 'doe',
        'price' => 6,
        'unit' => 'kg',
    ];

    $queryString = http_build_query($filters, '', '&');

    $response = getJson('api/v1/items?'.$queryString);

    $response->assertOk();
});

test('create item with fixed amount tax', function () {
    $item = Item::factory()->raw([
        'taxes' => [
            Tax::factory()->raw([
                'calculation_type' => 'fixed',
                'fixed_amount' => 5000,
            ]),
        ],
    ]);

    $response = postJson('api/v1/items', $item);

    $response->assertOk();

    $this->assertDatabaseHas('items', [
        'name' => $item['name'],
        'description' => $item['description'],
        'price' => $item['price'],
        'company_id' => $item['company_id'],
    ]);

    $this->assertDatabaseHas('taxes', [
        'item_id' => $response->getData()->data->id,
        'calculation_type' => 'fixed',
        'fixed_amount' => 5000,
    ]);
});

test('a zero-rated item gets the company zero-rated tax and no other', function () {
    $companyId = User::find(1)->companies()->first()->id;
    $other = TaxType::factory()->create(['company_id' => $companyId]);

    $response = postJson('api/v1/items', [
        'name' => 'Exported goods',
        'price' => 1000,
        'zero_rated' => true,
        'taxes' => [Tax::factory()->raw(['tax_type_id' => $other->id])],
    ])->assertOk()->assertJsonPath('data.zero_rated', true);

    $zeroRated = TaxType::query()
        ->where('company_id', $companyId)
        ->where('tax_category_code', TaxType::TAX_CATEGORY_CODE_ZERO_RATED)
        ->sole();

    expect((float) $zeroRated->percent)->toBe(0.0);

    $taxes = Item::find($response->json('data.id'))->taxes;
    expect($taxes)->toHaveCount(1)
        ->and($taxes->first()->tax_type_id)->toBe($zeroRated->id);
});

test('zero-rating reuses the tax type and can be switched off again', function () {
    $companyId = User::find(1)->companies()->first()->id;

    $first = postJson('api/v1/items', ['name' => 'A', 'price' => 1, 'zero_rated' => true])->assertOk();
    postJson('api/v1/items', ['name' => 'B', 'price' => 1, 'zero_rated' => true])->assertOk();

    expect(TaxType::query()
        ->where('company_id', $companyId)
        ->where('tax_category_code', TaxType::TAX_CATEGORY_CODE_ZERO_RATED)
        ->count())->toBe(1);

    putJson('api/v1/items/'.$first->json('data.id'), [
        'name' => 'A', 'price' => 1, 'zero_rated' => false, 'taxes' => [],
    ])->assertOk()->assertJsonPath('data.zero_rated', false);

    expect(Item::find($first->json('data.id'))->taxes)->toHaveCount(0);
});
