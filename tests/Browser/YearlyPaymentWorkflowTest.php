<?php

use App\Models\Transaction;
use App\Models\User;
use App\Models\YearlyPayment;
use App\Models\YearlyPaymentSetting;
use Carbon\CarbonImmutable;
use Database\Seeders\YearlyPaymentSeeder;

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
        ->select('#payment-due-month', '10')
        ->select('#payment-due-day', '08')
        ->press('Add payment')
        ->assertSee('Yearly payment added.')
        ->assertNotPresent('[role="dialog"]')
        ->assertSee('Insurance')
        ->click('[aria-label="Actions for Insurance"]')
        ->click('[role="menuitem"]:has-text("Edit")')
        ->fill('#payment-name', 'Medical estimate')
        ->fill('#payment-amount', '1200.00')
        ->press('Save payment')
        ->assertSee('Yearly payment updated.')
        ->assertNotPresent('[role="dialog"]')
        ->click('[aria-label="Actions for Medical estimate"]')
        ->click('[aria-label="Pause Medical estimate"]')
        ->assertSee('Yearly payment paused.')
        ->assertSee('No active commitments')
        ->click('[role="tab"]:has-text("Paused")')
        ->click('[aria-label="Actions for Medical estimate"]')
        ->click('[aria-label="Resume Medical estimate"]')
        ->assertSee('Yearly payment resumed.')
        ->click('[role="tab"]:has-text("Active")')
        ->click('[aria-label="Edit planning rate"]')
        ->fill('#planning-rate', '3.7500')
        ->press('Save planning rate')
        ->assertSee('Planning exchange rate updated.')
        ->assertNotPresent('[role="dialog"]')
        ->assertSee('1 USD = S/ 3.7500')
        ->assertNoJavaScriptErrors()
        ->screenshot(filename: 'yearly-payments-desktop');

    $page->click('Home')
        ->assertSee('Yearly payments coming up')
        ->assertSee('Medical estimate')
        ->assertNoJavaScriptErrors()
        ->screenshot(filename: 'yearly-payments-home')
        ->click('Manage yearly payments')
        ->assertSeeIn('[role="tab"][aria-selected="true"]:has-text("Active")', '1');

    $this->assertDatabaseHas('yearly_payments', ['user_id' => $owner->id, 'name' => 'Medical estimate', 'amount_minor' => 120000, 'cushion_minor' => 12000, 'is_active' => true]);
    $this->assertDatabaseHas('yearly_payment_settings', ['user_id' => $owner->id, 'pen_per_usd' => '3.7500']);
});

test('the mobile planner opens the editor and keeps exact values when validation fails', function () {
    $owner = User::factory()->create();
    YearlyPayment::factory()->for($owner, 'owner')->create(['name' => 'Annual insurance', 'amount_minor' => 9007199254740993, 'cushion_minor' => 9007199254740993]);
    YearlyPaymentSetting::factory()->for($owner, 'owner')->create(['pen_per_usd' => '3.7500']);
    $this->actingAs($owner);

    $page = visit('/yearly-payments')->on()->mobile();
    $page->assertSee('S/ 180,143,985,094,819.86')
        ->assertScript(<<<'JS'
            (() => {
                const target = document.querySelector('[data-test="yearly-payment-target"]');
                return target.scrollWidth <= target.clientWidth + 1
                    && target.getBoundingClientRect().right <= target.nextElementSibling.getBoundingClientRect().left + 1;
            })()
            JS)
        ->screenshot(filename: 'yearly-payments-mobile-layout')
        ->click('[aria-label="Edit Annual insurance"]')
        ->assertValue('#payment-amount', '90071992547409.93')
        ->fill('#payment-amount', '1')
        ->select('#payment-due-month', '10')
        ->select('#payment-due-day', '09')
        ->press('Save payment')
        ->assertSee('Yearly payment updated.')
        ->assertNotPresent('[role="dialog"]')
        ->click('[aria-label="Edit planning rate"]')
        ->fill('#planning-rate', 'bad rate')
        ->press('Save planning rate')
        ->assertSee('Enter a positive decimal planning rate in PEN per USD.')
        ->assertValue('#planning-rate', 'bad rate')
        ->assertNoJavaScriptErrors()
        ->screenshot(filename: 'yearly-payments-mobile');
});

