# Pramnos Framework - Roadmap v1.2

Αυτό το Roadmap περιγράφει τον προγραμματισμό και τα στάδια υλοποίησης για την επερχόμενη έκδοση **v1.2** του Pramnos Framework.

---

## 📜 Αρχή Σχεδιασμού: Backward Compatibility

> **Ένα project που αναβαθμίζει το framework στην έκδοση 1.2 δεν χρειάζεται να κάνει την παραμικρή αλλαγή στον κώδικά του.**

Αυτή δεν είναι απλώς επιθυμία — είναι **δεσμευτικός κανόνας σχεδιασμού** που επηρεάζει κάθε απόφαση υλοποίησης. Κάθε νέο feature, refactor ή αρχιτεκτονική αλλαγή πρέπει να ικανοποιεί ταυτόχρονα και τους δύο παρακάτω κανόνες πριν γίνει merge:

### Κανόνες BC

1. **Κανένα υπάρχον public API δεν αφαιρείται ή τροποποιείται.**
   Κλάσεις, μέθοδοι, properties, και constants που είναι ήδη διαθέσιμες παραμένουν, με τους ίδιους τύπους ορισμάτων και τα ίδια return types.

2. **Κανένα υπάρχον configuration key δεν μετονομάζεται ή αφαιρείται.**
   Οτιδήποτε διαβάζεται από settings, `.env`, ή config arrays πρέπει να λειτουργεί αμετάβλητο.

3. **Τα νέα features είναι πάντα additive — ποτέ replacement.**
   Νέες μέθοδοι, νέες κλάσεις, νέα interfaces προστίθενται δίπλα στα υπάρχοντα. Το υπάρχον σύστημα εξακολουθεί να τρέχει ανεξάρτητα.

4. **Deprecated ≠ Removed.**
   Μέθοδοι που αντικαθιστάνται από νεότερες χαρακτηρίζονται `@deprecated` και παράγουν `E_USER_DEPRECATED` notice, αλλά **δεν αφαιρούνται** σε αυτό το release cycle.

5. **Behavior-level BC: ίδια είσοδος → ίδια έξοδος.**
   Αν υπάρχουσα λογική εσωτερικά αναδομηθεί (refactor), το παρατηρήσιμο αποτέλεσμα πρέπει να παραμένει πανομοιότυπο.

### Γνωστή Εξαίρεση

> ⚠️ **PHP 8.1 Minimum (Φάση 4):** Η ανύψωση του minimum PHP version είναι **environment-level BC break**, όχι API-level. Ένα project σε PHP 7.4/8.0 δεν θα μπορεί να αναβαθμίσει χωρίς να αναβαθμίσει πρώτα τον PHP runtime. Αυτή είναι η μοναδική συνειδητή εξαίρεση στον κανόνα, και απαιτεί ξεχωριστή ανακοίνωση στα release notes.

---

## 🏁 Release Criteria (Quality Gates)

> **Το v1.2 δεν κυκλοφορεί αν δεν ικανοποιούνται ταυτόχρονα ΟΛΟΙ οι παρακάτω στόχοι.**

### Code Coverage

| Κατηγορία | Ελάχιστο | Στόχος |
|---|---|---|
| Νέα features (QueryBuilder, ORM, Middleware, κλπ.) | **90%** | **100%** |
| Υπάρχον codebase (`src/Pramnos/`) | **80%** | — |
| Security-critical paths (Auth, CSRF, Session, JWT) | **95%** | **100%** |

Το coverage μετράται με Xdebug μέσω του Docker container και παράγεται HTML report. Η μέτρηση γίνεται **ξεχωριστά ανά database backend** (βλ. παρακάτω).

### Database Test Matrix

Το Docker environment περιλαμβάνει επίτηδες και τις τρεις βάσεις (`db` → MySQL 8.0, `timescaledb` → PostgreSQL 14 + TimescaleDB). Κάθε test που αφορά database logic **εκτελείται υποχρεωτικά και στις τρεις**.

> **Σημείωση υποδομής:** Και οι δύο Docker databases υπάρχουν ήδη αλλά είναι αδειες — εκτελούν unit tests χωρίς schema. Μόλις ολοκληρωθεί το Migration System και τρέξουν τα framework migrations, θα είναι πλήρως λειτουργικές για integration tests × 3 databases. Στο interim, τα database integration tests εκτελούνται μέσω του Urbanwater test suite (PostgreSQL + TimescaleDB).

**Τα migration tests είναι πάντα integration tests.** Δεν αρκεί να επαληθεύεται ότι ο κώδικας εκτελείται χωρίς exception — κάθε test ελέγχει την πραγματική κατάσταση της βάσης (ύπαρξη πίνακα, στηλών, constraints, indexes) μέσω queries στο `information_schema` ή αντίστοιχο catalog. Το ίδιο ισχύει για rollback: επαληθεύεται ότι ο πίνακας/η στήλη **όντως αφαιρέθηκε**.

| Test Suite | MySQL | PostgreSQL | TimescaleDB |
|---|:---:|:---:|:---:|
| QueryBuilder / DML (incl. aggregates, shortcuts, locking) | ✅ | ✅ | ✅ |
| Schema Builder / DDL | ✅ | ✅ | ✅ |
| TimescaleDB Extensions | — | — | ✅ |
| TimescaleDB Fallback (`ifCapable`, `time_bucket` dialect, retention/aggregate/compression fallbacks) | ✅ | ✅ | ✅ |
| Model (CRUD, relations) | ✅ | ✅ | ✅ |
| DataTable (query generation) | ✅ | ✅ | ✅ |
| Migration system | ✅ | ✅ | ✅ |
| Adjacencylist | ✅ | ✅ | ✅ |
| Auth / User queries | ✅ | ✅ | ✅ |
| Logs insertion/query | ✅ | ✅ | ✅ |

Ένα test που περνά σε MySQL αλλά όχι σε PostgreSQL είναι **αποτυχία**. Δεν υπάρχουν εξαιρέσεις.

> **TimescaleDB Fallback Tests:** Για κάθε migration που χρησιμοποιεί `ifCapable()` / `createHypertable()` / `addRetentionPolicy()` / `timeBucket()` κλπ., πρέπει να υπάρχουν tests που επαληθεύουν ότι: (α) στο TimescaleDB εκτελείται το native feature, (β) σε MySQL/plain PG ο πίνακας δημιουργείται κανονικά και το query επιστρέφει αποτελέσματα — χωρίς exception. Οι assertions διαφέρουν ανά backend (π.χ. `assertIsHypertable` μόνο για TimescaleDB), αλλά κάθε test εκτελείται και στα τρία.

### Documentation

- Κάθε νέα public κλάση και μέθοδος φέρει **πλήρες docblock** (description, `@param`, `@return`, `@throws`).
- Κάθε non-obvious εσωτερική λογική φέρει inline comment που εξηγεί το *γιατί*, όχι το *τι*.
- Όλα τα νέα features τεκμηριώνονται στο αρχείο **`docs/1.2-new-features.md`** με:
  - Σύντομη περιγραφή του feature και του προβλήματος που λύνει
  - Παράδειγμα χρήσης από μηδέν (getting started snippet)
  - Πλήρη API reference με παραδείγματα για κάθε public method
  - Σημειώσεις BC: τι αντικαθιστά (αν αντικαθιστά κάτι) και πώς μεταφέρεται υπάρχουσα χρήση

---

## 🎯 Φάση 1: Core Database Architecture (Foundation)
*Ενίσχυση του συστήματος διαχείρισης δεδομένων. Υποστήριξη MySQL, PostgreSQL και TimescaleDB σε όλα τα επίπεδα.*

- [x] **Read/Write Replicas Support:** Αναβάθμιση της `Pramnos\Database\Database` class ώστε να μπορεί να συνδέεται σε πολλαπλές βάσεις (π.χ. μία για writes, άλλες για reads).
- [x] **Connection Health & Auto-reconnect:** Αυτόματη ανίχνευση dropped connection και διαφανής επανασύνδεση χωρίς επανεκκίνηση του worker ή της εφαρμογής.

### 🔨 Query Builder & ORM (Unified Multi-Dialect Layer)
*Ενιαίο σύστημα που λειτουργεί ως fluent query builder, ως schema/DDL builder, και ως πλήρες ORM — χωρίς να καταργηθούν οι υπάρχουσες μέθοδοι της `Database` class.*

> **BC Strategy:** Η `Database` class και οι μέθοδοί της (`prepareQuery()`, `query()`, κλπ.) παραμένουν αμετάβλητες. Το νέο `QueryBuilder` λαμβάνει instance της `Database` ως dependency και εκτελεί queries μέσω αυτής. Το υπάρχον `Model` αποκτά νέα opt-in capabilities (relationships, scopes, casting) μέσω traits — ο υπάρχων κώδικας που extend-άρει το `Model` δεν χρειάζεται αλλαγές.

- [x] **DML Query Builder:** Fluent interface για κλασικές DML πράξεις:
  - [x] `SELECT` με aliases, `DISTINCT`, column expressions
  - [x] `INSERT` (βασικό), `RETURNING` clause (PostgreSQL)
  - [x] `INSERT IGNORE` (MySQL), `INSERT ... ON CONFLICT` / upsert (PostgreSQL/TimescaleDB)
  - [x] `UPDATE` με conditional logic, `RETURNING` clause (PostgreSQL)
  - [x] `DELETE`, `RETURNING` clause (PostgreSQL)
  - [x] `TRUNCATE`
  - [x] JOINs: `INNER`, `LEFT` — `join($table, ..., $type)` δέχεται οποιοδήποτε type string (`right`, `full`, `cross`) αλλά δεν υπάρχουν convenience methods
  - [x] `rightJoin($table, $first, $op, $second)` — convenience method για RIGHT JOIN
  - [x] `crossJoin($table)` — convenience method για CROSS JOIN
  - [x] Conditions: `where()`, `orWhere()`, `whereIn()`, `whereRaw()`, nested where via Closure
  - [x] `whereNull()` / `whereNotNull()`, `whereBetween()` / `whereNotBetween()` (και or* παραλλαγές)
  - [x] `whereExists(Closure $callback)` / `whereNotExists()` — `WHERE EXISTS (SELECT 1 FROM …)` subquery condition
  - [x] `whereDate/Year/Month/Day/Time($col, $op, $value)` — date-part conditions χωρίς raw SQL
  - [x] `groupBy()`, `groupByRaw()`, `having()`, `havingRaw()`, `orderBy()`, `orderByRaw()`, `limit()`, `offset()`
  - [x] `latest(string $col = 'created_at'): static` — sugar για `orderBy($col, 'desc')`
  - [x] `oldest(string $col = 'created_at'): static` — sugar για `orderBy($col, 'asc')`
  - [x] `forPage(int $page, int $perPage): static` — sugar για `limit($perPage)->offset(($page-1)*$perPage)`
  - [x] `clearOrderingAndPaging()` — αφαιρεί ORDER BY/LIMIT/OFFSET
  - [x] `UNION` / `UNION ALL`
  - [x] Common Table Expressions (CTEs) — `with()`, `withRecursive()`; MySQL 8.0+ / PostgreSQL / TimescaleDB
  - [x] Subqueries ως SELECT columns ή FROM πηγή
  - [x] Window functions (`OVER`, `PARTITION BY`, `RANK`, `ROW_NUMBER`) — PostgreSQL/TimescaleDB
  - [x] Raw expressions με `raw()` / `Expression` class για dialect-specific syntax
  - [x] `when(bool|Closure $condition, Closure $callback, ?Closure $default = null): static` — conditional query building; αν η συνθήκη είναι false και δεν υπάρχει `$default`, ο builder επιστρέφεται αναλλοίωτος. Χρήσιμο για φόρμες φίλτρων
  - [x] `lockForUpdate(): static` — προσθέτει `FOR UPDATE` (MySQL/PG); φρένο για pessimistic locking
  - [x] `sharedLock(): static` — προσθέτει `LOCK IN SHARE MODE` (MySQL) / `FOR SHARE` (PG)

- **Execution shortcuts & aggregates** *(standard ORM API — παράλληλο pattern με το `count()`)*:
  - [x] `get()` — εκτελεί και επιστρέφει `Result`
  - [x] `first()` — `LIMIT 1`, επιστρέφει `Result`
  - [x] `count(): int` — `SELECT COUNT(*) AS aggregate`, strips ORDER BY/LIMIT, δεν μεταλλάσσει το builder
  - [x] `sum(string $col): float` — `SELECT SUM(col) AS aggregate`
  - [x] `avg(string $col): float` — `SELECT AVG(col) AS aggregate`
  - [x] `min(string $col): mixed` — `SELECT MIN(col) AS aggregate`
  - [x] `max(string $col): mixed` — `SELECT MAX(col) AS aggregate`
  - [x] `exists(): bool` — `SELECT EXISTS(SELECT 1 FROM … WHERE …)`; πιο αποδοτικό από `count() > 0`
  - [x] `doesntExist(): bool` — `!exists()`
  - [x] `value(string $col): mixed` — εκτελεί με `LIMIT 1`, επιστρέφει μία τιμή ή `null`
  - [x] `pluck(string $col): array` — επιστρέφει flat array με τις τιμές μίας στήλης από όλες τις γραμμές
  - [x] `increment(string $col, int|float $step = 1): int` — `UPDATE … SET col = col + step WHERE …`; επιστρέφει affected rows
  - [x] `decrement(string $col, int|float $step = 1): int` — `UPDATE … SET col = col - step WHERE …`
  - [x] `chunk(int $size, Closure $callback): void` — επεξεργασία μεγάλων result sets σε batches χωρίς φόρτωση όλων στη μνήμη; σταματάει αν το callback επιστρέψει `false`

- [x] **QueryBuilder Grammar/Adapter Pattern:**
  Αρχιτεκτονική βελτίωση που διαχωρίζει την **κατασκευή query** (QB) από τη **μετάφρασή του σε SQL** (Grammar). Αντί για `if ($this->db->type == 'postgresql')` checks σκορπισμένα στον κώδικα, κάθε dialect έχει το δικό του Grammar class.

  ```
  QueryBuilder (dialect-agnostic — χτίζει AST)
      └─ Grammar (interface)
           ├─ MySQLGrammar       (backtick quoting, % params, INSERT IGNORE, ON DUPLICATE KEY)
           ├─ PostgreSQLGrammar  (double-quote quoting, ON CONFLICT, RETURNING, ::text ILIKE cast)
           └─ TimescaleDBGrammar (extends PostgreSQL + time_bucket)
  ```

  - [x] `GrammarInterface` — `compileSelect`, `compileInsert`, `compileUpdate`, `compileDelete`, `compileWheres`, `compileHavings`, `compileTruncate`, `compileTimeBucket`
  - [x] `Grammar` (abstract base) — dialect-neutral DML compilation with template-method hooks
  - [x] `MySQLGrammar` — backtick quoting, `INSERT IGNORE`, `ON DUPLICATE KEY UPDATE`
  - [x] `PostgreSQLGrammar` — double-quote quoting, `ON CONFLICT`, `RETURNING`, ILIKE `::text` cast
  - [x] `TimescaleDBGrammar` (extends PostgreSQL) — native `time_bucket()`
  - [x] `Database` injects το κατάλληλο Grammar στον QueryBuilder κατά τη δημιουργία του
  - [x] `time_bucket()` dialect translation: TimescaleDB → `time_bucket()`, plain PG → `DATE_TRUNC()` / epoch arithmetic, MySQL → `FROM_UNIXTIME()` / `DATE_FORMAT()`

  > **BC Strategy:** Εντελώς εσωτερική αλλαγή. Το public API του QueryBuilder και το εξωτερικό συμπεριφοριακό αποτέλεσμα παραμένουν πανομοιότυπα. Υπάρχοντα `whereRaw()` / `orderByRaw()` calls λειτουργούν αμετάβλητα.

- [x] **DDL / Schema Builder:** Fluent interface για ορισμό και τροποποίηση schema:
  - [x] `createTable()`, `alterTable()`, `dropTable()`, `renameTable()`
  - [x] Column types με αυτόματη μετατροπή ανά dialect (`TEXT`, `JSONB`, `TIMESTAMPTZ`, `BIGSERIAL`, κλπ.)
  - [x] `dropColumn()`, `renameColumn()`, `modifyColumn()` — MySQL: MODIFY COLUMN; PostgreSQL: separate ALTER COLUMN TYPE / SET NOT NULL / SET DEFAULT
  - [x] Primary keys, foreign keys, unique constraints, check constraints
  - [x] Indexes: `createIndex()`, `createUniqueIndex()`, `dropIndex()`
  - [x] **Views:** `createView()`, `createOrReplaceView()`, `dropView()`
  - [x] **Materialized Views (PostgreSQL/TimescaleDB):** `createMaterializedView()`, `refreshMaterializedView()`, `dropMaterializedView()`
  - [x] **Triggers (MySQL + PostgreSQL):** `createTrigger()`, `dropTrigger()` — MySQL: `CREATE TRIGGER … FOR EACH ROW`; PG: `CREATE OR REPLACE TRIGGER … EXECUTE FUNCTION fn()`
  - [x] Sequences (PostgreSQL): `createSequence()`, `dropSequence()`, `nextVal()`, `setVal()` — MySQL: silent no-op (returns 0)

- [x] **TimescaleDB Extension Builder:** Native support για τα hypertable και time-series χαρακτηριστικά:
  - [x] `createHypertable($table, $timeColumn)` — στο TimescaleDB εκτελεί `SELECT create_hypertable()`; σε άλλα backends: silent no-op
  - [x] `addSpaceDimension($table, $column, $partitions)` — TimescaleDB native; silent no-op αλλού
  - [x] `createContinuousAggregate($name, $query, $interval)` — TimescaleDB native / PG MATERIALIZED VIEW / MySQL VIEW
  - [x] `addRetentionPolicy($table, $interval, $timeColumn='created_at')` — TimescaleDB native; non-TimescaleDB: inserts `retention` row into `framework_policies` via QB; Policy Engine daemon executes DELETE job
  - [x] `addCompressionPolicy($table, $interval)` / `enableCompression()` — TimescaleDB native; silent no-op αλλού
  - [x] `QueryBuilder::timeBucket($interval, $column)` — dialect-transparent expression helper
  - [x] `addContinuousAggregatePolicy($view, $startOffset, $endOffset, $scheduleInterval)` — TimescaleDB native; non-TimescaleDB: inserts `aggregate_refresh` row into `framework_policies` via QB
  - [x] Πρόσβαση στα TimescaleDB informational views (`timescaledb_information.*`) — `SchemaBuilder::getHypertables()`, `isHypertable()`, `getContinuousAggregates()`, `getHypertableDimensions()`, `getTimescaleJobs()`, `getChunks()` — returns `[]`/`false` on non-TimescaleDB; 10 integration tests in `SchemaBuilderTimescaleDBInfoTest`

- [x] **`DatabaseCapabilities` — Runtime Detection & Graceful Fallback:**
  - [x] `has(string $capability): bool` — runtime detection; WeakMap static cache (auto-cleans on GC)
  - [x] `isMySQL()` / `isPostgreSQL()` / `hasTimescaleDB()` / `hasMaterializedViews()` / `hasEnums()`
  - [x] `ifCapable(string $cap, callable $ifTrue, ?callable $ifFalse)` — conditional execution
  - [x] Constants: `ENGINE_MYSQL`, `ENGINE_POSTGRESQL`, `TIMESCALEDB`, `JSONB`, `MATERIALIZED_VIEWS`, `ENUMS`, `FEATURE_JSON`, `FEATURE_FULLTEXT`, `FEATURE_SPATIAL`
  - [x] Backport Spec Section 14.1 alignment complete (constants, static cache, new capabilities)
  - [x] `SchemaBuilder::ifCapable()` — conditional DDL execution (passes SchemaBuilder, not Database)
  - [x] **Compression/Hypertable fallback:** Silent no-op on non-TimescaleDB backends ✓
  - [x] **Retention policy fallback:** `addRetentionPolicy()` inserts `retention` policy into `framework_policies` on non-TimescaleDB; Policy Engine daemon executes the DELETE job
  - [x] **Continuous aggregate fallback Policy:** `addContinuousAggregatePolicy()` inserts `aggregate_refresh` policy into `framework_policies` on non-TimescaleDB
  - Αναλυτική προδιαγραφή: βλ. `UrbanWater-Backport-Features.md` Section 14

- [x] **Policy Engine Daemon — TimescaleDB Fallback Simulator:** Σύστημα που προσομοιώνει τις χρονοδιαγραμμένες λειτουργίες του TimescaleDB (retention policies, continuous aggregate refresh, compression) σε MySQL και plain PostgreSQL, όπου δεν υπάρχει native scheduler.

  **`framework_policies` table** *(scope: `core`)*:
  ```sql
  policyid     SERIAL        PRIMARY KEY
  policy_type  VARCHAR(50)   -- 'retention' | 'aggregate_refresh' | 'compression' | 'cache_rebuild'
  target       VARCHAR(255)  -- όνομα πίνακα ή view
  config       JSON          -- {interval, time_column, keep_last, ...} — backend-specific
  enabled      BOOLEAN       DEFAULT true
  last_run     TIMESTAMP     NULL
  next_run     TIMESTAMP     NULL
  last_result  TEXT          NULL
  last_error   TEXT          NULL
  created_at   TIMESTAMP     DEFAULT NOW()
  ```

  **Ροή:** Το `fallback_fn` μέσα στο `ifCapable()` καταχωρεί αυτόματα εγγραφή στο `framework_policies`. Το Policy Engine daemon διαβάζει τον πίνακα και εκτελεί περιοδικά:
  - `retention` → `DELETE FROM target WHERE time_column < NOW() - INTERVAL config.interval`
  - `aggregate_refresh` → εκ νέου φόρτωση materialized view ή cache table
  - `compression` → no-op ή custom compression ανά backend
  - Σε TimescaleDB-enabled περιβάλλον: native policies ενεργοποιούνται — ο Policy Engine δεν αναλαμβάνει αυτές τις εργασίες
  - Εκτίθεται ως CLI command: `service:policy-engine` (extends `CommandBase`, τρέχει ως daemon ή cron job)

- [x] ~~**Full ORM Layer**~~ → *Υλοποιήθηκε στη Φάση 9 ως `OrmModel` (βλ. τέλος).*

