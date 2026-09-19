(function(){
function boot(){
	if(window.__aiRagAdminReady)return;
	if(!window.jQuery) return;
	window.__aiRagAdminReady=true;
	$('#airag-admin-style').remove();
	$('<style id="airag-admin-style">'+[
		'.airag-action.btn-sm{padding:5px 13px!important;font-size:13px!important;line-height:1.4!important;min-width:88px;margin:0 4px 4px 0}',
		'.airag-action.is-busy{opacity:.85;pointer-events:none}',
		'.airag-status{line-height:1.5;font-size:13px;max-width:560px}',
		'.airag-metrics{display:grid;grid-template-columns:minmax(0,1fr) minmax(0,1fr);gap:8px 24px;margin:0 0 10px}',
		'.airag-stat{display:flex;align-items:baseline;gap:10px;min-width:0}',
		'.airag-stat.is-wide{grid-column:1/-1}',
		'.airag-status .k{flex:0 0 5.5em;color:#888;white-space:nowrap}',
		'.airag-status .v{min-width:0;font-variant-numeric:tabular-nums}',
		'.airag-error,.airag-warn{color:#c62828;margin:6px 0}',
		'.airag-warn{color:#b26a00;background:#fff7e6;border:1px solid #ffe0a3;padding:6px 8px;border-radius:4px}',
		'.airag-dot{display:inline-block;width:8px;height:8px;border-radius:50%;background:#5b5fc7;margin-right:8px;vertical-align:middle}',
		'.airag-dot.is-loading{animation:airagPulse 1s infinite alternate}',
		'.airag-progress{display:flex;flex-direction:column;gap:4px;margin:0 0 10px;padding:8px 10px;background:#f4f1ff;border:1px solid #d9d2f5;border-radius:4px;color:#4338ca}',
		'.airag-test-msg{display:inline-block;margin-left:8px;min-height:20px;vertical-align:middle;max-width:520px;line-height:1.45}',
		'.airag-test-msg.is-wait{color:#4338ca}.airag-test-msg.is-ok{color:#20a53a}.airag-test-msg.is-fail{color:#d9822b}',
		'.airag-search-box{margin-top:10px;padding:8px 0}.airag-search-box input{width:240px;margin-right:8px}',
		'@keyframes airagPulse{to{opacity:.25}}'
	].join('')+'</style>').appendTo('head');
	(function(){
		var el=document.querySelector('script[src*="aiRag/static/admin.js"]');
		var href=el&&el.src?el.src.replace(/admin\.js(\?.*)?$/,'studio.css?v=1.3.1'):'./plugins/aiRag/static/studio.css?v=1.3.1';
		$('#airag-studio-css').remove();
		$('<link id="airag-studio-css" rel="stylesheet" href="'+href+'">').appendTo('head');
	})();
	var loadingHtml='<span class="airag-dot is-loading"></span>正在读取运行情况…';
	var pending=null,watchTimer=0,pollTimer=0,busy=false,runStartedMs=0,lastRunning=0,airagForm=null,services=[],studioEditor=null,studioRename=null;
	var TYPE_META={chat:{label:'对话',cls:'is-chat'},embed:{label:'嵌入',cls:'is-embed'},rerank:{label:'重排序',cls:'is-rerank'},image:{label:'图片',cls:'is-image'},asr:{label:'语音',cls:'is-asr'}};
	function msg(result,fallback){var data=result&&result.data;return (data&&data.message)||(typeof data==='string'?data:fallback);}
	function statusBoxes(){return $('.airag-status');}
	function testMsg(button){
		var note=button.closest('.airag-check').find('.airag-test-msg');
		if(!note.length) note=button.nextAll('.airag-test-msg').first();
		if(!note.length){button.after('<span class="airag-test-msg"></span>');note=button.nextAll('.airag-test-msg').first();}
		return note;
	}
	function pick(name){
		var v='';
		if(airagForm&&_.isFunction(airagForm.getValue)){
			v=airagForm.getValue(name);
			if(_.isArray(v)) v=v.join(',');
			if(v!==undefined&&v!==null) v=$.trim(String(v));
			else v='';
		}
		if(!v){
			var $el=$('[name="'+name+'"]');
			if($el.length){
				var $visible=$el.filter(':visible');
				v=$.trim(($visible.length?$visible:$el).last().val()||'');
			}
		}
		if(/Url$/.test(name)) v=fixUrl(v);
		return v;
	}
	function setField(name,value){
		if(airagForm&&_.isFunction(airagForm.setValue)){
			try{airagForm.setValue(name,value);}catch(e){}
		}
		$('[name="'+name+'"]').val(value).trigger('change');
	}
	function fixUrl(v){
		v=String(v||'').replace(/\\/g,'/').trim();
		v=v.replace(/^(https?):\/+/i,'$1://');
		v=v.replace(/^(https?:\/\/)\/+/i,'$1');
		v=v.replace(/\/+(chat\/)?completions$/i,'');
		v=v.replace(/\/+embeddings$/i,'');
		return v.replace(/\/+$/,'');
	}
	function h(s){return $('<div>').text(s==null?'':s).html();}
	function ico(name){
		var paths={
			edit:'<path d="M12 20h9"/><path d="M16.5 3.5a2.1 2.1 0 0 1 3 3L7 19l-4 1 1-4 12.5-12.5z"/>',
			trash:'<polyline points="3 6 5 6 21 6"/><path d="M19 6l-1 14H6L5 6"/><path d="M10 11v6M14 11v6"/><path d="M9 6V4h6v2"/>',
			eye:'<path d="M1 12s4-7 11-7 11 7 11 7-4 7-11 7S1 12 1 12z"/><circle cx="12" cy="12" r="3"/>',
			plus:'<path d="M12 5v14M5 12h14"/>',
			fetch:'<polyline points="23 4 23 10 17 10"/><path d="M20.49 15a9 9 0 1 1-2.12-9.36L23 10"/>',
			play:'<polygon points="8 5 19 12 8 19 8 5"/>',
			out:'<path d="M18 13v6a2 2 0 0 1-2 2H6a2 2 0 0 1-2-2V8a2 2 0 0 1 2-2h6"/><polyline points="15 3 21 3 21 9"/><line x1="10" y1="14" x2="21" y2="3"/>'
		};
		return '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round">'+(paths[name]||'')+'</svg>';
	}
	function shortName(id){
		var parts=String(id||'').split('/');
		return parts[parts.length-1]||id;
	}
	function vendorOf(id){
		var parts=String(id||'').split('/');
		return parts.length>1?parts[0]:'';
	}
	function guessType(id){
		var low=String(id||'').toLowerCase();
		if(/rerank/.test(low)) return 'rerank';
		if(/bge|embed|e5-/.test(low)) return 'embed';
		if(/kolors|flux|sdxl|stable-diffusion/.test(low)) return 'image';
		if(/sensevoice|whisper|asr/.test(low)) return 'asr';
		return 'chat';
	}
	function defaultServices(){
		var url=fixUrl(pick('llmUrl')||pick('embedUrl')||'https://api.siliconflow.cn/v1')||'https://api.siliconflow.cn/v1';
		var key=pick('llmApiKey')||pick('embedApiKey')||'';
		var chat=pick('llmModel')||'deepseek-ai/DeepSeek-V3';
		var embed=pick('embedModel')||'BAAI/bge-m3';
		return [{
			id:'siliconflow',name:'硅基流动',enabled:1,url:url,apiKey:key,
			models:[
				{id:chat,name:shortName(chat),type:'chat',enabled:1,context:64000,status:''},
				{id:'deepseek-ai/DeepSeek-R1',name:'DeepSeek-R1',type:'chat',enabled:1,context:64000,status:''},
				{id:embed,name:shortName(embed),type:'embed',enabled:1,context:8000,status:''},
				{id:'BAAI/bge-reranker-v2-m3',name:'bge-reranker-v2-m3',type:'rerank',enabled:1,context:8000,status:''},
				{id:'Kwai-Kolors/Kolors',name:'Kolors',type:'image',enabled:1,context:0,status:''},
				{id:'FunAudioLLM/SenseVoiceSmall',name:'SenseVoice',type:'asr',enabled:1,context:0,status:''}
			]
		}];
	}
	function loadServices(){
		var raw=pick('modelServices');
		var data=[];
		try{data=raw?JSON.parse(raw):[];}catch(e){data=[];}
		if(!_.isArray(data)||!data.length) data=defaultServices();
		_.each(data,function(svc){
			svc.url=fixUrl(svc.url||'');
			svc.enabled=svc.enabled?1:0;
			svc.models=_.map(svc.models||[],function(m){
				return {
					id:m.id,name:m.name||shortName(m.id),type:m.type||guessType(m.id),
					enabled:m.enabled?1:0,context:parseInt(m.context,10)||8000,status:m.status||''
				};
			});
		});
		return data;
	}
	function firstOf(type){
		var hit=null;
		_.each(services,function(svc){
			if(hit||!svc.enabled) return;
			_.each(svc.models,function(m){
				if(hit||!m.enabled||m.type!==type) return;
				hit={svc:svc,model:m};
			});
		});
		return hit;
	}
	function persist(){
		_.each(services,function(svc){ svc.url=fixUrl(svc.url||''); });
		var json=JSON.stringify(services);
		setField('modelServices',json);
		var chat=firstOf('chat');
		var embed=firstOf('embed');
		if(chat){
			setField('llmUrl',chat.svc.url);
			setField('llmApiKey',chat.svc.apiKey||'');
			setField('llmModel',chat.model.id);
		}
		if(embed){
			setField('embedUrl',embed.svc.url);
			setField('embedApiKey',embed.svc.apiKey||'');
			setField('embedModel',embed.model.id);
		}else if(chat){
			setField('embedUrl',chat.svc.url);
			setField('embedApiKey',chat.svc.apiKey||'');
		}
		var names=[];
		_.each(services,function(svc){
			if(!svc.enabled) return;
			_.each(svc.models,function(m){if(m.enabled&&m.type==='chat') names.push(m.id);});
		});
		setField('llmModels',names.join(','));
	}
	function hideLegacy(){
		_.each(['modelServices','llmUrl','llmApiKey','llmModel','llmModels'],function(name){
			var $el=$('[name="'+name+'"]');
			if(!$el.length) return;
			$el.closest('.form-group,.setting-item,.form-row,tr,li,div').filter(function(){
				return $(this).find('[name="'+name+'"]').length && $(this).children().length<8;
			}).first().addClass('airag-hide-row');
			$el.closest('[data-key="'+name+'"]').addClass('airag-hide-row');
		});
	}
	function editorHtml(si){
		if(!studioEditor||studioEditor.si!==si) return '';
		var title=studioEditor.mi>=0?'编辑模型':'添加模型';
		var opts=_.map(['chat','embed','rerank','image','asr'],function(key){
			var meta=TYPE_META[key];
			return '<option value="'+key+'"'+(studioEditor.type===key?' selected':'')+'>'+meta.label+'</option>';
		}).join('');
		return '<div class="airag-editor">'+
			'<h4>'+title+'</h4>'+
			'<div class="airag-editor-grid">'+
			'<div><label>模型 ID</label><input data-editor="id" placeholder="deepseek-ai/DeepSeek-V3" value="'+h(studioEditor.id)+'"></div>'+
			'<div><label>显示名称</label><input data-editor="name" placeholder="DeepSeek-V3" value="'+h(studioEditor.name)+'"></div>'+
			'<div><label>类型</label><select data-editor="type">'+opts+'</select></div>'+
			'<div><label>上下文 (k)</label><input data-editor="context" value="'+h(Math.max(1,Math.round((studioEditor.context||8000)/1000)))+'"></div>'+
			'</div>'+
			'<div class="airag-editor-actions">'+
			'<button type="button" class="airag-btn" data-studio="editor-cancel">取消</button>'+
			'<button type="button" class="airag-btn airag-btn-primary" data-studio="editor-save">确定</button>'+
			'</div></div>';
	}
	function renderStudio(){
		var box=$('.airag-studio');
		if(!box.length) return;
		var html='<div class="airag-studio-toolbar"><button type="button" class="airag-ghost" data-studio="add-svc">+ 添加服务</button></div>';
		_.each(services,function(svc,si){
			html+='<div class="airag-svc" data-si="'+si+'">';
			html+='<div class="airag-svc-head"><span class="airag-svc-logo">F</span>';
			if(studioRename===si) html+='<input class="airag-svc-name-input" data-studio="rename-input" value="'+h(svc.name||'')+'">';
			else html+='<span class="airag-svc-name">'+h(svc.name||'未命名服务')+'</span>';
			html+='<span class="spacer"></span>';
			html+='<button type="button" class="airag-ico" title="编辑名称" data-studio="rename">'+ico('edit')+'</button>';
			html+='<button type="button" class="airag-ico" title="删除服务" data-studio="del-svc">'+ico('trash')+'</button>';
			html+='<button type="button" class="airag-switch'+(svc.enabled?' is-on':'')+'" title="启用服务" data-studio="svc-on"></button></div>';
			html+='<div class="airag-svc-body">';
			html+='<div class="airag-field"><div class="airag-field-lab"><span class="lab-left">API 地址</span></div><input type="text" data-studio="url" value="'+h(svc.url||'')+'" placeholder="https://api.siliconflow.cn/v1"></div>';
			html+='<div class="airag-field"><div class="airag-field-lab"><span class="lab-left">API 密钥<a href="https://cloud.siliconflow.cn/account/ak" target="_blank" rel="noreferrer">获取密钥</a></span></div>';
			html+='<div class="airag-key-wrap"><input type="password" data-studio="key" value="'+h(svc.apiKey||'')+'"><button type="button" class="airag-ico" data-studio="eye" title="显示/隐藏">'+ico('eye')+'</button></div></div>';
			html+='<div class="airag-models-head"><span class="title">模型列表</span><div class="airag-fetch-group">';
			html+='<button type="button" data-studio="fetch">'+ico('fetch')+' 获取模型列表</button>';
			html+='<button type="button" title="添加模型" data-studio="add-model">'+ico('plus')+'</button></div></div>';
			html+=editorHtml(si);
			_.each(svc.models||[],function(m,mi){
				var meta=TYPE_META[m.type]||TYPE_META.chat;
				var vendor=vendorOf(m.id);
				var editing=studioEditor&&studioEditor.si===si&&studioEditor.mi===mi;
				html+='<div class="airag-mrow'+(editing?' is-edit':'')+'" data-mi="'+mi+'">';
				html+='<div class="mid">';
				if(vendor) html+='<span class="vendor" title="'+h(m.id)+'">'+h(vendor)+'</span>';
				html+='<span class="mname" title="'+h(m.id)+'">'+h(m.name||shortName(m.id))+'</span>';
				html+='<span class="airag-badge '+meta.cls+'">'+meta.label+'</span>';
				if(m.context) html+='<span class="airag-ctx">'+Math.round(m.context/1000)+'k</span>';
				html+='</div><div class="airag-mops">';
				html+='<button type="button" class="airag-ico airag-hover" title="编辑" data-studio="edit-model">'+ico('edit')+'</button>';
				html+='<button type="button" class="airag-ico airag-hover" title="删除" data-studio="del-model">'+ico('trash')+'</button>';
				if(m.status==='ok') html+='<span class="airag-passed"><i></i>检测通过</span>';
				html+='<button type="button" class="airag-test'+(m.status==='ok'?' airag-hover':'')+'" title="'+h(m.error||'测试该模型')+'" data-studio="test">'+ico('play')+' 测试</button>';
				html+='<button type="button" class="airag-switch'+(m.enabled?' is-on':'')+'" title="'+(m.enabled?'已启用':'已停用')+'" data-studio="model-on"></button>';
				html+='</div></div>';
			});
			html+='</div></div>';
		});
		box.html(html);
		hideLegacy();
		if(studioRename!=null) box.find('[data-studio=rename-input]').focus().select();
		if(studioEditor) box.find('[data-editor=id]').focus();
	}
	function studioTarget(el){
		var $el=$(el);
		return {
			si:parseInt($el.closest('.airag-svc').attr('data-si'),10),
			mi:parseInt($el.closest('.airag-mrow').attr('data-mi'),10)
		};
	}
	function openEditor(si,mi){
		var svc=services[si]; if(!svc) return;
		if(mi>=0&&svc.models[mi]){
			var m=svc.models[mi];
			studioEditor={si:si,mi:mi,id:m.id,name:m.name||shortName(m.id),type:m.type||'chat',context:m.context||8000};
		}else{
			studioEditor={si:si,mi:-1,id:'',name:'',type:'chat',context:64000};
		}
		renderStudio();
	}
	function saveEditor(){
		if(!studioEditor) return;
		var box=$('.airag-svc[data-si="'+studioEditor.si+'"]');
		var id=$.trim(box.find('[data-editor=id]').val()||'');
		var name=$.trim(box.find('[data-editor=name]').val()||'');
		var type=box.find('[data-editor=type]').val()||guessType(id);
		var ctx=parseInt(box.find('[data-editor=context]').val(),10);
		if(!id){Tips.tips('请填写模型 ID',false);return;}
		var svc=services[studioEditor.si];
		svc.models=svc.models||[];
		var item={id:id,name:name||shortName(id),type:type,enabled:1,context:(ctx>0?ctx:8)*1000,status:'',error:''};
		if(studioEditor.mi>=0&&svc.models[studioEditor.mi]){
			item.enabled=svc.models[studioEditor.mi].enabled;
			item.status=svc.models[studioEditor.mi].status||'';
			item.error=svc.models[studioEditor.mi].error||'';
			svc.models[studioEditor.mi]=item;
		}else{
			svc.models.push(item);
		}
		studioEditor=null;
		persist();
		renderStudio();
	}
	function bindStudio(){
		$(document).off('.airagStudio');
		$(document).on('input.airagStudio','[data-studio=url],[data-studio=key]',function(){
			var t=studioTarget(this); if(isNaN(t.si)||!services[t.si]) return;
			if($(this).attr('data-studio')==='url') services[t.si].url=$(this).val();
			else services[t.si].apiKey=$.trim($(this).val()||'');
			persist();
		});
		$(document).on('change.airagStudio blur.airagStudio','[data-studio=url]',function(){
			var t=studioTarget(this); if(isNaN(t.si)||!services[t.si]) return;
			services[t.si].url=fixUrl($(this).val());
			$(this).val(services[t.si].url);
			persist();
		});
		$(document).on('keydown.airagStudio','[data-studio=rename-input]',function(e){
			if(e.key==='Enter'){e.preventDefault();$(this).trigger('blur');}
			if(e.key==='Escape'){studioRename=null;renderStudio();}
		});
		$(document).on('blur.airagStudio','[data-studio=rename-input]',function(){
			var t=studioTarget(this); if(!isNaN(t.si)&&services[t.si]){
				var name=$.trim($(this).val()||'');
				if(name) services[t.si].name=name;
				persist();
			}
			studioRename=null;
			renderStudio();
		});
		$(document).on('keydown.airagStudio','.airag-editor input',function(e){
			if(e.key==='Enter'){e.preventDefault();saveEditor();}
			if(e.key==='Escape'){studioEditor=null;renderStudio();}
		});
		$(document).on('click.airagStudio','[data-studio]',function(e){
			var act=$(this).attr('data-studio');
			if(act==='url'||act==='key'||act==='rename-input') return;
			e.preventDefault(); e.stopPropagation();
			var t=studioTarget(this);
			var svc=services[t.si];
			if(act==='add-svc'){
				services.push({id:'svc'+Date.now(),name:'自定义服务',enabled:1,url:'https://api.siliconflow.cn/v1',apiKey:'',models:[]});
				persist(); renderStudio(); return;
			}
			if(!svc && act!=='editor-cancel') return;
			if(act==='rename'){studioRename=t.si;renderStudio();return;}
			if(act==='del-svc'){
				if(!window.confirm('删除该模型服务？')) return;
				services.splice(t.si,1); persist(); renderStudio(); return;
			}
			if(act==='svc-on'){svc.enabled=svc.enabled?0:1;persist();renderStudio();return;}
			if(act==='eye'){
				var input=$(this).siblings('input');
				input.attr('type',input.attr('type')==='password'?'text':'password');
				return;
			}
			if(act==='add-model'){openEditor(t.si,-1);return;}
			if(act==='edit-model'){openEditor(t.si,t.mi);return;}
			if(act==='editor-cancel'){studioEditor=null;renderStudio();return;}
			if(act==='editor-save'){saveEditor();return;}
			if(act==='del-model'){
				if(isNaN(t.mi)) return;
				svc.models.splice(t.mi,1); persist(); renderStudio(); return;
			}
			if(act==='model-on'){
				if(isNaN(t.mi)||!svc.models[t.mi]) return;
				svc.models[t.mi].enabled=svc.models[t.mi].enabled?0:1; persist(); renderStudio(); return;
			}
			if(act==='fetch'){
				var btn=$(this);
				btn.prop('disabled',true);
				$.ajax({url:'?plugin/aiRag/manage',type:'POST',dataType:'json',timeout:25000,data:{operation:'fetchModels',url:fixUrl(svc.url),apiKey:svc.apiKey||''}})
				.done(function(result){
					if(!(result&&result.code)){Tips.tips(msg(result,'获取失败'),false);return;}
					var incoming=(result.data&&result.data.models)||[];
					var map={};
					_.each(svc.models||[],function(m){map[m.id]=m;});
					_.each(incoming,function(m){
						if(map[m.id]){
							map[m.id].name=m.name||map[m.id].name;
							if(!map[m.id].type) map[m.id].type=m.type;
						}else map[m.id]={id:m.id,name:m.name||shortName(m.id),type:m.type||guessType(m.id),enabled:1,context:m.context||8000,status:''};
					});
					svc.models=_.values(map);
					persist(); renderStudio();
					Tips.tips(msg(result,'已获取'),true);
				})
				.fail(function(xhr){Tips.tips(xhr.statusText||'获取失败',false);})
				.always(function(){btn.prop('disabled',false);});
				return;
			}
			if(act==='test'){
				if(isNaN(t.mi)||!svc.models[t.mi]) return;
				var model=svc.models[t.mi];
				$(this).prop('disabled',true).html(ico('play')+' 检测中');
				$.ajax({url:'?plugin/aiRag/manage',type:'POST',dataType:'json',timeout:25000,data:{operation:'testModel',url:fixUrl(svc.url),apiKey:svc.apiKey||'',modelId:model.id,modelType:model.type}})
				.done(function(result){
					var ok=!!(result&&result.code);
					model.status=ok?'ok':'fail';
					model.error=ok?'':msg(result,'检测失败');
					persist(); renderStudio();
					Tips.tips(msg(result,ok?'检测通过':'检测失败'),ok);
				})
				.fail(function(xhr){
					model.status='fail';
					model.error=xhr.statusText||'检测失败';
					persist(); renderStudio();
					Tips.tips(model.error,false);
				});
			}
		});
	}

	function livePayload(operation, extra){
		var data={operation:operation};
		var map={
			testEs:['elasticUrl','indexName'],
			testMilvus:['milvusUrl','milvusToken','milvusCollection'],
			testEmbed:['embedUrl','embedApiKey','embedModel','embedDim'],
			testLlm:['llmUrl','llmApiKey','llmModel','llmTemperature','llmMaxTokens']
		};
		_.each(map[operation]||[],function(key){
			var v=pick(key);
			if(v!=='') data[key]=v;
		});
		if(extra&&extra.llmModel) data.llmModel=extra.llmModel;
		if(extra&&extra.embedModel) data.embedModel=extra.embedModel;
		return data;
	}
	function actionButtons(){return $('.airag-action');}
	function paint(html){statusBoxes().html(html);bindBusyState();}
	function bindBusyState(){
		actionButtons().each(function(){
			var button=$(this),op=button.data('operation');
			if(String(op).indexOf('test')===0||op==='searchTest')return;
			if(busy){
				if(!button.data('label'))button.data('label',$.trim(button.text()));
				if(op==='run')button.addClass('is-busy').prop('disabled',true).text('处理中…');
				else button.prop('disabled',true);
			}else if(button.data('label')){
				button.removeClass('is-busy').prop('disabled',false).text(button.data('label'));
			}else button.prop('disabled',false).removeClass('is-busy');
		});
	}
	function refreshStatus(options){
		options=options||{};
		var box=statusBoxes(); if(!box.length)return;
		if(pending&&pending.readyState<4){if(!options.fast)pending.abort();else return;}
		if(!options.silent)paint(loadingHtml);
		pending=$.ajax({url:'?plugin/aiRag/status'+(options.fast?'&fast=1':''),dataType:'json',cache:false,timeout:options.fast?8000:15000})
		.done(function(result){
			lastRunning=!!(result&&result.data&&result.data.running);
			if(result&&result.code&&result.data&&result.data.html)paint(result.data.html);
			else if(!options.silent)paint(msg(result,'状态读取失败'));
			if(lastRunning)startPoll();
		})
		.fail(function(xhr,status){if(status==='abort'||options.silent)return;paint('<span style="color:#d9822b">● 状态读取失败：</span>'+(xhr.statusText||'请求超时'));});
	}
	function watchStatus(){
		if(watchTimer)clearInterval(watchTimer);
		watchTimer=setInterval(function(){
			var box=statusBoxes();
			if(!box.length)return;
			if(!(pending&&pending.readyState<4)) refreshStatus();
			clearInterval(watchTimer);
			watchTimer=0;
		},200);
	}
	function startPoll(){if(pollTimer)return;pollTimer=setInterval(function(){refreshStatus({silent:true,fast:true});},1500);}
	function stopPoll(){if(pollTimer){clearInterval(pollTimer);pollTimer=0;}}
	function finishBusy(){busy=false;stopPoll();bindBusyState();}
	function watchUntilDone(){
		startPoll(); lastRunning=1;
		var idle=0,t=setInterval(function(){
			if(lastRunning)idle=0;else idle++;
			if(idle>=4){clearInterval(t);finishBusy();refreshStatus();}
			if(Date.now()-runStartedMs>700000){clearInterval(t);finishBusy();}
		},1500);
	}
	function afterMin(start,ms,fn){setTimeout(fn,Math.max(0,ms-(Date.now()-start)));}
	function run(button,operation){
		var start=Date.now();
		if(String(operation).indexOf('test')===0){
			var note=testMsg(button);
			if(!button.data('label'))button.data('label',$.trim(button.text()));
			button.addClass('is-busy').prop('disabled',true);
			note.removeClass('is-ok is-fail').addClass('is-wait').text('检测中…');
			$.ajax({url:'?plugin/aiRag/manage',type:'POST',dataType:'json',data:livePayload(operation,{llmModel:button.attr('data-model'),embedModel:button.attr('data-model-embed')}),timeout:20000})
			.done(function(result){
				afterMin(start,700,function(){
					var ok=!!(result&&result.code);
					note.removeClass('is-wait').toggleClass('is-ok',ok).toggleClass('is-fail',!ok).text(msg(result,ok?'连接正常':'连接失败'));
					Tips.tips(msg(result,ok?'连接正常':'连接失败'),ok);
					if(ok)refreshStatus({silent:true});
				});
			})
			.fail(function(xhr){
				afterMin(start,700,function(){
					note.removeClass('is-wait is-ok').addClass('is-fail').text(xhr.statusText||'连接失败');
					Tips.tips(xhr.responseText||'连接失败',false);
				});
			})
			.always(function(){
				afterMin(start,700,function(){
					button.removeClass('is-busy').prop('disabled',false).text(button.data('label'));
				});
			});
			return;
		}
		if(operation==='searchTest'){
			var words=$.trim($('.airag-search-input').val()||'');
			if(!words){Tips.tips('请输入检索词',false);return;}
			$.ajax({url:'?plugin/aiRag/manage',type:'POST',dataType:'json',data:{operation:'searchTest',words:words},timeout:20000})
			.done(function(result){
				Tips.tips(msg(result,'完成'),!!(result&&result.code));
				var box=$('.airag-search-result');
				if(box.length&&result&&result.data){
					box.text(JSON.stringify({query:result.data.query,keywordHeavy:result.data.keywordHeavy,hybrid:result.data.hybrid,es:result.data.es,vector:result.data.vector},null,2));
				}
			})
			.fail(function(xhr){Tips.tips(xhr.responseText||'检索失败',false);});
			return;
		}
		busy=true;runStartedMs=Date.now();bindBusyState();startPoll();
		refreshStatus({silent:true,fast:true});
		$.ajax({url:'?plugin/aiRag/manage',type:'POST',dataType:'json',data:{operation:operation},timeout:20000})
		.done(function(result){
			Tips.tips(msg(result,result&&result.code?'已开始处理':'操作失败'),!!(result&&result.code));
			refreshStatus({silent:true,fast:true});
			if(result&&result.code)watchUntilDone();
			else finishBusy();
		})
		.fail(function(xhr){Tips.tips(xhr.responseText||'操作失败',false);finishBusy();refreshStatus({silent:true});});
	}
	function handleAction(el){
		var button=$(el),operation=button.attr('data-operation')||button.data('operation');
		if(!operation||button.prop('disabled')||button.hasClass('is-busy'))return;
		if(operation==='rebuild'&&!window.confirm('这会删除 ES 与 Milvus 中的 AIRAG 索引并重跑提取，确认继续吗？'))return;
		run(button,operation);
	}
	$(document).off('click.aiRag').on('click.aiRag','.airag-action',function(e){
		e.preventDefault();
		e.stopPropagation();
		handleAction(this);
	});
	if(!window.__aiRagAdminCapture){
		window.__aiRagAdminCapture=true;
		document.addEventListener('click',function(e){
			var el=e.target&&e.target.closest?e.target.closest('.airag-action'):null;
			if(!el) return;
			e.preventDefault();
			e.stopPropagation();
			handleAction(el);
		},true);
	}
	window.__aiRagAdmin={test:handleAction};
	Events.bind('plugin.config.formBefore',function(data,options){
		if(_.get(options,'id')!='app-config-aiRag')return;
		var check=function(op,label){return '<div class="airag-check"><a href="javascript:void(0)" class="btn btn-primary btn-sm airag-action" data-operation="'+op+'">'+label+'</a><span class="airag-test-msg"></span></div>';};
		data.modelStudio={type:'html',value:'<div class="airag-studio"></div>',display:'自定义模型服务',desc:'按服务管理地址、密钥和模型。点右上角 + 添加模型，保存本页后生效。'};
		data.esCheck={type:'html',value:check('testEs','检测 Elasticsearch'),display:'ES 检测',desc:'使用本页当前填写的 URL，无需先保存。'};
		data.milvusCheck={type:'html',value:check('testMilvus','检测 Milvus'),display:'Milvus 检测',desc:'使用本页当前填写的地址和 Token，无需先保存。'};
		data.embedCheck={type:'html',value:check('testEmbed','检测向量模型'),display:'向量检测',desc:'使用本页当前填写的 Embedding API、密钥和模型，无需先保存。'};
		data.runStatus={type:'html',value:'<div class="airag-status">'+loadingHtml+'</div><div class="airag-search-box"><input class="airag-search-input" placeholder="检索测试：A2026-001 或 去年采购合同"><button type="button" class="btn btn-default btn-sm airag-action" data-operation="searchTest">混合检索</button><pre class="airag-search-result" style="margin-top:8px;max-height:220px;overflow:auto;font-size:12px;background:#fafafa;padding:8px"></pre></div>',display:'运行情况'};
	});
	Events.bind('plugin.config.formAfter',function(_this){
		if(_this&&_this.formaiRag) airagForm=_this.formaiRag;
		else if(_this&&_this.form) airagForm=_this.form;
		services=loadServices();
		persist();
		renderStudio();
		bindStudio();
		setTimeout(function(){hideLegacy();},400);
		if(statusBoxes().length) watchStatus();
	});
	if(statusBoxes().length)watchStatus();
}
if(window.jQuery) boot();
if(window.kodReady) kodReady.push(boot);
})();
