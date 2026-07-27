import {
  createHash,
  createPrivateKey,
  randomUUID,
  sign,
} from 'node:crypto';
import type { KeyObject } from 'node:crypto';
import { defineToolPlugin } from 'openclaw/plugin-sdk/tool-plugin';
import { Type } from 'typebox';

const CAPABILITY_ORIGIN = 'http://127.0.0.1:8443';
const CAPABILITY_PATH = '/api/openclaw/v1/transport';
const REMINDER_HOOK_SESSION_KEY = 'hook:money-assistant:reminders';
const UUID_PATTERN = '^[0-9a-f]{8}-[0-9a-f]{4}-[1-8][0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$';
const SHA256_PATTERN = '^[a-f0-9]{64}$';

const pluginConfigSchema = Type.Object(
  {
    keyId: Type.String({ minLength: 1, maxLength: 128 }),
    privateKey: Type.String({ minLength: 1 }),
    agentId: Type.String({ minLength: 1, maxLength: 128 }),
    accountId: Type.String({ minLength: 1, maxLength: 128 }),
    conversationId: Type.String({ minLength: 1, maxLength: 128 }),
    ownerSenderId: Type.String({ minLength: 1, maxLength: 128 }),
  },
  { additionalProperties: false },
);

const transactionParameters = Type.Object(
  {
    transaction_id: Type.Integer({ minimum: 1 }),
  },
  { additionalProperties: false },
);

const manualTransactionPreparationParameters = Type.Object(
  {
    idempotency_key: Type.String({ pattern: UUID_PATTERN }),
    occurred_on: Type.String({ pattern: '^\\d{4}-\\d{2}-\\d{2}$' }),
    amount_minor: Type.Integer({ minimum: 1, maximum: Number.MAX_SAFE_INTEGER }),
    currency: Type.Union([Type.Literal('USD'), Type.Literal('PEN')]),
    kind: Type.Union([Type.Literal('purchase'), Type.Literal('refund')]),
    merchant_description: Type.String({ minLength: 1, maxLength: 255 }),
  },
  { additionalProperties: false },
);

const manualTransactionConfirmationParameters = Type.Object(
  {
    idempotency_key: Type.String({ pattern: UUID_PATTERN }),
    pending_operation_id: Type.String({ pattern: UUID_PATTERN }),
    pending_operation_revision: Type.Integer({ minimum: 1 }),
    payload_digest: Type.String({ pattern: SHA256_PATTERN }),
  },
  { additionalProperties: false },
);

const categoryReadParameters = Type.Object({
  page: Type.Integer({ minimum: 1 }),
  per_page: Type.Integer({ minimum: 1, maximum: 100 }),
}, { additionalProperties: false });

const categoryNameParameters = {
  name: Type.String({ minLength: 1, maxLength: 255 }),
  parent_id: Type.Union([Type.Integer({ minimum: 1 }), Type.Null()]),
  description: Type.Union([Type.String({ maxLength: 2000 }), Type.Null()]),
  examples: Type.Array(Type.String({ maxLength: 100 }), { maxItems: 20 }),
};

const categoryMutationPreparationParameters = Type.Union([
  Type.Object({
    idempotency_key: Type.String({ pattern: UUID_PATTERN }),
    operation: Type.Literal('create'),
    ...categoryNameParameters,
  }, { additionalProperties: false }),
  Type.Object({
    idempotency_key: Type.String({ pattern: UUID_PATTERN }),
    operation: Type.Literal('update'),
    category_id: Type.Integer({ minimum: 1 }),
    expected_revision: Type.Integer({ minimum: 1 }),
    ...categoryNameParameters,
  }, { additionalProperties: false }),
  Type.Object({
    idempotency_key: Type.String({ pattern: UUID_PATTERN }),
    operation: Type.Union([Type.Literal('retire'), Type.Literal('reactivate')]),
    category_id: Type.Integer({ minimum: 1 }),
    expected_revision: Type.Integer({ minimum: 1 }),
  }, { additionalProperties: false }),
  Type.Object({
    idempotency_key: Type.String({ pattern: UUID_PATTERN }),
    operation: Type.Literal('assign_transaction'),
    transaction_id: Type.Integer({ minimum: 1 }),
    expected_revision: Type.Integer({ minimum: 1 }),
    category_id: Type.Union([Type.Integer({ minimum: 1 }), Type.Null()]),
  }, { additionalProperties: false }),
]);

const reminderReadParameters = Type.Object({
  event_id: Type.String({ pattern: UUID_PATTERN }),
}, { additionalProperties: false });

