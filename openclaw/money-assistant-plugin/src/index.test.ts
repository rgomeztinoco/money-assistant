import assert from 'node:assert/strict';
import { createHash, generateKeyPairSync, verify } from 'node:crypto';
import test from 'node:test';
import {
  admittedOwnerMessage,
  authorizationHeaders,
  isBoundOwnerInteraction,
  OwnerMessageAdmissions,
} from './index.js';

test('authorization signs timestamp nonce method path and exact body digest', () => {
  const { privateKey, publicKey } = generateKeyPairSync('ed25519');
  const privateKeyDer = privateKey.export({ format: 'der', type: 'pkcs8' });
  const encodedSeed = privateKeyDer.subarray(-32).toString('base64');
  const body = '{"schema_version":1}';
  const timestamp = '1784912400';
  const nonce = '01983d79-a780-72f0-bb34-9b4f3f0cf372';
  const headers = authorizationHeaders(
    body,
    'openclaw-service-2026-07',
    encodedSeed,
    timestamp,
    nonce,
  );
  const signedMessage = [
    timestamp,
    nonce,
    'POST',
    '/api/openclaw/v1/transport',
    createHash('sha256').update(body).digest('hex'),
  ].join('\n');

  assert.equal(
    verify(
      null,
      Buffer.from(signedMessage),
      publicKey,
      Buffer.from(headers['X-Money-Assistant-Signature'], 'base64'),
    ),
    true,
  );
});

test('only the admitted owner Telegram interaction is bound', () => {
  const config = {
    agentId: 'money-assistant',
    accountId: 'money-assistant-owner',
    conversationId: 'telegram-owner-123',
    ownerSenderId: 'telegram-owner-123',
  };
  const context = {
    agentId: 'money-assistant',
    messageChannel: 'telegram',
    agentAccountId: 'money-assistant-owner',
    requesterSenderId: 'telegram-owner-123',
    senderIsOwner: true,
    sessionId: 'session-456',
    deliveryContext: {
      channel: 'telegram',
      accountId: 'money-assistant-owner',
      to: 'telegram-owner-123',
    },
  };

  assert.equal(isBoundOwnerInteraction(context, config), true);
  assert.equal(isBoundOwnerInteraction({ ...context, senderIsOwner: false }, config), false);
  assert.equal(isBoundOwnerInteraction({ ...context, sessionId: undefined }, config), false);
  assert.equal(isBoundOwnerInteraction({ ...context, requesterSenderId: 'other' }, config), false);
});

test('admission keeps the immutable inbound owner message identity and timestamp', () => {
  const config = {
    agentId: 'money-assistant',
    accountId: 'money-assistant-owner',
    conversationId: 'telegram-owner-123',
    ownerSenderId: 'telegram-owner-123',
  };
  const context = {
    channelId: 'telegram',
    accountId: 'money-assistant-owner',
    conversationId: 'telegram-owner-123',
    senderId: 'telegram-owner-123',
    sessionKey: 'agent:money-assistant:telegram-owner-123',
  };
  const event = {
    messageId: 'telegram-message-456',
    timestamp: 1_784_912_400_000,
  };

  assert.deepEqual(admittedOwnerMessage(event, context, config), {
    sessionKey: 'agent:money-assistant:telegram-owner-123',
    messageId: 'telegram-message-456',
    occurredAtSeconds: 1_784_912_400,
  });
  assert.equal(
    admittedOwnerMessage(event, { ...context, senderId: 'other' }, config),
    null,
  );
  assert.equal(
    admittedOwnerMessage({ ...event, messageId: undefined }, context, config),
    null,
  );
});

test('admissions are session-bound replaced and fresh for no more than thirty minutes', () => {
  const admissions = new OwnerMessageAdmissions();
  const config = {
    agentId: 'money-assistant',
    accountId: 'money-assistant-owner',
    conversationId: 'telegram-owner-123',
    ownerSenderId: 'telegram-owner-123',
  };
  const context = {
    channelId: 'telegram',
    accountId: 'money-assistant-owner',
    conversationId: 'telegram-owner-123',
    senderId: 'telegram-owner-123',
    sessionKey: 'owner-session',
  };

  admissions.admit({ messageId: 'first', timestamp: 1000 }, context, config);
  assert.equal(admissions.freshForSession('owner-session', 1001)?.messageId, 'first');
  assert.equal(admissions.freshForSession('other-session', 1001), null);
  assert.equal(admissions.freshForSession('owner-session', 2801), null);
  assert.equal(admissions.freshForSession('owner-session', 999), null);

  admissions.admit({ messageId: 'second', timestamp: 2000 }, context, config);
  assert.equal(admissions.freshForSession('owner-session', 2001)?.messageId, 'second');

  admissions.admit({ messageId: undefined, timestamp: 2002 }, context, config);
  assert.equal(admissions.freshForSession('owner-session', 2003), null);
});
