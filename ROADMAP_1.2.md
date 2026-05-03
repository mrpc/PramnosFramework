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

**Τα migration tests είναι πάντα integration tests.** Δεν αρκεί να επαληθεύεται ότι ο κώδικας εκτελείται χωρίς exception — κάθε test ελέγχει την πραγματική κατάσταση της βάσης (ύπαρξη πίνακα, στηλών, constraints, indexes) μέσω queries στο `information_schema` ή αντίστοιχο catalog. Το ίδιο ισχύει για rollback: επαληθεύεται ότι ο πίνακας/η στήλη **όντως αφαιρέθηκε**.

| Test Suite | MySQL | PostgreSQL | TimescaleDB |
|---|:---:|:---:|:---:|
| QueryBuilder / DML | ✅ | ✅ | ✅ |
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
  - [ ] `INSERT IGNORE` (MySQL), `INSERT ... ON CONFLICT` / upsert (PostgreSQL/TimescaleDB)
  - [x] `UPDATE` με conditional logic, `RETURNING` clause (PostgreSQL)
  - [x] `DELETE`, `RETURNING` clause (PostgreSQL)
  - [ ] `TRUNCATE`
  - [x] JOINs: `INNER`, `LEFT` — `join($table, ..., $type)` δέχεται οποιοδήποτε type string (`right`, `full`, `cross`) αλλά δεν υπάρχουν convenience methods
  - [x] Conditions: `where()`, `orWhere()`, `whereIn()`, `whereRaw()`, nested where via Closure
  - [ ] `whereNull()` / `whereNotNull()`, `whereBetween()` / `whereNotBetween()`
  - [x] `groupBy()`, `groupByRaw()`, `having()`, `havingRaw()`, `orderBy()`, `orderByRaw()`, `limit()`, `offset()`
  - [x] `clearOrderingAndPaging()` — αφαιρεί ORDER BY/LIMIT/OFFSET (χρήσιμο σε COUNT subqueries)
  - [ ] `UNION` / `UNION ALL`
  - [ ] Common Table Expressions (CTEs) με `with()`
  - [ ] Subqueries ως SELECT columns ή FROM πηγή
  - [ ] Window functions (`OVER`, `PARTITION BY`, `RANK`, `ROW_NUMBER`) — PostgreSQL/TimescaleDB
  - [x] Raw expressions με `raw()` / `Expression` class για dialect-specific syntax

- [ ] **QueryBuilder Grammar/Adapter Pattern** *(απαραίτητο πριν το Schema Builder)*:
  Αρχιτεκτονική βελτίωση που διαχωρίζει την **κατασκευή query** (QB) από τη **μετάφρασή του σε SQL** (Grammar). Αντί για `if ($this->db->type == 'postgresql')` checks σκορπισμένα στον κώδικα, κάθε dialect έχει το δικό του Grammar class.

  ```
  QueryBuilder (dialect-agnostic — χτίζει AST)
      └─ Grammar (interface)
           ├─ MySQLGrammar       (backtick quoting, ? params, AUTO_INCREMENT DDL)
           ├─ PostgreSQLGrammar  ($1/$2 params, double-quote quoting, SERIAL DDL)
           └─ TimescaleDBGrammar (extends PostgreSQL + time_bucket, hypertable DDL)
  ```

  - [ ] `Grammar` interface / abstract class με `compileSelect()`, `compileInsert()`, `compileUpdate()`, `compileDelete()`, `compileWhere()` κλπ.
  - [ ] `MySQLGrammar` — backtick quoting, `?` parameters, `INSERT IGNORE`, `ON DUPLICATE KEY`
  - [ ] `PostgreSQLGrammar` — double-quote quoting, `$1/$2` parameters, `ON CONFLICT`, `RETURNING`
  - [ ] `TimescaleDBGrammar` (extends PostgreSQL) — `time_bucket()`, hypertable DDL, continuous aggregates
  - [ ] `Database` injects το κατάλληλο Grammar στον QueryBuilder κατά τη δημιουργία του
  - [ ] Μεταφορά της dialect λογικής από `Database::prepare()` στο Grammar (backtick conversion, parameter substitution)
  - [ ] `time_bucket()` dialect translation: TimescaleDB → `time_bucket()`, plain PG → `DATE_TRUNC()`, MySQL → `DATE_FORMAT()`

  > **BC Strategy:** Εντελώς εσωτερική αλλαγή. Το public API του QueryBuilder και το εξωτερικό συμπεριφοριακό αποτέλεσμα παραμένουν πανομοιότυπα. Υπάρχοντα `whereRaw()` / `orderByRaw()` calls λειτουργούν αμετάβλητα.

