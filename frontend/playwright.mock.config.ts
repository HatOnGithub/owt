import { defineConfig } from '@playwright/test';
import process from 'node:process';

export default defineConfig({
  testDir: './dev/e2e',
  workers: process.env['CI'] ? 2 : 4,
  fullyParallel: true,
  retries: 0,
  use: {
    baseURL: 'http://127.0.0.1:4300',
    headless: true,
    viewport: { width: 1440, height: 1000 },
    trace: 'retain-on-failure',
    screenshot: 'only-on-failure',
  },
  projects: [
    {
      name: 'chromium',
      use: {
        browserName: 'chromium',
        launchOptions: { args: ['--autoplay-policy=user-gesture-required'] },
      },
    },
    {
      name: 'firefox',
      use: {
        browserName: 'firefox',
        launchOptions: {
          // Fresh test contexts can classify localhost as a bounce tracker before
          // the first automated click on slow runners. This is a first-party mock.
          firefoxUserPrefs: { 'privacy.bounceTrackingProtection.mode': 0 },
        },
      },
    },
    {
      name: 'webkit',
      // Concurrent Windows WebKit workers can stall during document initialization.
      // Keep its assertions intact and serialize this platform's browser instances.
      workers: process.platform === 'win32' ? 1 : undefined,
      use: { browserName: 'webkit' },
    },
  ],
  webServer: {
    // Build once instead of serving hundreds of development modules on each new page.
    // Interactive development still uses start-mock.mjs with the Angular dev server.
    command: 'node dev/start-e2e.mjs',
    url: 'http://127.0.0.1:4300',
    reuseExistingServer: false,
    timeout: 60000,
  },
  outputDir: 'test-results/mock',
  reporter: [
    ['list'],
    ['html', { outputFolder: 'playwright-report/mock', open: 'never' }],
    ['junit', { outputFile: 'test-results/mock/results.xml' }],
  ],
  timeout: process.env['CI'] ? 60000 : 20000,
});