### 🔁 Internal Framework Migration to QueryBuilder
*Εσωτερική αναδιαγραφή των framework classes που περιέχουν raw SQL, χρησιμοποιώντας το νέο QueryBuilder ως κινητήρα. Το εξωτερικό API κάθε κλάσης παραμένει **πανομοιότυπο**.*

> ⚠️ **Απαραίτητη Προϋπόθεση:** Αυτό το βήμα εκτελείται **μόνο αφού** ολοκληρωθούν τα Characterization Tests της Φάσης 5. Τα tests αποτελούν τη μοναδική απόδειξη ότι η εσωτερική αλλαγή δεν έχει επηρεάσει τη συμπεριφορά.

- [x] **`Pramnos\Application\Model`** — Όλα τα internal SQL calls (CRUD, column introspection, caching hooks) ξαναγράφονται μέσω QueryBuilder.
- [x] **`Pramnos\Html\DataTable`** — Τα dynamic query building, filtering, sorting και pagination calls αντικαθίστανται από QueryBuilder expressions (μέσω του `Datasource` refactor).
- [x] **`Pramnos\Database\Migration`** — Το DDL execution εσωτερικά χρησιμοποιεί τον Schema Builder. Η `executeQueries()` εκτελεί SQL που παράγεται από SchemaBuilder μέσω Grammar objects· δεν υπάρχει hand-written raw SQL. Τα migration files που χρησιμοποιούν `$db->query()` το κάνουν για PL/pgSQL functions και `CREATE SCHEMA` DDL που δεν εκφράζεται από τον SchemaBuilder — acceptable exception.
- [x] **`Pramnos\Database\Adjacencylist`** — Τα hierarchical queries (parent/children traversal) ξαναγράφονται με CTEs ή recursive QueryBuilder expressions.
- [x] **`Pramnos\Auth\Auth`** — Zero direct DB queries: η `Auth` class delegates τα πάντα σε addons (`onAuth`, `onAuthCheck`, `onLogout`). Επιβεβαιώνεται από `AuthCharacterizationTest`. *(Migration N/A — zero DB queries)*
- [x] **`Pramnos\User\*`** — Όλες οι user management queries (lookup, create, update, role assignment) ξαναγράφονται. Καλύπτει: deleteuser, activate, deactivate, load, getUsers, getuserid, getbyparam, makefriends/removefriends/arefriends/getfriends (+ SQL injection fix), addToken (upsert), deleteToken, clearTokens, getToken, getAllTokens, deactivateToken, expireToken, cleanupAuthTokens, cleanupAllAuthTokens, loadByToken, getDataUsageStats. 13 νέα integration tests. `setupDb()` (DDL) και `getFeed`/`addFeed` (legacy, χρησιμοποιούν non-framework class) αφέθηκαν.
- [x] **`Pramnos\Logs\*`** — Logger subsystem is **file-based** (zero DB queries) — επιβεβαιώνεται από `LoggerAndMigratorCharacterizationTest` / `LogManagerViewerCharacterizationTest`. Δεν υπάρχουν raw SQL calls προς αντικατάσταση. *(Migration N/A — file-based Logger, zero DB queries)*

## 📦 Φάση 2: Urbanwater Features Port
*Μεταφορά και ενσωμάτωση ώριμων υποσυστημάτων από το Urbanwater project στο Core Framework. Κάθε feature ενσωματώνεται ως αυτόνομο, opt-in component — ενεργοποιείται μέσω του Feature Registry (Φάση 4) και φέρει τα δικά του system migrations.*

> ⚠️ **Προϋπόθεση:** Η Φάση 2 εξαρτάται πλήρως από τη Φάση 4. Τα backport features χρησιμοποιούν Feature Registry για ενεργοποίηση, Service Providers για bootstrap, και System Migrations για schema. **Καμία από τις παρακάτω υλοποιήσεις δεν μπορεί να ολοκληρωθεί πριν τελειώσει η Φάση 4.** Σωστή σειρά υλοποίησης: Φάση 1 → Φάση 4 → Φάση 2.

- **Queues System** *(feature key: `queue`)*: Backport από Urbanwater — configurable hooks για URLs, task namespace, table name, dashboard title. Αναλυτική προδιαγραφή: βλ. `UrbanWater-Backport-Features.md` Section 5.
  - [x] `Pramnos\Queue\TaskInterface` — `src/Pramnos/Queue/TaskInterface.php`
  - [x] `Pramnos\Queue\AbstractTask` — `src/Pramnos/Queue/AbstractTask.php`
  - [x] `Pramnos\Queue\QueueManager` — `src/Pramnos/Queue/QueueManager.php` (hooks: `getTasksDirectory()`, `getTasksNamespace()`, `getQueueTableName()`, `createQueueItemModel()`)
  - [x] `Pramnos\Queue\Worker` — `src/Pramnos/Queue/Worker.php` (empty `$taskHandlers`, `registerTaskHandler()` chainable)
  - [x] `Pramnos\Queue\QueueItem` model — `src/Pramnos/Queue/QueueItem.php` (hooks: `getItemShowUrl()`, `getItemEditUrl()`, `getItemDeleteUrl()`)
  - [x] `queue:process` CLI command — `src/Pramnos/Console/Commands/ProcessQueue.php` (full dashboard, DB reconnect, stop-file; hooks: `getDashboardTitle()`, `getControllerName()`, `createWorker()`, `createQueueManager()`)
  - [x] `cleanup:queue` CLI command — `src/Pramnos/Console/Commands/CleanupQueue.php`
  - [x] System migration για `queueitems` table — `database/migrations/framework/queue/2020_01_01_000040_create_queueitems_table.php` (VARCHAR status + CHECK constraint on PG, composite indexes)
  - [x] `Pramnos\Queue\QueueServiceProvider` — `src/Pramnos/Queue/QueueServiceProvider.php`
  - [x] Unit tests: `tests/Unit/Queue/QueueManagerTest.php` (16 tests), `tests/Unit/Queue/WorkerTest.php` (9 tests)
  - [x] Integration tests × 2 databases — `tests/Integration/Queue/QueueManagerMySQLTest.php` (8 tests), `QueueManagerPostgreSQLTest.php` (8 inherited); queueitems schema, status VARCHAR+CHECK, index verification

- **Token Action Tracking** *(feature key: `auth`)*: Πλήρης αναβάθμιση του `tokenactions` + `urls` schema — hypertable support, execution time tracking, MySQL compat. Αναλυτική προδιαγραφή: βλ. `UrbanWater-Backport-Features.md` Section 9.
  - [x] System migration: `urls` table — `database/migrations/framework/auth/2020_01_01_000015_create_urls_table.php`
  - [x] System migration: `tokenactions` full schema — composite PK (actionid, action_time), hypertable on TimescaleDB (14-day chunks, compress after 60 days), `database/migrations/framework/auth/2020_01_01_000016_create_tokenactions_table.php`
  - [x] Sync trigger `sync_tokenactions_time` (bidirectional servertime ↔ action_time) — PostgreSQL only (inside tokenactions migration)
  - [x] TimescaleDB hypertable migration via `ifCapable()` in tokenactions migration
  - [x] `authserver.slow_api_calls` VIEW migration — `database/migrations/framework/authserver/2020_01_01_000030_create_slow_api_calls_view.php` (MySQL: `authserver_slow_api_calls`)
  - [x] `Token::updateAction()` — `src/Pramnos/User/Token.php:325`
  - [x] Integration tests × 2 databases — `tests/Integration/User/TokenActionMySQLTest.php`, `TokenActionPostgreSQLTest.php`; view migration tested in `FrameworkMigrationsMySQLTest` and `FrameworkMigrationsPostgreSQLTest`

- **OAuth Server** *(feature key: `authserver`)*: Ενσωμάτωση του πλήρους OAuth2 server (league/oauth2-server). Αναλυτική προδιαγραφή: βλ. `UrbanWater-Backport-Features.md` Section 3.
  - [x] `Pramnos\Auth\OAuth2\OAuth2ServerFactory` — 4 grant types (ClientCredentials, Password, AuthCode, RefreshToken) — `src/Pramnos/Auth/OAuth2/OAuth2ServerFactory.php` (commit b4d7be9)
  - [x] Repositories (6): `ClientRepository`, `ScopeRepository`, `AccessTokenRepository`, `AuthCodeRepository`, `RefreshTokenRepository`, `UserRepository` — `src/Pramnos/Auth/OAuth2/Repositories/`
  - [x] Entities (6): `ClientEntity`, `UserEntity`, `ScopeEntity`, `AuthCodeEntity`, `RefreshTokenEntity`, `AccessTokenEntity` — `src/Pramnos/Auth/OAuth2/Entities/`
  - [x] `Pramnos\Auth\OAuth2\OAuth2Middleware` (PSR-7 resource validation) — `src/Pramnos/Auth/OAuth2/OAuth2Middleware.php`
  - [x] `Pramnos\Auth\WebhookService` — `src/Pramnos/Auth/WebhookService.php`; queueEvent (MySQL path), processQueue (exponential back-off, HMAC-SHA256 signing), purgeOldEvents, verifySignature; 9 unit tests
  - [x] `AuthServerServiceProvider` με route registration — `src/Pramnos/Auth/AuthServerServiceProvider.php`
  - [x] System migrations: `authserver.device_authorizations` (RFC 8628, 000026), `jwt_replay_prevention` public table (000027), `authserver.oauth2_client_auth_methods` (000028), `oauth2_webhook_endpoints` + `oauth2_webhook_events` (000029), `authserver.slow_api_calls` VIEW (000030) — migrations exist, integration tests in `FrameworkMigrationsMySQLTest` + `FrameworkMigrationsPostgreSQLTest`
  - [x] PKCE columns σε `usertokens` (code_challenge, code_challenge_method + constraints + indexes), `usertokens.token` TEXT (από VARCHAR), 5 PL/pgSQL functions, `oauth2_application_permissions` + `oauth2_active_tokens` views — 000039 (oauth2_application_grants + views + cleanup fn), 000040 (deauthorize_user_from_app, create_gdpr_request, notify_user_profile_changed, token_revocation_webhook trigger + oauth2_webhook_status VIEW)
  - [x] RSA key generation (`openssl_pkey_new`) στο `pramnos init` — `scaffoldGitignore()` + `generateOAuth2KeyPair()` στο `Init.php`; 2048-bit RSA, app/keys/private.key (0600) + public.key (0644); .gitignore εξαίρεση; idempotent; 5 unit tests
  - [x] Auth Controllers: `Discovery.php` (OIDC + JWKS + RFC 8414 + health), `Session.php` (check/heartbeat/info/refresh, dual Bearer+session auth), `TwoFactorAuth.php`, `Gdpr.php` (WebhookService::queueEvent()), `Oauth.php` (authorize/token/revoke/introspect/userinfo/logout/deviceauthorization — League oauth2-server + nyholm/psr7 PSR-7 bridge), `Device.php` (RFC 8628 user-facing verification, dual session/credentials auth, webhook events), `Dashboard.php` (applications/revokeapplication/exportdata/deleteaccount/privacy/security/changepassword — bcrypt + SHA-256 fallback, cascading GDPR delete) — 39 unit tests
  - [x] `composer require league/oauth2-server:^8.5` — in composer.json
  - [x] Integration tests × 3 databases (grant flows, token validation, PKCE) — `OAuth2GrantFlowMySQLTest` (13 tests: device flow, consent recording, PKCE auth_code, token revocation, introspection) + `OAuth2GrantFlowPostgreSQLTest` (12 tests: same + PG CHECK constraint enforcement for PKCE method and challenge length); migrations 000041 (oauth2_device_codes) + 000042 (oauth2_user_consents)
  - [x] JWT `private_key_jwt` client assertion grant (RFC 7523 §2.2) — manual bypass στο `Oauth::token()` πριν από το League dispatch: επαλήθευση RSA signature, `loadByApiKey()`, `systemuser` deduplication (regression fix UW-461). Migration 000043 (`add_systemuser_to_applications`): nullable `systemuser BIGINT` σε `applications` × MySQL + PostgreSQL. Integration tests: `systemuserColumnExistsAndIsWritable`, `systemuserPersistsAcrossSelects` — `OAuth2GrantFlowMySQLTest`.
  - [x] QueryBuilder refactoring — εσωτερική αντικατάσταση raw SQL με QB σε: `AccessTokenRepository` (persistNewAccessToken, revokeAccessToken, isAccessTokenRevoked, resolveAppId), `AuthCodeRepository` (persistNewAuthCode, revokeAuthCode, isAuthCodeRevoked, resolveAppId), `RefreshTokenRepository` (persistNewRefreshToken, revokeRefreshToken, isRefreshTokenRevoked, resolveAccessTokenId, loadAccessTokenRow), `OAuth2Middleware` (revokeToken, loadTokenFromDatabase με LEFT JOIN), `Scopes::areApplicationScopesGranted()`. Zero API change — εσωτερική βελτίωση.

- **Authentication System** *(feature key: `auth`)*: Αναβαθμισμένο σύστημα auth — login lockout, 2FA (TOTP), GDPR, RBAC. Αναλυτική προδιαγραφή: βλ. `UrbanWater-Backport-Features.md` Section 4.
  - [x] `Pramnos\Auth\Loginlockout` — progressive lockout (3→60s, 5→300s, 7→900s, 10+→3600s), 3 scopes (user/identifier/ip) — `src/Pramnos/Auth/Loginlockout.php`, migration `000017`, integration tests × MySQL + PostgreSQL
  - [x] `Pramnos\Auth\TwoFactorAuthService` + `Pramnos\Auth\TOTPHelper` — migrations 000018/000019/000020 (user_twofactor, twofactor_setup, twofactor_attempts hypertable via ifCapable); TOTPHelper unit tests (15); service integration tests × MySQL + PostgreSQL (17 each)
  - [x] `Pramnos\Auth\Scopes`, `Pramnos\Auth\OAuthPolicyHelper` — static helpers; Scopes registry with inheritance resolution; OAuthPolicyHelper default auth methods + grant types; unit tests (12 + 6)
  - [x] System migrations — 2FA tables: `user_twofactor`, `twofactor_setup`, `twofactor_attempts` (TimescaleDB hypertable: 7-day chunks, compress after 7 days, retain 2 years) — done in migrations 000018/000019/000020
  - [x] System migrations — GDPR hypertables: `user_activity_log` (1-day chunks, 000021), `user_privacy_settings` (000022), `user_consents` (1-month chunks, 000023), `data_processing_records` (1-week chunks, 000024), `gdpr_requests` (1-month chunks, 000025), `daily_activity_summary` continuous aggregate (000026); all via ifCapable(TIMESCALEDB)
  - [x] System migrations — GDPR columns σε `users` table (000027): gdpr_consent, gdpr_consent_date, gdpr_data_export_requested, gdpr_deletion_requested, gdpr_deletion_date
  - [x] System migrations — authserver RBAC schema: `authserver` schema, `permissions`, `roles`, `user_deyas`, `user_roles`, `permission_templates`, `role_templates`, `permission_inheritance`, `audit_log`, `effective_permissions` VIEW, 7 PL/pgSQL functions — migrations 000031–000036; permissions.object_id fixed to VARCHAR(100) + unique constraint; integration tests × MySQL + PostgreSQL
  - [x] 2FA view templates (display/setup/backup) — τρία HTML/PHP view templates για κάθε theme (bootstrap, tailwind, plain-css): `twofactor.html.php` (overview + disable modal), `setup.html.php` (QR scan + backup codes + verify form), `backup.html.php` (remaining codes + regenerate form). Path: `scaffolding/themes/{theme}/views/twofactor/`.
  - [x] GDPR user-facing views (Dashboard account management) — `Dashboard.php` QB-migrated (10 methods: getAuthorizedApplications/getActivityLog/isTwoFactorEnabled/getPrivacySettings/verifyUserPassword/updatePassword/eraseUserData/revokeapplication/privacy POST/buildExportData); 6 view templates × 3 themes = 18 files: `dashboard/dashboard.html.php`, `OAuth2/authorized_applications.html.php`, `OAuth2/delete_account.html.php`, `OAuth2/privacy_settings.html.php`, `OAuth2/security.html.php`, `OAuth2/change_password.html.php`; 8 integration tests in `DashboardCharacterizationTest`; pre-existing bugs fixed: `users.salt` column removed from SELECT, `modified` column uses `time()` (int) not `NOW()`
  - [x] BC: υπάρχον addon hook interface (`onAuth()`, `onLogout()`, `onAuthCheck()`) παραμένει αμετάβλητο — `Auth.php` αμετάβλητο, καλύπτεται από `AuthCharacterizationTest` (testAuthCallsOnAuthOnAddon, testAuthCheckTriggersAddonAuthCheckHandlers, testLogoutSetsSessionLoggedFalseAndTriggersAddonLogoutHandlers)
  - [x] Integration tests × 3 databases — `FrameworkMigrationsTimescaleDBTest` (6 tests): twofactor_attempts, user_activity_log, user_consents, data_processing_records, gdpr_requests hypertables verified in timescaledb_information.hypertables; daily_activity_summary verified as continuous aggregate with refresh test

- **Messaging** *(feature key: `messaging`)*: Σύστημα μηνυμάτων — private messages, notifications, mass broadcast, email queue. Αναλυτική προδιαγραφή: βλ. `UrbanWater-Backport-Features.md` Section 7.
  - [x] `Pramnos\Messaging\Message` model (`messages` table, PK: messageid)
  - [x] `Pramnos\Messaging\Mail` model (`mails` table — email history + queue)
  - [x] `Pramnos\Messaging\MailTemplate` model (`mailtemplates` table, findByKey())
  - [x] `Pramnos\Messaging\MassMessage` + `MassMessageRecipient` models
  - [x] `MessagingServiceProvider`
  - [x] System migrations: `mails`, `mailtemplates`, `messages`, `massmessages`, `massmessagerecipients`
  - [x] Integration tests × 3 databases (MySQL + PostgreSQL model CRUD, TimescaleDB via PostgreSQL path)
- [x] **Daemons & Background Tasks** *(Policy Engine + Scheduler)*: Ολοκληρωμένο σύστημα δημιουργίας, διαχείρισης και επίβλεψης daemons/background tasks. Περιλαμβάνει:
  - **Policy Engine Daemon** (`service:policy-engine`): QB-migrated; `PolicyEngine::register/setEnabled/remove/loadPolicies/updateHistory` χρησιμοποιούν QB; `addRetentionPolicy()` + `addContinuousAggregatePolicy()` στο SchemaBuilder καταχωρούν policies στο `framework_policies`; 8 integration tests (`PolicyEngineCharacterizationTest`)
  - **Scheduled Tasks** (`service:scheduler` / `service:schedule-list`): `Scheduler`, `ScheduledTask`, `CronExpression`, `ScheduleRun`, `ScheduleList` — 29 unit tests + 6 PolicyRecord unit tests; full cron/interval API (`->cron()`, `->daily()`, `->everyHour()`, `->weekly()`, `->withoutOverlapping()`, `->isDue()`, `->run()`)
- [x] **CLI UX Improvements:** Backport `CommandBase` από Urbanwater — lock-file job guards, terminal control (cursor/clear/size), bordered dashboard rendering, block-character progress bar, text utils (formatBytes, formatTime, visibleLength, wrapDashboardText). Urbanwater commands μπορούν να extend-άρουν `Pramnos\Console\CommandBase`.
- [x] **Daemon Orchestrator:** Backport `DaemonOrchestrator` abstract class από Urbanwater — generic reconcile engine, desired-vs-actual process management, stop-file mechanism, flock singleton guard, dedup scan, git-hash restart detection, interactive dashboard. Apps override abstract methods (`buildDesiredProcesses`, `getDashboardTitle`, `getEntryPoint`) και hooks (`isOrchestratorEnabled`). Urbanwater's orchestrator μπορεί να κάνει extend `Pramnos\Console\DaemonOrchestrator` και να παρέχει μόνο την app-specific λογική.
- [x] **Event / Hook System:** Επίσημο σύστημα events και listeners πάνω από το υπάρχον addon hook σύστημα — `Event::fire()`, `Event::listen()` — για αποσύζευξη εσωτερικών subsystems και δυνατότητα επέκτασης από addons.
  > **BC Strategy:** Τα υπάρχοντα addon hooks (Login, Logout, Auth κλπ.) εξακολουθούν να πυροδοτούνται κανονικά. Το νέο Event system τρέχει παράλληλα — δεν τα αντικαθιστά.

## 🛠️ Φάση 3: Developer Experience (DX) & Scaffolding
*Βελτίωση της ταχύτητας ανάπτυξης εφαρμογών για τον developer.*

### 🏗️ `init` Command — Full Project Scaffolding

*Το `bin/pramnos init` γίνεται πλήρης project wizard. Ρωτάει τα πάντα, δημιουργεί την πλήρη δομή, κατεβάζει τα assets τοπικά, σηκώνει το Docker, και παραδίδει ένα έτοιμο project.*

- [x] **Step 1 — Project metadata:** Όνομα project, namespace, database type (MySQL / PostgreSQL / TimescaleDB), Docker ports.

- [x] **Step 2 — Framework features:** Επιλογή per-feature (auth, authserver, queue, messaging) με gate "Configure features? [y/N]". Αποθηκεύεται στο `app.php` ως `'features' => [...]`.

- [x] **Step 3 — UI System:** Επιλογή frontend stack (plain-css, bootstrap, tailwind). Theme files load από `scaffolding/themes/<ui>/`.

- [x] **Step 4 — Extra libraries:** Gate "Configure extra libraries? [y/N]". Αν yes, per-library choice με local download σε `www/assets/vendor/`. Manifest `scaffolding/assets.json`. Flag `--no-download` για CI. 21 libraries supported (βλ. docs/1.2-new-features.md § 24).

- [x] **Step 5 — Extra resources:** *(Step 5 merged with Step 4 gate — favicon/reset/print scaffolding deferred to future session).*

- [x] **Local Asset Download:** `file_get_contents()` με 15-second timeout. Local path `www/assets/vendor/<library>/<version>/`. Manifest `scaffolding/assets.json` per project.

