# Represent all confirmed money movements as Transactions

Status: Accepted.

Every confirmed posted movement is represented by one Transaction with two independent facts: Movement Direction records whether money moved out or in, while Transaction Kind records Spending, Refund or reimbursement, Income, or Transfer. Statement confirmation links every actual Statement Movement to a Transaction and continues to exclude balances, limits, headings, and other informational values.

Period reporting keeps currencies separate and exposes three summaries: Net Spending is Spending minus Refunds and reimbursements; Income is separate; and Moved to Savings is outbound Savings Transfers minus inbound Savings Transfers. Card payments and ordinary internal Transfers affect none of these summaries. The model does not calculate net external cash flow.

Spending and Refunds may use Spending Categories, Merchant Rules, Receipt Breakdowns, and Refund relationships. Income uses its separate Income Source taxonomy. Transfers use a Transfer Purpose, with Savings summarized and card payments or other internal Transfers excluded from Spending and Income.

This decision does not introduce account balances, liability accounting, or home-equity accounting. A full mortgage payment may remain Spending under Housing. It supersedes ADR-0001 and ADR-0002.