const reminderResponseParameters = Type.Union([
  Type.Object({
    idempotency_key: Type.String({ pattern: UUID_PATTERN }),
    reminder_id: Type.Integer({ minimum: 1 }),
    action: Type.Union([Type.Literal('acknowledge'), Type.Literal('dismiss')]),
  }, { additionalProperties: false }),
  Type.Object({
    idempotency_key: Type.String({ pattern: UUID_PATTERN }),
    reminder_id: Type.Integer({ minimum: 1 }),
    action: Type.Literal('snooze'),
    snoozed_until: Type.String({
      pattern: '^\\d{4}-\\d{2}-\\d{2}T\\d{2}:\\d{2}:\\d{2}(?:Z|[+-]\\d{2}:\\d{2})$',
    }),
  }, { additionalProperties: false }),
]);

type BindingConfiguration = {
  agentId: string;
  accountId: string;
  conversationId: string;
  ownerSenderId: string;
};

type PluginConfiguration = BindingConfiguration & {
  keyId: string;
  privateKey: string;
};

type AdmittedOwnerMessage = {
  sessionKey: string;
  messageId: string;
  occurredAtSeconds: number;
};

type AdmittedReminderEvent = {
  eventId: string;
  occurredAtSeconds: number;
  alreadyDelivered?: boolean;
};

type InboundMessage = {
  senderId?: string;
  messageId?: string;
  timestamp?: number;
  sessionKey?: string;
};

type InboundMessageContext = {
  channelId: string;
  accountId?: string;
  conversationId?: string;
  senderId?: string;
  messageId?: string;
  sessionKey?: string;
};

type SentMessage = {
  to: string;
  success: boolean;
  sessionKey?: string;
};

type OutgoingMessage = {
  to: string;
};

type SentMessageContext = {
  channelId: string;
  accountId?: string;
  conversationId?: string;
  sessionKey?: string;
};

type ReminderRunContext = {
  agentId?: string;
  sessionKey?: string;
};

type TrustedToolContext = {
  agentId?: string;
  messageChannel?: string;
  agentAccountId?: string;
  requesterSenderId?: string;
  senderIsOwner?: boolean;
  sessionId?: string;
  sessionKey?: string;
  deliveryContext?: {
    channel?: string;
    accountId?: string;
    to?: string;
  };
};

type CapabilityInput = Record<string, unknown>;

class CapabilityRequestError extends Error {
  constructor(
    message: string,
    readonly status?: number,
  ) {
    super(message);
  }
}

function timestampInSeconds(timestamp: number): number | null {
  if (!Number.isFinite(timestamp) || timestamp <= 0) {
    return null;
  }

  return Math.floor(timestamp > 10_000_000_000 ? timestamp / 1000 : timestamp);
}

export function admittedOwnerMessage(
  event: InboundMessage,
  context: InboundMessageContext,
  config: BindingConfiguration,
): AdmittedOwnerMessage | null {
  const sessionKey = event.sessionKey ?? context.sessionKey;
  const messageId = event.messageId ?? context.messageId;
  const occurredAtSeconds = event.timestamp === undefined
    ? null
    : timestampInSeconds(event.timestamp);

  if (context.channelId !== 'telegram'
    || context.accountId !== config.accountId
    || context.conversationId !== config.conversationId
    || (event.senderId ?? context.senderId) !== config.ownerSenderId
    || !sessionKey
    || !messageId
    || occurredAtSeconds === null) {
    return null;
  }

  return {
    sessionKey,
    messageId,
    occurredAtSeconds,
  };
}

export class OwnerMessageAdmissions {
  private readonly messages = new Map<string, AdmittedOwnerMessage>();

  admit(
    event: InboundMessage,
    context: InboundMessageContext,
    config: BindingConfiguration,
  ): void {
    const sessionKey = event.sessionKey ?? context.sessionKey;

    if (sessionKey) {
      this.messages.delete(sessionKey);
    }

    const admission = admittedOwnerMessage(event, context, config);

    if (admission) {
      this.messages.set(admission.sessionKey, admission);
    }
  }

  freshForSession(
    sessionKey: string | undefined,
    nowSeconds: number,
  ): AdmittedOwnerMessage | null {
    const admission = this.messages.get(sessionKey ?? '');

    if (!admission) {
      return null;
    }

    const ageInSeconds = nowSeconds - admission.occurredAtSeconds;

    return ageInSeconds >= 0 && ageInSeconds <= 1800 ? admission : null;
  }
}