- [x] **Step 6 — Docker startup & container bootstrap:**
  - `docker-compose up -d --build`
  - `docker-compose exec -T app composer update && composer dump-autoload`
  - `docker-compose exec -T app php bin/pramnos migrate:framework` — τρέχει τα system migrations ✅
  - Summary με URLs, credentials, επόμενα βήματα
  - Flag `--no-migrations` για skip

- [x] **Scaffolding Templates Directory:** `scaffolding/templates/` με `controller.stub`, `model.stub`, `migration.stub` (transactional=false), `middleware.stub`, `event.stub`, `listener.stub`, `test.stub`. `scaffolding/themes/plain-css|bootstrap|tailwind/`. `scaffolding/assets.json`.

- [x] **Modern Maker System:** `renderStub(name, tokens)` — loads `.stub` file, falls back to embedded skeleton. Χρησιμοποιείται από `Init` και `Create` commands.
- [x] **Test Auto-generation:** `generateTestStub(className, namespace, baseDir)` — αυτόματη δημιουργία `tests/Unit/<Class>Test.php` από `test.stub`. Ενεργό στο `create:middleware`.
- [x] **Middleware Scaffolding:** `php bin/pramnos create middleware <Name>` — `src/Middleware/<Name>.php` + `tests/Unit/<Name>MiddlewareTest.php`.
- [x] **Event/Listener Scaffolding:** `create:event` και `create:listener` — εξαρτάται από το Event System (Φάση 2).
- [x] **`docs/1.2-new-features.md`:** Section 24 added.
- [x] **Stub syntax unification:** Ενοποιήθηκε σε `{{ key }}` syntax — `CLAUDE.md.stub` και `mcp.json.stub` ενημερώθηκαν, `Init.php` χρησιμοποιεί πλέον `renderStub()` και για τα δύο. Το `{placeholder}` στα mail/notification templates είναι διαφορετικός σκοπός (αποδεκτή διαφορά).

### 🔧 Enhanced Scaffolding & Developer Experience (v1.2)
*Ανάβαθμη της ποιότητας του scaffolded κώδικα και της εμπειρίας προγραμματιστή.*

- [x] **PHP 8.5 Default Version:** Ανύψωση της default PHP version από 8.4 σε 8.5 — επηρεάζει:
  - Το `Dockerfile` του framework (base image αναβάθμιση)
  - Τα scaffolded Dockerfiles κάθε νέας εφαρμογής (`pramnos init`)
  - Τη `composer.json` requirement (`php: ^8.5`)
  - Τη δοκιμαστική μήνυμα κατά το bootstrap αν ο τοπικός PHP είναι πιο παλιός

- [x] **Symfony-Compatible Console Commands:** Αναδιαμόρφωση της ονοματολογίας CLI commands σε symfony format — π.χ. `create migration` → `create:migration`, `create model` → `create:model`, `create controller` → `create:controller`, `service queue` → `service:queue`, κλπ. Επηρεάζει:
  - Όλα τα `Console/Commands/*.php` αρχεία (όνομα κλάσης ή `getCommand()` return)
  - Την τεκμηρίωση (`docs/1.2-new-features.md` — πίνακας διαθέσιμων commands)
  - Τη backward compatibility: κρατήστε alias για τις παλιές εντολές (π.χ. `create migration` → εσωτερικά καλεί το `create:migration` command)

- [x] **Full Unit Tests in `create` Commands:** Τα scaffolded unit tests δεν θα είναι πια placeholders. Όταν δημιουργείται ένα model/controller/middleware, τα auto-generated tests πρέπει να:
  - Περιλαμβάνουν πλήρεις test methods για κάθε public method του scaffolded class
  - Ορίζουν fixtures (mocks, test data) ανάλογα με τα fields/relations του model
  - Χρησιμοποιούν σωστές assertions (π.χ. μη-empty strings, valid dates, type checks)
  - Καλύπτουν edge cases (null values, boundary conditions) για κάθε method
  - Περιλαμβάνουν docblocks που εξηγούν τι δοκιμάζει κάθε test

- [x] **Advanced Primary Key Naming:** Τα `create:migration` commands θα παράγουν primary keys με naming convention `{databasename}id` (π.χ. `userid`, `customerid`, `deviceid`) αντί του generic `id`. Ενδιαφέρει:
  - Τη `migration.stub` — το auto-generated schema πρέπει να χρησιμοποιεί `$table->bigIncrements('{pluralSnake}id')` και όχι `id`
  - Την αναλογία στα `Model` scaffolds (ώστε το `_dbtable` και το `_primarykey` να συμφωνούν με τη DB)
  - Τα integration tests × 2 databases (ότι το primary key έχει δημιουργηθεί με σωστό όνομα)

- [x] **Full CRUD Controllers & Views:** Τα scaffolded controllers και views δεν είναι πια placeholders — παράγουν **100% λειτουργικό CRUD** με την επιλεγμένη UI system (plain-css, bootstrap, ή tailwind). Ενδιαφέρει:
  - **Controllers:** 7 methods (list, create, store, edit, update, show, destroy) με πλήρη validation, model queries και error handling
  - **Views:** List/create/edit/show templates σύμφωνα με το UI system — data binding, form rendering, validation error display, success messages
  - **Routes:** Αυτόματη δημιουργία RESTful routes (GET /items, GET /items/create, POST /items, GET /items/{id}/edit, PUT /items/{id}, GET /items/{id}, DELETE /items/{id}) στο routing config
  - **UI Integration:** Τα created views να φορτώνουν σωστά τα UI components (buttons, forms, tables) από το επιλεγμένο theme
  - **Validation:** Σωστά πεδία validation σύμφωνα με τα scaffolded model properties

- [x] **Remove Scaffolding Output Folder:** Κατά το `pramnos init`, δεν θα δημιουργείται πλέον ο κενός φάκελος `scaffolding/` στη ρίζα του project. Αυτός ο φάκελος:
  - Θα παραμείνει μόνο μέσα στο framework (`src/Pramnos/Console/Resources/scaffolding/`)
  - Αν η εφαρμογή θέλει να override κάποιο stub ή theme, θα δημιουργήσει τη δικιά της `scaffolding/` δομή (προαιρετικό, όχι υποχρεωτικό)

- [x] **Full Docblocks for Scaffolded Code:** Ό,τι παράγεται από τα commands (`create:model`, `create:controller`, κλπ.) πρέπει να έχει **πλήρη docblocks**:
  - Class-level docblocks: περιγραφή του σκοπού της κλάσης, `@package` annotation
  - Method-level docblocks: περιγραφή του τι κάνει η μέθοδος, `@param` για όλα τα arguments, `@return` με τύπο, `@throws` αν πετάει exception
  - Property-level docblocks: περιγραφή του σκοπού της ιδιότητας και ο τύπος της (`@var`)
  - Inline comments μόνο για non-obvious λογική (π.χ. γιατί χρειάζεται special handling)

- [x] **Decompose MakeCommandBase.php:** Η κλάση `src/Pramnos/Console/Commands/MakeCommandBase.php` έχει ~3,000 γραμμές — απλώς μεταφέρθηκε η πολυπλοκότητα από την παλιά `Create.php`. Απαιτείται refactor σε focused service classes για να βελτιωθεί η testability και η maintainability:
  - `StubRenderer` — φορτώνει/renders τα `.stub` αρχεία με variable substitution
  - `FieldParser` / `BlueprintCompiler` — μετατρέπει field definitions σε DDL fragments (`blueprintCall`, `buildMigrationUpBody`)
  - `NamespaceResolver` — εντοπίζει namespace/path της εφαρμογής από το `applicationInfo`
  - `FakeDataGenerator` — heuristics για fake values (`generateFakeValue`, `buildSeederFields`)
  - Κάθε service class πρέπει να έχει dedicated unit tests χωρίς filesystem dependencies
  - Τα `Make/*.php` commands παραμένουν thin wrappers που καλούν τους services

- [x] **API Controller Scaffolding & Auto-Route Generation:** Η εφαρμογή θα μπορεί να δημιουργεί αυτόματα **ολοκληρωμένα API endpoints** για CRUD resources μέσω του `create:api-controller` command. Τα scaffolded API controllers θα περιλαμβάνουν:
  - **Full CRUD Methods:** `index()` (list with pagination/filtering), `store()` (create), `show()` (retrieve), `update()` (edit), `destroy()` (delete), με proper HTTP status codes (200, 201, 404, 422, 500)
  - **Request Validation:** Αυτόματη ενσωμάτωση της `Pramnos\Validation` system με validators ανάλογα με τα model fields
  - **JSON Response Serialization:** Χρήση `Model::toArray()` ή custom serializers για consistent JSON output format με metadata (pagination, errors, timestamps)
  - **Error Handling:** Proper exception handling με JSON error envelopes — `{"error": "...", "code": "...", "status": 400/404/500}`
  - **Model Relationships:** Auto-detection of foreign keys και generation των include-related methods (π.χ. `index?include=author,comments`)
  - **Auto-Route Registration:** Αυτόματη δημιουργία RESTful API routes στο routing config (`api/v1/resources`)
  - **API Documentation Stubs:** Αυτογενή phpDoc comments με apiDoc-style annotations (`@apiRoute`, `@apiParam`, `@apiResponse`) για κάθε endpoint
  - **Rate Limiting Integration:** Προσθήκη rate-limiting middleware annotations στο scaffolded controller (εν δυνάμει, ανάλογα με το config)
  - **Integration with Framework API Controllers:** Reuse patterns από existing framework API controllers (Auth, Dashboard, OAuth, κλπ.) — standard response envelopes, error formats, pagination cursor format

## 🔒 Φάση 4: Framework-Level Infrastructure & Security
*Ενίσχυση της ασφάλειας και της εσωτερικής αρχιτεκτονικής.*

- [x] **Feature Registry & `app.php` Integration:** Κεντρικό σύστημα ενεργοποίησης/απενεργοποίησης framework features μέσω του config αρχείου της εφαρμογής:

  ```php
  // app.php
  'features' => [
      'auth',        // Basic Auth System
      'authserver',  // OAuth Server
      'messaging',   // Messaging
      'queue',       // Queue System
  ],
  ```

  - Το `core` είναι πάντα ενεργό και δεν χρειάζεται να δηλωθεί.
  - Κατά το bootstrap, το framework διαβάζει τη λίστα και φορτώνει αυτόματα τον αντίστοιχο Service Provider κάθε feature.
  - Αν ένα feature δηλωθεί αλλά δεν έχουν τρέξει τα migrations του, το framework εμφανίζει warning (ή τρέχει τα migrations αυτόματα, ανάλογα με το config).
  - Άγνωστα feature keys πετούν `UnknownFeatureException` με σαφές μήνυμα.

### 🗄️ Migration System Overhaul

- [x] **Enhanced Migration History Table:** Το `framework_migrations` (και αντίστοιχα το app-level migrations table) αποκτούν πλήρη metadata:

  ```sql
  migration        VARCHAR(255)  -- όνομα αρχείου, π.χ. 2024_03_15_143022_create_users_table
  scope            VARCHAR(255)  DEFAULT 'app'   -- 'app' | 'framework'
  feature          VARCHAR(255)  NULL             -- π.χ. 'auth', 'queue', NULL για app migrations
  batch            INTEGER       NULL             -- ομαδοποίηση εκτέλεσης
  execution_time   DOUBLE        NULL             -- seconds
  result           SMALLINT      DEFAULT 1        -- 1=success, 0=failed
  error_message    TEXT          NULL
  description      VARCHAR(255)  NULL
  ran_at           TIMESTAMP
  ```

- [x] **Migration Class Anatomy:** Κάθε migration αρχείο φέρει δομημένα metadata:

  ```php
  class CreateUsersTable extends Migration
  {
      // Ημερομηνία-ώρα στο όνομα αρχείου: YYYY_MM_DD_HHmmss_<slug>.php
      // Ορίζει αυτόματα τη σειρά εκτέλεσης όταν priority και deps είναι ίσα.

      public string  $feature      = 'auth';           // feature key ή null για app migrations
      public int     $priority     = 10;               // μικρότερο = τρέχει πρώτο
      public array   $dependencies = [                 // slugs migrations που πρέπει να έχουν τρέξει
          'create_roles_table',
      ];
      public bool    $autorun      = true;             // false = τρέχει μόνο με --force
      public string  $description  = 'Creates the users table with roles FK';

      public function up(): void { ... }
      public function down(): void { ... }
  }
  ```

- [x] **Sort Order για εκτέλεση:** Τα migrations ταξινομούνται πάντα ως εξής:
  1. Επίλυση `$dependencies` (topological sort — εξαρτήσεις τρέχουν πρώτες)
  2. `$priority` ascending (μικρότερο = υψηλότερη προτεραιότητα)
  3. Datetime από το όνομα αρχείου ascending (YYYY_MM_DD_HHmmss)

- [x] **Auto-run Mechanism (Backport από Urbanwater):** Κατά το bootstrap της εφαρμογής, ο migration runner εκτελείται αυτόματα:
  - Τρέχει **app migrations** και **framework migrations** (scope=framework για τα ενεργά features) σε ενιαία ταξινομημένη ουρά.
  - Σέβεται το app setting `migration_cutoff` (datetime): **δεν εκτελεί κανένα migration με datetime παλιότερο από αυτό**. Τα framework baseline migrations φέρουν σκόπιμα παλιά timestamps (π.χ. `2020_01_01_*`) ώστε ένα υπάρχον installation (π.χ. UrbanWater production) να θέτει `migration_cutoff` σε μεταγενέστερη ημερομηνία και να τα παρακάμπτει αυτόματα — χωρίς να αγγίξει τα UW migrations που έχουν ήδη τρέξει.
  - Τρέχει μόνο migrations με `autorun = true`. Τα `autorun = false` παραλείπονται σιωπηλά (απαιτούν `--force` από CLI).
  - Αν αποτύχει migration, καταγράφεται στο history (result=0, error_message) και το bootstrap **δεν σταματά** — εμφανίζει warning και συνεχίζει.
  - **Capability-aware migrations:** Κάθε migration που χρησιμοποιεί `$schema->ifCapable()` εκτελείται κανονικά σε όλα τα backends — το capability check γίνεται εσωτερικά. Ο migration runner δεν χρειάζεται να γνωρίζει τι backend τρέχει. Τα TimescaleDB-specific DDL statements εκτελούνται μόνο αν `DatabaseCapabilities::has(TIMESCALEDB) === true`.

- [x] **System Migrations (per feature):** Migrations αποκλειστικά για τους πίνακες του framework, οργανωμένα ανά feature:

  | Feature | Tables που δημιουργεί |
  |---|---|
  | `core` *(πάντα ενεργό)* | `sessions`, `settings`, `permissions`, `framework_migrations`, `framework_policies` |
  | `auth` | `users`, `roles`, `user_roles`, `password_resets` |
  | `authserver` | `oauth_clients`, `oauth_tokens`, `oauth_authorization_codes`, `oauth_scopes` |
  | `messaging` | `messages`, `message_threads`, `message_recipients` |
  | `queue` | `jobs`, `failed_jobs`, `job_batches` |

- [x] **Scheduled Tasks System:** Cron-like σύστημα για τον ορισμό επαναλαμβανόμενων εργασιών μέσα στον κώδικα — χωρίς εξάρτηση από system crontab. Εκτελείται μέσω του `service:scheduler` daemon ή `schedule:run` εντολής.

  ```php
  // Ορισμός σε ServiceProvider::boot()
  $scheduler->command('cleanup:temp')->daily()->at('02:00');
  $scheduler->call(fn() => Cache::flush())->everyHour();
  $scheduler->job(new RefreshAnalyticsJob())->cron('*/15 * * * *');
  ```

  - Cron expression support (`* * * * *`) και named intervals (`daily()`, `hourly()`, `weekly()`)
  - Overlap prevention: αν η προηγούμενη εκτέλεση τρέχει ακόμα, η νέα παραλείπεται (opt-in `withoutOverlapping()`)
  - Logging στο framework Logs subsystem (start/end/duration/error)
  - `schedule:list` CLI command — εμφανίζει όλα τα registered tasks με next run time

- [x] **Health Check & Observability:** Ενσωματωμένο σύστημα ελέγχου υγείας της εφαρμογής.
  - `health:check` CLI command — εκτελεί όλους τους registered health checks και εμφανίζει αποτέλεσμα
  - Opt-in `/health` HTTP endpoint — επιστρέφει JSON `{status: ok|degraded|down, checks: {...}}` για load balancers και monitoring
  - Built-in checks: database connectivity (R/W), replica lag, disk space, PHP memory limit, cache reachability
  - Custom checks μέσω `HealthCheck` interface — addons μπορούν να καταχωρούν δικά τους checks
  - Υποστήριξη secret token για προστασία του `/health` endpoint από public access

- [x] **Migration CLI Commands:** Πλήρες σετ εντολών για τη διαχείριση migrations:

  | Εντολή | Περιγραφή |
  |---|---|
  | `migrate` | Τρέχει όλα τα pending migrations (app + framework) |
  | `migrate --scope=framework` | Μόνο framework migrations |
  | `migrate --scope=app` | Μόνο app migrations |
  | `migrate --feature=auth` | Μόνο migrations συγκεκριμένου feature |
  | `migrate <MigrationName>` | Τρέχει συγκεκριμένο migration |
  | `migrate --force` | Περιλαμβάνει και τα `autorun=false` migrations |
  | `migrate --pretend` | Εκτυπώνει το SQL χωρίς να εκτελέσει (dry run — δεν αγγίζει τη βάση) |
  | `migrate:rollback` | Rollback του τελευταίου batch |
  | `migrate:rollback --batch=3` | Rollback συγκεκριμένου batch |
  | `migrate:rollback <MigrationName>` | Rollback ενός συγκεκριμένου migration |
  | `migrate:reset` | Rollback όλων των migrations (αντίστροφη σειρά) |
  | `migrate:refresh` | Reset + επανεκτέλεση όλων |
  | `migrate:refresh --seed` | Reset + επανεκτέλεση + seeders |
  | `migrate:status` | Πίνακας με κατάσταση κάθε migration (ran/pending/failed, batch, execution_time) |
  | `migrate:export <MigrationName> --format=sql` | Εξαγωγή ως SQL αρχείο |
  | `migrate:export <MigrationName> --format=php` | Εξαγωγή ως PHP migration αρχείο |
  | `db:seed` | Εκτέλεση seeders |
  | `db:seed <SeederClass>` | Εκτέλεση συγκεκριμένου seeder |
- [x] **Middleware Pipeline:** Σύστημα middleware (before/after action execution) στο routing pipeline — rate limiting, auth enforcement, CORS, request logging — χωρίς τροποποίηση των controllers.
  > **BC Strategy:** Υπάρχοντες routes λειτουργούν αμετάβλητα. Middleware εφαρμόζεται μόνο αν δηλωθεί ρητά (opt-in), είτε per-route είτε globally. Η υπάρχουσα permission-checking λογική του Router παραμένει και συνεχίζει να τρέχει.
- [x] **Formal Response Object:** Κλάση `Pramnos\Http\Response` με fluent interface (`withStatus()`, `withHeader()`, `json()`, `redirect()`) που συμπληρώνει τα υπάρχοντα header calls στους controllers.
  > **BC Strategy:** Η νέα κλάση είναι εντελώς additive. Controllers που καλούν απευθείας `header()`, `echo`, ή χρησιμοποιούν το `Document` layer συνεχίζουν να λειτουργούν αμετάβλητα.
- [x] **Centralized Error / Exception Handler:** Ενιαίος handler για exceptions με environment-aware εξαγωγή: stack trace σε `debug` mode, friendly error page ή JSON envelope σε `production` mode, ενσωμάτωση με το Logs subsystem.
- [x] **Service Providers:** Καθιέρωση `ServiceProvider` interface (`register()` / `boot()`) για την ομαλή εγγραφή routes, bindings και listeners από addons κατά το bootstrap.
  > **BC Strategy:** Το υπάρχον addon bootstrap mechanism συνεχίζει να λειτουργεί. Το `ServiceProvider` pattern είναι νέος, προαιρετικός τρόπος εγγραφής — όχι υποχρεωτικός.
- [x] **PHP 8.1 Minimum Version:** Ανύψωση του minimum requirement στην PHP 8.1 (η 7.4 και 8.0 είναι EOL). Ανοίγει enums, readonly properties και intersection types στο core.
- [x] **Security Fixes:**
  - [x] Αναβαθμισμένο CSRF Protection.
  - [x] Εφαρμογή strict ρυθμίσεων (HttpOnly, SameSite) στα session cookies.
  - [x] Αυτόματο (ή πιο ασφαλές) escaping στα views/templates του framework.

## 🧪 Φάση 5: Quality Assurance
*Διασφάλιση της σταθερότητας του κώδικα.*

### Characterization Tests (Πριν από κάθε migration)
*Tests που καταγράφουν την **υπάρχουσα συμπεριφορά** των SQL-heavy classes, πριν αγγιχτεί γραμμή κώδικα. Αν όλα περνούν πριν και μετά το migration → BC αποδεδειγμένα διατηρήθηκε.*

> ℹ️ Αυτά τα tests γράφονται με γνώμονα το **παρατηρήσιμο αποτέλεσμα** (τι επιστρέφει κάθε public method, με ποιο SQL), όχι την εσωτερική υλοποίηση. Έτσι παραμένουν έγκυρα και μετά το refactor.

> ⚠️ **Κάθε characterization test εκτελείται υποχρεωτικά και στις τρεις βάσεις** (MySQL, PostgreSQL, TimescaleDB) μέσω του Docker environment. Ένα test που γράφεται μόνο για MySQL δεν θεωρείται ολοκληρωμένο.

