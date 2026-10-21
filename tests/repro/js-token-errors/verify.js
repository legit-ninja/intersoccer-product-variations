// verify.js <base> <path>: extract the TikTok inline block, compile as-is, then with the ellipsis restored, then with only the ellipsis removed.
const vm=require('vm');
(async()=>{ const [B,p]=process.argv.slice(2); const html=await (await fetch(B+p)).text();
 const m=[...html.matchAll(/<script\b[^>]*>([\s\S]*?)<\/script>/gi)].find(x=>x[1].includes("TiktokAnalyticsObject")); if(!m){console.log('no tiktok block');return;}
 const b=m[1]; const t=s=>{try{new vm.Script(s);return 'OK'}catch(e){return e.name+': '+e.message}};
 console.log(JSON.stringify({asIs:t(b), ellipsisCount:(b.match(/\u2026/g)||[]).length, restored:t(b.replace('tt\u2026 Date','ttq._t=ttq._t||{},ttq._t[e]=+new Date')), ellipsisOnlyRemoved:t(b.replace('\u2026',''))})); })();
