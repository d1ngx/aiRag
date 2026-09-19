<?php
return array(
	'aiRag.meta.name' => 'AIRAG 混合检索',
	'aiRag.meta.title' => 'Elasticsearch 关键词 + Milvus 语义，不走 MariaDB FULLTEXT',
	'aiRag.meta.desc' => 'Apache Tika 只抽一次正文：优先复用 elasticFulltext 的 Elasticsearch 索引，再切片写入 Milvus。MariaDB 只保存任务状态。',
	'aiRag.config.elasticUrlDesc' => '网盘可访问的地址，例如 http://elasticsearch:9200。',
	'aiRag.menu.ask' => 'AI 提问',
	'aiRag.chat.placeholder' => '有问题尽管问',
	'aiRag.chat.send' => '发送',
	'aiRag.chat.empty' => '基于当前文件或目录检索后回答',
	'aiRag.meta.titleAdmin' => 'AIRAG 管理',
);
