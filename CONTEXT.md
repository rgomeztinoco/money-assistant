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
An owner-enabled, versioned definition that trusts declared authenticated senders, identifies supported Spending Notification Formats, and extracts Transaction details using validated rules.
_Avoid_: Learned parser, sender rule

**Spending Notification Format**:
An independently identifiable message layout that a Parser Profile supports only when representative fixtures validate its matching and extraction behavior.
_Avoid_: Sender, institution format

**Category**:
The owner-facing classification assigned to a Transaction or Line Item for spending analysis. Its assignment may be uncertain and require approval or Correction; an unassigned amount reports in the system Uncategorized bucket. Categories form a customizable two-level taxonomy: either a top-level Category or one of its second-level Categories may be assigned, and second-level spending rolls up to its current parent. Active Category names are case-insensitively unique among siblings, while the same child name may appear under different parents. A Category may include an owner-editable description and examples that guide AI classification but do not alter deterministic Learned Rule matching. When no active Category fits adequately, AI may propose a new top-level or second-level Category with suggested guidance, but only an explicit owner confirmation creates it and assigns it to the current Transaction; applying it to other existing Transactions requires a separate, previewed owner action. A Category keeps its identity and historical assignments when renamed or moved, and its current name and parent apply across historical reporting. An assignment may be uncertain and require approval or Correction.
_Avoid_: Tag, type

**Retired Category**:
A Category that remains on historical assignments and reports but is unavailable for new assignments and cannot be targeted by active Learned Rules. Only a never-referenced Category may be deleted; one with any historical assignment, Learned Rule revision, or other financial reference must instead be retired. Retirement does not reassign existing Transactions or Line Items. A Category cannot be retired until every active Learned Rule targeting it has been explicitly revised or retired, and a top-level Category also requires each active child to be moved or retired explicitly. It may be reactivated with the same identity when its name does not conflict with an active sibling, but its former Learned Rules remain retired until separately reactivated.
_Avoid_: Deleted Category

**Uncategorized Transaction**:
A confirmed Transaction with no sufficiently reliable Category assignment. It remains included in total spending, appears in the system Uncategorized reporting bucket, and waits in the Review Queue; Uncategorized is not a customizable Category or a Learned Rule target. Classifier unavailability is recorded distinctly from low confidence and may be retried only until the owner supplies a Category.
_Avoid_: Uncategorized Category

**Provisional Category Assignment**:
A non-authoritative Category applied by AI when no Learned Rule resolves the assignment. Classification uses only the normalized merchant or short description, Transaction kind, amount and currency, and the active Category paths with their owner-provided guidance. It carries both a discrete confidence outcome and a visible numeric model-confidence estimate, which is not a guarantee of observed accuracy. Scores of at least 95% are high confidence, scores from 60% through 94% are medium confidence, and lower scores are low confidence. High-confidence assignments affect reports without creating review work only after at least 50 predictions from the current classifier version at that score have been owner-reviewed and at least 95% were approved unchanged. A classifier-version change or a change to active taxonomy membership or AI guidance resets that gate; simple Category renames or moves do not. Once validated, ten percent of high-confidence assignments remain review samples; if fewer than 95% of the latest 50 reviewed high-confidence assignments are approved unchanged, the gate closes again. Until the gate is met, high scores enter the Review Queue as medium-confidence assignments; lower scores also require review, with low-confidence outcomes remaining Uncategorized. Owner approval or Correction replaces the provisional status with an authoritative assignment.
_Avoid_: Confirmed Category

**Category Assignment Provenance**:
The visible source of a Transaction's current Category: an owner action, a linked Refund's purchase, a specific Learned Rule revision, or an AI classification. AI provenance retains the classifier version, numeric confidence, discrete outcome, and short explanation, but not the raw prompt or response.
_Avoid_: Classification log

**Review Queue**:
The collection of uncertain Transaction details awaiting the owner's approval or correction. A Transaction can enter the Review Queue without delaying its inclusion in spending records.
_Avoid_: Pending transactions, approval queue