- [x] **Characterization Tests — `Model`:** MySQL (`ModelCharacterizationTest` — unit, DB-agnostic) + MySQL (`ModelListApiCharacterizationTest`) + PostgreSQL/TimescaleDB (`ModelListApiPostgreSQLCharacterizationTest` → timescaledb:5432). Model έχει zero TimescaleDB-specific paths.
- [x] **Characterization Tests — `DataTable`:** MySQL (`DatasourceCharacterizationTest`) + PostgreSQL/TimescaleDB (`DatasourcePostgreSQLCharacterizationTest` → timescaledb:5432). Datasource έχει zero TimescaleDB-specific paths.
- [x] **Characterization Tests — `Migration`:** Κάλυψη schema creation/alteration/rollback — **× 3 databases**. Περιλαμβάνει: σωστή ταξινόμηση (priority/deps/datetime), σεβασμό του `migration_cutoff`, συμπεριφορά autorun=false, καταγραφή αποτυχίας στο history.
- [x] **Characterization Tests — `Adjacencylist`:** Κάλυψη parent/children traversal, depth queries, και tree reconstruction — **× 3 databases**.
- [x] **Characterization Tests — `Auth`:** `AuthCharacterizationTest` + `JWTCharacterizationTest` — pure unit tests (Auth class delegates all DB queries to addons, zero direct DB queries). DB-agnostic coverage είναι πλήρης.
- [x] **Characterization Tests — `Permissions`:** MySQL (`PermissionsCharacterizationTest`) + PostgreSQL/TimescaleDB (`PermissionsPostgreSQLCharacterizationTest` → timescaledb:5432). 13 behavioral contracts: allow/deny/removePermission/isAllowed, upsert semantics, admin escalation, instance cache, nonExistEqualsFalse, setDefaultPermission, setupDb idempotency. Bugs fixed: `convertBool()` returned `'t'` for PostgreSQL smallint column (now `(int) $value`); `User::load()` lacked null-guard for false DB result on missing table.
- [x] **Characterization Tests — `User`:** MySQL (`UserCharacterizationTest`, `UserTokenManagementCharacterizationTest`) + PostgreSQL/TimescaleDB (`UserPostgreSQLCharacterizationTest` → timescaledb:5432). User έχει zero TimescaleDB-specific paths.
- [x] **Characterization Tests — `Logs`:** `LoggerAndMigratorCharacterizationTest`, `LogManagerViewerCharacterizationTest` — file-based Logger, zero DB queries. DB-agnostic coverage είναι πλήρης.

> **Σημείωση για `[~]` (μερική κάλυψη):** Τα tests αυτά υπάρχουν στο Urbanwater integration suite και τρέχουν κατά τη διάρκεια ανάπτυξης ενάντια σε PostgreSQL + TimescaleDB. **Δεν** είναι επίσημα framework characterization tests × 3 databases — δεν τρέχουν σε MySQL και δεν βρίσκονται σε `tests/Characterization/`. Κατά συνέπεια, η Φάση 1 Internal Migration ολοκληρώθηκε χωρίς τη formal προϋπόθεση. Χρειάζεται επίσημη ολοκλήρωση πριν οποιοδήποτε επιπλέον refactoring.

### Code Quality
- [x] **Extract `ExpiredException` από `JWT.php`:** Μεταφορά σε `src/Pramnos/Auth/ExpiredException.php`. FQCN αναλλοίωτο (`Pramnos\Auth\ExpiredException`) — δεν χρειάστηκε `class_alias`.

### New Feature Tests (>90% coverage, στόχος 100%)
*Κάθε νέο feature που παραδίδεται στη v1.2 πρέπει να συνοδεύεται από tests που καλύπτουν τουλάχιστον το 90% του κώδικά του. Database-related features εκτελούνται × 3.*

- [x] **QueryBuilder Tests:** Πλήρης κάλυψη DML, DDL, TimescaleDB extensions, edge cases — **× 3 databases** (`QueryBuilderMySQLTest`, `QueryBuilderPostgreSQLTest`, `QueryBuilderTimescaleDBTest`; 373 tests). `timeBucket()` integration-tested on all 3 backends.
- [x] **Schema Builder Tests:** `SchemaBuilderMySQLTest` (26), `SchemaBuilderPostgreSQLTest` (24), `SchemaBuilderTimescaleDBTest` (28 — inherits all PG tests + 4 TimescaleDB-specific: createHypertable, retention/compression policies, continuous aggregate, ifCapable).
- [x] **ORM Layer Tests:** MySQL (`ModelListApiCharacterizationTest`) + PostgreSQL/TimescaleDB (`ModelListApiPostgreSQLCharacterizationTest`) — getCount, _getList, _getApiList contracts × 3 databases. Messaging model CRUD × 2 databases (MySQL + PostgreSQL) via `MessagingModelsMySQLTest` / `MessagingModelsPostgreSQLTest`.
- [x] **Middleware Pipeline Tests:** `MiddlewarePipelineTest` (567 lines) — before/after execution, short-circuit (403/401), chaining πολλαπλών middleware, exception propagation.
- [x] **Migration System Tests *(Integration only)*:** `FrameworkMigrationsMySQLTest` (52 tests) + `FrameworkMigrationsPostgreSQLTest` (52 tests) — all framework migrations × 2 databases. `MigrationRunnerMySQLTest` + `MigrationRunnerPostgreSQLTest` — runner lifecycle (batch, history, cutoff, rollback).
- [x] **Response Object Tests:** `ResponseTest` (407 lines) — status codes, header management, JSON serialization, redirect generation.
- [x] **Exception Handler Tests:** `ExceptionHandlerTest` (364 lines) — debug vs production output, JSON envelope για API routes, integration με Logs.
- [x] **Event System Tests:** `EventTest` (396 lines) — fire/listen, multiple listeners, listener priority, exception handling.
- [x] **Service Provider Tests:** `ServiceProviderUnitTest` (324 lines) — register/boot lifecycle, provider FQCN via FeatureRegistry, manually-added providers, multi-provider phase order.

### General Coverage (υπάρχον codebase → >80%)
- [x] **Coverage Baseline:** Μέτρηση τρέχοντος coverage του `src/Pramnos/` με Xdebug report — ορισμός αφετηρίας. **Current (2026-05-08):** Statements 36.0% (8153/22658), Methods 44.1% (801/1815), 157 classes; Clover XML at `coverage/clover.xml`.
- [x] **Coverage Reports:** Αυτόματη παραγωγή HTML coverage report στο CI (dockertest) με ορατό summary ανά class. *(`./dockertest --coverage` generates HTML + Clover XML in same pass; `--coverage-clover` added to `dockertest` script to ensure clover.xml is always refreshed)*
- [x] **Auth & Security Coverage:** PHPUnit tests για login flows, JWT issuance, CSRF validation και permission checks — **× 3 databases** για τα query paths. *(Login flows: Auth/OAuth2 integration tests; JWT: JWTCharacterizationTest; CSRF: CsrfTest 20 tests; Permission checks: RBAC function behavioral tests — `check_permission_with_inheritance`, `get_user_effective_permissions`, `apply_role_template`, `log_audit_event`, `check_user_deya_membership` trigger — 10 PostgreSQL characterization tests in `RbacFunctionsCharacterizationTest`)*
- [x] **Theme / View Layer Coverage:** Tests για asset enqueuing, widget rendering και variable passing από controllers στα views. *(Asset enqueuing: DocumentTest 4 tests — registerStyle/Script + enqueueStyle/Script + dependency resolution + idempotence; Widget management: ThemeCharacterizationTest — addWidget happy path, non-existent area, missing widgetId, getWidgets all / filtered by area, debug mode; 6 new widget tests)*
- [x] **Email & Media Coverage:** Βασικά unit tests για SMTP email building και Media/image processing pipeline. *(SMTP building: EmailCharacterizationTest 13 tests — fluent setters/getters, error state; Image pipeline: ResizeToolsCharacterizationTest — default properties, maxsize guard, zero-dimensions guard + 3 GD pipeline tests skipped when gd absent; ThumbnailCharacterizationTest 2 tests)*
- [x] **HTTP Layer Coverage:** Tests για Request parsing, Session fingerprinting, cookie management, CSRF token lifecycle. *(69 tests: CsrfTest 20 — token generation/verification/regeneration/entropy/field+header variants; SessionSecurityTest — HMAC-SHA256 fingerprint, IP variants; RequestTest — GET/POST/PUT/DELETE/JSON, cookies, URL, params, validation; SessionTest — session lifecycle)*

---

## 🔗 Σειρά Εξάρτησης Υλοποίησης

Η παρακάτω σειρά είναι **υποχρεωτική**. Οι εξαρτήσεις μεταξύ φάσεων δεν είναι ευθύγραμμες — η Φάση 4 πρέπει να προηγηθεί της Φάσης 2, και τα Characterization Tests (Φάση 5) πρέπει να γραφούν **πριν** το Internal Migration, όχι μετά.

```
━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━
 ΣΤΑΔΙΟ Α: Grammar & DML QueryBuilder (ολοκληρώθηκε) ✅
━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━
[Φάση 1] DML QueryBuilder — missing features
         (whereNull, whereBetween, UNION, CTEs, window functions,
          INSERT IGNORE / ON CONFLICT, TRUNCATE) ✅
         ↓
[Φάση 1] QB convenience & aggregate methods ✅
         (count, sum/avg/min/max, exists/doesntExist,
          value, pluck, increment/decrement, chunk,
          when, rightJoin/crossJoin, latest/oldest, forPage,
          whereExists, whereDate family, lockForUpdate/sharedLock,
          selectSub/fromSub subqueries, window functions over())
         ↓
[Φάση 1] Grammar/Adapter Pattern
         (MySQLGrammar, PostgreSQLGrammar, TimescaleDBGrammar)
         Απαραίτητο πριν οποιοδήποτε DDL — διαφορετικά το
         dialect-specific SQL συσσωρεύεται ως scattered if-checks
         ↓
[Φάση 1] DatabaseCapabilities alignment με Backport Spec
         (constants, static cache, MATERIALIZED_VIEWS, ENUMS,
          ifCapable() στο SchemaBuilder)

━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━
 ΣΤΑΔΙΟ Β: DDL Schema Builder (ολοκληρώθηκε) ✅
━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━
[Φάση 1] DDL / Schema Builder
         (createTable, alterTable, indexes, views, mat.views, triggers)
         ↓
[Φάση 1] TimescaleDB Extension Builder
         (createHypertable, retention/compression/aggregate policies)

━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━
 ΣΤΑΔΙΟ Γ: Framework Infrastructure (ολοκληρώθηκε) ✅
━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━
[Φάση 4] Migration System Overhaul
         (framework_migrations metadata, topological sort,
          migration_cutoff, autorun, CLI commands)
         ↓
[Φάση 4] Feature Registry & app.php Integration
         ↓
[Φάση 4] Service Providers (register/boot lifecycle)
         ↓
[Φάση 4] Policy Engine / Scheduled Tasks / Health Check
         (χρειάζεται Migration System για το framework_policies table)

━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━
 ΣΤΑΔΙΟ Δ: Characterization Tests — ΠΡΙΝ το Internal Migration
━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━
[Φάση 5] Επίσημα Characterization Tests × 3 databases
         για: Model, DataTable, Auth, User, Logs, Adjacencylist
         ⚠️ Τα υπάρχοντα Urbanwater tests μετρούν ως μερική
         κάλυψη PostgreSQL μόνο — δεν αρκούν
         ↓
[Φάση 1] Internal Migration (υπολοιπά: Migration, Adjacencylist,
         Auth, User, Logs) χρησιμοποιώντας QueryBuilder
         ↓
[Φάση 5] Επανεκτέλεση characterization tests → πρέπει να
         περνούν ΟΛΟΙ × 3 databases
         ↓
         ✅ BC αποδεδειγμένα διατηρήθηκε

━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━
 ΣΤΑΔΙΟ Ε: Backport Features (Φάση 2) — μετά τη Φάση 4
━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━
[Φάση 2] OAuth Server, Auth System, Queue, Messaging, Daemons
         (απαιτούν Feature Registry + Service Providers + Migrations)

━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━
 RELEASE GATES (παράλληλα με όλα τα στάδια)
━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━
[Κάθε Φάση] docs/1.2-new-features.md ενημερώνεται παράλληλα
[Release]   Coverage check: νέα features >90%, υπάρχον codebase >80%
            → Αν αποτύχει, το release δεν προχωρά
```

> **Παρατήρηση για την τρέχουσα κατάσταση:** Η Φάση 1 Internal Migration ολοκληρώθηκε (Model, DataTable) **χωρίς** προηγούμενα επίσημα characterization tests × 3 databases. Τα Urbanwater tests χρησιμοποιούν ως de facto characterization suite αλλά καλύπτουν μόνο PostgreSQL + TimescaleDB. Προτού αγγιχτεί οποιοδήποτε άλλο class (Auth, User, Logs, Adjacencylist), τα επίσημα tests πρέπει να γραφούν.


### 🆕 Backlog: UrbanWater Schema Backport & Open Jira Issues

> **Νέα tasks για v1.2+ (προσθήκη 2026-05-14):**
> **Ενημέρωση 2026-05-14:** Εκκρεμότητες από MIGRATION_AUDIT.md ενσωματώθηκαν στις εργασίες παρακάτω.

#### UrbanWater Schema Backport

##### Νέοι Πίνακες (HIGH PRIORITY)
- [x] **`000037a: create_application_settings_table`**
  - Πεδία: appid (PK/FK), rate_limit_requests, rate_limit_window_seconds, rate_limit_burst
  - enforce_pagination, max_page_size, default_page_size
  - ip_lock_enabled, allowed_ips[] (JSON), blocked_ips[] (JSON)
  - require_https (BOOLEAN), cors_enabled (BOOLEAN), cors_origins[] (JSON)
  - created_at, updated_at με trigger ενημέρωσης (applications.update_updated_at_column)
  - Indexes: PRIMARY KEY (appid), INDEX (updated_at)
  - × 3 databases (MySQL, PostgreSQL, TimescaleDB) — integration tests mandatory

- [x] **`000037b: create_application_stats_table` (hypertable on TimescaleDB)**
  - Hypertable partition key: `time` (TIMESTAMP)
  - Δεδομένα: appid, total_requests, successful_requests, failed_requests
  - avg_response_time (NUMERIC 10,3), min_response_time, max_response_time (NUMERIC 10,3)
  - HTTP status buckets: status_2xx, status_3xx, status_4xx, status_5xx (INT)
  - rate_limited_requests, rate_limit_violations (INT)
  - bytes_sent, bytes_received (BIGINT)
  - unique_ips_approx (INT), country_code (VARCHAR 2)
  - Hypertable config: 14-day chunks, compression enabled after 60 days (via addCompressionPolicy)
  - Materialized views: `application_stats_daily`, `application_stats_hourly` (continuous aggregate με refresh policy)
  - Indexes: (appid, time DESC), (country_code, time DESC)
  - × 3 databases με fallback σε MySQL/PostgreSQL (retention policy via framework_policies)
  - Integration tests: hypertable verification × TimescaleDB, retention job via Policy Engine × MySQL/PostgreSQL

- [x] **`000037c: add_user_app_authorizations_table`**
  - PK: id (BIGINT AUTO_INCREMENT / BIGSERIAL)
  - Columns: userid (FK → users.userid CASCADE), appid (FK → applications.appid CASCADE), 
    scope (TEXT), status (VARCHAR 50: 'active'/'revoked'/'expired'), 
    revoked_at (TIMESTAMP NULL), granted_at (TIMESTAMP), requested_by (VARCHAR 255)
  - Indexes: (userid, appid), (userid, revoked_at), (appid, status)
  - Unique constraint: (userid, appid) — ένας χρήστης μία εξουσιοδότηση ανά app
  - × 3 databases

##### Foreign Key Συγχρονισμός (HIGH PRIORITY)
- [x] **Missing FK σε `usertokens` (migration 000014):**
  - Προσθήκη: `parentToken` → `usertokens.tokenid` (SET NULL)
  - Προσθήκη: `applicationid` → `applications.appid` (SET NULL) — στήλη υπάρχει, FK λείπει
  - Alter migration με rollback safety
  - *Καλύπτεται από `core/000050-AddMissingForeignKeysToExistingTables.php`*

- [x] **Missing FK σε `tokenactions` (migration 000016):**
  - Προσθήκη: `tokenid` → `usertokens.tokenid` (CASCADE)
  - Προσθήκη: `urlid` → `urls.urlid` (CASCADE)
  - *Καλύπτεται από `core/000050-AddMissingForeignKeysToExistingTables.php`*

- [x] **Missing FK σε `applications` (migration 000025):**
  - Προσθήκη: `owner` → `users.userid` (SET NULL)
  - *Καλύπτεται από `core/000050-AddMissingForeignKeysToExistingTables.php`*

- [ ] **Missing FK σε `users` (migration 000010):**
  - Προσθήκη: `locationid` → `locations.locationid` (SET NULL) — *αν υπάρχει locations table στη parent app*

- [x] **Missing FK σε GDPR tables (migrations 000021-000025):**
  - `user_activity_log.userid` → `users.userid` (CASCADE)
  - `user_privacy_settings.userid` → `users.userid` (CASCADE)
  - `user_consents.userid` → `users.userid` (CASCADE)
  - `data_processing_records.userid` → `users.userid` (CASCADE)
  - `gdpr_requests.userid` → `users.userid` (CASCADE)
  - *Καλύπτεται από `core/000050-AddMissingForeignKeysToExistingTables.php`*

##### Triggers & Functions (MEDIUM PRIORITY)
- [x] **`applications.update_updated_at_column()` trigger function**
  - Εκτελεί: `SET updated_at = NOW()` κατά UPDATE της `application_settings`
  - Backends: PostgreSQL/TimescaleDB (PL/pgSQL), MySQL (TRIGGER FOR EACH ROW)
  - Ενσωματώνεται σε migration 000044 (application_settings)

- [x] **`authserver.sync_consent_timestamp()` trigger function**
  - Εκτελεί: `SET updated_at = NOW()` κατά INSERT/UPDATE της `oauth2_user_consents`
  - Backends: PostgreSQL/TimescaleDB (PL/pgSQL), MySQL (TRIGGER FOR EACH ROW)
  - Αν migration 000042 (oauth2_user_consents) υπάρχει χωρίς trigger, προσθήκη μέσω ξεχωριστής alter migration

##### Views (HIGH PRIORITY)

###### Applications Schema Views (10 views)
- [x] **`applications.api_performance_summary` VIEW**
  - Aggregates: response times (avg, min, max), success rates, method analysis per appid
  - Columns: appid, avg_response_time, min_response_time, max_response_time, success_rate, total_requests
  - Source: `application_stats` με time bucketing (last 24h ή aggregation interval ρυθμιζόμενο)

- [x] **`applications.application_health` VIEW**
  - Health indicators per app: error_rate, avg_latency, throughput (requests/min)
  - Columns: appid, overall_status ('healthy'/'degraded'/'unhealthy'), error_rate, avg_latency, throughput, last_update
  - Source: `application_stats` + rule thresholds

- [x] **`applications.application_stats_daily` VIEW (Materialized, PostreSQL/TimescaleDB)**
  - Ημερήσιες aggregates του `application_stats` — 1 row/app/day
  - Columns: appid, date, total_requests, successful_requests, avg_response_time, status distribution
  - Refresh policy: daily ή κατ' απαίτηση

- [x] **`applications.application_stats_hourly` VIEW (Materialized)**
  - Ωριαίες aggregates — 1 row/app/hour
  - Refresh policy: every hour

- [x] **`applications.rate_limit_status` VIEW**
  - Current rate limiting state per app
  - Columns: appid, requests_in_current_window, limit, remaining, resets_at, is_limited (BOOLEAN)
  - Source: real-time calculation από `application_stats` + `application_settings`

- [x] **`applications.slow_api_calls` VIEW**
  - Calls exceeding 5 second threshold
  - Columns: appid, method, endpoint, response_time, timestamp, ip_address, status_code
  - Source: `application_stats` με WHERE avg_response_time > 5000 (ms)

- [x] **`applications.ip_violations` VIEW**
  - IPs που παραβιάζουν `application_settings.ip_lock_enabled` rules
  - Columns: appid, ip_address, violation_count, first_attempt, last_attempt, status
  - Source: `application_stats` με JOIN σε `application_settings` IP whitelist/blacklist

- [x] **`applications.oauth2_active_tokens` VIEW**
  - Active OAuth tokens by status (not expired, not revoked)
  - Columns: appid, token_count, expired_count, revoked_count, avg_expiry_days
  - Source: `usertokens` + `oauth2_user_consents`

- [x] **`applications.usage_statistics` VIEW (Materialized)**
  - Aggregate usage metrics per app — total requests, unique users, bandwidth, top countries
  - Columns: appid, total_requests, unique_users, bytes_transferred, top_country_codes (JSON), period (last 30/90/365 days)

- [x] **`applications.top_applications` VIEW**
  - Applications ranked by usage volume
  - Columns: rank (ROW_NUMBER), appid, total_requests, successful_requests, avg_response_time

###### AuthServer Schema Views (8 views)
- [x] **`authserver.alert_high_failure_rate` VIEW**
  - Authentication failures spike detection — προειδοποίηση αν failure rate > threshold
  - Columns: alert_id, severity, message, affected_users, trigger_time, resolvable_at
  - Source: `twofactor_attempts` + `loginlockout` με trend analysis

- [x] **`authserver.alert_suspicious_ips` VIEW**
  - Suspicious IP activity detection
  - Columns: ip_address, suspicious_score, reason (failed_logins_in_timewindow, geographic_anomaly, κλπ), 
    attempt_count, last_seen, recommended_action
  - Source: `loginlockout` + `user_activity_log` με heuristics

- [x] **`authserver.daily_2fa_stats` VIEW (Materialized)**
  - Daily 2FA usage aggregates — completions, failures, average time
  - Columns: date, total_2fa_attempts, successful_completions, failed_attempts, avg_completion_time_seconds
  - Source: `twofactor_attempts` hypertable + continuous aggregate
  - Refresh policy: daily

- [x] **`authserver.failed_twofactor_summary` VIEW**
  - 2FA failures in last hour, 3+ attempts per user flagged
  - Columns: userid, failed_attempts, last_failure_time, account_status_recommendation
  - Source: `twofactor_attempts` με WHERE created_at > NOW() - INTERVAL '1 hour'

- [x] **`authserver.gdpr_compliance_report` VIEW**
  - User data processing and consent summary
  - Columns: userid, consents_given, data_retention_days, deletion_requested, export_requested, last_processing_date
  - Source: `user_consents` + `user_privacy_settings` + `gdpr_requests`

- [x] **`authserver.geographic_analysis` VIEW**
  - Login locations and geographic patterns
  - Columns: userid, country_code, city, last_login, login_count_7days, login_count_30days, anomaly_flag (BOOLEAN)
  - Source: `user_activity_log` + geolocation enrichment

