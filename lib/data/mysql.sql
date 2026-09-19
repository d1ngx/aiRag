CREATE TABLE IF NOT EXISTS `plugin_airag_state` (
  `fileID` bigint(20) unsigned NOT NULL,
  `sourceID` bigint(20) unsigned NOT NULL DEFAULT 0,
  `modifyTime` int(11) unsigned NOT NULL DEFAULT 0,
  `status` tinyint(3) unsigned NOT NULL DEFAULT 0,
  `chunkCount` int(11) unsigned NOT NULL DEFAULT 0,
  `contentHash` char(40) NOT NULL DEFAULT '',
  `error` varchar(1000) NOT NULL DEFAULT '',
  `indexTime` int(11) unsigned NOT NULL DEFAULT 0,
  PRIMARY KEY (`fileID`),
  KEY `status` (`status`),
  KEY `indexTime` (`indexTime`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