- [ ] **DDL / Schema Builder:** Fluent interface για ορισμό και τροποποίηση schema:
  - `createTable()`, `alterTable()`, `dropTable()`, `renameTable()`
  - Column types με αυτόματη μετατροπή ανά dialect (`TEXT`, `JSONB`, `TIMESTAMPTZ`, `BIGSERIAL`, κλπ.)
  - `addColumn()`, `modifyColumn()`, `dropColumn()`, `renameColumn()`
  - Primary keys, foreign keys, unique constraints, check constraints
  - Indexes: `createIndex()`, `createUniqueIndex()`, `dropIndex()`
  - **Views:** `createView()`, `createOrReplaceView()`, `dropView()`
  - **Materialized Views (PostgreSQL/TimescaleDB):** `createMaterializedView()`, `refreshMaterializedView()`, `dropMaterializedView()`
  - **Triggers (MySQL + PostgreSQL):** `createTrigger()` με `BEFORE`/`AFTER` και `INSERT`/`UPDATE`/`DELETE` support, `dropTrigger()`
  - Sequences (PostgreSQL): `createSequence()`, `nextVal()`, `setVal()`

- [ ] **TimescaleDB Extension Builder:** Native support για τα hypertable και time-series χαρακτηριστικά:
  - `createHypertable($table, $timeColumn)` — μετατροπή κανονικού πίνακα σε hypertable
  - `addSpaceDimension($table, $column, $partitions)` — space partitioning
  - `createContinuousAggregate($name, $query, $interval)` — continuous aggregate με `timescaledb.continuous`
  - `addContinuousAggregatePolicy()` — αυτόματο refresh policy
  - `addRetentionPolicy($table, $interval)` — αυτόματη διαγραφή παλιών chunks
  - `addCompressionPolicy($table, $interval)` — αυτόματη συμπίεση chunks
  - `enableCompression($table, $segmentBy)` / `disableCompression()`
  - `timeBucket($interval, $column)` — helper για `time_bucket()` expressions σε queries
  - Πρόσβαση στα TimescaleDB informational views (`timescaledb_information.*`)

- [x] **`DatabaseCapabilities` — Runtime Detection & Graceful Fallback:** Κλάση που ανιχνεύει τις δυνατότητες του τρέχοντος database backend και επιτρέπει capability-conditional DDL. **Κάθε TimescaleDB feature πρέπει να έχει silent fallback για MySQL και plain PostgreSQL** — κανένα migration ή query δεν επιτρέπεται να αποτύχει με fatal error σε non-TimescaleDB περιβάλλον.
  - [x] `DatabaseCapabilities::has(string $capability): bool` — runtime detection (check `pg_extension` για TimescaleDB)
  - [x] `DatabaseCapabilities::isMySQL()` / `isPostgreSQL()` / `hasTimescaleDB()`
  - Schema Builder `->ifCapable(capability, callable $ifTrue, ?callable $ifFalse)` — conditional DDL execution
  - **`time_bucket()` dialect translation:** TimescaleDB → `time_bucket()`, plain PG → `DATE_TRUNC()`, MySQL → `DATE_FORMAT()` — transparent μέσω `QueryBuilder::timeBucket()`
  - **Retention policy fallback:** Queue-based daily DELETE job όταν `add_retention_policy()` δεν είναι διαθέσιμο
  - **Continuous aggregate fallback:** `MATERIALIZED VIEW` σε plain PG, refreshed cache table σε MySQL (queue job)
  - **Compression policy fallback:** Silent no-op — δεδομένα αποθηκεύονται ασυμπίεστα, καμία error
  - **Hypertable fallback:** Regular table — η εφαρμογή λειτουργεί αργότερα αλλά χωρίς crash
  - Αναλυτική προδιαγραφή: βλ. `UrbanWater-Backport-Features.md` Section 14

