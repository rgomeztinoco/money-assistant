import assert from 'node:assert/strict';
import { createHash, generateKeyPairSync, verify } from 'node:crypto';
import test from 'node:test';
import { getToolPluginMetadata } from 'openclaw/plugin-sdk/tool-plugin';
import plugin, {
  admittedReminderEvent,
  capabilityRequestBody,
  consumeAlreadyDeliveredReminder,
  isBoundReminderChannelDelivery,
  isBoundReminderEventInteraction,
  ReminderEventAdmissions,
  recordReminderChannelDelivery,
  reminderEventCapabilityRequestBody,
  shouldSuppressReminderDelivery,
} from './index.js';
import {
  admittedOwnerMessage,
  authorizationHeaders,
  isBoundOwnerInteraction,
  OwnerMessageAdmissions,
} from './index.js';

test('the plugin exposes only bounded Transaction Category and Reminder tools', () => {
  const metadata = getToolPluginMetadata(plugin);

  assert.deepEqual(metadata?.tools.map((tool) => tool.name), [
    'money_assistant_transaction_read',
    'money_assistant_transaction_prepare',
    'money_assistant_transaction_confirm',
    'money_assistant_category_read',
    'money_assistant_category_prepare',
    'money_assistant_category_confirm',
    'money_assistant_reminder_read',
    'money_assistant_reminder_respond',
  ]);

  for (const tool of metadata?.tools ?? []) {
    const schema = tool.parameters as {
      additionalProperties?: boolean;
      anyOf?: Array<{ additionalProperties?: boolean }>;
    };

    if (schema.anyOf) {
      assert.equal(
        schema.anyOf.every((variant) => variant.additionalProperties === false),
        true,
      );
    } else {
      assert.equal(schema.additionalProperties, false);
    }
  }
});

test('the mapped hook binds Reminder events to one fixed unattended session and route', () => {
  const config = {
    agentId: 'money-assistant',
    accountId: 'money-assistant-owner',
    conversationId: 'telegram-owner-123',
    ownerSenderId: 'telegram-owner-123',
  };
  const context = {
    agentId: 'money-assistant',
    sessionKey: 'hook:money-assistant:reminders',
    deliveryContext: {
      channel: 'telegram',
      accountId: 'money-assistant-owner',
      to: 'telegram-owner-123',
    },
  };

  assert.deepEqual(admittedReminderEvent(
    'Fetch Reminder event 01983d79-a780-72f0-bb34-9b4f3f0cf390 that occurred at 2026-07-26T15:05:00Z with money_assistant_reminder_read.',
    {
      agentId: 'money-assistant',
      sessionKey: 'hook:money-assistant:reminders',
    },
    config,
    1_785_078_301,
  ), {
    eventId: '01983d79-a780-72f0-bb34-9b4f3f0cf390',
    occurredAtSeconds: 1_785_078_301,
  });
  assert.equal(admittedReminderEvent(
    'Fetch Reminder event 01983d79-a780-72f0-bb34-9b4f3f0cf390 that occurred at 2026-07-26T15:05:00Z with money_assistant_reminder_read.',
    {
      agentId: 'money-assistant',
      sessionKey: 'caller-selected',
    },
    config,
    1_785_078_301,
  ), null);

  assert.equal(isBoundReminderEventInteraction(context, config), true);
  assert.equal(
    isBoundReminderEventInteraction({ ...context, sessionKey: 'caller-selected' }, config),
    false,
  );
  assert.equal(
    isBoundReminderEventInteraction({
      ...context,
      deliveryContext: { ...context.deliveryContext, to: 'other' },
    }, config),
    false,
  );

  assert.equal(isBoundReminderChannelDelivery(
    {
      to: 'telegram-owner-123',
      success: true,
      sessionKey: 'hook:money-assistant:reminders',
    },
    {
      channelId: 'telegram',
      accountId: 'money-assistant-owner',
      conversationId: 'telegram-owner-123',
    },
    config,
  ), true);
  assert.equal(isBoundReminderChannelDelivery(
    {
      to: 'telegram-owner-123',
      success: false,
      sessionKey: 'hook:money-assistant:reminders',
    },
    {
      channelId: 'telegram',
      accountId: 'money-assistant-owner',
      conversationId: 'telegram-owner-123',
    },
    config,
  ), false);

  assert.deepEqual(
    JSON.parse(reminderEventCapabilityRequestBody(
      'reminder.read',
      { event_id: '01983d79-a780-72f0-bb34-9b4f3f0cf390' },
      config,
      '01983d79-a780-72f0-bb34-9b4f3f0cf390',
      1_785_078_300,
    )),
    {
      schema_version: 1,
      capability: 'reminder.read',
      interaction: {
        kind: 'money_assistant_event',
        agent_id: 'money-assistant',
        account_id: 'money-assistant-owner',
        conversation_id: 'telegram-owner-123',
        owner_sender_id: 'telegram-owner-123',
        message_id: '01983d79-a780-72f0-bb34-9b4f3f0cf390',
        occurred_at: '2026-07-26T15:05:00Z',
      },
      input: { event_id: '01983d79-a780-72f0-bb34-9b4f3f0cf390' },
    },
  );
});