const ownerMessageAdmissions = new OwnerMessageAdmissions();

export class ReminderEventAdmissions {
  private readonly events = new Map<string, AdmittedReminderEvent[]>();

  admit(sessionKey: string, eventId: string, occurredAtSeconds: number): void {
    const admissions = this.events.get(sessionKey) ?? [];

    if (!admissions.some((admission) => admission.eventId === eventId)) {
      admissions.push({ eventId, occurredAtSeconds });
    }

    this.events.set(sessionKey, admissions);
  }

  freshForSession(
    sessionKey: string | undefined,
    nowSeconds: number,
  ): AdmittedReminderEvent | null {
    const admissions = this.events.get(sessionKey ?? '') ?? [];
    const admission = admissions.find((candidate) => {
      const ageInSeconds = nowSeconds - candidate.occurredAtSeconds;

      return ageInSeconds >= 0 && ageInSeconds <= 1800;
    });

    if (!admission) {
      return null;
    }

    return admission;
  }

  freshEventForSession(
    sessionKey: string | undefined,
    eventId: string,
    nowSeconds: number,
  ): AdmittedReminderEvent | null {
    const admissions = this.events.get(sessionKey ?? '') ?? [];

    return admissions.find((admission) => {
      const ageInSeconds = nowSeconds - admission.occurredAtSeconds;

      return admission.eventId === eventId
        && ageInSeconds >= 0
        && ageInSeconds <= 1800;
    }) ?? null;
  }

  markAlreadyDelivered(sessionKey: string, eventId: string): void {
    const admission = this.events.get(sessionKey)?.find(
      (candidate) => candidate.eventId === eventId,
    );

    if (admission) {
      admission.alreadyDelivered = true;
    }
  }

  takeFreshForSession(
    sessionKey: string | undefined,
    nowSeconds: number,
  ): AdmittedReminderEvent | null {
    const key = sessionKey ?? '';
    const admissions = this.events.get(key) ?? [];
    const admissionIndex = admissions.findIndex((candidate) => {
      const ageInSeconds = nowSeconds - candidate.occurredAtSeconds;

      return ageInSeconds >= 0 && ageInSeconds <= 1800;
    });

    if (admissionIndex < 0) {
      return null;
    }

    const [admission] = admissions.splice(admissionIndex, 1);

    if (admissions.length === 0) {
      this.events.delete(key);
    } else {
      this.events.set(key, admissions);
    }

    return admission;
  }
}

const reminderEventAdmissions = new ReminderEventAdmissions();

export function isBoundOwnerInteraction(
  toolContext: TrustedToolContext,
  config: BindingConfiguration,
): boolean {
  if (toolContext.senderIsOwner !== true) {
    return false;
  }

  return toolContext.agentId === config.agentId
    && toolContext.messageChannel === 'telegram'
    && toolContext.agentAccountId === config.accountId
    && toolContext.requesterSenderId === config.ownerSenderId
    && toolContext.sessionId !== undefined
    && toolContext.deliveryContext?.channel === 'telegram'
    && toolContext.deliveryContext.accountId === config.accountId
    && toolContext.deliveryContext.to === config.conversationId;
}

export function isBoundReminderEventInteraction(
  toolContext: TrustedToolContext,
  config: BindingConfiguration,
): boolean {
  return toolContext.agentId === config.agentId
    && toolContext.sessionKey === REMINDER_HOOK_SESSION_KEY
    && toolContext.deliveryContext?.channel === 'telegram'
    && toolContext.deliveryContext.accountId === config.accountId
    && toolContext.deliveryContext.to === config.conversationId;
}

export function admittedReminderEvent(
  prompt: string,
  context: ReminderRunContext,
  config: BindingConfiguration,
  nowSeconds: number,
): AdmittedReminderEvent | null {
  if (context.agentId !== config.agentId
    || context.sessionKey !== REMINDER_HOOK_SESSION_KEY) {
    return null;
  }

  const match = prompt.match(
    /Fetch Reminder event ([0-9a-f-]{36}) that occurred at \d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}Z with money_assistant_reminder_read/,
  );

  if (!match
    || !new RegExp(UUID_PATTERN).test(match[1] ?? '')) {
    return null;
  }

  return {
    eventId: match[1] as string,
    occurredAtSeconds: nowSeconds,
  };
}