- [x] **`authserver.oauth2_active_tokens` VIEW** *(δύο ίδια ονόματα στο applications και authserver)*
  - Active OAuth tokens by app — authserver-wide overview
  - Columns: appid, token_count, by_grant_type (JSON), by_status (JSON)
  - Source: `usertokens` + grant type analysis

- [x] **`authserver.recent_twofactor_attempts` VIEW**
  - 2FA activity last 24h
  - Columns: userid, attempt_timestamp, success (BOOLEAN), method (totp/backup), device_fingerprint
  - Source: `twofactor_attempts` με WHERE created_at > NOW() - INTERVAL '1 day'

##### Ενημέρωση Υπάρχοντος Κώδικα
- [x] **Schema Repositioning:** `slow_api_calls` view — dropped from authserver schema (migration 000048), consolidated under `applications.slow_api_calls` (migration 000046)
- [ ] Συγχρονισμός indexes, comments, default values με UrbanWater schema
- [x] Ενημέρωση docs/1.2-new-features.md με τα νέα migration/schema elements

#### Open Jira Issues προς ενσωμάτωση
- **PF-9:** Native caching σε views (όχι μόνο manual)
- **PF-40:** Υποστήριξη group by επιλογής στο datatable UI (όχι μόνο backend)
- ~~**PF-43:** Database-driven CORS policy enforcement~~ ✅ `CorsMiddleware::fromApplicationSettings(appName, ?db)` + `fromCorsData(enabled, rawOrigins)` + `getAllowedOrigins()`. `Api::exec()` χρησιμοποιεί `fromApplicationSettings()` όταν `cors_from_db: true` στο `applicationInfo`. Fallback σε wildcard όταν η `application_settings` δεν υπάρχει / η DB δεν είναι συνδεδεμένη. 11 unit tests.

> Τα παρακάτω είναι **υποχρεωτικά follow-ups** που εντοπίστηκαν από τα νέα framework-native characterization tests. Παραμένουν εδώ ως ενεργό backlog και κλείνουν σταδιακά με ξεχωριστά commits.

- [x] **`Adjacencylist::getPathAsArray()` — namespace bug στο `stdClass`:** Στο `Pramnos\\Database\\Adjacencylist` γίνεται `new stdClass()` χωρίς leading `\\`, με αποτέλεσμα runtime error (`Pramnos\\Database\\stdClass not found`).
- [x] **`Datasource::render()` — count subqueries fallback σε `0`:** Σε αποτυχία των count subqueries τα `iTotalRecords` / `iTotalDisplayRecords` επιστρέφουν `0` από catch path. Να διορθωθεί ο μηχανισμός count ώστε να δίνει σταθερά σωστό total/display total.
- [x] **`Logger` hard dependency στο `LOG_PATH`:** Πολλαπλά code paths (Logger, Datasource error logging, Migration execute logging) προϋποθέτουν ορισμένο `LOG_PATH`. Να προστεθεί ασφαλές default/fallback ώστε να μην σπάνε flows/tests όταν λείπει το constant.
- [x] **`Logger` PSR-3 level-loss με empty context:** Σε `warning()/info()/debug()/notice()/emergency()/critical()/alert()` με κενό context, το level δεν αποτυπώνεται στο output επειδή αφαιρείται πριν την απόφαση JSON-vs-plain formatting. Να διατηρείται το level ανεξάρτητα από extra context.
- [x] **`Model::_generateSpecificCacheKey()` unresolved placeholders:** Όταν το `_dbtable` κρατά unresolved `#PREFIX#`, το παραγόμενο cache key διατηρεί token (`<id>-#PREFIX#table`). Να κανονικοποιείται πλήρως πριν τη δημιουργία cache key.
- [x] **`Model::_getList()` payload filtering with `useGetData` + `queryFields`:** Σε συγκεκριμένα query field selections, το post-filtering μπορεί να αφαιρεί όλα τα scalar keys και να επιστρέφει κενά arrays ανά row. Να διορθωθεί η αντιστοίχιση selected fields → returned keys.
- [x] **`Model::_getApiList()` paginated error envelope on field-selection paths:** Για ορισμένους συνδυασμούς `fields`/pagination, ο paginated κλάδος επιστρέφει `error` + `pagination = null` αντί κανονικού pagination block. Να σταθεροποιηθεί ο paginated query path.
- [x] **`Api\\Apikey::getList()` result iteration contract:** Η μέθοδος κάνει `foreach ($result as $app)` και προσπελαύνει `$app->fields`, αλλά σε legacy paths το iterated item δεν εγγυάται object με `fields`, προκαλώντας warnings/σπασμένο hydration. Να ενοποιηθεί στο `while ($result->fetch())` pattern.
- [x] **Coverage artifact inconsistency (`dockertest --coverage`):** Το HTML report ανανεώνεται (`coverage/index.html`, `coverage/dashboard.html`) αλλά το `coverage/clover.xml` μένει stale (παλιό mtime), με αποτέλεσμα λανθασμένη XML-based ανάλυση. Να ευθυγραμμιστεί η παραγωγή artifacts ώστε το Clover XML να ανανεώνεται στον ίδιο κύκλο. *Fixed: `--coverage-clover coverage/clover.xml` added to `./dockertest --coverage` command so HTML + XML are always regenerated in the same PHPUnit pass.*

---

*Σημείωση: Οποιεσδήποτε υπάρχοντες μέθοδοι αντικαθίστανται από νεότερες, θα χαρακτηρίζονται ως `@deprecated` στα σχόλια, αλλά θα συνεχίζουν να υποστηρίζονται κανονικά σε αυτό το release circle.*

---

## 🚀 Νέες Φάσεις Εκσυγχρονισμού (v1.2+)

### 🏗️ Φάση 6: Dependency Injection & PSR Compliance ✅
- [x] **PSR-11 Service Container:** `Container` (`src/Pramnos/Application/Container.php`) — bind/singleton/instance/make + ReflectionClass autowiring; `NotFoundException` + `ContainerException` exception classes; 10 characterization tests.
- [x] **Constructor Injection:** `Container::make()` resolves constructor type-hints recursively via reflection; named/positional parameter overrides supported.
- [x] **PSR-3 Logger Implementation:** `PsrLogger` (`src/Pramnos/Logs/PsrLogger.php`) — extends `AbstractLogger`; `{placeholder}` interpolation; level validation; `Logger::channel()` factory; 8 characterization tests.
- [x] **PSR-16 Simple Cache:** `SimpleCache` (`src/Pramnos/Cache/SimpleCache.php`) — wraps existing `Cache` class; key validation (reserved chars `{}()/\@:`); TTL normalisation (null/int/DateInterval); `SimpleCacheInvalidArgumentException`; 12 characterization tests.
- [x] **PSR-7/15 HTTP Stack:** `ServerRequestCreator` (`src/Pramnos/Http/Psr/ServerRequestCreator.php`) — `fromGlobals()` + `fromServerParams()`; `Pipeline` (`src/Pramnos/Http/Psr/Pipeline.php`) — FIFO immutable middleware pipeline implementing `MiddlewareInterface`; 11 characterization tests.

### 🛣️ Φάση 7: Modern Routing Engine ✅
*Υλοποιήθηκε πάνω στα υπάρχοντα `Route` / `Router` — 100% BC-safe (νέα additive API).*

- [x] **Attribute-based Routing:** `#[Route]` PHP 8 attribute (`src/Pramnos/Routing/Attributes/Route.php`) — `IS_REPEATABLE`, parameters: `uri`, `methods` (string|array), `name`, `permissions`, `middleware`.
- [x] **Route Discovery:** `RouteDiscovery::discover(string $dir, string $namespace)` (`src/Pramnos/Routing/RouteDiscovery.php`) — recursive `RecursiveIteratorIterator` scan; maps file path → FQCN; reads `#[Route]` via Reflection; registers with Router. `Router::loadFromDirectory()` convenience wrapper.
- [x] **Named Routes & URL Generation:** `Route::name(string $n): static` + `Router::getByName(string $n): ?Route` + `Router::route(string $name, array $params = []): string` — replaces `{param}` / `{param?}` placeholders; rawurlencode values; strips unresolved optional segments. Callback-based registration (no circular dependency). 26 characterization tests.
- [x] **`Router::group()` + `#[RouteGroup]`** — `Router::group(array $attributes, Closure $callback)`: pushes group context (prefix, middleware, permissions, name prefix) onto a stack; `addSingleRoute()` merges the active stack. `#[RouteGroup]` PHP 8 attribute (TARGET_CLASS) auto-detected by `RouteDiscovery`. `Route::prependMiddleware()` added for group middleware ordering. Nested groups stack. 15 unit tests in `RouteGroupTest`.

### 🛡️ Φάση 8: Security & Templating
- [x] **View Auto-escaping:** Σύστημα προστασίας XSS με αυτόματο escaping των μεταβλητών στα templates (με δυνατότητα `raw` bypass). *`View::escape(mixed $value): string` + `View::e()` alias — delegates to global `e()` helper. Templates use `<?= $this->e($var) ?>`.*
- [x] **CSRF Protection:** Native middleware για αυτόματο έλεγχο CSRF tokens σε POST requests. *`CsrfMiddleware` (`src/Pramnos/Http/Middleware/CsrfMiddleware.php`) implements `MiddlewareInterface`; protects POST/PUT/PATCH/DELETE; supports field token + `X-CSRF-Token` header; `CsrfMiddleware::tokenField()` helper for forms; 20 characterization tests.*
- [x] **Secure Headers:** Εύκολος ορισμός CSP (Content Security Policy) και άλλων security headers. *`Application::sendCspHeader()` + `getCspDomains()`: builds `Content-Security-Policy` from `$applicationInfo['csp']` config array; per-request nonce injected automatically; default-src 'none' base + script/style/img/font/connect/frame directives; `upgrade-insecure-requests` included.*

### 🗃️ Φάση 9: Full ORM Layer ✅
*Υλοποιήθηκε ως `OrmModel extends Model` με trait-based αρχιτεκτονική — 100% BC-safe.*

- [x] **Relationships:** `hasOne()`, `hasMany()`, `belongsTo()`, `belongsToMany()` — lazy-loaded via `__get()` + `HasRelationships` trait; relation classes in `src/Pramnos/Application/Orm/Relations/`.
- [x] **Eager Loading:** `with('relation')` — batch query per relation, N+1 prevention via `eagerLoadRelations()`.
- [x] **Scopes:** Local (`scopeXxx()` + `applyScope()`) + Global (`addGlobalScope()` / `withoutGlobalScope()`) — `HasScopes` trait.
- [x] **Model Events:** `creating`, `created`, `updating`, `updated`, `deleting`, `deleted` — `HasEvents` trait; observer pattern via `observe()` + `on()`; cancellation by returning false.
- [x] **Casting:** `int`, `float`, `bool`, `string`, `array`/`json`, `datetime`, `timestamp` — `HasAttributes` trait; `$casts` map; applied on `__get()` / reversed on `__set()`.
- [x] **Accessors / Mutators:** `getXxxAttribute()` / `setXxxAttribute()` convention — auto-detected in `__get()`/`__set()` via `studly()` name derivation.
- [x] **Soft Deletes:** `deleted_at` — `HasSoftDeletes` trait; `softDelete()` / `restore()` / `forceDelete()` / `trashed()` / `withTrashed()` / `onlyTrashed()`.
- [x] **Timestamps:** `created_at` / `updated_at` — `HasTimestamps` trait; `withoutTimestamps()` opt-out.
- [x] **Mass Assignment Protection:** `$fillable` / `$guarded` + `fill()` / `isFillable()` / `isGuarded()` — `HasAttributes` trait.
- [x] **Collections:** `Orm\Collection` — `filter`, `map`, `pluck`, `groupBy`, `sortBy`, `first`, `last`, `count`, `each`, `contains`, `toArray`, `isEmpty`; implements `Countable`, `IteratorAggregate`, `JsonSerializable`.

### 🗄️ Φάση 11: Cache — Ολοκλήρωση & Ενοποίηση
*Το core cache είναι ήδη υλοποιημένο (`Pramnos\Cache\Cache`, PSR-16 `SimpleCache`, adapters: Redis/Memcache/Memcached/File, 14 characterization tests). Αυτό που λείπει είναι η ενοποίηση με τη σύγχρονη υποδομή του framework.*

- [x] **`Cache` class:** file/memcache/memcached/redis adapters, prefix/category/timeout, `load()`/`save()`/`delete()`/`clear()`.
- [x] **`SimpleCache` (PSR-16):** `Psr\SimpleCache\CacheInterface` adapter — `get`, `set`, `delete`, `clear`, `getMultiple`, `setMultiple`, `deleteMultiple`, `has`; TTL ως `int|null|DateInterval`.
- [x] **`AdapterInterface` + adapters:** `FileAdapter`, `MemcacheAdapter`, `MemcachedAdapter`, `RedisAdapter`.
- [x] **Characterization tests:** 14 tests για SimpleCache στο `tests/Characterization/Cache/`.
- [x] **`ArrayAdapter`:** in-memory adapter για unit tests — αντικαθιστά τα hacks με `$_cacheData` arrays στα tests.
- [x] **`Cache::remember($key, $ttl, $callback)`:** lazy-fetch pattern — αν δεν υπάρχει το key, καλεί το callback και το αποθηκεύει.
- [x] **`ArrayAdapter`:** in-memory adapter για unit tests — χωρίς APCu/Redis/file I/O. TTL-aware (lazy expiry), prefix isolation. Εγγεγραμμένο ως `method='array'` στο `Cache::initializeAdapter()`.
- [x] **ServiceProvider integration:** `CacheServiceProvider` που διαβάζει `app.php` και αρχικοποιεί τον default adapter. Feature key `'cache'` εγγεγραμμένο στο `FeatureRegistry`.
- [x] **Rate limiting middleware:** `RateLimitMiddleware` με sliding window μέσω Cache — διαφορετικό από `ThrottleMiddleware` (APCu-only) γιατί δουλεύει με οποιονδήποτε adapter (Array/File/Redis/Memcached). 29 νέα tests (18 ArrayAdapter + 3 remember + 8 RateLimit).

### 📡 Φάση 12: Broadcasting / WebSockets
*Real-time events από server σε browser — αρχιτεκτονικά χωρισμένη από τα model events της Φάσης 9.*

- [x] **`DriverInterface`:** `broadcast(string $channel, string $event, array $payload)` + `name(): string`.
- [x] **`BroadcastingManager`:** manages named drivers; `setDefault()`, `driver()`, `broadcast()`, `via()` — fan-out to specific driver.
- [x] **`NullDriver`:** no-op driver (default) — safe fallback when no transport is configured.
- [x] **`LogDriver`:** writes JSON lines to a log file; `getEntries()` + `clear()` for test assertions.
- [x] **`Broadcastable` trait:** `broadcastCreated()`, `broadcastUpdated()`, `broadcastDeleted()`, `broadcastEvent()` — resolves BroadcastingManager from container; channel defaults to snake_case model name.
- [x] **`BroadcastingServiceProvider`:** registers `'broadcasting'` singleton in container; reads `app.php` `broadcasting.default` + `broadcasting.log_path`; fallback to null driver on misconfiguration.
- [x] **Feature key `'broadcasting'`** registered in FeatureRegistry.
- [x] **Tests:** 15 unit tests — FeatureRegistry, manager defaults, driver registration, setDefault errors, via() routing, LogDriver I/O (write/read/clear), NullDriver.
- [ ] **PusherDriver / ReverbDriver:** προαιρετική εξάρτηση — runtime guard αν δεν είναι εγκατεστημένο.
- [ ] **`pramnos broadcast:serve`** command: ελαφρύ WebSocket server για local dev (Ratchet/ReactPHP).
- [ ] **Client-side helper:** `www/assets/vendor/pramnos-echo/` — minimal JS που κάνει subscribe σε channels.

> **Εξάρτηση:** Φάση 12 εξαρτάται από Φάση 11 (Cache για channel presence/heartbeat tracking).

### 🤖 Φάση 13: AI Developer Tooling (MCP Server + Debug Infrastructure)
*Πρώτης τάξεως υποστήριξη για AI assistants και debuggers — παρόμοια με τo `laravel-mcp-server` και το Laravel Sail Xdebug integration.*

#### MCP Server (Model Context Protocol)
Ένα stdio-based MCP server που εκθέτει την εφαρμογή στον AI assistant (Claude, Copilot κ.λπ.) μέσω εργαλείων και πόρων. Ο AI μπορεί να εξερευνήσει το σχήμα, να τρέξει queries, να ελέγξει migrations — χωρίς να χρειάζεται ξεχωριστό DB MCP server.

- [x] **`Pramnos\Mcp\McpServer`:** JSON-RPC 2.0 over stdio — υλοποιεί το MCP protocol (`initialize`, `tools/list`, `tools/call`, `resources/list`, `resources/read`).
- [x] **`McpToolInterface`:** `name()`, `description()`, `inputSchema(): array`, `execute(array $input): mixed` — apps εγγράφουν custom tools.
- [x] **Built-in tools:**
  - `list-tables` — λίστα πινάκων + row counts από live DB.
  - `query-schema` — πλήρες schema ενός πίνακα (columns, types, indexes, FK).
  - `migration-status` — pending / applied migrations (επιστρέφει ό,τι δείχνει `migrate:status`).
  - `model-inspect` — fillable, casts, relations ενός OrmModel class.
  - `route-list` — λίστα registered routes.
- [x] **`McpResource`:** expose αρχεία ως `file://` resources (CLAUDE.md, config, views).
- [x] **`pramnos mcp:serve`** CLI command: εκκινεί τον MCP server σε stdio mode· κατάλληλο για `.mcp.json` `"command"` entry.
- [x] **`.mcp.json` upgrade:** `scaffolding/templates/mcp.json.stub` τώρα χρησιμοποιεί `php ./bin/pramnos mcp:serve` (zero npm); `Init.php` ενημερώθηκε — δεν προσθέτει πλέον `.mcp.json` στο `.gitignore`; `.mcp.json` προστέθηκε στο framework repo.
- [x] **`McpServiceProvider`:** opt-in εγγραφή μέσω `app.php`; lazy boot (τρέχει μόνο όταν κληθεί `mcp:serve`).
- [x] **Tests:** 25 unit tests — McpServer protocol handling (12), McpResource (4), built-in tools (9).

#### Debug Toolbar (Laravel Debugbar αντίστοιχο)
HTML toolbar που εγχέεται αυτόματα στο κάτω μέρος κάθε HTML response όταν `APP_DEBUG=true`. Zero-dependency, pure PHP+CSS+JS — κανένα npm build step.

- [x] **`Pramnos\Debug\DebugBar`:** κεντρική κλάση — singleton με registered collectors, renders HTML widget; `startTimer()`/`stopTimer()` convenience statics.
- [x] **Collectors:**
  - `QueryCollector` — reads `Database::getQueryLog()` (enabled via `enableQueryLog()`); SQL, time in ms, total.
  - `TimeCollector` — wall-clock request time + named timers via `DebugBar::startTimer('name')` / `stopTimer('name')`.
  - `MemoryCollector` — peak + current memory usage (`memory_get_peak_usage`).
  - `RouteCollector` — stores route data via `setRoute()`; returns matched URI, method, action.
  - `LogCollector` — ring-buffer up to N entries; populated via `addEntry()`.
  - `SessionCollector` — session keys/values; sensitive patterns masked with `***`.
- [x] **HTML renderer:** self-contained `<div id="pramnos-debugbar">` με inlined CSS+JS (Catppuccin Mocha theme) — collapsible tabs, SQL table with slow-query highlighting.
- [x] **Middleware integration:** `DebugBarMiddleware` injects widget before `</body>`; passes non-HTML responses through unchanged.
- [x] **`DebugBarServiceProvider`:** only activates when `APP_DEBUG=true` or `debug` setting; uses `ob_start()` callback to inject toolbar — zero router dependency.
- [x] **`pramnos debug:status`** command: prints APP_DEBUG, debug setting, Xdebug loaded/version/mode/port.
- [x] **Database query logging:** `Database::enableQueryLog()`, `getQueryLog(): array`, `clearQueryLog()` — opt-in, zero overhead when disabled.
- [x] **Tests:** 16 unit tests (DebugBar core, all 6 collectors, middleware injection, non-HTML passthrough).

> **Εξάρτηση:** Φάση 13 είναι ανεξάρτητη — μπορεί να υλοποιηθεί παράλληλα με Φάση 11/12.

### 🖥️ Φάση 14: DevPanel — Developer / Admin Dashboard
*Web-accessible admin panel ενσωματωμένο στο framework. Εμπνευσμένο από το dashboard του UrbanWater (`src/Controllers/Home.php` + `src/Controllers/Users.php`). Μετά την υλοποίηση το UrbanWater θα αντικαθιστήσει τον κώδικα Home/Users με thin wrappers (βλ. UrbanWater-Cleanup-Guide.md).*

Ενεργοποιείται opt-in μέσω feature registry. Mount point ρυθμιζόμενο στο `app.php` (default: `/devpanel`). Προστατεύεται από admin policy — εξ ορισμού απαιτεί `usertype >= 90` ή configurable policy callback.

#### Overview & System Info
- [x] **`Pramnos\DevPanel\DevPanelController`:** base controller — auto-discovered via `Pramnos\Application\Controllers\Devpanel` bridge; auth policy usertype >= 90 (configurable) or custom `$policyCallback`.
- [x] **Overview panel:** DB type/version, PHP version, framework version, uptime, git HEAD (branch + short hash + subject), migration status, queue stats.
- [x] **System info:** CPU load, RAM total/used/free — reads `/proc/meminfo` + `/proc/loadavg` without shell_exec.
- [x] **Migration status:** pending/applied count, last applied slug (via MigrationLoader + MigrationRunner).
- [x] **Queue stats:** pending / running / failed jobs (only when 'queue' feature enabled).

#### Database Panel
- [x] **Database stats (cross-DB):** top 30 tables by size — MySQL (`information_schema`) and PostgreSQL (`pg_class`) separate query paths.
- [x] **TimescaleDB sub-panel:** auto-detected via `pg_extension` — hypertables with chunk count + compression_enabled.

