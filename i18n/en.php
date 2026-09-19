<?php
return array(
	'aiRag.meta.name' => 'AIRAG Hybrid Search',
	'aiRag.meta.title' => 'Elasticsearch keywords plus Milvus semantics, without MariaDB FULLTEXT',
	'aiRag.meta.desc' => 'Reuses elasticFulltext Tika text when present, then chunks into Milvus. MariaDB stores job state only.',
	'aiRag.config.elasticUrlDesc' => 'URL reachable from the disk, e.g. http://elasticsearch:9200.',
	'aiRag.config.milvusUrlDesc' => 'Milvus REST URL, e.g. http://milvus:19530.',
	'aiRag.menu.ask' => 'Ask AI',
	'aiRag.chat.placeholder' => 'Ask anything',
	'aiRag.chat.send' => 'Send',
	'aiRag.chat.empty' => 'Answers are grounded in the selected file or folder',
	'aiRag.meta.titleAdmin' => 'AIRAG Admin',
);
