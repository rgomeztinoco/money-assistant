import { createHash, createPrivateKey, randomUUID, sign } from 'node:crypto';
import type { KeyObject } from 'node:crypto';
import { readFileSync, realpathSync, statSync } from 'node:fs';
import { unlink } from 'node:fs/promises';
import { extname, isAbsolute, relative, resolve } from 'node:path';
import {
  loadAuthProfileStoreForRuntime,
  resolveAgentDir,
  resolveAuthProfileOrder,
} from 'openclaw/plugin-sdk/agent-runtime';
import { getMediaDir } from 'openclaw/plugin-sdk/media-runtime';
import { getSessionEntry } from 'openclaw/plugin-sdk/session-store-runtime';
import { defineToolPlugin } from 'openclaw/plugin-sdk/tool-plugin';
import { Type } from 'typebox';

const CAPABILITY_PATH = '/api/openclaw/v1/transport';
const REMINDER_HOOK_SESSION_KEY = 'hook:money-assistant:reminders';
const UUID_PATTERN =
  '^[0-9a-f]{8}-[0-9a-f]{4}-[1-8][0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$';
const SHA256_PATTERN = '^[a-f0-9]{64}$';
const APPROVED_RECEIPT_PROVIDER = 'openai';
const APPROVED_RECEIPT_MODEL = 'openai/gpt-5.6-sol';
const RECEIPT_CONTRACT_VERSION = 2;
const RECEIPT_CLEANUP_CEILING_SECONDS = 3600;
const RECEIPT_IMAGE_MAX_BYTES = 20 * 1024 * 1024;
const RECEIPT_POLICY_VERSION = 'openai-oauth-gpt-5.6-sol-v1';
const APPROVED_OPENAI_OAUTH_PROFILE = 'openai:money-assistant-oauth';
const PROPOSABLE_LINE_ITEM_ROLES = [
  'purchased_item',
  'tax',
  'discount',
  'tip',
  'fee',
  'rounding',
  'other_adjustment',
] as const;
const OWNER_LINE_ITEM_ROLES = [
  ...PROPOSABLE_LINE_ITEM_ROLES,
  'unidentified',
] as const;
export const RECEIPT_PRIVACY_DISCLOSURE =
  'Receipt processing uses the existing OpenAI OAuth account and only openai/gpt-5.6-sol. OpenAI OAuth has no published fixed retention ceiling. Before enabling receipts, disable account-wide model improvement and Codex full-environment training. Receipt interactions are never submitted as feedback. OpenClaw deletes local images after proposal submission or terminal failure, enforces a one-hour crash-cleanup ceiling, then attempts to delete the Telegram source and warns if manual removal is needed. Money Assistant retains only the opaque proposal identifier, receipt_photo source kind, processing time, actual provider/model, contract version, and structured financial proposal.';

const secretRefSchema = Type.Object(
  {
    source: Type.Union([
      Type.Literal('env'),
      Type.Literal('file'),
      Type.Literal('exec'),
    ]),
    provider: Type.String(),
    id: Type.String(),
  },
  { additionalProperties: false },
);

const resolvedSecretInputStringSchema = Type.Unsafe<string>({
  anyOf: [Type.String({ minLength: 1 }), secretRefSchema],
});