test('a successful mapped-hook send consumes only its session Reminder event admission', () => {
  const admissions = new ReminderEventAdmissions();
  const eventId = '01983d79-a780-72f0-bb34-9b4f3f0cf390';
  const nextEventId = '01983d79-a780-72f0-bb34-9b4f3f0cf391';

  admissions.admit('hook:money-assistant:reminders', eventId, 1000);
  admissions.admit('hook:money-assistant:reminders', nextEventId, 1001);

  assert.equal(admissions.freshForSession('other', 1001), null);
  assert.equal(
    admissions.freshEventForSession(
      'hook:money-assistant:reminders',
      '01983d79-a780-72f0-bb34-9b4f3f0cf399',
      1001,
    ),
    null,
  );
  assert.equal(
    admissions.takeFreshForSession('hook:money-assistant:reminders', 1001)?.eventId,
    eventId,
  );
  assert.equal(
    admissions.takeFreshForSession('hook:money-assistant:reminders', 1002)?.eventId,
    nextEventId,
  );

  admissions.admit('hook:money-assistant:reminders', eventId, 1003);
  admissions.markAlreadyDelivered('hook:money-assistant:reminders', eventId);

  assert.equal(shouldSuppressReminderDelivery(
    { to: 'telegram-owner-123' },
    {
      channelId: 'telegram',
      accountId: 'money-assistant-owner',
      conversationId: 'telegram-owner-123',
      sessionKey: 'hook:money-assistant:reminders',
    },
    {
      agentId: 'money-assistant',
      accountId: 'money-assistant-owner',
      conversationId: 'telegram-owner-123',
      ownerSenderId: 'telegram-owner-123',
    },
    admissions,
    1004,
  ), true);
  assert.equal(
    admissions.freshForSession('hook:money-assistant:reminders', 1004),
    null,
  );

  admissions.admit('hook:money-assistant:reminders', nextEventId, 1005);
  admissions.markAlreadyDelivered('hook:money-assistant:reminders', nextEventId);
  assert.equal(consumeAlreadyDeliveredReminder(
    'hook:money-assistant:reminders',
    admissions,
    1006,
  ), true);
  assert.equal(
    admissions.freshForSession('hook:money-assistant:reminders', 1006),
    null,
  );
});

