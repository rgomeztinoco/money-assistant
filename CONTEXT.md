# Money Assistant

Money Assistant records personal spending and helps its owner understand and improve their spending patterns.

## Language

**Transaction**:
A confirmed personal money movement that immediately affects spending totals as either a purchase or a Refund, even when some details remain uncertain and require review. A saved manual entry, supported Spending Notification, or owner-confirmed Receipt Proposal is sufficient confirmation.
_Avoid_: Expense, purchase

**Voided Transaction**:
A retained Transaction determined not to represent actual spending and therefore excluded from spending totals. Voiding is reversible.
_Avoid_: Deleted transaction

**Suspected Duplicate**:
A Transaction whose distinct source evidence or manual entry resembles another Transaction closely enough to require owner review. It remains separate until the owner resolves the relationship.
_Avoid_: Duplicate transaction

**Spending Notification**:
A Gmail email from a financial account or payment method reporting a Transaction. It is read transiently as source evidence and remains in Gmail rather than being copied into Money Assistant.
_Avoid_: Receipt, candidate transaction

**Spending Notification Reference**:
The minimal Gmail identity and processing outcome retained for a Gmail message evaluated as a possible Spending Notification without storing its content. It may remain unlinked or support exactly one Transaction.
_Avoid_: Stored notification, raw email

**Parser Profile**:
An owner-created, owner-enabled, versioned definition that trusts declared authenticated senders, identifies supported Spending Notification Formats, and extracts Transaction details through deterministic rules validated against a transiently observed Gmail message.
_Avoid_: Learned parser, sender rule

**Spending Notification Format**:
An independently identifiable message layout that a Parser Profile supports only when representative fixtures validate its matching and extraction behavior.
_Avoid_: Sender, institution format

**Category**:
The owner-facing classification assigned to a Transaction or Line Item for spending analysis. An unassigned amount reports in the system Uncategorized bucket. Categories form a customizable two-level taxonomy: either a top-level Category or one of its second-level Categories may be assigned, and second-level spending rolls up to its current parent. Active Category names are case-insensitively unique among siblings, while the same child name may appear under different parents. A Category keeps its identity and historical assignments when renamed or moved, and its current name and parent apply across historical reporting.
_Avoid_: Tag, type

**Retired Category**:
A Category that remains on historical assignments and reports but is unavailable for new assignments or Merchant Rules. Only a never-referenced Category may be deleted; one with any historical assignment, Merchant Rule, or other financial reference must instead be retired. Retirement does not reassign existing Transactions or Line Items. A Category cannot be retired until every Merchant Rule targeting it has been retargeted or deleted, and a top-level Category also requires each active child to be moved or retired explicitly. It may be reactivated with the same identity when its name does not conflict with an active sibling.
_Avoid_: Deleted Category

**Uncategorized Transaction**:
A confirmed Transaction with no owner Category or deterministic Merchant Rule match. It remains included in total spending, appears in the system Uncategorized reporting bucket, and waits in the Review Queue; Uncategorized is not a customizable Category or a Merchant Rule target.
_Avoid_: Uncategorized Category

**Category Assignment Provenance**:
The visible source of a Transaction's current Category: an owner action, a linked Refund's purchase, or a specific Merchant Rule.
_Avoid_: Classification log

**Review Queue**:
The current set of Uncategorized Transactions, Uncategorized Line Items, and flagged Transaction details that need an owner edit. It is derived from current financial state rather than maintained as a separate approval workflow, and a Transaction remains included in spending while it needs review.
_Avoid_: Pending transactions, approval queue

**Transaction Edit**:
An owner-provided change to a current Transaction value. It takes effect immediately without a separate Correction or revision contract; changing a flagged value clears that field's current review flag. A direct edit neither creates nor changes a Merchant Rule, and later rule changes cannot replace an owner-assigned Category without another explicit owner action.
_Avoid_: Correction, override