#### Cache Browser
- [x] **Cache stats:** adapter type (`$cache->method`), total items, namespace count from `getStats()`.
- [x] **Clear all (POST flush):** POST `action=flush` to `/devpanel?action=cache` — returns JSON `{ok: true/false}`.
- [x] **Item browser:** top-100 items per namespace — key, type/NS, size, TTL, created — namespace filter bar from `getCategories()`.
- [x] **Item inspector (AJAX):** GET `?action=cache&key=X` → JSON `{ok, key, content}` — `var_export()` truncated to 50 KB.

#### User Activity & Security
- [x] **Active sessions:** top 50 active tokens (web + API) with username, IP, application, last_used.
- [x] **Login security monitor:** active lockouts — identifier, IP, failed attempts, lockout_until.
- [x] **Token detail page:** paginated action history per token — `?action=users&token=X`, 50/page, back link.
- [x] **User log (per-user):** `userlog` entries for a specific user — `?action=users&user=X`, 50/page, unix-ts date formatted.

#### Performance Report
- [x] **Slowest endpoints:** URL + method, call count, avg/max ms — time range selector (1h / 6h / 24h / 7d / 30d) from `tokenactions`.
- [x] **Slowest users/applications:** userid, username, app name, call count, avg/max ms (JOIN tokenactions→tokens→users→applications).
- [x] **Pluggable panels:** `DevPanel::registerPanel(string $slug, callable $renderer)` — apps προσθέτουν custom tabs. `__call()` dispatch + nav integration.

#### Git Info Widget
*Reads `.git/HEAD` / `.git/objects/` directly — zero `exec()`.*
- [x] **`Pramnos\Framework\GitInfo`:** `getBranch()`, `getHash()`, `getShortHash()`, `getSubject()`, `getAuthor()`, `getDate()`, `getLocalBranches()`, `getRemotes()` — pure PHP, no shell. 14 unit tests.
- [x] **`Pramnos\DevPanel\GitInfo`:** thin alias extending `Framework\GitInfo`.
- [x] **DevPanel git tab:** full branch/commit details + local branches + remotes list.

#### PHP Info
- [x] **`/devpanel/phpinfo`:** phpinfo() wrapper — admin-only; strips outer html/head/body; self-contained CSS.

#### DevPanel ServiceProvider & Routing
- [x] **`DevPanelServiceProvider`:** validates config on boot; no explicit route registration — uses framework controller auto-discovery.
- [x] **Assets:** Catppuccin Mocha dark theme CSS inlined in controller — no app theme dependency.
- [x] **Tests:** 9 unit tests — FeatureRegistry ('devpanel' key), class hierarchy, auth guard, ServiceProvider, GitInfo alias.

> **Εξάρτηση:** DevPanel χρησιμοποιεί Cache (Φάση 11) και Auth (Φάση 2/4). Μπορεί να υλοποιηθεί σταδιακά — κάθε panel ανεξάρτητα.

### 🔀 Φάση 15: Unified Application — Route Groups & API/Web Convergence
*Σήμερα κάθε Pramnos project στήνει δύο ξεχωριστές εφαρμογές: `Application` (MVC/web) και `Api extends Application` (REST). Αυτή η φάση τις ενώνει σε μία, με route groups που φέρουν τα δικά τους middleware — ακριβώς όπως το Laravel (`routes/web.php` + `routes/api.php`) και το Symfony (firewall per route prefix). BC: ο υπάρχων `Api` class παραμένει ως pre-configured sugar wrapper.*

#### Πρόβλημα σήμερα
Ένα project σαν το UrbanWater έχει:
- `www/index.php` → `new Application()` → `app/app.php` → controllers σε `Urbanwater\Controllers\`
- `www/api/index.php` → `new Api('api')` → `app/api.php` → controllers σε `Urbanwater\Api\Controllers\`

Δύο ξεχωριστά bootstrap, δύο configs, δύο namespaces — ενώ μοιράζονται DB, models, service providers, settings.

#### Λύση: Route Groups
Ένα `Application`, ένα `app/app.php`, ένα entry point. Η διαφορά web vs API ορίζεται στο routing layer:

```php
// app/routes.php
$router->group([
    'prefix'     => '/api/1.0',
    'middleware' => [CorsMiddleware::class, ApiAuthMiddleware::class, JsonResponseMiddleware::class],
    'namespace'  => 'Urbanwater\\Api\\Controllers',
], function ($r) {
    $r->get('/users',       'Users@index');
    $r->post('/users',      'Users@store');
    $r->get('/users/{id}',  'Users@show');
});

$router->group([
    'middleware' => [WebAuthMiddleware::class, CsrfMiddleware::class],
    'namespace'  => 'Urbanwater\\Controllers',
], function ($r) {
    $r->get('/users',       'Users@index');
});
```

Ή ισοδύναμα με annotations — οι δύο τρόποι παράγουν τα ίδια Route objects:

```php
// Μία φορά στο class, κληρονομείται από όλα τα methods
#[RouteGroup(prefix: '/api/1.0', middleware: [CorsMiddleware::class, ApiAuthMiddleware::class])]
class UsersApiController
{
    #[Route('/users',      methods: 'GET')]
    public function index() {}

    #[Route('/users/{id}', methods: 'GET')]
    public function show(int $id) {}
}
```

#### Υλοποίηση

- [x] **`Router::group(array $attrs, callable $cb)`:** stack-based context (prefix, middleware, namespace) που κληρονομείται από routes εντός του callback. Nested groups συσσωρεύουν prefix + middleware. *(υλοποιήθηκε στη Φάση 7)*
- [x] **`#[RouteGroup]` PHP attribute (`TARGET_CLASS`):** `prefix`, `middleware`, `namespace` — επεξεργάζεται από `RouteDiscovery` και εφαρμόζεται σε όλα τα method-level `#[Route]` του class. Συμβατό με υπάρχον method-level `middleware` (merge, όχι override). *(υλοποιήθηκε στη Φάση 7)*
- [x] **Built-in middleware για API groups:**
  - `CorsMiddleware` — `Access-Control-Allow-Origin` + preflight `OPTIONS` handling. *(υπήρχε ήδη)*
  - `JsonResponseMiddleware` — θέτει `Content-Type: application/json` (ή `application/xml` αν HTTP_ACCEPT το ζητά). 4 unit tests.
  - `ApiAuthMiddleware` — API key / Bearer token validation; short-circuits με JSON error envelope σε αποτυχία. 7 unit tests.
- [x] **`Api` class refactor (BC-safe):** το `Api::exec()` γίνεται thin wrapper που τρέχει `CorsMiddleware → JsonResponseMiddleware → ApiAuthMiddleware` pipeline. Κύρια λογική εξαιρέθηκε στο `_executeCore()`. `cors_origins` configurable μέσω `applicationInfo`. Συμπεριφορά αμετάβλητη.
- [x] **Single config:** `app/app.php` αποκτά ένα `'api'` section (`prefix`, `cors_origins`, `version`) — δεν χρειάζεται ξεχωριστό `app/api.php`. Το ξεχωριστό config παραμένει supported για BC. Παράγεται μόνο αν `--rest-api=y`.
- [x] **Scaffolding update:** `pramnos init` ρωτάει «Scaffold a REST API layer? [y/N]» (Step 2b) — αν ναι, δημιουργεί `src/Api/Controllers/` + `src/Api/routes.php` με Router::group() παράδειγμα, **χωρίς** ξεχωριστό entry point. 4 unit tests στο `InitCommandUnitTest.php`.
- [x] **Integration test:** API routes επιστρέφουν JSON και web routes επιστρέφουν HTML από το ίδιο `Application` instance. (`ApiWebConvergenceTest` — 6 tests: 403 JSON missing key, 401 JSON invalid key, pass-through with valid key, web pipeline no auth, same request handled differently by API vs web pipeline, error envelope structure).

> **BC:** `Pramnos\Application\Api`, `www/api/index.php` με `new Api(...)`, και ξεχωριστό `app/api.php` συνεχίζουν να λειτουργούν αναλλοίωτα. Δεν υπάρχει deprecation — η νέα προσέγγιση είναι additive.

> **UrbanWater migration:** βλ. `UrbanWater-Cleanup-Guide.md` Phase 7 (προστίθεται όταν αρχίσει η υλοποίηση).

### 🔑 Φάση 16: SPA-style Auth — Session Cookie ως API Credential
*Εμπνευσμένο από το Laravel Sanctum (cookie-based SPA auth). Σκοπός: ο web χρήστης να καλεί API endpoints απευθείας από JS χωρίς ξεχωριστό login, χωρίς duplicate controller methods, και με πλήρη audit trail.*

#### Πρόβλημα σήμερα

Όταν ένας developer χρειάζεται AJAX data στο web app, αναγκάζεται να γράψει duplicate μέθοδο στο web controller:

```php
// Web controller — duplicate, μόνο για AJAX
public function getUsersJson(): string {
    Factory::getDocument('json');
    return json_encode(User::getList());   // ίδιο με Api\Users@index
}
```

Ενώ το API endpoint `GET /api/1.0/users` ήδη υπάρχει. Ο λόγος που δεν το χρησιμοποιεί: ο web χρήστης δεν έχει Bearer token — έχει μόνο session cookie. Το υπάρχον workaround στέλνει το **password hash** ως auth header (`$_SESSION['auth'] == $_SERVER['HTTP_USERAUTH']`) — insecure και fragile.

| | Web session | API token |
|---|---|---|
| Εγγραφή στο `usertokens` | ✗ | ✓ |
| `tokenactions` audit log | ✗ | ✓ |
| AJAX → API endpoint | password hash header (!) | Bearer token |
| Revocation | session destroy μόνο | real-time token invalidation |

#### Λύση: Cookie-based SPA Auth (Sanctum pattern)

Το session cookie **γίνεται** αποδεκτό ως credential από τα API endpoints — ακριβώς όπως το κάνει το Laravel Sanctum. Το JS δεν χρειάζεται Bearer token: στέλνει το session cookie (αυτόματα, same-origin) + `X-CSRF-Token` header:

```js
// JS στο web app — καλεί ΑΠΕΥΘΕΙΑΣ το API endpoint
fetch('/api/1.0/users', {
    headers: { 'X-CSRF-Token': document.querySelector('meta[name=csrf]').content }
}).then(r => r.json()).then(data => /* ... */);
// Κανένας Bearer token, κανένο duplicate controller
```

Ταυτόχρονα, κάθε web login δημιουργεί εγγραφή στο `usertokens` (`tokentype = 'web_session'`) — έτσι αποκτά ενιαίο audit trail με τους API clients.

```
Web login  → usertokens (web_session) + session cookie  → same-origin AJAX ή web page
API client → usertokens (api/mobile)  + Bearer token    → cross-origin AJAX ή native app
```

#### Τι ΔΕΝ είναι αυτό
Δεν είναι OAuth2. Το OAuth2 server (authserver feature, Φάση 2) εξυπηρετεί **τρίτους clients** που χρησιμοποιείται delegated access. Η cookie-based SPA auth εξυπηρετεί **first-party clients** (ο ίδιος browser, ο ίδιος χρήστης). Οι δύο συνυπάρχουν.

#### Υλοποίηση

- [x] **`UnifiedAuthMiddleware`:** Εφαρμόζεται στο API route group (Φάση 15). Ελέγχει με σειρά:
  1. `Authorization: Bearer <value>` → φορτώνει token από `usertokens`, χρησιμοποιεί τα explicit scopes του token
  2. Session cookie + `X-CSRF-Token` header → αναγνωρίζει `$_SESSION['usertoken']`, περνάει `['*']` ως `$userPermissions`
  - Ο `Router::hasScope()` ήδη υποστηρίζει `'*'` wildcard (→ `return true` για οποιοδήποτε scope check). Καμία αλλαγή στο Router δεν χρειάζεται.
  - `['*']` ≠ bypass application auth. Ο controller εξακολουθεί να ελέγχει `$user->usertype`, policy κλπ. Η διαφορά:

  | Layer | Bearer token | Session cookie |
  |---|---|---|
  | Scope check (`Router`) | explicit scopes του token | `*` → πάντα true |
  | App auth (controller) | `$user->usertype`, policy | ← αμετάβλητο |

- [x] **`tokentype` constants στο `Token`:** `Token::TYPE_WEB_SESSION`, `TYPE_API`, `TYPE_ACCESS_TOKEN`, `TYPE_REFRESH_TOKEN`, `TYPE_AUTH_CODE`, `TYPE_APNS`, `TYPE_GCM` — αντικαθιστούν τα arbitrary strings (TYPE_MOBILE αφαιρέθηκε — mobile apps χρησιμοποιούν OAuth2).
- [x] **Web login → token creation:** `User::createWebSessionToken()` δημιουργεί `Token` (`TYPE_WEB_SESSION`), αποθηκεύει στη session. `$_SESSION['auth']` παραμένει για BC.
- [x] **Web logout → token invalidation:** `User::invalidateWebSessionToken()` αδρανοποιεί το token στη βάση + αφαιρεί από session.
- [x] **`Application::exec()` → `addAction()`:** Αν `$_SESSION['usertoken']` υπάρχει με `TYPE_WEB_SESSION`, καλεί `addAction()` — web requests καταγράφονται στο `tokenactions`.
- [x] **CSRF meta tag helper:** `CsrfMiddleware::csrfMeta()` → `<meta name="csrf" content="...">` — τυπικό Sanctum/Rails pattern για JS.
- [x] **Deprecation:** `HTTP_USERAUTH` + password-hash bridge marked `@deprecated since v1.2` στο `ApiAuthMiddleware`.
- [x] **Tests:** 12 unit tests (`UnifiedAuthMiddlewareTest`) — no-credentials, session cookie + CSRF, Bearer path, X-XSRF-TOKEN alias, token type constants. Όλα pass.

> **Εξάρτηση:** Φάση 16 εξαρτάται από Φάση 15 (`UnifiedAuthMiddleware` ενσωματώνεται στο API route group). Η cookie SPA auth **δεν απαιτεί** OAuth — συνυπάρχει με το authserver (Φάση 2).

### 📊 Φάση 17: Universal List API & Widget-agnostic Data Grid
*Σήμερα `_getJsonList()` επιστρέφει DataTables 1.9 legacy format (`aaData`/`sEcho`) hardcoded — μόνο για jQuery DataTables. Λειτουργεί cross-DB (MySQL μέσω `SHOW COLUMNS`, PostgreSQL/TimescaleDB μέσω `information_schema`) αλλά ο format εξόδου είναι locked στο DataTables 1.9 API. Η `_getApiList()` επιστρέφει clean REST format αλλά το DataTables widget δεν το διαβάζει. Αυτή η φάση ενώνει τα δύο και κάνει τον server widget-agnostic: DataTables και Grid.js καταναλώνουν το ίδιο API endpoint μέσω thin adapters (πρώτη φάση).*

#### Πρόβλημα σήμερα

```
DataTables widget → web controller method → _getJsonList() → aaData/sEcho (DT 1.9 format)
Grid.js → ✗ δεν υποστηρίζεται
JS AJAX → API controller → _getApiList() → {items, pagination} (clean REST)
```

Δύο ξεχωριστά code paths για το ίδιο πράγμα — paginated/filtered/searchable list. Κάθε model που θέλει DataTables support γράφει custom `getJsonList()`.

#### Λύση: Ενιαίο server endpoint, widget adapters στο client

```
Οποιοδήποτε widget → thin JS adapter → GET /api/1.0/model?page=1&search=...&fields=...
                                         ↓
                                    _getApiList() → {items, pagination, fields, total}
                                         ↓
                      thin JS adapter ← response (widget-specific format mapping)
```

Ο server δεν ξέρει ποιο widget μιλάει μαζί του. Η μετάφραση γίνεται εξ ολοκλήρου στο client.

#### Υλοποίηση — Server side

- [x] **`_getApiList(format: 'datatables')`:** νέο optional parameter — wraps την `{items, pagination}` απόκριση στο DataTables 2.x format (`{draw, data, recordsTotal, recordsFiltered}`). Εσωτερική λεπτομέρεια, χωρίς αλλαγή στη δημόσια API.
- [x] **`_getJsonList()` → delegate:** `_getJsonList()` καλεί εσωτερικά `_getApiList()` και αποδίδει DT 1.9 envelope για BC. Κανένα υπάρχον `getJsonList()` override στο Urbanwater δεν σπάει.
- [x] **Ενοποίηση column introspection:** `_getJsonList()` μεταβαίνει στο `_getAllTableFields()` αντί για inline `SHOW COLUMNS` / `information_schema` query — εξαλείφει τον duplicated κώδικα και ενοποιεί τη λογική introspection σε ένα σημείο.
- [x] **`_getJsonList()` marked `@deprecated`** στο docblock — δεν αφαιρείται, αλλά η τεκμηρίωση κατευθύνει τους νέους developers στο `_getApiList()`.

#### Υλοποίηση — Client side (JS adapters)

Κάθε adapter είναι ένα μικρό JS module (`www/assets/vendor/pramnos/`) που μεταφράζει το widget protocol → API format:

- [x] **`PramnosDataTable` adapter:** DataTables 2.x serverSide mode — μεταφράζει `{draw, start, length, search, order, columns}` → `?page=N&search=...&order=...&fields=...`. Αντικαθιστά το legacy `_getJsonList()` pattern. Config: `data-dt-api="/api/1.0/users"`.
- [x] **`PramnosGridJS` adapter:** Grid.js `server` config — μεταφράζει Grid.js pagination/search params → API format, αντιστοιχεί `{items, pagination}` → `{data, total}`. Vanilla JS, χωρίς jQuery εξάρτηση.
- [x] **CSRF header injection:** Και οι δύο adapters προσθέτουν αυτόματα `X-CSRF-Token` από το `<meta name="csrf-token">` (Φάση 16) — λειτουργούν χωρίς Bearer token όταν ο χρήστης είναι session-authenticated.

#### Αποτέλεσμα για τον developer

```html
<!-- Πριν: ξεχωριστή μέθοδος στον controller + inline DT config -->
<!-- Μετά: table με data-dt-api, κανένας server-side κώδικας δεν χρειάζεται -->
<table id="users-table" data-dt-api="<?php echo sURL;?>users/getApiList?format=datatables">
</table>
<script>
$(function() { PramnosDataTable.init('#users-table', { columns: [...] }); });
</script>
```

- [x] **Scaffolding:** `create:model` παράγει `getApiList()` override μόνο (χωρίς `getJsonList()`). Η view template για list pages χρησιμοποιεί `PramnosDataTable.init()` αντί για inline DataTables config.
- [x] **Tests:** server-side: `_getApiList(format: 'datatables')` output format validation × MySQL + PostgreSQL; `_getJsonList()` introspection unification tests × MySQL + PostgreSQL. Client-side: adapter unit tests (mock fetch) — pending.
- [ ] **UrbanWater migration:** βλ. `UrbanWater-Cleanup-Guide.md` Phase 8 — custom `getJsonList()` overrides μεταφέρονται σταδιακά.

> **BC:** `_getJsonList()` και όλα τα custom `getJsonList()` overrides στο Urbanwater συνεχίζουν να λειτουργούν αναλλοίωτα. Η μετάβαση γίνεται model-by-model, view-by-view.
> **Εξάρτηση:** Φάση 17 εξαρτάται από Φάση 16 (CSRF header για session-authenticated AJAX) και Φάση 15 (API route group).

### 📖 Φάση 18: API Documentation — Scaffolding & Βελτίωση Script
*Στο Urbanwater υπάρχει ήδη λειτουργικό documentation pipeline: το `scripts/apidoc-to-openapi.js` (~1400 γραμμές) διαβάζει `@api*` PHPDoc annotations και παράγει OpenAPI 3.0 JSON + interactive RapiDoc viewer. Δεν υπάρχει λόγος να ξαναγραφεί σε PHP — Node.js ως dev dependency είναι απολύτως αποδεκτό. Αυτή η φάση το καθαρίζει, το γενικεύει, και το εντάσσει στο scaffolding ώστε κάθε νέο project να ξεκινάει με έτοιμη documentation υποδομή.*

#### Τι γίνεται

- [ ] **Αφαίρεση hardcoded τιμών** από το `apidoc-to-openapi.js`: όλα τα Urbanwater-specific στοιχεία (`hydrigital.com`, `Hydrigital REST API`, `#4CAF50`, server URLs) μπαίνουν στο `api-doc.json` config
- [ ] **Εμπλουτισμός `api-doc.json`** με τα νέα πεδία:
  ```json
  {
    "name": "My App REST API",
    "title": "My App REST API",
    "description": "...",
    "url": "https://api.myapp.com/api",
    "sampleUrl": "https://api.myapp.com/api",
    "theme": "dark",
    "primaryColor": "#4CAF50",
    "authSchemes": ["apiKey", "accessToken"],
    "prefsKey": "myapp-api-prefs",
    "additionalServers": [
      { "url": "https://dev.myapp.com/api", "description": "Staging" },
      { "url": "http://localhost:81/myapp/www/api", "description": "Local" }
    ]
  }
  ```
- [ ] **`scaffolding/scripts/apidoc-to-openapi.js`:** το βελτιωμένο script μπαίνει στο framework scaffolding
- [ ] **`scaffolding/templates/api-doc.json.stub`:** template config με placeholders (`{{APP_NAME}}`, `{{API_URL}}`, `{{PRIMARY_COLOR}}`)
- [ ] **`scaffolding/templates/openapi-overrides.json.stub`:** κενό override file με οδηγίες σε comments
- [ ] **`scaffolding/templates/doc.sh.stub`:** shell script wrapper (αντίστοιχο του `src/Api/doc.sh`)
- [ ] **`pramnos init` wizard:** νέο βήμα "Configure API documentation? [y/N]" — αν yes:
  - Παράγει `src/Api/api-doc.json` από stub (με APP_NAME, API_URL κλπ από τα προηγούμενα βήματα)
  - Παράγει `src/Api/openapi-overrides.json`
  - Παράγει `scripts/apidoc-to-openapi.js`
  - Προσθέτει scripts στο `package.json` (`docs:generate`, `docs:validate`)
  - Προσθέτει `www/api/openapi*.json` και `www/api/docs/` στο `.gitignore`
