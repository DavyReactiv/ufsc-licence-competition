import fs from 'node:fs/promises';
import path from 'node:path';
import { test } from '@playwright/test';
import { expectNoFatalError, loginAsAdmin } from './helpers';

const output = path.resolve('test-results/visual');

test('capture the main competition workflow on desktop and mobile', async ({ page }) => {
  await fs.mkdir(output, { recursive: true });
  await loginAsAdmin(page);

  const screens = [
    { slug: 'competition-add', url: '/wp-admin/admin.php?page=ufsc-competitions&ufsc_action=add' },
    { slug: 'categories', url: '/wp-admin/admin.php?page=ufsc-competitions-categories' },
    { slug: 'combats', url: '/wp-admin/admin.php?page=ufsc-competitions-bouts' },
    { slug: 'plateau', url: '/wp-admin/admin.php?page=ufsc-competitions-plateau' },
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
