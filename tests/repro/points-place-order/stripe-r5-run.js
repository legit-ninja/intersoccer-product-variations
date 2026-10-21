// Repro harness: classic checkout Place Order with/without CRS points. Local sandbox only.
// usage: node run.js <baseUrl> <user> <pwfile> <points:0|N> <label>
const { chromium } = require('/home/jlee/projects/underdog/intersoccer/intersoccer-ui-tests/node_modules/playwright');
const fs=require('fs'), path=require('path');
const [B,USER,PWF,PTS,LABEL]=process.argv.slice(2); const HOST=new URL(B).host; const PW=fs.readFileSync(PWF,'utf8').trim().split(' ')[1];
const OUT=path.join(process.env.OUTDIR||'/tmp','out-'+LABEL+'.jsonl'); fs.writeFileSync(OUT,'');
const T0=Date.now(); const RD=x=>String(x||'').replace(/[\w.+-]+@[\w.-]+/g,'<email>').replace(/\b(pi|pm|ch|seti|src|cus|tok|req|py|pmc|lsess|ilsess)_[A-Za-z0-9]{6,}/g,m=>m.split('_')[0]+'_…').replace(/(client_secret|secret)["=:]+[^"&,]+/g,'$1=<r>').replace(/pk_test_[A-Za-z0-9]+/g,'pk_test_…'); const emit=o=>{const s=JSON.stringify(Object.assign({t:Date.now()-T0},o)).split(B).join(''); fs.appendFileSync(OUT,s+'\n'); process.stdout.write(s.slice(0,600)+'\n');};
const ATTR={"pa_intersoccer-venues":"geneva-stade-de-varembe-nations","pa_age-group":"5-13y-full-day","pa_camp-terms":"autumn-week-4-october-19-23-5-days","pa_camp-times":"1000-1700","pa_booking-type":"full-week"};
(async()=>{
 const b=await chromium.launch({headless:true,executablePath:'/home/jlee/.cache/ms-playwright/chromium-1248/chrome-linux64/chrome'});
 const ctx=await b.newContext({viewport:{width:1366,height:900},...(process.env.BA_U?{httpCredentials:{username:process.env.BA_U,password:process.env.BA_P}}:{})});
 const st={ext:0,stripe:0,blockedPluginPhp:0,cron:0,live:0}; const reqs=[];
 await ctx.route('**/*',r=>{const q=r.request(),u=q.url(); let h=''; try{h=new URL(u).host}catch(e){}
   const body=q.postData()||'';
   if(/pk_live_|sk_live_|rk_live_/.test(u+body)){st.live++; return r.abort();}
   if(h===HOST && /\/wp-content\/plugins\/.*\.php(\?|$)/.test(u)){st.blockedPluginPhp++; return r.abort();}
   if(u.includes('wp-cron.php')){st.cron++;return r.abort();}
   if(h!==HOST && !u.startsWith('data:') && !u.startsWith('blob:')){ if(/(^|\.)stripe(cdn)?\.(com|network)$/.test(h)){st.stripe++; return r.continue();} if(process.env.HCAP && /(^|\.)hcaptcha\.com$/.test(h)){st.hcap=(st.hcap||0)+1; return r.continue();} if(process.env.OPEN){st.extOpen=(st.extOpen||0)+1; return r.continue();} st.ext++; return r.abort(); }
   return r.continue();});
 const p=await ctx.newPage(); const perr=[]; p.on('pageerror',e=>{perr.push(String(e.message).slice(0,300)); emit({kind:'pageerror',m:String(e.message).slice(0,300)});});
 const cerr=[]; p.on('console',m=>{ if(m.type()==='error') cerr.push(m.text().slice(0,200));  if(m.type()==='error'||/\[REPRO\]/.test(m.text())) emit({kind:'console',type:m.type(),m:m.text().slice(0,300)}); });
 let rec=false; p.on('request',q=>{ if(!rec) return; const u=q.url(); let h=''; try{h=new URL(u).host}catch(e){} const pd=q.postData()||''; const a=(new URLSearchParams(pd)).get('action'); reqs.push({m:q.method(),host:h===HOST?'local':h,path:h===HOST?new URL(u).pathname+new URL(u).search.replace(/[?&](_wpnonce|security|nonce)=[^&]*/g,''):new URL(u).pathname,action:a}); });
 p.on('response',async r=>{ if(!rec) return; const u=r.url(); let h=''; try{h=new URL(u).host}catch(e){} const isWc=/wc-ajax=/.test(u)||/admin-ajax\.php/.test(u); const isStripeApi=/(^|\.)(api|merchant-ui-api|m)\.stripe\.com$/.test(h)||/stripe\.com$/.test(h)&&/\/v1\//.test(u); if(!isWc&&!isStripeApi) return; let body=''; try{ body=(await r.text()).slice(0,700);}catch(e){body='(no body)';} emit({kind:'resp',s:r.status(),m:r.request().method(),host:h===HOST?'local':h,path:new URL(u).pathname+(/wc-ajax=([a-z_]+)/.exec(u)||['',''])[0],action:(new URLSearchParams(r.request().postData()||'')).get('action'),body:RD(body)}); });
 p.on('requestfailed',q=>{ if(!rec) return; let h=''; try{h=new URL(q.url()).host}catch(e){} emit({kind:'reqfail',host:h===HOST?'local':h,path:new URL(q.url()).pathname,err:q.failure()&&q.failure().errorText}); });
 const go=async u=>{const r=await p.goto(B+u,{waitUntil:'load',timeout:180000}); return r&&r.status();};
 await p.goto(B+'/wp-login.php',{waitUntil:'domcontentloaded'}); await p.fill('#user_login',USER); await p.fill('#user_pass',PW);
 await Promise.all([p.waitForNavigation({timeout:180000,waitUntil:'domcontentloaded'}),p.click('#wp-submit')]);
 emit({kind:'login',landed:p.url().replace(B,'').slice(0,40)});
 // empty cart via page: go to cart and remove items
 await go('/cart/'); for(let i=0;i<5;i++){ const href=await p.locator('a.remove').first().getAttribute('href').catch(()=>null); if(!href) break; await p.goto(new URL(href,B).toString(),{waitUntil:'load',timeout:120000}); await p.waitForTimeout(1500); }
 // add one camp line (single child)
 emit({kind:'product',status:await go('/shop/geneva-autumn-camps-2026/')});
 for(const [k,v] of Object.entries(ATTR)){ const s=p.locator(`select[name="attribute_${k}"]`); if(await s.count()){ await s.selectOption(v).catch(e=>emit({kind:'sel_err',k,e:String(e).slice(0,120)})); await p.waitForTimeout(700);} }
 await p.waitForTimeout(2000);
 await p.waitForFunction(()=>{const s=document.querySelector('select[name=player_assignment]');return s&&s.options.length>1;},null,{timeout:30000}).catch(()=>emit({kind:'player_select_wait_timeout'}));
 const att=p.locator('select[name*="attendee" i], select[name*="player" i], select[id*="player" i]');
 if(await att.count()){ await att.first().selectOption({index:1}); emit({kind:'player_selected'}); await p.waitForTimeout(2500);}
 const btn=p.locator('form.cart button.single_add_to_cart_button'); await p.waitForFunction(()=>{const b=document.querySelector('form.cart button.single_add_to_cart_button');return b&&!b.disabled;},null,{timeout:30000}).catch(async()=>emit({kind:'btn_disabled',form:await p.evaluate(()=>[...document.querySelectorAll('form.cart select')].map(s=>s.name+'='+s.value))}));
 const nav=p.waitForNavigation({timeout:30000,waitUntil:'load'}).catch(()=>emit({kind:'atc_no_nav'})); await btn.click(); await nav; await p.waitForLoadState('load'); await p.waitForTimeout(3000);
 emit({kind:'add_to_cart',msgs:await p.evaluate(()=>[...document.querySelectorAll('.woocommerce-message,.woocommerce-error')].map(e=>e.innerText.replace(/\s+/g,' ').trim().slice(0,160)))});
 rec=true; emit({kind:'checkout',status:await go('/checkout/')}); await p.waitForTimeout(4000);
 const dump=()=>p.evaluate(()=>{const T=s=>(s||'').replace(/\s+/g,' ').trim();
   return {lines:document.querySelectorAll('.woocommerce-checkout-review-order-table .cart_item').length,
     totals:[...document.querySelectorAll('.woocommerce-checkout-review-order-table tfoot tr')].map(tr=>T(tr.innerText)),
     pointsUI:!!document.querySelector('.intersoccer-points-redemption-wrapper'), pm:(document.querySelector('input[name=payment_method]:checked')||{}).value,
     notices:[...document.querySelectorAll('.woocommerce-error li,.woocommerce-NoticeGroup li,.woocommerce-error')].map(e=>T(e.innerText).slice(0,200))};});
 emit({kind:'checkout_state',...(await dump())});
 if(parseInt(PTS)>0){ await p.locator('#intersoccer_use_points').check({force:true}); await p.waitForTimeout(800); await p.locator('#intersoccer_points_to_redeem').fill(String(PTS));
   await p.locator('.intersoccer-points-redemption button.apply-all-points').click(); await p.waitForTimeout(8000);
   emit({kind:'after_points',applied:(await p.locator('#intersoccer-points-applied').innerText().catch(()=>'')).replace(/\s+/g,' ').trim(),...(await dump())}); }
 // stripe card (test 4242)
 const fr=p.frameLocator('#wc-stripe-upe-form iframe, .wc-stripe-upe-element iframe').first();
 try{ await fr.locator('input[name=number]').fill('4242424242424242',{timeout:30000}); await fr.locator('input[name=expiry]').fill('12 / 34'); await fr.locator('input[name=cvc]').fill('123'); const pc=fr.locator('input[name=postalCode]'); if(await pc.count()) await pc.fill('1200');
   const lk=fr.locator('input[type=checkbox]'); if(process.env.LINK==='phone'){ const lo=fr.locator('input[name=linkOptIn]'); if(await lo.count() && !(await lo.isChecked())) await lo.evaluate(e=>e.click()); await p.waitForTimeout(1500);} else for(let i=0;i<await lk.count();i++){ if(await lk.nth(i).isChecked()) await lk.nth(i).uncheck({force:true}).catch(()=>{}); }
   if(process.env.LINK==='phone'){ const lp=fr.locator('input[name=linkMobilePhone]'); if(await lp.isVisible().catch(()=>false)){ await lp.click(); await lp.fill(''); await lp.type(process.env.LINKPHONE||'+41791234567',{delay:40}); emit({kind:'link_country',v:await fr.locator('select').evaluateAll(a=>a.map(s=>s.name+'='+s.value))}); await p.waitForTimeout(1500);} const ins=await fr.locator('input').evaluateAll(a=>a.filter(i=>i.offsetWidth||i.offsetHeight).map(i=>i.name+'/'+i.type)); emit({kind:'link_inputs',ins}); const nm=fr.locator('input[name=linkLegalName]:visible, input[name=name]:visible, input[autocomplete=name]:visible'); if(await nm.count()){ await nm.first().fill('Repro Parent'); emit({kind:'link_name_filled'}); if(process.env.NONAME){ await nm.first().fill(''); emit({kind:'link_name_cleared'});} } emit({kind:'link_phone',visible:await lp.isVisible().catch(()=>false),optin:await fr.locator('input[name=linkOptIn]').isChecked().catch(()=>null)}); } else if(!process.env.RAW){ const lo=fr.locator('input[name=linkOptIn]'); if(await lo.count() && await lo.isChecked()){ await lo.evaluate(e=>e.click()).catch(()=>{}); await p.waitForTimeout(800); emit({kind:'link_optin_after',checked:await lo.isChecked()}); } }
   const ph2=fr.locator('input[name=phoneNumber]:visible, input[type=tel]:visible'); if(await ph2.count()){ await ph2.first().fill('791234567'); emit({kind:'stripe_phone_filled'}); }
   emit({kind:'stripe_test_card_filled'}); }catch(e){ emit({kind:'stripe_fill_err',e:String(e).slice(0,200)}); }
 if(process.env.NOBILL){ for(const sel of ['#billing_first_name','#billing_last_name','#billing_address_1','#billing_city','#billing_postcode','#billing_phone']){const l=p.locator(sel); if(await l.count()) await l.fill('');} emit({kind:'billing_cleared'}); } else if(!process.env.RAW){const ph=p.locator('#billing_phone'); await ph.fill(''); await ph.pressSequentially('791234567',{delay:30}); await p.waitForTimeout(500);}
 const terms=p.locator('#terms'); if(await terms.count()) await terms.check({force:true}).catch(()=>{});
 await p.waitForTimeout(1500);
 // SETTLE (default; RAW skips): fire update_checkout, wait until updated_checkout has fired AND finished: no .blockUI anywhere,
 // form not .processing, #payment / review table not blockUI-blocked, no jQuery AJAX in flight; then 1.5s with no new refresh.
 if(!process.env.RAW){ const t0=Date.now(); await p.evaluate(()=>{window.__uc=0;window.__ucN=0;jQuery(document.body).on('updated_checkout',()=>{window.__uc=1;window.__ucN++;});jQuery(document.body).trigger('update_checkout');});
  const idle=()=>p.waitForFunction(()=>{const $=jQuery,f=document.querySelector('form.checkout');return window.__uc===1&&!document.querySelector('.blockUI')&&f&&!f.classList.contains('processing')&&!$('#payment').data('blockUI.isBlocked')&&!$('.woocommerce-checkout-review-order-table').data('blockUI.isBlocked')&&$.active===0;},null,{timeout:90000,polling:100}).then(()=>true).catch(()=>false);
  let ok=await idle(); let stable=false; for(let i=0;i<10&&ok&&!stable;i++){ const n0=await p.evaluate(()=>window.__ucN); await p.waitForTimeout(1500); const s2=await p.evaluate(()=>({n:window.__ucN,blk:document.querySelectorAll('.blockUI').length,proc:document.querySelector('form.checkout').classList.contains('processing'),act:jQuery.active})); stable=s2.n===n0&&!s2.blk&&!s2.proc&&s2.act===0; if(!stable) ok=await idle(); }
  emit({kind:'settle',ok,stable,ms:Date.now()-t0,updated_checkout_n:await p.evaluate(()=>window.__ucN)}); }
 // instrumentation (page-side, non-invasive): log triggerHandler results for checkout_place_order*, submit events, form state
 const pre=await p.evaluate(()=>{const $=window.jQuery; const f=document.querySelector('form.checkout'); const po=document.querySelector('#place_order');
   window.__repro={th:[],submits:[]};
   const orig=$.fn.triggerHandler; $.fn.triggerHandler=function(type){ const r=orig.apply(this,arguments); if(String(type).indexOf('checkout_place_order')===0) window.__repro.th.push({type:String(type),ret:r===undefined?'undefined':String(r)}); return r; };
   const rec=ph=>e=>window.__repro.submits.push({ph,type:e.type,t:(e.target&&e.target.tagName||'')+'#'+(e.target&&e.target.id||'')+'.'+String(e.target&&e.target.className||'').slice(0,30),dp:e.defaultPrevented});
   for(const ty of ['pointerdown','click','submit']){ window.addEventListener(ty,rec('win-cap'),true); window.addEventListener(ty,rec('win-bub'),false);}
   const ev=$._data(f,'events')||{}; const pevs=$._data(po,'events')||{}; const docev=$._data(document,'events')||{};
   return {placeOrderInForm:!!(po&&po.form===f), poDisabled:po&&po.disabled, poType:po&&po.type, formClasses:f&&f.className, blocked:$(f).data('blockUI.isBlocked')||0,
     formEvents:Object.fromEntries(Object.entries(ev).map(([k,v])=>[k,v.length])), placeOrderEvents:Object.keys(pevs), docClickDelegates:(docev.click||[]).map(h=>h.selector).filter(Boolean).slice(0,40), pm:(document.querySelector('input[name=payment_method]:checked')||{}).value};});
 emit({kind:'pre_click',...pre});
 if(!process.env.RAW&&!process.env.NOSCROLL){ await p.locator('#place_order').scrollIntoViewIfNeeded(); await p.waitForTimeout(500); }
 emit({kind:'geo',...(await p.evaluate(()=>{const po=document.querySelector('#place_order'); const r=po.getBoundingClientRect(); const top=document.elementFromPoint(r.x+r.width/2,r.y+r.height/2); return {r:[Math.round(r.x),Math.round(r.y),Math.round(r.width),Math.round(r.height)],top:top?(top.tagName+'#'+top.id+'.'+String(top.className).slice(0,80)):null,active:document.activeElement&&(document.activeElement.tagName+'#'+document.activeElement.id),paymentBlocked:window.jQuery('#payment').data('blockUI.isBlocked')||0,overlays:document.querySelectorAll('.blockUI.blockOverlay').length,vh:innerHeight};}))});
 rec=true;
 await p.locator('#place_order').click({timeout:20000}).catch(e=>emit({kind:'click_err',e:String(e).slice(0,600)}));
 await Promise.race([p.waitForURL(/order-received/i,{timeout:40000}).catch(()=>{}), p.waitForTimeout(40000)]);
 rec=false;
 try{ emit({kind:'post_click_geo',...(await p.evaluate(()=>{const po=document.querySelector('#place_order'); const r=po.getBoundingClientRect(); const cx=r.x+r.width/2, cy=r.y+r.height/2; const top=document.elementFromPoint(cx,cy);
   const ifr=[...document.querySelectorAll('iframe')].map(f=>{const b=f.getBoundingClientRect(); const cs=getComputedStyle(f); return {name:(f.name||'').slice(0,40),src:(f.src||'').replace(/^https?:\/\/([^/]+).*/,'$1'),pos:cs.position,z:cs.zIndex,vis:cs.visibility,disp:cs.display,op:cs.opacity,pe:cs.pointerEvents,rect:[Math.round(b.x),Math.round(b.y),Math.round(b.width),Math.round(b.height)],coversButton:(b.left<=cx&&b.right>=cx&&b.top<=cy&&b.bottom>=cy)};}).filter(f=>f.coversButton||f.pos==='fixed');
   return {r:[Math.round(r.x),Math.round(r.y),Math.round(r.width),Math.round(r.height)],scrollY:Math.round(scrollY),top:top?(top.tagName+'#'+top.id+'.'+String(top.className).slice(0,60)+' name='+(top.name||'').slice(0,40)):null,iframes:ifr};}))}); }catch(e){}
 let post={}; try{ post=await p.evaluate(()=>{const f=document.querySelector('form.checkout'); return {url:location.pathname.replace(/\d+/g,'N'), repro:window.__repro||null, formClasses:f&&f.className, blocked:f?window.jQuery(f).data('blockUI.isBlocked')||0:null,
   notices:[...document.querySelectorAll('.woocommerce-error li,.woocommerce-NoticeGroup li,.woocommerce-message,.wc-stripe-error,#wc-stripe-upe-errors')].map(e=>e.innerText.replace(/\s+/g,' ').trim().slice(0,200))};}); }catch(e){ post={evalErr:String(e).slice(0,200), url:p.url().replace(B,'').replace(/\d+/g,'N')}; }
 try{ const fr2=p.frameLocator('#wc-stripe-upe-form iframe, .wc-stripe-upe-element iframe').first(); emit({kind:'stripe_frame_after',ins:await fr2.locator('input').evaluateAll(a=>a.map(i=>({n:i.name,t:i.type,vis:!!(i.offsetWidth||i.offsetHeight),chk:i.checked,v:i.type==='tel'?i.value.length:undefined}))),alerts:await fr2.locator('[role=alert], .p-FieldError').allInnerTexts()}); }catch(e){emit({kind:'stripe_frame_err',e:String(e).slice(0,200)});}
 emit({kind:'billing_phone',v:(await p.locator('#billing_phone').inputValue().catch(()=>'')).replace(/\d(?=\d{3})/g,'x'),itiCountry:await p.evaluate(()=>{const e=document.querySelector('.iti__selected-dial-code, .iti__selected-flag'); return e?(e.innerText||e.title||'').trim().slice(0,30):null;})});
 const oid=(p.url().match(/order-received\/(\d+)/)||[])[1]||''; if(oid) fs.writeFileSync(path.join(process.env.OUTDIR||'/tmp','oid-'+LABEL+'.txt'),oid);
 emit({kind:'after_click',got_order:!!oid,...post,requests:reqs.filter(r=>!/\.(css|js|png|jpe?g|svg|woff2?|gif|webp)$/.test(r.path)).slice(0,40)});
 emit({kind:'summary',checkout_submit:reqs.filter(r=>/wc-ajax=checkout(&|$)/.test(r.path)).length,stripe_pm_calls:reqs.filter(r=>/payment_methods/.test(r.path)).length,got_order:!!oid,token_errors:perr.filter(m=>/Invalid or unexpected token/.test(m)).length,other_page_errors:perr.filter(m=>!/Invalid or unexpected token/.test(m)),console_errors:cerr});
 emit({kind:'end',st,page_errors:perr.length});
 await b.close(); })().catch(e=>{emit({kind:'fatal',e:String(e).slice(0,300)});process.exit(1);});
