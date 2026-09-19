(function(){
	var route={page:'aiRag',title:'AI 助手',url:'{{pluginApi}}',ignoreLogin:false};
	function mapRoute(){
		if(!window.Router||typeof Router.mapIframe!=='function') return false;
		try{ Router.mapIframe(route); window.__aiRagMapped=true; return true; }catch(e){ return false; }
	}
	mapRoute();
	if(window.kodReady && typeof kodReady.push==='function') kodReady.push(mapRoute);
	var n=0, t=setInterval(function(){ if(mapRoute()||++n>240) clearInterval(t); },25);
})();
(function aiRagBoot(){
	if(window.__aiRagSearchUI)return;
	if(!window.Events){
		if(!window.__aiRagKodReady && window.kodReady && typeof kodReady.push==='function'){
			window.__aiRagKodReady=true;
			kodReady.push(aiRagBoot);
		}
		window.__aiRagWait=(window.__aiRagWait||0)+1;
		if(window.__aiRagWait<180) setTimeout(aiRagBoot, 80);
		return;
	}
	window.__aiRagSearchUI=true;
	var staticPath='{{pluginHost}}static/';
	if(window.G) G.aiRagOption={api:'{{pluginApi}}',host:'{{pluginHost}}',version:'{{package.version}}'};
	if(!document.getElementById('airag-search-style')){
		var style=document.createElement('style');
		style.id='airag-search-style';
		style.type='text/css';
		style.appendChild(document.createTextNode(
			'.file-list-list .file.file-search-match.has-file-cover .search-match-content .file-cover:not(:has(.picture)){display:none!important}'+
			'.file-list-list .file.file-search-match.has-file-cover .search-match-content:not(:has(.picture)) .match-text{display:block!important;height:auto!important;margin:0 5px 5px 25px!important}'+
			'.context-menu-list .context-menu-item.airagAsk{display:list-item!important;visibility:visible!important}'+
			'.airag-dialog-frame{width:100%;height:100%;border:0;display:block;background:#fff}'+
			'.aui-outer[id*="airag-chat-dialog"],.dialog-simple[id*="airag-chat"]{max-width:96vw}'+
			'.path-ico.airag-file-rag-icon{position:relative;min-width:10px!important;width:1em!important;height:1em!important;margin:0;padding:0;display:inline-block;vertical-align:middle;cursor:pointer;overflow:visible}'+
			'.meta-info .airag-file-rag-icon .airag-ai-mark{width:100%!important;height:100%!important;min-width:0;padding:0;margin:0;border-radius:50%;background:#efedff;color:#705bf2;border:.5px solid rgba(112,91,242,.19);box-sizing:border-box;display:inline-flex;align-items:center;justify-content:center;font-size:.55em;font-weight:600;line-height:1}'+
			'.meta-info .airag-file-rag-icon .airag-ai-dot{position:absolute;top:-1px;right:-1px;width:6px;height:6px;border-radius:50%;background:#22c55e;padding:0}'+
			'.info-item-airag-file{margin:8px 0}'
		));
		document.head.appendChild(style);
	}
	Events.bind('admin.leftMenu.before',function(menuList){
		menuList.push({
			title:"{{LNG['aiRag.meta.titleAdmin']}}",
			icon:'ri-robot-line',
			link:'admin/setting/aiRag',
			after:'admin/setting/notice',
			fileSrc:staticPath+'console.js?v={{package.version}}'
		});
	});
	if(typeof requireAsync==='function'){
		requireAsync([staticPath+'console.js?v={{package.version}}']);
	}
	function hideTypeIconCover($root){
		($root&&$root.find?$root:$(document)).find('.file.file-search-match.has-file-cover').each(function(){
			var $file=$(this),$cover=$file.find('.search-match-content .file-cover');
			if(!$cover.length||$cover.find('.picture').length)return;
			$cover.remove();
			$file.removeClass('has-file-cover');
		});
	}
	Events.bind('explorer.path.list.after',function(){hideTypeIconCover($('.file-continer')); markReadyFiles();});
	Events.bind('path.list.fileMetaIconMake',function(sourceInfo,result){
		var rag=_.get(sourceInfo,'fileInfo.aiRagInfo')||_.get(sourceInfo,'aiRagInfo')||(function(){
			var fileID=_.get(sourceInfo,'fileInfo.fileID')||sourceInfo.fileID;
			return fileID&&window.__airagFlags&&window.__airagFlags[fileID];
		})();
		if(!rag||!rag.ready||!result) return;
		if(/airag-file-rag-icon/.test(result.icon||'')) return;
		result.icon=(result.icon||'')+'<i class="path-ico small small-size airag-file-rag-icon" title="已入库，可右键 AI 提问"><i class="airag-ai-mark"><span>AI</span></i><span class="airag-ai-dot"></span></i>';
	});
	Events.bind('explorer.pathInfo.render',function(sourceInfo,$main){
		var fileID=_.get(sourceInfo,'fileInfo.fileID')||sourceInfo.fileID;
		if(!fileID||!$main||!$main.length) return;
		var flag=window.__airagFlags&&window.__airagFlags[fileID];
		$main.find('.info-item-airag-file').remove();
		var html='<div class="p info-item-airag-file"><div class="title">AIRAG</div><div class="content">'+(flag&&flag.ready?'<span class="info-item" style="color:#16a34a">已向量化 · 可右键 AI 提问 · 分片 '+(flag.chunks||0)+'</span>':'<span class="info-item" style="color:#8a8f99">尚未向量化，入库完成后可提问</span>')+'</div></div>';
		var $dom=$main.find('.p.info-item-modify-time').last();
		if($dom.length) $(html).insertAfter($dom); else $main.append(html);
	});
	var flagSeq=0, flagTimer=null;
	function markReadyFiles(){
		clearTimeout(flagTimer);
		flagTimer=setTimeout(queryReadyFiles,180);
	}
	function queryReadyFiles(){
		var paths=[];
		$('.file-continer .file[data-path]').each(function(){
			var p=$(this).attr('data-path');
			if(p) paths.push(p);
		});
		if(!paths.length||!window.G||!G.aiRagOption) return;
		var seq=++flagSeq;
		$.ajax({
			url:G.aiRagOption.api+'chat',
			type:'POST',
			dataType:'json',
			data:{operation:'fileFlags',paths:JSON.stringify(paths.slice(0,500))},
			timeout:15000
		}).done(function(res){
			if(seq!==flagSeq) return; // 目录已切换，丢弃过期结果
			var data=(res&&res.data)||{};
			window.__airagFlags=data.flags||{};
			var byPath=data.byPath||{};
			$('.file-continer .file[data-path]').each(function(){
				var $file=$(this), path=$file.attr('data-path'), flag=byPath[path];
				$file.removeClass('is-airag-ready');
				$file.find('.filename .airag-file-rag-icon,.title-type-name .airag-file-rag-icon,.airag-file-tag').remove();
				if(!flag||!flag.ready) return;
				$file.addClass('is-airag-ready');
			});
		});
	}
	function menuItem(){
		return {
			name:'AI 助手',
			url:'{{pluginApi}}',
			target:'inline',
			menuAdd:'{{config.menuAdd}}',
			subMenu:'{{config.menuSubMenu}}',
			icon:'ri-robot-line bg-blue-6'
		};
	}
	function putMenu(listData){
		if(!listData) return;
		listData['aiRag']=menuItem();
	}
	Events.bind('main.menu.loadBefore',putMenu);
	Events.bind('main.menu.loadAfter',putMenu);
	if(window.Router&&Router.mapIframe){
		Router.mapIframe({page:'aiRag',title:'AI 助手',url:'{{pluginApi}}',ignoreLogin:false});
	}
	function selectedItems(){
		var pathAction=_.get(window,'kodApp.pathAction');
		if(!pathAction||!pathAction.makeParamSelect)return [];
		return pathAction.makeParamSelect()||[];
	}
	function openChat(items){
		items=_.filter(items||[],function(item){return item&&item.path;});
		var refs=_.map(items,function(item){
			return {path:item.path,name:item.name||item.path,type:item.type||(item.isFolder?'folder':'file')};
		});
		try{sessionStorage.setItem('airag.refs',JSON.stringify(refs));}catch(e){}
		try{localStorage.removeItem('airag.refs');}catch(e){}
		var api='{{pluginApi}}';
		var url=api+(api.indexOf('?')>=0?'':'?');
		if(!/\/$/.test(url)&&url.indexOf('index')<0) url+='/';
		url=url.replace(/\/?$/,'/');
		url+='index';
		if(refs.length){
			var joiner=url.indexOf('?')>=0?'&':'?';
			url+=joiner+'compact=1&refs='+encodeURIComponent(JSON.stringify(refs));
		}else{
			var joiner=url.indexOf('?')>=0?'&':'?';
			url+=joiner+'compact=1&refs='+encodeURIComponent('[]');
		}
		if($.dialog.list['airag-chat-dialog']){
			try{$.dialog.list['airag-chat-dialog'].close();}catch(e){}
		}
		$.dialog({
			id:'airag-chat-dialog',
			title:'AI 提问',
			ico:'<i class="font-icon ri-robot-line"></i>',
			width:820,
			height:640,
			padding:0,
			resize:true,
			content:'<iframe class="airag-dialog-frame" src="'+url.replace(/"/g,'&quot;')+'"></iframe>'
		});
	}
	window.addEventListener('message',function(ev){
		var data=ev.data||{};
		if(data.type!=='airag-open'||!data.path) return;
		var now=Date.now();
		if(window.__airagOpenPath===data.path && now-(window.__airagOpenAt||0)<800) return;
		window.__airagOpenPath=data.path; window.__airagOpenAt=now;
		try{
			if(window.kodApi&&kodApi.fileView){ kodApi.fileView(data.path,{title:data.name||'文件预览'}); return; }
			if(window.kodApp&&kodApp.pathAction&&kodApp.pathAction.fileOpen){ kodApp.pathAction.fileOpen({path:data.path,name:data.name||''}); return; }
			if(window.kodApp&&typeof kodApp.open==='function'){ kodApp.open(data.path); return; }
		}catch(e){}
	});
	window.AiRagChat={open:openChat};
	$(document).off('click.airagTag').on('click.airagTag','.airag-file-rag-icon,.airag-file-tag,.airag-ready-ico',function(e){
		e.preventDefault(); e.stopPropagation();
		var $file=$(this).closest('.file');
		var path=$file.attr('data-path');
		if(!path) return;
		openChat([{path:path,name:$file.attr('data-name')||$.trim($file.find('.filename,.title,.name').first().clone().children().remove().end().text())||path,type:'file'}]);
	});
	function askMenuItem(){
		return {
			airagAsk:{
				name:'AI 提问',
				className:'airagAsk',
				icon:'ri-robot-line',
				callback:function(){openChat(selectedItems());}
			}
		};
	}
	function isPathMenu(menu){
		var type=String((menu&&menu.menuType)||'');
		var cls=String((menu&&menu.$menu&&menu.$menu.attr('class'))||'');
		return /path-file|path-folder|path-more|path-mini|fav-path|toolbar-source|menu-simple|pathDefault|userRencent|guest-more|history-list-file/.test(type+' '+cls);
	}
	function menuShow(menu){
		if(!menu||!menu.$menu||!isPathMenu(menu))return;
		if(!menu.$menu.find('.airagAsk').length){
			var item=askMenuItem();
			var before='';
			_.each(['.open','.download','.copy','.path-info','.cute-to','.more-action'],function(sel){
				if(!before&&menu.$menu.find(sel).length) before=sel;
			});
			if(before) $.contextMenu.menuAdd(item,menu,false,before);
			else $.contextMenu.menuAdd(item,menu,false,'.context-menu-item');
		}
		if(!menu.$menu.find('.airagAsk').length){
			var $li=$('<li class="context-menu-item airagAsk" item-key="airagAsk"><span>AI 提问</span></li>');
			var $anchor=menu.$menu.children('.open,.download,.copy,.path-info,.context-menu-item').first();
			if($anchor.length) $anchor.before($li); else menu.$menu.prepend($li);
			$li.on('mouseup click',function(e){e.preventDefault();e.stopPropagation();openChat(selectedItems());});
		}
		if($.contextMenu.menuItemShow) $.contextMenu.menuItemShow(menu,'airagAsk');
		menu.$menu.find('.airagAsk').removeClass('hidden disable').show();
	}
	Events.bind('rightMenu.beforeShow',menuShow);
	Events.bind('rightMenu.afterShow',menuShow);
})();
