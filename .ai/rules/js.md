---
paths:
  - 'resources/js/**'
---

# Js

## Keep desktop pages within the viewport and preserve context
Follow the existing Breakdown and Trends page patterns with compact summaries and clear visual hierarchy.
On desktop, keep the main workspace within the viewport. Scroll only the content that needs it, such as tables or lists, while headers, controls, and summaries remain visible. Use tabs for states such as Active and Paused instead of stacked tables.
Use charts freely when they make the information easier to understand. Keep labels and helper copy brief; omit explanations that repeat what the interface already makes clear. Preserve usable responsive layouts.

## Build on fitting shadcn components
Before writing custom UI, check existing project components and shadcn components for a fit. Use the fitting shadcn component as the base and compose its supported parts and variants. Create custom UI only when the existing components cannot meet the interaction or presentation need.

## Use section cards and column layouts
Cards are encouraged to group meaningful page sections. Use one card level: a section Card must not contain another Card.
On desktop, divide summaries, controls, charts, and working content into useful columns so tables stay readable instead of stretching across the whole page. Stack columns on smaller screens; preserve the desktop viewport and internal scrolling.
