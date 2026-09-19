CREATE TABLE IF NOT EXISTS "plugin_airag_state" (
  "fileID" INTEGER NOT NULL PRIMARY KEY,
  "sourceID" INTEGER NOT NULL DEFAULT 0,
  "modifyTime" INTEGER NOT NULL DEFAULT 0,
  "status" INTEGER NOT NULL DEFAULT 0,
  "chunkCount" INTEGER NOT NULL DEFAULT 0,
  "contentHash" TEXT NOT NULL DEFAULT '',
  "error" TEXT NOT NULL DEFAULT '',
  "indexTime" INTEGER NOT NULL DEFAULT 0
);
CREATE INDEX IF NOT EXISTS "idx_airag_status" ON "plugin_airag_state" ("status");
CREATE INDEX IF NOT EXISTS "idx_airag_time" ON "plugin_airag_state" ("indexTime");
