type BindingConfiguration = {
    agentId: string;
    accountId: string;
    conversationId: string;
    ownerSenderId: string;
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
type CapabilityInput = Record<string, unknown>;
export declare function admittedOwnerMessage(event: InboundMessage, context: InboundMessageContext, config: BindingConfiguration): AdmittedOwnerMessage | null;
export declare class OwnerMessageAdmissions {
    private readonly messages;
    admit(event: InboundMessage, context: InboundMessageContext, config: BindingConfiguration): void;
    freshForSession(sessionKey: string | undefined, nowSeconds: number): AdmittedOwnerMessage | null;
}
export declare function isBoundOwnerInteraction(toolContext: TrustedToolContext, config: BindingConfiguration): boolean;
export declare function authorizationHeaders(body: string, keyId: string, encodedPrivateKey: string, timestamp: string, nonce: string): Record<string, string>;
export declare function capabilityRequestBody(capability: string, input: CapabilityInput, toolContext: TrustedToolContext, admission: AdmittedOwnerMessage): string;
declare const plugin: import("openclaw/plugin-sdk/tool-plugin").DefinedToolPluginEntry;
export default plugin;
