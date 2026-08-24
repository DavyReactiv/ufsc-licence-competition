import { expect, Page } from '@playwright/test';

export async function loginAsAdmin(page: Page): Promise<void> {
  await page.goto('/wp-login.php');
  await page.locator('#user_login').fill(process.env.WP_ADMIN_USER || 'admin');
  await page.locator('#user_pass').fill(process.env.WP_ADMIN_PASSWORD || 'password');
  await page.locator('#wp-submit').click();
  await expect(page.locator('body')).toHaveClass(/wp-admin/);
}

/**
 * Browser smoke tests must reject the errors that are easy to miss when only
 * checking HTTP 200 responses. WP_DEBUG deliberately stays enabled in wp-env.
 */
export async function expectNoFatalError(page: Page): Promise<void> {
  const body = page.locator('body');
  const forbiddenMessages = [
    'There has been a critical error on this website',
    'Fatal error',
    'Uncaught Error',
    'WordPress database error',
    'dépendances manquantes',
    "doesn't exist",
  ];

  for (const message of forbiddenMessages) {
    await expect(body, `Unexpected WordPress/PHP error detected: ${message}`).not.toContainText(message);
  }
}
