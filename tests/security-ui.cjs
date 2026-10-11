// Uses mocked application APIs; never contacts a live Kodbox instance.
const {chromium}=require('playwright');
const fs=require('node:fs'),path=require('node:path'),vm=require('node:vm'),assert=require('node:assert/strict');
(async()=>{
 let methods,prefilter;
 const jquery={ajaxPrefilter(fn){prefilter=fn;}};
 const ctx={window:{jQuery:jquery},jQuery:jquery,location:{href:'https://kod.test/index.php',origin:'https://kod.test'},URL,ClassBase:{define(x){methods=x;}}};
 const source=fs.readFileSync(path.join(__dirname,'../static/console.js'),'utf8');vm.runInNewContext(source,ctx);
 let headers={};prefilter({url:'?plugin/aiRag/manage'},null,{setRequestHeader(k,v){headers[k]=v;}});assert.equal(headers['X-Kodbox-Plugin'],'1');
 headers={};prefilter({url:'https://evil.test/?plugin/aiRag/manage'},null,{setRequestHeader(k,v){headers[k]=v;}});assert.deepEqual(headers,{});
 const browser=await chromium.launch({headless:true,executablePath:process.env.AUDIT_BROWSER_PATH||undefined,args:['--no-sandbox']});
 try {
  const page=await browser.newPage();
  const attack='" autofocus onfocus="window.auditXss=1" data-x="<img src=x onerror=window.auditXss=1>';
  const admin=fs.readFileSync(path.join(__dirname,'../static/admin.js'),'utf8');
  const helper=admin.match(/function h\(s\)\{[^\n]+/)[0];
  const adminEscape=vm.runInNewContext('('+helper+')');
  for(const escape of [methods.h,adminEscape]){
   await page.setContent('<input id="model" value="'+escape(attack)+'">');
   await page.locator('#model').focus();
   assert.equal(await page.locator('#model').inputValue(),attack);
   assert.equal(await page.locator('#model').getAttribute('onfocus'),null);
   assert.equal(await page.locator('img').count(),0);
   assert.equal(await page.evaluate(()=>window.auditXss),undefined);
  }
  if(process.env.AUDIT_RENDER_PATH){
   await page.route('**/*',r=>r.abort());
   await page.setContent(fs.readFileSync(process.env.AUDIT_RENDER_PATH,'utf8'));
   assert.equal(await page.evaluate(()=>window.auditXss),undefined);
   assert.match(await page.evaluate(()=>window.AIRAG_BOOT.refs[0].name),/<\/script>/);
  }
  console.log('PASS admin attribute XSS, same-origin request header scoping and PHP boot script XSS');
 }finally{await browser.close();}
})().catch(e=>{console.error(e);process.exitCode=1;});
