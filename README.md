# AIRAG 混合检索

## 单一正文架构

```text
原始文件 → elasticFulltext / Tika → kodbox-fulltext（唯一 ES 正文）
                                      ├─ 全文搜索 / AIRAG BM25
                                      └─ AIRAG 读取 → 切片 → Embedding → Milvus
MariaDB：两个插件各自维护任务状态，不写 io_file_contents / FULLTEXT
```

AIRAG 依赖 elasticFulltext 提供正文，ES 地址与索引名自动读取该插件配置。
不再创建或写入 `kodbox-airag`，不复制全文，不调用 Tika，也不向对方状态表写入数据。
Milvus 保存向量与检索所需的分片文本（含分片 hash 与资源库元数据），不是第二份 ES 全文索引。

1. **扫描**：遍历文件，应用 AIRAG 格式和大小限制，读取共享正文并核对 `modifyTime`。不写 Milvus。
2. **等待正文**：正文不存在或早于文件修改时间时等待下轮扫描，不自行转换。请检查 elasticFulltext 是否启用、提取失败、格式排除或大小超限。
3. **待向量化**：记录正文哈希与任务状态。
4. **向量化**：再次校验正文版本，清理实体、切片。按分片 `content_hash` 与 Milvus 已有记录比较：相同则跳过 Embedding，变化则只重算，消失的分片删除。单并发批量写入，默认 300 条、间隔 700 ms。全部分片对齐成功才标记完成。
5. **续传**：背压暂停时已写入分片可通过 hash 复用；不再根据本地断点计数推定分片存在。更换模型、地址或维度会使旧模型 hash 失效，任务按背压限制重新向量化。

### Milvus 集合

部署镜像为 `milvusdb/milvus:v3.0.1`。19530 / 9091 只绑本机；Kodbox 容器走 Docker 网络 `http://milvus:19530`。

集合 `kodbox_airag_chunk`（单集合）：

```text
chunk_id          VarChar PK   fileID:chunkIndex
file_id           Int64        文件过滤 / 删除
source_id         Int64        资源库条目
parent_id         Int64        直接父目录
chunk_index       Int64
text              VarChar      分片原文，检索引用
name / ext
content_hash      VarChar      模型指纹 + 分片正文的 sha1，增量向量
modify_time       Int64
vector            FloatVector  AUTOINDEX + COSINE
```

不启用 Milvus BM25、不分资源库多集合、不默认 mmap / IVF_FLAT。标量索引只建在 `file_id`、`source_id`、`parent_id`、`ext`、`modify_time`。资源库的类型/目录过滤会下推到 ES 与 Milvus。

### 生命周期与运维

- elasticFulltext 独占正文写入权。停用后已有正文仍可读取，新文件/更新文件等待恢复；故障不会自动切换到另一套索引。
- AIRAG 重建/重置只处理自己的 Milvus 集合、状态和断点，不删除共享正文；移除资源同样不删除共享正文。
- elasticFulltext 重建期间全文与关键词检索会受影响。提取规则变化后，先完成正文重建，再重建 AIRAG 向量。
- 两插件的重任务及重建通过同一文件锁互斥。此锁适用于共享锁文件的部署；多主机不同文件系统需分布式锁。
- 两插件独立应用格式和大小限制；AIRAG 接受而 elasticFulltext 排除的文件会等待正文，需协调两边策略。
- 原始文件不会被删除。旧 `kodbox-airag` 为废弃索引，确认共享配置后可清除，无需双向迁移。

### 界面计数含义

- **网盘文件**：`io_file` 物理文件总数，含图片、视频等非文档
- **可索引 / 已检查**：符合 AIRAG 允许扩展名的文档数 / 游标已经核对过的可索引文档。扫描进度不再用整盘 586 当分母
- **已登记**：AIRAG 状态表中已有共享正文（待向量或已向量）
- **ES 正文**：共享索引 `kodbox-fulltext` 的文档数；与可索引、已登记应对齐。不是扫描游标 `fileID`
- **待向量**：已提取正文、尚未写入 Milvus
- **已完成**：Milvus 中已有切片
- elasticFulltext **已入库**：该插件成功抽出正文的文档数，不等于网盘文件总数

这样可以避开现场已经验证过的卡顿：

> MariaDB FULLTEXT 与 Milvus 向量/BM25 **同时**维护索引，叠加任务重入，把 Buffer Pool、NVMe 和 MinIO 打满。

## 这和官方 RAG / docSearch 的差别

