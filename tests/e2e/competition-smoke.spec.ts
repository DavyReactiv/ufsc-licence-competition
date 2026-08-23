import { expect, test } from '@playwright/test';
import { expectNoFatalError, loginAsAdmin } from './helpers';

test.beforeEach(async ({ page }) => {
  await loginAsAdmin(page);
});

test('competition creation exposes Pancrace, premium workflow and regional access UX', async ({ page }) => {
  await page.goto('/wp-admin/admin.php?page=ufsc-competitions&ufsc_action=add');
  await expectNoFatalError(page);

  await expect(page.getByRole('heading', { name: /Ajouter une compétition/i })).toBeVisible();
  await expect(page.locator('.ufsc-premium-workflow')).toBeVisible();
  await expect(page.locator('.ufsc-premium-workflow__step')).toHaveCount(6);

  const discipline = page.locator('#discipline');
  await expect(discipline.locator('option[value="pancrace"]')).toHaveText(/Pancrace/i);

  const eventType = page.locator('#type');
  for (const value of ['competition', 'tournoi', 'coupe', 'open', 'gala']) {
    await expect(eventType.locator(`option[value="${value}"]`)).toHaveCount(1);
  }

  const accessMode = page.locator('select[name="access_mode"]');
  await accessMode.selectOption('regions');
  const regions = page.locator('select[name="allowed_regions[]"]');
  await expect(regions).toBeVisible();
  await expect(page.locator('.ufsc-live-access-summary')).toContainText(/région/i);

  await accessMode.selectOption('clubs');
  await expect(page.locator('select[name="allowed_club_ids[]"]')).toBeVisible();
  await expect(regions).toBeHidden();
});

test('a gala can contain a six-fighter belt tournament without changing gala type', async ({ page }) => {
  const galaName = `Gala QA ${Date.now()}`;
  await page.goto('/wp-admin/admin.php?page=ufsc-competitions&ufsc_action=add');
  await expectNoFatalError(page);

  await page.locator('#name').fill(galaName);
  await page.locator('#discipline').selectOption('pancrace');
  await page.locator('#type').selectOption('gala');
  const season = page.locator('#season');
  if (await season.count()) {
    await season.fill('2026-2027');
  }
  const status = page.locator('#status');
  if (await status.count()) {
    await status.selectOption('open');
  }
  await page.getByRole('button', { name: /^Créer$/i }).click();
  await expectNoFatalError(page);

  await page.goto('/wp-admin/admin.php?page=ufsc-competitions-program');
  await expectNoFatalError(page);
  await expect(page.getByRole('heading', { name: /Programme de l’événement/i })).toBeVisible();

  const selector = page.locator('#ufsc-program-competition');
  await selector.selectOption({ label: galaName });
  await page.getByRole('button', { name: /Ouvrir le programme/i }).click();
  await expectNoFatalError(page);
  await expect(page.locator('.ufsc-premium-callout')).toContainText(/Gala hybride autorisé/i);

  await page.getByRole('button', { name: /Mini-tournoi/i }).click();
  const blocks = page.locator('[data-ufsc-program-blocks] [data-ufsc-program-block]');
  await expect(blocks).toHaveCount(2);

  const tournament = blocks.nth(1);
  await tournament.locator('[data-ufsc-field="label"]').fill('Ceinture -75 kg');
  await tournament.locator('[data-ufsc-field="weight_class"]').fill('-75 kg');
  await tournament.locator('[data-ufsc-field="participant_target"]').fill('6');
  await tournament.locator('[data-ufsc-field="trophy"]').fill('Ceinture du gala');
  await page.getByRole('button', { name: /Enregistrer le programme/i }).click();

  await expectNoFatalError(page);
  await expect(page.locator('.notice-success')).toContainText(/Programme enregistré/i);
  await expect(page.locator('[data-ufsc-program-blocks] [data-ufsc-program-block]')).toHaveCount(2);
  await expect(page.locator('[data-ufsc-field="weight_class"]').last()).toHaveValue('-75 kg');
  await expect(page.locator('[data-ufsc-field="participant_target"]').last()).toHaveValue('6');
});

test('critical competition admin pages load without PHP or database errors', async ({ page }) => {
  const pages = [
    '/wp-admin/admin.php?page=ufsc-competitions',
    '/wp-admin/admin.php?page=ufsc-competitions-categories',
    '/wp-admin/admin.php?page=ufsc-competitions-entries',
    '/wp-admin/admin.php?page=ufsc-competitions-program',
    '/wp-admin/admin.php?page=ufsc-competitions-bouts',
    '/wp-admin/admin.php?page=ufsc-competitions-plateau',
    '/wp-admin/admin.php?page=ufsc-competitions-results',
  ];

  for (const url of pages) {
    await page.goto(url);
    await expectNoFatalError(page);
    await expect(page.locator('.ufsc-competitions-admin').first()).toBeVisible();
    await expect(page.locator('.ufsc-premium-workflow')).toBeVisible();
  }
});
