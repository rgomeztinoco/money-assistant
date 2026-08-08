export declare const RECEIPT_PRIVACY_DISCLOSURE = "Receipt processing uses the existing OpenAI OAuth account and only openai/gpt-5.6-sol. OpenAI OAuth has no published fixed retention ceiling. Before enabling receipts, disable account-wide model improvement and Codex full-environment training. Receipt interactions are never submitted as feedback. OpenClaw deletes local images after proposal submission or terminal failure, enforces a one-hour crash-cleanup ceiling, then attempts to delete the Telegram source and warns if manual removal is needed. Money Assistant retains only the opaque proposal identifier, receipt_photo source kind, processing time, actual provider/model, contract version, and structured financial proposal.";
export declare function isFinancialExportPreparationInput(input: Record<string, unknown>): input is Record<string, unknown> & {
    idempotency_key: string;
};
export declare function isFinancialDeletionPreparationInput(input: Record<string, unknown>): input is Record<string, unknown> & {
    idempotency_key: string;
    resource_type: 'category' | 'receipt_breakdown';
    resource_id: number;
    expected_revision: number;
};
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
type ReceiptPolicyConfiguration = Pick<PluginConfiguration, 'receiptProcessingEnabled' | 'receiptDisclosureDelivered' | 'receiptDisclosureAccepted' | 'openAiModelImprovementDisabled' | 'codexFullEnvironmentTrainingDisabled' | 'openAiOAuthProfileId' | 'openAiOAuthCredentialVersion' | 'receiptPolicyVersion' | 'receiptConfirmedPolicyVersion' | 'receiptConfirmedOAuthProfileId' | 'receiptConfirmedOAuthCredentialVersion'>;
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
export declare function admittedOwnerMessage(event: InboundMessage, context: InboundMessageContext, config: BindingConfiguration): AdmittedOwnerMessage | null;
export declare class OwnerMessageAdmissions {
    private readonly messages;
    admit(event: InboundMessage, context: InboundMessageContext, config: BindingConfiguration): void;
    clear(sessionKey: string | undefined): void;
    freshForSession(sessionKey: string | undefined, nowSeconds: number): AdmittedOwnerMessage | null;
}
export declare function receiptProcessingReady(config: ReceiptPolicyConfiguration): boolean;
export declare function receiptRuntimePolicyReady(runtimeConfig: unknown, agentId: string): boolean;
export declare function receiptEffectiveAuthStateReady(profiles: Record<string, unknown>, resolvedOrder: string[], sessionEntry?: unknown): boolean;
declare function safeReceiptPath(path: string, root: string): string | null;
export declare function inspectReceiptImage(path: string, root: string, declaredMimeType: string): string | null;
export declare function admittedReceiptPhoto(event: InboundMessage, context: InboundMessageContext, config: ReceiptBindingConfiguration, inspectImage?: typeof inspectReceiptImage): ValidatedReceiptPhoto | null;
export declare function isApprovedReceiptModel(provider: string, model: string): boolean;
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
export declare class ReceiptPhotoAdmissions {
    private readonly dependencies;
    private readonly photos;
    private readonly pendingSourceDeletions;
    private readonly rejectedRuns;
    private readonly rejectedSessionsWithoutRun;
    private readonly identitiesBySourceMessage;
    private readonly sensitiveSessions;
    private readonly boundResponseDeliveredSessions;
    constructor(dependencies?: ReceiptAdmissionDependencies);
    admit(event: InboundMessage, context: InboundMessageContext, config: ReceiptBindingConfiguration): boolean;
    freshForSession(sessionKey: string | undefined, nowSeconds: number): AdmittedReceiptPhoto | null;
    freshForRun(runId: string | undefined, sessionKey: string | undefined, nowSeconds: number): AdmittedReceiptPhoto | null;
    hasConflictingRun(runId: string | undefined, sessionKey: string | undefined): boolean;
    consumeRejectedRun(runId: string | undefined, sessionKey: string | undefined): boolean;
    clearRejectedRun(runId: string | undefined): void;
    activeForSession(sessionKey: string | undefined): AdmittedReceiptPhoto | null;
    recordActualModel(runId: string, provider: string, model: string): boolean;
    isSensitiveSession(sessionKey: string | undefined): boolean;
    markBoundResponseDelivered(sessionKey: string | undefined): void;
    finishForSession(sessionKey: string | undefined): Promise<void>;
    finishAdmission(photo: AdmittedReceiptPhoto): Promise<void>;
    finishForRun(runId: string | undefined, sessionKey?: string): Promise<AdmittedReceiptPhoto[]>;
    takePendingSourceDeletions(sessionKey: string | undefined): AdmittedReceiptPhoto[];
    private expire;
    private removeLocalImage;
    private queueSourceDeletion;
}
type ReceiptAdmissionBlockCategory = 'receipt_photo_concurrent' | 'receipt_photo_invalid' | 'receipt_photo_stale';
export declare function receiptAdmissionBlockCategory(admissions: ReceiptPhotoAdmissions, runId: string | undefined, sessionKey: string | undefined, nowSeconds: number): ReceiptAdmissionBlockCategory | null;
export declare function shouldBlockReceiptMessageWrite(admissions: ReceiptPhotoAdmissions, eventSessionKey: string | undefined, contextSessionKey: string | undefined): boolean;
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
export declare function isBoundReceiptChannelDelivery(event: SentMessage, context: SentMessageContext, config: BindingConfiguration): boolean;
export declare function shouldSuppressReminderDelivery(event: OutgoingMessage, context: SentMessageContext, config: BindingConfiguration, admissions: ReminderEventAdmissions, nowSeconds: number): boolean;
export declare function consumeAlreadyDeliveredReminder(sessionKey: string | undefined, admissions: ReminderEventAdmissions, nowSeconds: number): boolean;
export declare function authorizationHeaders(body: string, keyId: string, encodedPrivateKey: string, timestamp: string, nonce: string): Record<string, string>;
export declare function capabilityRequestBody(capability: string, input: CapabilityInput, toolContext: TrustedToolContext, admission: AdmittedOwnerMessage): string;
export declare function receiptProposalCapabilityRequestBody(input: CapabilityInput, toolContext: TrustedToolContext, admission: AdmittedReceiptPhoto, processedAtSeconds: number): string;
export declare function reminderEventCapabilityRequestBody(capability: string, input: CapabilityInput, config: BindingConfiguration, eventId: string, occurredAtSeconds: number): string;
export declare function recordReminderChannelDelivery(admission: AdmittedReminderEvent, config: CapabilityConfiguration): Promise<void>;
type GatewayRequestRuntime = {
    request: (method: string, params?: Record<string, unknown>) => Promise<unknown>;
};
export declare function deleteReceiptSourceMessage(gateway: GatewayRequestRuntime, admission: AdmittedReceiptPhoto, config: CapabilityConfiguration): Promise<void>;
export declare function warnReceiptSourceDeletionFailed(gateway: GatewayRequestRuntime, admission: AdmittedReceiptPhoto, config: CapabilityConfiguration): Promise<void>;
export declare function isReceiptBreakdownMutationInput(input: Record<string, unknown>): boolean;
declare const plugin: import("openclaw/plugin-sdk/tool-plugin").DefinedToolPluginEntry;
export default plugin;