export function isBoundReminderChannelDelivery(
  event: SentMessage,
  context: SentMessageContext,
  config: BindingConfiguration,
): boolean {
  const sessionKey = event.sessionKey ?? context.sessionKey;

  return event.success === true
    && sessionKey === REMINDER_HOOK_SESSION_KEY
    && context.channelId === 'telegram'
    && context.accountId === config.accountId
    && context.conversationId === config.conversationId
    && event.to === config.conversationId;
}

export function shouldSuppressReminderDelivery(
  event: OutgoingMessage,
  context: SentMessageContext,
  config: BindingConfiguration,
  admissions: ReminderEventAdmissions,
  nowSeconds: number,
): boolean {
  const sessionKey = context.sessionKey;

  if (sessionKey !== REMINDER_HOOK_SESSION_KEY
    || context.channelId !== 'telegram'
    || context.accountId !== config.accountId
    || context.conversationId !== config.conversationId
    || event.to !== config.conversationId) {
    return false;
  }

  return consumeAlreadyDeliveredReminder(sessionKey, admissions, nowSeconds);
}

export function consumeAlreadyDeliveredReminder(
  sessionKey: string | undefined,
  admissions: ReminderEventAdmissions,
  nowSeconds: number,
): boolean {
  if (admissions.freshForSession(sessionKey, nowSeconds)?.alreadyDelivered !== true) {
    return false;
  }

  admissions.takeFreshForSession(sessionKey, nowSeconds);

  return true;
}

function privateKeyFromSodiumSecret(encodedPrivateKey: string): KeyObject {
  const sodiumSecret = Buffer.from(encodedPrivateKey, 'base64');

  if (sodiumSecret.length !== 32 && sodiumSecret.length !== 64) {
    throw new Error('Money Assistant signing key is invalid.');
  }

  const seed = sodiumSecret.subarray(0, 32);
  const pkcs8Prefix = Buffer.from('302e020100300506032b657004220420', 'hex');

  return createPrivateKey({
    key: Buffer.concat([pkcs8Prefix, seed]),
    format: 'der',
    type: 'pkcs8',
  });
}

export function authorizationHeaders(
  body: string,
  keyId: string,
  encodedPrivateKey: string,
  timestamp: string,
  nonce: string,
): Record<string, string> {
  const bodyDigest = createHash('sha256').update(body).digest('hex');
  const signedMessage = [
    timestamp,
    nonce,
    'POST',
    CAPABILITY_PATH,
    bodyDigest,
  ].join('\n');
  const signature = sign(
    null,
    Buffer.from(signedMessage),
    privateKeyFromSodiumSecret(encodedPrivateKey),
  );

  return {
    'Content-Type': 'application/json',
    Accept: 'application/json',
    'X-Money-Assistant-Key-Id': keyId,
    'X-Money-Assistant-Timestamp': timestamp,
    'X-Money-Assistant-Nonce': nonce,
    'X-Money-Assistant-Signature': signature.toString('base64'),
  };
}

export function capabilityRequestBody(
  capability: string,
  input: CapabilityInput,
  toolContext: TrustedToolContext,
  admission: AdmittedOwnerMessage,
): string {
  const occurredAt = new Date(admission.occurredAtSeconds * 1000)
    .toISOString()
    .replace('.000Z', 'Z');

  return JSON.stringify({
    schema_version: 1,
    capability,
    interaction: {
      kind: 'owner_message',
      agent_id: toolContext.agentId,
      account_id: toolContext.agentAccountId,
      conversation_id: toolContext.deliveryContext?.to,
      owner_sender_id: toolContext.requesterSenderId,
      message_id: admission.messageId,
      occurred_at: occurredAt,
    },
    input,
  });
}

export function reminderEventCapabilityRequestBody(
  capability: string,
  input: CapabilityInput,
  config: BindingConfiguration,
  eventId: string,
  occurredAtSeconds: number,
): string {
  const occurredAt = new Date(occurredAtSeconds * 1000)
    .toISOString()
    .replace('.000Z', 'Z');

  return JSON.stringify({
    schema_version: 1,
    capability,
    interaction: {
      kind: 'money_assistant_event',
      agent_id: config.agentId,
      account_id: config.accountId,
      conversation_id: config.conversationId,
      owner_sender_id: config.ownerSenderId,
      message_id: eventId,
      occurred_at: occurredAt,
    },
    input,
  });
}