**Correction**:
An owner-provided replacement for the current value of an extracted or inferred Transaction detail. It takes effect immediately and is authoritative feedback for future classification, but never activates, revises, or retires a Learned Rule without owner confirmation. When it contradicts the rule that supplied the value, the owner may keep the Correction as a one-off exception, revise or narrow the rule, or retire it. An owner-approved or corrected Category is authoritative for that Transaction and cannot be replaced by later AI predictions or Learned Rule changes without another explicit owner action. An owner-confirmed bulk categorization creates authoritative Corrections with shared provenance and may be undone as a group, restoring each immediately previous Category unless that Transaction changed again afterward.
_Avoid_: Override, edit

**Confirmation Grant**:
A 30-minute, single-use authorization issued by Money Assistant after the owner reviews an exact proposed operation and explicitly approves it. Each owner conversation may have only one pending grant; preparing another operation cancels the previous one. A grant expires sooner if a referenced resource, the proposed inputs, or the capability schema version changes. It is bound to that operation, its complete inputs, the owner, and the immutable approval identity, so OpenClaw may carry out the confirmed change but cannot broaden or replay it. A grant may cover a finite, fully itemized bundle only when every change succeeds or none does; it cannot authorize an open-ended future scope. Ordinary single-resource changes may use an unambiguous affirmative response in a new message from the paired, allowlisted owner conversation, while changes affecting many Transactions require the owner to return an exact, short confirmation phrase generated for that operation. Export and permanent deletion instead require fresh passkey-authenticated approval in Money Assistant's web interface. A prior or inferred instruction is not confirmation. Read-only queries, reminder delivery, and submission of a Receipt Proposal do not require a Confirmation Grant because they do not alter confirmed financial state; approving, correcting, or reconciling that state does.
_Avoid_: Blanket approval, confirmation token

**OpenClaw Access**:
OpenClaw's authenticated authority within Money Assistant's application boundary. It includes task-shaped capabilities plus generic query and domain-action mutation access to Money Assistant's financial resources and owner-facing settings, including Categories, Learned Rules, Parser Profile enablement, Reporting Currency, Daily Exchange Rates, Category Targets, Reminders, and manual replay of failed processing. Every call is bound for at most 30 minutes to either a distinct message from the paired, allowlisted owner conversation or a Money Assistant-issued Reminder or event; OpenClaw has no background or self-initiated access. Money Assistant continues to enforce every domain invariant, Confirmation Grant, and audit requirement. Owner-visible financial values returned through OpenClaw Access may enter its configured cloud model context, so every query is field-minimized and bounded; raw Gmail content, receipt images, credentials, private audit identifiers, and server data are excluded, while full exports are delivered only through a freshly authenticated web flow. Audit events are readable but never mutable through OpenClaw Access. OpenClaw may prepare exports and permanent deletion, but their Confirmation Grants require fresh passkey-authenticated web approval. OpenClaw Access never includes direct database, filesystem, shell, server configuration, OAuth connections, credentials, channel bindings, backups, networking, recovery codes, deployment, or framework-operator access.
_Avoid_: Full access, direct database access

**Learned Rule**:
A visible, reversible, and revisioned merchant-centered classification pattern derived from Corrections or explicitly created by the owner. It classifies whole Transactions, not Receipt Breakdown Line Items. Its deterministic merchant comparison key may case-fold, Unicode-normalize, standardize punctuation, and collapse whitespace while retaining the displayed text losslessly; it removes volatile fragments only when a Parser Profile declares them explicitly. The resulting match is exact, starts with, or contains, and may be narrowed by Transaction kind, currency, or payment instrument; it does not use dates, amounts, institution references, regular expressions, fuzzy matching, or opaque AI similarity. A Correction may originate only an exact, narrow merchant match; broadening requires an explicit owner choice and a preview of existing matches. One Correction may offer an immediate opt-in rule, while two separate, consistent Corrections to the same exact merchant pattern may proactively suggest one; a scoped suggestion must meet that threshold independently within its scope, and neither form activates without owner confirmation. Dismissing a suggestion suppresses the same pattern, scope, and target until the owner revisits it or its conditions materially change. An active Learned Rule takes precedence over AI predictions; when several match, the provably most specific wins, while equally specific rules assigning different Categories leave the Transaction Uncategorized for owner review. Creation and revision previews expose overlapping rules and their precedence; a confirmed, more-specific override is allowed, while an equally specific contradiction is blocked until resolved. Creating, revising, retiring, or reactivating a rule changes only future matching; historical matches change only through an owner-confirmed bulk application, and each assignment retains the rule revision that produced it.
_Avoid_: Model training, hidden preference

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
