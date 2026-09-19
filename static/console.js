if(!window.__AiRagConsoleDefined){
window.__AiRagConsoleDefined=true;
ClassBase.define({
	init:function(){
		this.opt=G.aiRagOption||{};
		this.api=String(this.opt.api||'?plugin/aiRag/').replace(/\/?$/,'/');
		this.host=String(this.opt.host||'./plugins/aiRag/');
		this.cfg={}; this.services=[]; this.page='library'; this.setTab='basic'; this.libPage=1; this.libWords=''; this.libStatus=''; this.libFilter={time:'',ext:'',size:'',sourceID:0,pathName:''}; this.poll=0; this._passFlash={};
		this.restoreRoute();
		if(!document.getElementById('airag-console-css')){
			$('<link id="airag-console-css" rel="stylesheet" href="'+this.host+'static/console.css?v='+(this.opt.version||'1.6.3')+'">').appendTo('head');
		}
		this.renderHtml('<div class="page-airag-console"><div class="airag-console"></div></div>');
		this.$root=this.$('.airag-console');
		this.bind('onRemove',function(){ $('#airag-mask,#airag-set-mask,#airag-toast,#airag-errtip,.airag-drawer,#airag-chunk-mask').remove(); $(document).off('click.airagDd').off('.airagErr'); $(window).off('hashchange.airagRoute'); try{ $(window.top).off('hashchange.airagRoute'); }catch(e){} if(this.poll) clearInterval(this.poll); }.bind(this));
		this.services=this.defaultServices();
		this.bindRoute();
		this.render();
		this.load();
	},
	load:function(){
		var self=this;
		this.post('manage',{operation:'getConfig'}).done(function(res){
			self.cfg=(res&&res.data)||{};
			self.services=self.cfg.services||self.parseServices(self.cfg.modelServices)||[];
			if(!self.services.length) self.services=self.defaultServices();
			self.render();
		});
	},
	parseServices:function(raw){
		if(_.isArray(raw)) return raw;
		try{var data=JSON.parse(raw||'[]'); return _.isArray(data)?data:[];}catch(e){return [];}
	},
	defaultServices:function(){
		return [{id:'siliconflow',name:'硅基流动',enabled:1,url:'https://api.siliconflow.cn/v1',apiKey:this.cfg.llmApiKey||this.cfg.embedApiKey||'',models:[
			{id:'deepseek-ai/DeepSeek-V3',name:'DeepSeek-V3',type:'chat',enabled:1,context:64000,status:''},
			{id:'BAAI/bge-m3',name:'bge-m3',type:'embed',enabled:1,context:8000,status:''},
			{id:'BAAI/bge-reranker-v2-m3',name:'bge-reranker-v2-m3',type:'rerank',enabled:1,context:8000,status:''}
		]}];
	},
	post:function(path,data){ return $.ajax({url:this.api+path,type:'POST',dataType:'json',data:data||{},timeout:60000}); },
	fixUrl:function(v){
		v=String(v||'').replace(/\\/g,'/').trim();
		v=v.replace(/^(https?):\/+/i,'$1://').replace(/^(https?:\/\/)\/+/i,'$1');
		return v.replace(/\/+(chat\/)?completions$/i,'').replace(/\/+embeddings$/i,'').replace(/\/+$/,'');
	},
	h:function(s){return $('<div>').text(s==null?'':s).html();},
	shortName:function(id){var p=String(id||'').split('/');return p[p.length-1]||id;},
	vendorOf:function(id){var p=String(id||'').split('/');return p.length>1?p[0]:'';},
	guessType:function(id){
		var low=String(id||'').toLowerCase();
		if(/rerank/.test(low)) return 'rerank';
		if(/bge|embed|e5-|nomic/.test(low)) return 'embed';
		if(/kolors|flux|sdxl/.test(low)) return 'image';
		if(/sensevoice|whisper|asr/.test(low)) return 'asr';
		return 'chat';
	},
	modelTypes:function(m){
		if(!m) return ['chat'];
		if(_.isArray(m.types)&&m.types.length) return m.types;
		if(typeof m.types==='string'&&m.types) return _.compact(String(m.types).split(/[,;]+/));
		return [m.type||this.guessType(m.id)];
	},
	modelHas:function(m,type){ return _.includes(this.modelTypes(m), type); },
	on:function(key){return String(this.cfg[key]==null?'1':this.cfg[key])!=='0';},
	isSettings:function(){return this.page==='models';},
	modelsOf:function(type){
		var list=[], self=this;
		_.each(this.services,function(svc){
			if(!svc.enabled) return;
			_.each(svc.models||[],function(m){
				if(!m.enabled||!self.modelHas(m,type)) return;
				if(type==='chat' && m.tested==='fail') return;
				list.push({id:m.id,name:m.name||m.id,provider:svc.name});
			});
		});
		return list;
	},
	optionHtml:function(type,selected){
		var list=this.modelsOf(type), self=this;
		if(!list.length) return '<option value="">请先在模型服务启用'+({embed:'向量',rerank:'重排序',chat:'对话',image:'图片',asr:'语音'}[type]||'')+'模型</option>';
		return _.map(list,function(m){ return '<option value="'+self.h(m.id)+'"'+(m.id===selected?' selected':'')+'>'+self.h((m.name||m.id)+(m.provider?' · '+m.provider:''))+'</option>'; }).join('');
	},
	notify:function(msg,ok,opt){
		if(opt&&opt.silent) return;
		msg=String(msg||'');
		if(!msg) return;
		var cls=ok===false?'is-fail':(ok===null?'is-wait':'is-ok');
		var $t=$('#airag-toast');
		if(!$t.length) $t=$('<div id="airag-toast" class="airag-toast"></div>').appendTo('body');
		$t.attr('class','airag-toast '+cls+' is-show').html('<i></i><span>'+this.h(msg)+'</span>');
		clearTimeout(this._toastT);
		this._toastT=setTimeout(function(){ $t.removeClass('is-show'); }, ok===false?4200:2600);
	},
	flashCheck:function($el, ok, text){
		this.notify(text, ok);
		if(!$el||!$el.length) return;
		$el.removeClass('is-wait is-ok is-fail is-mini').addClass(ok?'is-ok':'is-fail').attr('title',text||'').text(ok?'● 通过':'失败');
		// 失败只保留一个可悬浮查看原因的小标记，不留“检测失败”长文案
		if(!ok){
			setTimeout(function(){ if($el.hasClass('is-fail')) $el.addClass('is-mini').text('!'); }, 1600);
		}
	},
	confirm:function(msg,onOk){
		var self=this;
		$('#airag-mask').remove();
		var $mask=$('<div class="airag-mask" id="airag-mask"><div class="airag-modal airag-modal-sm"><h4>请确认</h4><p class="airag-confirm-txt">'+self.h(msg)+'</p><div class="airag-modal-actions"><button type="button" class="airag-btn" data-close="1">取消</button><button type="button" class="airag-btn airag-btn-primary" data-ok="1">确定</button></div></div></div>');
		$mask.on('click',function(e){ if(e.target===this||$(e.target).closest('[data-close]').length) $mask.remove(); });
		$mask.on('click','[data-ok]',function(){ $mask.remove(); if(onOk) onOk(); });
		$('body').append($mask);
	},
	ico:function(name){
		var d={
			pen:'<path d="M12 20h9"/><path d="M16.5 3.5a2.1 2.1 0 013 3L7 19l-4 1 1-4z"/>',
			trash:'<polyline points="3 6 5 6 21 6"/><path d="M19 6l-1 14H6L5 6"/><path d="M10 11v6M14 11v6"/>',
			play:'<polygon points="8 5 19 12 8 19 8 5"/>',
			plus:'<path d="M12 5v14M5 12h14"/>',
			search:'<circle cx="11" cy="11" r="7"/><path d="M20 20l-3-3"/>',
			retry:'<polyline points="23 4 23 10 17 10"/><path d="M20.49 15a9 9 0 1 1 .5-5.5"/>',
			library:'<path d="M4 19.5A2.5 2.5 0 016.5 17H20"/><path d="M6.5 2H20v20H6.5A2.5 2.5 0 014 19.5v-15A2.5 2.5 0 016.5 2z"/><path d="M8 7h8M8 11h6"/>',
			probe:'<circle cx="11" cy="11" r="7"/><path d="M20 20l-3-3"/>',
			basic:'<circle cx="12" cy="12" r="3"/><path d="M12 3v2M12 19v2M3 12h2M19 12h2M5.6 5.6l1.4 1.4M17 17l1.4 1.4M5.6 18.4L7 17M17 7l1.4-1.4"/>',
			models:'<rect x="4" y="4" width="7" height="7" rx="1.5"/><rect x="13" y="4" width="7" height="7" rx="1.5"/><rect x="4" y="13" width="7" height="7" rx="1.5"/><rect x="13" y="13" width="7" height="7" rx="1.5"/>',
			vector:'<ellipse cx="12" cy="6" rx="7" ry="3"/><path d="M5 6v6c0 1.7 3.1 3 7 3s7-1.3 7-3V6M5 12v6c0 1.7 3.1 3 7 3s7-1.3 7-3v-6"/>',
			chunk:'<path d="M8 6h13M8 12h13M8 18h13M3 6h.01M3 12h.01M3 18h.01"/>',
			vision:'<rect x="3" y="5" width="18" height="14" rx="2"/><circle cx="12" cy="12" r="3"/>',
			settings:'<circle cx="12" cy="12" r="3"/><path d="M19.4 15a1.65 1.65 0 00.33 1.82l.06.06a2 2 0 01-2.83 2.83l-.06-.06a1.65 1.65 0 00-1.82-.33 1.65 1.65 0 00-1 1.51V21a2 2 0 01-4 0v-.09A1.65 1.65 0 009 19.4a1.65 1.65 0 00-1.82.33l-.06.06a2 2 0 01-2.83-2.83l.06-.06A1.65 1.65 0 004.68 15a1.65 1.65 0 00-1.51-1H3a2 2 0 010-4h.09A1.65 1.65 0 004.6 9a1.65 1.65 0 00-.33-1.82l-.06-.06a2 2 0 012.83-2.83l.06.06A1.65 1.65 0 009 4.68a1.65 1.65 0 001-1.51V3a2 2 0 014 0v.09a1.65 1.65 0 001 1.51 1.65 1.65 0 001.82-.33l.06-.06a2 2 0 012.83 2.83l-.06.06A1.65 1.65 0 0019.4 9a1.65 1.65 0 001.51 1H21a2 2 0 010 4h-.09a1.65 1.65 0 00-1.51 1z"/>',
			pop:'<path d="M14 4h6v6M10 14l10-10M20 14v5a1 1 0 01-1 1H5a1 1 0 01-1-1V5a1 1 0 011-1h5"/>'
		};
		return '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round">'+(d[name]||'')+'</svg>';
	},
	render:function(){
		var nav=[
			['library','资源库','library'],['probe','检索测试','probe'],['models','模型服务','models']
		];
		var self=this;
		var html='<header class="airag-nav"><div class="airag-nav-brand">AIRAG</div><div class="airag-nav-tabs">'+_.map(nav,function(item){
			return '<a href="javascript:void(0)" data-page="'+item[0]+'" class="'+(self.page===item[0]?'is-on':'')+'">'+self.ico(item[2])+'<span>'+item[1]+'</span></a>';
		}).join('')+'<a href="javascript:void(0)" data-act="settings" class="'+(self._setOpen?'is-on':'')+'">'+self.ico('settings')+'<span>设置</span></a>'+
		'</div><div class="airag-nav-prog" id="airag-nav-prog">总进度 0%</div><button type="button" class="airag-nav-pop" data-act="popout" title="新窗口打开配置">'+self.ico('pop')+'新窗口</button></header><section class="airag-body"><div class="airag-flash"></div>'+
			'<div class="airag-progress-bar is-overall" id="airag-progress"><div class="head"><span class="phase">正在读取任务状态</span><span class="overall" title="估算工作量：扫描每文件计 1，向量每文档计 3；发现新文件会重新估算">总体 0%</span></div>'+
			'<div class="dual"><div class="stage"><div><b>1　扫描文件并检查共享正文</b><span class="scan-text">0 / 0</span></div><div class="bar scan"><i></i></div></div><div class="stage"><div><b>2　切片向量并写 Milvus</b><span class="vec-text">0 / 0</span></div><div class="bar vec"><i></i></div></div></div>'+
			'<div class="airag-progress-name">打开本页即显示总进度。空闲时会说明还剩多少、要不要点「手动更新」。</div></div>';
		html+=this.pageLibrary()+this.pageProbe()+this.pageModels();
		html+='<div class="airag-foot'+(this.isSettings()?'':' is-hide')+'"><button type="button" class="airag-btn" data-act="reload">取消</button><button type="button" class="airag-btn airag-btn-primary" data-act="save">保存</button></div></section>';
		this.$root.html(html);
		this.bindUi();
		this.gotoPage(this.page, true);
		this.watchProgress();
		if(this._setOpen) this.openSettings(this.setTab);
	},
	hashWin:function(){
		try{ return window.top && window.top.location ? window.top : window; }catch(e){ return window; }
	},
	restoreRoute:function(){
		var page='', set='';
		try{
			var h=this.hashWin().location.hash||location.hash||'';
			var m=h.match(/(?:[?&#])airag=([a-zA-Z]+)/);
			if(m) page=m[1];
			var s=h.match(/(?:[?&#])set=([a-zA-Z]+)/);
			if(s) set=s[1];
		}catch(e){}
		if(!page){
			try{
				var o=JSON.parse(sessionStorage.getItem('airag.console.route')||'{}');
				if(o.page) page=o.page;
				if(o.set) set=o.set;
			}catch(e){}
		}
		if({library:1,probe:1,models:1}[page]) this.page=page;
		if(set && {basic:1,vector:1,chunk:1,search:1,vision:1,advanced:1}[set]){
			this._setOpen=true; this.setTab=set;
		}
	},
	writeRoute:function(){
		var page=this.page||'library';
		var set=this._setOpen? (this.setTab||'basic') : '';
		try{ sessionStorage.setItem('airag.console.route', JSON.stringify({page:page,set:set})); }catch(e){}
		try{
			var loc=this.hashWin().location;
			var raw=String(loc.hash||'#admin/setting/aiRag');
			var parts=raw.replace(/^#/,'').split('&').filter(function(p){
				return p && !/^airag=/.test(p) && !/^set=/.test(p);
			});
			if(!parts.length || parts[0].indexOf('aiRag')<0) parts=['admin/setting/aiRag'];
			if(page && page!=='library') parts.push('airag='+page);
			if(set) parts.push('set='+set);
			var next='#'+parts.join('&');
			if(loc.hash===next) return;
			var win=this.hashWin();
			if(win.history && win.history.replaceState){
				win.history.replaceState(null,'', loc.pathname+(loc.search||'')+next);
			}else{
				loc.hash=next;
			}
		}catch(e){}
	},
	bindRoute:function(){
		var self=this, onHash=function(){
			var h='';
			try{ h=self.hashWin().location.hash||''; }catch(e){ h=location.hash||''; }
			if(h.indexOf('aiRag')<0) return;
			var before=self.page+'|'+(self._setOpen?self.setTab:'');
			self.restoreRoute();
			var after=self.page+'|'+(self._setOpen?self.setTab:'');
			if(before===after) return;
			self.gotoPage(self.page, true);
			if(self._setOpen) self.openSettings(self.setTab);
			else { $('#airag-set-mask').remove(); self.$root.find('[data-act=settings]').removeClass('is-on'); }
		};
		$(window).off('hashchange.airagRoute').on('hashchange.airagRoute', onHash);
		try{ $(window.top).off('hashchange.airagRoute').on('hashchange.airagRoute', onHash); }catch(e){}
	},
	gotoPage:function(page, keep){
		if(!keep) this.collect();
		this.page=page||'library';
		if(!keep){ this._setOpen=false; $('#airag-set-mask').remove(); }
		this.$root.find('[data-page]').removeClass('is-on');
		this.$root.find('[data-page="'+this.page+'"]').addClass('is-on');
		this.$root.find('[data-act=settings]').toggleClass('is-on', !!this._setOpen);
		this.$root.find('.airag-pane').removeClass('is-on');
		this.$root.find('[data-pane="'+this.page+'"]').addClass('is-on');
		this.$root.find('.airag-foot').toggleClass('is-hide', this.page!=='models');
		if(this.page==='library') this.loadLibrary();
		this.writeRoute();
	},
	sw:function(key,label,desc){
		return '<div class="airag-row"><div class="txt"><b>'+label+'</b><p>'+desc+'</p></div><button type="button" class="airag-switch'+(this.on(key)?' is-on':'')+'" data-key="'+key+'"></button></div>';
	},
	fieldDetect:function(label,key,ph,op){
		return '<div class="airag-field"><label>'+label+'</label><div class="airag-input-wrap">'+
			'<input class="airag-input" data-cfg="'+key+'" value="'+this.h(this.cfg[key]||'')+'" placeholder="'+this.h(ph||'')+'">'+
			'<button type="button" class="airag-detect" data-act="test" data-op="'+op+'">检测</button><span class="airag-test-msg"></span></div></div>';
	},
	pageLibrary:function(){
		var f=this.libFilter||{};
		var self=this;
		var exts=['doc','docx','xls','xlsx','ppt','pptx','pdf','ofd','txt','md','html','htm'];
		var timeOpts=[['','不限时间'],['1d','近1天'],['7d','最近7天'],['30d','最近30天'],['365d','最近一年']];
		var stOpts=[['','全部状态'],['wait','等待正文'],['es','待向量化'],['ok','已完成'],['fail','失败'],['skip','忽略']];
		var sizeOpts=[['','不限大小'],['100k','0~100 KB'],['1m','100 KB~1 MB'],['10m','1 MB~10 MB'],['100m','10 MB~100 MB'],['1g','100 MB~1 GB'],['over1g','1 GB 以上']];
		var timeLabel=(_.find(timeOpts,function(x){return x[0]===f.time;})||timeOpts[0])[1];
		var stLabel=(_.find(stOpts,function(x){return x[0]===self.libStatus;})||stOpts[0])[1];
		var sizeLabel=(_.find(sizeOpts,function(x){return x[0]===f.size;})||sizeOpts[0])[1];
		var extSel=(f.ext||'').split(',').filter(Boolean);
		return '<div class="airag-pane is-wide'+(this.page==='library'?' is-on':'')+'" data-pane="library">'+
			'<div class="airag-hero">'+
			'<span class="airag-on">已开启</span>'+
			'<div class="airag-statbox"><b>文件入库</b><div><em data-stat="total">0 文件</em> · <em data-stat="scan">可索引 0 / 0</em></div></div>'+
			'<div class="airag-statbox"><b>向量化</b><div><em data-stat="done">已完成 0</em> · <em data-stat="pending">待向量 0</em> · <em data-stat="failed">失败 0</em></div></div>'+
			'<span class="spacer"></span>'+
			'<button type="button" class="airag-btn" data-act="retry-all">重试失败</button>'+
			'<button type="button" class="airag-btn airag-btn-primary" data-act="job" data-op="run">'+this.ico('play')+'手动更新</button></div>'+
			'<div class="airag-filters">'+
			'<div class="airag-dd" data-dd="time"><button type="button" class="airag-dd-btn">'+(f.time?timeLabel:'修改时间')+'</button><div class="airag-dd-menu">'+_.map(timeOpts,function(it){return '<a href="javascript:void(0)" data-time="'+it[0]+'">'+it[1]+'</a>';}).join('')+'</div></div>'+
			'<div class="airag-dd" data-dd="status"><button type="button" class="airag-dd-btn">'+(this.libStatus?stLabel:'状态')+'</button><div class="airag-dd-menu">'+_.map(stOpts,function(it){return '<a href="javascript:void(0)" data-status="'+it[0]+'">'+it[1]+'</a>';}).join('')+'</div></div>'+
			'<div class="airag-dd" data-dd="ext"><button type="button" class="airag-dd-btn">'+(extSel.length?extSel.join(','):'文件类型')+(extSel.length?' <i data-act="clear-ext">×</i>':'')+'</button><div class="airag-dd-menu is-grid">'+_.map(exts,function(e){return '<a href="javascript:void(0)" data-ext="'+e+'" class="'+(extSel.indexOf(e)>=0?'is-on':'')+'">'+e+'</a>';}).join('')+'</div></div>'+
			'<div class="airag-dd" data-dd="size"><button type="button" class="airag-dd-btn">'+(f.size?sizeLabel:'不限大小')+'</button><div class="airag-dd-menu">'+_.map(sizeOpts,function(it){return '<a href="javascript:void(0)" data-size="'+it[0]+'">'+it[1]+'</a>';}).join('')+'</div></div>'+
			'<button type="button" class="airag-dd-btn" data-act="lib-folder">'+(f.pathName?('目录: '+this.h(f.pathName)+' ×'):'限定目录')+'</button>'+
			'<span class="spacer"></span><input class="airag-input" style="width:220px;height:34px" data-act="lib-words" placeholder="输入关键词搜索" value="'+this.h(this.libWords||'')+'"></div>'+
			'<div class="airag-lib-tip">筛选按修改时间、状态、类型、大小、目录组合。已向量化的文件在网盘列表显示 AI 标签，可右键提问。</div>'+
			'<table class="airag-table"><thead><tr><th>ID</th><th>名称</th><th>文件大小</th><th>分片情况</th><th>创建时间</th><th>修改时间</th><th>操作</th></tr></thead><tbody id="airag-lib-body"><tr><td colspan="7">加载中…</td></tr></tbody></table>'+
			'<div class="airag-pager"><button type="button" class="airag-btn" data-act="lib-prev">上一页</button><span class="airag-page-info" data-stat="pageinfo"></span><button type="button" class="airag-btn" data-act="lib-next">下一页</button></div></div>';
	},
	pageProbe:function(){
		return '<div class="airag-pane'+(this.page==='probe'?' is-on':'')+'" data-pane="probe"><div class="airag-search-hero">'+
			'<div class="illus">🔎</div><div class="airag-search-box"><input id="airag-probe-q" placeholder="输入关键词搜索">'+
			'<button type="button" class="airag-btn airag-btn-primary" data-act="probe-go">'+this.ico('search')+'搜索</button></div>'+
			'<div class="airag-search-meta"><select id="airag-probe-mode" class="airag-input" style="width:140px;height:34px"><option value="hybrid">混合检索</option><option value="keyword">全文搜索</option><option value="vector">向量搜索</option></select></div>'+
			'<div class="airag-hits" id="airag-probe-hits"></div></div></div>';
	},
	pageBasic:function(){
		return '<div class="airag-pane'+(this.page==='basic'?' is-on':'')+'" data-pane="basic"><h1>基础设置</h1>'+
			this.sw('serviceEnabled','RAG 启用状态','关闭后，AI 问答无法把网盘文件当资料库，全文检索增强也不再生效。')+
			this.sw('llmEnabled','启用对话','开启后可在文件、目录右键「AI 提问」。')+
			this.sw('serialPhase','扫描与向量分轮运行','本轮只读取共享正文并登记任务，下一轮切片并写入 Milvus；全文提取由 elasticFulltext 独立维护。')+
			'<div class="airag-row"><div class="txt"><b>任务互斥已启用</b><p>aiRag 与 elasticFulltext 共用索引锁；上一轮未结束时跳过，不堆积任务。docSearch 保持独立，启用时建议错峰运行。</p></div></div>'+
			this.sw('backpressure','索引背压保护','脏页、空闲页等待增量或 checkpoint 压力过高时暂停，低于恢复阈值后继续。')+
			this.pressureFields()+
			'<div class="airag-note"><b>背压指标说明</b><br>脏页：MariaDB 内存中已修改、尚未刷盘的数据页；达到暂停阈值即停，降到恢复阈值后继续。<br>checkpoint：redo 日志距安全上限的占比；达到 60% 且缓存池仍有压力时暂停，达到 85% 无条件暂停。数据库空闲、脏页已回落且空闲页充足时允许继续，避免 checkpoint 长时间不推进造成永久暂停。<br>空闲页等待：buffer_pool_wait_free 有新增，说明数据库曾等空闲缓存页；暂停并冷却 30 秒。<br>主机保护：MariaDB 内存达到 12.5 GiB，或 NVMe 利用率达到 80% 且持续 30 秒时暂停。指标无法读取时也暂停，避免盲目继续写入。</div>'+
			'<div class="airag-note">第一阶段只扫描文件并检查 elasticFulltext 正文版本，不提取、不复制正文。正文就绪后，第二阶段按分片 hash 增量切片 → Embedding → 写入 Milvus：未变化的分片复用原向量。等待正文时请检查 elasticFulltext 的任务状态和格式、大小限制。</div></div>';
	},
	pressureFields:function(){
		var self=this;
		return [{key:'pauseDirtyPercent',label:'脏页暂停阈值 (%)',value:40,min:10,max:90},
			{key:'resumeDirtyPercent',label:'脏页恢复阈值 (%)',value:28,min:5,max:85},
			{key:'milvusBatch',label:'Milvus 每批切片',value:300,min:50,max:500},
			{key:'milvusPauseMs',label:'写入间隔 (ms)',value:700,min:0,max:5000}].map(function(f){
			var value=self.cfg[f.key]==null?f.value:Number(self.cfg[f.key]);
			return '<div class="airag-row"><label for="airag-cfg-'+f.key+'">'+f.label+'</label><input id="airag-cfg-'+f.key+'" class="airag-input" style="width:130px" type="number" min="'+f.min+'" max="'+f.max+'" data-cfg="'+f.key+'" value="'+value+'"></div>';
		}).join('');
	},
	pageModels:function(){
		var self=this, types={chat:{label:'对话',cls:'is-chat'},embed:{label:'嵌入',cls:'is-embed'},rerank:{label:'重排序',cls:'is-rerank'},image:{label:'图片',cls:'is-image'},asr:{label:'语音',cls:'is-asr'}};
		var cards=_.map(this.services,function(svc,si){
			var rows=_.map(svc.models||[],function(m,mi){
				var vendor=self.vendorOf(m.id);
				var badges=_.map(self.modelTypes(m),function(t){ var meta=types[t]||types.chat; return '<span class="airag-badge '+meta.cls+'">'+meta.label+'</span>'; }).join('');
				var pass=!!(self._passFlash&&self._passFlash[si+':'+mi]);
				return '<div class="airag-mrow'+(m.enabled?'':' is-off')+'" data-si="'+si+'" data-mi="'+mi+'"><div class="mid">'+
					(vendor?'<span class="vendor">'+self.h(vendor)+'</span>':'')+
					'<span class="mname" title="'+self.h(m.id)+'">'+self.h(m.name||self.shortName(m.id))+'</span>'+
					badges+
					(m.context?'<span class="airag-ctx">'+Math.round(m.context/1000)+'k</span>':'')+
					'</div>'+
					'<div class="airag-macts">'+
					(pass?'<span class="airag-test-msg is-ok">● 通过</span>'
						:(m.tested==='fail'?'<span class="airag-test-msg is-fail is-mini" title="'+self.h(m.testMsg||'检测失败')+'">!</span>'
						:'<span class="airag-test-msg"></span>'))+
					'<button type="button" class="airag-ico" data-act="edit-model" title="编辑">'+self.ico('pen')+'</button>'+
					'<button type="button" class="airag-ico" data-act="del-model" title="删除">'+self.ico('trash')+'</button>'+
					'<button type="button" class="airag-detect" data-act="test-model">测试</button>'+
					'<button type="button" class="airag-switch'+(m.enabled?' is-on':'')+'" data-act="model-on"></button></div></div>';
			}).join('');
			return '<div class="airag-svc'+(svc.enabled?'':' is-off')+'" data-si="'+si+'"><div class="airag-svc-head"><span class="airag-svc-logo">'+self.h(String(svc.name||'服').charAt(0))+'</span><span class="airag-svc-name">'+self.h(svc.name||'未命名')+'</span><span class="spacer"></span>'+
				'<div class="airag-head-acts">'+
				'<button type="button" class="airag-ico" data-act="rename-svc" title="编辑服务">'+self.ico('pen')+'</button>'+
				'<button type="button" class="airag-ico" data-act="del-svc" title="删除服务">'+self.ico('trash')+'</button>'+
				'<button type="button" class="airag-switch'+(svc.enabled?' is-on':'')+'" data-act="svc-on" title="启用"></button></div></div><div class="airag-svc-body">'+
				'<div class="airag-field"><label>API 地址</label><input class="airag-input" data-act="svc-url" value="'+self.h(svc.url||'')+'"></div>'+
				'<div class="airag-field"><label>API 密钥 <a href="https://cloud.siliconflow.cn/account/ak" target="_blank" rel="noreferrer">获取密钥</a></label><input class="airag-input" type="password" data-act="svc-key" value="'+self.h(svc.apiKey||'')+'"></div>'+
				'<div class="airag-models-head"><b>模型列表</b><div class="airag-fetch-group"><button type="button" data-act="fetch">获取模型列表</button><button type="button" data-act="add-model">＋</button></div></div>'+rows+'</div></div>';
		}).join('');
		return '<div class="airag-pane is-wide'+(this.page==='models'?' is-on':'')+'" data-pane="models"><h1>模型服务<button type="button" class="airag-btn" data-act="add-svc" style="margin-left:auto">+ 添加服务</button></h1><div class="airag-grid airag-svc-grid">'+cards+'</div></div>';
	},
	pageVector:function(){
		return '<div class="airag-pane'+(this.page==='vector'?' is-on':'')+'" data-pane="vector"><h1>向量数据库</h1>'+
			'<div class="airag-box">共享正文由 elasticFulltext 管理：'+this.h(this.cfg.elasticUrl||'')+'</div>'+
			'<div class="airag-field"><label>共享 ES 索引（在 elasticFulltext 中配置）</label><input class="airag-input" readonly value="'+this.h(this.cfg.indexName||'kodbox-fulltext')+'"></div>'+
			this.fieldDetect('Milvus URL','milvusUrl','http://milvus:19530','testMilvus')+
			'<div class="airag-field"><label>访问 Token</label><input class="airag-input" type="password" data-cfg="milvusToken" value="'+this.h(this.cfg.milvusToken||'')+'"></div>'+
			'<div class="airag-box">Collection：'+this.h(this.cfg.milvusCollection||'kodbox_airag_chunk')+'</div></div>';
	},
	pageChunk:function(){
		return '<div class="airag-pane'+(this.page==='chunk'?' is-on':'')+'" data-pane="chunk"><h1>向量化模型及文本切片</h1>'+
			'<div class="airag-field"><label>向量化模型</label><div class="airag-input-wrap"><select data-cfg="embedModel">'+this.optionHtml('embed',this.cfg.embedModel)+'</select><button type="button" class="airag-detect" data-act="test" data-op="testEmbed">检测</button><span class="airag-test-msg"></span></div></div>'+
			'<div class="airag-field"><label>向量维度</label><input class="airag-input" data-cfg="embedDim" value="'+this.h(this.cfg.embedDim||1024)+'"></div>'+
			'<div class="airag-field"><label>Reranker</label><select data-cfg="rerankModel">'+this.optionHtml('rerank',this.cfg.rerankModel)+'</select></div>'+
			'<h3>文本切片</h3>'+
			'<div class="airag-row"><div class="txt"><b>切片长度</b><p>企业网盘建议 600–1000</p></div><div class="airag-slider"><input type="range" min="200" max="2000" step="20" data-cfg="chunkSize" value="'+(this.cfg.chunkSize||800)+'"><span class="num">'+(this.cfg.chunkSize||800)+'</span></div></div>'+
			'<div class="airag-row"><div class="txt"><b>切片重叠</b></div><div class="airag-slider"><input type="range" min="0" max="400" step="10" data-cfg="chunkOverlap" value="'+(this.cfg.chunkOverlap||120)+'"><span class="num">'+(this.cfg.chunkOverlap||120)+'</span></div></div>'+
			this.sw('prependName','分片追加文件名','把文件名写进每个切片，便于召回。')+'</div>';
	},
	pageSearch:function(){
		return '<div class="airag-pane'+(this.page==='search'?' is-on':'')+'" data-pane="search"><h1>检索设置</h1>'+
			this.sw('keywordEnabled','全文搜索增强','通过 Elasticsearch 对文本做关键词检索。')+
			this.sw('semanticEnabled','全文搜索-语义检索','通过 Milvus 对搜索内容做向量混合检索。')+
			this.sw('hybridEnabled','接管文件内容搜索','用混合检索替换 MariaDB MATCH AGAINST。')+
			'<h3>AI 问答设置</h3>'+
			'<div class="airag-row"><div class="txt"><b>检索召回数量</b></div><div class="airag-slider"><input type="range" min="20" max="200" step="5" data-cfg="searchLimit" value="'+(this.cfg.searchLimit||80)+'"><span class="num">'+(this.cfg.searchLimit||80)+'</span></div></div>'+
			'<div class="airag-row"><div class="txt"><b>AI 问答召回数量</b></div><div class="airag-slider"><input type="range" min="4" max="80" step="1" data-cfg="askLimit" value="'+(this.cfg.askLimit||20)+'"><span class="num">'+(this.cfg.askLimit||20)+'</span></div></div>'+
			this.sw('fillChunks','AI 召回分片补全优化','针对召回分片补充前后内容。')+
			'<div class="airag-row"><div class="txt"><b>补全分片数量前 topN</b></div><div class="airag-slider"><input type="range" min="0" max="20" step="1" data-cfg="fillTopN" value="'+(this.cfg.fillTopN||5)+'"><span class="num">'+(this.cfg.fillTopN||5)+'</span></div></div></div>';
	},
	pageVision:function(){
		var opts=this.optionHtml('image',this.cfg.ocrModel);
		if(!this.modelsOf('image').length) opts=this.optionHtml('chat',this.cfg.ocrModel);
		return '<div class="airag-pane'+(this.page==='vision'?' is-on':'')+'" data-pane="vision"><h1>图片识别</h1>'+
			this.sw('imageOcrEnabled','启用图片识别 / PDF OCR','开启后对图片和 PDF 扫描件做识别。')+
			'<div class="airag-field"><label>识别模型</label><select data-cfg="ocrModel">'+opts+'</select>'+
			'<div class="airag-empty-opt">从「模型服务」里选择图片模型；没有图片模型时可选用对话模型。</div></div></div>';
	},
	pageAdvanced:function(){
		return '<div class="airag-pane'+(this.page==='advanced'?' is-on':'')+'" data-pane="advanced"><h1>高级设置</h1>'+
			'<div class="airag-actions"><button type="button" class="airag-btn airag-btn-primary" data-act="job" data-op="run">手动更新</button>'+
			'<button type="button" class="airag-btn airag-btn-warn" data-act="job" data-op="rebuild">重新生成索引</button>'+
			'<button type="button" class="airag-btn airag-btn-danger" data-act="job" data-op="reset">重置资源库</button></div>'+
			'<div class="airag-progress-bar adv-progress"><div class="head"><span class="phase">总进度</span><span class="cur"></span></div><div class="bar"><i></i></div><div class="airag-progress-name"></div></div>'+
			'<div class="airag-row"><div class="txt"><b>每批文件数</b><p>每一轮扫描/向量化处理多少个文档，建议 20–40。</p></div><div class="airag-slider"><input type="range" min="8" max="80" step="1" data-cfg="batchSize" value="'+(this.cfg.batchSize||40)+'"><span class="num">'+(this.cfg.batchSize||40)+'</span></div></div>'+
			'<div class="airag-row"><div class="txt"><b>最大文件</b><p>超过此大小的文档会跳过。</p></div><div class="airag-slider"><input type="range" min="5" max="200" step="5" data-cfg="maxFileSizeMB" value="'+(this.cfg.maxFileSizeMB||50)+'"><span class="num">'+(this.cfg.maxFileSizeMB||50)+' MB</span></div></div>'+
			'<div class="airag-status">正在读取运行情况…</div>'+this.sw('debugMode','DEBUG','写入更详细的运行日志。')+'</div>';
	},
	sizeText:function(n){
		n=parseInt(n,10)||0;
		if(n<1024) return n+' B';
		if(n<1048576) return (n/1024).toFixed(1)+' KB';
		return (n/1048576).toFixed(1)+' MB';
	},
	timeText:function(t){
		if(!t) return '-';
		var d=new Date(t*1000);
		var p=function(n){return n<10?'0'+n:''+n;};
		return d.getFullYear()+'/'+p(d.getMonth()+1)+'/'+p(d.getDate())+' '+p(d.getHours())+':'+p(d.getMinutes());
	},
	libRowHtml:function(item){
		var self=this;
		var ready=!!item.ready, disabled=item.status===3||item.statusCls==='is-skip';
		var st=ready?'<span class="airag-st is-ok">已完成</span>':(disabled?'<span class="airag-st is-skip">'+self.h(item.statusText||'已禁用')+'</span>':'<span class="airag-st is-wait">'+self.h(item.statusText||'进行中')+'</span>');
		var hash=item.hash?'<div class="airag-hash" title="'+self.h(item.hash)+'"><em>MD5</em> '+self.h(item.hash)+'</div>':'';
		var chunk='<span class="'+(ready?'airag-chunk-ok':'')+'">'+(item.chunkDone||0)+' / '+(item.chunkCount||0)+'</span> '+st;
		return '<tr data-file="'+item.fileID+'" data-path="'+self.h(item.path||'')+'" data-name="'+self.h(item.name||'')+'" data-sig="'+item.fileID+'-'+item.status+'-'+(item.chunkDone||0)+'-'+(item.chunkCount||0)+'">'+
			'<td class="airag-id">'+item.fileID+'</td>'+
			'<td><div class="airag-name" title="'+self.h(item.name)+'">'+self.h(item.name)+'</div>'+hash+'</td>'+
			'<td>'+self.sizeText(item.size)+'</td>'+
			'<td>'+chunk+'</td>'+
			'<td class="airag-time">'+self.timeText(item.createTime)+'</td>'+
			'<td class="airag-time">'+self.timeText(item.modifyTime)+'</td>'+
			'<td class="airag-ops"><div class="airag-op"><button type="button" class="airag-btn airag-btn-sm" data-act="op-menu">操作</button>'+
			'<div class="airag-op-menu">'+
			'<a href="javascript:void(0)" data-act="view-file">查看文件</a>'+
			'<a href="javascript:void(0)" data-act="probe-file">检索测试</a>'+
			(disabled?'<a href="javascript:void(0)" data-act="retry-file">启用并重新生成</a>':'<a href="javascript:void(0)" data-act="disable-file">禁用</a>')+
			'<a href="javascript:void(0)" data-act="retry-file">重新生成</a>'+
			'<a href="javascript:void(0)" data-act="drop-file">删除文件</a>'+
			'</div></div></td></tr>';
	},
	loadLibrary:function(opt){
		var self=this, f=this.libFilter||{}, silent=!!(opt&&opt.silent);
		var $body=this.$root.find('#airag-lib-body');
		if(!silent && !$body.children('[data-file]').length) $body.html('<tr><td colspan="7">加载中…</td></tr>');
		this.post('manage',{
			operation:'listLibrary',page:this.libPage,
			filterWords:this.libWords,filterStatus:this.libStatus||'',
			filterTime:f.time||'',filterExt:f.ext||'',filterSize:f.size||'',filterSource:f.sourceID||0
		}).done(function(res){
			var data=res&&res.data||{};
			var list=data.list||[], stats=data.stats||{};
			self.$root.find('[data-stat=total]').text((stats.total||0)+' 文件');
			self.$root.find('[data-stat=scan]').text('可索引 '+(stats.scanDone||0)+' / '+(stats.targetTotal!=null?stats.targetTotal:(stats.diskTotal||0)));
			self.$root.find('[data-stat=done]').text('已完成 '+(stats.done||0));
			self.$root.find('[data-stat=pending]').text('待向量 '+(stats.pending||0));
			self.$root.find('[data-stat=failed]').text('失败 '+(stats.failed||0));
			self.$root.find('[data-stat=pageinfo]').text((stats.filtered!=null?('筛选 '+stats.filtered+' · '):'')+'第 '+(data.page||1)+' 页');
			var sig=_.map(list,function(item){ return item.fileID+'-'+item.status+'-'+(item.chunkDone||0)+'-'+(item.chunkCount||0); }).join('|')+'#'+(data.page||1);
			if(silent && sig===self._libSig) return;
			self._libSig=sig;
			var body=_.map(list,function(item){ return self.libRowHtml(item); }).join('')||'<tr><td colspan="7">没有符合筛选的文件。</td></tr>';
			$body.html(body);
		}).fail(function(xhr){
			if(!silent) $body.html('<tr><td colspan="7">列表加载失败：'+self.h(xhr.statusText||'网络错误')+'</td></tr>');
		});
	},
	openFile:function(fileID){
		var self=this;
		this.post('manage',{operation:'fileDetail',fileID:fileID}).done(function(res){
			var item=(res.data&&res.data.item)||{};
			$('.airag-drawer,#airag-chunk-mask').remove();
			var list=item.chunks||[];
			var chunksHtml='<div class="airag-chunk-head"><span>分片情况</span><span class="airag-st '+(item.statusCls||'')+'">● '+self.h(item.statusText||'')+'</span> <b>'+list.length+' / '+(item.chunkCount||list.length)+'</b></div>'+
				(_.map(list,function(c,i){
					var text=c.text||'', idx=(c.index!=null?c.index:i)+1, preview=text.slice(0,160);
					return '<div class="airag-ccard" data-ci="'+i+'"><div class="hd"><span class="no">'+idx+'</span><span class="sz">'+self.sizeText(text.length)+'</span><span class="tm">'+self.timeText(item.indexTime)+'</span><span class="airag-st '+(item.statusCls||'is-ok')+'">● '+self.h(item.statusText||'已完成')+'</span></div>'+
						'<div class="nm">[name:'+self.h(item.name||'')+']</div>'+
						'<div class="tx">'+self.h(preview)+(text.length>160?'<span class="more">更多</span>':'')+'</div></div>';
				}).join('')||'<div class="airag-empty-opt">还没有分片。请先完成向量化。</div>');
			var $d=$('<div class="airag-drawer"><h3>'+self.h(item.name||'文件')+'</h3><div class="airag-tabs"><a href="javascript:void(0)" data-tab="info">属性</a><a href="javascript:void(0)" class="is-on" data-tab="chunks">分片情况</a></div><div class="body"></div></div>');
			var info='<div class="airag-kv"><div class="k">分片情况</div><div>'+((item.chunkDone||0)+' / '+(item.chunkCount||0))+' <span class="airag-st '+self.h(item.statusCls||'')+'">'+self.h(item.statusText||'')+'</span></div>'+
				'<div class="k">fileID</div><div>'+item.fileID+'</div>'+
				'<div class="k">文件大小</div><div>'+self.sizeText(item.size)+'</div>'+
				'<div class="k">提取文本</div><div>'+self.sizeText(item.textSize)+'</div>'+
				'<div class="k">索引时间</div><div>'+self.timeText(item.indexTime)+'</div>'+
				'<div class="k">MD5</div><div>'+self.h(item.hash||'-')+'</div>'+
				(item.error?'<div class="k">错误</div><div>'+self.h(item.error)+'</div>':'')+'</div>';
			$d.find('.body').html(chunksHtml);
			$d.on('click','[data-tab]',function(){
				$d.find('[data-tab]').removeClass('is-on'); $(this).addClass('is-on');
				$d.find('.body').html($(this).attr('data-tab')==='chunks'?chunksHtml:info);
			});
			$d.on('click','.airag-ccard .more',function(e){
				e.stopPropagation();
				var $card=$(this).closest('.airag-ccard').toggleClass('is-open');
				var i=Number($card.attr('data-ci')), c=list[i]||{}, text=c.text||'', open=$card.hasClass('is-open');
				$card.find('.tx').html(self.h(open?text:text.slice(0,160))+(text.length>160?'<span class="more">'+(open?'收起':'更多')+'</span>':''));
			});
			$d.on('click','.airag-ccard',function(e){
				if($(e.target).closest('.more').length) return;
				var i=Number($(this).attr('data-ci')), c=list[i]||{};
				self.openChunkDetail(item, c, i);
			});
			$('body').append($d);
			$(document).off('mousedown.airagDraw').on('mousedown.airagDraw',function(e){
				if(!$(e.target).closest('.airag-drawer,#airag-chunk-mask').length){ $d.remove(); $('#airag-chunk-mask').remove(); $(document).off('mousedown.airagDraw'); }
			});
		});
	},
	openChunkDetail:function(item, chunk, i){
		$('#airag-chunk-mask').remove();
		var idx=((chunk&&chunk.index)!=null?chunk.index:i)+1;
		var text=(chunk&&chunk.text)||'';
		var $m=$('<div class="airag-set-mask" id="airag-chunk-mask"><div class="airag-chunk-dlg"><div class="airag-set-head"><b>分片 #'+idx+'</b><button type="button" class="airag-ico" data-close="1">×</button></div><div class="meta">'+this.h(item.name||'')+' · '+this.sizeText(text.length)+' · '+this.timeText(item.indexTime)+'</div><pre>'+this.h(text)+'</pre></div></div>');
		$m.on('click',function(e){ if(e.target===this||$(e.target).closest('[data-close]').length) $m.remove(); });
		$('body').append($m);
	},
	openDiskFile:function(path, name){
		if(!path){ this.notify('没有网盘路径，请到文件列表打开',false); return; }
		var hosts=[window, window.parent, window.top];
		for(var i=0;i<hosts.length;i++){
			var w=hosts[i];
			try{
				if(w&&w.kodApi&&typeof w.kodApi.fileView==='function'){ w.kodApi.fileView(path,{title:name||'文件预览'}); return; }
				if(w&&w.kodApp&&typeof w.kodApp.open==='function'){ w.kodApp.open(path); return; }
				if(_.get(w,'kodApp.pathAction.fileOpen')){ w.kodApp.pathAction.fileOpen({path:path,name:name||''}); return; }
			}catch(e){}
		}
		try{ (window.top||window).location.hash='#explorer&path='+encodeURIComponent(path); }catch(e){ this.notify('无法打开 '+path,false); }
	},
	// 按相邻两次进度轮询的切片增量估算速度，跨文件累计，空闲时清零
	chunkRate:function(p){
		var now=Date.now(), done=parseInt(p&&p.chunkDone,10)||0, total=parseInt(p&&p.chunkTotal,10)||0;
		var last=this._rate;
		if(!p||!p.running){ this._rate=null; return 0; }
		if(!last||done<last.done||total!==last.total){
			this._rate={t:now,done:done,total:total,ema:(last&&last.ema)||0};
			return this._rate.ema?Number(this._rate.ema.toFixed(1)):0;
		}
		var dt=(now-last.t)/1000;
		if(dt<0.8) return last.ema?Number(last.ema.toFixed(1)):0;
		var v=(done-last.done)/dt;
		var ema=last.ema?last.ema*0.6+v*0.4:v;
		this._rate={t:now,done:done,total:total,ema:ema};
		return ema>0?Number(ema.toFixed(1)):0;
	},
	etaText:function(sec){
		sec=Number(sec);
		if(!sec || !isFinite(sec) || sec<=0) return '';
		if(sec<90) return '剩余约 '+Math.max(1,Math.round(sec))+' 秒';
		if(sec<3600) return '剩余约 '+Math.round(sec/60)+' 分钟';
		var h=Math.floor(sec/3600), m=Math.round((sec%3600)/60);
		return '剩余约 '+h+' 小时'+(m?m+' 分':'');
	},
	progressLine:function(p, running, rate){
		if(!running) return p.wait||p.current||'等待任务';
		var bits=[];
		if(p.current) bits.push(p.current);
		var curDone=parseInt(p.chunkDone,10)||0, curTotal=parseInt(p.chunkTotal,10)||0;
		if(p.phase==='vector'&&curTotal) bits.push('当前文件 '+curDone+'/'+curTotal+' 片');
		var allDone=parseInt(p.chunkAllDone,10)||0, allEst=parseInt(p.chunkAllEst,10)||0;
		if(rate) bits.push(rate+' 片/秒');
		var remain=parseInt(p.chunkRemain,10);
		if(!(remain>=0)) remain=Math.max(0, curTotal-curDone);
		var eta=0;
		if(rate>0 && remain>0) eta=remain/rate;
		else if(p.started && running){
			var elapsed=Date.now()/1000-(parseInt(p.started,10)||0);
			if(p.phase==='vector' && allEst>allDone && elapsed>8 && allDone>0) eta=elapsed*(allEst-allDone)/allDone;
			else if(p.phase!=='vector'){
				var scanRemain=parseInt(p.scanRemain,10)||0, scanDone=parseInt(p.scanDone,10)||0;
				if(scanRemain>0 && scanDone>0 && elapsed>8) eta=elapsed*scanRemain/scanDone;
			}
		}
		var etaStr=this.etaText(eta);
		if(etaStr) bits.push(etaStr);
		return bits.join(' · ');
	},
	watchProgress:function(){
		var self=this, ticks=0;
		if(this.poll) clearTimeout(this.poll);
		var paint=function(p){
			p=p||{};
			var $bar=self.$root.find('#airag-progress');
			$bar.show();
			var running=!!p.running;
			var scanPct=parseInt(p.scanPct,10); if(isNaN(scanPct)) scanPct=0;
			var vecPct=parseInt(p.vecPct,10); if(isNaN(vecPct)) vecPct=0;
			var overall=parseInt(p.overall,10); if(isNaN(overall)) overall=Math.round((scanPct+vecPct)/2);
			scanPct=Math.max(0, Math.min(100, scanPct));
			vecPct=Math.max(0, Math.min(100, vecPct));
			overall=Math.max(0, Math.min(100, overall));
			var rate=self.chunkRate(p);
			var wait=self.progressLine(p, running, rate);
			var scanTotal=parseInt(p.targetTotal,10); if(isNaN(scanTotal)||scanTotal<0) scanTotal=parseInt(p.diskTotal,10)||0;
			$bar.toggleClass('is-run', running);
			var phase=running?(p.phase==='vector'?'正在切片、Embedding 并写入 Milvus':'正在扫描并检查共享正文'):(overall>=100?'索引已完成':'等待下一轮');
			$bar.find('.phase').text(phase);
			$bar.find('.overall').text('总体 '+overall+'%');
			$bar.find('.scan-text').text((p.scanDone||0)+' / '+scanTotal+' · '+scanPct+'%');
			$bar.find('.vec-text').text((p.vecDone||0)+' / '+((parseInt(p.vecDone,10)||0)+(parseInt(p.vecPend,10)||0)+(parseInt(p.waiting,10)||0)+(parseInt(p.failed,10)||0))+' · '+vecPct+'%'+(p.waiting?(' · '+p.waiting+' 等待正文'):'')+(p.failed?(' · '+p.failed+' 失败'):'') );
			$bar.find('.bar.scan i').css({width:Math.max(running?2:0,scanPct)+'%',animation:'none'});
			$bar.find('.bar.vec i').css({width:Math.max(running?2:0,vecPct)+'%',animation:'none'});
			$bar.find('.airag-progress-name').text(wait);
			self.$root.find('#airag-nav-prog').text((running?'进行中 ':'总进度 ')+overall+'%');
			self.$root.find('[data-stat=total]').text((p.libTotal||0)+' 文件');
			self.$root.find('[data-stat=scan]').text('可索引 '+(p.scanDone||0)+' / '+scanTotal);
			self.$root.find('[data-stat=done]').text('已完成 '+(p.vecDone||0));
			self.$root.find('[data-stat=pending]').text('待向量 '+(p.vecPend||0));
			self.$root.find('[data-stat=failed]').text('失败 '+(p.failed||0));
			self.$root.find('[data-act=job][data-op=run]').toggleClass('is-pulse', !running && ((p.scanRemain||0)>0 || (p.vecPend||0)>0));
			var $adv=$('#airag-set-mask .adv-progress');
			if($adv.length){
				$adv.show();
				$adv.find('.phase').text((running?'进行中':'总进度')+' '+overall+'%');
				$adv.find('.cur').text('可索引 '+(p.scanDone||0)+'/'+scanTotal+' · 向量 '+(p.vecDone||0)+'/'+((parseInt(p.vecDone,10)||0)+(parseInt(p.vecPend,10)||0)+(parseInt(p.waiting,10)||0)+(parseInt(p.failed,10)||0)));
				$adv.find('.airag-progress-name').text(wait);
				$adv.find('.bar i').css({width:Math.max(0,overall)+'%',animation:'none'});
			}
		};
		var schedule=function(running){
			clearTimeout(self.poll);
			self.poll=setTimeout(tick, document.hidden?15000:(running?1500:6000));
		};
		var tick=function(){
			$.ajax({url:self.api+'status&fast=1',dataType:'json',cache:false}).done(function(res){
				var p=(res.data&&res.data.progress)||res.data||{};
				if(res && res.code===false && !p.scanPct && !p.diskTotal){
					paint({wait:(res.data&&res.data.message)||'无权读取进度', overall:0});
					return;
				}
				paint(p);
				ticks++;
				if(p.running && self.page==='library' && ticks%8===0) self.loadLibrary({silent:true});
				if(!p.running && self._wasRun){ self._wasRun=false; if(self.page==='library') self.loadLibrary({silent:true}); if(self.page==='advanced') self.refreshStatus(); }
				if(p.running) self._wasRun=true;
				if(!p.running && p.needRun && !self._kicked){
					self._kicked=true;
					self.post('manage',{operation:'run'}).done(function(){ self._wasRun=true; })
						.always(function(){ setTimeout(function(){ self._kicked=false; },30000); });
				}
				schedule(!!p.running);
			}).fail(function(xhr){
				paint({wait:'进度读取失败：'+(xhr.statusText||'网络错误')+'。请刷新后台。', overall:0});
				schedule(false);
			});
		};
		tick();
	},
	collect:function(){
		var self=this;
		var $scope=this.$root.add($('#airag-set-mask'));
		$scope.find('[data-cfg]').each(function(){ self.cfg[$(this).attr('data-cfg')]=$(this).val(); });
		$scope.find('.airag-switch[data-key]').each(function(){ self.cfg[$(this).attr('data-key')]=$(this).hasClass('is-on')?1:0; });
		this.$root.find('[data-act=svc-url]').each(function(){ var si=$(this).closest('.airag-svc').data('si'); if(self.services[si]) self.services[si].url=self.fixUrl($(this).val()); });
		this.$root.find('[data-act=svc-key]').each(function(){ var si=$(this).closest('.airag-svc').data('si'); if(self.services[si]) self.services[si].apiKey=$.trim($(this).val()||''); });
		this.cfg.modelServices=JSON.stringify(this.services);
	},
	save:function(){
		var self=this; this.collect();
		this.post('manage',{operation:'saveConfig',config:JSON.stringify(this.cfg)}).done(function(res){
			self.notify((res.data&&res.data.message)||'已保存',!!(res&&res.code));
			if(res&&res.code&&res.data&&res.data.config){ self.cfg=res.data.config; self.services=self.cfg.services||self.services; }
		}).fail(function(xhr){ self.notify(xhr.statusText||'保存失败',false); });
	},
	saveQuiet:function(){
		var self=this;
		this.collect();
		clearTimeout(this._saveQ);
		this._saveQ=setTimeout(function(){
			self.post('manage',{operation:'saveConfig',config:JSON.stringify(self.cfg)});
		}, 280);
	},
	refreshStatus:function(){
		var box=this.$root.find('.airag-status');
		if(!box.length) box=$('#airag-set-mask .airag-status');
		if(!box.length) return;
		$.ajax({url:this.api+'status',dataType:'json',cache:false}).done(function(res){ if(res&&res.data&&res.data.html) box.html(res.data.html); });
	},
	openModal:function(title,fields,onOk){
		$('#airag-mask').remove();
		var body=_.map(fields,function(f){
			if(f.type==='select') return '<div class="'+(f.span2?'span2':'')+'"><label>'+f.label+'</label><select name="'+f.name+'">'+f.options+'</select></div>';
			return '<div class="'+(f.span2?'span2':'')+'"><label>'+f.label+'</label><input name="'+f.name+'" value="'+_.escape(f.value||'')+'" placeholder="'+_.escape(f.placeholder||'')+'"></div>';
		}).join('');
		var $mask=$('<div class="airag-mask" id="airag-mask"><div class="airag-modal"><h4>'+title+'</h4><div class="airag-modal-grid">'+body+'</div><div class="airag-modal-actions"><button type="button" class="airag-btn" data-close="1">取消</button><button type="button" class="airag-btn airag-btn-primary" data-ok="1">确定</button></div></div></div>');
		$mask.on('click',function(e){ if(e.target===this||$(e.target).closest('[data-close]').length) $mask.remove(); });
		$mask.on('click','[data-ok]',function(e){
			e.preventDefault(); var data={}; $mask.find('input,select').each(function(){ data[this.name]=$.trim($(this).val()||''); });
			if(onOk(data)!==false) $mask.remove();
		});
		$('body').append($mask);
		setTimeout(function(){ $mask.find('input,select').first().focus(); },30);
	},
	bindUi:function(){
		var self=this;
		this.$root.off('.airag');
		$(document).off('click.airagDd').on('click.airagDd',function(){ self.$root.find('.airag-dd,.airag-op').removeClass('is-open'); });
		this.$root.on('click.airag','[data-page]',function(e){
			e.preventDefault();
			self.gotoPage($(this).attr('data-page'));
		});
		this.$root.on('click.airag','[data-act=settings]',function(){ self.openSettings(self.setTab||'basic'); });
		this.$root.on('click.airag','[data-act=popout]',function(){
			self.writeRoute();
			var url='./index.php#admin/setting/aiRag';
			try{
				var loc=self.hashWin().location;
				url=loc.pathname+(loc.search||'')+(loc.hash||'#admin/setting/aiRag');
			}catch(e){}
			window.open(url,'airag-console','noopener,width=1360,height=860');
		});
		this.$root.on('click.airag','.airag-switch',function(){
			$(this).toggleClass('is-on');
			var key=$(this).attr('data-key'); if(key) self.cfg[key]=$(this).hasClass('is-on')?1:0;
			if($(this).attr('data-act')==='svc-on'){ var $svc=$(this).closest('.airag-svc'), si=$svc.data('si'), on=$(this).hasClass('is-on'); if(self.services[si]) self.services[si].enabled=on?1:0; $svc.toggleClass('is-off',!on); self.saveQuiet(); }
			if($(this).attr('data-act')==='model-on'){ var $row=$(this).closest('.airag-mrow'); var svc=self.services[$row.data('si')], mOn=$(this).hasClass('is-on'); if(svc&&svc.models[$row.data('mi')]) svc.models[$row.data('mi')].enabled=mOn?1:0; $row.toggleClass('is-off',!mOn); self.saveQuiet(); }
		});
		this.$root.on('input.airag','input[type=range]',function(){ $(this).siblings('.num').text($(this).val()); });
		this.$root.on('click.airag','[data-act=save]',function(){ self.save(); });
		this.$root.on('click.airag','[data-act=reload]',function(){ self.load(); });
		this.$root.on('click.airag','#airag-lib-body tr',function(e){
			if($(e.target).closest('[data-act]').length) return;
			var id=$(this).attr('data-file'); if(id) self.openFile(id);
		});
		this.$root.on('click.airag','[data-act=detail-file]',function(e){
			e.stopPropagation();
			var id=$(this).closest('tr').attr('data-file'); if(id) self.openFile(id);
		});
		this.$root.on('click.airag','[data-act=op-menu]',function(e){
			e.stopPropagation();
			var $op=$(this).closest('.airag-op');
			self.$root.find('.airag-op').not($op).removeClass('is-open');
			$op.toggleClass('is-open');
		});
		this.$root.on('click.airag','[data-act=view-file]',function(e){
			e.stopPropagation();
			var $tr=$(this).closest('tr');
			self.openDiskFile($tr.attr('data-path'), $tr.attr('data-name'));
		});
		this.$root.on('click.airag','[data-act=probe-file]',function(e){
			e.stopPropagation();
			var name=$(this).closest('tr').attr('data-name')||'';
			self.gotoPage('probe');
			self.$root.find('#airag-probe-q').val(name);
			if(name) self.runProbe();
		});
		this.$root.on('click.airag','[data-act=disable-file]',function(e){
			e.stopPropagation();
			var id=$(this).closest('tr').attr('data-file');
			if(!id) return;
			self.confirm('禁用后该文件不再参与检索和提问，确认？',function(){
				self.post('manage',{operation:'disableLibrary',fileID:id}).done(function(res){
					self.notify((res.data&&res.data.message)||'已禁用',!!(res&&res.code));
					self.loadLibrary();
				});
			});
		});
		this.$root.on('click.airag','.airag-dd-btn',function(e){
			e.stopPropagation();
			var $dd=$(this).closest('.airag-dd');
			if(!$dd.length) return;
			self.$root.find('.airag-dd').not($dd).removeClass('is-open');
			$dd.toggleClass('is-open');
		});
		this.$root.on('click.airag','[data-time]',function(){
			self.libFilter.time=$(this).attr('data-time')||'';
			self.libPage=1; self.loadLibrary();
			$(this).closest('.airag-dd').removeClass('is-open').find('.airag-dd-btn').text($(this).text());
		});
		this.$root.on('click.airag','[data-size]',function(){
			self.libFilter.size=$(this).attr('data-size')||'';
			self.libPage=1; self.loadLibrary();
			$(this).closest('.airag-dd').removeClass('is-open').find('.airag-dd-btn').text($(this).text());
		});
		this.$root.on('click.airag','[data-ext]',function(e){
			e.stopPropagation();
			var ext=$(this).attr('data-ext');
			var cur=_.compact(String(self.libFilter.ext||'').split(','));
			if(_.includes(cur,ext)) cur=_.without(cur,ext); else cur.push(ext);
			self.libFilter.ext=cur.join(',');
			self.libPage=1; self.loadLibrary();
			$(this).toggleClass('is-on');
			$(this).closest('.airag-dd').find('.airag-dd-btn').text(cur.length?cur.join(','):'文件类型');
		});
		this.$root.on('click.airag','[data-act=clear-ext]',function(e){
			e.stopPropagation();
			self.libFilter.ext=''; self.libPage=1; self.loadLibrary();
			$(this).closest('.airag-dd').removeClass('is-open').find('.airag-dd-btn').text('文件类型').end().find('[data-ext]').removeClass('is-on');
		});
		this.$root.on('click.airag','[data-act=lib-folder]',function(){
			if(self.libFilter.sourceID){ self.libFilter.sourceID=0; self.libFilter.pathName=''; self.libPage=1; $(this).text('限定目录'); self.loadLibrary(); return; }
			var api=window.kodApi||(window.parent&&parent.kodApi);
			var btn=$(this);
			if(api&&api.pathSelect){
				new api.pathSelect({type:'folder',title:'限定目录',single:true,callback:function(info){
					self.libFilter.sourceID=info.sourceID||info.id||0;
					self.libFilter.pathName=info.name||info.pathDisplay||'已选目录';
					self.libPage=1; btn.text('目录: '+self.libFilter.pathName+' ×'); self.loadLibrary();
				}});
				return;
			}
			self.notify('当前页无法打开目录选择',false);
		});
		this.$root.on('click.airag','[data-act=drop-file]',function(e){
			e.stopPropagation();
			var id=$(this).closest('tr').attr('data-file')||$(this).attr('data-file');
			self.confirm('从资源库移除该文件的索引？',function(){
				self.post('manage',{operation:'dropLibrary',fileID:id}).done(function(){
					self.notify('已从资源库移除',true);
					self.loadLibrary();
				});
			});
		});
		this.$root.on('click.airag','[data-act=retry-file]',function(e){
			e.stopPropagation();
			var id=$(this).closest('tr').attr('data-file')||$(this).attr('data-file');
			self.notify('正在重试…', null);
			self._wasRun=true;
			self.post('manage',{operation:'retryLibrary',fileID:id}).done(function(res){
				self.notify((res.data&&res.data.message)||'已提交重试',!!(res&&res.code));
				self.watchProgress();
			}).fail(function(xhr){ self.notify(xhr.statusText||'重试失败',false); });
		});
		this.$root.on('click.airag','[data-act=retry-all]',function(){
			self.notify('正在重试失败文件…', null);
			self._wasRun=true;
			self.post('manage',{operation:'retryLibrary'}).done(function(res){
				self.notify((res.data&&res.data.message)||'已提交重试',!!(res&&res.code));
				self.watchProgress();
			}).fail(function(xhr){ self.notify(xhr.statusText||'重试失败',false); });
		});
		this.$root.on('keydown.airag','[data-act=lib-words]',function(e){ if(e.key==='Enter'){ self.libWords=$.trim($(this).val()||''); self.libPage=1; self.loadLibrary(); }});
		this.$root.on('click.airag','[data-status]',function(){
			self.libStatus=$(this).attr('data-status')||'';
			self.libPage=1;
			$(this).closest('.airag-dd').removeClass('is-open').find('.airag-dd-btn').text($(this).text()||'状态');
			self.loadLibrary();
		});
		this.$root.on('click.airag','[data-act=lib-prev]',function(){ if(self.libPage>1){ self.libPage--; self.loadLibrary(); }});
		this.$root.on('click.airag','[data-act=lib-next]',function(){ self.libPage++; self.loadLibrary(); });
		this.$root.on('click.airag','[data-act=probe-go]',function(){ self.runProbe(); });
		this.$root.on('keydown.airag','#airag-probe-q',function(e){ if(e.key==='Enter') self.runProbe(); });
		this.$root.on('click.airag','[data-act=add-svc]',function(){ self.openServiceEditor(-1); });
		this.$root.on('click.airag','[data-act=del-svc]',function(){
			var si=$(this).closest('.airag-svc').data('si');
			self.confirm('删除该模型服务？',function(){ self.services.splice(si,1); self.render(); self.notify('已删除服务',true); });
		});
		this.$root.on('click.airag','[data-act=rename-svc]',function(){ self.openServiceEditor($(this).closest('.airag-svc').data('si')); });
		this.$root.on('click.airag','[data-act=add-model]',function(){ self.openModelEditor($(this).closest('.airag-svc').data('si'),-1); });
		this.$root.on('click.airag','[data-act=edit-model]',function(){ var $row=$(this).closest('.airag-mrow'); self.openModelEditor($row.data('si'),$row.data('mi')); });
		this.$root.on('click.airag','[data-act=del-model]',function(){
			var $row=$(this).closest('.airag-mrow');
			var svc=self.services[$row.data('si')], mi=$row.data('mi');
			if(!svc) return;
			self.confirm('删除该模型？',function(){ svc.models.splice(mi,1); self.render(); self.notify('已删除模型',true); });
		});
		this.$root.on('click.airag','[data-act=fetch]',function(){
			var $svc=$(this).closest('.airag-svc'), si=$svc.data('si'), svc=self.services[si]; if(!svc) return;
			svc.url=self.fixUrl($svc.find('[data-act=svc-url]').val()); svc.apiKey=$.trim($svc.find('[data-act=svc-key]').val()||'');
			var btn=$(this).prop('disabled',true);
			self.post('manage',{operation:'fetchModels',url:svc.url,apiKey:svc.apiKey}).done(function(res){
				if(!(res&&res.code)){ self.notify((res.data&&res.data.message)||'获取失败',false); return; }
				var map={}; _.each(svc.models||[],function(m){map[m.id]=m;});
				_.each((res.data&&res.data.models)||[],function(m){
					if(map[m.id]) return;
					var t=m.type||self.guessType(m.id);
					map[m.id]={id:m.id,name:m.name||self.shortName(m.id),type:t,types:[t],enabled:1,context:m.context||8000,status:''};
				});
				svc.models=_.values(map); self.render(); self.notify((res.data&&res.data.message)||'已获取',true);
			}).fail(function(xhr){ self.notify(xhr.statusText||'获取失败',false); }).always(function(){ btn.prop('disabled',false); });
		});
		this.$root.on('mouseover.airag','[data-err],.airag-test-msg.is-fail',function(e){
			if(e.target!==this && !this.contains(e.target)) return;
			var text=$(this).attr('title')||'';
			if(!text) return;
			var $tip=$('#airag-errtip');
			if(!$tip.length) $tip=$('<div id="airag-errtip" class="airag-errtip"></div>').appendTo('body');
			$tip.text(text).show();
			var r=this.getBoundingClientRect();
			$tip.css({left:Math.max(8, Math.min(r.left, window.innerWidth-440))+'px', top:(r.bottom+8)+'px'});
		});
		this.$root.on('mouseout.airag','[data-err],.airag-test-msg.is-fail',function(e){
			if(e.relatedTarget && this.contains(e.relatedTarget)) return;
			$('#airag-errtip').remove();
		});
		$(document).off('.airagErr').on('mouseover.airagErr','#airag-set-mask .airag-test-msg.is-fail',function(e){
			if(e.relatedTarget && this.contains(e.relatedTarget)) return;
			var text=$(this).attr('title')||$(this).text()||'';
			if(!text) return;
			var $tip=$('#airag-errtip');
			if(!$tip.length) $tip=$('<div id="airag-errtip" class="airag-errtip"></div>').appendTo('body');
			$tip.text(text).show();
			var r=this.getBoundingClientRect();
			$tip.css({left:Math.max(8, Math.min(r.left, window.innerWidth-440))+'px', top:(r.bottom+8)+'px'});
		}).on('mouseout.airagErr','#airag-set-mask .airag-test-msg.is-fail',function(e){
			if(e.relatedTarget && this.contains(e.relatedTarget)) return;
			$('#airag-errtip').remove();
		});
		this.$root.on('click.airag','[data-act=test-model]',function(){
			var $row=$(this).closest('.airag-mrow'), svc=self.services[$row.data('si')], model=svc&&svc.models[$row.data('mi')]; if(!model) return;
			var btn=$(this).prop('disabled',true).text('检测中');
			var $msg=$row.find('.airag-test-msg').removeClass('is-ok is-fail').addClass('is-wait').text('检测中…');
			self.post('manage',{operation:'testModel',url:self.fixUrl(svc.url),apiKey:svc.apiKey||'',modelId:model.id,modelType:self.modelTypes(model).join(',')}).done(function(res){
				var ok=!!(res&&res.code), text=(res.data&&res.data.message)||(ok?'检测通过':'检测失败');
				model.tested=ok?'ok':'fail';
				model.status=model.tested;
				model.testMsg=text;
				if(ok) self._passFlash[$row.data('si')+':'+$row.data('mi')]=1;
				self.flashCheck($msg, ok, text);
				self.collect();
				self.saveQuiet();
			}).fail(function(xhr){
				var text=xhr.statusText||'检测失败';
				model.tested='fail'; model.status='fail'; model.testMsg=text;
				self.flashCheck($msg, false, text);
				self.collect(); self.saveQuiet();
			}).always(function(){ btn.prop('disabled',false).text('测试'); });
		});
		this.$root.on('click.airag','[data-act=test]',function(){
			self.collect();
			var op=$(this).attr('data-op'), btn=$(this).prop('disabled',true).text('检测中');
			var $msg=$(this).siblings('.airag-test-msg').removeClass('is-ok is-fail').addClass('is-wait').text('检测中…');
			var data={operation:op,elasticUrl:self.cfg.elasticUrl,indexName:self.cfg.indexName,milvusUrl:self.cfg.milvusUrl,milvusToken:self.cfg.milvusToken,embedModel:self.cfg.embedModel,embedDim:self.cfg.embedDim};
			_.each(self.services,function(svc){ _.each(svc.models||[],function(m){ if(m.enabled&&m.id===self.cfg.embedModel){ data.embedUrl=svc.url; data.embedApiKey=svc.apiKey; }}); });
			self.post('manage',data).done(function(res){
				var ok=!!(res&&res.code), text=(res.data&&res.data.message)||(ok?'检测通过':'失败');
				self.flashCheck($msg, ok, text);
			}).fail(function(xhr){ self.flashCheck($msg, false, xhr.statusText||'检测失败'); })
			.always(function(){ setTimeout(function(){ btn.prop('disabled',false).text('检测'); },400); });
		});
		this.$root.on('click.airag','[data-act=job]',function(){
			self.runJob($(this).attr('data-op'));
		});
	},
	runJob:function(op){
		var self=this;
		if(op==='rebuild'){
			this.confirm('将重建 AIRAG 向量与任务状态，共享正文保留。确认继续？',function(){ self._prog={scan:0,vec:0,overall:0}; self.submitJob(op); });
			return;
		}
		if(op==='reset'){
			this.confirm('重置后文件需重新切片入库，确认继续？',function(){ self._prog={scan:0,vec:0,overall:0}; self.submitJob(op); });
			return;
		}
		this.submitJob(op);
	},
	submitJob:function(op){
		var self=this;
		this.notify(op==='run'?'已开始处理，进度见上方进度条':'任务已提交', null);
		this.post('manage',{operation:op}).done(function(res){
			self.notify((res.data&&res.data.message)||'已提交',!!(res&&res.code));
			self._wasRun=true; self.watchProgress();
		}).fail(function(xhr){ self.notify(xhr.statusText||'失败',false); });
	},
	openSettings:function(tab){
		var self=this;
		this.setTab=tab||this.setTab||'basic';
		this._setOpen=true;
		this.$root.find('[data-act=settings]').addClass('is-on');
		this.writeRoute();
		$('#airag-set-mask').remove();
		var tabs=[['basic','基础'],['vector','向量库'],['chunk','切片向量'],['search','检索'],['vision','图片识别'],['advanced','高级']];
		var nav=_.map(tabs,function(t){ return '<a href="javascript:void(0)" data-set="'+t[0]+'" class="'+(self.setTab===t[0]?'is-on':'')+'">'+t[1]+'</a>'; }).join('');
		var $mask=$('<div class="airag-set-mask" id="airag-set-mask"><div class="airag-set"><div class="airag-set-head"><b>设置</b><span class="airag-set-sub">连接、切片、检索等进这里，日常用顶部的资源库 / 模型服务</span><button type="button" class="airag-ico" data-act="set-close">×</button></div><div class="airag-set-tabs">'+nav+'</div><div class="airag-set-body">'+this.pageBasic()+this.pageVector()+this.pageChunk()+this.pageSearch()+this.pageVision()+this.pageAdvanced()+'</div><div class="airag-set-foot"><button type="button" class="airag-btn" data-act="set-close">取消</button><button type="button" class="airag-btn airag-btn-primary" data-act="set-save">保存</button></div></div></div>');
		$mask.find('[data-pane]').removeClass('is-on');
		$mask.find('[data-pane="'+this.setTab+'"]').addClass('is-on');
		$mask.on('click','[data-set]',function(){
			self.setTab=$(this).attr('data-set');
			self.writeRoute();
			$mask.find('[data-set]').removeClass('is-on'); $(this).addClass('is-on');
			$mask.find('[data-pane]').removeClass('is-on');
			$mask.find('[data-pane="'+self.setTab+'"]').addClass('is-on');
			if(self.setTab==='advanced') self.refreshStatus();
		});
		$mask.on('click','.airag-switch',function(){
			$(this).toggleClass('is-on');
			var key=$(this).attr('data-key'); if(key) self.cfg[key]=$(this).hasClass('is-on')?1:0;
		});
		$mask.on('input','input[type=range]',function(){ $(this).siblings('.num').text($(this).val()); });
		$mask.on('click','[data-act=set-close]',function(){ self._setOpen=false; $mask.remove(); self.$root.find('[data-act=settings]').removeClass('is-on'); self.writeRoute(); });
		$mask.on('click',function(e){ if(e.target===this){ self._setOpen=false; $mask.remove(); self.$root.find('[data-act=settings]').removeClass('is-on'); self.writeRoute(); }});
		$mask.on('click','[data-act=set-save]',function(){ self.save(); });
		$mask.on('click','[data-act=test]',function(){
			self.collect();
			var op=$(this).attr('data-op'), btn=$(this).prop('disabled',true).text('检测中');
			var $msg=$(this).siblings('.airag-test-msg').removeClass('is-ok is-fail').addClass('is-wait').text('检测中…');
			if(!$msg.length){ $(this).after('<span class="airag-test-msg is-wait">检测中…</span>'); $msg=$(this).siblings('.airag-test-msg'); }
			var data={operation:op,elasticUrl:self.cfg.elasticUrl,indexName:self.cfg.indexName,milvusUrl:self.cfg.milvusUrl,milvusToken:self.cfg.milvusToken,embedModel:self.cfg.embedModel,embedDim:self.cfg.embedDim};
			_.each(self.services,function(svc){ _.each(svc.models||[],function(m){ if(m.enabled&&m.id===self.cfg.embedModel){ data.embedUrl=svc.url; data.embedApiKey=svc.apiKey; }}); });
			self.post('manage',data).done(function(res){
				var ok=!!(res&&res.code), text=(res.data&&res.data.message)||(ok?'检测通过':'失败');
				self.flashCheck($msg, ok, text);
			}).fail(function(xhr){ self.flashCheck($msg, false, xhr.statusText||'检测失败'); })
			.always(function(){ setTimeout(function(){ btn.prop('disabled',false).text('检测'); },400); });
		});
		$mask.on('click','[data-act=job]',function(){
			self.runJob($(this).attr('data-op'));
		});
		$('body').append($mask);
		if(this.setTab==='advanced') this.refreshStatus();
	},
	runProbe:function(){
		var self=this, q=$.trim(this.$root.find('#airag-probe-q').val()||''), mode=this.$root.find('#airag-probe-mode').val()||'hybrid';
		if(!q){ this.notify('请输入关键词',false); return; }
		this.notify('检索中…', null);
		this.post('manage',{operation:'searchTest',words:q,mode:mode,ext:this.libFilter.ext||'',sourceID:this.libFilter.sourceID||0}).done(function(res){
			var ok=!!(res&&res.code), data=res.data||{};
			self.notify(ok?(data.message||'完成'):(data.message||'失败'), ok);
			var rows=data.hybrid||data.es||[];
			if(mode==='keyword') rows=data.es||[];
			if(mode==='vector') rows=data.vector||[];
			var html=_.map(rows,function(hit){
				return '<div class="airag-hit" data-file="'+(hit.fileID||'')+'"><b>'+self.h(hit.name||'')+'</b><p>'+self.h(hit.snippet||hit.text||'')+'</p></div>';
			}).join('')||'<div class="airag-empty-opt">没有命中。确认资源库已入库后再试。</div>';
			self.$root.find('#airag-probe-hits').html(html);
		}).fail(function(xhr){ self.notify(xhr.statusText||'检索失败',false); });
		this.$root.off('click.probehit').on('click.probehit','.airag-hit',function(){ var id=$(this).attr('data-file'); if(id) self.openFile(id); });
	},
	openServiceEditor:function(si){
		var self=this, isNew=si<0, svc=isNew?{id:'svc'+Date.now(),name:'自定义服务',enabled:1,url:'https://api.siliconflow.cn/v1',apiKey:'',models:[]}:this.services[si];
		if(!svc) return;
		$('#airag-mask').remove();
		var $mask=$('<div class="airag-mask" id="airag-mask"><div class="airag-modal airag-modal-form"><div class="airag-modal-head"><h4>'+(isNew?'添加服务':'编辑服务')+'</h4><label class="airag-en"><span>是否启用</span><button type="button" class="airag-switch'+(svc.enabled===0?'':' is-on')+'" data-act="ed-on"></button></label></div>'+
			'<div class="airag-field"><label>服务名称</label><input name="name" value="'+_.escape(svc.name||'')+'" placeholder="硅基流动 / 自定义服务"></div>'+
			'<div class="airag-field"><label>API 地址</label><input name="url" value="'+_.escape(svc.url||'')+'" placeholder="https://api.siliconflow.cn/v1"></div>'+
			'<div class="airag-field"><label>API 密钥</label><input name="apiKey" type="password" value="'+_.escape(svc.apiKey||'')+'" placeholder="sk-…"></div>'+
			'<p class="airag-modal-hint">新服务会以弹窗添加，避免卡片被挤到页面下方看不到。</p>'+
			'<div class="airag-modal-actions"><button type="button" class="airag-btn" data-close="1">取消</button><button type="button" class="airag-btn airag-btn-primary" data-ok="1">保存</button></div></div></div>');
		$mask.on('click','.airag-switch',function(e){ e.preventDefault(); $(this).toggleClass('is-on'); });
		$mask.on('click',function(e){ if(e.target===this||$(e.target).closest('[data-close]').length) $mask.remove(); });
		$mask.on('click','[data-ok]',function(){
			var name=$.trim($mask.find('[name=name]').val()||'');
			if(!name){ self.notify('请填写服务名称',false); return; }
			svc.name=name;
			svc.url=self.fixUrl($mask.find('[name=url]').val());
			svc.apiKey=$.trim($mask.find('[name=apiKey]').val()||'');
			svc.enabled=$mask.find('[data-act=ed-on]').hasClass('is-on')?1:0;
			if(isNew) self.services.push(svc);
			$mask.remove();
			self.render();
			self.notify(isNew?'已添加服务':'已更新服务',true);
			self.gotoPage('models', true);
		});
		$('body').append($mask);
		setTimeout(function(){ $mask.find('[name=name]').focus(); },30);
	},
	parseContext:function(v){
		v=String(v==null?'':v).trim().toLowerCase().replace(/,/g,'');
		var m=v.match(/^(\d+(?:\.\d+)?)\s*k/);
		if(m) return Math.max(1024, Math.round(parseFloat(m[1])*1024));
		var n=parseInt(v,10)||0;
		if(n>0 && n<=256) return n*1024;
		return n||8192;
	},
	openModelEditor:function(si,mi){
		var self=this, svc=this.services[si]; if(!svc) return;
		var m=mi>=0?svc.models[mi]:{id:'',name:'',type:'chat',types:['chat'],context:64000,enabled:1};
		var selected=self.modelTypes(m);
		var typeHtml=_.map([['chat','对话'],['embed','嵌入'],['rerank','重排序'],['image','图片'],['asr','语音']],function(item){
			return '<label class="airag-type"><input type="checkbox" value="'+item[0]+'"'+(selected.indexOf(item[0])>=0?' checked':'')+'><span>'+item[1]+'</span></label>';
		}).join('');
		$('#airag-mask').remove();
		var $mask=$('<div class="airag-mask" id="airag-mask"><div class="airag-modal airag-modal-form"><div class="airag-modal-head"><h4>'+(mi>=0?'编辑模型':'添加模型')+'</h4><label class="airag-en"><span>是否启用</span><button type="button" class="airag-switch'+(m.enabled===0?'':' is-on')+'" data-act="ed-on"></button></label></div>'+
			'<div class="airag-field"><label>模型 ID <em>*</em></label><input name="id" value="'+_.escape(m.id)+'" placeholder="deepseek-ai/DeepSeek-V3 或 BAAI/bge-m3"></div>'+
			'<div class="airag-modal-grid"><div class="airag-field"><label>显示名称</label><input name="name" value="'+_.escape(m.name||self.shortName(m.id))+'" placeholder="DeepSeek-V3"></div>'+
			'<div class="airag-field"><label>上下文长度</label><input name="context" value="'+(m.context||8000)+'" placeholder="16384 或 16k"></div></div>'+
			'<div class="airag-field"><label>模型类型</label><p class="airag-modal-hint">可多选。同一模型若同时是对话和嵌入，测试时会分别请求。</p><div class="airag-types">'+typeHtml+'</div></div>'+
			'<div class="airag-modal-actions"><button type="button" class="airag-btn" data-close="1">取消</button><button type="button" class="airag-btn airag-btn-primary" data-ok="1">保存</button></div></div></div>');
		$mask.on('click','.airag-switch',function(e){ e.preventDefault(); $(this).toggleClass('is-on'); });
		$mask.on('click',function(e){ if(e.target===this||$(e.target).closest('[data-close]').length) $mask.remove(); });
		$mask.on('click','[data-ok]',function(){
			var id=$.trim($mask.find('[name=id]').val()||'');
			if(!id){ self.notify('请填写模型 ID',false); return; }
			var types=[];
			$mask.find('.airag-types input:checked').each(function(){ types.push(this.value); });
			if(!types.length) types=[self.guessType(id)];
			var ctx=self.parseContext($mask.find('[name=context]').val());
			var item={id:id,name:$.trim($mask.find('[name=name]').val()||'')||self.shortName(id),type:types[0],types:types,enabled:$mask.find('[data-act=ed-on]').hasClass('is-on')?1:0,context:ctx,status:m.status||'',tested:m.tested||'',testMsg:m.testMsg||''};
			svc.models=svc.models||[];
			if(mi>=0) svc.models[mi]=item; else svc.models.push(item);
			$mask.remove();
			self.render();
			self.notify(mi>=0?'已更新模型':'已添加模型',true);
		});
		$('body').append($mask);
		setTimeout(function(){ $mask.find('[name=id]').focus(); },30);
	}
});
}