**Confirmation Grant**:
A 30-minute, single-use authorization issued by Money Assistant after the owner reviews an exact proposed operation and explicitly approves it. Each owner conversation may have only one pending grant; preparing another operation cancels the previous one. A grant expires sooner if a referenced resource, the proposed inputs, or the capability schema version changes. It is bound to that operation, its complete inputs, the owner, and the immutable approval identity, so OpenClaw may carry out the confirmed change but cannot broaden or replay it. A grant may cover a finite, fully itemized bundle only when every change succeeds or none does; it cannot authorize an open-ended future scope. Ordinary single-resource changes may use an unambiguous affirmative response in a new message from the paired, allowlisted owner conversation, while changes affecting many Transactions require the owner to return an exact, short confirmation phrase generated for that operation. Export and permanent deletion instead require fresh passkey-authenticated approval in Money Assistant's web interface. A prior or inferred instruction is not confirmation. Read-only queries, reminder delivery, and submission of a Receipt Proposal do not require a Confirmation Grant because they do not alter confirmed financial state; approving, correcting, or reconciling that state does.
_Avoid_: Blanket approval, confirmation token

**OpenClaw Access**:
OpenClaw's authenticated authority within Money Assistant's application boundary. It includes task-shaped capabilities plus generic query and domain-action mutation access to Money Assistant's financial resources and owner-facing settings, including Categories, Merchant Rules, Parser Profile enablement, Reporting Currency, Daily Exchange Rates, Category Targets, Reminders, and manual replay of failed processing. Every call is bound for at most 30 minutes to either a distinct message from the paired, allowlisted owner conversation or a Money Assistant-issued Reminder or event; OpenClaw has no background or self-initiated access. Money Assistant continues to enforce every domain invariant, Confirmation Grant, and audit requirement. Owner-visible financial values returned through OpenClaw Access may enter its configured cloud model context, so every query is field-minimized and bounded; raw Gmail content, receipt images, credentials, private audit identifiers, and server data are excluded, while full exports are delivered only through a freshly authenticated web flow. Audit events are readable but never mutable through OpenClaw Access. OpenClaw may prepare exports and permanent deletion, but their Confirmation Grants require fresh passkey-authenticated web approval. OpenClaw Access never includes direct database, filesystem, shell, server configuration, OAuth connections, credentials, channel bindings, backups, networking, recovery codes, deployment, or framework-operator access.
_Avoid_: Full access, direct database access

**Merchant Rule**:
An owner-created exact merchant-to-active-Category mapping for whole Transactions, optionally scoped by Transaction kind or currency. Its deterministic merchant key normalizes case, Unicode, punctuation, and whitespace; when enabled, it categorizes only future Uncategorized Transactions, and its complete scope cannot conflict with another enabled Merchant Rule.
_Avoid_: Learned Rule, model training, hidden preference

**Receipt Breakdown**:
An itemized allocation attached to a Transaction whose reconciled amounts replace that Transaction's own Category contribution while active. It does not create additional Transactions or change the Transaction's amount; the retained Transaction Category is only a fallback.
_Avoid_: Nested transactions, child transactions

**Draft Receipt Breakdown**:
An unconfirmed initial or replacement itemization attached to a Transaction. Its Line Items do not affect reporting until their signed amounts reconcile exactly to the Transaction and the owner explicitly confirms them; meanwhile, reporting continues through the current confirmed Receipt Breakdown or, when none exists, the Transaction's Category.
_Avoid_: Partial allocation, balancing item

**Receipt Proposal**:
A structured, image-free set of proposed Transaction and Receipt Breakdown details that OpenClaw derives from a deliberately submitted owner receipt photo for Money Assistant to validate and review. Money Assistant accepts it without a Confirmation Grant only when OpenClaw attests a distinct photo message from the paired, allowlisted owner conversation. The message identity belongs to the protected request audit rather than Receipt Proposal provenance; the image never crosses into Money Assistant.
_Avoid_: Receipt extraction result, stored receipt

**Reminder**:
A Money Assistant-owned prompt about a current financial task or condition that OpenClaw delivers to the owner. Acknowledging it records that the owner saw it, snoozing defers the same Reminder until an owner-selected time, and dismissing closes that occurrence without changing financial state or preventing a later qualifying occurrence. Completing its offered domain action resolves it automatically. OpenClaw neither owns its schedule or recurrence nor treats delivery as resolution.
_Avoid_: OpenClaw cron, notification state