| | 官方常见路径 | 本插件 |
| --- | --- | --- |
| 正文 | `INSERT io_file_contents` → MariaDB ngram FULLTEXT | 只进 Elasticsearch |
| 关键词 | MATCH AGAINST / 或 Milvus BM25 | Elasticsearch BM25 |
| 语义 | Milvus dense | Milvus dense（不再重复开 BM25） |
| 调度 | 提取与向量可能同一分钟叠跑 | **一轮只做一件事**，文件锁禁止重入 |
| 正文编码 | 可能留下 `&#24207;` 实体 | 入库前 `html_entity_decode` |

勾选网盘「文件内容」搜索时，插件会：

1. 拦截 `docSearch.fileContentMatch`，避免再走 `MATCH (content) AGAINST`
2. 用 RRF 融合 ES 与 Milvus
3. 查询改写：`去年` → 当前年-1，提高“去年采购合同”这类问法的命中

编号类查询自动提高 ES 权重；自然语言自动提高向量权重。

## 调度策略（针对卡顿，1.6.1）

1. **互斥**：aiRag 自动/手动更新、重试与 elasticFulltext 任务共用 `kod-heavy-index.lock`。锁冲突直接跳过，不排队；锁跟随进程释放，不以固定 TTL 猜测任务完成。docSearch 不作代码改动，也不参与该锁。
2. **分轮**：默认本轮只扫描共享正文，下一轮优先处理待向量文件；两个阶段和每批写入均检查背压。
3. **限流**：Milvus 默认每批 300 条、间隔 700ms、单并发；允许 50–500 条。向量缓存达到批次上限即写入释放，压力恢复后以 Milvus 强一致查询确认已写分片，跳过其 Embedding。进度只计已确认写入的分片。
4. **滞回**：脏页占总页数达到 40% 暂停，降至 28% 恢复；`wait_free` 检查采样增量，增长后冷却 30 秒，不因历史累计值永久暂停。checkpoint 达到 60% 且缓存池仍有压力时暂停，达到 85% 无条件暂停；数据库空闲、脏页已回落且空闲页充足时允许继续，避免 checkpoint age 不推进造成永久暂停。仅 free pages=0 不单独判断为压力。
5. **任务预算**：自动任务仍按原有每分钟调度，文件之间检查时间预算；单个外部请求无法被该预算强制中止，运行期间持有互斥锁。
6. **正文**：aiRag 正文保留在 ES，MariaDB 只保存索引状态；aiRag 在切片前还原 HTML 实体。不会重写 docSearch 的历史正文，也不会删除 MariaDB FULLTEXT 索引。

