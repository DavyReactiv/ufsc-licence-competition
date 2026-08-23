import { expect, Page } from '@playwright/test';

export async function loginAsAdmin(page: Page): Promise<void> {
  await page.goto('/wp-login.php');
  await page.locator('#user_login').fill(process.env.WP_ADMIN_USER || 'admin');
  await page.locator('#user_pass').fill(process.env.WP_ADMIN_PASSWORD || 'password');
  await page.locator('#wp-submit').click();
  await expect(page.locator('body')).toHaveClass(/wp-admin/);
}

export async function expectNoFatalError(page: Page): Promise<void> {
  const body = page.locator('body');
  await expect(body).not.toContainText('There has been a critical error on this website');
  await expect(body).not.toContainText('Fatal error');
  await expect(body).not.toContainText('Uncaught Error');
}
