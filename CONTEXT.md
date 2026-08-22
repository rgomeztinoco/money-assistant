# Money Assistant

Money Assistant records personal spending and helps its owner understand and improve their spending patterns.

## Language

**Transaction**:
A confirmed posted movement of money represented once with an independent Movement Direction and financial meaning: Spending, Refund or reimbursement, Income, or Transfer. A Transaction may come from manual entry, a supported Spending Notification, or a Statement Movement, and it remains confirmed even when details require review.
_Avoid_: Expense, purchase, cash-flow entry

**Movement Direction**:
Whether money moved out of an account (debit) or into an account (credit). Direction records what happened to the account and does not determine the movement's financial meaning.
_Avoid_: Transaction type, financial meaning

**Spending**:
A Transaction meaning for money spent on goods, services, fees, taxes, or obligations. Spending increases Net Spending and may use a Category or Receipt Breakdown. A full mortgage payment may remain Spending under Housing without representing a liability or home-equity ledger.
_Avoid_: Debit, expense record

**Refund or reimbursement**:
A Transaction meaning for money returned after Spending or reimbursed by another party. It reduces Net Spending and may be linked to the original Spending Transaction without rewriting either movement.
_Avoid_: Income, credit

**Income**:
A Transaction meaning for money earned or otherwise received as income. It is summarized separately from Net Spending and uses an Income Source rather than a Spending Category.
_Avoid_: Refund, credit

**Income Source**:
The small owner-facing taxonomy for Income: Salary, Independent work, Investments, or Other income. It is independent from Spending Categories.
_Avoid_: Category, merchant

**Transfer**:
A Transaction meaning for money moved between the owner's accounts or used to pay a card. Transfers do not affect Net Spending or Income. Savings Transfers contribute to Moved to Savings; card payments and ordinary internal Transfers do not.
_Avoid_: Spending, Income

**Transfer Purpose**:
The reason for a Transfer: Moved to savings, Card payment, or Other transfer. It determines whether the movement contributes to Moved to Savings without changing its Movement Direction.
_Avoid_: Category, direction

**Net Spending**:
Spending minus Refunds and reimbursements for one currency and period. Income and Transfers never reduce it.
_Avoid_: Net external cash flow, balance change

**Moved to Savings**:
Savings Transfers moving money out minus savings withdrawals moving money in, for one currency and period. It is not spending, income, equity, or a complete account balance.
_Avoid_: Savings spending, net external cash flow

**Voided Transaction**:
A retained Transaction excluded from period summaries because it does not represent an actual confirmed movement in its current form. Voiding is reversible.
_Avoid_: Deleted transaction

**Spending Notification**:
A Gmail email from a financial account or payment method reporting a Transaction. It is read transiently as source evidence and remains in Gmail rather than being copied into Money Assistant.
_Avoid_: Receipt, candidate transaction

**Spending Notification Reference**:
The minimal Gmail identity and processing outcome retained for a Gmail message evaluated as a possible Spending Notification without storing its content. It may remain unlinked or support exactly one Transaction.
_Avoid_: Stored notification, raw email

**Statement Import**:
An owner-initiated conversion of every posted Statement Movement from a supported Financial Statement Format. Every actual movement creates one linked Transaction, while balances, limits, headings, and other informational values remain excluded.
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
BCP's automatic savings feature. Money moved into or withdrawn from WARDA is a Transfer with the Savings purpose. Deposits increase Moved to Savings and withdrawals reduce it without affecting Net Spending or Income.
_Avoid_: Guarda, ordinary transfer

**Parser Profile**:
An owner-created, owner-enabled definition that trusts declared authenticated senders, identifies supported Spending Notification Formats, and extracts Transaction details through deterministic rules validated against a transiently observed Gmail message.
_Avoid_: Learned parser, sender rule

**Spending Notification Format**:
An independently identifiable message layout that a Parser Profile supports only when representative fixtures validate its matching and extraction behavior.
_Avoid_: Sender, institution format

**Category**:
The owner-facing classification assigned to a Spending or Refund Transaction or its Line Item for spending analysis. Income uses an Income Source, and Transfers use a Transfer Purpose. An unassigned Spending or Refund amount reports in the system Uncategorized bucket. Categories form a customizable two-level taxonomy: either a top-level Category or one of its second-level Categories may be assigned, and second-level spending rolls up to its current parent. Active Category names are case-insensitively unique among siblings, while the same child name may appear under different parents. A Category keeps its identity and historical assignments when renamed or moved, and its current name and parent apply across historical reporting.
_Avoid_: Tag, type

**Archived Category**:
A Category that keeps its historical assignments and report contribution but is unavailable for new assignments or Merchant Rules. Archiving does not reassign existing Transactions or Line Items, disables Merchant Rules that target the Category, and archives the active children of a top-level Category. It may be unarchived with the same identity when its parent is active and its name does not conflict with an active sibling.
_Avoid_: Deleted Category

**Uncategorized Transaction**:
A confirmed Spending or Refund Transaction with no owner Category or deterministic Merchant Rule match. It remains included in Net Spending, appears in the system Uncategorized reporting bucket, and waits in the Review Queue; Income and Transfers are never Uncategorized.
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
An owner-created exact merchant-to-active-Category mapping for whole Spending or Refund Transactions, optionally scoped by those meanings or currency. Its deterministic merchant key normalizes case, Unicode, punctuation, and whitespace; when enabled, it categorizes only future Uncategorized Transactions, and its complete scope cannot conflict with another enabled Merchant Rule.
_Avoid_: Model training, hidden preference

**Receipt Breakdown**:
An itemized allocation attached to a Spending or Refund Transaction whose reconciled amounts replace that Transaction's own Category contribution. Saving an initial or replacement Receipt Breakdown succeeds atomically only when its signed Line Items reconcile exactly to the Transaction amount. It does not create additional Transactions or change the Transaction's amount; the retained Transaction Category is only a fallback.
_Avoid_: Nested transactions, child transactions

**Line Item**:
A single purchased item or explicitly shown adjustment within a Receipt Breakdown, with its own authoritative signed line total and Category. Positive adjustments increase the reconciled amount; negative adjustments reduce it. Quantity and unit price may provide review context but do not determine its line total.
_Avoid_: Sub-transaction

**Unidentified Line Item**:
An owner-confirmed, Uncategorized Line Item representing a known amount whose receipt detail is unavailable. It may reconcile a partial receipt and remains in the Review Queue; Money Assistant may not invent it from an arithmetic remainder.
_Avoid_: Balancing item, miscellaneous item
