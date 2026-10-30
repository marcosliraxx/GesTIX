/* Visual/interaction checks on a development copy only. Requires Playwright.
   Set GESTI_UI_BASE_URL, GESTI_UI_EMAIL and GESTI_UI_PASSWORD for a test account
   with at least 17 networks. The scenario counts change HTML in the browser;
   no networks are created, changed or deleted by this test.
*/
const {chromium} = require('playwright');
const assert = require('node:assert/strict');
const fs = require('node:fs');
(async () => {
  const base = process.env.GESTI_UI_BASE_URL;
  assert(base && process.env.GESTI_UI_EMAIL && process.env.GESTI_UI_PASSWORD, 'Development URL and test credentials are required');
  const browser = await chromium.launch({executablePath:process.env.GESTI_CHROMIUM_PATH || undefined, headless:true, args:['--no-sandbox']});
  try {
    const context = await browser.newContext();
    const login = await context.newPage();
    await login.goto(new URL('index.php', base).href);
    await login.locator('[name=email]').fill(process.env.GESTI_UI_EMAIL);
    await login.locator('[name=password]').fill(process.env.GESTI_UI_PASSWORD);
    await Promise.all([login.waitForURL('**/pages/painel.php'), login.locator('form button[type=submit]').click()]);
    const initial = await login.locator('[data-panel-network]').count();
    assert(initial >= 17, 'A development account with at least 17 networks is required');
    await login.close();
    let count = initial;
    await context.route('**/pages/painel.php', async route => {
      const response = await route.fetch();
      const html = await response.text();
      let seen = 0;
      const body = html.replace(/<a class="arc-panel-network-link"[\s\S]*?<\/a>/g, card => seen++ < count ? card : '');
      await route.fulfill({response, body});
    });
    const page = await context.newPage();
    const errors = []; page.on('pageerror', error => errors.push(error.message));
    let cases = 0;
    const sizes = [[320,568],[360,640],[390,844],[430,932],[768,1024],[844,390],[1440,900]];
    for (const [width,height] of sizes) {
      await page.setViewportSize({width,height});
      for (count of [0,1,2,5,17]) {
        await page.goto(new URL('pages/painel.php', base).href);
        await page.waitForTimeout(180);
        const measurements = await page.evaluate(() => {
          const main = document.querySelector('.app-main');
          const grid = document.getElementById('arc-panel-grid');
          const bounds = main.getBoundingClientRect();
          const cards = [...grid.querySelectorAll('[data-panel-network]:not([hidden])')];
          return {horizontal:document.body.scrollWidth > innerWidth,
            scroll:main.scrollHeight > main.clientHeight + 1,
            clipped:cards.some(card => {const r=card.getBoundingClientRect();return r.bottom > bounds.bottom + 1 || r.right > bounds.right + 1 || card.scrollHeight > card.clientHeight + 1;}),
            visible:cards.length,
            dockVisible:getComputedStyle(document.querySelector('.arc-mobile-dock')).display !== 'none',
            pager:document.querySelector('.arc-panel-pagination').getBoundingClientRect().bottom <= bounds.bottom + 1};
        });
        assert(!measurements.horizontal, `${width}x${height}/${count}: horizontal overflow`);
        assert(!measurements.scroll, `${width}x${height}/${count}: panel requires vertical scrolling`);
        assert(!measurements.clipped, `${width}x${height}/${count}: clipped card content`);
        assert(measurements.pager, `${width}x${height}/${count}: pagination not visible`);
        assert.equal(measurements.dockVisible, width <= 860);
        if (count) assert(measurements.visible > 0);
        else assert(await page.locator('#arc-panel-empty-create').isVisible());
        cases++;
      }
    }
    // All networks remain reachable even when the mobile viewport fits one row.
    await page.setViewportSize({width:320,height:568}); count=17;
    await page.goto(new URL('pages/painel.php', base).href); await page.waitForTimeout(150);
    const visited = new Set();
    for (let i=0;i<30;i++) {
      for (const id of await page.locator('[data-panel-network]:visible').evaluateAll(cards => cards.map(card => card.dataset.panelNetwork))) visited.add(id);
      if (await page.locator('#arc-panel-next').isDisabled()) break;
      await page.locator('#arc-panel-next').click();
    }
    assert.equal(visited.size,17,'Some networks are unreachable');
    await page.locator('#arc-panel-search').fill('nome muito longo');
    assert.equal(await page.locator('[data-panel-network]:visible').count(),1,'Search did not reset pagination');
    await page.locator('#arc-panel-search').fill('no-match-xyz');
    assert(await page.locator('#arc-panel-search-empty').isVisible());
    await page.locator('#arc-panel-clear').click();
    assert((await page.locator('[data-panel-network]:visible').count()) > 0,'Empty search did not restore grid');
    await page.locator('[data-panel-network]:visible').first().click();
    assert(await page.locator('#arc-pn-dialog').isVisible(),'Network modal did not open');
    await page.locator('#arc-pn-close').click();
    await page.locator('[data-open-register]').first().click();
    assert(await page.locator('#modal-register').isVisible(),'Registration modal did not open');
    const modal = await page.locator('#modal-register > .modal').boundingBox();
    assert(modal.x>=0 && modal.y>=0 && modal.y+modal.height<=568,'Registration modal does not fit');
    await page.locator('[data-close-modal]').click();
    await page.locator('[data-mobile-menu]').click();
    assert(await page.locator('body').evaluate(body=>body.classList.contains('arc-menu-open')),'Mobile menu failed');
    await page.keyboard.press('Escape');
    assert(!(await page.locator('body').evaluate(body=>body.classList.contains('arc-menu-open'))));
    const manifest = await (await context.request.get(new URL('manifest.webmanifest',base).href)).json();
    assert(manifest.start_url.endsWith('/pages/painel.php'),'PWA does not start at panel');
    for(const icon of manifest.icons) assert((await context.request.get(new URL(icon.src,base).href)).ok(),'PWA icon missing');
    const networkIds = await page.locator('[data-panel-network]').evaluateAll(cards => cards.map(card => card.dataset.panelNetwork));
    await context.route('**/api/api.php?action=network-agent-states', route => route.fulfill({json:{ok:true,networks:Object.fromEntries(networkIds.map((id,index)=>[id,{state:index===0?'online':index===1?'partial':'offline',online:index<2?1:0,total:2}]))}}));
    await page.waitForFunction(()=>document.getElementById('arc-panel-online').textContent==='2');
    await page.locator('[data-panel-filter=online]').click();
    assert.equal(await page.locator('#arc-panel-result-count').textContent(),'2','Partial networks with a connected agent must remain in the connected filter');
    const connectedStates = new Set();
    for(let i=0;i<2;i++){
      for(const state of await page.locator('[data-panel-network]:visible').evaluateAll(cards=>cards.map(card=>card.dataset.agentState))) connectedStates.add(state);
      if(await page.locator('#arc-panel-next').isDisabled()) break;
      await page.locator('#arc-panel-next').click();
    }
    assert(connectedStates.has('online') && connectedStates.has('partial'),'Connected networks must remain reachable on small screens');
    await page.locator('[data-panel-filter=attention]').click();
    assert.equal(await page.locator('#arc-panel-result-count').textContent(),'15');
    await page.locator('[data-panel-filter=all]').click();
    const output=process.env.GESTI_UI_OUTPUT_DIR;
    if(output){
      fs.mkdirSync(output,{recursive:true});
      await page.setViewportSize({width:390,height:844}); await page.reload();await page.waitForTimeout(150);
      await page.screenshot({path:output+'/panel-mobile.png'});
      await page.setViewportSize({width:1440,height:900});await page.waitForTimeout(150);
      await page.screenshot({path:output+'/panel-desktop.png'});
    }
    assert.deepEqual(errors,[],'JavaScript runtime errors');
    console.log(`${cases} responsive scenarios passed; pagination, search, dialogs, navigation and PWA verified.`);
    await context.close();
  } finally { await browser.close(); }
})().catch(error=>{console.error(error);process.exitCode=1;});