async function requestCapability(
  capability: string,
  body: string,
  config: PluginConfiguration,
  signal?: AbortSignal,
): Promise<unknown> {
  signal?.throwIfAborted();
  const response = await fetch(`${CAPABILITY_ORIGIN}${CAPABILITY_PATH}`, {
    method: 'POST',
    headers: authorizationHeaders(
      body,
      config.keyId,
      config.privateKey,
      Math.floor(Date.now() / 1000).toString(),
      randomUUID(),
    ),
    body,
    signal,
  });

  if (!response.ok) {
    throw new CapabilityRequestError(
      `Money Assistant rejected ${capability} (${response.status}).`,
      response.status,
    );
  }

  return response.json();
}

async function executeCapability(
  capability: string,
  input: CapabilityInput,
  config: PluginConfiguration,
  toolContext: TrustedToolContext,
  signal?: AbortSignal,
): Promise<{ content: Array<{ type: 'text'; text: string }>; details: unknown }> {
  const nowSeconds = Math.floor(Date.now() / 1000);
  const admission = ownerMessageAdmissions.freshForSession(
    toolContext.sessionKey,
    nowSeconds,
  );

  if (!admission) {
    throw new Error('Money Assistant owner message admission is unavailable.');
  }

  const body = capabilityRequestBody(capability, input, toolContext, admission);
  const details = await requestCapability(capability, body, config, signal);

  return {
    content: [{ type: 'text', text: JSON.stringify(details) }],
    details,
  };
}

async function executeReminderEventCapability(
  capability: string,
  input: CapabilityInput,
  eventId: string,
  config: PluginConfiguration,
  toolContext: TrustedToolContext,
  signal?: AbortSignal,
): Promise<{ content: Array<{ type: 'text'; text: string }>; details: unknown }> {
  if (!isBoundReminderEventInteraction(toolContext, config)
    || toolContext.sessionKey === undefined) {
    throw new Error('Money Assistant Reminder event binding is unavailable.');
  }

  const admission = reminderEventAdmissions.freshEventForSession(
    toolContext.sessionKey,
    eventId,
    Math.floor(Date.now() / 1000),
  );

  if (!admission) {
    throw new Error('Money Assistant Reminder event admission is unavailable.');
  }

  const body = reminderEventCapabilityRequestBody(
    capability,
    input,
    config,
    eventId,
    admission.occurredAtSeconds,
  );
  const details = await requestCapability(capability, body, config, signal);

  if (capability === 'reminder.read'
    && typeof details === 'object'
    && details !== null
    && 'delivery' in details
    && typeof details.delivery === 'object'
    && details.delivery !== null
    && 'channel_delivered_at' in details.delivery
    && details.delivery.channel_delivered_at !== null) {
    reminderEventAdmissions.markAlreadyDelivered(
      toolContext.sessionKey,
      eventId,
    );
  }

  return {
    content: [{ type: 'text', text: JSON.stringify(details) }],
    details,
  };
}

export async function recordReminderChannelDelivery(
  admission: AdmittedReminderEvent,
  config: PluginConfiguration,
): Promise<void> {
  const body = reminderEventCapabilityRequestBody(
    'reminder.delivery.record',
    { event_id: admission.eventId },
    config,
    admission.eventId,
    admission.occurredAtSeconds,
  );

  const delays = [200, 1000];

  for (let attempt = 0; attempt <= delays.length; attempt += 1) {
    try {
      await requestCapability('reminder.delivery.record', body, config);

      return;
    } catch (error) {
      const isTransient = !(error instanceof CapabilityRequestError)
        || error.status === 429
        || (error.status !== undefined && error.status >= 500);

      if (!isTransient || attempt === delays.length) {
        throw error;
      }

      await new Promise((resolve) => setTimeout(resolve, delays[attempt]));
    }
  }
}

function isPositiveSafeInteger(value: unknown): boolean {
  return Number.isSafeInteger(value) && Number(value) > 0;
}

function isCategoryMutationInput(input: Record<string, unknown>): boolean {
  const operation = input.operation;

  if (typeof input.idempotency_key !== 'string'
    || !new RegExp(UUID_PATTERN).test(input.idempotency_key)
    || typeof operation !== 'string') {
    return false;
  }

  if (operation === 'assign_transaction') {
    return isPositiveSafeInteger(input.transaction_id)
      && isPositiveSafeInteger(input.expected_revision)
      && (input.category_id === null || isPositiveSafeInteger(input.category_id));
  }

  if (operation === 'retire' || operation === 'reactivate') {
    return isPositiveSafeInteger(input.category_id)
      && isPositiveSafeInteger(input.expected_revision);
  }

  if (operation !== 'create' && operation !== 'update') {
    return false;
  }

  return (operation === 'create'
      || (isPositiveSafeInteger(input.category_id)
        && isPositiveSafeInteger(input.expected_revision)))
    && typeof input.name === 'string'
    && input.name.trim() !== ''
    && input.name.length <= 255
    && (input.parent_id === null || isPositiveSafeInteger(input.parent_id))
    && (input.description === null
      || (typeof input.description === 'string' && input.description.length <= 2000))
    && Array.isArray(input.examples)
    && input.examples.length <= 20
    && input.examples.every(
      (example) => typeof example === 'string' && example.length <= 100,
    );
}

