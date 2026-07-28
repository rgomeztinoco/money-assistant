import { createHash, createPrivateKey, randomUUID, sign, } from 'node:crypto';
import { readFileSync, realpathSync, statSync, } from 'node:fs';
import { unlink } from 'node:fs/promises';
import { extname, isAbsolute, relative, resolve } from 'node:path';
import { loadAuthProfileStoreForRuntime, resolveAgentDir, resolveAuthProfileOrder, } from 'openclaw/plugin-sdk/agent-runtime';
import { getMediaDir } from 'openclaw/plugin-sdk/media-runtime';
import { getSessionEntry } from 'openclaw/plugin-sdk/session-store-runtime';
import { defineToolPlugin } from 'openclaw/plugin-sdk/tool-plugin';
import { Type } from 'typebox';
const CAPABILITY_ORIGIN = 'http://127.0.0.1:8443';
const CAPABILITY_PATH = '/api/openclaw/v1/transport';
const REMINDER_HOOK_SESSION_KEY = 'hook:money-assistant:reminders';
const UUID_PATTERN = '^[0-9a-f]{8}-[0-9a-f]{4}-[1-8][0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$';
const SHA256_PATTERN = '^[a-f0-9]{64}$';
const APPROVED_RECEIPT_PROVIDER = 'openai';
const APPROVED_RECEIPT_MODEL = 'openai/gpt-5.6';
const RECEIPT_CONTRACT_VERSION = 1;
const RECEIPT_CLEANUP_CEILING_SECONDS = 3600;
const RECEIPT_IMAGE_MAX_BYTES = 20 * 1024 * 1024;
const RECEIPT_POLICY_VERSION = 'openai-oauth-gpt-5.6-v1';
const APPROVED_OPENAI_OAUTH_PROFILE = 'openai:money-assistant-oauth';
export const RECEIPT_PRIVACY_DISCLOSURE = 'Receipt processing uses the existing OpenAI OAuth account and only openai/gpt-5.6. OpenAI OAuth has no published fixed retention ceiling. Before enabling receipts, disable account-wide model improvement and Codex full-environment training. Receipt interactions are never submitted as feedback. OpenClaw deletes local images after proposal submission or terminal failure, enforces a one-hour crash-cleanup ceiling, then attempts to delete the Telegram source and warns if manual removal is needed. Money Assistant retains only the opaque proposal identifier, receipt_photo source kind, processing time, actual provider/model, contract version, and structured financial proposal.';
const pluginConfigSchema = Type.Object({
    keyId: Type.String({ minLength: 1, maxLength: 128 }),
    privateKey: Type.String({ minLength: 1 }),
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
}, { additionalProperties: false });
const transactionParameters = Type.Object({
    transaction_id: Type.Integer({ minimum: 1 }),
}, { additionalProperties: false });
const manualTransactionPreparationParameters = Type.Object({
    idempotency_key: Type.String({ pattern: UUID_PATTERN }),
    occurred_on: Type.String({ pattern: '^\\d{4}-\\d{2}-\\d{2}$' }),
    amount_minor: Type.Integer({ minimum: 1, maximum: Number.MAX_SAFE_INTEGER }),
    currency: Type.Union([Type.Literal('USD'), Type.Literal('PEN')]),
    kind: Type.Union([Type.Literal('purchase'), Type.Literal('refund')]),
    merchant_description: Type.String({ minLength: 1, maxLength: 255 }),
}, { additionalProperties: false });
const manualTransactionConfirmationParameters = Type.Object({
    idempotency_key: Type.String({ pattern: UUID_PATTERN }),
    pending_operation_id: Type.String({ pattern: UUID_PATTERN }),
    pending_operation_revision: Type.Integer({ minimum: 1 }),
    payload_digest: Type.String({ pattern: SHA256_PATTERN }),
}, { additionalProperties: false });
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
const receiptProposalParameters = Type.Object({
    transaction: Type.Object({
        occurred_on: Type.String({ pattern: '^\\d{4}-\\d{2}-\\d{2}$' }),
        amount_minor: Type.Integer({ minimum: 1, maximum: Number.MAX_SAFE_INTEGER }),
        currency: Type.Union([Type.Literal('USD'), Type.Literal('PEN')]),
        kind: Type.Union([Type.Literal('purchase'), Type.Literal('refund')]),
        merchant_description: Type.String({ minLength: 1, maxLength: 255 }),
    }, { additionalProperties: false }),
    line_items: Type.Array(Type.Object({
        description: Type.String({ minLength: 1, maxLength: 255 }),
        line_total_minor: Type.Union([
            Type.Integer({ minimum: Number.MIN_SAFE_INTEGER, maximum: -1 }),
            Type.Integer({ minimum: 1, maximum: Number.MAX_SAFE_INTEGER }),
        ]),
    }, { additionalProperties: false }), { minItems: 1, maxItems: 200 }),
}, { additionalProperties: false });
class CapabilityRequestError extends Error {
    status;
    constructor(message, status) {
        super(message);
        this.status = status;
    }
}
function timestampInSeconds(timestamp) {
    if (!Number.isFinite(timestamp) || timestamp <= 0) {
        return null;
    }
    return Math.floor(timestamp > 10_000_000_000 ? timestamp / 1000 : timestamp);
}
export function admittedOwnerMessage(event, context, config) {
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
    messages = new Map();
    admit(event, context, config) {
        const sessionKey = event.sessionKey ?? context.sessionKey;
        if (sessionKey) {
            this.messages.delete(sessionKey);
        }
        const admission = admittedOwnerMessage(event, context, config);
        if (admission) {
            this.messages.set(admission.sessionKey, admission);
        }
    }
    clear(sessionKey) {
        if (sessionKey) {
            this.messages.delete(sessionKey);
        }
    }
    freshForSession(sessionKey, nowSeconds) {
        const admission = this.messages.get(sessionKey ?? '');
        if (!admission) {
            return null;
        }
        const ageInSeconds = nowSeconds - admission.occurredAtSeconds;
        return ageInSeconds >= 0 && ageInSeconds <= 1800 ? admission : null;
    }
}
const ownerMessageAdmissions = new OwnerMessageAdmissions();
export function receiptProcessingReady(config) {
    return config.receiptProcessingEnabled
        && config.receiptDisclosureDelivered
        && config.receiptDisclosureAccepted
        && config.openAiModelImprovementDisabled
        && config.codexFullEnvironmentTrainingDisabled
        && config.openAiOAuthProfileId === APPROVED_OPENAI_OAUTH_PROFILE
        && config.openAiOAuthCredentialVersion !== ''
        && config.receiptPolicyVersion === RECEIPT_POLICY_VERSION
        && config.receiptConfirmedPolicyVersion === config.receiptPolicyVersion
        && config.receiptConfirmedOAuthProfileId === config.openAiOAuthProfileId
        && config.receiptConfirmedOAuthCredentialVersion === config.openAiOAuthCredentialVersion;
}
function isRecord(value) {
    return typeof value === 'object' && value !== null && !Array.isArray(value);
}
export function receiptRuntimePolicyReady(runtimeConfig, agentId) {
    if (!isRecord(runtimeConfig)
        || !isRecord(runtimeConfig.auth)
        || !isRecord(runtimeConfig.auth.profiles)
        || !isRecord(runtimeConfig.auth.order)
        || !isRecord(runtimeConfig.commands)
        || !isRecord(runtimeConfig.agents)
        || !isRecord(runtimeConfig.agents.defaults)) {
        return false;
    }
    const profiles = runtimeConfig.auth.profiles;
    const approvedProfile = profiles[APPROVED_OPENAI_OAUTH_PROFILE];
    const openAiProfiles = Object.entries(profiles).filter(([, profile]) => (isRecord(profile) && profile.provider === APPROVED_RECEIPT_PROVIDER));
    const defaultAgentPolicy = runtimeConfig.agents.defaults;
    const models = defaultAgentPolicy.models;
    const model = defaultAgentPolicy.model;
    const imageModel = defaultAgentPolicy.imageModel;
    return isRecord(approvedProfile)
        && approvedProfile.provider === APPROVED_RECEIPT_PROVIDER
        && approvedProfile.mode === 'oauth'
        && openAiProfiles.length === 1
        && Array.isArray(runtimeConfig.auth.order.openai)
        && runtimeConfig.auth.order.openai.length === 1
        && runtimeConfig.auth.order.openai[0] === APPROVED_OPENAI_OAUTH_PROFILE
        && runtimeConfig.commands.text === false
        && runtimeConfig.commands.native === false
        && isRecord(models)
        && Object.keys(models).length === 1
        && isRecord(models[APPROVED_RECEIPT_MODEL])
        && isRecord(model)
        && model.primary === APPROVED_RECEIPT_MODEL
        && Array.isArray(model.fallbacks)
        && model.fallbacks.length === 0
        && isRecord(imageModel)
        && imageModel.primary === APPROVED_RECEIPT_MODEL
        && Array.isArray(imageModel.fallbacks)
        && imageModel.fallbacks.length === 0
        && Array.isArray(runtimeConfig.agents.list)
        && runtimeConfig.agents.list.some((agent) => isRecord(agent) && agent.id === agentId);
}
export function receiptEffectiveAuthStateReady(profiles, resolvedOrder, sessionEntry) {
    const credential = profiles[APPROVED_OPENAI_OAUTH_PROFILE];
    const sessionProfile = isRecord(sessionEntry)
        ? sessionEntry.authProfileOverride
        : undefined;
    return isRecord(credential)
        && credential.type === 'oauth'
        && resolvedOrder.length === 1
        && resolvedOrder[0] === APPROVED_OPENAI_OAUTH_PROFILE
        && (sessionProfile === undefined || sessionProfile === APPROVED_OPENAI_OAUTH_PROFILE);
}
function receiptEffectiveAuthReady(runtimeConfig, agentId, sessionKey) {
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
            ? getSessionEntry({ agentId, sessionKey, readConsistency: 'latest' })
            : undefined;
        return receiptEffectiveAuthStateReady(store.profiles, resolvedOrder, sessionEntry);
    }
    catch {
        return false;
    }
}
function oneMediaValue(metadata, singular, plural) {
    const multiple = metadata[plural];
    if (Array.isArray(multiple)) {
        return multiple.filter((value) => typeof value === 'string');
    }
    return typeof metadata[singular] === 'string'
        ? [metadata[singular]]
        : [];
}
function pathIsInsideRoot(path, root) {
    const relativePath = relative(resolve(root), resolve(path));
    return relativePath !== ''
        && relativePath !== '..'
        && !relativePath.startsWith(`..${process.platform === 'win32' ? '\\' : '/'}`)
        && !isAbsolute(relativePath);
}
function safeReceiptPath(path, root) {
    try {
        const realRoot = realpathSync(root);
        const realPath = realpathSync(path);
        return pathIsInsideRoot(realPath, realRoot) && statSync(realPath).isFile()
            ? realPath
            : null;
    }
    catch {
        return null;
    }
}
function hasJpegStructure(bytes) {
    if (bytes.length < 12
        || bytes[0] !== 0xff
        || bytes[1] !== 0xd8
        || bytes[bytes.length - 2] !== 0xff
        || bytes[bytes.length - 1] !== 0xd9) {
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
        if ((marker >= 0xc0 && marker <= 0xc3)
            || (marker >= 0xc5 && marker <= 0xc7)
            || (marker >= 0xc9 && marker <= 0xcb)
            || (marker >= 0xcd && marker <= 0xcf)) {
            if (segmentLength < 8
                || bytes.readUInt16BE(offset + 3) === 0
                || bytes.readUInt16BE(offset + 5) === 0) {
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
function hasPngStructure(bytes) {
    const signature = Buffer.from([0x89, 0x50, 0x4e, 0x47, 0x0d, 0x0a, 0x1a, 0x0a]);
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
            if (chunkType !== 'IHDR'
                || chunkLength !== 13
                || bytes.readUInt32BE(offset + 8) === 0
                || bytes.readUInt32BE(offset + 12) === 0) {
                return false;
            }
            hasHeader = true;
        }
        else if (chunkType === 'IDAT') {
            hasImageData = hasImageData || chunkLength > 0;
        }
        else if (chunkType === 'IEND') {
            return chunkLength === 0 && hasImageData && chunkEnd === bytes.length;
        }
        offset = chunkEnd;
    }
    return false;
}
function hasWebpStructure(bytes) {
    if (bytes.length < 26
        || bytes.subarray(0, 4).toString('ascii') !== 'RIFF'
        || bytes.subarray(8, 12).toString('ascii') !== 'WEBP'
        || bytes.readUInt32LE(4) !== bytes.length - 8) {
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
            hasImageChunk = chunkLength >= 10
                && bytes.subarray(dataOffset + 3, dataOffset + 6).equals(Buffer.from([0x9d, 0x01, 0x2a]));
        }
        else if (chunkType === 'VP8L') {
            hasImageChunk = chunkLength >= 5 && bytes[dataOffset] === 0x2f;
        }
        offset = paddedChunkEnd;
    }
    return hasImageChunk && offset === bytes.length;
}
export function inspectReceiptImage(path, root, declaredMimeType) {
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
    const normalizedMimeType = declaredMimeType.toLowerCase() === 'image/jpg'
        ? 'image/jpeg'
        : declaredMimeType.toLowerCase();
    const isJpeg = hasJpegStructure(bytes);
    const isPng = hasPngStructure(bytes);
    const isWebp = hasWebpStructure(bytes);
    if ((isJpeg && normalizedMimeType === 'image/jpeg' && ['.jpg', '.jpeg'].includes(extension))
        || (isPng && normalizedMimeType === 'image/png' && extension === '.png')
        || (isWebp && normalizedMimeType === 'image/webp' && extension === '.webp')) {
        return realPath;
    }
    return null;
}
export function admittedReceiptPhoto(event, context, config, inspectImage = inspectReceiptImage) {
    const ownerMessage = admittedOwnerMessage(event, context, config);
    const metadata = event.metadata;
    if (!ownerMessage || !metadata) {
        return null;
    }
    const mediaPaths = oneMediaValue(metadata, 'mediaPath', 'mediaPaths');
    const mediaTypes = oneMediaValue(metadata, 'mediaType', 'mediaTypes');
    if (mediaPaths.length !== 1
        || mediaTypes.length !== 1
        || !mediaTypes[0]?.toLowerCase().startsWith('image/')) {
        return null;
    }
    const mediaPath = inspectImage(mediaPaths[0], config.receiptMediaRoot, mediaTypes[0]);
    if (!mediaPath) {
        return null;
    }
    return {
        ...ownerMessage,
        ...(event.runId ? { runId: event.runId } : {}),
        mediaPath,
    };
}
export function isApprovedReceiptModel(provider, model) {
    const normalizedModel = model.startsWith(`${provider}/`)
        ? model
        : `${provider}/${model}`;
    return provider === APPROVED_RECEIPT_PROVIDER
        && normalizedModel === APPROVED_RECEIPT_MODEL;
}
const defaultReceiptAdmissionDependencies = {
    async removeFile(path) {
        try {
            await unlink(path);
        }
        catch (error) {
            if (error.code !== 'ENOENT') {
                throw error;
            }
        }
    },
    setTimer(callback, delay) {
        const timer = setTimeout(() => {
            void Promise.resolve(callback()).catch(() => { });
        }, delay);
        timer.unref();
        return timer;
    },
    clearTimer(timer) {
        clearTimeout(timer);
    },
    createProposalId: randomUUID,
    createInteractionId: randomUUID,
    nowSeconds: () => Math.floor(Date.now() / 1000),
    inspectImage: inspectReceiptImage,
    safePath: safeReceiptPath,
    managedMediaRoot: () => resolve(getMediaDir(), 'inbound'),
};
export class ReceiptPhotoAdmissions {
    dependencies;
    photos = new Map();
    pendingSourceDeletions = new Map();
    rejectedRuns = new Map();
    rejectedSessionsWithoutRun = new Map();
    identitiesBySourceMessage = new Map();
    sensitiveSessions = new Set();
    constructor(dependencies = defaultReceiptAdmissionDependencies) {
        this.dependencies = dependencies;
    }
    admit(event, context, config) {
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
        const admitted = admittedReceiptPhoto(event, context, config, this.dependencies.inspectImage);
        const cleanupRoots = [
            config.receiptMediaRoot,
            this.dependencies.managedMediaRoot(),
        ];
        const cleanupPaths = admitted
            ? [admitted.mediaPath]
            : mediaPaths
                .map((path) => cleanupRoots
                .map((root) => this.dependencies.safePath(path, root))
                .find((safePath) => safePath !== null) ?? null)
                .filter((path) => path !== null);
        const existing = this.photos.get(ownerMessage.sessionKey);
        if (existing?.messageId === ownerMessage.messageId) {
            for (const path of cleanupPaths) {
                if (!existing.cleanupPaths.includes(path)) {
                    void this.dependencies.removeFile(path).catch(() => { });
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
            expiresAtSeconds: ownerMessage.occurredAtSeconds + RECEIPT_CLEANUP_CEILING_SECONDS,
        };
        this.identitiesBySourceMessage.set(sourceMessageKey, identity);
        const photo = {
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
            }
            else {
                const rejectedUntil = Math.max(this.rejectedSessionsWithoutRun.get(ownerMessage.sessionKey) ?? 0, ownerMessage.occurredAtSeconds + RECEIPT_CLEANUP_CEILING_SECONDS);
                this.rejectedSessionsWithoutRun.set(ownerMessage.sessionKey, rejectedUntil);
            }
            void this.removeLocalImage(photo).catch(() => { });
            return true;
        }
        const remainingSeconds = Math.max(0, ownerMessage.occurredAtSeconds
            + RECEIPT_CLEANUP_CEILING_SECONDS
            - nowSeconds);
        photo.cleanupTimer = this.dependencies.setTimer(() => this.expire(ownerMessage.sessionKey, photo.proposalId), remainingSeconds * 1000);
        this.photos.set(ownerMessage.sessionKey, photo);
        this.sensitiveSessions.add(ownerMessage.sessionKey);
        return true;
    }
    freshForSession(sessionKey, nowSeconds) {
        const photo = this.photos.get(sessionKey ?? '');
        if (!photo) {
            return null;
        }
        const ageInSeconds = nowSeconds - photo.occurredAtSeconds;
        return photo.processable && ageInSeconds >= 0 && ageInSeconds <= 1800
            ? photo
            : null;
    }
    freshForRun(runId, sessionKey, nowSeconds) {
        const photo = this.photos.get(sessionKey ?? '');
        if (!photo || (photo.runId !== undefined && photo.runId !== runId)) {
            return null;
        }
        if (runId !== undefined) {
            photo.runId = runId;
        }
        return this.freshForSession(sessionKey, nowSeconds);
    }
    hasConflictingRun(runId, sessionKey) {
        const photo = this.photos.get(sessionKey ?? '');
        return photo?.runId !== undefined && photo.runId !== runId;
    }
    consumeRejectedRun(runId, sessionKey) {
        const key = sessionKey ?? '';
        if (runId !== undefined && this.rejectedRuns.get(runId) === key) {
            this.rejectedRuns.delete(runId);
            return true;
        }
        const rejectedUntil = this.rejectedSessionsWithoutRun.get(key);
        if (rejectedUntil !== undefined && rejectedUntil >= this.dependencies.nowSeconds()) {
            return true;
        }
        if (rejectedUntil !== undefined) {
            this.rejectedSessionsWithoutRun.delete(key);
        }
        return false;
    }
    clearRejectedRun(runId) {
        if (runId !== undefined) {
            this.rejectedRuns.delete(runId);
        }
    }
    activeForSession(sessionKey) {
        return this.photos.get(sessionKey ?? '') ?? null;
    }
    recordActualModel(runId, provider, model) {
        const photo = [...this.photos.values()].find((candidate) => candidate.runId === runId);
        if (!photo || !photo.processable || !isApprovedReceiptModel(provider, model)) {
            return false;
        }
        photo.provider = provider;
        photo.model = model.startsWith(`${provider}/`) ? model : `${provider}/${model}`;
        return true;
    }
    isSensitiveSession(sessionKey) {
        return this.sensitiveSessions.has(sessionKey ?? '');
    }
    async finishForSession(sessionKey) {
        const key = sessionKey ?? '';
        const photo = this.photos.get(key);
        if (!photo) {
            return;
        }
        await this.finishAdmission(photo);
    }
    async finishAdmission(photo) {
        if (this.photos.get(photo.sessionKey) !== photo) {
            return;
        }
        this.photos.delete(photo.sessionKey);
        try {
            await this.removeLocalImage(photo);
        }
        finally {
            this.queueSourceDeletion(photo);
        }
    }
    async finishForRun(runId, sessionKey) {
        const photo = runId ? [...this.photos.values()].find((candidate) => candidate.runId === runId) : this.photos.get(sessionKey ?? '');
        if (photo) {
            await this.finishAdmission(photo);
        }
    }
    takePendingSourceDeletions(sessionKey) {
        const key = sessionKey ?? '';
        const photos = this.pendingSourceDeletions.get(key) ?? [];
        if (photos.length > 0) {
            this.pendingSourceDeletions.delete(key);
            if (!this.photos.has(key)) {
                this.sensitiveSessions.delete(key);
            }
        }
        return photos;
    }
    async expire(sessionKey, proposalId) {
        const photo = this.photos.get(sessionKey);
        if (!photo || photo.proposalId !== proposalId) {
            return;
        }
        await this.finishForSession(sessionKey);
    }
    async removeLocalImage(photo) {
        if (photo.cleanupTimer !== undefined) {
            this.dependencies.clearTimer(photo.cleanupTimer);
            photo.cleanupTimer = undefined;
        }
        for (const path of photo.cleanupPaths) {
            await this.dependencies.removeFile(path);
        }
    }
    queueSourceDeletion(photo) {
        const pending = this.pendingSourceDeletions.get(photo.sessionKey) ?? [];
        if (!pending.some((candidate) => candidate.messageId === photo.messageId)) {
            pending.push(photo);
            this.pendingSourceDeletions.set(photo.sessionKey, pending);
        }
    }
}
export function receiptAdmissionBlockCategory(admissions, runId, sessionKey, nowSeconds) {
    if (admissions.consumeRejectedRun(runId, sessionKey)
        || admissions.hasConflictingRun(runId, sessionKey)) {
        return 'receipt_photo_concurrent';
    }
    const activeReceiptPhoto = admissions.activeForSession(sessionKey);
    const receiptPhoto = admissions.freshForRun(runId, sessionKey, nowSeconds);
    if (activeReceiptPhoto && !activeReceiptPhoto.processable) {
        return 'receipt_photo_invalid';
    }
    return activeReceiptPhoto && !receiptPhoto ? 'receipt_photo_stale' : null;
}
export function shouldBlockReceiptMessageWrite(admissions, eventSessionKey, contextSessionKey) {
    return admissions.isSensitiveSession(eventSessionKey ?? contextSessionKey);
}
const receiptPhotoAdmissions = new ReceiptPhotoAdmissions();
export class ReminderEventAdmissions {
    events = new Map();
    admit(sessionKey, eventId, occurredAtSeconds) {
        const admissions = this.events.get(sessionKey) ?? [];
        if (!admissions.some((admission) => admission.eventId === eventId)) {
            admissions.push({ eventId, occurredAtSeconds });
        }
        this.events.set(sessionKey, admissions);
    }
    freshForSession(sessionKey, nowSeconds) {
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
    freshEventForSession(sessionKey, eventId, nowSeconds) {
        const admissions = this.events.get(sessionKey ?? '') ?? [];
        return admissions.find((admission) => {
            const ageInSeconds = nowSeconds - admission.occurredAtSeconds;
            return admission.eventId === eventId
                && ageInSeconds >= 0
                && ageInSeconds <= 1800;
        }) ?? null;
    }
    markAlreadyDelivered(sessionKey, eventId) {
        const admission = this.events.get(sessionKey)?.find((candidate) => candidate.eventId === eventId);
        if (admission) {
            admission.alreadyDelivered = true;
        }
    }
    takeFreshForSession(sessionKey, nowSeconds) {
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
        }
        else {
            this.events.set(key, admissions);
        }
        return admission;
    }
}
const reminderEventAdmissions = new ReminderEventAdmissions();
export function isBoundOwnerInteraction(toolContext, config) {
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
export function isBoundReminderEventInteraction(toolContext, config) {
    return toolContext.agentId === config.agentId
        && toolContext.sessionKey === REMINDER_HOOK_SESSION_KEY
        && toolContext.deliveryContext?.channel === 'telegram'
        && toolContext.deliveryContext.accountId === config.accountId
        && toolContext.deliveryContext.to === config.conversationId;
}
export function admittedReminderEvent(prompt, context, config, nowSeconds) {
    if (context.agentId !== config.agentId
        || context.sessionKey !== REMINDER_HOOK_SESSION_KEY) {
        return null;
    }
    const match = prompt.match(/Fetch Reminder event ([0-9a-f-]{36}) that occurred at \d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}Z with money_assistant_reminder_read/);
    if (!match
        || !new RegExp(UUID_PATTERN).test(match[1] ?? '')) {
        return null;
    }
    return {
        eventId: match[1],
        occurredAtSeconds: nowSeconds,
    };
}
export function isBoundReminderChannelDelivery(event, context, config) {
    const sessionKey = event.sessionKey ?? context.sessionKey;
    return event.success === true
        && sessionKey === REMINDER_HOOK_SESSION_KEY
        && context.channelId === 'telegram'
        && context.accountId === config.accountId
        && context.conversationId === config.conversationId
        && event.to === config.conversationId;
}
export function shouldSuppressReminderDelivery(event, context, config, admissions, nowSeconds) {
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
export function consumeAlreadyDeliveredReminder(sessionKey, admissions, nowSeconds) {
    if (admissions.freshForSession(sessionKey, nowSeconds)?.alreadyDelivered !== true) {
        return false;
    }
    admissions.takeFreshForSession(sessionKey, nowSeconds);
    return true;
}
function privateKeyFromSodiumSecret(encodedPrivateKey) {
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
export function authorizationHeaders(body, keyId, encodedPrivateKey, timestamp, nonce) {
    const bodyDigest = createHash('sha256').update(body).digest('hex');
    const signedMessage = [
        timestamp,
        nonce,
        'POST',
        CAPABILITY_PATH,
        bodyDigest,
    ].join('\n');
    const signature = sign(null, Buffer.from(signedMessage), privateKeyFromSodiumSecret(encodedPrivateKey));
    return {
        'Content-Type': 'application/json',
        Accept: 'application/json',
        'X-Money-Assistant-Key-Id': keyId,
        'X-Money-Assistant-Timestamp': timestamp,
        'X-Money-Assistant-Nonce': nonce,
        'X-Money-Assistant-Signature': signature.toString('base64'),
    };
}
export function capabilityRequestBody(capability, input, toolContext, admission) {
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
export function receiptProposalCapabilityRequestBody(input, toolContext, admission, processedAtSeconds) {
    if (!admission.provider
        || !admission.model
        || !isApprovedReceiptModel(admission.provider, admission.model)) {
        throw new Error('Approved Receipt Proposal model provenance is unavailable.');
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
            conversation_id: toolContext.deliveryContext?.to,
            owner_sender_id: toolContext.requesterSenderId,
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
export function reminderEventCapabilityRequestBody(capability, input, config, eventId, occurredAtSeconds) {
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
async function requestCapability(capability, body, config, signal) {
    signal?.throwIfAborted();
    const response = await fetch(`${CAPABILITY_ORIGIN}${CAPABILITY_PATH}`, {
        method: 'POST',
        headers: authorizationHeaders(body, config.keyId, config.privateKey, Math.floor(Date.now() / 1000).toString(), randomUUID()),
        body,
        signal,
    });
    if (!response.ok) {
        throw new CapabilityRequestError(`Money Assistant rejected ${capability} (${response.status}).`, response.status);
    }
    return response.json();
}
async function executeCapability(capability, input, config, toolContext, signal) {
    const nowSeconds = Math.floor(Date.now() / 1000);
    const admission = ownerMessageAdmissions.freshForSession(toolContext.sessionKey, nowSeconds);
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
async function requestReceiptProposal(body, config, signal) {
    const delays = [200, 1000];
    for (let attempt = 0; attempt <= delays.length; attempt += 1) {
        try {
            return await requestCapability('receipt.proposal.submit', body, config, signal);
        }
        catch (error) {
            const isTransient = !(error instanceof CapabilityRequestError)
                || error.status === 429
                || (error.status !== undefined && error.status >= 500);
            if (!isTransient || attempt === delays.length) {
                throw error;
            }
            await new Promise((resolveDelay) => setTimeout(resolveDelay, delays[attempt]));
        }
    }
    throw new Error('Receipt Proposal submission failed.');
}
async function executeReceiptProposal(input, config, toolContext, signal) {
    const admission = receiptPhotoAdmissions.freshForSession(toolContext.sessionKey, Math.floor(Date.now() / 1000));
    if (!admission) {
        throw new Error('Money Assistant receipt-photo admission is unavailable.');
    }
    try {
        const body = receiptProposalCapabilityRequestBody(input, toolContext, admission, Math.floor(Date.now() / 1000));
        const details = await requestReceiptProposal(body, config, signal);
        return {
            content: [{ type: 'text', text: JSON.stringify(details) }],
            details,
        };
    }
    finally {
        await receiptPhotoAdmissions.finishAdmission(admission);
    }
}
async function executeReminderEventCapability(capability, input, eventId, config, toolContext, signal) {
    if (!isBoundReminderEventInteraction(toolContext, config)
        || toolContext.sessionKey === undefined) {
        throw new Error('Money Assistant Reminder event binding is unavailable.');
    }
    const admission = reminderEventAdmissions.freshEventForSession(toolContext.sessionKey, eventId, Math.floor(Date.now() / 1000));
    if (!admission) {
        throw new Error('Money Assistant Reminder event admission is unavailable.');
    }
    const body = reminderEventCapabilityRequestBody(capability, input, config, eventId, admission.occurredAtSeconds);
    const details = await requestCapability(capability, body, config, signal);
    if (capability === 'reminder.read'
        && typeof details === 'object'
        && details !== null
        && 'delivery' in details
        && typeof details.delivery === 'object'
        && details.delivery !== null
        && 'channel_delivered_at' in details.delivery
        && details.delivery.channel_delivered_at !== null) {
        reminderEventAdmissions.markAlreadyDelivered(toolContext.sessionKey, eventId);
    }
    return {
        content: [{ type: 'text', text: JSON.stringify(details) }],
        details,
    };
}
export async function recordReminderChannelDelivery(admission, config) {
    const body = reminderEventCapabilityRequestBody('reminder.delivery.record', { event_id: admission.eventId }, config, admission.eventId, admission.occurredAtSeconds);
    const delays = [200, 1000];
    for (let attempt = 0; attempt <= delays.length; attempt += 1) {
        try {
            await requestCapability('reminder.delivery.record', body, config);
            return;
        }
        catch (error) {
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
export async function deleteReceiptSourceMessage(gateway, admission, config) {
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
    if (typeof result === 'object'
        && result !== null
        && 'ok' in result
        && result.ok !== true) {
        throw new Error('Telegram source message deletion failed.');
    }
}
export async function warnReceiptSourceDeletionFailed(gateway, admission, config) {
    await gateway.request('message.action', {
        channel: 'telegram',
        action: 'send',
        accountId: config.accountId,
        agentId: config.agentId,
        sessionKey: admission.sessionKey,
        idempotencyKey: randomUUID(),
        params: {
            target: config.conversationId,
            message: 'I could not delete the receipt photo from Telegram. Please remove it manually.',
        },
    });
}
function isPositiveSafeInteger(value) {
    return Number.isSafeInteger(value) && Number(value) > 0;
}
function isCategoryMutationInput(input) {
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
        && input.examples.every((example) => typeof example === 'string' && example.length <= 100);
}
function isReminderResponseInput(input) {
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
function hasExactKeys(input, expected) {
    return Object.keys(input).sort().join('\0') === [...expected].sort().join('\0');
}
function isReceiptProposalInput(input) {
    if (!hasExactKeys(input, ['transaction', 'line_items'])
        || typeof input.transaction !== 'object'
        || input.transaction === null
        || Array.isArray(input.transaction)
        || !Array.isArray(input.line_items)
        || input.line_items.length < 1
        || input.line_items.length > 200) {
        return false;
    }
    const transaction = input.transaction;
    if (!hasExactKeys(transaction, [
        'occurred_on',
        'amount_minor',
        'currency',
        'kind',
        'merchant_description',
    ])
        || typeof transaction.occurred_on !== 'string'
        || !/^\d{4}-\d{2}-\d{2}$/.test(transaction.occurred_on)
        || !isPositiveSafeInteger(transaction.amount_minor)
        || (transaction.currency !== 'USD' && transaction.currency !== 'PEN')
        || (transaction.kind !== 'purchase' && transaction.kind !== 'refund')
        || typeof transaction.merchant_description !== 'string'
        || transaction.merchant_description.trim() === ''
        || transaction.merchant_description.length > 255) {
        return false;
    }
    return input.line_items.every((candidate) => {
        if (typeof candidate !== 'object'
            || candidate === null
            || Array.isArray(candidate)) {
            return false;
        }
        const lineItem = candidate;
        return hasExactKeys(lineItem, ['description', 'line_total_minor'])
            && typeof lineItem.description === 'string'
            && lineItem.description.trim() !== ''
            && lineItem.description.length <= 255
            && Number.isSafeInteger(lineItem.line_total_minor)
            && Number(lineItem.line_total_minor) !== 0;
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
const receiptProposalToolDefinition = {
    name: 'money_assistant_receipt_proposal_submit',
    label: 'Submit Money Assistant Receipt Proposal',
    description: 'Submit structured image-free Transaction and Line Item details from the admitted receipt photo.',
    parameters: receiptProposalParameters,
};
const plugin = defineToolPlugin({
    id: 'money-assistant',
    name: 'Money Assistant',
    description: 'Reads and confirms bounded Money Assistant financial operations and submits image-free Receipt Proposals.',
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
                        const transactionId = params.transaction_id;
                        if (!Number.isSafeInteger(transactionId) || Number(transactionId) < 1) {
                            throw new Error('Money Assistant Transaction identifier is invalid.');
                        }
                        return executeCapability('transaction.read', { transaction_id: Number(transactionId) }, config, toolContext, signal);
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
                        const input = params;
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
                        return executeCapability('transaction.manual.prepare', {
                            idempotency_key: idempotencyKey,
                            occurred_on: occurredOn,
                            amount_minor: Number(amountMinor),
                            currency,
                            kind,
                            merchant_description: merchantDescription,
                        }, config, toolContext, signal);
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
                        const input = params;
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
                        return executeCapability('transaction.manual.confirm', {
                            idempotency_key: idempotencyKey,
                            pending_operation_id: pendingOperationId,
                            pending_operation_revision: Number(pendingOperationRevision),
                            payload_digest: payloadDigest,
                        }, config, toolContext, signal);
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
                        const input = params;
                        if (!isPositiveSafeInteger(input.page)
                            || !isPositiveSafeInteger(input.per_page)
                            || Number(input.per_page) > 100) {
                            throw new Error('Money Assistant Category page input is invalid.');
                        }
                        return executeCapability('category.read', {
                            page: Number(input.page),
                            per_page: Number(input.per_page),
                        }, config, toolContext, signal);
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
                        const input = params;
                        if (!isCategoryMutationInput(input)) {
                            throw new Error('Money Assistant Categorization input is invalid.');
                        }
                        return executeCapability('category.mutation.prepare', input, config, toolContext, signal);
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
                        const input = params;
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
                        return executeCapability('category.mutation.confirm', {
                            idempotency_key: idempotencyKey,
                            pending_operation_id: pendingOperationId,
                            pending_operation_revision: Number(pendingOperationRevision),
                            payload_digest: payloadDigest,
                        }, config, toolContext, signal);
                    },
                };
            },
        }),
        tool({
            ...receiptProposalToolDefinition,
            factory({ config, toolContext }) {
                if (!isBoundOwnerInteraction(toolContext, config)
                    || !receiptProcessingReady(config)) {
                    return null;
                }
                return {
                    ...receiptProposalToolDefinition,
                    async execute(_toolCallId, params, signal) {
                        const input = params;
                        if (!isReceiptProposalInput(input)) {
                            throw new Error('Money Assistant Receipt Proposal is invalid.');
                        }
                        return executeReceiptProposal(input, config, toolContext, signal);
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
                        const eventId = params.event_id;
                        if (typeof eventId !== 'string'
                            || !new RegExp(UUID_PATTERN).test(eventId)) {
                            throw new Error('Money Assistant Reminder event identifier is invalid.');
                        }
                        return executeReminderEventCapability('reminder.read', { event_id: eventId }, eventId, config, toolContext, signal);
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
                        const input = params;
                        if (!isReminderResponseInput(input)) {
                            throw new Error('Money Assistant Reminder response is invalid.');
                        }
                        return executeCapability('reminder.respond', input, config, toolContext, signal);
                    },
                };
            },
        }),
    ],
});
const registerTool = plugin.register;
plugin.register = (api) => {
    const config = api.pluginConfig;
    const runtimeReceiptPolicyReady = receiptRuntimePolicyReady(api.config, config.agentId);
    api.on('message_received', (event, context) => {
        if (receiptPhotoAdmissions.admit(event, context, config)) {
            ownerMessageAdmissions.clear(event.sessionKey ?? context.sessionKey);
            return;
        }
        ownerMessageAdmissions.admit(event, context, config);
    });
    api.on('before_model_resolve', (event, context) => {
        const receiptPhoto = receiptPhotoAdmissions.freshForRun(context.runId, context.sessionKey, Math.floor(Date.now() / 1000));
        if (receiptPhoto
            && receiptProcessingReady(config)
            && runtimeReceiptPolicyReady
            && receiptEffectiveAuthReady(api.config, config.agentId, context.sessionKey)) {
            return {
                providerOverride: APPROVED_RECEIPT_PROVIDER,
                modelOverride: 'gpt-5.6',
            };
        }
        const admission = admittedReminderEvent(event.prompt, context, config, Math.floor(Date.now() / 1000));
        if (admission && context.sessionKey) {
            reminderEventAdmissions.admit(context.sessionKey, admission.eventId, admission.occurredAtSeconds);
        }
    });
    api.on('before_agent_run', (_event, context) => {
        const blockCategory = receiptAdmissionBlockCategory(receiptPhotoAdmissions, context.runId, context.sessionKey, Math.floor(Date.now() / 1000));
        if (blockCategory === 'receipt_photo_concurrent') {
            return {
                outcome: 'block',
                reason: 'Another receipt photo is already active for this conversation.',
                message: 'I am still processing the previous receipt photo. Please retry this receipt after it finishes.',
                category: 'receipt_photo_concurrent',
            };
        }
        const receiptPhoto = receiptPhotoAdmissions.freshForRun(context.runId, context.sessionKey, Math.floor(Date.now() / 1000));
        if (blockCategory === 'receipt_photo_invalid') {
            return {
                outcome: 'block',
                reason: 'Receipt photo failed strict local validation.',
                message: 'That receipt photo could not be processed safely. Send one JPEG, PNG, or WebP image up to 20 MB and try again.',
                category: 'receipt_photo_invalid',
            };
        }
        if (blockCategory === 'receipt_photo_stale') {
            return {
                outcome: 'block',
                reason: 'Receipt-photo admission is stale or not bound to this run.',
                message: 'That receipt photo is no longer available for processing. Please send it again.',
                category: 'receipt_photo_stale',
            };
        }
        if (receiptPhoto && (!receiptProcessingReady(config)
            || !runtimeReceiptPolicyReady
            || !receiptEffectiveAuthReady(api.config, config.agentId, context.sessionKey))) {
            return {
                outcome: 'block',
                reason: 'Receipt-photo privacy policy is not confirmed.',
                message: RECEIPT_PRIVACY_DISCLOSURE,
                category: 'receipt_privacy_policy',
            };
        }
    });
    api.on('model_call_started', (event) => {
        receiptPhotoAdmissions.recordActualModel(event.runId, event.provider, event.model);
    });
    api.on('before_message_write', (event, context) => {
        if (shouldBlockReceiptMessageWrite(receiptPhotoAdmissions, event.sessionKey, context.sessionKey)) {
            return { block: true };
        }
    });
    api.on('agent_end', async (event, context) => {
        await receiptPhotoAdmissions.finishForRun(event.runId, context.sessionKey);
        receiptPhotoAdmissions.clearRejectedRun(event.runId);
    });
    api.on('before_agent_reply', (_event, context) => {
        if (context.agentId === config.agentId
            && context.sessionKey === REMINDER_HOOK_SESSION_KEY
            && consumeAlreadyDeliveredReminder(context.sessionKey, reminderEventAdmissions, Math.floor(Date.now() / 1000))) {
            return {
                handled: true,
                reason: 'Money Assistant Reminder event was already delivered.',
            };
        }
    });
    api.on('message_sending', (event, context) => {
        if (shouldSuppressReminderDelivery(event, context, config, reminderEventAdmissions, Math.floor(Date.now() / 1000))) {
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
            const reminderAdmission = reminderEventAdmissions.takeFreshForSession(sessionKey, Math.floor(Date.now() / 1000));
            if (reminderAdmission) {
                try {
                    await recordReminderChannelDelivery(reminderAdmission, config);
                }
                catch (error) {
                    reminderEventAdmissions.admit(sessionKey, reminderAdmission.eventId, reminderAdmission.occurredAtSeconds);
                    throw error;
                }
            }
        }
        if (event.success !== true
            || context.channelId !== 'telegram'
            || context.accountId !== config.accountId
            || context.conversationId !== config.conversationId) {
            return;
        }
        const receiptAdmissions = receiptPhotoAdmissions.takePendingSourceDeletions(sessionKey);
        for (const receiptAdmission of receiptAdmissions) {
            try {
                await deleteReceiptSourceMessage(api.runtime.gateway, receiptAdmission, config);
            }
            catch {
                await warnReceiptSourceDeletionFailed(api.runtime.gateway, receiptAdmission, config);
            }
        }
    });
    registerTool(api);
};
export default plugin;