test('Spending creates or links yearly payments without leaving the filtered workspace', function (string $workspace) {
    $this->travelTo(CarbonImmutable::parse('2026-10-08 12:00:00', 'America/Lima'));
    $owner = User::factory()->create();
    $first = Transaction::factory()->for($owner, 'owner')->spending()->usd()->create(['description' => 'Insurance first', 'amount_minor' => 12345, 'occurred_on' => '2026-10-01']);
    $second = Transaction::factory()->for($owner, 'owner')->spending()->usd()->create(['description' => 'Insurance renewal', 'amount_minor' => 13000, 'occurred_on' => '2026-10-02']);
    $this->actingAs($owner);
    $url = $workspace === 'transactions' ? '/transactions?search=Insurance' : '/breakdown?period=month&anchor=2026-10-08&currency=USD';
    $page = visit($url);
    if ($workspace === 'breakdown') {
        $page->fill('#transaction-search', 'Insurance');
    }

    $page->click('[aria-label="Actions for Insurance first"]')
        ->click('Mark as yearly payment')
        ->assertValue('#payment-name', 'Insurance first')
        ->assertValue('#payment-amount', '123.45')
        ->assertValue('#payment-currency', 'USD')
        ->assertValue('#payment-due-month', '10')
        ->assertValue('#payment-due-day', '01')
        ->fill('#payment-name', 'Annual insurance')
        ->fill('#payment-amount', '150.00')
        ->select('#payment-due-month', '11')
        ->select('#payment-due-day', '05')
        ->press('Add payment')
        ->assertSee('Yearly payment linked.')
        ->assertPathIs('/'.$workspace)
        ->assertValue('#transaction-search', 'Insurance')
        ->click('[aria-label="Actions for Insurance renewal"]')
        ->click('Mark as yearly payment')
        ->assertValue('#yearly-payment-choice', 'new');

    $payment = YearlyPayment::query()->where('user_id', $owner->id)->firstOrFail();
    $page->select('#yearly-payment-choice', (string) $payment->id)
        ->press('Link payment')
        ->assertSee('Yearly payment linked.')
        ->assertValue('#transaction-search', 'Insurance')
        ->click('[aria-label="Actions for Insurance renewal"]')
        ->click('Mark as yearly payment')
        ->assertSee('Edit yearly payment')
        ->assertValue('#payment-name', 'Annual insurance')
        ->assertValue('#payment-amount', '150.00')
        ->assertNotPresent('#yearly-payment-choice')
        ->fill('#payment-name', 'Updated annual insurance')
        ->press('Save payment')
        ->assertSee('Yearly payment updated.')
        ->assertPathIs('/'.$workspace)
        ->assertValue('#transaction-search', 'Insurance')
        ->assertNoJavaScriptErrors();

    expect($first->fresh()->yearly_payment_id)->toBe($payment->id);
    expect($second->fresh()->yearly_payment_id)->toBe($payment->id);
    $this->assertDatabaseCount('yearly_payments', 1);
    $this->assertDatabaseHas('yearly_payments', ['id' => $payment->id, 'amount_minor' => 15000, 'due_month_day' => '11-05']);
})->with(['transactions', 'breakdown']);

test('desktop planner keeps its controls visible while the commitment table scrolls', function () {
    $owner = User::factory()->create(['email' => 'test@example.com']);
    $this->seed(YearlyPaymentSeeder::class);
    YearlyPayment::factory()->count(30)->for($owner, 'owner')->create();
    YearlyPayment::factory()->for($owner, 'owner')->paused()->create(['name' => 'Paused insurance']);
    $this->actingAs($owner);

    visit('/yearly-payments')->resize(1280, 800)
        ->assertSeeIn('[role="tab"][aria-selected="true"]:has-text("Active")', '40')
        ->assertDontSee('Paused insurance')
        ->screenshot(filename: 'yearly-payments-full-desktop')
        ->assertScript(<<<'JS'
            (() => {
                const scroller = document.querySelector('[data-test="yearly-payments-scroll"]');
                const add = [...document.querySelectorAll('button')].find(button => button.textContent.includes('Add yearly payment'));
                const before = add.getBoundingClientRect().top;
                const tabs = [...document.querySelectorAll('[role="tablist"]')].find(tabs => tabs.textContent.includes('Active'));
                scroller.scrollTop = 1000;
                return scroller.scrollTop > 0 && Math.abs(add.getBoundingClientRect().top - before) < 1
                    && tabs.getBoundingClientRect().bottom <= scroller.getBoundingClientRect().top
                    && document.documentElement.scrollHeight <= window.innerHeight + 2;
            })()
            JS)
        ->click('[role="tab"]:has-text("Paused")')
        ->assertSee('Paused insurance')
        ->assertNotPresent('[aria-label^="Pause "]')
        ->assertNoJavaScriptErrors();
});

test('recurring due dates offer valid days support leap day and can be cleared', function () {
    $owner = User::factory()->create();
    $this->actingAs($owner);

    $page = visit('/yearly-payments')->on()->mobile()->click('Add yearly payment');
    $page->fill('#payment-name', 'Annual renewal')
        ->fill('#payment-amount', '120.00')
        ->select('#payment-due-month', '01')
        ->select('#payment-due-day', '31')
        ->select('#payment-due-month', '04')
        ->assertValue('#payment-due-day', '')
        ->assertNotPresent('#payment-due-day option[value="31"]')
        ->press('Add payment')
        ->assertPresent('[role="dialog"]')
        ->assertScript('document.querySelector("#payment-due-day").validity.valueMissing');

    $this->assertDatabaseCount('yearly_payments', 0);

    $page->select('#payment-due-month', '02')
        ->assertNotPresent('#payment-due-day option[value="30"]')
        ->select('#payment-due-day', '29')
        ->assertNoJavaScriptErrors()
        ->screenshot(filename: 'yearly-payments-due-date-mobile')
        ->press('Add payment')
        ->assertSee('Yearly payment added.')
        ->assertNotPresent('[role="dialog"]');

    $this->assertDatabaseHas('yearly_payments', ['user_id' => $owner->id, 'name' => 'Annual renewal', 'due_month_day' => '02-29']);

    $page->click('[aria-label="Edit Annual renewal"]')
        ->assertValue('#payment-due-month', '02')
        ->assertValue('#payment-due-day', '29')
        ->select('#payment-due-month', '')
        ->assertScript('document.querySelector("#payment-due-day").disabled')
        ->press('Save payment')
        ->assertSee('Yearly payment updated.')
        ->assertNotPresent('[role="dialog"]')
        ->assertNoJavaScriptErrors();

    $this->assertDatabaseHas('yearly_payments', ['user_id' => $owner->id, 'name' => 'Annual renewal', 'due_month_day' => null]);
});