- [ ] **Full ORM Layer:** Επέκταση του υπάρχοντος `Pramnos\Application\Model` σε πλήρες ORM:
  - **Relationships:** `hasOne()`, `hasMany()`, `belongsTo()`, `belongsToMany()`, `hasManyThrough()`
  - **Eager Loading:** `with('relation')` για αποφυγή N+1 queries
  - **Scopes:** Local scopes (`scopeActive()`) και Global scopes που εφαρμόζονται αυτόματα
  - **Model Events:** `creating`, `created`, `updating`, `updated`, `deleting`, `deleted` — ενσωμάτωση με το Event system (Φάση 2)
  - **Casting:** Αυτόματη μετατροπή τιμών (`int`, `bool`, `json`, `datetime`, `array`)
  - **Accessors / Mutators:** `getXAttribute()` / `setXAttribute()` για virtual fields
  - **Soft Deletes:** `deleted_at` timestamp pattern με αυτόματο φιλτράρισμα
  - **Timestamps:** Αυτόματη διαχείριση `created_at` / `updated_at`
  - **Mass Assignment Protection:** `$fillable` / `$guarded` properties
  - **Collections:** Επιστροφή αποτελεσμάτων ως typed collection με helper methods (`filter`, `map`, `pluck`, `groupBy`)

### 🔁 Internal Framework Migration to QueryBuilder
*Εσωτερική αναδιαγραφή των framework classes που περιέχουν raw SQL, χρησιμοποιώντας το νέο QueryBuilder ως κινητήρα. Το εξωτερικό API κάθε κλάσης παραμένει **πανομοιότυπο**.*

> ⚠️ **Απαραίτητη Προϋπόθεση:** Αυτό το βήμα εκτελείται **μόνο αφού** ολοκληρωθούν τα Characterization Tests της Φάσης 5. Τα tests αποτελούν τη μοναδική απόδειξη ότι η εσωτερική αλλαγή δεν έχει επηρεάσει τη συμπεριφορά.

- [x] **`Pramnos\Application\Model`** — Όλα τα internal SQL calls (CRUD, column introspection, caching hooks) ξαναγράφονται μέσω QueryBuilder.
- [x] **`Pramnos\Html\DataTable`** — Τα dynamic query building, filtering, sorting και pagination calls αντικαθίστανται από QueryBuilder expressions (μέσω του `Datasource` refactor).
- [ ] **`Pramnos\Database\Migration`** — Το DDL execution εσωτερικά χρησιμοποιεί τον Schema Builder.
- [ ] **`Pramnos\Database\Adjacencylist`** — Τα hierarchical queries (parent/children traversal) ξαναγράφονται με CTEs ή recursive QueryBuilder expressions.
- [ ] **`Pramnos\Auth\Auth`** — Τα queries για credential lookup, session persistence, και permission resolution περνούν από QueryBuilder.
- [ ] **`Pramnos\User\*`** — Όλες οι user management queries (lookup, create, update, role assignment) ξαναγράφονται.
- [ ] **`Pramnos\Logs\*`** — Τα log insert/query calls αντικαθίστανται από QueryBuilder, με ιδιαίτερη προσοχή στο TimescaleDB time-series path.

## 📦 Φάση 2: Urbanwater Features Port
*Μεταφορά και ενσωμάτωση ώριμων υποσυστημάτων από το Urbanwater project στο Core Framework. Κάθε feature ενσωματώνεται ως αυτόνομο, opt-in component — ενεργοποιείται μέσω του Feature Registry (Φάση 4) και φέρει τα δικά του system migrations.*

