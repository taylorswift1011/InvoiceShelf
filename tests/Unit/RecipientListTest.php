<?php

use App\Rules\EmailList;
use App\Support\Mail\RecipientList;
use Illuminate\Support\Facades\Validator;

test('a typed list splits on commas, semicolons and line breaks', function () {
    expect(RecipientList::parse(" a@x.com, b@y.com;c@z.com\nd@w.com\r\n"))
        ->toBe(['a@x.com', 'b@y.com', 'c@z.com', 'd@w.com']);
});

test('duplicates go, whatever their case, and the first spelling is kept', function () {
    expect(RecipientList::parse('Ops@X.com, ops@x.com, a@y.com'))->toBe(['Ops@X.com', 'a@y.com']);
});

test('arrays are cleaned the same way and nothing gives an empty list', function () {
    expect(RecipientList::parse([' a@x.com ', '', 'a@x.com', null]))->toBe(['a@x.com'])
        ->and(RecipientList::parse(null))->toBe([])
        ->and(RecipientList::parse(''))->toBe([])
        ->and(RecipientList::parse(' , ; '))->toBe([]);
});

test('format joins for the dialogs and gives null for an empty list', function () {
    expect(RecipientList::format(['a@x.com', 'b@y.com']))->toBe('a@x.com, b@y.com')
        ->and(RecipientList::format([]))->toBeNull()
        ->and(RecipientList::format(null))->toBeNull();
});

test('merge combines lists without repeating an address', function () {
    expect(RecipientList::merge(['a@x.com'], 'b@y.com, A@x.com', null))->toBe('a@x.com, b@y.com');
});

test('the email list rule accepts lists and names the address that is wrong', function () {
    $passes = fn (mixed $value) => Validator::make(['cc' => $value], ['cc' => ['nullable', new EmailList]])->passes();

    expect($passes('a@x.com, b@münchen.de'))->toBeTrue()
        ->and($passes(['a@x.com', 'b@y.com']))->toBeTrue()
        ->and($passes(null))->toBeTrue()
        ->and($passes('a@x.com, not-an-address'))->toBeFalse()
        ->and($passes(123))->toBeFalse();

    $errors = Validator::make(['cc' => 'a@x.com, broken'], ['cc' => [new EmailList]])->errors();
    expect($errors->first('cc'))->toContain('broken');
});

test('the email list rule caps how many addresses one field may hold', function () {
    $tooMany = implode(',', array_map(fn ($i) => "u{$i}@x.com", range(1, EmailList::MAX_ADDRESSES + 1)));

    expect(Validator::make(['cc' => $tooMany], ['cc' => [new EmailList]])->passes())->toBeFalse();
});
