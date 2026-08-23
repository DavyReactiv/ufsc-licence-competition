import fs from 'node:fs/promises';
import path from 'node:path';
import { test } from '@playwright/test';
import { expectNoFatalError, loginAsAdmin } from './helpers';

const output = path.resolve('test-results/visual');

test('capture the premium competition administration on desktop and mobile', async ({ page }) => {
  await fs.mkdir(output, { recursive: true });
  await loginAsAdmin(page);

  const screens = [
    { slug: 'competitions', url: '/wp-admin/admin.php?page=ufsc-competitions' },
    { slug: 'competition-add', url: '/wp-admin/admin.php?page=ufsc-competitions&ufsc_action=add' },
    { slug: 'entries', url: '/wp-admin/admin.php?page=ufsc-competitions-entries' },
    { slug: 'categories', url: '/wp-admin/admin.php?page=ufsc-competitions-categories' },
    { slug: 'weighins', url: '/wp-admin/admin.php?page=ufsc-competitions-weighins' },
    { slug: 'program', url: '/wp-admin/admin.php?page=ufsc-competitions-program' },
    { slug: 'combats', url: '/wp-admin/admin.php?page=ufsc-competitions-bouts' },
    { slug: 'plateau', url: '/wp-admin/admin.php?page=ufsc-competitions-plateau' },
    { slug: 'results', url: '/wp-admin/admin.php?page=ufsc-competitions-results' },
    { slug: 'quality', url: '/wp-admin/admin.php?page=ufsc-competitions-quality' },
    { slug: 'settings', url: '/wp-admin/admin.php?page=ufsc-competitions-settings' },
  ];

  await page.setViewportSize({ width: 1440, height: 1200 });
  for (const screen of screens) {
    await page.goto(screen.url);
    await expectNoFatalError(page);
    await page.screenshot({ path: path.join(output, `${screen.slug}-desktop.png`), fullPage: true });
  }

  await page.setViewportSize({ width: 390, height: 844 });
  for (const screen of screens) {
    await page.goto(screen.url);
    await expectNoFatalError(page);
    await page.screenshot({ path: path.join(output, `${screen.slug}-mobile.png`), fullPage: true });
  }
});
