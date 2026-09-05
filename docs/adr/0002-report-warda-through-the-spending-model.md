# Report WARDA through the spending model

Status: Superseded by ADR-0003.

Money moved into WARDA creates a Purchase Transaction under an owner-selected Savings Category, while money withdrawn from WARDA creates an unlinked Refund under the same Category. Although WARDA is an owned savings feature rather than ordinary spending, this deliberate convention lets existing reports show the net amount saved without introducing global account or cash-flow reporting in Statement Import v1.
