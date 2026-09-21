<?php

use App\Models\User;
use Inertia\Testing\AssertableInertia as Assert;
use Laravel\Sanctum\PersonalAccessToken;

beforeEach(fn () => $this->withoutVite());

test('agent access requires sign-in and recent confirmation before managing credentials', function () {
    $this->get('/settings/agent-access')->assertRedirect(route('login'));
    $owner = User::factory()->create();
    $this->actingAs($owner)->get('/settings/agent-access')->assertRedirect(route('password.confirm'));
    $this->withSession(['auth.password_confirmed_at' => now()->subMinutes(31)->timestamp])
        ->postJson('/settings/agent-access/tokens', ['name' => 'Codex'])->assertStatus(423);
    $this->assertDatabaseCount('personal_access_tokens', 0);
    $this->withSession(['auth.password_confirmed_at' => now()->subMinutes(29)->timestamp])
        ->get('/settings/agent-access')->assertOk()->assertInertia(fn (Assert $page) => $page
        ->component('settings/agent-access')->where('tokens', []));
});

test('the owner receives a hashed read token once without putting its secret in the session', function () {
    $owner = User::factory()->create();
    $response = $this->actingAs($owner)
        ->withSession(['auth.password_confirmed_at' => now()->timestamp])
        ->postJson('/settings/agent-access/tokens', ['name' => 'Codex', 'abilities' => ['*']])
        ->assertCreated()->assertJsonStructure(['token' => ['id', 'name'], 'plain_text_token'])
        ->assertHeader('Cache-Control', 'no-store, private');

    $secret = $response->json('plain_text_token');
    $token = PersonalAccessToken::findToken($secret);
    expect($token)->not->toBeNull()
        ->and($token->abilities)->toBe(['financial-data:read'])
        ->and($token->expires_at)->toBeNull()
        ->and($token->token)->not->toBe($secret)
        ->and(json_encode(session()->all()))->not->toContain($secret);

    $this->get('/settings/agent-access')->assertInertia(fn (Assert $page) => $page
        ->where('tokens.0.name', 'Codex')->where('tokens.0.last_used_at', null)
        ->missing('plain_text_token')->missing('tokens.0.token')->missing('tokens.0.abilities'))
        ->assertDontSee($secret);
});

test('one confirmation permits multiple tokens, rotation and revocation until it expires', function () {
    $owner = User::factory()->create();
    $this->actingAs($owner)->withSession(['auth.password_confirmed_at' => now()->timestamp]);
    $first = $this->postJson('/settings/agent-access/tokens', ['name' => 'Codex'])->assertCreated()->json();
    $this->postJson('/settings/agent-access/tokens', ['name' => 'OpenClaw'])->assertCreated();
    $id = $first['token']['id'];
    $replacement = $this->postJson("/settings/agent-access/tokens/$id/rotate")->assertOk()
        ->assertJsonPath('token.id', $id)->assertJsonPath('token.name', 'Codex')->json('plain_text_token');
    expect(PersonalAccessToken::findToken($first['plain_text_token']))->toBeNull()
        ->and(PersonalAccessToken::findToken($replacement))->not->toBeNull();
    $this->assertDatabaseCount('personal_access_tokens', 2);
    $this->withSession(['auth.password_confirmed_at' => now()->subMinutes(31)->timestamp])
        ->postJson("/settings/agent-access/tokens/$id/rotate")->assertStatus(423);
    $this->deleteJson("/settings/agent-access/tokens/$id")->assertStatus(423);
    expect(PersonalAccessToken::findToken($replacement))->not->toBeNull();
    $this->withSession(['auth.password_confirmed_at' => now()->timestamp])
        ->deleteJson("/settings/agent-access/tokens/$id")->assertNoContent();
    expect(PersonalAccessToken::findToken($replacement))->toBeNull();
    $this->postJson('/settings/agent-access/tokens', ['name' => 'Codex'])->assertCreated();
});

test('token names are required and unique among the owner active tokens', function (mixed $name) {
    $owner = User::factory()->create();
    $owner->createToken('Codex', ['financial-data:read']);
    $this->actingAs($owner)->withSession(['auth.password_confirmed_at' => now()->timestamp])
        ->postJson('/settings/agent-access/tokens', ['name' => $name])->assertUnprocessable()->assertJsonValidationErrors('name');
    $this->assertDatabaseCount('personal_access_tokens', 1);
})->with([null, '', str_repeat('x', 101), 'Codex']);

test('agent access never lists rotates or revokes another owners token', function () {
    $other = User::factory()->create();
    $token = $other->createToken('Codex', ['financial-data:read']);
    $id = $token->accessToken->id;
    $owner = User::factory()->create();
    $this->actingAs($owner)->withSession(['auth.password_confirmed_at' => now()->timestamp])
        ->get('/settings/agent-access')->assertInertia(fn (Assert $page) => $page->where('tokens', []));
    $this->postJson("/settings/agent-access/tokens/$id/rotate")->assertNotFound();
    $this->deleteJson("/settings/agent-access/tokens/$id")->assertNotFound();
    $this->postJson('/settings/agent-access/tokens', ['name' => 'Codex'])->assertCreated();
    expect(PersonalAccessToken::findToken($token->plainTextToken))->not->toBeNull();
});