- [ ] **OAuth Server** *(feature key: `authserver`)*: Ενσωμάτωση του πλήρους oAuth Server ως core component του framework.
- [ ] **Authentication System** *(feature key: `auth`)*: Νέο, αναβαθμισμένο σύστημα πιστοποίησης, βασισμένο στον oAuth Server.
- [ ] **Queues System** *(feature key: `queue`)*: Σύστημα για Queues και Workers για εκτέλεση jobs στο background.
- [ ] **Messaging** *(feature key: `messaging`)*: Σύστημα μηνυμάτων — threads, recipients, read status.
- [ ] **Daemons & Background Tasks:** Ολοκληρωμένο σύστημα δημιουργίας, διαχείρισης και επίβλεψης daemons/background tasks.
- [ ] **CLI UX Improvements:** Αναβάθμιση της εμπειρίας στο terminal (Progress bars, formatted tables, styling) στα CLI commands.
- [ ] **Event / Hook System:** Επίσημο σύστημα events και listeners πάνω από το υπάρχον addon hook σύστημα — `Event::fire()`, `Event::listen()` — για αποσύζευξη εσωτερικών subsystems και δυνατότητα επέκτασης από addons.
  > **BC Strategy:** Τα υπάρχοντα addon hooks (Login, Logout, Auth κλπ.) εξακολουθούν να πυροδοτούνται κανονικά. Το νέο Event system τρέχει παράλληλα — δεν τα αντικαθιστά.

## 🛠️ Φάση 3: Developer Experience (DX) & Scaffolding
*Βελτίωση της ταχύτητας ανάπτυξης εφαρμογών για τον developer.*

### 🏗️ `init` Command — Full Project Scaffolding

*Το `bin/pramnos init` γίνεται πλήρης project wizard. Ρωτάει τα πάντα, δημιουργεί την πλήρη δομή, κατεβάζει τα assets τοπικά, σηκώνει το Docker, και παραδίδει ένα έτοιμο project.*

- [ ] **Step 1 — Project metadata:** Όνομα project, namespace, database type (MySQL / PostgreSQL / TimescaleDB), Docker ports.

- [ ] **Step 2 — Framework features:**
  ```
  Which framework features do you want to enable?
   ✅ Core System (required)
  >✅ Basic Auth System    [auth]
  >◻  OAuth Server         [authserver]
  >◻  Queue System         [queue]
  >◻  Messaging            [messaging]
  ```

- [ ] **Step 3 — UI System:** Επιλογή του frontend stack:
  ```
  Select UI system:
  > Plain CSS          — vanilla HTML/CSS, καμία εξάρτηση
    Bootstrap 5        — component library, responsive grid
    Tailwind CSS       — utility-first CSS framework
    Svelte             — compiled component framework (SPA/hybrid)
  ```

- [ ] **Step 4 — Extra libraries:** Η λίστα φιλτράρεται αυτόματα ανάλογα με το UI system. Τα assets κατεβαίνουν τοπικά — **κανένα CDN link** στο παραγόμενο project:

  | Library | Plain CSS | Bootstrap | Tailwind | Svelte | Περιγραφή |
  |---|:---:|:---:|:---:|:---:|---|
  | **jQuery** | ✓ | ✓ | ✓ | — | DOM manipulation |
  | **Alpine.js** | ✓ | ✓ | ✓ | — | Lightweight reactivity |
  | **Htmx** | ✓ | ✓ | ✓ | — | AJAX χωρίς JS framework |
  | **DataTables.js** | ✓ | ✓ | ✓ | — | Interactive tables (απαιτεί jQuery) |
  | **Select2** | ✓ | ✓ | ✓ | — | Enhanced select (απαιτεί jQuery) |
  | **Tom Select** | ✓ | ✓ | ✓ | ✓ | Select χωρίς jQuery dependency |
  | **Flatpickr** | ✓ | ✓ | ✓ | ✓ | Date/time picker |
  | **Chart.js** | ✓ | ✓ | ✓ | ✓ | Canvas charts |
  | **ApexCharts** | ✓ | ✓ | ✓ | ✓ | SVG charts |
  | **Dropzone.js** | ✓ | ✓ | ✓ | — | File upload drag & drop |
  | **FilePond** | ✓ | ✓ | ✓ | ✓ | Advanced file upload |
  | **SweetAlert2** | ✓ | ✓ | ✓ | ✓ | Modal dialogs & alerts |
  | **Toastify** | ✓ | ✓ | ✓ | ✓ | Toast notifications |
  | **Sortable.js** | ✓ | ✓ | ✓ | ✓ | Drag & drop sorting |
  | **Cropper.js** | ✓ | ✓ | ✓ | — | Image cropping |
  | **Leaflet.js** | ✓ | ✓ | ✓ | ✓ | Maps |
  | **TinyMCE** | ✓ | ✓ | ✓ | — | Rich text editor |
  | **Quill** | ✓ | ✓ | ✓ | ✓ | Rich text editor (alt) |
  | **Font Awesome** | ✓ | ✓ | ✓ | ✓ | Icon set |
  | **Bootstrap Icons** | — | ✓ | — | — | Bootstrap-native icons |
  | **Flowbite** | — | — | ✓ | ✓ | Tailwind component library |
  | **Svelte Select** | — | — | — | ✓ | Native Svelte select |

