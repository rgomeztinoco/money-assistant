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

type CapabilityInput = Record<string, string | number>;

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

async function executeCapability(
  capability: string,
  input: CapabilityInput,
  config: PluginConfiguration,
  toolContext: TrustedToolContext,
  signal?: AbortSignal,
): Promise<{ content: Array<{ type: 'text'; text: string }>; details: unknown }> {
  signal?.throwIfAborted();

  const nowSeconds = Math.floor(Date.now() / 1000);
  const admission = ownerMessageAdmissions.freshForSession(
    toolContext.sessionKey,
    nowSeconds,
  );

  if (!admission) {
    throw new Error('Money Assistant owner message admission is unavailable.');
  }

  const body = capabilityRequestBody(capability, input, toolContext, admission);
  const timestamp = nowSeconds.toString();
  const response = await fetch(`${CAPABILITY_ORIGIN}${CAPABILITY_PATH}`, {
    method: 'POST',
    headers: authorizationHeaders(
      body,
      config.keyId,
      config.privateKey,
      timestamp,
      randomUUID(),
    ),
    body,
    signal,
  });

  if (!response.ok) {
    throw new Error(`Money Assistant rejected ${capability} (${response.status}).`);
  }

  const details = await response.json();

  return {
    content: [{ type: 'text', text: JSON.stringify(details) }],
    details,
  };
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

const plugin = defineToolPlugin({
  id: 'money-assistant',
  name: 'Money Assistant',
  description: 'Reads and confirms bounded Money Assistant Transaction operations.',
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
  ],
});

const registerTool = plugin.register;

plugin.register = (api): void => {
  const config = api.pluginConfig as BindingConfiguration;

  api.on('message_received', (event, context) => {
    ownerMessageAdmissions.admit(event, context, config);
  });

  registerTool(api);
};

export default plugin;
