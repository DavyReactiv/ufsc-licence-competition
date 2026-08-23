import { expect, test } from '@playwright/test';
import { expectNoFatalError, loginAsAdmin } from './helpers';

test.beforeEach(async ({ page }) => {
  await loginAsAdmin(page);
});

test('competition creation exposes Pancrace and all core event types', async ({ page }) => {
  await page.goto('/wp-admin/admin.php?page=ufsc-competitions&ufsc_action=add');
  await expectNoFatalError(page);

  await expect(page.getByRole('heading', { name: /Ajouter une compétition/i })).toBeVisible();

  const discipline = page.locator('#discipline');
  await expect(discipline.locator('option[value="pancrace"]')).toHaveText(/Pancrace/i);

  const eventType = page.locator('#type');
  for (const value of ['competition', 'tournoi', 'coupe', 'open', 'gala']) {
    await expect(eventType.locator(`option[value="${value}"]`)).toHaveCount(1);
  }
});

test('critical competition admin pages load without PHP fatal errors', async ({ page }) => {
  const pages = [
    '/wp-admin/admin.php?page=ufsc-competitions',
    '/wp-admin/admin.php?page=ufsc-competitions-categories',
    '/wp-admin/admin.php?page=ufsc-competitions-entries',
    '/wp-admin/admin.php?page=ufsc-competitions-bouts',
    '/wp-admin/admin.php?page=ufsc-competitions-plateau',
    '/wp-admin/admin.php?page=ufsc-competitions-results',
  ];

  for (const url of pages) {
    await page.goto(url);
    await expectNoFatalError(page);
    await expect(page.locator('.ufsc-competitions-admin').first()).toBeVisible();
  }
});
