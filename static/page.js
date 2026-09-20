(function(){
	var boot=window.AIRAG_BOOT||{};
	var api=String(boot.api||window.AIRAG_API||'?plugin/aiRag/').replace(/\/?$/,'/');
	var state={id:'',title:'新对话',messages:[],refs:[],model:'',models:[],thinking:false,tools:{disk:true,web:false,mail:false,save:false},list:[],busy:false,abort:null,waitSec:0,waitTimer:0};
	var raf=window.requestAnimationFrame||function(fn){return setTimeout(fn,16);};
	var caf=window.cancelAnimationFrame||clearTimeout;
	var streamFollowBottom=true,streamScrollRaf=0,streamThinkEl=null;
	var $ = function(id){return document.getElementById(id);};
	function esc(s){return String(s||'').replace(/[&<>"']/g,function(c){return ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c]);});}
	function fmtNum(n){
		n=Number(n)||0;
		if(n>=10000) return (n/10000).toFixed(n>=100000?1:2).replace(/\.0+$/,'').replace(/(\.\d)0$/,'$1')+'万';
		return String(n);
	}
	function fmtMs(ms){
		ms=Number(ms)||0;
		if(ms<1000) return ms+'ms';
		return (ms/1000).toFixed(ms>=10000?1:3).replace(/\.?0+$/,'')+'秒';
	}
	function fileIco(){
		return '<svg viewBox="0 0 24 24" width="14" height="14" fill="none" stroke="currentColor" stroke-width="1.8"><path d="M14 3H7a2 2 0 00-2 2v14a2 2 0 002 2h10a2 2 0 002-2V8z"/><path d="M14 3v5h5"/></svg>';
	}
	function citeBtn(n, src){
		src=src||{};
		return '<span class="airag-cite-wrap">'+
			'<button type="button" class="airag-cite" data-act="cite" data-n="'+n+'" data-file="'+(src.fileID||'')+'" data-chunk="'+(src.chunk==null?-1:src.chunk)+'" data-path="'+esc(src.path||'')+'" title="打开引用 '+n+'">['+n+']</button>'+
			'</span>';
	}
	function mdTable(block){
		var lines=block.trim().split(/\n/).filter(function(l){return /\|/.test(l);});
		if(lines.length<2) return esc(block);
		var rows=lines.map(function(l){return l.replace(/^\||\|$/g,'').split('|').map(function(c){return c.trim();});});
		if(/^[\s:|-]+$/.test(lines[1].replace(/\|/g,''))) rows.splice(1,1);
		var head=rows.shift()||[];
		var html='<table class="airag-md-table"><thead><tr>'+head.map(function(c){return '<th>'+inlineMd(c)+'</th>';}).join('')+'</tr></thead><tbody>';
		html+=rows.map(function(r){return '<tr>'+r.map(function(c){return '<td>'+inlineMd(c)+'</td>';}).join('')+'</tr>';}).join('');
		return html+'</tbody></table>';
	}
	function inlineMd(s, sources){
		s=esc(s);
		s=s.replace(/`([^`]+)`/g,'<code>$1</code>');
		s=s.replace(/\*\*(.+?)\*\*/g,'<strong>$1</strong>');
		s=s.replace(/\*(.+?)\*/g,'<em>$1</em>');
		s=s.replace(/\[(\d+)\]|\[\^(\d+)\]|\^\[(\d+)\]/g,function(_,a,b,c){
			var n=Number(a||b||c);
			var src=(sources||[]).filter(function(x){return Number(x.index||0)===n;})[0]||(sources||[])[n-1]||{};
			return citeBtn(n, src);
		});
		s=s.replace(/\[([^\]]+)\]\((https?:[^)]+)\)/g,'<a href="$2" target="_blank" rel="noreferrer">$1</a>');
		return s;
	}
	function md(src, sources){
		src=String(src||'').replace(/\r\n/g,'\n');
		var blocks=[];
		src=src.replace(/```(\w*)\n?([\s\S]*?)```/g,function(_,lang,code){
			blocks.push('<pre class="airag-md-code"><code>'+esc(code.replace(/\n$/,''))+'</code></pre>');
			return '\u0000B'+(blocks.length-1)+'\u0000';
		});
		src=src.replace(/(?:(?:^|\n)(?:\|.+\|[ \t]*\n)+)/g,function(block){
			blocks.push(mdTable(block));
			return '\n\u0000B'+(blocks.length-1)+'\u0000\n';
		});
		var lines=src.split('\n'), out=[], list=null, para=[];
		function closeList(){ if(list){ out.push('</'+list+'>'); list=null; } }
		function closePara(){
			if(!para.length) return;
			var text='';
			para.forEach(function(line){
				line=String(line||'').replace(/^[ \t]+|[ \t]+$/g,'');
				if(!line) return;
				if(!text){ text=line; return; }
				var prev=text.slice(-1), first=line.charAt(0);
				if(/[A-Za-z0-9]$/.test(prev) && /[A-Za-z0-9]/.test(first)) text+=' '+line;
				else text+=line;
			});
			if(text) out.push('<p>'+inlineMd(text,sources)+'</p>');
			para=[];
		}
		lines.forEach(function(line){
			if(/^\u0000B\d+\u0000$/.test(line.trim())){ closePara(); closeList(); out.push(line.trim()); return; }
			var m;
			if((m=line.match(/^(#{1,3})\s+(.+)/))){ closePara(); closeList(); out.push('<h'+(m[1].length+2)+'>'+inlineMd(m[2],sources)+'</h'+(m[1].length+2)+'>'); return; }
			if(/^\s*[-*]\s+/.test(line)){
				closePara();
				if(list!=='ul'){ closeList(); list='ul'; out.push('<ul>'); }
				out.push('<li>'+inlineMd(line.replace(/^\s*[-*]\s+/,''),sources)+'</li>'); return;
			}
			if(/^\s*\d+\.\s+/.test(line)){
				closePara();
				if(list!=='ol'){ closeList(); list='ol'; out.push('<ol>'); }
				out.push('<li>'+inlineMd(line.replace(/^\s*\d+\.\s+/,''),sources)+'</li>'); return;
			}
			closeList();
			if(!line.trim()){ closePara(); return; }
			para.push(line);
		});
		closePara();
		closeList();
		return out.join('').replace(/\u0000B(\d+)\u0000/g,function(_,n){return blocks[Number(n)]||'';});
	}
	function sizeText(n){
		n=parseInt(n,10)||0;
		if(n<1024) return n+' B';
		if(n<1048576) return (n/1024).toFixed(1)+' KB';
		return (n/1048576).toFixed(1)+' MB';
	}
	function modelId(m){return typeof m==='string'?m:((m&&(m.id||m.name))||'');}
	function modelIcon(m){
		var id=modelId(m), name=modelLabel(m)||'M';
		var letter=name.replace(/^BAAI\//,'').charAt(0).toUpperCase();
		var cls='is-llm';
		if(/bge|embed|e5/i.test(id+name)) cls='is-embed';
		else if(/rerank/i.test(id+name)) cls='is-rerank';
		else if(/deepseek/i.test(id+name)) cls='is-deepseek';
		else if(/qwen|tongyi/i.test(id+name)) cls='is-qwen';
		else if(/gpt|openai/i.test(id+name)) cls='is-gpt';
		return '<span class="airag-mico '+cls+'">'+esc(letter)+'</span>';
	}
	function modelLabel(m){
		if(!m) return '';
		if(typeof m==='string') return m;
		var id=m.id||'';
		var name=m.name||id;
		if(name&&id&&name!==id) return name;
		return name||id;
	}
	function normalizeModels(list){
		return (list||[]).map(function(m){
			if(typeof m==='string') return {id:m,name:m};
			return {id:m.id||m.name||'',name:m.name||m.id||'',provider:m.provider||''};
		}).filter(function(m){return m.id;});
	}
	function applyModels(list, preferred){
		state.models=normalizeModels(list);
		var ids=state.models.map(function(m){return m.id;});
		if(preferred && ids.indexOf(preferred)>=0) state.model=preferred;
		else if(ids.indexOf(state.model)<0) state.model=ids[0]||'';
	}
	function parseJson(text){
		try{return JSON.parse(text);}
		catch(e){
			throw new Error('接口没有返回 JSON。请确认已登录，并且请求地址为 '+api);
		}
	}
	function post(path, data, extra){
		var body=new URLSearchParams();
		Object.keys(data||{}).forEach(function(k){
			var v=data[k];
			if(v===undefined||v===null) return;
			body.append(k, typeof v==='string'?v:JSON.stringify(v));
		});
		var opt={method:'POST',credentials:'same-origin',headers:{'Content-Type':'application/x-www-form-urlencoded;charset=UTF-8'},body:body.toString()};
		if(extra&&extra.signal) opt.signal=extra.signal;
		return fetch(api+path,opt)
			.then(function(res){return res.text().then(function(text){
				if(!res.ok) throw new Error('HTTP '+res.status+' '+(text||'').slice(0,180));
				return parseJson(text);
			});});
	}
	function readStoredRefs(){
		var list=[];
		['sessionStorage','localStorage'].forEach(function(kind){
			if(list.length) return;
			try{
				var raw=window[kind].getItem('airag.refs');
				var parsed=raw?JSON.parse(raw):[];
				if(Array.isArray(parsed)&&parsed.length) list=parsed;
			}catch(e){}
		});
		return list;
	}
	function qsRefs(){
		var q={};
		location.search.replace(/[?&]([^=]+)=([^&]*)/g,function(_,k,v){
			try{q[decodeURIComponent(k)]=decodeURIComponent(v);}catch(e){q[k]=v;}
		});
		var refs=[];
		try{refs=JSON.parse(q.refs||'[]')||[];}catch(e){refs=[];}
		if(!refs.length&&q.path) refs=[{path:q.path,name:q.name||q.path,type:q.type||'file'}];
		return Array.isArray(refs)?refs:[];
	}
	function collectRefs(){
		if(boot.refsGiven || boot.compact){
			return (Array.isArray(boot.refs)?boot.refs:[]).filter(function(item){return item&&item.path;});
		}
		var refs=Array.isArray(boot.refs)?boot.refs.slice():[];
		if(!refs.length) refs=qsRefs();
		return (refs||[]).filter(function(item){return item&&item.path;});
	}
	function clearStoredRefs(){
		try{sessionStorage.removeItem('airag.refs');}catch(e){}
		try{localStorage.removeItem('airag.refs');}catch(e){}
	}
	function toolsOn(name){return !!state.tools[name];}
	function setTool(name,on){
		state.tools[name]=!!on;
		document.querySelectorAll('.airag-tool').forEach(function(row){
			if(row.getAttribute('data-tool')===name) row.querySelector('.airag-sw').classList.toggle('is-on',!!on);
		});
	}
	function renderRefs(){
		var box=$('airag-refs');
		if(!box) return;
		box.classList.toggle('is-empty', !state.refs.length);
		box.innerHTML=state.refs.map(function(item,i){
			return '<span class="airag-chip" data-path="'+esc(item.path||'')+'" title="点击预览">'+
				(item.type==='folder'?'<i class="airag-folder-cover">📁</i>':fileCover(item,'is-chip'))+' <em>'+esc(item.name||item.path)+'</em>'+
				'<b data-i="'+i+'" title="移除">×</b></span>';
		}).join('');
	}
	function renderHist(){
		var query=($('airag-history-search').value||'').trim().toLowerCase();
		$('airag-hist').innerHTML=state.list.filter(function(item){return !query||String(item.title||'').toLowerCase().indexOf(query)>=0;}).map(function(item){
			return '<div role="button" tabindex="0" class="airag-hist-item'+(item.id===state.id?' is-on':'')+'" data-id="'+esc(item.id)+'"><span>'+esc(item.title||'未命名')+'</span><button class="del" type="button" data-del="'+esc(item.id)+'">×</button></div>';
		}).join('')||'<div class="airag-side-label">还没有对话</div>';
	}
	function chunkPos(s){
		s=s||{};
		if(s.chunk==null) return '正文摘要';
		var no=Number(s.chunk);
		if(!(no>=0)) no=0;
		var disp=no+1;
		var tot=Number(s.chunks||0); if(tot<disp) tot=disp;
		return disp+' / '+tot;
	}
	function groupSources(sources){
		var list=[], map={};
		(sources||[]).forEach(function(s,si){
			var key=String(s.fileID||s.path||s.name||('i'+si));
			if(!map[key]){
				map[key]={fileID:s.fileID,name:s.name,path:s.path,size:s.size,chunks:s.chunks,items:[]};
				list.push(map[key]);
			}
			map[key].items.push(s);
		});
		return list;
	}
	function toolLabel(t){
		t=t||{};
		var name=t.name||'工具';
		if(t.files!=null || t.chunks!=null){
			var files=Number(t.files)||0, chunks=Number(t.chunks!=null?t.chunks:t.count)||0;
			if(!files && !chunks) return name+' 未命中';
			return name+' '+files+' 篇 / '+chunks+' 片';
		}
		if(t.count!=null) return t.count? (name+' '+t.count+' 条') : (name+' 未命中');
		return name;
	}
	function fmtClock(ts){
		if(!ts) return '';
		var d=new Date(Number(ts)*1000);
		if(isNaN(d.getTime())) return '';
		var p=function(n){return n<10?'0'+n:String(n);};
		return p(d.getMonth()+1)+'-'+p(d.getDate())+' '+p(d.getHours())+':'+p(d.getMinutes());
	}
	function fmtDate(ts){
		if(!ts) return '';
		var d=new Date(Number(ts)*1000);
		if(isNaN(d.getTime())) return '';
		var p=function(n){return n<10?'0'+n:String(n);};
		return d.getFullYear()+'-'+p(d.getMonth()+1)+'-'+p(d.getDate())+' '+p(d.getHours())+':'+p(d.getMinutes())+':'+p(d.getSeconds());
	}
	function statsDetail(msg,u,total,est){
		var service=(msg.provider?msg.provider+' · ':'')+(msg.model||state.model||'未标记');
		var usage=(est?'约 ':'')+fmtNum(total)+' <small>(prompt: '+fmtNum(u.prompt||0)+', output: '+fmtNum(u.output||0)+', cache: '+fmtNum(u.cache||0)+')</small>';
		var rows=[['模型服务',service],['token 用量',usage],['总用时',fmtMs(msg.elapsedMs)]];
		if(msg.firstMs) rows.push(['首字耗时',fmtMs(msg.firstMs)]);
		if(msg.speed) rows.push(['生成速度',(est?'约 ':'')+msg.speed+' token/s']);
		if(msg.created) rows.push(['创建时间',fmtDate(msg.created)]);
		return '<span class="airag-stat-pop" role="tooltip">'+rows.map(function(row){return '<span><b>'+esc(row[0])+'：</b><i>'+(/^token/.test(row[0])?row[1]:esc(row[1]))+'</i></span>';}).join('')+'</span>';
	}
	function icoBtn(act,title,path,on){
		return '<button type="button" class="airag-ibtn'+(on?' is-on':'')+'" data-act="'+act+'" title="'+title+'"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round">'+path+'</svg></button>';
	}
	function fileCover(item, extra){
		item=item||{};
		var name=item.name||item.path||'文件';
		var ext=(String(name).split('.').pop()||'FILE').toUpperCase();
		if(ext.length>5) ext='FILE';
		var thumb=item.fileThumb||item.filethumb||item.thumb||item.cover||'';
		if(!thumb && item.path){
			var base=String(location.href||'').split('#')[0].split('?')[0];
			thumb=base+'?plugin/fileThumb/cover&width=240&path='+encodeURIComponent(item.path)+'&name='+encodeURIComponent('/'+name);
		}
		return '<span class="airag-filecover'+(extra?' '+extra:'')+'"><em>'+esc(ext)+'</em>'+
			(thumb?'<img src="'+esc(thumb)+'" alt="" loading="lazy" onerror="this.style.display=\'none\'">':'')+'</span>';
	}
	function fileCard(item){
		if(!item) return '';
		var name=item.name||item.path||'文件';
		var meta=[];
		if(item.size) meta.push(sizeText(item.size));
		return '<div class="airag-filecard" data-act="preview" data-path="'+esc(item.path||'')+'" data-name="'+esc(item.name||'')+'" title="点击预览">'+fileCover(item,'is-card')+'<div><b>'+esc(name)+'</b>'+(meta.length?'<small>'+esc(meta.join(' · '))+'</small>':'')+'</div></div>';
	}
	function userActs(){
		return '<div class="airag-barfoot is-user"><div class="airag-acts">'+
			icoBtn('edit','编辑','<path d="M12 20h9"/><path d="M16.5 3.5a2.1 2.1 0 013 3L7 19l-4 1 1-4z"/>')+
			icoBtn('copy','复制','<rect x="9" y="9" width="13" height="13" rx="2"/><path d="M5 15H4a2 2 0 01-2-2V4a2 2 0 012-2h9a2 2 0 012 2v1"/>')+
			icoBtn('share','分享','<circle cx="18" cy="5" r="3"/><circle cx="6" cy="12" r="3"/><circle cx="18" cy="19" r="3"/><path d="M8.6 13.5l6.8 4M15.4 6.5l-6.8 4"/>')+
			icoBtn('more','更多','<circle cx="12" cy="12" r="1"/><circle cx="19" cy="12" r="1"/><circle cx="5" cy="12" r="1"/>')+
			'</div><div class="airag-more"><button type="button" data-act="edit">编辑</button><button type="button" data-act="share">分享</button><button type="button" data-act="copy">复制</button><button type="button" data-act="delete">删除</button></div></div>';
	}
	function renderLog(){
		var empty=!state.messages.length;
		$('airag-empty').style.display=empty?'flex':'none';
		$('airag-log').style.display=empty?'none':'block';
		var box=$('airag-log');
		var stayBottom=!box.scrollHeight || (box.scrollHeight-box.scrollTop-box.clientHeight<80);
		box.innerHTML=state.messages.map(function(msg,i){
			var html='<div class="airag-msg '+esc(msg.role)+(msg.loading?' is-load':'')+'" data-i="'+i+'">';
			if(msg.loading && !msg.streaming){
				html+='<div class="airag-load"><span></span><span></span><span></span><em>'+esc(msg.content||'正在思考…')+'</em></div>';
			}else if(msg.role==='user'){
				if(msg.editing){
					html+='<div class="airag-user-bubble is-edit">';
					if(msg.refs&&msg.refs.length) html+=msg.refs.map(fileCard).join('');
					html+='<textarea class="airag-edit-box" data-act="edit-box">'+esc(msg.content||'')+'</textarea>';
					html+='<div class="airag-edit-acts"><button type="button" data-act="edit-cancel">取消</button><button type="button" class="is-pri" data-act="edit-send">发送</button></div></div>';
				}else{
					html+='<div class="airag-user-bubble">';
					if(msg.refs&&msg.refs.length) html+=msg.refs.map(fileCard).join('');
					html+='<div class="airag-msg-body">'+esc(msg.content||'')+'</div></div>'+userActs();
				}
			}else{
				var tools=msg.tools||[];
				if(!tools.length && msg.sources && msg.sources.length){
					var g0=groupSources(msg.sources);
					tools=[{name:'知识库检索',ok:true,files:g0.length,chunks:msg.sources.length}];
				}
				if(tools.length){
					html+='<div class="airag-calls">'+tools.map(function(t){
						if((t.name||'')==='知识库检索' && msg.sources && msg.sources.length) return '<span class="'+(t.ok===false?'is-fail':'is-ok')+'">知识库检索完成</span>';
						return '<span class="'+(t.ok===false?'is-fail':'is-ok')+'" title="篇=命中文档数，片=喂给模型的分片数">'+esc(toolLabel(t))+'</span>';
					}).join('')+'</div>';
				}
				if(msg.reasoning){
					var thinkLive=msg.streaming&&!msg.answerStarted;
					html+='<details class="airag-think-box'+(thinkLive?' is-live':'')+'"'+(thinkLive?' open':'')+'><summary>'+(thinkLive?'正在深度思考':'思考过程')+'</summary><div class="airag-think">'+esc(msg.reasoning)+'</div></details>';
				}
				html+='<div class="airag-msg-body airag-md">'+(msg.streaming?'<span class="airag-stream-text">'+esc(msg.content||'')+'</span><i class="airag-caret"></i>':md(msg.content||'',msg.sources||[]))+'</div>';
				if(!msg.streaming && msg.sources&&msg.sources.length){
					var groups=groupSources(msg.sources);
					html+='<details class="airag-refs-box"><summary>'+fileIco()+'<span>引用资料</span><b>'+groups.length+'</b><em>篇</em></summary><div class="airag-src-list">';
					html+=groups.map(function(g){
						return '<section class="airag-src-group"><div class="airag-src-title">'+fileIco()+'<b>'+esc(g.name||'资料')+'</b><em>'+g.items.length+' 片</em></div>'+
							g.items.map(function(src){
								var n=src.index||1;
								return '<button type="button" class="airag-src-piece" data-act="cite" data-n="'+n+'" data-file="'+(g.fileID||'')+'" data-chunk="'+(src.chunk==null?-1:src.chunk)+'" data-path="'+esc(g.path||'')+'">'+
									'<i>'+n+'</i><span>'+esc(src.snippet||'该分片暂无摘要，点击查看正文')+'</span></button>';
							}).join('')+'</section>';
					}).join('');
					html+='</div></details>';
				}
				if(msg.note && !msg.streaming) html+='<div class="airag-note">'+esc(msg.note)+'</div>';
				if(!msg.streaming){
				var u=msg.usage||{};
				var total=u.total||((u.prompt||0)+(u.output||0));
				var est=!!u.est;
				html+='<div class="airag-barfoot">';
				html+='<div class="airag-acts">'+
					icoBtn('copy','复制','<rect x="9" y="9" width="13" height="13" rx="2"/><path d="M5 15H4a2 2 0 01-2-2V4a2 2 0 012-2h9a2 2 0 012 2v1"/>')+
					icoBtn('star','收藏','<path d="M12 17.3l-6.2 3.3 1.2-7L2 8.6l7.1-1L12 1l2.9 6.6 7.1 1-5 4.9 1.2 7z"/>',!!msg.starred)+
					icoBtn('share','分享','<circle cx="18" cy="5" r="3"/><circle cx="6" cy="12" r="3"/><circle cx="18" cy="19" r="3"/><path d="M8.6 13.5l6.8 4M15.4 6.5l-6.8 4"/>')+
					icoBtn('retry','重新生成','<polyline points="23 4 23 10 17 10"/><path d="M20.49 15a9 9 0 11.5-5.5"/>')+
					icoBtn('more','更多','<circle cx="12" cy="12" r="1"/><circle cx="19" cy="12" r="1"/><circle cx="5" cy="12" r="1"/>')+
					'</div>';
				html+='<div class="airag-more"><button type="button" data-act="star">收藏</button><button type="button" data-act="share">分享</button><button type="button" data-act="copy">复制</button><button type="button" data-act="export">导出</button><button type="button" data-act="retry">重新生成</button></div>';
				if(total || msg.elapsedMs){
					html+='<div class="airag-stats" tabindex="0">'+(fmtClock(msg.created)?'<span>'+fmtClock(msg.created)+'</span>':'')+'<span>token: '+(est?'约 ':'')+fmtNum(total)+'</span><span>'+fmtMs(msg.elapsedMs)+'</span>'+statsDetail(msg,u,total,est)+'</div>';
				}
				html+='</div>';
				}
			}
			return html+'</div>';
		}).join('');
		if(stayBottom) box.scrollTop=box.scrollHeight;
		$('airag-new').disabled=state.busy;
		$('airag-new').title=state.busy?'请先停止当前回复':'';
		if(empty) $('airag-latest').hidden=true;
		var sendBtn=$('airag-send'), stopBtn=$('airag-stop');
		if(sendBtn) sendBtn.style.display=state.busy?'none':'inline-flex';
		if(stopBtn) stopBtn.style.display=state.busy?'inline-flex':'none';
	}
	function renderModel(){
		var current=state.models.filter(function(m){return m.id===state.model;})[0];
		$('airag-model-btn').innerHTML=modelIcon(current||state.model)+'<span>'+esc(current?modelLabel(current):(state.model||'选择模型'))+'</span>';
		$('airag-models').innerHTML=state.models.map(function(m){
			return '<div class="'+(m.id===state.model?'is-on':'')+'" data-model="'+esc(m.id)+'">'+modelIcon(m)+'<div><b>'+esc(modelLabel(m))+'</b>'+(m.provider?'<small>'+esc(m.provider)+'</small>':'')+'</div></div>';
		}).join('')||'<div>请先在插件「模型服务」里启用对话模型并保存</div>';
		$('airag-think-btn').classList.toggle('is-on',state.thinking);
	}
	function closePop(){
		$('airag-tools').classList.remove('is-open');
		$('airag-models').classList.remove('is-open');
	}
	function setMode(mode){
		if(mode==='image' && !mediaReady('image')) mode='chat';
		if(mode==='asr' && !mediaReady('asr')) mode='chat';
		state.mode=mode;
		document.querySelectorAll('#airag-tabs button').forEach(function(btn){
			btn.classList.toggle('is-on',btn.getAttribute('data-mode')===mode);
		});
		['chat','image','asr'].forEach(function(name){
			var el=$('view-'+name);
			if(el) el.classList.toggle('is-on',name===mode);
		});
	}
	function mediaReady(type){
		var list=type==='image'?(boot.imageModels||[]):(boot.asrModels||[]);
		return Array.isArray(list) && list.length>0;
	}
	function gateMediaTabs(){
		var img=mediaReady('image'), asr=mediaReady('asr');
		document.querySelectorAll('#airag-tabs [data-mode="image"]').forEach(function(btn){ btn.style.display=img?'':'none'; });
		document.querySelectorAll('#airag-tabs [data-mode="asr"]').forEach(function(btn){ btn.style.display=asr?'':'none'; });
		var tabs=$('airag-tabs');
		if(tabs) tabs.style.display=(!img && !asr)?'none':'';
		if(!img && $('view-image')) $('view-image').classList.remove('is-on');
		if(!asr && $('view-asr')) $('view-asr').classList.remove('is-on');
	}
	function openAdmin(blank){
		var hash='#admin/setting/aiRag';
		var url='./index.php'+hash;
		try{
			var loc=(window.top&&window.top.location)?window.top.location:location;
			url=loc.pathname+(loc.search||'')+hash;
		}catch(e){}
		if(blank){
			window.open(url,'airag-console','noopener,width=1360,height=860');
			return;
		}
		try{
			var p=window.top||window.parent||window;
			if(p!==window && p.Router&&typeof p.Router.go==='function'){p.Router.go('admin/setting/aiRag');return;}
			if(p!==window && p.location){p.location.href=url;return;}
		}catch(e){}
		location.href=url;
	}
	function setMsg(id,text,ok){
		var el=$(id); if(!el) return;
		el.textContent=text||'';
		el.style.color=ok===false?'#d9822b':(ok?'#20a53a':'#8a8f99');
	}
	function resetChat(refs){
		if(state.busy) return;
		historyRequest++;
		state.id='';
		state.title='新对话';
		state.messages=[];
		state.refs=Array.isArray(refs)?refs.slice():[];
		clearStoredRefs();
		renderRefs();renderLog();renderHist();
		$('airag-input').focus();
	}
	function addRef(info){
		if(!info) return;
		var item={
			path:info.path||info,
			name:info.name||info.pathDisplay||info.path,
			type:info.type||(info.isFolder?'folder':'file'),
			size:Number(info.size||0),
			ext:info.ext||'',
			fileThumb:info.fileThumb||info.filethumb||info.thumb||info.fileShowView||''
		};
		if(!item.path) return;
		if(state.refs.some(function(r){return r.path===item.path;})) return;
		state.refs.push(item);
		renderRefs();
	}
	function pickRef(){
		var parent=window.parent!==window?window.parent:window;
		if(parent.kodApi&&parent.kodApi.pathSelect){
			new parent.kodApi.pathSelect({
				type:'file,folder',
				single:true,
				title:'引用文件或文件夹',
				callback:function(info){addRef(info);}
			});
			return;
		}
		var path=window.prompt('输入要引用的文件或文件夹路径');
		if(path) addRef({path:path,name:path,type:'file'});
	}
	function loadList(){
		return post('chat',{operation:'list'}).then(function(res){
			if(res&&res.code&&res.data){
				state.list=res.data.list||[];
				applyModels(Array.isArray(res.data.models)?res.data.models:[], state.model||res.data.model||'');
				renderHist();renderModel();
			}
		});
	}
	var historyRequest=0;
	function loadOne(id){
		if(state.busy) return Promise.resolve();
		var request=++historyRequest;
		return post('chat',{operation:'get',id:id}).then(function(res){
			if(request!==historyRequest||state.busy||!(res&&res.code&&res.data&&res.data.item)) return;
			var item=res.data.item;
			state.id=item.id;
			state.title=item.title;
			state.messages=(item.messages||[]).map(function(m,idx,arr){
				if(m&&m.role==='user'&&(!m.refs||!m.refs.length)&&item.refs&&item.refs.length){
					var lastUser=-1;
					arr.forEach(function(x,j){ if(x&&x.role==='user') lastUser=j; });
					if(idx===lastUser) m.refs=item.refs.slice();
				}
				return m;
			});
			state.refs=item.refs&&item.refs.length?item.refs.slice():[];
			if(item.model) state.model=item.model;
			state.thinking=!!item.thinking;
			state.tools=item.tools||state.tools;
			['disk','web','mail','save'].forEach(function(k){setTool(k,state.tools[k]);});
			renderRefs();renderLog();renderHist();renderModel();
		});
	}
	function lastBot(){
		for(var i=state.messages.length-1;i>=0;i--){
			if(state.messages[i].role==='bot') return state.messages[i];
		}
		return null;
	}
	function scheduleStreamFollow(thinkEl){
		if(thinkEl && thinkEl._airagFollow!==false) streamThinkEl=thinkEl;
		if(streamScrollRaf) return;
		streamScrollRaf=raf(function(){
			streamScrollRaf=0;
			var box=$('airag-log');
			if(streamFollowBottom&&box) box.scrollTop=2147483647;
			if(streamThinkEl&&streamThinkEl.isConnected&&streamThinkEl._airagFollow!==false) streamThinkEl.scrollTop=2147483647;
			streamThinkEl=null;
		});
	}
	function patchStream(){
		var msg=lastBot();
		if(!msg) return;
		var wrap=document.querySelector('#airag-log .airag-msg.bot:last-child');
		if(!wrap){ renderLog(); return; }
		var mdEl=wrap.querySelector('.airag-md');
		var thinkEl=wrap.querySelector('.airag-think');
		if(msg.reasoning){
			if(!thinkEl){
				var thinkBox=document.createElement('details');
				thinkBox.className='airag-think-box is-live';
				thinkBox.open=!msg.answerStarted;
				thinkBox.innerHTML='<summary>'+(msg.answerStarted?'思考过程':'正在深度思考')+'</summary><div class="airag-think"></div>';
				wrap.insertBefore(thinkBox, wrap.querySelector('.airag-md')||wrap.firstChild);
				thinkEl=thinkBox.querySelector('.airag-think');
			}
			if(!thinkEl._airagFollowBound){
				thinkEl._airagFollowBound=true;thinkEl._airagFollow=true;
				thinkEl.addEventListener('wheel',function(){this._airagFollow=false;},{passive:true});
				thinkEl.addEventListener('touchstart',function(){this._airagFollow=false;},{passive:true});
			}
			var details=thinkEl.closest('details');
			var shown=thinkEl._airagText||thinkEl.textContent||'';
			var thinkNode=thinkEl.firstChild;
			if(msg.reasoning.length>=shown.length && thinkNode && thinkNode.nodeType===3 && thinkEl.childNodes.length===1) thinkNode.appendData(msg.reasoning.slice(shown.length));
			else thinkEl.textContent=msg.reasoning;
			thinkEl._airagText=msg.reasoning;
			if(details){
				details.classList.toggle('is-live',!msg.answerStarted);
				var summary=details.querySelector('summary');
				if(summary) summary.textContent=msg.answerStarted?'思考过程':'正在深度思考';
				if(msg.answerStarted&&!msg.thinkCollapsed){details.open=false;msg.thinkCollapsed=true;}
			}
		}
		var raw=msg.content||'';
		if(mdEl&&mdEl._airagRaw!==raw){
			var streamText=mdEl.querySelector('.airag-stream-text');
			if(!streamText){
				mdEl.innerHTML='<span class="airag-stream-text"></span><i class="airag-caret"></i>';
				streamText=mdEl.querySelector('.airag-stream-text');
			}
			var shown=streamText._airagText||streamText.textContent||'';
			var answerNode=streamText.firstChild;
			if(raw.length>=shown.length && answerNode && answerNode.nodeType===3 && streamText.childNodes.length===1) answerNode.appendData(raw.slice(shown.length));
			else streamText.textContent=raw;
			streamText._airagText=raw;
			mdEl._airagRaw=raw;
		}
		scheduleStreamFollow(thinkEl);
	}
	var streamRenderRaf=0;
	function scheduleStreamPatch(force){
		if(force){
			if(streamRenderRaf){caf(streamRenderRaf);streamRenderRaf=0;}
			patchStream();return;
		}
		if(streamRenderRaf) return;
		streamRenderRaf=raf(function(){streamRenderRaf=0;patchStream();});
	}
	function typePush(s){
		if(!s) return;
		var msg=lastBot();
		if(!msg) return;
		if(!msg.streaming){
			msg.loading=false;msg.streaming=true;
			if(msg.content==='正在检索资料…') msg.content='';
			renderLog();
		}
		if(!msg.answerStarted){msg.answerStarted=Date.now();msg.thinkCollapsed=false;}
		msg.content=(msg.content||'')+s;
		scheduleStreamPatch(false);
	}
	function typeDrain(){
		scheduleStreamPatch(true);
	}
	function parseSseBlock(block, onEvent){
		var ev='message', data=[];
		String(block||'').split('\n').forEach(function(line){
			if(line.indexOf('event:')===0) ev=line.slice(6).trim();
			else if(line.indexOf('data:')===0) data.push(line.slice(5).trim());
		});
		if(!data.length) return;
		var raw=data.join('\n');
		try{ onEvent(ev, JSON.parse(raw)); }catch(e){}
	}
	function readSse(res, onEvent){
		if(!res.body||!res.body.getReader){
			return res.text().then(function(text){
				text.split(/\n\n/).forEach(function(block){ parseSseBlock(block, onEvent); });
				return text;
			});
		}
		var reader=res.body.getReader(), dec=new TextDecoder(), buf='';
		function pump(){
			return reader.read().then(function(part){
				if(part.value) buf+=dec.decode(part.value,{stream:!part.done});
				var chunks=buf.split('\n\n');
				buf=chunks.pop()||'';
				chunks.forEach(function(block){ parseSseBlock(block, onEvent); });
				if(part.done){
					if(buf) parseSseBlock(buf, onEvent);
					return;
				}
				return pump();
			});
		}
		return pump();
	}
	function finishBot(data, fallback){
		typeDrain();
		var msg=lastBot()||{};
		var streamed=String(msg.content||'');
		var incoming=data&&data.answer!=null?String(data.answer):'';
		// `done.answer` is sent only when the server had to repair or normalize
		// the streamed text, so it is authoritative even when it is shorter.
		var answer=incoming||streamed||fallback||msg.reasoning||'';
		replaceLoading({
			role:'bot',
			content:answer,
			streaming:false,
			loading:false,
			reasoning:(data&&data.reasoning)||msg.reasoning||'',
			sources:(data&&data.sources)||msg.sources||[],
			note:(data&&data.note)||msg.note||'',
			fed:data&&data.fed,
			tools:(data&&data.tools)||msg.tools||[],
			usage:(data&&data.usage)||{},
			elapsedMs:(data&&data.elapsedMs)||0,
			firstMs:(data&&data.firstMs)||msg.firstMs||0,
			speed:(data&&data.speed)||0,
			model:(data&&data.model)||state.model,
			provider:(data&&data.provider)||'',
			created:(data&&data.created)||Math.floor(Date.now()/1000)
		});
		if(data&&data.id){state.id=data.id;state.title=data.title||state.title;}
		if(data&&data.model) state.model=data.model;
		renderLog();renderModel();
	}
	function send(text){
		var input=$('airag-input');
		var q=(text!=null?text:(input.value||'')).trim();
		if(!q||state.busy) return;
		if(!state.model){ chatToast('没有可用的对话模型（检测未通过的已排除）'); return; }
		hideCiteTip(0);
		streamFollowBottom=true;
		if(text==null) input.value='';
		state.messages.push({role:'user',content:q,refs:state.refs.slice()});
		state.messages.push({role:'bot',content:'正在检索资料…',loading:true,streaming:false});
		state.busy=true;
		state.waitSec=0;
		if(state.waitTimer) clearInterval(state.waitTimer);
		state.waitTimer=setInterval(function(){
			state.waitSec++;
			var em=document.querySelector('#airag-log .airag-load em');
			if(em) em.textContent='正在检索资料… '+state.waitSec+'s';
		},1000);
		state.abort=typeof AbortController==='function'?new AbortController():null;
		renderLog();
		var payload={
			question:q,
			id:state.id,
			model:state.model,
			thinking:state.thinking?1:0,
			stream:1,
			tools:state.tools,
			paths:state.refs.map(function(r){return r.path;}),
			refs:state.refs,
			history:state.messages.filter(function(m){return m.role==='user'||(!m.loading&&!m.streaming);}).slice(0,-1)
		};
		var body=new URLSearchParams();
		Object.keys(payload).forEach(function(k){
			var v=payload[k];
			if(v===undefined||v===null) return;
			body.append(k, typeof v==='string'?v:JSON.stringify(v));
		});
		fetch(api+'ask',{
			method:'POST',
			credentials:'same-origin',
			headers:{'Content-Type':'application/x-www-form-urlencoded;charset=UTF-8','Accept':'text/event-stream, application/json'},
			body:body.toString(),
			signal:state.abort?state.abort.signal:undefined
		}).then(function(res){
			var ctype=String(res.headers.get('content-type')||'');
			if(ctype.indexOf('text/event-stream')>=0){
				var streamFail=null, finished=false;
				return readSse(res, function(ev, data){
					data=data||{};
					var msg=lastBot();
					if(!msg) return;
					if(ev==='meta'){
						if(state.waitTimer){clearInterval(state.waitTimer);state.waitTimer=0;}
						msg.loading=false;
						msg.streaming=true;
						msg.content='';
						msg.tools=data.tools||[];
						msg.sources=data.sources||[];
						msg.note=data.note||'';
						msg.provider=data.provider||'';
						msg.model=data.model||state.model;
						renderLog();
					}
					if(ev==='delta') typePush(data.text||'');
					if(ev==='think'){
						msg.reasoning=(msg.reasoning||'')+(data.text||'');
						if(!msg.streaming){ msg.loading=false; msg.streaming=true; if(msg.content==='正在检索资料…') msg.content=''; renderLog(); }
						else scheduleStreamPatch(false);
					}
					if(ev==='done'){ finished=true; finishBot(data); }
					if(ev==='error') streamFail=new Error(data.message||'提问失败');
				}).then(function(){
					if(streamFail) throw streamFail;
					var msg=lastBot();
					if(!finished && msg&&(msg.streaming||msg.loading)) finishBot(null, msg.content||'已停止生成');
				});
			}
			return res.text().then(function(text){
				if(!res.ok) throw new Error('HTTP '+res.status+' '+(text||'').slice(0,180));
				var json=parseJson(text);
				var ok=!!(json&&json.code);
				var data=json&&json.data||{};
				if(!ok) throw new Error((data&&data.message)||json.message||'提问失败');
				if(state.waitTimer){clearInterval(state.waitTimer);state.waitTimer=0;}
				var bot=lastBot();
				if(bot){ bot.loading=false; bot.streaming=true; bot.content=''; bot.sources=data.sources||[]; bot.tools=data.tools||[]; renderLog(); }
				typePush(data.answer||'');
				finishBot(data);
				return data;
			});
		}).catch(function(err){
			typeDrain();
			if(err&&err.name==='AbortError'){
				var msg=lastBot();
				replaceLoading({role:'bot',content:(msg&&msg.content)||'已停止生成',streaming:false,loading:false});
			}else replaceLoading({role:'bot',content:String(err&&err.message||err||'请求失败'),streaming:false,loading:false});
			renderLog();
		}).then(function(){
			state.busy=false; state.abort=null;
			if(state.waitTimer){clearInterval(state.waitTimer);state.waitTimer=0;}
			var sendBtn=$('airag-send'), stopBtn=$('airag-stop');
			if(sendBtn) sendBtn.style.display='inline-flex';
			if(stopBtn) stopBtn.style.display='none';
			input.focus();
			return loadList();
		});
	}
	function replaceLoading(msg){
		var i=state.messages.length-1;
		if(i>=0 && state.messages[i] && (state.messages[i].loading || state.messages[i].streaming || state.messages[i].role==='bot')){
			state.messages[i]=msg;
			return;
		}
		state.messages.push(msg);
	}
	function stopSend(){
		typeDrain();
		if(state.abort) try{state.abort.abort();}catch(e){}
		state.busy=false;
		if(state.waitTimer){clearInterval(state.waitTimer);state.waitTimer=0;}
		var msg=lastBot();
		if(msg&&(msg.streaming||msg.loading)){
			msg.streaming=false; msg.loading=false;
			if(!msg.content||msg.content==='正在检索资料…') msg.content='已停止生成';
		}
		renderLog();
	}
	function editAt(i){
		i=Number(i);
		var msg=state.messages[i];
		if(!msg||msg.role!=='user'||state.busy) return;
		state.messages.forEach(function(m){ m.editing=false; });
		msg.editing=true;
		renderLog();
		var ta=document.querySelector('.airag-msg[data-i="'+i+'"] textarea.airag-edit-box');
		if(ta){
			ta.focus();
			var n=ta.value.length;
			try{ ta.setSelectionRange(n,n); }catch(e){}
		}
	}
	function cancelEdit(i){
		i=Number(i);
		var msg=state.messages[i];
		if(!msg) return;
		msg.editing=false;
		renderLog();
	}
	function commitEdit(i){
		i=Number(i);
		var msg=state.messages[i];
		if(!msg||msg.role!=='user') return;
		var wrap=document.querySelector('.airag-msg[data-i="'+i+'"]');
		var ta=wrap&&wrap.querySelector('textarea.airag-edit-box');
		var q=((ta&&ta.value)||msg.content||'').trim();
		msg.editing=false;
		if(!q||state.busy){ renderLog(); return; }
		if(msg.refs&&msg.refs.length) state.refs=msg.refs.slice();
		state.messages=state.messages.slice(0,i);
		send(q);
	}
	function deleteAt(i){
		i=Number(i);
		if(!state.messages[i]||state.messages[i].role!=='user') return;
		var next=state.messages[i+1];
		var end=next&&next.role==='bot'?i+2:i+1;
		state.messages=state.messages.slice(0,i).concat(state.messages.slice(end));
		renderLog();
	}
	function retryAt(i){
		i=Number(i);
		var msg=state.messages[i];
		if(!msg) return;
		if(msg.role==='bot'){
			while(i>=0&&state.messages[i].role!=='user') i--;
			msg=state.messages[i];
		}
		if(!msg||msg.role!=='user') return;
		var q=msg.content;
		state.messages=state.messages.slice(0,i);
		send(q);
	}
	var citeTipTimer=0, citeTipBtn=null;
	function hideCiteTip(delay){
		clearTimeout(citeTipTimer);
		if(delay){
			citeTipTimer=setTimeout(function(){ hideCiteTip(0); }, delay);
			return;
		}
		var tip=document.getElementById('airag-cite-tip');
		if(tip) tip.remove();
		citeTipBtn=null;
	}
	function showCiteTip(btn){
		if(!btn) return;
		clearTimeout(citeTipTimer);
		if(citeTipBtn===btn && document.getElementById('airag-cite-tip')) return;
		hideCiteTip(0);
		citeTipBtn=btn;
		var n=btn.getAttribute('data-n')||'1';
		var fileID=btn.getAttribute('data-file')||'';
		var msgEl=btn.closest('.airag-msg');
		var i=msgEl?Number(msgEl.getAttribute('data-i')):-1;
		var all=(state.messages[i]||{}).sources||[];
		var src=all.filter(function(s){return String(s.index||'')===String(n);})[0]
			||all[Number(n)-1]||{};
		var same=fileID?all.filter(function(s){return String(s.fileID||'')===String(fileID);}):[src];
		if(!same.length) same=[src];
		var nos=same.map(function(s){ return s.chunk!=null&&Number(s.chunk)>=0?Number(s.chunk)+1:Number(s.index||1); }).filter(Boolean);
		var meta=sizeText(src.size||same[0]&&same[0].size)+' · '+(same.length>1?('引用 '+same.length+' 片（'+nos.join('、')+'）'):('分片 '+chunkPos(src)));
		var tip=document.createElement('div');
		tip.id='airag-cite-tip';
		tip.className='airag-cite-tip';
		tip.innerHTML='<b>'+esc(src.name||same[0]&&same[0].name||'资料 '+n)+'</b>'+
			'<span>'+esc(meta)+'</span>'+
			'<div class="body">'+esc(src.snippet||'点击打开原文分片')+'</div>';
		document.body.appendChild(tip);
		var r=btn.getBoundingClientRect();
		tip.style.left=Math.max(8, Math.min(r.left, window.innerWidth-320))+'px';
		// 引用一般在回答末尾，下方常常没有空间，不够就翻到上面显示
		var h=tip.offsetHeight||200;
		tip.style.top=(r.bottom+6+h>window.innerHeight-8 ? Math.max(8, r.top-h-6) : r.bottom+6)+'px';
		tip.addEventListener('mouseenter',function(){ clearTimeout(citeTipTimer); });
		tip.addEventListener('mouseleave',function(){ hideCiteTip(480); });
		tip.addEventListener('wheel',function(e){
			var body=tip.querySelector('.body');
			var box=(body&&body.scrollHeight>body.clientHeight+1)?body:tip;
			var before=box.scrollTop;
			box.scrollTop=before+e.deltaY;
			if(box.scrollTop!==before){ e.preventDefault(); }
			e.stopPropagation();
		},{passive:false});
	}
	function findChunkIdx(chunks, want, extra){
		want=Number(want); if(!(want>=0)) want=0;
		var i, c, byIndex=-1, byText=-1;
		for(i=0;i<chunks.length;i++){
			c=chunks[i]||{};
			if(Number(c.index)===want) byIndex=i;
			if(extra && extra.text && c.text===extra.text) byText=i;
		}
		if(byIndex>=0) return byIndex;
		if(want>=1 && chunks[want-1] && Number((chunks[want-1]||{}).index)===want-1) return want-1;
		if(chunks[want]) return want;
		if(byText>=0) return byText;
		return 0;
	}
	function openCite(btn){
		var fileID=btn.getAttribute('data-file');
		var chunk=Number(btn.getAttribute('data-chunk')||0);
		if(chunk<0){
			var msg=btn.closest('.airag-msg');
			var sources=(state.messages[msg?Number(msg.getAttribute('data-i')):-1]||{}).sources||[];
			var source=sources.filter(function(s){return String(s.index)===String(btn.getAttribute('data-n'));})[0];
			if(source){showSourceDlg({name:source.name,size:source.size,summary:true},[{index:null,text:source.snippet}],0,source.path||'');return;}
		}
		var path=btn.getAttribute('data-path')||'';
		hideCiteTip(0);
		if(!fileID){
			if(path) openDisk(path);
			return;
		}
		post('chat',{operation:'source',fileID:fileID,chunk:chunk}).then(function(res){
			if(!res||res.code===false) throw new Error('引用分片不可用');
			var data=res&&res.data||{};
			var item=data.item||{};
			var chunks=data.chunks||item.chunks||[];
			var idx=findChunkIdx(chunks, data.chunkIndex!=null?data.chunkIndex:chunk, data.chunk);
			showSourceDlg(item, chunks, idx, path||item.path||'');
		}).catch(function(){ if(path) openDisk(path); });
	}
	function tryOpenDisk(win, path, name){
		if(!win) return false;
		try{
			if(win.kodApi&&typeof win.kodApi.fileView==='function'){ win.kodApi.fileView(path,{title:name||'文件预览'}); return true; }
			if(win.kodApp&&win.kodApp.pathAction&&typeof win.kodApp.pathAction.fileOpen==='function'){ win.kodApp.pathAction.fileOpen({path:path,name:name||''}); return true; }
			if(win.kodApp&&typeof win.kodApp.open==='function'){ win.kodApp.open(path); return true; }
			if(win.core&&win.core.openPath){ win.core.openPath(path); return true; }
		}catch(e){}
		return false;
	}
	function openDisk(path, name){
		if(!path) return;
		var now=Date.now();
		if(openDisk._path===path && now-openDisk._t<800) return;
		openDisk._path=path; openDisk._t=now;
		if(tryOpenDisk(window, path, name)) return;
		try{ if(window.parent&&window.parent!==window && tryOpenDisk(window.parent, path, name)) return; }catch(e){}
		try{ if(window.top&&window.top!==window && window.top!==window.parent && tryOpenDisk(window.top, path, name)) return; }catch(e){}
		try{ if(window.parent&&window.parent!==window) window.parent.postMessage({type:'airag-open',path:path,name:name||''},'*'); }catch(e){}
	}
	function showSourceDlg(item, chunks, idx, path){
		chunks=chunks&&chunks.length?chunks:[{index:0,text:item.content||''}];
		idx=Math.max(0, Math.min(idx||0, chunks.length-1));
		var old=document.getElementById('airag-src-mask');
		if(old) old.remove();
		function paint(){
			var c=chunks[idx]||{};
			var tot=item.chunkCount||chunks.length||(idx+1);
			if(tot<chunks.length) tot=chunks.length;
			mask.querySelector('.meta').textContent=sizeText(item.size)+(item.summary?' · 正文摘要':' · 分片 '+(Number(c.index)+1)+' / '+tot);
			mask.querySelector('pre').textContent=(c.text!=null&&c.text!=='')?c.text:(idx===0?(item.content||''):'（该分片无文本）');
			mask.querySelector('pre').scrollTop=0;
			mask.querySelector('[data-prev]').disabled=idx<=0;
			mask.querySelector('[data-next]').disabled=idx>=chunks.length-1;
		}
		var mask=document.createElement('div');
		mask.id='airag-src-mask';
		mask.className='airag-src-mask';
		mask.innerHTML='<div class="airag-src-dlg"><header><b>'+esc(item.name||'资料')+'</b><button type="button" data-x="1">×</button></header>'+
			'<div class="meta"></div>'+
			'<pre></pre>'+
			'<footer>'+
			'<button type="button" data-prev="1">上一片</button>'+
			'<button type="button" data-next="1">下一片</button>'+
			(path?'<button type="button" data-open="1">打开文件</button>':'')+
			'<button type="button" data-x="1">关闭</button></footer></div>';
		mask.addEventListener('click',function(e){
			var t=e.target.closest?e.target.closest('[data-x],[data-open],[data-prev],[data-next]'):e.target;
			if(e.target===mask||(t&&t.getAttribute('data-x'))){ mask.remove(); return; }
			if(t&&t.getAttribute('data-open')) openDisk(path, item.name||'');
			if(t&&t.getAttribute('data-prev')&&idx>0){ idx--; paint(); }
			if(t&&t.getAttribute('data-next')&&idx<chunks.length-1){ idx++; paint(); }
		});
		document.body.appendChild(mask);
		paint();
	}
	function chatToast(text){
		var t=document.getElementById('airag-chat-toast');
		if(!t){ t=document.createElement('div'); t.id='airag-chat-toast'; t.className='airag-chat-toast'; document.body.appendChild(t); }
		t.textContent=text||'';
		t.classList.add('is-show');
		clearTimeout(chatToast._t);
		chatToast._t=setTimeout(function(){ t.classList.remove('is-show'); }, 2200);
	}
	function msgAt(btn){
		var wrap=btn.closest('.airag-msg');
		return wrap?Number(wrap.getAttribute('data-i')):-1;
	}
	function copyText(text){
		text=String(text||'');
		if(navigator.clipboard&&navigator.clipboard.writeText) return navigator.clipboard.writeText(text);
		var ta=document.createElement('textarea'); ta.value=text; document.body.appendChild(ta); ta.select();
		try{document.execCommand('copy');}catch(e){}
		ta.remove();
		return Promise.resolve();
	}

	$('airag-new').onclick=function(){closePop();resetChat([]);};
	$('airag-admin').onclick=function(){openAdmin(false);};
	if($('airag-popout')) $('airag-popout').onclick=function(){openAdmin(true);};
	$('airag-tabs').onclick=function(e){
		var mode=e.target.getAttribute('data-mode');
		if(mode) setMode(mode);
	};
	$('airag-image-go').onclick=function(){
		if(!mediaReady('image')){setMsg('airag-image-msg','没有可用的图片模型',false);return;}
		var prompt=($('airag-image-prompt').value||'').trim();
		if(!prompt){setMsg('airag-image-msg','请先描述图片',false);return;}
		setMsg('airag-image-msg','生成中…');
		$('airag-image-go').disabled=true;
		post('media',{operation:'image',prompt:prompt,model:state.model}).then(function(res){
			var ok=!!(res&&res.code);
			setMsg('airag-image-msg',ok?'已生成':((res.data&&res.data.message)||res.message||'失败'),ok);
			if(ok&&res.data&&res.data.url){
				$('airag-image-out').innerHTML='<img src="'+esc(res.data.url)+'" alt="">';
			}
		}).catch(function(err){setMsg('airag-image-msg',String(err&&err.message||err),false);})
		.then(function(){$('airag-image-go').disabled=false;});
	};
	$('airag-asr-go').onclick=function(){
		if(!mediaReady('asr')){setMsg('airag-asr-msg','没有可用的语音模型',false);return;}
		var file=$('airag-asr-file').files&&$('airag-asr-file').files[0];
		if(!file){setMsg('airag-asr-msg','请选择音频文件',false);return;}
		setMsg('airag-asr-msg','识别中…');
		$('airag-asr-go').disabled=true;
		var body=new FormData();
		body.append('operation','asr');
		body.append('file',file);
		fetch(api+'media',{method:'POST',credentials:'same-origin',body:body}).then(function(res){return res.text().then(function(text){
			if(!res.ok) throw new Error('HTTP '+res.status);
			return parseJson(text);
		});}).then(function(res){
			var ok=!!(res&&res.code);
			setMsg('airag-asr-msg',ok?'完成':((res.data&&res.data.message)||res.message||'失败'),ok);
			$('airag-asr-out').textContent=ok?(res.data.text||''):'';
		}).catch(function(err){setMsg('airag-asr-msg',String(err&&err.message||err),false);})
		.then(function(){$('airag-asr-go').disabled=false;});
	};
	$('airag-send').onclick=function(){send();};
	$('airag-stop').onclick=stopSend;
	$('airag-log').addEventListener('click',function(e){
		var btn=e.target.closest('[data-act]');
		if(!btn) return;
		var act=btn.getAttribute('data-act'), i=btn.getAttribute('data-i');
		if(i==null) i=msgAt(btn);
		if(act==='edit-box') return;
		if(act==='edit') editAt(i);
		if(act==='edit-cancel') cancelEdit(i);
		if(act==='edit-send') commitEdit(i);
		if(act==='delete'){ document.querySelectorAll('.airag-more.is-open').forEach(function(el){el.classList.remove('is-open');}); deleteAt(i); chatToast('已删除'); }
		if(act==='retry'){ document.querySelectorAll('.airag-more.is-open').forEach(function(el){el.classList.remove('is-open');}); retryAt(i); }
		if(act==='cite') openCite(btn.closest('[data-act=cite]')||btn);
		if(act==='preview'){ openDisk(btn.getAttribute('data-path'), btn.getAttribute('data-name')); return; }
		if(act==='more'){
			e.stopPropagation();
			var menu=btn.closest('.airag-barfoot')&&btn.closest('.airag-barfoot').querySelector('.airag-more');
			document.querySelectorAll('.airag-more.is-open').forEach(function(el){ if(el!==menu) el.classList.remove('is-open'); });
			if(menu) menu.classList.toggle('is-open');
			return;
		}
		if(act==='copy'||act==='share'||act==='export'||act==='star'){
			var msg=state.messages[Number(i)]||{};
			var text=String(msg.content||'');
			document.querySelectorAll('.airag-more.is-open').forEach(function(el){el.classList.remove('is-open');});
			if(act==='copy') copyText(text).then(function(){ chatToast('已复制'); });
			if(act==='export'){
				var blob=new Blob([text],{type:'text/markdown;charset=utf-8'});
				var a=document.createElement('a'); a.href=URL.createObjectURL(blob); a.download=(state.title||'回答')+'.md'; a.click();
				chatToast('已导出');
			}
			if(act==='share'){
				if(navigator.share) navigator.share({title:state.title||'AI 回答',text:text}).catch(function(){});
				else copyText(text).then(function(){ chatToast('已复制，可去分享'); });
			}
			if(act==='star'){
				msg.starred=!msg.starred;
				state.messages[Number(i)]=msg;
				if(state.id) post('chat',{operation:'flag',id:state.id,index:Number(i),star:msg.starred?1:0});
				chatToast(msg.starred?'已收藏':'已取消收藏');
				renderLog();
			}
		}
	});
	$('airag-log').addEventListener('mouseover',function(e){
		var btn=e.target.closest('[data-act=cite],.airag-cite,.airag-src-chip');
		if(btn) showCiteTip(btn);
	});
	$('airag-log').addEventListener('mouseout',function(e){
		var btn=e.target.closest('[data-act=cite],.airag-cite,.airag-src-chip');
		if(!btn) return;
		var to=e.relatedTarget;
		if(to && (btn.contains(to) || (to.closest && to.closest('#airag-cite-tip')))) return;
		hideCiteTip(480);
	});
	$('airag-log').addEventListener('keydown',function(e){
		if(e.target.classList && e.target.classList.contains('airag-edit-box')){
			if(e.key==='Enter'&&!e.shiftKey&&!e.isComposing&&e.keyCode!==229){
				e.preventDefault();
				e.stopPropagation();
				commitEdit(msgAt(e.target));
			}
			if(e.key==='Escape'){ e.preventDefault(); cancelEdit(msgAt(e.target)); }
		}
	});
	$('airag-file-btn').onclick=function(){closePop();pickRef();};
	$('airag-think-btn').onclick=function(){state.thinking=!state.thinking;renderModel();};
	$('airag-tool-btn').onclick=function(){$('airag-models').classList.remove('is-open');$('airag-tools').classList.toggle('is-open');};
	$('airag-model-btn').onclick=function(){
		$('airag-tools').classList.remove('is-open');
		$('airag-models').classList.toggle('is-open');
		loadList();
	};
	$('airag-input').addEventListener('keydown',function(e){
		if(e.key==='Enter'&&!e.shiftKey&&!e.isComposing&&e.keyCode!==229){e.preventDefault();send();}
	});
	$('airag-refs').addEventListener('click',function(e){
		var x=e.target.closest('b[data-i]');
		if(x){
			e.stopPropagation();
			state.refs.splice(Number(x.getAttribute('data-i')),1);
			renderRefs();
			return;
		}
		var chip=e.target.closest('.airag-chip');
		if(chip&&chip.getAttribute('data-path')) openDisk(chip.getAttribute('data-path'), (chip.querySelector('em')&&chip.querySelector('em').textContent)||chip.textContent||'');
	});
	$('airag-hist').addEventListener('click',function(e){
		if(state.busy) return;
		document.body.classList.remove('is-side-open');
		$('airag-menu').setAttribute('aria-expanded','false');
		var del=e.target.getAttribute('data-del');
		if(del){
			e.stopPropagation();
			post('chat',{operation:'delete',id:del}).then(loadList).then(function(){
				if(state.id===del) resetChat([]);
			});
			return;
		}
		var id=e.target.closest('.airag-hist-item')&&e.target.closest('.airag-hist-item').getAttribute('data-id');
		if(id) loadOne(id);
	});
	$('airag-tools').addEventListener('click',function(e){
		var row=e.target.closest('.airag-tool');
		if(!row) return;
		var name=row.getAttribute('data-tool');
		setTool(name,!toolsOn(name));
	});
	$('airag-models').addEventListener('click',function(e){
		var node=e.target.closest('[data-model]');
		var name=node&&node.getAttribute('data-model');
		if(!name) return;
		state.model=name;
		closePop();
		renderModel();
	});
	document.addEventListener('click',function(e){
		if(!e.target.closest('.airag-tools,.airag-models,#airag-tool-btn,#airag-model-btn')) closePop();
		if(!e.target.closest('.airag-more,.airag-ibtn')) document.querySelectorAll('.airag-more.is-open').forEach(function(el){el.classList.remove('is-open');});
	});
	window.addEventListener('message',function(ev){
		var data=ev.data||{};
		if(ev.origin===location.origin&&ev.source===window.parent&&data.type==='airag-refs'&&Array.isArray(data.refs)){
			state.refs=[];
			data.refs.forEach(addRef);
		}
	});

	$('airag-history-search').addEventListener('input',renderHist);
	(function initSideResize(){
		var handle=$('airag-side-resizer'), side=$('airag-sidebar');
		if(!handle||!side) return;
		var saved=Number(localStorage.getItem('airag-side-width')||0);
		function setWidth(value,save){
			value=Math.max(180,Math.min(460,Number(value)||256));
			document.documentElement.style.setProperty('--airag-side-width',value+'px');
			handle.setAttribute('aria-valuenow',String(Math.round(value)));
			if(save) localStorage.setItem('airag-side-width',String(Math.round(value)));
		}
		if(saved) setWidth(saved,false);
		handle.addEventListener('pointerdown',function(e){
			if(window.innerWidth<=760) return;
			handle.setPointerCapture(e.pointerId); document.body.classList.add('is-side-resizing');
		});
		handle.addEventListener('pointermove',function(e){
			if(!handle.hasPointerCapture(e.pointerId)) return;
			setWidth(e.clientX,false);
		});
		handle.addEventListener('pointerup',function(e){
			if(handle.hasPointerCapture(e.pointerId)) handle.releasePointerCapture(e.pointerId);
			document.body.classList.remove('is-side-resizing');
			setWidth(side.getBoundingClientRect().width,true);
		});
		handle.addEventListener('pointercancel',function(){ document.body.classList.remove('is-side-resizing'); });
		handle.addEventListener('keydown',function(e){
			if(e.key!=='ArrowLeft'&&e.key!=='ArrowRight') return;
			e.preventDefault(); setWidth(side.getBoundingClientRect().width+(e.key==='ArrowRight'?16:-16),true);
		});
	})();
	$('airag-menu').onclick=function(){
		var open=document.body.classList.toggle('is-side-open');
		this.setAttribute('aria-expanded',String(open));
		if(open) $('airag-history-search').focus();
	};
	$('airag-hist').addEventListener('keydown',function(e){
		if((e.key==='Enter'||e.key===' ')&&e.target.classList.contains('airag-hist-item')){e.preventDefault();e.target.click();}
	});
	$('airag-latest').onclick=function(){$('airag-log').scrollTop=$('airag-log').scrollHeight;};
	$('airag-log').addEventListener('scroll',function(){
		streamFollowBottom=this.scrollHeight-this.scrollTop-this.clientHeight<160;
		$('airag-latest').hidden=streamFollowBottom;
	});
	document.querySelectorAll('[data-prompt]').forEach(function(button){button.onclick=function(){
		$('airag-input').value=this.getAttribute('data-prompt'); $('airag-input').focus();
	};});
	document.addEventListener('keydown',function(e){if(e.key==='Escape'){
		closePop(); document.body.classList.remove('is-side-open'); $('airag-menu').setAttribute('aria-expanded','false');
	}});

	if(boot.compact) document.body.classList.add('is-compact');
	gateMediaTabs();
	applyModels(boot.models||[], boot.model||'');
	resetChat(collectRefs());
	renderModel();
	loadList().catch(function(err){
		state.messages.push({role:'bot',content:String(err&&err.message||err)});
		renderLog();
	});
	setInterval(function(){ if(!document.hidden&&!state.busy) loadList().catch(function(){}); }, 30000);
})();