test('the channel callback records delivery for the exact admitted event', async () => {
  const { privateKey } = generateKeyPairSync('ed25519');
  const privateKeyDer = privateKey.export({ format: 'der', type: 'pkcs8' });
  const originalFetch = globalThis.fetch;
  let requestBody: Record<string, unknown> | null = null;
  let requestCount = 0;

  globalThis.fetch = async (_input, init) => {
    requestCount += 1;
    requestBody = JSON.parse(String(init?.body)) as Record<string, unknown>;

    if (requestCount === 1) {
      return new Response(null, { status: 503 });
    }

    return new Response(JSON.stringify({ schema_version: 1 }), {
      status: 200,
      headers: { 'Content-Type': 'application/json' },
    });
  };

  try {
    await recordReminderChannelDelivery(
      {
        eventId: '01983d79-a780-72f0-bb34-9b4f3f0cf390',
        occurredAtSeconds: 1_785_078_300,
      },
      {
        keyId: 'openclaw-service-2026-07',
        privateKey: privateKeyDer.subarray(-32).toString('base64'),
        agentId: 'money-assistant',
        accountId: 'money-assistant-owner',
        conversationId: 'telegram-owner-123',
        ownerSenderId: 'telegram-owner-123',
      },
    );
  } finally {
    globalThis.fetch = originalFetch;
  }

  assert.deepEqual(requestBody, {
    schema_version: 1,
    capability: 'reminder.delivery.record',
    interaction: {
      kind: 'money_assistant_event',
      agent_id: 'money-assistant',
      account_id: 'money-assistant-owner',
      conversation_id: 'telegram-owner-123',
      owner_sender_id: 'telegram-owner-123',
      message_id: '01983d79-a780-72f0-bb34-9b4f3f0cf390',
      occurred_at: '2026-07-26T15:05:00Z',
    },
    input: { event_id: '01983d79-a780-72f0-bb34-9b4f3f0cf390' },
  });
  assert.equal(requestCount, 2);
});

test('the channel callback does not retry deterministic rejection', async () => {
  const { privateKey } = generateKeyPairSync('ed25519');
  const privateKeyDer = privateKey.export({ format: 'der', type: 'pkcs8' });
  const originalFetch = globalThis.fetch;
  let requestCount = 0;

  globalThis.fetch = async () => {
    requestCount += 1;

    return new Response(null, { status: 422 });
  };

  try {
    await assert.rejects(() => recordReminderChannelDelivery(
      {
        eventId: '01983d79-a780-72f0-bb34-9b4f3f0cf390',
        occurredAtSeconds: 1_785_078_300,
      },
      {
        keyId: 'openclaw-service-2026-07',
        privateKey: privateKeyDer.subarray(-32).toString('base64'),
        agentId: 'money-assistant',
        accountId: 'money-assistant-owner',
        conversationId: 'telegram-owner-123',
        ownerSenderId: 'telegram-owner-123',
      },
    ));
  } finally {
    globalThis.fetch = originalFetch;
  }

  assert.equal(requestCount, 1);
});

test('prepare and confirm serialize exact state-bound capability requests', () => {
  const toolContext = {
    agentId: 'money-assistant',
    agentAccountId: 'money-assistant-owner',
    requesterSenderId: 'telegram-owner-123',
    deliveryContext: { to: 'telegram-owner-123' },
  };
  const admission = {
    sessionKey: 'owner-session',
    messageId: 'telegram-message-prepare',
    occurredAtSeconds: 1_784_912_400,
  };
  const preparationInput = {
    idempotency_key: '01983d79-a780-72f0-bb34-9b4f3f0cf372',
    occurred_on: '2026-07-24',
    amount_minor: 12345,
    currency: 'USD',
    kind: 'purchase',
    merchant_description: 'Neighborhood market',
  };

  assert.deepEqual(
    JSON.parse(capabilityRequestBody(
      'transaction.manual.prepare',
      preparationInput,
      toolContext,
      admission,
    )),
    {
      schema_version: 1,
      capability: 'transaction.manual.prepare',
      interaction: {
        kind: 'owner_message',
        agent_id: 'money-assistant',
        account_id: 'money-assistant-owner',
        conversation_id: 'telegram-owner-123',
        owner_sender_id: 'telegram-owner-123',
        message_id: 'telegram-message-prepare',
        occurred_at: '2026-07-24T17:00:00Z',
      },
      input: preparationInput,
    },
  );

  const confirmationInput = {
    idempotency_key: '01983d79-a780-72f0-bb34-9b4f3f0cf374',
    pending_operation_id: '01983d79-a780-72f0-bb34-9b4f3f0cf373',
    pending_operation_revision: 1,
    payload_digest: 'a'.repeat(64),
  };

  assert.equal(
    JSON.parse(capabilityRequestBody(
      'transaction.manual.confirm',
      confirmationInput,
      toolContext,
      { ...admission, messageId: 'telegram-message-approve' },
    )).interaction.message_id,
    'telegram-message-approve',
  );
});

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
