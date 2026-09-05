import { run, codex } from '@ai-hero/sandcastle';
import { noSandbox } from '@ai-hero/sandcastle/sandboxes/no-sandbox';

// Run this with: npx tsx .sandcastle/main.ts

await run({
    name: 'worker',
    sandbox: noSandbox(),
    agent: codex('gpt-5.6-sol', {
        effort: 'high',
    }),

    // Path to the prompt file. Shell expressions inside are evaluated inside the
    // sandbox at the start of each iteration, so the agent always sees fresh data.
    promptFile: './.sandcastle/prompt.md',

    // Maximum number of iterations (agent invocations) to run in a session.
    // Each iteration works on a single issue. Increase this to process more issues
    // per run, or set it to 1 for a single-shot mode.
    maxIterations: 5,

    branchStrategy: { type: 'head' },
});