- [ ] **Step 5 — Extra resources:** Επιλογή πρόσθετων static resources:
  - Default favicon set (16x16, 32x32, 180x180, SVG, `site.webmanifest`)
  - Base CSS reset / normalize
  - Base print stylesheet
  - Open Graph / social meta tags template

- [ ] **Local Asset Download:** Για κάθε επιλεγμένη library, το init command:
  1. Κατεβάζει το pinned release (CSS + JS minified) από το επίσημο source (GitHub Releases / npm registry CDN)
  2. Αποθηκεύει σε `public/assets/vendor/<library>/<version>/`
  3. Καταγράφει library + version σε `scaffolding/assets.json` (manifest για μελλοντικό `assets:update`)
  4. Παράγει τα `enqueue()` calls στο default theme του project

- [ ] **Step 6 — Docker startup & container bootstrap:**
  1. Γράφει `docker-compose.yml` με τις επιλεγμένες βάσεις και ports
  2. `docker-compose up -d` — ξεκινά τα containers
  3. Αναμένει health check (MySQL / PostgreSQL ready)
  4. `docker exec <container> composer install` μέσα στο container
  5. `docker exec <container> php bin/pramnos migrate:framework` — τρέχει τα system migrations
  6. Εμφανίζει summary με URLs, credentials και επόμενα βήματα

- [ ] **Scaffolding Templates Directory:** Τα template αρχεία για code generation (controllers, models, migrations, tests, κλπ.) μεταφέρονται από το `src/` σε αυτόνομο directory `scaffolding/templates/`. Δομή:
  ```
  scaffolding/
  ├── templates/          # PHP stubs για code generators
  │   ├── controller.stub
  │   ├── model.stub
  │   ├── migration.stub
  │   ├── middleware.stub
  │   ├── event.stub
  │   ├── listener.stub
  │   └── test.stub
  ├── themes/             # Starter theme files ανά UI system
  │   ├── plain-css/
  │   ├── bootstrap/
  │   ├── tailwind/
  │   └── svelte/
  ├── resources/          # Static resources (favicons, base CSS κλπ.)
  └── assets.json         # Pinned versions για τα vendor assets
  ```
  > **BC Strategy:** Τα υπάρχοντα generators συνεχίζουν να λειτουργούν. Η νέα template engine διαβάζει από `scaffolding/templates/` — αν δεν βρεθεί εκεί, fallback στα embedded stubs.

- [ ] **Modern Maker System:** Αντικατάσταση των Heredocs με καθαρά PHP Templates (Symfony MakerBundle style) για τη δημιουργία Controllers, Models, κλπ.
- [ ] **Test Auto-generation:** Αυτόματη δημιουργία των αντίστοιχων PHPUnit test files κατά το scaffolding οποιασδήποτε νέας κλάσης.
- [ ] **Middleware Scaffolding:** `create:middleware MiddlewareName` — δημιουργία skeleton middleware class, διαθέσιμο μόλις ολοκληρωθεί το pipeline (Φάση 4).
- [ ] **Event/Listener Scaffolding:** `create:event` και `create:listener` generators αντίστοιχοι του Event system (Φάση 2).
- [ ] **`docs/1.2-new-features.md`:** Αρχείο τεκμηρίωσης όλων των νέων χαρακτηριστικών της έκδοσης. Γράφεται παράλληλα με την υλοποίηση — όχι στο τέλος. Περιέχει: περιγραφή feature, getting started snippet, πλήρη API reference με παραδείγματα ανά method, και σημείωση BC compatibility.

