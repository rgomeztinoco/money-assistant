# Money Assistant

Money Assistant records personal spending and helps its owner understand and improve their spending patterns.

## Language

**Transaction**:
A confirmed personal money movement that immediately affects spending totals as either a purchase or a Refund, even when some details remain uncertain and require review. A saved manual entry or supported Spending Notification is sufficient confirmation.
_Avoid_: Expense, purchase

**Voided Transaction**:
A retained Transaction determined not to represent actual spending and therefore excluded from spending totals. Voiding is reversible.
_Avoid_: Deleted transaction

**Spending Notification**:
A Gmail email from a financial account or payment method reporting a Transaction. It is read transiently as source evidence and remains in Gmail rather than being copied into Money Assistant.
_Avoid_: Receipt, candidate transaction

**Spending Notification Reference**:
The minimal Gmail identity and processing outcome retained for a Gmail message evaluated as a possible Spending Notification without storing its content. It may remain unlinked or support exactly one Transaction.
_Avoid_: Stored notification, raw email

**Statement Import**:
An owner-initiated conversion of every posted Statement Movement from a supported Financial Statement Format. Movements that contribute to spending also create linked Transactions, while other movements remain visible only within the Statement Import.
_Avoid_: Transaction Import, statement synchronization

**Financial Statement Format**:
An independently identifiable provider PDF layout supported by Statement Import through deterministic extraction rules.
_Avoid_: Provider, generic PDF

**Statement Movement**:
A posted financial statement row representing an actual movement of money, including spending, a Refund, income, or a transfer. Balances, limits, subtotals, and payment instructions are informational statement values rather than Statement Movements.
_Avoid_: Statement row, imported Transaction

**Import Preview**:
The transient, owner-editable set of proposed Statement Movements and explicitly excluded informational values produced before confirming a Statement Import. It does not affect spending totals and is discarded if not confirmed.
_Avoid_: Pending Transactions, import draft

**Statement Import Reference**:
The minimal identity and outcome retained for a confirmed Statement Import so the same PDF cannot be imported twice. The source PDF and its extracted text are not retained.
_Avoid_: Stored statement, import file

**WARDA**:
BCP's automatic savings feature. Money moved into WARDA contributes positive spending under a Savings Category, while money withdrawn from WARDA contributes a Refund under the same Category so reports show the net amount saved.
_Avoid_: Guarda, ordinary transfer

**Parser Profile**:
An owner-created, owner-enabled definition that trusts declared authenticated senders, identifies supported Spending Notification Formats, and extracts Transaction details through deterministic rules validated against a transiently observed Gmail message.
_Avoid_: Learned parser, sender rule

**Spending Notification Format**:
An independently identifiable message layout that a Parser Profile supports only when representative fixtures validate its matching and extraction behavior.
_Avoid_: Sender, institution format

**Category**:
The owner-facing classification assigned to a Transaction or Line Item for spending analysis. An unassigned amount reports in the system Uncategorized bucket. Categories form a customizable two-level taxonomy: either a top-level Category or one of its second-level Categories may be assigned, and second-level spending rolls up to its current parent. Active Category names are case-insensitively unique among siblings, while the same child name may appear under different parents. A Category keeps its identity and historical assignments when renamed or moved, and its current name and parent apply across historical reporting.
_Avoid_: Tag, type

**Archived Category**:
A Category that keeps its historical assignments and report contribution but is unavailable for new assignments or Merchant Rules. Archiving does not reassign existing Transactions or Line Items, disables Merchant Rules that target the Category, and archives the active children of a top-level Category. It may be unarchived with the same identity when its parent is active and its name does not conflict with an active sibling.
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
An owner-provided change to a current Transaction value. It takes effect immediately, and changing a flagged value clears that field's current review flag. A direct edit neither creates nor changes a Merchant Rule, and later rule changes cannot replace an owner-assigned Category without another explicit owner action.
_Avoid_: Override

**Merchant Rule**:
An owner-created exact merchant-to-active-Category mapping for whole Transactions, optionally scoped by Transaction kind or currency. Its deterministic merchant key normalizes case, Unicode, punctuation, and whitespace; when enabled, it categorizes only future Uncategorized Transactions, and its complete scope cannot conflict with another enabled Merchant Rule.
_Avoid_: Model training, hidden preference

**Receipt Breakdown**:
An itemized allocation attached to a Transaction whose reconciled amounts replace that Transaction's own Category contribution. Saving an initial or replacement Receipt Breakdown succeeds atomically only when its signed Line Items reconcile exactly to the Transaction amount. It does not create additional Transactions or change the Transaction's amount; the retained Transaction Category is only a fallback.
_Avoid_: Nested transactions, child transactions

**Line Item**:
A single purchased item or explicitly shown adjustment within a Receipt Breakdown, with its own authoritative signed line total and Category. Positive adjustments increase the reconciled amount; negative adjustments reduce it. Quantity and unit price may provide review context but do not determine its line total.
_Avoid_: Sub-transaction

**Unidentified Line Item**:
An owner-confirmed, Uncategorized Line Item representing a known amount whose receipt detail is unavailable. It may reconcile a partial receipt and remains in the Review Queue; Money Assistant may not invent it from an arithmetic remainder.
_Avoid_: Balancing item, miscellaneous item

**Refund**:
A separate Transaction that reverses all or part of an earlier purchase and reduces spending totals. It may be linked to that purchase without changing the original Transaction, but the link never copies or infers a Receipt Breakdown; every Refund allocation requires owner review.
_Avoid_: Income, credit
