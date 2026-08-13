# Money Assistant

Money Assistant records personal spending and helps its owner understand and improve their spending patterns.

## Language

**Owner Account**:
The single private authentication identity through which the owner accesses Money Assistant. Financial records and configuration belong to the application as a whole because no second owner can exist.
_Avoid_: User account, tenant

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

**Merchant Rule**:
An owner-created exact merchant-to-active-Category mapping for whole Transactions, optionally scoped by Transaction kind or currency. Its deterministic merchant key normalizes case, Unicode, punctuation, and whitespace; when enabled, it categorizes only future Uncategorized Transactions, and its complete scope cannot conflict with another enabled Merchant Rule.
_Avoid_: Learned Rule, model training, hidden preference

**Receipt Breakdown**:
An itemized allocation attached to a Transaction whose reconciled amounts replace that Transaction's own Category contribution while active. It does not create additional Transactions or change the Transaction's amount; the retained Transaction Category is only a fallback.
_Avoid_: Nested transactions, child transactions

**Draft Receipt Breakdown**:
An unconfirmed initial or replacement itemization attached to a Transaction. Its Line Items do not affect reporting until their signed amounts reconcile exactly to the Transaction and the owner explicitly confirms them; meanwhile, reporting continues through the current confirmed Receipt Breakdown or, when none exists, the Transaction's Category.
_Avoid_: Partial allocation, balancing item

**Line Item**:
A single purchased item or explicitly shown adjustment within a Receipt Breakdown, with its own authoritative signed line total and Category. Positive adjustments increase the reconciled amount; negative adjustments reduce it. Quantity and unit price may provide review context but do not determine its line total.
_Avoid_: Sub-transaction

**Unidentified Line Item**:
An owner-confirmed, Uncategorized Line Item representing a known amount whose receipt detail is unavailable. It may reconcile a partial receipt and remains in the Review Queue; Money Assistant may not invent it from an arithmetic remainder.
_Avoid_: Balancing item, miscellaneous item

**Refund**:
A separate Transaction that reverses all or part of an earlier purchase and reduces spending totals. It may be linked to that purchase without changing the original Transaction, but the link never copies or infers a Receipt Breakdown; every Refund allocation requires owner review.
_Avoid_: Income, credit