const pluginConfigSchema = Type.Object(
  {
    keyId: Type.String({ minLength: 1, maxLength: 128 }),
    capabilityOrigin: Type.String({
      pattern: '^http://127\\.0\\.0\\.1:[0-9]{1,5}$',
    }),
    privateKey: resolvedSecretInputStringSchema,
    agentId: Type.String({ minLength: 1, maxLength: 128 }),
    accountId: Type.String({ minLength: 1, maxLength: 128 }),
    conversationId: Type.String({ minLength: 1, maxLength: 128 }),
    ownerSenderId: Type.String({ minLength: 1, maxLength: 128 }),
    receiptMediaRoot: Type.String({ minLength: 1 }),
    receiptProcessingEnabled: Type.Boolean(),
    receiptDisclosureDelivered: Type.Boolean(),
    receiptDisclosureAccepted: Type.Boolean(),
    openAiModelImprovementDisabled: Type.Boolean(),
    codexFullEnvironmentTrainingDisabled: Type.Boolean(),
    openAiOAuthProfileId: Type.String({ minLength: 1 }),
    openAiOAuthCredentialVersion: Type.String({ minLength: 1 }),
    receiptPolicyVersion: Type.String({ minLength: 1 }),
    receiptConfirmedPolicyVersion: Type.String(),
    receiptConfirmedOAuthProfileId: Type.String(),
    receiptConfirmedOAuthCredentialVersion: Type.String(),
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
    amount_minor: Type.Integer({
      minimum: 1,
      maximum: Number.MAX_SAFE_INTEGER,
    }),
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

const categoryReadParameters = Type.Object(
  {
    page: Type.Integer({ minimum: 1 }),
    per_page: Type.Integer({ minimum: 1, maximum: 100 }),
  },
  { additionalProperties: false },
);

const financialExportPreparationParameters = Type.Object(
  {
    idempotency_key: Type.String({ pattern: UUID_PATTERN }),
  },
  { additionalProperties: false },
);

const financialDeletionPreparationParameters = Type.Object(
  {
    idempotency_key: Type.String({ pattern: UUID_PATTERN }),
    resource_type: Type.Union([
      Type.Literal('category'),
      Type.Literal('receipt_breakdown'),
    ]),
    resource_id: Type.Integer({ minimum: 1 }),
    expected_revision: Type.Integer({ minimum: 1 }),
  },
  { additionalProperties: false },
);

export function isFinancialExportPreparationInput(
  input: Record<string, unknown>,
): input is Record<string, unknown> & { idempotency_key: string } {
  return (
    typeof input.idempotency_key === 'string' &&
    new RegExp(UUID_PATTERN).test(input.idempotency_key)
  );
}

export function isFinancialDeletionPreparationInput(
  input: Record<string, unknown>,
): input is Record<string, unknown> & {
  idempotency_key: string;
  resource_type: 'category' | 'receipt_breakdown';
  resource_id: number;
  expected_revision: number;
} {
  return (
    isFinancialExportPreparationInput(input) &&
    (input.resource_type === 'category' ||
      input.resource_type === 'receipt_breakdown') &&
    isPositiveSafeInteger(input.resource_id) &&
    isPositiveSafeInteger(input.expected_revision)
  );
}

const categoryNameParameters = {
  name: Type.String({ minLength: 1, maxLength: 255 }),
  parent_id: Type.Union([Type.Integer({ minimum: 1 }), Type.Null()]),
  description: Type.Union([Type.String({ maxLength: 2000 }), Type.Null()]),
  examples: Type.Array(Type.String({ maxLength: 100 }), { maxItems: 20 }),
};

const categoryMutationPreparationParameters = Type.Union([
  Type.Object(
    {
      idempotency_key: Type.String({ pattern: UUID_PATTERN }),
      operation: Type.Literal('create'),
      ...categoryNameParameters,
    },
    { additionalProperties: false },
  ),
  Type.Object(
    {
      idempotency_key: Type.String({ pattern: UUID_PATTERN }),
      operation: Type.Literal('update'),
      category_id: Type.Integer({ minimum: 1 }),
      expected_revision: Type.Integer({ minimum: 1 }),
      ...categoryNameParameters,
    },
    { additionalProperties: false },
  ),
  Type.Object(
    {
      idempotency_key: Type.String({ pattern: UUID_PATTERN }),
      operation: Type.Union([
        Type.Literal('retire'),
        Type.Literal('reactivate'),
      ]),
      category_id: Type.Integer({ minimum: 1 }),
      expected_revision: Type.Integer({ minimum: 1 }),
    },
    { additionalProperties: false },
  ),
  Type.Object(
    {
      idempotency_key: Type.String({ pattern: UUID_PATTERN }),
      operation: Type.Literal('assign_transaction'),
      transaction_id: Type.Integer({ minimum: 1 }),
      expected_revision: Type.Integer({ minimum: 1 }),
      category_id: Type.Union([Type.Integer({ minimum: 1 }), Type.Null()]),
    },
    { additionalProperties: false },
  ),
]);

const reminderReadParameters = Type.Object(
  {
    event_id: Type.String({ pattern: UUID_PATTERN }),
  },
  { additionalProperties: false },
);

const reminderResponseParameters = Type.Union([
  Type.Object(
    {
      idempotency_key: Type.String({ pattern: UUID_PATTERN }),
      reminder_id: Type.Integer({ minimum: 1 }),
      action: Type.Union([
        Type.Literal('acknowledge'),
        Type.Literal('dismiss'),
      ]),
    },
    { additionalProperties: false },
  ),
  Type.Object(
    {
      idempotency_key: Type.String({ pattern: UUID_PATTERN }),
      reminder_id: Type.Integer({ minimum: 1 }),
      action: Type.Literal('snooze'),
      snoozed_until: Type.String({
        pattern:
          '^\\d{4}-\\d{2}-\\d{2}T\\d{2}:\\d{2}:\\d{2}(?:Z|[+-]\\d{2}:\\d{2})$',
      }),
    },
    { additionalProperties: false },
  ),
]);

const receiptProposalParameters = Type.Object(
  {
    transaction: Type.Object(
      {
        occurred_on: Type.String({ pattern: '^\\d{4}-\\d{2}-\\d{2}$' }),
        amount_minor: Type.Integer({
          minimum: 1,
          maximum: Number.MAX_SAFE_INTEGER,
        }),
        currency: Type.Union([Type.Literal('USD'), Type.Literal('PEN')]),
        kind: Type.Union([Type.Literal('purchase'), Type.Literal('refund')]),
        merchant_description: Type.String({
          minLength: 1,
          maxLength: 255,
        }),
      },
      { additionalProperties: false },
    ),
    line_items: Type.Array(
      Type.Object(
        {
          description: Type.String({ minLength: 1, maxLength: 255 }),
          role: Type.Union(
            PROPOSABLE_LINE_ITEM_ROLES.map((role) => Type.Literal(role)),
          ),
          quantity: Type.Union([
            Type.String({
              pattern: '^(?=.*[1-9])\\d+(?:\\.\\d{1,6})?$',
              maxLength: 64,
            }),
            Type.Null(),
          ]),
          unit_price_minor: Type.Union([
            Type.Integer({
              minimum: Number.MIN_SAFE_INTEGER,
              maximum: Number.MAX_SAFE_INTEGER,
            }),
            Type.Null(),
          ]),
          line_total_minor: Type.Union([
            Type.Integer({
              minimum: Number.MIN_SAFE_INTEGER,
              maximum: -1,
            }),
            Type.Integer({
              minimum: 1,
              maximum: Number.MAX_SAFE_INTEGER,
            }),
          ]),
        },
        { additionalProperties: false },
      ),
      { minItems: 1, maxItems: 200 },
    ),
  },
  { additionalProperties: false },
);

const receiptBreakdownMutationPreparationParameters = Type.Union([
  Type.Object(
    {
      idempotency_key: Type.String({ pattern: UUID_PATTERN }),
      operation: Type.Literal('create_draft'),
      transaction_id: Type.Integer({ minimum: 1 }),
      expected_transaction_revision: Type.Integer({ minimum: 1 }),
      line_items: Type.Array(
        Type.Object(
          {
            id: Type.Null(),
            description: Type.String({
              minLength: 1,
              maxLength: 255,
            }),
            role: Type.Optional(
              Type.Union(
                OWNER_LINE_ITEM_ROLES.map((role) => Type.Literal(role)),
              ),
            ),
            quantity: Type.Optional(
              Type.Union([
                Type.String({
                  pattern: '^(?=.*[1-9])\\d+(?:\\.\\d{1,6})?$',
                  maxLength: 64,
                }),
                Type.Null(),
              ]),
            ),
            unit_price_minor: Type.Optional(
              Type.Union([
                Type.Integer({
                  minimum: Number.MIN_SAFE_INTEGER,
                  maximum: Number.MAX_SAFE_INTEGER,
                }),
                Type.Null(),
              ]),
            ),
            related_line_item_id: Type.Optional(Type.Null()),
            line_total_minor: Type.Union([
              Type.Integer({
                minimum: Number.MIN_SAFE_INTEGER,
                maximum: -1,
              }),
              Type.Integer({
                minimum: 1,
                maximum: Number.MAX_SAFE_INTEGER,
              }),
            ]),
            category_id: Type.Union([
              Type.Integer({ minimum: 1 }),
              Type.Null(),
            ]),
          },
          { additionalProperties: false },
        ),
        { minItems: 1, maxItems: 200 },
      ),
    },
    { additionalProperties: false },
  ),
  Type.Object(
    {
      idempotency_key: Type.String({ pattern: UUID_PATTERN }),
      operation: Type.Literal('update_draft'),
      receipt_breakdown_id: Type.Integer({ minimum: 1 }),
      expected_revision: Type.Integer({ minimum: 1 }),
      line_items: Type.Array(
        Type.Object(
          {
            id: Type.Union([
              Type.String({ pattern: UUID_PATTERN }),
              Type.Null(),
            ]),
            description: Type.String({
              minLength: 1,
              maxLength: 255,
            }),
            role: Type.Optional(
              Type.Union(
                OWNER_LINE_ITEM_ROLES.map((role) => Type.Literal(role)),
              ),
            ),
            quantity: Type.Optional(
              Type.Union([
                Type.String({
                  pattern: '^(?=.*[1-9])\\d+(?:\\.\\d{1,6})?$',
                  maxLength: 64,
                }),
                Type.Null(),
              ]),
            ),
            unit_price_minor: Type.Optional(
              Type.Union([
                Type.Integer({
                  minimum: Number.MIN_SAFE_INTEGER,
                  maximum: Number.MAX_SAFE_INTEGER,
                }),
                Type.Null(),
              ]),
            ),
            related_line_item_id: Type.Optional(
              Type.Union([Type.String({ pattern: UUID_PATTERN }), Type.Null()]),
            ),
            line_total_minor: Type.Union([
              Type.Integer({
                minimum: Number.MIN_SAFE_INTEGER,
                maximum: -1,
              }),
              Type.Integer({
                minimum: 1,
                maximum: Number.MAX_SAFE_INTEGER,
              }),
            ]),
            category_id: Type.Union([
              Type.Integer({ minimum: 1 }),
              Type.Null(),
            ]),
          },
          { additionalProperties: false },
        ),
        { minItems: 1, maxItems: 200 },
      ),
    },
    { additionalProperties: false },
  ),
  Type.Object(
    {
      idempotency_key: Type.String({ pattern: UUID_PATTERN }),
      operation: Type.Literal('confirm_draft'),
      receipt_breakdown_id: Type.Integer({ minimum: 1 }),
      expected_revision: Type.Integer({ minimum: 1 }),
    },
    { additionalProperties: false },
  ),
]);

type BindingConfiguration = {
  agentId: string;
  accountId: string;
  conversationId: string;
  ownerSenderId: string;
};

type CapabilityConfiguration = BindingConfiguration & {
  keyId: string;
  capabilityOrigin: string;
  privateKey: string;
};

type PluginConfiguration = CapabilityConfiguration & {
  receiptMediaRoot: string;
  receiptProcessingEnabled: boolean;
  receiptDisclosureDelivered: boolean;
  receiptDisclosureAccepted: boolean;
  openAiModelImprovementDisabled: boolean;
  codexFullEnvironmentTrainingDisabled: boolean;
  openAiOAuthProfileId: string;
  openAiOAuthCredentialVersion: string;
  receiptPolicyVersion: string;
  receiptConfirmedPolicyVersion: string;
  receiptConfirmedOAuthProfileId: string;
  receiptConfirmedOAuthCredentialVersion: string;
};

type ReceiptPolicyConfiguration = Pick<
  PluginConfiguration,
  | 'receiptProcessingEnabled'
  | 'receiptDisclosureDelivered'
  | 'receiptDisclosureAccepted'
  | 'openAiModelImprovementDisabled'
  | 'codexFullEnvironmentTrainingDisabled'
  | 'openAiOAuthProfileId'
  | 'openAiOAuthCredentialVersion'
  | 'receiptPolicyVersion'
  | 'receiptConfirmedPolicyVersion'
  | 'receiptConfirmedOAuthProfileId'
  | 'receiptConfirmedOAuthCredentialVersion'
>;

type AdmittedOwnerMessage = {
  sessionKey: string;
  messageId: string;
  occurredAtSeconds: number;
};

type ReceiptBindingConfiguration = BindingConfiguration & {
  receiptMediaRoot: string;
};

type ValidatedReceiptPhoto = AdmittedOwnerMessage & {
  runId?: string;
  mediaPath: string;
};

type AdmittedReceiptPhoto = ValidatedReceiptPhoto & {
  proposalId: string;
  interactionId: string;
  processable: boolean;
  cleanupPaths: string[];
  provider?: string;
  model?: string;
  cleanupTimer?: unknown;
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
  runId?: string;
  metadata?: Record<string, unknown>;
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

function normalizedTelegramPeerId(
  peerId: string | undefined,
): string | undefined {
  if (!peerId) {
    return undefined;
  }

  const normalizedPeerId = peerId.startsWith('telegram:')
    ? peerId.slice('telegram:'.length)
    : peerId;

  if (
    normalizedPeerId === '' ||
    normalizedPeerId.startsWith('telegram:')
  ) {
    return undefined;
  }

  return normalizedPeerId;
}

function matchesTelegramPeer(
  peerId: string | undefined,
  configuredPeerId: string,
): boolean {
  const normalizedPeerId = normalizedTelegramPeerId(peerId);
  const normalizedConfiguredPeerId =
    normalizedTelegramPeerId(configuredPeerId);

  return (
    normalizedPeerId !== undefined &&
    normalizedPeerId === normalizedConfiguredPeerId
  );
}

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
  const occurredAtSeconds =
    event.timestamp === undefined ? null : timestampInSeconds(event.timestamp);

  if (
    context.channelId !== 'telegram' ||
    context.accountId !== config.accountId ||
    !matchesTelegramPeer(context.conversationId, config.conversationId) ||
    !matchesTelegramPeer(
      event.senderId ?? context.senderId,
      config.ownerSenderId,
    ) ||
    !sessionKey ||
    !messageId ||
    occurredAtSeconds === null
  ) {
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

  clear(sessionKey: string | undefined): void {
    if (sessionKey) {
      this.messages.delete(sessionKey);
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

export function receiptProcessingReady(
  config: ReceiptPolicyConfiguration,
): boolean {
  return (
    config.receiptProcessingEnabled &&
    config.receiptDisclosureDelivered &&
    config.receiptDisclosureAccepted &&
    config.openAiModelImprovementDisabled &&
    config.codexFullEnvironmentTrainingDisabled &&
    config.openAiOAuthProfileId === APPROVED_OPENAI_OAUTH_PROFILE &&
    config.openAiOAuthCredentialVersion !== '' &&
    config.receiptPolicyVersion === RECEIPT_POLICY_VERSION &&
    config.receiptConfirmedPolicyVersion === config.receiptPolicyVersion &&
    config.receiptConfirmedOAuthProfileId === config.openAiOAuthProfileId &&
    config.receiptConfirmedOAuthCredentialVersion ===
      config.openAiOAuthCredentialVersion
  );
}

function isRecord(value: unknown): value is Record<string, unknown> {
  return typeof value === 'object' && value !== null && !Array.isArray(value);
}

export function receiptRuntimePolicyReady(
  runtimeConfig: unknown,
  agentId: string,
): boolean {
  if (
    !isRecord(runtimeConfig) ||
    !isRecord(runtimeConfig.auth) ||
    !isRecord(runtimeConfig.auth.profiles) ||
    !isRecord(runtimeConfig.auth.order) ||
    !isRecord(runtimeConfig.commands) ||
    !isRecord(runtimeConfig.agents) ||
    !isRecord(runtimeConfig.agents.defaults)
  ) {
    return false;
  }

  const profiles = runtimeConfig.auth.profiles;
  const approvedProfile = profiles[APPROVED_OPENAI_OAUTH_PROFILE];
  const openAiProfiles = Object.entries(profiles).filter(
    ([, profile]) =>
      isRecord(profile) && profile.provider === APPROVED_RECEIPT_PROVIDER,
  );
  const defaultAgentPolicy = runtimeConfig.agents.defaults;
  const models = defaultAgentPolicy.models;
  const model = defaultAgentPolicy.model;
  const imageModel = defaultAgentPolicy.imageModel;
  const targetAgent = Array.isArray(runtimeConfig.agents.list)
    ? runtimeConfig.agents.list.find(
        (agent) => isRecord(agent) && agent.id === agentId,
      )
    : undefined;
  const targetAgentModel = isRecord(targetAgent)
    ? targetAgent.model
    : undefined;

  return (
    isRecord(approvedProfile) &&
    approvedProfile.provider === APPROVED_RECEIPT_PROVIDER &&
    approvedProfile.mode === 'oauth' &&
    openAiProfiles.length === 1 &&
    Array.isArray(runtimeConfig.auth.order.openai) &&
    runtimeConfig.auth.order.openai.length === 1 &&
    runtimeConfig.auth.order.openai[0] === APPROVED_OPENAI_OAUTH_PROFILE &&
    runtimeConfig.commands.text === false &&
    runtimeConfig.commands.native === false &&
    isRecord(models) &&
    Object.keys(models).length === 1 &&
    isRecord(models[APPROVED_RECEIPT_MODEL]) &&
    isRecord(model) &&
    model.primary === APPROVED_RECEIPT_MODEL &&
    Array.isArray(model.fallbacks) &&
    model.fallbacks.length === 0 &&
    isRecord(imageModel) &&
    imageModel.primary === APPROVED_RECEIPT_MODEL &&
    Array.isArray(imageModel.fallbacks) &&
    imageModel.fallbacks.length === 0 &&
    isRecord(targetAgent) &&
    isRecord(targetAgentModel) &&
    targetAgentModel.primary === APPROVED_RECEIPT_MODEL &&
    Array.isArray(targetAgentModel.fallbacks) &&
    targetAgentModel.fallbacks.length === 0
  );
}

export function receiptEffectiveAuthStateReady(
  profiles: Record<string, unknown>,
  resolvedOrder: string[],
  sessionEntry?: unknown,
): boolean {
  const credential = profiles[APPROVED_OPENAI_OAUTH_PROFILE];
  const sessionProfile = isRecord(sessionEntry)
    ? sessionEntry.authProfileOverride
    : undefined;

  return (
    isRecord(credential) &&
    credential.type === 'oauth' &&
    resolvedOrder.length === 1 &&
    resolvedOrder[0] === APPROVED_OPENAI_OAUTH_PROFILE &&
    (sessionProfile === undefined ||
      sessionProfile === APPROVED_OPENAI_OAUTH_PROFILE)
  );
}

function receiptEffectiveAuthReady(
  runtimeConfig: Parameters<typeof resolveAgentDir>[0],
  agentId: string,
  sessionKey: string | undefined,
): boolean {
  try {
    const agentDir = resolveAgentDir(runtimeConfig, agentId);
    const store = loadAuthProfileStoreForRuntime(agentDir, {
      allowKeychainPrompt: false,
      config: runtimeConfig,
      readOnly: true,
    });
    const resolvedOrder = resolveAuthProfileOrder({
      cfg: runtimeConfig,
      store,
      provider: APPROVED_RECEIPT_PROVIDER,
    });
    const sessionEntry = sessionKey
      ? getSessionEntry({
          agentId,
          sessionKey,
          readConsistency: 'latest',
        })
      : undefined;

    return receiptEffectiveAuthStateReady(
      store.profiles,
      resolvedOrder,
      sessionEntry,
    );
  } catch {
    return false;
  }
}

function oneMediaValue(
  metadata: Record<string, unknown>,
  singular: string,
  plural: string,
): string[] {
  const multiple = metadata[plural];

  if (Array.isArray(multiple)) {
    return multiple.filter(
      (value): value is string => typeof value === 'string',
    );
  }

  return typeof metadata[singular] === 'string' ? [metadata[singular]] : [];
}

function pathIsInsideRoot(path: string, root: string): boolean {
  const relativePath = relative(resolve(root), resolve(path));

  return (
    relativePath !== '' &&
    relativePath !== '..' &&
    !relativePath.startsWith(
      `..${process.platform === 'win32' ? '\\' : '/'}`,
    ) &&
    !isAbsolute(relativePath)
  );
}

function safeReceiptPath(path: string, root: string): string | null {
  try {
    const realRoot = realpathSync(root);
    const realPath = realpathSync(path);

    return pathIsInsideRoot(realPath, realRoot) && statSync(realPath).isFile()
      ? realPath
      : null;
  } catch {
    return null;
  }
}

function hasJpegStructure(bytes: Buffer): boolean {
  if (
    bytes.length < 12 ||
    bytes[0] !== 0xff ||
    bytes[1] !== 0xd8 ||
    bytes[bytes.length - 2] !== 0xff ||
    bytes[bytes.length - 1] !== 0xd9
  ) {
    return false;
  }

  let offset = 2;
  let hasFrame = false;

  while (offset < bytes.length - 2) {
    if (bytes[offset] !== 0xff) {
      return false;
    }

    while (bytes[offset] === 0xff) {
      offset++;
    }

    const marker = bytes[offset++];

    if (marker === undefined || marker === 0x00 || marker === 0xd9) {
      return false;
    }

    if (marker === 0x01 || (marker >= 0xd0 && marker <= 0xd7)) {
      continue;
    }

    if (offset + 2 > bytes.length - 2) {
      return false;
    }

    const segmentLength = bytes.readUInt16BE(offset);
    const segmentEnd = offset + segmentLength;

    if (segmentLength < 2 || segmentEnd > bytes.length - 2) {
      return false;
    }

    if (
      (marker >= 0xc0 && marker <= 0xc3) ||
      (marker >= 0xc5 && marker <= 0xc7) ||
      (marker >= 0xc9 && marker <= 0xcb) ||
      (marker >= 0xcd && marker <= 0xcf)
    ) {
      if (
        segmentLength < 8 ||
        bytes.readUInt16BE(offset + 3) === 0 ||
        bytes.readUInt16BE(offset + 5) === 0
      ) {
        return false;
      }

      hasFrame = true;
    }

    if (marker === 0xda) {
      return hasFrame && segmentLength >= 6 && segmentEnd < bytes.length - 2;
    }

    offset = segmentEnd;
  }

  return false;
}

function hasPngStructure(bytes: Buffer): boolean {
  const signature = Buffer.from([
    0x89, 0x50, 0x4e, 0x47, 0x0d, 0x0a, 0x1a, 0x0a,
  ]);

  if (bytes.length < 45 || !bytes.subarray(0, 8).equals(signature)) {
    return false;
  }

  let offset = 8;
  let hasHeader = false;
  let hasImageData = false;

  while (offset + 12 <= bytes.length) {
    const chunkLength = bytes.readUInt32BE(offset);
    const chunkType = bytes.subarray(offset + 4, offset + 8).toString('ascii');
    const chunkEnd = offset + 12 + chunkLength;

    if (chunkEnd > bytes.length) {
      return false;
    }

    if (!hasHeader) {
      if (
        chunkType !== 'IHDR' ||
        chunkLength !== 13 ||
        bytes.readUInt32BE(offset + 8) === 0 ||
        bytes.readUInt32BE(offset + 12) === 0
      ) {
        return false;
      }

      hasHeader = true;
    } else if (chunkType === 'IDAT') {
      hasImageData = hasImageData || chunkLength > 0;
    } else if (chunkType === 'IEND') {
      return chunkLength === 0 && hasImageData && chunkEnd === bytes.length;
    }

    offset = chunkEnd;
  }

  return false;
}

function hasWebpStructure(bytes: Buffer): boolean {
  if (
    bytes.length < 26 ||
    bytes.subarray(0, 4).toString('ascii') !== 'RIFF' ||
    bytes.subarray(8, 12).toString('ascii') !== 'WEBP' ||
    bytes.readUInt32LE(4) !== bytes.length - 8
  ) {
    return false;
  }

  let offset = 12;
  let hasImageChunk = false;

  while (offset + 8 <= bytes.length) {
    const chunkType = bytes.subarray(offset, offset + 4).toString('ascii');
    const chunkLength = bytes.readUInt32LE(offset + 4);
    const dataOffset = offset + 8;
    const chunkEnd = dataOffset + chunkLength;
    const paddedChunkEnd = chunkEnd + (chunkLength % 2);

    if (chunkEnd > bytes.length || paddedChunkEnd > bytes.length) {
      return false;
    }

    if (chunkType === 'VP8 ') {
      hasImageChunk =
        chunkLength >= 10 &&
        bytes
          .subarray(dataOffset + 3, dataOffset + 6)
          .equals(Buffer.from([0x9d, 0x01, 0x2a]));
    } else if (chunkType === 'VP8L') {
      hasImageChunk = chunkLength >= 5 && bytes[dataOffset] === 0x2f;
    }

    offset = paddedChunkEnd;
  }

  return hasImageChunk && offset === bytes.length;
}

export function inspectReceiptImage(
  path: string,
  root: string,
  declaredMimeType: string,
): string | null {
  const realPath = safeReceiptPath(path, root);

  if (!realPath) {
    return null;
  }

  const size = statSync(realPath).size;

  if (size < 12 || size > RECEIPT_IMAGE_MAX_BYTES) {
    return null;
  }

  const bytes = readFileSync(realPath);

  const extension = extname(realPath).toLowerCase();
  const normalizedMimeType =
    declaredMimeType.toLowerCase() === 'image/jpg'
      ? 'image/jpeg'
      : declaredMimeType.toLowerCase();
  const isJpeg = hasJpegStructure(bytes);
  const isPng = hasPngStructure(bytes);
  const isWebp = hasWebpStructure(bytes);

  if (
    (isJpeg &&
      normalizedMimeType === 'image/jpeg' &&
      ['.jpg', '.jpeg'].includes(extension)) ||
    (isPng && normalizedMimeType === 'image/png' && extension === '.png') ||
    (isWebp && normalizedMimeType === 'image/webp' && extension === '.webp')
  ) {
    return realPath;
  }

  return null;
}

export function admittedReceiptPhoto(
  event: InboundMessage,
  context: InboundMessageContext,
  config: ReceiptBindingConfiguration,
  inspectImage: typeof inspectReceiptImage = inspectReceiptImage,
): ValidatedReceiptPhoto | null {
  const ownerMessage = admittedOwnerMessage(event, context, config);
  const metadata = event.metadata;

  if (!ownerMessage || !metadata) {
    return null;
  }

  const mediaPaths = oneMediaValue(metadata, 'mediaPath', 'mediaPaths');
  const mediaTypes = oneMediaValue(metadata, 'mediaType', 'mediaTypes');

  if (
    mediaPaths.length !== 1 ||
    mediaTypes.length !== 1 ||
    !mediaTypes[0]?.toLowerCase().startsWith('image/')
  ) {
    return null;
  }

  const mediaPath = inspectImage(
    mediaPaths[0] as string,
    config.receiptMediaRoot,
    mediaTypes[0] as string,
  );

  if (!mediaPath) {
    return null;
  }

  return {
    ...ownerMessage,
    ...(event.runId ? { runId: event.runId } : {}),
    mediaPath,
  };
}

export function isApprovedReceiptModel(
  provider: string,
  model: string,
): boolean {
  const normalizedModel = model.startsWith(`${provider}/`)
    ? model
    : `${provider}/${model}`;

  return (
    provider === APPROVED_RECEIPT_PROVIDER &&
    normalizedModel === APPROVED_RECEIPT_MODEL
  );
}

type ReceiptAdmissionDependencies = {
  removeFile: (path: string) => Promise<void>;
  setTimer: (callback: () => Promise<void> | void, delay: number) => unknown;
  clearTimer: (timer: unknown) => void;
  createProposalId: () => string;
  createInteractionId: () => string;
  nowSeconds: () => number;
  inspectImage: typeof inspectReceiptImage;
  safePath: typeof safeReceiptPath;
  managedMediaRoot: () => string;
};

const defaultReceiptAdmissionDependencies: ReceiptAdmissionDependencies = {
  async removeFile(path) {
    try {
      await unlink(path);
    } catch (error) {
      if ((error as NodeJS.ErrnoException).code !== 'ENOENT') {
        throw error;
      }
    }
  },
  setTimer(callback, delay) {
    const timer = setTimeout(() => {
      void Promise.resolve(callback()).catch(() => {});
    }, delay);
    timer.unref();

    return timer;
  },
  clearTimer(timer) {
    clearTimeout(timer as ReturnType<typeof setTimeout>);
  },
  createProposalId: randomUUID,
  createInteractionId: randomUUID,
  nowSeconds: () => Math.floor(Date.now() / 1000),
  inspectImage: inspectReceiptImage,
  safePath: safeReceiptPath,
  managedMediaRoot: () => resolve(getMediaDir(), 'inbound'),
};

export class ReceiptPhotoAdmissions {
  private readonly photos = new Map<string, AdmittedReceiptPhoto>();
  private readonly pendingSourceDeletions = new Map<
    string,
    AdmittedReceiptPhoto[]
  >();
  private readonly rejectedRuns = new Map<string, string>();
  private readonly rejectedSessionsWithoutRun = new Map<string, number>();
  private readonly identitiesBySourceMessage = new Map<
    string,
    {
      proposalId: string;
      interactionId: string;
      expiresAtSeconds: number;
    }
  >();
  private readonly sensitiveSessions = new Set<string>();
  private readonly boundResponseDeliveredSessions = new Set<string>();

  constructor(
    private readonly dependencies: ReceiptAdmissionDependencies = defaultReceiptAdmissionDependencies,
  ) {}

  admit(
    event: InboundMessage,
    context: InboundMessageContext,
    config: ReceiptBindingConfiguration,
  ): boolean {
    const ownerMessage = admittedOwnerMessage(event, context, config);
    const metadata = event.metadata;

    if (!ownerMessage || !metadata) {
      return false;
    }

    const mediaPaths = oneMediaValue(metadata, 'mediaPath', 'mediaPaths');
    const mediaTypes = oneMediaValue(metadata, 'mediaType', 'mediaTypes');

    if (mediaPaths.length === 0 && mediaTypes.length === 0) {
      return false;
    }

    const admitted = admittedReceiptPhoto(
      event,
      context,
      config,
      this.dependencies.inspectImage,
    );

    const cleanupRoots = [
      config.receiptMediaRoot,
      this.dependencies.managedMediaRoot(),
    ];
    const cleanupPaths = admitted
      ? [admitted.mediaPath]
      : mediaPaths
          .map(
            (path) =>
              cleanupRoots
                .map((root) => this.dependencies.safePath(path, root))
                .find((safePath) => safePath !== null) ?? null,
          )
          .filter((path): path is string => path !== null);
    const existing = this.photos.get(ownerMessage.sessionKey);

    if (existing?.messageId === ownerMessage.messageId) {
      for (const path of cleanupPaths) {
        if (!existing.cleanupPaths.includes(path)) {
          void this.dependencies.removeFile(path).catch(() => {});
        }
      }

      return true;
    }

    const nowSeconds = this.dependencies.nowSeconds();
    const sourceMessageKey = `${ownerMessage.sessionKey}\u0000${ownerMessage.messageId}`;

    for (const [key, identity] of this.identitiesBySourceMessage) {
      if (identity.expiresAtSeconds < nowSeconds) {
        this.identitiesBySourceMessage.delete(key);
      }
    }

    const identity = this.identitiesBySourceMessage.get(sourceMessageKey) ?? {
      proposalId: this.dependencies.createProposalId(),
      interactionId: this.dependencies.createInteractionId(),
      expiresAtSeconds:
        ownerMessage.occurredAtSeconds + RECEIPT_CLEANUP_CEILING_SECONDS,
    };
    this.identitiesBySourceMessage.set(sourceMessageKey, identity);

    const photo: AdmittedReceiptPhoto = {
      ...ownerMessage,
      ...(event.runId ? { runId: event.runId } : {}),
      mediaPath: admitted?.mediaPath ?? cleanupPaths[0] ?? '',
      proposalId: identity.proposalId,
      interactionId: identity.interactionId,
      processable: admitted !== null,
      cleanupPaths,
    };

    if (existing) {
      this.queueSourceDeletion(photo);
      this.sensitiveSessions.add(ownerMessage.sessionKey);

      if (photo.runId) {
        this.rejectedRuns.set(photo.runId, ownerMessage.sessionKey);
      } else {
        const rejectedUntil = Math.max(
          this.rejectedSessionsWithoutRun.get(ownerMessage.sessionKey) ?? 0,
          ownerMessage.occurredAtSeconds + RECEIPT_CLEANUP_CEILING_SECONDS,
        );
        this.rejectedSessionsWithoutRun.set(
          ownerMessage.sessionKey,
          rejectedUntil,
        );
      }

      void this.removeLocalImage(photo).catch(() => {});

      return true;
    }

    const remainingSeconds = Math.max(
      0,
      ownerMessage.occurredAtSeconds +
        RECEIPT_CLEANUP_CEILING_SECONDS -
        nowSeconds,
    );
    photo.cleanupTimer = this.dependencies.setTimer(
      () => this.expire(ownerMessage.sessionKey, photo.proposalId),
      remainingSeconds * 1000,
    );

    this.photos.set(ownerMessage.sessionKey, photo);
    this.sensitiveSessions.add(ownerMessage.sessionKey);

    return true;
  }

  freshForSession(
    sessionKey: string | undefined,
    nowSeconds: number,
  ): AdmittedReceiptPhoto | null {
    const photo = this.photos.get(sessionKey ?? '');

    if (!photo) {
      return null;
    }

    const ageInSeconds = nowSeconds - photo.occurredAtSeconds;

    return photo.processable && ageInSeconds >= 0 && ageInSeconds <= 1800
      ? photo
      : null;
  }

  freshForRun(
    runId: string | undefined,
    sessionKey: string | undefined,
    nowSeconds: number,
  ): AdmittedReceiptPhoto | null {
    const photo = this.photos.get(sessionKey ?? '');

    if (!photo || (photo.runId !== undefined && photo.runId !== runId)) {
      return null;
    }

    if (runId !== undefined) {
      photo.runId = runId;
    }

    return this.freshForSession(sessionKey, nowSeconds);
  }

  hasConflictingRun(
    runId: string | undefined,
    sessionKey: string | undefined,
  ): boolean {
    const photo = this.photos.get(sessionKey ?? '');

    return photo?.runId !== undefined && photo.runId !== runId;
  }

  consumeRejectedRun(
    runId: string | undefined,
    sessionKey: string | undefined,
  ): boolean {
    const key = sessionKey ?? '';

    if (runId !== undefined && this.rejectedRuns.get(runId) === key) {
      this.rejectedRuns.delete(runId);

      return true;
    }

    const rejectedUntil = this.rejectedSessionsWithoutRun.get(key);

    if (
      rejectedUntil !== undefined &&
      rejectedUntil >= this.dependencies.nowSeconds()
    ) {
      return true;
    }

    if (rejectedUntil !== undefined) {
      this.rejectedSessionsWithoutRun.delete(key);
    }

    return false;
  }

  clearRejectedRun(runId: string | undefined): void {
    if (runId !== undefined) {
      this.rejectedRuns.delete(runId);
    }
  }

  activeForSession(
    sessionKey: string | undefined,
  ): AdmittedReceiptPhoto | null {
    return this.photos.get(sessionKey ?? '') ?? null;
  }

  recordActualModel(runId: string, provider: string, model: string): boolean {
    const photo = [...this.photos.values()].find(
      (candidate) => candidate.runId === runId,
    );

    if (
      !photo ||
      !photo.processable ||
      !isApprovedReceiptModel(provider, model)
    ) {
      return false;
    }

    photo.provider = provider;
    photo.model = model.startsWith(`${provider}/`)
      ? model
      : `${provider}/${model}`;

    return true;
  }

  isSensitiveSession(sessionKey: string | undefined): boolean {
    return this.sensitiveSessions.has(sessionKey ?? '');
  }

  markBoundResponseDelivered(sessionKey: string | undefined): void {
    const key = sessionKey ?? '';

    if (this.sensitiveSessions.has(key)) {
      this.boundResponseDeliveredSessions.add(key);
    }
  }

  async finishForSession(sessionKey: string | undefined): Promise<void> {
    const key = sessionKey ?? '';
    const photo = this.photos.get(key);

    if (!photo) {
      return;
    }

    await this.finishAdmission(photo);
  }

  async finishAdmission(photo: AdmittedReceiptPhoto): Promise<void> {
    if (this.photos.get(photo.sessionKey) !== photo) {
      return;
    }

    this.photos.delete(photo.sessionKey);

    try {
      await this.removeLocalImage(photo);
    } finally {
      this.queueSourceDeletion(photo);
    }
  }

  async finishForRun(
    runId: string | undefined,
    sessionKey?: string,
  ): Promise<AdmittedReceiptPhoto[]> {
    const photo = runId
      ? [...this.photos.values()].find((candidate) => candidate.runId === runId)
      : this.photos.get(sessionKey ?? '');

    if (photo) {
      await this.finishAdmission(photo);
    }

    const key = photo?.sessionKey ?? sessionKey ?? '';

    return this.boundResponseDeliveredSessions.has(key)
      ? this.takePendingSourceDeletions(key)
      : [];
  }

  takePendingSourceDeletions(
    sessionKey: string | undefined,
  ): AdmittedReceiptPhoto[] {
    const key = sessionKey ?? '';
    const photos = this.pendingSourceDeletions.get(key) ?? [];

    if (photos.length > 0) {
      this.pendingSourceDeletions.delete(key);
      this.boundResponseDeliveredSessions.delete(key);

      if (!this.photos.has(key)) {
        this.sensitiveSessions.delete(key);
      }
    }

    return photos;
  }

  private async expire(sessionKey: string, proposalId: string): Promise<void> {
    const photo = this.photos.get(sessionKey);

    if (!photo || photo.proposalId !== proposalId) {
      return;
    }

    await this.finishForSession(sessionKey);
  }

  private async removeLocalImage(photo: AdmittedReceiptPhoto): Promise<void> {
    if (photo.cleanupTimer !== undefined) {
      this.dependencies.clearTimer(photo.cleanupTimer);
      photo.cleanupTimer = undefined;
    }

    for (const path of photo.cleanupPaths) {
      await this.dependencies.removeFile(path);
    }
  }

  private queueSourceDeletion(photo: AdmittedReceiptPhoto): void {
    const pending = this.pendingSourceDeletions.get(photo.sessionKey) ?? [];

    if (!pending.some((candidate) => candidate.messageId === photo.messageId)) {
      pending.push(photo);
      this.pendingSourceDeletions.set(photo.sessionKey, pending);
    }
  }
}

type ReceiptAdmissionBlockCategory =
  'receipt_photo_concurrent' | 'receipt_photo_invalid' | 'receipt_photo_stale';

export function receiptAdmissionBlockCategory(
  admissions: ReceiptPhotoAdmissions,
  runId: string | undefined,
  sessionKey: string | undefined,
  nowSeconds: number,
): ReceiptAdmissionBlockCategory | null {
  if (
    admissions.consumeRejectedRun(runId, sessionKey) ||
    admissions.hasConflictingRun(runId, sessionKey)
  ) {
    return 'receipt_photo_concurrent';
  }

  const activeReceiptPhoto = admissions.activeForSession(sessionKey);
  const receiptPhoto = admissions.freshForRun(runId, sessionKey, nowSeconds);

  if (activeReceiptPhoto && !activeReceiptPhoto.processable) {
    return 'receipt_photo_invalid';
  }

  return activeReceiptPhoto && !receiptPhoto ? 'receipt_photo_stale' : null;
}

export function shouldBlockReceiptMessageWrite(
  admissions: ReceiptPhotoAdmissions,
  eventSessionKey: string | undefined,
  contextSessionKey: string | undefined,
): boolean {
  return admissions.isSensitiveSession(eventSessionKey ?? contextSessionKey);
}

const receiptPhotoAdmissions = new ReceiptPhotoAdmissions();

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

    return (
      admissions.find((admission) => {
        const ageInSeconds = nowSeconds - admission.occurredAtSeconds;

        return (
          admission.eventId === eventId &&
          ageInSeconds >= 0 &&
          ageInSeconds <= 1800
        );
      }) ?? null
    );
  }

  markAlreadyDelivered(sessionKey: string, eventId: string): void {
    const admission = this.events
      .get(sessionKey)
      ?.find((candidate) => candidate.eventId === eventId);

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

  return (
    toolContext.agentId === config.agentId &&
    toolContext.messageChannel === 'telegram' &&
    toolContext.agentAccountId === config.accountId &&
    matchesTelegramPeer(
      toolContext.requesterSenderId,
      config.ownerSenderId,
    ) &&
    toolContext.sessionId !== undefined &&
    toolContext.deliveryContext?.channel === 'telegram' &&
    toolContext.deliveryContext.accountId === config.accountId &&
    matchesTelegramPeer(
      toolContext.deliveryContext.to,
      config.conversationId,
    )
  );
}

export function isBoundReminderEventInteraction(
  toolContext: TrustedToolContext,
  config: BindingConfiguration,
): boolean {
  return (
    toolContext.agentId === config.agentId &&
    isReminderHookSessionKey(toolContext.sessionKey, config.agentId) &&
    toolContext.deliveryContext?.channel === 'telegram' &&
    toolContext.deliveryContext.accountId === config.accountId &&
    matchesTelegramPeer(
      toolContext.deliveryContext.to,
      config.conversationId,
    )
  );
}

function isReminderHookSessionKey(
  sessionKey: string | undefined,
  agentId: string,
): boolean {
  return (
    sessionKey === REMINDER_HOOK_SESSION_KEY ||
    sessionKey === `agent:${agentId}:${REMINDER_HOOK_SESSION_KEY}`
  );
}

export function admittedReminderEvent(
  prompt: string,
  context: ReminderRunContext,
  config: BindingConfiguration,
  nowSeconds: number,
): AdmittedReminderEvent | null {
  if (
    context.agentId !== config.agentId ||
    !isReminderHookSessionKey(context.sessionKey, config.agentId)
  ) {
    return null;
  }

  const match = prompt.match(
    /Fetch Reminder event ([0-9a-f-]{36}) that occurred at \d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}Z with money_assistant_reminder_read/,
  );

  if (!match || !new RegExp(UUID_PATTERN).test(match[1] ?? '')) {
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

  return (
    event.success === true &&
    isReminderHookSessionKey(sessionKey, config.agentId) &&
    context.channelId === 'telegram' &&
    context.accountId === config.accountId &&
    matchesTelegramPeer(context.conversationId, config.conversationId) &&
    matchesTelegramPeer(event.to, config.conversationId)
  );
}

export function isBoundReceiptChannelDelivery(
  event: SentMessage,
  context: SentMessageContext,
  config: BindingConfiguration,
): boolean {
  return (
    event.success === true &&
    context.channelId === 'telegram' &&
    context.accountId === config.accountId &&
    matchesTelegramPeer(context.conversationId, config.conversationId) &&
    matchesTelegramPeer(event.to, config.conversationId)
  );
}

export function shouldSuppressReminderDelivery(
  event: OutgoingMessage,
  context: SentMessageContext,
  config: BindingConfiguration,
  admissions: ReminderEventAdmissions,
  nowSeconds: number,
): boolean {
  const sessionKey = context.sessionKey;

  if (
    !isReminderHookSessionKey(sessionKey, config.agentId) ||
    context.channelId !== 'telegram' ||
    context.accountId !== config.accountId ||
    !matchesTelegramPeer(context.conversationId, config.conversationId) ||
    !matchesTelegramPeer(event.to, config.conversationId)
  ) {
    return false;
  }

  return consumeAlreadyDeliveredReminder(sessionKey, admissions, nowSeconds);
}

export function consumeAlreadyDeliveredReminder(
  sessionKey: string | undefined,
  admissions: ReminderEventAdmissions,
  nowSeconds: number,
): boolean {
  if (
    admissions.freshForSession(sessionKey, nowSeconds)?.alreadyDelivered !==
    true
  ) {
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
      conversation_id: normalizedTelegramPeerId(
        toolContext.deliveryContext?.to,
      ),
      owner_sender_id: normalizedTelegramPeerId(
        toolContext.requesterSenderId,
      ),
      message_id: admission.messageId,
      occurred_at: occurredAt,
    },
    input,
  });
}

export function receiptProposalCapabilityRequestBody(
  input: CapabilityInput,
  toolContext: TrustedToolContext,
  admission: AdmittedReceiptPhoto,
  processedAtSeconds: number,
): string {
  if (
    !admission.provider ||
    !admission.model ||
    !isApprovedReceiptModel(admission.provider, admission.model)
  ) {
    throw new Error(
      'Approved Receipt Proposal model provenance is unavailable.',
    );
  }

  const occurredAt = new Date(admission.occurredAtSeconds * 1000)
    .toISOString()
    .replace('.000Z', 'Z');
  const processedAt = new Date(processedAtSeconds * 1000)
    .toISOString()
    .replace('.000Z', 'Z');

  return JSON.stringify({
    schema_version: 1,
    capability: 'receipt.proposal.submit',
    interaction: {
      kind: 'owner_photo_message',
      agent_id: toolContext.agentId,
      account_id: toolContext.agentAccountId,
      conversation_id: normalizedTelegramPeerId(
        toolContext.deliveryContext?.to,
      ),
      owner_sender_id: normalizedTelegramPeerId(
        toolContext.requesterSenderId,
      ),
      message_id: admission.interactionId,
      occurred_at: occurredAt,
    },
    input: {
      proposal_id: admission.proposalId,
      source_kind: 'receipt_photo',
      processed_at: processedAt,
      provider: admission.provider,
      model: admission.model,
      contract_version: RECEIPT_CONTRACT_VERSION,
      ...input,
    },
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
  config: CapabilityConfiguration,
  signal?: AbortSignal,
): Promise<unknown> {
  signal?.throwIfAborted();
  const response = await fetch(`${config.capabilityOrigin}${CAPABILITY_PATH}`, {
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
  config: CapabilityConfiguration,
  toolContext: TrustedToolContext,
  signal?: AbortSignal,
): Promise<{
  content: Array<{ type: 'text'; text: string }>;
  details: unknown;
}> {
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

async function requestReceiptProposal(
  body: string,
  config: PluginConfiguration,
  signal?: AbortSignal,
): Promise<unknown> {
  const delays = [200, 1000];

  for (let attempt = 0; attempt <= delays.length; attempt += 1) {
    try {
      return await requestCapability(
        'receipt.proposal.submit',
        body,
        config,
        signal,
      );
    } catch (error) {
      const isTransient =
        !(error instanceof CapabilityRequestError) ||
        error.status === 429 ||
        (error.status !== undefined && error.status >= 500);

      if (!isTransient || attempt === delays.length) {
        throw error;
      }

      await new Promise((resolveDelay) =>
        setTimeout(resolveDelay, delays[attempt]),
      );
    }
  }

  throw new Error('Receipt Proposal submission failed.');
}

async function executeReceiptProposal(
  input: CapabilityInput,
  config: PluginConfiguration,
  toolContext: TrustedToolContext,
  signal?: AbortSignal,
): Promise<{
  content: Array<{ type: 'text'; text: string }>;
  details: unknown;
}> {
  const admission = receiptPhotoAdmissions.freshForSession(
    toolContext.sessionKey,
    Math.floor(Date.now() / 1000),
  );

  if (!admission) {
    throw new Error('Money Assistant receipt-photo admission is unavailable.');
  }

  try {
    const body = receiptProposalCapabilityRequestBody(
      input,
      toolContext,
      admission,
      Math.floor(Date.now() / 1000),
    );
    const details = await requestReceiptProposal(body, config, signal);

    return {
      content: [{ type: 'text', text: JSON.stringify(details) }],
      details,
    };
  } finally {
    await receiptPhotoAdmissions.finishAdmission(admission);
  }
}

async function executeReminderEventCapability(
  capability: string,
  input: CapabilityInput,
  eventId: string,
  config: CapabilityConfiguration,
  toolContext: TrustedToolContext,
  signal?: AbortSignal,
): Promise<{
  content: Array<{ type: 'text'; text: string }>;
  details: unknown;
}> {
  if (
    !isBoundReminderEventInteraction(toolContext, config) ||
    toolContext.sessionKey === undefined
  ) {
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

  if (
    capability === 'reminder.read' &&
    typeof details === 'object' &&
    details !== null &&
    'delivery' in details &&
    typeof details.delivery === 'object' &&
    details.delivery !== null &&
    'channel_delivered_at' in details.delivery &&
    details.delivery.channel_delivered_at !== null
  ) {
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
  config: CapabilityConfiguration,
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
      const isTransient =
        !(error instanceof CapabilityRequestError) ||
        error.status === 429 ||
        (error.status !== undefined && error.status >= 500);

      if (!isTransient || attempt === delays.length) {
        throw error;
      }

      await new Promise((resolve) => setTimeout(resolve, delays[attempt]));
    }
  }
}

type GatewayRequestRuntime = {
  request: (
    method: string,
    params?: Record<string, unknown>,
  ) => Promise<unknown>;
};

export async function deleteReceiptSourceMessage(
  gateway: GatewayRequestRuntime,
  admission: AdmittedReceiptPhoto,
  config: CapabilityConfiguration,
): Promise<void> {
  const messageId = Number(admission.messageId);

  if (!Number.isSafeInteger(messageId) || messageId < 1) {
    throw new Error('Telegram source message identifier is invalid.');
  }

  const result = await gateway.request('message.action', {
    channel: 'telegram',
    action: 'delete',
    accountId: config.accountId,
    agentId: config.agentId,
    sessionKey: admission.sessionKey,
    idempotencyKey: admission.proposalId,
    params: {
      target: config.conversationId,
      messageId,
    },
  });

  if (
    typeof result === 'object' &&
    result !== null &&
    'ok' in result &&
    result.ok !== true
  ) {
    throw new Error('Telegram source message deletion failed.');
  }
}

export async function warnReceiptSourceDeletionFailed(
  gateway: GatewayRequestRuntime,
  admission: AdmittedReceiptPhoto,
  config: CapabilityConfiguration,
): Promise<void> {
  await gateway.request('message.action', {
    channel: 'telegram',
    action: 'send',
    accountId: config.accountId,
    agentId: config.agentId,
    sessionKey: admission.sessionKey,
    idempotencyKey: randomUUID(),
    params: {
      target: config.conversationId,
      message:
        'I could not delete the receipt photo from Telegram. Please remove it manually.',
    },
  });
}

async function deleteReceiptSourceMessages(
  gateway: GatewayRequestRuntime,
  admissions: AdmittedReceiptPhoto[],
  config: CapabilityConfiguration,
): Promise<void> {
  for (const admission of admissions) {
    try {
      await deleteReceiptSourceMessage(gateway, admission, config);
    } catch {
      await warnReceiptSourceDeletionFailed(gateway, admission, config);
    }
  }
}

function isPositiveSafeInteger(value: unknown): boolean {
  return Number.isSafeInteger(value) && Number(value) > 0;
}

function isCategoryMutationInput(input: Record<string, unknown>): boolean {
  const operation = input.operation;

  if (
    typeof input.idempotency_key !== 'string' ||
    !new RegExp(UUID_PATTERN).test(input.idempotency_key) ||
    typeof operation !== 'string'
  ) {
    return false;
  }

  if (operation === 'assign_transaction') {
    return (
      isPositiveSafeInteger(input.transaction_id) &&
      isPositiveSafeInteger(input.expected_revision) &&
      (input.category_id === null || isPositiveSafeInteger(input.category_id))
    );
  }

  if (operation === 'retire' || operation === 'reactivate') {
    return (
      isPositiveSafeInteger(input.category_id) &&
      isPositiveSafeInteger(input.expected_revision)
    );
  }

  if (operation !== 'create' && operation !== 'update') {
    return false;
  }

  return (
    (operation === 'create' ||
      (isPositiveSafeInteger(input.category_id) &&
        isPositiveSafeInteger(input.expected_revision))) &&
    typeof input.name === 'string' &&
    input.name.trim() !== '' &&
    input.name.length <= 255 &&
    (input.parent_id === null || isPositiveSafeInteger(input.parent_id)) &&
    (input.description === null ||
      (typeof input.description === 'string' &&
        input.description.length <= 2000)) &&
    Array.isArray(input.examples) &&
    input.examples.length <= 20 &&
    input.examples.every(
      (example) => typeof example === 'string' && example.length <= 100,
    )
  );
}

function isReminderResponseInput(input: Record<string, unknown>): boolean {
  if (
    typeof input.idempotency_key !== 'string' ||
    !new RegExp(UUID_PATTERN).test(input.idempotency_key) ||
    !isPositiveSafeInteger(input.reminder_id)
  ) {
    return false;
  }

  if (input.action === 'acknowledge' || input.action === 'dismiss') {
    return Object.keys(input).length === 3;
  }

  if (
    input.action !== 'snooze' ||
    typeof input.snoozed_until !== 'string' ||
    Object.keys(input).length !== 4
  ) {
    return false;
  }

  const snoozedUntil = Date.parse(input.snoozed_until);

  return Number.isFinite(snoozedUntil) && snoozedUntil > Date.now();
}

function hasExactKeys(
  input: Record<string, unknown>,
  expected: string[],
): boolean {
  return (
    Object.keys(input).sort().join('\0') === [...expected].sort().join('\0')
  );
}

function hasAllowedAndRequiredKeys(
  input: Record<string, unknown>,
  allowed: string[],
  required: string[],
): boolean {
  const actual = Object.keys(input);

  return (
    actual.every((key) => allowed.includes(key)) &&
    required.every((key) => actual.includes(key))
  );
}

function hasValidLineItemRole(
  role: unknown,
  supportedRoles: readonly string[],
  lineTotalMinor: unknown,
): boolean {
  return (
    typeof role === 'string' &&
    supportedRoles.includes(role) &&
    Number.isSafeInteger(lineTotalMinor) &&
    (role === 'purchased_item'
      ? Number(lineTotalMinor) > 0
      : Number(lineTotalMinor) !== 0)
  );
}

function hasValidPrintedContext(
  quantity: unknown,
  unitPriceMinor: unknown,
): boolean {
  return (
    (quantity === null ||
      (typeof quantity === 'string' &&
        quantity.length <= 64 &&
        /^(?=.*[1-9])\d+(?:\.\d{1,6})?$/.test(quantity))) &&
    (unitPriceMinor === null || Number.isSafeInteger(unitPriceMinor))
  );
}

function isReceiptProposalInput(input: Record<string, unknown>): boolean {
  if (
    !hasExactKeys(input, ['transaction', 'line_items']) ||
    typeof input.transaction !== 'object' ||
    input.transaction === null ||
    Array.isArray(input.transaction) ||
    !Array.isArray(input.line_items) ||
    input.line_items.length < 1 ||
    input.line_items.length > 200
  ) {
    return false;
  }

  const transaction = input.transaction as Record<string, unknown>;

  if (
    !hasExactKeys(transaction, [
      'occurred_on',
      'amount_minor',
      'currency',
      'kind',
      'merchant_description',
    ]) ||
    typeof transaction.occurred_on !== 'string' ||
    !/^\d{4}-\d{2}-\d{2}$/.test(transaction.occurred_on) ||
    !isPositiveSafeInteger(transaction.amount_minor) ||
    (transaction.currency !== 'USD' && transaction.currency !== 'PEN') ||
    (transaction.kind !== 'purchase' && transaction.kind !== 'refund') ||
    typeof transaction.merchant_description !== 'string' ||
    transaction.merchant_description.trim() === '' ||
    transaction.merchant_description.length > 255
  ) {
    return false;
  }

  return input.line_items.every((candidate) => {
    if (
      typeof candidate !== 'object' ||
      candidate === null ||
      Array.isArray(candidate)
    ) {
      return false;
    }

    const lineItem = candidate as Record<string, unknown>;

    return (
      hasExactKeys(lineItem, [
        'description',
        'role',
        'quantity',
        'unit_price_minor',
        'line_total_minor',
      ]) &&
      typeof lineItem.description === 'string' &&
      lineItem.description.trim() !== '' &&
      lineItem.description.length <= 255 &&
      hasValidLineItemRole(
        lineItem.role,
        PROPOSABLE_LINE_ITEM_ROLES,
        lineItem.line_total_minor,
      ) &&
      hasValidPrintedContext(lineItem.quantity, lineItem.unit_price_minor)
    );
  });
}

export function isReceiptBreakdownMutationInput(
  input: Record<string, unknown>,
): boolean {
  if (
    typeof input.idempotency_key !== 'string' ||
    !new RegExp(UUID_PATTERN).test(input.idempotency_key)
  ) {
    return false;
  }

  const isCreate = input.operation === 'create_draft';

  if (isCreate) {
    if (
      !hasExactKeys(input, [
        'idempotency_key',
        'operation',
        'transaction_id',
        'expected_transaction_revision',
        'line_items',
      ]) ||
      !isPositiveSafeInteger(input.transaction_id) ||
      !isPositiveSafeInteger(input.expected_transaction_revision)
    ) {
      return false;
    }
  } else if (
    !isPositiveSafeInteger(input.receipt_breakdown_id) ||
    !isPositiveSafeInteger(input.expected_revision)
  ) {
    return false;
  }

  if (input.operation === 'confirm_draft') {
    return hasExactKeys(input, [
      'idempotency_key',
      'operation',
      'receipt_breakdown_id',
      'expected_revision',
    ]);
  }

  if (
    (!isCreate &&
      (input.operation !== 'update_draft' ||
        !hasExactKeys(input, [
          'idempotency_key',
          'operation',
          'receipt_breakdown_id',
          'expected_revision',
          'line_items',
        ]))) ||
    !Array.isArray(input.line_items) ||
    input.line_items.length < 1 ||
    input.line_items.length > 200
  ) {
    return false;
  }

  const seenIds = new Set<string>();

  const lineItemsAreValid = input.line_items.every((candidate) => {
    if (
      typeof candidate !== 'object' ||
      candidate === null ||
      Array.isArray(candidate)
    ) {
      return false;
    }

    const lineItem = candidate as Record<string, unknown>;

    if (
      !hasAllowedAndRequiredKeys(
        lineItem,
        [
          'id',
          'description',
          'role',
          'quantity',
          'unit_price_minor',
          'related_line_item_id',
          'line_total_minor',
          'category_id',
        ],
        ['id', 'description', 'line_total_minor', 'category_id'],
      ) ||
      (lineItem.id !== null && typeof lineItem.id !== 'string') ||
      (isCreate && lineItem.id !== null) ||
      (typeof lineItem.id === 'string' &&
        !new RegExp(UUID_PATTERN).test(lineItem.id)) ||
      (typeof lineItem.id === 'string' && seenIds.has(lineItem.id)) ||
      typeof lineItem.description !== 'string' ||
      lineItem.description.trim() === '' ||
      Array.from(lineItem.description).length > 255 ||
      !hasValidLineItemRole(
        lineItem.role ?? 'purchased_item',
        OWNER_LINE_ITEM_ROLES,
        lineItem.line_total_minor,
      ) ||
      !hasValidPrintedContext(
        lineItem.quantity ?? null,
        lineItem.unit_price_minor ?? null,
      ) ||
      (lineItem.related_line_item_id !== undefined &&
        lineItem.related_line_item_id !== null &&
        (typeof lineItem.related_line_item_id !== 'string' ||
          !new RegExp(UUID_PATTERN).test(lineItem.related_line_item_id))) ||
      (isCreate && lineItem.related_line_item_id != null) ||
      (lineItem.category_id !== null &&
        !isPositiveSafeInteger(lineItem.category_id)) ||
      (lineItem.role === 'unidentified' && lineItem.category_id !== null) ||
      (lineItem.related_line_item_id != null &&
        (lineItem.role === undefined ||
          lineItem.role === 'purchased_item' ||
          lineItem.role === 'unidentified'))
    ) {
      return false;
    }

    if (typeof lineItem.id === 'string') {
      seenIds.add(lineItem.id);
    }

    return true;
  });

  if (!lineItemsAreValid) {
    return false;
  }

  if (isCreate) {
    return true;
  }

  const lineItems = input.line_items as Array<Record<string, unknown>>;
  const lineItemsById = new Map<string, Record<string, unknown>>();

  for (const lineItem of lineItems) {
    if (typeof lineItem.id === 'string') {
      lineItemsById.set(lineItem.id, lineItem);
    }
  }

  return lineItems.every((lineItem) => {
    if (lineItem.related_line_item_id == null) {
      return true;
    }

    const relatedLineItem = lineItemsById.get(
      lineItem.related_line_item_id as string,
    );

    return (
      lineItem.id !== lineItem.related_line_item_id &&
      relatedLineItem !== undefined &&
      (relatedLineItem.role === undefined ||
        relatedLineItem.role === 'purchased_item')
    );
  });
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
  description:
    'Validate and summarize one exact manual Transaction for owner confirmation.',
  parameters: manualTransactionPreparationParameters,
};

const manualTransactionConfirmationToolDefinition = {
  name: 'money_assistant_transaction_confirm',
  label: 'Confirm Money Assistant Transaction',
  description:
    'Confirm one prepared manual Transaction from a new owner message.',
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
  description:
    'Validate and summarize one Category lifecycle or Transaction assignment operation.',
  parameters: categoryMutationPreparationParameters,
};

const categoryMutationConfirmationToolDefinition = {
  name: 'money_assistant_category_confirm',
  label: 'Confirm Money Assistant Categorization',
  description:
    'Confirm one prepared Categorization operation from a new owner message.',
  parameters: manualTransactionConfirmationParameters,
};

const reminderReadToolDefinition = {
  name: 'money_assistant_reminder_read',
  label: 'Read Money Assistant Reminder',
  description:
    'Read the current Reminder issued by the fixed Money Assistant hook event.',
  parameters: reminderReadParameters,
};

const reminderResponseToolDefinition = {
  name: 'money_assistant_reminder_respond',
  label: 'Respond to Money Assistant Reminder',
  description:
    'Acknowledge, snooze, or dismiss one Reminder from an admitted owner message.',
  parameters: reminderResponseParameters,
};

const receiptProposalToolDefinition = {
  name: 'money_assistant_receipt_proposal_submit',
  label: 'Submit Money Assistant Receipt Proposal',
  description:
    'Submit structured image-free Transaction and Line Item details from the admitted receipt photo.',
  parameters: receiptProposalParameters,
};

const receiptBreakdownMutationPreparationToolDefinition = {
  name: 'money_assistant_receipt_breakdown_prepare',
  label: 'Prepare Money Assistant Receipt Breakdown',
  description:
    'Validate and summarize one revision-bound Receipt Breakdown draft creation, edit, or confirmation. Use create_draft with a Transaction identifier and manual Line Items when no receipt photo or Receipt Proposal is available.',
  parameters: receiptBreakdownMutationPreparationParameters,
};

const receiptBreakdownMutationConfirmationToolDefinition = {
  name: 'money_assistant_receipt_breakdown_confirm',
  label: 'Confirm Money Assistant Receipt Breakdown',
  description:
    'Confirm one prepared Receipt Breakdown operation from a new owner message.',
  parameters: manualTransactionConfirmationParameters,
};

const financialExportPreparationToolDefinition = {
  name: 'money_assistant_export_prepare',
  label: 'Prepare Money Assistant Export',
  description:
    'Prepare a complete financial export for fresh passkey-authenticated web delivery. This tool never receives the export payload.',
  parameters: financialExportPreparationParameters,
};

const financialDeletionPreparationToolDefinition = {
  name: 'money_assistant_deletion_prepare',
  label: 'Prepare Money Assistant Deletion',
  description:
    'Prepare one eligible financial deletion for fresh passkey-authenticated web approval and retention-backed purge.',
  parameters: financialDeletionPreparationParameters,
};

const plugin = defineToolPlugin({
  id: 'money-assistant',
  name: 'Money Assistant',
  description:
    'Reads and confirms bounded Money Assistant financial operations and submits image-free Receipt Proposals.',
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
            const transactionId = (params as { transaction_id?: unknown })
              .transaction_id;

            if (
              !Number.isSafeInteger(transactionId) ||
              Number(transactionId) < 1
            ) {
              throw new Error(
                'Money Assistant Transaction identifier is invalid.',
              );
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

            if (
              typeof idempotencyKey !== 'string' ||
              !new RegExp(UUID_PATTERN).test(idempotencyKey) ||
              typeof occurredOn !== 'string' ||
              !/^\d{4}-\d{2}-\d{2}$/.test(occurredOn) ||
              !Number.isSafeInteger(amountMinor) ||
              Number(amountMinor) < 1 ||
              (currency !== 'USD' && currency !== 'PEN') ||
              (kind !== 'purchase' && kind !== 'refund') ||
              typeof merchantDescription !== 'string' ||
              merchantDescription.trim() === '' ||
              merchantDescription.length > 255
            ) {
              throw new Error(
                'Money Assistant manual Transaction input is invalid.',
              );
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

            if (
              typeof idempotencyKey !== 'string' ||
              !new RegExp(UUID_PATTERN).test(idempotencyKey) ||
              typeof pendingOperationId !== 'string' ||
              !new RegExp(UUID_PATTERN).test(pendingOperationId) ||
              !Number.isSafeInteger(pendingOperationRevision) ||
              Number(pendingOperationRevision) < 1 ||
              typeof payloadDigest !== 'string' ||
              !new RegExp(SHA256_PATTERN).test(payloadDigest)
            ) {
              throw new Error(
                'Money Assistant Confirmation Grant input is invalid.',
              );
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

            if (
              !isPositiveSafeInteger(input.page) ||
              !isPositiveSafeInteger(input.per_page) ||
              Number(input.per_page) > 100
            ) {
              throw new Error(
                'Money Assistant Category page input is invalid.',
              );
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
              throw new Error(
                'Money Assistant Categorization input is invalid.',
              );
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

            if (
              typeof idempotencyKey !== 'string' ||
              !new RegExp(UUID_PATTERN).test(idempotencyKey) ||
              typeof pendingOperationId !== 'string' ||
              !new RegExp(UUID_PATTERN).test(pendingOperationId) ||
              !Number.isSafeInteger(pendingOperationRevision) ||
              Number(pendingOperationRevision) < 1 ||
              typeof payloadDigest !== 'string' ||
              !new RegExp(SHA256_PATTERN).test(payloadDigest)
            ) {
              throw new Error(
                'Money Assistant Confirmation Grant input is invalid.',
              );
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
      ...receiptProposalToolDefinition,
      factory({ config, toolContext }) {
        if (
          !isBoundOwnerInteraction(toolContext, config) ||
          !receiptProcessingReady(config)
        ) {
          return null;
        }

        return {
          ...receiptProposalToolDefinition,
          async execute(_toolCallId, params, signal) {
            const input = params as Record<string, unknown>;

            if (!isReceiptProposalInput(input)) {
              throw new Error('Money Assistant Receipt Proposal is invalid.');
            }

            return executeReceiptProposal(input, config, toolContext, signal);
          },
        };
      },
    }),
    tool({
      ...receiptBreakdownMutationPreparationToolDefinition,
      factory({ config, toolContext }) {
        if (!isBoundOwnerInteraction(toolContext, config)) {
          return null;
        }

        return {
          ...receiptBreakdownMutationPreparationToolDefinition,
          async execute(_toolCallId, params, signal) {
            const input = params as Record<string, unknown>;

            if (!isReceiptBreakdownMutationInput(input)) {
              throw new Error(
                'Money Assistant Receipt Breakdown input is invalid.',
              );
            }

            return executeCapability(
              'receipt.breakdown.mutation.prepare',
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
      ...receiptBreakdownMutationConfirmationToolDefinition,
      factory({ config, toolContext }) {
        if (!isBoundOwnerInteraction(toolContext, config)) {
          return null;
        }

        return {
          ...receiptBreakdownMutationConfirmationToolDefinition,
          async execute(_toolCallId, params, signal) {
            const input = params as Record<string, unknown>;
            const idempotencyKey = input.idempotency_key;
            const pendingOperationId = input.pending_operation_id;
            const pendingOperationRevision = input.pending_operation_revision;
            const payloadDigest = input.payload_digest;

            if (
              typeof idempotencyKey !== 'string' ||
              !new RegExp(UUID_PATTERN).test(idempotencyKey) ||
              typeof pendingOperationId !== 'string' ||
              !new RegExp(UUID_PATTERN).test(pendingOperationId) ||
              !isPositiveSafeInteger(pendingOperationRevision) ||
              typeof payloadDigest !== 'string' ||
              !new RegExp(SHA256_PATTERN).test(payloadDigest)
            ) {
              throw new Error(
                'Money Assistant Confirmation Grant input is invalid.',
              );
            }

            return executeCapability(
              'receipt.breakdown.mutation.confirm',
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
      ...financialExportPreparationToolDefinition,
      factory({ config, toolContext }) {
        if (!isBoundOwnerInteraction(toolContext, config)) {
          return null;
        }

        return {
          ...financialExportPreparationToolDefinition,
          async execute(_toolCallId, params, signal) {
            const input = params as Record<string, unknown>;

            if (!isFinancialExportPreparationInput(input)) {
              throw new Error(
                'Money Assistant financial export input is invalid.',
              );
            }

            return executeCapability(
              'financial.export.prepare',
              { idempotency_key: input.idempotency_key },
              config,
              toolContext,
              signal,
            );
          },
        };
      },
    }),
    tool({
      ...financialDeletionPreparationToolDefinition,
      factory({ config, toolContext }) {
        if (!isBoundOwnerInteraction(toolContext, config)) {
          return null;
        }

        return {
          ...financialDeletionPreparationToolDefinition,
          async execute(_toolCallId, params, signal) {
            const input = params as Record<string, unknown>;

            if (!isFinancialDeletionPreparationInput(input)) {
              throw new Error(
                'Money Assistant financial deletion input is invalid.',
              );
            }

            return executeCapability(
              'financial.deletion.prepare',
              {
                idempotency_key: input.idempotency_key,
                resource_type: input.resource_type,
                resource_id: Number(input.resource_id),
                expected_revision: Number(input.expected_revision),
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

            if (
              typeof eventId !== 'string' ||
              !new RegExp(UUID_PATTERN).test(eventId)
            ) {
              throw new Error(
                'Money Assistant Reminder event identifier is invalid.',
              );
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
  const runtimeReceiptPolicyReady = receiptRuntimePolicyReady(
    api.config,
    config.agentId,
  );

  api.on('message_received', (event, context) => {
    if (receiptPhotoAdmissions.admit(event, context, config)) {
      ownerMessageAdmissions.clear(event.sessionKey ?? context.sessionKey);

      return;
    }

    ownerMessageAdmissions.admit(event, context, config);
  });

  api.on('before_model_resolve', (event, context) => {
    const receiptPhoto = receiptPhotoAdmissions.freshForRun(
      context.runId,
      context.sessionKey,
      Math.floor(Date.now() / 1000),
    );

    if (
      receiptPhoto &&
      receiptProcessingReady(config) &&
      runtimeReceiptPolicyReady &&
      receiptEffectiveAuthReady(api.config, config.agentId, context.sessionKey)
    ) {
      return {
        providerOverride: APPROVED_RECEIPT_PROVIDER,
        modelOverride: 'gpt-5.6-sol',
      };
    }

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

  api.on('before_agent_run', (_event, context) => {
    const blockCategory = receiptAdmissionBlockCategory(
      receiptPhotoAdmissions,
      context.runId,
      context.sessionKey,
      Math.floor(Date.now() / 1000),
    );

    if (blockCategory === 'receipt_photo_concurrent') {
      return {
        outcome: 'block',
        reason:
          'Another receipt photo is already active for this conversation.',
        message:
          'I am still processing the previous receipt photo. Please retry this receipt after it finishes.',
        category: 'receipt_photo_concurrent',
      };
    }

    const receiptPhoto = receiptPhotoAdmissions.freshForRun(
      context.runId,
      context.sessionKey,
      Math.floor(Date.now() / 1000),
    );

    if (blockCategory === 'receipt_photo_invalid') {
      return {
        outcome: 'block',
        reason: 'Receipt photo failed strict local validation.',
        message:
          'That receipt photo could not be processed safely. Send one JPEG, PNG, or WebP image up to 20 MB and try again.',
        category: 'receipt_photo_invalid',
      };
    }

    if (blockCategory === 'receipt_photo_stale') {
      return {
        outcome: 'block',
        reason: 'Receipt-photo admission is stale or not bound to this run.',
        message:
          'That receipt photo is no longer available for processing. Please send it again.',
        category: 'receipt_photo_stale',
      };
    }

    if (
      receiptPhoto &&
      (!receiptProcessingReady(config) ||
        !runtimeReceiptPolicyReady ||
        !receiptEffectiveAuthReady(
          api.config,
          config.agentId,
          context.sessionKey,
        ))
    ) {
      return {
        outcome: 'block',
        reason: 'Receipt-photo privacy policy is not confirmed.',
        message: RECEIPT_PRIVACY_DISCLOSURE,
        category: 'receipt_privacy_policy',
      };
    }
  });

  api.on('model_call_started', (event) => {
    receiptPhotoAdmissions.recordActualModel(
      event.runId,
      event.provider,
      event.model,
    );
  });

  api.on('before_message_write', (event, context) => {
    if (
      shouldBlockReceiptMessageWrite(
        receiptPhotoAdmissions,
        event.sessionKey,
        context.sessionKey,
      )
    ) {
      return { block: true };
    }
  });

  api.on('agent_end', async (event, context) => {
    const pendingSourceDeletions =
      await receiptPhotoAdmissions.finishForRun(
        event.runId,
        context.sessionKey,
      );
    receiptPhotoAdmissions.clearRejectedRun(event.runId);
    await deleteReceiptSourceMessages(
      api.runtime.gateway,
      pendingSourceDeletions,
      config,
    );
  });

  api.on('before_agent_reply', (_event, context) => {
    if (
      context.agentId === config.agentId &&
      isReminderHookSessionKey(context.sessionKey, config.agentId) &&
      consumeAlreadyDeliveredReminder(
        context.sessionKey,
        reminderEventAdmissions,
        Math.floor(Date.now() / 1000),
      )
    ) {
      return {
        handled: true,
        reason: 'Money Assistant Reminder event was already delivered.',
      };
    }
  });

  api.on('message_sending', (event, context) => {
    if (
      shouldSuppressReminderDelivery(
        event,
        context,
        config,
        reminderEventAdmissions,
        Math.floor(Date.now() / 1000),
      )
    ) {
      return {
        cancel: true,
        cancelReason: 'Money Assistant Reminder event was already delivered.',
      };
    }
  });

  api.on('message_sent', async (event, context) => {
    const sessionKey = event.sessionKey ?? context.sessionKey;

    if (sessionKey === undefined) {
      return;
    }

    if (isBoundReminderChannelDelivery(event, context, config)) {
      const reminderAdmission = reminderEventAdmissions.takeFreshForSession(
        sessionKey,
        Math.floor(Date.now() / 1000),
      );

      if (reminderAdmission) {
        try {
          await recordReminderChannelDelivery(reminderAdmission, config);
        } catch (error) {
          reminderEventAdmissions.admit(
            sessionKey,
            reminderAdmission.eventId,
            reminderAdmission.occurredAtSeconds,
          );

          throw error;
        }
      }
    }

    if (!isBoundReceiptChannelDelivery(event, context, config)) {
      return;
    }

    receiptPhotoAdmissions.markBoundResponseDelivered(sessionKey);
    const receiptAdmissions =
      receiptPhotoAdmissions.takePendingSourceDeletions(sessionKey);

    await deleteReceiptSourceMessages(
      api.runtime.gateway,
      receiptAdmissions,
      config,
    );
  });

  registerTool(api);
};

export default plugin;
