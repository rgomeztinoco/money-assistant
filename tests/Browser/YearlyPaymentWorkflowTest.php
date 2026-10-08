<?php

use App\Models\User;
use App\Models\YearlyPayment;
use App\Models\YearlyPaymentSetting;
use Carbon\CarbonImmutable;

test('yearly payment forms add edit pause resume and update the planning assumption', function () {
    $this->travelTo(CarbonImmutable::parse('2026-10-08 12:00:00', 'America/Lima'));
    $owner = User::factory()->create();
    $this->actingAs($owner);

    $page = visit('/yearly-payments');
    $page->assertSee('No active commitments')
        ->click('Add yearly payment')
        ->fill('#payment-name', 'Insurance')
        ->fill('#payment-amount', '2400.00')
        ->fill('#payment-cushion', '120.00')
        ->fill('#payment-due', '2026-10-08')
        ->press('Add payment')
        ->assertSee('Yearly payment added.')
        ->assertSee('Insurance')
        ->click('[aria-label="Edit Insurance"]')
        ->fill('#payment-name', 'Medical estimate')
        ->fill('#payment-amount', '1200.00')
        ->press('Save payment')
        ->assertSee('Yearly payment updated.')
        ->click('[aria-label="Pause Medical estimate"]')
        ->assertSee('Yearly payment paused.')
        ->assertSee('No active commitments')
        ->click('[aria-label="Resume Medical estimate"]')
        ->assertSee('Yearly payment resumed.')
        ->fill('#planning-rate', '3.7500')
        ->press('Save planning rate')
        ->assertSee('Planning exchange rate updated.')
        ->assertSee('Manual assumption: 3.7500 PEN per USD')
        ->assertNoJavaScriptErrors()
        ->screenshot(filename: 'yearly-payments-desktop');

    $page->click('Home')
        ->assertSee('Yearly payments coming up')
        ->assertSee('Medical estimate')
        ->assertNoJavaScriptErrors()
        ->screenshot(filename: 'yearly-payments-home')
        ->click('Manage yearly payments')
        ->assertSee('Active commitments');

    $this->assertDatabaseHas('yearly_payments', ['user_id' => $owner->id, 'name' => 'Medical estimate', 'amount_minor' => 120000, 'cushion_minor' => 12000, 'is_active' => true]);
    $this->assertDatabaseHas('yearly_payment_settings', ['user_id' => $owner->id, 'pen_per_usd' => '3.7500']);
});

test('the mobile planner opens the editor and keeps exact values when validation fails', function () {
    $owner = User::factory()->create();
    YearlyPayment::factory()->for($owner, 'owner')->create(['name' => 'Annual insurance', 'amount_minor' => 9007199254740993]);
    YearlyPaymentSetting::factory()->for($owner, 'owner')->create(['pen_per_usd' => '3.7500']);
    $this->actingAs($owner);

    $page = visit('/yearly-payments')->on()->mobile();
    $page->assertSee('S/ 90,071,992,547,409.93')
        ->click('[aria-label="Edit Annual insurance"]')
        ->assertValue('#payment-amount', '90071992547409.93')
        ->fill('#payment-amount', '1')
        ->fill('#payment-due', '2026-10-09')
        ->press('Save payment')
        ->assertSee('Yearly payment updated.')
        ->fill('#planning-rate', 'bad rate')
        ->press('Save planning rate')
        ->assertSee('Enter a positive decimal planning rate in PEN per USD.')
        ->assertValue('#planning-rate', 'bad rate')
        ->assertNoJavaScriptErrors()
        ->screenshot(filename: 'yearly-payments-mobile');
});
