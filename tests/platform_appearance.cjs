/* Run on a development copy with synthetic user/admin accounts. No screenshots. */
const {chromium}=require('playwright');
(async()=>{
 const browser=await chromium.launch({executablePath:process.env.GESTI_CHROMIUM_PATH||undefined,headless:true,args:['--no-sandbox']});
 const fixture={email:process.env.GESTI_UI_EMAIL,password:process.env.GESTI_UI_PASSWORD};const admin={adminEmail:process.env.GESTI_UI_ADMIN_EMAIL,networkId:process.env.GESTI_UI_NETWORK_ID};const base=process.env.GESTI_UI_BASE_URL;
 if(!base||!fixture.email||!fixture.password||!admin.adminEmail||!admin.networkId)throw Error('Development URL, test credentials and network ID are required');
 let total=0;const failures=[];
 for(const role of ['user','admin']){
  const context=await browser.newContext({viewport:{width:360,height:640}});const page=await context.newPage();let errors=[];page.on('pageerror',e=>errors.push(e.message));
  await page.goto(new URL('index.php',base).href);
  await page.locator('[name=email]').fill(role==='admin'?admin.adminEmail:fixture.email);await page.locator('[name=password]').fill(role==='admin'?(process.env.GESTI_UI_ADMIN_PASSWORD||fixture.password):fixture.password);
  await Promise.all([page.waitForURL('**/pages/painel.php'),page.locator('form button[type=submit]').click()]);
  const routes=role==='admin'?['pages/painel.php','pages/clientes.php','pages/usuarios.php','pages/permissoes.php','pages/menus.php','pages/configuracoes.php','pages/perfil.php','pages/renovar-senha.php']:['pages/painel.php','pages/perfil.php','pages/renovar-senha.php',...['servidor','velocidade','dispositivos','mapa','conexao'].map(p=>'redes/'+p+'.php?network='+admin.networkId)];
  for(const [width,height] of [[360,640],[844,390],[1440,900]]){
   await page.setViewportSize({width,height});
   for(const theme of ['light','dark']){
    await page.evaluate(theme=>window.arcTheme.set(theme),theme);
    for(const route of routes){
     errors=[];const response=await page.goto(new URL(route,base).href,{waitUntil:'domcontentloaded'});await page.waitForTimeout(120);
     const data=await page.evaluate(()=>({theme:document.documentElement.dataset.theme,w:document.body.scrollWidth,main:document.querySelector('.app-main')?{w:document.querySelector('.app-main').clientWidth,sw:document.querySelector('.app-main').scrollWidth}:null,fatal:document.body.innerText.includes('Fatal error'),select:!!document.querySelector('[data-arc-appearance]'),bg:getComputedStyle(document.body).backgroundColor}));
     total++;
     if(response.status()!==200||data.fatal||errors.length||data.w>width+1||data.theme!==theme||!data.select||data.main&&data.main.sw>data.main.w+1)failures.push({role,route,width,height,status:response.status(),...data,errors});
    }
    console.log('Checked',role,width+'x'+height,theme);
   }
  }
  await page.emulateMedia({colorScheme:'dark'});await page.evaluate(()=>window.arcTheme.set('auto'));await page.waitForFunction(()=>document.documentElement.dataset.theme==='dark');
  if(await page.evaluate(()=>document.documentElement.dataset.theme)!=='dark')failures.push({role,error:'Auto dark failed'});
  await page.emulateMedia({colorScheme:'light'});await page.waitForFunction(()=>document.documentElement.dataset.theme==='light');
  if(await page.evaluate(()=>document.documentElement.dataset.theme)!=='light')failures.push({role,error:'Auto light failed'});
  if(!await page.evaluate(()=>CanvasRenderingContext2D.prototype.fillText.toString().includes('[native code]')))failures.push({role,error:'Canvas native method was replaced'});
  await context.close();
 }
 console.log(JSON.stringify({total,failures},null,2));await browser.close();if(failures.length)process.exitCode=1;
})().catch(e=>{console.error(e);process.exitCode=1});
