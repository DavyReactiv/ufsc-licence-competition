import { expect, test } from '@playwright/test';
import { expectNoFatalError, loginAsAdmin } from './helpers';

test.beforeEach(async ({ page }) => {
  await loginAsAdmin(page);
});

test('competition form exposes configurable club document checks', async ({ page }) => {
  await page.goto('/wp-admin/admin.php?page=ufsc-competitions&ufsc_action=add');
  await expectNoFatalError(page);

  const block = page.locator('#ufsc-competition-requirements-fields');
  await expect(block).toBeVisible();
  await expect(block.getByRole('heading', { name: /Pièces & contrôles demandés aux clubs/i })).toBeVisible();

  const mode = block.locator('#requirements_mode');
  await expect(mode).toHaveValue('auto');
  await expect(block.getByText(/rappel médical et autorisation parentale/i)).toBeVisible();

  await mode.selectOption('custom');
  await expect(block.locator('input[name="check_medical_document"]')).toBeVisible();
  await expect(block.locator('input[name="check_parental_authorization"]')).toBeVisible();
  await expect(block.locator('input[name="check_sport_passport"]')).toBeVisible();
  await expect(block.locator('#requirements_note')).toBeVisible();
});
