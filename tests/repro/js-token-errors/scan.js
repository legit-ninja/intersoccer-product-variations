// scan.js <base> <outPrefix> <path>...: GET each page (no login), compile every inline <script> and every same-host external script with vm.Script; report syntax errors with page line/col and bytes.
const vm=require('vm'); const fs=require('fs');
const [B,OUT,...paths]=process.argv.slice(2); const H=new URL(B).host;
const rows=[]; const extSeen={};
async function get(u){ const r=await fetch(u,{redirect:'follow',headers:{'user-agent':'repro-scan'}}); const buf=Buffer.from(await r.arrayBuffer()); return {status:r.status,buf,url:r.url}; }
function compile(src){ try{ new vm.Script(src,{filename:'x'}); return null; }catch(e){ const m=/x:(\d+)/.exec(e.stack||''); return {msg:e.message,line:m?+m[1]:null}; } }
(async()=>{
 for(const p of paths){
  const {status,buf,url}=await get(B+p); const html=buf.toString('utf8');
  const re=/<script\b([^>]*)>([\s\S]*?)<\/script>/gi; let m, n=0, bad=0;
  while((m=re.exec(html))){ n++; const attrs=m[1]; const body=m[2]; const type=(/type=["']?([^"'\s>]+)/i.exec(attrs)||[])[1]||'';
   if(type && !/javascript|module|^text\/javascript$/i.test(type)) continue;
   const src=(/\bsrc=["']([^"']+)["']/i.exec(attrs)||[])[1];
   const id=(/\bid=["']([^"']+)["']/i.exec(attrs)||[])[1]||'';
   const startLine=html.slice(0,m.index).split('\n').length; const tagLen=m[0].indexOf('>')+1;
   if(src){ let su; try{su=new URL(src,url);}catch(e){continue;} if(su.host!==H) continue; if(/\/wp-content\/plugins\/.*\.php/.test(su.pathname)) continue; const k=su.pathname; if(extSeen[k]!==undefined){ if(extSeen[k]) rows.push({page:p,ext:k,id,...extSeen[k],dup:true}); continue; }
     const r=await get(su.toString()); const e=compile(r.buf.toString('utf8')); extSeen[k]=e?{...e,bom:r.buf.slice(0,3).toString('hex')==='efbbbf'}:null; if(e){bad++; rows.push({page:p,ext:k,id,...extSeen[k]});} continue; }
   const e=compile(body); if(!e) continue; bad++;
   const lines=body.split('\n'); const L=e.line||1; const lineTxt=lines[L-1]||'';
   // find first char that is non-ASCII or control inside the failing line
   const odd=[]; for(let i=0;i<lineTxt.length;i++){ const c=lineTxt.charCodeAt(i); if(c>126||(c<32&&c!==9)) odd.push({col:i+1,cp:'U+'+c.toString(16).toUpperCase().padStart(4,'0'),utf8:Buffer.from(lineTxt[i]).toString('hex'),ctx:JSON.stringify(lineTxt.slice(Math.max(0,i-30),i+30))}); }
   rows.push({page:p,status,inline:true,id,pageLine:startLine+L-1,blockStartLine:startLine,blockLen:body.length,msg:e.msg,errLineInBlock:L,lineLen:lineTxt.length,odd:odd.slice(0,6),head:JSON.stringify(body.slice(0,90)),sha8:require('crypto').createHash('sha256').update(body).digest('hex').slice(0,8)});
  }
  rows.push({page:p,status,summary:true,scripts:n,bad,finalPath:new URL(url).pathname});
 }
 fs.writeFileSync(OUT+'.json',JSON.stringify(rows,null,1)); for(const r of rows) console.log(JSON.stringify(r).slice(0,900));
})();
