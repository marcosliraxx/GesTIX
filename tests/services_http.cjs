/* Caminho: tests/services_http.cjs */
/* Run only against the dedicated synthetic services database. */
const {chromium,request}=require('playwright'),fs=require('fs'),assert=require('node:assert/strict');
(async()=>{
 const base=process.env.GESTI_SERVICES_URL,fixturePath=process.env.GESTI_SERVICES_FIXTURE;
 assert(base&&fixturePath,'Explicit isolated URL and fixture file required');const f=JSON.parse(fs.readFileSync(fixturePath));
 const browser=await chromium.launch({executablePath:process.env.GESTI_CHROMIUM_PATH||'/usr/bin/chromium',args:['--no-sandbox']});const checks=[];
 try{
  const contexts={};
  for(const role of ['owner','operator','viewer','other']){
   const context=await browser.newContext();contexts[role]=context;const p=await context.newPage();await p.goto(base);await p.locator('[name=email]').fill(f.users[role]);await p.locator('[name=password]').fill(f.password);await Promise.all([p.waitForURL('**/pages/painel.php'),p.locator('form button[type=submit]').click()]);
   context.csrf=await p.locator('input[name=_csrf]').first().inputValue();
   await p.goto(base+'redes/suporte.php?network='+f.network);if(role==='other'){assert.equal((await p.content()).includes('Rede não autorizada'),true);continue;}
   context.csrf=await p.locator('input[name=_csrf]').first().inputValue();
   if(role==='viewer')assert(await p.locator('#arc-agent-remote-view').isDisabled());
  }
  const post=(role,path,data,csrf=true)=>contexts[role].request.post(base+path,{data,headers:csrf?{'X-CSRF-Token':contexts[role].csrf}:{}});
  let r=await post('operator','api/support.php?action=launch',{network_id:f.network,agent_id:f.agent},false);assert.equal(r.status(),403);checks.push('CSRF required');
  r=await post('viewer','api/support.php?action=launch',{network_id:f.network,agent_id:f.agent});assert.equal(r.status(),422);checks.push('Viewer remote denied');
  r=await post('other','api/support.php?action=launch',{network_id:f.network,agent_id:f.agent});assert.equal(r.status(),422);checks.push('Cross-tenant remote denied');
  r=await post('operator','api/support.php?action=launch',{network_id:f.network,agent_id:f.agent,ticket_id:f.ticket});let value=await r.json();assert(value.ok,JSON.stringify({status:r.status(),error:value.error}));const command=value.command_id,code=value.code;checks.push('Operator native launch');
  const anon=await request.newContext();r=await anon.post(base+'api/support.php?action=redeem',{data:{code}});const native=await r.json();assert(native.ok);r=await anon.post(base+'api/support.php?action=redeem',{data:{code}});assert.equal(r.status(),422);checks.push('One-time native exchange');
  const credentials={fingerprint:f.fingerprint,site_code:f.site_code,download_key:f.download_key};r=await anon.post(base+'api/api.php?action=agent-command-poll',{data:credentials});value=await r.json();assert(value.ok&&value.command?.id===command,JSON.stringify(value));assert.equal(Number(value.command.requester_user_id),f.operator);assert.equal(value.command.requester_role,'operator');checks.push('Agent receives actual technician identity');
  const headers={Authorization:'Bearer '+native.token};r=await anon.get(base+'api/remote-screen.php?action=status&command_id='+command,{headers});value=await r.json();assert(value.ok);checks.push('Bearer without cookies');
  r=await anon.get(base+'api/remote-screen.php?action=status&command_id='+(command+999),{headers});assert(!r.ok());checks.push('Bearer command scope');
  r=await contexts.viewer.request.get(base+'api/remote-screen.php?action=status&command_id='+command);assert.equal(r.status(),403);checks.push('Viewer cannot inspect remote');
  r=await anon.post(base+'api/remote-screen.php?action=preferences&command_id='+command,{headers,data:{quality:'low'}});assert((await r.json()).ok);checks.push('Native quality selection');
  const jpeg=Buffer.from(f.jpeg,'base64');r=await anon.post(base+'api/remote-screen.php?action=frame&command_id='+command,{data:{...credentials,control:true,image:f.jpeg,monitors:[{id:'1',label:'Tela 1',width:2,height:2,primary:true}],selected_monitor:'1'}});value=await r.json();assert(value.ok&&value.quality==='low',JSON.stringify(value));checks.push('Agent upload uses selected quality');
  r=await anon.get(base+'api/remote-screen.php?action=image&command_id='+command,{headers});assert.equal(r.status(),200);assert((await r.body()).equals(jpeg));const etag=r.headers().etag;
  r=await anon.get(base+'api/remote-screen.php?action=image&command_id='+command,{headers:{...headers,'If-None-Match':etag}});assert.equal(r.status(),304);checks.push('Unchanged frames avoid download');
  r=await anon.post(base+'api/remote-screen.php?action=input&command_id='+command,{headers,data:{events:[{t:'m',x:.5,y:.5},{t:'k',d:1,c:'KeyA'},{t:'k',d:0,c:'KeyA'}]}});assert.equal((await r.json()).queued,3);checks.push('Authorized native input');
  r=await anon.post(base+'api/remote-screen.php?action=poll&command_id='+command,{data:{...credentials,control:true}});assert.equal((await r.json()).events.length,3);checks.push('Agent receives input once');
  r=await anon.post(base+'api/remote-screen.php?action=poll&command_id='+command,{data:{...credentials,control:false}});assert((await r.json()).ok);
  r=await anon.post(base+'api/remote-screen.php?action=input&command_id='+command,{headers,data:{events:[{t:'m',x:0,y:0}]}});assert.equal(r.status(),409);checks.push('Local consent revocation blocks control');
  r=await anon.post(base+'api/remote-screen.php?action=stop&command_id='+command,{headers,data:{}});assert((await r.json()).ok);
  r=await anon.get(base+'api/remote-screen.php?action=status&command_id='+command,{headers});assert.equal(r.status(),403);checks.push('Stop revokes bearer');
  r=await contexts.operator.request.get(base+'api/download-support.php');assert(r.ok());assert(r.headers()['content-type'].includes('zip'));fs.writeFileSync(process.env.GESTI_SUPPORT_ZIP,r.body?await r.body():Buffer.alloc(0));checks.push('Authenticated installer download');
  for(const provider of ['mercadopago','asaas']){r=await anon.post(base+'api/billing-webhook.php?provider='+provider,{data:{data:{id:'123'},payment:{id:'pay_test'}}});assert.equal(r.status(),403);}checks.push('Unauthenticated webhooks rejected');
  r=await contexts.operator.request.get(base+'pages/comercial.php');assert(!((await r.text()).includes('id="commercial-plans"')));checks.push('Commercial administration restricted');
  const admin=await browser.newContext();const ap=await admin.newPage();await ap.goto(base);await ap.locator('[name=email]').fill(f.adminEmail);await ap.locator('[name=password]').fill(f.password);await Promise.all([ap.waitForURL('**/pages/painel.php'),ap.locator('form button[type=submit]').click()]);
  await ap.goto(base+'pages/comercial.php');const acsrf=await ap.locator('input[name=_csrf]').first().inputValue();
  r=await admin.request.post(base+'pages/comercial.php',{form:{_csrf:acsrf,action:'plan_save',name:'HTTP test plan '+f.owner,price_cents:'7900',networks_limit:'5',agents_limit:'20',seats_limit:'3',enabled:'1'}});assert((await r.text()).includes('HTTP test plan '+f.owner));checks.push('Admin plan creation');
  const fakeKey='synthetic-key-for-contract-tests';r=await admin.request.post(base+'pages/comercial.php',{form:{_csrf:acsrf,action:'gateway_save',public_url:'https://app.example.test',mercadopago_mode:'sandbox',mercadopago_key:fakeKey,mercadopago_hook:'synthetic-hook-signing-secret',asaas_mode:'sandbox',asaas_key:fakeKey,asaas_hook:'synthetic-asaas-hook-token',pix_enabled:'1',pix_key:'synthetic@example.test',pix_instructions:'Synthetic test only',sla_low:'24',sla_normal:'8',sla_high:'4',sla_urgent:'1'}});const html=await r.text();assert(!html.includes(fakeKey));assert(html.includes('Operação registrada.'));checks.push('Admin encrypted gateway configuration');
  await admin.close();
  await anon.dispose();console.log(checks.length+' service HTTP workflows passed: '+checks.join(', '));
 }finally{await browser.close();}
})().catch(e=>{console.error(e);process.exitCode=1});
