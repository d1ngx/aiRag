// Run with NODE_PATH pointing to an installed playwright package. All APIs are mocked.
const {chromium}=require('playwright');
const fs=require('node:fs');
const path=require('node:path');
const assert=require('node:assert/strict');
(async()=>{
 const browser=await chromium.launch({headless:true,args:['--no-sandbox']});
 try {
 const page=await browser.newPage({viewport:{width:1440,height:1000}});
 const errors=[]; let askCount=0;
 page.on('pageerror',e=>errors.push(e.message));
 const root=path.resolve(__dirname,'../static');
 let html=fs.readFileSync(path.join(root,'page.html'),'utf8');
 html=html.replace(/<script>[\s\S]*?<\/script>/,'<script>window.AIRAG_API="/api/";window.AIRAG_BOOT={};</script>')
  .replace(/<\?php echo '<script[\s\S]*?\?>/,'<script src="/static/page.js"></script>')
  .replace(/<\?php[\s\S]*?\?>/g,'/');
 await page.route('http://airag.test/**',async route=>{
  const url=new URL(route.request().url());
  if(url.pathname==='/') return route.fulfill({contentType:'text/html',body:html});
  if(url.pathname.endsWith('chat.css')||url.pathname.endsWith('page.js')) return route.fulfill({contentType:url.pathname.endsWith('.css')?'text/css':'text/javascript',body:fs.readFileSync(path.join(root,path.basename(url.pathname)),'utf8')});
  if(url.pathname==='/api/ask') {askCount++;return route.fulfill({json:{code:false,data:{message:'mock'}}});}
  if(url.pathname==='/api/chat') return route.fulfill({json:{code:true,data:{list:[{id:'1',title:'采购合同总结'},{id:'2',title:'年度报告'}],models:[]}}});
  return route.fulfill({json:{code:true,data:{}}});
 });
 await page.goto('http://airag.test/');
 await page.waitForFunction(()=>document.querySelectorAll('.airag-hist-item').length===2);
 await page.locator('#airag-history-search').fill('合同');
 assert.equal(await page.locator('.airag-hist-item').count(),1);
 await page.locator('[data-prompt]').first().click();
 assert.match(await page.locator('#airag-input').inputValue(),/总结/);
 await page.locator('#airag-input').dispatchEvent('keydown',{key:'Enter',isComposing:true,keyCode:229});
 assert.equal(askCount,0);
 assert.ok(await page.evaluate(()=>document.documentElement.scrollWidth<=innerWidth));
 await page.screenshot({path:'/tmp/airag-desktop.png',fullPage:true});
 await page.setViewportSize({width:390,height:844});
 assert.equal(await page.locator('#airag-sidebar').isVisible(),false);
 await page.locator('#airag-menu').click();
 assert.equal(await page.locator('#airag-sidebar').isVisible(),true);
 assert.equal(await page.locator('#airag-menu').getAttribute('aria-expanded'),'true');
 await page.keyboard.press('Escape');
 assert.equal(await page.locator('#airag-sidebar').isVisible(),false);
 assert.ok(await page.evaluate(()=>document.documentElement.scrollWidth<=innerWidth));
 const composer=await page.locator('#airag-input').boundingBox();
 assert.ok(composer && composer.y+composer.height<=844);
 await page.screenshot({path:'/tmp/airag-mobile.png',fullPage:true});
 assert.deepEqual(errors,[]);
 console.log('PASS desktop/mobile layout, history filtering, prompt actions, IME Enter, navigation, no JS errors');
 } finally {await browser.close();}
})().catch(e=>{console.error(e);process.exitCode=1;});