function isReminderResponseInput(input: Record<string, unknown>): boolean {
  if (typeof input.idempotency_key !== 'string'
    || !new RegExp(UUID_PATTERN).test(input.idempotency_key)
    || !isPositiveSafeInteger(input.reminder_id)) {
    return false;
  }

  if (input.action === 'acknowledge' || input.action === 'dismiss') {
    return Object.keys(input).length === 3;
  }

  if (input.action !== 'snooze'
    || typeof input.snoozed_until !== 'string'
    || Object.keys(input).length !== 4) {
    return false;
  }

  const snoozedUntil = Date.parse(input.snoozed_until);

  return Number.isFinite(snoozedUntil) && snoozedUntil > Date.now();
}

const transactionToolDefinition = {
  name: 'money_assistant_transaction_read',
  label: 'Read Money Assistant Transaction',
  description: 'Read one Transaction by its Money Assistant identifier.',
  parameters: transactionParameters,
};

const manualTransactionPreparationToolDefinition = {
  name: 'money_assistant_transaction_prepare',
  label: 'Prepare Money Assistant Transaction',
  description: 'Validate and summarize one exact manual Transaction for owner confirmation.',
  parameters: manualTransactionPreparationParameters,
};

const manualTransactionConfirmationToolDefinition = {
  name: 'money_assistant_transaction_confirm',
  label: 'Confirm Money Assistant Transaction',
  description: 'Confirm one prepared manual Transaction from a new owner message.',
  parameters: manualTransactionConfirmationParameters,
};

const categoryReadToolDefinition = {
  name: 'money_assistant_category_read',
  label: 'Read Money Assistant Categories',
  description: 'Read the bounded two-level Category taxonomy and guidance.',
  parameters: categoryReadParameters,
};

const categoryMutationPreparationToolDefinition = {
  name: 'money_assistant_category_prepare',
  label: 'Prepare Money Assistant Categorization',
  description: 'Validate and summarize one Category lifecycle or Transaction assignment operation.',
  parameters: categoryMutationPreparationParameters,
};

const categoryMutationConfirmationToolDefinition = {
  name: 'money_assistant_category_confirm',
  label: 'Confirm Money Assistant Categorization',
  description: 'Confirm one prepared Categorization operation from a new owner message.',
  parameters: manualTransactionConfirmationParameters,
};

const reminderReadToolDefinition = {
  name: 'money_assistant_reminder_read',
  label: 'Read Money Assistant Reminder',
  description: 'Read the current Reminder issued by the fixed Money Assistant hook event.',
  parameters: reminderReadParameters,
};

const reminderResponseToolDefinition = {
  name: 'money_assistant_reminder_respond',
  label: 'Respond to Money Assistant Reminder',
  description: 'Acknowledge, snooze, or dismiss one Reminder from an admitted owner message.',
  parameters: reminderResponseParameters,
};

