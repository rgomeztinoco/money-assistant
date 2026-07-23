# Money Assistant

Money Assistant records personal spending and helps its owner understand and improve their spending patterns.

## Language

**Transaction**:
A confirmed personal money movement that immediately affects spending totals as either a purchase or a Refund, even when some details remain uncertain and require review. A saved manual entry or supported Spending Notification is sufficient confirmation.
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
The owner-facing classification assigned to a Transaction for spending analysis. Its assignment may be uncertain and require approval or correction.
_Avoid_: Tag, type

**Review Queue**:
The collection of uncertain Transaction details awaiting the owner's approval or correction. A Transaction can enter the Review Queue without delaying its inclusion in spending records.
_Avoid_: Pending transactions, approval queue

**Correction**:
An owner-provided replacement for the current value of an extracted or inferred Transaction detail. It takes effect immediately and is authoritative feedback for future classification.
_Avoid_: Override, edit

**Learned Rule**:
A visible and reversible classification pattern derived from Corrections or explicitly created by the owner. It may assign Transaction details when its match is sufficiently reliable.
_Avoid_: Model training, hidden preference

**Receipt Breakdown**:
An itemized allocation attached to a Transaction whose reconciled amounts explain how that Transaction contributes to Categories. It does not create additional Transactions.
_Avoid_: Nested transactions, child transactions

**Line Item**:
A single purchased item or adjustment within a Receipt Breakdown, with its own amount and Category.
_Avoid_: Sub-transaction

**Reporting Currency**:
The owner-selected currency in which combined USD-and-PEN insights are expressed. Currency-specific insights remain available in each Transaction's original currency.
_Avoid_: Default currency, display currency

**Daily Exchange Rate**:
The owner-editable PEN value of one USD for a calendar date, shared by all combined reporting for that date. Replacing it recalculates affected combined insights without retaining a revision history.
_Avoid_: Transaction exchange rate, live exchange rate

**Refund**:
A separate Transaction that reverses all or part of an earlier purchase and reduces spending totals. It may be linked to that purchase without changing the original Transaction.
_Avoid_: Income, credit

**Spending Baseline**:
A representative view of the owner's typical spending derived from complete historical periods. It provides context for later targets and improvement suggestions.
_Avoid_: Budget, goal

**Category Target**:
An owner-approved spending intention for a Category over a period. It is set only after the owner has enough Spending Baseline information to make it meaningful.
_Avoid_: Baseline, AI budget
