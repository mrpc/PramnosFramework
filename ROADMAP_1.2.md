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
   Μέθοδοι που αντικαθίστανται από νεότερες χαρακτηρίζονται `@deprecated` και παράγουν `E_USER_DEPRECATED` notice, αλλά **δεν αφαιρούνται** σε αυτό το release cycle.

5. **Behavior-level BC: ίδια είσοδος → ίδια έξοδος.**
   Αν υπάρχουσα λογική εσωτερικά αναδομηθεί (refactor), το παρατηρήσιμο αποτέλεσμα πρέπει να παραμείνει πανομοιότυπο.

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
  - [x] `count(): int` — `SELECT COUNT(*) AS aggregate`, strips ORDER BY/LIMIT, δεν μεταλλάσσει τον builder
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
  - [x] `dropColumn()`, `renameColumn()` (modifyColumn not yet)
  - [x] Primary keys, foreign keys, unique constraints, check constraints
  - [x] Indexes: `createIndex()`, `createUniqueIndex()`, `dropIndex()`
  - [x] **Views:** `createView()`, `createOrReplaceView()`, `dropView()`
  - [x] **Materialized Views (PostgreSQL/TimescaleDB):** `createMaterializedView()`, `refreshMaterializedView()`, `dropMaterializedView()`
  - [x] **Triggers (MySQL + PostgreSQL):** `createTrigger()`, `dropTrigger()` — MySQL: `CREATE TRIGGER … FOR EACH ROW`; PG: `CREATE OR REPLACE TRIGGER … EXECUTE FUNCTION fn()`
  - [x] Sequences (PostgreSQL): `createSequence()`, `dropSequence()` — MySQL: silent no-op; `nextVal()` / `setVal()` pending

- [x] **TimescaleDB Extension Builder:** Native support για τα hypertable και time-series χαρακτηριστικά:
  - [x] `createHypertable($table, $timeColumn)` — στο TimescaleDB εκτελεί `SELECT create_hypertable()`; σε άλλα backends: silent no-op
  - [x] `addSpaceDimension($table, $column, $partitions)` — TimescaleDB native; silent no-op αλλού
  - [x] `createContinuousAggregate($name, $query, $interval)` — TimescaleDB native / PG MATERIALIZED VIEW / MySQL VIEW
  - [x] `addRetentionPolicy($table, $interval, $timeColumn='created_at')` — TimescaleDB native; non-TimescaleDB: inserts `retention` row into `framework_policies` via QB; Policy Engine daemon executes DELETE job
  - [x] `addCompressionPolicy($table, $interval)` / `enableCompression()` — TimescaleDB native; silent no-op αλλού
  - [x] `QueryBuilder::timeBucket($interval, $column)` — dialect-transparent expression helper
  - [x] `addContinuousAggregatePolicy($view, $startOffset, $endOffset, $scheduleInterval)` — TimescaleDB native; non-TimescaleDB: inserts `aggregate_refresh` row into `framework_policies` via QB
  - [ ] Πρόσβαση στα TimescaleDB informational views (`timescaledb_information.*`)

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

- [ ] ~~**Full ORM Layer**~~ → *Μεταφέρθηκε στη Φάση 9 (βλ. τέλος).*

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
  - [x] System migrations: `authserver.device_authorizations` (RFC 8628, 000026), `authserver.jwt_replay_prevention` (000027), `authserver.oauth2_client_auth_methods` (000028), `oauth2_webhook_endpoints` + `oauth2_webhook_events` (000029), `authserver.slow_api_calls` VIEW (000030)
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
- [x] **Characterization Tests — `User`:** MySQL (`UserCharacterizationTest`, `UserTokenManagementCharacterizationTest`) + PostgreSQL/TimescaleDB (`UserPostgreSQLCharacterizationTest` → timescaledb:5432). User έχει zero TimescaleDB-specific paths.
- [x] **Characterization Tests — `Logs`:** `LoggerAndMigratorCharacterizationTest`, `LogManagerViewerCharacterizationTest` — file-based Logger, zero DB queries. DB-agnostic coverage είναι πλήρης.