const plugin = defineToolPlugin({
  id: 'money-assistant',
  name: 'Money Assistant',
  description: 'Reads and confirms bounded Money Assistant financial operations.',
  configSchema: pluginConfigSchema,
  tools: (tool) => [
    tool({
      ...transactionToolDefinition,
      factory({ config, toolContext }) {
        if (!isBoundOwnerInteraction(toolContext, config)) {
          return null;
        }

        return {
          ...transactionToolDefinition,
          async execute(_toolCallId, params, signal) {
            const transactionId = (params as { transaction_id?: unknown }).transaction_id;

            if (!Number.isSafeInteger(transactionId) || Number(transactionId) < 1) {
              throw new Error('Money Assistant Transaction identifier is invalid.');
            }

            return executeCapability(
              'transaction.read',
              { transaction_id: Number(transactionId) },
              config,
              toolContext,
              signal,
            );
          },
        };
      },
    }),
    tool({
      ...manualTransactionPreparationToolDefinition,
      factory({ config, toolContext }) {
        if (!isBoundOwnerInteraction(toolContext, config)) {
          return null;
        }

        return {
          ...manualTransactionPreparationToolDefinition,
          async execute(_toolCallId, params, signal) {
            const input = params as Record<string, unknown>;
            const idempotencyKey = input.idempotency_key;
            const occurredOn = input.occurred_on;
            const amountMinor = input.amount_minor;
            const currency = input.currency;
            const kind = input.kind;
            const merchantDescription = input.merchant_description;

            if (typeof idempotencyKey !== 'string'
              || !new RegExp(UUID_PATTERN).test(idempotencyKey)
              || typeof occurredOn !== 'string'
              || !/^\d{4}-\d{2}-\d{2}$/.test(occurredOn)
              || !Number.isSafeInteger(amountMinor)
              || Number(amountMinor) < 1
              || (currency !== 'USD' && currency !== 'PEN')
              || (kind !== 'purchase' && kind !== 'refund')
              || typeof merchantDescription !== 'string'
              || merchantDescription.trim() === ''
              || merchantDescription.length > 255) {
              throw new Error('Money Assistant manual Transaction input is invalid.');
            }

            return executeCapability(
              'transaction.manual.prepare',
              {
                idempotency_key: idempotencyKey,
                occurred_on: occurredOn,
                amount_minor: Number(amountMinor),
                currency,
                kind,
                merchant_description: merchantDescription,
              },
              config,
              toolContext,
              signal,
            );
          },
        };
      },
    }),
    tool({
      ...manualTransactionConfirmationToolDefinition,
      factory({ config, toolContext }) {
        if (!isBoundOwnerInteraction(toolContext, config)) {
          return null;
        }

        return {
          ...manualTransactionConfirmationToolDefinition,
          async execute(_toolCallId, params, signal) {
            const input = params as Record<string, unknown>;
            const idempotencyKey = input.idempotency_key;
            const pendingOperationId = input.pending_operation_id;
            const pendingOperationRevision = input.pending_operation_revision;
            const payloadDigest = input.payload_digest;

            if (typeof idempotencyKey !== 'string'
              || !new RegExp(UUID_PATTERN).test(idempotencyKey)
              || typeof pendingOperationId !== 'string'
              || !new RegExp(UUID_PATTERN).test(pendingOperationId)
              || !Number.isSafeInteger(pendingOperationRevision)
              || Number(pendingOperationRevision) < 1
              || typeof payloadDigest !== 'string'
              || !new RegExp(SHA256_PATTERN).test(payloadDigest)) {
              throw new Error('Money Assistant Confirmation Grant input is invalid.');
            }

            return executeCapability(
              'transaction.manual.confirm',
              {
                idempotency_key: idempotencyKey,
                pending_operation_id: pendingOperationId,
                pending_operation_revision: Number(pendingOperationRevision),
                payload_digest: payloadDigest,
              },
              config,
              toolContext,
              signal,
            );
          },
        };
      },
    }),
    tool({
      ...categoryReadToolDefinition,
      factory({ config, toolContext }) {
        if (!isBoundOwnerInteraction(toolContext, config)) {
          return null;
        }

        return {
          ...categoryReadToolDefinition,
          async execute(_toolCallId, params, signal) {
            const input = params as Record<string, unknown>;

            if (!isPositiveSafeInteger(input.page)
              || !isPositiveSafeInteger(input.per_page)
              || Number(input.per_page) > 100) {
              throw new Error('Money Assistant Category page input is invalid.');
            }

            return executeCapability(
              'category.read',
              {
                page: Number(input.page),
                per_page: Number(input.per_page),
              },
              config,
              toolContext,
              signal,
            );
          },
        };
      },
    }),
    tool({
      ...categoryMutationPreparationToolDefinition,
      factory({ config, toolContext }) {
        if (!isBoundOwnerInteraction(toolContext, config)) {
          return null;
        }

        return {
          ...categoryMutationPreparationToolDefinition,
          async execute(_toolCallId, params, signal) {
            const input = params as Record<string, unknown>;

            if (!isCategoryMutationInput(input)) {
              throw new Error('Money Assistant Categorization input is invalid.');
            }

            return executeCapability(
              'category.mutation.prepare',
              input,
              config,
              toolContext,
              signal,
            );
          },
        };
      },
    }),
    tool({
      ...categoryMutationConfirmationToolDefinition,
      factory({ config, toolContext }) {
        if (!isBoundOwnerInteraction(toolContext, config)) {
          return null;
        }

        return {
          ...categoryMutationConfirmationToolDefinition,
          async execute(_toolCallId, params, signal) {
            const input = params as Record<string, unknown>;
            const idempotencyKey = input.idempotency_key;
            const pendingOperationId = input.pending_operation_id;
            const pendingOperationRevision = input.pending_operation_revision;
            const payloadDigest = input.payload_digest;

            if (typeof idempotencyKey !== 'string'
              || !new RegExp(UUID_PATTERN).test(idempotencyKey)
              || typeof pendingOperationId !== 'string'
              || !new RegExp(UUID_PATTERN).test(pendingOperationId)
              || !Number.isSafeInteger(pendingOperationRevision)
              || Number(pendingOperationRevision) < 1
              || typeof payloadDigest !== 'string'
              || !new RegExp(SHA256_PATTERN).test(payloadDigest)) {
              throw new Error('Money Assistant Confirmation Grant input is invalid.');
            }

            return executeCapability(
              'category.mutation.confirm',
              {
                idempotency_key: idempotencyKey,
                pending_operation_id: pendingOperationId,
                pending_operation_revision: Number(pendingOperationRevision),
                payload_digest: payloadDigest,
              },
              config,
              toolContext,
              signal,
            );
          },
        };
      },
    }),
    tool({
      ...reminderReadToolDefinition,
      factory({ config, toolContext }) {
        if (!isBoundReminderEventInteraction(toolContext, config)) {
          return null;
        }

        return {
          ...reminderReadToolDefinition,
          async execute(_toolCallId, params, signal) {
            const eventId = (params as { event_id?: unknown }).event_id;

            if (typeof eventId !== 'string'
              || !new RegExp(UUID_PATTERN).test(eventId)) {
              throw new Error('Money Assistant Reminder event identifier is invalid.');
            }

            return executeReminderEventCapability(
              'reminder.read',
              { event_id: eventId },
              eventId,
              config,
              toolContext,
              signal,
            );
          },
        };
      },
    }),
    tool({
      ...reminderResponseToolDefinition,
      factory({ config, toolContext }) {
        if (!isBoundOwnerInteraction(toolContext, config)) {
          return null;
        }

        return {
          ...reminderResponseToolDefinition,
          async execute(_toolCallId, params, signal) {
            const input = params as Record<string, unknown>;

            if (!isReminderResponseInput(input)) {
              throw new Error('Money Assistant Reminder response is invalid.');
            }

            return executeCapability(
              'reminder.respond',
              input,
              config,
              toolContext,
              signal,
            );
          },
        };
      },
    }),
  ],
});