MariaDB 指标定义参考：[InnoDB status variables](https://mariadb.com/docs/server/ha-and-performance/optimization-and-tuning/system-variables/innodb-status-variables)。状态读取失败时暂停新索引任务并显示原因。SQLite 不采集 InnoDB 指标。

### 部署范围与主机背压

本次代码改动限定在 `plugins/aiRag` 与 `plugins/elasticFulltext`。docSearch 保持原样，因此无法从代码层强制阻止它与 aiRag 同时运行；后台会提示风险，部署时应关闭 docSearch 全文入库或将其与 RAG 错峰。aiRag 与 elasticFulltext 的锁要求各 PHP 进程共用同一个支持 flock 的 `TEMP_PATH`；多主机独立临时目录不提供集群互斥。

PHP 无法可靠读取另一容器的 MariaDB RSS 或宿主机 NVMe 利用率。可由现有监控采集器原子写入 `TEMP_PATH/airag-host-pressure.json`：

```json
{"time": 1790000000, "mariadbMemoryGiB": 12.6, "nvmeUtil": 85, "nvmeBusySeconds": 35}
```

`time` 为当前 Unix 秒时间戳，样本有效期 60 秒。MariaDB 内存 >=12.5 GiB，或 NVMe 利用率 >=80% 且持续 >=30 秒时暂停，并冷却 30 秒。缺少/过期样本不会伪造主机指标，数据库背压仍有效。本次未安装主机采集服务，也未修改数据库内存参数。

### 验证

- `php plugins/aiRag/tests/corpus-share.php`：共享正文版本是否过期、空白内容不可复用。
- `php plugins/aiRag/tests/regression.php`：独立背压、锁、文本测试，不连接业务数据库。
- `php plugins/aiRag/tests/milvus-plan.php`：分片 hash 增量计划、字符串主键、过滤表达式。
- `php plugins/aiRag/tests/vector-resume.php`：中断后续传，未变化分片不重复 Embedding。
- `NODE_PATH=/path/to/node_modules node plugins/aiRag/tests/ui.cjs`：Playwright 模拟接口，覆盖桌面/手机布局、历史搜索、快捷提问及中文输入法。

## 安装

目录名必须是 `plugins/aiRag`，并启用 `elasticFulltext` 作为唯一正文来源。

测试栈（ES + Milvus standalone）：

```bash
# 在 Kodbox compose 目录
docker compose -f compose.yml -f compose.airag.yml up -d
```

镜像已换成当前能拉取的地址（不要用 Docker Hub 的 `minio/minio`，该仓库已下线）：

- Elasticsearch：`docker.elastic.co/elasticsearch/elasticsearch:8.19.4`
- MinIO：`quay.io/minio/minio:RELEASE.2025-04-22T22-12-26Z`
- etcd：`swr.cn-north-4.myhuaweicloud.com/ddn-k8s/quay.io/coreos/etcd:v3.5.18`
- Milvus：`milvusdb/milvus:v3.0.1`

Kodbox 容器需能访问：

- `http://elasticsearch:9200`
- `http://milvus:19530`

后台启用插件后做「ES / Milvus / 向量」检测。Embedding URL 留空时使用本地哈希向量（只适合连通性测试）。生产请填写 OpenAI 兼容接口，例如硅基流动 `bge-m3`，维度 1024。

## 建议同时关闭

- 官方 **docSearch** 的全文入库（它会写 `io_file_contents` + FULLTEXT）
- **elasticFulltext** 与本插件共用 Tika 结果，但仍共用 `kod-heavy-index.lock`，避免同一分钟同时打 ES/磁盘

如果 Kodbox 原生文件名搜索仍需要 MariaDB FULLTEXT，可以保留 `io_source.name` 的 FULLTEXT，但不要再给 RAG 正文表加一份。

## 卸载

禁用插件会停计划任务，不会自动删 ES / Milvus 数据。

共享正文边界测试：`php plugins/aiRag/tests/shared-corpus.php`。

### 1.6.5 一致性修复

- HTTP 成功但 Milvus 业务 code 非 0、缺失 code、索引创建失败均显式报错，不再推进完成状态。已有集合先核对字段和向量维度。
- 旧文本 hash 在首次重扫时按新模型指纹重新计算；不会删除共享 ES 正文。只有目录、文件名或修改时间改变时，对已有向量分批更新元数据。消失分片在新内容写入成功后再删除。
- 空的限定范围返回空结果；目录遍历分页读取所有文件。混合检索按相同文件范围过滤，ES 与 Milvus 均应用时间条件。
- 保留同文件多个命中分片，按真实分片编号读取引用；关键词摘要单独标记。UTF-8 文本按 schema 字节上限安全截断。
- `tests/vector-resume.php` 覆盖业务失败、模型变化、元数据复用和断点；`tests/retrieval-boundaries.php` 覆盖空范围、650 文件分页和多分片。

### 1.6.6 流式体验优化

- 模型的小粒度 token 在服务端短暂合并后再发送，浏览器按内容长度自适应到约 7–20 帧/秒更新，取消固定每 18ms 播放一个字符造成的积压。
- 深度思考文本只追加新增部分，流式阶段保持固定高度并跟随最新内容；正文开始后自动收起，可随时展开查看完整思考过程。
- 完成事件不再重复传输整篇正文和思考内容，长回答结束时不会因为再次解析大块 JSON 而停顿。
- 静态资源 URL 自动使用文件修改时间作为版本，升级后浏览器会加载最新的脚本和样式。

### 1.6.7 回答与引用体验优化

- 回答流式阶段在单一文本节点中只追加新文本，不再对不断增长的整篇 Markdown 重复解析和替换 DOM；结束后一次性完成 Markdown、表格与引用渲染，长回答也能保持连续滚动且不会持续堆积 DOM 节点。
- 对话输入附件和用户消息附件使用 KodBox `fileThumb` 封面服务，暂未生成封面或不支持预览的格式自动回退到扩展名图标。
- “引用资料”收成紧凑单行入口；展开后按文件列出分片，文件名只显示一次，并限制摘要为两行。

### 1.6.8 输入附件、统计与流式优化

### 1.6.9 正文版本协同

- 与 elasticFulltext 1.5.0 使用 `extractVersion` 握手；最大提取字符或提取规则变化后，只消费版本匹配的正文。
- 正文尚未升级时文件保持等待状态，由后续扫描自动继续，避免旧正文或截断正文写入 Milvus。

- 从网盘加入对话时完整保留 `fileThumb`、大小和扩展名；封面只出现在输入附件和用户消息中，回答引用使用紧凑文件图标，避免引用区抢占正文空间。
- 回答底部显示时间、token 总量和总用时；悬停或键盘聚焦后显示模型服务、prompt/output/cache token、首字耗时、生成速度与创建时间。
- 对话页使用与 KodBox 主界面相同的字体栈，减少插件页与网盘界面的字重和行高差异。
- 浏览器按动画帧合并 token 更新，并移除每个 token 对 `scrollHeight` 的同步读取；服务端以 72 字节或约 24ms 为一批推送，在响应及时性和渲染负担之间保持稳定。
