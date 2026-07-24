import { createHash, createPrivateKey, randomUUID, sign, } from 'node:crypto';
import { defineToolPlugin } from 'openclaw/plugin-sdk/tool-plugin';
import { Type } from 'typebox';
const CAPABILITY_ORIGIN = 'http://127.0.0.1:8443';
const CAPABILITY_PATH = '/api/openclaw/v1/transport';
const TOOL_NAME = 'money_assistant_transaction_read';
const pluginConfigSchema = Type.Object({
    keyId: Type.String({ minLength: 1, maxLength: 128 }),
    privateKey: Type.String({ minLength: 1 }),
    agentId: Type.String({ minLength: 1, maxLength: 128 }),
    accountId: Type.String({ minLength: 1, maxLength: 128 }),
    conversationId: Type.String({ minLength: 1, maxLength: 128 }),
    ownerSenderId: Type.String({ minLength: 1, maxLength: 128 }),
}, { additionalProperties: false });
const transactionParameters = Type.Object({
    transaction_id: Type.Integer({ minimum: 1 }),
}, { additionalProperties: false });
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
const transactionToolDefinition = {
    name: TOOL_NAME,
    label: 'Read Money Assistant Transaction',
    description: 'Read one Transaction by its Money Assistant identifier.',
    parameters: transactionParameters,
};
const plugin = defineToolPlugin({
    id: 'money-assistant',
    name: 'Money Assistant',
    description: 'Reads one field-minimized Money Assistant Transaction.',
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
                        signal?.throwIfAborted();
                        const transactionId = params.transaction_id;
                        if (!Number.isSafeInteger(transactionId) || Number(transactionId) < 1) {
                            throw new Error('Money Assistant Transaction identifier is invalid.');
                        }
                        const nowSeconds = Math.floor(Date.now() / 1000);
                        const admission = ownerMessageAdmissions.freshForSession(toolContext.sessionKey, nowSeconds);
                        if (!admission) {
                            throw new Error('Money Assistant owner message admission is unavailable.');
                        }
                        const timestamp = nowSeconds.toString();
                        const occurredAt = new Date(admission.occurredAtSeconds * 1000)
                            .toISOString()
                            .replace('.000Z', 'Z');
                        const body = JSON.stringify({
                            schema_version: 1,
                            capability: 'transaction.read',
                            interaction: {
                                kind: 'owner_message',
                                agent_id: toolContext.agentId,
                                account_id: toolContext.agentAccountId,
                                conversation_id: toolContext.deliveryContext?.to,
                                owner_sender_id: toolContext.requesterSenderId,
                                message_id: admission.messageId,
                                occurred_at: occurredAt,
                            },
                            input: {
                                transaction_id: transactionId,
                            },
                        });
                        const nonce = randomUUID();
                        const response = await fetch(`${CAPABILITY_ORIGIN}${CAPABILITY_PATH}`, {
                            method: 'POST',
                            headers: authorizationHeaders(body, config.keyId, config.privateKey, timestamp, nonce),
                            body,
                            signal,
                        });
                        if (!response.ok) {
                            throw new Error(`Money Assistant rejected the read (${response.status}).`);
                        }
                        const details = await response.json();
                        return {
                            content: [{ type: 'text', text: JSON.stringify(details) }],
                            details,
                        };
                    },
                };
            },
        }),
    ],
});
const registerTool = plugin.register;
plugin.register = (api) => {
    const config = api.pluginConfig;
    api.on('message_received', (event, context) => {
        ownerMessageAdmissions.admit(event, context, config);
    });
    registerTool(api);
};
export default plugin;
