const {chromium}=require('playwright');
const fs=require('fs'),assert=require('assert');
(async()=>{
const browser=await chromium.launch({executablePath:process.env.CHROME_PATH||'/usr/bin/chromium',headless:true,args:['--no-sandbox']});
const page=await browser.newPage({viewport:{width:1120,height:760}}),errors=[];page.on('pageerror',e=>errors.push(e.message));
const css=fs.readFileSync('assets/css/arcoll.css','utf8')+'\n'+fs.readFileSync('assets/css/themes.css','utf8'),js=fs.readFileSync('assets/js/arcoll.js','utf8');
const catalog=JSON.parse(fs.readFileSync('assets/themes/catalog.json')).themes;
await page.route('https://fixture.test/**',route=>route.fulfill({contentType:'text/html',body:'<!doctype html><html><head></head><body></body></html>'}));
let count=0;
for(const [id,theme]of Object.entries(catalog))for(const mode of ['light','dark']){
 await page.goto('https://fixture.test/');
 await page.evaluate(({id,mode})=>{document.documentElement.dataset.arcDesign=id;document.documentElement.dataset.arcSystemTheme=mode;localStorage.setItem('arc_appearance',mode==='light'?'dark':'light');document.body.innerHTML='<main style="padding:24px"><h1>Prévia de componentes · dados de exemplo</h1><section class="card"><h2>Gestores</h2><p>Diagnóstico sincronizado</p><label>Buscar <input placeholder="Pesquisar dispositivo"></label><button class="button primary">Verificar</button><table><thead><tr><th>Dispositivo</th><th>Situação</th></tr></thead><tbody><tr><td>Servidor de demonstração</td><td>Disponível</td></tr></tbody></table></section></main>'},{id,mode});
 await page.addStyleTag({content:css});await page.addScriptTag({content:js});
 assert.equal(await page.getAttribute('html','data-theme'),mode);count++;
 const colors=await page.evaluate(()=>{const s=getComputedStyle(document.documentElement);return {accent:s.getPropertyValue('--primary').trim(),surface:s.getPropertyValue('--surface').trim(),text:s.getPropertyValue('--text').trim()}});
 assert.equal(colors.accent,theme.variants[mode].accent);assert.equal(colors.surface,theme.variants[mode].surface);assert.equal(colors.text,theme.variants[mode].text);count+=3;
 await page.evaluate(()=>window.arcTheme.set('auto'));assert.equal(await page.getAttribute('html','data-theme'),mode);count++;
 await page.locator('input').focus();assert.equal(await page.locator('input').evaluate(el=>getComputedStyle(el).outlineStyle),'solid');count++;
 if(['graphite','ocean','sandstone','aurora'].includes(id)&&mode===themeDefault(theme)){fs.mkdirSync('previews/temas',{recursive:true});await page.screenshot({path:`previews/temas/${id}-${mode}.png`});}
}
await page.goto('https://fixture.test/');await page.evaluate(()=>{document.documentElement.dataset.arcSystemTheme='auto'});await page.addScriptTag({content:js});
for(const mode of ['light','dark','light']){await page.emulateMedia({colorScheme:mode});await page.waitForFunction(m=>document.documentElement.dataset.theme===m,mode);count++;}
assert.deepEqual(errors,[]);console.log(`PASS ${count} browser theme, Admin priority, focus and OS-change assertions.`);await browser.close();
function themeDefault(t){return t.light?'light':'dark'}
})().catch(e=>{console.error(e);process.exit(1)});
