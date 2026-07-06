const { defineConfig, devices } = require('@playwright/test');

module.exports = defineConfig({
  testDir: __dirname,
  outputDir: '../../MyFiles/Cache/playwright-rich-line-description',
  reporter: [['list']],
  timeout: 30000,
  use: {
    ...devices['Desktop Chrome'],
    channel: 'chrome',
    screenshot: 'only-on-failure',
    trace: 'retain-on-failure',
  },
});