## 🔒 Φάση 4: Framework-Level Infrastructure & Security
*Ενίσχυση της ασφάλειας και της εσωτερικής αρχιτεκτονικής.*

- [ ] **Feature Registry & `app.php` Integration:** Κεντρικό σύστημα ενεργοποίησης/απενεργοποίησης framework features μέσω του config αρχείου της εφαρμογής:

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

- [ ] **Enhanced Migration History Table:** Το `framework_migrations` (και αντίστοιχα το app-level migrations table) αποκτούν πλήρη metadata:

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

- [ ] **Migration Class Anatomy:** Κάθε migration αρχείο φέρει δομημένα metadata:

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

- [ ] **Sort Order για εκτέλεση:** Τα migrations ταξινομούνται πάντα ως εξής:
  1. Επίλυση `$dependencies` (topological sort — εξαρτήσεις τρέχουν πρώτες)
  2. `$priority` ascending (μικρότερο = υψηλότερη προτεραιότητα)
  3. Datetime από το όνομα αρχείου ascending (YYYY_MM_DD_HHmmss)

- [ ] **Auto-run Mechanism (Backport από Urbanwater):** Κατά το bootstrap της εφαρμογής, ο migration runner εκτελείται αυτόματα:
  - Τρέχει **app migrations** και **framework migrations** (scope=framework για τα ενεργά features) σε ενιαία ταξινομημένη ουρά.
  - Σέβεται το app setting `migration_cutoff` (datetime): **δεν εκτελεί κανένα migration με datetime παλιότερο από αυτό**. Τα framework baseline migrations φέρουν σκόπιμα παλιά timestamps (π.χ. `2020_01_01_*`) ώστε ένα υπάρχον installation (π.χ. UrbanWater production) να θέτει `migration_cutoff` σε μεταγενέστερη ημερομηνία και να τα παρακάμπτει αυτόματα — χωρίς να αγγίξει τα UW migrations που έχουν ήδη τρέξει.
  - Τρέχει μόνο migrations με `autorun = true`. Τα `autorun = false` παραλείπονται σιωπηλά (απαιτούν `--force` από CLI).
  - Αν αποτύχει migration, καταγράφεται στο history (result=0, error_message) και το bootstrap **δεν σταματά** — εμφανίζει warning και συνεχίζει.
  - **Capability-aware migrations:** Κάθε migration που χρησιμοποιεί `$schema->ifCapable()` εκτελείται κανονικά σε όλα τα backends — το capability check γίνεται εσωτερικά. Ο migration runner δεν χρειάζεται να γνωρίζει τι backend τρέχει. Τα TimescaleDB-specific DDL statements εκτελούνται μόνο αν `DatabaseCapabilities::has(TIMESCALEDB) === true`.

- [ ] **System Migrations (per feature):** Migrations αποκλειστικά για τους πίνακες του framework, οργανωμένα ανά feature:

  | Feature | Tables που δημιουργεί |
  |---|---|
  | `core` *(πάντα ενεργό)* | `sessions`, `settings`, `permissions`, `framework_migrations` |
  | `auth` | `users`, `roles`, `user_roles`, `password_resets` |
  | `authserver` | `oauth_clients`, `oauth_tokens`, `oauth_authorization_codes`, `oauth_scopes` |
  | `messaging` | `messages`, `message_threads`, `message_recipients` |
  | `queue` | `jobs`, `failed_jobs`, `job_batches` |

- [ ] **Migration CLI Commands:** Πλήρες σετ εντολών για τη διαχείριση migrations:

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
- [ ] **Middleware Pipeline:** Σύστημα middleware (before/after action execution) στο routing pipeline — rate limiting, auth enforcement, CORS, request logging — χωρίς τροποποίηση των controllers.
  > **BC Strategy:** Υπάρχοντες routes λειτουργούν αμετάβλητα. Middleware εφαρμόζεται μόνο αν δηλωθεί ρητά (opt-in), είτε per-route είτε globally. Η υπάρχουσα permission-checking λογική του Router παραμένει και συνεχίζει να τρέχει.