> **Σημείωση για `[~]` (μερική κάλυψη):** Τα tests αυτά υπάρχουν στο Urbanwater integration suite και τρέχουν κατά τη διάρκεια ανάπτυξης ενάντια σε PostgreSQL + TimescaleDB. **Δεν** είναι επίσημα framework characterization tests × 3 databases — δεν τρέχουν σε MySQL και δεν βρίσκονται σε `tests/Characterization/`. Κατά συνέπεια, η Φάση 1 Internal Migration ολοκληρώθηκε χωρίς τη formal προϋπόθεση. Χρειάζεται επίσημη ολοκλήρωση πριν οποιοδήποτε επιπλέον refactoring.

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
[Φάση 1] Internal Migration (υπόλοιπα: Migration, Adjacencylist,
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

> **Παρατήρηση για την τρέχουσα κατάσταση:** Η Φάση 1 Internal Migration ολοκληρώθηκε (Model, DataTable) **χωρίς** προηγούμενα επίσημα characterization tests × 3 databases. Τα Urbanwater tests χρησίμευσαν ως de facto characterization suite αλλά καλύπτουν μόνο PostgreSQL + TimescaleDB. Προτού αγγιχτεί οποιοδήποτε άλλο class (Auth, User, Logs, Adjacencylist), τα επίσημα tests πρέπει να γραφούν.

### 🔎 Backlog Διορθώσεων από Characterization Findings

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

*Σημείωση: Οποιεσδήποτε υπάρχουσες μέθοδοι αντικαθίστανται από νεότερες, θα χαρακτηρίζονται ως `@deprecated` στα σχόλια, αλλά θα συνεχίσουν να υποστηρίζονται κανονικά σε αυτό το release circle.*

---

## 🚀 Νέες Φάσεις Εκσυγχρονισμού (v1.2+)

### 🏗️ Φάση 6: Dependency Injection & PSR Compliance
- [ ] **PSR-11 Service Container:** Υλοποίηση IoC Container για διαχείριση dependencies.
- [ ] **Constructor Injection:** Υποστήριξη αυτόματης επίλυσης εξαρτήσεων στους controllers.
- [ ] **PSR-3 Logger Implementation:** Συμβατότητα του `Logger` με το PSR-3 interface.
- [ ] **PSR-16 Simple Cache:** Wrapper για το υπάρχον caching system.
- [ ] **PSR-7/15 HTTP Stack:** Υποστήριξη PSR compliant requests/responses και middleware pipeline.

### 🛣️ Φάση 7: Modern Routing Engine
- [ ] **Attribute-based Routing:** Υποστήριξη `#[Route]` attributes πάνω από τις μεθόδους των controllers.
- [ ] **Route Discovery:** Αυτόματο scanning φακέλων για ανακάλυψη routes.
- [ ] **Named Routes & URL Generation:** Βελτίωση της παραγωγής URLs βάσει ονόματος route.

### 🛡️ Φάση 8: Security & Templating
- [ ] **View Auto-escaping:** Σύστημα προστασίας XSS με αυτόματο escaping των μεταβλητών στα templates (με δυνατότητα `raw` bypass).
- [ ] **CSRF Protection:** Native middleware για αυτόματο έλεγχο CSRF tokens σε POST requests.
- [ ] **Secure Headers:** Εύκολος ορισμός CSP (Content Security Policy) και άλλων security headers.

### 🗃️ Φάση 9: Full ORM Layer
*Προϋπόθεση: Event system (Φάση 2) + Characterization tests × 3 databases (Φάση 5) ολοκληρωμένα.*

- [ ] **Relationships:** `hasOne()`, `hasMany()`, `belongsTo()`, `belongsToMany()`, `hasManyThrough()`
- [ ] **Eager Loading:** `with('relation')` για αποφυγή N+1 queries
- [ ] **Scopes:** Local scopes (`scopeActive()`) και Global scopes που εφαρμόζονται αυτόματα
- [ ] **Model Events:** `creating`, `created`, `updating`, `updated`, `deleting`, `deleted` — ενσωμάτωση με το Event system (Φάση 2)
- [ ] **Casting:** Αυτόματη μετατροπή τιμών (`int`, `bool`, `json`, `datetime`, `array`)
- [ ] **Accessors / Mutators:** `getXAttribute()` / `setXAttribute()` για virtual fields
- [ ] **Soft Deletes:** `deleted_at` timestamp pattern με αυτόματο φιλτράρισμα
- [ ] **Timestamps:** Αυτόματη διαχείριση `created_at` / `updated_at`
- [ ] **Mass Assignment Protection:** `$fillable` / `$guarded` properties
- [ ] **Collections:** Επιστροφή αποτελεσμάτων ως typed collection με helper methods (`filter`, `map`, `pluck`, `groupBy`)
