import assert from 'node:assert/strict';
import { createHash, generateKeyPairSync, verify } from 'node:crypto';
import { mkdtemp, rm, truncate, writeFile } from 'node:fs/promises';
import { tmpdir } from 'node:os';
import { join } from 'node:path';
import test from 'node:test';
import { getToolPluginMetadata } from 'openclaw/plugin-sdk/tool-plugin';
import plugin, {
  admittedReminderEvent,
  capabilityRequestBody,
  consumeAlreadyDeliveredReminder,
  isBoundReceiptChannelDelivery,
  isBoundReminderChannelDelivery,
  isBoundReminderEventInteraction,
  admittedReceiptPhoto,
  isApprovedReceiptModel,
  inspectReceiptImage,
  isReceiptBreakdownMutationInput,
  RECEIPT_PRIVACY_DISCLOSURE,
  receiptEffectiveAuthStateReady,
  receiptAdmissionBlockCategory,
  receiptProcessingReady,
  receiptRuntimePolicyReady,
  receiptProposalCapabilityRequestBody,
  ReceiptPhotoAdmissions,
  shouldBlockReceiptMessageWrite,
  deleteReceiptSourceMessage,
  warnReceiptSourceDeletionFailed,
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

test('the plugin exposes only bounded Transaction Category Receipt Proposal and Reminder tools', () => {
  const metadata = getToolPluginMetadata(plugin);

  assert.deepEqual(
    metadata?.tools.map((tool) => tool.name),
    [
      'money_assistant_transaction_read',
      'money_assistant_transaction_prepare',
      'money_assistant_transaction_confirm',
      'money_assistant_category_read',
      'money_assistant_category_prepare',
      'money_assistant_category_confirm',
      'money_assistant_receipt_proposal_submit',
      'money_assistant_receipt_breakdown_prepare',
      'money_assistant_receipt_breakdown_confirm',
      'money_assistant_reminder_read',
      'money_assistant_reminder_respond',
    ],
  );

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

test('receipt processing fails closed until every approved privacy gate is confirmed', () => {
  const policy = {
    receiptProcessingEnabled: true,
    receiptDisclosureDelivered: true,
    receiptDisclosureAccepted: true,
    openAiModelImprovementDisabled: true,
    codexFullEnvironmentTrainingDisabled: true,
    openAiOAuthProfileId: 'openai:money-assistant-oauth',
    openAiOAuthCredentialVersion: 'oauth-credential-2026-07',
    receiptPolicyVersion: 'openai-oauth-gpt-5.6-sol-v1',
    receiptConfirmedPolicyVersion: 'openai-oauth-gpt-5.6-sol-v1',
    receiptConfirmedOAuthProfileId: 'openai:money-assistant-oauth',
    receiptConfirmedOAuthCredentialVersion: 'oauth-credential-2026-07',
  };

  assert.equal(receiptProcessingReady(policy), true);

  for (const key of [
    'receiptProcessingEnabled',
    'receiptDisclosureDelivered',
    'receiptDisclosureAccepted',
    'openAiModelImprovementDisabled',
    'codexFullEnvironmentTrainingDisabled',
  ] as const) {
    assert.equal(receiptProcessingReady({ ...policy, [key]: false }), false);
  }

  assert.equal(
    receiptProcessingReady({
      ...policy,
      openAiOAuthProfileId: 'openai:replacement',
    }),
    false,
  );
  assert.equal(
    receiptProcessingReady({
      ...policy,
      receiptPolicyVersion: 'changed-policy',
    }),
    false,
  );
  assert.equal(
    receiptProcessingReady({
      ...policy,
      receiptConfirmedPolicyVersion: 'older-policy',
    }),
    false,
  );
  assert.equal(
    receiptProcessingReady({
      ...policy,
      openAiOAuthCredentialVersion: 'rotated-credential',
    }),
    false,
  );

  for (const requiredDisclosure of [
    'OpenAI OAuth',
    'no published fixed retention ceiling',
    'model improvement',
    'Codex full-environment training',
    'never submitted as feedback',
    'one-hour crash-cleanup ceiling',
    'Telegram source',
    'proposal identifier',
    'actual provider/model',
  ]) {
    assert.equal(RECEIPT_PRIVACY_DISCLOSURE.includes(requiredDisclosure), true);
  }
});

test('receipt runtime policy pins the only OpenAI OAuth profile model and command surface', () => {
  const runtimePolicy = {
    auth: {
      profiles: {
        'openai:money-assistant-oauth': {
          provider: 'openai',
          mode: 'oauth',
        },
      },
      order: { openai: ['openai:money-assistant-oauth'] },
    },
    commands: { text: false, native: false },
    agents: {
      defaults: {
        model: { primary: 'openai/gpt-5.6-sol', fallbacks: [] },
        models: { 'openai/gpt-5.6-sol': {} },
        imageModel: { primary: 'openai/gpt-5.6-sol', fallbacks: [] },
      },
      list: [
        {
          id: 'money-assistant',
          model: { primary: 'openai/gpt-5.6-sol', fallbacks: [] },
        },
      ],
    },
  };

  assert.equal(
    receiptRuntimePolicyReady(runtimePolicy, 'money-assistant'),
    true,
  );
  assert.equal(
    receiptRuntimePolicyReady(
      {
        ...runtimePolicy,
        auth: {
          ...runtimePolicy.auth,
          profiles: {
            ...runtimePolicy.auth.profiles,
            'openai:other': { provider: 'openai', mode: 'oauth' },
          },
        },
      },
      'money-assistant',
    ),
    false,
  );

  assert.equal(
    receiptEffectiveAuthStateReady(
      { 'openai:money-assistant-oauth': { type: 'oauth' } },
      ['openai:money-assistant-oauth'],
      {},
    ),
    true,
  );
  assert.equal(
    receiptEffectiveAuthStateReady(
      { 'openai:money-assistant-oauth': { type: 'oauth' } },
      ['openai:other'],
      {},
    ),
    false,
  );
  assert.equal(
    receiptEffectiveAuthStateReady(
      { 'openai:money-assistant-oauth': { type: 'oauth' } },
      ['openai:money-assistant-oauth'],
      { authProfileOverride: 'openai:other' },
    ),
    false,
  );
  assert.equal(
    receiptRuntimePolicyReady(
      {
        ...runtimePolicy,
        commands: { text: true, native: false },
      },
      'money-assistant',
    ),
    false,
  );
  assert.equal(
    receiptRuntimePolicyReady(
      {
        ...runtimePolicy,
        agents: {
          ...runtimePolicy.agents,
          list: [
            {
              id: 'money-assistant',
              model: { primary: 'openai/gpt-5.6', fallbacks: [] },
            },
          ],
        },
      },
      'money-assistant',
    ),
    false,
  );
});

test('only one local image from the bound owner Telegram photo is admitted', () => {
  const config = {
    agentId: 'money-assistant',
    accountId: 'money-assistant-owner',
    conversationId: 'telegram-owner-123',
    ownerSenderId: 'telegram-owner-123',
    receiptMediaRoot: '/var/lib/openclaw/media/inbound',
  };
  const context = {
    channelId: 'telegram',
    accountId: 'money-assistant-owner',
    conversationId: 'telegram-owner-123',
    senderId: 'telegram-owner-123',
    sessionKey: 'owner-session',
  };
  const event = {
    messageId: 'telegram-photo-456',
    runId: 'run-456',
    timestamp: 1_785_283_200_000,
    metadata: {
      mediaPath: '/var/lib/openclaw/media/inbound/receipt.jpg',
      mediaType: 'image/jpeg',
    },
  };
  const inspectImage = (path: string, root: string) =>
    path.startsWith(`${root}/`) ? path : null;

  assert.deepEqual(admittedReceiptPhoto(event, context, config, inspectImage), {
    sessionKey: 'owner-session',
    messageId: 'telegram-photo-456',
    runId: 'run-456',
    occurredAtSeconds: 1_785_283_200,
    mediaPath: '/var/lib/openclaw/media/inbound/receipt.jpg',
  });
  assert.equal(
    admittedReceiptPhoto(
      {
        ...event,
        metadata: { ...event.metadata, mediaType: 'application/pdf' },
      },
      context,
      config,
      inspectImage,
    ),
    null,
  );
  assert.equal(
    admittedReceiptPhoto(
      {
        ...event,
        metadata: {
          mediaPaths: [
            '/var/lib/openclaw/media/inbound/one.jpg',
            '/var/lib/openclaw/media/inbound/two.jpg',
          ],
          mediaTypes: ['image/jpeg', 'image/jpeg'],
        },
      },
      context,
      config,
      inspectImage,
    ),
    null,
  );
  assert.equal(
    admittedReceiptPhoto(
      {
        ...event,
        metadata: {
          mediaPath: '/etc/passwd',
          mediaType: 'image/jpeg',
        },
      },
      context,
      config,
      inspectImage,
    ),
    null,
  );
});

test('receipt image admission validates actual content extension size and containment', async () => {
  const root = await mkdtemp(join(tmpdir(), 'money-assistant-receipt-'));
  const receiptPath = join(root, 'receipt.jpg');
  const mislabeledPath = join(root, 'not-an-image.jpg');
  const wrongExtensionPath = join(root, 'receipt.txt');
  const oversizedPath = join(root, 'oversized.jpg');
  const forgedPath = join(root, 'forged.jpg');
  const pngPath = join(root, 'receipt.png');
  const jpeg = Buffer.from(
    '/9j/4AAQSkZJRgABAQEASABIAAD/2wBDAP//////////////////////////////////////////////////////////////////////////////////////2wBDAf//////////////////////////////////////////////////////////////////////////////////////wAARCAABAAEDASIAAhEBAxEB/8QAFQABAQAAAAAAAAAAAAAAAAAAAAH/xAAUEAEAAAAAAAAAAAAAAAAAAAAA/9oADAMBAAIQAxAAAAF//8QAFBABAAAAAAAAAAAAAAAAAAAAAP/aAAgBAQABBQJ//8QAFBEBAAAAAAAAAAAAAAAAAAAAAP/aAAgBAwEBPwF//8QAFBEBAAAAAAAAAAAAAAAAAAAAAP/aAAgBAgEBPwF//8QAFBABAAAAAAAAAAAAAAAAAAAAAP/aAAgBAQAGPwJ//8QAFBABAAAAAAAAAAAAAAAAAAAAAP/aAAgBAQABPyF//9oADAMBAAIAAwAAABD/xAAUEQEAAAAAAAAAAAAAAAAAAAAA/9oACAEDAQE/EB//xAAUEQEAAAAAAAAAAAAAAAAAAAAA/9oACAECAQE/EB//xAAUEAEAAAAAAAAAAAAAAAAAAAAA/9oACAEBAAE/EB//2Q==',
    'base64',
  );
  const png = Buffer.from(
    'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mNk+A8AAQUBAScY42YAAAAASUVORK5CYII=',
    'base64',
  );

  try {
    await writeFile(receiptPath, jpeg);
    await writeFile(mislabeledPath, 'not an image');
    await writeFile(wrongExtensionPath, jpeg);
    await writeFile(oversizedPath, jpeg);
    await writeFile(
      forgedPath,
      Buffer.from([
        0xff, 0xd8, 0xff, 0xe0, 0x00, 0x04, 0x00, 0x00, 0xff, 0xd9, 0x00, 0x00,
      ]),
    );
    await writeFile(pngPath, png);
    await truncate(oversizedPath, 20 * 1024 * 1024 + 1);

    assert.equal(
      inspectReceiptImage(receiptPath, root, 'image/jpeg'),
      receiptPath,
    );
    assert.equal(inspectReceiptImage(pngPath, root, 'image/png'), pngPath);
    assert.equal(inspectReceiptImage(mislabeledPath, root, 'image/jpeg'), null);
    assert.equal(
      inspectReceiptImage(wrongExtensionPath, root, 'image/jpeg'),
      null,
    );
    assert.equal(inspectReceiptImage(forgedPath, root, 'image/jpeg'), null);
    assert.equal(inspectReceiptImage(oversizedPath, root, 'image/jpeg'), null);
    assert.equal(inspectReceiptImage('/etc/passwd', root, 'image/jpeg'), null);
  } finally {
    await rm(root, { recursive: true, force: true });
  }
});

test('receipt admission enforces the cleanup ceiling and stages source deletion', async () => {
  const timers: Array<{
    callback: () => Promise<void> | void;
    delay: number;
  }> = [];
  const deletedFiles: string[] = [];
  const admissions = new ReceiptPhotoAdmissions({
    removeFile: async (path) => {
      deletedFiles.push(path);
    },
    setTimer: (callback, delay) => {
      timers.push({ callback, delay });

      return timers.length;
    },
    clearTimer: () => {},
    createProposalId: () => '01983d79-a780-72f0-bb34-9b4f3f0cf390',
    createInteractionId: () => '01983d79-a780-72f0-bb34-9b4f3f0cf391',
    nowSeconds: () => 1_785_283_200,
    inspectImage: (path, root) => (path.startsWith(`${root}/`) ? path : null),
    safePath: (path, root) => (path.startsWith(`${root}/`) ? path : null),
    managedMediaRoot: () => '/var/lib/openclaw/media/inbound',
  });
  const config = {
    agentId: 'money-assistant',
    accountId: 'money-assistant-owner',
    conversationId: 'telegram-owner-123',
    ownerSenderId: 'telegram-owner-123',
    receiptMediaRoot: '/var/lib/openclaw/media/inbound',
  };
  const context = {
    channelId: 'telegram',
    accountId: 'money-assistant-owner',
    conversationId: 'telegram-owner-123',
    senderId: 'telegram-owner-123',
    sessionKey: 'owner-session',
  };
  const event = {
    messageId: 'telegram-photo-456',
    runId: 'run-456',
    timestamp: 1_785_283_200,
    metadata: {
      mediaPath: '/var/lib/openclaw/media/inbound/receipt.jpg',
      mediaType: 'image/jpeg',
    },
  };

  admissions.admit(event, context, config);

  assert.equal(timers[0]?.delay, 3_600_000);
  assert.equal(admissions.isSensitiveSession('owner-session'), true);
  assert.equal(
    shouldBlockReceiptMessageWrite(admissions, undefined, 'owner-session'),
    true,
  );
  assert.equal(
    admissions.recordActualModel('run-456', 'openai', 'gpt-5.6-sol'),
    true,
  );
  assert.equal(isApprovedReceiptModel('openai', 'gpt-5.6-sol'), true);
  assert.equal(isApprovedReceiptModel('openai', 'gpt-5.6'), false);
  assert.equal(
    receiptAdmissionBlockCategory(
      admissions,
      'run-456',
      'owner-session',
      1_785_285_001,
    ),
    'receipt_photo_stale',
  );

  await admissions.finishForSession('owner-session');

  assert.deepEqual(deletedFiles, [
    '/var/lib/openclaw/media/inbound/receipt.jpg',
  ]);
  assert.equal(admissions.isSensitiveSession('owner-session'), true);
  assert.equal(
    admissions.takePendingSourceDeletions('owner-session')[0]?.messageId,
    'telegram-photo-456',
  );
  assert.equal(admissions.isSensitiveSession('owner-session'), false);

  admissions.admit(
    {
      ...event,
      messageId: 'telegram-photo-expired',
      runId: 'run-expired',
    },
    context,
    config,
  );
  await timers[1]?.callback();

  assert.deepEqual(deletedFiles, [
    '/var/lib/openclaw/media/inbound/receipt.jpg',
    '/var/lib/openclaw/media/inbound/receipt.jpg',
  ]);
  admissions.takePendingSourceDeletions('owner-session');

  assert.equal(
    admissions.admit(
      {
        ...event,
        runId: undefined,
        messageId: 'telegram-photo-invalid',
        metadata: {
          mediaPath: '/var/lib/openclaw/media/inbound/receipt.pdf',
          mediaType: 'application/pdf',
        },
      },
      context,
      config,
    ),
    true,
  );
  assert.equal(
    receiptAdmissionBlockCategory(
      admissions,
      undefined,
      'owner-session',
      1_785_283_201,
    ),
    'receipt_photo_invalid',
  );
  assert.equal(
    admissions.freshForSession('owner-session', 1_785_283_201),
    null,
  );
  assert.equal(
    admissions.recordActualModel('generated-run', 'openai', 'gpt-5.6-sol'),
    false,
  );
  await admissions.finishForRun(undefined, 'owner-session');

  assert.equal(
    admissions.takePendingSourceDeletions('owner-session')[0]?.messageId,
    'telegram-photo-invalid',
  );

  assert.equal(
    admissions.admit(
      {
        ...event,
        runId: undefined,
        messageId: 'telegram-photo-without-inbound-run',
      },
      context,
      config,
    ),
    true,
  );
  assert.equal(
    admissions.freshForRun('generated-run', 'owner-session', 1_785_283_201)
      ?.messageId,
    'telegram-photo-without-inbound-run',
  );
  assert.equal(
    admissions.recordActualModel('generated-run', 'openai', 'gpt-5.6-sol'),
    true,
  );
  await admissions.finishForRun('generated-run', 'owner-session');
  assert.equal(
    admissions.takePendingSourceDeletions('owner-session')[0]?.messageId,
    'telegram-photo-without-inbound-run',
  );

  assert.equal(
    admissions.admit(
      {
        ...event,
        runId: undefined,
        messageId: 'telegram-photo-root-mismatch',
      },
      context,
      { ...config, receiptMediaRoot: '/misconfigured/media/root' },
    ),
    true,
  );
  assert.equal(
    admissions.freshForSession('owner-session', 1_785_283_201),
    null,
  );
  await admissions.finishForRun(undefined, 'owner-session');

  assert.equal(
    deletedFiles.at(-1),
    '/var/lib/openclaw/media/inbound/receipt.jpg',
  );
  assert.equal(
    admissions.takePendingSourceDeletions('owner-session')[0]?.messageId,
    'telegram-photo-root-mismatch',
  );
});

test('receipt redelivery reuses opaque identities and replacement queues source deletion', async () => {
  const proposalIds = ['proposal-1', 'proposal-2'];
  const interactionIds = ['interaction-1', 'interaction-2'];
  const removedFiles: string[] = [];
  const admissions = new ReceiptPhotoAdmissions({
    removeFile: async (path) => {
      removedFiles.push(path);
    },
    setTimer: () => 1,
    clearTimer: () => {},
    createProposalId: () => proposalIds.shift() ?? 'unexpected-proposal',
    createInteractionId: () =>
      interactionIds.shift() ?? 'unexpected-interaction',
    nowSeconds: () => 1000,
    inspectImage: (path) => path,
    safePath: (path) => path,
    managedMediaRoot: () => '/var/lib/openclaw/media/inbound',
  });
  const config = {
    agentId: 'money-assistant',
    accountId: 'money-assistant-owner',
    conversationId: 'telegram-owner-123',
    ownerSenderId: 'telegram-owner-123',
    receiptMediaRoot: '/var/lib/openclaw/media/inbound',
  };
  const context = {
    channelId: 'telegram',
    accountId: 'money-assistant-owner',
    conversationId: 'telegram-owner-123',
    senderId: 'telegram-owner-123',
    sessionKey: 'owner-session',
  };
  const event = {
    messageId: 'telegram-photo-1',
    runId: 'run-1',
    timestamp: 1000,
    metadata: {
      mediaPath: '/var/lib/openclaw/media/inbound/receipt-1.jpg',
      mediaType: 'image/jpeg',
    },
  };

  admissions.admit(event, context, config);
  const firstAdmission = admissions.activeForSession('owner-session');
  await admissions.finishForSession('owner-session');
  admissions.takePendingSourceDeletions('owner-session');
  admissions.admit(event, context, config);
  const redeliveredAdmission = admissions.activeForSession('owner-session');

  assert.equal(redeliveredAdmission?.proposalId, firstAdmission?.proposalId);
  assert.equal(
    redeliveredAdmission?.interactionId,
    firstAdmission?.interactionId,
  );

  admissions.admit(
    {
      ...event,
      messageId: 'telegram-photo-2',
      runId: 'run-2',
      metadata: {
        mediaPath: '/var/lib/openclaw/media/inbound/receipt-2.jpg',
        mediaType: 'image/jpeg',
      },
    },
    context,
    config,
  );

  assert.equal(
    admissions.takePendingSourceDeletions('owner-session')[0]?.messageId,
    'telegram-photo-2',
  );
  assert.equal(
    admissions.activeForSession('owner-session')?.proposalId,
    'proposal-1',
  );
  assert.equal(admissions.hasConflictingRun('run-2', 'owner-session'), true);
  await admissions.finishForRun('run-1', 'owner-session');
  assert.equal(admissions.activeForSession('owner-session'), null);
  assert.equal(
    receiptAdmissionBlockCategory(admissions, 'run-2', 'owner-session', 1001),
    'receipt_photo_concurrent',
  );
  assert.equal(
    receiptAdmissionBlockCategory(admissions, 'run-2', 'owner-session', 1001),
    null,
  );
  assert.deepEqual(removedFiles, [
    '/var/lib/openclaw/media/inbound/receipt-1.jpg',
    '/var/lib/openclaw/media/inbound/receipt-2.jpg',
    '/var/lib/openclaw/media/inbound/receipt-1.jpg',
  ]);
});

test('Receipt Proposal transport injects approved provenance without media identity', () => {
  const body = receiptProposalCapabilityRequestBody(
    {
      transaction: {
        occurred_on: '2026-07-28',
        amount_minor: 2590,
        currency: 'PEN',
        kind: 'purchase',
        merchant_description: 'Neighborhood market',
      },
      line_items: [
        {
          description: 'Coffee beans',
          role: 'purchased_item',
          quantity: '2',
          unit_price_minor: 1250,
          line_total_minor: 2500,
        },
        {
          description: 'Tax',
          role: 'tax',
          quantity: null,
          unit_price_minor: null,
          line_total_minor: 90,
        },
      ],
    },
    {
      agentId: 'money-assistant',
      agentAccountId: 'money-assistant-owner',
      requesterSenderId: 'telegram-owner-123',
      deliveryContext: { to: 'telegram-owner-123' },
    },
    {
      sessionKey: 'owner-session',
      messageId: 'telegram-photo-456',
      runId: 'run-456',
      occurredAtSeconds: 1_785_283_200,
      mediaPath: '/var/lib/openclaw/media/inbound/receipt.jpg',
      proposalId: '01983d79-a780-72f0-bb34-9b4f3f0cf390',
      interactionId: '01983d79-a780-72f0-bb34-9b4f3f0cf391',
      processable: true,
      cleanupPaths: ['/var/lib/openclaw/media/inbound/receipt.jpg'],
      provider: 'openai',
      model: 'openai/gpt-5.6-sol',
    },
    1_785_283_260,
  );
  const payload = JSON.parse(body);

  assert.deepEqual(payload.input, {
    proposal_id: '01983d79-a780-72f0-bb34-9b4f3f0cf390',
    source_kind: 'receipt_photo',
    processed_at: '2026-07-29T00:01:00Z',
    provider: 'openai',
    model: 'openai/gpt-5.6-sol',
    contract_version: 2,
    transaction: {
      occurred_on: '2026-07-28',
      amount_minor: 2590,
      currency: 'PEN',
      kind: 'purchase',
      merchant_description: 'Neighborhood market',
    },
    line_items: [
      {
        description: 'Coffee beans',
        role: 'purchased_item',
        quantity: '2',
        unit_price_minor: 1250,
        line_total_minor: 2500,
      },
      {
        description: 'Tax',
        role: 'tax',
        quantity: null,
        unit_price_minor: null,
        line_total_minor: 90,
      },
    ],
  });
  assert.equal(payload.interaction.kind, 'owner_photo_message');
  assert.equal(
    payload.interaction.message_id,
    '01983d79-a780-72f0-bb34-9b4f3f0cf391',
  );
  assert.equal(body.includes('telegram-photo-456'), false);
  assert.equal(body.includes('mediaPath'), false);
  assert.equal(body.includes('receipt.jpg'), false);
});

test('receipt completion deletes the Telegram source or warns the owner', async () => {
  const calls: Array<{ method: string; params?: Record<string, unknown> }> = [];
  const gateway = {
    async request(method: string, params?: Record<string, unknown>) {
      calls.push({ method, params });

      return { ok: true };
    },
  };
  const admission = {
    sessionKey: 'owner-session',
    messageId: '456',
    occurredAtSeconds: 1_785_283_200,
    mediaPath: '/var/lib/openclaw/media/inbound/receipt.jpg',
    proposalId: '01983d79-a780-72f0-bb34-9b4f3f0cf390',
    interactionId: '01983d79-a780-72f0-bb34-9b4f3f0cf391',
    processable: true,
    cleanupPaths: ['/var/lib/openclaw/media/inbound/receipt.jpg'],
  };
  const config = {
    keyId: 'openclaw-service-2026-07',
    privateKey: 'unused',
    agentId: 'money-assistant',
    accountId: 'money-assistant-owner',
    conversationId: 'telegram-owner-123',
    ownerSenderId: 'telegram-owner-123',
  };

  await deleteReceiptSourceMessage(gateway, admission, config);
  await warnReceiptSourceDeletionFailed(gateway, admission, config);

  assert.deepEqual(calls[0], {
    method: 'message.action',
    params: {
      channel: 'telegram',
      action: 'delete',
      accountId: 'money-assistant-owner',
      agentId: 'money-assistant',
      sessionKey: 'owner-session',
      idempotencyKey: '01983d79-a780-72f0-bb34-9b4f3f0cf390',
      params: {
        target: 'telegram-owner-123',
        messageId: 456,
      },
    },
  });
  assert.deepEqual(calls[1]?.params?.params, {
    target: 'telegram-owner-123',
    message:
      'I could not delete the receipt photo from Telegram. Please remove it manually.',
  });
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

  assert.deepEqual(
    admittedReminderEvent(
      'Fetch Reminder event 01983d79-a780-72f0-bb34-9b4f3f0cf390 that occurred at 2026-07-26T15:05:00Z with money_assistant_reminder_read.',
      {
        agentId: 'money-assistant',
        sessionKey: 'hook:money-assistant:reminders',
      },
      config,
      1_785_078_301,
    ),
    {
      eventId: '01983d79-a780-72f0-bb34-9b4f3f0cf390',
      occurredAtSeconds: 1_785_078_301,
    },
  );
  assert.equal(
    admittedReminderEvent(
      'Fetch Reminder event 01983d79-a780-72f0-bb34-9b4f3f0cf390 that occurred at 2026-07-26T15:05:00Z with money_assistant_reminder_read.',
      {
        agentId: 'money-assistant',
        sessionKey: 'caller-selected',
      },
      config,
      1_785_078_301,
    ),
    null,
  );

  assert.equal(isBoundReminderEventInteraction(context, config), true);
  assert.equal(
    isBoundReminderEventInteraction(
      {
        ...context,
        sessionKey:
          'agent:money-assistant:hook:money-assistant:reminders',
      },
      config,
    ),
    true,
  );
  assert.deepEqual(
    admittedReminderEvent(
      'Fetch Reminder event 01983d79-a780-72f0-bb34-9b4f3f0cf390 that occurred at 2026-07-26T15:05:00Z with money_assistant_reminder_read.',
      {
        agentId: 'money-assistant',
        sessionKey:
          'agent:money-assistant:hook:money-assistant:reminders',
      },
      config,
      1_785_078_301,
    ),
    {
      eventId: '01983d79-a780-72f0-bb34-9b4f3f0cf390',
      occurredAtSeconds: 1_785_078_301,
    },
  );
  assert.equal(
    isBoundReminderEventInteraction(
      { ...context, sessionKey: 'caller-selected' },
      config,
    ),
    false,
  );
  assert.equal(
    isBoundReminderEventInteraction(
      {
        ...context,
        deliveryContext: { ...context.deliveryContext, to: 'other' },
      },
      config,
    ),
    false,
  );

  assert.equal(
    isBoundReminderChannelDelivery(
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
    ),
    true,
  );
  assert.equal(
    isBoundReminderChannelDelivery(
      {
        to: 'telegram-owner-123',
        success: true,
        sessionKey:
          'agent:money-assistant:hook:money-assistant:reminders',
      },
      {
        channelId: 'telegram',
        accountId: 'money-assistant-owner',
        conversationId: 'telegram-owner-123',
      },
      config,
    ),
    true,
  );
  assert.equal(
    isBoundReminderChannelDelivery(
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
    ),
    false,
  );

  assert.deepEqual(
    JSON.parse(
      reminderEventCapabilityRequestBody(
        'reminder.read',
        { event_id: '01983d79-a780-72f0-bb34-9b4f3f0cf390' },
        config,
        '01983d79-a780-72f0-bb34-9b4f3f0cf390',
        1_785_078_300,
      ),
    ),
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
    admissions.takeFreshForSession('hook:money-assistant:reminders', 1001)
      ?.eventId,
    eventId,
  );
  assert.equal(
    admissions.takeFreshForSession('hook:money-assistant:reminders', 1002)
      ?.eventId,
    nextEventId,
  );

  admissions.admit('hook:money-assistant:reminders', eventId, 1003);
  admissions.markAlreadyDelivered('hook:money-assistant:reminders', eventId);

  assert.equal(
    shouldSuppressReminderDelivery(
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
    ),
    true,
  );
  assert.equal(
    admissions.freshForSession('hook:money-assistant:reminders', 1004),
    null,
  );

  admissions.admit('hook:money-assistant:reminders', nextEventId, 1005);
  admissions.markAlreadyDelivered(
    'hook:money-assistant:reminders',
    nextEventId,
  );
  assert.equal(
    consumeAlreadyDeliveredReminder(
      'hook:money-assistant:reminders',
      admissions,
      1006,
    ),
    true,
  );
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
    await assert.rejects(() =>
      recordReminderChannelDelivery(
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
      ),
    );
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
    JSON.parse(
      capabilityRequestBody(
        'transaction.manual.prepare',
        preparationInput,
        toolContext,
        admission,
      ),
    ),
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
  assert.deepEqual(
    JSON.parse(
      capabilityRequestBody(
        'transaction.manual.prepare',
        preparationInput,
        {
          ...toolContext,
          requesterSenderId: 'telegram:telegram-owner-123',
          deliveryContext: { to: 'telegram:telegram-owner-123' },
        },
        admission,
      ),
    ).interaction,
    {
      kind: 'owner_message',
      agent_id: 'money-assistant',
      account_id: 'money-assistant-owner',
      conversation_id: 'telegram-owner-123',
      owner_sender_id: 'telegram-owner-123',
      message_id: 'telegram-message-prepare',
      occurred_at: '2026-07-24T17:00:00Z',
    },
  );

  const confirmationInput = {
    idempotency_key: '01983d79-a780-72f0-bb34-9b4f3f0cf374',
    pending_operation_id: '01983d79-a780-72f0-bb34-9b4f3f0cf373',
    pending_operation_revision: 1,
    payload_digest: 'a'.repeat(64),
  };

  assert.equal(
    JSON.parse(
      capabilityRequestBody(
        'transaction.manual.confirm',
        confirmationInput,
        toolContext,
        { ...admission, messageId: 'telegram-message-approve' },
      ),
    ).interaction.message_id,
    'telegram-message-approve',
  );
});

test('Receipt Breakdown tools accept bounded replacement edits and exact revision confirmations', () => {
  const lineItemId = '01983d79-a780-72f0-bb34-9b4f3f0cf390';
  const update = {
    idempotency_key: '01983d79-a780-72f0-bb34-9b4f3f0cf391',
    operation: 'update_draft',
    receipt_breakdown_id: 12,
    expected_revision: 3,
    line_items: [
      {
        id: lineItemId,
        description: 'Coffee beans',
        role: 'purchased_item',
        quantity: '2',
        unit_price_minor: 600,
        line_total_minor: 1200,
        category_id: 4,
      },
      {
        id: null,
        description: 'Receipt detail unavailable',
        role: 'unidentified',
        quantity: null,
        unit_price_minor: null,
        line_total_minor: 1300,
        category_id: null,
      },
    ],
  };

  assert.equal(isReceiptBreakdownMutationInput(update), true);
  assert.equal(
    isReceiptBreakdownMutationInput({
      ...update,
      line_items: [
        {
          id: lineItemId,
          description: 'Coffee',
          line_total_minor: 1200,
          category_id: 4,
        },
      ],
    }),
    true,
  );
  assert.equal(
    isReceiptBreakdownMutationInput({
      ...update,
      line_items: [
        update.line_items[0],
        {
          ...update.line_items[1],
          role: 'tax',
          related_line_item_id: lineItemId,
        },
      ],
    }),
    true,
  );
  assert.equal(
    isReceiptBreakdownMutationInput({
      ...update,
      line_items: [update.line_items[0], update.line_items[0]],
    }),
    false,
  );
  assert.equal(
    isReceiptBreakdownMutationInput({
      ...update,
      line_items: [
        {
          ...update.line_items[0],
          line_total_minor: Number.MAX_SAFE_INTEGER + 1,
        },
      ],
    }),
    false,
  );
  assert.equal(
    isReceiptBreakdownMutationInput({
      idempotency_key: '01983d79-a780-72f0-bb34-9b4f3f0cf392',
      operation: 'confirm_draft',
      receipt_breakdown_id: 12,
      expected_revision: 3,
    }),
    true,
  );

  assert.equal(
    isReceiptBreakdownMutationInput({
      idempotency_key: '01983d79-a780-72f0-bb34-9b4f3f0cf393',
      operation: 'create_draft',
      transaction_id: 18,
      expected_transaction_revision: 2,
      line_items: [
        {
          id: null,
          description: 'Cinema tickets',
          quantity: '4',
          unit_price_minor: 4300,
          line_total_minor: 17200,
          category_id: 4,
        },
        {
          id: null,
          description: 'Concessions combo',
          line_total_minor: 5900,
          category_id: 4,
        },
      ],
    }),
    true,
  );
  assert.equal(
    isReceiptBreakdownMutationInput({
      idempotency_key: '01983d79-a780-72f0-bb34-9b4f3f0cf393',
      operation: 'create_draft',
      transaction_id: 18,
      expected_transaction_revision: 2,
      line_items: [
        {
          id: lineItemId,
          description: 'Cinema tickets',
          line_total_minor: 23100,
          category_id: 4,
        },
      ],
    }),
    false,
  );
});

test('receipt cleanup delivery accepts the normalized bound Telegram peer', () => {
  const config = {
    agentId: 'money-assistant',
    accountId: 'money-assistant-owner',
    conversationId: '1837588898',
    ownerSenderId: '1837588898',
  };

  assert.equal(
    isBoundReceiptChannelDelivery(
      {
        to: 'telegram:1837588898',
        success: true,
        sessionKey: 'agent:money-assistant:telegram:direct:1837588898',
      },
      {
        channelId: 'telegram',
        accountId: 'money-assistant-owner',
        conversationId: 'telegram:1837588898',
        sessionKey: 'agent:money-assistant:telegram:direct:1837588898',
      },
      config,
    ),
    true,
  );
  assert.equal(
    isBoundReceiptChannelDelivery(
      {
        to: 'telegram:999',
        success: true,
        sessionKey: 'agent:money-assistant:telegram:direct:1837588898',
      },
      {
        channelId: 'telegram',
        accountId: 'money-assistant-owner',
        conversationId: 'telegram:999',
        sessionKey: 'agent:money-assistant:telegram:direct:1837588898',
      },
      config,
    ),
    false,
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
  assert.equal(
    isBoundOwnerInteraction(
      {
        ...context,
        requesterSenderId: 'telegram:telegram-owner-123',
        deliveryContext: {
          ...context.deliveryContext,
          to: 'telegram:telegram-owner-123',
        },
      },
      config,
    ),
    true,
  );
  assert.equal(
    isBoundOwnerInteraction({ ...context, senderIsOwner: false }, config),
    false,
  );
  assert.equal(
    isBoundOwnerInteraction({ ...context, sessionId: undefined }, config),
    false,
  );
  assert.equal(
    isBoundOwnerInteraction({ ...context, requesterSenderId: 'other' }, config),
    false,
  );
  assert.equal(
    isBoundOwnerInteraction(
      {
        ...context,
        requesterSenderId: 'telegram:other',
        deliveryContext: {
          ...context.deliveryContext,
          to: 'telegram:telegram-owner-123',
        },
      },
      config,
    ),
    false,
  );
  assert.equal(
    isBoundOwnerInteraction(
      {
        ...context,
        requesterSenderId: 'signal:telegram-owner-123',
      },
      config,
    ),
    false,
  );
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
  assert.deepEqual(
    admittedOwnerMessage(
      event,
      {
        ...context,
        conversationId: 'telegram:telegram-owner-123',
        senderId: 'telegram:telegram-owner-123',
      },
      config,
    ),
    {
      sessionKey: 'agent:money-assistant:telegram-owner-123',
      messageId: 'telegram-message-456',
      occurredAtSeconds: 1_784_912_400,
    },
  );
  assert.equal(
    admittedOwnerMessage(event, { ...context, senderId: 'other' }, config),
    null,
  );
  assert.equal(
    admittedOwnerMessage(
      event,
      { ...context, senderId: 'signal:telegram-owner-123' },
      config,
    ),
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
  assert.equal(
    admissions.freshForSession('owner-session', 1001)?.messageId,
    'first',
  );
  assert.equal(admissions.freshForSession('other-session', 1001), null);
  assert.equal(admissions.freshForSession('owner-session', 2801), null);
  assert.equal(admissions.freshForSession('owner-session', 999), null);

  admissions.admit({ messageId: 'second', timestamp: 2000 }, context, config);
  assert.equal(
    admissions.freshForSession('owner-session', 2001)?.messageId,
    'second',
  );

  admissions.admit({ messageId: undefined, timestamp: 2002 }, context, config);
  assert.equal(admissions.freshForSession('owner-session', 2003), null);
});