- [ ] **Formal Response Object:** Κλάση `Pramnos\Http\Response` με fluent interface (`withStatus()`, `withHeader()`, `json()`, `redirect()`) που συμπληρώνει τα υπάρχοντα header calls στους controllers.
  > **BC Strategy:** Η νέα κλάση είναι εντελώς additive. Controllers που καλούν απευθείας `header()`, `echo`, ή χρησιμοποιούν το `Document` layer συνεχίζουν να λειτουργούν αμετάβλητα.
- [ ] **Centralized Error / Exception Handler:** Ενιαίος handler για exceptions με environment-aware εξαγωγή: stack trace σε `debug` mode, friendly error page ή JSON envelope σε `production` mode, ενσωμάτωση με το Logs subsystem.
- [ ] **Service Providers:** Καθιέρωση `ServiceProvider` interface (`register()` / `boot()`) για την ομαλή εγγραφή routes, bindings και listeners από addons κατά το bootstrap.
  > **BC Strategy:** Το υπάρχον addon bootstrap mechanism συνεχίζει να λειτουργεί. Το `ServiceProvider` pattern είναι νέος, προαιρετικός τρόπος εγγραφής — όχι υποχρεωτικός.
- [ ] **PHP 8.1 Minimum Version:** Ανύψωση του minimum requirement στην PHP 8.1 (η 7.4 και 8.0 είναι EOL). Ανοίγει enums, readonly properties και intersection types στο core.
- [ ] **Security Fixes:**
  - Αναβαθμισμένο CSRF Protection.
  - Εφαρμογή strict ρυθμίσεων (HttpOnly, SameSite) στα session cookies.
  - Αυτόματο (ή πιο ασφαλές) escaping στα views/templates του framework.

## 🧪 Φάση 5: Quality Assurance
*Διασφάλιση της σταθερότητας του κώδικα.*

### Characterization Tests (Πριν από κάθε migration)
*Tests που καταγράφουν την **υπάρχουσα συμπεριφορά** των SQL-heavy classes, πριν αγγιχτεί γραμμή κώδικα. Αν όλα περνούν πριν και μετά το migration → BC αποδεδειγμένα διατηρήθηκε.*

> ℹ️ Αυτά τα tests γράφονται με γνώμονα το **παρατηρήσιμο αποτέλεσμα** (τι επιστρέφει κάθε public method, με ποιο SQL), όχι την εσωτερική υλοποίηση. Έτσι παραμένουν έγκυρα και μετά το refactor.

> ⚠️ **Κάθε characterization test εκτελείται υποχρεωτικά και στις τρεις βάσεις** (MySQL, PostgreSQL, TimescaleDB) μέσω του Docker environment. Ένα test που γράφεται μόνο για MySQL δεν θεωρείται ολοκληρωμένο.

- [x] **Characterization Tests — `Model`:** Κάλυψη `get()`, `save()`, `delete()`, column introspection, change tracking, και caching integration — **× 3 databases**.
- [x] **Characterization Tests — `DataTable`:** Κάλυψη dynamic filtering, multi-column sorting, pagination, και παραγόμενο SQL output — **× 3 databases**.
- [ ] **Characterization Tests — `Migration`:** Κάλυψη schema creation/alteration/rollback — **× 3 databases**. Περιλαμβάνει: σωστή ταξινόμηση (priority/deps/datetime), σεβασμό του `migration_cutoff`, συμπεριφορά autorun=false, καταγραφή αποτυχίας στο history.
- [ ] **Characterization Tests — `Adjacencylist`:** Κάλυψη parent/children traversal, depth queries, και tree reconstruction — **× 3 databases**.
- [x] **Characterization Tests — `Auth`:** Κάλυψη credential lookup, session persistence, permission resolution, JWT issuance και login/logout flows — **× 3 databases**.
- [x] **Characterization Tests — `User`:** Κάλυψη create, update, lookup, role assignment — **× 3 databases**.
- [x] **Characterization Tests — `Logs`:** Κάλυψη log insertion και query — **× 3 databases**, με ξεχωριστές assertions για το TimescaleDB hypertable path.

### New Feature Tests (>90% coverage, στόχος 100%)
*Κάθε νέο feature που παραδίδεται στη v1.2 πρέπει να συνοδεύεται από tests που καλύπτουν τουλάχιστον το 90% του κώδικά του. Database-related features εκτελούνται × 3.*