- [ ] **Urbanwater migration:** αντικαθιστήσει `src/Api/apidoc.json` + `scripts/apidoc-to-openapi.js` με τις νέες εκδόσεις — output identικό, χωρίς αλλαγές στα controllers

> **Δεν γίνεται:** PHP-native generator, νέα PHP attributes (`#[ApiDoc]` κλπ), `pramnos api:docs` CLI command. Το Node.js pipeline είναι η σωστή εργαλειοθήκη για αυτή τη δουλειά.
> **Εξάρτηση:** Καμία framework dependency — standalone Node.js script, τρέχει ανεξάρτητα.

---

### 🔗 Φάση 19: Git Webhook Handler
*Στο Urbanwater υπάρχουν δύο bare PHP scripts (`githook.php`, `githook-dev.php`) που εκτελούν `git pull` όταν το GitHub/Bitbucket στέλνει push event. Έχουν κρίσιμο security gap: **δεν επαληθεύουν HMAC signature** — ο server εκτελεί `shell_exec` σε οποιοδήποτε στέλνει POST request. Αυτή η φάση φτιάχνει ασφαλή, configurable webhook infrastructure ως framework component.*

#### Πρόβλημα σήμερα (Urbanwater)

```php
// githook.php — κανένας έλεγχος πηγής:
$payload = json_decode(file_get_contents('php://input'));
// ... branch detection ...
$content = shell_exec('cd /home/urbanwater/public_html && git pull origin master');
```

- Δεν υπάρχει επαλήθευση `X-Hub-Signature-256` (GitHub HMAC-SHA256)
- Hardcoded paths (`/home/urbanwater/public_html/`)
- Logs με `file_put_contents` αντί για framework `Logs`
- Δύο ξεχωριστά αρχεία για production + dev αντί για config-driven behavior
- Δεν υποστηρίζει πολλαπλές ενέργειες (μόνο `git pull`, δεν μπορεί να εκτελέσει composer, migrations κλπ)

#### Λύση: `Pramnos\Webhook\` namespace

- [x] **`WebhookHandler`:** core class (`src/Pramnos/Webhook/WebhookHandler.php`)
  - **HMAC verification:** `X-Hub-Signature-256` (GitHub SHA-256), `X-Hub-Signature` (Bitbucket/GitHub SHA-1 legacy) — hash_equals() timing-safe
  - **Provider detection:** GitHub (`x-github-event`) vs Bitbucket — auto-detect από headers
  - **Branch-to-actions mapping:** config-driven via `onBranch(branch, commands[])`, fluent API
  - **Event filtering:** `push`, `release` (published), `workflow_run` (completed+success) — ignores all others (204)
  - **Structured logging:** Pramnos\Logs — best-effort, never breaks webhook response
  - **Secret from .env:** empty secret throws InvalidArgumentException at construction
  - **Response:** 200 OK `{status,branch,commands_run,elapsed_ms}` / 204 ignored / 403 bad sig / 500 command failed
  - **Tests:** 17 unit tests in `tests/Unit/Webhook/WebhookHandlerTest.php`
- [x] **`WebhookServiceProvider`:** registers 'webhook' singleton; reads webhook.{secret,repo_dir,log_channel,timeout} from app.php
- [x] **`pramnos make:webhook` command**: παράγει `www/webhook.php` με config placeholders:
    ```php
    // www/webhook.php
    $handler = new \Pramnos\Webhook\WebhookHandler(
        secret: $_ENV['WEBHOOK_SECRET'],
        repoDir: ROOT,
        logChannel: 'webhook'
    );
    $handler->onBranch('main', ['git fetch --all', 'git reset --hard origin/main']);
    $handler->onBranch('develop', ['git fetch --all', 'git reset --hard origin/develop']);
    $handler->handle();
    ```
- [x] **Scaffolding (init):** `pramnos init` wizard — `--webhook=y` / "Configure Git webhook? [y/N]" → παράγει `www/webhook.php` + προσθέτει `WEBHOOK_SECRET=` στο `.env.example`
- [x] **Tests:** 21 unit tests — HMAC verification (valid/invalid/missing/priority); provider detection; branch mapping; event filtering; executeCommands fail-fast

> **BC:** Τα υπάρχοντα `githook.php` / `githook-dev.php` αντικαθίστανται εθελοντικά. Το `WebhookHandler` μπορεί να χρησιμοποιηθεί ως standalone script ή ως `#[Route]` endpoint μέσα στο framework app (Φάση 15).
> **Security:** Το `WEBHOOK_SECRET` **πρέπει** να οριστεί στο GitHub repo settings → Webhooks → Secret. Χωρίς secret, ο handler αρνείται να ξεκινήσει (exception στο constructor).

---

### 💾 Φάση 10: File Storage Abstraction ✅
*Υλοποιήθηκε ως `Pramnos\Storage\` namespace — 100% BC-safe (Filesystem unchanged).*

- [x] **StorageInterface:** Ενιαίο συμβόλαιο 20 μεθόδων (`src/Pramnos/Storage/StorageInterface.php`) — `get`, `put`, `readStream`, `append`, `prepend`, `exists`, `missing`, `size`, `lastModified`, `mimeType`, `delete`, `move`, `copy`, `files`, `allFiles`, `directories`, `makeDirectory`, `deleteDirectory`, `url`, `temporaryUrl`.
- [x] **LocalDriver:** `src/Pramnos/Storage/Drivers/LocalDriver.php` — χρησιμοποιεί `Filesystem` για directory ops (`destroyDirectory`, `listDirectoryFiles`, `recurseCopy`), PHP `file_get_contents`/`file_put_contents`/`copy` για αρχεία. Υποστηρίζει `url` prefix configuration. BC: `Pramnos\Filesystem\Filesystem` αμετάβλητο.
- [x] **S3Driver:** `src/Pramnos/Storage/Drivers/S3Driver.php` — προαιρετική εξάρτηση από AWS SDK (runtime guard); lazy client init; presigned URLs (temporaryUrl); paginator για allFiles; PresignedUrl generation.
- [x] **FtpDriver:** `src/Pramnos/Storage/Drivers/FtpDriver.php` — ext-ftp guard; lazy connection; passive mode; MIME type από extension map; `__destruct()` κλείνει σύνδεση.
- [x] **StorageManager:** `src/Pramnos/Storage/StorageManager.php` — factory + registry; lazy disk creation; `extend()` για mock/custom drivers; proxies όλες τις StorageInterface μεθόδους στο default disk.
- [x] **Storage facade:** `src/Pramnos/Storage/Storage.php` — static façade; `Storage::init($config)` bootstrap; `Storage::disk('name')` για named disk; `Storage::setManager()` για testing.
- [x] **37 characterization tests:** `tests/Characterization/Storage/StorageCharacterizationTest.php` — LocalDriver (all 20 methods), S3/FTP optional-dependency guards, StorageManager (lazy creation, extend, default disk), Storage façade, Filesystem delegation verification.

### 🧪 Φάση 20: HTTP Testing Infrastructure & DOM Assertions
*Εργαλεία για να γράφονται γρήγορα και αξιόπιστα tests που επαληθεύουν το τελικό HTML/JSON output, χωρίς την ευθραυστότητα του `strpos()`.*

- [x] **`symfony/dom-crawler` & `symfony/css-selector`:** Προσθήκη ως `require-dev` dependencies στο `composer.json` του framework. Δεν φορτώνονται στο production.
- [x] **`Pramnos\Testing\TestResponse` Wrapper:** Κλάση που «ντύνει» την απάντηση του framework (string ή object) και προσφέρει fluent assertions:
  - `assertSuccessful()`, `assertStatus(int)`
  - `assertSee(string)`, `assertDontSee(string)`, `assertSeeText(string)`
  - `assertJson(array)`, `assertJsonPath(path, value)`
  - `assertSelectorExists(string $selector)`
  - `assertSelectorContains(string $selector, string $text)`
  - `assertSelectorAttribute(string $selector, string $attr, string $val)`
- [x] **`Pramnos\Testing\TestClient` (Mock Browser):** Ένας in-memory client που κάνει bypass τον πραγματικό web server (Nginx/Apache) και χτυπάει απευθείας το `Application` / `Router`.
  - `$client->get('/url')`, `$client->post('/url', data)`
  - `$client->submitForm('Κουμπί', [data])` — Αυτόματη ανακάλυψη CSRF tokens από το DOM και αποστολή τους.
- [x] **Scaffolding Integration:** Τα scaffolded Feature Tests των controllers (`pramnos create:controller`) να χρησιμοποιούν αυτόματα το νέο `TestClient` κάνοντας πλήρη έλεγχο των views που παράγονται.

## UrbanWater Schema Backport Tasks

### AuthServer Schema
- [x] Create `authserver` schema namespace (PostgreSQL only). — migration 000020
- [x] Create `authserver.permissions` table for fine-grained RBAC permission grants. — migration 000022
- [x] Create `authserver.roles` table for RBAC role definitions. — migration 000021
- [x] Create `authserver.audit_log` table for permission change history. — migration 000024
- [x] Create `authserver.permission_templates` table for reusable permission blueprints. — migration 000032
- [x] Create `authserver.role_templates` table for role blueprints. — migration 000033
- [x] Create `authserver.permission_inheritance` table for hierarchical relationships. — migration 000034
- [x] Create `authserver.user_organizations` table for user membership in organizations. — migration 000031
- [x] Create `jwt_replay_prevention` table (public schema) to block token replay attacks. — migration 000027
- [x] Create `authserver.device_authorizations` table for RFC 8628 Device Authorization Grant. — migration 000026
- [x] Create `authserver.effective_permissions` view for deny-takes-priority logic. — migration 000035
- [x] Create `authserver.slow_api_calls` view for performance monitoring. — migrations 000030 + 000048 (reposition)
- [x] Create `authserver.rbac_functions` (PostgreSQL-specific). — migration 000036

### Applications Schema
- [x] Create `applications` schema namespace (PostgreSQL only). — migration 000037
- [x] Create `applications.oauth2_client_auth_methods` table for client authentication methods. — migration 000028
- [x] Create `applications.oauth2_webhook_endpoints` table for registered webhook URLs. — migration 000029
- [x] Create `applications.oauth2_webhook_events` table for delivery queue/audit log. — migration 000029
- [x] Create `applications.oauth2_user_consents` table for persisted user authorization decisions. — migration 000042
- [x] Create `applications.oauth2_device_codes` table for RFC 8628 Device Authorization Grant. — migration 000041
- [x] Create `applications.oauth2_helper_functions` (PostgreSQL-specific). — migration 000040

### Public Schema
- [x] Create `public.organizations` table for generic organization registry. — migration 000038

## Μελοντικές Φάσεις / DX Advanced Tooling

### 🚀 Φάση 21: Developer Experience (DX) - Advanced Tooling
*Προσθήκες που θα φέρουν το Pramnos στο επίπεδο των πιο σύγχρονων frameworks (Laravel/Symfony) όσον αφορά την ταχύτητα ανάπτυξης και την ποιότητα κώδικα.*

- [ ] **Form Requests (Advanced Validation):** Επέκταση του `Pramnos\Validation\Validator`. Αντί για χειροκίνητο validation στους controllers, δημιουργία κλάσεων Request (π.χ. `StoreUserRequest`) που εκτελούνται αυτόματα πριν τον controller και κάνουν αυτόματο redirect σε περίπτωση λάθους.
- [ ] **Model Factories:** Δημιουργία συστήματος Factories (π.χ. `UserFactory`) που θα συνδέει το ORM με το `Pramnos\Support\Faker`. Έτσι, με μία εντολή όπως `User::factory()->count(50)->create()`, θα δημιουργούνται μαζικά test data απευθείας στη βάση, ιδανικό για Seeding και Unit Testing.
- [ ] **Notification Channels:** Δημιουργία ενός ενοποιημένου Notification Component (πέραν του απλού Email και Messaging). Έτσι, θα ορίζουμε μια κλάση `InvoicePaidNotification` και το σύστημα θα την κάνει dispatch ταυτόχρονα σε πολλαπλά κανάλια (Email, SMS, WebSockets, Database Logs) ανάλογα με τα preferences του χρήστη.

---

### 🩺 Φάση 22: Health & Monitoring Dashboard

*Παρέχει σε κάθε εφαρμογή έτοιμο HTTP dashboard για παρακολούθηση της υγείας του συστήματος — χωρίς να χρειάζεται να γραφεί κώδικας στην εφαρμογή.*

#### Υποδομή (framework-level)

- [x] **`HealthController`** στο `\Pramnos\Application\Controllers\` με:
  - `display()` — HTML dashboard: HealthRegistry::runAll() results + DB type/version + cache adapter + active sessions + PHP version + peak memory.
  - `check()` — JSON endpoint (public, no auth): `{"status":"ok|degraded|down","checks":{...}}` — HTTP 200 for ok, 503 for degraded/down.
  - `phpinfo()` — phpinfo() page (usertype >= 90 check, strips html/head/body wrappers).
  - `display` + `phpinfo` in `actions_auth`; `check` in `actions` (public).

- [ ] **Views** (scaffolding fallback για όλα τα themes: bootstrap, plain-css, tailwind):
  - `health/health.html.php` — summary dashboard με color-coded status badges (ok/degraded/down) ανά check, DB info table, cache stats, active users counter
  - `health/check.html.php` — αποτελέσματα individual checks σε table format (χρησιμοποιείται και για JSON via `check()` endpoint)

- [x] **Built-in checks registration** στο `Application::init()` (μέσω `registerBuiltInHealthChecks()`, πάντα):
  - `DatabaseConnectivityCheck` — registered μόνο αν `$this->database->connected`
  - `DiskSpaceCheck` — always
  - `MemoryLimitCheck` — always
  - Τα checks είναι idempotent: `HealthRegistry::register()` κάνει replace αν ο ίδιος name υπάρχει

#### Scaffolding (init app)

- [x] **`src/Controllers/Health.php`** — thin wrapper extending `\Pramnos\Application\Controllers\Health`, scaffolded σε κάθε νέα εφαρμογή μέσω `scaffoldHealthWiring()`. Ίδιο pattern με `Logs.php`.
- [x] **Navbar link "Health"** — registered στο `registerDefaultNavItems()` (Admin section, position 11, δίπλα στο Logs)
- [x] **JSON endpoint documentation** — το `GET /health/check` τεκμηριώνεται στο `CLAUDE.md.stub` ως monitoring endpoint (added with Health scaffold)

#### Tests

- [x] Unit tests για `HealthController::display()` και `HealthController::check()` — HTML output (ok/down badge, checks table, system info, empty state), HTTP code mapping test.
- [ ] Integration tests (MySQL + PostgreSQL) — επαλήθευση ότι το DB info query δουλεύει σωστά και στα δύο backends
- [x] Scaffolding tests — `Health.php` wrapper δημιουργείται (InitCommandUnitTest), `admin.health` NavItem registered by `registerDefaultNavItems()` (HealthControllerTest).

---

### 🗂️ Φάση 23: Framework Admin CRUD Controllers

*Backport και βελτίωση όλων των admin management controllers που υπάρχουν στο Urbanwater — ώστε κάθε νέα εφαρμογή να τα κληρονομεί out-of-the-box μέσω wrappers.*

Κάθε controller που υλοποιείται εδώ:
- Ζει στο `\Pramnos\Application\Controllers\` ή `\Pramnos\Auth\Controllers\` (framework namespace)
- Scaffolds ως thin wrapper στo `src/Controllers/` κατά το `init app` (ανάλογα feature)
- Απαιτεί authentication (`addAuthAction`) — η εφαρμογή μπορεί να προσθέσει permission level
- Έχει views ως scaffolding fallback (όλα τα themes)

#### 23.1 Διαχείριση Χρηστών

- [x] **`UsersController`** στο `\Pramnos\Application\Controllers\` (πάντα διαθέσιμο):
  - [x] `display()` — DataTable λίστα χρηστών (username, email, usertype, status, last login)
  - [x] `edit($id)` — φόρμα επεξεργασίας χρήστη (username, email, usertype, active/validated flags)
  - [x] `save()` — POST handler για create/update
  - [x] `delete($id)` — διαγραφή (soft delete αν `deleted_at` υπάρχει)
  - [x] `lock($id)` / `unlock($id)` — αλλαγή `active` flag
  - `resetpassword($id)` — send password reset email *(pending)*
  - [x] `sessions($id)` — λίστα ενεργών sessions για χρήστη
  - [x] Wrapper: `src/Controllers/Users.php` — scaffolded πάντα

#### 23.2 Διαχείριση OAuth2 Applications (Clients)

- [x] **`ApplicationsController`** στο `\Pramnos\Auth\Controllers\` (όταν feature `authserver`):
  - [x] `display()` — DataTable λίστα registered OAuth2 applications
  - [x] `edit($id)` — φόρμα: name, description, redirect URIs, allowed scopes
  - [x] `save()` — δημιουργία/ενημέρωση client (generate client_id/client_secret)
  - [x] `delete($id)` — soft-delete + ανάκληση tokens
  - [x] `tokens($id)` — λίστα ενεργών tokens για application
  - [x] `rotate($id)` — rotate client_secret
  - [x] Wrapper: `src/Controllers/Applications.php` — scaffolded όταν feature `authserver`

#### 23.3 Διαχείριση OAuth2 Tokens

- [x] **`TokensController`** στο `\Pramnos\Auth\Controllers\` (όταν feature `authserver`):
  - [x] `display()` — DataTable λίστα ενεργών tokens (user, application, scope, expires_at, issued_at)
  - [x] `revoke($id)` — ανάκληση token
  - [x] `revokeall()` — ανάκληση όλων των tokens χρήστη ή application (POST με filters)
  - [x] Wrapper: `src/Controllers/Tokens.php` — scaffolded όταν feature `authserver`

#### 23.4 Διαχείριση Permissions & Roles (RBAC)

- [x] **`PermissionsController`** στο `\Pramnos\Auth\Controllers\` (όταν feature `authserver`):
  - [x] `display()` — DataTable λίστα permissions
  - [x] `edit($id)` — φόρμα permission (subject_type, object_type, action, grant_type)
  - [x] `save()` / `delete($id)`
  - [x] `assign($userId)` — εκχώρηση permissions σε χρήστη
  - [x] Wrapper: `src/Controllers/Permissions.php`

#### 23.5 Application Settings (Key-Value Store)

- [x] **`SettingsController`** στο `\Pramnos\Application\Controllers\` (πάντα):
  - [x] `display()` — DataTable λίστα settings (key, value, category, description)
  - [x] `edit($key)` — φόρμα επεξεργασίας setting
  - [x] `save()` — POST handler
  - [x] `delete($key)` — διαγραφή setting
  - [x] Βασίζεται στο `\Pramnos\Application\Settings` class (ήδη υπάρχει)
  - [x] Wrapper: `src/Controllers/Settings.php` — scaffolded πάντα

#### 23.6 Ιστορικό Emails

- [x] **`EmailsController`** στο `\Pramnos\Application\Controllers\` (πάντα — αν υπάρχει email log table):
  - [x] `display()` — DataTable λίστα αποσταλμένων emails (recipient, subject, sent_at, status)
  - [x] `show($id)` — εμφάνιση περιεχομένου email (HTML preview)
  - [x] `resend($id)` — επαναποστολή
  - [x] Χρησιμοποιεί `mails` table (messaging feature migration)
  - [x] Wrapper: `src/Controllers/Emails.php` — scaffolded πάντα

#### 23.7 Διαχείριση Queue

- [x] **`QueueController`** στο `\Pramnos\Queue\Controllers\` (όταν feature `queue`):
  - [x] `display()` — DataTable λίστα jobs ανά status (pending/running/failed/completed) με φίλτρα
  - [x] `retry($id)` — επαναπρογραμματισμός failed job
  - [x] `retryall()` — μαζικό retry όλων failed
  - [x] `delete($id)` / `clear()` — καθαρισμός queue ανά status (soft-delete: status='deleted')
  - [x] `stats()` — JSON endpoint: counts ανά status, throughput, average processing time
  - [x] Wrapper: `src/Controllers/Queue.php` — scaffolded όταν feature `queue`

#### 23.8 Διαχείριση Services / Workers

- [x] **`ServicesController`** στο `\Pramnos\Application\Controllers\` (**πάντα** — ο χρήστης μπορεί να ορίσει custom daemons ανεξάρτητα από queue/messaging):
  - [x] `display()` — λίστα registered daemon/worker services με status (running/stopped/error), PID, uptime
  - [x] `start($name)` / `stop($name)` / `restart($name)` — έλεγχος lifecycle μέσω stop-file sentinel
  - [x] `logs($name)` — tail τελευταίων N γραμμών log για service
  - [x] `status()` — JSON endpoint: σύνοψη status όλων services (κατάλληλο για monitoring)
  - [x] Wrapper: `src/Controllers/Services.php` — scaffolded **πάντα**

#### 23.9 Διαχείριση Organizations

- [x] **`OrganizationsController`** στο `\Pramnos\Application\Controllers\` (πάντα — χρησιμοποιεί υπάρχον `organizations` migration):
  - [x] `display()` — DataTable λίστα organizations (name, description, members count, created_at)
  - [x] `edit($id)` — φόρμα δημιουργίας/επεξεργασίας organization
  - [x] `save()` / `delete($id)` — soft-delete: `is_active = 0`
  - [x] `members($id)` — λίστα χρηστών που ανήκουν στο organization (`authserver.user_organizations`)
  - [x] `addmember($orgId)` / `removemember($orgId, $userId)` — διαχείριση membership
  - [x] Wrapper: `src/Controllers/Organizations.php` — scaffolded πάντα

#### 23.10 Προβολή Token Actions (Audit Log)

- [x] **`TokenActionsController`** στο `\Pramnos\Auth\Controllers\` (πάντα — `#PREFIX#tokenactions` table δημιουργείται αυτόματα από `Token.php`):
  - [x] Read-only audit log — δεν επιτρέπεται εγγραφή/διαγραφή μέσω UI
  - [x] `display()` — DataTable με φίλτρα: token, user, endpoint, HTTP status, execution time, date range
  - [x] `show($id)` — αναλυτική προβολή μιας εγγραφής (request params, response data, headers)
  - [x] `stats()` — JSON/HTML dashboard: top endpoints, error rate, average execution time, p95/p99 latency
  - [x] `export()` — CSV export με φίλτρα (για compliance/auditing), max 10 000 rows
  - [x] Wrapper: `src/Controllers/TokenActions.php` — scaffolded όταν feature `auth`

