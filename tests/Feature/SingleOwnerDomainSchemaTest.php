<?php

use App\Models\Category;
use App\Models\GmailConnection;
use App\Models\GmailMessageDiscovery;
use App\Models\LineItem;
use App\Models\MerchantRule;
use App\Models\ParserProfile;
use App\Models\ReceiptBreakdown;
use App\Models\SpendingNotificationFormat;
use App\Models\SpendingNotificationReference;
use App\Models\Transaction;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Schema;

test('the User remains the sole authentication identity while domain tables omit redundant ownership', function () {
    User::factory()->create();

    foreach ([
        (new Transaction)->getTable(),
        (new Category)->getTable(),
        (new MerchantRule)->getTable(),
        (new ParserProfile)->getTable(),
        (new GmailConnection)->getTable(),
        (new SpendingNotificationReference)->getTable(),
        (new ReceiptBreakdown)->getTable(),
    ] as $table) {
        expect(Schema::hasColumn($table, 'user_id'))->toBeFalse();
    }

    expect(Schema::hasColumn('passkeys', 'user_id'))->toBeTrue()
        ->and(Schema::hasColumn('sessions', 'user_id'))->toBeTrue();

    expect(fn () => User::factory()->create())->toThrow(QueryException::class);
});

test('singleton configuration and aggregate children cannot compete for owner scope', function () {
    User::factory()->create();
    GmailConnection::factory()->create();

    $profile = ParserProfile::factory()->create();
    $format = SpendingNotificationFormat::factory()->for($profile, 'profile')->create();
    $connection = GmailConnection::query()->sole();
    $discovery = GmailMessageDiscovery::factory()->for($connection)->create();
    $transaction = Transaction::factory()->create();
    $breakdown = ReceiptBreakdown::factory()->for($transaction)->create();
    $lineItem = LineItem::factory()->for($breakdown)->create();
    $reference = SpendingNotificationReference::factory()
        ->for($transaction)
        ->for($format, 'format')
        ->for($discovery, 'discovery')
        ->create();

    expect($lineItem->receiptBreakdown->transaction->is($transaction))->toBeTrue()
        ->and($reference->discovery->gmailConnection->is($connection))->toBeTrue()
        ->and($reference->format->profile->is($profile))->toBeTrue();

    expect(fn () => GmailConnection::factory()->create())->toThrow(QueryException::class);
});
