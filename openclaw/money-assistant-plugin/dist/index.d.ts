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
export declare function admittedOwnerMessage(event: InboundMessage, context: InboundMessageContext, config: BindingConfiguration): AdmittedOwnerMessage | null;
export declare class OwnerMessageAdmissions {
    private readonly messages;
    admit(event: InboundMessage, context: InboundMessageContext, config: BindingConfiguration): void;
    freshForSession(sessionKey: string | undefined, nowSeconds: number): AdmittedOwnerMessage | null;
}
export declare class ReminderEventAdmissions {
    private readonly events;
    admit(sessionKey: string, eventId: string, occurredAtSeconds: number): void;
    freshForSession(sessionKey: string | undefined, nowSeconds: number): AdmittedReminderEvent | null;
    freshEventForSession(sessionKey: string | undefined, eventId: string, nowSeconds: number): AdmittedReminderEvent | null;
    markAlreadyDelivered(sessionKey: string, eventId: string): void;
    takeFreshForSession(sessionKey: string | undefined, nowSeconds: number): AdmittedReminderEvent | null;
}
export declare function isBoundOwnerInteraction(toolContext: TrustedToolContext, config: BindingConfiguration): boolean;
export declare function isBoundReminderEventInteraction(toolContext: TrustedToolContext, config: BindingConfiguration): boolean;
export declare function admittedReminderEvent(prompt: string, context: ReminderRunContext, config: BindingConfiguration, nowSeconds: number): AdmittedReminderEvent | null;
export declare function isBoundReminderChannelDelivery(event: SentMessage, context: SentMessageContext, config: BindingConfiguration): boolean;
export declare function shouldSuppressReminderDelivery(event: OutgoingMessage, context: SentMessageContext, config: BindingConfiguration, admissions: ReminderEventAdmissions, nowSeconds: number): boolean;
export declare function consumeAlreadyDeliveredReminder(sessionKey: string | undefined, admissions: ReminderEventAdmissions, nowSeconds: number): boolean;
export declare function authorizationHeaders(body: string, keyId: string, encodedPrivateKey: string, timestamp: string, nonce: string): Record<string, string>;
export declare function capabilityRequestBody(capability: string, input: CapabilityInput, toolContext: TrustedToolContext, admission: AdmittedOwnerMessage): string;
export declare function reminderEventCapabilityRequestBody(capability: string, input: CapabilityInput, config: BindingConfiguration, eventId: string, occurredAtSeconds: number): string;
export declare function recordReminderChannelDelivery(admission: AdmittedReminderEvent, config: PluginConfiguration): Promise<void>;
declare const plugin: import("openclaw/plugin-sdk/tool-plugin").DefinedToolPluginEntry;
export default plugin;