- [ ] **QueryBuilder Tests:** Πλήρης κάλυψη DML, DDL, TimescaleDB extensions, edge cases (empty results, null values, special characters) — **× 3 databases**.
- [ ] **ORM Layer Tests:** Κάλυψη όλων των relationships, eager loading, scopes, casting, soft deletes, model events — **× 3 databases**.
- [ ] **Middleware Pipeline Tests:** Κάλυψη before/after execution, short-circuit (403/401), chaining πολλαπλών middleware, exception propagation.
- [ ] **Migration System Tests *(Integration only)*:** Όλα τα migration tests είναι **integration tests** που εκτελούνται σε πραγματικές βάσεις μέσω του Docker environment. Δεν αρκεί να "τρέξει" ο κώδικας — κάθε assertion επαληθεύει την **πραγματική κατάσταση της βάσης** μετά την εκτέλεση:
  - Ο πίνακας **όντως υπάρχει** (`SHOW TABLES` / `information_schema`)
  - Οι **στήλες, τύποι, και constraints** είναι ακριβώς αυτοί που ορίζει το migration (όνομα, τύπος, nullable, default, PK, FK, unique)
  - Τα **indexes** δημιουργήθηκαν
  - Το **rollback** αφαιρεί πραγματικά τον πίνακα / τη στήλη / το index από τη βάση
  - Το history table (`framework_migrations`) έχει εγγραφή με σωστό result, batch, execution_time
  - Topological sort, `migration_cutoff`, autorun=false, αποτυχία migration, όλα τα CLI commands (status, pretend, rollback, reset, refresh, export)
  - **× 3 databases** — MySQL, PostgreSQL, TimescaleDB
- [ ] **Response Object Tests:** Κάλυψη status codes, header management, JSON serialization, redirect generation.
- [ ] **Exception Handler Tests:** Κάλυψη debug vs production output, JSON envelope για API routes, integration με Logs.
- [ ] **Event System Tests:** Κάλυψη fire/listen, multiple listeners, listener priority, exception handling μέσα σε listeners.
- [ ] **Service Provider Tests:** Κάλυψη register/boot lifecycle, binding resolution, route registration από provider.

### General Coverage (υπάρχον codebase → >80%)
- [ ] **Coverage Baseline:** Μέτρηση τρέχοντος coverage του `src/Pramnos/` με Xdebug report — ορισμός αφετηρίας.
- [ ] **Coverage Reports:** Αυτόματη παραγωγή HTML coverage report στο CI (dockertest) με ορατό summary ανά class.
- [ ] **Auth & Security Coverage:** PHPUnit tests για login flows, JWT issuance, CSRF validation και permission checks — **× 3 databases** για τα query paths.
- [ ] **Theme / View Layer Coverage:** Tests για asset enqueuing, widget rendering και variable passing από controllers στα views.
- [ ] **Email & Media Coverage:** Βασικά unit tests για SMTP email building και Media/image processing pipeline.
- [ ] **HTTP Layer Coverage:** Tests για Request parsing, Session fingerprinting, cookie management, CSRF token lifecycle.

---

## 🔗 Σειρά Εξάρτησης Υλοποίησης

Η παρακάτω σειρά είναι **υποχρεωτική** για τη διατήρηση BC κατά τη migration:

```
[Φάση 1] QueryBuilder & Schema Builder υλοποίηση
         + Tests (>90% coverage, × 3 databases)
         + Docblocks & inline comments
         ↓
[Φάση 5] Characterization Tests για κάθε SQL-heavy class (× 3 databases)
         ↓
[Φάση 1] Internal Migration: κάθε class ξαναγράφεται με QB
         ↓
[Φάση 5] Επανεκτέλεση των ίδιων tests → πρέπει να περνούν όλα (× 3 databases)
         ↓
         ✅ BC αποδεδειγμένα διατηρήθηκε

[Κάθε Φάση] docs/1.2-new-features.md ενημερώνεται παράλληλα με την υλοποίηση
[Release]   Coverage check: νέα features >90%, υπάρχον codebase >80%
            → Αν αποτύχει, το release δεν προχωρά
```

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