#### 23.11 Statistics & Analytics Dashboard

*Backport της λογικής `getActiveUsers()` / `getPerformanceSummary()` από το Urbanwater Home controller σε επαναχρησιμοποιήσιμους framework services.*

- [x] **`\Pramnos\Application\Statistics\ActiveUsersService`** — query στο `#PREFIX#sessions` για: ενεργοί χρήστες τώρα, last 1h, last 24h, last 7d, last 30d. Backend-agnostic (MySQL + PostgreSQL).

- [x] **`\Pramnos\Application\Statistics\DatabaseStatsService`** — DB server metrics:
  - [x] PostgreSQL: `pg_database_size`, `pg_stat_activity` (connections, active), `pg_stat_database` (cache hit ratio, transactions, disk reads)
  - [x] MySQL: `information_schema`, `SHOW STATUS` (connections, queries, cache hits)

- [x] **`\Pramnos\Application\Statistics\ApiPerformanceService`** — query στο `#PREFIX#tokenactions`:
  - [x] Throughput (requests/hour), error rate ανά status code, average/p95/p99 execution time
  - [x] Top N slowest endpoints, top N most-called endpoints
  - [x] Configurable time window (last 1h, 24h, 7d)

- [x] **`DashboardController`** στο `\Pramnos\Application\Controllers\` (πάντα):
  - [x] `display()` — overview dashboard: active users widget + DB stats widget + API performance summary + health check badges (re-uses `HealthRegistry::runAll()`)
  - [x] `activeusers()` — JSON: αριθμοί ανά time window — κατάλληλο για AJAX refresh
  - [x] `apistats()` — JSON: performance summary για charts
  - [x] `dbstats()` — JSON: DB metrics
  - [x] Wrapper: `src/Controllers/Dashboard.php` — scaffolded πάντα
  - **Σημείωση:** Το `Dashboard` controller είναι διαφορετικό από το `\Pramnos\Auth\Controllers\Dashboard` (που χειρίζεται τον λογαριασμό χρήστη). Το εδώ `DashboardController` είναι admin/ops overview.

#### Κοινές Απαιτήσεις Φάσης 23

- [x] Κάθε controller έχει **views ως scaffolding fallback** για όλα τα themes (bootstrap, plain-css, tailwind) — 66 αρχεία (22 templates × 3 themes)
- [x] Ο `init app` scaffolds wrapper controllers στο `src/Controllers/` ανάλογα feature — 11 controllers
- [x] Navbar links προστίθενται δυναμικά μέσω `NavRegistry` στο `Application::registerDefaultNavItems()` — 10 admin items (7 always, 3 feature-gated)
- [x] Κάθε controller έχει unit tests (34 tests) + integration tests για QueueController MySQL + PostgreSQL (14 tests)
  - **Σημείωση:** Integration tests για τους υπόλοιπους controllers προαιρετικά — η QueryBuilder integration suite καλύπτει τις βασικές SQL πράξεις
- [x] **Προαπαιτούμενο: Φάση 24 (NavRegistry)** — ολοκληρώθηκε
- [x] Η σειρά υλοποίησης εντός φάσης: 23.11 → 23.1 → 23.5 → 23.8 → 23.9 → 23.2 → 23.3 → 23.10 → 23.6 → 23.4 → 23.7
  - Πρώτα services (Statistics + Services) — universal, χωρίς feature dependencies
  - Μετά auth-dependent (Users, Settings, Organizations, Applications, Tokens, TokenActions, Emails)
  - Τελευταία τα feature-specific (Permissions RBAC, Queue)

---

### 🧭 Φάση 24: Navigation Registry

*Αντικαθιστά τα hardcoded navbar links με ένα κεντρικό registry που εκδίδει nav items δυναμικά βάσει login state, permission level και enabled features. Κάθε νέος controller εγγράφεται μόνος του — το theme header δεν αγγίζεται ποτέ ξανά.*

#### Κίνητρο

Η τωρινή προσέγγιση (`buildThemeHeader()` με hardcoded PHP conditionals) έχει τρία προβλήματα:
1. Κάθε νέος admin controller απαιτεί αλλαγή στο template — δεν κλιμακώνεται
2. Δεν υπάρχει έλεγχος permission level (μόνο login/not-login)
3. Το scaffolded `header.php` είναι static — αν μπει νέος controller μετά το `init app`, η navbar δεν ενημερώνεται

#### Αρχιτεκτονική: `NavRegistry`

#### Τα δύο μοντέλα permission

Το framework παρέχει δύο επίπεδα permission control που πρέπει να υποστηρίζονται ταυτόχρονα:

| Μοντέλο | Πότε χρησιμοποιείται | Πώς ελέγχεται |
|---|---|---|
| **Απλό** — `usertype` levels | Πάντα διαθέσιμο | `$user->usertype >= minUserType` |
| **Σύνθετο** — RBAC roles/permissions | Μόνο όταν feature `authserver` + RBAC schema | `PermissionEngine::userHas($user, $permission)` |

Κανόνας: **και τα δύο πρέπει να περνούν** όταν είναι ορισμένα. Το `minUserType` λειτουργεί ως minimum bar — ακόμα κι αν ο χρήστης έχει το RBAC permission, αν ο `usertype` είναι κάτω από το minimum, δεν βλέπει το item. Αν δεν υπάρχει RBAC (authserver απενεργοποιημένο), μόνο το `minUserType` μετράει.

- [x] **`\Pramnos\Application\NavRegistry`** — static registry (pattern ίδιο με `HealthRegistry`):

```php
NavRegistry::register(new NavItem(
    id:         'admin.logs',
    label:      'Logs',
    url:        sURL . 'logs',
    section:    NavSection::Admin,
    position:   10,
    requireAuth: true,
    minUserType: 80,               // Απλό μοντέλο: 0=all, 50=user, 80=manager, 90=admin, 99=superadmin
    permission: 'admin.logs.view', // Σύνθετο μοντέλο: RBAC permission name (null = skip RBAC check)
    feature:    null,              // null=always, 'auth', 'queue', 'authserver', ...
    icon:       'bi-journal-text',
));
```

- [x] **`NavItem`** value object — immutable, readonly properties: `id`, `label`, `url`, `section`, `position`, `requireAuth`, `minUserType`, `permission`, `feature`, `icon`
- [x] **`NavSection`** enum — `Main`, `User`, `Admin`, `Feature`
- [x] **`NavRegistry::getForUser(?User $user, array $enabledFeatures): array`** — επιστρέφει filtered + sorted items ανά section με **διπλό permission check**:
  1. [x] Αν `requireAuth=true` και χρήστης δεν είναι logged in → **αφαίρεση**
  2. [x] Αν `minUserType > 0` και `$user->usertype < minUserType` → **αφαίρεση**
  3. [x] Αν `permission !== null` ΚΑΙ RBAC είναι ενεργό (`authserver` + RBAC schema) → ελέγχει `PermissionEngine::userHas($user, $permission)` → αφαίρεση αν false
  4. [x] Αν `permission !== null` ΚΑΙ RBAC **δεν είναι** ενεργό → το permission check **παραλείπεται** (fallback στο minUserType)
  5. [x] Αν `feature !== null` και δεν είναι στα `$enabledFeatures` → **αφαίρεση**
  6. [x] Ταξινόμηση ανά `position`
- [x] **`NavRegistry::reset()`** — για test isolation
- [x] **`NavRegistry::remove(string $id)`** — για εφαρμογές που θέλουν να αφαιρέσουν framework items

#### Εγγραφή nav items

Κάθε framework controller εγγράφει το nav item του μέσω του `Application::init()` bootstrapping. Δεν χρειάζεται instance — η εγγραφή γίνεται μία φορά κατά το boot:

```php
// Απλό μοντέλο μόνο (χωρίς authserver):
NavRegistry::register(new NavItem('admin.logs', 'Logs', sURL.'logs',
    NavSection::Admin, 10, requireAuth: true, minUserType: 80));

// Και τα δύο μοντέλα (με authserver):
NavRegistry::register(new NavItem('admin.tokens', 'Tokens', sURL.'tokens',
    NavSection::Admin, 30, requireAuth: true, minUserType: 80,
    permission: 'admin.tokens.view', feature: 'authserver'));

// User section (login/logout — δεν χρειάζεται permission, μόνο auth state):
NavRegistry::register(new NavItem('user.login',  'Login',   sURL.'login',
    NavSection::User, 0, requireAuth: false));
NavRegistry::register(new NavItem('user.account','Account', sURL.'account',
    NavSection::User, 0, requireAuth: true,  minUserType: 1));
NavRegistry::register(new NavItem('user.logout', 'Logout',  sURL.'login/logout',
    NavSection::User, 99, requireAuth: true, minUserType: 1));
```

Η εφαρμογή μπορεί να προσθέσει δικά της items ή να αφαιρέσει/αντικαταστήσει framework items (override by id, `NavRegistry::remove()`).

#### Scaffolded `header.php`

Το `buildThemeHeader()` δεν παράγει πλέον hardcoded links. Παράγει αντ' αυτού ένα **μικρό PHP snippet** που καλεί το registry:

```php
// app/themes/default/header.php — γεννιέται μία φορά, δεν ξαναγγίζεται
<?php
$_navUser  = \Pramnos\User\User::getCurrentUser();
$_navFeatures = \Pramnos\Application\Application::getInstance()->applicationInfo['features'] ?? [];
$_nav = \Pramnos\Application\NavRegistry::getForUser($_navUser, $_navFeatures);
?>
<nav ...>
  <!-- Main items -->
  <?php foreach ($_nav[\Pramnos\Application\NavSection::Main->value] ?? [] as $_item): ?>
    <li><a href="<?php echo htmlspecialchars($_item->url, ENT_QUOTES); ?>"><?php echo htmlspecialchars($_item->label, ENT_QUOTES); ?></a></li>
  <?php endforeach; ?>

  <!-- User items (Account / Logout / Login) -->
  <?php foreach ($_nav[\Pramnos\Application\NavSection::User->value] ?? [] as $_item): ?>
    <li><a href="..."><?php echo htmlspecialchars($_item->label, ENT_QUOTES); ?></a></li>
  <?php endforeach; ?>

  <!-- Admin dropdown — εμφανίζεται μόνο αν υπάρχουν items -->
  <?php if (!empty($_nav[\Pramnos\Application\NavSection::Admin->value])): ?>
    <li class="dropdown">
      <a href="#" class="dropdown-toggle">Admin</a>
      <ul>
        <?php foreach ($_nav[\Pramnos\Application\NavSection::Admin->value] as $_item): ?>
          <li><a href="<?php echo htmlspecialchars($_item->url, ENT_QUOTES); ?>"><?php echo htmlspecialchars($_item->label, ENT_QUOTES); ?></a></li>
        <?php endforeach; ?>
      </ul>
    </li>
  <?php endif; ?>
</nav>
```

Η Bootstrap και plain-css variants διαφέρουν μόνο στα CSS classes — η λογική είναι ίδια.

#### Migration από hardcoded navbar (Φάσεις 22-23 → 24)

- [x] Τα hardcoded links που προστέθηκαν στις Φάσεις 22-23 (`logs`, `health`, `oauth`, auth conditionals) **αντικαθίστανται** από `NavRegistry::register()` calls στο `Application::init()`
- [x] Το `buildThemeHeader()` απλοποιείται — παράγει μόνο CSS/meta + το dynamic nav snippet
- [x] Η `scaffoldAuthWiring()` και `scaffoldLogsWiring()` παύουν να τροποποιούν το header — απλώς εγγράφουν controllers που κάνουν `NavRegistry::register()` στο constructor τους

#### Σειρά εξάρτησης

> **Φάση 24 υλοποιείται πριν τη Φάση 23.** Κάθε controller της Φάσης 23 εγγράφει το nav item του μέσω `NavRegistry` — δεν αγγίζει το `header.php`.

#### Tests

**Απλό μοντέλο (usertype):**
- [x] Anonymous user βλέπει μόνο `requireAuth=false` items
- [x] `usertype=50` δεν βλέπει `minUserType=80` admin items
- [x] `usertype=90` βλέπει `minUserType=80` items

**Σύνθετο μοντέλο (RBAC):**
- [x] Χρήστης με αρκετό `minUserType` αλλά χωρίς RBAC permission → **δεν βλέπει** το item (RBAC ενεργό)
- [x] Χρήστης με αρκετό `minUserType` και χωρίς RBAC (authserver ανενεργό) → **βλέπει** το item (fallback)
- [x] Χρήστης με RBAC permission αλλά ανεπαρκή `usertype` → **δεν βλέπει** (minUserType πάντα ισχύει)
- [x] Χρήστης με αρκετό `minUserType` ΚΑΙ RBAC permission → **βλέπει** (και τα δύο pass)

**Features & sections:**
- [x] Item με `feature: 'queue'` φιλτράρεται όταν το queue δεν είναι στα `$enabledFeatures`
- [x] Admin dropdown εμφανίζεται μόνο αν υπάρχουν ορατά admin items για τον χρήστη
- [x] `NavRegistry::remove()` αφαιρεί item, επόμενο `getForUser()` δεν το επιστρέφει
- [x] `NavRegistry::reset()` καθαρίζει όλα τα items (test isolation)
- [x] Items ταξινομούνται ανά `position` εντός κάθε section

**Scaffolding:**
- [x] `buildThemeHeader()` παράγει nav snippet που καλεί `NavRegistry::getForUser()` (string assertion)
- [x] `header.php` δεν περιέχει hardcoded controller URLs μετά τη Φάση 24

---

## Phase 25: Auth System Modernization — Addons → Native / Middleware

### Κίνητρο

Το framework έχει 3 built-in addons που αποτελούν ουσιαστικά **υποδομή, όχι επέκταση**. Πρέπει να γίνουν native components ή middleware, με το addon system να παραμένει μόνο για genuine extension points (LDAP, OAuth external, custom auth providers).

---

### 25.1 — Ανάλυση υπαρχόντων addons

| Addon | Type | Hook | Τι κάνει | Κατάλληλο για |
|---|---|---|---|---|
| `Addon\Auth\UserDatabase` | `auth` | `onAuth`, `onAuthCheck` | Επαλήθευση κωδικού από DB | Native driver (default) |
| `Addon\User\User` | `user` | `onLogin`, `onLogout` | Session vars + cookies + lastlogin | Built-in lifecycle (Auth class) |
| `Addon\System\Session` | `system` | `onAppInit` | DB session tracking, bot detection, force-logout | `SessionTrackingMiddleware` (opt-in) |

**Κοινό πρόβλημα:** Αν κάποιο addon δεν φορτωθεί στο `app.php`, το σύστημα αποτυγχάνει σιωπηλά — δεν υπάρχει fallback ούτε warning.

---

### 25.2 — `UserDatabase` → Native Authentication Driver

**Στόχος:** Το `Auth::auth()` να λειτουργεί χωρίς addon registration στο `app.php`.

#### Σχέδιο

```php
// Πριν: απαιτεί 'addons' entry στο app.php
Auth::getInstance()->auth($user, $pass);  // → silent false αν δεν υπάρχει addon

// Μετά: λειτουργεί out-of-the-box
Auth::getInstance()->auth($user, $pass);  // → χρησιμοποιεί DatabaseAuthDriver by default
```

- Νέος `DatabaseAuthDriver` (`src/Pramnos/Auth/Drivers/DatabaseAuthDriver.php`) — εξάγει λογική από `UserDatabase::onAuth()` σε standalone class
- `Auth::setDriver(AuthDriverInterface $driver)` για custom drivers (LDAP, OAuth, κτλ.)
- `Auth::addDriver(AuthDriverInterface $driver)` για chaining (π.χ. DB + LDAP fallback)
- `AuthDriverInterface` — `verify(string $username, string $password): AuthResult`
- Default driver: `DatabaseAuthDriver` — ενεργοποιείται αυτόματα χωρίς config
- Addon system (παλιό): παραμένει functional για BC, με `@deprecated` notice

#### BC

Το `app.php` `addons` format δεν αφαιρείται — applications που το έχουν ήδη συνεχίζουν να δουλεύουν. Ο νέος driver απλώς προηγείται αν δεν υπάρχει addon.

---

### 25.3 — MD5 Legacy Path με Auto-Upgrade

**Πρόβλημα:** Το `UserDatabase::onAuth()` (και ο `DatabaseAuthDriver`) υποστηρίζει `md5($password)` ως fallback για legacy password stores. Αυτό πρέπει να είναι:
- Disabled by default σε νέες εφαρμογές
- Enabled μόνο για legacy apps (explicit opt-in)
- Αυτόματα αναβαθμίζεται σε bcrypt κατά το πρώτο login

#### Σχέδιο

```php
// Νέα εφαρμογή: MD5 ανενεργό (default)
$driver = new DatabaseAuthDriver(['legacy_md5' => false]);

// Legacy εφαρμογή: MD5 ενεργό με auto-upgrade
$driver = new DatabaseAuthDriver(['legacy_md5' => true, 'auto_upgrade' => true]);
// → αν βρεθεί MD5 match, κάνει rehash σε bcrypt αμέσως και σώζει στη DB
```

#### Auto-upgrade flow

```
VERIFY($username, $password)
  ├── bcrypt_verify(password + md5(salt+uid), stored_hash)  →  ✓ → login
  ├── [legacy_md5=true] md5(password) == stored_hash        →  ✓ → login + rehash → login
  └── FAIL
```

**Rehash:** Μόλις βρεθεί MD5 match:
1. Υπολόγισε `$newHash = password_hash($password . md5($salt . $userId), PASSWORD_DEFAULT)`
2. `UPDATE users SET password = $newHash WHERE userid = $userId`
3. Συνέχισε το login κανονικά

Αυτό εξαλείφει σταδιακά τα MD5 passwords χωρίς migration script.

#### Config στο app.php

```php
'auth' => [
    'legacy_md5'   => false,   // default για νέες εφαρμογές
    'auto_upgrade' => true,    // αν legacy_md5=true, αναβάθμισε αυτόματα
],
```

Το scaffolded `app.php` (με `auth` feature) παράγεται με `'legacy_md5' => false`.

---

### 25.4 — `Addon\User\User` → Built-in Login/Logout Lifecycle

**Πρόβλημα:** Το `onLogin` / `onLogout` ρυθμίζει `$_SESSION`, cookies, και `lastlogin` — λογική που **πρέπει πάντα να τρέξει** μετά από κάθε επιτυχές login. Ο addon mechanism την κάνει optional (silently missing).

#### Σχέδιο

- Η λογική `onLogin` / `onLogout` ενσωματώνεται απευθείας στο `Auth` class ή σε `AuthLifecycle` class που καλείται αυτόματα
- Extension points μέσω hooks: `Auth::afterLogin(callable $fn)` / `Auth::afterLogout(callable $fn)` για custom behavior
- `Addon\User\User` παραμένει για BC (deprecated)

---

### 25.5 — `Addon\System\Session` → `SessionTrackingMiddleware` (opt-in)

**Πρόβλημα:** Το `onAppInit` κάνει:
- DB write σε **κάθε** request (performance cost)
- Bot detection με 60+ regex
- Tracking visitorid, country, agent
- Force-logout μέσω sessions table

Αυτό ΔΕΝ είναι appropriate για κάθε εφαρμογή — πρέπει να είναι opt-in.

#### Σχέδιο

```php
// Διαχωρισμός σε υπηρεσίες:
SessionTrackingMiddleware   // DB upsert sessions table + force-logout check
BotDetector                 // extract: isBot(string $agent): bool + botName(string $agent): string
VisitorTracker              // visitorid cookie management + lastseen

// Opt-in στο app.php:
'middleware' => [
    \Pramnos\Http\Middleware\SessionTrackingMiddleware::class,
],
```

- `Addon\System\Session` παραμένει για BC (deprecated)
- Νέες εφαρμογές (scaffolded) δεν το ενεργοποιούν αυτόματα — το Session tracking γίνεται explicit choice
- `BotDetector` είναι standalone service χρήσιμο και σε άλλα contexts (analytics, rate limiting)

---

### 25.6 — Improved Auth Error Surfacing

**Πρόβλημα:** `Auth::auth()` επιστρέφει `false` σιωπηλά αν δεν υπάρχει κανένα addon / driver. Ο developer δεν ξέρει γιατί το login δεν δουλεύει.

#### Σχέδιο

```php
// Αν δεν υπάρχει κανένας driver:
Logger::log('Auth::auth() called but no authentication driver is registered. '
    . 'Ensure DatabaseAuthDriver is configured or a Pramnos\\Addon\\Auth addon is loaded.');
```

---

### Σειρά υλοποίησης

```
25.3 (MD5 auto-upgrade, μέσα στον υπάρχοντα UserDatabase)  — γρήγορο, isolated
  ↓
25.6 (error surfacing)                                       — 5 λεπτά
  ↓
25.2 (DatabaseAuthDriver native)                             — νέα κλάση + Auth refactor
  ↓
25.4 (Auth lifecycle built-in)                               — Auth class changes
  ↓
25.5 (SessionTrackingMiddleware)                             — νέο middleware + BotDetector
```

### Tests

- [x] `DatabaseAuthDriver::verify()` επιστρέφει `AuthResult::success` για σωστό bcrypt password
- [x] `DatabaseAuthDriver::verify()` επιστρέφει `AuthResult::failure` για λάθος password
- [x] `legacy_md5=false` → MD5 password δεν γίνεται δεκτό
- [x] `legacy_md5=true` → MD5 password γίνεται δεκτό ΚΑΙ rehashed σε bcrypt στη DB
- [x] Auto-upgrade: μετά το login, το stored password είναι πλέον bcrypt-verifiable
- [x] `Auth::auth()` χωρίς driver → log warning, return false
- [x] `SessionTrackingMiddleware` γράφει session record στη DB
- [x] `BotDetector::isBot('Googlebot/2.1')` → true
- [x] `BotDetector::isBot('Mozilla/5.0 (Windows NT 10.0)')` → false
- [x] BC: `app.php` με `addons: [UserDatabase]` συνεχίζει να δουλεύουν