**Line Item**:
A single purchased item or explicitly shown adjustment within a Receipt Breakdown, with its own authoritative signed line total and Category. Positive adjustments increase the reconciled amount; negative adjustments reduce it. Quantity and unit price may provide review context but do not determine its line total.
_Avoid_: Sub-transaction

**Unidentified Line Item**:
An owner-confirmed, Uncategorized Line Item representing a known amount whose receipt detail is unavailable. It may reconcile a partial receipt and remains in the Review Queue; neither Money Assistant nor OpenClaw may invent it from an arithmetic remainder.
_Avoid_: Balancing item, miscellaneous item

**Reporting Currency**:
The owner-selected currency in which combined USD-and-PEN insights are expressed. Currency-specific insights remain available in each Transaction's original currency.
_Avoid_: Default currency, display currency

**Daily Exchange Rate**:
The owner-editable PEN value of one USD for a calendar date, shared by all combined reporting for that date. Replacing it recalculates affected combined insights without retaining a revision history.
_Avoid_: Transaction exchange rate, live exchange rate

**Refund**:
A separate Transaction that reverses all or part of an earlier purchase and reduces spending totals. It may be linked to that purchase without changing the original Transaction, but the link never copies or infers a Receipt Breakdown; every Refund allocation requires owner review.
_Avoid_: Income, credit

**Spending Baseline**:
A recent reference for the owner's spending derived from complete calendar months. A calendar month is complete once it has ended and none of its Transactions remain in the Review Queue. After one or two complete months, the Spending Baseline is provisional: the months remain visible as history, but Money Assistant does not make baseline comparisons or propose Category Targets. Three complete months establish the Spending Baseline and permit those comparisons and proposals. An established Spending Baseline is the arithmetic average of the latest three complete months. During a month, comparisons use the preceding three complete months; once the current month becomes complete, it joins the rolling window for the following month. Every complete month participates; the MVP does not classify or exclude exceptional months.
_Avoid_: Budget, goal, normal month, typical spending

**Spending Insight**:
An owner-facing, descriptive comparison derived from recorded spending. For the MVP, a completed month may be compared with its preceding Spending Baseline using factual amount and percentage differences for total spending or a Category, described specifically as a comparison with the preceding three-month average rather than with normal or typical spending. An incomplete month may show spending to date and Category Target progress, but Money Assistant does not forecast its month-end spending or claim that the owner is on track.
_Avoid_: Forecast, prediction

**Category Target**:
An owner-approved recurring monthly spending intention for a Category. It is set only after the owner has an established Spending Baseline. Money Assistant may propose the Category's three-month baseline average as a starting amount, but it does not infer a desired reduction or activate the Category Target; the owner must approve or edit the amount. At most one Category Target is active for a Category at a time. It begins in an owner-selected calendar month and repeats until the owner revises or retires it. Its amount remains in the Reporting Currency selected when the owner approved it; later Reporting Currency changes do not convert or otherwise alter the Category Target. A Category Target may belong to a top-level or second-level Category. A second-level target measures only its Category, while a top-level target includes spending assigned directly to it and spending rolled up from its children. Parent and child targets may coexist, but their amounts are evaluated independently and are never added together. Each approved amount is an effective-dated revision: a revision or retirement may take effect in the current or a future calendar month but cannot rewrite a completed month, and prior target results remain visible. Revisions are owner-initiated and explicitly approved; Money Assistant may show the latest Spending Baseline and historical target results as context but does not recommend or apply a replacement amount in the MVP. Progress is the Category's net monthly spending—purchases minus Refunds—expressed in the target's currency using Daily Exchange Rates. It exposes the amount spent, the amount remaining or exceeded, and the percentage used. Incomplete-month progress is explicitly spending to date and is not a forecast; completed-month results state factually whether the target was met or exceeded. A zero amount is valid and means the owner intends no spending in that Category; its progress shows the amount remaining or exceeded without a percentage.
_Avoid_: Baseline, AI budget
