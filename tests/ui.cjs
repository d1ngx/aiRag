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
  if(url.search.includes('plugin/fileThumb/cover')||url.pathname==='/thumb/input.svg') return route.fulfill({contentType:'image/svg+xml',body:'<svg xmlns="http://www.w3.org/2000/svg" width="48" height="60"><rect width="48" height="60" fill="#dbeafe"/><rect x="7" y="8" width="34" height="44" rx="3" fill="#fff"/><path d="M12 19h24M12 26h24M12 33h18" stroke="#3478f6"/></svg>'});
  if(url.pathname==='/') return route.fulfill({contentType:'text/html',body:html});
  if(url.pathname.endsWith('chat.css')||url.pathname.endsWith('page.js')) return route.fulfill({contentType:url.pathname.endsWith('.css')?'text/css':'text/javascript',body:fs.readFileSync(path.join(root,path.basename(url.pathname)),'utf8')});
  if(url.pathname==='/api/ask') {
   askCount++;
   const event=(name,data)=>'event: '+name+'\ndata: '+JSON.stringify(data)+'\n\n';
   if(askCount===2){
    const repaired=event('meta',{tools:[],sources:[],model:'mock'})+
     event('delta',{text:'半截回答'})+
     event('done',{answer:'中途断开后的完整恢复',id:'stream-2',title:'恢复测试',model:'mock'});
    return route.fulfill({status:200,contentType:'text/event-stream',body:repaired});
   }
   const reasoning=Array.from({length:1200},()=>event('think',{text:'分析资料与问题。'})).join('');
   const answer=['## 流式回答\n\n'].concat(Array.from({length:800},(_,i)=>'增量'+i+' ')).concat(['\n\n- 已完成平滑输出。']);
   const body=event('meta',{tools:[{name:'知识库检索',ok:true,files:1,chunks:2}],sources:[],model:'mock'})+
    reasoning+
    answer.map(text=>event('delta',{text})).join('')+
    event('done',{id:'stream-1',title:'流式测试',model:'mock',provider:'Mock服务',usage:{prompt:70,output:30,total:100,cache:10},elapsedMs:1200,firstMs:320,speed:25,created:1790000000});
   return route.fulfill({status:200,contentType:'text/event-stream',body});
  }
  const params=new URLSearchParams(route.request().postData()||'');
  if(url.pathname==='/api/chat'&&params.get('operation')==='get') return route.fulfill({json:{code:true,data:{item:{id:'1',title:'引用测试',refs:[{name:'test.txt',path:'{source:8}',size:1024,fileThumb:'/thumb/input.svg'}],messages:[{role:'user',content:'请总结文件',refs:[{name:'test.txt',path:'{source:8}',size:1024,fileThumb:'/thumb/input.svg'}]},{role:'assistant',content:'第一处[^1]，第二处[^2]，摘要[^3]。',provider:'Mock服务',model:'mock',usage:{prompt:120,output:30,total:150,cache:20},elapsedMs:12600,firstMs:584,speed:12.3,created:1790000000,sources:[{index:1,fileID:8,name:'test.txt',path:'{source:8}',chunk:4,chunks:900,snippet:'first excerpt'},{index:2,fileID:8,name:'test.txt',path:'{source:8}',chunk:801,chunks:900,snippet:'high excerpt'},{index:3,fileID:8,name:'test.txt',path:'{source:8}',chunk:null,snippet:'keyword summary'}]}]}}}});
  if(url.pathname==='/api/chat'&&params.get('operation')==='source') return route.fulfill({json:{code:true,data:{item:{name:'test.txt',chunkCount:900},chunkIndex:801,chunk:{index:801,text:'high excerpt'},chunks:[{index:0,text:'unrelated first chunk'},{index:801,text:'high excerpt'}]}}});
  if(url.pathname==='/api/chat') return route.fulfill({json:{code:true,data:{list:[{id:'1',title:'采购合同总结'},{id:'2',title:'年度报告'}],models:[{id:'mock',name:'DeepSeek-R1'}],model:'mock'}}});
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
 await page.locator('.airag-hist-item').first().click();
 await page.locator('.airag-filecard .airag-filecover.is-card img').waitFor();
 assert.equal(await page.locator('.airag-filecard .airag-filecover.is-card img').getAttribute('src'),'/thumb/input.svg');
 assert.equal(await page.locator('#airag-refs .airag-filecover.is-chip img').getAttribute('src'),'/thumb/input.svg');
 assert.match(await page.evaluate(()=>getComputedStyle(document.body).fontFamily),/Lantinghei SC/);
 assert.match(await page.evaluate(()=>getComputedStyle(document.querySelector('#airag-input')).fontFamily),/Lantinghei SC/);
 assert.match((await page.locator('.airag-refs-box summary').textContent()).replace(/\s/g,''),/引用资料1篇$/);
 await page.waitForFunction(()=>document.querySelector('.airag-filecard img')?.naturalWidth>0);
 const collapsedRef=await page.locator('.airag-refs-box').boundingBox();
 assert.ok(collapsedRef&&collapsedRef.width<180);
 await page.locator('.airag-refs-box summary').click();
 assert.equal(await page.locator('.airag-refs-box .airag-filecover').count(),0);
 assert.equal(await page.locator('.airag-src-title').count(),1);
 assert.equal(await page.locator('.airag-src-piece').count(),3);
 await page.locator('.airag-stats').hover();
 await page.locator('.airag-stat-pop').waitFor({state:'visible'});
 const statText=(await page.locator('.airag-stat-pop').textContent()).replace(/\s+/g,' ');
 assert.match(statText,/模型服务.*Mock服务.*mock/);
 assert.match(statText,/token 用量.*prompt: 120.*output: 30.*cache: 20/);
 assert.match(statText,/首字耗时.*584/);
 assert.match(statText,/生成速度.*12.3 token\/s/);
 assert.match(statText,/创建时间/);
 await page.screenshot({path:'/tmp/airag-refs.png',fullPage:true});
 await page.locator('.airag-cite[data-n="2"]').waitFor();
 await page.locator('.airag-cite[data-n="2"]').click();
 await page.waitForFunction(()=>document.querySelector('#airag-src-mask pre')?.textContent==='high excerpt');
 assert.match(await page.locator('#airag-src-mask .meta').textContent(),/802/);
 await page.locator('#airag-src-mask [data-x]').first().click();
 await page.locator('.airag-cite[data-n="3"]').click();
 assert.equal(await page.locator('#airag-src-mask pre').textContent(),'keyword summary');
 assert.match(await page.locator('#airag-src-mask .meta').textContent(),/正文摘要/);
 await page.locator('#airag-src-mask [data-x]').first().click();
 await page.locator('#airag-input').fill('测试长思考流式输出');
 await page.locator('#airag-input').press('Enter');
 await page.waitForFunction(()=>document.querySelector('.airag-msg.bot:last-child .airag-md')?.textContent.includes('已完成平滑输出'));
 assert.equal(askCount,1);
 assert.ok((await page.locator('.airag-msg.bot:last-child .airag-think').textContent()).length>6000);
 assert.equal(await page.locator('.airag-msg.bot:last-child .airag-think-box').getAttribute('open'),null);
 await page.waitForFunction(()=>getComputedStyle(document.querySelector('#airag-send')).display!=='none');
 await page.locator('#airag-input').fill('测试流式中断恢复');
 await page.locator('#airag-input').press('Enter');
 await page.waitForFunction(()=>document.querySelector('.airag-msg.bot:last-child .airag-md')?.textContent.includes('中途断开后的完整恢复'));
 assert.equal(askCount,2);
 assert.doesNotMatch(await page.locator('.airag-msg.bot:last-child .airag-md').textContent(),/半截回答/);
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
 console.log('PASS desktop/mobile layout, smooth long reasoning stream, history, citations, IME and navigation');
 } finally {await browser.close();}
})().catch(e=>{console.error(e);process.exitCode=1;});
