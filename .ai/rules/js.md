---
paths:
  - 'resources/js/**'
---

# Js

## Keep desktop pages within the viewport and preserve context
Follow the existing Breakdown and Trends page patterns. Never build pages as repeated or nested cards; use a simple shared layout, compact summaries, and clear visual hierarchy.
On desktop, keep the main workspace within the viewport. Scroll only the content that needs it, such as tables or lists, while headers, controls, and summaries remain visible. Use tabs for states such as Active and Paused instead of stacked tables.
Use charts freely when they make the information easier to understand. Keep labels and helper copy brief; omit explanations that repeat what the interface already makes clear. Preserve usable responsive layouts.

## Build on fitting shadcn components
Before writing custom UI, check existing project components and shadcn components for a fit. Use the fitting shadcn component as the base and compose its supported parts and variants. Create custom UI only when the existing components cannot meet the interaction or presentation need.