const registerTool = plugin.register;

plugin.register = (api): void => {
  const config = api.pluginConfig as PluginConfiguration;

  api.on('message_received', (event, context) => {
    ownerMessageAdmissions.admit(event, context, config);
  });

  api.on('before_model_resolve', (event, context) => {
    const admission = admittedReminderEvent(
      event.prompt,
      context,
      config,
      Math.floor(Date.now() / 1000),
    );

    if (admission && context.sessionKey) {
      reminderEventAdmissions.admit(
        context.sessionKey,
        admission.eventId,
        admission.occurredAtSeconds,
      );
    }
  });

  api.on('before_agent_reply', (_event, context) => {
    if (context.agentId === config.agentId
      && context.sessionKey === REMINDER_HOOK_SESSION_KEY
      && consumeAlreadyDeliveredReminder(
        context.sessionKey,
        reminderEventAdmissions,
        Math.floor(Date.now() / 1000),
      )) {
      return {
        handled: true,
        reason: 'Money Assistant Reminder event was already delivered.',
      };
    }
  });

  api.on('message_sending', (event, context) => {
    if (shouldSuppressReminderDelivery(
      event,
      context,
      config,
      reminderEventAdmissions,
      Math.floor(Date.now() / 1000),
    )) {
      return {
        cancel: true,
        cancelReason: 'Money Assistant Reminder event was already delivered.',
      };
    }
  });

  api.on('message_sent', async (event, context) => {
    const sessionKey = event.sessionKey ?? context.sessionKey;

    if (!isBoundReminderChannelDelivery(event, context, config)
      || sessionKey === undefined) {
      return;
    }

    const admission = reminderEventAdmissions.takeFreshForSession(
      sessionKey,
      Math.floor(Date.now() / 1000),
    );

    if (!admission) {
      return;
    }

    try {
      await recordReminderChannelDelivery(admission, config);
    } catch (error) {
      reminderEventAdmissions.admit(
        sessionKey,
        admission.eventId,
        admission.occurredAtSeconds,
      );

      throw error;
    }
  });

  registerTool(api);
};

export default plugin;
