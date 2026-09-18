<?php

use Symfony\Component\Process\Process;

test('date presentation rejects malformed values and renders neutral timestamp SSR output', function () {
    $script = <<<'JS'
        import path from 'node:path';
        import React from 'react';
        import { renderToString } from 'react-dom/server';
        import react from '@vitejs/plugin-react';
        import { createServer } from 'vite';

        const server = await createServer({
            configFile: false,
            appType: 'custom',
            logLevel: 'silent',
            plugins: [react()],
            resolve: {
                alias: {
                    '@': path.resolve('resources/js'),
                },
            },
            server: {
                middlewareMode: true,
            },
        });

        try {
            const presentation = await server.ssrLoadModule(
                '/resources/js/lib/date-presentation.ts',
            );
            const { LocalTimestamp } = await server.ssrLoadModule(
                '/resources/js/components/date-time.tsx',
            );
            const captureError = (callback) => {
                try {
                    callback();

                    return null;
                } catch (error) {
                    return error.constructor.name;
                }
            };

            process.stdout.write(JSON.stringify({
                impossibleDate: captureError(() =>
                    presentation.formatFullDate('2026-02-30'),
                ),
                reversedRange: captureError(() =>
                    presentation.formatDateRange('2026-09-19', '2026-09-18'),
                ),
                nonIsoInstant: captureError(() =>
                    presentation.formatTimestamp(
                        'September 18, 2026 14:30 UTC',
                        'UTC',
                    ),
                ),
                normalizedInstant: captureError(() =>
                    presentation.formatTimestamp(
                        '2026-02-30T14:30:00Z',
                        'UTC',
                    ),
                ),
                invalidCalendarDate: captureError(() =>
                    presentation.formatCalendarMonth(new Date('invalid')),
                ),
                ssr: renderToString(
                    React.createElement(LocalTimestamp, {
                        value: '2026-09-18T14:30:00Z',
                    }),
                ),
            }));
        } finally {
            await server.close();
        }
        JS;
    $process = new Process(
        ['node', '--input-type=module', '--eval', $script],
        base_path(),
    );
    $process->setTimeout(30);
    $process->mustRun();
    $result = json_decode($process->getOutput(), true, flags: JSON_THROW_ON_ERROR);

    expect($result)
        ->impossibleDate->toBe('RangeError')
        ->reversedRange->toBe('RangeError')
        ->nonIsoInstant->toBe('RangeError')
        ->normalizedInstant->toBe('RangeError')
        ->invalidCalendarDate->toBe('RangeError')
        ->ssr->toContain('Local time loading')
        ->ssr->toContain('...')
        ->ssr->not->toContain('18 Sep 2026');
});
